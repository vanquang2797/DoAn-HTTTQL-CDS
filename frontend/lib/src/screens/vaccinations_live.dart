import 'package:flutter/material.dart';

import '../controller.dart';
import '../data/api_client.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets.dart';
import 'family.dart' show VaccinationReminderSettings;

class LiveVaccinations extends StatefulWidget {
  const LiveVaccinations({
    super.key,
    required this.app,
    required this.onProfile,
  });
  final BabiController app;
  final VoidCallback onProfile;
  @override
  State<LiveVaccinations> createState() => _LiveVaccinationsState();
}

class _LiveVaccinationsState extends State<LiveVaccinations> {
  String _view = 'plan';
  bool get history => _view == 'recorded';
  List<Map<String, dynamic>> get _visibleSchedules => widget.app.vaccinations
      .where((schedule) {
        final overdue = schedule['status'] != 'recorded' &&
            schedule['date_label'] == 'date_passed';
        return _view == 'review' ? overdue : !overdue && schedule['status'] != 'recorded';
      })
      .toList();
  int get _overdueCount => widget.app.vaccinations
      .where((schedule) =>
          schedule['status'] != 'recorded' &&
          schedule['date_label'] == 'date_passed')
      .length;
  int get _recordedCount => widget.app.vaccinations
      .where((schedule) => schedule['status'] == 'recorded')
      .length;
  int get _reviewCount => widget.app.overdueVaccinationReviewCount;
  int get _upcomingCount => widget.app.vaccinations
      .where(
        (schedule) =>
            schedule['status'] != 'recorded' &&
            schedule['date_label'] == 'upcoming',
      )
      .length;
  String dateLabel(Map<String, dynamic> s) => switch (s['date_label']) {
    'needs_appointment' => 'Cần bổ sung ngày hẹn',
    'date_passed' =>
      s['date_basis'] == 'appointment'
          ? 'Đã qua ngày hẹn'
          : 'Đã qua ngày tham khảo',
    'upcoming' => 'Sắp đến ngày',
    _ => 'Chưa đến ngày',
  };
  String status(Map<String, dynamic> s) => switch (s['status']) {
    'recorded' => 'Đã ghi nhận tiêm',
    'pending' => 'Owner xác nhận chưa tiêm',
    _ => 'Chưa ghi nhận',
  };

