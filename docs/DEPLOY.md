# Deploy lên server (CI/CD)

```
push main ─▶ CI (test backend + frontend) ─▶ Deploy: build image ─▶ GHCR ─▶ SSH vào server ─▶ deploy.sh
                                                                                    │
                          pull image → app:preflight → (migration) → đổi container → kiểm tra /up
```

- **CI** (`.github/workflows/ci.yml`): Pint, Pest, kiểm tra TypeScript, i18n, locale extension, build frontend. Chạy ở mọi push và pull request.
- **Deploy** (`.github/workflows/deploy.yml`): chỉ chạy khi CI trên `main` pass. Build image `app` và `web` **một lần** trên GitHub, đẩy lên GitHub Container Registry với tag `sha-<commit>`, rồi SSH vào server chạy `deploy/deploy.sh`.
- Server **không cần mã nguồn** và không build gì: chỉ có `docker-compose.prod.yml`, `deploy.sh` (workflow tự copy lên) và file cấu hình `backend/.env`.
- **Migration không tự chạy** (quy định của dự án). Nếu bản mới có migration chưa chạy, deploy **dừng lại trước khi đổi container**, app cũ vẫn chạy. Muốn chạy migration: vào Actions → Deploy → *Run workflow* → tick `migrate`. Script tự backup database trước khi migrate.
- Nếu `app:preflight` báo lỗi cấu hình (debug bật, billing test, scope sai...) thì cũng dừng, không đổi container.

## 1. Chuẩn bị server (làm một lần)

Khuyến nghị: Ubuntu 24.04, 2 vCPU, 4 GB RAM, 40 GB ổ đĩa (MySQL + Redis + app chạy chung một máy).

```bash
# Docker + plugin compose
curl -fsSL https://get.docker.com | sh

# User riêng để deploy, được dùng docker
sudo adduser --disabled-password --gecos "" deploy
sudo usermod -aG docker deploy

# Thư mục deploy
sudo mkdir -p /opt/clear_stock/backend && sudo chown -R deploy:deploy /opt/clear_stock
```

**File cấu hình app**: tạo `/opt/clear_stock/backend/.env` từ `backend/.env.production.example` và điền đủ:

- `APP_KEY`: tạo bằng `docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
- `APP_URL`: domain HTTPS
- `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` của **app production**
- `DB_PASSWORD`, `DB_ROOT_PASSWORD`: mật khẩu mạnh, không dùng `root` / `secret`
- `MAIL_*`, `SUPPORT_EMAIL`, `HORIZON_BASIC_AUTH_*`, `MONITORING_SLACK_*`

```bash
chmod 600 /opt/clear_stock/backend/.env
```

**Cổng**: tạo `/opt/clear_stock/.env` (file này chỉ để compose đọc, khác `backend/.env`) để container `web` chỉ nghe nội bộ, Caddy lo HTTPS phía trước:

```
APP_PORT=127.0.0.1:8080
```

**HTTPS bằng Caddy**:

```bash
sudo apt install -y caddy
```

Chép `deploy/Caddyfile` vào `/etc/caddy/Caddyfile`, đổi `app.example.com` thành domain của bạn (DNS bản ghi A đã trỏ về IP server), rồi chạy `sudo systemctl reload caddy`. Caddy tự lấy và gia hạn chứng chỉ Let's Encrypt.

**Tường lửa**: chỉ mở 22, 80, 443.

```bash
sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
```

**Cho server kéo image từ GHCR** (nếu repo private): tạo GitHub token *classic* chỉ có quyền `read:packages`, rồi đăng nhập trên server bằng user `deploy`:

```bash
echo <TOKEN> | docker login ghcr.io -u <github-username> --password-stdin
```

## 2. Cấu hình GitHub (làm một lần)

**SSH key cho deploy**: tạo trên máy bạn một key riêng, không dùng key cá nhân.

```bash
ssh-keygen -t ed25519 -f clear_stock_deploy -N "" -C "github-actions-deploy"
```

- Nội dung `clear_stock_deploy.pub` thêm vào `/home/deploy/.ssh/authorized_keys` trên server.
- Lấy known_hosts: `ssh-keyscan -p 22 <ip-server>`

**Environment**: Repo → Settings → Environments → **New environment** `production`. Nên bật *Required reviewers* (thêm chính bạn) để mỗi lần deploy phải bấm duyệt.

**Secrets** (trong environment `production`):

| Secret | Giá trị |
|---|---|
| `DEPLOY_HOST` | IP hoặc domain server |
| `DEPLOY_USER` | `deploy` |
| `DEPLOY_PORT` | `22` (bỏ trống cũng được) |
| `DEPLOY_PATH` | `/opt/clear_stock` |
| `DEPLOY_SSH_KEY` | nội dung file `clear_stock_deploy` (private key) |
| `DEPLOY_KNOWN_HOSTS` | kết quả `ssh-keyscan` ở trên |

**Quyền cho workflow**: Settings → Actions → General → Workflow permissions: *Read and write* (để đẩy image lên GHCR). Workflow đã khai báo `packages: write`.

## 3. Lần deploy đầu tiên

1. Push lên `main` → CI chạy. Deploy tự chạy và sẽ **dừng ở bước migration** (database còn trống): đây là đúng.
2. Actions → **Deploy** → *Run workflow* → tick **migrate** → Run. Script sẽ tạo bảng, bật app và kiểm tra `/up`.
3. Mở `https://<domain>/support`: trang hỗ trợ hiện lên là app đã chạy.
4. Đẩy cấu hình app lên Shopify, từ máy bạn: `npx @shopify/cli@latest app deploy --config production`. Việc này chỉ cần khi đổi scope, webhook hoặc extension, không cần mỗi lần deploy code.

