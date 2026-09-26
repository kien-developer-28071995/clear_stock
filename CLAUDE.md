# CLAUDE.md

Bạn là senior engineer. Hãy xây dựng Shopify app dự báo tồn kho hoàn chỉnh, sẵn sàng nộp Shopify App Store. Làm theo từng phase, dừng sau mỗi phase để tôi review và test trước khi làm tiếp.

## Sản phẩm
App nhúng trong Shopify admin cho merchant nhỏ và vừa (thị trường quốc tế): dự báo ngày hết hàng theo variant, gợi ý điểm đặt hàng lại và số lượng cần nhập, cảnh báo qua email. Tên app: **clear_stock** (slug kỹ thuật: tên project Docker, image, repo). Tên hiển thị cho merchant: "Clear Stock" (cấu hình qua `SHOPIFY_APP_NAME`, không hardcode trong code).

## Định vị (ảnh hưởng trực tiếp đến thiết kế)
- Rẻ hơn các app dự báo cùng loại; giá cố định, không tính theo GMV, không hợp đồng; gói miễn phí có dự báo thật
- Dự báo minh bạch: mọi con số đều giải thích được và merchant chỉnh được
- Hỗ trợ bundle ngay từ MVP
- Tự phục vụ hoàn toàn: không cần demo hay gọi điện, onboarding tự dẫn dắt
- Không làm phiền: không spam email, không ép dùng tính năng AI

## Stack
- Backend: Laravel bản mới nhất, MySQL, Redis (cache + queue), Laravel Horizon, Laravel Scheduler
- Frontend: React + TypeScript + Vite, Polaris, App Bridge (dùng bản Shopify đang khuyến nghị, kiểm tra docs trước khi chọn)
- Shopify Admin GraphQL API (phiên bản ổn định mới nhất), Billing API
- Không dùng template Remix/React Router của Shopify: Laravel xử lý OAuth, session, webhook, billing
- Môi trường chạy bằng Docker Compose (dev + production)

## Cấu trúc thư mục
Backend và frontend tách riêng, mỗi bên tự quản lý dependency:
```
/                     docker-compose*.yml, Dockerfile, Makefile, shopify.app.toml, docker/
├── backend/          Laravel (composer.json, artisan, tests/, .env) — API, webhook, trang shell nhúng
└── frontend/         React + Vite (package.json, vite.config.ts, .env) — build ra backend/public/build
```
Mỗi bên có file env riêng: `backend/.env` (Laravel; container mysql và tunnel cũng đọc file này) và `frontend/.env` (Vite, chỉ biến `VITE_*` lộ ra trình duyệt). Không có `.env` ở root.

## Cấu trúc backend (backend/)
```
app/
├── Http/Controllers/Api/, Requests/, Resources/, Middleware/
├── Models/
├── Repositories/Contracts/, Eloquent/, Cache/   (Cache là decorator bọc Eloquent)
├── Services/          (logic nghiệp vụ, controller chỉ gọi service)
├── Observers/         (xóa cache khi model thay đổi)
├── Enums/, Events/, Listeners/, Jobs/, Exceptions/
└── Providers/RepositoryServiceProvider.php  (bind interface → Cached repository)
```
Luồng: Controller → Service → Repository (Cache → Eloquent) → Model. Key cache đặt tập trung, không rải rác.

## Cấu trúc frontend (frontend/)
```
src/
├── app/            (App, router, providers)
├── features/<module>/api, hooks, components, pages, types.ts
├── components/ui, components/layout
├── lib/            (http client gắn session token App Bridge, queryClient)
├── hooks/, types/, utils/
```
Dùng React Query để fetch và cache phía client.

## Schema
Không lưu đơn hàng thô, chỉ lưu tổng hợp bán hàng theo ngày của từng variant. Không lưu tên, email, địa chỉ khách hàng.
- shops: domain, access_token (encrypted), plan, currency, timezone, sync_status, last_synced_at
- suppliers: name, email, lead_time_days
- variants: shopify_variant_id, inventory_item_id, sku, unit_cost, supplier_id, lead_time_override, safety_days, is_bundle
- bundle_components: bundle_variant_id, component_variant_id, quantity
- inventory_levels: variant, location, available
- daily_sales: variant, date, units_sold, units_returned, end_of_day_stock, was_in_stock (unique variant+date)
- forecasts: avg_daily_sales, days_of_cover, stockout_date, reorder_point, suggested_qty, confidence, explanation (JSON)
- forecast_overrides: variant, field, value, note (điều chỉnh thủ công của merchant)
- alert_settings, alert_logs

