import 'dart:convert';

import 'package:babi/src/controller.dart';
import 'package:babi/src/data/api_client.dart';
import 'package:babi/src/screens/family_invitation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  for (final revoked in [false, true]) {
    testWidgets(
      revoked
          ? 'revoked invite explains revocation before wrong account'
          : 'wrong account cannot accept invitation',
      (tester) async {
        final api = ApiClient(
          client: MockClient(
            (request) async => http.Response(
              jsonEncode({
                'success': true,
                'data': {
                  'inviter_name': 'Owner demo',
                  'family_name': 'Gia đình thử',
                  'invited_email': 'recipient@example.test',
                  'invitation_type': 'family_viewer',
                  'role': 'viewer',
                  'status': revoked ? 'revoked' : 'pending',
                  'email_matches': false,
                  'email_verified': false,
                  'inviter_active': true,
                  'already_member': false,
                  'owner_conflict': false,
                  'can_respond': false,
                },
              }),
              200,
              headers: {'content-type': 'application/json; charset=utf-8'},
            ),
          ),
        );
        final app = BabiController(seedDemo: false, api: api);
        await tester.pumpWidget(
          MaterialApp(
            home: FamilyInvitationScreen(
              app: app,
              token: 'test-only',
              onFinished: () {},
            ),
          ),
        );
        await tester.pumpAndSettle();
        expect(find.widgetWithText(FilledButton, 'Chấp nhận'), findsNothing);
        if (revoked) {
          expect(find.text('Owner đã thu hồi lời mời này.'), findsOneWidget);
        } else {
          expect(
            find.textContaining('Đăng nhập bằng đúng email được mời'),
            findsOneWidget,
          );
        }
        await tester.pumpWidget(const SizedBox());
        app.dispose();
      },
    );
  }
}
