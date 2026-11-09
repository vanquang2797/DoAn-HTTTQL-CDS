import 'package:flutter/material.dart';

import 'dart:convert';

import 'package:image_picker/image_picker.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../controller.dart';
import '../data/api_client.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets.dart';
import 'family_invitation.dart';
import 'family_join.dart';
import '../family_access_link.dart';

class AuthScreen extends StatefulWidget {
  const AuthScreen({super.key, required this.app, this.invitationFlow = false});
  final BabiController app;
  final bool invitationFlow;
  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  final form = GlobalKey<FormState>();
  final email = TextEditingController();
  final password = TextEditingController();
  final name = TextEditingController();
  bool register = false, obscure = true, acceptedTerms = false;
  String? error;

  @override
  void dispose() {
    email.dispose();
    password.dispose();
    name.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (!form.currentState!.validate() || widget.app.busy) return;
    if (register && !acceptedTerms) {
      setState(() => error = 'Hãy xác nhận bạn đã đọc thông tin bảo mật.');
      return;
    }
    setState(() => error = null);
    try {
      await widget.app.authenticate(
        email.text.trim(),
        password.text,
        name: register ? name.text.trim() : null,
      );
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    }
  }

  void setMode(bool value) {
    if (widget.app.busy) return;
    setState(() {
      register = value;
      error = null;
    });
  }