  Future<void> _correctRecord(Map<String, dynamic> record) async {
    final correction = await showModalBottomSheet<_VaccinationCorrection>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _VaccinationCorrectionForm(
        record: record,
        birthDate: widget.app.baby.birthDate,
      ),
    );
    if (correction == null || !mounted) return;
    try {
      await widget.app.correctVaccination(
        record,
        reason: correction.reason,
        vaccineName: correction.vaccineName,
        administeredDate: correction.administeredDate,
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Đã đính chính lần tiêm và lưu lịch sử'),
          ),
        );
      }
    } catch (e) {
      if (mounted) showIssue(context, e);
    }
  }

  Future<void> _voidRecord(Map<String, dynamic> record) async {
    final reason = TextEditingController(text: 'Ghi nhận nhầm');
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Hủy bản ghi tiêm?'),
          content: TextField(
            controller: reason,
            autofocus: true,
            maxLength: 500,
            onChanged: (_) => setDialogState(() {}),
            decoration: const InputDecoration(labelText: 'Lý do hủy'),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext, false),
              child: const Text('Quay lại'),
            ),
            FilledButton(
              onPressed: reason.text.trim().isEmpty
                  ? null
                  : () => Navigator.pop(dialogContext, true),
              child: const Text('Hủy bản ghi'),
            ),
          ],
        ),
      ),
    );
    final reasonText = reason.text.trim();
    reason.dispose();
    if (confirmed != true || reasonText.isEmpty || !mounted) return;
    try {
      await widget.app.voidVaccination(record, reason: reasonText);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Đã hủy bản ghi, lịch sử vẫn được giữ')),
        );
      }
    } catch (e) {
      if (mounted) showIssue(context, e);
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    children: [
      PageHeader(
        title: 'Lịch tiêm của bé',
        subtitle: widget.app.baby.name,
        onProfile: widget.onProfile,
      ),
      if (widget.app.canCreate)
        Align(
          alignment: Alignment.centerRight,
          child: Padding(
            padding: const EdgeInsets.only(right: 20, top: 2),
            child: IconButton(
              key: const Key('vaccination-reminder-settings'),
              tooltip: 'Cài đặt nhắc lịch tiêm',
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.notifications_outlined),
              onPressed: _openReminderSettings,
            ),
          ),
        ),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20),
        child: SegmentedButton<String>(
          segments: const [
            ButtonSegment(
              value: 'plan',
              label: FittedBox(
                fit: BoxFit.scaleDown,
                child: Text('Kế hoạch', maxLines: 1, softWrap: false),
              ),
            ),
            ButtonSegment(
              value: 'review',
              label: FittedBox(
                fit: BoxFit.scaleDown,
                child: Text('Cần rà soát', maxLines: 1, softWrap: false),
              ),
            ),
            ButtonSegment(
              value: 'recorded',
              label: FittedBox(
                fit: BoxFit.scaleDown,
                child: Text('Đã ghi nhận', maxLines: 1, softWrap: false),
              ),
            ),
          ],
          showSelectedIcon: false,
          style: ButtonStyle(
            visualDensity: VisualDensity.compact,
            padding: const WidgetStatePropertyAll(
              EdgeInsets.symmetric(horizontal: 8, vertical: 8),
            ),
            textStyle: const WidgetStatePropertyAll(
              TextStyle(fontSize: 14, height: 1.2),
            ),
          ),
          selected: {_view},
          onSelectionChanged: (v) => setState(() => _view = v.first),
        ),
      ),
      const SizedBox(height: 12),
      Expanded(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
          children: [
            if (_view == 'plan' && widget.app.canCreate)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  key: const Key('add-custom-vaccine'),
                  onPressed: _addCustomVaccination,
                  icon: const Icon(Icons.add_rounded, size: 17),
                  label: const Text('Thêm mũi ngoài lịch'),
                ),
              ),
            if (history && widget.app.vaccinationRecords.isEmpty)
              const SurfaceCard(child: Text('Chưa có mũi tiêm được ghi nhận.')),
            if (!history && _visibleSchedules.isEmpty)
              SurfaceCard(
                child: Text(
                  _view == 'review'
                      ? 'Chưa có mũi tiêm quá ngày dự kiến cần rà soát.'
                      : widget.app.configuration['vaccine_catalog_ready'] == true
                      ? 'Chưa có mũi tiêm trong kế hoạch.'
                      : 'Lịch tiêm tham khảo chưa được cấu hình.',
                ),
              ),
            if (history)
              for (final record in widget.app.vaccinationRecords)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: SurfaceCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          record['vaccine_name'] as String,
                          style: Theme.of(context).textTheme.titleMedium,
                        ),
                        Text(
                          record['status'] == 'voided'
                              ? 'Đã hủy bản ghi nhầm'
                              : 'Lần tiêm đã ghi nhận',
                        ),
                        Text(
                          record['administered_date'] == null
                              ? 'Chưa biết ngày tiêm'
                              : 'Ngày tiêm: ${calendarDate(DateTime.parse(record['administered_date'] as String))}',
                        ),
                        Text(
                          record['source'] == 'document'
                              ? 'Nguồn: giấy tờ'
                              : 'Nguồn: phụ huynh cung cấp',
                        ),
                        if (widget.app.canCreate &&
                            record['status'] == 'active') ...[
                          const Divider(height: 20),
                          Wrap(
                            spacing: 8,
                            children: [
                              TextButton.icon(
                                key: Key('correct-vaccination-${record['id']}'),
                                onPressed: () => _correctRecord(record),
                                icon: const Icon(Icons.edit_outlined, size: 17),
                                label: const Text('Đính chính'),
                              ),
                              TextButton.icon(
                                key: Key('void-vaccination-${record['id']}'),
                                onPressed: () => _voidRecord(record),
                                icon: const Icon(Icons.undo_rounded, size: 17),
                                label: const Text('Hủy bản ghi'),
                              ),
                            ],
                          ),
                        ],
                      ],
                    ),
                  ),
                ),
            if (_view != 'recorded') ...[
              if (widget.app.canCreate && _view == 'review' && _overdueCount > 0) ...[
                OverdueVaccinationNotice(count: _overdueCount),
                const SizedBox(height: 12),
              ],
              if (_view == 'plan') ...[
                if (widget.app.canCreate && _reviewCount > 0) ...[
                  OverdueVaccinationNotice(
                    count: _reviewCount,
                    onReview: () => setState(() => _view = 'review'),
                  ),
                  const SizedBox(height: 12),
                ],
                _progressCard(context),
                const SizedBox(height: 12),
              ],
              for (final schedule in _visibleSchedules)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(16),
                    onTap: () => showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      builder: (_) =>
                          VaccinationForm(app: widget.app, schedule: schedule),
                    ),
                    child: _scheduleCard(context, schedule),
                  ),
                ),
            ],
          ],
        ),
      ),
    ],
  );

  Future<void> _openReminderSettings() async {
    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        contentPadding: const EdgeInsets.all(12),
        content: SingleChildScrollView(
          child: VaccinationReminderSettings(app: widget.app),
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

  Widget _progressCard(BuildContext context) {
    final total = widget.app.vaccinations.length;
    final progress = total == 0 ? 0.0 : _recordedCount / total;
    return SurfaceCard(
      color: BabiColors.selected,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const CircleAvatar(
                radius: 20,
                backgroundColor: Colors.white,
                child: BabiIcon(
                  Icons.vaccines_outlined,
                  color: BabiColors.primary,
                  size: 21,
                ),
              ),
              const SizedBox(width: 11),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Tiến độ tiêm chủng',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    Text(
                      total == 0
                          ? 'Chưa có dữ liệu lịch tiêm'
                          : '$_recordedCount / $total mũi đã ghi nhận',
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ),
              ),
            ],
          ),
          if (total > 0) ...[
            const SizedBox(height: 14),
            ClipRRect(
              borderRadius: BorderRadius.circular(20),
              child: LinearProgressIndicator(
                value: progress,
                minHeight: 7,
                backgroundColor: Colors.white.withValues(alpha: .8),
                color: BabiColors.success,
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: [
                _summaryPill('$_reviewCount cần kiểm tra', BabiColors.error),
                _summaryPill(
                  '$_upcomingCount sắp đến',
                  const Color(0xFF9C6B00),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  Future<void> _addCustomVaccination() async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _CustomVaccinationForm(
        birthDate: widget.app.baby.birthDate,
        onSave: (name, date) => widget.app.recordVaccination(null, name, date),
      ),
    );
    if (saved == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Đã ghi nhận mũi tiêm ngoài lịch')),
      );
    }
  }

  Widget _summaryPill(String label, Color color) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
    decoration: BoxDecoration(
      color: Colors.white.withValues(alpha: .75),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Text(
      label,
      style: textStyle(11, line: 15, weight: FontWeight.w600, color: color),
    ),
  );

  Widget _scheduleCard(BuildContext context, Map<String, dynamic> schedule) {
    final recorded = schedule['status'] == 'recorded';
    final passed = !recorded && schedule['date_label'] == 'date_passed';
    final upcoming = !recorded && schedule['date_label'] == 'upcoming';
    final pending = schedule['status'] == 'pending';
    final color = recorded
        ? BabiColors.success
        : passed
        ? BabiColors.error
        : upcoming || pending
        ? const Color(0xFF9C6B00)
        : BabiColors.secondary;
    final background = recorded
        ? BabiColors.successBg
        : passed
        ? const Color(0xFFFCE8E6)
        : upcoming || pending
        ? const Color(0xFFFFF2CC)
        : const Color(0xFFFFF7F0);
    final icon = recorded
        ? Icons.check_circle_outline_rounded
        : passed
        ? Icons.error_outline_rounded
        : upcoming || pending
        ? Icons.warning_amber_rounded
        : Icons.schedule_rounded;
    final badge = recorded
        ? 'Đã tiêm'
        : pending
        ? 'Phụ huynh xác nhận chưa tiêm'
        : passed
        ? (schedule['date_basis'] == 'appointment'
              ? 'Đã qua ngày hẹn · cần kiểm tra'
              : 'Đã qua ngày tham khảo · cần kiểm tra')
        : upcoming
        ? 'Sắp đến ngày'
        : dateLabel(schedule);
    final date = schedule['effective_date'] as String?;
    return SurfaceCard(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              BabiIcon(icon, color: color, size: 21),
              const SizedBox(width: 8),
              Expanded(
                child: Container(
                  alignment: Alignment.center,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: background,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    badge,
                    textAlign: TextAlign.center,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: textStyle(
                      11,
                      line: 15,
                      weight: FontWeight.w600,
                      color: color,
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 9),
          Text(
            schedule['name'] as String,
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 3),
          Text(
            date == null
                ? 'Chưa có ngày hẹn hoặc ngày tham khảo'
                : '${schedule['date_basis'] == 'appointment' ? 'Ngày hẹn' : 'Ngày tham khảo'}: ${calendarDate(DateTime.parse(date))}',
            style: Theme.of(context).textTheme.bodySmall,
          ),
          if (schedule['dose_number'] != null)
            Text(
              'Liều ${schedule['dose_number']}',
              style: Theme.of(context).textTheme.bodySmall,
            ),
          if (passed &&
              widget.app.canCreate &&
              schedule['status'] == 'unrecorded') ...[
            const SizedBox(height: 6),
            Text(
              'Chạm để ghi nhận đã tiêm hoặc xác nhận chưa tiêm.',
              style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: BabiColors.error,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _CustomVaccinationForm extends StatefulWidget {
  const _CustomVaccinationForm({required this.birthDate, required this.onSave});
  final DateTime birthDate;
  final Future<void> Function(String, DateTime) onSave;
  @override
  State<_CustomVaccinationForm> createState() => _CustomVaccinationFormState();
}

class _CustomVaccinationFormState extends State<_CustomVaccinationForm> {
  final _name = TextEditingController();
  DateTime? _date;
  bool _saving = false;
  String? _error;
  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || _name.text.trim().isEmpty || _date == null) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.onSave(_name.text.trim(), _date!);
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    child: Padding(
      padding: EdgeInsets.fromLTRB(
        20,
        18,
        20,
        MediaQuery.viewInsetsOf(context).bottom + 24,
      ),
      child: AbsorbPointer(
        absorbing: _saving,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              'Thêm mũi tiêm ngoài lịch',
              style: textStyle(17, line: 23, weight: FontWeight.w600),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _name,
              onChanged: (_) => setState(() {}),
              decoration: const InputDecoration(labelText: 'Tên vắc xin'),
            ),
            OutlinedButton.icon(
              onPressed: () async {
                final date = await showDatePicker(
                  context: context,
                  initialDate: _date ?? DateTime.now(),
                  firstDate: widget.birthDate,
                  lastDate: DateTime.now(),
                );
                if (date != null && mounted) setState(() => _date = date);
              },
              icon: const Icon(Icons.calendar_month_outlined, size: 18),
              label: Text(
                _date == null ? 'Chọn ngày đã tiêm' : calendarDate(_date!),
              ),
            ),
            if (_error != null) Text(_error!),
            FilledButton(
              onPressed: _name.text.trim().isEmpty || _date == null || _saving
                  ? null
                  : _save,
              child: Text(_saving ? 'Đang lưu…' : 'Lưu ghi nhận'),
            ),
          ],
        ),
      ),
    ),
  );
}

class VaccinationForm extends StatefulWidget {
  const VaccinationForm({super.key, required this.app, required this.schedule});
  final BabiController app;
  final Map<String, dynamic> schedule;
  @override
  State<VaccinationForm> createState() => _VaccinationFormState();
}

class _VaccinationFormState extends State<VaccinationForm> {
  final source = TextEditingController();
  final vaccine = TextEditingController();
  DateTime? appointment, administered;
  bool unknown = false, saving = false;
  String? error;
  @override
  void initState() {
    super.initState();
    vaccine.text = widget.schedule['name'] as String;
    source.text = widget.schedule['appointment_source'] as String? ?? '';
    appointment = DateTime.tryParse(
      widget.schedule['appointment_date'] as String? ?? '',
    );
  }

  @override
  void dispose() {
    source.dispose();
    vaccine.dispose();
    super.dispose();
  }

  Future<void> perform(Future<void> Function() write) async {
    if (saving) return;
    setState(() {
      saving = true;
      error = null;
    });
    try {
      await write();
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (e is ApiException && (e.status == 409 || e.status == 403)) {
        await widget.app.refreshSelected();
      }
      if (mounted) setState(() => error = e.toString());
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  Future<DateTime?> pick({bool future = false}) => showDatePicker(
    context: context,
    initialDate: future
        ? appointment ?? DateTime.now()
        : administered ?? DateTime.now(),
    firstDate: widget.app.baby.birthDate,
    lastDate: future ? DateTime(DateTime.now().year + 5) : DateTime.now(),
  );

  Future<void> _confirmNotAdministered() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Xác nhận bé chưa tiêm?'),
        content: const Text(
          'Chỉ xác nhận khi bạn đã kiểm tra sổ tiêm hoặc hỏi cơ sở tiêm. '
          'Ngày tham khảo đã qua không tự chứng minh bé chưa được tiêm.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Quay lại'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Xác nhận chưa tiêm'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    await perform(
      () => widget.app.markVaccinationPending(
        (widget.schedule['id'] as num).toInt(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    padding: EdgeInsets.fromLTRB(
      20,
      0,
      20,
      MediaQuery.viewInsetsOf(context).bottom + 24,
    ),
    child: AbsorbPointer(
      absorbing: saving,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            widget.schedule['name'] as String,
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 12),
          if (widget.schedule['timing_note'] != null)
            Text(widget.schedule['timing_note'] as String),
          if (widget.app.canCreate &&
              widget.schedule['status'] != 'recorded') ...[
            const SizedBox(height: 16),
            Text(
              'Ngày hẹn từ cơ sở tiêm',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            OutlinedButton(
              onPressed: () async {
                final date = await pick(future: true);
                if (date != null && mounted) setState(() => appointment = date);
              },
              child: Text(
                appointment == null
                    ? 'Chọn ngày hẹn'
                    : calendarDate(appointment!),
              ),
            ),
            TextField(
              controller: source,
              decoration: const InputDecoration(
                labelText: 'Tên cơ sở / nguồn ngày hẹn',
              ),
            ),
            FilledButton(
              onPressed: () => perform(() async {
                if (appointment == null || source.text.trim().isEmpty) {
                  throw const ApiException(
                    422,
                    'Chọn ngày hẹn và nhập nguồn thông tin.',
                  );
                }
                await widget.app.saveAppointment(
                  (widget.schedule['id'] as num).toInt(),
                  appointment,
                  source.text.trim(),
                );
              }),
              child: const Text('Lưu ngày hẹn'),
            ),
            if (widget.schedule['status'] == 'pending')
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  'Bạn đã xác nhận bé chưa tiêm mũi này. Khi có thông tin thực tế, hãy ghi nhận ngày tiêm bên dưới.',
                  style: Theme.of(context).textTheme.bodySmall,
                ),
              )
            else
              OutlinedButton.icon(
                key: Key('mark-vaccination-pending-${widget.schedule['id']}'),
                onPressed: saving ? null : _confirmNotAdministered,
                icon: const Icon(Icons.check_circle_outline_rounded, size: 18),
                label: const Text('Xác nhận bé chưa tiêm'),
              ),
            const Divider(height: 32),
            Text(
              'Ghi nhận lần tiêm thực tế',
              style: Theme.of(context).textTheme.titleMedium,
            ),
            TextField(
              controller: vaccine,
              decoration: const InputDecoration(
                labelText: 'Tên vắc xin theo sổ / thông tin thực tế',
              ),
            ),
            CheckboxListTile(
              contentPadding: EdgeInsets.zero,
              value: unknown,
              onChanged: (v) => setState(() => unknown = v!),
              title: const Text('Đã tiêm nhưng chưa biết ngày chính xác'),
            ),
            if (!unknown)
              OutlinedButton(
                onPressed: () async {
                  final date = await pick();
                  if (date != null && mounted) {
                    setState(() => administered = date);
                  }
                },
                child: Text(
                  administered == null
                      ? 'Chọn ngày tiêm thực tế'
                      : calendarDate(administered!),
                ),
              ),
            FilledButton(
              onPressed: () => perform(() async {
                if (vaccine.text.trim().isEmpty ||
                    !unknown && administered == null) {
                  throw const ApiException(
                    422,
                    'Nhập tên vắc xin và ngày thực tế, hoặc chọn chưa biết ngày.',
                  );
                }
                await widget.app.recordVaccination(
                  (widget.schedule['id'] as num).toInt(),
                  vaccine.text.trim(),
                  unknown ? null : administered,
                );
              }),
              child: const Text('Xác nhận ghi nhận lần tiêm'),
            ),
          ],
          if (error != null)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Text(
                error!,
                style: const TextStyle(color: BabiColors.error),
              ),
            ),
          if (saving) const LinearProgressIndicator(),
        ],
      ),
    ),
  );
}

class _VaccinationCorrection {
  const _VaccinationCorrection({
    required this.reason,
    required this.vaccineName,
    required this.administeredDate,
  });

  final String reason;
  final String vaccineName;
  final DateTime? administeredDate;
}

class _VaccinationCorrectionForm extends StatefulWidget {
  const _VaccinationCorrectionForm({
    required this.record,
    required this.birthDate,
  });

  final Map<String, dynamic> record;
  final DateTime birthDate;

  @override
  State<_VaccinationCorrectionForm> createState() =>
      _VaccinationCorrectionFormState();
}

class _VaccinationCorrectionFormState
    extends State<_VaccinationCorrectionForm> {
  late final TextEditingController _name;
  late final TextEditingController _reason;
  DateTime? _date;
  bool _unknown = false;

  @override
  void initState() {
    super.initState();
    _name = TextEditingController(
      text: widget.record['vaccine_name'] as String,
    );
    _reason = TextEditingController();
    final date = widget.record['administered_date'] as String?;
    _date = date == null ? null : DateTime.tryParse(date);
    _unknown = widget.record['date_precision'] == 'unknown' || date == null;
  }

  @override
  void dispose() {
    _name.dispose();
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.fromLTRB(
      20,
      20,
      20,
      MediaQuery.viewInsetsOf(context).bottom + 24,
    ),
    child: SingleChildScrollView(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Đính chính lần tiêm',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _name,
            onChanged: (_) => setState(() {}),
            decoration: const InputDecoration(labelText: 'Tên vắc xin'),
          ),
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            value: _unknown,
            onChanged: (value) => setState(() => _unknown = value ?? false),
            title: const Text('Chưa biết ngày tiêm chính xác'),
          ),
          if (!_unknown)
            OutlinedButton.icon(
              onPressed: () async {
                final now = DateTime.now();
                final date = await showDatePicker(
                  context: context,
                  initialDate: _date ?? widget.birthDate,
                  firstDate: widget.birthDate,
                  lastDate: now,
                );
                if (date != null && mounted) setState(() => _date = date);
              },
              icon: const Icon(Icons.calendar_month_outlined, size: 18),
              label: Text(
                _date == null ? 'Chọn ngày tiêm' : calendarDate(_date!),
              ),
            ),
          TextField(
            controller: _reason,
            onChanged: (_) => setState(() {}),
            maxLength: 500,
            decoration: const InputDecoration(
              labelText: 'Lý do đính chính',
              hintText: 'Ví dụ: nhập nhầm ngày theo sổ tiêm',
            ),
          ),
          const SizedBox(height: 8),
          FilledButton(
            onPressed:
                _name.text.trim().isEmpty ||
                    _reason.text.trim().isEmpty ||
                    (!_unknown && _date == null)
                ? null
                : () => Navigator.pop(
                    context,
                    _VaccinationCorrection(
                      reason: _reason.text.trim(),
                      vaccineName: _name.text.trim(),
                      administeredDate: _unknown ? null : _date,
                    ),
                  ),
            child: const Text('Lưu đính chính'),
          ),
        ],
      ),
    ),
  );
}
