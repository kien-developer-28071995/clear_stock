# App tương tự Clear Stock trên Shopify và điều học được

Khảo sát ngày 2026-10-06 từ trang App Store của từng app và hai bài so sánh (nguồn ở cuối). Số đánh giá, giá và tính năng là theo trang công khai lúc khảo sát; tôi chưa cài thử app nào, nên "có tính năng X" nghĩa là app **tự mô tả** như vậy.

Giá của Clear Stock để so: Free (50 sản phẩm), Starter $4, Growth $6.

Cập nhật 2026-10-08 (lần 9): thêm 28 app vào Nhóm A và 6 app ngách, sửa dòng Replenra, cập nhật mục 2, 3, 6. Các app này đọc qua công cụ tóm tắt trang listing, nên chi tiết nhỏ có thể lệch.

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
| **Replenra** (Adease) | 2026-09-14 | chưa có | Free / $4.99 / $9.99 / $19.99 (đọc lại 2026-10-08; dòng cũ ghi $29–199 và 5 sản phẩm là sai hoặc đã đổi) | 500 SKU, 3 PO/tháng | PO, nhận hàng, MOQ, cảnh báo tồn thừa, email PO cho NCC từ $4.99, phân tích lead time giao hàng của NCC ở $9.99 |

#### Bổ sung 2026-10-08 (lần 9): app mới hoặc chưa có trong bảng

Đọc từ trang listing ngày 2026-10-08. Trừ khi ghi khác, app chỉ có tiếng Anh và chưa có review.

