# Clear Stock: danh sách tính năng và gói

Cập nhật: 2026-10-04. Nguồn: `backend/config/billing.php` (gói nào có gì), `backend/config/features.php` (công tắc bật/tắt toàn app) và `backend/.env.production.example` (bản v1 nộp App Store).

## Gói và giá

| Gói | Giá tháng | Giá năm | Giới hạn |
|---|---|---|---|
| **Free** | $0 | $0 | Dự báo 50 sản phẩm bán chạy nhất (sản phẩm ngừng nhập không tính vào giới hạn) |
| **Starter** | $4 | $38 (giảm ~20%) | Không giới hạn sản phẩm, dùng thử 7 ngày (1 lần/shop) |
| **Growth** | $6 | $58 (giảm ~20%) | Như Starter + đa chi nhánh và tự động hoá, dùng thử 7 ngày |

Giá cố định, không tính theo doanh thu, không hợp đồng, huỷ bất cứ lúc nào. Cam kết "Giữ giá": shop đang trả tiền giữ nguyên giá tới khi tự đổi gói.

Ký hiệu: ✅ có · — không có. Cột **Công tắc** là biến `FEATURE_*` tắt tính năng cho mọi shop (không có = tính năng lõi, luôn bật). Cột **v1** là trạng thái trong bản nộp App Store đầu tiên (`.env.production.example`): v1 chỉ bán Free và Starter (`BILLING_GROWTH_OFFERED=false`).

## 1. Dự báo (lõi)

| Tính năng | Mô tả | Free | Starter | Growth | Công tắc | v1 |
|---|---|:-:|:-:|:-:|---|:-:|
| Dự báo ngày hết hàng | Tốc độ bán có trọng số 7/30/90 ngày, bỏ ngày hết hàng, hệ số mùa vụ theo năm trước | ✅ 50 SP | ✅ | ✅ | — | Bật |
| Điểm đặt lại + gợi ý số lượng nhập | Lead time + tồn an toàn + chu kỳ đặt hàng, trừ hàng đang về | ✅ | ✅ | ✅ | — | Bật |
| Giải thích "Vì sao con số này?" | Mọi con số đều có câu giải thích, mở cho mọi gói (lời hứa minh bạch) | ✅ | ✅ | ✅ | — | Bật |
| Độ tin cậy | Thấp / trung bình / cao, kèm lý do | ✅ | ✅ | ✅ | — | Bật |
| Điều chỉnh tay | Ghi đè tốc độ bán (có hạn), lead time, tồn an toàn | ✅ | ✅ | ✅ | — | Bật |
| Min/Max thủ công | Điểm đặt lại và mức nhập tới do merchant đặt | ✅ | ✅ | ✅ | — | Bật |
| MOQ / quy cách thùng | Theo sản phẩm hoặc mặc định theo NCC, làm tròn lượng đặt | ✅ | ✅ | ✅ | — | Bật |
| Trừ hàng đang về | Đọc `incoming` của Shopify (PO, chuyển kho) | ✅ | ✅ | ✅ | — | Bật |
| Bỏ qua đột biến bán hàng | Ngày bán > 5× bình thường tính theo mức thường; tắt được trong Settings | ✅ | ✅ | ✅ | `FEATURE_SPIKE_FILTER` | Bật |
| **Sự kiện bán hàng** | Black Friday, khuyến mãi, nghỉ lễ: tăng/giảm nhu cầu theo ngày; ngày sự kiện đã qua quy về mức thường | ✅ | ✅ | ✅ | `FEATURE_SALES_EVENTS` | Bật |
| **Ngừng nhập sản phẩm** | Chỉ bán hết tồn: không gợi ý, không cảnh báo, không tính vào giới hạn Free | ✅ | ✅ | ✅ | — | Bật |
| Dự báo sản phẩm mới theo SP tương tự | Mượn tốc độ bán của sản phẩm tham chiếu tới khi đủ 30 ngày | — | ✅ | ✅ | `FEATURE_REFERENCE_PRODUCTS` | Bật |
| Combo / bộ (bundle) | Doanh số combo cộng vào nhu cầu từng thành phần | — | ✅ | ✅ | — | Bật |
| Dự báo theo từng chi nhánh | Tồn và dự báo riêng mỗi location | — | — | ✅ | `FEATURE_LOCATIONS` | Tắt |

