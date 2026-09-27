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
- Starter $4/tháng ($38/năm): không giới hạn SKU, bundle, cảnh báo email, xuất PO + gửi đơn cho NCC thủ công, mô phỏng tăng trưởng, dự báo sản phẩm mới theo sản phẩm tương tự (dùng thử 7 ngày, một lần mỗi shop)
- Growth $6/tháng ($58/năm): multi-location, chuyển kho, tự động gửi đơn NCC hằng tuần, cảnh báo tức thời, Flow triggers (dùng thử 7 ngày)
- Giá hạ từ $9/$24 xuống $2/$3 rồi nâng lên $4/$5, sau đó Growth lên $6 ngày 2026-09-26 (quyết định của chủ app). Xuất PO, gửi đơn thủ công và mô phỏng chuyển từ Growth xuống Starter cùng ngày. Shop đang trả tiền giữ giá cũ tới khi tự đổi gói (subscription Shopify giữ nguyên giá). Đây là cam kết công khai "Giữ giá" trên trang Gói: không bao giờ chuyển shop sang giá mới
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
- Chạy config:cache, route:cache, view:cache khi khởi động; migration không chạy khi container khởi động. Deploy (CI/CD) tự chạy migration chỉ-thêm (bảng/cột/index), sau khi backup; migration xóa/đổi tên/đổi kiểu dừng deploy chờ chạy tay (quyết định 2026-09-27, tắt bằng Variable `AUTO_MIGRATE=false`). Viết migration theo kiểu mở rộng rồi thu gọn: bản cũ phải chạy được trên schema mới
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
- [x] Rà soát Built for Shopify: menu `s-app-nav`, contextual save bar (Settings, cài đặt sản phẩm, chỉnh tốc độ bán) + leaveConfirmation, modal xác nhận thay `window.confirm`, banner quảng bá tắt được, tính năng theo gói bị khóa hẳn (nút PO, ô cảnh báo), Home có dòng trạng thái đồng bộ, lazy-load trang (main 408→234 KB)
- [x] Gửi đơn đặt hàng cho nhà cung cấp qua email (Growth): merchant xem/sửa rồi gửi, hoặc tự động theo từng NCC (tối đa 1 lần/tuần, từ 8h giờ shop); Reply-To về merchant, kèm CSV, log `supplier_emails`, 284 test
- [x] Log mọi email gửi đi: bảng `email_logs` (listener `MessageSent` → sent, `JobFailed` của mailable → failed), chỉ metadata không lưu nội dung, giữ 180 ngày (`model:prune`), xóa theo shop khi shop/redact, 290 test
- [x] Queue email riêng: mọi mailable kế thừa `App\Mail\QueuedMailable` (#[Queue('mail')], 3 lần thử, backoff 1/5 phút), Horizon `mail-supervisor` riêng; test bắt buộc mailable mới phải kế thừa, 292 test
- [x] Cảnh báo khi hạ gói: `/api/billing/impact` tính trên dữ liệu shop (SKU vượt giới hạn, combo, email cảnh báo, chi nhánh, PO, NCC tự gửi), hộp xác nhận trên trang Gói; hạ gói không xóa cài đặt, nâng lại dùng tiếp; badge "tạm dừng" cho NCC tự gửi, 295 test
- [x] Cảnh báo tức thời (chỉ Growth): webhook `inventory_levels/update` đăng ký riêng từng shop qua GraphQL (chỉ khi bật, tự gỡ khi hạ gói, `alerts:realtime-sync` đối soát hằng đêm), tính trên tổng mọi chi nhánh so với reorder point. Chống spam: chỉ gửi khi xấu đi (ok→low→out, `variant_alert_states`), hysteresis +20% trước khi re-arm, cooldown 7 ngày/sản phẩm dùng chung với digest (trừ low→out 1 lần), gom mail 15 phút, tối đa 3 mail/ngày, không gửi 21h–7h, tắt cảnh báo từng sản phẩm (`variants.alerts_muted`, áp dụng cả digest). Bỏ qua webhook đến trễ theo `updated_at`. Mặc định tắt, 320 test
- [x] Đối soát billing hằng ngày (`billing:reconcile` 04:20 UTC, job `ReconcileBilling` theo shop): đọc lại subscription từ Shopify, sửa gói nếu lệch do mất webhook, log warning
- [x] Tích hợp cảnh báo thời gian thực: dòng "cảnh báo tức thì sẽ dừng" trong hộp hạ gói; đối soát billing khi lệch gói cũng đồng bộ lại webhook tồn kho (qua `BillingService::apply`); queue `webhooks` có `webhooks-supervisor` riêng; ngưỡng chờ từng queue + `LongWaitDetected` → Slack, 325 test
- [x] Slack kênh sự kiện kinh doanh (`MONITORING_SLACK_EVENTS_WEBHOOK_URL`, tách khỏi kênh lỗi): cài mới/cài lại, gỡ (gói lúc gỡ, số ngày dùng), nâng/hạ gói, đổi kỳ thanh toán (MRR thay đổi, số shop đang cài); event `PlanChanged`, job queue gửi Slack, 331 test
- [x] Nhà cung cấp từ Vendor Shopify (roadmap #12): sync `vendor`/`product_type`, `/suppliers/from-vendors`, bộ lọc Vendor/Loại sản phẩm, gợi ý trong setup guide, 336 test
- [x] Trạng thái Tồn thừa (roadmap #15): `target_stock`/`excess_units`, ngưỡng 50%, lọc, mục Tồn thừa ở Phân tích, ô tiền kẹt trên Home, 340 test
- [x] Tối ưu API (nhánh refactor/optimize-api-responses, đã merge): trạng thái SQL một nguồn cho số đếm/lọc/badge, slow/overstock tổng hợp bằng SQL, payload dashboard 40→16 KB, danh sách 13.8→6 KB (`ForecastListResource`), cache email liên hệ onboarding, 341 test
- [x] Test E2E Playwright (`frontend/e2e`, `make e2e`): chạy app thật ngoài admin với App Bridge giả, mọi màn hình + tính năng trên Free/Starter/Growth (lệnh dev `dev:session-token`, `dev:set-plan`), 39 test. Phát hiện và sửa: `<s-option value="">` gửi nhãn thay vì rỗng (dùng `utils/select.ts`), `overrides` trả `[]` thay vì object
- [x] PO gốc Shopify (roadmap #11): xuất định dạng nhập PO của Shopify (menu Xuất đơn đặt hàng), đồng bộ barcode, header `X-Skipped-Rows`, 342 test
- [x] Admin extensions (roadmap #13): `extensions/product-forecast-block` (block trang sản phẩm: dự báo từng biến thể, giải thích, link `app:`), `extensions/product-settings-action` (chọn nhiều sản phẩm → đặt NCC/lead time/safety); API `/api/extension/*` + CORS; locale extension chép từ app (`npm run extensions:locales`); `package.json` ở root chỉ là workspace Shopify CLI; 348 test + 43 E2E
- [x] Gợi ý chuyển kho (roadmap #8, Growth): `TransferPlanner` (thuần, thiếu = vị thế ≤ điểm đặt lại → tới mức nhập tới; dư = tồn thực trên mức cần giữ; ưu tiên ngày hết sớm), trang `/transfers` theo tuyến A → B sửa được số lượng, tạo phiếu chuyển nháp trong Shopify (`inventoryTransferCreate` + `@idempotent`), scope tùy chọn `write_inventory_transfers` xin khi dùng lần đầu (`shopify.scopes.request`), phiếu nháp 7 ngày gần nhất tính là đã chuyển (`inventory_transfers`), banner ở trang Cần nhập hàng, 361 test + 47 E2E
- [x] Phân loại ABC (roadmap #6): `variants.price` đồng bộ từ Shopify, `abc_class`/`revenue_90d`/`revenue_share` tính lại mỗi lần forecast đầy đủ (`AbcClassifier` thuần, ngưỡng 80/95% trong `forecast.abc`), lọc `abc` + sắp xếp `revenue` ở danh sách, giải thích ở trang sản phẩm, bảng tóm tắt ở Phân tích, mọi gói, 372 test + 50 E2E
- [x] Mô phỏng tăng trưởng (roadmap #7): `/api/what-if` + trang `/what-if`, tách `ForecastCalculator::reorderPlan` dùng chung cho forecast và kịch bản, lượng đặt theo ngày đặt (hôm nay: từ vị thế tồn; sau: từ điểm đặt lại), mọi gói, 379 test + 53 E2E
- [x] Phân gói lại + giá Growth $6 ($58/năm): xuất PO + gửi đơn NCC thủ công + mô phỏng xuống Starter; tự động gửi NCC (`supplier_auto_email`) và Flow ở Growth; feature mới `what_if`, `supplier_auto_email`, `flow_triggers`, hộp hạ gói cập nhật
- [x] Shopify Flow triggers (roadmap #14, Growth): 3 extension `extensions/flow-*` + `flow-lifecycle` (callback `/flow/lifecycle`, HMAC), `FlowTriggerPlanner` thuần (mỗi thay đổi 1 lần, ngưỡng hết hàng 30/14/7/0 có hysteresis), `SendFlowTriggers` sau mỗi lần forecast, chỉ shop có workflow bật, thẻ Shopify Flow trong Settings, 392 test + 56 E2E. Chưa thử với Flow thật (cần `app deploy`)
- [x] Báo sync lỗi liên tục + giữ giá (roadmap #10, mọi gói): `SyncFailureNotifier` sau mỗi lần sync lỗi, 1 email/chuỗi lỗi (≥ 2 lỗi liên tiếp + 24h không sync được), `shops.sync_failure_notified_at` reset khi sync thành công; badge "Giữ giá" trên trang Gói, 397 test
- [x] MOQ / quy cách thùng mặc định theo NCC (mọi gói): `suppliers.min_order_qty`/`pack_size`, `Variant::effectiveMinOrderQty()/effectivePackSize()`, dòng giải thích `rounding_supplier_default`, 398 test
- [x] Sản phẩm mới theo sản phẩm tương tự (roadmap #9, Starter+, feature `reference_products`): chọn bằng resource picker trong Cài đặt sản phẩm, trộn tốc độ bán theo số ngày còn hàng (30 ngày), 404 test + 59 E2E. #16 barcode: quyết định không làm
- [x] Bật/tắt tính năng toàn app (`config/features.php`, `FEATURE_*`, `BILLING_GROWTH_OFFERED`): một cổng duy nhất `Entitlements::has()` = gói **và** công tắc, API 404 `feature_disabled`, frontend ẩn hẳn (`useFeature()`), không xóa dữ liệu, `php artisan features:status`, `make e2e-features-off`, 413 test
- [x] Nghiên cứu lần 3 (roadmap #17–20): bỏ qua ngày bán đột biến (mọi gói, tắt được trong Settings, giữ nguyên khi lặp lại hằng tuần), doanh thu mất do hết hàng (`lost_units_30d`, mục ở Phân tích), chu kỳ đặt hàng theo NCC (`suppliers.order_cycle_days`), kế hoạch nhập hàng 12 tuần (`/purchase-plan`, Starter+); công tắc `FEATURE_SPIKE_FILTER`/`FEATURE_LOST_SALES`/`FEATURE_PURCHASE_PLAN`, bật trong v1, 439 test + E2E
- [x] Nghiên cứu lần 4 (roadmap #21–23): ngừng nhập sản phẩm (`variants.discontinued`, mọi gói, trạng thái riêng, ngoài giới hạn Free, không gợi ý/cảnh báo/kế hoạch), độ chính xác dự báo (`forecast_snapshots` hằng tuần, `/api/accuracy` 1 − WAPE + độ lệch, mục ở Phân tích + dòng ở trang sản phẩm, `FEATURE_ACCURACY`), xuất CSV kế hoạch nhập (Starter), 455 test + 72 E2E
- [x] Ngày đặt hàng theo NCC + lịch đặt hàng (roadmap #24): `suppliers.order_weekdays`, ngày cần đặt dời về ngày đặt hàng liền trước (`ForecastCalculator::onOrderDay`, dùng chung cho forecast/what-if/kế hoạch nhập), email tự động cho NCC chỉ gửi vào ngày đó, mục "Lịch đặt hàng" ở Kế hoạch nhập, 460 test + 75 E2E. Chỉ merge local, chưa deploy
- [ ] Việc sắp tới: xem mục "Việc sắp tới" trong `docs/ROADMAP.md`
- [x] CI/CD cấu hình: `backend/.env` production dựng từ GitHub Secrets/Variables (`deploy/envtool.py render` trong Deploy, `push` để nhập qua `gh`), đổi file an toàn trong `deploy.sh` (trả lại bản cũ nếu dừng trước khi đổi container), tag `current` = chỉ áp dụng config; CI build thử 2 image Docker + kiểm tra template `.env`; xoay `APP_KEY` qua `APP_PREVIOUS_KEYS` + `app:reencrypt-secrets`, mất key thì token exchange cấp token mới (không coi là cài lại), 442 test
- [ ] Phase 7: Chuẩn bị nộp App Store. Phần làm trong repo đã xong (xem `docs/APP_STORE.md`): bộ tính năng v1 (Free + Starter; tắt NCC email, chi nhánh, chuyển kho, cảnh báo tức thời, Flow), `shopify.app.production.toml` (5 scope, không write_orders), `backend/.env.production.example`, billing tự dùng test charge trên dev store, `app:preflight`, privacy/support hoàn chỉnh, hướng dẫn reviewer + listing nháp. Còn lại là việc của chủ app (Partner Dashboard, hosting, SPF/DKIM, icon/ảnh/screencast, nộp)
