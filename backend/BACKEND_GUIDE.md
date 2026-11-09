# Chạy và tích hợp Babi Backend

Laravel 12 / PHP 8.2+, Sanctum; đích MySQL 8.0.16+. Đây là đợt hiện thực nền nghiệp vụ, xem [ngữ cảnh hệ thống](../docs/SYSTEM_CONTEXT.md).

## Khởi động

```powershell
cd E:\DA\DA_HTTTQL_UngDung\backend
composer install
# Chỉ copy khi chưa có .env, không ghi đè cấu hình hiện tại.
Copy-Item .env.example .env
php artisan key:generate
```

Tạo database babi trên MySQL 8.0.16+ với utf8mb4; chỉnh DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD trong .env:

```powershell
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Khi dùng upload từ Flutter, ưu tiên các lệnh scripts/dev.ps1 trong [hướng dẫn tích hợp](../docs/INTEGRATION.md) để áp dụng giới hạn upload cho PHP server và chạy Queue/GD riêng.

.env.example cấu hình MySQL đích. DB local XAMPP thực tế là MariaDB 10.4.32; migrations đã chạy hết, metadata xác nhận 14 CHECK constraint và 2 generated column. PHPUnit và HTTP smoke dùng SQLite cô lập. Chưa nghiệm thu concurrency/load trên MariaDB hoặc tương thích hoàn toàn với MySQL 8 đích và môi trường production. Không có tài khoản/mật khẩu mặc định. Đăng ký qua API.

MAIL_MAILER=log chỉ ghi thư tại máy phát triển, không gửi thật; log có mã xác thực/lời mời, không công khai hoặc đưa lên Git. Khi dùng SMTP cần kiểm thử giao thư. Không nạp danh mục WHO/tiêm giả.

Kiểm tra local ngày 31/10/2026: `.env` đang cấu hình SMTP và một email kiểm tra ngắn đã được máy chủ Gmail chấp nhận gửi tới địa chỉ người dùng cung cấp. Ngày 02/11/2026, SMTP handshake/auth tiếp tục thành công (`SMTP_AUTH_OK`) mà không gửi thư. Phản hồi SMTP không xác nhận thư đã vào Inbox; người nhận cần kiểm tra cả Spam.

## Danh mục tiêm

Lần cài đầu không tạo sẵn tài khoản Admin. Sau khi người dùng đăng ký và xác thực email, quản trị viên máy chủ có thể cấp quyền qua lệnh console có xác nhận: `php artisan babi:grant-admin email@example.com`. Chỉ dùng `--force` trong quy trình triển khai có kiểm soát; lệnh từ chối tài khoản chưa xác thực hoặc đã deactivated và ghi log theo user ID. Thu hồi role bằng `php artisan babi:revoke-admin email@example.com`; lệnh không cho gỡ Admin hoạt động cuối cùng. Không có API công khai để tự nâng quyền.

Thiếu danh mục đang có hiệu lực không chặn tạo hồ sơ: API tạo gia đình, hồ sơ và đồng ý nhưng không sinh lịch tiêm, đồng thời trả `meta.vaccine_catalog_ready=false` và `meta.vaccine_schedule_count=0`. Dữ liệu hồ sơ/sức khỏe/Timeline vẫn dùng được; không tạo lịch y tế thay thế. Admin đang hoạt động có thể nhập catalog qua `POST /api/admin/vaccine-catalogs` hoặc CLI; cả hai dùng chung kiểm tra dữ liệu. API yêu cầu `reviewed=true`; đây là xác nhận của Admin rằng catalog đã được review độc lập, hệ thống không tự xác minh tính đúng y tế. Nhập catalog không tự kích hoạt. Admin có thể xem phiên bản qua `GET /api/admin/vaccine-catalogs` và kích hoạt catalog mở rộng còn hiệu lực qua `PUT /api/admin/vaccine-catalogs/active` với `schedule_version`. Phiên bản chọn trong database được ưu tiên hơn `BABI_VACCINE_CATALOG_VERSION`; nếu chưa có lựa chọn trong database thì dùng biến môi trường cũ. Sau đó chạy `php artisan babi:populate-missing-vaccination-schedules`. Lệnh chỉ bổ sung lịch cho hồ sơ đang còn đồng ý lưu trữ và chưa có bất kỳ lịch nào; thao tác lặp không nhân đôi lịch, mỗi hồ sơ được cập nhật `data_revision`. Hồ sơ đã có lịch không bị trộn thêm phiên bản mới.

Nhập JSON đã kiểm chứng bằng:

```powershell
php artisan babi:import-vaccines E:\duong-dan\catalog.json --reviewed
```

JSON gốc gồm schedule_version (string), program (expanded/service), effective_until (YYYY-MM-DD hoặc null nếu nguồn chính thức không quy định ngày kết thúc), templates (array). Mỗi mẫu cần vaccine_code, name, dose_number, disease, timing_rule, source_url; có thể kèm previous_template_id, min_interval_days, age_value, age_unit và timing_note. Mẫu age cần age_value và age_unit=day/month; clinical để age_value/age_unit rỗng và có timing_note nếu cần. previous_template_id phải trỏ tới mẫu đã nhập trước đó; min_interval_days là số ngày tối thiểu. Importer từ chối catalog có ngày hết hạn trong quá khứ; API không dùng phiên bản hết hạn để sinh lịch mới. Không đặt ngày hết hạn giả để qua kiểm tra. Không dùng bảng minh họa trong báo cáo làm seed. `database/catalogs/vietnam-epi-52-2025.json` là bản nháp, chưa được duyệt để sử dụng. `effective_until` không bắt buộc: Thông tư 52/2025/TT-BYT có hiệu lực từ 15/02/2026 nhưng không quy định ngày kết thúc. Riêng JEV mũi 3, lịch tổng hợp NIHE ghi một năm sau mũi 2, còn điều khoản chi tiết Thông tư 52 ghi cách mũi 1 ít nhất một năm và cách mũi 2 ít nhất một tháng; bản nháp đã chỉnh theo điều khoản chi tiết TT52 và ghi điều kiện tiêm bù; khác biệt với lịch tổng hợp NIHE vẫn cần Admin/nhân sự chuyên môn xác nhận trước khi import hoặc kích hoạt. Flag `--reviewed` chỉ xác nhận của người nhập; hệ thống không tự xác minh tính đúng y tế. Nguồn: https://nihe.org.vn/chuyen-mon/tiem-chung-vac-xin/cac-vac-xin-trong-tiem-chung-mo-rong/ và https://emohbackup.moh.gov.vn/publish/attach/getfile/413589.

Sau import, Admin có thể chọn phiên bản bằng API như trên; triển khai cũ cũng có thể đặt `BABI_VACCINE_CATALOG_VERSION` và chạy `php artisan config:clear`. Chỉ program=expanded đúng phiên bản được tự sinh. Dữ liệu tổng hợp chỉ có trong PHPUnit, không cài vào DB ứng dụng.

## Hợp đồng chung

Base URL `http://127.0.0.1:8000/api`; Accept/Content-Type application/json; sau đăng nhập gửi Authorization: Bearer token. JSON dùng snake_case. Thành công có success=true và data; lỗi có success=false, message, errors; mã 401/403/409/410/422/429 theo trường hợp. Phân trang tại data.data/data.current_page/data.total, mặc định 20, tối đa 100.