## 4. Hằng ngày

- **Deploy code**: merge hoặc push vào `main` là xong (duyệt deploy nếu đã bật required reviewers).
- **Có migration mới**: deploy tự động báo "pending migration" và dừng. Chạy lại bằng tay với `migrate = true`.
- **Quay lại bản cũ**: Actions → Deploy → *Run workflow* → điền **tag** của bản cũ (ví dụ `sha-1a2b3c4`, xem trong GHCR hoặc file `/opt/clear_stock/.deployed-tag`). Không build lại, chỉ đổi image. Nếu bản mới đã chạy migration, kiểm tra migration đó có tương thích ngược không; backup nằm trong `/opt/clear_stock/backups/`.
- **Xem log**: `docker compose -f docker-compose.prod.yml logs -f --tail=100 app horizon` (chạy trong `/opt/clear_stock`).
- **Đổi cấu hình** (`backend/.env`, ví dụ công tắc `FEATURE_*`): sửa file trên server rồi chạy `docker compose -f docker-compose.prod.yml up -d --force-recreate app horizon scheduler`. Container khởi động lại sẽ cache lại config.

## 5. Backup database hằng ngày

Thêm vào crontab của user `deploy` (`crontab -e`): backup lúc 3h sáng, giữ 14 ngày.

```
0 3 * * * cd /opt/clear_stock && docker compose -f docker-compose.prod.yml exec -T mysql sh -c 'exec mysqldump --single-transaction -u root -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"' | gzip > backups/daily-$(date +\%F).sql.gz && find backups -name 'daily-*' -mtime +14 -delete
```

Nên chép backup ra nơi khác (S3, Backblaze...) để không mất khi server hỏng.

## 6. Kiểm tra nhanh sau khi setup

- [ ] `https://<domain>/up` trả 200, `/privacy` và `/support` hiện nội dung
- [ ] `docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan app:preflight` pass
- [ ] `https://<domain>/horizon` đòi mật khẩu, Horizon đang chạy
- [ ] Cài app production lên một development store: onboarding → đồng bộ → Home có dữ liệu
- [ ] Gửi thử email (Settings → cảnh báo), email tới được và không vào spam (SPF/DKIM)
