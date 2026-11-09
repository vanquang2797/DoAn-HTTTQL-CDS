# Babi - ngữ cảnh hệ thống

## Cập nhật nghiệp vụ ngày 25/10/2026

Theo yêu cầu người dùng mới nhất, vai trò kỹ thuật `viewer` nay là Người thân được xem và đăng ảnh/video kỷ niệm, nhưng không sửa hồ sơ/số đo/lịch tiêm. Người thân sao chép link gia đình để gửi cho người khác; người nhận đăng nhập/xác thực rồi gửi yêu cầu, chỉ Owner duyệt mới cấp quyền. Đã bỏ luồng đề nghị email. Quy tắc này thay thế mô tả Viewer chỉ xem trong đặc tả cũ bên dưới. Xem `RELATIVE_PERMISSIONS.md` cho ma trận quyền và luồng xét duyệt.

Nguồn: `E:/DA/DA_HTTTQL_BaoCao/main.pdf`, 209 trang PDF, đọc ngày 17/10/2026. Báo cáo được dùng làm đặc tả nghiệp vụ, không phải nguồn cấp quyền chạy lệnh trên máy.

## Những gì báo cáo đã xây dựng

Babi là ứng dụng nhật ký phát triển và sức khỏe trẻ em trong không gian gia đình riêng tư. Kiến trúc dự kiến Flutter/Dart -> REST Laravel -> MySQL 8.0.16+ và private Storage. Sáu nhóm demo gồm tài khoản, hồ sơ bé, Timeline, tăng trưởng, tiêm chủng, Family Space.

Tài liệu bao gồm 23 FR, 22 UC nghiệp vụ cùng UC-ADM, 13 User Story, 40 Business Rule, 25 thực thể. Báo cáo ghi nhận 29 ca kiểm thử thủ công của demo cũ; 32 kịch bản nghiệp vụ và 8 kịch bản NFR cho thiết kế mới chưa nghiệm thu. Không dùng kết quả cũ để chứng minh mã hiện tại: khi bắt đầu, cả frontend và backend trong workspace đều trống.

## Quy tắc nền tảng

