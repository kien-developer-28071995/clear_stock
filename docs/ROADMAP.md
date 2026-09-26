# Roadmap: tính năng mới từ nghiên cứu đối thủ

Nghiên cứu ngày 2026-09-26 (Shopify App Store, review 1–3 sao, bài viết về Stocky). Mỗi mục ghi lý do để khi lên kế hoạch không phải tìm lại.

## Bối cảnh thị trường

- **Stocky (app tồn kho của Shopify) đã đóng**: gỡ khỏi App Store 2026-02-02, tắt hẳn 2026-08-31. Merchant còn quyền xuất dữ liệu read-only khoảng 90 ngày (tới khoảng cuối 11/2026); danh sách nhà cung cấp không xuất được. Công cụ tồn kho có sẵn trong Shopify admin không có dự báo. → Cửa sổ thu hút người dùng Stocky đang mở, cần làm nhanh.
- **Đối thủ trực tiếp cùng định vị**: Stockcast (ra mắt 2026-05, "clear, explainable math", Free 25 SKU, $19/$29/$59, có import CSV đơn đặt hàng Stocky). Giá của ta ($4/$6 từ 2026-09-26, trước đó $2/$3 rồi $4/$5) vẫn rẻ hơn khoảng 5 lần.

| App | Giá | Đánh giá | Điểm đáng chú ý |
|---|---|---|---|
| Stockcast | Free 25 SKU, $19–59 | chưa có | Giải thích bằng công thức, import từ Stocky |
| IFH Inventory Forecasting Hero | $25 | 5.0 (23) | Mô phỏng tăng trưởng doanh số, tạo Shopify PO |
| Monocle | $47–129 (theo doanh thu) | 4.5 (30) | ABC-XYZ, market basket, tự sửa dữ liệu ngày hết hàng |
| Prediko | $49–199 (theo doanh thu) | 4.9 (254) | 10 ngôn ngữ, BOM, chuyển kho |
| Assisty | $19–39 | 4.8 (359) | Rẻ nhất trong nhóm AI |
| Inventory Planner (Sage) | báo giá, hợp đồng năm | 4.3 (154) | Đang bỏ khách nhỏ (< $1M/năm) |

## Than phiền lặp lại trong review xấu (và vị thế của ta)

| Than phiền | Ví dụ | Clear Stock |
|---|---|---|
| Giá tính theo doanh thu, tăng giá khi gia hạn, bị khóa hợp đồng | Sage (x2 khi gia hạn), Assisty ($19 → $39, bị cắt tính năng) | Giá cố định, không hợp đồng. **Cần thêm: cam kết giữ giá cho khách cũ** |
| Dự báo hộp đen, không chỉnh được | Prediko, Sage | Có giải thích + override (lợi thế cốt lõi) |
| Không dự báo được bundle | Prediko (1 sao sau nhiều tháng hỗ trợ) | Đã có |
| Spam email | Prediko | Tối đa 1 digest/ngày |
| Đồng bộ lỗi âm thầm nhiều ngày | Sage (21+ ngày) | Có trạng thái sync. **Cần thêm: báo khi sync lỗi liên tục** |
| Onboarding dài, cần hơn 1 năm dữ liệu | Sage (10+ tuần) | Onboarding khoảng 5 phút, confidence thấp khi ít dữ liệu |
| Sản phẩm mới / theo mùa dự báo kém | Sage | Một phần (override tốc độ bán) |

## Việc cần làm

### Ưu tiên cao: làm trước Phase 7 (nộp App Store)

1. ✅ **Chuyển từ Stocky** (xong 2026-09-26, trừ phần listing)
   - ✅ Import CSV đơn đặt hàng (Stocky hoặc app khác) → tạo suppliers, gán sản phẩm theo lần đặt gần nhất, lead time = trung vị ngày đặt → ngày nhận/dự kiến. Tự nhận cột + merchant sửa được, xem trước rồi mới lưu. Trang Suppliers → "Import from Stocky".
   - ✅ Setup guide (bước nhà cung cấp) nhắc tới import từ Stocky.
   - ⏳ Listing App Store có thông điệp "Stocky alternative": làm ở Phase 7.
   - Chưa kiểm chứng với file Stocky thật (tên cột Stocky không được công bố; bộ nhận diện dựa trên alias + kiểm tra giá trị ngày).
   - Lý do: người dùng Stocky đang tìm app thay thế ngay; dữ liệu Stocky chỉ còn xuất được đến khoảng cuối 11/2026.
