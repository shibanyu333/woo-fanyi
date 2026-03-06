<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/mail.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/admin/login') {
    handle_admin_login($method);
} elseif ($path === '/admin/logout') {
    $_SESSION = [];
    session_destroy();
    redirect_to('/admin/login');
} elseif ($path === '/admin') {
    require_admin();
    redirect_to('/admin/settings');
} elseif ($path === '/admin/settings') {
    require_admin();
    handle_settings($method);
} elseif ($path === '/admin/links') {
    require_admin();
    handle_links($method);
} elseif ($path === '/admin/logs') {
    require_admin();
    handle_logs();
} elseif (preg_match('#^/q/([A-Za-z0-9]+)$#', $path, $matches)) {
    handle_query_page($matches[1]);
} else {
    handle_home();
}

function handle_home(): void
{
    render_page('邮件查询系统', function (): void {
        echo '<section class="hero"><h1>邮件查询系统</h1><p>这是一个独立部署版查询系统。管理员入口在 <a href="/admin/login">/admin/login</a>。</p></section>';
    }, false);
}

function handle_admin_login(string $method): void
{
    if ($method === 'POST') {
        verify_csrf();
        $password = (string) ($_POST['password'] ?? '');
        if (hash_equals(admin_password(), $password)) {
            $_SESSION['admin_authenticated'] = true;
            redirect_to('/admin/settings');
        }
        flash_set('error', '后台密码错误');
        redirect_to('/admin/login');
    }

    render_page('管理员登录', function (): void {
        echo '<section class="panel narrow"><h1>管理员登录</h1>';
        echo '<form method="post"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
        echo '<label>后台密码<input type="password" name="password" required></label>';
        echo '<button type="submit">登录</button></form></section>';
    }, false);
}

function handle_settings(string $method): void
{
    if ($method === 'POST') {
        verify_csrf();
        save_settings([
            'imap_host' => trim((string) ($_POST['imap_host'] ?? 'imap.qq.com')),
            'imap_port' => trim((string) ($_POST['imap_port'] ?? '993')),
            'imap_encryption' => trim((string) ($_POST['imap_encryption'] ?? 'ssl')),
            'imap_mailbox' => trim((string) ($_POST['imap_mailbox'] ?? 'INBOX')),
            'imap_email' => trim((string) ($_POST['imap_email'] ?? '')),
            'imap_password' => trim((string) ($_POST['imap_password'] ?? '')),
            'sender_domains' => trim((string) ($_POST['sender_domains'] ?? '')),
            'subject_includes' => trim((string) ($_POST['subject_includes'] ?? '')),
            'subject_excludes' => trim((string) ($_POST['subject_excludes'] ?? '')),
            'recent_hours' => (string) max(1, (int) ($_POST['recent_hours'] ?? 24)),
            'max_results' => (string) max(1, min(30, (int) ($_POST['max_results'] ?? 10))),
            'default_expiry_hours' => (string) max(1, (int) ($_POST['default_expiry_hours'] ?? 72)),
        ]);
        flash_set('success', '设置已保存');
        redirect_to('/admin/settings');
    }

    $settings = get_settings();
    render_page('系统设置', function () use ($settings): void {
        echo admin_nav('/admin/settings');
        echo '<section class="panel"><h1>系统设置</h1><form method="post" class="grid-form">';
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
        field('IMAP Host', 'imap_host', $settings['imap_host']);
        field('IMAP Port', 'imap_port', $settings['imap_port']);
        select_field('加密方式', 'imap_encryption', $settings['imap_encryption'], ['ssl' => 'SSL', 'tls' => 'TLS', '' => 'None']);
        field('邮箱目录', 'imap_mailbox', $settings['imap_mailbox']);
        field('QQ 邮箱地址', 'imap_email', $settings['imap_email'], 'email');
        field('授权码', 'imap_password', $settings['imap_password'], 'password');
        textarea_field('发件域名白名单', 'sender_domains', $settings['sender_domains'], '一行一个或逗号分隔，留空表示不过滤');
        textarea_field('标题包含关键词', 'subject_includes', $settings['subject_includes'], '配置后仅展示包含这些词的邮件');
        textarea_field('标题屏蔽关键词', 'subject_excludes', $settings['subject_excludes'], '仅在“包含关键词”为空时生效');
        field('查询时间范围（小时）', 'recent_hours', $settings['recent_hours'], 'number');
        field('最多返回邮件数', 'max_results', $settings['max_results'], 'number');
        field('默认链接有效期（小时）', 'default_expiry_hours', $settings['default_expiry_hours'], 'number');
        echo '<div class="form-actions"><button type="submit">保存设置</button></div></form></section>';
    });
}

