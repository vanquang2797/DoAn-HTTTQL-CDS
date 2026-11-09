# Gửi lời mời thật bằng Gmail

Trong `backend/.env`, cấu hình:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.gmail.com
MAIL_PORT=465
MAIL_USERNAME=your-address@gmail.com
MAIL_PASSWORD="your-google-app-password"
MAIL_FROM_ADDRESS=your-address@gmail.com
MAIL_FROM_NAME="Babi"
```

Dùng mật khẩu ứng dụng Google, không dùng mật khẩu đăng nhập Gmail. Tạo tại https://myaccount.google.com/apppasswords sau khi bật xác minh hai bước. Không đưa `.env` hoặc mật khẩu vào chat/Git/log.

Sau khi đổi cấu hình, khởi động lại môi trường bằng `scripts/prepare-family-test.ps1 -Rebuild`. Script dùng cấu hình email trong `.env`, không ép ghi thư vào log nữa. Nếu terminal gọi script có sẵn biến `MAIL_MAILER=log`, xóa biến đó trước bằng `Remove-Item Env:MAIL_MAILER -ErrorAction SilentlyContinue`.

Môi trường này vẫn dùng database SQLite demo riêng và tự bật Queue xử lý media. SMTP gửi thư lời mời ngay trong request; Queue media không quyết định việc gửi thư.

`BABI_FRONTEND_URL=http://127.0.0.1:5173` chỉ phù hợp khi người nhận mở thư trên máy đang chạy Babi. Muốn người nhận mở trên máy/điện thoại khác cần triển khai frontend và API tại địa chỉ truy cập được, build đúng `BABI_API_URL`, cấu hình `APP_URL`, `BABI_FRONTEND_URL` và `BABI_WEB_ORIGINS` tương ứng.

Kiểm chứng bằng email nhận thật:

1. Owner gửi lời mời từ màn Gia đình; kiểm tra thư đến, cả Spam.
2. Người nhận mở liên kết, đăng nhập/đăng ký đúng email. Nếu chưa xác thực, bấm Gửi email xác thực, mở thư và xác thực, rồi mở lại lời mời.
3. Chấp nhận mới tạo thành viên. Yêu cầu tham gia qua link riêng cần Owner duyệt mới tạo thành viên.
4. Với một lời mời còn chờ khác, Owner thu hồi; liên kết trong thư phải báo đã kết thúc và không thể chấp nhận. Thu hồi lời mời không phải xóa thư khỏi hộp thư và không gỡ thành viên đã chấp nhận; gỡ thành viên dùng thao tác riêng.

`submitted` chỉ chứng minh SMTP đã nhận gửi, chưa chứng minh thư đã đến Inbox. Chỉ xác nhận hoàn thành gửi thật khi kiểm tra hộp thư người nhận.
