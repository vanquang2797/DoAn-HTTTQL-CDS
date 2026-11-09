# Kiểm tra Babi ngày 25/10/2026

## Đã kiểm chứng

- Gmail SMTP xác thực thành công; API gửi lời mời thật tới email người dùng chỉ định trả `delivery=submitted`. Ảnh người dùng cung cấp cho thấy liên kết lời mời mở được trong ứng dụng. Chưa có kiểm tra tự động hộp thư nhận.
- Lời mời demo ID 1 được thu hồi qua HTTP API, đọc lại có `status=revoked`.
- Đã gửi thư xác thực thật tới cùng Gmail. Chưa xác nhận người dùng bấm xác thực thành công.
- Cập nhật sau thao tác người dùng: lời mời ID 2 tới `[email đã ẩn]` có `status=accepted`; tài khoản có `email_verified_at` và membership Viewer trong đúng Space được mời. Đã kiểm tra bằng truy vấn join users/invitations/memberships, không tự sửa trạng thái để đạt kết quả.
- Tài khoản Gmail nhận thử đã là Owner của gia đình khác và chưa xác thực tại thời điểm kiểm tra: không đủ điều kiện nhận quyền Viewer trong gia đình demo. Không tự đổi quyền, xóa gia đình hoặc đánh dấu email đã xác thực.
- Laravel: 40 test, 339 assertions đạt trên SQLite cô lập. Bao gồm chấp nhận lời mời đúng email đã xác thực, từ chối email khác, hạn lời mời, đổi token khi gửi lại, thu hồi token hiện tại, duyệt yêu cầu, quyền Owner/Viewer, sức khỏe, tiêm và media.
- Flutter: 35 test đạt. Phân tích mã không có lỗi. Có test xác thực email gửi POST tới API của ứng dụng, giữ nguyên chữ ký và Bearer, không gửi token tới host lấy từ email.
- Hai widget test bổ sung đạt: sai email không được chấp nhận; lời mời đã thu hồi ưu tiên hiển thị đúng lý do thu hồi. Tổng cộng 37 test Flutter được chạy thành công (bộ 35 và hai test mới).
- Sau sửa onboarding người thân: toàn bộ 38 test Flutter chạy cùng lượt thành công, analyze không có lỗi; test backend mới về người thân đăng ký trước → đăng nhập → chấp nhận bằng cùng tài khoản đạt 12 assertions. Người thân không bị đưa thẳng vào form tạo bé, được chờ/nhận lời mời; mở link mời ưu tiên đăng nhập cho tài khoản có sẵn. Chi tiết tại `ACCOUNT_INVITATION_FLOW.md`.
- HTTP/Queue smoke đạt: CORS, tài khoản, hồ sơ, số đo, upload PNG/MP4/WebM chống trùng, xuất bản bằng Queue, tải media riêng tư, xóa/dọn tệp và thu hồi phiên.
- Chrome camera smoke cuối cùng đạt: camera giả chụp JPEG, quay WebM, Flutter upload chung một kỷ niệm; Queue xuất bản và endpoint Bearer trả media riêng tư; Chrome giải mã/phát WebM và MP4 với `readyState=4`, thời gian phát tăng. Các lượt trước từng lỗi do bộ chọn UI cũ, camera chưa có khung hình và một lượt Queue chưa xuất bản; không xem một lần qua là bằng chứng ổn định trên mọi thiết bị.
- Bản web `frontend/build/app-connected` đã build thành công với API `http://127.0.0.1:8000/api`.
- Demo đang chạy: tự thêm ba số đo giả lập vào đúng hồ sơ “Bé Kiểm Thử” của `babi.owner@example.test`, ngày 20/07, 20/08, 20/09/2026. Bản ghi đánh dấu AUTOTEST, không phải dữ liệu y tế thực.
- Demo đang chạy: đặt lịch hẹn giả lập, đánh dấu chưa tiêm, ghi nhận lần tiêm chính xác, kiểm tra lịch chuyển recorded, hủy thử và kiểm tra giữ audit/chuyển unrecorded.
- Demo đang chạy: Viewer gửi yêu cầu qua link, Owner duyệt, thu hồi link; Viewer vẫn đọc được hồ sơ sau duyệt nhưng POST số đo bị 403. Dùng tài khoản giả lập, không tự cấp quyền cho người thật.

## Sửa trong đợt kiểm tra

- Script demo không ép mail về log; dùng SMTP từ `.env`, tự bật Queue database và GD.
- Health check script dùng `-UseBasicParsing` để chạy được trong Windows PowerShell.
- Thư xác thực mở màn Flutter, sau đăng nhập mới POST chữ ký tới API.
- Màn lời mời có nút gửi thư xác thực; lời mời hết hạn/thu hồi được ưu tiên giải thích.
- Test ngày kỷ niệm dùng lịch Việt Nam khi tạo ngày tương lai, tránh sai lệch giữa UTC và ngày Việt Nam quanh 00:00. Một test lời mời cũng phục hồi đúng đồng hồ fixture sau khi kiểm tra hết hạn.
- Bộ chọn trong camera browser smoke được cập nhật cho nút gửi đăng nhập, số tệp và nút lưu hiện tại.

## Giới hạn

Không xem kết quả SQLite là nghiệm thu MySQL, tải đồng thời hoặc triển khai thực tế. Camera/micro thật và thiết bị Android/iOS chưa được nghiệm thu. Không tự đánh dấu email người thật đã xác thực hoặc giả lập chấp nhận bằng tài khoản của người nhận. Chưa hoàn thành các tính năng chưa có như Admin/ZIP/FCM/bình luận.

Kết quả demo có thể chạy lại bằng `scripts/exercise-local-demo.py`; script chỉ thao tác hồ sơ giả lập đã định danh. Kết quả gần nhất ghi tại `tmp/local-demo-check.json`.
