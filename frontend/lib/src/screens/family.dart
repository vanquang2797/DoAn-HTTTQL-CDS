import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../controller.dart';
import '../models.dart';
import '../data/api_client.dart';
import '../theme.dart';
import '../widgets.dart';

enum _MemberAction { demote, remove }

class FamilyScreen extends StatefulWidget {
  const FamilyScreen({super.key, required this.app, required this.onProfile});
  final BabiController app;
  final VoidCallback onProfile;

  @override
  State<FamilyScreen> createState() => _FamilyScreenState();
}

class _FamilyScreenState extends State<FamilyScreen> {
  String? _workingId;
  Map<String, dynamic>? _createdJoinLink;
  Future<void> _invite(String type) async {
    final delivery = await showDialog<String>(
      context: context,
      builder: (_) => _InviteDialog(app: widget.app, type: type),
    );
    if (delivery != null && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(_deliveryMessage(delivery, 'Lời mời đã được tạo')),
        ),
      );
    }
  }

  String _deliveryMessage(String delivery, String success) =>
      delivery == 'logged_locally'
      ? '$success. Máy chủ đang ghi email vào log, chưa gửi thư thật.'
      : '$success và đã chuyển email cho dịch vụ gửi thư.';

  Future<void> _createJoinLink() async {
    setState(() => _workingId = 'join-link');
    try {
      final data = await widget.app.createFamilyJoinLink();
      final uri = Uri.base.replace(
        queryParameters: {'familyJoin': '${data['token']}'},
      );
      final link = uri.toString();
      var copied = false;
      try {
        await Clipboard.setData(ClipboardData(text: link));
        copied = true;
      } catch (_) {
        // The token stays visible and selectable if clipboard access is blocked.
      }
      if (!mounted) return;
      setState(
        () => _createdJoinLink = {...data, 'url': link, 'copied': copied},
      );
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            copied
                ? 'Đã tạo link 7 ngày và sao chép vào clipboard.'
                : 'Đã tạo link. Chạm giữ đường dẫn bên dưới để sao chép.',
          ),
        ),
      );
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _workingId = null);
    }
  }

  Future<void> _revokeJoinLink(Map<String, dynamic> link) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Thu hồi link này?'),
        content: const Text(
          'Người chưa gửi yêu cầu sẽ không thể dùng link này nữa.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Giữ link'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Thu hồi'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    final id = '${link['id']}';
    setState(() => _workingId = 'join-link:$id');
    try {
      await widget.app.revokeFamilyJoinLink(id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Đã thu hồi link tham gia.')),
        );
      }
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _workingId = null);
    }
  }

  Future<void> _reviewJoinRequest(
    Map<String, dynamic> request, {
    required bool approve,
  }) async {
    if (approve) {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Duyệt thành viên?'),
          content: Text(
            '${request['name'] ?? request['email']} sẽ được cấp quyền người thân hồ sơ và dữ liệu của bé trong Family Space này.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Để sau'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Duyệt quyền người thân'),
            ),
          ],
        ),
      );
      if (confirmed != true || !mounted) return;
    }
    final id = '${request['id']}';
    setState(() => _workingId = 'join-request:$id');
    try {
      if (approve) {
        await widget.app.approveFamilyJoinRequest(id);
      } else {
        await widget.app.declineFamilyJoinRequest(id);
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              approve ? 'Đã duyệt thành viên.' : 'Đã từ chối yêu cầu.',
            ),
          ),
        );
      }
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _workingId = null);
    }
  }

  Future<void> _invitationAction(
    Map<String, dynamic> invitation, {
    required bool resend,
  }) async {
    final id = '${invitation['id']}';
    setState(() => _workingId = 'invitation:$id');
    try {
      if (resend) {
        final delivery = await widget.app.resendInvitation(id);
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(_deliveryMessage(delivery, 'Đã gửi lại lời mời')),
            ),
          );
        }
      } else {
        await widget.app.revokeInvitation(id);
        if (mounted) {
          ScaffoldMessenger.of(
            context,
          ).showSnackBar(const SnackBar(content: Text('Đã thu hồi lời mời')));
        }
      }
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _workingId = null);
    }
  }

  Future<void> _memberAction(
    Map<String, dynamic> member,
    _MemberAction action,
  ) async {
    final name = member['name'] as String? ?? 'thành viên này';
    final demote = action == _MemberAction.demote;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(demote ? 'Đổi quyền quản lý?' : 'Gỡ thành viên?'),
        content: Text(
          demote
              ? '$name sẽ chuyển từ Owner sang thành viên xem. Quyền này có thể ảnh hưởng khả năng thêm, sửa dữ liệu.'
              : '$name sẽ mất quyền truy cập vào dữ liệu gia đình này.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Hủy'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(demote ? 'Đổi quyền' : 'Gỡ thành viên'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    final id = '${member['id']}';
    setState(() => _workingId = 'member:$id');
    try {
      if (demote) {
        await widget.app.demoteMember(id);
      } else {
        await widget.app.removeMember(id);
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              demote ? 'Đã đổi quyền thành viên' : 'Đã gỡ thành viên',
            ),
          ),
        );
      }
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _workingId = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final app = widget.app;
    final ownId = '${app.user?['id']}';
    return Column(
      children: [
        PageHeader(
          title: 'Gia đình của bé',
          subtitle: '${app.familyMembers.length} thành viên · ${app.baby.name}',
          onProfile: widget.onProfile,
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
            children: [
              SurfaceCard(
                color: BabiColors.selected,
                child: Row(
                  children: [
                    const CircleAvatar(
                      radius: 26,
                      backgroundColor: Colors.white,
                      child: BabiIcon(
                        Icons.favorite_border_rounded,
                        color: BabiColors.primary,
                        size: 26,
                      ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Cùng chăm sóc ${app.baby.displayName}',
                            style: Theme.of(context).textTheme.titleMedium,
                          ),
                          const SizedBox(height: 4),
                          Text(
                            'Chia sẻ khoảnh khắc và thông tin chăm sóc với người thân.',
                            style: Theme.of(context).textTheme.bodySmall,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'Thành viên',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                  ),
                  if (app.loading || _workingId != null)
                    const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                ],
              ),
              const SizedBox(height: 8),
              SurfaceCard(
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 6,
                ),
                child: Column(
                  children: [
                    for (var i = 0; i < app.familyMembers.length; i++) ...[
                      if (i > 0) const Divider(height: 1),
                      _memberTile(
                        app.familyMembers[i],
                        ownId: ownId,
                        canManage: app.canCreate,
                      ),
                    ],
                    if (app.familyMembers.isEmpty)
                      const Padding(
                        padding: EdgeInsets.all(12),
                        child: Text('Chưa tải được danh sách thành viên.'),
                      ),
                  ],
                ),
              ),
              if (!app.canCreate) ...[
                const SizedBox(height: 16),
                FilledButton.icon(
                  key: const Key('share-family-link'),
                  onPressed: _workingId == null ? _createJoinLink : null,
                  icon: const Icon(Icons.link_rounded),
                  label: const Text('Sao chép link gia đình'),
                ),
                const Text(
                  'Gửi link này cho người thân. Người nhận đăng nhập, gửi yêu cầu và chờ Owner duyệt; link không tự cấp quyền.',
                ),
                if (_createdJoinLink != null)
                  SurfaceCard(
                    child: SelectableText('${_createdJoinLink!['url']}'),
                  ),
              ],
              if (app.canCreate) ...[
                const SizedBox(height: 16),
                Text(
                  'Mời thêm người thân',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                FilledButton.icon(
                  onPressed: _workingId == null
                      ? () => _invite('family_viewer')
                      : null,
                  icon: const BabiIcon(
                    Icons.send_outlined,
                    color: Colors.white,
                  ),
                  label: const Text('Mời người thân'),
                ),
                OutlinedButton.icon(
                  onPressed: _workingId == null
                      ? () => _invite('co_manager')
                      : null,
                  icon: const BabiIcon(Icons.group_add_outlined),
                  label: const Text('Mời đồng quản lý'),
                ),
                const SizedBox(height: 16),
                Text(
                  'Chia sẻ link Family Space',
                  style: Theme.of(context).textTheme.titleMedium,
                ),
                const SizedBox(height: 4),
                Text(
                  'Lời mời email: người nhận xác thực đúng email rồi tự chấp nhận quyền người thân hoặc đồng quản lý. Link Family Space: người thân gửi yêu cầu xem, bạn duyệt trước khi cấp quyền; hạn 7 ngày.',
                  style: Theme.of(context).textTheme.bodySmall
                      ?.copyWith(color: BabiColors.secondary),
                ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: _workingId == null ? _createJoinLink : null,
                  icon: const BabiIcon(Icons.link_rounded),
                  label: const Text('Tạo link mời người thân'),
                ),
                if (_createdJoinLink case final created?)
                  SurfaceCard(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Link mới · quyền người thân · ${created['copied'] == true ? 'đã sao chép' : 'giữ để sao chép'}',
                        ),
                        const SizedBox(height: 4),
                        Row(
                          children: [
                            Expanded(
                              child: SelectableText(
                                '${created['url']}',
                                maxLines: 2,
                              ),
                            ),
                            IconButton(
                              tooltip: 'Sao chép link',
                              onPressed: () async {
                                try {
                                  await Clipboard.setData(
                                    ClipboardData(text: '${created['url']}'),
                                  );
                                  if (!context.mounted) return;
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    const SnackBar(
                                      content: Text(
                                        'Đã sao chép link tham gia.',
                                      ),
                                    ),
                                  );
                                } catch (e) {
                                  if (context.mounted) showIssue(context, e);
                                }
                              },
                              icon: const BabiIcon(
                                Icons.copy_rounded,
                                size: 18,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                for (final link in app.familyJoinLinks) _joinLinkCard(link),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        'Yêu cầu tham gia',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                    ),
                    Text(
                      '${app.familyJoinRequests.where((r) => r['status'] == 'pending').length} chờ duyệt',
                    ),
                  ],
                ),
                if (app.familyJoinRequests.isEmpty)
                  const SurfaceCard(child: Text('Chưa có yêu cầu tham gia.'))
                else
                  for (final request in app.familyJoinRequests)
                    _joinRequestCard(request),
                const SizedBox(height: 16),
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        'Lời mời',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                    ),
                    IconButton(
                      tooltip: 'Tải lại lời mời',
                      onPressed: _workingId == null
                          ? () => app.refreshSelected()
                          : null,
                      icon: const BabiIcon(Icons.refresh_rounded),
                    ),
                  ],
                ),
                if (app.familyInvitations.isEmpty)
                  const SurfaceCard(child: Text('Chưa có lời mời đang chờ.'))
                else
                  for (final invitation in app.familyInvitations)
                    _invitationCard(invitation),
              ],
              const SizedBox(height: 12),
              const SurfaceCard(
                child: Text(
                  'Người thân có thể đăng ảnh/video và chia sẻ link gia đình. Owner duyệt yêu cầu của người nhận link trước khi cấp quyền. Sức khỏe và lịch tiêm chỉ được xem.',
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _memberTile(
    Map<String, dynamic> member, {
    required String ownId,
    required bool canManage,
  }) {
    final role = member['role'] as String? ?? 'viewer';
    final self = '${member['user_id']}' == ownId;
    final name = member['name'] as String? ?? 'Thành viên';
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: CircleAvatar(
        backgroundColor: role == 'owner'
            ? BabiColors.selected
            : const Color(0xFFE8F3EE),
        child: BabiIcon(
          role == 'owner'
              ? Icons.shield_outlined
              : Icons.person_outline_rounded,
          color: role == 'owner' ? BabiColors.primary : BabiColors.success,
        ),
      ),
      title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        '${role == 'owner' ? 'Owner · Quản lý' : 'Người thân · Đóng góp kỷ niệm'}${self ? ' · Bạn' : ''}${member['status'] == 'inactive' ? ' · Đã khóa' : ''}',
      ),
      trailing: canManage && !self
          ? PopupMenuButton<_MemberAction>(
              tooltip: 'Quản lý $name',
              enabled: _workingId == null,
              onSelected: (action) => _memberAction(member, action),
              itemBuilder: (_) => [
                if (role == 'owner')
                  const PopupMenuItem(
                    value: _MemberAction.demote,
                    child: Text('Chuyển thành quyền người thân'),
                  ),
                const PopupMenuItem(
                  value: _MemberAction.remove,
                  child: Text('Gỡ khỏi gia đình'),
                ),
              ],
            )
          : null,
    );
  }

  Widget _invitationCard(Map<String, dynamic> invitation) {
    final status = invitation['status'] as String? ?? 'pending';
    final pending = status == 'pending';
    final expires = DateTime.tryParse(
      invitation['expires_at'] as String? ?? '',
    );
    final type = invitation['invitation_type'] == 'co_manager'
        ? 'Đồng quản lý'
        : 'Người thân · Đóng góp kỷ niệm';
    final statusLabel = switch (status) {
      'pending' => 'Đang chờ',
      'accepted' => 'Đã chấp nhận',
      'declined' => 'Đã từ chối',
      'revoked' => 'Đã thu hồi',
      'expired' => 'Đã hết hạn',
      _ => status,
    };
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: SurfaceCard(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const BabiIcon(
                  Icons.mail_outline_rounded,
                  color: BabiColors.primary,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    invitation['email'] as String? ?? '',
                    style: Theme.of(context).textTheme.titleSmall,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Text(
                  statusLabel,
                  style: textStyle(
                    12,
                    line: 16,
                    weight: FontWeight.w600,
                    color: pending ? BabiColors.primary : BabiColors.secondary,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              '$type${expires == null ? '' : ' · Hạn ${calendarDate(expires)}'}',
            ),
            if (pending) ...[
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                children: [
                  TextButton.icon(
                    onPressed: _workingId == null
                        ? () => _invitationAction(invitation, resend: true)
                        : null,
                    icon: const BabiIcon(Icons.refresh_rounded, size: 18),
                    label: const Text('Gửi lại'),
                  ),
                  TextButton.icon(
                    onPressed: _workingId == null
                        ? () => _invitationAction(invitation, resend: false)
                        : null,
                    icon: const BabiIcon(Icons.close_rounded, size: 18),
                    label: const Text('Thu hồi'),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _joinLinkCard(Map<String, dynamic> link) {
    final status = link['status'] as String? ?? 'expired';
    final expires = DateTime.tryParse(link['expires_at'] as String? ?? '');
    final canRevoke = status == 'active';
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: SurfaceCard(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        child: ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const BabiIcon(
            Icons.link_rounded,
            color: BabiColors.primary,
          ),
          title: Text(
            'Link ${status == 'active'
                ? 'đang hoạt động'
                : status == 'revoked'
                ? 'đã thu hồi'
                : 'đã hết hạn'}',
          ),
          subtitle: Text(
            'Quyền xem${expires == null ? '' : ' · Hạn ${calendarDate(expires)}'}',
          ),
          trailing: canRevoke
              ? TextButton(
                  onPressed: _workingId == null
                      ? () => _revokeJoinLink(link)
                      : null,
                  child: const Text('Thu hồi'),
                )
              : null,
        ),
      ),
    );
  }

  Widget _joinRequestCard(Map<String, dynamic> request) {
    final pending = request['status'] == 'pending';
    final date = DateTime.tryParse(request['requested_at'] as String? ?? '');
    final id = '${request['id']}';
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: SurfaceCard(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const CircleAvatar(
                  radius: 18,
                  backgroundColor: BabiColors.selected,
                  child: BabiIcon(
                    Icons.person_outline_rounded,
                    color: BabiColors.primary,
                    size: 19,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${request['name'] ?? 'Người thân'}',
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      Text(
                        '${request['email'] ?? ''}',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                    ],
                  ),
                ),
                Text(
                  pending
                      ? 'Chờ duyệt'
                      : request['status'] == 'approved'
                      ? 'Đã duyệt'
                      : 'Đã từ chối',
                ),
              ],
            ),
            if (date != null)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  'Gửi ngày ${calendarDate(date)}',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              ),
            if (pending) ...[
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                children: [
                  FilledButton.tonalIcon(
                    onPressed: _workingId == null
                        ? () => _reviewJoinRequest(request, approve: true)
                        : null,
                    icon: const BabiIcon(Icons.check_rounded),
                    label: const Text('Duyệt quyền người thân'),
                  ),
                  TextButton.icon(
                    onPressed: _workingId == null
                        ? () => _reviewJoinRequest(request, approve: false)
                        : null,
                    icon: const BabiIcon(Icons.close_rounded, size: 18),
                    label: const Text('Từ chối'),
                  ),
                ],
              ),
            ],
            if (_workingId == 'join-request:$id')
              const LinearProgressIndicator(minHeight: 2),
          ],
        ),
      ),
    );
  }
}

