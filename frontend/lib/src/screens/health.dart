import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../controller.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets.dart';

class HealthScreen extends StatefulWidget {
  const HealthScreen({super.key, required this.app, required this.onProfile});
  final BabiController app;
  final VoidCallback onProfile;

  @override
  State<HealthScreen> createState() => _HealthScreenState();
}

class _HealthScreenState extends State<HealthScreen> {
  int _periodMonths = 0;
  BabiController get app => widget.app;
  VoidCallback get onProfile => widget.onProfile;

  Future<void> _showBabyInfo() async {
    final edit = await showModalBottomSheet<bool>(
      context: context,
      builder: (_) => _ChildProfileInfo(app: app),
    );
    if (edit == true && mounted) {
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _ChildProfileEditor(app: app),
      );
    }
  }

  List<Measurement> _recordsInPeriod() {
    final today = DateUtils.dateOnly(DateTime.now());
    final start = _periodMonths == 0
        ? DateTime(app.baby.birthDate.year, app.baby.birthDate.month)
        : DateTime(today.year, today.month - _periodMonths + 1);
    return app.records
        .where((record) => !DateUtils.dateOnly(record.date).isBefore(start))
        .toList()
      ..sort((a, b) => a.date.compareTo(b.date));
  }

  @override
  Widget build(BuildContext context) {
    final latest = app.records.isEmpty ? null : app.records.first;
    final previous = app.records.length > 1 ? app.records[1] : null;
    final periodRecords = _recordsInPeriod();
    final chartRecords = periodRecords;
    return Column(
      children: [
        PageHeader(
          title: 'Sức khỏe',
          subtitle: '${app.baby.name} · Theo dõi tăng trưởng',
          onProfile: onProfile,
          compact: true,
        ),
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 6, 16, 18),
            children: [
              Align(
                alignment: Alignment.centerRight,
                child: OutlinedButton.icon(
                  key: const Key('child-profile-info'),
                  onPressed: _showBabyInfo,
                  icon: const Icon(Icons.child_care_rounded, size: 17),
                  label: const Text('Thông tin bé'),
                  style: OutlinedButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                    padding: const EdgeInsets.symmetric(
                      horizontal: 12,
                      vertical: 7,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 4),
              Row(
                children: [
                  Expanded(
                    child: _metric(
                      'Chiều cao',
                      latest == null ? '—' : '${decimal(latest.height)} cm',
                      icon: Icons.height_rounded,
                      previous: previous == null
                          ? null
                          : latest!.height - previous.height,
                      unit: 'cm',
                      date: latest?.date,
                    ),
                  ),
                  const SizedBox(width: 9),
                  Expanded(
                    child: _metric(
                      'Cân nặng',
                      latest == null ? '—' : '${decimal(latest.weight)} kg',
                      icon: Icons.monitor_weight_outlined,
                      previous: previous == null
                          ? null
                          : latest!.weight - previous.weight,
                      unit: 'kg',
                      date: latest?.date,
                    ),
                  ),
                ],
              ),
              if (app.canCreate) ...[
                const SizedBox(height: 6),
                SizedBox(
                  height: 40,
                  width: double.infinity,
                  child: FilledButton.icon(
                    key: const Key('add-measurement'),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(40),
                      textStyle: textStyle(13, weight: FontWeight.w600),
                      backgroundColor: const Color(0xFFD96B43),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(15),
                      ),
                    ),
                    onPressed: () => showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      builder: (_) => MeasurementForm(app: app),
                    ),
                    icon: const Icon(Icons.add_rounded),
                    label: const Text('Ghi nhận số đo'),
                  ),
                ),
              ],
              const SizedBox(height: 8),
              if (latest != null) ...[
                SurfaceCard(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Diễn biến trong kỳ',
                        style: textStyle(13, line: 18, weight: FontWeight.w600),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        periodRecords.length < 2
                            ? 'Cần ít nhất hai lần đo trong khoảng này để so sánh thay đổi.'
                            : 'Từ ${calendarDate(periodRecords.first.date)} đến ${calendarDate(periodRecords.last.date)}: ${_delta(periodRecords.last.height - periodRecords.first.height)} cm chiều cao · ${_delta(periodRecords.last.weight - periodRecords.first.weight)} kg cân nặng.',
                        style: textStyle(
                          10,
                          line: 15,
                          color: BabiColors.secondary,
                        ),
                      ),
                      const SizedBox(height: 8),
                      _periodSelector(),
                      const SizedBox(height: 6),
                    ],
                  ),
                ),
                const SizedBox(height: 9),
                if (chartRecords.isEmpty)
                  const SurfaceCard(
                    child: Text(
                      'Không có số đo trong khoảng đã chọn. Hãy chọn “Tất cả” hoặc ghi nhận số đo mới.',
                    ),
                  )
                else
                  ActualGrowthChart(
                    key: const Key('weight-chart'),
                    records: chartRecords,
                    height: false,
                  ),
                const SizedBox(height: 9),
              ],
              if (latest == null)
                SurfaceCard(
                  child: Column(
                    children: [
                      const BabiIcon(
                        Icons.monitor_weight_outlined,
                        size: 36,
                        color: BabiColors.secondary,
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Bắt đầu theo dõi',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const SizedBox(height: 4),
                      const Text(
                        'Lưu chiều cao và cân nặng sau lần đo tiếp theo của bé.',
                        textAlign: TextAlign.center,
                      ),
                    ],
                  ),
                ),
              if (latest != null && chartRecords.isNotEmpty) ...[
                ActualGrowthChart(
                  key: const Key('height-chart'),
                  records: chartRecords,
                  height: true,
                ),
                const SizedBox(height: 9),
              ],
              SurfaceCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Nhật ký từng lần đo',
                      style: textStyle(14, line: 19, weight: FontWeight.w600),
                    ),
                    const SizedBox(height: 8),
                    if (app.records.isEmpty)
                      Text(
                        'Chưa có số đo nào được lưu.',
                        style: Theme.of(context).textTheme.bodyMedium,
                      ),
                    if (app.records.isNotEmpty && periodRecords.isEmpty)
                      Text(
                        'Không có số đo trong khoảng này. Chọn khoảng dài hơn hoặc xem toàn bộ lịch sử.',
                        style: textStyle(
                          11,
                          line: 16,
                          color: BabiColors.secondary,
                        ),
                      ),
                    for (final record in periodRecords.reversed) ...[
                      if (record != periodRecords.last)
                        const Divider(height: 1),
                      _recordTile(record),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _periodSelector() => Wrap(
    spacing: 6,
    children: [
      for (final item in const [
        (3, '3 tháng'),
        (6, '6 tháng'),
        (12, '12 tháng'),
        (0, 'Tất cả'),
      ])
        ChoiceChip(
          label: Text(item.$2),
          selected: _periodMonths == item.$1,
          onSelected: (_) => setState(() => _periodMonths = item.$1),
          visualDensity: VisualDensity.compact,
          labelStyle: textStyle(10, line: 14, weight: FontWeight.w500),
        ),
    ],
  );

  Widget _recordTile(Measurement record) => ListTile(
    key: Key('health-record-${record.id ?? isoDate(record.date)}'),
    contentPadding: EdgeInsets.zero,
    dense: true,
    visualDensity: const VisualDensity(horizontal: 0, vertical: -2),
    leading: const CircleAvatar(
      radius: 15,
      backgroundColor: BabiColors.selected,
      child: BabiIcon(Icons.straighten_rounded, size: 15),
    ),
    title: Text(
      calendarDate(record.date),
      style: textStyle(12, line: 17, weight: FontWeight.w500),
    ),
    subtitle: Text(
      '${decimal(record.height)} cm · ${decimal(record.weight)} kg · ${record.method == 'lying' ? 'đo nằm' : 'đo đứng'}',
      style: textStyle(10, line: 14, color: BabiColors.secondary),
    ),
    trailing: app.canCreate ? _recordActions(record) : null,
  );

  Widget _recordActions(Measurement record) => PopupMenuButton<String>(
    key: Key('measurement-actions-${record.id ?? isoDate(record.date)}'),
    tooltip: 'Tùy chọn số đo',
    onSelected: (action) {
      if (action == 'edit') {
        showModalBottomSheet<void>(
          context: context,
          isScrollControlled: true,
          builder: (_) => MeasurementForm(app: app, original: record),
        );
      } else {
        _deleteMeasurement(context, record);
      }
    },
    itemBuilder: (_) => const [
      PopupMenuItem(value: 'edit', child: Text('Sửa số đo')),
      PopupMenuItem(value: 'delete', child: Text('Xóa số đo')),
    ],
  );

  String _delta(double value) => '${value >= 0 ? '+' : ''}${decimal(value)}';

  Future<void> _deleteMeasurement(
    BuildContext context,
    Measurement record,
  ) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Xóa số đo này?'),
        content: Text(
          'Số đo ngày ${calendarDate(record.date)} sẽ bị xóa khỏi hồ sơ.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Hủy'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Xóa'),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;
    try {
      await app.deleteMeasurement(record);
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Đã xóa số đo')));
      }
    } catch (e) {
      if (context.mounted) showIssue(context, e);
    }
  }

  Widget _metric(
    String label,
    String value, {
    required IconData icon,
    required double? previous,
    required String unit,
    required DateTime? date,
  }) => SurfaceCard(
    padding: const EdgeInsets.all(12),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            BabiIcon(icon, size: 18, color: BabiColors.primary),
            const SizedBox(width: 5),
            Expanded(
              child: Text(
                label,
                style: textStyle(12, line: 16, color: BabiColors.secondary),
              ),
            ),
          ],
        ),
        const SizedBox(height: 5),
        FittedBox(
          alignment: Alignment.centerLeft,
          fit: BoxFit.scaleDown,
          child: Text(
            value,
            style: textStyle(19, line: 25, weight: FontWeight.w600),
          ),
        ),
        const SizedBox(height: 3),
        Text(
          date == null
              ? 'Chưa có dữ liệu'
              : previous == null
              ? 'Cập nhật ${calendarDate(date)}'
              : '${previous >= 0 ? '+' : ''}${decimal(previous)} $unit so với lần trước',
          style: textStyle(11, line: 16, color: BabiColors.secondary),
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
        ),
      ],
    ),
  );
}

