# Tích hợp Flutter - Laravel, ngày 18/10/2026

**Cập nhật 26/10/2026:** Người thân (role kỹ thuật `viewer`) được chụp/đăng kỷ niệm, không sửa hồ sơ/số đo/lịch tiêm. Người thân chia sẻ link; Owner duyệt yêu cầu tham gia của người nhận. Owner gửi lời mời email trực tiếp để người nhận chấp nhận hoặc từ chối; quy trình “đề nghị mời email để Owner duyệt” đã bỏ. Xem `RELATIVE_PERMISSIONS.md`.

## Khởi động trên Windows

Laravel hiện cấu hình MySQL qua `backend/.env` (XAMPP). Kiểm tra `DB_CONNECTION`, `DB_DATABASE`, `DB_USERNAME` và `DB_PASSWORD` trong file này trước khi chạy; không chép `.env` lên nơi công khai. Nếu chưa có `.env`, tạo từ `.env.example`, cấu hình MySQL và chạy `php artisan key:generate`. Không chạy `migrate:fresh` trên cơ sở dữ liệu đang dùng.

```powershell
cd E:\DA\DA_HTTTQL_UngDung\backend
composer install
php artisan migrate
php artisan db:seed
php artisan babi:sync-milestone-reminders
```

Seeder thêm nhãn Timeline và các mẫu dịp sinh nhật mặc định (`Đầy tháng`, `Ăn dặm`, `Thôi nôi / sinh nhật`); không thêm tài khoản mặc định hoặc danh mục y tế giả. Các mốc theo hồ sơ chỉ được sinh khi có đồng ý lưu trữ. Laravel tạo database nếu MySQL cho phép; nếu chưa có schema, tạo schema trùng với `DB_DATABASE` trong `.env` trước khi migrate.

Mở 3 terminal tại thư mục gốc dự án để chạy API, Queue và Flutter:

```powershell
.\scripts\dev.ps1 -Service api
```

```powershell
.\scripts\dev.ps1 -Service queue
```

```powershell
.\scripts\dev.ps1 -Service web
```

Nếu PowerShell chặn file `.ps1`, chạy mỗi lệnh trong terminal riêng bằng `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev.ps1 -Service api` (đổi `api` thành `queue` hoặc `web`); tùy chọn này chỉ áp dụng cho tiến trình đó. API chạy tại http://127.0.0.1:8000/api, web tại http://127.0.0.1:5173. API áp dụng giới hạn upload ảnh/video. Queue script nạp GD có sẵn ở XAMPP để xử lý ảnh, không đổi `php.ini` toàn máy. Cần giữ cả API và Queue chạy; nếu chỉ bật API, media sẽ còn trạng thái `uploading`.

Mở terminal thứ tư và chạy `.\scripts\dev.ps1 -Service schedule` để gửi email nhắc tiêm theo cấu hình, FCM nhắc tiêm/nhắc việc một lần/nhắc dịp sinh nhật trước 7 hoặc 1 ngày, thông báo khi kỷ niệm được publish và đối soát media/ZIP hết hạn; đối soát cũng phát lại export đang queued/processing quá 5 phút. Nhắc dịp sinh nhật gửi từ 08:00 giờ Việt Nam tới thiết bị Owner đang bật `milestone_enabled`. FCM cần đặt `FIREBASE_CREDENTIALS` tới JSON service account và `FIREBASE_PROJECT_ID` trong `.env`; giữ JSON ngoài `public/`, không commit private key. Có thể chạy `php artisan babi:reconcile-media` thủ công. Chỉ chạy ứng dụng qua HTTPS khi triển khai thực tế.

## Cấu hình địa chỉ

Web mặc định gọi http://127.0.0.1:8000/api; Android emulator mặc định http://10.0.2.2:8000/api. Có thể đổi bằng `--dart-define=BABI_API_URL=https://your-api.example/api`. Điện thoại thật cần địa chỉ máy chủ truy cập được từ thiết bị, không dùng localhost của điện thoại.

Web phải có origin được liệt kê trong BABI_WEB_ORIGINS của backend; mặc định localhost:5173 và 127.0.0.1:5173. Nếu đổi cấu hình, chạy `php artisan config:clear`. Đặt `BABI_FRONTEND_URL` thành origin web mà người nhận mở được để link trong email trỏ đúng ứng dụng (ví dụ `http://127.0.0.1:5173` khi chạy local). Camera web cần mở trên `localhost`/`127.0.0.1` hoặc HTTPS. Khi trình duyệt hỏi quyền, cho phép Camera và Microphone; nếu đã từ chối, mở biểu tượng quyền cạnh thanh địa chỉ, đổi Camera/Microphone sang Allow rồi tải lại trang. Trên điện thoại thật, dùng địa chỉ IP máy chạy API có thể truy cập từ điện thoại và HTTPS; `localhost` trên điện thoại trỏ về chính điện thoại.

