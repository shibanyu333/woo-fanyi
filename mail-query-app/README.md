# Mail Query App

独立部署的邮件查询系统，基于 PHP + SQLite + IMAP。

## 功能
- 后台登录与系统设置
- IMAP 查询 QQ 邮箱邮件
- 发件域名白名单
- 标题包含关键词 / 屏蔽关键词
- 为客户生成专属查询链接
- 每个邮箱仅保留 1 个有效链接
- 支持链接到期时间
- 查询日志

## 运行
```bash
docker compose up -d --build
```

访问地址：`http://localhost:8099`

后台登录：`http://localhost:8099/admin/login`

默认后台密码：`admin123456`

## 重要说明
- 当前已将 QQ 邮箱授权码写入 `docker-compose.yml`。
- `APP_IMAP_EMAIL` 仍需填写你的完整 QQ 邮箱地址，或者进入后台保存。
- 如果后续要对外部署，先修改 `APP_ADMIN_PASSWORD`。
- `data/app.db` 为 SQLite 数据库，专属链接和日志都保存在这里。