String _childAge(DateTime birth) {
  final today = DateTime.now();
  var months = (today.year - birth.year) * 12 + today.month - birth.month;
  if (today.day < birth.day) months--;
  if (months < 1) return '${today.difference(birth).inDays} ngày tuổi';
  if (months < 24) return '$months tháng tuổi';
  final years = months ~/ 12;
  final remaining = months % 12;
  return remaining == 0 ? '$years tuổi' : '$years tuổi $remaining tháng';
}

String _shortNumber(double value) => value == value.roundToDouble()
    ? value.toStringAsFixed(0)
    : value.toStringAsFixed(1);

class _ChildProfileInfo extends StatelessWidget {
  const _ChildProfileInfo({required this.app});
  final BabiController app;

  @override
  Widget build(BuildContext context) {
    final baby = app.baby;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 18, 20, 20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Thông tin bé', style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 12),
            _infoRow('Tên bé', baby.name),
            _infoRow('Ngày sinh', calendarDate(baby.birthDate)),
            _infoRow('Tuổi hiện tại', _childAge(baby.birthDate)),
            _infoRow('Giới tính', baby.gender == 'male' ? 'Bé trai' : 'Bé gái'),
            _infoRow(
              'Cân nặng lúc sinh',
              baby.birthWeightKg == null
                  ? 'Chưa nhập'
                  : '${_shortNumber(baby.birthWeightKg!)} kg',
            ),
            _infoRow(
              'Chiều dài lúc sinh',
              baby.birthLengthCm == null
                  ? 'Chưa nhập'
                  : '${_shortNumber(baby.birthLengthCm!)} cm',
            ),
            if (app.canCreate) ...[
              const SizedBox(height: 10),
              FilledButton.icon(
                key: const Key('edit-child-profile'),
                onPressed: () => Navigator.pop(context, true),
                icon: const Icon(Icons.edit_outlined),
                label: const Text('Chỉnh sửa thông tin'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

Widget _infoRow(String label, String value) => Padding(
  padding: const EdgeInsets.symmetric(vertical: 6),
  child: Row(
    children: [
      Expanded(
        child: Text(
          label,
          style: textStyle(12, line: 17, color: BabiColors.secondary),
        ),
      ),
      const SizedBox(width: 12),
      Flexible(
        child: Text(
          value,
          textAlign: TextAlign.end,
          style: textStyle(12, line: 17, weight: FontWeight.w600),
        ),
      ),
    ],
  ),
);

class _ChildProfileEditor extends StatefulWidget {
  const _ChildProfileEditor({required this.app});
  final BabiController app;
  @override
  State<_ChildProfileEditor> createState() => _ChildProfileEditorState();
}

class _ChildProfileEditorState extends State<_ChildProfileEditor> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name;
  late final TextEditingController _weight;
  late final TextEditingController _length;
  late DateTime _birth;
  late String _gender;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final baby = widget.app.baby;
    _name = TextEditingController(text: baby.name);
    _birth = baby.birthDate;
    _gender = baby.gender;
    _weight = TextEditingController(text: baby.birthWeightKg?.toString() ?? '');
    _length = TextEditingController(text: baby.birthLengthCm?.toString() ?? '');
  }

  @override
  void dispose() {
    _name.dispose();
    _weight.dispose();
    _length.dispose();
    super.dispose();
  }

  String? _number(String? raw, double min, double max, String label) {
    final value = (raw ?? '').trim();
    if (value.isEmpty) return null;
    final number = double.tryParse(value.replaceAll(',', '.'));
    return number == null || number < min || number > max
        ? '$label không hợp lệ'
        : null;
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate() || _saving) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.app.updateBabyProfile(
        name: _name.text.trim(),
        birthDate: _birth,
        gender: _gender,
        birthWeightKg: double.tryParse(
          _weight.text.trim().replaceAll(',', '.'),
        ),
        birthLengthCm: double.tryParse(
          _length.text.trim().replaceAll(',', '.'),
        ),
      );
      if (mounted) Navigator.pop(context);
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => SafeArea(
    child: Padding(
      padding: EdgeInsets.fromLTRB(
        18,
        16,
        18,
        18 + MediaQuery.viewInsetsOf(context).bottom,
      ),
      child: Form(
        key: _form,
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'Thông tin ban đầu của bé',
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _name,
                decoration: const InputDecoration(labelText: 'Tên bé'),
                validator: (value) =>
                    (value ?? '').trim().isEmpty ? 'Nhập tên bé' : null,
              ),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: _saving
                    ? null
                    : () async {
                        final selected = await showDatePicker(
                          context: context,
                          initialDate: _birth,
                          firstDate: DateTime(DateTime.now().year - 5),
                          lastDate: DateTime.now(),
                        );
                        if (selected != null && mounted)
                          setState(() => _birth = selected);
                      },
                icon: const Icon(Icons.calendar_today_outlined),
                label: Text('Ngày sinh · ${calendarDate(_birth)}'),
              ),
              const SizedBox(height: 8),
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'female', label: Text('Bé gái')),
                  ButtonSegment(value: 'male', label: Text('Bé trai')),
                ],
                selected: {_gender},
                onSelectionChanged: _saving
                    ? null
                    : (values) => setState(() => _gender = values.first),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _weight,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'Cân nặng lúc sinh',
                  suffixText: 'kg',
                ),
                validator: (value) => _number(value, .1, 20, 'Cân nặng'),
              ),
              const SizedBox(height: 8),
              TextFormField(
                controller: _length,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'Chiều dài lúc sinh',
                  suffixText: 'cm',
                ),
                validator: (value) => _number(value, 10, 100, 'Chiều dài'),
              ),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(
                    _error!,
                    style: const TextStyle(color: BabiColors.error),
                  ),
                ),
              const SizedBox(height: 10),
              FilledButton(
                onPressed: _saving ? null : _save,
                child: Text(_saving ? 'Đang lưu…' : 'Lưu hồ sơ'),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

class ActualGrowthChart extends StatelessWidget {
  const ActualGrowthChart({
    super.key,
    required this.records,
    required this.height,
  });
  final List<Measurement> records;
  final bool height;

  @override
  Widget build(BuildContext context) {
    final sorted = List<Measurement>.of(records)
      ..sort((a, b) => a.date.compareTo(b.date));
    if (sorted.isEmpty) return const SizedBox.shrink();
    final values = sorted.map((m) => height ? m.height : m.weight).toList();
    final lowValue = values.reduce((a, b) => a < b ? a : b);
    final highValue = values.reduce((a, b) => a > b ? a : b);
    final padding = (highValue - lowValue).abs() < 1
        ? 1.0
        : (highValue - lowValue) * .15;
    final low = lowValue - padding, high = highValue + padding;
    final unit = height ? 'cm' : 'kg';
    return SurfaceCard(
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 15,
                backgroundColor: BabiColors.selected,
                child: BabiIcon(
                  height ? Icons.height_rounded : Icons.monitor_weight_outlined,
                  size: 16,
                  color: BabiColors.primary,
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  height ? 'Chiều cao' : 'Cân nặng',
                  style: textStyle(14, line: 19, weight: FontWeight.w600),
                ),
              ),
              Text(
                '${values.length} lần đo',
                style: textStyle(10, line: 14, color: BabiColors.secondary),
              ),
            ],
          ),
          const SizedBox(height: 10),
          SizedBox(
            height: 96,
            child: Row(
              children: [
                SizedBox(
                  width: 42,
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      Text(
                        decimal(high),
                        style: textStyle(
                          9,
                          line: 12,
                          color: BabiColors.secondary,
                        ),
                      ),
                      Text(
                        decimal((high + low) / 2),
                        style: textStyle(
                          9,
                          line: 12,
                          color: BabiColors.secondary,
                        ),
                      ),
                      Text(
                        decimal(low),
                        style: textStyle(
                          9,
                          line: 12,
                          color: BabiColors.secondary,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Semantics(
                    label:
                        '${height ? 'Chiều cao' : 'Cân nặng'} theo ngày đo, ${values.length} điểm, từ ${calendarDate(sorted.first.date)} đến ${calendarDate(sorted.last.date)}, ${decimal(lowValue)} đến ${decimal(highValue)} $unit',
                    child: CustomPaint(
                      painter: _MeasurementPainter(sorted, values),
                      child: const SizedBox.expand(),
                    ),
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.only(left: 50, top: 3),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  calendarDate(sorted.first.date),
                  style: textStyle(9, line: 12, color: BabiColors.secondary),
                ),
                if (sorted.last.date != sorted.first.date)
                  Text(
                    calendarDate(sorted.last.date),
                    style: textStyle(9, line: 12, color: BabiColors.secondary),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 5),
          _ChartLegend(color: BabiColors.primary, label: 'Số đo của bé'),
        ],
      ),
    );
  }
}

class _MeasurementPainter extends CustomPainter {
  _MeasurementPainter(this.records, this.values);
  final List<Measurement> records;
  final List<double> values;

  @override
  void paint(Canvas canvas, Size size) {
    if (values.isEmpty || size.width <= 0 || size.height <= 0) return;
    final line = Paint()
      ..color = BabiColors.primary
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..style = PaintingStyle.stroke;
    final grid = Paint()
      ..color = BabiColors.secondary.withValues(alpha: .14)
      ..strokeWidth = 1;
    final plot = Rect.fromLTRB(4, 7, size.width - 4, size.height - 7);
    for (var i = 0; i <= 3; i++) {
      final y = plot.top + plot.height * i / 3;
      canvas.drawLine(Offset(plot.left, y), Offset(plot.right, y), grid);
    }
    var low = values.reduce((a, b) => a < b ? a : b);
    var high = values.reduce((a, b) => a > b ? a : b);
    final padding = (high - low).abs() < 1 ? 1.0 : (high - low) * .15;
    low -= padding;
    high += padding;
    final firstDate = DateUtils.dateOnly(records.first.date);
    final lastDate = DateUtils.dateOnly(records.last.date);
    final rangeDays = math.max(1, lastDate.difference(firstDate).inDays);
    final points = <Offset>[];
    for (var i = 0; i < values.length; i++) {
      final x = values.length == 1
          ? plot.left + plot.width / 2
          : plot.left +
                plot.width *
                    DateUtils.dateOnly(records[i].date)
                        .difference(firstDate)
                        .inDays /
                    rangeDays;
      final y = plot.bottom - plot.height * (values[i] - low) / (high - low);
      points.add(Offset(x, y));
    }
    if (points.length > 1) {
      final path = Path()..moveTo(points.first.dx, points.first.dy);
      for (final point in points.skip(1)) {
        path.lineTo(point.dx, point.dy);
      }
      final area = Path()
        ..addPath(path, Offset.zero)
        ..lineTo(points.last.dx, plot.bottom)
        ..lineTo(points.first.dx, plot.bottom)
        ..close();
      final fill = Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            BabiColors.primary.withValues(alpha: .22),
            BabiColors.primary.withValues(alpha: .015),
          ],
        ).createShader(plot);
      canvas.drawPath(area, fill);
      canvas.drawPath(path, line);
    }
    for (final point in points) {
      canvas.drawCircle(point, 4, Paint()..color = Colors.white);
      canvas.drawCircle(point, 3, Paint()..color = BabiColors.primary);
    }
  }

  @override
  bool shouldRepaint(covariant _MeasurementPainter old) => true;
}