## Luồng sử dụng

1. Đăng ký/đăng nhập. Token lưu qua flutter_secure_storage và hết hạn theo server; deep link xác thực email phải mở bằng đúng tài khoản đăng ký rồi hoàn tất xác thực trước khi tham gia Family Space.
2. Thêm hồ sơ: đọc mục đích lưu/chia sẻ, xác nhận quyền đại diện và đồng ý. Backend tự dùng/tạo Space. Nếu catalog tiêm chưa được cấu hình, hồ sơ vẫn được tạo nhưng chưa có lịch tiêm tự sinh; response `meta` và `app-config` báo trạng thái catalog để UI không trình bày nhầm rằng đã có lịch tham khảo.
3. Nạp danh mục thật đã kiểm chứng theo backend/BACKEND_GUIDE.md (`effective_until` chỉ khai báo nếu nguồn quy định ngày hết hạn), chọn phiên bản qua Admin API hoặc `BABI_VACCINE_CATALOG_VERSION`, chạy `php artisan config:clear` nếu dùng biến môi trường, rồi `php artisan babi:populate-missing-vaccination-schedules` để bổ sung lịch cho hồ sơ chưa có lịch. Đọc DB ngày 31/10/2026: MariaDB chưa chọn phiên bản và có 0 vaccine template. File `vietnam-epi-52-2025.json` là bản nháp theo TT52/2025, chưa được duyệt/import; cần đối chiếu lịch chính thức NIHE và yêu cầu chuyên môn hiện hành trước khi dùng, đặc biệt các nội dung triển khai theo địa phương/tiêm bù không thể suy ra tự động từ bảng tuổi. Không lấy danh mục tổng hợp của test làm dữ liệu người dùng.
4. Vào tab Chụp. Chạm nút tròn để chụp ảnh; giữ nút để quay, thả tay để dừng (tối đa 30 giây mặc định). Cho phép Camera và Microphone trong trình duyệt. Có thể chọn nhiều media; ảnh tối đa 10 MB, video MP4/WebM tối đa 50 MB mỗi tệp. Nhập caption, chọn cột mốc rồi lưu. Server tiếp nhận `uploading`, Queue xử lý ảnh/đăng video riêng tư rồi đổi sang `published`. UI kiểm tra tiến trình; khi media báo lỗi, Owner có thể thử lại cùng bản ghi. Khi mất phản hồi upload, bản nháp và UUID được giữ để gửi lại không tạo bản sao.
5. Tăng trưởng đọc/lưu số đo API; đồ thị và nhật ký chỉ hiển thị từng lần đo thực tế. Đối chiếu WHO là hạng mục enhance sau.
6. Tiêm chủng hiển thị ba trạng thái: Kế hoạch, Cần rà soát và Đã ghi nhận; dùng ngày hẹn/tham khảo và trạng thái API. Owner có thể lưu ngày hẹn, ghi nhận mũi đúng lịch hoặc mũi ngoài lịch, xác nhận “chưa tiêm” sau khi kiểm tra, đính chính/hủy bản ghi với lý do; lịch sử audit được giữ. Viewer chỉ xem. URL nguồn được giữ trong catalog backend để truy xuất nguồn gốc; API lịch tiêm không trả URL đó cho giao diện thông thường. Thiết lập nhắc email/push nằm trong nút nhỏ ở màn Lịch tiêm; Owner chọn số ngày trước ngày hẹn (mặc định 7, tùy chỉnh), giờ gửi, ngày bắt đầu và email nhận. Email tài khoản Owner chỉ được gửi sau khi email đã xác thực; các địa chỉ phụ Owner chủ động nhập vẫn nhận được thư. Push chỉ tới thiết bị của Owner đang bật `vaccination_enabled`. Scheduler cũng đồng bộ lại reminder sinh nhật mỗi ngày lúc 02:15 giờ Việt Nam để phục hồi job bị lỡ; scheduler cần chạy trong môi trường backend.
7. Gia đình đọc thành viên từ API. Owner đã xác thực email có thể mời trực tiếp người thân/đồng quản lý qua email, xem lời mời, gửi lại/thu hồi, hạ đồng quản lý thành Viewer hoặc gỡ thành viên. Tài khoản Owner chưa xác thực không gửi/gửi lại được thư mời nhưng vẫn thu hồi được lời mời. Người nhận mở link email để xem người mời và vai trò, sau đó tự chấp nhận hoặc từ chối; phải đăng nhập đúng email đã xác thực. Chỉ chấp nhận mới tạo membership. Ngoài ra, Owner hoặc người thân có thể chia sẻ link Family Space; người nhận đăng nhập và gửi yêu cầu tham gia. Link chỉ tạo yêu cầu, không tiết lộ dữ liệu hay cấp quyền; Owner duyệt mới thêm người đó với quyền Viewer hoặc từ chối. Owner cũng có thể thu hồi link; yêu cầu đã gửi vẫn được xét sau khi link hết hạn/thu hồi. Quy trình đề nghị mời email để Owner duyệt đã ngừng. MAIL_MAILER=log chỉ ghi thư cục bộ, không gửi email thật. Trong menu tài khoản, chức năng xác thực nhận liên kết trong thư/log rồi gửi POST giữ chữ ký.

