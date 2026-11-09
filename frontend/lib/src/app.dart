import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import 'controller.dart';
import 'data/api_client.dart';
import 'models.dart';
import 'theme.dart';
import 'widgets.dart';
import 'screens/timeline.dart';
import 'screens/capture.dart';
import 'screens/preview.dart';
import 'screens/health.dart';
import 'screens/vaccinations.dart';
import 'screens/account.dart';
import 'screens/family.dart';
import 'screens/family_join.dart';
import 'screens/family_invitation.dart';
import 'screens/email_verification.dart';
import 'screens/password_reset.dart';
import 'platform/family_join_url.dart';

class BabiApp extends StatelessWidget {
  const BabiApp({super.key, this.controller, this.previewChrome = kIsWeb});
  final BabiController? controller;
  final bool previewChrome;
  @override
  Widget build(BuildContext context) => MaterialApp(
    title: 'Babi',
    debugShowCheckedModeBanner: false,
    theme: babiTheme(),
    home: BabiHome(controller: controller, previewChrome: previewChrome),
  );
}

class BabiHome extends StatefulWidget {
  const BabiHome({super.key, this.controller, required this.previewChrome});
  final BabiController? controller;
  final bool previewChrome;
  @override
  State<BabiHome> createState() => _BabiHomeState();
}

class _BabiHomeState extends State<BabiHome> {
  // iPhone 14/15 logical viewport for the desktop web demo.
  static const _phoneWidth = 390.0;
  static const _phoneHeight = 844.0;
  String? _familyJoinToken;
  String? _familyInviteToken;
  String? _emailVerifyUrl;
  String? _passwordResetToken;
  String? _passwordResetEmail;
  bool _familyJoinDismissed = false;
  late final BabiController app =
      widget.controller ??
      (const bool.fromEnvironment('BABI_DESIGN_PREVIEW')
          ? BabiController()
          : BabiController(seedDemo: false, api: ApiClient()));
  @override
  void initState() {
    super.initState();
    _familyJoinToken = Uri.base.queryParameters['familyJoin'];
    _familyInviteToken = Uri.base.queryParameters['familyInvite'];
    _emailVerifyUrl = Uri.base.queryParameters['emailVerify'];
    _passwordResetToken = Uri.base.queryParameters['passwordReset'];
    _passwordResetEmail = Uri.base.queryParameters['email'];
    if (app.connected) app.initialize();
  }

  @override
  void dispose() {
    if (widget.controller == null) {
      app.dispose();
    }
    super.dispose();
  }

  Future<void> _gallery() async {
    if (!app.canContribute || app.busy) {
      return;
    }
    try {
      final files = await ImagePicker().pickMultipleMedia();
      if (files.isEmpty) {
        return;
      }
      if (files.length + app.draft.length > BabiController.maxFiles) {
        throw FormatException('Chọn tối đa ${BabiController.maxFiles} tệp.');
      }
      final items = await Future.wait(files.map(MediaItem.fromFile));
      if (mounted) {
        app.addMedia(items);
      }
    } catch (e) {
      if (mounted) {
        showIssue(context, e);
      }
    }
  }

