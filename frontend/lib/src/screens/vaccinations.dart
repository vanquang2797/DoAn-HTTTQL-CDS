import 'package:flutter/material.dart';

import '../theme.dart';
import '../widgets.dart';
import '../controller.dart';
import '../models.dart';
import 'vaccinations_live.dart';

class VaccinationsScreen extends StatefulWidget {
  const VaccinationsScreen({super.key, required this.onProfile, this.app});
  final VoidCallback onProfile;
  final BabiController? app;
  @override
  State<VaccinationsScreen> createState() => _VaccinationsScreenState();
}

class _VaccinationsScreenState extends State<VaccinationsScreen> {
  bool appointments = false;

  int get _ageMonths {
    final baby = widget.app?.baby;
    if (baby == null) return 0;
    final today = DateTime.now();
    var months =
        (today.year - baby.birthDate.year) * 12 +
        today.month -
        baby.birthDate.month;
    if (today.day < baby.birthDate.day) months--;
    return months.clamp(0, 60);
  }

  @override
  Widget build(BuildContext context) => widget.app?.connected == true
      ? LiveVaccinations(app: widget.app!, onProfile: widget.onProfile)
      : Column(
          children: [
            PageHeader(
              title: 'Lịch tiêm của bé',
              subtitle: widget.app?.baby.name ?? 'Lịch tham khảo theo tuổi',
              onProfile: widget.onProfile,
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 22),
                children: [
                  SizedBox(
                    height: 40,
                    child: Row(
                      children: [
                        for (final value in [false, true])
                          Expanded(
                            child: TextButton(
                              onPressed: () =>
                                  setState(() => appointments = value),
                              style: TextButton.styleFrom(
                                backgroundColor: appointments == value
                                    ? BabiColors.selected
                                    : Colors.white.withValues(alpha: .78),
                                foregroundColor: BabiColors.ink,
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(15),
                                ),
                              ),
                              child: Text(value ? 'Đã ghi nhận' : 'Kế hoạch'),
                            ),
                          ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 10),
                  if (!appointments) ...[
                    _ageSuggestionCard(context),
                    const SizedBox(height: 10),
                    SurfaceCard(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 12,
                        vertical: 4,
                      ),
                      child: Column(
                        children: [
                          for (var i = 0; i < _vaccineStages.length; i++)
                            _stageTile(context, _vaccineStages[i], i),
                        ],
                      ),
                    ),
                    const SizedBox(height: 10),
                    if (widget.app?.canCreate == true)
                      OutlinedButton.icon(
                        key: const Key('add-custom-vaccine'),
                        onPressed: () => _addCustomVaccine(context),
                        icon: const Icon(Icons.add_rounded, size: 18),
                        label: const Text('Thêm mũi tiêm ngoài lịch'),
                      ),
                  ] else
                    _recordHistory(context),
                ],
              ),
            ),
          ],
        );

  Widget _ageSuggestionCard(BuildContext context) {
    final age = _ageMonths;
    final stage = age < 2
        ? 'Sơ sinh'
        : age < 7
        ? '2–6 tháng'
        : age < 12
        ? '9 tháng'
        : age < 15
        ? '12 tháng'
        : '15–24 tháng';
    final suggestion = age >= 12
        ? 'Đề xuất cho bé 1 tuổi: hỏi cơ sở tiêm về viêm não Nhật Bản theo lịch địa phương; MMR, thủy đậu và viêm gan A là lựa chọn cần trao đổi theo tiền sử và loại vắc xin.'
        : 'Nhóm tuổi hiện tại: $stage. Đối chiếu sổ tiêm để xem liều đến hạn hoặc cần tiêm bù.';
    return SurfaceCard(
      color: const Color(0xFFEAF5F0),
      padding: const EdgeInsets.all(12),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const BabiIcon(
            Icons.event_available_rounded,
            color: BabiColors.success,
            size: 20,
          ),
          const SizedBox(width: 9),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Bé $_ageMonths tháng · kế hoạch tham khảo',
                  style: textStyle(12, line: 16, weight: FontWeight.w600),
                ),
                const SizedBox(height: 4),
                Text(
                  suggestion,
                  style: textStyle(10, line: 15, color: BabiColors.secondary),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _stageTile(BuildContext context, _VaccineStage stage, int index) {
    final records =
        widget.app?.localRecordsForBaby ?? const <Map<String, dynamic>>[];
    final recorded = stage.vaccines
        .where((v) => records.any((r) => r['schedule_id'] == v.id))
        .length;
    final expand = _stageForAge(_ageMonths) == index;
    return ExpansionTile(
      key: PageStorageKey('vaccine-stage-${stage.id}'),
      initiallyExpanded: expand,
      tilePadding: EdgeInsets.zero,
      childrenPadding: const EdgeInsets.only(bottom: 8),
      title: Text(
        stage.title,
        style: textStyle(13, line: 18, weight: FontWeight.w600),
      ),
      subtitle: Text(
        '$recorded/${stage.vaccines.length} mũi đã ghi',
        style: textStyle(10, line: 14, color: BabiColors.secondary),
      ),
      children: [
        for (final vaccine in stage.vaccines)
          _vaccineRow(context, vaccine, records),
      ],
    );
  }

  Widget _vaccineRow(
    BuildContext context,
    _VaccineItem vaccine,
    List<Map<String, dynamic>> records,
  ) {
    final record = records
        .where((r) => r['schedule_id'] == vaccine.id)
        .firstOrNull;
    final logged = record != null;
    final date = logged ? record['date'] as DateTime : null;
    return Container(
      margin: const EdgeInsets.only(bottom: 7),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: logged ? BabiColors.successBg : const Color(0xFFFFFBF7),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(
          color: logged ? const Color(0xFFD6EBDD) : const Color(0xFFF0E6DC),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(
                logged ? Icons.check_circle_rounded : Icons.vaccines_outlined,
                color: logged ? BabiColors.success : BabiColors.primary,
                size: 17,
              ),
              const SizedBox(width: 7),
              Expanded(
                child: Text(
                  vaccine.name,
                  style: textStyle(
                    12,
                    line: 16,
                    weight: FontWeight.w600,
                    color: logged ? BabiColors.secondary : BabiColors.ink,
                  ),
                ),
              ),
              if (vaccine.service)
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 7,
                    vertical: 3,
                  ),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFFF2E8),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    'Trao đổi',
                    style: textStyle(9, line: 12, color: BabiColors.primary),
                  ),
                ),
            ],
          ),
          Padding(
            padding: const EdgeInsets.only(left: 24, top: 3),
            child: Text(
              vaccine.note,
              style: textStyle(10, line: 14, color: BabiColors.secondary),
            ),
          ),
          const SizedBox(height: 5),
          Align(
            alignment: Alignment.centerRight,
            child: logged
                ? Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        'Đã tiêm · ${calendarDate(date!)}',
                        style: textStyle(
                          10,
                          line: 14,
                          color: BabiColors.success,
                        ),
                      ),
                      if (widget.app?.canCreate == true)
                        IconButton(
                          tooltip: 'Bỏ ghi nhận',
                          visualDensity: VisualDensity.compact,
                          onPressed: () => widget.app!.removeLocalVaccination(
                            vaccine.name,
                            scheduleId: vaccine.id,
                          ),
                          icon: const Icon(Icons.undo_rounded, size: 16),
                        ),
                    ],
                  )
                : widget.app?.canCreate == true
                ? TextButton.icon(
                    key: Key('log-vaccine-${vaccine.id}'),
                    onPressed: () => _logVaccine(context, vaccine),
                    icon: const Icon(Icons.add_task_rounded, size: 15),
                    label: const Text('Ghi nhận đã tiêm'),
                    style: TextButton.styleFrom(
                      visualDensity: VisualDensity.compact,
                      foregroundColor: BabiColors.primary,
                    ),
                  )
                : const SizedBox.shrink(),
          ),
        ],
      ),
    );
  }

  Widget _recordHistory(BuildContext context) {
    final records =
        widget.app?.localRecordsForBaby ?? const <Map<String, dynamic>>[];
    if (records.isEmpty) {
      return const SurfaceCard(child: Text('Chưa có mũi tiêm được ghi nhận.'));
    }
    return SurfaceCard(
      child: Column(
        children: [
          for (final record in records)
            ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(
                Icons.check_circle,
                color: BabiColors.success,
                size: 19,
              ),
              title: Text(
                record['name'] as String,
                style: textStyle(12, line: 16, weight: FontWeight.w600),
              ),
              subtitle: Text(
                calendarDate(record['date'] as DateTime),
                style: textStyle(10, line: 14, color: BabiColors.secondary),
              ),
            ),
        ],
      ),
    );
  }

  Future<void> _logVaccine(BuildContext context, _VaccineItem vaccine) async {
    final date = await showDatePicker(
      context: context,
      initialDate: DateTime.now(),
      firstDate: widget.app!.baby.birthDate,
      lastDate: DateTime.now(),
    );
    if (date != null && mounted) {
      widget.app!.recordLocalVaccination(
        vaccine.name,
        date,
        scheduleId: vaccine.id,
      );
    }
  }

  Future<void> _addCustomVaccine(BuildContext context) async {
    final result = await showModalBottomSheet<_CustomVaccine>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _CustomVaccineForm(birthDate: widget.app!.baby.birthDate),
    );
    if (result != null && mounted) {
      widget.app!.recordLocalVaccination(result.name, result.date);
    }
  }
}