## 2. Nhập hàng và nhà cung cấp

| Tính năng | Mô tả | Free | Starter | Growth | Công tắc | v1 |
|---|---|:-:|:-:|:-:|---|:-:|
| Danh sách Cần nhập hàng | Hết hàng / đặt hôm nay / tuần này, chọn nhiều | ✅ | ✅ | ✅ | — | Bật |
| Nhà cung cấp + lead time | Lead time theo NCC, gán sản phẩm | ✅ | ✅ | ✅ | — | Bật |
| Chu kỳ đặt hàng theo NCC | Mỗi đơn phủ N ngày bán | ✅ | ✅ | ✅ | — | Bật |
| **Ngày đặt hàng theo NCC** | Chỉ đặt vào thứ cố định (vd. T2, T5); ngày cần đặt dời về ngày đặt gần nhất | ✅ | ✅ | ✅ | — | Bật |
| Nhập NCC từ Stocky (CSV) | Tạo NCC, gán sản phẩm, đo lead time từ đơn cũ | ✅ | ✅ | ✅ | — | Bật |
| Tạo NCC từ Vendor Shopify | Một lần bấm | ✅ | ✅ | ✅ | — | Bật |
| **Đánh dấu "Đã đặt hàng"** | Đơn đặt ngoài Shopify tính là đang về, tránh gợi ý lặp; trang Đơn đã đặt | ✅ | ✅ | ✅ | — | Bật |
| Xuất đơn đặt hàng (CSV) | File dễ đọc hoặc đúng mẫu nhập PO của Shopify | — | ✅ | ✅ | `FEATURE_PURCHASE_ORDERS` | Bật |
| Gửi đơn cho NCC qua email (tay) | Xem/sửa rồi gửi, Reply-To về merchant, tự ghi "đã đặt" | — | ✅ | ✅ | `FEATURE_SUPPLIER_EMAILS` | Tắt |
| Tự động gửi đơn cho NCC | Theo ngày đặt hàng của NCC (hoặc tối đa 1 lần/tuần) | — | — | ✅ | `FEATURE_SUPPLIER_EMAILS` | Tắt |
| Gợi ý chuyển kho | Chi nhánh thừa → chi nhánh sắp hết, tạo phiếu chuyển nháp trong Shopify | — | — | ✅ | `FEATURE_TRANSFERS` (cần `FEATURE_LOCATIONS`) | Tắt |

## 3. Lập kế hoạch