Đăng xuất phải nhận phản hồi thu hồi token; lỗi mạng giữ phiên để thử lại, không thông báo đã thu hồi trên server. 401 xóa phiên và yêu cầu đăng nhập; 403 dừng tải dữ liệu hiện tại; 409 yêu cầu tải lại, không tự ghi đè.

## API mới

- GET /api/app-config: phiên bản chính sách và trạng thái sẵn sàng danh mục.
- Cài đặt Admin đầu tiên không có tài khoản mặc định: sau khi người dùng xác thực email, quản trị viên máy chủ cấp quyền bằng `php artisan babi:grant-admin email@example.com`; lệnh yêu cầu xác nhận, từ chối user chưa xác thực/deactivated và ghi log user ID. Thu hồi role qua `php artisan babi:revoke-admin email@example.com`; không thể gỡ Admin active cuối cùng. Không có API tự nâng quyền.
- Khôi phục mật khẩu: POST `/api/auth/password/forgot` nhận email và luôn trả thông báo chung; email (nếu tài khoản tồn tại) dẫn về Flutter với query `passwordReset` và `email`, hạn 60 phút. Màn hình gửi token/email/mật khẩu mới lên POST `/api/auth/password/reset`; token dùng một lần và thành công sẽ thu hồi mọi Sanctum token cùng liên kết thiết bị push để yêu cầu đăng nhập lại.
- Admin catalog: GET `/api/admin/vaccine-catalogs` liệt kê phiên bản đã nhập; PUT `/api/admin/vaccine-catalogs/active` chọn `schedule_version` mở rộng còn hiệu lực. Chỉ Admin đang hoạt động được phép; URL nguồn không nằm trong response. Lựa chọn DB ưu tiên biến môi trường catalog.
- Admin nhắc dịp: GET/POST `/api/admin/milestone-templates`, PATCH `/api/admin/milestone-templates/{id}`. Sửa tuổi/chu kỳ của mẫu đã có lịch bị từ chối để không đổi ý nghĩa lịch sử.
- Nhắc dịp theo hồ sơ: GET `/api/child-profiles/{id}/milestone-reminders` cho thành viên đang có quyền; POST `/api/milestone-reminders/{id}/complete` chỉ Owner.
- GET /api/milestones: nhãn cột mốc.
- POST `/api/child-profiles/{id}/exports` yêu cầu `current_password` và UUID `X-Operation-Key`; gửi lặp cùng khóa cho cùng hồ sơ trả lại cùng yêu cầu, khóa dùng cho hồ sơ khác trả 409. GET `/api/child-profile-exports/{id}` xem tiến độ; GET `/api/child-profile-exports/{id}/download` tải bản xuất trong 24 giờ tính từ khi ZIP hoàn tất. ZIP có `data.json`, README và media riêng tư. Snapshot bị từ chối nếu `data_revision` đổi khi đang tạo; queue worker cần PHP ZipArchive. Nếu dispatch gặp lỗi sau khi yêu cầu được lưu, API vẫn trả `queued`; scheduler `reconcile-media` xếp lại yêu cầu bị treo sau 5 phút.
- Nhắc việc một lần: GET/POST `/api/child-profiles/{id}/appointment-reminders`, PATCH/DELETE `/api/appointment-reminders/{id}`. Chỉ Owner quản lý; Viewer chỉ xem. Mọi thao tác ghi cần `expected_data_revision`; revision cũ trả 409 để tránh ghi đè dữ liệu thay đổi từ thiết bị khác. `remind_at` nhận ISO-8601 có múi giờ (ví dụ `2026-11-01T09:30:00+07:00`) và trả theo giờ Việt Nam. Nhắc thuốc không lưu liều kê đơn; việc hoàn thành không thể mở lại. Sửa thời điểm tăng `notification_revision` để lượt cũ không được gửi.
- Catalog tiêm: `php artisan babi:populate-missing-vaccination-schedules` bổ sung lịch đã duyệt cho hồ sơ còn đồng ý lưu trữ và chưa có lịch; không sửa hồ sơ đã có lịch.
- Push nhắc tiêm: `php artisan babi:send-vaccination-push-reminders` chạy mỗi phút. Cùng offset/ngày bắt đầu/giờ với nhắc email; chỉ Owner có thiết bị và `vaccination_enabled` bật nhận. Delivery được hủy nếu consent, membership, lịch hoặc tùy chọn không còn hợp lệ; Firebase chưa cấu hình thì delivery giữ chờ.
- GET/PUT `/api/notification-preferences`: tùy chọn mặc định bật nếu chưa có bản ghi. PUT/DELETE `/api/device-tokens`: liên kết FCM token với tài khoản và UUID bản cài đặt; token được mã hóa khi lưu, đổi tài khoản gỡ liên kết cũ; logout nhận `installation_id` để gỡ đúng thiết bị.
- DELETE `/api/child-profiles/{id}` xóa hồ sơ sau xác nhận Owner với `confirmed` và `expected_data_revision`; tệp riêng tư được dọn qua manifest nền.
- Family email invitations: POST `family-invitations/preview`, `family-invitations/accept` hoặc `family-invitations/decline`. Email chứa deep link `?familyInvite=...`; preview chỉ trả thông tin người mời, Family Space, email được mời và vai trò đề nghị, không cấp quyền.
- Gia đình: GET/POST `family-spaces/{id}/join-links`, DELETE `family-join-links/{id}`, GET `family-spaces/{id}/join-requests`, POST `family-space-join-requests`, GET `family-space-join-requests/{id}`, POST `family-join-requests/{id}/approve|decline`. Link trả token một lần, chỉ lưu hash, hết hạn sau 7 ngày và mặc định yêu cầu cấp quyền Viewer; duyệt tạo membership trong cùng transaction.
- GET /api/child-profiles/{id}/memories: phân trang, Owner thấy cả uploading/failed, Viewer chỉ published.
- POST /api/memories: multipart files[], caption, child_profile_id, memory_date, milestone_id tùy chọn; UUID X-Operation-Key bắt buộc.
- GET/PUT/DELETE /api/memories/{id}; DELETE cần confirmed và expected_data_revision.
- POST /api/memories/{id}/retry, /api/memories/{id}/reactions.
- GET /api/media-files/{id}: Bearer token, quyền/đồng ý hiện tại, Cache-Control private/no-store; không trả Storage path hoặc public URL.