| App | Ra mắt | Đánh giá | Giá/tháng | Gói miễn phí | Điểm đáng chú ý |
|---|---|---|---|---|---|
| **Supremo** (Sixthshop) | 2026-06-22 | 5.0 (7), 6/7 từ Ấn Độ | Free / $19 / $49 / $99 | 100 sản phẩm, 2.000 biến thể | Tự tạo PO khi chạm điểm đặt lại, kiểm kê, pick list, chuyển kho. App mới duy nhất đang có review |
| **Stockbeam** | 2026-09-24 | chưa có | Free / $15 | **1.000 SKU** | "Reorder with reasons"; một PO mỗi NCC (PDF/email), nhận từng phần có lô và hạn dùng, kiểm kê trong POS, ngân sách theo nhóm hàng, đối chiếu hoá đơn NCC, bảng điểm NCC, Flow |
| **Stock Superstar** (ZOJO) | 2026-09-23 | chưa có | Free (listing không ghi giới hạn) | Toàn bộ | Gợi ý nhập "each one explained", PO có thuế/chiết khấu/hạn thanh toán + PDF, nhận hàng bằng máy quét, in nhãn |
| **Restock Forecast** (lala.com) | 2026-08-05 | chưa có | Free / **$4.99** / $9.99 / $19.99 | 50 sản phẩm | Gói $4.99 không giới hạn sản phẩm; PO có phí vận chuyển và thuế nhập, kiểm kê mã vạch, nhập Stocky, Slack, Excel. Giá sát ta nhất |
| **Replenish** (Codequal) | 2026-05-08 | chưa có | $10 / $29 / $59 | Không (thử 14 ngày) | "Explain every reorder"; 500 SKU ở $10; ABC/XYZ, combo/BOM, Slack từ $29; mùa vụ, dòng tiền ở $59 |
| **ReplenishRadar** | 2026-05-22 | chưa có | Free / $9.99 / $99 / $199 / $499 | 500 SKU, dự báo hằng tuần | Shopify + Amazon/FBA; "returns-adjusted, with the math shown"; chat AI, MCP, cổng NCC |
| **Shelfwise** (Ayush Pathak) | 2026-10-06 | chưa có | Free / $9 / $19 | 25 sản phẩm bán chạy | Chỉ đọc; xếp theo độ gấp kèm lý do; **gửi đơn qua email hoặc WhatsApp**; theo dõi đơn đã đặt để khỏi đặt trùng |
| **Styrla** | 2026-09-28 | chưa có | Free / $39 / $79 | 300 SKU, 1 chi nhánh | Điểm đặt lại theo thời gian giao thực tế của NCC; PO PDF/CSV; nhận hàng ghi tồn; nhập Stocky; EN + SV |
| **Reorderly** (El Ansari Konsult) | 2026-09-11 | chưa có | Free / $12 / $39 | 50 sản phẩm | "With the math shown"; PO có số + PDF; hỏi đáp "Ask Reorderly" tính theo số câu; tự đặt hàng ở $39 |
| **Replenly** (VK Apps) | 2026-07-20 | 5.0 (1) | Free / $19 / $39 | 100 SKU, 5 PO/tháng | PO + landed cost, nhận từng phần, kiểm kê, công thức/nguyên liệu, email tuần, nhập Stocky |
| **Restockly** | 2026-07-13 | chưa có | $29 | Không (thử 14 ngày) | PDF PO một bấm, điều khoản thanh toán và tiền tệ của NCC, nhận hàng bằng mã vạch |
| **BuyAhead** (Northline) | 2026-09-18 | chưa có | $19 / $49 | Không (thử 14 ngày) | Gói $19 chỉ đọc, "calculations shown", mức phục vụ (service level); PO, kiểm kê, chuyển kho, định giá tồn ở $49; hỏi bằng câu thường |
| **Wrightloop Stock** | 2026-09-29 | chưa có | Free / $19.99 | 20 sản phẩm | PO, nhận hàng, Slack ở Free; nhập cài đặt sản phẩm bằng CSV chỉ ở Pro |
| **Smart Inventory Alerts** (Lusca) | 2026-09-15 | chưa có | Free / $9.99 / $19.99 | 10 sản phẩm | Tốc độ bán 7/30/60 ngày; digest email/Slack có giờ yên lặng; **email hoặc WhatsApp cho NCC một bấm**; email tuần |
| **Nasolv Reorder** | 2026-09-11 | chưa có | Free / $29 / $79 | Có (listing không ghi giới hạn) | "Where we cannot be sure, we say so"; EN + DE |
| **Restock Radar** | 2026-09-11 | chưa có | Free / $12 ($115/năm) | Có (giới hạn không ghi) | PO một bấm (PDF, email, CSV nhập của Shopify), email tuần, không ghi tồn |
| **Restockly Inventory Forecast** (Stelar) | 2026-09-08 | chưa có | Free | Toàn bộ | Bốn ô: đặt ngay / sắp đặt / đủ hàng / không bán; danh sách theo vendor "ready to copy and send" |
| **StockSight** (Borneo) | 2026-09-17 | chưa có | Free / $12 | Không giới hạn sản phẩm | Hướng nhu cầu 5 mức, độ biến động, tồn âm; Excel ở $12. Không thấy lượng đặt gợi ý |
| **StockLane** (Wardwell) | 2026-10-06 | chưa có | $8 / $24 | Không (thử 30 ngày) | Cảnh báo theo chi nhánh, một email mỗi sáng; điểm đặt lại theo tốc độ bán ở $24; **xuất/nhập cài đặt theo SKU giữa các store** |
| **StockDue** | 2026-09-29 | chưa có | $39 / $69 / $149 | Không (thử 3 ngày) | "Calculation evidence", nói rõ khi thiếu dữ liệu; không tạo PO, không ghi tồn; ngân sách ở $149 |
| **RestockPilot** | 2026-09-24 | chưa có | $12 | Không | Email kèm PO nháp khi dưới điểm đặt lại; không tự liên hệ NCC |
| **Kavediq** | 2026-09-11 | chưa có | $14.99 | Không | Cảnh báo hằng ngày, phát hiện bán đột biến, email tuần |
| **Agent Forecast** | 2026-10-06 | chưa có | Free | Toàn bộ | Chỉ đọc; AI giải thích con số, merchant tự mang API key Gemini/Claude; chỉ đọc 60 ngày đơn |
| **Backroom** | 2026-09-28 | chưa có | Free / $9 / $19 | 10 PO/tháng | Thay Stocky cho cửa hàng: PO, nhận hàng theo giá bình quân, min/max, kiểm kê, nhãn |
| **MarginSentry** | 2026-10-06 | chưa có | Free / $4.99 / $9.99 / $39.99 | 1 NCC | Bảng giá NCC → biên lợi nhuận; PO PDF, điểm đặt lại, dự báo; ghi giá vốn về Shopify có xác nhận; **12 ngôn ngữ** |
| **Foreshelf** | 2026-07-30 | chưa có | Free / $19 / $49 | 50 SKU | (từ lần 8) "Shows the maths"; PO và nhận hàng từ $19; mùa vụ và xu hướng chỉ ở $49 |
| **Days of Cover** | 2026-08-10 | chưa có | Free / $19 / $39 | 30 biến thể, 5 PO/tháng | (từ lần 8) Xếp theo lợi nhuận có nguy cơ mất, ngân sách, lợi nhuận mất tháng trước |
| **Restock: Reorder Forecasting** | 2026-07-20 | chưa có | Free / $19 | 2 sản phẩm | (từ lần 8) "ML", snooze, email tuần |

