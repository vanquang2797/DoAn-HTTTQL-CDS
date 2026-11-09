import 'package:flutter/material.dart';

import '../controller.dart';
import '../theme.dart';
import '../widgets.dart';

class FamilyJoinScreen extends StatefulWidget {
  const FamilyJoinScreen({
    super.key,
    required this.app,
    required this.token,
    required this.onJoined,
  });

  final BabiController app;
  final String token;
  final VoidCallback onJoined;

  @override
  State<FamilyJoinScreen> createState() => _FamilyJoinScreenState();
}

class _FamilyJoinScreenState extends State<FamilyJoinScreen> {
  String? _requestId;
  String? _status;
  bool _working = false;
  bool _loading = true;
  bool _alreadyMember = false;
  bool _emailVerified = false;
  bool _ownerConflict = false;
  String? _error;
  String? _notice;
  int? _spaceId;
  Future<void> _sendVerification() async {
    setState(() => _working = true);
    try {
      final result = await widget.app.api!.request(
        'POST',
        'auth/email/verification',
        body: {'family_join_token': widget.token},
      );
      if (result['email_verified'] == true) {
        await _load();
        return;
      }
      if (mounted) {
        setState(
          () => _notice = 'Đã yêu cầu gửi thư xác thực. Mở thư mới nhất để xác thực rồi tiếp tục xin tham gia gia đình.',
        );
      }
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _working = false);
    }
  }

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
      final result = await widget.app.previewFamilyJoin(widget.token);
      if (!mounted) return;
      setState(() {
        _requestId = result['id']?.toString();
        _status = result['status'] as String?;
        _alreadyMember = result['already_member'] == true;
        _emailVerified = result['email_verified'] == true;
        _ownerConflict = result['owner_conflict'] == true;
        _spaceId = (result['family_space_id'] as num?)?.toInt();
      });
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _requestAccess() async {
    setState(() => _working = true);
    try {
      final result = await widget.app.requestFamilyJoin(widget.token);
      if (!mounted) return;
      setState(() {
        _requestId = result['id']?.toString();
        _status = result['status'] as String? ?? 'pending';
        _alreadyMember = result['already_member'] == true;
        _spaceId = (result['family_space_id'] as num?)?.toInt();
      });
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _working = false);
    }
  }

  Future<void> _checkStatus() async {
    final id = _requestId;
    if (id == null) return;
    setState(() => _working = true);
    try {
      final result = await widget.app.familyJoinRequestStatus(id);
      if (!mounted) return;
      setState(() => _status = result['status'] as String?);
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _working = false);
    }
  }

  Future<void> _openFamily() async {
    setState(() => _working = true);
    try {
      await widget.app.refreshProfiles();
      final index = widget.app.babies.indexWhere(
        (baby) => baby.spaceId == _spaceId?.toString(),
      );
      if (index >= 0) widget.app.selectBaby(index);
      if (!mounted) return;
      widget.onJoined();
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _working = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final approved = _status == 'approved';
    final pending = _status == 'pending';
    return Scaffold(
      backgroundColor: Colors.transparent,
      body: Stack(
        fit: StackFit.expand,
        children: [
          const LiquidGlassBackdrop(),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 14, 20, 24),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Family Space',
                    style: Theme.of(context).textTheme.labelLarge,
                  ),
                  const Spacer(),
                  Center(
                    child: SurfaceCard(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const CircleAvatar(
                            radius: 30,
                            backgroundColor: BabiColors.selected,
                            child: BabiIcon(
                              Icons.family_restroom_rounded,
                              color: BabiColors.primary,
                              size: 30,
                            ),
                          ),
                          const SizedBox(height: 16),
                          Text(
                            _loading
                                ? 'Đang kiểm tra liên kết'
                                : _alreadyMember
                                ? 'Bạn đã là thành viên'
                                : approved
                                ? 'Bạn đã được duyệt'
                                : pending
                                ? 'Đang chờ duyệt'
                                : 'Xin tham gia gia đình',
                            textAlign: TextAlign.center,
                            style: Theme.of(context).textTheme.titleLarge,
                          ),
                          const SizedBox(height: 10),
                          Text(
                            _error != null
                                ? _error!
                                : _alreadyMember
                                ? 'Tài khoản này đã thuộc gia đình. Bạn không cần gửi yêu cầu hoặc chờ duyệt lại.'
                                : approved
                                ? 'Yêu cầu đã được chấp thuận. Bạn có thể xem hồ sơ bé và dữ liệu gia đình.'
                                : pending
                                ? 'Yêu cầu đã gửi. Bạn chưa xem được dữ liệu cho đến khi Owner duyệt.'
                                : 'Gửi yêu cầu bằng tài khoản đã xác thực email. Người quản lý gia đình sẽ duyệt trước khi bạn được xem dữ liệu.',
                            textAlign: TextAlign.center,
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(color: BabiColors.secondary),
                          ),
                          const SizedBox(height: 20),
                          if (_loading)
                            const CircularProgressIndicator()
                          else if (_error != null)
                            OutlinedButton(
                              onPressed: _load,
                              child: const Text('Kiểm tra lại'),
                            )
                          else if (approved)
                            FilledButton.icon(
                              onPressed: _working ? null : _openFamily,
                              icon: const BabiIcon(
                                Icons.arrow_forward_rounded,
                                color: Colors.white,
                              ),
                              label: const Text('Vào gia đình'),
                            )
                          else if (pending)
                            OutlinedButton.icon(
                              onPressed: _working ? null : _checkStatus,
                              icon: const BabiIcon(Icons.refresh_rounded),
                              label: const Text('Kiểm tra trạng thái'),
                            )
                          else if (_ownerConflict)
                            const Text(
                              'Tài khoản đang là Owner của gia đình khác, không thể gửi yêu cầu tham gia.',
                            )
                          else if (!_emailVerified) ...[
                            const Text(
                              'Hãy xác thực email trước khi gửi yêu cầu tham gia.',
                            ),
                            FilledButton(
                              onPressed: _working ? null : _sendVerification,
                              child: const Text('Gửi email xác thực'),
                            ),
                            TextButton(
                              onPressed: _working ? null : _load,
                              child: const Text(
                                'Tôi đã xác thực — kiểm tra lại',
                              ),
                            ),
                            if (_notice != null) Text(_notice!),
                          ] else
                            FilledButton.icon(
                              onPressed: _working ? null : _requestAccess,
                              icon: const BabiIcon(
                                Icons.send_rounded,
                                color: Colors.white,
                              ),
                              label: const Text('Gửi yêu cầu tham gia'),
                            ),
                          TextButton(
                            onPressed: _working ? null : widget.onJoined,
                            child: const Text('Đóng liên kết'),
                          ),
                          if (_working) ...[
                            const SizedBox(height: 14),
                            const CircularProgressIndicator(strokeWidth: 2),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const Spacer(),
                  const Text(
                    'Liên kết chỉ cho phép gửi yêu cầu. Quyền xem chỉ được cấp sau khi Owner duyệt.',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 12, color: BabiColors.secondary),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
