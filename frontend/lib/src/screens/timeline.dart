import 'package:flutter/material.dart';

import '../controller.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets.dart';

class TimelineScreen extends StatelessWidget {
  const TimelineScreen({
    super.key,
    required this.app,
    required this.onProfile,
    required this.onBaby,
  });
  final BabiController app;
  final VoidCallback onProfile, onBaby;
  @override
  Widget build(BuildContext context) => Column(
    children: [
      PageHeader(
        title: app.baby.displayName,
        subtitle: !app.connected && app.babyIndex == 0
            ? '8 tháng · Những ngày con lớn'
            : 'Những ngày con lớn',
        onProfile: onProfile,
        onTitle: onBaby,
      ),
      Expanded(
        child: ListView(
          key: ValueKey('timeline-${app.baby.id}-${app.timelineRevision}'),
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
          children: [
            SizedBox(
              height: 44,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: 4,
                separatorBuilder: (_, _) => const SizedBox(width: 8),
                itemBuilder: (context, i) {
                  final label = ['Tất cả', 'Cột mốc', 'Video', 'Lần đầu'][i];
                  return Center(
                    child: SizedBox(
                      height: 44,
                      child: TextButton(
                        onPressed: () => app.setFilter(label),
                        style: TextButton.styleFrom(
                          padding: EdgeInsets.zero,
                          minimumSize: const Size(44, 44),
                        ),
                        child: GlassSurface(
                          borderRadius: 18,
                          padding: const EdgeInsets.symmetric(
                            horizontal: 18,
                            vertical: 8,
                          ),
                          tint: app.filter == label
                              ? BabiColors.selected.withValues(alpha: .62)
                              : Colors.white.withValues(alpha: .42),
                          child: Text(
                            label,
                            style: textStyle(
                              14,
                              line: 20,
                              weight: FontWeight.w500,
                            ),
                          ),
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),
            const SizedBox(height: 12),
            if (app.canCreate && app.overdueVaccinationReviewCount > 0) ...[
              OverdueVaccinationNotice(
                count: app.overdueVaccinationReviewCount,
                onReview: () => app.selectTab(AppTab.vaccinations),
              ),
              const SizedBox(height: 12),
            ],
            if (app.visibleMemories.isEmpty)
              SurfaceCard(
                child: Column(
                  children: [
                    const SizedBox(height: 24),
                    const BabiIcon(
                      Icons.favorite_border_rounded,
                      size: 48,
                      color: BabiColors.primary,
                    ),
                    const SizedBox(height: 16),
                    Text(
                      'Những kỷ niệm đang chờ bé',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Lưu lại những khoảnh khắc nhỏ, để cả nhà cùng nhớ.',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 20),
                    if (app.canContribute)
                      FilledButton(
                        key: const Key('empty-capture'),
                        onPressed: () => app.selectTab(AppTab.capture),
                        child: const Text('Chụp kỷ niệm đầu tiên'),
                      ),
                    const SizedBox(height: 20),
                  ],
                ),
              ),
            for (final memory in app.visibleMemories)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: memory.status != 'published'
                    ? SurfaceCard(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(memory.caption),
                            const SizedBox(height: 8),
                            Text(
                              memory.status == 'failed'
                                  ? 'Xử lý tệp thất bại'
                                  : 'Đang xử lý tệp…',
                            ),
                            if (memory.status == 'failed' && app.canContribute)
                              TextButton(
                                onPressed: app.busy
                                    ? null
                                    : () async {
                                        try {
                                          await app.retryMemory(memory);
                                        } catch (e) {
                                          if (context.mounted) {
                                            showIssue(context, e);
                                          }
                                        }
                                      },
                                child: const Text('Thử lại'),
                              ),
                          ],
                        ),
                      )
                    : MemoryCard(
                        item: memory,
                        onLike: () => app.like(memory),
                        onOpen: () => _openMemory(context, memory),
                      ),
              ),
          ],
        ),
      ),
    ],
  );
  void _openMemory(BuildContext context, MemoryItem memory) =>
      showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (context) => DraggableScrollableSheet(
          expand: false,
          initialChildSize: .82,
          builder: (context, scroll) => ListView(
            controller: scroll,
            padding: const EdgeInsets.all(20),
            children: [
              Text(
                memory.author,
                style: Theme.of(context).textTheme.titleMedium,
              ),
              Text(memory.date, style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 16),
              SizedBox(
                height: 420,
                child: PageView(
                  children: [
                    for (final file in memory.media)
                      ClipRRect(
                        borderRadius: BorderRadius.circular(24),
                        child: MediaView(file, interactive: true),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              Text(memory.caption, style: textStyle(16, line: 24)),
              if (memory.milestone != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Text('✧ ${memory.milestone}'),
                ),
            ],
          ),
        ),
      );
}

class MemoryCard extends StatelessWidget {
  const MemoryCard({
    super.key,
    required this.item,
    required this.onLike,
    required this.onOpen,
  });
  final MemoryItem item;
  final VoidCallback onLike, onOpen;
  @override
  Widget build(BuildContext context) => SurfaceCard(
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          height: MediaQuery.textScalerOf(context).scale(22),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  item.author,
                  style: textStyle(15, line: 22, weight: FontWeight.w500),
                ),
              ),
              Text(
                item.date,
                style: textStyle(13, line: 18, color: BabiColors.secondary),
              ),
            ],
          ),
        ),
        const SizedBox(height: 4),
        GestureDetector(
          onTap: onOpen,
          child: ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: AspectRatio(
              aspectRatio: 1.6,
              child: MediaView(item.media.first),
            ),
          ),
        ),
        const SizedBox(height: 4),
        Container(
          height: 24,
          padding: const EdgeInsets.symmetric(horizontal: 8),
          decoration: BoxDecoration(
            color: BabiColors.selected,
            borderRadius: BorderRadius.circular(12),
          ),
          alignment: Alignment.centerLeft,
          width: 148,
          child: Text(
            '${item.media.length} ${item.media.first.isVideo ? 'tệp' : 'ảnh'} · Kỷ niệm',
            style: textStyle(13, line: 18),
          ),
        ),
        const SizedBox(height: 4),
        GestureDetector(
          onTap: onOpen,
          child: SizedBox(
            height: MediaQuery.textScalerOf(context).scale(48),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                item.caption,
                style: textStyle(16, line: 24),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ),
        ),
        const SizedBox(height: 4),
        // Keep the visual 18 pt reaction line; its transparent hit target is 44 pt.
        SizedBox(
          height: MediaQuery.textScalerOf(context).scale(18),
          child: OverflowBox(
            maxHeight: 44,
            alignment: Alignment.centerLeft,
            child: Align(
              alignment: Alignment.centerLeft,
              widthFactor: 1,
              child: InkWell(
                onTap: onLike,
                child: SizedBox(
                  height: 44,
                  child: Align(
                    alignment: Alignment.centerLeft,
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        BabiIcon(
                          Icons.favorite_border_rounded,
                          size: 13,
                          color: item.liked
                              ? BabiColors.primary
                              : BabiColors.secondary,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          '${item.likes}   ·   ${item.comments} bình luận',
                          style: textStyle(
                            13,
                            line: 18,
                            color: BabiColors.secondary,
                          ),
                        ),
                      ],
                    ),
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
