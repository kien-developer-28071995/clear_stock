# App tương tự Clear Stock trên Shopify và điều học được

Khảo sát ngày 2026-10-06 từ trang App Store của từng app và hai bài so sánh (nguồn ở cuối). Số đánh giá, giá và tính năng là theo trang công khai lúc khảo sát; tôi chưa cài thử app nào, nên "có tính năng X" nghĩa là app **tự mô tả** như vậy.

Giá của Clear Stock để so: Free (50 sản phẩm), Starter $4, Growth $6.

## 1. Danh sách

### Nhóm A: cùng phân khúc (merchant nhỏ, giá thấp, tự phục vụ)

| App | Ra mắt | Đánh giá | Giá/tháng | Gói miễn phí | Điểm đáng chú ý |
|---|---|---|---|---|---|
| **Stockie** (Plutonian) | 2021-07 | 4.9 (162), có huy hiệu Built for Shopify | $4.99 / $9.99 / $29.99 / $59.99 | Không (thử 14 ngày) | Hai gói rẻ chỉ là cảnh báo tồn thấp; dự báo từ $29.99; PO, nhận hàng bằng mã vạch, kiểm kê, landed cost, API ở $59.99. Được khen là "thay thế Stocky" |
| **Stockcast** (Karakaya) | 2026-05 | chưa có | Free / $19 / $29 / $59 | 25 sản phẩm, 1 chi nhánh | "Plain math, not black-box AI" (cùng thông điệp với ta); tạo và nhận PO; nhập CSV từ Stocky; nhật ký thay đổi; phát hiện lệch vendor; dự phóng 30/60/90 ngày; landed cost trên PO |
| **IFH Inventory Forecasting Hero** | 2022-07 | 5.0 (24) | $25 (một gói) | Không (thử 30 ngày) | Mô phỏng tăng trưởng; tạo PO trong Shopify; cảnh báo đặt hàng hằng ngày; chatbot lên kế hoạch mùa lễ (beta) |
| **Sensible Inventory Forecasting** | — | 5.0 (10) | $29 | Không | Đơn giản; **tạm ẩn sản phẩm (snooze)**; nhiều vendor cho một sản phẩm; email báo cáo tuần; xuất CSV và XLSX |
| **Assisty** | 2021-06 | 4.8 (359) | Free / $19 / $39 / $199 | Có (theo dõi tồn, báo cáo tồn thấp) | Dự báo từ $19; PO + duyệt PO, kiểm kê, chuyển kho ở $39; ABC, sell-through, hàng chết; báo cáo tuỳ biến bằng AI; tích hợp Shopify Flow |
| **Bee Forecast & Replenishment** (merchbees) | 2024-04 | 5.0 (3) | Free / $18.99 / $44.99 / $64.99 | 100 sản phẩm | Phát hiện ngoại lệ; báo cáo KPI; nhiều cửa hàng |
| **Stockful** | mới | ít | từ $19.99 | Không | Bỏ ngày hết hàng khi tính tốc độ bán (giống ta); 11 loại báo cáo hẹn giờ gửi email/Slack |
| **Forstock** | mới | 5.0 (3) | Có gói miễn phí | Có | Gợi ý PO bằng AI theo lead time và quy tắc NCC |
| **Replenra** (Adease) | 2026-09 | chưa có | Free / $29 / $89 / $199 | 5 sản phẩm | PO, nhận hàng, MOQ, cảnh báo tồn thừa |

### Nhóm B: cao cấp (thương hiệu $500k–$25M/năm, giá theo doanh thu)

| App | Đánh giá | Giá/tháng | Điểm đáng chú ý |
|---|---|---|---|
| **Prediko** | 4.9 (255) | $49 / $119 / $199 / báo giá | 10 ngôn ngữ; BOM nguyên liệu; chuyển kho; 20+ báo cáo; 100+ tích hợp WMS/3PL, Amazon, Faire, Etsy, Xero, QuickBooks; nhập NCC từ Stocky |
| **Fabrikatör** | 4.8 (111) | $99 / $149 / $199 + $0.75 mỗi backorder | Bán backorder/pre-order; tích hợp Klaviyo, Xero, QuickBooks, ShipHero; **có store demo để thử** |
| **Monocle** | 4.5 (30) | $47–129 | ABC-XYZ; phân tích giỏ hàng; tự sửa dữ liệu ngày hết hàng |
| **Cogsy** | 4.9 (13) | $199 | Kế hoạch nhu cầu **12 tháng**; kịch bản tốt/xấu/khả năng cao; đưa sự kiện marketing vào kế hoạch; lập kế hoạch theo dòng tiền |
| **Rewize** | 5.0 (31) | $149 / $299 / $449 | Chatbot "Rey" hỏi đáp dữ liệu tồn kho; dự báo combo theo từng thành phần |
| **ReplenOS** (Strum AI) | 5.0 (1) | $79 / $179 / $349 | Tự chọn mô hình dự báo theo từng sản phẩm; "must-order-by" xếp theo ưu tiên; bản tin AI hằng ngày |
| **Inventory Planner (Sage)** | 4.3 (154) | từ $119.99, hợp đồng năm | Lâu đời; đang bỏ khách nhỏ |
| **Tightly Lite** | 2.8 (7) | Miễn phí cài, chỉ nhận shop $1M–25M | Hỏi đáp "Ask Tightly"; theo dõi hạn dùng; bị chê **chậm với 30.000 sản phẩm** và thiếu chọn hàng loạt |
| **Sumtracker**, **StockTrim**, **Organizely**, **Verve AI**, **StockIQ** | — | $39–59 | Đa kênh (Sumtracker), sản phẩm mới (StockTrim), sản xuất/BOM (Organizely) |

