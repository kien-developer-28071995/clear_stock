# Deploy lên server (CI/CD)

```
push main ─▶ CI (test backend + frontend) ─▶ Deploy: build image ─▶ GHCR ─▶ SSH vào server ─▶ deploy.sh
                                                                                    │
                          pull image → app:preflight → (migration) → đổi container → kiểm tra /up
```

- **CI** (`.github/workflows/ci.yml`): Pint, Pest, kiểm tra TypeScript, i18n, locale extension, build frontend, **build thử 2 image Docker** (không đẩy đi đâu) và kiểm tra template `.env`. Chạy ở mọi push và pull request.
- **Deploy** (`.github/workflows/deploy.yml`): chỉ chạy khi CI trên `main` pass. Build image `app` và `web` **một lần** trên GitHub, đẩy lên GitHub Container Registry với tag `sha-<commit>`, rồi SSH vào server chạy `deploy/deploy.sh`.
- Server **không cần mã nguồn** và không build gì: chỉ có `docker-compose.prod.yml`, `deploy.sh` và `backend/.env`, cả ba do workflow tự copy lên.
- **`backend/.env` được dựng từ GitHub** (Secrets + Variables của environment `production`, mục 2b), không sửa tay trên server.
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

**File cấu hình app** (`backend/.env`): không cần tạo tay, workflow dựng từ GitHub (mục 2b).

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

### 2b. Cấu hình app (`backend/.env`) bằng Secrets + Variables

Mỗi lần deploy, workflow chạy `deploy/envtool.py render`: lấy khung `backend/.env.production.example`, key nào có **Secret** hoặc **Variable** cùng tên trong environment `production` thì dùng giá trị đó, còn lại giữ mặc định của khung. File được copy lên server (`chmod 600`) và `deploy.sh` mới đổi sang nó; nếu deploy dừng trước khi đổi container thì file cũ được trả lại (`backend/.env.previous` giữ bản trước).

- **Secret** (ẩn, không đọc lại được): `APP_KEY`, `SHOPIFY_API_SECRET`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `HORIZON_BASIC_AUTH_USER`, `HORIZON_BASIC_AUTH_PASSWORD`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MONITORING_SLACK_WEBHOOK_URL`, `MONITORING_SLACK_EVENTS_WEBHOOK_URL`.
- **Variable** (xem/sửa trên giao diện GitHub): `APP_URL`, `SHOPIFY_API_KEY` (client ID, vốn công khai), `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `SUPPORT_EMAIL`, `LOG_LEVEL`, mọi `FEATURE_*`, `BILLING_GROWTH_OFFERED`...
- **Bắt buộc** (thiếu là deploy dừng, báo rõ key nào): `APP_KEY`, `APP_URL`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` (không được là `secret`/`root`), `HORIZON_BASIC_AUTH_*`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`, `SUPPORT_EMAIL`. Sau đó `app:preflight` vẫn kiểm tra như trước.
- **Key không có trong khung**: thêm Secret/Variable cùng tên và liệt kê tên đó trong Variable `EXTRA_ENV_KEYS` (cách nhau bằng dấu phẩy).
- **Chưa có Secret `APP_KEY`** = chưa bật cách này: server giữ nguyên `backend/.env` tự tạo (cách cũ vẫn chạy).

**Nhập nhanh bằng lệnh** (máy bạn, cần `gh auth login`):

```bash
python3 deploy/envtool.py push
```

Lệnh hỏi các giá trị bắt buộc (mật khẩu nhập ẩn), tự tạo `APP_KEY` cho lần đầu, đặt `FEATURE_*` thành Variable để bật/tắt trên GitHub, rồi ghi tất cả vào environment `production`. Đã có `backend/.env` trên server thì chép về rồi dùng `--from`, để giữ **đúng `APP_KEY` đang chạy**:

```bash
scp deploy@<server>:/opt/clear_stock/backend/.env ./server.env
python3 deploy/envtool.py push --from server.env && rm server.env
```

> **Không đổi thẳng `APP_KEY`** sau khi đã có shop cài: token Shopify lưu trong DB được mã hóa bằng key này. Muốn đổi (lộ key, định kỳ) thì làm theo mục "Đổi APP_KEY" bên dưới. `DB_PASSWORD` / `DB_ROOT_PASSWORD` chỉ có tác dụng khi MySQL khởi tạo lần đầu; đổi secret sau đó không đổi mật khẩu database.

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
- **Đổi cấu hình** (ví dụ bật công tắc `FEATURE_*`): sửa Secret/Variable trong Settings → Environments → `production`, rồi Actions → Deploy → *Run workflow* → **tag** = `current`. Không build lại: chỉ dựng lại `.env`, chạy preflight và khởi động lại app/horizon/scheduler/web (MySQL, Redis không restart). Lần deploy code tiếp theo cũng tự áp dụng.

### Đổi APP_KEY (xoay key)

`APP_KEY` chỉ dùng để mã hóa `shops.access_token` và `shops.refresh_token` (app không dùng cookie, session hay URL ký). Laravel giải mã được bằng key cũ nếu key cũ nằm trong `APP_PREVIOUS_KEYS`, nên đổi key không làm gián đoạn:

1. Tạo key mới: `docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`
2. Trong Settings → Environments → `production`: tạo Secret `APP_PREVIOUS_KEYS` = **key cũ** (giá trị `APP_KEY` hiện tại), rồi đổi Secret `APP_KEY` = **key mới**. Làm theo đúng thứ tự này và giữ bản key cũ ở nơi an toàn tới khi xong bước 4.
3. Actions → Deploy → *Run workflow*, tag = `current`. App chạy với key mới và vẫn đọc được token cũ.
4. Mã hóa lại token bằng key mới (chạy được nhiều lần; `--dry-run` để xem trước):
   ```bash
   docker compose -f docker-compose.prod.yml exec app php artisan app:reencrypt-secrets
   ```
   Lệnh báo số token đã chuyển; nếu còn token không đọc được thì báo shop nào và **dừng lại, không xóa key cũ**.
5. Khi lệnh báo 0 token không đọc được: xóa Secret `APP_PREVIOUS_KEYS` (hoặc để rỗng) rồi deploy `current` lần nữa.

Nếu **mất hẳn key cũ**: token không giải mã được nữa; shop vẫn còn dữ liệu nhưng app không gọi được Shopify cho tới khi merchant mở lại app (token exchange cấp token mới). Backup DB không giúp được vì token trong backup cũng mã hóa bằng key cũ.

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