2. ✅ **Hàng đang về (incoming / on order) trừ vào gợi ý nhập** (xong 2026-09-26)
   - Hiện app có thể gợi ý nhập lại cả phần hàng đã đặt nhưng chưa về.
   - Shopify có trạng thái tồn kho `incoming`; **đọc docs Admin GraphQL trước** để xác nhận field và scope.
   - Explanation thêm dòng "Đã có N cái đang về".
3. ✅ **Làm tròn theo MOQ / quy cách thùng** (xong 2026-09-26, theo sản phẩm; chưa có mặc định theo nhà cung cấp)
   - MOQ và pack size theo sản phẩm hoặc nhà cung cấp; suggested_qty làm tròn lên (59 → 60 khi thùng 12).
   - Ghi vào explanation. Rẻ, merchant hay xin.
4. ✅ **Min/Max thủ công** (xong 2026-09-26)
   - `variants.min_stock` / `max_stock` (cài đặt cố định của sản phẩm, không dùng override vì override có hạn). Min thay điểm đặt hàng lại (so với tồn + đang về), Max thay mức nhập tới; vẫn làm tròn MOQ/thùng. Sản phẩm chưa bán được chỉ đặt hàng qua Min. Áp dụng cho dự báo tổng cửa hàng, không chia theo location.

### Sau khi ra mắt

5. ~~**Vòng đời đơn đặt hàng**~~ → **thay bằng mục 11** (dùng Purchase Order gốc của Shopify, xem bên dưới). Gửi email cho NCC đã xong (2026-09-26).
6. ✅ **Phân loại ABC** (xong 2026-09-26): nhóm A/B/C theo doanh thu 90 ngày = (bán − trả) × giá bán hiện tại (đồng bộ `variants.price`, không lưu giá trong đơn). A = 80% doanh thu đầu, B tới 95%, C phần còn lại (`forecast.abc`). Tính lại ở mỗi lần chạy forecast đầy đủ, xếp hạng mọi sản phẩm được theo dõi (cả ngoài giới hạn gói Free). Cột + bộ lọc + sắp xếp theo doanh thu ở danh sách sản phẩm, dòng giải thích ở trang sản phẩm, bảng tóm tắt ở trang Phân tích. Mở cho mọi gói. Chưa làm: doanh thu combo chưa chia về thành phần.
7. ✅ **Mô phỏng tăng trưởng (what-if)** (xong 2026-09-26): trang `/what-if` (menu "Mô phỏng"), nhập % thay đổi doanh số (−90…+500, nút nhanh −20/+10/+20/+50/+100), khoảng đơn cần đặt (hôm nay / 14 / 30 ngày), lọc NCC/Vendor/ABC. Tốc độ bán × (1 + %), chạy đúng phép tính đặt hàng của forecast (`ForecastCalculator::reorderPlan`, giữ lead time, safety, Min/Max, MOQ, thùng), không lưu gì. So sánh hiện tại → kịch bản: số sản phẩm cần đặt, số lượng, chi phí theo giá vốn, số sản phẩm hết trước khi hàng về. Mọi gói. Chưa làm: xuất PO theo kịch bản, áp kịch bản thành điều chỉnh tạm thời.
8. **Gợi ý chuyển kho giữa location** (gói Growth): location thừa → location sắp hết.
9. **Dự báo sản phẩm mới theo sản phẩm tương tự**: chọn một sản phẩm tham chiếu, dùng tốc độ bán của nó khi chưa đủ lịch sử.
10. ✅ **Báo khi sync lỗi liên tục + cam kết giữ giá** (xong 2026-09-26, mọi gói): email một lần khi ≥ 2 lần sync lỗi liên tiếp và không có sync thành công trong 24h (`sync.failure_email`), gửi tới email cảnh báo hoặc email liên hệ shop kể cả khi tắt cảnh báo, reset khi sync thành công. Trang Gói có dòng "Giữ giá". Listing: Phase 7.

## Nghiên cứu bổ sung (2026-09-26, lần 2)

