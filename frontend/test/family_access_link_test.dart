import 'package:babi/src/family_access_link.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  final token = 'A' * 64;
  test('join links use join flow and never send full URL as invite token', () {
    final link = FamilyAccessLink.parse(
      ' http://127.0.0.1:5173/?familyJoin=$token ',
    );
    expect(link.isJoin, isTrue);
    expect(link.token, token);
  });
  test('email invitations and raw legacy codes stay in invitation flow', () {
    expect(
      FamilyAccessLink.parse('http://127.0.0.1:5173/?familyInvite=$token')
          .isJoin,
      isFalse,
    );
    expect(FamilyAccessLink.parse(token).token, token);
  });
  test(
    'verification URL and malformed/ambiguous codes fail before API request',
    () {
      for (final value in [
        'http://127.0.0.1:5173/?emailVerify=abc',
        'short',
        'http://localhost/?familyJoin=$token&familyInvite=$token',
      ]) {
        expect(() => FamilyAccessLink.parse(value), throwsFormatException);
      }
    },
  );
}