| Nhóm | Endpoint |
| --- | --- |
| Tài khoản | POST /auth/register, /auth/login, /auth/logout, /auth/password, /auth/password/forgot, /auth/password/reset; GET/PUT /auth/me |
| Email | POST /auth/email/verification, /auth/email/verify |
| Hồ sơ | GET/POST /child-profiles; GET/PUT/DELETE /child-profiles/{id}; POST /child-profiles/{id}/exports; GET /child-profile-exports/{id} và /download |
| Đồng ý | GET/POST /child-profiles/{id}/consents; POST /child-data-consents/{id}/withdraw |
| Thao tác | GET /operations/{uuid} |
| Gia đình | GET /family-spaces; GET /family-spaces/{id}/members; join-links, join-requests và duyệt yêu cầu |
| Nhắc tiêm | GET/PUT /family-spaces/{id}/vaccination-reminder-settings; GET /family-spaces/{id}/vaccination-reminder-deliveries (Owner only) |
| Lời mời | GET/POST /family-spaces/{id}/invitations; POST /family-invitations/accept, /decline, /{id}/revoke, /{id}/resend |
| Quản trị | GET/POST /admin/vaccine-catalogs; PUT /admin/vaccine-catalogs/active; PATCH /admin/users/{id}/status |
| Nhắc dịp | GET/POST /admin/milestone-templates; PATCH /admin/milestone-templates/{id}; GET /child-profiles/{id}/milestone-reminders; POST /milestone-reminders/{id}/complete |
| Thành viên | PATCH/DELETE /family-members/{id} |
| Số đo | GET /child-profiles/{id}/health-records; POST /health-records; PATCH/DELETE /health-records/{id} |
| Lịch tiêm | GET /child-profiles/{id}/vaccinations; PUT /vaccinations/{id}/appointment; PATCH /vaccinations/{id}/mark-pending |
| Lần tiêm | GET/POST /child-profiles/{id}/vaccination-records; PATCH /vaccination-records/{id}; POST /vaccination-records/{id}/void; GET /vaccination-records/{id}/changes |