### Phát hiện
- **Shopify đã có Purchase Order gốc trong admin nhưng chưa có API** (không có object/mutation PurchaseOrder tới bản 2026-07). PO gốc **nhập được từ CSV** (nhận diện biến thể theo SKU và/hoặc barcode, số lượng, giá vốn và thuế tùy chọn); nhận hàng đi qua inventory transfer, tạo ra số `incoming` mà app đã đọc. → Không nên tự xây vòng đời PO; nên **khớp với PO gốc**.
- **Thêm đối thủ cùng định vị "giải thích được"**: Foreshelf (ra mắt 2026-07-30, Free 50 SKU, $19/$49, có overstock, nhận hàng bằng barcode). Cùng với Stockcast, "minh bạch" không còn là khác biệt duy nhất → khác biệt phải đến từ **giá + combo + đa ngôn ngữ + tích hợp sâu vào Shopify**.
- Review IFH: merchant muốn **lọc/gom theo nhà sản xuất (vendor) và loại sản phẩm** để đặt cùng lúc; muốn lịch sử dài hơn 60 ngày (ta có 400 ngày → điểm bán hàng).
- Shopify Flow có trigger tồn kho sẵn nhưng chỉ theo ngưỡng cố định; app có thể cung cấp **trigger dựa trên dự báo** qua Flow trigger extension.
- Admin UI extension cho phép **block trên trang sản phẩm / biến thể** (`admin.product-details.block.render`, `admin.product-variant-details.block.render`) và **hành động hàng loạt** trên trang danh sách sản phẩm; gọi backend app bằng ID token tự động.

### Tính năng đề xuất (xếp theo ưu tiên)

11. **Tạo Purchase Order gốc của Shopify từ gợi ý** (ưu tiên cao)
    - Nút "Tạo PO trong Shopify": xuất CSV đúng mẫu nhập PO của Shopify (SKU/barcode, số lượng, giá vốn) theo nhà cung cấp, kèm hướng dẫn / link mở trang tạo PO trong admin.
    - PO gốc → incoming → app đã tự trừ khỏi gợi ý: khép kín vòng "gợi ý → đặt → đang về → nhận".
    - Cần: đồng bộ thêm `barcode` của variant; **tải file mẫu CSV thật từ admin Shopify để chốt tên cột trước khi làm**.
12. ✅ **Tự tạo nhà cung cấp từ trường Vendor của Shopify** (xong 2026-09-26: đồng bộ `vendor` + `product_type`, trang `/suppliers/from-vendors` xem trước rồi tạo/dùng lại NCC và gán sản phẩm, bỏ chọn sẵn vendor trùng tên cửa hàng, bộ lọc Vendor/Loại sản phẩm ở Sản phẩm và Vendor ở Cần nhập hàng, setup guide gợi ý. Lưu ý: đồng bộ đêm chỉ lấy biến thể vừa đổi, nên đổi Vendor trên Shopify cần lần đồng bộ đầy đủ mới cập nhật hết)
    - Hầu hết shop đã điền Vendor cho sản phẩm → onboarding đề xuất "Tạo 8 nhà cung cấp từ Vendor và gán 214 sản phẩm" một lần bấm (xem trước như import Stocky).
    - Thêm bộ lọc Vendor / Product type trong danh sách sản phẩm và trang Cần nhập hàng (đáp ứng yêu cầu "đặt cùng nhà sản xuất").
13. **Block dự báo trên trang sản phẩm Shopify** (admin UI extension)
    - Hiện "Hết hàng khoảng 7/10 · nên nhập 153 · vì sao" ngay trong trang sản phẩm/biến thể của admin, link vào app. Merchant thấy giá trị mà không cần mở app; tăng cảm giác "native" (tốt cho Built for Shopify).
    - Kèm hành động hàng loạt trên danh sách sản phẩm Shopify: "Gán nhà cung cấp / lead time".
14. ✅ **Shopify Flow triggers dựa trên dự báo** (xong 2026-09-26, Growth: 3 trigger `product-reorder-date-reached`, `product-stockout-threshold-reached` (ngưỡng 30/14/7/0), `supplier-reorder-date-reached`; chỉ gửi cho shop có workflow đang bật (lifecycle callback), mỗi thay đổi một lần, tối đa 250/lần chạy. Chưa thử với Flow thật: cần `app deploy`)
    - "Sản phẩm cần đặt hàng", "Sản phẩm sẽ hết trong N ngày", "Đơn cho nhà cung cấp tới hạn" (biến: SKU, số lượng gợi ý, ngày, nhà cung cấp) → merchant tự nối Slack, tag, task… Không ép AI, không spam: merchant tự chọn.