Mọi bảng dữ liệu shop đều scope theo shop_id.

## Thuật toán forecast
- Trung bình bán/ngày có trọng số từ cửa sổ 7/30/90 ngày, loại bỏ các ngày hết hàng
- Hệ số mùa vụ đơn giản: so với cùng kỳ năm trước khi có đủ dữ liệu
- Reorder point = nhu cầu trong lead time + safety stock
- Bundle: quy đổi doanh số bundle thành doanh số từng thành phần theo bundle_components, cộng vào nhu cầu của thành phần
- Confidence thấp khi ít lịch sử hoặc biến động lớn; hiển thị rõ cho merchant
- Merchant có thể ghi đè trung bình bán, lead time, safety stock; override được ưu tiên và ghi vào explanation
- MVP dự báo theo variant; theo từng location dành cho gói Growth
- Chạy hằng đêm qua scheduler, theo timezone của shop

## Explanation (tính năng khác biệt chính)
Mỗi forecast lưu explanation gồm: các cửa sổ ngày đã dùng và trọng số, số ngày hết hàng bị loại bỏ, hệ số mùa vụ, đóng góp từ bundle, lead time và safety stock đang áp dụng, các override. Frontend hiển thị bằng câu dễ hiểu, ví dụ: "Bán trung bình 4,2/ngày trong 30 ngày (đã bỏ 3 ngày hết hàng). Lead time 14 ngày → cần đặt 59 cái trước ngày 12/10."

## Onboarding (merchant thấy giá trị trong ~5 phút)
1. Cài app → OAuth → vào thẳng app nhúng, không đăng ký thêm
2. Chạy Bulk Operation đồng bộ đơn hàng và tồn kho trong nền, hiển thị thanh tiến độ
3. Chỉ hỏi lead time mặc định (điền sẵn 14 ngày) và email nhận cảnh báo
4. Màn hình "aha": top 5 SKU sắp hết hàng (kèm giải thích) + số tiền đang kẹt ở hàng bán chậm
5. Nhà cung cấp, lead time từng SKU và bundle chỉnh sau trong Settings

## Gói giá (Billing API)
- Free: dưới 50 SKU, có dự báo, gợi ý nhập hàng và giải thích dự báo đầy đủ (quyết định 2026-09-26: giải thích mở cho mọi gói vì là lời hứa "minh bạch")
- Starter $4/tháng ($38/năm): không giới hạn SKU, bundle, cảnh báo email (dùng thử 7 ngày, một lần mỗi shop)
- Growth $5/tháng ($48/năm): multi-location, xuất purchase order (dùng thử 7 ngày)
- Giá hạ từ $9/$24 xuống $2/$3 rồi nâng lên $4/$5 ngày 2026-09-26 (quyết định của chủ app). Shop đang trả tiền giữ giá cũ tới khi tự đổi gói (subscription Shopify giữ nguyên giá)
- Gói năm giảm ~20%

Giá cố định, không tính theo GMV, không hợp đồng, hủy bất cứ lúc nào.
Tối ưu chi phí hạ tầng mỗi shop để gói $4 vẫn có lãi: job gộp theo lô, chỉ đồng bộ phần thay đổi sau lần sync đầu, không lưu dữ liệu thừa.

## Độ tin cậy đồng bộ
Hiển thị trạng thái đồng bộ cho merchant (lần sync cuối, lỗi nếu có). Job lỗi phải retry có backoff và được ghi log để theo dõi.

## Yêu cầu bắt buộc cho App Store
- HTTPS; OAuth ngay khi cài; xác thực bằng session token App Bridge, không dùng cookie bên thứ ba
- Scopes tối thiểu: giải thích lý do từng scope trong README; dùng read_all_orders
- Webhook: app/uninstalled, customers/data_request, customers/redact, shop/redact; xác thực HMAC, xử lý qua queue
- Mọi khoản thu qua Billing API, có nâng/hạ gói và gói năm
- UI theo Polaris, có empty state, loading, thông báo lỗi rõ ràng
- Phản hồi API nhanh; việc nặng đưa vào queue
- Route public: /privacy, /support

