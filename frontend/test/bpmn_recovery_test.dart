import 'dart:convert';

import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/models.dart';
import 'package:babi/src/screens/account.dart';
import 'package:babi/src/screens/family_join.dart';
import 'package:babi/src/screens/health.dart';
import 'package:babi/src/screens/family.dart';
import 'package:babi/src/screens/vaccinations_live.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'api_integration_test.dart'
    show MemoryTokens, page, readFixture, success;

void main() {
  testWidgets('Owner saves vaccination reminder days and recipients', (tester) async {
    http.Request? saved;
    final app = BabiController(seedDemo: false, api: ApiClient(
      tokenStore: MemoryTokens()..value = 'test-token',
      client: MockClient((request) async {
        if (request.url.path.endsWith('/vaccination-reminder-settings')) {
          if (request.method == 'PUT') {
            saved = request;
            return success({'days_before': [14, 2], 'recipient_emails': ['family@example.test'], 'can_edit': true});
          }
          return success({'days_before': [7, 3], 'recipient_emails': [], 'can_edit': true});
        }
        return readFixture(request, role: 'owner');
      }),
    ));
    await app.initialize();
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: LiveVaccinations(app: app, onProfile: () {}))));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('vaccination-reminder-settings')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField).first, '14, 2');
    await tester.enterText(find.byType(TextField).last, 'family@example.test');
    await tester.ensureVisible(find.text('Lưu cài đặt nhắc'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lưu cài đặt nhắc'));
    await tester.pumpAndSettle();
    expect(jsonDecode(saved!.body)['days_before'], [14, 2]);
    expect(jsonDecode(saved!.body)['recipient_emails'], ['family@example.test']);
    expect(find.text('Đã lưu cài đặt nhắc lịch tiêm.'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });

  testWidgets('owner confirms an unvaccinated schedule through the API', (
    tester,
  ) async {
    final requests = <http.Request>[];
    final schedule = <String, dynamic>{
      'id': 81,
      'child_profile_id': 1,
      'name': 'Vắc xin thử nghiệm',
      'dose_number': 1,
      'status': 'unrecorded',
      'effective_date': '2026-10-01',
      'date_basis': 'reference',
      'date_label': 'date_passed',
      'source_url': 'https://example.test/vaccine',
    };
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: MemoryTokens()..value = 'test-token',
        client: MockClient((request) async {
          requests.add(request);
          if (request.url.path.endsWith('/vaccinations')) {
            return page([schedule]);
          }
          if (request.url.path.endsWith('/mark-pending')) {
            schedule['status'] = 'pending';
            return success(schedule);
          }
          return readFixture(request, role: 'owner');
        }),
      ),
    );
    await app.initialize();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: LiveVaccinations(app: app, onProfile: () {}),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.text('Cần rà soát'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Vắc xin thử nghiệm').first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Xác nhận bé chưa tiêm'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Xác nhận chưa tiêm'));
    await tester.pumpAndSettle();

    final request = requests.singleWhere(
      (item) => item.url.path.endsWith('/mark-pending'),
    );
    expect(request.method, 'PATCH');
    expect(jsonDecode(request.body), {
      'confirmed': true,
      'expected_data_revision': 4,
    });
    expect(app.vaccinations.single['status'], 'pending');

    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });

  testWidgets('invitation failure retains email inside the form', (
    tester,
  ) async {
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: MemoryTokens()..value = 'test-token',
        client: MockClient((request) async {
          if (request.method == 'POST') {
            return http.Response(
              '{"success":false,"message":"Invalid recipient"}',
              422,
            );
          }
          return readFixture(request, role: 'owner');
        }),
      ),
    );
    await app.initialize();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: FamilyScreen(app: app, onProfile: () {}),
        ),
      ),
    );
    await tester.pumpAndSettle();
    final invite = find.widgetWithText(FilledButton, 'Mời người thân');
    await tester.scrollUntilVisible(
      invite,
      150,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(invite);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('invite-email')), 'retained@example.test');
    await tester.tap(find.text('Gửi lời mời'));
    await tester.pumpAndSettle();
    expect(find.text('retained@example.test'), findsOneWidget);
    expect(find.text('Invalid recipient'), findsOneWidget);
    expect(find.byType(AlertDialog), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });

  testWidgets('vaccination failure retains vaccine and selected date', (
    tester,
  ) async {
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: MemoryTokens()..value = 'test-token',
        client: MockClient((request) async {
          if (request.method == 'POST') {
            return http.Response(
              '{"success":false,"message":"Temporary save failure"}',
              503,
            );
          }
          return readFixture(request, role: 'owner');
        }),
      ),
    );
    await app.initialize();
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: LiveVaccinations(app: app, onProfile: () {}),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('add-custom-vaccine')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'Retained vaccine');
    await tester.tap(find.text('Chọn ngày đã tiêm'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lưu ghi nhận'));
    await tester.pumpAndSettle();
    expect(find.text('Retained vaccine'), findsOneWidget);
    expect(find.text('Temporary save failure'), findsOneWidget);
    expect(find.text('Thêm mũi tiêm ngoài lịch'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });
  testWidgets(
    'pasted familyJoin URL opens request screen with only the token',
    (tester) async {
      final token = 'A' * 64;
      http.Request? sent;
      final app = BabiController(
        seedDemo: false,
        api: ApiClient(
          client: MockClient((request) async {
            sent = request;
            return http.Response(
              jsonEncode({
                'success': true,
                'data': {
                  'status': 'available',
                  'email_verified': true,
                  'already_member': false,
                  'owner_conflict': false,
                },
              }),
              200,
              headers: {'content-type': 'application/json'},
            );
          }),
        ),
      );
      await tester.pumpWidget(MaterialApp(home: OnboardingScreen(app: app)));
      await tester.tap(find.text('Nhận lời mời gia đình'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'http://127.0.0.1:5173/?familyJoin=$token',
      );
      await tester.tap(find.text('Tiếp tục'));
      await tester.pumpAndSettle();
      expect(find.byType(FamilyJoinScreen), findsOneWidget);
      expect(sent!.url.path, '/api/family-space-join-requests/preview');
      expect(jsonDecode(sent!.body)['token'], token);
      expect(find.text('Gửi yêu cầu tham gia'), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
      app.dispose();
    },
  );

  testWidgets('duplicate measurement displays existing record before editing', (
    tester,
  ) async {
    final app = BabiController(seedDemo: false);
    final today = DateUtils.dateOnly(DateTime.now());
    app.measurements[app.baby.id] = [Measurement(today, 70, 8, 'lying')];
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(body: MeasurementForm(app: app)),
      ),
    );
    await tester.enterText(find.byKey(const Key('height-input')), '71');
    await tester.enterText(find.byKey(const Key('weight-input')), '9');
    await tester.tap(find.text('Lưu số đo'));
    await tester.pumpAndSettle();
    expect(find.text('Ngày này đã có số đo'), findsOneWidget);
    expect(app.records.single.height, 70);
    await tester.tap(find.text('Sửa bản ghi hiện có'));
    await tester.pumpAndSettle();
    expect(find.text('Sửa số đo'), findsOneWidget);
    expect(find.text('Cập nhật số đo'), findsOneWidget);
    expect(app.records.single.height, 70);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });

  test(
    'draft calendar date stays selected and rejects dates outside child life',
    () {
      final app = BabiController(seedDemo: false);
      final date = app.baby.birthDate.add(const Duration(days: 1));
      app.setDraftDate(date);
      app.setCaption('Keeps the date');
      expect(app.draftDate, date);
      expect(
        () => app.setDraftDate(
          app.baby.birthDate.subtract(const Duration(days: 1)),
        ),
        throwsFormatException,
      );
      expect(
        () => app.setDraftDate(DateTime.now().add(const Duration(days: 2))),
        throwsFormatException,
      );
      app.dispose();
    },
  );
}

