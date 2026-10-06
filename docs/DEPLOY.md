# Deploy lên server (CI/CD)

```
push main ─▶ CI (test backend + frontend) ─▶ Deploy: build image ─▶ GHCR ─▶ SSH vào server ─▶ deploy.sh
                                                                                    │
                          pull image → app:preflight → (migration) → đổi container → kiểm tra /up
```

- **CI** (`.github/workflows/ci.yml`): Pint, Pest, kiểm tra TypeScript, i18n, locale extension, build frontend, **build thử 2 image Docker** (không đẩy đi đâu) và kiểm tra template `.env`. Chạy ở mọi push và pull request.
- **Deploy** (`.github/workflows/deploy.yml`): chỉ chạy khi CI trên `main` pass. Build image `app` và `web` **một lần** trên GitHub, đẩy lên GitHub Container Registry với tag `sha-<commit>`, rồi SSH vào server chạy `deploy/deploy.sh`.
- Server **không cần mã nguồn** và không build gì: chỉ có `docker-compose.prod.yml`, `deploy.sh` và `app/backend/.env`, cả ba do workflow tự copy lên.
- **`app/backend/.env` được dựng từ GitHub** (Secrets + Variables của environment `production`, mục 2b), không sửa tay trên server.
- **Migration tự chạy nếu chỉ thêm** (bảng, cột, index): script backup database, chạy migration, rồi mới đổi container. Script đọc SQL sẽ chạy (`migrate --pretend`); nếu có xóa, đổi tên, đổi kiểu cột, `UPDATE`/`DELETE` dữ liệu thì deploy **dừng trước khi đổi container** (app cũ vẫn chạy) và in ra câu lệnh đó. Kiểm tra xong thì chạy tay: Actions → Deploy → *Run workflow* → tick `migrate`. Tắt hẳn tự động: Variable `AUTO_MIGRATE` = `false`.
- **Vì sao không tự chạy mọi migration**: trong lúc migrate, bản cũ vẫn phục vụ; khi quay lại bản cũ (rollback), schema không quay lại theo. Nên đổi schema theo kiểu *mở rộng rồi thu gọn*: bản 1 thêm cột mới và code dùng cả hai; bản sau mới xóa cột cũ (bước này chạy tay).
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
sudo mkdir -p /opt/clear_stock/app/backend && sudo chown -R deploy:deploy /opt/clear_stock
```

**File cấu hình app** (`app/backend/.env`): không cần tạo tay, workflow dựng từ GitHub (mục 2b).

**Cổng**: tạo `/opt/clear_stock/.env` (file này chỉ để compose đọc, khác `app/backend/.env`) để container `web` chỉ nghe nội bộ, Caddy lo HTTPS phía trước:

```
APP_PORT=127.0.0.1:8080
```

**HTTPS bằng Caddy**:

```bash
sudo apt install -y caddy
```

Chép `deploy/Caddyfile` vào `/etc/caddy/Caddyfile`, đổi `app.clear-stock.techfoxify.com` thành domain của app và `clear-stock.techfoxify.com` thành domain của website (DNS bản ghi A của cả hai đã trỏ về IP server), rồi chạy `sudo systemctl reload caddy`. Caddy tự lấy và gia hạn chứng chỉ Let's Encrypt.

**Website** (`website/`, trang giới thiệu + `/privacy` + `/support`): là file HTML tĩnh, Caddy phục vụ thẳng từ `/opt/clear_stock/website` (không qua Docker). Workflow Deploy build website bằng chính các Variable của app rồi upload và đổi thư mục một lần (không có lúc nửa cũ nửa mới). Website phải là domain **khác** `APP_URL`: app chuyển `/privacy`, `/support` của nó sang `WEBSITE_URL`.

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

### 2b. Cấu hình app (`app/backend/.env`) bằng Secrets + Variables

Mỗi lần deploy, workflow chạy `deploy/envtool.py render`: lấy khung `app/backend/.env.production.example`, key nào có **Secret** hoặc **Variable** cùng tên trong environment `production` thì dùng giá trị đó, còn lại giữ mặc định của khung. File được copy lên server (`chmod 600`) và `deploy.sh` mới đổi sang nó; nếu deploy dừng trước khi đổi container thì file cũ được trả lại (`app/backend/.env.previous` giữ bản trước).

- **Secret** (ẩn, không đọc lại được): `APP_KEY`, `SHOPIFY_API_SECRET`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `HORIZON_BASIC_AUTH_USER`, `HORIZON_BASIC_AUTH_PASSWORD`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MONITORING_SLACK_WEBHOOK_URL`, `MONITORING_SLACK_EVENTS_WEBHOOK_URL`.
- **Variable** (xem/sửa trên giao diện GitHub): `APP_URL`, `WEBSITE_URL`, `SHOPIFY_API_KEY` (client ID, vốn công khai), `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `SUPPORT_EMAIL`, `LOG_LEVEL`, mọi `FEATURE_*`, `BILLING_GROWTH_OFFERED`...
- **Chỉ cho website** (Variable, không vào `app/backend/.env`): `INSTALL_URL` = link App Store listing cho mọi nút "Cài trên Shopify" (chưa có listing thì bỏ trống, nút trỏ tới apps.shopify.com). Website lấy thêm `WEBSITE_URL`, `SHOPIFY_APP_NAME`, `SUPPORT_EMAIL`, `BILLING_GROWTH_OFFERED`. Chưa đặt `WEBSITE_URL` thì workflow bỏ qua bước website.
- **Bắt buộc** (thiếu là deploy dừng, báo rõ key nào): `APP_KEY`, `APP_URL`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` (không được là `secret`/`root`), `HORIZON_BASIC_AUTH_*`, `MAIL_HOST`, `MAIL_FROM_ADDRESS`, `SUPPORT_EMAIL`, `WEBSITE_URL`. Sau đó `app:preflight` vẫn kiểm tra như trước.
- **Key không có trong khung**: thêm Secret/Variable cùng tên và liệt kê tên đó trong Variable `EXTRA_ENV_KEYS` (cách nhau bằng dấu phẩy).
- **Chưa có Secret `APP_KEY`** = chưa bật cách này: server giữ nguyên `app/backend/.env` tự tạo (cách cũ vẫn chạy).

