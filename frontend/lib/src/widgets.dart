import 'package:flutter/material.dart';

import 'dart:ui';

import 'package:flutter_svg/flutter_svg.dart';
import 'package:video_player/video_player.dart';

import 'models.dart';
import 'data/api_client.dart';
import 'theme.dart';
import 'platform/video_controller.dart';

/// Keep the same icon family as the Figma source, including rounded ligatures.
class BabiIcon extends StatelessWidget {
  const BabiIcon(this.icon, {super.key, this.size, this.color});
  final IconData? icon;
  final double? size;
  final Color? color;
  static final names = <IconData, String>{
    Icons.photo_camera_outlined: 'photo_camera',
    Icons.home_outlined: 'home',
    Icons.vaccines_outlined: 'vaccines',
    Icons.group_outlined: 'group',
    Icons.stacked_line_chart_rounded: 'monitoring',
    Icons.person_outline_rounded: 'person',
    Icons.person_outline: 'person',
    Icons.child_care_rounded: 'child_care',
    Icons.child_care: 'child_care',
    Icons.expand_more: 'expand_more',
    Icons.flash_on_outlined: 'flash_on',
    Icons.flash_off_outlined: 'flash_off',
    Icons.cameraswitch_outlined: 'cameraswitch',
    Icons.arrow_back_rounded: 'arrow_back',
    Icons.send_outlined: 'send',
    Icons.close_rounded: 'close',
    Icons.edit_outlined: 'edit',
    Icons.delete_outline: 'delete',
    Icons.add: 'add',
    Icons.auto_awesome_outlined: 'auto_awesome',
    Icons.check: 'check',
    Icons.error_outline: 'error',
    Icons.warning_amber_rounded: 'warning',
    Icons.schedule: 'schedule',
    Icons.check_circle_outline: 'check_circle',
    Icons.calendar_today_outlined: 'calendar_today',
    Icons.event_note_outlined: 'event_note',
    Icons.favorite_border_rounded: 'favorite',
    Icons.play_circle_outline: 'play_circle',
    Icons.play_arrow: 'play_arrow',
    Icons.pause: 'pause',
    Icons.signal_cellular_alt: 'signal_cellular_alt',
    Icons.battery_full: 'battery_full',
  };
  @override
  Widget build(BuildContext context) {
    final name = names[icon];
    if (name == null) {
      return Icon(icon, size: size, color: color);
    }
    final theme = IconTheme.of(context), dimension = size ?? theme.size ?? 24;
    return ExcludeSemantics(
      child: SizedBox.square(
        dimension: dimension,
        child: Center(
          child: Text(
            name,
            textScaler: TextScaler.noScaling,
            maxLines: 1,
            overflow: TextOverflow.visible,
            style: TextStyle(
              fontFamily: 'Material Symbols Rounded',
              fontSize: dimension,
              height: 1,
              color: color ?? theme.color,
              fontFeatures: const [FontFeature.enable('liga')],
              fontVariations: const [
                FontVariation('FILL', 0),
                FontVariation('wght', 400),
                FontVariation('GRAD', 0),
                FontVariation('opsz', 24),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

void showIssue(BuildContext context, Object error) {
  final message = error is ApiException
      ? error.message
      : error is FormatException
      ? error.message
      : error is StateError
      ? error.message
      : 'Không thể hoàn tất thao tác. Vui lòng thử lại.';
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
}

class FigmaArt extends StatelessWidget {
  const FigmaArt(
    this.asset, {
    super.key,
    this.width,
    this.height,
    this.fit = BoxFit.fill,
  });
  final String asset;
  final double? width, height;
  final BoxFit fit;
  @override
  Widget build(BuildContext context) =>
      SvgPicture.asset(asset, width: width, height: height, fit: fit);
}

class WhiteIconButton extends StatelessWidget {
  const WhiteIconButton({
    super.key,
    required this.icon,
    required this.label,
    this.onPressed,
    this.size = 44,
    this.radius = 14,
  });
  final IconData icon;
  final String label;
  final VoidCallback? onPressed;
  final double size, radius;
  @override
  Widget build(BuildContext context) => SizedBox(
    width: size < 44 ? 44 : size,
    height: size < 44 ? 44 : size,
    child: Center(
      child: SizedBox.square(
        dimension: size,
        child: IconButton(
          onPressed: onPressed,
          tooltip: label,
          padding: EdgeInsets.zero,
          style: IconButton.styleFrom(
            backgroundColor: BabiColors.glassWhite,
            foregroundColor: BabiColors.ink,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(radius),
              side: const BorderSide(color: BabiColors.glassBorder),
            ),
            elevation: 2,
            shadowColor: BabiColors.ink.withValues(alpha: .08),
          ),
          icon: BabiIcon(icon, size: 24),
        ),
      ),
    ),
  );
}

class PageHeader extends StatelessWidget {
  const PageHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.onProfile,
    this.onTitle,
    this.leading,
    this.compact = false,
  });
  final String title;
  final String? subtitle;
  final VoidCallback? onProfile, onTitle;
  final Widget? leading;
  final bool compact;
  @override
  Widget build(BuildContext context) => SizedBox(
    height: compact ? 60 : 70,
    child: Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Row(
        children: [
          if (leading != null) ...[leading!, const SizedBox(width: 12)],
          Expanded(
            child: GestureDetector(
              onTap: onTitle,
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Flexible(
                        child: Text(
                          title,
                          style: textStyle(
                            compact ? 19 : 22,
                            line: compact ? 24 : 28,
                            weight: FontWeight.w600,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      if (onTitle != null)
                        const BabiIcon(Icons.expand_more, size: 20),
                    ],
                  ),
                  if (subtitle != null) ...[
                    SizedBox(height: compact ? 2 : 4),
                    Text(
                      subtitle!,
                      style: textStyle(
                        compact ? 11 : 13,
                        line: compact ? 15 : 18,
                        color: BabiColors.secondary,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ],
              ),
            ),
          ),
          if (onProfile != null) ...[
            const SizedBox(width: 16),
            WhiteIconButton(
              icon: Icons.person_outline_rounded,
              label: 'Hồ sơ và vai trò',
              onPressed: onProfile,
              size: compact ? 36 : 40,
            ),
          ],
        ],
      ),
    ),
  );
}

class SurfaceCard extends StatelessWidget {
  const SurfaceCard({
    super.key,
    required this.child,
    this.color = const Color(0xBFFFFFFF),
    this.padding = const EdgeInsets.all(16),
  });
  final Widget child;
  final Color color;
  final EdgeInsets padding;
  @override
  Widget build(BuildContext context) => ClipRRect(
    borderRadius: BorderRadius.circular(24),
    child: BackdropFilter(
      filter: ImageFilter.blur(sigmaX: 24, sigmaY: 24),
      child: Container(
        width: double.infinity,
        padding: padding,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              color.withValues(alpha: .16),
              Color.lerp(
                color,
                BabiColors.glassTint,
                .55,
              )!.withValues(alpha: .10),
            ],
          ),
          borderRadius: BorderRadius.circular(24),
          border: Border.all(
            color: Colors.white.withValues(alpha: .96),
            width: 1.4,
          ),
          boxShadow: [
            BoxShadow(
              color: BabiColors.ink.withValues(alpha: .09),
              blurRadius: 22,
              offset: const Offset(0, 9),
            ),
            const BoxShadow(
              color: Colors.white,
              blurRadius: 2,
              offset: Offset(0, 1),
            ),
          ],
        ),
        child: child,
      ),
    ),
  );
}

class OverdueVaccinationNotice extends StatelessWidget {
  const OverdueVaccinationNotice({
    super.key,
    required this.count,
    this.onReview,
  });

  final int count;
  final VoidCallback? onReview;

  @override
  Widget build(BuildContext context) => SurfaceCard(
    color: const Color(0xFFFCE8E6),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Icon(Icons.notification_important_outlined, color: BabiColors.error),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                '$count mũi tiêm đã qua ngày dự kiến',
                style: Theme.of(context).textTheme.titleMedium?.copyWith(color: BabiColors.error),
              ),
            ),
          ],
        ),
        const SizedBox(height: 6),
        const Text(
          'Hãy kiểm tra sổ tiêm. Nếu bé đã tiêm, mở mũi bên dưới để ghi nhận ngày tiêm; nếu chưa tiêm và chưa xác nhận, hãy cập nhật trạng thái. Ngày dự kiến không tự xác nhận bé đã tiêm hay chưa.',
        ),
        if (onReview != null) ...[
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              onPressed: onReview,
              icon: const Icon(Icons.vaccines_outlined, size: 18),
              label: const Text('Rà soát lịch tiêm'),
            ),
          ),
        ],
      ],
    ),
  );
}

