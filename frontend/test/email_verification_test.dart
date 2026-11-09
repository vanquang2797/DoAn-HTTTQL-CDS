import 'dart:convert';

import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/screens/email_verification.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('wrong session displays email and blocks verification request', (
    tester,
  ) async {
    var requests = 0;
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        client: MockClient((request) async {
          requests++;
          return http.Response('{}', 200);
        }),
      ),
    )..user = {'id': 1, 'email': 'owner@example.test'};
    await tester.pumpWidget(
      MaterialApp(
        home: EmailVerificationScreen(
          app: app,
          verificationUrl: 'http://127.0.0.1:8000/api/auth/email/verify?id=6&hash=test&expires=123&signature=test',
          onFinished: () {},
        ),
      ),
    );
    expect(find.text('Đang đăng nhập: owner@example.test'), findsOneWidget);
    expect(
      find.textContaining('Thư này dành cho tài khoản khác'),
      findsOneWidget,
    );
    final button = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Xác thực email'),
    );
    expect(button.onPressed, isNull);
    expect(find.text('Gửi lại thư xác thực'), findsNothing);
    expect(requests, 0);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });
  testWidgets('verification uses our API and preserves the signed query', (
    tester,
  ) async {
    http.Request? sent;
    final api = ApiClient(
      baseUrl: 'http://127.0.0.1:8000/api',
      client: MockClient((request) async {
        sent = request;
        return http.Response(jsonEncode({'success': true}), 200);
      }),
    )..token = 'test-token';
    final app = BabiController(seedDemo: false, api: api);
    await tester.pumpWidget(
      MaterialApp(
        home: EmailVerificationScreen(
          app: app,
          verificationUrl: 'https://untrusted.example/api/auth/email/verify?id=2&hash=abc&expires=123&signature=xyz',
          onFinished: () {},
        ),
      ),
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Xác thực email'));
    await tester.pumpAndSettle();
    expect(sent!.url.host, '127.0.0.1');
    expect(sent!.url.query, 'id=2&hash=abc&expires=123&signature=xyz');
    expect(sent!.method, 'POST');
    expect(sent!.headers['Authorization'], 'Bearer test-token');
    expect(find.textContaining('Đã xác thực email.'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });
}