**Nhập nhanh bằng lệnh** (máy bạn, cần `gh auth login`):

```bash
python3 deploy/envtool.py push
```

Lệnh hỏi các giá trị bắt buộc (mật khẩu nhập ẩn), tự tạo `APP_KEY` cho lần đầu, đặt `FEATURE_*` thành Variable để bật/tắt trên GitHub, rồi ghi tất cả vào environment `production`. Đã có `app/backend/.env` trên server thì chép về rồi dùng `--from`, để giữ **đúng `APP_KEY` đang chạy**:

```bash
scp deploy@<server>:/opt/clear_stock/app/backend/.env ./server.env
python3 deploy/envtool.py push --from server.env && rm server.env
```

> **Không đổi thẳng `APP_KEY`** sau khi đã có shop cài: token Shopify lưu trong DB được mã hóa bằng key này. Muốn đổi (lộ key, định kỳ) thì làm theo mục "Đổi APP_KEY" bên dưới. `DB_PASSWORD` / `DB_ROOT_PASSWORD` chỉ có tác dụng khi MySQL khởi tạo lần đầu; đổi secret sau đó không đổi mật khẩu database.

**Quyền cho workflow**: Settings → Actions → General → Workflow permissions: *Read and write* (để đẩy image lên GHCR). Workflow đã khai báo `packages: write`.

## 3. Lần deploy đầu tiên

1. Push lên `main` → CI chạy → Deploy tự chạy: tạo bảng (database trống, toàn migration thêm), bật app và kiểm tra `/up`.
2. Nếu deploy dừng ở bước migration (có migration xóa/đổi): Actions → **Deploy** → *Run workflow* → tick **migrate** → Run.
3. Mở `https://<domain>/up` (200 là app đã chạy) và `https://<website>/support` (trang hỗ trợ trên website). `https://<domain>/support` chuyển sang website.
4. Đẩy cấu hình app lên Shopify, từ máy bạn: `npx @shopify/cli@latest app deploy --config production`. Việc này chỉ cần khi đổi scope, webhook hoặc extension, không cần mỗi lần deploy code.

## 4. Hằng ngày

- **Deploy code**: merge hoặc push vào `main` là xong (duyệt deploy nếu đã bật required reviewers).
- **Có migration mới**: loại chỉ thêm thì tự chạy (có backup trong `/opt/clear_stock/backups/`). Loại xóa/đổi thì deploy dừng và in câu lệnh; kiểm tra rồi chạy lại bằng tay với `migrate = true`.
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

### Mỗi lần deploy thì container nào khởi động lại

| Container | Deploy code | Chỉ đổi config (`current`) | Ghi chú |
|---|---|---|---|
| `app`, `web` | Tạo lại (image mới) | Tạo lại | Gián đoạn vài giây: healthcheck kiểm tra mỗi 2 giây lúc khởi động (`start_interval`, cần Docker Engine ≥ 25). Webhook Shopify gửi lỗi sẽ tự gửi lại. |
| `horizon` | Tạo lại | Tạo lại | Nhận SIGTERM: ngừng nhận job, làm xong job đang chạy (tối đa 10 phút, `stop_grace_period`). Job bị ngắt quá hạn sẽ chạy lại sau `retry_after`. |
| `scheduler` | Tạo lại | Tạo lại | Chờ lệnh đang chạy tối đa 2 phút. Khóa `withoutOverlapping` hết hạn trước chu kỳ kế tiếp nên lệnh bị ngắt không làm lỡ lần chạy sau. |
| `mysql`, `redis` | Không | Không | Dữ liệu nằm trong volume. Chỉ khởi động lại khi đổi image trong `docker-compose.prod.yml`. |

- Image cũ không còn dùng và cũ hơn 7 ngày được xóa sau mỗi lần deploy thành công, nên ổ đĩa không đầy dần; image 7 ngày gần nhất vẫn còn để quay lại nhanh.
- Deploy có thể lâu hơn bình thường (tối đa khoảng 10 phút) nếu đúng lúc đó đang có job đồng bộ lớn: Horizon chờ job xong rồi mới dừng.

