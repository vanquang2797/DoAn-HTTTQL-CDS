import 'package:flutter/material.dart';

import '../controller.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets.dart';

class PreviewScreen extends StatefulWidget {
  const PreviewScreen({
    super.key,
    required this.app,
    required this.onGallery,
    required this.onBaby,
  });
  final BabiController app;
  final Future<void> Function() onGallery;
  final VoidCallback onBaby;
  @override
  State<PreviewScreen> createState() => _PreviewScreenState();
}

class _PreviewScreenState extends State<PreviewScreen> {
  late final PageController _pages = PageController(
    initialPage: widget.app.currentMedia,
  );
  bool _captionError = false;
  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  Future<void> _editCaption() async {
    final value = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (_) => CaptionEditor(widget.app.caption),
    );
    if (value != null && mounted) {
      widget.app.setCaption(value);
      setState(() => _captionError = false);
    }
  }

  Future<void> _save() async {
    final app = widget.app;
    if (app.caption.trim().isEmpty) {
      setState(() => _captionError = true);
      return;
    }
    final messenger = ScaffoldMessenger.of(context);
    try {
      await app.saveDraft();
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            app.connected
                ? 'Đã tiếp nhận kỷ niệm. Đang xử lý tệp.'
                : 'Đã lưu kỷ niệm',
          ),
        ),
      );
    } catch (e) {
      if (mounted) {
        showIssue(context, e);
      }
    }
  }

  Future<void> _milestone() async {
    final result = await showModalBottomSheet<String>(
      context: context,
      builder: (context) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Text(
                'Gắn cột mốc',
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ),
            for (final value
                in widget.app.connected
                    ? [
                        'Không gắn cột mốc',
                        ...widget.app.milestoneOptions.map(
                          (m) => m['name'] as String,
                        ),
                      ]
                    : [
                        'Không gắn cột mốc',
                        'Nụ cười đầu tiên',
                        'Lật người',
                        'Ngồi vững',
                        'Bước chân đầu tiên',
                      ])
              ListTile(
                title: Text(value),
                trailing: widget.app.milestone == value
                    ? const BabiIcon(Icons.check)
                    : null,
                onTap: () => Navigator.pop(context, value),
              ),
          ],
        ),
      ),
    );
    if (result != null && mounted) {
      widget.app.setMilestone(result == 'Không gắn cột mốc' ? null : result);
    }
  }

  String _ageLabel(DateTime birthDate) {
    final today = DateTime.now();
    var months =
        (today.year - birthDate.year) * 12 + today.month - birthDate.month;
    if (today.day < birthDate.day) months--;
    if (months < 24) return '$months tháng';
    final years = months ~/ 12;
    final remainder = months % 12;
    return remainder == 0 ? '$years tuổi' : '$years tuổi $remainder tháng';
  }

  Widget _glassChip(IconData icon, String label, {bool alert = false}) =>
      GlassSurface(
        borderRadius: 18,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        tint: (alert ? BabiColors.selected : Colors.white).withValues(
          alpha: .55,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            BabiIcon(
              icon,
              size: 15,
              color: alert ? BabiColors.primary : BabiColors.secondary,
            ),
            const SizedBox(width: 5),
            Text(
              label,
              style: textStyle(
                11,
                line: 15,
                weight: FontWeight.w500,
                color: alert ? BabiColors.primary : BabiColors.secondary,
              ),
            ),
          ],
        ),
      );

  Widget _babyTile(BabiController app, int index) {
    final baby = app.babies[index];
    final selected = index == app.babyIndex;
    return Semantics(
      selected: selected,
      button: true,
      child: InkWell(
        onTap: () => app.selectBaby(index),
        borderRadius: BorderRadius.circular(30),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 180),
          width: selected ? 154 : 112,
          height: 62,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(
            color: Colors.white.withValues(alpha: selected ? .48 : .22),
            borderRadius: BorderRadius.circular(30),
            border: Border.all(
              color: selected
                  ? BabiColors.primary.withValues(alpha: .58)
                  : Colors.white.withValues(alpha: .82),
            ),
          ),
          child: Row(
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: selected
                      ? Border.all(color: BabiColors.primary, width: 2)
                      : null,
                ),
                child: ClipOval(
                  child: const FigmaArt('assets/figma/preview_baby.svg'),
                ),
              ),
              const SizedBox(width: 7),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      baby.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: textStyle(12, line: 16, weight: FontWeight.w600),
                    ),
                    Text(
                      _ageLabel(baby.birthDate),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: textStyle(
                        10,
                        line: 14,
                        color: BabiColors.secondary,
                      ),
                    ),
                  ],
                ),
              ),
              if (selected)
                const Padding(
                  padding: EdgeInsets.only(left: 3),
                  child: Icon(Icons.circle, size: 6, color: BabiColors.primary),
                ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final app = widget.app;
    return Column(
      children: [
        SizedBox(
          height: 60,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Stack(
              alignment: Alignment.center,
              children: [
                Text(
                  'Lưu vào hồ sơ bé',
                  style: textStyle(16, line: 22, weight: FontWeight.w600),
                ),
                Align(
                  alignment: Alignment.centerLeft,
                  child: WhiteIconButton(
                    icon: Icons.arrow_back_rounded,
                    label: 'Quay lại',
                    size: 40,
                    radius: 20,
                    onPressed: app.busy
                        ? null
                        : () => app.selectTab(AppTab.capture),
                  ),
                ),
                Align(
                  alignment: Alignment.centerRight,
                  child: PopupMenuButton<String>(
                    tooltip: 'Tùy chọn ảnh',
                    onSelected: (value) {
                      if (value == 'add') widget.onGallery();
                      if (value == 'remove') {
                        app.removeCurrent();
                        if (app.draft.isNotEmpty) {
                          _pages.jumpToPage(app.currentMedia);
                        }
                      }
                    },
                    itemBuilder: (_) => const [
                      PopupMenuItem(value: 'add', child: Text('Thêm tệp')),
                      PopupMenuItem(value: 'remove', child: Text('Bỏ tệp này')),
                    ],
                    child: const SizedBox.square(
                      dimension: 40,
                      child: GlassSurface(
                        borderRadius: 20,
                        child: Center(
                          child: BabiIcon(Icons.more_horiz, size: 22),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
        if (app.busy)
          LinearProgressIndicator(
            value: app.connected ? null : app.savedCount / app.draft.length,
            minHeight: 3,
          ),
        Expanded(
          child: AbsorbPointer(
            absorbing: app.busy,
            child: SingleChildScrollView(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 18),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  GlassSurface(
                    borderRadius: 28,
                    padding: const EdgeInsets.all(8),
                    tint: Colors.white.withValues(alpha: .48),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(22),
                      child: AspectRatio(
                        // The reference camera frame is portrait but close to
                        // 1:1.08; 4:5 made the photo so tall that the save and
                        // family controls disappeared below the phone's fold.
                        aspectRatio: .92,
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            PageView.builder(
                              controller: _pages,
                              itemCount: app.draft.length,
                              onPageChanged: app.viewMedia,
                              itemBuilder: (_, i) => MediaView(
                                app.draft[i],
                                key: ValueKey(app.draft[i]),
                                interactive: true,
                              ),
                            ),
                            Positioned(
                              left: 16,
                              right: 16,
                              bottom: 16,
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  if (_captionError)
                                    Padding(
                                      padding: const EdgeInsets.only(bottom: 4),
                                      child: Text(
                                        'Caption không được để trống',
                                        key: const Key('caption-error'),
                                        style: textStyle(
                                          13,
                                          line: 18,
                                          color: BabiColors.error,
                                        ),
                                      ),
                                    ),
                                  GlassSurface(
                                    borderRadius: 16,
                                    tint: Colors.white.withValues(alpha: .55),
                                    child: InkWell(
                                      key: const Key('caption-pill'),
                                      onTap: _editCaption,
                                      borderRadius: BorderRadius.circular(16),
                                      child: ConstrainedBox(
                                        constraints: const BoxConstraints(
                                          minHeight: 48,
                                        ),
                                        child: Padding(
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 12,
                                            vertical: 10,
                                          ),
                                          child: Row(
                                            children: [
                                              const BabiIcon(
                                                Icons.edit_outlined,
                                                size: 20,
                                              ),
                                              const SizedBox(width: 8),
                                              Expanded(
                                                child: Text(
                                                  app.caption.isEmpty
                                                      ? 'Thêm caption (bắt buộc)…'
                                                      : app.caption,
                                                  style: textStyle(
                                                    15,
                                                    line: 20,
                                                  ),
                                                  maxLines: 2,
                                                  overflow:
                                                      TextOverflow.ellipsis,
                                                ),
                                              ),
                                              const SizedBox(width: 7),
                                              Container(
                                                padding:
                                                    const EdgeInsets.symmetric(
                                                      horizontal: 9,
                                                      vertical: 6,
                                                    ),
                                                decoration: BoxDecoration(
                                                  color: BabiColors.selected
                                                      .withValues(alpha: .84),
                                                  borderRadius:
                                                      BorderRadius.circular(18),
                                                ),
                                                child: Text(
                                                  'Bé ${_ageLabel(app.baby.birthDate)}',
                                                  style: textStyle(
                                                    10,
                                                    line: 14,
                                                    weight: FontWeight.w500,
                                                    color: BabiColors.primary,
                                                  ),
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            if (app.busy)
                              Positioned(
                                top: 16,
                                left: 16,
                                right: 16,
                                child: Container(
                                  padding: const EdgeInsets.all(12),
                                  decoration: BoxDecoration(
                                    color: BabiColors.glassWhite,
                                    borderRadius: BorderRadius.circular(12),
                                    border: Border.all(
                                      color: BabiColors.glassBorder,
                                    ),
                                  ),
                                  child: Text(
                                    'Đang lưu · ${app.savedCount}/${app.draft.length} tệp',
                                    textAlign: TextAlign.center,
                                    style: textStyle(13, line: 18),
                                  ),
                                ),
                              ),
                            Positioned(
                              top: 13,
                              left: 13,
                              child: _glassChip(
                                Icons.check_circle_outline_rounded,
                                app.connected
                                    ? 'Sẵn sàng tải lên'
                                    : 'Ảnh đã chọn',
                              ),
                            ),
                            Positioned(
                              top: 13,
                              right: 13,
                              child: _glassChip(
                                Icons.high_quality_rounded,
                                'HD',
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    height: 44,
                    child: Row(
                      children: [
                        Text(
                          '${app.currentMedia + 1}/${app.draft.length} tệp',
                          style: textStyle(
                            13,
                            line: 18,
                            color: BabiColors.secondary,
                          ),
                        ),
                        Expanded(
                          child: Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              for (var i = 0; i < app.draft.length; i++)
                                GestureDetector(
                                  onTap: () => _pages.animateToPage(
                                    i,
                                    duration: const Duration(milliseconds: 180),
                                    curve: Curves.easeOut,
                                  ),
                                  child: Container(
                                    width: i == app.currentMedia ? 16 : 6,
                                    height: 6,
                                    margin: const EdgeInsets.symmetric(
                                      horizontal: 4,
                                    ),
                                    decoration: BoxDecoration(
                                      color: i == app.currentMedia
                                          ? BabiColors.primary
                                          : BabiColors.secondary,
                                      borderRadius: BorderRadius.circular(3),
                                    ),
                                  ),
                                ),
                            ],
                          ),
                        ),
                        WhiteIconButton(
                          icon: Icons.add,
                          label: 'Thêm tệp',
                          onPressed: app.draft.length >= BabiController.maxFiles
                              ? null
                              : widget.onGallery,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 4),
                  const SizedBox(height: 2),
                  SizedBox(
                    height:
                        84 + (MediaQuery.textScalerOf(context).scale(20) - 20),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SizedBox(
                          width: 96,
                          child: Column(
                            children: [
                              WhiteIconButton(
                                icon: Icons.close_rounded,
                                label: 'Bỏ tệp',
                                size: 48,
                                radius: 24,
                                onPressed: () {
                                  app.removeCurrent();
                                  if (app.draft.isNotEmpty) {
                                    _pages.jumpToPage(app.currentMedia);
                                  }
                                },
                              ),
                              const SizedBox(height: 4),
                              Text(
                                'Bỏ tệp',
                                style: textStyle(
                                  13,
                                  line: 18,
                                  color: BabiColors.secondary,
                                ),
                              ),
                            ],
                          ),
                        ),
                        Expanded(
                          child: Column(
                            children: [
                              SizedBox.square(
                                dimension: 64,
                                child: IconButton(
                                  key: const Key('save-memory'),
                                  onPressed: _save,
                                  tooltip: 'Lưu hồ sơ bé',
                                  style: IconButton.styleFrom(
                                    backgroundColor: BabiColors.primary,
                                    foregroundColor: BabiColors.ink,
                                    elevation: 5,
                                    shadowColor: BabiColors.primary.withValues(
                                      alpha: .4,
                                    ),
                                    shape: const CircleBorder(),
                                  ),
                                  icon: const BabiIcon(
                                    Icons.send_outlined,
                                    size: 28,
                                  ),
                                ),
                              ),
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                child: Text(
                                  'Lưu hồ sơ',
                                  maxLines: 1,
                                  softWrap: false,
                                  style: textStyle(
                                    13,
                                    line: 18,
                                    weight: FontWeight.w600,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                        SizedBox(
                          width: 96,
                          child: Column(
                            children: [
                              WhiteIconButton(
                                icon: Icons.auto_awesome_outlined,
                                label: 'Cột mốc',
                                size: 48,
                                radius: 24,
                                onPressed: _milestone,
                              ),
                              const SizedBox(height: 4),
                              Text(
                                'Cột mốc',
                                style: textStyle(
                                  13,
                                  line: 18,
                                  color: BabiColors.secondary,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 7),
                  if (app.milestone != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Text('✧ ${app.milestone}', style: textStyle(14)),
                    ),
                  OutlinedButton.icon(
                    key: const Key('memory-date'),
                    onPressed: app.busy
                        ? null
                        : () async {
                            final date = await showDatePicker(
                              context: context,
                              initialDate: app.draftDate,
                              firstDate: app.baby.birthDate,
                              lastDate: DateTime.now(),
                            );
                            if (date != null && mounted) app.setDraftDate(date);
                          },
                    icon: const Icon(Icons.calendar_month_outlined),
                    label: Text('Ngày kỷ niệm: ${calendarDate(app.draftDate)}'),
                  ),
                  SurfaceCard(
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            const BabiIcon(
                              Icons.favorite_border_rounded,
                              size: 16,
                              color: BabiColors.primary,
                            ),
                            const SizedBox(width: 7),
                            Expanded(
                              child: Text(
                                'Lưu cho bé · chia sẻ cùng gia đình',
                                style: textStyle(
                                  12,
                                  line: 17,
                                  color: BabiColors.secondary,
                                ),
                              ),
                            ),
                            TextButton(
                              onPressed: widget.onBaby,
                              style: TextButton.styleFrom(
                                minimumSize: const Size(44, 36),
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 5,
                                ),
                                foregroundColor: BabiColors.primary,
                              ),
                              child: Text(
                                'Quản lý',
                                style: textStyle(
                                  11,
                                  line: 16,
                                  weight: FontWeight.w600,
                                  color: BabiColors.primary,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        SizedBox(
                          height: 62,
                          child: ListView(
                            scrollDirection: Axis.horizontal,
                            children: [
                              for (var i = 0; i < app.babies.length; i++) ...[
                                if (i > 0) const SizedBox(width: 6),
                                _babyTile(app, i),
                              ],
                              const SizedBox(width: 6),
                              Semantics(
                                button: true,
                                label: 'Thêm hồ sơ bé',
                                child: InkWell(
                                  onTap: widget.onBaby,
                                  borderRadius: BorderRadius.circular(28),
                                  child: Container(
                                    width: 48,
                                    height: 48,
                                    margin: const EdgeInsets.symmetric(
                                      vertical: 7,
                                    ),
                                    decoration: BoxDecoration(
                                      shape: BoxShape.circle,
                                      color: Colors.white.withValues(
                                        alpha: .25,
                                      ),
                                      border: Border.all(
                                        color: BabiColors.secondary.withValues(
                                          alpha: .45,
                                        ),
                                      ),
                                    ),
                                    child: const Center(
                                      child: BabiIcon(
                                        Icons.add,
                                        size: 22,
                                        color: BabiColors.secondary,
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}