/// Warm pastel atmosphere used by the shared mobile shell.
class LiquidGlassBackdrop extends StatelessWidget {
  const LiquidGlassBackdrop({super.key});

  @override
  Widget build(BuildContext context) => Stack(
    fit: StackFit.expand,
    children: [
      const DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [Color(0xFFFFF5F0), Color(0xFFFFF8F1), Color(0xFFFFF4E5)],
          ),
        ),
      ),
      Positioned(
        top: -90,
        left: -70,
        child: _Glow(color: BabiColors.blush.withValues(alpha: 1), size: 420),
      ),
      Positioned(
        top: 220,
        left: -145,
        child: _Glow(color: BabiColors.mint.withValues(alpha: .98), size: 450),
      ),
      Positioned(
        right: -150,
        bottom: 80,
        child: _Glow(
          color: const Color(0xFFFFD86F).withValues(alpha: .95),
          size: 450,
        ),
      ),
      Positioned(
        top: 280,
        right: -170,
        child: _Glow(
          color: const Color(0xFFD8CEFF).withValues(alpha: .62),
          size: 300,
        ),
      ),
    ],
  );
}

class _Glow extends StatelessWidget {
  const _Glow({required this.color, required this.size});
  final Color color;
  final double size;
  @override
  Widget build(BuildContext context) => ImageFiltered(
    imageFilter: ImageFilter.blur(sigmaX: 42, sigmaY: 42),
    child: Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    ),
  );
}

