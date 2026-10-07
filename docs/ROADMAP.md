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
9. ✅ **Dự báo sản phẩm mới theo sản phẩm tương tự** (xong 2026-09-26, Starter+): `variants.reference_variant_id` + `reference_percent`, ước tính = trộn tốc độ bán của sản phẩm tham chiếu × % với tốc độ riêng theo số ngày còn hàng (đủ 30 ngày thì dùng hẳn dữ liệu riêng), override vẫn thắng, giải thích `reference_blend`/`reference_done`, chỉ áp dụng dự báo tổng.
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

## Nghiên cứu bổ sung (2026-09-27, lần 3)

### Phát hiện
- **Forthcast** ($19.99): "lost sales & stockout tracking", "demand spike & anomaly detection", báo cáo độ chính xác dự báo, 24 ngôn ngữ.
- **Forstock** ($79–159 theo doanh thu): tính đến lead time + MOQ + quy cách thùng, loại sản phẩm bán một lần khỏi kế hoạch, dự báo 12 tháng.
- **Assisty** ($19–199): "buying calendar", nhịp đặt hàng theo NCC (supplier cadence), duyệt PO.
- **Inventory Planner (Sage)**: lập kế hoạch dòng tiền (cash flow planning) cho hàng nhập.
- **Prediko** ($49–199): BOM nguyên liệu, 20+ báo cáo, đồng bộ Amazon/Faire/Etsy.

### Đã làm (2026-09-27)
17. ✅ **Bỏ qua ngày bán đột biến** (mọi gói, `FEATURE_SPIKE_FILTER`, tắt được trong Settings `shops.filter_sales_spikes`): trong 90 ngày, ngày bán ≥ 10 cái và > 5 lần trung bình các ngày còn hàng khác được tính theo mức thường ngày; cần ≥ 20 ngày còn hàng và ≥ 5 ngày có bán; hơn 3 ngày như vậy là quy luật (khách sỉ hằng tuần) nên giữ nguyên (`forecast.spikes`). Explanation `spikes` + dòng `spikes_capped`. (Forthcast, Monocle có.)
18. ✅ **Doanh thu mất do hết hàng** (mọi gói, `FEATURE_LOST_SALES`): `forecasts.lost_units_30d` = tốc độ bán × số ngày hết hàng trong 30 ngày; mục ở Phân tích (× giá bán hiện tại), dòng giải thích `lost_sales`. (Forthcast có.)
19. ✅ **Chu kỳ đặt hàng theo NCC** (mọi gói, cài đặt lõi): `suppliers.order_cycle_days` thay cho 30 ngày mặc định khi tính lượng đặt; dùng chung cho what-if và kế hoạch nhập; dòng giải thích `order_cycle_supplier`. (Assisty "supplier cadence".)
20. ✅ **Kế hoạch nhập hàng 12 tuần** (Starter+, `FEATURE_PURCHASE_PLAN`): trang `/purchase-plan`, `PurchasePlanner` thuần mô phỏng từng ngày với đúng phép tính đặt hàng, tổng theo tuần (biểu đồ chi phí), theo NCC, theo sản phẩm; lọc NCC/Vendor, 4/8/12 tuần; không lưu gì. (Inventory Planner cash flow, Assisty buying calendar.)

### Đề xuất tiếp → đã làm ở lần 4 (#21–23) và #24 (lịch đặt hàng theo NCC).

## Nghiên cứu bổ sung (2026-09-27, lần 4)

### Phát hiện
- Stockcast, DemandMind, IFH đều quảng bá **"dead stock detection"** (hàng không bán được, kẹt vốn); ta đã có Bán chậm + Tồn thừa, nhưng thiếu cách nói "sản phẩm này tôi không nhập nữa, chỉ bán hết" (Forstock: loại sản phẩm khỏi kế hoạch).
- Forthcast bán **báo cáo độ chính xác dự báo**: đúng lời hứa "minh bạch" của ta, và gần như không đối thủ giá rẻ nào có. Là bằng chứng cụ thể cho câu "mọi con số đều kiểm tra được".
- Review IFH vẫn xin **gom đơn theo nhà sản xuất** để đặt cùng lúc: kế hoạch nhập đã gom theo NCC, còn thiếu bước đưa ra file.

