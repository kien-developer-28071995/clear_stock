# Kịch bản test Clear Stock

Mục tiêu: không một lỗi nào tới tay merchant. Mỗi kịch bản ghi rõ **kết quả mong đợi** và **cách kiểm**:

- **A** = test tự động (lệnh ở cuối file). Chạy mỗi lần trước khi merge.
- **M** = thao tác tay trong trình duyệt như người dùng (skill `.claude/skills/ui-ux-review`). Chạy khi đổi giao diện.
- **S** = chỉ kiểm được trong Shopify admin thật (store dev + tunnel). Chưa có cách tự động.

Quy tắc: mọi lỗi tìm thấy bằng **M** hoặc **S** phải có thêm một kịch bản **A** để không quay lại.

## 1. Cài đặt, đăng nhập, phân quyền

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 1.1 | Mở app lần đầu từ Shopify (token hợp lệ, shop chưa có) | Shop được tạo, token lưu mã hoá, bắt đầu đồng bộ | A (backend) |
| 1.2 | Gọi API không có token / token sai / token hết hạn | 401 `session_expired`, kèm header để App Bridge thử lại | A |
| 1.3 | Shop A gọi dữ liệu của shop B (id trong URL) | 404, không lộ dữ liệu | A |
| 1.4 | Trình duyệt vào domain backend | Chuyển sang frontend (giữ query); không có shop → website | A |
| 1.5 | Frontend gọi API khác domain | CORS cho phép, đọc được tên file tải về | A (`make e2e-split`) |
| 1.6 | Mở frontend ngoài admin với `?shop=` | Chuyển vào admin của shop đó | S |
| 1.7 | Trang app trong iframe của shop khác / không có shop | Trình duyệt chặn (header `frame-ancestors`) | A (CI `web-config`), S |
| 1.8 | Gỡ app, rồi `shop/redact` sau 48 giờ | Dừng job; xoá sạch mọi bảng của shop | A |
| 1.9 | Webhook sai chữ ký / gửi lặp | 401 / chỉ xử lý một lần | A |

## 2. Onboarding và đồng bộ

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 2.1 | Trả lời 2 câu onboarding | Lưu lead time + email, vào Home | A |
| 2.2 | Đồng bộ đang chạy | Thanh tiến độ; Home không trống | A |
| 2.3 | Đồng bộ lỗi 2 lần liên tiếp trong 24 giờ | Một email báo, không lặp; thẻ lỗi có nút thử lại | A |
| 2.4 | Bấm "Sync now" | Bắt đầu đồng bộ, không bấm được lần hai khi đang chạy | A |
| 2.5 | Đồng bộ thật với store có đơn hàng | Số bán theo ngày khớp Shopify | S |

## 3. Dự báo (lõi)

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 3.1 | Sản phẩm bán đều | Trung bình có trọng số 7/30/90, ngày hết hàng, điểm đặt lại đúng | A |
| 3.2 | Có ngày hết hàng | Ngày đó bị loại khỏi trung bình, ghi trong giải thích | A |
| 3.3 | Ít lịch sử / biến động lớn | Độ tin cậy thấp, nói rõ | A |
| 3.4 | Combo | Doanh số combo cộng vào thành phần | A |
| 3.5 | Ghi đè tốc độ bán, có hạn | Dùng số ghi đè tới hết hạn rồi về tự động | A |
| 3.6 | Hàng đang về (Shopify + đơn đánh dấu) | Trừ vào lượng gợi ý, không đổi ngày hết hàng | A |
| 3.7 | MOQ, quy cách thùng, min/max, chu kỳ, ngày đặt hàng của NCC | Lượng và ngày đặt làm tròn đúng, có dòng giải thích | A |
| 3.8 | Sự kiện bán hàng sắp tới / đã qua | Nhu cầu nhân hệ số / quy về mức thường | A |
| 3.9 | Ngừng nhập | Không gợi ý, không cảnh báo, không vào kế hoạch | A |
| 3.10 | Mọi con số trên trang sản phẩm | Khớp với câu giải thích bên dưới | A, M |