## 4a. Frontend và backend là hai phần riêng

| Phần | Domain | Biến | Chạy bằng |
|---|---|---|---|
| Backend: API, webhook, Horizon | `app-api.clear-stock.techfoxify.com` | `APP_URL` | container `web` + `app` + `horizon` + `scheduler` |
| Frontend: giao diện app nhúng | `app.clear-stock.techfoxify.com` | `FRONTEND_URL` | file tĩnh trong `<thư mục deploy>/frontend`, Caddy phục vụ |
| Website giới thiệu | `clear-stock.techfoxify.com` | `WEBSITE_URL` | file tĩnh trong `<thư mục deploy>/website` |

Frontend chỉ gọi backend qua `https://<APP_URL>/api/...` kèm session token của Shopify. Backend không trả trang nào của app; request khác vào backend bằng trình duyệt được chuyển sang `FRONTEND_URL`.

**Cài lần đầu (hoặc khi chuyển từ bản một domain):**

1. Tạo bản ghi DNS cho domain frontend và domain backend, trỏ về server.
2. Thêm khối `app.clear-stock.techfoxify.com` và `app-api.clear-stock.techfoxify.com` vào `/etc/caddy/Caddyfile` theo `deploy/Caddyfile`, rồi `sudo caddy validate --config /etc/caddy/Caddyfile && sudo systemctl reload caddy`. Khối frontend gửi header `frame-ancestors` theo từng shop: Shopify bắt buộc.
3. GitHub → Variables (environment `production`): `APP_URL` = domain backend, `FRONTEND_URL` = domain frontend. Deploy dừng nếu thiếu `FRONTEND_URL`.
4. `shopify.app.production.toml`: `application_url` = domain frontend; `redirect_urls`, URL webhook và `extensions/flow-lifecycle` = domain backend. Chạy `shopify app deploy --config production`.
5. Push `main`, duyệt Deploy. Workflow build frontend với `VITE_API_URL` = `APP_URL` và upload sau khi backend đã lên.

Domain cũ của app (`clear-stock.techfoxify.com`) nay là website: chạy `shopify app deploy --config production` trước khi đổi Caddy, để webhook và OAuth không còn gửi về domain đó.

**Sau này tách frontend sang server riêng:** cài Caddy ở server mới với riêng khối `app.clear-stock.techfoxify.com`, thêm Secrets `FRONTEND_DEPLOY_HOST`, `FRONTEND_DEPLOY_USER`, `FRONTEND_DEPLOY_PATH`, `FRONTEND_DEPLOY_KNOWN_HOSTS` (và `FRONTEND_DEPLOY_SSH_KEY`, `FRONTEND_DEPLOY_PORT` nếu khác), đổi DNS. Không phải sửa code hay workflow.

## 4b. Báo cáo cho chủ app (`admin/`)

Container `admin` chạy cạnh app, chỉ mở `127.0.0.1:8090` trên server. Cài một lần trong thư mục deploy: `bash admin-setup.sh` (tạo `admin/.env` và user MySQL `report` chỉ đọc); lần deploy sau tự bật. Tạo tài khoản: `docker compose -f docker-compose.prod.yml exec admin php artisan admin:user you@example.com`. Mở từ máy cá nhân: `ssh -N -L 8090:127.0.0.1:8090 <user>@<server>` rồi vào http://localhost:8090. Chi tiết và cách gắn domain riêng: `admin/README.md`.

- Admin lỗi không chặn và không rollback deploy của app.
- Rollback về image cũ hơn bản có admin: bước admin báo "did not start", app vẫn chạy.
- Backup: sổ cài/gỡ nằm trong volume `clear_stock_admin-data` (file `admin.sqlite`). Backup database hằng ngày ở mục 5 **không** gồm file này.

## 5. Backup database hằng ngày

Thêm vào crontab của user `deploy` (`crontab -e`): backup lúc 3h sáng, giữ 14 ngày.

```
0 3 * * * cd /opt/clear_stock && docker compose -f docker-compose.prod.yml exec -T mysql sh -c 'exec mysqldump --single-transaction -u root -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"' | gzip > backups/daily-$(date +\%F).sql.gz && find backups -name 'daily-*' -mtime +14 -delete
```

Nên chép backup ra nơi khác (S3, Backblaze...) để không mất khi server hỏng.

## 6. Kiểm tra nhanh sau khi setup

- [ ] `https://<domain>/up` trả 200; `https://<domain>/privacy` chuyển sang `https://<website>/privacy` và hiện nội dung, `/support` cũng vậy
- [ ] `https://<website>/` hiện trang giới thiệu, `/vi` bản tiếng Việt, `/sitemap.xml` có đủ trang
- [ ] `docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan app:preflight` pass
- [ ] `https://<domain>/horizon` đòi mật khẩu, Horizon đang chạy
- [ ] Cài app production lên một development store: onboarding → đồng bộ → Home có dữ liệu
- [ ] Gửi thử email (Settings → cảnh báo), email tới được và không vào spam (SPF/DKIM)