Ảnh thu nhỏ cạnh dài tối đa 1080px, không phóng lớn ảnh nhỏ; JPEG được áp dụng EXIF orientation trước khi resize. Backend giữ nguyên MP4 và WebM để hỗ trợ định dạng MediaRecorder thường trả về trên web. Video web tải bằng request có Bearer rồi phát blob cục bộ, thu hồi blob khi đóng player. Worker kiểm tra lại quyền người tạo/đồng ý trước công bố và ghi lịch dọn tệp nguồn trong cùng giao dịch xuất bản; đối soát xóa bản nguồn sau đó. Xóa kỷ niệm cũng ghi danh sách dọn tệp bền vững.

## Kiểm chứng

Kiểm tra backend trên SQLite in-memory đạt 32 test / 238 assertions, gồm upload nhiều ảnh/video cùng batch, giới hạn 10 tệp/50 MB video/10 MB ảnh, MP4/WebM, stream riêng tư, retry cùng ID và xử lý EXIF. HTTP smoke qua server PHP thật đạt: preflight CORS từ web, đăng ký/đăng nhập, hồ sơ test, số đo, multipart PNG + MP4 + WebM chống trùng, database queue, tải media riêng tư, xóa/dọn và thu hồi token. Browser smoke chạy trên Chrome với camera/micro giả: Flutter UI chụp JPEG, quay WebM và gửi chung trong một kỷ niệm; Laravel queue xuất bản; endpoint Bearer trả media riêng tư; Chrome tải, giải mã và phát WebM quay từ camera lẫn MP4 mẫu hợp lệ. Các smoke dùng SQLite/storage tạm trong `backend/tmp`, không đụng MariaDB XAMPP hoặc dữ liệu ứng dụng. Flutter đạt 29 test, `flutter analyze` không có lỗi và `flutter build web --release --no-pub` biên dịch thành công. Ba test camera mô phỏng lỗi quyền, chạm chụp, giữ/thả quay và tự dừng; test trình phát xác nhận URL riêng tư dùng Bearer và nút phát/dừng hoạt động. Camera/micro vật lý trên thiết bị người dùng vẫn cần nghiệm thu riêng.

