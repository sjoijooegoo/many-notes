# jcrEw Note 部署与维护

该分支基于 Many Notes `v0.16.2`，增加了在笔记中粘贴、拖入并上传图片的功能。生产镜像
使用固定的上游镜像摘要构建，避免 `latest` 更新后前后端版本不匹配。

## 目录与端口

默认生产配置：

| 项目 | 值 |
| --- | --- |
| 公网地址 | `https://jcrewnote.top` |
| 本地监听 | `127.0.0.1:8080` |
| SQLite | `/srv/many-notes/database` |
| 笔记及附件 | `/srv/many-notes/private` |
| 日志 | `/srv/many-notes/logs` |
| Typesense | `/srv/many-notes/typesense` |
| 容器内存上限 | `768 MiB` |

上述数据使用 bind mount 保存，不会因为重新构建或替换容器而丢失。请勿将
`/srv/many-notes` 复制进 Git 仓库。

## 首次部署或更新

```bash
cd /opt/many-notes-app
sudo docker compose -f compose.production.yaml build
sudo docker compose -f compose.production.yaml up -d
sudo docker compose -f compose.production.yaml ps
```

查看启动日志：

```bash
sudo docker compose -f compose.production.yaml logs --tail=100 many-notes
```

检查本机服务：

```bash
curl -I http://127.0.0.1:8080/login
```

`VITE_REVERB_APP_KEY` 是浏览器连接实时服务所需的公开应用标识，并不是
`REVERB_APP_SECRET`。它必须与基础镜像运行时的 Reverb Key 一致，否则登录页仍能显示，
但登录后的主界面会空白并在控制台报告 Pusher Key 错误。

## Caddy 反向代理

最小配置示例：

```caddyfile
jcrewnote.top {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy 会处理 HTTPS 证书，并能正确转发 Many Notes 使用的 WebSocket 连接。

## 备份

备份前建议暂停容器，确保 SQLite 和文件内容处于一致状态：

```bash
cd /opt/many-notes-app
sudo docker compose -f compose.production.yaml stop many-notes
sudo cp -a /srv/many-notes /srv/many-notes-backup-YYYYMMDD
sudo docker compose -f compose.production.yaml start many-notes
```

备份目录名中的日期需要手动替换。恢复数据库时必须先停止 Many Notes，不能覆盖正在使用的
SQLite 文件。

## 回滚

服务器仍保留修改前的部署配置 `/opt/many-notes`。需要回滚时执行：

```bash
cd /opt/many-notes
sudo docker compose up -d
```

现有部署前数据库备份：

```text
/srv/many-notes/backups/database-20260817-before-image-upload.sqlite
```

只回滚镜像通常不需要恢复数据库。除非数据库确实损坏，否则不要用旧备份覆盖新数据。

## Git 维护

`upstream` 应指向原项目，`origin` 应指向本定制仓库：

```bash
git remote -v
git fetch upstream --tags
```

合并新的上游版本前，应先检查数据库迁移、前端依赖和 Reverb 构建变量，然后在独立环境完成
登录、打开 Vault、编辑笔记及粘贴图片测试。
