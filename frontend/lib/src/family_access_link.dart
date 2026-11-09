class FamilyAccessLink {
  const FamilyAccessLink(this.token, {required this.isJoin});
  final String token;
  final bool isJoin;

  factory FamilyAccessLink.parse(String input) {
    final value = input.trim();
    final uri = Uri.tryParse(value);
    final join = uri?.queryParameters['familyJoin'];
    final invite = uri?.queryParameters['familyInvite'];
    if (join != null && invite != null) {
      throw const FormatException(
        'Liên kết chứa hai loại lời mời. Hãy sao chép lại link gia đình.',
      );
    }
    final token = join ?? invite ?? value;
    if (!RegExp(r'^[A-Za-z0-9]{64}$').hasMatch(token)) {
      throw const FormatException(
        'Hãy dán đầy đủ link gia đình hoặc mã lời mời gồm 64 ký tự. Không dùng link xác thực email ở đây.',
      );
    }
    return FamilyAccessLink(token, isJoin: join != null);
  }
}
