# Babi

Ứng dụng quản lý nhật ký phát triển và sức khỏe trẻ em.

- [Ngữ cảnh từ báo cáo và quyết định nghiệp vụ](docs/SYSTEM_CONTEXT.md)
- [Frontend Flutter: chạy giao diện Babi từ Figma](frontend/README.md)
- [Backend: cài đặt, chạy, danh mục và hợp đồng API](backend/BACKEND_GUIDE.md)
- [API routes](backend/routes/api.php)
- [Kiểm thử nghiệp vụ](backend/tests/Feature/BabiCoreTest.php)

Frontend Flutter đã nối API Laravel cho tài khoản, hồ sơ, gia đình, tăng trưởng, lịch tiêm và Timeline/media riêng tư. Chế độ xem thiết kế vẫn có thể bật riêng. Chưa hoàn tất toàn bộ phạm vi báo cáo; xem [tình trạng tích hợp và cách chạy](docs/INTEGRATION.md).

Chạy bộ kiểm tra tự động Flutter + Laravel bằng `powershell -ExecutionPolicy Bypass -File .\scripts\check.ps1`: analyze, Flutter tests, web release build, Laravel PHPUnit, HTTP/queue smoke và Chrome E2E với camera/micro giả lập. Dùng `-SkipFlutter`, `-SkipWebBuild`, `-SkipHttpSmoke` hoặc `-SkipBrowserSmoke` để bỏ nhóm tương ứng; `-SkipWebBuild` bỏ bản release riêng, còn browser smoke vẫn tự build nếu chưa có artifact hợp lệ. Browser smoke cần Python, Node, Playwright và Chrome; có thể cấu hình bằng các biến `BABI_PYTHON`, `BABI_NODE`, `BABI_NODE_MODULES`, `BABI_CHROME`. Camera vật lý và quyền trên thiết bị thật vẫn cần nghiệm thu thủ công.