### Đã làm (2026-09-27)
21. ✅ **Ngừng nhập sản phẩm** (mọi gói, cài đặt lõi): `variants.discontinued`, ô "Ngừng nhập" trong Cài đặt sản phẩm (và cập nhật hàng loạt qua `/variants/settings`). Dự báo vẫn tính ngày bán hết, nhưng không có điểm đặt lại, gợi ý nhập, tồn thừa hay doanh thu mất; trạng thái riêng "Ngừng nhập" (lọc được), không có trong việc cần làm ở Home, cảnh báo email/tức thời, kế hoạch nhập, what-if, Flow. **Không tính vào giới hạn 50 SKU của Free** (đổi cờ này trên gói có giới hạn sẽ chạy lại dự báo cả shop). Mục "Hàng ngừng nhập" ở Phân tích (số cái + tiền theo giá vốn còn phải bán hết). Dòng giải thích `discontinued_*`.
22. ✅ **Độ chính xác dự báo** (mọi gói, `FEATURE_ACCURACY`): bảng `forecast_snapshots` (lần dự báo đầy đủ đầu tiên mỗi tuần, từ thứ Hai giờ shop; giữ 16 tuần). `/api/accuracy` so tốc độ dự báo với bán thực tế trên ngày còn hàng trong 28 ngày sau đó: độ chính xác = 1 − WAPE, độ lệch (dự báo cao/thấp), tách dự báo của app với tốc độ merchant tự chỉnh, xu hướng 8 tuần, các sản phẩm lệch nhiều nhất. Bỏ qua sản phẩm còn hàng < 14 ngày và dự báo có doanh số combo. Mục ở Phân tích + một dòng trên trang sản phẩm. Kết quả đầu tiên có sau 4 tuần (trước đó hiện ngày có kết quả).
24. ✅ **Ngày đặt hàng theo NCC + lịch đặt hàng** (mọi gói cho ngày đặt hàng; lịch nằm trong Kế hoạch nhập, Starter): `suppliers.order_weekdays` (thứ ISO, vd. Thứ Hai + Thứ Năm; không chọn = ngày nào cũng được). Ngày cần đặt của sản phẩm dời về ngày đặt hàng gần nhất trước hạn (lỡ ngày đó thì đặt hôm nay), nên Home, trạng thái "Cần đặt ngay", cảnh báo, Flow, what-if và kế hoạch nhập đều theo; giải thích có dòng `order_weekday_moved` (hạn thực tế vs ngày đặt). Email tự động cho NCC (Growth) chỉ gửi vào các ngày này, tối đa 1 lần/ngày. Trang Kế hoạch nhập có mục "Lịch đặt hàng": mỗi dòng = ngày × NCC (số sản phẩm, số cái, chi phí). (Assisty "buying calendar / supplier cadence".)
25. ✅ **Sự kiện bán hàng** (mọi gói, `FEATURE_SALES_EVENTS`): bảng `sales_events` (tên, từ–đến ≤ 92 ngày, hệ số ×0,1–×10, áp dụng cho cả shop / một NCC / sản phẩm chọn bằng resource picker). Sự kiện sắp tới: nhu cầu từng ngày = trung bình × hệ số, nên điểm đặt lại, mức nhập tới, ngày hết hàng và ngày cần đặt đều tính trên đường nhu cầu này (`ForecastCalculator::reorderPlan`, dùng chung cho kế hoạch nhập và what-if; kế hoạch nhập trừ tồn theo hệ số từng ngày). Sự kiện đã qua: doanh số các ngày đó chia cho hệ số trước khi lấy trung bình. Giải thích `event_upcoming`/`event_upcoming_lower`/`event_past`. Trang `/events` (menu "Sự kiện bán hàng"). Lý do: mùa BFCM, hệ số mùa vụ chỉ dựa vào năm trước.
26. ✅ **Đánh dấu "Đã đặt hàng"** (mọi gói): bảng `manual_orders` cho đơn đặt ngoài Shopify (email, điện thoại, cổng NCC). Đơn mở được cộng vào "đang về" (vị thế tồn) cho tới khi nhận/huỷ, hoặc quá ngày dự kiến 3 ngày (`forecast.manual_order_grace_days`; sau đó "Quá hạn", không tính nữa và nhắc merchant). Ngày dự kiến mặc định = hôm nay + lead time. Nút "Đánh dấu đã đặt" ở trang Cần nhập hàng (các dòng đang chọn) và trang sản phẩm; trang `/orders` "Đơn đã đặt" (nhận / huỷ). Gửi email đơn cho NCC tự ghi các dòng đã gửi là đã đặt. Giải thích `ordered_manual`. Lý do: không có đơn trong Shopify thì app gợi ý đặt lại hàng đã đặt.
27. ✅ **Giá vốn nhập trong app** (mọi gói): `variants.cost_override` (giá của merchant) + `shopify_unit_cost` (đồng bộ ghi vào đây); `unit_cost` = giá của merchant, không có thì giá Shopify, nên mọi con số tiền (tiền kẹt, tồn thừa, ABC, PO, kế hoạch nhập, what-if) dùng được ngay. Trang `/costs` (lọc sản phẩm thiếu giá vốn, sửa hàng loạt, nhập CSV theo SKU/barcode, dùng thẳng file xuất sản phẩm của Shopify), ô giá vốn trong Cài đặt sản phẩm, liên kết "Thêm giá vốn" ở mọi chỗ báo thiếu giá vốn. Không ghi ngược lên Shopify (không cần thêm scope). Lý do: store dev có 17/19 sản phẩm thiếu giá vốn → mọi con số tiền trống.
28. ✅ **Ngân sách nhập hàng** (Starter+, `FEATURE_ORDER_BUDGET`): `shops.order_budget` (mỗi tháng). Trang `/budget`: mọi sản phẩm cần nhập ngay được xếp hạng (hết hàng → sẽ hết trước khi hàng kịp về → nhóm ABC → ngày hết sớm nhất), lấp phần ngân sách còn lại của tháng (đơn đã đánh dấu đặt trong tháng tính là đã chi); sản phẩm không vừa thì "Chờ", có lý do cho từng dòng; xuất PO / đánh dấu đã đặt cho phần trong ngân sách. Kế hoạch nhập hiện chi theo tháng so với ngân sách. Có trong hộp cảnh báo hạ gói. (Inventory Planner "cash flow planning".)
29. ✅ **Thêm 4 ngôn ngữ: ES, DE, FR, PT (Brazil)** (mọi gói): `app/frontend/src/i18n/locales/{es,de,fr,pt}.json` (đủ 1.036 khoá, dạng số nhiều `many` cho es/fr/pt), 2 admin extension (chuỗi riêng + phần giải thích chép từ app), `app.supported_locales`, trang công khai privacy/support. Email vẫn tiếng Anh. Lý do: định vị thị trường quốc tế, đối thủ Prediko có 10 ngôn ngữ, Forthcast 24; listing App Store tiếp cận thêm merchant.
30. ✅ **Việc còn dở của #6/#7**: doanh thu combo ảo (combo không theo dõi tồn) được chia về sản phẩm thành phần theo số lượng × giá khi xếp ABC (gói có combo; combo được theo dõi tồn thì tự xếp hạng, không chia) — `BundleRevenue`; nút "Xuất đơn theo kịch bản (CSV)" ở trang Mô phỏng (`/api/what-if/export`, cần quyền xuất PO). Chưa làm: áp kịch bản thành điều chỉnh tạm thời (đã có Sự kiện bán hàng thay thế).
31. ✅ **Thêm tính năng trên trang sản phẩm Shopify** (2026-09-27; nghiên cứu các target: `admin.product-details.block/action/print-action/configuration/reorder.render`, `admin.product-variant-details.block/action.render`; `reorder.render` chưa có tài liệu rõ nên không dùng): (a) khối dự báo hiện cả trên **trang biến thể**, có dòng "đã đặt N"; (b) extension mới `product-order-action` — **Đánh dấu đã đặt** từ More actions của trang sản phẩm/biến thể (số lượng gợi ý điền sẵn, ngày dự kiến, mã đơn); (c) **Cài đặt nhập hàng** chạy cả trên trang sản phẩm (More actions) và thêm MOQ, quy cách thùng, Ngừng nhập. API `/api/extension/variants/{id}`, `/api/extension/manual-orders`. `shopify.app.production.toml` có thêm extension thứ 3.
23. ✅ **Xuất đơn hàng từ kế hoạch nhập** (Starter, cần cả `purchase_plan` và `purchase_orders`): nút "Xuất đơn hàng (CSV)" ở trang Kế hoạch nhập, mỗi dòng = một lần đặt (ngày, tuần, NCC, sản phẩm, SKU, số lượng, giá vốn, thành tiền), theo bộ lọc đang chọn.

