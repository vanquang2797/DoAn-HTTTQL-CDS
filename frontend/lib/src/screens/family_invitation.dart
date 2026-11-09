import 'package:flutter/material.dart';

import '../controller.dart';
import '../theme.dart';
import '../widgets.dart';
import 'account.dart';

class FamilyInvitationScreen extends StatefulWidget {
  const FamilyInvitationScreen({
    super.key,
    required this.app,
    required this.token,
    required this.onFinished,
  });

  final BabiController app;
  final String token;
  final VoidCallback onFinished;

  @override
  State<FamilyInvitationScreen> createState() => _FamilyInvitationScreenState();
}

class _FamilyInvitationScreenState extends State<FamilyInvitationScreen> {
  Map<String, dynamic>? _invitation;
  String? _error;
  bool _loading = true;
  bool _working = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final invitation = await widget.app.previewInvitation(widget.token);
      if (!mounted) return;
      setState(() => _invitation = invitation);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _respond({required bool accept}) async {
    setState(() => _working = true);
    try {
      if (accept) {
        await widget.app.acceptInvitation(widget.token);
      } else {
        await widget.app.declineInvitation(widget.token);
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            accept
                ? 'Đã chấp nhận lời mời. Gia đình đã được thêm vào tài khoản.'
                : 'Đã từ chối lời mời gia đình.',
          ),
        ),
      );
      widget.onFinished();
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _working = false);
    }
  }

  Future<void> _switchAccount() async {
    setState(() => _working = true);
    try {
      await widget.app.logout();
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _working = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _invitation;
    final matches = data?['email_matches'] == true;
    final verified = data?['email_verified'] == true;
    final inviterActive = data?['inviter_active'] == true;
    final alreadyMember = data?['already_member'] == true;
    final ownerConflict = data?['owner_conflict'] == true;
    final canRespond = data?['can_respond'] == true;
    final role = data?['role'] == 'owner'
        ? 'Đồng quản lý · quyền Owner'
        : 'Người thân · Xem và đăng kỷ niệm';
    final status = data?['status'] as String? ?? 'pending';
    final expired = status == 'expired';
    final finished =
        status == 'accepted' || status == 'declined' || status == 'revoked';

    return Scaffold(
      backgroundColor: Colors.transparent,
      body: Stack(
        fit: StackFit.expand,
        children: [
          const LiquidGlassBackdrop(),
          SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 430),
                child: SingleChildScrollView(
                  padding: const EdgeInsets.fromLTRB(20, 28, 20, 30),
                  child: GlassSurface(
                    borderRadius: 30,
                    padding: const EdgeInsets.fromLTRB(24, 28, 24, 24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Container(
                          width: 62,
                          height: 62,
                          decoration: BoxDecoration(
                            color: BabiColors.selected.withValues(alpha: .78),
                            shape: BoxShape.circle,
                            border: Border.all(color: Colors.white, width: 2),
                          ),
                          child: const Icon(
                            Icons.family_restroom_rounded,
                            color: BabiColors.primary,
                            size: 30,
                          ),
                        ),
                        const SizedBox(height: 16),
                        Text(
                          _loading
                              ? 'Đang kiểm tra lời mời'
                              : canRespond
                              ? 'Lời mời gia đình'
                              : expired
                              ? 'Lời mời đã hết hạn'
                              : finished
                              ? 'Lời mời đã kết thúc'
                              : 'Kiểm tra tài khoản',
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.titleLarge,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          _loading
                              ? 'Đang tải người mời và quyền được đề nghị.'
                              : data == null
                              ? 'Không thể tải chi tiết lời mời. Hãy thử tải lại hoặc nhập mã trong Hồ sơ.'
                              : 'Bạn được ${data['inviter_name']} mời tham gia Family Space “${data['family_name']}”.',
                          textAlign: TextAlign.center,
                          style: Theme.of(context).textTheme.bodyMedium
                              ?.copyWith(color: BabiColors.secondary),
                        ),
                        if (data != null) ...[
                          const SizedBox(height: 18),
                          _detailTile(
                            Icons.mail_outline_rounded,
                            '${data['invited_email']}',
                            'Email được mời',
                          ),
                          const SizedBox(height: 8),
                          _detailTile(
                            Icons.verified_user_outlined,
                            role,
                            'Quyền sau khi chấp nhận',
                          ),
                          const SizedBox(height: 8),
                          _detailTile(
                            Icons.schedule_rounded,
                            _expiryText(data['expires_at'] as String?),
                            'Thời hạn',
                          ),
                          const SizedBox(height: 14),
                          if (finished)
                            _notice(
                              _statusText(status),
                              Icons.info_outline_rounded,
                            )
                          else if (expired)
                            _notice(
                              'Liên kết đã hết hạn. Owner cần gửi lời mời mới.',
                              Icons.timer_off_outlined,
                            )
                          else if (!matches)
                            _notice(
                              'Đăng nhập bằng đúng email được mời (${data['invited_email']}) để tiếp tục.',
                              Icons.person_search_rounded,
                            )
                          else if (!verified)
                            _notice(
                              'Bấm Gửi email xác thực, mở thư để xác thực rồi quay lại lời mời và bấm Kiểm tra lại.',
                              Icons.mark_email_unread_outlined,
                            )
                          else if (!inviterActive)
                            _notice(
                              'Người mời không còn là Owner đang hoạt động. Hãy liên hệ họ để nhận lời mời mới.',
                              Icons.person_off_outlined,
                            )
                          else if (alreadyMember)
                            _notice(
                              'Tài khoản của bạn đã là thành viên trong Family Space này.',
                              Icons.group_outlined,
                            )
                          else if (ownerConflict)
                            _notice(
                              data['invitation_type'] == 'co_manager'
                                  ? 'Để trở thành Owner, tài khoản chưa được thuộc Family Space nào khác. Người thân đang tham gia nhiều gia đình không thể nhận quyền Owner.'
                                  : 'Owner chỉ truy cập và quản lý Family Space của mình. Lời mời này dành cho tài khoản người thân.',
                              Icons.admin_panel_settings_outlined,
                            )
                          else if (!canRespond)
                            _notice(
                              _error ?? 'Lời mời không còn khả dụng.',
                              Icons.info_outline_rounded,
                            ),
                          if (matches &&
                              !verified &&
                              !finished &&
                              !expired) ...[
                            TextButton.icon(
                              onPressed: _working
                                  ? null
                                  : () => showEmailVerification(
                                      context,
                                      widget.app,
                                    ),
                              icon: const Icon(Icons.mark_email_read_outlined),
                              label: const Text('Gửi email xác thực'),
                            ),
                          ],
                          if (!matches) ...[
                            const SizedBox(height: 8),
                            TextButton.icon(
                              onPressed: _working ? null : _switchAccount,
                              icon: const Icon(Icons.logout_rounded),
                              label: const Text('Đổi tài khoản'),
                            ),
                          ],
                        ],
                        if (_error != null && (data == null || canRespond)) ...[
                          const SizedBox(height: 8),
                          Text(
                            _error!,
                            textAlign: TextAlign.center,
                            style: const TextStyle(color: BabiColors.error),
                          ),
                        ],
                        if (_loading) ...[
                          const SizedBox(height: 22),
                          const CircularProgressIndicator(strokeWidth: 2),
                        ] else if (canRespond) ...[
                          const SizedBox(height: 20),
                          Row(
                            children: [
                              Expanded(
                                child: OutlinedButton.icon(
                                  onPressed: _working
                                      ? null
                                      : () => _respond(accept: false),
                                  icon: const Icon(Icons.close_rounded),
                                  label: const Text('Từ chối'),
                                ),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                child: FilledButton.icon(
                                  onPressed: _working
                                      ? null
                                      : () => _respond(accept: true),
                                  icon: const Icon(Icons.check_rounded),
                                  label: const Text('Chấp nhận'),
                                ),
                              ),
                            ],
                          ),
                        ] else ...[
                          const SizedBox(height: 20),
                          OutlinedButton.icon(
                            onPressed: _working || _loading ? null : _load,
                            icon: const Icon(Icons.refresh_rounded),
                            label: const Text('Kiểm tra lại'),
                          ),
                          if (finished || expired)
                            TextButton(
                              onPressed: widget.onFinished,
                              child: const Text('Đóng'),
                            ),
                        ],
                        const SizedBox(height: 18),
                        const Text(
                          'Chỉ khi bạn chấp nhận, Family Space mới được thêm vào tài khoản. Quyền được cấp đúng theo lời mời; dữ liệu chưa được chia sẻ trước đó.',
                          textAlign: TextAlign.center,
                          style: TextStyle(
                            fontSize: 12,
                            height: 1.45,
                            color: BabiColors.secondary,
                          ),
                        ),
                        if (_working) ...[
                          const SizedBox(height: 14),
                          const LinearProgressIndicator(minHeight: 2),
                        ],
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _detailTile(IconData icon, String title, String caption) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
    decoration: BoxDecoration(
      color: Colors.white.withValues(alpha: .42),
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: Colors.white.withValues(alpha: .86)),
    ),
    child: Row(
      children: [
        Icon(icon, size: 19, color: BabiColors.primary),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.bodyMedium
                    ?.copyWith(fontWeight: FontWeight.w600),
              ),
              Text(caption, style: Theme.of(context).textTheme.bodySmall),
            ],
          ),
        ),
      ],
    ),
  );

  Widget _notice(String text, IconData icon) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: BabiColors.selected.withValues(alpha: .58),
      borderRadius: BorderRadius.circular(16),
      border: Border.all(color: Colors.white.withValues(alpha: .82)),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 18, color: BabiColors.primary),
        const SizedBox(width: 8),
        Expanded(
          child: Text(text, style: Theme.of(context).textTheme.bodySmall),
        ),
      ],
    ),
  );

  String _statusText(String status) => switch (status) {
    'accepted' => 'Lời mời này đã được chấp nhận trước đó.',
    'declined' => 'Lời mời này đã bị từ chối trước đó.',
    'revoked' => 'Owner đã thu hồi lời mời này.',
    _ => 'Lời mời này không còn chờ phản hồi.',
  };

  String _expiryText(String? value) {
    final date = DateTime.tryParse(value ?? '')?.toLocal();
    if (date == null) return 'Có hạn 7 ngày';
    return 'Đến ${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year}';
  }
}