int _stageForAge(int months) => months < 2
    ? 0
    : months < 7
    ? 1
    : months < 12
    ? 2
    : months < 15
    ? 3
    : 4;

class _VaccineItem {
  const _VaccineItem(this.id, this.name, this.note, {this.service = false});
  final String id, name, note;
  final bool service;
}

class _VaccineStage {
  const _VaccineStage(this.id, this.title, this.vaccines);
  final String id, title;
  final List<_VaccineItem> vaccines;
}

const _vaccineStages = [
  _VaccineStage('newborn', 'Sơ sinh', [
    _VaccineItem(
      'hep-b-birth',
      'Viêm gan B · liều sơ sinh',
      'Trong 24 giờ đầu sau sinh theo chương trình quốc gia.',
    ),
    _VaccineItem(
      'bcg',
      'Lao (BCG)',
      'Tiêm sớm theo lịch tiêm chủng mở rộng và đánh giá của cơ sở tiêm.',
    ),
  ]),
  _VaccineStage('2-6m', '2–6 tháng', [
    _VaccineItem(
      'dtp-series',
      'Bạch hầu – ho gà – uốn ván (loạt cơ bản)',
      'Bắt đầu từ 2 tháng; số liều và khoảng cách theo loại vắc xin, sổ tiêm.',
    ),
    _VaccineItem(
      'polio-series',
      'Bại liệt (OPV/IPV)',
      'Lịch phối hợp theo chương trình và các mũi bé đã nhận.',
    ),
    _VaccineItem(
      'hib-series',
      'Hib / vắc xin phối hợp',
      'Đối chiếu thành phần trong vắc xin phối hợp để tránh trùng liều.',
    ),
    _VaccineItem(
      'rota-series',
      'Rota',
      'Cửa sổ tuổi phụ thuộc sản phẩm; hỏi cơ sở tiêm nếu bé chưa bắt đầu.',
    ),
    _VaccineItem(
      'pcv-series',
      'Phế cầu liên hợp (PCV)',
      'Lịch triển khai và sản phẩm có thể khác theo địa phương; xác nhận tại điểm tiêm.',
    ),
  ]),
  _VaccineStage('9m', '9 tháng', [
    _VaccineItem(
      'measles-1',
      'Sởi · liều đầu',
      'Mốc thường dùng trong chương trình; chiến dịch có thể thay đổi theo dịch tễ.',
    ),
  ]),
  _VaccineStage('12m', '12 tháng', [
    _VaccineItem(
      'je-1',
      'Viêm não Nhật Bản · xem xét liều đầu',
      'Phụ thuộc lịch địa phương/vùng lưu hành, sản phẩm và tiền sử tiêm.',
    ),
    _VaccineItem(
      'mmr',
      'Sởi – quai bị – rubella (MMR)',
      'Vắc xin dịch vụ; trao đổi tuổi tiêm và mũi sởi trước đó.',
      service: true,
    ),
    _VaccineItem(
      'varicella',
      'Thủy đậu',
      'Lựa chọn dịch vụ; lịch liều phụ thuộc tuổi và sản phẩm.',
      service: true,
    ),
    _VaccineItem(
      'hepatitis-a',
      'Viêm gan A',
      'Lựa chọn theo dịch tễ, tiền sử và hướng dẫn của nhân viên y tế.',
      service: true,
    ),
  ]),
  _VaccineStage('15-24m', '15–24 tháng', [
    _VaccineItem(
      'dtp-booster',
      'Mũi nhắc bạch hầu – ho gà – uốn ván',
      'Mốc nhắc và thành phần cần được đối chiếu với lịch quốc gia/sổ tiêm.',
    ),
    _VaccineItem(
      'measles-booster',
      'Sởi / sởi – rubella · mũi tiếp theo',
      'Hoàn tất số liều theo lịch quốc gia và chiến dịch đang áp dụng.',
    ),
    _VaccineItem(
      'je-followup',
      'Viêm não Nhật Bản · liều tiếp theo',
      'Chỉ theo phác đồ của loại vắc xin và cơ sở tiêm.',
    ),
    _VaccineItem(
      'service-review',
      'Rà soát các vắc xin dịch vụ',
      'MMR, thủy đậu, viêm gan A và các mũi khác: hỏi lịch phù hợp.',
      service: true,
    ),
  ]),
];

