# Tài khoản người thân và lời mời gia đình

Đối chiếu `E:/DA/DA_HTTTQL_BaoCao/BPMN/PhanQuyenNguoiDung.drawio.png` (ô người được mời: “đăng nhập/đăng ký nếu cần”) và `chapters/chapter2.tex`, phần Quy trình mời thành viên gia đình, UC-F01/UC-F02.

1. Mỗi người có một tài khoản Babi riêng. Tài khoản không đồng nghĩa với hồ sơ bé hoặc quyền gia đình.
2. Ông bà được đăng ký trước và chờ lời mời, không bắt tạo bé/Space. Đăng ký chưa tạo membership và chưa cấp quyền Owner/Viewer.
3. Khi nhận lời mời, người đã có tài khoản chỉ đăng nhập bằng đúng email đã đăng ký, không đăng ký lại. Nếu đã xác thực email trước đó thì không xác thực lại để nhận mỗi lời mời.
4. Người thực sự chưa có tài khoản Babi đăng ký một lần; việc có địa chỉ Gmail không tự tạo tài khoản Babi. Đăng nhập Gmail/OAuth hoặc đăng nhập không mật khẩu là cơ chế khác, chưa được tích hợp.
5. Liên kết lời mời được giữ trong phiên điều hướng qua đăng nhập/đăng ký. Sau đó hiển thị người mời, gia đình, quyền đề nghị và nút chấp nhận/từ chối; thiếu xác thực thì hướng dẫn xác thực.
6. Chỉ chấp nhận hợp lệ mới tạo membership. Không tự tạo gia đình cho người thân và không tự cấp quyền khi gửi thư.
7. Tài khoản đã là Owner không nhận Viewer ở Space khác theo quy tắc hiện tại; tài khoản Viewer được tham gia nhiều gia đình.

Màn tài khoản chưa có hồ sơ nay có Nhận lời mời gia đình, Xác thực email trước và Đăng xuất. Tạo hồ sơ bé và gia đình là lựa chọn riêng cho cha mẹ. Người nhận có thể dán liên kết hoặc mã để xem lời mời trước khi phản hồi.

## Mở lại link Family Space khi đã là thành viên

Link xin tham gia khác với lời mời email. Trước khi hiện nút gửi yêu cầu, UI gọi API preview để kiểm tra link và membership hiện tại. Người đã là thành viên (kể cả được thêm qua lời mời email, không có bản ghi yêu cầu tham gia) thấy “Bạn đã là thành viên” và nút “Vào gia đình”; không tạo yêu cầu hoặc membership mới. Link hết hạn/thu hồi vẫn bị chặn; người dùng có thể đóng liên kết để quay về ứng dụng bằng quyền hiện có. API trả thông báo rõ và UI giữ nguyên thông báo đó thay vì thay bằng lỗi chung.

Kiểm chứng đợt sửa này: 29 test nghiệp vụ backend đạt, 40 test Flutter đạt, analyze không có lỗi. Có regression test thành viên qua email mở lại link, không tạo yêu cầu/membership trùng; preview và gửi yêu cầu bằng link thu hồi đều trả 410.

Kiểm chứng: test backend `test_pre_registered_relative_accepts_invitation_without_registering_again_or_creating_child` kiểm tra cùng ID tài khoản sau đăng nhập, chấp nhận bằng tài khoản đã đăng ký, không thêm user/Space/bé ngoài gia đình của cha mẹ. Widget test `account_waiting_test.dart` kiểm tra người thân có màn chờ/nhận lời mời và không bị mở form tạo bé ngay.

## Sửa lỗi token sau xác thực — 26/10/2026

Ô nhập “Nhận lời mời gia đình” phân tích liên kết trước khi gọi API. Link có `familyJoin` mở màn xin tham gia; link có `familyInvite` mở màn chấp nhận lời mời email. Chỉ mã 64 ký tự được gửi trong trường `token`, không gửi cả URL. Link sai định dạng hoặc chứa cả hai loại được báo ngay trong ứng dụng.

Khi gửi thư xác thực từ màn xin tham gia, ứng dụng đính kèm ngữ cảnh `family_join_token`. Thư xác thực giữ link gia đình ở ngoài URL API có chữ ký. Sau khi xác thực và bấm Tiếp tục, ứng dụng bỏ tham số `emailVerify`, giữ `familyJoin` và mở lại màn xin tham gia. Xác thực email không tự tạo membership hay gửi yêu cầu: người dùng chủ động gửi yêu cầu và Owner xét duyệt.

Thư cũ có thể chưa giữ ngữ cảnh gia đình. Khi đó mở lại link Family Space ban đầu bằng tài khoản vừa xác thực; không cần đăng ký lại.

Kiểm chứng ngày 26/10: 52 test Flutter đạt, phân tích tĩnh không có lỗi; 48 test backend với 425 assertions đạt. Có kiểm thử dán nguyên URL chỉ gửi token, mở đúng màn join; thư xác thực giữ ngữ cảnh và không tự cấp quyền; tài khoản đăng nhập sai không xác thực được email người khác.