| Tính năng | Mô tả | Free | Starter | Growth | Công tắc | v1 |
|---|---|:-:|:-:|:-:|---|:-:|
| Kế hoạch nhập hàng 4/8/12 tuần | Đơn và chi phí theo tuần, theo NCC, theo sản phẩm | — | ✅ | ✅ | `FEATURE_PURCHASE_PLAN` | Bật |
| **Lịch đặt hàng** | Trong Kế hoạch nhập: ngày × NCC | — | ✅ | ✅ | `FEATURE_PURCHASE_PLAN` | Bật |
| **Xuất CSV kế hoạch nhập** | Mỗi dòng một lần đặt (cần cả quyền xuất PO) | — | ✅ | ✅ | `FEATURE_PURCHASE_PLAN` + `FEATURE_PURCHASE_ORDERS` | Bật |
| **Ngân sách nhập hàng** | Ngân sách tháng, xếp hạng việc cần nhập, phần vượt thì chờ; kế hoạch nhập so theo tháng | — | ✅ | ✅ | `FEATURE_ORDER_BUDGET` | Bật |
| Hồ sơ dự báo | Chọn cách lấy trung bình cho cả shop hoặc từng sản phẩm: Cân bằng (7/30/90), Theo doanh số gần đây, Ổn định (tới 365 ngày) | ✅ | ✅ | ✅ | — | Bật |
| Xu hướng bán | 14 ngày gần đây so với 42 ngày trước; nhãn ↑/↓, bộ lọc ở danh sách, dòng giải thích | ✅ | ✅ | ✅ | — | Bật |
| Email tóm tắt hằng tuần | 1 email/tuần (bật tay): cần đặt gì, hết hàng, tiền kẹt, doanh thu mất, giá trị tồn | ✅ | ✅ | ✅ | `FEATURE_WEEKLY_SUMMARY` | Bật |
| Cảnh báo qua Slack + ngưỡng ngày tồn | Digest gửi thêm vào kênh Slack; cảnh báo khi còn dưới N ngày tồn | — | ✅ | ✅ | — (theo Cảnh báo email) | Bật |
| Lịch sử giá trị tồn kho | Ghi mỗi ngày (số cái + giá trị theo giá vốn), biểu đồ ở Phân tích, giữ 2 năm | ✅ | ✅ | ✅ | — | Bật |
| Kiểm tra dữ liệu | Tồn âm, không theo dõi tồn, thiếu giá vốn/SKU/giá/NCC, SKU trùng, lead time mặc định | ✅ | ✅ | ✅ | — | Bật |
| Nhận hàng từng phần | Đơn đã đặt: nhập số đã nhận, phần còn lại vẫn tính là đang về | ✅ | ✅ | ✅ | — | Bật |
| Lead time thực tế theo NCC | Trung vị số ngày từ đặt tới nhận (≥ 3 đơn), một bấm để áp dụng | ✅ | ✅ | ✅ | — | Bật |
| Mã hàng của NCC | Lưu theo sản phẩm, in trên PO (cột Supplier SKU của Shopify) và email đặt hàng | ✅ | ✅ | ✅ | — | Bật |
| Loại chi nhánh khỏi tồn | Kho hàng trả/hàng lỗi/showroom không tính vào tồn dự báo | ✅ | ✅ | ✅ | — | Bật |
| Xuất danh sách sản phẩm (CSV) | Toàn bộ danh sách theo bộ lọc đang chọn | ✅ | ✅ | ✅ | — | Bật |
| Mô phỏng tăng trưởng (what-if) | "+20% doanh số thì cần đặt gì" | — | ✅ | ✅ | `FEATURE_WHAT_IF` | Bật |
| **Xuất CSV kịch bản what-if** | Đơn theo kịch bản (cần quyền xuất PO) | — | ✅ | ✅ | `FEATURE_WHAT_IF` + `FEATURE_PURCHASE_ORDERS` | Bật |

## 4. Phân tích

| Tính năng | Mô tả | Free | Starter | Growth | Công tắc | v1 |
|---|---|:-:|:-:|:-:|---|:-:|
| Home: 4 chỉ số + 5 SP gấp nhất | Kèm setup guide và trạng thái đồng bộ | ✅ | ✅ | ✅ | — | Bật |
| Đường băng tồn kho | Số ngày tồn còn lại theo sản phẩm | ✅ | ✅ | ✅ | — | Bật |
| Hàng bán chậm + tiền kẹt | Tồn > 180 ngày | ✅ | ✅ | ✅ | — | Bật |
| Tồn thừa | Vượt mức cần giữ > 50% | ✅ | ✅ | ✅ | — | Bật |
| Phân loại ABC | Theo doanh thu 90 ngày; **doanh thu combo chia về thành phần** (gói có combo) | ✅ | ✅ | ✅ | `FEATURE_ABC` | Bật |
| Doanh thu mất do hết hàng | 30 ngày gần nhất | ✅ | ✅ | ✅ | `FEATURE_LOST_SALES` | Bật |
| **Độ chính xác dự báo** | Dự báo mỗi tuần so với bán thực tế 4 tuần sau (1 − WAPE, độ lệch, xu hướng) | ✅ | ✅ | ✅ | `FEATURE_ACCURACY` | Bật |
| **Hàng ngừng nhập còn tồn** | Số cái và tiền cần bán hết | ✅ | ✅ | ✅ | — | Bật |