class VaccinationReminderSettings extends StatefulWidget {
  const VaccinationReminderSettings({super.key, required this.app});
  final BabiController app;

  @override
  State<VaccinationReminderSettings> createState() => _VaccinationReminderSettingsState();
}

class _VaccinationReminderSettingsState extends State<VaccinationReminderSettings> {
  final _days = TextEditingController(text: '7');
  final _emails = TextEditingController();
  bool _loading = true;
  bool _saving = false;
  TimeOfDay _sendTime = const TimeOfDay(hour: 8, minute: 0);
  DateTime _startDate = DateUtils.dateOnly(DateTime.now());
  String? _error;
  String? _notice;
  int get _spaceId => int.parse(widget.app.baby.spaceId);
  String get _sendTimeLabel =>
      '${_sendTime.hour.toString().padLeft(2, '0')}:${_sendTime.minute.toString().padLeft(2, '0')}';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _days.dispose();
    _emails.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final response = await widget.app.api!.request('GET', 'family-spaces/$_spaceId/vaccination-reminder-settings');
      final data = response['data'] as Map<String, dynamic>;
      _days.text = (data['days_before'] as List).join(', ');
      _emails.text = (data['recipient_emails'] as List).join('\n');
      final time = (data['send_time'] as String? ?? '08:00').split(':');
      _sendTime = TimeOfDay(hour: int.parse(time[0]), minute: int.parse(time[1]));
      _startDate = DateTime.parse(data['start_date'] as String? ?? DateTime.now().toIso8601String().substring(0, 10));
    } catch (e) {
      _error = e.toString();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save() async {
    final days = _days.text.split(RegExp(r'[,;\s]+')).where((v) => v.isNotEmpty).map(int.tryParse).toList();
    if (days.isEmpty || days.any((d) => d == null || d < 1 || d > 30) || days.toSet().length != days.length) {
      setState(() => _error = 'Nhập 1–5 số ngày nhắc khác nhau, từ 1 đến 30 (ví dụ: 7 hoặc 14, 7).');
      return;
    }
    final emails = _emails.text.split(RegExp(r'[,;\s]+')).where((v) => v.isNotEmpty).map((e) => e.trim().toLowerCase()).toList();
    setState(() { _saving = true; _error = null; _notice = null; });
    try {
      await widget.app.api!.request('PUT', 'family-spaces/$_spaceId/vaccination-reminder-settings', body: {
        'days_before': days.cast<int>(), 'recipient_emails': emails,
        'send_time': '${_sendTime.hour.toString().padLeft(2, '0')}:${_sendTime.minute.toString().padLeft(2, '0')}',
        'start_date': '${_startDate.year.toString().padLeft(4, '0')}-${_startDate.month.toString().padLeft(2, '0')}-${_startDate.day.toString().padLeft(2, '0')}',
      });
      if (mounted) setState(() => _notice = 'Đã lưu cài đặt nhắc lịch tiêm.');
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => SurfaceCard(
    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Text('Nhắc lịch tiêm qua email', style: Theme.of(context).textTheme.titleMedium),
      const SizedBox(height: 6),
      const Text('Ngày bắt đầu là ngày sớm nhất hệ thống được gửi thư; chọn hôm nay để thử từ giờ đã đặt.'),
      ListTile(
        key: const Key('reminder-start-date'),
        contentPadding: EdgeInsets.zero,
        title: const Text('Ngày bắt đầu nhắc'),
        subtitle: Text('${_startDate.day.toString().padLeft(2, '0')}/${_startDate.month.toString().padLeft(2, '0')}/${_startDate.year}'),
        trailing: const Icon(Icons.calendar_month_outlined),
        onTap: _loading || _saving ? null : () async {
          final today = DateUtils.dateOnly(DateTime.now());
          final date = await showDatePicker(context: context, initialDate: _startDate.isBefore(today) ? today : _startDate, firstDate: today, lastDate: today.add(const Duration(days: 3650)));
          if (date != null && mounted) setState(() => _startDate = date);
        },
      ),
      const Text('Mặc định gửi trước lịch tiêm 7 ngày. Tự nhập số ngày muốn nhắc (1–30); có thể nhập nhiều mốc, cách nhau bằng dấu phẩy. Owner luôn nhận thư.'),
      const SizedBox(height: 10),
      TextField(controller: _days, enabled: !_loading && !_saving, decoration: const InputDecoration(labelText: 'Nhắc trước bao nhiêu ngày', hintText: '7 hoặc 14, 7')),
      ListTile(
        key: const Key('reminder-send-time'),
        contentPadding: EdgeInsets.zero, title: const Text('Giờ gửi'),
        subtitle: Text(_sendTimeLabel),
        trailing: const Icon(Icons.schedule),
        onTap: _loading || _saving ? null : () async {
          final time = await showTimePicker(
            context: context,
            initialTime: _sendTime,
            initialEntryMode: TimePickerEntryMode.input,
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(context).copyWith(alwaysUse24HourFormat: true),
              child: child!,
            ),
          );
          if (time != null && mounted) setState(() => _sendTime = time);
        },
      ),
      const SizedBox(height: 8),
      TextField(controller: _emails, enabled: !_loading && !_saving, minLines: 1, maxLines: 3, decoration: const InputDecoration(labelText: 'Email nhận thêm', hintText: 'nguoithan@example.com')),
      const Text('Tối đa 10 email. Các email nhận thông tin lịch tiêm của bé.'),
      if (_error != null) Text(_error!, style: const TextStyle(color: Colors.red)),
      if (_notice != null) Text(_notice!, style: const TextStyle(color: Colors.green)),
      const SizedBox(height: 8),
      FilledButton(onPressed: _loading || _saving ? null : _save, child: Text(_loading ? 'Đang tải…' : _saving ? 'Đang lưu…' : 'Lưu cài đặt nhắc')),
    ]),
  );
}