class GlassSurface extends StatelessWidget {
  const GlassSurface({
    super.key,
    required this.child,
    this.borderRadius = 24,
    this.padding,
    this.tint = BabiColors.glassWhite,
  });
  final Widget child;
  final double borderRadius;
  final EdgeInsetsGeometry? padding;
  final Color tint;

  @override
  Widget build(BuildContext context) => ClipRRect(
    borderRadius: BorderRadius.circular(borderRadius),
    child: BackdropFilter(
      filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
      child: Container(
        padding: padding,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [tint.withValues(alpha: .64), tint.withValues(alpha: .34)],
          ),
          borderRadius: BorderRadius.circular(borderRadius),
          border: Border.all(color: BabiColors.glassBorder, width: 1.2),
          boxShadow: [
            BoxShadow(
              color: BabiColors.ink.withValues(alpha: .07),
              blurRadius: 22,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: child,
      ),
    ),
  );
}

class ArcNavigation extends StatelessWidget {
  const ArcNavigation({
    super.key,
    required this.active,
    required this.viewer,
    required this.onSelected,
    this.disabled = false,
    this.previewChrome = false,
  });
  final AppTab active;
  final bool viewer, disabled, previewChrome;
  final ValueChanged<AppTab> onSelected;
  @override
  Widget build(BuildContext context) {
    final bottom = previewChrome ? 34.0 : MediaQuery.paddingOf(context).bottom;
    final tabs = viewer
        ? [AppTab.vaccinations, AppTab.home, AppTab.family, AppTab.health]
        : AppTab.values;
    final labels = {
      AppTab.vaccinations: 'Lịch tiêm',
      AppTab.home: 'Trang chủ',
      AppTab.family: 'Gia đình',
      AppTab.health: 'Sức khỏe',
    };
    final icons = {
      AppTab.vaccinations: Icons.vaccines_outlined,
      AppTab.home: Icons.home_outlined,
      AppTab.family: Icons.group_outlined,
      AppTab.health: Icons.stacked_line_chart_rounded,
    };
    return Container(
      // Extend a solid backing all the way through the home-indicator safe
      // area. A translucent rounded surface here exposed triangular gaps at
      // the phone's lower corners in the desktop iPhone preview.
      color: BabiColors.cream,
      height: 76 + bottom,
      child: Column(
        children: [
          ClipRRect(
            borderRadius: const BorderRadius.vertical(top: Radius.circular(28)),
            child: BackdropFilter(
              filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20),
              child: Container(
                height: 76,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                    colors: [
                      Colors.white.withValues(alpha: .62),
                      BabiColors.cream.withValues(alpha: .5),
                    ],
                  ),
                  border: Border(
                    top: BorderSide(
                      color: Colors.white.withValues(alpha: .9),
                      width: 1.4,
                    ),
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: BabiColors.ink.withValues(alpha: .08),
                      blurRadius: 24,
                      offset: const Offset(0, -7),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    for (final tab in tabs)
                      Expanded(
                        child: tab == AppTab.capture
                            ? _CaptureNavigationItem(
                                active: active == tab,
                                disabled: disabled,
                                onTap: () => onSelected(tab),
                              )
                            : _NavigationItem(
                                key: Key('nav-${tab.name}'),
                                label: labels[tab]!,
                                icon: icons[tab]!,
                                selected: active == tab,
                                disabled: disabled,
                                onTap: () => onSelected(tab),
                              ),
                      ),
                  ],
                ),
              ),
            ),
          ),
          Container(
            height: bottom,
            color: BabiColors.cream,
            alignment: Alignment.topCenter,
            child: previewChrome
                ? Padding(
                    padding: const EdgeInsets.only(top: 20),
                    child: Container(
                      width: 134,
                      height: 5,
                      decoration: BoxDecoration(
                        color: BabiColors.ink,
                        borderRadius: BorderRadius.circular(3),
                      ),
                    ),
                  )
                : null,
          ),
        ],
      ),
    );
  }
}