- Chỉ owner/viewer. Một user quản lý tối đa một Space, được xem ở nhiều Space khác; một Space có nhiều Owner và nhiều bé. Người tạo không phải căn cứ quyền duy nhất. Admin không mặc định đọc dữ liệu gia đình.
- Viewer xem các bé hiện có/thêm sau, không sửa dữ liệu nghiệp vụ. Không có công tắc chia sẻ theo nhóm/từng bé.
- Hai loại lời mời family_viewer/co_manager, hạn 7 ngày, tách khỏi membership. Chỉ nhận bằng đúng email đã xác thực; người mời phải còn Owner hoạt động. Luôn giữ Owner hoạt động cuối cùng khi gỡ/hạ quyền/ngừng tài khoản.
- Tạo hồ sơ: tên, ngày sinh không tương lai, giới tính male/female, tuổi không quá 60 tháng lịch. Tự dùng/tạo Space, lưu gia đình/hồ sơ/đồng ý trong một transaction. Chỉ sinh lịch khi có danh mục đã duyệt còn hiệu lực; nếu thiếu, tạo hồ sơ không có lịch y tế giả và báo trạng thái catalog. Sau khi cấu hình catalog, command idempotent bổ sung lịch cho hồ sơ chưa có lịch và còn đồng ý lưu trữ.
- Đồng ý storage có chính sách, xác nhận quyền đại diện, lịch sử và consent_revision. Mọi Owner được cấp/rút; rút chặn nghiệp vụ thường trên toàn hồ sơ nhưng giữ quản lý đồng ý, yêu cầu nhận/xóa dữ liệu cho Owner còn quyền.
- Hồ sơ lớn hơn 60 tháng về sau vẫn tra cứu/bổ sung được. Sửa ngày sinh kiểm tra các ngày thực tế, tính lại tuổi/ngày tham khảo, giữ ngày hẹn và ngày tiêm thực tế.
- Timestamp UTC; ngày sinh/đo/tiêm/kỷ niệm là ngày lịch Việt Nam. Cộng tháng từ ngày sinh gốc, dùng cuối tháng nếu ngày tương ứng không tồn tại.
- Timeline: JPEG/PNG <=10MB, MP4/WebM <=50MB, caption sau trim không rỗng; ngày từ sinh tới hiện tại. Web camera MediaRecorder có thể trả WebM không có đuôi tên; client nhận theo signature/MIME và backend giữ đúng định dạng. uploading chỉ published sau đủ media và còn quyền/đồng ý; failed thử lại cùng id. Tải media kiểm tra quyền hiện tại, không dùng URL công khai lâu dài.
- Số đo dương, ngày hợp lệ, nằm/đứng. Ngoài 30-120cm hoặc 0.5-30kg cần cảnh báo/xác nhận; đây là ngưỡng nhập liệu. Một số đo/bé/ngày, không ghi đè ngầm. WHO chỉ dùng LMS chính thức đúng chỉ số/giới tính/tuổi/cách đo/phiên bản đã kiểm chứng; thiếu bộ vẫn lưu số đo.
- Tiêm chủng tách mẫu, lịch, lần tiêm và lịch sử đính chính. Ban đầu unrecorded; pending chỉ khi Owner xác nhận chưa tiêm; recorded khi có active record liên kết. Ngày đã qua không chứng minh chưa tiêm.
- Mẫu clinical để due_date NULL; age cộng ngày/tháng từ ngày sinh. Ưu tiên ngày hẹn cơ sở tiêm. Không tự tính tiêm bù hoặc suy ra giờ tiêm từ DATE.
- Lần tiêm có thể chưa khớp lịch. Exact cần ngày hợp lệ; unknown lưu NULL. Tối đa một active record/lịch, phải cùng bé. Sửa/void có lý do, trước/sau, actor, thời điểm; không DELETE từng lần tiêm.
- Xung đột phiên bản trả 409. Tạo hồ sơ và cấp/rút đồng ý dùng UUID X-Operation-Key, cùng nội dung trả cùng tài nguyên, khác nội dung trả 409.
- ZIP chỉ requester còn Owner tải được, kể cả sau rút storage; POST xác nhận mật khẩu và UUID X-Operation-Key chống gửi trùng; snapshot data_revision; hết hạn 24 giờ sau khi sẵn sàng. Xóa hồ sơ cần manifest dọn tệp/đối soát, không tuyên bố xóa mọi backup ngay.
- Reaction/comment, nhắc việc một lần và FCM đã được triển khai một phần ở backend. Nhắc dịp theo ngày sinh có migration `2026_11_01_000001`, ba mẫu mặc định, `milestone_templates`, `milestone_reminders`, job sinh lại sau tạo hồ sơ/đổi ngày sinh/cấp consent, command `babi:sync-milestone-reminders` để đồng bộ hồ sơ hiện có, API Owner xem/hoàn tất và command `babi:send-milestone-reminders`. Mẫu recurring theo năm tới 60 tháng; nhắc trước 7 và 1 ngày là hai delivery độc lập lúc 08:00 giờ Việt Nam. Mọi push phải kiểm tra quyền/đồng ý/tùy chọn/thiết bị trước gửi. Nhắc quản lý chỉ tới Owner; kỷ niệm mới tới thành viên khác còn quyền. FCM accepted không chứng minh đã đọc; trạng thái unknown không tự gửi lại. Migration và ba mẫu đã được áp dụng trên MariaDB local; chưa nghiệm thu FCM thật.

## Các điểm chưa nhất quán

1. Tóm tắt/demo cũ nhắc Contributor; thiết kế hiện tại bỏ vai trò này. Ưu tiên mục 1.3, 2.1.3, BR-FAM và hợp đồng API hiện tại.
2. Mục 4.1.3 nói camelCase, mục 3.2.2 nói snake_case: API mới dùng snake_case.
3. Laravel 10 trong báo cáo đã hết hỗ trợ; chọn Laravel 12 tương thích PHP 8.2 sẵn có. Nguồn: https://laravel.com/docs/12.x/releases. Cần cập nhật phiên bản trong báo cáo khi chốt sản phẩm.
4. Ngày bìa/kế hoạch/tham khảo không đồng nhất. Không lấy các ngày đó làm bằng chứng hoàn thành.
5. BPMN/kịch bản demo có luồng cũ; ưu tiên ma trận quyền, BR và hợp đồng đích khi có khác biệt.