`php artisan route:list --path=api` liệt kê route thực tế. Timeline/media riêng tư, ZIP và xóa hồ sơ được mô tả trong [hướng dẫn tích hợp](../docs/INTEGRATION.md). Backend có email và FCM push nhắc lịch tiêm theo cấu hình, FCM cho nhắc việc một lần, mốc sinh nhật và thông báo khi kỷ niệm được đăng. Nhắc dịp dùng ba mẫu mặc định; hồ sơ được đồng bộ khi tạo/đổi ngày sinh/cấp lại consent và scheduler chạy `babi:sync-milestone-reminders` hằng ngày lúc 02:15 giờ Việt Nam để khôi phục nếu queue bỏ lỡ job. `babi:send-milestone-reminders` gửi hai delivery riêng trước 7 và 1 ngày lúc 08:00 giờ Việt Nam. Chưa có hộp thư thông báo chung hoặc export toàn Family Space. `php artisan schedule:work` chạy các scheduler trong môi trường phát triển; production cần cron gọi `php artisan schedule:run` mỗi phút. Push cần Firebase service-account credentials; khi chưa cấu hình, delivery giữ chờ.

Owner có thể xem delivery email nhắc tiêm qua GET `/api/family-spaces/{id}/vaccination-reminder-deliveries` (phân trang tối đa 50), gồm số lượt thử, lần thử tiếp theo/lỗi cuối, người nhận và trạng thái (`pending`, `retry_wait`, `needs_attention`, `accepted_by_mail_transport`). `accepted_by_mail_transport` nghĩa SMTP chấp nhận nội dung, không chứng minh thư đã vào Inbox. Thành viên Viewer không có quyền xem log này.

### Tài khoản và xác thực

Đăng ký: name, email, password, password_confirmation; mật khẩu 8-72 ký tự; giới hạn 5 lần/phút theo IP. Đăng nhập email/password trả token hạn cố định 7 ngày, giới hạn 10 lần/phút theo IP. Email trim/lowercase. Đổi mật khẩu gửi current_password/password/password_confirmation, thu hồi toàn bộ token; logout chỉ token hiện tại. Không đổi email/tự cấp admin qua API cá nhân.