class _NavigationItem extends StatelessWidget {
  const _NavigationItem({
    super.key,
    required this.label,
    required this.icon,
    required this.selected,
    required this.disabled,
    required this.onTap,
  });
  final String label;
  final IconData icon;
  final bool selected, disabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    selected: selected,
    child: InkWell(
      onTap: disabled ? null : onTap,
      borderRadius: BorderRadius.circular(18),
      child: SizedBox(
        height: 68,
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              width: 38,
              height: 30,
              decoration: BoxDecoration(
                color: selected ? BabiColors.selected : Colors.transparent,
                borderRadius: BorderRadius.circular(15),
              ),
              alignment: Alignment.center,
              child: BabiIcon(
                icon,
                size: 21,
                color: selected ? BabiColors.ink : BabiColors.secondary,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              label,
              textScaler: TextScaler.noScaling,
              style: textStyle(
                10,
                line: 13,
                color: selected ? BabiColors.ink : BabiColors.secondary,
                weight: selected ? FontWeight.w500 : FontWeight.w400,
              ),
              maxLines: 1,
              overflow: TextOverflow.fade,
              softWrap: false,
            ),
          ],
        ),
      ),
    ),
  );
}

class _CaptureNavigationItem extends StatelessWidget {
  const _CaptureNavigationItem({
    required this.active,
    required this.disabled,
    required this.onTap,
  });
  final bool active, disabled;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Semantics(
    label: 'Chụp',
    button: true,
    selected: active,
    child: SizedBox(
      height: 68,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          SizedBox.square(
            dimension: 44,
            child: IconButton(
              key: const Key('nav-capture'),
              onPressed: disabled ? null : onTap,
              tooltip: 'Chụp',
              style: IconButton.styleFrom(
                backgroundColor: BabiColors.primary,
                foregroundColor: BabiColors.ink,
                padding: EdgeInsets.zero,
              ),
              icon: const BabiIcon(Icons.photo_camera_outlined, size: 21),
            ),
          ),
          const SizedBox(height: 1),
          Text(
            'Chụp',
            textScaler: TextScaler.noScaling,
            style: textStyle(9, line: 12, color: BabiColors.secondary),
          ),
        ],
      ),
    ),
  );
}