- Backend: PHPUnit với GD, gồm quyền, token, transaction, lời mời, số đo, tiêm và Timeline.
- Flutter: test API với HTTP giả lập cho Bearer/401, quyền server, lỗi mạng, revision, UUID upload, thu hồi quyền, dữ liệu phản hồi muộn; test camera, giới hạn media và trình phát video có Bearer; cùng các test UI cũ.
- HTTP smoke thật: preflight CORS từ origin web, đăng ký, tạo hồ sơ, số đo, multipart chống trùng, database Queue thật, tải media xác thực, xóa/dọn, thu hồi token. Dùng SQLite tạm riêng và danh mục tổng hợp chỉ trong test; tự dừng server/dọn dữ liệu test, không thay danh mục ứng dụng.
- Browser smoke: Chrome + Playwright với fake camera/micro, chạy giao diện Flutter Web qua API/Queue cô lập; chụp JPEG, quay WebM, upload cùng một kỷ niệm, tải ảnh/video qua endpoint riêng tư, rồi giải mã/phát WebM và MP4 trong Chrome. MP4 playback dùng clip mẫu đi cùng package Flutter `video_player`. Cần Flutter, Node, Playwright, Chrome và `flutter pub get`; có thể đặt `BABI_FLUTTER`, `BABI_NODE`, `BABI_NODE_MODULES`, `BABI_CHROME` để chọn bản cài trên máy.

```powershell
cd backend
php -d extension=gd vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --testdox
# Python có sẵn trên máy, hoặc Python của runtime Codex:
python tests/smoke_http.py
python tests/smoke_browser.py
```

HTTP smoke đặt database và toàn bộ private media tạm trong `backend/tmp` để không chạm MariaDB XAMPP hoặc storage ứng dụng; thư mục này được dọn khi kết thúc. Có thể chọn PHP cụ thể bằng biến `BABI_PHP` (ví dụ DLL GD tương ứng cần được nạp bởi cấu hình PHP đang dùng). Root lưu media mặc định vẫn là `storage/app/private`; có thể override bằng `BABI_PRIVATE_MEDIA_ROOT` khi cần cô lập môi trường.

```powershell
cd frontend
.\scripts\flutter.ps1 analyze --no-pub
.\scripts\flutter.ps1 test --no-pub --dart-define=BABI_DESIGN_PREVIEW=true
.\scripts\flutter.ps1 build web --release --no-pub
```

Chế độ dùng thật là mặc định. `--dart-define=BABI_DESIGN_PREVIEW=true` chỉ bật dữ liệu mẫu trong phiên để đối chiếu giao diện; test API tiêm controller riêng và vẫn kiểm tra chế độ thật.

## Phần chưa hoàn tất

UI tăng trưởng hỗ trợ thêm, sửa và xóa số đo qua API; biểu đồ dùng ngày đo thực, lọc theo khoảng thời gian, hiển thị từng lần đo thực tế, và hiển thị thay đổi giữa các số đo. Đối chiếu WHO là hạng mục enhance sau. UI Gia đình hỗ trợ quản lý lời mời, yêu cầu tham gia, thành viên và quyền. Lịch tiêm có đầy đủ thao tác Owner gồm xác nhận chưa tiêm, ghi nhận, đính chính và hủy có audit. Backend có API ZIP/xóa hồ sơ; UI chưa nối hai thao tác này. Danh mục y tế cá nhân hóa chưa được nhập; mục tham khảo WHO không tự sinh lịch cá nhân. Backend đã có API comment theo BR-CMT, nhưng Flutter chưa nối giao diện comment. Admin có thể đổi trạng thái user active/deactivated và quản lý mẫu nhắc dịp qua API; UI Admin chưa có. Backend có nhắc việc một lần, tùy chọn thông báo, liên kết thiết bị, FCM HTTP v1 dispatcher cho nhắc việc, nhắc lịch tiêm, nhắc dịp sinh nhật và thông báo khi kỷ niệm publish. Timeline push chỉ gửi cho thành viên khác; nhắc tiêm và nhắc dịp chỉ gửi tới thiết bị Owner có bật tùy chọn tương ứng; các worker kiểm tra lại consent/quyền/tùy chọn trước gửi. Flutter chưa nối các màn hình/API liên quan; cần Firebase service-account credentials để gửi push thật. Nhắc dịp theo từng bé có backend; `milestones` vẫn là danh mục nhãn Timeline. Cần nghiệm thu cạnh tranh, MySQL 8 đích, SMTP thật, thiết bị Android/iOS và NFR trước sử dụng thực tế.

