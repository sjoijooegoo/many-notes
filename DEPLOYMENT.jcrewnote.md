# jcrEw Note deployment

This repository is pinned to the upstream Many Notes `v0.16.2` source tag and contains the local
image paste/drop customization.

## Build and deploy

```bash
cd /opt/many-notes-app
sudo docker compose -f compose.production.yaml build
sudo docker compose -f compose.production.yaml up -d
```

The production data remains in bind mounts under `/srv/many-notes`; rebuilding the image does not
replace the database or vault files.

## Rollback

The previous deployment files remain in `/opt/many-notes`. To return to them:

```bash
cd /opt/many-notes
sudo docker compose up -d
```

The pre-change SQLite backup is:

```text
/srv/many-notes/backups/database-20260817-before-image-upload.sqlite
```

Do not restore that database while Many Notes is running.