## Nghiên cứu bổ sung (2026-10-04, lần 5): 25 tính năng merchant cần

Nguồn: review 1–4 sao của Prediko, Assisty, Inventory Planner, Sumtracker, Stockie, IFH, Monocle, Stocky; diễn đàn Shopify Community; listing của Stockful, Logistified, Sensible, StockAngel, Fabrikatör, Restocked, Stovura; changelog Shopify 2026-10. Chưa đọc được Reddit (công cụ tìm kiếm không trả kết quả). Công sức: S ≤ 1 ngày, M 2–4 ngày, L ≥ 1 tuần.

### Phát hiện
- **Shopify đã mở API Purchase Order (chỉ đọc) ở bản 2026-10**: query `inventoryPurchaseOrders` / `inventoryPurchaseOrder`, scope `read_inventory_purchase_orders`, nhân viên Shopify xác nhận dùng được cho mọi app công khai. Chưa thấy mutation tạo PO. **Chưa xem danh sách field của `InventoryPurchaseOrder`: đọc docs trước khi làm.**
- **Email tóm tắt hằng tuần** là thứ người dùng Stocky mất và Shopify admin không có; Sensible ($29), StockAngel ($19) đều bán tính năng này.
- Than phiền mới nhất về Prediko (2026-09-14): "hộp đen", ít tuỳ chỉnh, bị ép tính năng AI, bảng tốn chỗ. Assisty (2026-08-04): thiếu bộ lọc loại trừ và mức tồn tối thiểu theo kho. Inventory Planner: không tách được phương pháp cho hàng mùa vụ và hàng mới bán nhanh, không lập kế hoạch theo doanh số thuần, không xem theo tuần/tháng.
- Diễn đàn Shopify: merchant muốn cảnh báo theo **số ngày tồn còn lại với ngưỡng tự chọn** (vd. 35 ngày) và **cửa sổ tính dài hơn 30 ngày**; muốn **loại đơn sỉ khỏi số liệu**; muốn theo dõi **giá trị tồn kho theo ngày**.
- Ngách thời trang đang có app riêng (Restocked $19, Stovura $59): đường cong size, "size run" bị gãy.
- Stockie: merchant phàn nàn PO chỉ có ở gói $60 → PO ở gói $4 của ta là điểm bán hàng cho listing.

### Đề xuất (✅ = đã làm, merge vào main 2026-10-05)

**A. Khép vòng đặt hàng**
32. ✅ (chỉ hiện PO đã đặt; API không có ngày dự kiến về và số đã nhận; tắt ở v1, chưa thử với API thật) **Đọc PO gốc Shopify qua API** (Starter, M): PO đang mở theo NCC, ngày dự kiến về, "hàng về kịp trước khi hết không"; thay cho việc merchant phải đánh dấu tay. Thêm scope `read_inventory_purchase_orders`.
33. ✅ **Lead time thực tế + độ tin cậy NCC** (mọi gói, M): đo từ PO Shopify và `manual_orders` (ngày đặt → ngày nhận), gợi ý "lead time đang cài 14, thực tế trung vị 19", số ngày an toàn theo độ dao động. (Checklist chuyển từ Stocky: lead time phải dựng lại bằng tay.)
34. ✅ (mã hàng NCC; chưa có PDF) **PO dạng PDF + mã hàng của NCC + mô tả sản phẩm** (Starter, M): `variants.supplier_sku`, đính PDF vào email NCC. (Stocky 2026-02: không thấy mô tả khi tạo đơn; Logistified gửi PDF/XLSX/CSV; mẫu CSV PO của Shopify có cột Supplier SKU.)
35. ✅ (phần nhận từng phần; chưa có nhiều ngày giao) **Nhận hàng từng phần + giao nhiều đợt** cho đơn đã đặt (mọi gói, M): mỗi dòng có số đã nhận, nhiều ngày dự kiến. (Salorworks: đối chiếu đặt/nhận là chỗ khó nhất khi rời Stocky; Prediko 4 sao 2025-03: thiếu "blanket PO / shipment plan".)
36. ✅ **Nhiều NCC cho một sản phẩm** (Starter, L): NCC chính + dự phòng, giá và lead time riêng. (Sensible, Logistified, Monocle có.)
37. ✅ (giá trị đơn tối thiểu; chưa có tiền tệ NCC và bậc giá) **Điều kiện mua theo NCC** (Starter, M): tiền tệ của NCC, giá trị đơn tối thiểu, bậc giá theo số lượng; gợi ý thêm hàng cho đủ ngưỡng. (Stockful "price lists"; phần "lấp đơn cho đủ ngưỡng" là suy luận của ta từ MOQ.)
38. ✅ **Landed cost đơn giản** (Starter, S): % hoặc phí mỗi đơn vị theo NCC cộng vào giá vốn khi tính tiền. (Stockful; LandedCostSync bán riêng $12/tháng.)

**B. Dự báo đúng hơn, chỉnh được hơn**
39. ✅ (lọc lúc tổng hợp đơn, không thêm bảng; đổi cài đặt = đồng bộ lại toàn bộ) **Loại kênh bán / đơn theo tag khỏi dự báo** (mọi gói, L): bỏ đơn sỉ, POS, draft order. Cần lưu tổng hợp theo kênh (thêm cột hoặc bảng, vẫn không lưu đơn thô). (Shopify Community; Inventory Planner 2024-12.)
40. ✅ (đã kiểm tra: tồn âm vốn đã cộng vào lượng đặt và ngày có bán được tính là còn hàng; thêm dòng giải thích) **Backorder / pre-order** (mọi gói, M): tồn âm cộng vào lượng cần nhập; ngày bán pre-order không bị coi là hết hàng. **Kiểm tra code hiện tại xử lý `available` âm thế nào trước.** (Fabrikatör sống nhờ tính năng này; Prediko không có.)
41. ✅ (3 hồ sơ: balanced / recent / steady) **Hồ sơ dự báo theo sản phẩm** (mọi gói, M): Ổn định / Theo mùa / Mới bán nhanh / Bán thưa, mỗi hồ sơ là một bộ trọng số 7/30/90/365 ghi rõ trong giải thích; shop chọn mặc định. (Prediko 2026-09, Inventory Planner 2024-08, Flow thread "30 ngày không đủ".)
42. ✅ **Chỉ báo xu hướng** (mọi gói, S): "7 ngày gần đây cao hơn 40% so với 30 ngày", lọc "đang tăng / đang giảm". (StockAngel, Monocle; Community "trend analysis".)
43. ✅ (làm bằng sự kiện bán hàng lặp lại hằng năm thay cho 12 hệ số) **Đường mùa vụ 12 tháng chỉnh được** (Starter, M): hệ số theo tháng cho sản phẩm / loại sản phẩm, gợi ý từ lịch sử, thay hệ số 28 ngày khi merchant đã đặt. (Inventory Planner: hàng mùa vụ dự báo sai là than phiền lặp lại.)
44. ✅ (mục "Sản phẩm lệch size" ở Phân tích; chưa chia lượng đặt theo tỷ lệ size) **Xem theo sản phẩm cha + đường cong size** (Starter, L): gom biến thể, cảnh báo size chủ lực sắp hết trong khi size khác thừa, chia lượng đặt theo tỷ lệ size. (Restocked, Stovura, Shopify Community.)
45. ✅ **Mức tồn tối thiểu theo từng chi nhánh** (Growth, M): hiện Min/Max chỉ áp dụng dự báo tổng. (Assisty 2026-08.)
46. ✅ **Loại chi nhánh khỏi tồn khả dụng** (mọi gói, S): kho 3PL trả hàng, kho hàng lỗi, showroom. **Kiểm tra xem đã có chưa.** (Inventory Planner 2024-10.)