## 2. Vị thế của Clear Stock

- **Giá:** ta là app rẻ nhất có dự báo. Stockie nhìn như ngang giá ($4.99) nhưng ở mức đó chỉ có cảnh báo; dự báo của họ là $29.99. Bee cho miễn phí 100 sản phẩm, nhiều hơn 50 của ta.
- **Thông điệp "minh bạch":** không còn là của riêng ta. Stockcast dùng đúng câu "plain math, not black-box AI". Ta vẫn hơn ở chỗ giải thích từng con số ngay trên trang sản phẩm và cho chỉnh, nhưng trang giới thiệu cần cho thấy điều đó bằng ảnh chụp, không chỉ bằng khẩu hiệu.
- **Nhu cầu có thật:** review 1 sao của Prediko chê đúng những thứ ta tránh: "dự báo là hộp đen", "liên tục đẩy AI khó chịu", "bị spam email gần như hằng ngày", bảng tốn chỗ. Đây là bằng chứng cho định vị hiện tại.
- **Uy tín:** Stockie có huy hiệu Built for Shopify và 162 đánh giá; Assisty 359; Prediko 255. Ta chưa có đánh giá nào. Đây là khoảng cách lớn nhất, không phải tính năng.

## 3. So tính năng

"Có" = đã làm trong Clear Stock (kể cả đang tắt ở bản v1).

| Tính năng | Ai có | Clear Stock |
|---|---|---|
| Dự báo theo sản phẩm, bỏ ngày hết hàng, mùa vụ | hầu hết | Có |
| Giải thích con số, cho chỉnh | Stockcast (công thức) | Có, sâu hơn |
| Combo / bundle | Prediko, Rewize, Fabrikatör, Stockcast | Có |
| Mô phỏng tăng trưởng, sự kiện bán hàng | IFH, Cogsy | Có |
| Ngân sách / dòng tiền, tiền kẹt trong tồn | Cogsy, Stockcast | Có |
| ABC, hàng chậm, tồn thừa, sell-through | Assisty, Monocle | Có |
| Độ chính xác dự báo | Stockcast | Có |
| Nhập từ Stocky | Stockcast, Prediko, Stockie | Có |
| Cảnh báo email, Slack, Shopify Flow | Stockie, Stockful, Assisty | Có (Slack và Flow tắt ở v1) |
| Dự báo theo chi nhánh, chuyển kho | Stockie, Prediko, Assisty | Có (tắt ở v1) |
| **PO là một chứng từ: tạo, gửi, nhận hàng** | Stockie, Stockcast, Assisty, Prediko, Fabrikatör, Replenra, IFH | **Một phần**: xuất CSV, "đánh dấu đã đặt" theo từng sản phẩm, nhận một phần; chưa gom thành PO có số, chưa có PDF |
| **Tạm ẩn sản phẩm (snooze)** | Sensible | **Chưa** (chỉ có ngừng nhập và tắt cảnh báo) |
| **Dữ liệu mẫu / store demo để thử** | Fabrikatör | **Chưa** |
| **Dự phóng nhu cầu 30/60/90 ngày** | Stockcast | **Chưa** (chỉ hiện tốc độ bán/ngày) |
| **Chọn cột, bảng gọn** | (Prediko bị chê thiếu) | **Chưa** |
| **Sửa hàng loạt trong danh sách** | (Tightly bị chê thiếu) | **Một phần** (chỉ qua extension ở trang sản phẩm Shopify) |
| **Nhật ký thay đổi** | Stockcast | **Chưa** (đã đề xuất ở roadmap lần 6) |
| **Xuất XLSX; báo cáo hẹn giờ gửi email** | Sensible, Stockful | **Chưa** (chỉ CSV, và một email tóm tắt tuần) |
| **KPI tồn kho (vòng quay, tỷ lệ hết hàng)** | Bee, Assisty | **Chưa** |
| **Kế hoạch dài hơn 12 tuần** | Cogsy (12 tháng) | **Chưa** |
| **Nhiều ngôn ngữ hơn** | Prediko (10) | 6 |
| Landed cost theo từng PO | Stockcast, Stockie | Một phần (theo % của NCC) |
| Kiểm kê, quét mã vạch, ghi tồn về Shopify | Stockie, Assisty, Prediko | Không (quyết định không xin quyền ghi) |
| Backorder / pre-order | Fabrikatör, Cogsy | Không |
| BOM / nguyên liệu | Prediko, Organizely | Không |
| Đa kênh, 3PL/WMS, kế toán | Prediko, Sumtracker, Fabrikatör | Không |
| Chatbot / hỏi đáp AI | Rewize, Tightly, IFH, ReplenOS, Assisty | Không (trái định vị) |

