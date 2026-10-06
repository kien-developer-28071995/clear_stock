# Clear Stock Admin: báo cáo riêng cho chủ app

Laravel + Blade thuần (không React, không bước build). Chỉ chủ app dùng: xem bao nhiêu shop đang cài, bao nhiêu shop đã gỡ, shop nào dùng tính năng nào, và app có đang chạy ổn không.

Hệ thống này **chỉ đọc** database của app Clear Stock (connection `app`) và có database SQLite riêng cho tài khoản đăng nhập và sổ theo dõi cài/gỡ.

## Vì sao cần sổ riêng

App xoá toàn bộ dữ liệu của shop 48 giờ sau khi gỡ (webhook `shop/redact`), kể cả dòng trong bảng `shops`. Nếu chỉ đếm trong database của app thì số shop đã gỡ luôn gần bằng 0.

Lệnh `report:sync` (tự chạy mỗi 15 phút) chép trạng thái các shop vào sổ `shop_records` và ghi mỗi thay đổi thành một sự kiện trong `shop_events`: cài, cài lại, gỡ, đổi gói, dữ liệu bị xoá. Khi app đã xoá shop, sổ chỉ giữ ngày tháng và gói, **xoá tên và domain**, nên vẫn đúng yêu cầu xoá dữ liệu của Shopify.

Mỗi ngày sổ lưu thêm một dòng tổng (`daily_stats`): số shop đang cài, đang hoạt động, trả tiền, MRR, số shop dùng từng tính năng. Cột "so với 30 ngày trước" ở trang Feature usage lấy từ đây, nên nó chỉ có số sau khi hệ thống chạy được 30 ngày.

## Các trang

| Trang | Nội dung |
|---|---|
| **Overview** (`/`) | Shop đang cài, đã gỡ, trả tiền, đang dùng thử, MRR ước tính, shop hoạt động, đã xong onboarding. Biểu đồ cài/gỡ theo tuần (12 tuần). Bảng theo gói. Shop đã gỡ ở lại bao lâu (trong ngày, tuần đầu, tháng đầu), bao nhiêu shop từng trả tiền. Hoạt động gần nhất. |
| **Shops** (`/shops`) | Danh sách mọi shop từng cài. Lọc theo trạng thái, gói, tính năng đang dùng, tìm theo tên/domain. Xuất CSV. |
| **Shop** (`/shops/{id}`) | Gói, MRR, ngày cài/gỡ, số ngày ở lại, trạng thái đồng bộ, số sản phẩm/NCC/email, **từng tính năng shop có dùng hay không**, lịch sử sự kiện. |
| **Feature usage** (`/features`) | Mỗi tính năng: bao nhiêu shop dùng, tỷ lệ trên shop đang cài, chia theo gói, thay đổi so với 30 ngày trước. Phân nhóm shop theo số tính năng đang dùng. |
| **Health** (`/health`) | Shop đồng bộ lỗi, dự báo cũ hơn 36 giờ, shop cài rồi chưa đồng bộ, số lần đồng bộ, email gửi/lỗi theo loại, job lỗi. |

## Cách đếm "shop dùng tính năng"

Dựa trên dữ liệu shop đã lưu: có nhà cung cấp, có combo, có đơn đánh dấu đã đặt, có bật cảnh báo... Danh sách ở `app/Reports/FeatureUsage.php`, mỗi tính năng khai báo bảng và cột nó cần.

- App đang chạy bản cũ chưa có cột đó thì tính năng hiện "Not in the deployed version of the app yet", không báo lỗi. Thêm tính năng mới vào app chỉ cần thêm một mục vào danh sách.
- **Không đếm được** tính năng không lưu gì: mô phỏng what-if, kế hoạch nhập, các trang Phân tích, xuất PO/CSV. Xem "Việc tiếp theo".
- Chỉ đếm shop đang cài.

MRR là **ước tính** theo giá niêm yết trong `config/report.php`: shop đăng ký ở giá cũ giữ giá cũ, shop đang dùng thử chưa trả tiền.

## Chạy trên máy (dev)

```bash
make admin-setup
```

```bash
make admin-user EMAIL=you@example.com
```

Mở http://localhost:8090. Cổng chỉ mở trên máy này (`127.0.0.1`). `make admin-setup` tạo `admin/.env` với thông tin database lấy từ `app/backend/.env`.

Không có trang đăng ký. Tài khoản chỉ tạo bằng `admin:user`; chạy lại lệnh để đổi mật khẩu.