function handle_links(string $method): void
{
    $pdo = db();
    if ($method === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'create');
        if ($action === 'create') {
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $note = trim((string) ($_POST['note'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash_set('error', '请输入有效邮箱');
                redirect_to('/admin/links');
            }
            $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
            if ($expiresAt === '') {
                $settings = get_settings();
                $hours = max(1, (int) ($settings['default_expiry_hours'] ?? 72));
                $expiresAt = date('Y-m-d\TH:i', strtotime('+' . $hours . ' hours'));
            }
            $normalizedExpiry = date('Y-m-d H:i:s', strtotime($expiresAt));
            $pdo->prepare('UPDATE access_links SET status = "disabled", updated_at = :updated_at WHERE email = :email AND status = "active"')
                ->execute([':updated_at' => now_string(), ':email' => $email]);
            $token = bin2hex(random_bytes(16));
            $stmt = $pdo->prepare('INSERT INTO access_links(email, token, expires_at, status, note, created_at, updated_at) VALUES(:email, :token, :expires_at, "active", :note, :created_at, :updated_at)');
            $stmt->execute([
                ':email' => $email,
                ':token' => $token,
                ':expires_at' => $normalizedExpiry,
                ':note' => $note,
                ':created_at' => now_string(),
                ':updated_at' => now_string(),
            ]);
            flash_set('success', '专属链接已生成');
            redirect_to('/admin/links');
        }

        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && in_array($action, ['disable', 'delete'], true)) {
            if ($action === 'disable') {
                $pdo->prepare('UPDATE access_links SET status = "disabled", updated_at = :updated_at WHERE id = :id')
                    ->execute([':updated_at' => now_string(), ':id' => $id]);
                flash_set('success', '链接已禁用');
            } else {
                $pdo->prepare('DELETE FROM access_links WHERE id = :id')->execute([':id' => $id]);
                flash_set('success', '链接已删除');
            }
            redirect_to('/admin/links');
        }
    }

    $links = $pdo->query('SELECT * FROM access_links ORDER BY id DESC')->fetchAll();
    $settings = get_settings();
    $defaultExpiry = date('Y-m-d\TH:i', strtotime('+' . max(1, (int) ($settings['default_expiry_hours'] ?? 72)) . ' hours'));
    render_page('专属链接', function () use ($links, $defaultExpiry): void {
        echo admin_nav('/admin/links');
        echo '<section class="panel"><h1>生成客户专属链接</h1><form method="post" class="grid-form">';
        echo '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
        echo '<input type="hidden" name="action" value="create">';
        field('客户邮箱', 'email', '', 'email');
        field('到期时间', 'expires_at', $defaultExpiry, 'datetime-local');
        field('备注', 'note', '');
        echo '<div class="form-actions"><button type="submit">生成链接</button></div></form></section>';

        echo '<section class="panel"><h2>已生成链接</h2><table><thead><tr><th>ID</th><th>邮箱</th><th>专属链接</th><th>到期时间</th><th>状态</th><th>备注</th><th>操作</th></tr></thead><tbody>';
        foreach ($links as $link) {
            $expired = strtotime($link['expires_at']) < time();
            $status = $expired ? 'expired' : $link['status'];
            $url = app_url('/q/' . $link['token']);
            echo '<tr>';
            echo '<td>' . (int) $link['id'] . '</td>';
            echo '<td>' . h($link['email']) . '</td>';
            echo '<td><input class="url-box" value="' . h($url) . '" readonly></td>';
            echo '<td>' . h($link['expires_at']) . '</td>';
            echo '<td>' . h($status) . '</td>';
            echo '<td>' . h($link['note']) . '</td>';
            echo '<td><form method="post" class="inline"><input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '"><input type="hidden" name="id" value="' . (int) $link['id'] . '"><button name="action" value="disable">禁用</button><button name="action" value="delete" class="danger" onclick="return confirm(\'确认删除这个链接？\')">删除</button></form></td>';
            echo '</tr>';
        }
        if (!$links) {
            echo '<tr><td colspan="7">暂无数据</td></tr>';
        }
        echo '</tbody></table></section>';
    });
}

function handle_logs(): void
{
    $logs = db()->query('SELECT * FROM query_logs ORDER BY id DESC LIMIT 200')->fetchAll();
    render_page('查询日志', function () use ($logs): void {
        echo admin_nav('/admin/logs');
        echo '<section class="panel"><h1>查询日志</h1><table><thead><tr><th>时间</th><th>邮箱</th><th>Token</th><th>IP</th><th>结果数</th><th>User-Agent</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            echo '<tr><td>' . h($log['created_at']) . '</td><td>' . h($log['email']) . '</td><td>' . h($log['token']) . '</td><td>' . h($log['ip_address']) . '</td><td>' . (int) $log['result_count'] . '</td><td>' . h($log['user_agent']) . '</td></tr>';
        }
        if (!$logs) {
            echo '<tr><td colspan="6">暂无日志</td></tr>';
        }
        echo '</tbody></table></section>';
    });
}

