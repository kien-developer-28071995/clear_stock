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
29. ✅ **Thêm 4 ngôn ngữ: ES, DE, FR, PT (Brazil)** (mọi gói): `frontend/src/i18n/locales/{es,de,fr,pt}.json` (đủ 1.036 khoá, dạng số nhiều `many` cho es/fr/pt), 2 admin extension (chuỗi riêng + phần giải thích chép từ app), `app.supported_locales`, trang công khai privacy/support. Email vẫn tiếng Anh. Lý do: định vị thị trường quốc tế, đối thủ Prediko có 10 ngôn ngữ, Forthcast 24; listing App Store tiếp cận thêm merchant.
30. ✅ **Việc còn dở của #6/#7**: doanh thu combo ảo (combo không theo dõi tồn) được chia về sản phẩm thành phần theo số lượng × giá khi xếp ABC (gói có combo; combo được theo dõi tồn thì tự xếp hạng, không chia) — `BundleRevenue`; nút "Xuất đơn theo kịch bản (CSV)" ở trang Mô phỏng (`/api/what-if/export`, cần quyền xuất PO). Chưa làm: áp kịch bản thành điều chỉnh tạm thời (đã có Sự kiện bán hàng thay thế).
23. ✅ **Xuất đơn hàng từ kế hoạch nhập** (Starter, cần cả `purchase_plan` và `purchase_orders`): nút "Xuất đơn hàng (CSV)" ở trang Kế hoạch nhập, mỗi dòng = một lần đặt (ngày, tuần, NCC, sản phẩm, SKU, số lượng, giá vốn, thành tiền), theo bộ lọc đang chọn.

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