Quên mật khẩu: POST `/auth/password/forgot` nhận email và luôn trả thông báo chung để không tiết lộ tài khoản có tồn tại hay không. Nếu tài khoản tồn tại, email chứa link Flutter `/?passwordReset=<token>&email=<email>`; token hết hạn sau 60 phút. Màn hình reset gửi token/email/password/password_confirmation lên POST `/auth/password/reset`. Token chỉ dùng một lần; khi đổi thành công, server thu hồi toàn bộ Sanctum token và liên kết thiết bị push để người dùng đăng nhập lại.

POST /auth/email/verification gửi URL có chữ ký và hạn 60 phút. Flutter Web đọc deep link `emailVerify`, yêu cầu đăng nhập đúng tài khoản rồi POST nguyên query id/hash/expires/signature lên API bằng Bearer token. Liên kết chỉ xác thực tài khoản tương ứng. Đăng ký không tự xác thực email.

### Hồ sơ và đồng ý

POST /child-profiles cần X-Operation-Key UUID do client tạo, giữ nguyên khi gửi lại cùng nội dung:

```json
{
  "name": "Bé An",
  "date_of_birth": "2026-01-31",
  "gender": "female",
  "representative_confirmed": true,
  "storage_consent": true,
  "family_sharing_acknowledged": true,
  "policy_version": "2026-10-17"
}
```

Client phải hiển thị chính sách/phạm vi chia sẻ thật trước xác nhận. Không dùng ví dụ làm chính sách. Không gửi user_id/family_space_id; server tự chọn. Avatar tùy chọn nhận qua multipart `avatar` (JPEG/PNG tối đa 10 MB), lưu riêng tư và đọc qua GET /child-profiles/{id}/avatar với quyền hiện hành.

PUT hồ sơ: name/date_of_birth/gender, blood_type tùy chọn, expected_data_revision đang xem. Sau sửa data_revision tăng; 409 yêu cầu tải lại/xác nhận, không tự đổi version rồi gửi lại.

Cấp đồng ý: UUID X-Operation-Key, expected_consent_revision, policy_version, representative_confirmed=true, family_sharing_acknowledged=true. Rút: UUID, expected_consent_revision, confirmed=true. GET consents còn dùng được cho Owner sau rút storage.

Export hồ sơ: POST `/child-profiles/{id}/exports` chỉ Owner; yêu cầu `current_password` và UUID `X-Operation-Key`. Gửi lại cùng khóa cho cùng hồ sơ trả cùng yêu cầu, khóa đã dùng cho hồ sơ khác trả 409. ZIP riêng tư gồm JSON dữ liệu, lịch sử consent/audit và media, gắn snapshot `data_revision`; hạn tải 24 giờ bắt đầu khi ZIP ở trạng thái ready. Poll GET `/child-profile-exports/{id}` rồi tải GET `/child-profile-exports/{id}/download`; quyền Owner được kiểm tra lại mỗi lần, kể cả sau khi rút consent. Yêu cầu bị từ chối nếu media vẫn đang xử lý; nếu revision đổi trong lúc tạo, export thất bại và cần yêu cầu mới. Cần bật PHP ZipArchive và Queue worker. Nếu dispatch queue lỗi sau khi lưu yêu cầu, API vẫn trả trạng thái queued; scheduler `reconcile-media` sẽ thử lại yêu cầu quá 5 phút. Xóa: DELETE `/child-profiles/{id}` cần `confirmed=true` và `expected_data_revision`; chỉ Owner, vẫn dùng được sau rút consent. Dữ liệu liên quan xóa trong transaction; avatar, nguồn/output media và ZIP được ghi vào manifest dọn bền vững, scheduler/reconcile xóa vật lý sau đó.

### Gia đình