**C. Báo cáo và thông báo**
47. ✅ **Email tóm tắt hằng tuần** (Free trở lên, S–M): 1 email/tuần, bật tay: cần đặt tuần này, sắp hết, tiền kẹt, doanh thu mất, độ chính xác. (Stocky cũ, Sensible, StockAngel.)
48. ✅ (Slack incoming webhook) **Cảnh báo qua Slack / webhook** (Starter, S): cùng nội dung digest, cùng luật chống spam. (iAlert, Restoket, Stockup, Stockful.)
49. ✅ (digest; Flow chưa đổi) **Ngưỡng "còn N ngày tồn" tự chọn** cho cảnh báo và Flow (Starter, S): theo shop hoặc sản phẩm, thay ngưỡng cứng 30/14/7/0. (Flow feature request, vd. 35 ngày.)
50. ✅ (bảng `inventory_snapshots`, ghi từ ngày triển khai: `end_of_day_stock` cũ thiếu sản phẩm không bán nên không dùng để dựng lại) **Lịch sử tồn kho và giá trị tồn theo ngày** (mọi gói, S–M): biểu đồ 400 ngày từ `daily_sales.end_of_day_stock` × giá vốn, đã có sẵn dữ liệu. (Community "track daily inventory value"; Stockful bán "daily snapshots 2 năm"; Shopify chỉ giữ 180 ngày.)
51. ✅ **Sell-through, vòng quay, tuổi tồn + danh sách xả hàng** (Starter, M): xuất CSV sản phẩm cần giảm giá. (Stovura "aged stock"; mọi đối thủ quảng cáo dead stock.)
52. ✅ (xuất CSV danh sách sản phẩm; chưa có báo cáo định kỳ) **Báo cáo định kỳ + xuất mọi bảng** (Starter, M): CSV gửi email theo lịch, hoặc link CSV cho Google Sheets `IMPORTDATA`. (Assisty 2024-02: bỏ export là mất quy trình; Inventory Planner xin export.)
53. ✅ (chế độ xem đã lưu; chưa có cột tuỳ chọn và lọc theo tag/collection) **Chế độ xem đã lưu, cột tuỳ chọn, bộ lọc loại trừ, lọc theo tag / collection** (mọi gói, M). (Assisty 2026-08, Prediko 2026-09, IFH 2024-12, Logistified.)

**D. Tin cậy và ngách**
54. ✅ **Kiểm tra sức khoẻ dữ liệu** (mọi gói, S–M): thiếu SKU / giá vốn / NCC, không theo dõi tồn, tồn âm, lead time còn mặc định, SKU trùng; mỗi dòng có link sửa. (Stockful "Health Checks"; Assisty 2024-10 và Prediko 2026-09: phải tự đối chiếu số với Shopify.)
55. ✅ **"Vì sao con số đổi so với tuần trước"** (mọi gói, S): so `forecast_snapshots` với hiện tại. (Bằng chứng yếu hơn: suy ra từ than phiền "hộp đen".)
56. ✅ (số combo làm được từ tồn thành phần) **Nguyên liệu / BOM nhẹ** (Starter, L): dùng lại `bundle_components` cho hàng tự sản xuất, "làm được bao nhiêu từ nguyên liệu đang có". (Sumtracker 2025-06, Prediko 2025-02; Katana $179, Craftybase $20.)

### Quyết định không làm (giữ nguyên)
Đồng bộ đa kênh Amazon/eBay/Etsy, kiểm kho bằng barcode, in nhãn: nhiều review Sumtracker 1 sao là do app ghi đè tồn kho; ta chỉ đọc, không ghi tồn.

### Thứ tự đề xuất
#47 → #32 → #54 → #50 → #41 → #39 → #48/#49 → #33 → #34, phần còn lại theo phản hồi merchant sau khi ra mắt.

Nguồn lần 5: https://community.shopify.dev/t/can-public-app-store-apps-use-read-inventory-purchase-orders-in-admin-api-2026-10/38081 · https://shopify.dev/docs/api/admin-graphql/2026-10/queries/inventoryPurchaseOrders · https://shopify.dev/changelog/release-notes/2026-10 · https://apps.shopify.com/prediko/reviews · https://apps.shopify.com/assisty/reviews · https://apps.shopify.com/inventory-planner/reviews · https://apps.shopify.com/sumtracker-fulfil-ship-track/reviews · https://apps.shopify.com/stockie/reviews · https://apps.shopify.com/stocky/reviews · https://community.shopify.com/t/expose-days-of-inventory-remaining-in-shopify-flow-as-a-trigger-condition/608333 · https://community.shopify.com/t/recommendations-for-inventory-demand-planning-apps-in-shopify-store/568432 · https://community.shopify.com/t/how-can-i-exclude-wholesale-orders-from-e-commerce-analytics/56106 · https://community.shopify.com/t/how-can-i-track-daily-inventory-value-for-my-store/82117 · https://www.salorworks.app/resources/shopify-stocky-shutdown-migration-checklist · https://sensible.tools/blog/stocky-deprecated-shopify-inventory-forecasting-alternatives · https://stockful.app/stocky-alternative · https://apps.shopify.com/logistified · https://apps.shopify.com/stockangel · https://apps.shopify.com/sensible-forecasting · https://apps.shopify.com/fabrikator · https://apps.shopify.com/restocked-3 · https://apps.shopify.com/stockpilot-9