## 4. Màn hình và thao tác

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 4.1 | Mở từng trang trên Free / Starter / Growth | Hiện đúng, không lỗi console, không gọi API bị khoá | A |
| 4.2 | Home → "See all" → Cần nhập hàng | Đúng danh sách, đúng nhóm | A, M |
| 4.3 | Chọn sản phẩm → Đánh dấu đã đặt | Hộp thoại đóng, có thông báo, sản phẩm rời danh sách, đơn hiện ở Đơn đã đặt | A, M |
| 4.4 | Sản phẩm hết hàng đã đặt xong | Vẫn hiện, ghi "Nothing more to order", không tick được | A, M |
| 4.4b | Sản phẩm hết hàng nhưng đơn đang về | Nhãn, bộ lọc và số đếm trên Home đều là "Out of stock", không lọt vào nhóm "OK" | A |
| 4.5 | Nhận hàng / nhận một phần | Trạng thái và lượng đang về đổi đúng | A, M |
| 4.6 | Huỷ đơn | Hỏi lại trước; "Keep order" giữ nguyên | A, M |
| 4.7 | Tìm, lọc, sắp xếp, phân trang sản phẩm | Kết quả khớp API; bộ lọc nằm trong URL | A, M |
| 4.8 | Sửa cài đặt sản phẩm → Save / Discard | Save bar hiện; Save cập nhật con số phía trên; Discard trả lại | A, M |
| 4.9 | Trang cài đặt sản phẩm của sản phẩm đã có NCC | Ô NCC hiện đúng tên, không phải "No supplier" | A, M |
| 4.10 | Thêm / sửa / xoá nhà cung cấp | Bảng cập nhật, xoá có hỏi lại | A, M |
| 4.11 | Thêm sự kiện, bỏ dở bằng Esc, mở lại | Form trống | A, M |
| 4.12 | Đặt / xoá ngân sách | "Left this month" đổi theo | A, M |
| 4.13 | What-if đổi % | Bảng và URL đổi, không lưu gì | A, M |
| 4.14 | Kế hoạch nhập, lịch đặt hàng | Tổng theo tuần = tổng theo sản phẩm | A |
| 4.15 | Phân tích: 3 tab | Mỗi tab đúng nội dung, tab nằm trong URL | A, M |
| 4.16 | Cài đặt: lưu lead time, cảnh báo, tóm tắt tuần | Lưu đúng, dự báo tính lại | A |
| 4.17 | Đổi ngôn ngữ (6 ngôn ngữ) | Toàn bộ chữ đổi, không lộ khoá dịch | A |
| 4.18 | Xuất CSV (sản phẩm, PO, kế hoạch, what-if, hàng nên xả) | File đúng cột, đúng số dòng, ô bắt đầu bằng `=` bị vô hiệu | A |
| 4.19 | Màn hình 390 px | Không tràn ngang, mỗi hàng tối đa hai nút | M |
| 4.20 | Thanh tiêu đề trang (nút Add, Export) trong admin | Hiện và bấm được | S |

## 5. Gói và bật/tắt tính năng

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 5.1 | Tính năng ngoài gói | Khoá + lời mời nâng gói; API 402 | A |
| 5.2 | Free quá 50 sản phẩm | Chỉ 50 sản phẩm bán chạy nhất được dự báo | A |
| 5.3 | Nâng / hạ gói, tháng / năm, dùng thử một lần | Qua Billing API; hộp cảnh báo hạ gói liệt kê đúng thứ sẽ dừng | A, M |
| 5.4 | Mất webhook billing | Đối soát hằng ngày sửa lại gói | A |
| 5.5 | Tắt từng công tắc (36) | API 404, giao diện biến mất, tác dụng ngầm dừng | A |
| 5.6 | Tắt hết 36 công tắc | Phần lõi vẫn chạy, không gọi API đã tắt | A (`make e2e-features-off`) |
| 5.7 | Bộ v1 (24 bật, 12 tắt) | Đúng những gì bản nộp App Store hứa | A (`make feature-screenshots`) |
| 5.8 | Thanh toán thật trên store dev | Charge thử được chấp nhận, quay về trang Gói | S |

## 6. Khi có sự cố

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 6.1 | API lỗi 500 khi mở từng trang (17 trang + trang sản phẩm) | Báo lỗi + "Try again"; không spinner vô hạn, không trang trắng | A (`failures.spec`) |
| 6.2 | API hoạt động lại → Try again | Trang tải bình thường | A |
| 6.3 | Lưu thất bại (500 / mất mạng) | Có thông báo lỗi; chữ đã gõ còn nguyên; vẫn ở trạng thái chưa lưu | A |
| 6.4 | Thao tác trong hộp thoại thất bại | Hộp thoại ở lại, nút hết quay, có thông báo | A |
| 6.5 | Nhập sai (0, âm, ngày kết thúc trước ngày bắt đầu, email sai) | Lỗi hiện ngay tại ô, bằng câu chữ, không lưu gì | A |
| 6.6 | Lỗi JavaScript trong trang | Trang báo lỗi thân thiện, lỗi gửi về Slack | A |
| 6.7 | Shopify trả lỗi / giới hạn tốc độ khi đồng bộ | Job thử lại có giãn cách, ghi log | A |
| 6.8 | Email gửi lỗi | Thử lại 3 lần, ghi `email_logs` | A |

