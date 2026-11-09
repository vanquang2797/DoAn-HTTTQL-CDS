import 'package:flutter/material.dart';

import '../controller.dart';

class EmailVerificationScreen extends StatefulWidget {
  const EmailVerificationScreen({
    super.key,
    required this.app,
    required this.verificationUrl,
    required this.onFinished,
    this.returnJoinToken,
  });

  final BabiController app;
  final String verificationUrl;
  final VoidCallback onFinished;
  final String? returnJoinToken;

  @override
  State<EmailVerificationScreen> createState() =>
      _EmailVerificationScreenState();
}

class _EmailVerificationScreenState extends State<EmailVerificationScreen> {
  bool _busy = false;
  String? _error;
  bool _verified = false;
  bool get _matchesAccount {
    final id = widget.app.user?['id'];
    return id == null ||
        Uri.tryParse(widget.verificationUrl)?.queryParameters['id'] == '$id';
  }

  Future<void> _resend() async {
    setState(() => _busy = true);
    try {
      await widget.app.api!.request(
        'POST',
        'auth/email/verification',
        body: widget.returnJoinToken == null
            ? null
            : {'family_join_token': widget.returnJoinToken},
      );
      if (mounted) {
        setState(
          () =>
              _error = 'Đã yêu cầu gửi thư xác thực mới. Hãy mở thư mới nhất.',
        );
      }
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final url = Uri.parse(widget.verificationUrl);
      if (url.path != '/api/auth/email/verify' || url.query.isEmpty) {
        throw const FormatException('Liên kết xác thực không hợp lệ.');
      }
      // Only send the signed query to our own API, never to an email-supplied host.
      await widget.app.api!.request('POST', 'auth/email/verify?${url.query}');
      widget.app.markEmailVerified();
      if (mounted) setState(() => _verified = true);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: const Text('Xác thực email')),
    body: Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (widget.app.user?['email'] != null) ...[
              Text('Đang đăng nhập: ${widget.app.user!['email']}'),
              const SizedBox(height: 12),
            ],
            Text(
              _verified
                  ? widget.returnJoinToken == null
                        ? 'Đã xác thực email. Bạn có thể mở lại lời mời để chấp nhận.'
                        : 'Đã xác thực email. Bấm Tiếp tục để gửi yêu cầu tham gia, Owner sẽ xét duyệt trước khi bạn có quyền xem.'
                  : !_matchesAccount
                  ? 'Thư này dành cho tài khoản khác. Bấm Đăng nhập tài khoản khác bên dưới và dùng đúng email đã nhận thư. Các tab trong cùng trình duyệt dùng chung phiên đăng nhập.'
                  : 'Xác thực email của tài khoản đang đăng nhập bằng liên kết trong thư.',
            ),
            if (_error != null) Text(_error!),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _busy || !_matchesAccount
                  ? null
                  : (_verified ? widget.onFinished : _verify),
              child: Text(
                _busy
                    ? 'Đang xác thực…'
                    : (_verified ? 'Tiếp tục' : 'Xác thực email'),
              ),
            ),
            if (!_verified && _matchesAccount)
              TextButton(
                onPressed: _busy ? null : _resend,
                child: const Text('Gửi lại thư xác thực'),
              ),
            if (!_verified)
              TextButton(
                onPressed: _busy
                    ? null
                    : () async {
                        try {
                          await widget.app.logout();
                        } catch (e) {
                          if (mounted) setState(() => _error = e.toString());
                        }
                      },
                child: const Text('Đăng nhập tài khoản khác'),
              ),
          ],
        ),
      ),
    ),
  );
}
