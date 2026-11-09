import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/screens/account.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('relative account can wait for invitation without creating a child', (tester) async {
    final app = BabiController(seedDemo: false, api: ApiClient());
    await tester.pumpWidget(MaterialApp(home: OnboardingScreen(app: app)));
    expect(find.text('Nhận lời mời gia đình'), findsOneWidget);
    expect(find.textContaining('không cần tạo hồ sơ bé'), findsOneWidget);
    expect(find.byType(Form), findsNothing);
    expect(app.hasBaby, false);
    await tester.pumpWidget(const SizedBox());
    app.dispose();
  });
}