15. ✅ **Trạng thái Overstock rõ ràng** (xong 2026-09-26: `forecasts.target_stock`/`excess_units`, trạng thái "Tồn thừa" khi thừa > 50% mức cần giữ (`forecast.overstock_ratio`), lọc được, mục Tồn thừa + tiền kẹt ở trang Phân tích, ô "Tiền kẹt trong kho" trên Home, dòng giải thích) (Foreshelf có): ngoài "bán chậm", đánh dấu sản phẩm có tồn vượt mức Max / vượt N ngày bán, kèm số tiền kẹt; lọc được.
16. **Nhận hàng / kiểm kho bằng barcode** (thấp, cho nhóm bán lẻ/POS cũ của Stocky): cân nhắc sau vì Shopify admin đã có nhận hàng qua transfer.

Nguồn lần 2: https://community.shopify.dev/t/feature-request-expose-the-existing-purchase-orders-to-the-admin-graphql-api/35229 · https://help.shopify.com/en/manual/products/inventory/purchase-orders/creating-purchase-orders · https://apps.shopify.com/stockahead-1 · https://apps.shopify.com/inventory-forecasting-hero/reviews · https://shopify.dev/docs/apps/build/flow/triggers · https://shopify.dev/docs/api/admin-extensions · https://help.shopify.com/en/manual/shopify-flow/reference/triggers/product-variant-inventory-quantity-changed

## Việc sắp tới (tổng hợp, theo thứ tự đề xuất)

1. ~~**#11 Tạo Purchase Order gốc của Shopify từ gợi ý**~~ — xong: nút xuất PO có thêm định dạng "Đơn đặt hàng Shopify" đúng mẫu `SKU,Barcode,Supplier SKU,Quantity,Cost,Tax` (không có API tạo PO), đồng bộ `variants.barcode`, bỏ qua sản phẩm không có SKU lẫn barcode và báo số lượng.
2. ~~**#13 Block dự báo trên trang sản phẩm Shopify** + gán NCC/lead time hàng loạt~~ — xong: 2 admin UI extension (`extensions/`): block trên trang sản phẩm (dự báo từng biến thể + giải thích + link vào app), hành động "Đặt NCC & lead time" cho sản phẩm được chọn ở danh sách sản phẩm.
3. ~~**#14 Shopify Flow triggers dựa trên dự báo**~~ (xong, Growth).
5. ~~**#10 Báo khi đồng bộ lỗi liên tục** + cam kết giữ giá~~ (xong; câu giữ giá cho listing làm ở Phase 7).
6. ~~**#6 Phân loại ABC**~~ (xong), ~~**#7 Mô phỏng tăng trưởng (what-if)**~~ (xong), ~~**#8 Gợi ý chuyển kho** (Growth)~~ (xong: trang Chuyển kho, tạo phiếu chuyển nháp trong Shopify qua scope tùy chọn `write_inventory_transfers`), **#9 Dự báo sản phẩm mới theo sản phẩm tham chiếu**.
7. **#16 Nhận hàng / kiểm kho bằng barcode** (thấp).
8. **Mặc định MOQ / quy cách thùng theo nhà cung cấp** (hiện chỉ theo từng sản phẩm).
9. **Phase 7 — chuẩn bị nộp App Store**: gỡ scope `write_orders`, `SHOPIFY_BILLING_TEST=false`, listing có "Stocky alternative", ảnh/icon, hướng dẫn test cho reviewer, domain gửi mail có SPF/DKIM.

## Nguồn

- https://apps.shopify.com/stockcast-inventory-forecast
- https://apps.shopify.com/inventory-forecasting-hero
- https://apps.shopify.com/forecast
- https://apps.shopify.com/prediko và review 1–3 sao
- https://apps.shopify.com/inventory-planner và review 1–3 sao
- https://apps.shopify.com/assisty review 1–3 sao
- https://apps.shopify.com/stocky/reviews
- https://starshipit.com/blog-content/stocky-shutting-down
- https://help.shopify.com/en/manual/products/inventory/transitioning-from-stocky
- https://www.charleagency.com/articles/best-alternatives-to-shopify-stocky/
