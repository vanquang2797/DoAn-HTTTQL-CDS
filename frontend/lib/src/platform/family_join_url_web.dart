import 'package:web/web.dart' as web;

void removeEmailVerificationToken() {
  final uri = Uri.base;
  final parameters = Map<String, String>.from(uri.queryParameters)
    ..remove('emailVerify');
  web.window.history.replaceState(
    null,
    '',
    uri
        .replace(queryParameters: parameters.isEmpty ? null : parameters)
        .toString(),
  );
}

void removeFamilyAccessTokens() {
  final uri = Uri.base;
  final parameters = Map<String, String>.from(uri.queryParameters)
    ..remove('familyJoin')
    ..remove('familyInvite');
  web.window.history.replaceState(
    null,
    '',
    uri
        .replace(queryParameters: parameters.isEmpty ? null : parameters)
        .toString(),
  );
}