## Docker
Thiết lập môi trường bằng Docker Compose cho cả dev và production.

Dev (docker-compose.yml):
- app: PHP-FPM bản Laravel yêu cầu, extension pdo_mysql, redis, bcmath, intl, gd, zip, opcache
- nginx: reverse proxy tới app, phục vụ public/
- mysql: MySQL 8, volume lưu dữ liệu, healthcheck
- redis: cache + queue
- horizon: container riêng chạy `php artisan horizon`
- scheduler: container riêng chạy `php artisan schedule:work`
- node: chạy Vite dev server có HMR
- mailpit: bắt email cảnh báo khi dev
- tunnel: cloudflared (hoặc ngrok) để có URL HTTPS công khai cho Shopify OAuth, webhook và app nhúng; ghi rõ cách cập nhật URL này vào shopify.app.toml và .env

Production (docker-compose.prod.yml + Dockerfile multi-stage):
- Stage build frontend (npm ci && npm run build), stage composer install --no-dev, stage runtime tối giản
- Không mount source code, không có node và mailpit
- Chạy config:cache, route:cache, view:cache khi khởi động; migration chạy bằng lệnh riêng, không tự động
- Horizon và scheduler tự khởi động lại khi lỗi (restart: unless-stopped)
- Healthcheck cho app, mysql, redis; log ra stdout

Yêu cầu chung:
- Toàn bộ cấu hình qua biến môi trường; .env.example ghi đủ biến Shopify, DB, Redis, mail, APP_URL
- Makefile với các lệnh: make up, make down, make shell, make migrate, make test, make logs, make tunnel
- Tốc độ build và dung lượng image hợp lý (dùng .dockerignore, cache layer composer/npm)
- README hướng dẫn từ clone repo đến cài app vào store dev trong dưới 10 phút

## Các phase
1. Docker (dev + prod) + khởi tạo Laravel + Vite, OAuth, lưu shop, middleware xác thực session token, trang nhúng hello world
2. Migration theo schema + webhook bắt buộc
3. Bulk Operation sync → daily_sales, inventory_levels, trạng thái đồng bộ, thanh tiến độ
4. Engine forecast (kèm bundle, mùa vụ, override, explanation) + scheduler + test unit cho thuật toán
5. Frontend: onboarding, màn hình aha, danh sách SKU với giải thích, Settings (supplier, lead time, bundle)
6. Billing (Free / Starter / Growth, tháng và năm) + alert email
7. Rà soát yêu cầu App Store (dùng lệnh kiểm tra requirements của Shopify CLI nếu có), viết hướng dẫn test cho reviewer

## Quy tắc làm việc
- Đọc docs Shopify hiện hành trước khi dùng API, không đoán tên field
- Viết test (Pest/PHPUnit) cho service và forecast, đặc biệt các trường hợp: ngày hết hàng, bundle, ít lịch sử, override
- Không commit secret; dùng .env.example
- Cuối mỗi phase: tóm tắt đã làm gì, cách test, việc tôi cần làm thủ công (Partner Dashboard, store dev, biến môi trường)
- Sau mỗi phase, cập nhật mục "Tiến độ" bên dưới

