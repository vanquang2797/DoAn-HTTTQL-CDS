import 'package:flutter/material.dart';

import '../controller.dart';
import '../theme.dart';
import '../widgets.dart';

class PasswordResetScreen extends StatefulWidget {
  const PasswordResetScreen({
    super.key,
    required this.app,
    required this.token,
    required this.email,
    required this.onFinished,
  });
  final BabiController app;
  final String token, email;
  final VoidCallback onFinished;
  @override
  State<PasswordResetScreen> createState() => _PasswordResetScreenState();
}

class _PasswordResetScreenState extends State<PasswordResetScreen> {
  final _password = TextEditingController(), _confirm = TextEditingController();
  bool _busy = false, _done = false;
  String? _error;
  @override
  void dispose() {
    _password.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_password.text.length < 8 || _password.text != _confirm.text) {
      setState(
        () => _error = 'Mật khẩu cần ít nhất 8 ký tự và hai ô phải trùng nhau.',
      );
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.app.api!.request(
        'POST',
        'auth/password/reset',
        body: {
          'email': widget.email,
          'token': widget.token,
          'password': _password.text,
          'password_confirmation': _confirm.text,
        },
      );
      if (mounted) setState(() => _done = true);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: BabiColors.cream,
    body: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 440),
        child: SurfaceCard(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                _done ? 'Đã đổi mật khẩu' : 'Đặt lại mật khẩu',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 12),
              if (_done) ...[
                const Text(
                  'Mật khẩu đã được cập nhật. Hãy quay lại đăng nhập.',
                ),
                FilledButton(
                  onPressed: () async {
                    await widget.app.clearSessionAfterPasswordReset();
                    widget.onFinished();
                  },
                  child: const Text('Quay lại đăng nhập'),
                ),
              ] else ...[
                Text('Tài khoản: ${widget.email}'),
                TextField(
                  controller: _password,
                  obscureText: true,
                  decoration: const InputDecoration(labelText: 'Mật khẩu mới'),
                ),
                TextField(
                  controller: _confirm,
                  obscureText: true,
                  decoration: const InputDecoration(
                    labelText: 'Nhập lại mật khẩu',
                  ),
                ),
                if (_error != null)
                  Text(_error!, style: const TextStyle(color: Colors.red)),
                FilledButton(
                  onPressed: _busy ? null : _submit,
                  child: Text(_busy ? 'Đang lưu…' : 'Cập nhật mật khẩu'),
                ),
              ],
            ],
          ),
        ),
      ),
    ),
  );
}