## 5. Cảnh báo và tự động hoá

| Tính năng | Mô tả | Free | Starter | Growth | Công tắc | v1 |
|---|---|:-:|:-:|:-:|---|:-:|
| Email tóm tắt (ngày/tuần) | Tối đa 1 email/ngày, chống spam | — | ✅ | ✅ | — | Bật |
| Tắt cảnh báo từng sản phẩm | | — | ✅ | ✅ | — | Bật |
| Cảnh báo tức thời | Khi tồn vượt ngưỡng, gom 15 phút, tối đa 3 email/ngày, không gửi ban đêm | — | — | ✅ | `FEATURE_REALTIME_ALERTS` | Tắt |
| Shopify Flow triggers | Đến ngày đặt, sắp hết (30/14/7/0 ngày), NCC tới hạn | — | — | ✅ | `FEATURE_FLOW_TRIGGERS` | Tắt |
| Báo khi đồng bộ lỗi liên tục | 1 email/chuỗi lỗi (≥ 2 lần lỗi + 24h) | ✅ | ✅ | ✅ | — | Bật |

## 6. Dữ liệu, cài đặt, tích hợp

| Tính năng | Mô tả | Free | Starter | Growth | Công tắc | v1 |
|---|---|:-:|:-:|:-:|---|:-:|
| Đồng bộ Shopify | Bulk Operation lần đầu, mỗi đêm chỉ lấy phần thay đổi | ✅ | ✅ | ✅ | — | Bật |
| **Giá vốn nhập trong app** | Trang Giá vốn, sửa hàng loạt, nhập CSV (dùng thẳng file xuất của Shopify); thắng giá Shopify | ✅ | ✅ | ✅ | — | Bật |
| Block dự báo trên trang sản phẩm Shopify | Admin UI extension; **cũng có trên trang biến thể**, kèm dòng "đã đặt" | ✅ | ✅ | ✅ | — | Bật |
| **Đánh dấu đã đặt từ trang sản phẩm/biến thể Shopify** | More actions → số lượng gợi ý điền sẵn, ngày dự kiến, mã đơn | ✅ | ✅ | ✅ | — | Bật |
| Cài đặt nhập hàng từ Shopify | Danh sách sản phẩm (chọn nhiều) **và trang sản phẩm**: NCC, lead time, tồn an toàn, **MOQ, quy cách thùng, ngừng nhập** | ✅ | ✅ | ✅ | — | Bật |
| Onboarding 2 câu hỏi + setup guide | | ✅ | ✅ | ✅ | — | Bật |
| **Ngôn ngữ** | EN, VI, **ES, DE, FR, PT**; theo ngôn ngữ admin hoặc chọn trong Settings (email vẫn tiếng Anh) | ✅ | ✅ | ✅ | — | Bật |
| Website giới thiệu (`website/`) | Trang chủ, bảng giá, Privacy, Support; 6 ngôn ngữ; `/privacy`, `/support` của app chuyển sang đây | ✅ | ✅ | ✅ | — | Bật |

Tính năng in **đậm** là tính năng mới thêm ngày 2026-09-27; code đã merge vào `main` ở local và chưa deploy.

## Khi hạ gói

Không xoá dữ liệu hay cài đặt. Hộp xác nhận trên trang Gói liệt kê những gì shop sẽ mất, tính trên dữ liệu thật của shop: sản phẩm vượt 50, combo, email cảnh báo, chi nhánh, PO, NCC tự gửi, Flow, sản phẩm tham chiếu, what-if, kế hoạch nhập, ngân sách. Nâng gói lại thì mọi thứ hoạt động như cũ.