function handle_query_page(string $token): void
{
    $stmt = db()->prepare('SELECT * FROM access_links WHERE token = :token LIMIT 1');
    $stmt->execute([':token' => $token]);
    $link = $stmt->fetch();
    if (!$link) {
        http_response_code(404);
        render_page('链接不存在', fn() => print '<section class="panel narrow"><h1>链接不存在</h1></section>', false);
        return;
    }
    if ($link['status'] !== 'active' || strtotime($link['expires_at']) < time()) {
        http_response_code(410);
        render_page('链接已失效', fn() => print '<section class="panel narrow"><h1>链接已失效</h1><p>请联系客服获取新的查询链接。</p></section>', false);
        return;
    }

    $result = fetch_filtered_messages($link['email']);
    db()->prepare('INSERT INTO query_logs(link_id, token, email, ip_address, user_agent, result_count, created_at) VALUES(:link_id, :token, :email, :ip_address, :user_agent, :result_count, :created_at)')
        ->execute([
            ':link_id' => $link['id'],
            ':token' => $token,
            ':email' => $link['email'],
            ':ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ':user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':result_count' => count($result['messages'] ?? []),
            ':created_at' => now_string(),
        ]);

    render_page('邮件查询', function () use ($link, $result): void {
        echo '<section class="hero"><h1>邮件查询</h1><p>当前查询邮箱：' . h($link['email']) . '</p><p>链接到期时间：' . h($link['expires_at']) . '</p></section>';
        if (!empty($result['error'])) {
            echo '<section class="panel narrow"><p class="alert error">' . h($result['error']) . '</p></section>';
            return;
        }
        if (empty($result['messages'])) {
            echo '<section class="panel narrow"><p>没有找到符合条件的邮件。</p></section>';
            return;
        }
        foreach ($result['messages'] as $message) {
            echo '<section class="panel"><h2>' . h($message['subject']) . '</h2>';
            echo '<p class="meta">发件人：' . h($message['from']) . ' | 时间：' . h($message['date']) . '</p>';
            echo '<iframe sandbox="allow-popups allow-popups-to-escape-sandbox allow-forms" srcdoc="' . h(build_srcdoc($message['body_html'])) . '"></iframe>';
            echo '</section>';
        }
    }, false);
}

