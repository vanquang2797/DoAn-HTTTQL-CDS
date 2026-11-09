# Quyền người thân - quyết định mới nhất ngày 25/10/2026

Vai trò kỹ thuật viewer được gọi là Người thân. Người thân được chụp/đăng ảnh và video, xem hồ sơ/sức khỏe/lịch tiêm; không được chỉnh dữ liệu chăm sóc, tự cấp quyền hoặc quản lý thành viên.

## Chia sẻ link, không đề nghị email

1. Người thân vào Gia đình, bấm Sao chép link gia đình và gửi link qua ứng dụng nhắn tin họ chọn.
2. Người nhận mở link, đăng nhập/đăng ký một lần bằng tài khoản của mình và xác thực email, rồi gửi yêu cầu tham gia.
3. Owner vào Gia đình → Yêu cầu tham gia, duyệt hoặc từ chối. Chỉ duyệt mới tạo membership người thân.
4. Người chia sẻ không thể tự duyệt, xem danh sách yêu cầu hoặc thu hồi link. Owner xem và thu hồi được các link của gia đình.

Link có hạn bảy ngày, chỉ lưu hash trên server; quyền người chia sẻ được kiểm tra lại khi người nhận mở/gửi yêu cầu. Bị gỡ hoặc ngừng tài khoản thì không dùng link họ tạo để gửi yêu cầu mới. Yêu cầu đã gửi vẫn do Owner quyết định theo quyền của người nhận hiện tại; thu hồi/hết hạn link không tự hủy yêu cầu đã nộp.

Luồng đề nghị mời bằng email đã bỏ khỏi UI/controller; API cũ trả 410, không tạo đề nghị hoặc gửi thư. Dữ liệu lịch sử trong bảng family_invitation_proposals được giữ nguyên, không tự duyệt hay gửi các đề nghị đang chờ. Owner vẫn có chức năng mời email trực tiếp riêng.

Quyền đăng kỷ niệm vẫn được giữ: kiểm tra membership/consent trước nhận và trước worker xuất bản. Người thân xem/thử lại bài chưa xuất bản của chính mình; Owner quản lý tất cả. API chỉnh/xóa kỷ niệm hiện vẫn dành cho Owner.

Kiểm chứng: 44 test backend / 391 assertions đạt, gồm người thân tạo link, người nhận chưa có quyền trước duyệt, chỉ Owner duyệt/thu hồi được, API đề nghị email cũ bị chặn và người chia sẻ bị gỡ thì link không dùng để xin tham gia được. Test giao diện kiểm tra nút sao chép trên màn 320px, nội dung clipboard có familyJoin và không có thao tác gửi đề nghị email.
