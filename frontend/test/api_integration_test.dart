import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:babi/src/app.dart';
import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image_picker/image_picker.dart' show XFile;

import 'app_test.dart' show render, exportRender, loadFonts;

class MemoryTokens implements TokenStore {
  String? value;
  @override
  Future<String?> read() async => value;
  @override
  Future<void> write(String token) async => value = token;
  @override
  Future<void> clear() async => value = null;
}

http.Response success(dynamic data) => http.Response(
  jsonEncode({'success': true, 'data': data}),
  200,
  headers: {'content-type': 'application/json; charset=utf-8'},
);
http.Response page(List<dynamic> data) => success({
  'data': data,
  'current_page': 1,
  'last_page': 1,
  'total': data.length,
});

const child = {
  'id': 1,
  'name': 'Bé từ API',
  'date_of_birth': '2026-01-01',
  'gender': 'female',
  'family_space_id': 10,
  'data_revision': 4,
};

http.Response readFixture(http.Request request, {String role = 'viewer'}) {
  final path = request.url.path;
  if (path.endsWith('/invitation-proposals')) return success([]);
  if (path.endsWith('/auth/me')) {
    return success({'id': 2, 'name': 'Người dùng thật'});
  }
  if (path.endsWith('/child-profiles')) return success([child]);
  if (path.endsWith('/child-profiles/1')) return success(child);
  if (path.endsWith('/family-spaces')) {
    return success([
      {'id': 10, 'role': role, 'name': 'Gia đình thật'},
    ]);
  }
  if (path.endsWith('/milestones')) return success([]);
  if (path.endsWith('/app-config')) {
    return success({
      'policy_version': 'test-policy',
      'vaccine_catalog_ready': true,
    });
  }
  if (path.endsWith('/members')) {
    return success([
      {'id': 20, 'user_id': 2, 'name': 'Người dùng thật', 'role': role},
      if (role == 'owner')
        {'id': 21, 'user_id': 3, 'name': 'Người thân', 'role': 'viewer'},
    ]);
  }
  if (path.endsWith('/invitations')) {
    return success([
      {
        'id': 51,
        'email': 'nguoi-than@example.com',
        'invitation_type': 'family_viewer',
        'status': 'pending',
        'expires_at': '2026-10-25T00:00:00.000000Z',
      },
    ]);
  }
  if (path.endsWith('/join-links') || path.endsWith('/join-requests')) {
    return success([]);
  }
  if (path.endsWith('/health-records')) {
    return page([
      {
        'id': 31,
        'version': 2,
        'measured_at': '2026-06-01',
        'height_cm': '65.0',
        'weight_kg': '6.50',
        'measurement_method': 'lying',
      },
    ]);
  }
  if (path.endsWith('/memories') ||
      path.endsWith('/vaccinations') ||
      path.endsWith('/vaccination-records')) {
    return page([]);
  }
  throw StateError('Unexpected request $path');
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(loadFonts);
  test(
    'video WebM do camera web được nhận dù trình duyệt không gắn đuôi file',
    () async {
      final videoBytes = Uint8List.fromList([
        0x1a,
        0x45,
        0xdf,
        0xa3,
        0x9f,
        0x42,
        0x86,
        0x81,
        0x01,
        0x42,
        0xf7,
        0x81,
        0x01,
        0x42,
        0xf2,
        0x81,
        0x04,
        0x42,
        0xf3,
        0x81,
        0x08,
        0x42,
        0x82,
        0x84,
        0x77,
        0x65,
        0x62,
        0x6d,
      ]);
      final captured = MediaItem.fromFile(
        XFile.fromData(videoBytes, name: 'camera-blob', mimeType: 'video/webm'),
      );
      final media = await captured;
      expect(media.isVideo, isTrue);
      expect(media.mimeType, 'video/webm');

      final api = ApiClient(
        client: MockClient((request) async {
          expect(
            latin1.decode(request.bodyBytes),
            contains('filename="media-0.webm"'),
          );
          return success({});
        }),
      );
      await api.uploadMemory(
        childId: '1',
        caption: 'Video thử',
        memoryDate: '2026-06-01',
        files: [media],
        operation: '00000000-0000-4000-8000-000000000001',
      );
      api.close();
    },
  );
  test('loại video WebM từ API đi theo kỷ niệm đến trình phát', () async {
    final tokens = MemoryTokens()..value = 'test-token';
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: tokens,
        client: MockClient((request) async {
          if (request.url.path.endsWith('/memories')) {
            return page([
              {
                'id': 12,
                'child_profile_id': 1,
                'caption': 'Bước đi đầu tiên',
                'memory_date': '2026-06-01',
                'status': 'published',
                'milestone': null,
                'author': 'Người dùng thật',
                'likes': 0,
                'liked': false,
                'media': [
                  {
                    'id': 14,
                    'file_type': 'video',
                    'mime_type': 'video/webm',
                    'url': '/api/media-files/14',
                    'file_size_kb': 10,
                  },
                ],
              },
            ]);
          }
          return readFixture(request);
        }),
      ),
    );
    await app.initialize();
    expect(app.memories.single.media.single.mimeType, 'video/webm');
    app.dispose();
  });
  test('retry kỷ niệm khóa yêu cầu trùng và nạp lại trạng thái', () async {
    final tokens = MemoryTokens()..value = 'test-token';
    final retryResponse = Completer<http.Response>();
    var retryCalls = 0;
    var retried = false;
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: tokens,
        client: MockClient((request) async {
          if (request.method == 'POST' && request.url.path.endsWith('/retry')) {
            retryCalls++;
            retried = true;
            return retryResponse.future;
          }
          if (request.url.path.endsWith('/child-profiles/1/memories')) {
            return page([
              {
                'id': 88,
                'child_profile_id': 1,
                'caption': 'Kỷ niệm đang thử lại',
                'memory_date': '2026-06-01',
                'status': retried ? 'uploading' : 'failed',
                'milestone': null,
                'author': 'Người dùng thật',
                'likes': 0,
                'liked': false,
                'media': [],
              },
            ]);
          }
          return readFixture(request, role: 'owner');
        }),
      ),
    );
    await app.initialize();
    final memory = app.memories.single;
    expect(memory.status, 'failed');

    final firstRetry = app.retryMemory(memory);
    await Future<void>.delayed(Duration.zero);
    expect(app.busy, isTrue);
    await app.retryMemory(memory);
    expect(retryCalls, 1);

    retryResponse.complete(success({'id': 88, 'status': 'uploading'}));
    await firstRetry;
    expect(retryCalls, 1);
    expect(app.memories.single.status, 'uploading');
    expect(app.busy, isFalse);
    app.dispose();
  });
  test('đăng nhập lưu token; request dùng Bearer và 401 xóa phiên', () async {
    final tokens = MemoryTokens();
    var unauthorized = false;
    final api = ApiClient(
      tokenStore: tokens,
      baseUrl: 'http://localhost/api',
      client: MockClient((r) async {
        if (r.url.path.endsWith('/login')) {
          expect(jsonDecode(r.body)['email'], 'parent@example.com');
          return success({
            'user': {'id': 1, 'name': 'Parent'},
            'token': 'test-token',
          });
        }
        expect(r.headers['Authorization'], 'Bearer test-token');
        return http.Response(
          '{"success":false,"message":"Expired","errors":{}}',
          401,
        );
      }),
    );
    api.onUnauthorized = () => unauthorized = true;
    await api.authenticate('parent@example.com', 'password');
    expect(tokens.value, 'test-token');
    await expectLater(
      api.request('GET', 'auth/me'),
      throwsA(isA<ApiException>().having((e) => e.status, 'status', 401)),
    );
    expect(api.token, isNull);
    expect(tokens.value, isNull);
    expect(unauthorized, isTrue);
    api.close();
  });

  test(
    'vai trò chỉ từ server, không trộn hồ sơ mẫu và tải đúng số đo',
    () async {
      final tokens = MemoryTokens()..value = 'test-token';
      final app = BabiController(
        seedDemo: false,
        api: ApiClient(
          tokenStore: tokens,
          client: MockClient((r) async => readFixture(r)),
        ),
      );
      await app.initialize();
      expect(app.babies.single.name, 'Bé từ API');
      expect(app.records.single.height, 65);
      expect(app.role, FamilyRole.viewer);
      app.setRole(FamilyRole.owner);
      expect(app.canCreate, isFalse);
      expect(app.memories, isEmpty);
      app.dispose();
    },
  );

  test(
    'lỗi tải hồ sơ giữ trạng thái lỗi và không dùng dữ liệu mẫu thay thế',
    () async {
      final tokens = MemoryTokens()..value = 'test-token';
      final app = BabiController(
        seedDemo: false,
        api: ApiClient(
          tokenStore: tokens,
          client: MockClient((r) async {
            if (r.url.path.endsWith('/auth/me')) {
              return success({'id': 2, 'name': 'Parent'});
            }
            throw http.ClientException('offline');
          }),
        ),
      );
      await app.initialize();
      expect(app.babies, isEmpty);
      expect(app.memories, isEmpty);
      expect(app.issue, contains('Không kết nối'));
      app.dispose();
    },
  );

  test(
    'gửi số đo dùng revision từ API và chuyển xác nhận ngoài ngưỡng',
    () async {
      final tokens = MemoryTokens()..value = 'test-token';
      Map<String, dynamic>? written;
      final app = BabiController(
        seedDemo: false,
        api: ApiClient(
          tokenStore: tokens,
          client: MockClient((r) async {
            if (r.method == 'POST' && r.url.path.endsWith('/health-records')) {
              written = jsonDecode(r.body) as Map<String, dynamic>;
              return success({'id': 1});
            }
            return readFixture(r, role: 'owner');
          }),
        ),
      );
      await app.initialize();
      await app.saveMeasurement(
        Measurement(DateTime(2026, 6, 2), 130, 8, 'standing'),
        outlierConfirmed: true,
      );
      expect(written!['expected_data_revision'], 4);
      expect(written!['outlier_confirmed'], isTrue);
      expect(written!['child_profile_id'], 1);
      app.dispose();
    },
  );

  test('sửa và xóa số đo gửi version cùng revision để chống ghi đè', () async {
    final tokens = MemoryTokens()..value = 'test-token';
    final writes = <http.Request>[];
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: tokens,
        client: MockClient((r) async {
          if (r.method == 'PATCH' || r.method == 'DELETE') {
            writes.add(r);
            return success({'id': 31});
          }
          return readFixture(r, role: 'owner');
        }),
      ),
    );
    await app.initialize();
    final original = app.records.single;
    expect(original.id, '31');
    expect(original.version, 2);

    await app.updateMeasurement(
      original,
      Measurement(DateTime(2026, 6, 2), 66, 6.8, 'standing'),
    );
    expect(writes.first.url.path, '/api/health-records/31');
    expect(writes.first.method, 'PATCH');
    final update = jsonDecode(writes.first.body) as Map<String, dynamic>;
    expect(update['expected_version'], 2);
    expect(update['expected_data_revision'], 4);
    expect(update['measured_at'], '2026-06-02');

    await app.deleteMeasurement(app.records.single);
    expect(writes.last.method, 'DELETE');
    final deletion = jsonDecode(writes.last.body) as Map<String, dynamic>;
    expect(deletion['expected_version'], 2);
    expect(deletion['expected_data_revision'], 4);
    expect(deletion['confirmed'], isTrue);
    app.dispose();
  });

  testWidgets('Owner sửa số đo và cần xác nhận trước khi xóa', (tester) async {
    final tokens = MemoryTokens()..value = 'test-token';
    final mutations = <http.Request>[];
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: tokens,
        client: MockClient((r) async {
          if (r.method == 'PATCH' || r.method == 'DELETE') {
            mutations.add(r);
            return success({'id': 31});
          }
          return readFixture(r, role: 'owner');
        }),
      ),
    );
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
      app.dispose();
    });
    await app.initialize();
    app.selectTab(AppTab.health);
    expect(app.canCreate, isTrue);
    expect(app.records.single.id, '31');
    await render(
      tester,
      app,
      size: const Size(700, 1400),
      previewChrome: false,
    );

    final actions = find.byKey(const Key('measurement-actions-31'));
    expect(actions, findsOneWidget);
    await tester.tap(actions);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sửa số đo'));
    await tester.pumpAndSettle();
    expect(find.text('Sửa số đo'), findsOneWidget);
    expect(
      (tester
              .widget<TextFormField>(find.byKey(const Key('height-input')))
              .controller!)
          .text,
      '65,0',
    );
    await tester.tap(find.text('Cập nhật số đo'));
    await tester.pumpAndSettle();
    expect(mutations.single.method, 'PATCH');
    await tester.tap(actions);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Xóa số đo'));
    await tester.pumpAndSettle();
    expect(find.text('Xóa số đo này?'), findsOneWidget);
    await tester.tap(find.widgetWithText(TextButton, 'Hủy'));
    await tester.pumpAndSettle();
    expect(mutations, hasLength(1));

    await tester.tap(actions);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Xóa số đo'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Xóa'));
    await tester.pumpAndSettle();
    expect(mutations, hasLength(2));
    expect(mutations.last.method, 'DELETE');
  });

  test('mất phản hồi upload giữ bản nháp và cùng UUID khi gửi lại', () async {
    final keys = <String>[];
    final tokens = MemoryTokens()..value = 'test-token';
    var calls = 0;
    final api = ApiClient(
      tokenStore: tokens,
      client: MockClient((r) async {
        if (r.method == 'POST' && r.url.path.endsWith('/memories')) {
          keys.add(r.headers['X-Operation-Key']!);
          calls++;
          if (calls == 1) throw http.ClientException('lost response');
          return success({'id': 88, 'status': 'uploading'});
        }
        return readFixture(r, role: 'owner');
      }),
    );
    final app = BabiController(seedDemo: false, api: api);
    await app.initialize();
    app.addMedia([
      MediaItem.file(
        bytes: Uint8List.fromList([137, 80, 78, 71]),
        path: 'test.png',
        isVideo: false,
      ),
    ]);
    app.setCaption('Kỷ niệm thật');
    await expectLater(app.saveDraft(), throwsA(isA<ApiException>()));
    expect(app.draft, hasLength(1));
    expect(app.caption, 'Kỷ niệm thật');
    await app.saveDraft();
    expect(keys, hasLength(2));
    expect(keys.first, keys.last);
    expect(app.draft, isEmpty);
    app.dispose();
  });

  test('403 sau thu hồi quyền xóa dữ liệu riêng khỏi màn hiện tại', () async {
    var forbidden = false;
    final tokens = MemoryTokens()..value = 'test-token';
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: tokens,
        client: MockClient((r) async {
          if (forbidden) {
            return http.Response(
              '{"success":false,"message":"Forbidden","errors":{}}',
              403,
            );
          }
          return readFixture(r, role: 'owner');
        }),
      ),
    );
    await app.initialize();
    expect(app.records, isNotEmpty);
    forbidden = true;
    await app.refreshSelected();
    expect(app.records, isEmpty);
    expect(app.canCreate, isFalse);
    expect(app.issue, 'Forbidden');
    app.dispose();
  });

  test('phản hồi hồ sơ cũ đến muộn không ghi đè phiên đăng nhập mới', () async {
    final pending = Completer<http.Response>();
    final tokens = MemoryTokens()..value = 'old-token';
    final api = ApiClient(
      tokenStore: tokens,
      client: MockClient((r) => pending.future),
    );
    await api.restore();
    var expired = false;
    api.onUnauthorized = () => expired = true;
    final request = api.request('GET', 'auth/me');
    await Future<void>.delayed(Duration.zero);
    api.token = 'new-token';
    await tokens.write('new-token');
    pending.complete(
      http.Response('{"success":false,"message":"Expired"}', 401),
    );
    await expectLater(request, throwsA(isA<ApiException>()));
    expect(api.token, 'new-token');
    expect(tokens.value, 'new-token');
    expect(expired, isFalse);
    api.close();
  });

  testWidgets('chế độ dùng thật mở màn đăng nhập, không hiện bé và quyền mẫu', (
    tester,
  ) async {
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: MemoryTokens(),
        client: MockClient((r) async => readFixture(r)),
      ),
    );
    await tester.pumpWidget(BabiApp(controller: app, previewChrome: false));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('login-email')), findsOneWidget);
    expect(find.text('Bé Minh Khang'), findsNothing);
    expect(find.text('Xem thử vai trò'), findsNothing);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });

  testWidgets('màn API giữ bố cục nhỏ và không dùng đánh giá WHO mẫu', (
    tester,
  ) async {
    final tokens = MemoryTokens()..value = 'test-token';
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: tokens,
        client: MockClient((r) async => readFixture(r)),
      ),
    );
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await app.initialize();
    await render(tester, app);
    expect(find.text('Bé từ API'), findsOneWidget);
    expect(find.byKey(const Key('nav-capture')), findsOneWidget);
    await exportRender(tester, 'api_timeline');
    app.selectTab(AppTab.health);
    await tester.pumpAndSettle();
    expect(find.text('Đối chiếu WHO chưa khả dụng'), findsNothing);
    expect(find.textContaining('Mức đánh giá: Bình thường'), findsNothing);
    await exportRender(tester, 'api_health');
    for (final tab in [AppTab.health, AppTab.family, AppTab.vaccinations]) {
      app.selectTab(tab);
      await render(tester, app, size: const Size(320, 640), textScale: 1.3);
      if (tab == AppTab.vaccinations) {
        expect(find.text('Chưa có mũi tiêm trong kế hoạch.'), findsOneWidget);
      }
      expect(tester.takeException(), isNull, reason: 'Bố cục tab $tab');
    }
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });

  testWidgets('Owner xem lời mời và gỡ được thành viên khỏi gia đình', (
    tester,
  ) async {
    final tokens = MemoryTokens()..value = 'owner-token';
    final calls = <String>[];
    var memberRemoved = false;
    var invitationRevoked = false;
    var joinRequestApproved = false;
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: tokens,
        client: MockClient((request) async {
          calls.add('${request.method} ${request.url.path}');
          if (request.method == 'DELETE' &&
              request.url.path.endsWith('/family-members/21')) {
            memberRemoved = true;
            return success({});
          }
          if (request.method == 'POST' &&
              request.url.path.endsWith('/family-invitations/51/revoke')) {
            invitationRevoked = true;
            return success({});
          }
          if (request.method == 'POST' &&
              request.url.path.endsWith('/family-join-requests/71/approve')) {
            joinRequestApproved = true;
            return success({'id': 71, 'status': 'approved', 'role': 'viewer'});
          }
          if (request.url.path.endsWith('/members')) {
            return success([
              {
                'id': 20,
                'user_id': 2,
                'name': 'Người dùng thật',
                'role': 'owner',
              },
              if (!memberRemoved)
                {
                  'id': 21,
                  'user_id': 3,
                  'name': 'Người thân',
                  'role': 'viewer',
                },
            ]);
          }
          if (request.url.path.endsWith('/invitations')) {
            return success([
              {
                'id': 51,
                'email': 'nguoi-than@example.com',
                'invitation_type': 'family_viewer',
                'status': invitationRevoked ? 'revoked' : 'pending',
                'expires_at': '2026-10-25T00:00:00.000000Z',
              },
            ]);
          }
          if (request.url.path.endsWith('/join-links')) return success([]);
          if (request.url.path.endsWith('/join-requests')) {
            return success([
              {
                'id': 71,
                'user_id': 4,
                'name': 'Cô Lan',
                'email': 'co.lan@example.com',
                'status': joinRequestApproved ? 'approved' : 'pending',
                'requested_at': '2026-10-17T00:00:00.000000Z',
              },
            ]);
          }
          return readFixture(request, role: 'owner');
        }),
      ),
    );
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await app.initialize();
    expect(app.familyInvitations, hasLength(1));
    app.selectTab(AppTab.family);
    await render(tester, app);
    await tester.tap(find.byTooltip('Quản lý Người thân'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Gỡ khỏi gia đình'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Gỡ thành viên'));
    await tester.pumpAndSettle();
    expect(calls, contains('DELETE /api/family-members/21'));
    expect(find.text('Người thân'), findsNothing);
    await tester.scrollUntilVisible(
      find.text('nguoi-than@example.com'),
      420,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();
    expect(find.text('nguoi-than@example.com'), findsOneWidget);
    expect(find.text('Đang chờ'), findsOneWidget);
    expect(find.text('Cô Lan'), findsOneWidget);
    await tester.tap(find.text('Thu hồi'));
    await tester.pumpAndSettle();
    expect(calls, contains('POST /api/family-invitations/51/revoke'));
    expect(find.text('Đã thu hồi'), findsOneWidget);
    await tester.tap(find.text('Duyệt quyền người thân'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Duyệt quyền người thân').last);
    await tester.pumpAndSettle();
    expect(joinRequestApproved, isTrue);
    expect(calls, contains('POST /api/family-join-requests/71/approve'));
    expect(find.text('Đã duyệt'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });
}