Mời: email, invitation_type=family_viewer/co_manager, confirmed=true; Owner phải xác thực email trước khi gửi hoặc gửi lại lời mời. Thu hồi lời mời vẫn được phép với Owner chưa xác thực. Chỉ gửi token qua thư; response không lộ token/hash. Accept/decline: token, đúng tài khoản đã xác thực email. Resend đổi token sau khi mailer chấp nhận gửi và giữ hạn cũ; nếu gửi lỗi, token cũ vẫn dùng được. PATCH hạ quyền: action=demote, confirmed=true. DELETE thành viên: confirmed=true. Cấp Owner qua lời mời, không PATCH nâng quyền.

Owner chỉ được truy cập Family Space mình quản lý. Người thân (viewer) có thể tham gia nhiều Family Space, nhưng không thể tạo Family Space Owner mới khi đang là thành viên hoặc nhận lời mời co_manager cho một Space khác. Owner không thể nhận lời mời viewer hay gửi yêu cầu tham gia Space khác; server cũng lọc các Space/hồ sơ ngoài Space Owner khỏi danh sách.

### Nhắc việc một lần

GET danh sách cần quyền thành viên; POST/PATCH/DELETE chỉ Owner và cần đồng ý lưu dữ liệu còn hiệu lực. Mọi thao tác ghi gửi `expected_data_revision`; revision cũ trả 409. POST/PATCH `remind_at` dùng ISO-8601 kèm offset; PATCH thời điểm tăng `notification_revision` để worker bỏ delivery của giờ cũ. Reminder hoàn tất không thể mở lại; chỉ được sửa ghi chú hoặc xóa. Nhắc thuốc là lời nhắc do gia đình nhập, không phải chỉ định liều dùng.

### Số đo và tiêm

POST số đo: child_profile_id, measured_at, height_cm, weight_kg, measurement_method=lying/standing, expected_data_revision; notes tùy chọn. Ngoài ngưỡng nhập liệu cần outlier_confirmed=true. PATCH gửi đủ số đo cùng expected_version/expected_data_revision; DELETE cần hai version và confirmed=true. Sau ghi dữ liệu phụ thuộc, GET hồ sơ để lấy data_revision mới. WHO hiện null cùng lý do chưa có bộ kiểm chứng.

Ngày hẹn: appointment_date (ngày/null), appointment_source khi có ngày, expected_data_revision. Xác nhận chưa tiêm: confirmed=true, expected_data_revision.

POST lần tiêm: vaccine_name, date_precision=exact/unknown, administered_date nếu exact, vaccination_schedule_id (id/null), source=document/parent_report, expected_data_revision; dose_number/facility/notes tùy chọn. Unknown không gửi ngày khác null. PATCH đính chính thêm action=correct, reason, expected_version. Void dùng action=void hoặc /void, luôn lý do và cả hai version. Không DELETE lần tiêm; bản voided không sửa/khôi phục qua API hiện tại.

## Kiểm thử

```powershell
php -d extension=gd vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --testdox
php vendor/laravel/pint/builds/pint --test
```

PHPUnit ép SQLite :memory:, không chạm DB ứng dụng hoặc gửi thư thật. Kiểm tra quyền, token, rollback, chống tạo trùng, ngày cuối tháng, lời mời, đồng ý, reset mật khẩu, số đo/lần tiêm, export/xóa, media worker, email nhắc và FCM reminder bằng HTTP mock. PHP CLI của XAMPP cần bật GD cho các test tạo ảnh; nếu chưa bật trong `php.ini`, chạy trực tiếp PHPUnit bằng `php -d extension=gd vendor/phpunit/phpunit/phpunit`. HTTP smoke riêng dùng SQLite/Queue/storage tạm để kiểm tra API qua loopback: `py -3.14 tests/smoke_http.py`. Các kiểm tra này chưa thay thế kiểm thử tải, tranh chấp MySQL, giao thư SMTP thật, gửi FCM tới thiết bị thật hoặc Flutter.