  void _babies() => showModalBottomSheet<void>(
    context: context,
    builder: (context) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (app.connected)
            ListTile(
              leading: const BabiIcon(Icons.add),
              title: const Text('Thêm hồ sơ bé'),
              onTap: () {
                Navigator.pop(context);
                showCreateBaby(this.context, app);
              },
            ),
          Text('Chọn hồ sơ bé', style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 12),
          for (var i = 0; i < app.babies.length; i++)
            ListTile(
              leading: app.babies[i].avatarUrl == null || !app.connected
                  ? const CircleAvatar(
                      backgroundColor: BabiColors.selected,
                      child: BabiIcon(Icons.child_care_rounded),
                    )
                  : ClipOval(
                      child: Image.network(
                        Uri.parse(app.api!.baseUrl)
                            .resolve(app.babies[i].avatarUrl!)
                            .toString(),
                        headers: app.api!.headers,
                        width: 40,
                        height: 40,
                        fit: BoxFit.cover,
                        errorBuilder: (_, _, _) => const Icon(Icons.child_care),
                      ),
                    ),
              title: Text(app.babies[i].name),
              trailing: app.babyIndex == i
                  ? const BabiIcon(Icons.check, color: BabiColors.primary)
                  : null,
              onTap: () {
                app.selectBaby(i);
                Navigator.pop(context);
              },
            ),
        ],
      ),
    ),
  );
  void _profile() => showModalBottomSheet<void>(
    context: context,
    builder: (context) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (app.connected) ...[
              Text(
                app.displayName,
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 8),
              Text(
                app.role == FamilyRole.owner
                    ? 'Người quản lý gia đình'
                    : 'Người thân · Đóng góp kỷ niệm',
              ),
              ListTile(
                title: const Text('Chọn hồ sơ bé'),
                onTap: () {
                  Navigator.pop(context);
                  _babies();
                },
              ),
              if (app.canCreateFamilySpace)
                ListTile(
                  title: const Text('Thêm hồ sơ bé'),
                  onTap: () {
                    Navigator.pop(context);
                    showCreateBaby(this.context, app);
                  },
                ),
              ListTile(
                title: const Text('Xác thực email'),
                onTap: () {
                  Navigator.pop(context);
                  showEmailVerification(this.context, app);
                },
              ),
              if (!app.ownsFamilySpace)
                ListTile(
                  title: const Text('Nhận lời mời gia đình'),
                  onTap: () {
                    Navigator.pop(context);
                    showAcceptInvitation(this.context, app);
                  },
                ),
              ListTile(
                title: const Text('Tải lại dữ liệu'),
                onTap: () async {
                  Navigator.pop(context);
                  try {
                    await app.refreshProfiles();
                  } catch (e) {
                    if (mounted) showIssue(this.context, e);
                  }
                },
              ),
              ListTile(
                title: const Text('Đăng xuất'),
                onTap: () async {
                  Navigator.pop(context);
                  try {
                    await app.logout();
                    if (mounted) {
                      PaintingBinding.instance.imageCache.clear();
                      PaintingBinding.instance.imageCache.clearLiveImages();
                    }
                  } catch (e) {
                    if (mounted) showIssue(this.context, e);
                  }
                },
              ),
            ] else ...[
              Text(
                'Gia đình Babi',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 8),
              const Text(
                'Bản xem thử · Dữ liệu chỉ giữ trong phiên đang mở, chưa đồng bộ lên máy chủ.',
              ),
              const SizedBox(height: 16),
              const Text('Xem thử vai trò'),
              const SizedBox(height: 8),
              SegmentedButton<FamilyRole>(
                segments: const [
                  ButtonSegment(value: FamilyRole.owner, label: Text('Owner')),
                  ButtonSegment(
                    value: FamilyRole.viewer,
                    label: Text('Viewer'),
                  ),
                ],
                selected: {app.role},
                onSelectionChanged: (v) {
                  app.setRole(v.first);
                  Navigator.pop(context);
                },
              ),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: () {
                  Navigator.pop(context);
                  _babies();
                },
                icon: const BabiIcon(Icons.child_care),
                label: const Text('Chọn hồ sơ bé'),
              ),
            ],
          ],
        ),
      ),
    ),
  );
  Widget _family() => app.connected
      ? FamilyScreen(app: app, onProfile: _profile)
      : Column(
          children: [
            PageHeader(
              title: 'Gia đình của bé',
              subtitle: 'Cùng lưu những điều mình thương',
              onProfile: _profile,
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                children: [
                  SurfaceCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Family Space',
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Bé ${app.baby.name}',
                          style: textStyle(16, line: 24),
                        ),
                        const SizedBox(height: 12),
                        for (final person in [
                          ('Mẹ Quỳnh', 'Owner'),
                          ('Ba Minh', 'Owner'),
                          ('Bà ngoại', 'Viewer'),
                        ])
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const CircleAvatar(
                              backgroundColor: BabiColors.selected,
                              child: BabiIcon(Icons.person_outline),
                            ),
                            title: Text(person.$1),
                            subtitle: Text(person.$2),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  const SurfaceCard(
                    child: Text(
                      'Owner quản lý dữ liệu. Người thân xem và đăng kỷ niệm; chỉ xem sức khỏe và lịch tiêm.',
                    ),
                  ),
                ],
              ),
            ),
          ],
        );
  Widget _page() => app.preview
      ? PreviewScreen(app: app, onGallery: _gallery, onBaby: _babies)
      : switch (app.tab) {
          AppTab.home => TimelineScreen(
            app: app,
            onProfile: _profile,
            onBaby: _babies,
          ),
          AppTab.capture => CaptureScreen(
            app: app,
            onGallery: _gallery,
            onBaby: _babies,
          ),
          AppTab.health => HealthScreen(app: app, onProfile: _profile),
          AppTab.vaccinations => VaccinationsScreen(
            app: app,
            onProfile: _profile,
          ),
          AppTab.family => _family(),
        };
  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: app,
    builder: (context, _) {
      if (app.connected && app.restoring) {
        return const Scaffold(body: Center(child: CircularProgressIndicator()));
      }
      if (app.connected && _passwordResetToken != null) {
        return PasswordResetScreen(
          app: app,
          token: _passwordResetToken!,
          email: _passwordResetEmail ?? '',
          onFinished: () => setState(() => _passwordResetToken = null),
        );
      }
      if (app.connected && !app.authenticated) {
        return AuthScreen(app: app, invitationFlow: _familyInviteToken != null);
      }
      if (app.connected && _emailVerifyUrl != null) {
        return EmailVerificationScreen(
          app: app,
          verificationUrl: _emailVerifyUrl!,
          returnJoinToken: _familyJoinToken,
          onFinished: () {
            removeEmailVerificationToken();
            setState(() => _emailVerifyUrl = null);
          },
        );
      }
      if (app.connected &&
          _familyInviteToken != null &&
          !_familyJoinDismissed) {
        return FamilyInvitationScreen(
          app: app,
          token: _familyInviteToken!,
          onFinished: () {
            removeFamilyAccessTokens();
            setState(() => _familyJoinDismissed = true);
          },
        );
      }
      if (app.connected && _familyJoinToken != null && !_familyJoinDismissed) {
        return FamilyJoinScreen(
          app: app,
          token: _familyJoinToken!,
          onJoined: () {
            removeFamilyAccessTokens();
            setState(() => _familyJoinDismissed = true);
          },
        );
      }
      if (app.connected && !app.hasBaby) return OnboardingScreen(app: app);
      return LayoutBuilder(
        builder: (context, box) {
          final preview = widget.previewChrome;
          final bottom = ArcNavigation(
            active: app.tab,
            viewer: !app.canContribute,
            disabled: app.busy,
            previewChrome: preview,
            onSelected: app.selectTab,
          );
          final page = Scaffold(
            resizeToAvoidBottomInset: true,
            backgroundColor: Colors.transparent,
            body: Stack(
              fit: StackFit.expand,
              children: [
                const LiquidGlassBackdrop(),
                Column(
                  children: [
                    if (preview) const _PreviewStatus(),
                    if (app.connected && app.loading)
                      const LinearProgressIndicator(minHeight: 2),
                    if (app.connected && app.issue != null)
                      MaterialBanner(
                        content: Text(app.issue!),
                        actions: [
                          TextButton(
                            onPressed: app.loading
                                ? null
                                : () => app.refreshSelected(),
                            child: const Text('Tải lại'),
                          ),
                        ],
                      ),
                    Expanded(
                      child: SafeArea(
                        top: !preview,
                        bottom: false,
                        child: _page(),
                      ),
                    ),
                  ],
                ),
              ],
            ),
            bottomNavigationBar: bottom,
          );
          if (!preview) {
            return page;
          }
          return ColoredBox(
            color: const Color(0xFFF0ECE7),
            child: Center(
              child: Container(
                width: math.min(box.maxWidth, _phoneWidth + 8),
                height: math.min(box.maxHeight, _phoneHeight + 8),
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(
                  gradient: const LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      Color(0xFFF5F1E9),
                      Color(0xFFB9B3AA),
                      Color(0xFFE9E4DC),
                      Color(0xFFAAA49B),
                    ],
                    stops: [0, .28, .62, 1],
                  ),
                  borderRadius: BorderRadius.circular(44),
                  border: Border.all(color: const Color(0xFFD8D2C9), width: .7),
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xFF4C4138).withValues(alpha: .2),
                      blurRadius: 20,
                      offset: const Offset(0, 9),
                    ),
                  ],
                ),
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(41),
                  child: ClipRect(child: page),
                ),
              ),
            ),
          );
        },
      );
    },
  );
}

/// Only shown in the web design preview. Native uses the actual OS safe areas.
class _PreviewStatus extends StatelessWidget {
  const _PreviewStatus();
  @override
  Widget build(BuildContext context) => SizedBox(
    height: 59,
    child: Stack(
      children: [
        Positioned(
          left: 24,
          top: 20,
          child: Text(
            '9:41',
            style: textStyle(15, line: 20, weight: FontWeight.w600),
          ),
        ),
        Align(
          alignment: Alignment.topCenter,
          child: Padding(
            padding: const EdgeInsets.only(top: 11),
            child: Container(
              width: 120,
              height: 32,
              decoration: BoxDecoration(
                color: BabiColors.ink,
                borderRadius: BorderRadius.circular(16),
              ),
            ),
          ),
        ),
        const Positioned(
          right: 24,
          top: 22,
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              BabiIcon(Icons.signal_cellular_alt, size: 14),
              SizedBox(width: 4),
              BabiIcon(Icons.battery_full, size: 16),
            ],
          ),
        ),
      ],
    ),
  );
}