### Kiểm chứng trước đó — 26/10/2026

- Backend: 48 test, 425 assertion đạt trên SQLite in-memory.
- Flutter: `analyze --no-pub` sạch; 52 test đạt; `build web --release --no-pub` thành công.
- Đây là kiểm chứng local/mock và build web, chưa thay thế nghiệm thu MySQL, SMTP, push, camera vật lý hay nhiều thiết bị đồng thời.

### Kiểm chứng backend — 31/10/2026

- PHPUnit backend: 80 test / 768 assertions đạt trên SQLite in-memory. Pint các file đã sửa đạt. MariaDB 10.4.32 XAMPP đã áp dụng mọi migration qua `2026_10_31_000018_create_vaccination_push_deliveries`; metadata xác nhận 14 CHECK constraint và 2 generated column. Tương thích với MySQL 8 đích vẫn cần kiểm tra riêng. Admin có API đổi trạng thái tài khoản, xem/nhập/chọn phiên bản catalog, chặn tắt Owner hoạt động cuối cùng và thu hồi token/thiết bị. Import API và CLI dùng chung validator; nhập không tự kích hoạt phiên bản, và ngày hết hạn là tùy chọn khi nguồn chính thức không quy định ngày kết thúc. Test importer cũng kiểm tra catalog nháp trong repo có thể nhập vào SQLite mà chưa bật. Media worker claim theo token/generation, lease 2 phút, reconcile cập nhật dấu thời gian trước khi enqueue lại để tránh lặp dồn. Email nhắc tiêm chỉ lập delivery khi hồ sơ còn consent, kiểm tra lại consent, lịch, cấu hình/người nhận trước khi gửi; SMTP lỗi khiến lệnh báo thất bại và delivery chờ 15 phút rồi retry. Email và push dùng chung offset/ngày bắt đầu/giờ; push chỉ lập cho thiết bị Owner đang bật `vaccination_enabled`, và worker kiểm tra lại consent, membership, trạng thái lịch, cài đặt và thiết bị trước gửi. Scheduler gửi ở lần chạy đầu tiên tại hoặc sau giờ Owner đặt để tránh bỏ lượt khi cron khởi chạy trễ. Nhắc việc một lần và thông báo kỷ niệm dùng delivery riêng, device token mã hóa, tùy chọn per-user và claim theo thiết bị; kỷ niệm chỉ gửi sau publish và kiểm tra lại quyền/consent. FCM OAuth JWT exchange và HTTP v1 đã kiểm tra bằng HTTP mock; chưa gửi tới Firebase thật do chưa có service-account credentials. Khi thiếu cấu hình Firebase, các tác vụ push giữ delivery ở trạng thái chờ và ghi cảnh báo có giới hạn; sau khi cấu hình hợp lệ, lần chạy scheduler sau sẽ thử gửi. Cập nhật cấu hình nhắc tiêm khóa Family Space để tuần tự hóa lần khởi tạo, đồng thời giữ nguyên `created_at` khi sửa. Owner chưa xác thực email không thể gửi hoặc gửi lại lời mời gia đình; vẫn có thể thu hồi. Gửi lại lời mời chỉ thay token sau khi dịch vụ thư nhận thư; khi SMTP lỗi, link cũ vẫn hiệu lực. Hai hành vi có test hồi quy. Nhắc việc một lần cũng yêu cầu `expected_data_revision` khi tạo/sửa/xóa để trả 409 với dữ liệu cũ; test kiểm tra đủ các thao tác này. Đăng ký tài khoản giới hạn 5 request/phút theo IP; test xác nhận request thứ sáu nhận 429. Vẫn có giới hạn at-least-once với FCM/SMTP nếu tiến trình chết sau khi bên nhận chấp nhận nhưng trước khi lưu `sent_at`.

