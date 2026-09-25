# Task: Xây dựng "Shopify App Review Crawler" bằng Laravel 11

## Mục tiêu
Crawl review mới từ Shopify App Store cho danh sách app được cấu hình sẵn (app của mình + đối thủ), lưu DB, và bắn thông báo về Slack. Chạy tự động qua scheduler.

## Stack & ràng buộc
- Laravel 11, PHP 8.3, MySQL
- Queue driver: Redis (`QUEUE_CONNECTION=redis`), `CACHE_STORE=redis` (cần cho `ShouldBeUnique` và rate limit). Dùng Laravel Horizon để chạy và giám sát worker
- Tách 2 queue: `crawl` (job crawl) và `slack` (job gửi Slack), để crawl chậm không làm nghẽn thông báo
- HTTP: `Illuminate\Support\Facades\Http`; parse HTML: `symfony/dom-crawler` + `symfony/css-selector`
- Không dùng headless browser (trang render server-side)
- Code theo hướng service class, dễ test; không nhồi logic vào command/job

## Nguồn dữ liệu
- URL: `https://apps.shopify.com/{handle}/reviews?sort_by=newest&page={n}`
- ~10 review/trang, mới nhất trước
- Mỗi review có: external id (attribute id trên element review, vd `data-review-content-id` — PHẢI kiểm tra HTML thực tế bằng cách fetch 1 trang và lưu vào `tests/Fixtures/reviews_page.html` trước khi viết parser), số sao (thường ở `aria-label` dạng "X out of 5 stars"), ngày review, tên store, quốc gia, thời gian dùng app ("About 1 month using the app"), nội dung, phản hồi của dev (nếu có) + ngày phản hồi
- Ở header trang có: rating trung bình và tổng số review

## Database
1. `tracked_apps`: id, handle (unique), name, is_own_app (bool), slack_channel_key (string, map tới config), min_rating_to_notify (tinyint, default 5 = báo tất cả), is_active, backfilled_at (nullable), last_crawled_at, timestamps
2. `app_reviews`: id, tracked_app_id (FK), external_id (unique theo tracked_app_id), rating, store_name, country, usage_duration, body (text), body_hash, reviewed_at (date), dev_reply (text nullable), dev_replied_at (nullable), notified_at (nullable), timestamps
3. `app_snapshots`: id, tracked_app_id, date, avg_rating (decimal 2,1), total_reviews; unique (tracked_app_id, date)

## Config
- `config/review_crawler.php`:
  - `slack_webhooks`: map key → env (`mine`, `competitor_pain`, `competitor_digest`, `alerts`)
  - `request_delay_ms`: [2000, 5000] (random), `max_pages_per_run`: 3, `backfill_max_pages`: 50
  - `user_agent`
  - `queues`: `crawl` => 'crawl', `slack` => 'slack'
- Seeder `TrackedAppSeeder` đọc từ mảng config (handle, name, is_own_app)
- `.env.example`: `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_HOST`, `REDIS_PORT`, các `SLACK_WEBHOOK_*`

## Thành phần cần viết
1. `App\Services\ReviewCrawler\ShopifyReviewPageFetcher` — fetch 1 trang, retry 3 lần kèm exponential backoff cho 429/403/5xx, timeout 20s, random delay giữa các request
2. `App\Services\ReviewCrawler\ShopifyReviewParser` — input HTML, output `ReviewData[]` (readonly DTO) + `AppSummaryData` (avg_rating, total_reviews). Tách selector thành constant ở đầu class để dễ sửa khi Shopify đổi HTML
3. `App\Services\ReviewCrawler\ReviewSyncService`:
   - Chạy từ page 1, dừng khi gặp external_id đã tồn tại hoặc hết `max_pages_per_run`
   - Nếu app chưa backfill (`backfilled_at` null): crawl tới `backfill_max_pages`, lưu với `notified_at = now()` (KHÔNG bắn Slack), rồi set `backfilled_at`
   - Review đã tồn tại nhưng `body_hash` đổi hoặc vừa có `dev_reply` → cập nhật + dispatch thông báo "review edited" / "dev replied" (chỉ với app đối thủ nếu dev_reply)
   - Upsert snapshot hằng ngày
   - Nếu page 1 parse ra 0 review → throw `ParserBrokenException` → gửi cảnh báo tới webhook `alerts`