## 4. Điều nên học, xếp theo giá trị

1. **PO thành chứng từ.** Gần như mọi đối thủ có, và review khen nhiều nhất chính là "tạo PO nhanh". Ta đã có sẵn các mảnh (gợi ý theo NCC, đánh dấu đã đặt, nhận một phần, mã hàng của NCC). Việc cần làm là gom các dòng đã đặt cùng NCC thành một PO có số, in được PDF, "nhận hết" một bấm. Không cần quyền ghi vào Shopify.
2. **Dữ liệu mẫu để thử.** Merchant mới (và người duyệt App Store) thấy app đầy đủ ngay, không phải chờ đồng bộ hay có đủ lịch sử bán. Hợp với định vị tự phục vụ.
3. **Tạm ẩn sản phẩm.** "Đừng nhắc tôi món này trong 14 ngày." Nhỏ, dễ làm, giảm nhiễu trong danh sách cần nhập.
4. **Dự phóng 30/60/90 ngày** trên trang sản phẩm: "Dự kiến bán 126 cái trong 30 ngày tới." Dễ hiểu hơn "4,2/ngày" và khớp cách merchant nói chuyện với NCC.
5. **Sửa hàng loạt và chọn cột** trong danh sách sản phẩm. Hai điểm bị chê rõ nhất ở Tightly và Prediko.
6. **Nhật ký thay đổi.** Ai đổi lead time, khi nào, dự báo đổi ra sao. Củng cố lời hứa minh bạch.
7. **KPI tồn kho** ở trang Phân tích: vòng quay, tỷ lệ ngày hết hàng, số ngày tồn trung bình.
8. **Xuất XLSX và báo cáo hẹn giờ.** Nhỏ, nhưng merchant làm việc bằng Excel sẽ để ý.
9. **Kế hoạch nhập 26/52 tuần** cho hàng có lead time dài.
10. **Thêm ngôn ngữ** (Nhật, Ý, Hà Lan, Trung giản thể): Prediko có 10, ta có 6.

## 5. Điều không nên theo

- **Chatbot và "AI briefing":** năm app đang làm, và chính review xấu của Prediko cho thấy merchant nhỏ thấy phiền. Định vị của ta là ngược lại.
- **Kiểm kê, mã vạch, ghi tồn kho, backorder:** cần quyền ghi vào Shopify, tăng rủi ro và thời gian duyệt. Shopify admin đã có sẵn phần ghi tồn.
- **BOM, đa kênh, 3PL, kế toán:** là sân của nhóm $49–449/tháng; làm nửa vời sẽ kéo ta ra khỏi mức giá $4.
- **Giá theo doanh thu, email onboarding dồn dập:** bị chê trực tiếp trong review.

## 6. Việc ngoài tính năng

- **Huy hiệu Built for Shopify và những đánh giá đầu tiên** quan trọng hơn bất kỳ tính năng nào trong danh sách trên. Stockie có cả hai.
- **Gói miễn phí:** cân nhắc nâng từ 50 lên 100 sản phẩm để ngang Bee; Stockcast chỉ cho 25, Replenra 5.
- **Từ khoá listing:** "Stocky alternative" đang được Stockie, Stockcast và Prediko dùng; cửa sổ này còn tới khoảng cuối tháng 11/2026.

## Nguồn

- https://apps.shopify.com/stockie
- https://apps.shopify.com/stockcast-inventory-forecast
- https://apps.shopify.com/inventory-forecasting-hero
- https://apps.shopify.com/sensible-forecasting
- https://apps.shopify.com/assisty
- https://apps.shopify.com/bees-forecast-replenishment
- https://apps.shopify.com/stockful-inventory-management
- https://apps.shopify.com/forstock-1
- https://apps.shopify.com/inventory-12 (Replenra)
- https://apps.shopify.com/prediko và review 1–3 sao
- https://apps.shopify.com/fabrikator
- https://apps.shopify.com/cogsy
- https://apps.shopify.com/rewize
- https://apps.shopify.com/replenos
- https://apps.shopify.com/tightly-io
- https://www.sumtracker.com/blog/best-inventory-forecasting-tools-for-shopify
- https://www.getverveai.com/blog/shopify-inventory-forecasting-app-comparison
