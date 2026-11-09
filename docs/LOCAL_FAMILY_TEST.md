# Kiểm thử gia đình trên máy local

Môi trường thử SQLite riêng hiện được dựng sẵn ở `http://127.0.0.1:5173/`. Nếu cần dựng lại sau khi thay đổi mã, mở PowerShell ở thư mục dự án và chạy:

```powershell
$env:MAIL_MAILER='log'
.\scripts\prepare-family-test.ps1 -Rebuild
```

Script dùng database SQLite riêng, chạy migration, nạp lịch Tiêm chủng mở rộng theo dữ liệu Viện Vệ sinh dịch tễ Trung ương, tạo hồ sơ giả lập và build/chạy Flutter Web, Laravel API cùng queue. Cấu hình MySQL trong `backend/.env` không bị thay đổi. Khi chạy trong bộ môi trường thử này, email được ghi vào log thay vì gửi SMTP. Dữ liệu ở `backend/database/family-test.sqlite` chỉ dành cho kiểm thử local.

- Mở `http://127.0.0.1:5173/`.
- Owner: `babi.owner@example.test`.
- Người thân thử nghiệm: `babi.viewer@example.test`.
- Mật khẩu của cả hai: `BabiLocalTest2026!`.

Owner đăng nhập sẽ thấy “Bé Kiểm Thử” và vai trò Owner. Màn Gia đình có thể gửi lời mời email hoặc tạo link Family Space. Để thử duyệt quyền: Owner tạo link; mở link ở cửa sổ khác, đăng nhập bằng tài khoản Viewer, gửi yêu cầu tham gia; quay lại Owner và duyệt yêu cầu. Cả hai email được xác thực sẵn chỉ trong database giả lập này.

Owner chỉ thấy và quản lý Family Space của mình, không có mục “Nhận lời mời gia đình” và không thể tham gia Family Space khác. Tài khoản Viewer có thể tham gia thêm Family Space bằng lời mời hoặc link. Viewer đang thuộc gia đình khác không thể nhận vai trò Owner hoặc tự tạo Family Space Owner mới.

Trong lệnh chạy thử ở trên, đặt `MAIL_MAILER=log` để thư không gửi ra ngoài; lời mời email được ghi trong `backend/storage/logs/laravel.log`. Quy trình link tham gia và duyệt quyền không cần cấu hình SMTP.

Tài khoản và hồ sơ trong database này là dữ liệu tổng hợp, không đại diện cho em bé hay lịch sử tiêm thực. Lịch mẫu được trích từ lịch Tiêm chủng mở rộng do Viện Vệ sinh dịch tễ Trung ương công bố; khi dùng cho chăm sóc thực tế, luôn xác nhận với cơ sở tiêm và cán bộ y tế.