**Cập nhật 01/11/2026:** Nhắc dịp sinh nhật có migration, job đồng bộ sau tạo hồ sơ/đổi ngày sinh/cấp consent, lệnh đồng bộ hồ sơ cũ, API Owner đọc/hoàn tất, API Admin xem/tạo/sửa mẫu và scheduler FCM nhắc riêng trước 7/1 ngày. Tám test/32 assertion mới đạt; Pint đạt. Toàn bộ 88 test/800 assertion đạt trên SQLite in-memory với GD XAMPP. Migration 000001 đã Ran trên MariaDB local; ba mẫu mặc định đã seed. Firebase thật chưa nghiệm thu do chưa có service-account credentials.
- Kiểm chứng backend 02/11/2026: `composer validate --no-check-publish` hợp lệ; `composer audit --locked` không có advisory; toàn bộ PHPUnit đạt 92 test/820 assertion khi chạy với GD XAMPP. Ba test password reset riêng đạt 11 assertions. Cấu hình PHP mặc định của XAMPP chưa nạp GD nên `php artisan test` sẽ làm fail test cần tạo ảnh; bật GD trong php.ini hoặc dùng lệnh PHPUnit có `-d extension=gd`.
- Cập nhật backend 02/11/2026: CLI bootstrap/thu hồi Admin có xác nhận và log user ID; không cấp cho tài khoản chưa xác thực/deactivated, không gỡ Admin active cuối cùng. Toàn bộ suite hiện đạt 94 test/838 assertions; Pint cho app/routes/config/database/tests đạt. Không tạo Admin mặc định.
- SMTP trên máy local xác thực với Gmail thành công ngày 02/11/2026 qua handshake, không gửi email thử. Kết quả giao thật gần nhất vẫn là email ngày 31/10 được Gmail chấp nhận gửi; điều đó không chứng minh thư đã vào Inbox.
- Cập nhật retry FCM ngày 02/11/2026: timeout/mất kết nối khi gửi message được lưu `unknown` để không tự gửi trùng; lỗi tạm thời được backoff 15/30 phút, tối đa 3 lần; lỗi terminal không lặp theo scheduler mỗi phút. Hai migration bổ sung đã chạy trên MariaDB local. PHPUnit đạt 109 test/950 assertions, Pint đạt. Vẫn có giới hạn at-least-once nếu tiến trình bị dừng đột ngột sau khi FCM chấp nhận nhưng trước khi DB lưu trạng thái; đây khác với connection exception có thể bắt và lưu `unknown`.
- Cập nhật retry email nhắc lịch tiêm ngày 02/11/2026: SMTP lỗi backoff 15/30 phút và dừng sau tối đa 3 lần, lưu `failed_at` cùng `reason_code=retry_limit_reached`; claim treo hết lượt cũng được chốt terminal sau 15 phút, không gửi vượt hạn mức. Migration `2026_11_02_000004` đã chạy trên MariaDB local. Toàn suite PHPUnit đạt 112 test/987 assertions khi chạy trực tiếp với GD XAMPP; Pint đạt. Lệnh `php artisan test` tự mở tiến trình PHPUnit riêng và không kế thừa cờ bật GD; dùng PHPUnit trực tiếp với `-d extension=C:\xampp\php\ext\php_gd.dll` trên máy này.
- Cập nhật API theo dõi email nhắc tiêm ngày 02/11/2026: Owner có thể xem danh sách delivery phân trang, trạng thái đang chờ/retry/cần xử lý/SMTP accepted và lý do terminal; endpoint lọc theo Family Space và Viewer bị từ chối. “SMTP accepted” không đồng nghĩa thư đã vào Inbox. Test mới kiểm tra quyền, phạm vi dữ liệu và status mapping.
- Trạng thái cấu hình đọc trực tiếp ngày 31/10/2026: SMTP mailer chọn `smtp` và có username/password cấu hình (giá trị không được ghi nhận trong tài liệu); FCM service-account chưa sẵn sàng. Database hiện chưa chọn catalog vaccine và có 0 template đang dùng được, nên tạo hồ sơ mới sẽ trả `vaccine_catalog_ready=false` và không tự sinh lịch.
- HTTP smoke cô lập đạt: CORS, đăng ký/đăng nhập, tạo hồ sơ, số đo, upload ảnh/video, database queue, tải media riêng tư, xóa/dọn và thu hồi token.
- Browser smoke Chrome + camera/micro giả đạt: chụp JPEG, quay WebM, upload chung, xử lý Queue, tải media riêng tư và phát WebM/MP4.
- Toàn bộ migration đã chạy trên MariaDB 10.4.32 XAMPP local, gồm migration 000008 cho khóa idempotency và hạn ZIP bắt đầu sau khi ready, 000016 cho delivery FCM của kỷ niệm, 000017 cho thời điểm retry email và 000018 cho delivery FCM nhắc tiêm; migration trạng thái `Pending` không còn và cột ngày nhắc cũ đã backfill không null. HTTP smoke mới nhất đạt với `py -3.14 tests/smoke_http.py` và SQLite/Queue/storage cô lập. Một email kiểm tra đã được Gmail SMTP chấp nhận gửi; chưa xác nhận đã vào Inbox. PHPUnit/HTTP smoke dùng SQLite; FCM push chưa được gửi qua Firebase thật do thiếu credentials. Cạnh tranh request, reminder production, thiết bị camera vật lý và production chưa được nghiệm thu.