```bash
make admin-test
```

## Production

Chạy cạnh app trên server, trong container `admin` của `docker-compose.prod.yml` (image `…/clear_stock-admin`, build từ thư mục này). Container chỉ mở cổng `127.0.0.1:8090` trên server, không ra Internet. File SQLite nằm trên volume `admin-data`, nên deploy lại không mất sổ cài/gỡ. `report:sync` chạy mỗi 15 phút trong chính container đó.

**Cài lần đầu (một lần, trên server, trong thư mục deploy):**

```bash
bash admin-setup.sh
```

Script tạo `admin/.env` (APP_KEY riêng) và một user MySQL `report` **chỉ có quyền SELECT** trên database của app. Từ lần deploy kế tiếp, `deploy.sh` tự kéo image và bật container (chưa có `admin/.env` thì bỏ qua; admin lỗi cũng không ảnh hưởng app).

**Tạo tài khoản của bạn (trên server):**

```bash
docker compose -f docker-compose.prod.yml exec admin php artisan admin:user you@example.com
```

**Mở từ máy cá nhân qua SSH tunnel:**

```bash
ssh -N -L 8090:127.0.0.1:8090 user@server
```

Rồi mở http://localhost:8090.

**Muốn có domain riêng** (không bắt buộc): thêm bản ghi DNS, bỏ comment khối `admin.example.com` trong `deploy/Caddyfile` (đã có sẵn luật chỉ cho IP của bạn), đặt `REPORT_ALLOWED_IPS` và `APP_URL=https://…` trong `admin/.env`, rồi `bash deploy.sh current`.

Chạy trên máy cá nhân với dữ liệu production cũng được (tunnel tới MySQL, điền `APPDB_*`), nhưng máy tắt thì `report:sync` không chạy: shop gỡ rồi bị xoá sau 48 giờ sẽ bị ghi ngày gỡ muộn.

## Bảo mật

- Mọi trang cần đăng nhập. Sai mật khẩu 5 lần từ một IP thì khoá 15 phút.
- `REPORT_ALLOWED_IPS`: chỉ các IP này mở được, kể cả trang đăng nhập (IP khác nhận 404).
- Không đọc và không hiện token Shopify. Không ghi gì vào database của app.
- `noindex` trên mọi trang.

## Kế hoạch tính năng

Đã làm (bản đầu):

1. Sổ cài/gỡ/đổi gói sống sót qua `shop/redact`.
2. Tổng quan: cài, gỡ, trả tiền, MRR, hoạt động, onboarding, theo tuần.
3. Danh sách shop, lọc theo tính năng, xuất CSV.
4. Trang shop: tính năng đang dùng, lịch sử.
5. Mức độ dùng từng tính năng, chia theo gói, so với tháng trước.
6. Sức khoẻ hệ thống.

Việc tiếp theo, xếp theo giá trị:

1. **Usage events trong app.** Thêm bảng `feature_events` (shop_id, feature, ngày, số lần) ở backend, ghi mỗi lần mở trang what-if, kế hoạch nhập, Phân tích, xuất file. Đây là cách duy nhất biết các tính năng không lưu dữ liệu có ai dùng. Cần sửa app chính nên chưa làm ở đây.
2. **Cohort giữ chân.** Shop cài tuần N còn lại bao nhiêu sau 1, 2, 4, 8 tuần. Sổ sự kiện đã đủ dữ liệu.
3. **Phễu chuyển đổi.** Cài → onboarding → đồng bộ xong → thêm NCC → dùng thử → trả tiền, kèm thời gian giữa các bước.
4. **Doanh thu thật từ Shopify Partner API** (payouts, app charges) thay cho MRR ước tính. Cần token Partner API.
5. **Lý do gỡ.** Shopify gửi lý do gỡ trong Partner Dashboard, lấy qua Partner API (`app events`).
6. **Tin tóm tắt hằng ngày** gửi Slack/email cho chủ app: cài mới, gỡ, đổi gói, lỗi đồng bộ.
7. **Ghi chú theo shop** (đã liên hệ, đang hỗ trợ) và gắn thẻ.
8. **So sánh shop trả tiền với shop Free**: tính năng nào xuất hiện nhiều ở shop trả tiền, để biết nên đẩy tính năng nào trong onboarding.
9. **Quy mô shop**: số sản phẩm, số đơn/ngày (từ `daily_sales`), để biết nhóm khách chính.