## Nghiên cứu bổ sung (2026-10-05, lần 6)

Nguồn: Shopify Editions Spring '26, listing Stockcast và Forthcast (cập nhật), bài so sánh Sidekick với app tồn kho, yêu cầu Built for Shopify. Trang danh mục "newest" của App Store trả 404 và một nguồn về Sidekick trả 429, nên phần Sidekick dưới đây dựa trên trang Editions chính thức cộng một bài của đối thủ (có thiên vị).

### Phát hiện
- **Sidekick đã gợi ý nhập hàng và soạn PO ngay trong admin** (Spring '26: "Sidekick can generate purchase orders, which now automatically create transfers"). Đây là đối thủ miễn phí, có sẵn. Thứ Sidekick chưa làm (theo bài của Forthcast, cần tự kiểm chứng): tính lượng đặt theo lead time + MOQ, dự báo dài hạn, doanh thu mất do hết hàng. → Listing và onboarding phải nói rõ phần này; "giải thích từng con số" và "độ chính xác dự báo" là thứ một trợ lý chat không đưa ra được.
- **"Sidekick works with your apps"** (Judge.me, Klaviyo, Loop, Smile "and more top partners"): Sidekick trả lời câu hỏi và thao tác trong app của bên thứ ba. Chưa rõ có mở cho mọi app hay chỉ đối tác được chọn.
- **Webhook theo trường thay đổi** ("Configure triggers to fire webhooks only on specific field changes") và **bulk query đọc song song** ("up to four times faster"): hai thứ giảm tải hạ tầng trực tiếp cho gói $4.
- **App Events API**: app gửi sự kiện cho Shopify, theo dõi ở Dev Dashboard. **Shopify App Pricing**: cấu hình giá ngay lúc nộp app.
- Stockcast (ra mắt 2026-05-29) vẫn 0 review; có "audit logs". Forthcast $19.99, 2 review, liệt kê tích hợp Amazon, Notion, QuickBooks, Xero.
- Built for Shopify: điểm ≥ 4.0 với đủ số review, phản hồi hỗ trợ trung vị < 24 giờ; yêu cầu LCP đang tạm dừng áp dụng.

### Đề xuất (chưa làm), xếp theo giá trị
57. ✅ **Ghi sự kiện dùng tính năng** (S, xong 2026-10-06: bảng `feature_events`, middleware route `usage:<tính năng>`, nhóm "Used in the last 28 days" ở `admin/`): bảng `feature_events` (shop, tính năng, ngày, số lần) cho what-if, kế hoạch nhập, Phân tích, xuất file. Là điều kiện để `admin/` biết tính năng không lưu dữ liệu có ai dùng, và để quyết định bỏ hay đẩy tính năng.
58. **Webhook tồn kho chỉ khi `available` đổi** (S): cảnh báo tức thời đang nhận mọi thay đổi của `inventory_levels/update`. Đọc docs bộ lọc webhook trước.
59. **Bulk operation đọc song song** (S–M): đồng bộ đầu nhanh hơn, onboarding "5 phút" chắc hơn với shop lớn. Đọc docs trước.
60. ✅ **Nhật ký thay đổi** (M, xong 2026-10-06: bảng `change_logs`, tab History trên trang sản phẩm, giữ 180 ngày; chưa ghi *ai* đổi vì app không lưu thông tin nhân viên): ai đổi lead time, min/max, điều chỉnh dự báo, khi nào; hiện trên trang sản phẩm. Hợp với lời hứa minh bạch; Stockcast đã có.
61. ✅ **Nhờ đánh giá đúng lúc + hộp góp ý trong app** (S, xong 2026-10-06: hộp thoại đánh giá của Shopify qua `shopify.reviews.request()`, một lần mỗi shop, sau khi đánh dấu đã đặt hoặc xuất PO, từ ngày thứ 7; hộp góp ý ở Cài đặt gửi về `SUPPORT_EMAIL`): hỏi review sau khi merchant xuất PO đầu tiên hoặc sau 14 ngày dùng, tối đa một lần; góp ý gửi về email hỗ trợ. Built for Shopify cần điểm và số review.
62. **Tích hợp Sidekick** (→ xem #74, lần 8: Sidekick app extensions đã mở cho mọi app) (chưa rõ công): tìm hiểu cách app khai báo dữ liệu/hành động cho Sidekick; nếu mở cho mọi app thì để Sidekick trả lời "cần nhập gì" bằng số của Clear Stock.
63. **Việc còn dở của lần 5**: PO dạng PDF (#34), nhiều ngày giao cho một đơn (#35), tiền tệ NCC và bậc giá (#37), báo cáo CSV gửi định kỳ (#52), lọc theo tag/collection và cột tuỳ chọn (#53), chia lượng đặt theo tỷ lệ size (#44), ngưỡng ngày tồn tuỳ chọn cho Flow (#49).
64. **Xuất PO theo mẫu Xero / QuickBooks** (S–M): chỉ là thêm định dạng CSV, không cần OAuth. Forthcast quảng cáo tích hợp này.
65. **Admin: cohort giữ chân, phễu chuyển đổi, tin tóm tắt hằng ngày** (M): mục 2, 3, 6 trong kế hoạch ở `admin/README.md`.
66. **Listing so với Sidekick** (việc Phase 7): một đoạn "khác gì gợi ý của Sidekick" kèm ảnh trang giải thích và độ chính xác.

Nguồn lần 6: https://www.shopify.com/editions/spring2026 · https://www.forthcast.io/blog/shopify-sidekick-inventory-what-it-can-and-cant-do · https://apps.shopify.com/stockcast-inventory-forecast · https://apps.shopify.com/forthcast · https://community.shopify.dev/t/bfs-enforcement-update-reviewing-lcp-readings/21956

## Nghiên cứu bổ sung (2026-10-06, lần 7): 20 app tương tự

Danh sách, bảng so tính năng và phân tích nằm ở `docs/COMPETITORS.md`. Đề xuất (chưa làm), theo thứ tự giá trị:

67. PO thành chứng từ: gom dòng đã đặt cùng NCC thành PO có số, PDF, nhận hết một bấm (không cần quyền ghi).
68. ✅ Dữ liệu mẫu để thử app trước khi đồng bộ xong (xong 2026-10-07: `SampleForecasts` chạy calculator thật trên 5 sản phẩm giả định với lead time của shop, không lưu gì; mục "See how it works" trên Home khi shop chưa có dự báo; công tắc `FEATURE_SAMPLE_DATA`).
69. ✅ Tạm ẩn sản phẩm khỏi danh sách cần nhập trong N ngày (xong 2026-10-06: `variants.snoozed_until`, nút Snooze ở Cần nhập hàng).
70. ✅ Dự phóng nhu cầu 30/60/90 ngày trên trang sản phẩm (xong 2026-10-06: `DemandProjection`, mục "Expected sales").
71. Sửa hàng loạt và chọn cột trong danh sách sản phẩm.
72. KPI tồn kho (vòng quay, tỷ lệ ngày hết hàng); xuất XLSX; báo cáo hẹn giờ; kế hoạch nhập 26/52 tuần; thêm ngôn ngữ.

Không làm: chatbot AI, kiểm kê/mã vạch/ghi tồn, backorder, BOM, đa kênh.

## Nghiên cứu bổ sung (2026-10-07, lần 8)

### Phát hiện
- **Bốn app mới cùng định vị với ta, ra từ tháng 7–9/2026, đều chưa có review:** Foreshelf ("shows the maths", Free 50 SKU, trả tiền từ $19), Days of Cover ("transparent arithmetic", Free 30 biến thể, $19/$39, xếp theo lợi nhuận có nguy cơ mất, nhập CSV Stocky), Replenra (Free **500 SKU**, $4.99/$9.99/$19.99, PO + email NCC + nhiều chi nhánh), Restock ($19, "ML", snooze, email tuần). "Giải thích được" không còn là điểm riêng của ta; giá $4 và gói Free 50 SKU cũng không còn rẻ nhất (Replenra).
- **Sidekick app extensions mở cho mọi nhà phát triển từ 2026-06-17** (hai loại: *app data* để Sidekick tìm trong dữ liệu của app, *app actions* để mở đúng trang của app với ngữ cảnh điền sẵn; tạo bằng Shopify CLI). Mục #62 trước đây "chưa rõ công" nay làm được.
- **Shopify nối PO với phiếu chuyển và lô hàng** (Summer '26): nhận hàng từng phần tạo nhiều lô, mỗi lô có ngày gửi, ngày nhận, mã vận đơn. API có `InventoryShipment` (`dateShipped`, `dateReceived`, `tracking`, số đã nhận / từ chối / huỷ; scope `read_inventory_shipments`).
- **API 2026-10 bỏ dần `ProductVariant.barcode`**, thay bằng connection `barcodes`. Ta đang đồng bộ `barcode` trong `BulkQueries`.
- **API 2026-04 thêm `InventoryLevel.isActive`**: mức tồn đã ngừng hoạt động có thể được trả về khi xin.
- **Chưa có API đọc lịch sử điều chỉnh tồn kho** bằng GraphQL (`inventoryHistory` không dùng được); chỉ có bảng ShopifyQL `inventory_adjustment_history`.
- **BFCM 2026 là 27–30/11**; các hướng dẫn đều khuyên chốt đơn nhập trước 6–8 tuần, tức là ngay lúc này. Than phiền lặp lại trên Reddit: app chỉ lấy trung bình sẽ đặt thừa nhiều tuần sau đợt bán đột biến (ta đã xử lý bằng #17 và #25).
- Shopify có bản xem trước "physical inventory" (kiểm kê) từ 7/2026: củng cố quyết định không làm kiểm kê.

### Đề xuất (chưa làm), xếp theo giá trị
73. ✅ (xong 2026-10-07: `PeakSeasonAdvisor`, `/api/sales-events/suggestions`, thẻ gợi ý ở trang Sự kiện bán hàng, thêm sự kiện một bấm; mức tăng tính cho cả shop = số bán/ngày của 4 ngày Black Friday–Cyber Monday năm ngoái so với 28 ngày kết thúc trước đó một tuần; chưa tính riêng từng sản phẩm và chưa có danh sách "sẽ hết hàng trong đợt", vì dự báo tự tính lại sau khi thêm sự kiện) **Chuẩn bị mùa cao điểm từ số năm ngoái** (S–M, mọi gói): với mỗi sản phẩm có dữ liệu cùng kỳ năm trước, tính mức tăng thực tế của đợt BFCM năm ngoái so với các tuần trước đó và gợi ý tạo sẵn sự kiện bán hàng (#25) với hệ số đó; kèm danh sách "sẽ hết hàng trong đợt này nếu không đặt trước ngày X". Dùng lại `sales_events` và `ForecastCalculator::multiplier`, không thêm thuật toán. Đúng mùa: nên có trước giữa tháng 10.
74. **Sidekick app extensions** (M, thay cho #62): *app data* cho câu hỏi "cần nhập gì", "món nào sắp hết" trả bằng số của Clear Stock; *app action* mở trang Cần nhập hàng hoặc hộp "Đã đặt hàng" điền sẵn. Đây là cách đứng cạnh thay vì đối đầu với gợi ý nhập hàng của Sidekick. Đọc https://shopify.dev/docs/apps/build/sidekick trước; cần `shopify app deploy`.
75. ✅ **Đã có sẵn, đề xuất nhầm**: `BulkQueries::variants` đã đọc `productVariantComponents` và `VariantImporter` lưu thành phần với `source = shopify` (lúc nghiên cứu chỉ tìm thấy `requiresComponents`). ~~**Tự lấy thành phần combo từ Shopify Bundles**~~ (S–M, Starter): ta đã đồng bộ `requiresComponents` nhưng merchant vẫn phải khai thành phần bằng tay. Đọc thành phần và số lượng từ API (scope `read_products` đã có; đọc docs `ProductBundleComponent` / `productVariantComponents` trước), giữ khai tay cho combo ngoài Shopify Bundles.
76. **Lô hàng đang về có ngày** (M, tắt mặc định như #32): đọc `InventoryShipment` để biết lô nào đã gửi, khi nào, còn bao nhiêu chưa nhận; sửa điểm yếu đã ghi ở #32 (PO Shopify không có ngày dự kiến). Scope tuỳ chọn `read_inventory_shipments`, xin khi bật.
77. **Chuyển `barcode` sang `barcodes`** (S, kỹ thuật; đã kiểm tra 2026-10-07: app đang ở API 2026-07, nơi chưa có `barcodes`, và ở 2026-10 `barcode` mới chỉ bị đánh dấu deprecated chứ chưa bị gỡ, nên **không gấp**: làm cùng lúc nâng `SHOPIFY_API_VERSION` + `api_version` trong hai file toml lên 2026-10): sửa `BulkQueries` + `VariantImporter`, giữ cột `variants.barcode` là mã đầu tiên. Thêm test khoá truy vấn theo phiên bản API.
78. ✅ (xong 2026-10-07, bản gọn: thêm cách sắp xếp "Most profit per day" ở danh sách Sản phẩm = (giá bán − giá vốn) × tốc độ bán dự báo, thiếu giá vốn thì tính cả giá bán; kết hợp với bộ lọc trạng thái "Cần đặt ngay" để biết mua gì trước. Chưa nhân với số ngày dự kiến hết hàng và chưa có ở trang Cần nhập hàng / Ngân sách) **Xếp theo lợi nhuận có nguy cơ mất** (S, mọi gói): thêm cách sắp xếp ở Cần nhập hàng và Ngân sách = (giá bán − giá vốn) × tốc độ bán × số ngày dự kiến hết hàng trước khi hàng về. Giá và giá vốn đã có; sản phẩm thiếu giá vốn xếp theo doanh thu và nói rõ. (Days of Cover lấy đây làm điểm bán chính.)
79. **Nhập cài đặt sản phẩm bằng CSV theo SKU** (M, mọi gói): lead time, MOQ, quy cách thùng, NCC, min/max, ngừng nhập; xem trước rồi mới áp dụng, giống `/suppliers/import` và nhập giá vốn. Giải quyết phần lớn nhu cầu "sửa hàng loạt" của #71 mà không cần bảng sửa trực tiếp. (Foreshelf: "any CSV with a SKU column".)
80. ✅ (đã kiểm tra 2026-10-07, không cần sửa: truy vấn `inventoryLevels` trong `BulkQueries::inventory` không xin `includeInactive`, nên mức tồn đã ngừng hoạt động không được trả về) **Kiểm tra mức tồn không hoạt động** (S, kỹ thuật): xác nhận sync không tính `InventoryLevel` có `isActive = false` vào tồn; thêm test.
81. **Ngày hết hàng chính xác hơn từ lịch sử điều chỉnh** (chưa rõ công): tìm hiểu ShopifyQL `inventory_adjustment_history` (scope, giới hạn, có dùng được trong bulk không) để thay cách dựng lại ngày hết hàng hiện nay. Chỉ làm nếu đọc được bằng scope đang có.

### Quyết định của chủ app (không phải code)
82. **Giới hạn gói Free**: Replenra cho 500 SKU miễn phí, ta 50 (cùng mức Foreshelf, hơn Days of Cover 30 và Restock 2). Cân nhắc nâng lên 100–200 trước khi nộp; chi phí hạ tầng mỗi shop Free cần đo lại trước.
83. **Listing**: bốn app trên đều nói "minh bạch". Đưa lên đầu những gì họ chưa có: độ chính xác dự báo đo được (#22), sự kiện bán hàng, combo, 6 ngôn ngữ, giá $4 có cam kết giữ giá, dữ liệu mẫu (#68).

### Không làm (giữ nguyên)
Kiểm kê / physical inventory (Shopify tự làm), "ML" làm điểm bán (trái định vị giải thích được).

### Thứ tự đề xuất
~~#73~~, ~~#78~~ (xong) → #79 → #74 → #76; #77 làm khi nâng API lên 2026-10; #75 và #80 không cần làm.

Nguồn lần 8: https://shopify.dev/changelog/release-notes/2026-10 · https://shopify.dev/changelog/sidekick-app-extensions-available-today · https://shopify.dev/docs/api/admin-graphql/latest/objects/InventoryShipment · https://changelog.shopify.com/posts/purchase-orders-now-create-transfers-to-move-inventory · https://shopify.dev/docs/api/shopifyql/latest/schemas/inventory/inventory_adjustment_history.md · https://apps.shopify.com/restock-7 · https://apps.shopify.com/days-of-cover · https://apps.shopify.com/inventory-12 · https://www.producthunt.com/products/foreshelf-stock-forecasting · https://www.prediko.io/blog/shopify-black-friday-inventory-checklist

## Phase tiếp theo: tối ưu UI/UX và hiệu năng (kế hoạch, 2026-10-05)

> **Trạng thái 2026-10-05:** đã làm nhóm trang + tab, sửa lệch chuẩn qua 2 vòng rà ảnh chụp (máy tính + điện thoại), web vitals, tăng tốc và cache kế hoạch nhập, tổng đến hạn theo NCC bằng SQL. Chưa làm: A4/A5 (chunk dùng chung 72 KB, nạp ngôn ngữ dự phòng), A6 prefetch, usage events (#57), lọc field webhook (#58), đọc bulk song song (#59).

Dựa trên: yêu cầu Built for Shopify (bản hiện hành), hướng dẫn thiết kế app của Shopify (layout, onboarding), ảnh chụp 22 màn hình ở `docs/screens/`, và số đo trên store dev (19 sản phẩm, bản dev của Vite, mạng nội bộ).

### Số đo hiện tại
- API: mọi endpoint chính 9–35 ms, payload lớn nhất 12 KB (`/manual-orders`), 10 KB (`/purchase-plan`). **Store dev quá nhỏ**: chưa có số đo với shop 5.000–20.000 biến thể.
- Bundle production: `main` 238 KB (74 KB gzip), mỗi ngôn ngữ 68–93 KB (21–25 KB gzip), một chunk dùng chung tên `ErrorBanner` 72 KB (24 KB gzip) cần xem bên trong có gì.
- Thác request: mọi trang gọi `/shop` trước, **xong mới** gọi dữ liệu của trang; chunk của trang cũng chỉ tải sau đó. Trên mạng thật mỗi bước là một vòng đi về.
- Trang Phân tích gọi 6 API ngay khi mở, kể cả dữ liệu của tab đang ẩn. Cài đặt gọi 4.
- CLS đo được 0,000 (Home, Cài đặt); LCP Home 360 ms. Đây là số máy nội bộ ngoài admin, **không phải** số Shopify dùng để xét (p75 trong admin thật, cần ≥ 100 lượt/28 ngày).

### Ngưỡng Built for Shopify cần giữ
LCP ≤ 2,5 s, CLS ≤ 0,1, INP ≤ 200 ms (p75, 28 ngày). 4.1.5 lưu form bằng contextual save bar. 4.2.2 onboarding gọn, tối đa 5 bước, bỏ qua được. 4.2.3 trang chủ có trạng thái setup và chỉ số. 4.3.4 không gây quá tải: chia nhỏ form, hạn chế banner, chữ ngắn. Bố cục: một cột cho trang danh sách, không đổi mật độ thông tin trong cùng một trang, mỗi thẻ tối đa một nút primary, bảng dùng nút phụ.

### A. Hiệu năng (đo trước, sửa sau)
1. **Gửi Web Vitals thật về backend**: App Bridge có `shopify.webVitals.onReport` (LCP, CLS, INP theo trang). Lưu tổng hợp theo ngày, hiện ở `admin/`. Không có bước này thì mọi tối ưu khác không kiểm chứng được.
2. **Shop giả 10.000 biến thể** (`dev:seed-large`) để đo API, forecast và sync ở quy mô thật; đặt ngưỡng (vd. danh sách < 200 ms, dashboard < 300 ms) và thêm test chặn N+1.
3. **Bỏ thác `/shop` → dữ liệu trang**: gọi song song, và tải trước chunk của trang theo URL ngay từ đầu.
4. **Tải dữ liệu theo tab**: Phân tích và Cài đặt chỉ gọi API của tab đang mở.
5. **Xem lại chunk dùng chung 72 KB** và việc tải ngôn ngữ dự phòng (EN) khi merchant dùng ngôn ngữ khác.
6. **Prefetch khi rê chuột vào menu/liên kết** sản phẩm (React Query `prefetchQuery`).
7. Từ roadmap lần 6: webhook tồn kho chỉ khi `available` đổi (#58), bulk operation đọc song song (#59).

### B. UI/UX
8. **Menu 12 mục → khoảng 7**: Home, Cần nhập hàng, Sản phẩm, Phân tích, Kế hoạch (gộp Kế hoạch nhập + Ngân sách + Mô phỏng + Sự kiện thành tab), Nhà cung cấp (gộp Combo, Đơn đã đặt), Cài đặt (gộp Gói). Cùng cách chia tab vừa làm cho 3 trang dài.
9. **Trang sản phẩm**: câu kết luận lên đầu ("Đặt 52 cái trước 4/10"), lưới chỉ số 4 cột thay vì 2 cột thưa; tab Cài đặt chia nhóm (NCC và lead time / Quy tắc đặt hàng / Min–Max / Dự báo / Cảnh báo).
10. **Danh sách sản phẩm**: 7 ô lọc trên một hàng → giữ Tìm kiếm, Trạng thái, Sắp xếp; phần còn lại vào "Thêm bộ lọc". Trên điện thoại mỗi sản phẩm đang chiếm 8 dòng (trang 390px dài 5.750px) → còn 3–4 dòng.
11. **Bảng và nút**: rà mỗi thẻ chỉ một nút primary, hành động trong bảng dùng nút phụ hoặc menu "…" (trang Nhà cung cấp đang có 5 nút mỗi dòng).
12. **Trạng thái rỗng và đang tải** cho các mục mới (xả hàng, lệch size, lịch sử tồn, PO Shopify, NCC khác).
13. **Rà chữ**: câu ngắn, bỏ lặp, soát 5 ngôn ngữ ngoài EN do máy dịch (ES/DE/FR/PT chưa có người bản ngữ đọc).
14. **Kiểm tra trong admin thật** (cần `make tunnel`): thanh tiêu đề, save bar, điều hướng, màn hình điện thoại của app Shopify. Bộ E2E chạy ngoài admin nên không thấy các phần này.
15. **Khả năng truy cập**: tương phản WCAG AA cho biểu đồ tự vẽ, nhãn cho biểu đồ, điều hướng bàn phím qua tab.

### Thứ tự đề xuất
A1 → A2 (có số đo) → B8, B9, B10 (thay đổi merchant thấy rõ nhất) → A3, A4 → B11–B13 → B14 → phần còn lại.

Nguồn: https://shopify.dev/docs/apps/launch/built-for-shopify/requirements · https://shopify.dev/docs/apps/design/layout · https://shopify.dev/docs/apps/design/user-experience/onboarding · https://shopify.dev/docs/api/app-home/apis/device-and-platform-integration/web-vitals-api · https://community.shopify.dev/t/improve-admin-performance-faq/1100

## Việc sắp tới (tổng hợp, theo thứ tự đề xuất)

1. ~~**#11 Tạo Purchase Order gốc của Shopify từ gợi ý**~~ — xong: nút xuất PO có thêm định dạng "Đơn đặt hàng Shopify" đúng mẫu `SKU,Barcode,Supplier SKU,Quantity,Cost,Tax` (không có API tạo PO), đồng bộ `variants.barcode`, bỏ qua sản phẩm không có SKU lẫn barcode và báo số lượng.
2. ~~**#13 Block dự báo trên trang sản phẩm Shopify** + gán NCC/lead time hàng loạt~~ — xong: 2 admin UI extension (`extensions/`): block trên trang sản phẩm (dự báo từng biến thể + giải thích + link vào app), hành động "Đặt NCC & lead time" cho sản phẩm được chọn ở danh sách sản phẩm.
3. ~~**#14 Shopify Flow triggers dựa trên dự báo**~~ (xong, Growth).
5. ~~**#10 Báo khi đồng bộ lỗi liên tục** + cam kết giữ giá~~ (xong; câu giữ giá cho listing làm ở Phase 7).
6. ~~**#6 Phân loại ABC**~~ (xong), ~~**#7 Mô phỏng tăng trưởng (what-if)**~~ (xong), ~~**#8 Gợi ý chuyển kho** (Growth)~~ (xong: trang Chuyển kho, tạo phiếu chuyển nháp trong Shopify qua scope tùy chọn `write_inventory_transfers`), ~~**#9 Dự báo sản phẩm mới theo sản phẩm tham chiếu**~~ (xong, Starter).
7. **#16 Nhận hàng / kiểm kho bằng barcode**: quyết định không làm (2026-09-26): Shopify admin đã có nhận hàng qua transfer, nhu cầu hẹp.
8. ~~**Mặc định MOQ / quy cách thùng theo nhà cung cấp**~~ (xong 2026-09-26, mọi gói: `suppliers.min_order_qty`/`pack_size`, cài đặt của sản phẩm thắng, giải thích ghi "mặc định của NCC").
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