  void showInfo(String title, String body) => showDialog<void>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(body),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Đã hiểu'),
        ),
      ],
    ),
  );

  Future<void> requestPasswordReset() async {
    final address = TextEditingController(text: email.text.trim());
    var busy = false;
    String? message;
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Khôi phục mật khẩu'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: address,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(labelText: 'Email tài khoản'),
              ),
              if (message != null)
                Padding(
                  padding: const EdgeInsets.only(top: 10),
                  child: Text(message!),
                ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: busy ? null : () => Navigator.pop(dialogContext),
              child: const Text('Đóng'),
            ),
            FilledButton(
              onPressed: busy || message != null
                  ? null
                  : () async {
                      if (!address.text.contains('@')) {
                        setDialogState(() => message = 'Nhập email hợp lệ.');
                        return;
                      }
                      setDialogState(() => busy = true);
                      try {
                        await widget.app.api!.request(
                          'POST',
                          'auth/password/forgot',
                          body: {'email': address.text.trim()},
                        );
                        setDialogState(
                          () => message = 'Nếu email đã đăng ký, hướng dẫn đặt lại mật khẩu sẽ được gửi tới hộp thư.',
                        );
                      } catch (e) {
                        setDialogState(() => message = e.toString());
                      } finally {
                        setDialogState(() => busy = false);
                      }
                    },
              child: Text(busy ? 'Đang gửi…' : 'Gửi hướng dẫn'),
            ),
          ],
        ),
      ),
    );
    address.dispose();
  }

  Widget svg(String file, {double size = 22}) => SvgPicture.asset(
    'assets/figma/auth/$file.svg',
    width: size,
    height: size,
    fit: BoxFit.contain,
  );

  InputDecoration fieldDecoration(
    String label,
    String icon, {
    Widget? suffix,
  }) => InputDecoration(
    labelText: label,
    prefixIcon: Padding(
      padding: const EdgeInsets.all(14),
      child: svg(icon, size: 20),
    ),
    suffixIcon: suffix,
    filled: true,
    fillColor: const Color(0xEFFFFFFF),
    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
    border: OutlineInputBorder(
      borderRadius: BorderRadius.circular(18),
      borderSide: const BorderSide(color: BabiColors.border),
    ),
    enabledBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(18),
      borderSide: const BorderSide(color: BabiColors.border),
    ),
    focusedBorder: OutlineInputBorder(
      borderRadius: BorderRadius.circular(18),
      borderSide: const BorderSide(color: BabiColors.primary, width: 1.5),
    ),
  );

  Widget socialButton(String file, String label) => Tooltip(
    message: '$label chưa được kết nối với máy chủ.',
    child: InkWell(
      borderRadius: BorderRadius.circular(40),
      onTap: () => ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Đăng nhập $label chưa được tích hợp.')),
      ),
      child: Container(
        width: 52,
        height: 52,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: .86),
          shape: BoxShape.circle,
          border: Border.all(color: BabiColors.border),
        ),
        child: Center(child: svg(file, size: 22)),
      ),
    ),
  );

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      final screen = Scaffold(
        backgroundColor: Colors.transparent,
        body: Stack(
          fit: StackFit.expand,
          children: [
            const LiquidGlassBackdrop(),
            SafeArea(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(24, 14, 24, 24),
                child: Form(
                  key: form,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const SizedBox(height: 4),
                      Center(
                        child: SizedBox(
                          width: 64,
                          height: 64,
                          child: Stack(
                            clipBehavior: Clip.none,
                            children: [
                              Center(
                                child: SvgPicture.asset(
                                  'assets/figma/auth/${register ? 'register_brand_face' : 'login_brand_babi_mark'}.svg',
                                  width: register ? 56 : 62,
                                  height: 52,
                                  fit: BoxFit.contain,
                                ),
                              ),
                              Positioned(
                                right: -3,
                                top: 0,
                                child: SvgPicture.asset(
                                  'assets/figma/auth/${register ? 'register_brand_sparkle' : 'login_brand_sparkle'}.svg',
                                  width: 18,
                                  height: 18,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        register ? 'Tạo tài khoản' : 'Chào mừng trở lại',
                        textAlign: TextAlign.center,
                        style: textStyle(26, line: 34, weight: FontWeight.w700),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        register
                            ? 'Bắt đầu hành trình lưu giữ tuổi thơ của bé'
                            : 'Cùng lưu lại từng khoảnh khắc bé lớn lên',
                        textAlign: TextAlign.center,
                        style: textStyle(
                          13,
                          line: 19,
                          color: BabiColors.secondary,
                        ),
                      ),
                      if (widget.invitationFlow) ...[
                        const SizedBox(height: 12),
                        const Text(
                          'Đã có tài khoản Babi? Chỉ đăng nhập bằng email được mời. Chưa có tài khoản? Đăng ký một lần; sau đó bạn sẽ quay lại lời mời, không cần tạo hồ sơ bé.',
                          textAlign: TextAlign.center,
                        ),
                      ],
                      const SizedBox(height: 22),
                      GlassSurface(
                        padding: const EdgeInsets.fromLTRB(16, 16, 16, 18),
                        borderRadius: 30,
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Container(
                              padding: const EdgeInsets.all(4),
                              decoration: BoxDecoration(
                                color: const Color(0xFFF3ECE7),
                                borderRadius: BorderRadius.circular(18),
                              ),
                              child: Row(
                                children: [
                                  _modeTab(
                                    false,
                                    'Đăng nhập',
                                    'login_tab_lock',
                                  ),
                                  _modeTab(
                                    true,
                                    'Đăng ký',
                                    'login_tab_register',
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 16),
                            if (register) ...[
                              TextFormField(
                                key: const Key('login-name'),
                                controller: name,
                                textCapitalization: TextCapitalization.words,
                                decoration: fieldDecoration(
                                  'Tên phụ huynh',
                                  'register_field_name',
                                ),
                                validator: (v) =>
                                    register && (v ?? '').trim().isEmpty
                                    ? 'Nhập tên của bạn'
                                    : null,
                              ),
                              const SizedBox(height: 12),
                            ],
                            TextFormField(
                              key: const Key('login-email'),
                              controller: email,
                              keyboardType: TextInputType.emailAddress,
                              autofillHints: const [AutofillHints.email],
                              decoration: fieldDecoration(
                                'Email tài khoản',
                                register
                                    ? 'register_field_email'
                                    : 'login_field_email',
                              ),
                              validator: (v) => !(v ?? '').contains('@')
                                  ? 'Nhập email hợp lệ'
                                  : null,
                            ),
                            const SizedBox(height: 12),
                            TextFormField(
                              key: const Key('login-password'),
                              controller: password,
                              obscureText: obscure,
                              autofillHints: [
                                register
                                    ? AutofillHints.newPassword
                                    : AutofillHints.password,
                              ],
                              decoration: fieldDecoration(
                                'Mật khẩu',
                                register
                                    ? 'register_field_password'
                                    : 'login_field_password',
                                suffix: IconButton(
                                  tooltip: obscure
                                      ? 'Hiện mật khẩu'
                                      : 'Ẩn mật khẩu',
                                  onPressed: () =>
                                      setState(() => obscure = !obscure),
                                  icon: svg('login_password_eye', size: 20),
                                ),
                              ),
                              validator: (v) => (v ?? '').length < 8
                                  ? 'Mật khẩu cần ít nhất 8 ký tự'
                                  : null,
                              onFieldSubmitted: (_) => submit(),
                            ),
                            if (!register)
                              Align(
                                alignment: Alignment.centerRight,
                                child: TextButton(
                                  onPressed: requestPasswordReset,
                                  child: const Text('Quên mật khẩu?'),
                                ),
                              )
                            else ...[
                              const SizedBox(height: 8),
                              Material(
                                color: Colors.transparent,
                                child: CheckboxListTile(
                                  key: const Key('auth-terms'),
                                  value: acceptedTerms,
                                  onChanged: (v) => setState(
                                    () => acceptedTerms = v ?? false,
                                  ),
                                  contentPadding: EdgeInsets.zero,
                                  dense: true,
                                  controlAffinity:
                                      ListTileControlAffinity.leading,
                                  title: const Text(
                                    'Tôi đồng ý với chính sách riêng tư. Có thể thêm hồ sơ bé sau khi tạo tài khoản.',
                                    style: TextStyle(fontSize: 12, height: 1.4),
                                  ),
                                ),
                              ),
                            ],
                            if (!register)
                              Row(
                                children: [
                                  SvgPicture.asset(
                                    'assets/figma/auth/login_remember_check.svg',
                                    width: 18,
                                    height: 18,
                                  ),
                                  const SizedBox(width: 8),
                                  Text(
                                    'Giữ đăng nhập trên thiết bị này',
                                    style: textStyle(12, line: 18),
                                  ),
                                ],
                              ),
                            if (error != null || widget.app.issue != null) ...[
                              const SizedBox(height: 8),
                              Text(
                                error ?? widget.app.issue!,
                                style: const TextStyle(color: BabiColors.error),
                              ),
                            ],
                            const SizedBox(height: 12),
                            _submitButton(),
                            const SizedBox(height: 16),
                            Row(
                              children: [
                                const Expanded(
                                  child: Divider(color: BabiColors.border),
                                ),
                                Padding(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 12,
                                  ),
                                  child: Text(
                                    'hoặc tiếp tục với',
                                    style: textStyle(
                                      12,
                                      line: 18,
                                      color: BabiColors.secondary,
                                    ),
                                  ),
                                ),
                                const Expanded(
                                  child: Divider(color: BabiColors.border),
                                ),
                              ],
                            ),
                            const SizedBox(height: 14),
                            Row(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                socialButton(
                                  register ? 'register_google' : 'login_google',
                                  'Google',
                                ),
                                const SizedBox(width: 14),
                                socialButton(
                                  register ? 'register_apple' : 'login_apple',
                                  'Apple',
                                ),
                                const SizedBox(width: 14),
                                socialButton(
                                  register
                                      ? 'register_facebook'
                                      : 'login_facebook',
                                  'Facebook',
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                      if (!register) ...[
                        const SizedBox(height: 14),
                        _memoryReminder(),
                      ] else ...[
                        const SizedBox(height: 12),
                        Center(
                          child: TextButton(
                            onPressed: () => setMode(false),
                            child: const Text(
                              'Đã có tài khoản? Đăng nhập ngay',
                            ),
                          ),
                        ),
                      ],
                      const SizedBox(height: 8),
                      Text(
                        register
                            ? 'Thông tin của gia đình được bảo vệ và chỉ chia sẻ theo lựa chọn của bạn.'
                            : 'Bằng việc tiếp tục, bạn đồng ý với Điều khoản sử dụng và Chính sách quyền riêng tư.',
                        textAlign: TextAlign.center,
                        style: textStyle(
                          10,
                          line: 15,
                          color: BabiColors.secondary,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      );
      if (!kIsWeb) return ClipRect(child: screen);
      final width = constraints.maxWidth < 393 ? constraints.maxWidth : 393.0;
      final height = constraints.maxHeight < 852
          ? constraints.maxHeight
          : 852.0;
      return ColoredBox(
        color: const Color(0xFFEEE9E4),
        child: Center(
          child: SizedBox(
            width: width,
            height: height,
            child: Column(
              children: [
                const _AuthStatusBar(),
                Expanded(child: ClipRect(child: screen)),
              ],
            ),
          ),
        ),
      );
    },
  );

  Widget _modeTab(bool value, String label, String asset) => Expanded(
    child: InkWell(
      key: Key(value ? 'auth-register-tab' : 'auth-login-tab'),
      borderRadius: BorderRadius.circular(14),
      onTap: () => setMode(value),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        height: 42,
        decoration: BoxDecoration(
          color: register == value ? Colors.white : Colors.transparent,
          borderRadius: BorderRadius.circular(14),
          boxShadow: register == value
              ? [
                  BoxShadow(
                    color: BabiColors.ink.withValues(alpha: .08),
                    blurRadius: 10,
                    offset: const Offset(0, 2),
                  ),
                ]
              : null,
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            SvgPicture.asset(
              'assets/figma/auth/$asset.svg',
              width: 17,
              height: 17,
            ),
            const SizedBox(width: 7),
            Text(
              label,
              style: textStyle(13, line: 18, weight: FontWeight.w600),
            ),
          ],
        ),
      ),
    ),
  );

  Widget _submitButton() => SizedBox(
    height: 54,
    child: DecoratedBox(
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFFF47B5D), Color(0xFFE9573F)],
        ),
        borderRadius: BorderRadius.circular(18),
        boxShadow: [
          BoxShadow(
            color: BabiColors.primary.withValues(alpha: .24),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: FilledButton(
        key: const Key('login-submit'),
        style: FilledButton.styleFrom(
          backgroundColor: Colors.transparent,
          shadowColor: Colors.transparent,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
        ),
        onPressed: widget.app.busy ? null : submit,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(
              widget.app.busy
                  ? 'Đang kết nối…'
                  : register
                  ? 'Tạo tài khoản'
                  : 'Vào sổ ký ức',
              style: const TextStyle(
                color: Colors.white,
                fontSize: 15,
                fontWeight: FontWeight.w700,
              ),
            ),
            const SizedBox(width: 8),
            SvgPicture.asset(
              'assets/figma/auth/${register ? 'register' : 'login'}_submit_arrow.svg',
              width: 18,
              height: 18,
            ),
          ],
        ),
      ),
    ),
  );

  Widget _memoryReminder() => GlassSurface(
    borderRadius: 22,
    padding: const EdgeInsets.all(12),
    tint: const Color(0xCFFFFFFF),
    child: Row(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(14),
          child: Image.asset(
            'assets/figma/auth/login_reminder_photo.png',
            width: 52,
            height: 52,
            fit: BoxFit.cover,
          ),
        ),
        const SizedBox(width: 11),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Khoảnh khắc của bé',
                style: textStyle(12, line: 17, weight: FontWeight.w600),
              ),
              const SizedBox(height: 3),
              Text(
                'Mỗi ngày con lớn thêm một chút.',
                style: textStyle(11, line: 15, color: BabiColors.secondary),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
        SvgPicture.asset(
          'assets/figma/auth/login_reminder_icon.svg',
          width: 24,
          height: 24,
        ),
      ],
    ),
  );
}

class _AuthStatusBar extends StatelessWidget {
  const _AuthStatusBar();
  @override
  Widget build(BuildContext context) => SizedBox(
    height: 56,
    child: Padding(
      padding: const EdgeInsets.fromLTRB(24, 18, 22, 0),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text('9:41', style: textStyle(14, line: 20, weight: FontWeight.w600)),
          Row(
            children: [
              SvgPicture.asset(
                'assets/figma/auth/login_status_cellular.svg',
                width: 16,
                height: 16,
              ),
              const SizedBox(width: 5),
              SvgPicture.asset(
                'assets/figma/auth/login_status_wifi.svg',
                width: 16,
                height: 16,
              ),
              const SizedBox(width: 5),
              SvgPicture.asset(
                'assets/figma/auth/login_status_battery.svg',
                width: 20,
                height: 16,
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class OnboardingScreen extends StatelessWidget {
  const OnboardingScreen({super.key, required this.app});
  final BabiController app;
  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: Colors.transparent,
    body: Stack(
      fit: StackFit.expand,
      children: [
        const LiquidGlassBackdrop(),
        SafeArea(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(22, 32, 22, 28),
            children: [
              const Icon(
                Icons.child_care_rounded,
                size: 52,
                color: BabiColors.primary,
              ),
              const SizedBox(height: 12),
              Text(
                'Tài khoản của bạn đã sẵn sàng',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 6),
              const Text(
                'Ông bà và người thân có thể dùng tài khoản này để nhận lời mời. Bạn không cần tạo hồ sơ bé hoặc đăng ký lại.',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: () => showAcceptInvitation(context, app),
                icon: const Icon(Icons.mail_outline),
                label: const Text('Nhận lời mời gia đình'),
              ),
              const SizedBox(height: 8),
              const Text(
                'Chưa có lời mời? Bạn có thể đóng ứng dụng và quay lại khi cha mẹ gửi liên kết đến đúng email đã đăng ký.',
              ),
              if (!app.emailVerified)
                TextButton(
                  onPressed: () => showEmailVerification(context, app),
                  child: const Text('Xác thực email trước'),
                ),
              const Divider(height: 32),
              const Text('Dành cho cha mẹ muốn quản lý gia đình của mình'),
              OutlinedButton.icon(
                onPressed: () => showCreateBaby(context, app),
                icon: const Icon(Icons.child_care),
                label: const Text('Tạo hồ sơ bé và gia đình'),
              ),
              TextButton(
                onPressed: () async {
                  try {
                    await app.logout();
                  } catch (e) {
                    if (context.mounted) showIssue(context, e);
                  }
                },
                child: const Text('Đăng xuất'),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

Future<void> showCreateBaby(BuildContext context, BabiController app) =>
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => SafeArea(
        child: Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.viewInsetsOf(context).bottom,
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            child: _BabyProfileForm(app: app),
          ),
        ),
      ),
    );

class _BabyProfileForm extends StatefulWidget {
  const _BabyProfileForm({required this.app});
  final BabiController app;
  @override
  State<_BabyProfileForm> createState() => _BabyProfileFormState();
}

class _BabyProfileFormState extends State<_BabyProfileForm> {
  final form = GlobalKey<FormState>();
  final name = TextEditingController();
  final birthWeight = TextEditingController();
  final birthLength = TextEditingController();
  DateTime? birth;
  String gender = 'female';
  bool consent = false, saving = false;
  String? error;
  MediaItem? avatar;
  int _avatarRevision = 0;
  String? _operation, _fingerprint;

  Future<void> _pickAvatar() async {
    try {
      final file = await ImagePicker().pickImage(source: ImageSource.gallery);
      if (file == null) return;
      final image = await MediaItem.fromFile(file);
      if (image.isVideo) throw const FormatException('Chọn ảnh JPEG hoặc PNG.');
      if (mounted) {
        setState(() {
          avatar = image;
          _avatarRevision++;
          error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    }
  }

  @override
  void dispose() {
    name.dispose();
    birthWeight.dispose();
    birthLength.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (!form.currentState!.validate() || saving) return;
    if (!consent || birth == null) {
      setState(
        () => error = birth == null
            ? 'Chọn ngày sinh của bé.'
            : 'Cần xác nhận quyền đại diện và đồng ý lưu hồ sơ.',
      );
      return;
    }
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final fingerprint = jsonEncode([
        name.text.trim(),
        birth!.toIso8601String(),
        gender,
        birthWeight.text.trim(),
        birthLength.text.trim(),
        _avatarRevision,
      ]);
      if (_fingerprint != fingerprint) {
        _fingerprint = fingerprint;
        _operation = operationKey();
      }
      await widget.app.createBaby(
        name: name.text.trim(),
        birthDate: birth!,
        gender: gender,
        birthWeightKg: double.tryParse(
          birthWeight.text.trim().replaceAll(',', '.'),
        ),
        birthLengthCm: double.tryParse(
          birthLength.text.trim().replaceAll(',', '.'),
        ),
        operation: _operation!,
        avatar: avatar,
      );
      if (mounted && Navigator.canPop(context)) Navigator.pop(context);
    } catch (e) {
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Form(
    key: form,
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('Thông tin của bé', style: Theme.of(context).textTheme.titleLarge),
        if (avatar?.bytes != null)
          SizedBox(
            height: 100,
            child: Image.memory(avatar!.bytes!, fit: BoxFit.contain),
          ),
        OutlinedButton.icon(
          onPressed: saving ? null : _pickAvatar,
          icon: const Icon(Icons.add_photo_alternate_outlined),
          label: const Text('Ảnh bé tùy chọn · JPEG/PNG tối đa 10 MB'),
        ),
        if (avatar != null)
          TextButton(
            onPressed: saving
                ? null
                : () => setState(() {
                    avatar = null;
                    _avatarRevision++;
                  }),
            child: const Text('Bỏ ảnh đại diện'),
          ),
        const SizedBox(height: 12),
        TextFormField(
          controller: name,
          decoration: const InputDecoration(labelText: 'Tên bé'),
          validator: (v) => (v ?? '').trim().isEmpty ? 'Nhập tên bé' : null,
        ),
        const SizedBox(height: 10),
        OutlinedButton.icon(
          onPressed: () async {
            final now = DateTime.now();
            final selected = await showDatePicker(
              context: context,
              initialDate: birth ?? DateTime(now.year - 1),
              firstDate: DateTime(now.year - 5),
              lastDate: now,
            );
            if (selected != null && mounted) setState(() => birth = selected);
          },
          icon: const Icon(Icons.calendar_today_outlined),
          label: Text(
            birth == null ? 'Ngày sinh của bé' : calendarDate(birth!),
          ),
        ),
        const SizedBox(height: 8),
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'female', label: Text('Bé gái')),
            ButtonSegment(value: 'male', label: Text('Bé trai')),
          ],
          selected: {gender},
          onSelectionChanged: (v) => setState(() => gender = v.first),
        ),
        const SizedBox(height: 10),
        Text(
          'Thông tin lúc sinh (có thể bổ sung sau)',
          style: Theme.of(context).textTheme.titleSmall,
        ),
        const SizedBox(height: 6),
        Row(
          children: [
            Expanded(
              child: TextFormField(
                controller: birthWeight,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'Cân nặng lúc sinh',
                  suffixText: 'kg',
                ),
                validator: (value) =>
                    _validateOptionalMeasurement(value, .1, 20, 'Cân nặng'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: TextFormField(
                controller: birthLength,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'Chiều dài lúc sinh',
                  suffixText: 'cm',
                ),
                validator: (value) =>
                    _validateOptionalMeasurement(value, 10, 100, 'Chiều dài'),
              ),
            ),
          ],
        ),
        CheckboxListTile(
          contentPadding: EdgeInsets.zero,
          value: consent,
          onChanged: (v) => setState(() => consent = v ?? false),
          title: const Text(
            'Tôi là người đại diện hợp pháp và đồng ý lưu hồ sơ, chia sẻ trong gia đình theo chính sách hiện hành.',
            style: TextStyle(fontSize: 12),
          ),
        ),
        if (error != null)
          Text(error!, style: const TextStyle(color: BabiColors.error)),
        FilledButton(
          onPressed: saving ? null : submit,
          child: Text(saving ? 'Đang tạo hồ sơ…' : 'Tạo hồ sơ bé'),
        ),
      ],
    ),
  );

  String? _validateOptionalMeasurement(
    String? value,
    double min,
    double max,
    String label,
  ) {
    final text = (value ?? '').trim();
    if (text.isEmpty) return null;
    final number = double.tryParse(text.replaceAll(',', '.'));
    if (number == null || number < min || number > max) {
      return '$label nhập từ $min đến $max';
    }
    return null;
  }
}

Future<String?> textPrompt(
  BuildContext context,
  String title,
  String label, {
  String? explanation,
}) => showDialog<String>(
  context: context,
  builder: (_) =>
      _TextPromptDialog(title: title, label: label, explanation: explanation),
);

class _TextPromptDialog extends StatefulWidget {
  const _TextPromptDialog({
    required this.title,
    required this.label,
    this.explanation,
  });
  final String title, label;
  final String? explanation;
  @override
  State<_TextPromptDialog> createState() => _TextPromptDialogState();
}

class _TextPromptDialogState extends State<_TextPromptDialog> {
  final controller = TextEditingController();
  @override
  void dispose() {
    controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.explanation != null) Text(widget.explanation!),
        const SizedBox(height: 10),
        TextField(
          controller: controller,
          autofocus: true,
          decoration: InputDecoration(labelText: widget.label),
        ),
      ],
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Hủy'),
      ),
      FilledButton(
        onPressed: () => Navigator.pop(context, controller.text),
        child: const Text('Tiếp tục'),
      ),
    ],
  );
}

Future<void> showEmailVerification(
  BuildContext context,
  BabiController app,
) async {
  try {
    await app.refreshAccount();
    if (app.emailVerified) {
      if (context.mounted) {
        await showDialog<void>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Email đã xác thực'),
            content: const Text(
              'Tài khoản này đã xác thực email. Không cần gửi lại thư.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Đóng'),
              ),
            ],
          ),
        );
      }
      return;
    }
    final result = await app.api!.request('POST', 'auth/email/verification');
    if (result['email_verified'] == true) app.markEmailVerified();
    if (context.mounted) {
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Đã yêu cầu xác thực email'),
          content: const Text(
            'Hệ thống đã chuyển yêu cầu gửi liên kết xác thực tới email của bạn.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Đóng'),
            ),
          ],
        ),
      );
    }
  } catch (e) {
    if (context.mounted) showIssue(context, e);
  }
}

Future<void> showAcceptInvitation(
  BuildContext context,
  BabiController app,
) async {
  final token = await textPrompt(
    context,
    'Nhận lời mời gia đình',
    'Liên kết hoặc mã lời mời',
  );
  if (token == null || token.trim().isEmpty || !context.mounted) return;
  try {
    final link = FamilyAccessLink.parse(token);
    await Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (routeContext) => link.isJoin
            ? FamilyJoinScreen(
                app: app,
                token: link.token,
                onJoined: () => Navigator.of(routeContext).pop(),
              )
            : FamilyInvitationScreen(
                app: app,
                token: link.token,
                onFinished: () => Navigator.of(routeContext).pop(),
              ),
      ),
    );
  } catch (e) {
    if (context.mounted) showIssue(context, e);
  }
}