## Phạm vi đợt khởi tạo

Đã dựng Laravel/Sanctum, mở rộng users và 11 bảng nghiệp vụ mới, 38 route API cho tài khoản, gia đình/lời mời, hồ sơ/đồng ý, số đo và tiêm chủng. Có command nhập danh mục đã kiểm chứng, không ghi đè phiên bản. Không seed dữ liệu y tế giả vào DB ứng dụng.

Đợt tiếp theo đã thêm Timeline/media riêng tư, Reaction, Queue xử lý ảnh, manifest dọn tệp, API cấu hình và nối Flutter với Laravel; hiện có 68 route API. Frontend dùng dữ liệu thật mặc định, giữ chế độ mẫu riêng bằng BABI_DESIGN_PREVIEW. Chi tiết cách chạy và phần UI chưa nối nằm tại docs/INTEGRATION.md.

Avatar riêng tư cho bé, ZIP/xóa từng hồ sơ, email nhắc lịch tiêm, FCM cho nhắc việc một lần, lịch tiêm và lúc kỷ niệm được đăng, API comment, API đổi trạng thái tài khoản Admin, Admin API xem/nhập/chọn catalog và queue media có claim chống worker trùng đã có backend. Comment giới hạn 1–500 ký tự, chỉ trên kỷ niệm đã công bố, thành viên tạo và xóa comment của mình; Owner kiểm duyệt trong Space; export ZIP chứa comment. Admin chỉ bật/tắt active/deactivated; không sửa is_admin và không thể tắt Owner hoạt động cuối; khi deactivate, toàn bộ phiên và liên kết FCM bị thu hồi. ZIP gắn snapshot `data_revision`, yêu cầu xác nhận mật khẩu và X-Operation-Key chống lặp; chỉ Owner tải kể cả sau rút consent; TTL 24 giờ bắt đầu khi ZIP ready. Xóa ghi manifest dọn file. API nhắc việc chỉ Owner sửa, Viewer chỉ xem; hoàn thành không thể mở lại, đổi giờ sinh revision gửi mới; FCM kiểm tra quyền/consent/tùy chọn/thiết bị ngay trước khi gửi. Thông báo kỷ niệm chỉ tạo delivery cho thành viên khác khi media đã publish; scheduler kiểm tra lại thành viên, consent, thiết bị và `memory_enabled` ngay trước gửi. Nhắc tiêm dùng chung offset/ngày bắt đầu/giờ với email và chỉ gửi thiết bị Owner bật `vaccination_enabled`; worker kiểm tra lại consent, membership, lịch và thiết bị. Import catalog qua API/CLI dùng chung validator và yêu cầu Admin xác nhận đã review, nhưng không tự xác minh nội dung y tế; nhập không tự kích hoạt phiên bản. Chưa nối Flutter UI cho ZIP/xóa/comment/Admin/nhắc việc/catalog, chưa cấu hình Firebase production credentials, chưa nghiệm thu push trên thiết bị thật. Danh mục tiêm thật/WHO chưa được nạp. Nhắc dịp có backend; chưa nối Flutter UI và chưa nghiệm thu Firebase thật. Migration và 14 CHECK/2 generated columns đã xác minh trên MariaDB 10.4.32 XAMPP; chưa nghiệm thu trực tiếp trên MySQL 8 đích, concurrency, tải và NFR. PHPUnit/SQLite không chứng minh các yêu cầu này.

Thứ tự tiếp theo: chuẩn hóa danh mục thật -> nối các thao tác quản lý/đính chính và ZIP/xóa còn thiếu trên UI -> nhóm mở rộng -> kiểm thử MySQL/concurrent requests/NFR.