4. `App\Jobs\CrawlAppReviewsJob` — `onQueue('crawl')`, `ShouldBeUnique` theo app id (`uniqueFor` 3600s), tries 3, backoff [60, 300, 900]
5. `App\Jobs\SendReviewToSlackJob` — `onQueue('slack')`, rate limit 1 msg/giây/webhook bằng `Redis::throttle("slack:{$webhookKey}")->allow(1)->every(1)`; nếu bị chặn thì `release(2)`. tries 5. Set `notified_at` sau khi gửi thành công
6. `App\Notifications\Slack\ReviewMessageBuilder` — Block Kit: header (⭐ x số sao + tên app), fields (store, quốc gia, thời gian dùng, ngày), body cắt 500 ký tự, button "Xem review" link tới trang review. Màu/emoji theo rating (1–2 đỏ, 3 vàng, 4–5 xanh)
7. Routing Slack:
   - `is_own_app` → `mine` (mọi rating)
   - đối thủ, rating ≤ 3 → `competitor_pain` (gửi ngay)
   - đối thủ, rating ≥ 4 → không gửi ngay; gom vào digest
8. `App\Console\Commands\CrawlReviewsCommand` — `reviews:crawl {--app=handle} {--backfill}` dispatch job cho các app active; dispatch mỗi app kèm delay tăng dần (vd `now()->addSeconds($i * 30)`) để không crawl dồn
9. `App\Console\Commands\SendCompetitorDigestCommand` — `reviews:digest`: gửi 1 tin tóm tắt review 4–5★ của đối thủ trong 24h qua (số lượng theo app + 3 trích dẫn tiêu biểu), đánh dấu notified_at
10. Scheduler (`routes/console.php`): `reviews:crawl` mỗi 2 giờ `withoutOverlapping()->onOneServer()`; `reviews:digest` 9:00 hằng ngày, timezone Asia/Ho_Chi_Minh; `horizon:snapshot` mỗi 5 phút

## Horizon
- Cài `laravel/horizon`, config `config/horizon.php`:
  - supervisor `crawl`: queue `crawl`, `maxProcesses` 1–2 (tránh crawl song song dễ bị Cloudflare chặn), timeout 300s
  - supervisor `slack`: queue `slack`, `maxProcesses` 1, timeout 30s
- Bảo vệ dashboard Horizon bằng gate (chỉ email admin trong env)
- Job fail sau khi hết tries → gửi cảnh báo tới webhook `alerts` (dùng `failed()` trong job)

## Test (Pest)
- Parser: dùng fixture HTML thật, assert số review, rating, external_id, dev_reply
- SyncService: `Http::fake()` với fixture; test dừng khi gặp review cũ, backfill không bắn Slack, detect edit
- Jobs: `Queue::fake()` assert job được dispatch đúng queue (`crawl` / `slack`) và đúng routing
- Slack: `Http::fake()` + assert payload Block Kit đúng
- Parser trả 0 review → alert được gửi
- Test môi trường dùng `QUEUE_CONNECTION=sync`; mock `Redis::throttle` hoặc tách throttle ra class riêng để fake được

## Đầu ra mong muốn
- Migrations, models (relations + casts), services, DTOs, jobs, commands, config, seeder, Horizon config, tests
- `README` ngắn: cài đặt, env cần set, chạy `php artisan horizon`, cách thêm app mới, cách chạy backfill lần đầu (`php artisan reviews:crawl --backfill`)
- Làm theo thứ tự: fetch fixture thật → parser + test → DB → sync service → Slack → jobs/queue → Horizon → scheduler. Sau mỗi bước chạy test.