class _ChartLegend extends StatelessWidget {
  const _ChartLegend({
    required this.color,
    required this.label,
    this.dashed = false,
  });
  final Color color;
  final String label;
  final bool dashed;
  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      SizedBox(
        width: 18,
        height: 8,
        child: CustomPaint(painter: _LegendLine(color, dashed)),
      ),
      const SizedBox(width: 5),
      Text(label, style: textStyle(9, line: 12, color: BabiColors.secondary)),
    ],
  );
}

class _LegendLine extends CustomPainter {
  const _LegendLine(this.color, this.dashed);
  final Color color;
  final bool dashed;
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 2;
    if (dashed) {
      for (var x = 0.0; x < size.width; x += 5) {
        canvas.drawLine(
          Offset(x, size.height / 2),
          Offset(math.min(x + 3, size.width), size.height / 2),
          paint,
        );
      }
    } else {
      canvas.drawLine(Offset.zero, Offset(size.width, size.height / 2), paint);
    }
  }

  @override
  bool shouldRepaint(covariant _LegendLine old) =>
      color != old.color || dashed != old.dashed;
}

class MeasurementForm extends StatefulWidget {
  const MeasurementForm({super.key, required this.app, this.original});
  final BabiController app;
  final Measurement? original;
  @override
  State<MeasurementForm> createState() => _MeasurementFormState();
}

