<?php
/**
 * 数据库管理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Fanyi2_Database {

    const TABLE_TRANSLATIONS = 'fanyi2_translations';
    const TABLE_STRINGS = 'fanyi2_strings';

    /**
     * 规范化字符串（用于翻译记忆复用）
     */
    public static function normalize_string($text) {
        if (!is_string($text)) {
            return '';
        }

        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);

        return $text;
    }

    /**
     * 语言别名归一化（用于共享翻译桶）
     * 当前策略：香港/台湾共用繁体翻译，统一落在 tw 桶
     */
    public static function resolve_translation_language($language) {
        $language = sanitize_text_field((string) $language);
        if ($language === 'hk' || $language === 'tw') {
            return 'tw';
        }
        return $language;
    }

    /**
     * 获取语言的等价查询集合（兼容历史数据）
     */
    public static function get_equivalent_translation_languages($language) {
        $canonical = self::resolve_translation_language($language);
        if ($canonical === 'tw') {
            return array('tw', 'hk');
        }
        return array($canonical);
    }

    /**
     * 创建数据库表
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // 源字符串表
        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $sql_strings = "CREATE TABLE $table_strings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            original_string text NOT NULL,
            string_hash varchar(64) NOT NULL DEFAULT '',
            domain varchar(100) NOT NULL DEFAULT 'general',
            context varchar(255) NOT NULL DEFAULT '',
            page_url varchar(500) NOT NULL DEFAULT '',
            element_type varchar(50) NOT NULL DEFAULT 'text',
            selector varchar(500) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY string_hash (string_hash),
            KEY domain (domain),
            KEY page_url (page_url(191)),
            KEY status (status)
        ) $charset_collate;";

        // 翻译表
        $table_translations = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $sql_translations = "CREATE TABLE $table_translations (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            string_id bigint(20) unsigned NOT NULL,
            language varchar(10) NOT NULL,
            translated_string text NOT NULL,
            translation_source varchar(20) NOT NULL DEFAULT 'manual',
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY string_lang (string_id, language),
            KEY language (language),
            KEY status (status),
            KEY translation_source (translation_source)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_strings);
        dbDelta($sql_translations);

        update_option('fanyi2_db_version', FANYI2_DB_VERSION);
    }

    /**
     * 获取或创建源字符串
     */
    public static function get_or_create_string($original_string, $args = array()) {
        global $wpdb;

        $defaults = array(
            'domain'       => 'general',
            'context'      => '',
            'page_url'     => '',
            'element_type' => 'text',
            'selector'     => '',
        );
        $args = wp_parse_args($args, $defaults);

        $string_hash = md5($original_string);
        $table = $wpdb->prefix . self::TABLE_STRINGS;

        // 查找已存在的字符串
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE string_hash = %s LIMIT 1",
            $string_hash
        ));

        if ($existing) {
            return $existing;
        }

        // 创建新字符串（使用 INSERT IGNORE 防止并发重复插入）
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (original_string, string_hash, domain, context, page_url, element_type, selector)
             VALUES (%s, %s, %s, %s, %s, %s, %s)",
            $original_string, $string_hash, $args['domain'], $args['context'],
            $args['page_url'], $args['element_type'], $args['selector']
        ));

        // 无论是刚插入还是已存在，通过 hash 查找返回
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE string_hash = %s LIMIT 1",
            $string_hash
        ));
    }

    /**
     * 保存翻译
     */
    public static function save_translation($string_id, $language, $translated_string, $source = 'manual') {
        global $wpdb;

        $canonical_language = self::resolve_translation_language($language);
        $equivalent_languages = self::get_equivalent_translation_languages($canonical_language);
        $table = $wpdb->prefix . self::TABLE_TRANSLATIONS;

        // 优先查找 canonical 语言记录
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE string_id = %d AND language = %s",
            $string_id, $canonical_language
        ));

        // 兼容历史：若 canonical 不存在，尝试查找等价语言记录（如旧 hk）
        if (!$existing && count($equivalent_languages) > 1) {
            $placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
            $params = array_merge(array($string_id), $equivalent_languages);
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE string_id = %d AND language IN ($placeholders) ORDER BY updated_at DESC LIMIT 1",
                $params
            ));
        }

        if ($existing) {
            $wpdb->update(
                $table,
                array(
                    'language'           => $canonical_language,
                    'translated_string'  => $translated_string,
                    'translation_source' => $source,
                    'status'             => 'published',
                ),
                array('id' => $existing->id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
            return $existing->id;
        }

        $wpdb->insert($table, array(
            'string_id'          => $string_id,
            'language'           => $canonical_language,
            'translated_string'  => $translated_string,
            'translation_source' => $source,
            'status'             => 'published',
        ));

        return $wpdb->insert_id;
    }

    /**
     * 判断字符串在目标语言是否已有已发布翻译
     */
    public static function has_published_translation($string_id, $language) {
        global $wpdb;

        $equivalent_languages = self::get_equivalent_translation_languages($language);
        $table = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge(array($string_id), $equivalent_languages);
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE string_id = %d AND language IN ($placeholders) AND status = 'published' LIMIT 1",
            $params
        ));

        return !empty($exists);
    }

    /**
     * 仅在缺失时保存翻译（避免二次翻译覆盖）
     */
    public static function save_translation_if_missing($string_id, $language, $translated_string, $source = 'manual') {
        if (self::has_published_translation($string_id, $language)) {
            return 0;
        }

        return self::save_translation($string_id, $language, $translated_string, $source);
    }

    /**
     * 获取翻译
     */
    public static function get_translation($original_string, $language) {
        global $wpdb;

        $canonical_language = self::resolve_translation_language($language);
        $equivalent_languages = self::get_equivalent_translation_languages($canonical_language);
        $string_hash = md5($original_string);
        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge(array($string_hash), $equivalent_languages, array($canonical_language));

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT t.translated_string
             FROM $table_trans t
             INNER JOIN $table_strings s ON t.string_id = s.id
             WHERE s.string_hash = %s
               AND t.language IN ($placeholders)
               AND t.status = 'published'
             ORDER BY CASE WHEN t.language = %s THEN 0 ELSE 1 END, t.updated_at DESC
             LIMIT 1",
            $params
        ));

        return $result;
    }

    /**
     * 批量获取翻译
     */
    public static function get_translations_batch($original_strings, $language) {
        global $wpdb;

        if (empty($original_strings)) {
            return array();
        }

        $canonical_language = self::resolve_translation_language($language);
        $equivalent_languages = self::get_equivalent_translation_languages($canonical_language);
        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;

        $hashes = array();
        $hash_to_original = array();
        foreach ($original_strings as $str) {
            $hash = md5($str);
            $hashes[] = $hash;
            $hash_to_original[$hash] = $str;
        }

        $hash_placeholders = implode(',', array_fill(0, count($hashes), '%s'));
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge($hashes, $equivalent_languages, array($canonical_language));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.string_hash, s.original_string, t.translated_string, t.language
             FROM $table_trans t 
             INNER JOIN $table_strings s ON t.string_id = s.id 
             WHERE s.string_hash IN ($hash_placeholders)
               AND t.language IN ($lang_placeholders)
               AND t.status = 'published'
             ORDER BY s.string_hash ASC, CASE WHEN t.language = %s THEN 0 ELSE 1 END, t.updated_at DESC",
            $params
        ));

        $translations = array();
        foreach ($results as $row) {
            if (!isset($translations[$row->original_string])) {
                $translations[$row->original_string] = $row->translated_string;
            }
        }

        return $translations;
    }

    /**
     * 获取页面所有翻译
     */
    public static function get_page_translations($page_url, $language) {
        global $wpdb;

        $canonical_language = self::resolve_translation_language($language);
        $equivalent_languages = self::get_equivalent_translation_languages($canonical_language);
        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge($equivalent_languages, array($canonical_language, $page_url));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.original_string, s.string_hash, s.selector,
                    (
                        SELECT tt.translated_string
                        FROM $table_trans tt
                        WHERE tt.string_id = s.id
                          AND tt.language IN ($lang_placeholders)
                          AND tt.status = 'published'
                        ORDER BY CASE WHEN tt.language = %s THEN 0 ELSE 1 END, tt.updated_at DESC
                        LIMIT 1
                    ) AS translated_string
             FROM $table_strings s
             WHERE s.page_url = %s AND s.status = 'active'",
            $params
        ));

        return $results;
    }

    /**
     * 获取所有字符串（分页）
     */
    public static function get_all_strings($args = array()) {
        global $wpdb;

        $defaults = array(
            'page'               => 1,
            'per_page'           => 20,
            'search'             => '',
            'language'           => '',
            'status'             => '',
            'domain'             => '',
            'translation_status' => '', // translated, untranslated, partial
            'filter_language'    => '', // specific language code to check
        );
        $args = wp_parse_args($args, $defaults);

        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;

        $where = array("1=1");
        $params = array();
        if (!empty($args['search'])) {
            $where[] = "s.original_string LIKE %s";
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        if (!empty($args['domain'])) {
            $where[] = "s.domain = %s";
            $params[] = $args['domain'];
        }
        if (!empty($args['status'])) {
            $where[] = "s.status = %s";
            $params[] = $args['status'];
        }

        // 翻译状态筛选
        $enabled_languages = get_option('fanyi2_enabled_languages', array());
        $default_lang = get_option('fanyi2_default_language', 'zh');
        $target_langs = array_filter($enabled_languages, function($l) use ($default_lang) {
            return $l !== $default_lang;
        });
        $canonical_target_langs = array();
        foreach ($target_langs as $lang_code) {
            $canonical_target_langs[self::resolve_translation_language($lang_code)] = true;
        }
        $target_lang_count = count($canonical_target_langs);

        if (!empty($args['translation_status']) || !empty($args['filter_language'])) {
            // 需要使用子查询来计算翻译状态
            if (!empty($args['filter_language'])) {
                $filter_lang = self::resolve_translation_language($args['filter_language']);
                $equivalent_filter_langs = self::get_equivalent_translation_languages($filter_lang);
                if ($args['translation_status'] === 'translated') {
                    if (count($equivalent_filter_langs) === 1) {
                        $where[] = "EXISTS (SELECT 1 FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.language = %s AND t2.status = 'published')";
                        $params[] = $filter_lang;
                    } else {
                        $lang_placeholders = implode(',', array_fill(0, count($equivalent_filter_langs), '%s'));
                        $where[] = "EXISTS (SELECT 1 FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.language IN ($lang_placeholders) AND t2.status = 'published')";
                        $params = array_merge($params, $equivalent_filter_langs);
                    }
                } elseif ($args['translation_status'] === 'untranslated') {
                    if (count($equivalent_filter_langs) === 1) {
                        $where[] = "NOT EXISTS (SELECT 1 FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.language = %s AND t2.status = 'published')";
                        $params[] = $filter_lang;
                    } else {
                        $lang_placeholders = implode(',', array_fill(0, count($equivalent_filter_langs), '%s'));
                        $where[] = "NOT EXISTS (SELECT 1 FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.language IN ($lang_placeholders) AND t2.status = 'published')";
                        $params = array_merge($params, $equivalent_filter_langs);
                    }
                }
            } else {
                if ($args['translation_status'] === 'translated' && $target_lang_count > 0) {
                    // 所有目标语言都有翻译
                    $where[] = "(SELECT COUNT(DISTINCT CASE WHEN t2.language IN ('hk','tw') THEN 'tw' ELSE t2.language END) FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.status = 'published') >= %d";
                    $params[] = $target_lang_count;
                } elseif ($args['translation_status'] === 'untranslated') {
                    // 没有任何翻译
                    $where[] = "NOT EXISTS (SELECT 1 FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.status = 'published')";
                } elseif ($args['translation_status'] === 'partial' && $target_lang_count > 0) {
                    // 有一些翻译，但不完整
                    $where[] = "(SELECT COUNT(DISTINCT CASE WHEN t2.language IN ('hk','tw') THEN 'tw' ELSE t2.language END) FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.status = 'published') > 0";
                    $where[] = "(SELECT COUNT(DISTINCT CASE WHEN t2.language IN ('hk','tw') THEN 'tw' ELSE t2.language END) FROM $table_trans t2 WHERE t2.string_id = s.id AND t2.status = 'published') < %d";
                    $params[] = $target_lang_count;
                }
            }
        }

        $where_clause = implode(' AND ', $where);
        $offset = ($args['page'] - 1) * $args['per_page'];

        // 获取总数
        $count_query = "SELECT COUNT(*) FROM $table_strings s WHERE $where_clause";
        if (!empty($params)) {
            $total = $wpdb->get_var($wpdb->prepare($count_query, $params));
        } else {
            $total = $wpdb->get_var($count_query);
        }

        // 获取数据
        $query = "SELECT s.*, 
                  GROUP_CONCAT(DISTINCT t.language) as translated_languages
                  FROM $table_strings s 
                  LEFT JOIN $table_trans t ON s.id = t.string_id AND t.status = 'published'
                  WHERE $where_clause 
                  GROUP BY s.id
                  ORDER BY s.updated_at DESC 
                  LIMIT %d OFFSET %d";
        $params[] = $args['per_page'];
        $params[] = $offset;

        $items = $wpdb->get_results($wpdb->prepare($query, $params));

        return array(
            'items' => $items,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page']),
        );
    }

    /**
     * 获取字符串详情及所有翻译
     */
    public static function get_string_with_translations($string_id) {
        global $wpdb;

        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;

        $string = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_strings WHERE id = %d", $string_id
        ));

        if (!$string) {
            return null;
        }

        $translations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_trans WHERE string_id = %d", $string_id
        ));

        $string->translations = array();
        foreach ($translations as $t) {
            $string->translations[$t->language] = $t;
        }

        // 香港/台湾共享繁体翻译：详情回填显示
        if (isset($string->translations['tw']) && !isset($string->translations['hk'])) {
            $string->translations['hk'] = $string->translations['tw'];
        } elseif (isset($string->translations['hk']) && !isset($string->translations['tw'])) {
            $string->translations['tw'] = $string->translations['hk'];
        }

        return $string;
    }

    /**
     * 删除字符串及其翻译
     */
    public static function delete_string($string_id) {
        global $wpdb;

        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;

        $wpdb->delete($table_trans, array('string_id' => $string_id), array('%d'));
        $wpdb->delete($table_strings, array('id' => $string_id), array('%d'));

        return true;
    }

    /**
     * 仅删除字符串的所有翻译（保留字符串本身）
     */
    public static function delete_translations_for_string($string_id) {
        global $wpdb;

        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $wpdb->delete($table_trans, array('string_id' => $string_id), array('%d'));

        return true;
    }

    /**
     * 获取统计信息
     */
    public static function get_stats() {
        global $wpdb;

        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;

        $total_strings = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_strings WHERE status = 'active'");
        $total_translations = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_trans WHERE status = 'published'");

        $languages = get_option('fanyi2_enabled_languages', array());
        $lang_stats = array();
        foreach ($languages as $lang) {
            $equivalent_languages = self::get_equivalent_translation_languages($lang);
            $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT string_id) FROM $table_trans WHERE language IN ($lang_placeholders) AND status = 'published'",
                $equivalent_languages
            ));
            $lang_stats[$lang] = array(
                'translated' => $count,
                'total'      => $total_strings,
                'percent'    => $total_strings > 0 ? round(($count / $total_strings) * 100, 1) : 0,
            );
        }

        return array(
            'total_strings'      => $total_strings,
            'total_translations' => $total_translations,
            'languages'          => $lang_stats,
        );
    }

    /**
     * 获取未翻译的字符串
     */
    public static function get_untranslated_strings($language, $limit = 50) {
        global $wpdb;

        $equivalent_languages = self::get_equivalent_translation_languages($language);
        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge($equivalent_languages, array($limit));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.* FROM $table_strings s
             WHERE s.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM $table_trans t
                   WHERE t.string_id = s.id
                     AND t.language IN ($lang_placeholders)
                     AND t.status = 'published'
               )
             ORDER BY s.created_at ASC
             LIMIT %d",
            $params
        ));

        return $results;
    }

    /**
     * 获取未翻译字符串的总数（轻量 COUNT 查询）
     */
    public static function count_untranslated_strings($language) {
        global $wpdb;

        $equivalent_languages = self::get_equivalent_translation_languages($language);
        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_strings s
             WHERE s.status = 'active'
               AND NOT EXISTS (
                   SELECT 1 FROM $table_trans t
                   WHERE t.string_id = s.id
                     AND t.language IN ($lang_placeholders)
                     AND t.status = 'published'
               )",
            $equivalent_languages
        ));
    }

    /**
     * 统计已发布翻译数量（按 string_id 去重）
     */
    public static function count_translated_strings($language) {
        global $wpdb;

        $equivalent_languages = self::get_equivalent_translation_languages($language);
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT string_id)
             FROM $table_trans
             WHERE language IN ($lang_placeholders) AND status = 'published'",
            $equivalent_languages
        ));
    }

    /**
     * 统计活跃源字符串总数
     */
    public static function count_active_strings() {
        global $wpdb;

        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_strings WHERE status = 'active'");
    }

    /**
     * 获取目标语言翻译记忆映射：规范化原文 => 译文
     */
    public static function get_translation_memory_map($language) {
        global $wpdb;

        $canonical_language = self::resolve_translation_language($language);
        $equivalent_languages = self::get_equivalent_translation_languages($canonical_language);
        $table_strings = $wpdb->prefix . self::TABLE_STRINGS;
        $table_trans = $wpdb->prefix . self::TABLE_TRANSLATIONS;
        $lang_placeholders = implode(',', array_fill(0, count($equivalent_languages), '%s'));
        $params = array_merge($equivalent_languages, array($canonical_language));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.original_string, t.translated_string
             FROM $table_trans t
             INNER JOIN $table_strings s ON t.string_id = s.id
             WHERE t.language IN ($lang_placeholders) AND t.status = 'published' AND s.status = 'active'
             ORDER BY CASE WHEN t.language = %s THEN 0 ELSE 1 END, t.updated_at DESC",
            $params
        ));

        $map = array();
        foreach ($rows as $row) {
            $normalized = self::normalize_string($row->original_string);
            if ($normalized === '' || empty($row->translated_string)) {
                continue;
            }

            if (!isset($map[$normalized])) {
                $map[$normalized] = $row->translated_string;
            }
        }

        return $map;
    }
}