class _CustomVaccine {
  const _CustomVaccine(this.name, this.date);
  final String name;
  final DateTime date;
}

class _CustomVaccineForm extends StatefulWidget {
  const _CustomVaccineForm({required this.birthDate});
  final DateTime birthDate;
  @override
  State<_CustomVaccineForm> createState() => _CustomVaccineFormState();
}

class _CustomVaccineFormState extends State<_CustomVaccineForm> {
  final _name = TextEditingController();
  DateTime? _date;
  @override
  void dispose() {
    _name.dispose();
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
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(
          'Thêm mũi tiêm ngoài lịch',
          style: textStyle(17, line: 23, weight: FontWeight.w600),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _name,
          autofocus: true,
          onChanged: (_) => setState(() {}),
          decoration: const InputDecoration(labelText: 'Tên vắc xin'),
        ),
        const SizedBox(height: 8),
        OutlinedButton.icon(
          onPressed: () async {
            final picked = await showDatePicker(
              context: context,
              initialDate: DateTime.now(),
              firstDate: widget.birthDate,
              lastDate: DateTime.now(),
            );
            if (picked != null && mounted) setState(() => _date = picked);
          },
          icon: const Icon(Icons.calendar_month_outlined, size: 18),
          label: Text(
            _date == null ? 'Chọn ngày đã tiêm' : calendarDate(_date!),
          ),
        ),
        const SizedBox(height: 8),
        FilledButton(
          onPressed: _name.text.trim().isEmpty || _date == null
              ? null
              : () => Navigator.pop(
                  context,
                  _CustomVaccine(_name.text.trim(), _date!),
                ),
          child: const Text('Lưu ghi nhận'),
        ),
      ],
    ),
  );
}