class _MeasurementFormState extends State<MeasurementForm> {
  final _form = GlobalKey<FormState>();
  final _height = TextEditingController(), _weight = TextEditingController();
  DateTime _date = DateUtils.dateOnly(DateTime.now());
  String _method = 'lying';
  bool _saving = false;
  Measurement? _editingRecord;
  @override
  void initState() {
    super.initState();
    final record = widget.original;
    _editingRecord = record;
    if (record != null) {
      _date = record.date;
      _height.text = decimal(record.height);
      _weight.text = decimal(record.weight);
      _method = record.method;
    }
  }

  @override
  void dispose() {
    _height.dispose();
    _weight.dispose();
    super.dispose();
  }

  double? _number(String value) => double.tryParse(value.replaceAll(',', '.'));
  Future<void> _save() async {
    if (_saving) return;
    if (!_form.currentState!.validate()) {
      return;
    }
    final h = _number(_height.text)!, w = _number(_weight.text)!;
    final duplicates = widget.app.records.where(
      (record) =>
          DateUtils.isSameDay(record.date, _date) &&
          (_editingRecord == null || record.id != _editingRecord?.id),
    );
    if (duplicates.isNotEmpty && _editingRecord == null) {
      final existing = duplicates.first;
      final edit = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Ngày này đã có số đo'),
          content: Text(
            '${calendarDate(existing.date)}: ${decimal(existing.height)} cm, ${decimal(existing.weight)} kg. Không tự ghi đè số đo cũ.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Chọn ngày khác'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Sửa bản ghi hiện có'),
            ),
          ],
        ),
      );
      if (edit == true && mounted) {
        setState(() {
          _editingRecord = existing;
          _height.text = decimal(existing.height);
          _weight.text = decimal(existing.weight);
          _method = existing.method;
        });
      }
      return;
    }
    var outlierConfirmed = false;
    if (h < 30 || h > 120 || w < .5 || w > 30) {
      final confirmed = await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Kiểm tra lại số đo'),
          content: const Text(
            'Số đo nằm ngoài khoảng kiểm tra nhập liệu. Đây không phải đánh giá sức khỏe. Bạn xác nhận số vừa nhập là đúng?',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Nhập lại'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Xác nhận'),
            ),
          ],
        ),
      );
      if (confirmed != true || !mounted) {
        return;
      }
      outlierConfirmed = true;
    }
    setState(() => _saving = true);
    try {
      final record = Measurement(_date, h, w, _method);
      if (_editingRecord == null) {
        await widget.app.saveMeasurement(
          record,
          outlierConfirmed: outlierConfirmed,
        );
      } else {
        await widget.app.updateMeasurement(
          _editingRecord!,
          record,
          outlierConfirmed: outlierConfirmed,
        );
      }
      if (!mounted) return;
      final messenger = ScaffoldMessenger.of(context);
      Navigator.pop(context);
      messenger.showSnackBar(
        SnackBar(
          content: Text(
            widget.app.connected
                ? 'Đã lưu số đo lên máy chủ'
                : 'Đã lưu số đo trong bản xem thử',
          ),
        ),
      );
    } catch (e) {
      if (mounted) showIssue(context, e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    padding: EdgeInsets.fromLTRB(
      20,
      0,
      20,
      MediaQuery.viewInsetsOf(context).bottom + 24,
    ),
    child: Form(
      key: _form,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            _editingRecord == null ? 'Nhập chỉ số' : 'Sửa số đo',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: () async {
              final d = await showDatePicker(
                context: context,
                initialDate: _date,
                firstDate: widget.app.baby.birthDate,
                lastDate: DateTime.now(),
              );
              if (d != null && mounted) {
                setState(() => _date = d);
              }
            },
            icon: const BabiIcon(Icons.calendar_today_outlined),
            label: Text(calendarDate(_date)),
          ),
          const SizedBox(height: 12),
          TextFormField(
            key: const Key('height-input'),
            controller: _height,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Chiều cao (cm)'),
            validator: (s) =>
                _number(s ?? '') == null ||
                    _number(s!)! <= 0 ||
                    !_number(s)!.isFinite
                ? 'Nhập số dương hợp lệ'
                : null,
          ),
          const SizedBox(height: 12),
          TextFormField(
            key: const Key('weight-input'),
            controller: _weight,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'Cân nặng (kg)'),
            validator: (s) =>
                _number(s ?? '') == null ||
                    _number(s!)! <= 0 ||
                    !_number(s)!.isFinite
                ? 'Nhập số dương hợp lệ'
                : null,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            key: ValueKey(_method),
            initialValue: _method,
            decoration: const InputDecoration(labelText: 'Cách đo'),
            items: const [
              DropdownMenuItem(value: 'lying', child: Text('Đo nằm')),
              DropdownMenuItem(value: 'standing', child: Text('Đo đứng')),
            ],
            onChanged: (v) => setState(() => _method = v!),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: Text(
              _saving
                  ? 'Đang lưu…'
                  : _editingRecord == null
                  ? 'Lưu số đo'
                  : 'Cập nhật số đo',
            ),
          ),
        ],
      ),
    ),
  );
}