App ngách (một việc, bán riêng):

| App | Ra mắt | Giá/tháng | Làm gì | Clear Stock |
|---|---|---|---|---|
| **Incoming Stock Digest** (Metigro) | 2026-10-06 | Free (tuần) / $3.99 (ngày) | Email hàng đang về theo chi nhánh: mới gửi, đã về, về một phần, đứng yên | Một phần: có "đang về" và đơn quá hạn trong app, chưa có trong email (roadmap #85) |
| **Sizecurve** | 2026-09-29 | $29 | Đường cong size theo nhu cầu đã trừ hàng trả, size run gãy, PO CSV; chỉ đọc | Có "Sản phẩm lệch size"; doanh số vốn đã trừ hàng trả; chưa chia lượng đặt theo size |
| **Dead Stock Radar** / **Deadstock Hero** | 2026-09-15 / 09-25 | $9.99 / $19.90 | Hàng không bán + tiền kẹt; gắn tag xả hàng, ẩn khỏi cửa hàng, tạo giảm giá | Có danh sách xả hàng + CSV; không ghi tag/giảm giá |
| **Transferly** (Focus Labs) | 2026-09-17, 5.0 (1) | $24.99 | Gợi ý chuyển kho từ doanh số POS, tạo phiếu chuyển nháp | Có (Growth, tắt ở v1) |
| **PackSaviour** | 2026-09-16 | $33 | Dự báo vật tư đóng gói theo đơn | Không |

Đã thấy trên trang danh mục nhưng **chưa mở listing** (chỉ có tên và dòng mô tả): Tallyfold, Replinish, Inventory King, Stockbeak, Olli Lite, ReorderPilot, Restack, PO Simple, Reorderly (`reorderly-3`), InventoryQ, Ballast, Shelfward, Quaybound, SizeGuard, MakeStock, StockHQ, Stockitron, SupplyCore. OrderPoint (Toolster) được nhắc trên Community nhưng listing báo "not currently available". Stockler, Sheafly, Trace ($2.99, theo một bài Community) chưa kiểm chứng.

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

- **Giá:** $4 vẫn là giá trả tiền thấp nhất đọc được cho dự báo không giới hạn sản phẩm, nhưng khoảng cách chỉ còn dưới $1: Restock Forecast $4.99 không giới hạn (kèm PO và kiểm kê), Replenra $4.99 cho 5.000 SKU. Ba app miễn phí hoàn toàn (Stock Superstar, Restockly Inventory Forecast, Agent Forecast). Stockie nhìn như ngang giá ($4.99) nhưng ở mức đó chỉ có cảnh báo; dự báo của họ là $29.99.
- **Gói miễn phí:** 50 sản phẩm của ta nay thuộc nhóm hẹp. Stockbeam 1.000 SKU, Replenra và ReplenishRadar 500, Styrla 300, Supremo, Replenly và Bee 100; ngang ta có Restock Forecast, Reorderly, Foreshelf.
- **Thông điệp "minh bạch":** không còn là của riêng ta; tới 2026-10-08 có hơn mười app dùng gần nguyên câu "the math shown" (Reorderly, Nasolv, Styrla, BuyAhead, Stock Superstar, Stockbeam, Shelfwise, ReplenishRadar, Replenish, StockDue, Foreshelf, Days of Cover). "Chỉ đọc" cũng đã có Shelfwise, Sizecurve, Agent Forecast, BuyAhead. Stockcast dùng đúng câu "plain math, not black-box AI". Ta vẫn hơn ở chỗ giải thích từng con số ngay trên trang sản phẩm và cho chỉnh, nhưng trang giới thiệu cần cho thấy điều đó bằng ảnh chụp, không chỉ bằng khẩu hiệu.
- **Nhu cầu có thật:** review 1 sao của Prediko chê đúng những thứ ta tránh: "dự báo là hộp đen", "liên tục đẩy AI khó chịu", "bị spam email gần như hằng ngày", bảng tốn chỗ. Đây là bằng chứng cho định vị hiện tại.
- **Uy tín:** Stockie có huy hiệu Built for Shopify và 162 đánh giá; Assisty 359; Prediko 257. Ta chưa có đánh giá nào. Đây là khoảng cách lớn nhất, không phải tính năng. Trong 72 app ra mắt 2026-09-08 → 10-06 ở danh mục này gần như tất cả cũng 0 đánh giá; chỉ Supremo (7), Replenly (1), Transferly (1) có.

## 3. So tính năng

"Có" = đã làm trong Clear Stock (kể cả đang tắt ở bản v1).

| Tính năng | Ai có | Clear Stock |
|---|---|---|
| Dự báo theo sản phẩm, bỏ ngày hết hàng, mùa vụ | hầu hết | Có |
| Giải thích con số, cho chỉnh | Stockcast (công thức), Reorderly, Styrla, BuyAhead, Stockbeam, Shelfwise, Nasolv, StockDue, ReplenishRadar, Replenish | Có, sâu hơn |
| Combo / bundle | Prediko, Rewize, Fabrikatör, Stockcast, BuyAhead, Replenish ($29) | Có |
| Mô phỏng tăng trưởng, sự kiện bán hàng | IFH, Cogsy | Có |
| Ngân sách / dòng tiền, tiền kẹt trong tồn | Cogsy, Stockcast | Có |
| ABC, hàng chậm, tồn thừa, sell-through | Assisty, Monocle | Có |
| Độ chính xác dự báo | Stockcast; ReplenishRadar ("stockout backtesting") | Có |
| Nhập từ Stocky | Stockcast, Prediko, Stockie, Restock Forecast, Styrla, Stockbeam, Replenly, Restockly, MarginSentry | Có |
| Cảnh báo email, Slack, Shopify Flow | Stockie, Stockful, Assisty | Có (Slack và Flow tắt ở v1) |
| Dự báo theo chi nhánh, chuyển kho | Stockie, Prediko, Assisty | Có (tắt ở v1) |
| **PO là một chứng từ: tạo, gửi, nhận hàng** | Stockie, Stockcast, Assisty, Prediko, Fabrikatör, Replenra, IFH; app mới: Reorderly, Restock Radar, Stockbeam, Stock Superstar, Replenly, Restockly, Styrla, MarginSentry, Restock Forecast, Supremo | **Một phần**: xuất CSV, "đánh dấu đã đặt" theo từng sản phẩm, nhận một phần; chưa gom thành PO có số, chưa có PDF (roadmap #67); gửi email NCC đã làm nhưng tắt ở v1 |
| **Gửi đơn cho NCC qua WhatsApp / chép văn bản** | Shelfwise, Smart Inventory Alerts, Restockly Inventory Forecast | **Chưa** (roadmap #84) |
| **Email hàng đang về / đơn quá hạn** | Incoming Stock Digest | **Một phần**: có trong app, chưa có trong email (roadmap #85) |
| **Xuất và nhập cài đặt sản phẩm theo SKU** | StockLane, Wrightloop ($19.99) | **Một phần**: nhập có, xuất theo mẫu chưa (roadmap #86) |
| **Độ ổn định nhu cầu (XYZ / volatility)** | Monocle, Replenish ($29), StockSight | **Một phần**: hệ số biến thiên tuần nằm trong độ tin cậy, chưa thành nhãn lọc được (roadmap #88) |
| Tự đặt hàng / tự tạo PO khi chạm điểm đặt lại | Supremo, Reorderly ($39), ReplenishRadar | Một phần: tự gửi email NCC theo lịch (Growth, tắt ở v1) |
| Tạm ẩn sản phẩm (snooze) | Sensible, Restock | Có (từ 2026-10-06) |
| Dữ liệu mẫu / store demo để thử | Fabrikatör | Có (từ 2026-10-07) |
| Dự phóng nhu cầu 30/60/90 ngày | Stockcast | Có (từ 2026-10-06) |
| **Chọn cột, bảng gọn** | (Prediko bị chê thiếu) | **Chưa** |
| **Sửa hàng loạt trong danh sách** | (Tightly bị chê thiếu) | **Một phần** (extension ở trang sản phẩm Shopify; nhập cài đặt bằng CSV từ 2026-10-07) |
| Nhật ký thay đổi | Stockcast | Có (từ 2026-10-06, chưa ghi ai đổi) |
| **Xuất XLSX; báo cáo hẹn giờ gửi email** | Sensible, Stockful, StockSight, Restock Forecast | **Chưa** (chỉ CSV, và một email tóm tắt tuần) |
| **KPI tồn kho (vòng quay, tỷ lệ hết hàng)** | Bee, Assisty | **Chưa** |
| **Kế hoạch dài hơn 12 tuần** | Cogsy (12 tháng) | **Chưa** |
| **Nhiều ngôn ngữ hơn** | Prediko (10), MarginSentry (12) | 6 (gần như mọi app mới năm 2026 chỉ có tiếng Anh) |
| Landed cost theo từng PO | Stockcast, Stockie, Restock Forecast, Replenly, MarginSentry | Một phần (theo % của NCC) |
| Kiểm kê, quét mã vạch, ghi tồn về Shopify | Stockie, Assisty, Prediko; đa số app mới (Supremo, Stockbeam, Stock Superstar, Restock Forecast, Styrla, Replenly, Backroom, BuyAhead $49, Wrightloop) | Không (quyết định không xin quyền ghi) |
| Backorder / pre-order | Fabrikatör, Cogsy | Không |
| BOM / nguyên liệu | Prediko, Organizely | Không |
| Đa kênh, 3PL/WMS, kế toán | Prediko, Sumtracker, Fabrikatör, ReplenishRadar (Amazon/FBA) | Không |
| Xả hàng bằng giảm giá / tag tự tạo | Deadstock Hero, Dead Stock Radar | Không (cần quyền ghi; có danh sách xả hàng + CSV) |
| Lô, hạn dùng | Stockbeam, Tightly | Không |
| Chatbot / hỏi đáp AI | Rewize, Tightly, IFH, ReplenOS, Assisty, Reorderly, BuyAhead, ReplenishRadar, Agent Forecast | Không (trái định vị; hướng thay thế là Sidekick app extension, roadmap #74) |

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
- **Gói miễn phí:** (cập nhật 2026-10-08) mức 100 không còn đủ để nổi bật: Stockbeam 1.000, Replenra và ReplenishRadar 500, Styrla 300. Nếu nâng thì 200–300; đo chi phí hạ tầng một shop Free trước (roadmap #93).
- **Tốc độ ra mắt:** khoảng 15–20 app mới mỗi tuần trong danh mục, gần như đều chưa có đánh giá. Nộp v1 sớm và có 5 đánh giá đầu tiên đáng giá hơn mọi mục tính năng còn lại (roadmap #92).
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

Bổ sung 2026-10-08 (lần 9):

- https://apps.shopify.com/categories/orders-and-shipping-inventory-inventory-optimization/all?sort_by=newest (trang 1–3)
- https://apps.shopify.com/supremo và /reviews
- https://apps.shopify.com/stockbeam
- https://apps.shopify.com/stock-superstar
- https://apps.shopify.com/lala-forecast (Restock Forecast)
- https://apps.shopify.com/replenish
- https://apps.shopify.com/replenishradar
- https://apps.shopify.com/inventory-12 (Replenra, đọc lại)
- https://apps.shopify.com/restock-app-1 (Shelfwise)
- https://apps.shopify.com/styrla
- https://apps.shopify.com/reorderly-app
- https://apps.shopify.com/restock-11 (Replenly)
- https://apps.shopify.com/restockly-4
- https://apps.shopify.com/buyahead
- https://apps.shopify.com/wrightloop-stock
- https://apps.shopify.com/smart-inventory-alerts
- https://apps.shopify.com/nasolv-reorder
- https://apps.shopify.com/restock-radar-7
- https://apps.shopify.com/forecastor (Restockly Inventory Forecast)
- https://apps.shopify.com/stock-levels (StockSight)
- https://apps.shopify.com/stocklane
- https://apps.shopify.com/stockdue
- https://apps.shopify.com/restockpilot-1
- https://apps.shopify.com/kavediq
- https://apps.shopify.com/ai-forecast-agent
- https://apps.shopify.com/backroom-purchase-orders
- https://apps.shopify.com/marginsentry
- https://apps.shopify.com/incoming-stock-digest
- https://apps.shopify.com/sizecurve
- https://apps.shopify.com/deadstock-hero
- https://apps.shopify.com/dead-stock-radar
- https://apps.shopify.com/transferly
- https://apps.shopify.com/packsaviour
- https://apps.shopify.com/stockahead-1 (Foreshelf), https://apps.shopify.com/days-of-cover, https://apps.shopify.com/restock-7
- https://apps.shopify.com/prediko/reviews, https://apps.shopify.com/assisty/reviews, https://apps.shopify.com/stockie/reviews (xếp theo mới nhất)
