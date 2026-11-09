import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/screens/family.dart';
import 'package:babi/src/theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/testing.dart';

import 'api_integration_test.dart' show MemoryTokens, readFixture, success;

void main() {
  testWidgets('relative copies link without email workflow', (tester) async {
    String? copied;
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied = (call.arguments as Map)['text'] as String;
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );
    tester.view.physicalSize = const Size(320, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final writes = <String>[];
    final app = BabiController(
      seedDemo: false,
      api: ApiClient(
        tokenStore: MemoryTokens()..value = 'test-token',
        client: MockClient((request) async {
          if (request.method == 'POST') {
            writes.add(request.url.path);
            if (request.url.path.endsWith('/join-links')) {
              return success({
                'id': 1,
                'token': 'test-link-only',
                'expires_at': '2099-01-01',
              });
            }
          }
          return readFixture(request, role: 'viewer');
        }),
      ),
    );
    await app.initialize();
    expect(app.canContribute, isTrue);
    expect(app.canCreate, isFalse);
    await tester.pumpWidget(
      MaterialApp(
        theme: babiTheme(),
        home: Scaffold(
          body: FamilyScreen(app: app, onProfile: () {}),
        ),
      ),
    );
    await tester.pumpAndSettle();
    final button = find.byKey(const Key('share-family-link'));
    await tester.scrollUntilVisible(
      button,
      150,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(writes, ['/api/family-spaces/10/join-links']);
    expect(copied, contains('familyJoin=test-link-only'));
    expect(find.textContaining('familyJoin=test-link-only'), findsOneWidget);
    expect(find.text('Đề nghị mời người thân'), findsNothing);
    expect(find.text('Duyệt và gửi lời mời'), findsNothing);
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });
}
