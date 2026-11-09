# Babi

**Đồ án ngành Hệ thống thông tin quản lý** — ứng dụng hỗ trợ gia đình lưu giữ nhật ký phát triển và theo dõi sức khỏe cơ bản của trẻ.

Babi được xây dựng theo mô hình ứng dụng di động kết nối API. Phụ huynh có thể quản lý hồ sơ bé, ghi lại kỷ niệm và số đo theo ngày, theo dõi lịch tiêm tham khảo và chia sẻ thông tin với người thân theo quyền của Family Space.

## Chức năng chính

- **Tài khoản và hồ sơ bé:** đăng ký, đăng nhập, xác thực email và quản lý hồ sơ.
- **Timeline gia đình:** lưu ảnh/video kỷ niệm trong không gian riêng tư; media được xử lý qua hàng đợi.
- **Theo dõi tăng trưởng:** ghi nhận chiều cao và cân nặng theo từng lần đo, xem biểu đồ và lịch sử.
- **Lịch tiêm:** xem lịch tham khảo, cập nhật ngày hẹn và ghi nhận lịch sử tiêm.
- **Family Space:** quản lý thành viên, lời mời và yêu cầu tham gia theo quyền Owner/Viewer.
- **Nhắc lịch:** cấu hình nhắc tiêm qua email; thông báo đẩy cần cấu hình Firebase riêng.

## Công nghệ

| Thành phần | Công nghệ |
| --- | --- |
| Ứng dụng | Flutter, Dart |
| API | Laravel 12, PHP 8.2+, Laravel Sanctum |
| Cơ sở dữ liệu đích | MySQL 8.0.16+ |
| Lưu trữ media | Laravel Storage, thư mục riêng tư |

Sơ đồ kiến trúc, quy trình nghiệp vụ và quyết định thiết kế được mô tả trong [ngữ cảnh hệ thống](docs/SYSTEM_CONTEXT.md). Hướng dẫn backend và hợp đồng API nằm ở [backend/BACKEND_GUIDE.md](backend/BACKEND_GUIDE.md); cách chạy frontend được ghi trong [frontend/README.md](frontend/README.md).

## Chạy bản demo trên Windows

### 1. Cài backend

Cần PHP 8.2+, Composer và MySQL 8.0.16+ theo cấu hình đích. Nếu dùng MariaDB/XAMPP để chạy local, lưu ý báo cáo kiểm thử tương thích với MySQL đích chưa hoàn tất. Tạo database trước, sau đó:

```powershell
cd backend
composer install
Copy-Item .env.example .env
```

Điền kết nối database trong `backend/.env`, rồi chạy:

```powershell
php artisan key:generate
php artisan migrate --seed
```

Không có tài khoản mẫu mặc định. Tạo tài khoản bằng chức năng đăng ký trong ứng dụng. Danh mục tiêm tham khảo cần được quản trị viên nạp và kiểm tra riêng; hệ thống không tự tạo lịch y tế khi chưa có danh mục phù hợp.

### 2. Cài frontend

```powershell
cd frontend
.\scripts\flutter.ps1 pub get
```

### 3. Chạy các dịch vụ

Mở ba cửa sổ PowerShell tại thư mục gốc dự án và chạy mỗi lệnh trong một cửa sổ:

```powershell
.\scripts\dev.ps1 -Service api
```

```powershell
.\scripts\dev.ps1 -Service queue
```

```powershell
.\scripts\dev.ps1 -Service web
```

Mở `http://127.0.0.1:5173`. API mặc định chạy tại `http://127.0.0.1:8000`. Giữ các cửa sổ đang chạy trong lúc dùng ứng dụng. Queue cần hoạt động để xử lý media tải lên. Cấu hình SMTP và các bước tích hợp đầy đủ được ghi trong [docs/INTEGRATION.md](docs/INTEGRATION.md).

## Phạm vi và giới hạn

- Đây là bản demo đồ án chạy cục bộ, chưa phải dịch vụ production.
- Số đo và biểu đồ phản ánh dữ liệu phụ huynh ghi nhận. Đối chiếu WHO chưa có trong giao diện hiện tại; ứng dụng không chẩn đoán hoặc thay thế tư vấn y tế.
- Danh mục lịch tiêm phải được quản trị viên kiểm tra trước khi kích hoạt. Ngày tham khảo không phải chỉ định tiêm cá nhân.
- Push notification cần Firebase credentials; chưa nghiệm thu gửi tới Firebase thật. Thiết bị iOS, tải lớn và môi trường triển khai production cũng chưa được nghiệm thu.

## Kiểm tra mã nguồn

Các lệnh kiểm tra Flutter và Laravel được gom trong script:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\check.ps1
```

Script có các tùy chọn bỏ qua từng nhóm kiểm tra; xem tham số trong [`scripts/check.ps1`](scripts/check.ps1). Kết quả kiểm thử tự động không thay thế nghiệm thu camera/micro trên thiết bị thật hoặc kiểm thử production.

## Cấu trúc repository

```text
backend/    Laravel API, migrations, services và kiểm thử
frontend/   Flutter app, assets và kiểm thử giao diện
docs/       Tài liệu nghiệp vụ, thiết kế và tích hợp
scripts/    Script chạy demo và kiểm tra tự động
```
