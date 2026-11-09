import 'dart:convert';

import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/screens/family_join.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  for (final revoked in [false, true]) {
    testWidgets(revoked ? 'revoked link shows API explanation' : 'existing member opens family without requesting again', (tester) async {
      final paths = <String>[];
      final api = ApiClient(client: MockClient((request) async {
        paths.add(request.url.path);
        return http.Response(jsonEncode(revoked
            ? {'success': false, 'message': 'Liên kết gia đình đã bị thu hồi.'}
            : {'success': true, 'data': {'id': null, 'status': 'approved', 'already_member': true, 'family_space_id': 1, 'email_verified': true, 'owner_conflict': false}}),
          revoked ? 410 : 200, headers: {'content-type': 'application/json; charset=utf-8'});
      }));
      final app = BabiController(seedDemo: false, api: api);
      await tester.pumpWidget(MaterialApp(home: FamilyJoinScreen(app: app, token: 'test-only', onJoined: () {})));
      await tester.pumpAndSettle();
      expect(find.text('Gửi yêu cầu tham gia'), findsNothing);
      expect(paths, ['/api/family-space-join-requests/preview']);
      if (revoked) {
        expect(find.text('Liên kết gia đình đã bị thu hồi.'), findsOneWidget);
      } else {
        expect(find.text('Bạn đã là thành viên'), findsOneWidget);
        expect(find.text('Vào gia đình'), findsOneWidget);
      }
      await tester.pumpWidget(const SizedBox());
      app.dispose();
    });
  }
}