## Tiến độ
- [x] Phase 1: Docker + khởi tạo + OAuth (managed install + token exchange, expiring offline token, session token middleware, trang hello world, 46 test pass)
- [x] Phase 2: Schema + webhook (11 bảng scope theo shop_id, trait BelongsToShop, endpoint /webhooks HMAC + dedupe + queue, uninstall/scopes_update/GDPR, xóa dữ liệu khi shop/redact, 73 test pass)
- [x] Phase 3: Bulk sync (3 bulk op song song + poll/webhook, daily_sales theo timezone shop, tái dựng ngày hết hàng, nightly 30 ngày + variant thay đổi, sync_runs + thanh tiến độ, 110 test pass, đã chạy thật trên store dev)
- [x] Phase 4: Forecast engine (calculator thuần 7/30/90 bỏ ngày hết hàng, mùa vụ 28 ngày năm trước (bỏ qua thay đổi < ±10%), bundle manual, override + thứ tự ưu tiên lead time/safety, confidence theo CV tuần, explanation JSON + câu giải thích, chạy sau mỗi sync + safety net 5h sáng, 149 test pass; dev:fake-orders + sync:run --full)
- [x] Phase 5: Frontend (onboarding 2 câu hỏi, màn hình aha, danh sách SKU lọc/sắp xếp/tìm, chi tiết + giải thích + điều chỉnh tạm thời, Settings/Suppliers/Bundles với resource picker, cache dashboard theo version, 171 test pass, đã kiểm tra UI bằng preview)
- [x] Phase 6: Billing + alert (Free/Starter/Growth tháng+năm qua Billing API, webhook app_subscriptions/update, Entitlements + 402, giới hạn 50 SKU bán chạy, khóa giải thích/bundle/alert/location/PO theo gói, digest email chống spam, export PO CSV, 201 test pass, đã thử billing + email thật)
- [x] Bổ sung sau Phase 6: dùng thử 7 ngày (1 lần/shop), giải thích dự báo mở cho Free, dự báo riêng từng location cho Growth (fulfillment orders → location_daily_sales, engine chạy theo location, lọc + PO theo location, resync khi lên Growth; thêm 2 scope read_*_fulfillment_orders), 218 test pass
- [x] Giao diện: Home phương án D (danh sách việc + đường băng tồn kho), setup guide 5 bước + mẹo ngữ cảnh, 228 test pass
- [x] Đa ngôn ngữ: EN + VI (react-i18next, file `frontend/src/i18n/locales/*.json`, thêm ngôn ngữ = thêm 1 file, `npm run i18n:check` kiểm tra key/số nhiều). Backend không trả text lên frontend: lỗi `{code, params}`, validation `errors.field[{code, params}]`, explanation `explanation_lines`, sync `stage` + `sync_error {code, params}`. Chọn ngôn ngữ trong Settings (`shops.locale`, null = theo Shopify admin). Email vẫn tiếng Anh. 233 test pass
- [ ] Tính năng mới từ nghiên cứu đối thủ: xem `docs/ROADMAP.md` (ưu tiên trước Phase 7: ~~import từ Stocky~~ (xong: `/suppliers/import`, CSV đơn đặt hàng → suppliers + gán sản phẩm + lead time trung vị, tự nhận cột + sửa tay, preview/apply stateless, 243 test; listing "Stocky alternative" để Phase 7), ~~trừ hàng đang về~~ (xong: Shopify `incoming` → stock position = tồn + đang về cho reorder date/suggested_qty, ngày hết hàng vẫn theo tồn thực, 238 test), ~~làm tròn MOQ/thùng~~ (xong: `variants.min_order_qty` + `pack_size`, nâng lên MOQ rồi làm tròn thùng, ghi trong explanation `reorder.rounding`, 255 test), ~~min/max thủ công~~ (xong: `variants.min_stock`/`max_stock`, min = điểm đặt lại, max = mức nhập tới, chỉ áp dụng dự báo tổng, 261 test). Tất cả mục ưu tiên trước Phase 7 đã xong)
- [x] Giao diện gọn lại: Home chỉ còn 4 chỉ số + 5 sản phẩm gấp nhất (+ setup guide, thẻ sync khi đang chạy/lỗi); trang con mới trong menu: Cần nhập hàng (`/reorder`, danh sách đầy đủ + xuất PO), Phân tích (`/insights`, đường băng tồn kho + hàng bán chậm); thẻ đồng bộ chuyển vào Settings; responsive điện thoại (390px) đã kiểm tra bằng ảnh chụp
- [x] Giám sát lỗi → Slack: listener `MessageLogged` (mọi log ≥ error), `Monitor::caught()/expected()` cho try/catch, lỗi frontend qua `/api/client-errors` + ErrorBoundary, throttle theo fingerprint, che token, `monitoring:test`, 271 test
- [x] Cache thêm: danh sách sản phẩm (không search, key theo forecast version + catalog version), chi nhánh, combo, số SKU theo dõi (COUNT). `catalog:version` tăng ở mọi ghi catalog (CachedCatalogRepository, CachedVariantRepository, SupplierObserver), 276 test
- [ ] Phase 7: Chuẩn bị nộp App Store (nhớ: gỡ scope write_orders chỉ dùng cho dev:fake-orders khỏi shopify.app.toml và SHOPIFY_SCOPES; SHOPIFY_BILLING_TEST=false ở production)