### Rà soát backend — 02/11/2026

- PHPUnit trực tiếp với GD XAMPP: 120 test / 1,047 assertions đạt trên MariaDB 10.4.32; 120 test / 1,046 assertions đạt trên SQLite (1 MySQL metadata test skipped). Nhóm regression cho delivery claim bị tiến trình bỏ dở đạt 6 test / 27 assertions; worker appointment, vaccination push, memory và milestone chốt claim đã hết 3 lượt thành `retry_limit_reached` sau lease 5 phút, không nhận thêm lần gửi thứ tư. Regression cho đăng ký → xác thực email → yêu cầu vào gia đình → Owner duyệt cũng đạt; token link bị cắt trả lỗi tiếng Việt hướng dẫn mở lại link đầy đủ ở `message` và `errors.token`. Validation API mặc định dùng tiếng Việt mà không đổi locale web; thông báo lỗi từ password broker cũng đã dịch; gọi trực tiếp API đang chạy với payload đăng ký rỗng trả HTTP 422 và thông báo tiếng Việt.
- HTTP smoke cô lập đạt: CORS preflight, đăng ký/đăng nhập, tạo hồ sơ atomic, ghi sức khỏe, upload PNG/MP4/WebM idempotent, database queue, tải ảnh/video private có xác thực, xóa/dọn file và thu hồi token. `composer validate --no-check-publish` hợp lệ; `composer audit --locked --no-interaction` không có advisory; Pint `--test` đạt; không còn TODO/FIXME/NotImplemented trong `app`, `routes`, `database`, `tests`.
- Runtime hiện tại: API `127.0.0.1:8000/up` và Flutter web `127.0.0.1:5173/` trả HTTP 200; queue worker và scheduler đang chạy; queue/failed queue rỗng; migrations MariaDB local đều `Ran` tới `2026_11_02_000005_make_event_timestamps_immutable`. SMTP được chọn trong cấu hình và Gmail SMTP handshake thành công; lần kiểm tra này không gửi thư. Không ghi hoặc in bí mật cấu hình.
- Còn phụ thuộc bên ngoài: Firebase service-account chưa cấu hình; vaccine catalog chưa được chọn và DB có 0 template nên không thể sinh lịch cá nhân thật; MySQL 8 đích chưa nghiệm thu (local là MariaDB 10.4.32); SMTP được cấu hình nhưng chưa xác minh delivery/inbox trong lần rà này. Các điểm này không thể được tuyên bố hoàn tất chỉ từ test SQLite/mock.


- Log rà lúc 06:10 UTC ngày 02/11: các lỗi `testing.ERROR` mới nhất của appointment/memory notification là fault-injection trong PHPUnit (channel `testing`, stack trace đi qua test); không thấy lỗi `production` mới. `local.ERROR` mới nhất là 05:09 UTC, gồm socket MariaDB bị sandbox chặn, truy vấn `performance_schema.session_status` không tồn tại trên MariaDB, và lệnh PsySH/Artisan chẩn đoán sai cú pháp; các lệnh kiểm tra sau đó chạy thành công. Lỗi scheduler thật gần nhất vẫn là 31/10 lúc 09:42–09:46 UTC do bảng `appointment_reminders` chưa tồn tại; migration đã `Ran`, không thấy lỗi scheduler thật mới. Giữ nguyên log để bảo toàn audit.



