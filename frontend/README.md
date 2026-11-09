# Babi — Flutter frontend

Giao diện Flutter Material 3 theo phong cách Liquid Glass từ [Figma Babi](https://www.figma.com/design/Bdye6gLrK8UTWGNjhzTNez?node-id=53-867). Nền pastel khuếch tán, thẻ kính có viền bắt sáng, thanh điều hướng mờ và các nút nổi dùng chung trên Timeline, Chụp, Xem trước & Lưu, Tăng trưởng, Lịch tiêm, Gia đình và màn đăng nhập.

## Chạy

```powershell
cd E:\DA\DA_HTTTQL_UngDung\frontend
.\scripts\flutter.ps1 pub get
.\scripts\flutter.ps1 run -d chrome --web-hostname=127.0.0.1 --web-port=5180 --dart-define=BABI_DESIGN_PREVIEW=true
```

Lệnh trên mở giao diện Liquid Glass mẫu trên Chrome, không cần PHP hoặc Laravel. Dùng cổng `5180` riêng để tránh cổng `5173` mà PHP demo cũ có thể đang chiếm. Giữ cửa sổ PowerShell mở trong lúc xem; nhấn `Ctrl+C` để dừng. Nếu PowerShell chặn file `.ps1`, chạy Flutter trực tiếp bằng `C:\src\flutter\flutter\bin\flutter.bat run -d chrome --web-hostname=127.0.0.1 --web-port=5180 --dart-define=BABI_DESIGN_PREVIEW=true`.

Script chọn SDK Flutter đầy đủ từ FLUTTER_ROOT hoặc PATH. Trên máy hiện tại, SDK đầy đủ nằm ở `C:\src\flutter\flutter`; lệnh Flutter ngoài PATH tại `C:\src\flutter` bị thiếu thư mục packages. Script không đổi PATH hay sửa SDK toàn máy.

Để duyệt đúng hình mẫu Figma mà không cần webcam:

```powershell
.\scripts\flutter.ps1 run -d chrome --dart-define=BABI_DESIGN_PREVIEW=true
```

Chế độ này dùng ảnh mẫu trong khung ngắm và nút chụp tạo bản nháp ảnh mẫu. Bỏ cờ để dùng camera thật. Web cần localhost/HTTPS và quyền camera/micro; có thể chọn tệp từ thư viện nếu không có camera. Android/iOS dùng safe area hệ thống; bản web thêm khung xem trước 390 × 844 (iPhone 14/15) và thanh trạng thái minh họa. Build iOS cần macOS/Xcode.

Để thử camera/quay video thật trên web mà không cần tạo hồ sơ bé hay ghi dữ liệu lên backend, build bản demo riêng:

```powershell
cd E:\DA\DA_HTTTQL_UngDung\frontend
C:\src\flutter\flutter\bin\flutter.bat build web --release --no-pub --output=build/camera-test --dart-define=BABI_DESIGN_PREVIEW=true --dart-define=BABI_CAMERA_TEST=true
php -S 127.0.0.1:5174 -t build\camera-test
```

Chạy lệnh build trong một cửa sổ PowerShell; chạy lệnh PHP trong cửa sổ khác sau khi build xong. Mở `http://127.0.0.1:5174`, cho phép Camera và Microphone, vào tab Chụp, chạm để chụp hoặc giữ nút rồi thả để dừng video. Ảnh/video được lưu trong dữ liệu demo cục bộ của phiên và không gửi đến Laravel.

## Phạm vi hiện tại

- Giao diện dùng Be Vietnam Pro, Material Symbols Rounded và SVG đã xuất từ Figma, lưu trong assets. Không có URL tài nguyên Figma tạm thời khi chạy.
- Thanh cung 5 mục với nút Chụp 56; Viewer có 4 mục. Nhấn biểu tượng hồ sơ để xem thử Owner/Viewer hoặc đổi bé. Đây là mô phỏng quyền ở UI, không phải xác thực hay phân quyền phía máy chủ.
- Chạm chụp ảnh; giữ nút chụp để quay video, thả tay để dừng; đổi camera và flash tùy phần cứng. Web camera thường tạo WebM; app cũng nhận MP4 từ camera/thư viện. JPEG/PNG tối đa 10 MB, MP4/WebM tối đa 50 MB mỗi tệp; kiểm tra chữ ký tệp trước upload. Preview vuốt nhiều tệp, sửa caption bắt buộc, bỏ tệp, chọn cột mốc/bé và Lưu vào Timeline.
- Số tệp và thời lượng video chưa được nhóm chốt. Giá trị demo mặc định 10 tệp/30 giây; đổi bằng `--dart-define=BABI_MAX_FILES=...` và `--dart-define=BABI_MAX_VIDEO_SECONDS=...` với số nguyên dương.
- Form số đo kiểm tra ngày, số dương, cảnh báo ngoài ngưỡng nhập liệu, không ghi đè số đo cùng ngày. Tab Sức khỏe hiển thị biểu đồ theo ngày đo thật, lọc 3/6/12 tháng hoặc toàn bộ lịch sử, hiển thị từng lần đo thật và khoảng thay đổi giữa các số đo. Đối chiếu WHO để dành cho đợt enhance sau. Lịch tiêm chỉ hiển thị kế hoạch và ngày hẹn của hồ sơ.

**Đã nối API Laravel ở chế độ mặc định.** Có đăng nhập/đăng ký, lưu token, tải hồ sơ/quyền/gia đình/số đo/tiêm chủng thật và upload Timeline/media riêng tư. Owner có thể quản lý lời mời và thành viên trong màn Gia đình. Xem [hướng dẫn chạy API + Queue + Flutter và giới hạn hiện tại](../docs/INTEGRATION.md). Các mô tả dữ liệu mẫu và thanh tiến trình mô phỏng ở trên chỉ áp dụng khi bật BABI_DESIGN_PREVIEW=true. Chưa có bình luận/FCM và chưa nạp danh mục vắc xin cá nhân hóa.

`lib/src/data/api_client.dart` gọi API và quản lý token; controller tách dữ liệu mẫu khỏi phiên thật. Owner/Viewer lấy từ server, UI thật không cho đổi quyền. Backend kiểm tra quyền/consent cho dữ liệu và media. 401 xóa phiên; lỗi upload giữ UUID/bản nháp; xung đột revision không tự ghi đè.

## Cấu trúc

- `lib/src/app.dart`: khung ứng dụng, điều hướng, hồ sơ và vai trò xem thử.
- `lib/src/theme.dart`, `widgets.dart`: token, chữ, icon, thanh cung, thẻ và media.
- `lib/src/screens/`: các màn được chuyển từ Figma và tương tác.
- `lib/src/controller.dart`, `models.dart`: trạng thái phiên và kiểm tra dữ liệu.
- `assets/figma/`: tài nguyên SVG gốc; `design/asset_manifest.json`: nguồn màn tương ứng.
- `assets/fonts/`: font cục bộ, kèm giấy phép OFL/Apache.

## Kiểm tra

```powershell
.\scripts\flutter.ps1 analyze --no-pub
.\scripts\flutter.ps1 test --no-pub --dart-define=BABI_DESIGN_PREVIEW=true --dart-define=BABI_EXPORT_RENDERS=true
.\scripts\flutter.ps1 build web --release --no-pub
```

Kiểm thử bao phủ caption trống, vai trò Viewer, gửi hai lần, giới hạn/loại tệp, nhận video WebM không có đuôi tệp từ camera web, số đo trùng ngày, luồng nhập caption → Lưu, điều hướng và màn 320 × 640 với chữ 130%. Ảnh đối chiếu được xuất vào `artifacts/`. Camera thật, micro, flash và quyền thiết bị cần nghiệm thu trên Chrome/điện thoại có camera.
