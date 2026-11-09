# Kiểm tra frontend

- Flutter 3.47.5 / Dart 3.13.4, SDK đầy đủ tại `C:/src/flutter/flutter`.
- `flutter analyze --no-pub`: không có lỗi/cảnh báo.
- `flutter test --no-pub --dart-define=BABI_DESIGN_PREVIEW=true --dart-define=BABI_EXPORT_RENDERS=true`: 13 kiểm thử thành công.
- `flutter build web --release --no-pub`: thành công, kết quả tại `build/web`.
- Đã đối chiếu ảnh widget render của 5 màn với screenshot/context Figma; font Be Vietnam Pro và Material Symbols Rounded nạp từ tệp cục bộ. Ảnh kiểm tra tại `artifacts/`.
- 13 tệp SVG được tải về, không rỗng; mã chạy không tham chiếu URL asset Figma tạm thời. Thanh cung dùng lại cùng tài nguyên mặt thanh ở các màn có cùng component gốc.
- Bố cục 320 × 640 với chữ 130% không phát sinh tràn RenderFlex; nhãn nav giữ cỡ 12 để phù hợp thanh cố định, các nội dung còn lại theo cỡ chữ hệ thống.

Các kết quả trên ghi nhận giao diện ở chế độ thiết kế. Đợt tích hợp tiếp theo đã nối API ở chế độ mặc định; ảnh render mới tại artifacts/api_timeline.png và artifacts/api_health.png, xem docs/INTEGRATION.md ở thư mục gốc để phân biệt phạm vi.

Giới hạn: flutter_svg bỏ qua filter đổ bóng trong SVG xuất; đường cong/nền giữ nguyên, bóng rất nhẹ có thể khác Figma. Chỉ chế độ BABI_DESIGN_PREVIEW dùng biểu đồ mẫu/dữ liệu phiên; chế độ API vẽ số đo thực tế và chưa đánh giá WHO. Camera/micro/flash/quyền hệ điều hành/video native chưa được nghiệm thu trên thiết bị thật. Bản build web thành công không chứng minh bản Android/iOS đã build hoặc phần cứng hoạt động.