class _InviteDialog extends StatefulWidget {
  const _InviteDialog({required this.app, required this.type});
  final BabiController app;
  final String type;
  @override
  State<_InviteDialog> createState() => _InviteDialogState();
}

class _InviteDialogState extends State<_InviteDialog> {
  final email = TextEditingController();
  bool saving = false, savedButNotDelivered = false;
  String? error;
  @override
  void dispose() {
    email.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (saving || email.text.trim().isEmpty) return;
    setState(() {
      saving = true;
      error = null;
    });
    try {
      final delivery = await widget.app.invite(email.text.trim(), widget.type);
      if (mounted) Navigator.pop(context, delivery);
    } catch (e) {
      if (mounted) {
        setState(() {
          error = e.toString();
          savedButNotDelivered = e is ApiException && e.status == 503;
        });
      }
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(
      widget.type == 'co_manager' ? 'Mời đồng quản lý' : 'Mời người thân',
    ),
    content: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            widget.type == 'co_manager'
                ? 'Người nhận có toàn quyền Owner sau khi chấp nhận.'
                : 'Người nhận có thể xem và đăng kỷ niệm sau khi chấp nhận.',
          ),
          TextField(
            key: const Key('invite-email'),
            controller: email,
            enabled: !saving && !savedButNotDelivered,
            decoration: const InputDecoration(labelText: 'Email người nhận'),
            keyboardType: TextInputType.emailAddress,
          ),
          if (error != null) Text(error!),
          if (savedButNotDelivered)
            const Text(
              'Đóng hộp thoại và dùng Gửi lại trong danh sách Lời mời. Không cần tạo thêm lời mời.',
            ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: saving ? null : () => Navigator.pop(context),
        child: const Text('Đóng'),
      ),
      if (!savedButNotDelivered)
        FilledButton(
          onPressed: saving ? null : submit,
          child: Text(saving ? 'Đang gửi…' : 'Gửi lời mời'),
        ),
    ],
  );
}

