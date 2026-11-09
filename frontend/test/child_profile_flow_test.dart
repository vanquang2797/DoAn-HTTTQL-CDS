import 'dart:convert';

import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/models.dart';
import 'package:babi/src/screens/account.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('avatar is multipart with consent booleans and operation key', () async {
    http.Request? sent;
    final api = ApiClient(
      client: MockClient((request) async {
        sent = request;
        return http.Response('{"success":true,"data":{"id":3}}', 201);
      }),
    )..token = 'test-token';
    final avatar = MediaItem.file(
      bytes: base64Decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jGQAAAABJRU5ErkJggg==',
      ),
      path: 'avatar.png',
      isVideo: false,
      mimeType: 'image/png',
    );
    await api.createChildProfile(
      {'name': 'Test child', 'storage_consent': true},
      operation: 'same-key',
      avatar: avatar,
    );
    expect(sent!.headers['Content-Type'], startsWith('multipart/form-data'));
    expect(sent!.headers['Authorization'], 'Bearer test-token');
    expect(sent!.headers['X-Operation-Key'], 'same-key');
    expect(latin1.decode(sent!.bodyBytes), contains('name="avatar"; filename="avatar.png"'));
    expect(latin1.decode(sent!.bodyBytes), contains('name="storage_consent"\r\n\r\n1'));
    api.close();
  });

  testWidgets(
    'failed child creation retains form and retries same operation key',
    (tester) async {
      final operations = <String>[];
      final app = BabiController(
        seedDemo: false,
        api: ApiClient(
          client: MockClient((request) async {
            operations.add(request.headers['X-Operation-Key']!);
            return http.Response(
              '{"success":false,"message":"Temporary failure"}',
              503,
            );
          }),
        ),
      );
      app.configuration['policy_version'] = 'test';
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () => showCreateBaby(context, app),
                child: const Text('Add'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Add'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextFormField), 'Preserved name');
      await tester.tap(find.text('Ngày sinh của bé'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pumpAndSettle();
      final save = find.widgetWithText(FilledButton, 'Tạo hồ sơ bé');
      await tester.ensureVisible(save);
      await tester.tap(save);
      await tester.pumpAndSettle();
      expect(find.text('Preserved name'), findsOneWidget);
      await tester.tap(save);
      await tester.pumpAndSettle();
      expect(operations, hasLength(2));
      expect(operations[1], operations[0]);
      await tester.pumpWidget(const SizedBox());
      app.dispose();
    },
  );
}