## 6b. Dữ liệu bất thường

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 6b.1 | Gửi rác tới **mọi** API (sai kiểu, số cực lớn, chuỗi 70.000 ký tự, mã HTML/SQL, id không tồn tại) | Trả 2xx/4xx, không bao giờ 500 | A (`HostileInputTest`) |
| 6b.2 | Mọi API khi không có token | 401 | A |
| 6b.3 | Shop A dùng id của shop B ở mọi route | Không lộ, không sửa được dữ liệu shop B | A |
| 6b.4 | Địa chỉ trình duyệt bị sửa (tham số sai, id sai, mã HTML) | Trang vẫn hiện, không chạy mã lạ | A (`failures.spec`) |
| 6b.5 | Bấm nhiều lần liên tiếp / hai request trùng cùng lúc | Chỉ ghi một lần | A |
| 6b.6 | Thêm sự kiện trùng tên và ngày | Từ chối (nếu không tác động bị nhân đôi) | A |
| 6b.7 | 30 shop ngẫu nhiên (tồn âm, bán thưa, đột biến, mọi quy tắc đặt hàng) | Các bất biến luôn đúng: không gợi ý âm, không ngày trong quá khứ, đủ thùng/MOQ, nhãn = bộ lọc = số đếm, tổng kế hoạch khớp | A (`ForecastInvariantsTest`) |
| 6b.8 | Bán cực chậm + tồn cực lớn | Không có ngày (quá 10 năm), không gợi ý đặt, không tràn cột | A |
| 6b.9 | Sản phẩm không ai mua có đặt max / quy cách thùng / tồn âm | Không gợi ý đặt hàng | A |
| 6b.11 | Webhook có chữ ký đúng nhưng nội dung rác (mọi topic, shop chưa cài) | Luôn nhận, xử lý không sập | A (`HostileWebhookTest`) |
| 6b.12 | File xuất của Shopify có dòng thiếu trường / sai kiểu / số âm / số cực lớn | Nhập phần hợp lệ, bỏ phần rác, không dừng đồng bộ (dòng không phải JSON vẫn làm hỏng lần đọc: file tải dở phải tải lại) | A |
| 6b.13 | File CSV tải lên: rỗng, nhị phân, sai mã hoá, công thức, 6.000 dòng, ô 200.000 ký tự | Không lỗi 500 | A |
| 6b.14 | Lệnh theo lịch khi có shop đã gỡ / chưa đồng bộ / token hết hạn / múi giờ lạ, và Shopify lỗi 503, 401, trả rác, không kết nối được | Lệnh chạy xong cho mọi shop; job chỉ thất bại vì Shopify (để thử lại), không sập vì mã của ta | A (`ScheduledTasksTest`) |
| 6b.15 | Tên sản phẩm 255 ký tự có mã HTML, emoji, chữ Do Thái | Hiện thành chữ, không chạy mã, không tràn ngang ở 1280px và 390px | A |
| 6b.16 | Tiêu đề email có ký tự xuống dòng từ tên shop | Một dòng sạch | A |
| 6b.17 | Hệ thống báo cáo (`admin/`): tham số là mảng, mã HTML trong tên shop, khách chưa đăng nhập | Không 500, không chạy mã, chuyển về trang đăng nhập | A |
| 6b.18 | Cả bộ E2E ở khổ điện thoại (`E2E_PHONE=1`) | Mọi thao tác làm được; 3 test trượt là do bố cục hẹp cố ý ẩn cột ABC và rút danh sách còn 4 dòng | M (chạy tay khi đổi bố cục) |
| 6b.10 | Toàn bộ test trên MySQL | Qua như trên SQLite | A (`make test-mysql`, CI `backend-mysql`) |

## 7. Chống làm phiền và an toàn dữ liệu

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 7.1 | Digest cảnh báo | Một email mỗi kỳ, không nhắc lại sản phẩm trong 7 ngày | A |
| 7.2 | Cảnh báo tức thời | Gom 15 phút, tối đa 3/ngày, im 21h–7h | A |
| 7.3 | Không lưu tên, email, địa chỉ khách hàng | Payload webhook chỉ giữ id | A |
| 7.4 | Token Shopify, webhook Slack | Mã hoá trong DB, bị che trong log và Slack | A |
| 7.5 | Xoay `APP_KEY` | Token cũ vẫn đọc được qua `APP_PREVIOUS_KEYS` | A |

## 8. Triển khai

| # | Kịch bản | Mong đợi | Kiểm |
|---|---|---|---|
| 8.1 | Deploy thiếu biến bắt buộc (`FRONTEND_URL`…) | Dừng trước khi đổi gì | A (CI `env-template`, `envtool`) |
| 8.2 | Bản build frontend | Có client id, nạp App Bridge trước, trỏ đúng API | A (`npm run build:check`) |
| 8.3 | Cấu hình nginx / Caddy | Hợp lệ; header theo shop đúng cả với `shop` giả mạo | A (CI `web-config`) |
| 8.4 | Migration | Chỉ thêm thì tự chạy sau backup; xoá/đổi thì dừng chờ | A (deploy.sh) |
| 8.5 | Sau deploy | Frontend + CORS của API đang chạy trả lời đúng | A (bước cuối Deploy), S |

## Lệnh chạy

```bash
make test                 # backend (Pest, SQLite)
make test-mysql           # cùng bộ test trên MySQL (hệ quản trị của production)
make admin-test           # hệ thống báo cáo
cd frontend && npx tsc --noEmit && npm run i18n:check
make e2e                  # mọi màn hình trên 3 gói + extension + sự cố (failures.spec)
make e2e-features-off     # tắt hết công tắc
make e2e-split            # frontend build thật, API khác origin
make feature-screenshots  # bộ v1
bash deploy/check-web-config.sh   # nginx + Caddy (cần kéo được image caddy)
```
