# Roadmap: tính năng mới từ nghiên cứu đối thủ

Nghiên cứu ngày 2026-09-26 (Shopify App Store, review 1–3 sao, bài viết về Stocky). Mỗi mục ghi lý do để khi lên kế hoạch không phải tìm lại.

## Bối cảnh thị trường

- **Stocky (app tồn kho của Shopify) đã đóng**: gỡ khỏi App Store 2026-02-02, tắt hẳn 2026-08-31. Merchant còn quyền xuất dữ liệu read-only khoảng 90 ngày (tới khoảng cuối 11/2026); danh sách nhà cung cấp không xuất được. Công cụ tồn kho có sẵn trong Shopify admin không có dự báo. → Cửa sổ thu hút người dùng Stocky đang mở, cần làm nhanh.
- **Đối thủ trực tiếp cùng định vị**: Stockcast (ra mắt 2026-05, "clear, explainable math", Free 25 SKU, $19/$29/$59, có import CSV đơn đặt hàng Stocky). Giá của ta ($4/$5 từ 2026-09-26, trước đó $2/$3) vẫn rẻ hơn khoảng 5 lần.

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

5. **Vòng đời đơn đặt hàng**: nháp → đã gửi → đã nhận; xuất PDF / gửi email cho nhà cung cấp; nhận hàng xong thì hết tính là incoming.
6. **Phân loại ABC**: nhóm A/B/C theo đóng góp doanh thu. Chỉ cần thêm cột và bộ lọc trong danh sách sản phẩm, không cần AI.
7. **Mô phỏng tăng trưởng (what-if)**: "doanh số +20% thì cần nhập bao nhiêu". Hợp với định vị minh bạch.
8. **Gợi ý chuyển kho giữa location** (gói Growth): location thừa → location sắp hết.
9. **Dự báo sản phẩm mới theo sản phẩm tương tự**: chọn một sản phẩm tham chiếu, dùng tốc độ bán của nó khi chưa đủ lịch sử.
10. **Báo khi sync lỗi liên tục** (email một lần, không spam) + cam kết giữ giá cho khách cũ trên trang Plans / listing.

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