class MediaView extends StatelessWidget {
  const MediaView(this.item, {super.key, this.interactive = false});
  final MediaItem item;
  final bool interactive;
  @override
  Widget build(BuildContext context) {
    if (item.asset != null) {
      return FigmaArt(item.asset!);
    }
    if (item.url != null) {
      if (item.isVideo) {
        return interactive
            ? VideoPreview(
                item.url!,
                headers: item.headers,
                remote: true,
                mimeType: item.mimeType,
              )
            : const ColoredBox(
                color: BabiColors.ink,
                child: Center(
                  child: BabiIcon(
                    Icons.play_circle_outline,
                    size: 56,
                    color: Colors.white,
                  ),
                ),
              );
      }
      return Image.network(
        item.url!,
        headers: item.headers,
        fit: BoxFit.cover,
        errorBuilder: (_, _, _) => const Center(
          child: Text('Không thể tải ảnh. Hãy kiểm tra quyền và tải lại.'),
        ),
      );
    }
    if (item.isVideo) {
      if (interactive && item.path != null) {
        return VideoPreview(item.path!);
      }
      return const ColoredBox(
        color: BabiColors.ink,
        child: Center(
          child: BabiIcon(
            Icons.play_circle_outline,
            size: 56,
            color: Colors.white,
          ),
        ),
      );
    }
    return Image.memory(
      item.bytes!,
      fit: BoxFit.cover,
      gaplessPlayback: true,
      errorBuilder: (_, _, _) =>
          const Center(child: Text('Không thể xem ảnh này')),
    );
  }
}

class VideoPreview extends StatefulWidget {
  const VideoPreview(
    this.path, {
    super.key,
    this.headers = const {},
    this.remote = false,
    this.mimeType,
  });
  final String path;
  final Map<String, String> headers;
  final bool remote;
  final String? mimeType;
  @override
  State<VideoPreview> createState() => _VideoPreviewState();
}

class _VideoPreviewState extends State<VideoPreview> {
  VideoPlayerController? _player;
  bool failed = false;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final player = widget.remote
          ? await remoteVideoController(
              widget.path,
              widget.headers,
              mimeType: widget.mimeType,
            )
          : videoController(widget.path);
      if (!mounted) {
        releaseRemoteVideo(player.dataSource);
        await player.dispose();
        return;
      }
      _player = player;
      await player.initialize();
      if (mounted) {
        setState(() {});
      }
    } catch (_) {
      if (mounted) {
        setState(() => failed = true);
      }
    }
  }

  @override
  void dispose() {
    if (_player != null) {
      releaseRemoteVideo(_player!.dataSource);
      _player!.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ColoredBox(
    color: BabiColors.ink,
    child: failed
        ? const Center(
            child: Text(
              'Không thể phát video',
              style: TextStyle(color: Colors.white),
            ),
          )
        : _player == null || !_player!.value.isInitialized
        ? const Center(child: CircularProgressIndicator())
        : Stack(
            alignment: Alignment.center,
            children: [
              Center(
                child: AspectRatio(
                  aspectRatio: _player!.value.aspectRatio,
                  child: VideoPlayer(_player!),
                ),
              ),
              IconButton.filled(
                tooltip: _player!.value.isPlaying
                    ? 'Tạm dừng video'
                    : 'Phát video',
                iconSize: 40,
                onPressed: () {
                  setState(() {
                    _player!.value.isPlaying
                        ? _player!.pause()
                        : _player!.play();
                  });
                },
                icon: BabiIcon(
                  _player!.value.isPlaying ? Icons.pause : Icons.play_arrow,
                ),
              ),
            ],
          ),
  );
}

class CaptionEditor extends StatefulWidget {
  const CaptionEditor(this.value, {super.key});
  final String value;
  @override
  State<CaptionEditor> createState() => _CaptionEditorState();
}

class _CaptionEditorState extends State<CaptionEditor> {
  late final TextEditingController text = TextEditingController(
    text: widget.value,
  );
  @override
  void dispose() {
    text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.fromLTRB(
      20,
      0,
      20,
      MediaQuery.viewInsetsOf(context).bottom + 24,
    ),
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          'Một chút về kỷ niệm này',
          style: Theme.of(context).textTheme.titleLarge,
        ),
        const SizedBox(height: 16),
        TextField(
          key: const Key('caption-input'),
          controller: text,
          autofocus: true,
          minLines: 2,
          maxLines: 5,
          maxLength: 2000,
          decoration: const InputDecoration(
            hintText: 'Viết caption cho kỷ niệm của bé…',
            labelText: 'Caption (bắt buộc)',
          ),
        ),
        const SizedBox(height: 12),
        FilledButton(
          onPressed: () => Navigator.pop(context, text.text),
          child: const Text('Xong'),
        ),
      ],
    ),
  );
}