function build_srcdoc(string $html): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>body{font-family:Arial,sans-serif;padding:18px;line-height:1.6}img{max-width:100%;height:auto}</style></head><body>' . $html . '</body></html>';
}

function render_page(string $title, callable $content, bool $admin = true): void
{
    $flash = flash_get();
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>' . base_css() . '</style></head><body>';
    if ($admin && !empty($_SESSION['admin_authenticated'])) {
        echo '<header class="topbar"><div>Mail Query Admin</div><a href="/admin/logout">退出</a></header>';
    }
    echo '<main class="container">';
    if ($flash) {
        echo '<div class="alert ' . h($flash['type']) . '">' . h($flash['message']) . '</div>';
    }
    $content();
    echo '</main></body></html>';
}

function admin_nav(string $current): string
{
    $items = [
        '/admin/settings' => '系统设置',
        '/admin/links' => '专属链接',
        '/admin/logs' => '查询日志',
    ];
    $html = '<nav class="tabs">';
    foreach ($items as $path => $label) {
        $class = $path === $current ? 'active' : '';
        $html .= '<a href="' . $path . '" class="' . $class . '">' . $label . '</a>';
    }
    return $html . '</nav>';
}

function field(string $label, string $name, string $value, string $type = 'text'): void
{
    echo '<label>' . h($label) . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '" ' . ($type !== 'password' ? 'required' : '') . '></label>';
}

function select_field(string $label, string $name, string $value, array $options): void
{
    echo '<label>' . h($label) . '<select name="' . h($name) . '">';
    foreach ($options as $optionValue => $optionLabel) {
        $selected = (string) $value === (string) $optionValue ? ' selected' : '';
        echo '<option value="' . h((string) $optionValue) . '"' . $selected . '>' . h($optionLabel) . '</option>';
    }
    echo '</select></label>';
}

function textarea_field(string $label, string $name, string $value, string $hint = ''): void
{
    echo '<label class="full">' . h($label) . '<textarea name="' . h($name) . '" rows="4">' . h($value) . '</textarea>';
    if ($hint !== '') {
        echo '<small>' . h($hint) . '</small>';
    }
    echo '</label>';
}

function base_css(): string
{
    return 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f4f6f8;color:#111827}a{color:#0f766e;text-decoration:none}.container{max-width:1200px;margin:0 auto;padding:24px}.topbar{display:flex;justify-content:space-between;align-items:center;background:#0f172a;color:#fff;padding:16px 24px}.hero,.panel{background:#fff;border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(15,23,42,.08);margin-bottom:20px}.hero{background:linear-gradient(135deg,#0f766e,#164e63);color:#fff}.panel.narrow{max-width:560px;margin:24px auto}.grid-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.grid-form label,.grid-form .full{display:flex;flex-direction:column;gap:8px;font-weight:600}.grid-form .full{grid-column:1/-1}.form-actions{grid-column:1/-1}.tabs{display:flex;gap:12px;margin-bottom:16px}.tabs a{padding:10px 14px;border-radius:999px;background:#dbeafe;color:#1e3a8a}.tabs a.active{background:#0f766e;color:#fff}input,textarea,select,button{font:inherit;padding:12px 14px;border-radius:10px;border:1px solid #cbd5e1}button{background:#0f766e;color:#fff;border:none;cursor:pointer}button.danger{background:#b91c1c}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #e5e7eb;vertical-align:top}iframe{width:100%;min-height:420px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}.meta{color:#475569}.alert{padding:14px 18px;border-radius:12px;margin-bottom:20px}.alert.success{background:#dcfce7;color:#166534}.alert.error{background:#fee2e2;color:#991b1b}.inline{display:flex;gap:8px}.url-box{min-width:320px;width:100%}small{font-weight:400;color:#64748b}@media(max-width:768px){.container{padding:16px}.inline{flex-direction:column}.url-box{min-width:0}}';
}
