import 'dart:async';

import 'package:flutter/foundation.dart';

import 'data/api_client.dart';

import 'models.dart';

/// Demo data is isolated from authenticated API sessions.
class BabiController extends ChangeNotifier {
  BabiController({this.seedDemo = true, this.api}) {
    if (api != null) {
      babies.clear();
      api!.onUnauthorized = _expireSession;
    }
    if (seedDemo && api == null) {
      memories.addAll([
        MemoryItem(
          babyId: 'khang',
          caption: 'Lần đầu con ngồi vững. Cả nhà vui thật nhiều!',
          date: '20/09/2026',
          likes: 12,
          comments: 4,
          milestone: 'Ngồi vững',
          media: const [
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
          ],
        ),
        MemoryItem(
          babyId: 'khang',
          caption: 'Một buổi chiều bình yên bên con.',
          date: '15/09/2026',
          likes: 12,
          comments: 4,
          media: const [
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
          ],
        ),
        MemoryItem(
          babyId: 'khang',
          caption: 'Nụ cười nhỏ, niềm vui thật lớn.',
          date: '10/09/2026',
          likes: 12,
          comments: 4,
          media: const [
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
            MediaItem.asset('assets/figma/timeline_memory_image1610.svg'),
          ],
        ),
      ]);
      measurements['khang'] = [
        Measurement(DateTime(2026, 9, 20), 70.5, 8.2, 'lying'),
        Measurement(DateTime(2026, 9, 15), 70, 8, 'lying'),
      ];
    }
  }
  final bool seedDemo;
  final ApiClient? api;
  bool get connected => api != null;
  bool loading = false, restoring = true;
  String? issue;
  Map<String, dynamic>? user;
  Map<String, dynamic> configuration = {};
  final familyMembers = <Map<String, dynamic>>[];
  final familyInvitations = <Map<String, dynamic>>[];
  final familyJoinLinks = <Map<String, dynamic>>[];
  final familyJoinRequests = <Map<String, dynamic>>[];
  final vaccinations = <Map<String, dynamic>>[];
  int get overdueVaccinationReviewCount => vaccinations
      .where(
        (schedule) =>
            schedule['status'] == 'unrecorded' &&
            schedule['date_label'] == 'date_passed',
      )
      .length;
  final vaccinationRecords = <Map<String, dynamic>>[];
  final localVaccinationRecords = <Map<String, dynamic>>[];
  final milestoneOptions = <Map<String, dynamic>>[];
  final _roles = <String, FamilyRole>{};
  Timer? _poll;
  int _session = 0, _load = 0;
  bool _disposed = false;
  String? _draftOperation;
  DateTime? _draftDate;
  DateTime get draftDate => _draftDate ?? DateTime.now();
  void setDraftDate(DateTime date) {
    if (busy || !canContribute) return;
    final selected = DateTime(date.year, date.month, date.day);
    final birth = DateTime(
      baby.birthDate.year,
      baby.birthDate.month,
      baby.birthDate.day,
    );
    final now = DateTime.now();
    final today = DateTime(now.year, now.month, now.day);
    if (selected.isBefore(birth) || selected.isAfter(today)) {
      throw const FormatException(
        'Ngày kỷ niệm phải từ ngày sinh đến hôm nay.',
      );
    }
    _draftDate = selected;
    _draftOperation = null;
    _emit();
  }

  bool get authenticated => user != null;
  bool get emailVerified => user?['email_verified_at'] != null;
  bool get hasBaby => babies.isNotEmpty;
  String get displayName => user?['name'] as String? ?? 'Gia đình Babi';
  static const maxFiles = int.fromEnvironment(
    'BABI_MAX_FILES',
    defaultValue: 10,
  );
  static const maxVideoSeconds = int.fromEnvironment(
    'BABI_MAX_VIDEO_SECONDS',
    defaultValue: 30,
  );
  final babies = [
    Baby('khang', 'Minh Khang', DateTime(2026, 1, 20)),
    Baby('nhi', 'An Nhi', DateTime(2026, 3, 8)),
  ];
  int babyIndex = 0;
  Baby get baby => babies[babyIndex];
  FamilyRole role = FamilyRole.owner;
  bool get ownsFamilySpace => _roles.values.contains(FamilyRole.owner);
  bool get canCreateFamilySpace => _roles.isEmpty || ownsFamilySpace;
  bool get canCreate =>
      role == FamilyRole.owner && (!connected || authenticated && hasBaby);
  bool get canContribute => !connected || authenticated && hasBaby;
  AppTab tab = AppTab.home;
  bool preview = false, busy = false;
  String caption = '', filter = 'Tất cả';
  String? milestone;
  int currentMedia = 0, savedCount = 0, timelineRevision = 0;
  final draft = <MediaItem>[];
  final memories = <MemoryItem>[];
  final measurements = <String, List<Measurement>>{};
  List<MemoryItem> get visibleMemories => memories
      .where(
        (m) =>
            m.babyId == baby.id &&
            switch (filter) {
              'Video' => m.media.any((f) => f.isVideo),
              'Cột mốc' || 'Lần đầu' => m.milestone != null,
              _ => true,
            },
      )
      .toList();
  List<Measurement> get records => measurements[baby.id] ?? [];
  List<Map<String, dynamic>> get localRecordsForBaby => localVaccinationRecords
      .where((record) => record['baby_id'] == baby.id)
      .toList();

  void recordLocalVaccination(
    String name,
    DateTime date, {
    String? scheduleId,
  }) {
    if (!canCreate || connected) {
      throw StateError('Không thể ghi nhận mũi tiêm ở chế độ này.');
    }
    localVaccinationRecords.removeWhere(
      (record) =>
          record['baby_id'] == baby.id &&
          (scheduleId == null
              ? record['schedule_id'] == null && record['name'] == name
              : record['schedule_id'] == scheduleId),
    );
    localVaccinationRecords.add({
      'baby_id': baby.id,
      'name': name,
      'date': date,
      'schedule_id': scheduleId,
    });
    notifyListeners();
  }

  void removeLocalVaccination(String name, {String? scheduleId}) {
    localVaccinationRecords.removeWhere(
      (record) =>
          record['baby_id'] == baby.id &&
          (scheduleId == null
              ? record['schedule_id'] == null && record['name'] == name
              : record['schedule_id'] == scheduleId),
    );
    notifyListeners();
  }

  void selectTab(AppTab value) {
    if (busy || value == AppTab.capture && !canContribute) {
      return;
    }
    tab = value;
    preview = false;
    notifyListeners();
  }

  void selectBaby(int index) {
    if (busy) {
      return;
    }
    babyIndex = index;
    if (connected) {
      role = _roles[baby.spaceId] ?? FamilyRole.viewer;
      memories.clear();
      measurements.clear();
      vaccinations.clear();
      vaccinationRecords.clear();
      familyMembers.clear();
      familyInvitations.clear();
      familyJoinLinks.clear();
      familyJoinRequests.clear();
      // Keep the selected media when switching between profiles the user can
      // create for. A viewer profile still clears it through this guard.
      if (!canContribute) clearDraft();
      unawaited(refreshSelected());
    }
    timelineRevision++;
    notifyListeners();
  }

  void setRole(FamilyRole value) {
    if (connected) return;
    if (busy) {
      return;
    }
    role = value;
    if (!canContribute) {
      clearDraft();
      if (tab == AppTab.capture) {
        tab = AppTab.home;
      }
    }
    notifyListeners();
  }

  void setFilter(String value) {
    filter = value;
    notifyListeners();
  }

  void setCaption(String value) {
    if (busy) return;
    if (caption != value) _draftOperation = null;
    caption = value;
    notifyListeners();
  }

  void setMilestone(String? value) {
    if (busy) return;
    if (milestone != value) _draftOperation = null;
    milestone = value;
    notifyListeners();
  }

  void viewMedia(int index) {
    currentMedia = index;
    notifyListeners();
  }

  void addMedia(List<MediaItem> files) {
    if (!canContribute || busy) {
      throw StateError('Bạn không có quyền tạo kỷ niệm.');
    }
    if (draft.length + files.length > maxFiles) {
      throw FormatException('Chọn tối đa $maxFiles tệp cho một kỷ niệm.');
    }
    if (files.isEmpty) {
      return;
    }
    draft.addAll(files);
    _draftOperation = null;
    currentMedia = draft.length - files.length;
    preview = true;
    tab = AppTab.capture;
    notifyListeners();
  }

  void removeCurrent() {
    if (busy || draft.isEmpty) {
      return;
    }
    draft.removeAt(currentMedia);
    _draftOperation = null;
    if (draft.isEmpty) {
      clearDraft();
    } else if (currentMedia >= draft.length) {
      currentMedia = draft.length - 1;
    }
    notifyListeners();
  }

  void clearDraft() {
    draft.clear();
    caption = '';
    milestone = null;
    preview = false;
    currentMedia = 0;
    _draftOperation = null;
    _draftDate = null;
  }

  Future<void> saveDraft() async {
    if (!canContribute || busy) {
      throw StateError('Bạn không có quyền tạo kỷ niệm.');
    }
    if (caption.trim().isEmpty) {
      throw const FormatException('Caption không được để trống');
    }
    if (draft.isEmpty) {
      throw const FormatException('Hãy chọn ít nhất một ảnh hoặc video.');
    }
    busy = true;
    savedCount = 0;
    notifyListeners();
    try {
      if (connected) {
        _draftOperation ??= operationKey();
        _draftDate ??= DateTime.now();
        final match = milestoneOptions.where((m) => m['name'] == milestone);
        await api!.uploadMemory(
          childId: baby.id,
          caption: caption.trim(),
          memoryDate: isoDate(_draftDate!),
          files: List.of(draft),
          operation: _draftOperation!,
          milestoneId: match.isEmpty
              ? null
              : (match.first['id'] as num).toInt(),
        );
        clearDraft();
        tab = AppTab.home;
        filter = 'Tất cả';
        await refreshSelected();
        return;
      }
      for (var i = 0; i < draft.length; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 180));
        if (!canContribute) {
          throw StateError('Quyền truy cập đã thay đổi.');
        }
        savedCount = i + 1;
        notifyListeners();
      }
      memories.insert(
        0,
        MemoryItem(
          babyId: baby.id,
          caption: caption.trim(),
          date: 'Vừa xong',
          media: List.of(draft),
          milestone: milestone,
        ),
      );
      clearDraft();
      tab = AppTab.home;
      filter = 'Tất cả';
      timelineRevision++;
    } on ApiException catch (e) {
      if (e.status == 403) await refreshSelected();
      rethrow;
    } finally {
      busy = false;
      _emit();
    }
  }

  void like(MemoryItem item) {
    if (connected) {
      unawaited(_likeRemote(item));
      return;
    }
    item.liked = !item.liked;
    item.likes += item.liked ? 1 : -1;
    notifyListeners();
  }

  void addMeasurement(Measurement record) {
    if (connected) throw StateError('Dùng saveMeasurement để lưu lên máy chủ.');
    if (!canCreate) {
      throw StateError('Bạn chỉ có quyền xem.');
    }
    if (!record.height.isFinite ||
        !record.weight.isFinite ||
        record.height <= 0 ||
        record.weight <= 0) {
      throw const FormatException('Số đo phải là số dương hợp lệ.');
    }
    if (record.date.isBefore(baby.birthDate) ||
        record.date.isAfter(DateTime.now())) {
      throw const FormatException('Ngày đo không hợp lệ.');
    }
    final list = measurements.putIfAbsent(baby.id, () => []);
    if (list.any(
      (m) =>
          m.date.year == record.date.year &&
          m.date.month == record.date.month &&
          m.date.day == record.date.day,
    )) {
      throw const FormatException('Ngày này đã có số đo.');
    }
    list.add(record);
    list.sort((a, b) => b.date.compareTo(a.date));
    notifyListeners();
  }

  void _emit() {
    if (!_disposed) notifyListeners();
  }

  void markEmailVerified() {
    if (user == null || emailVerified) return;
    user = {...user!, 'email_verified_at': DateTime.now().toIso8601String()};
    _emit();
  }

  Future<void> refreshAccount() async {
    if (!connected || !authenticated) return;
    user =
        (await api!.request('GET', 'auth/me'))['data'] as Map<String, dynamic>;
    _emit();
  }

  void _clearPrivate() {
    _session++;
    _load++;
    _poll?.cancel();
    user = null;
    babies.clear();
    memories.clear();
    measurements.clear();
    vaccinations.clear();
    vaccinationRecords.clear();
    familyMembers.clear();
    familyInvitations.clear();
    familyJoinLinks.clear();
    familyJoinRequests.clear();
    milestoneOptions.clear();
    _roles.clear();
    babyIndex = 0;
    role = FamilyRole.viewer;
    tab = AppTab.home;
    clearDraft();
  }

  void _expireSession() {
    _clearPrivate();
    issue = 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.';
    _emit();
  }

  Future<void> initialize() async {
    if (!connected) {
      restoring = false;
      return;
    }
    try {
      await api!.restore();
      if (api!.token != null) {
        user =
            (await api!.request('GET', 'auth/me'))['data']
                as Map<String, dynamic>;
        await refreshProfiles();
      }
    } catch (e) {
      issue = e.toString();
    } finally {
      restoring = false;
      _emit();
    }
  }

  Future<void> authenticate(
    String email,
    String password, {
    String? name,
  }) async {
    busy = true;
    issue = null;
    _emit();
    try {
      _clearPrivate();
      user = await api!.authenticate(email, password, name: name);
      await refreshProfiles();
    } finally {
      busy = false;
      _emit();
    }
  }

  Future<void> logout() async {
    if (busy) return;
    busy = true;
    _emit();
    try {
      // Keep the session on network failure so server revocation can be confirmed.
      await api!.request('POST', 'auth/logout');
      await api!.clear();
      _clearPrivate();
      issue = null;
    } finally {
      busy = false;
      _emit();
    }
  }

  Future<void> clearSessionAfterPasswordReset() async {
    await api?.clear();
    _clearPrivate();
    issue = null;
    restoring = false;
    _emit();
  }

  Future<void> refreshProfiles() async {
    final session = _session;
    final results = await Future.wait([
      api!.request('GET', 'child-profiles'),
      api!.request('GET', 'family-spaces'),
      api!.request('GET', 'milestones'),
      api!.request('GET', 'app-config'),
    ]);
    if (_disposed || session != _session) return;
    final selected = hasBaby ? baby.id : null;
    _roles.clear();
    for (final s in results[1]['data'] as List) {
      _roles['${s['id']}'] = s['role'] == 'owner'
          ? FamilyRole.owner
          : FamilyRole.viewer;
    }
    babies
      ..clear()
      ..addAll(
        (results[0]['data'] as List).map(
          (v) => Baby.fromJson(v as Map<String, dynamic>),
        ),
      );
    milestoneOptions
      ..clear()
      ..addAll((results[2]['data'] as List).cast<Map<String, dynamic>>());
    configuration = results[3]['data'] as Map<String, dynamic>;
    babyIndex = babies.indexWhere((b) => b.id == selected);
    if (babyIndex < 0) babyIndex = 0;
    if (hasBaby) {
      role = _roles[baby.spaceId] ?? FamilyRole.viewer;
      await refreshSelected();
    } else {
      memories.clear();
      measurements.clear();
      vaccinations.clear();
      vaccinationRecords.clear();
      familyMembers.clear();
      familyInvitations.clear();
      familyJoinLinks.clear();
      familyJoinRequests.clear();
    }
    _emit();
  }

  MemoryItem _memory(Map<String, dynamic> m) {
    final item = MemoryItem(
      id: '${m['id']}',
      babyId: '${m['child_profile_id']}',
      caption: m['caption'] as String,
      date: calendarDate(DateTime.parse(m['memory_date'] as String)),
      author: m['author'] as String? ?? '',
      status: m['status'] as String,
      milestone: m['milestone'] as String?,
      likes: (m['likes'] as num).toInt(),
      media: (m['media'] as List).map((f) {
        final url = Uri.parse(api!.baseUrl)
            .resolve(f['url'] as String)
            .toString();
        return MediaItem.remote(
          url,
          headers: Map.of(api!.headers),
          isVideo: f['file_type'] == 'video',
          mimeType: f['mime_type'] as String?,
        );
      }).toList(),
    );
    item.liked = m['liked'] == true;
    return item;
  }

  Future<void> refreshSelected({bool timelineOnly = false}) async {
    if (!connected || !authenticated || !hasBaby) return;
    final id = baby.id, space = baby.spaceId;
    final session = _session, load = ++_load;
    loading = true;
    issue = null;
    _emit();
    try {
      final child =
          (await api!.request('GET', 'child-profiles/$id'))['data']
              as Map<String, dynamic>;
      final results = await Future.wait([
        api!.allPages('child-profiles/$id/memories'),
        if (!timelineOnly) api!.allPages('child-profiles/$id/health-records'),
        if (!timelineOnly) api!.allPages('child-profiles/$id/vaccinations'),
        if (!timelineOnly)
          api!.allPages('child-profiles/$id/vaccination-records'),
      ]);
      final members = await api!.request('GET', 'family-spaces/$space/members');
      final memberData = (members['data'] as List).cast<Map<String, dynamic>>();
      final own = memberData.where(
        (m) => '${m['user_id']}' == '${user!['id']}',
      );
      final nextRole = own.isNotEmpty && own.first['role'] == 'owner'
          ? FamilyRole.owner
          : FamilyRole.viewer;
      final invitations = !timelineOnly && nextRole == FamilyRole.owner
          ? await api!.request('GET', 'family-spaces/$space/invitations')
          : null;
      final joinManagement = !timelineOnly && nextRole == FamilyRole.owner
          ? await Future.wait([
              api!.request('GET', 'family-spaces/$space/join-links'),
              api!.request('GET', 'family-spaces/$space/join-requests'),
            ])
          : null;
      if (_disposed ||
          session != _session ||
          load != _load ||
          !hasBaby ||
          baby.id != id) {
        return;
      }
      babies[babyIndex] = Baby.fromJson(child);
      familyMembers
        ..clear()
        ..addAll(memberData);
      role = nextRole;
      if (!timelineOnly) {
        familyInvitations
          ..clear()
          ..addAll(
            invitations == null
                ? const <Map<String, dynamic>>[]
                : (invitations['data'] as List).cast<Map<String, dynamic>>(),
          );
        familyJoinLinks
          ..clear()
          ..addAll(
            joinManagement == null
                ? const <Map<String, dynamic>>[]
                : (joinManagement[0]['data'] as List)
                      .cast<Map<String, dynamic>>(),
          );
        familyJoinRequests
          ..clear()
          ..addAll(
            joinManagement == null
                ? const <Map<String, dynamic>>[]
                : (joinManagement[1]['data'] as List)
                      .cast<Map<String, dynamic>>(),
          );
      }
      if (!canContribute) {
        clearDraft();
        if (tab == AppTab.capture) tab = AppTab.home;
      }
      memories
        ..clear()
        ..addAll(results[0].map(_memory));
      if (!timelineOnly) {
        measurements[id] =
            results[1]
                .map(
                  (v) => Measurement(
                    DateTime.parse(v['measured_at'] as String),
                    double.parse('${v['height_cm']}'),
                    double.parse('${v['weight_kg']}'),
                    v['measurement_method'] as String,
                    id: v['id']?.toString(),
                    version: (v['version'] as num?)?.toInt() ?? 0,
                  ),
                )
                .toList()
              ..sort((a, b) => b.date.compareTo(a.date));
        vaccinations
          ..clear()
          ..addAll(results[2]);
        vaccinationRecords
          ..clear()
          ..addAll(results[3]);
      }
      _poll?.cancel();
      if (memories.any((m) => m.status == 'uploading')) {
        _poll = Timer(
          const Duration(seconds: 15),
          () => unawaited(refreshSelected(timelineOnly: true)),
        );
      }
    } catch (e) {
      if (session == _session && load == _load && !_disposed) {
        issue = e.toString();
        if (e is ApiException && (e.status == 401 || e.status == 403)) {
          memories.clear();
          measurements.clear();
          vaccinations.clear();
          vaccinationRecords.clear();
          familyMembers.clear();
          familyInvitations.clear();
          familyJoinLinks.clear();
          familyJoinRequests.clear();
          clearDraft();
          role = FamilyRole.viewer;
        }
      }
    } finally {
      if (load == _load) loading = false;
      _emit();
    }
  }

  Future<void> createBaby({
    required String name,
    required DateTime birthDate,
    required String gender,
    required String operation,
    MediaItem? avatar,
    double? birthWeightKg,
    double? birthLengthCm,
  }) async {
    final result = await api!.createChildProfile(
      {
        'name': name,
        'date_of_birth': isoDate(birthDate),
        'gender': gender,
        'birth_weight_kg': birthWeightKg,
        'birth_length_cm': birthLengthCm,
        'representative_confirmed': true,
        'storage_consent': true,
        'family_sharing_acknowledged': true,
        'policy_version': configuration['policy_version'],
      },
      operation: operation,
      avatar: avatar,
    );
    await refreshProfiles();
    final index = babies.indexWhere(
      (baby) => baby.id == '${result['data']['id']}',
    );
    if (index >= 0) selectBaby(index);
  }

  Future<void> updateBabyProfile({
    required String name,
    required DateTime birthDate,
    required String gender,
    double? birthWeightKg,
    double? birthLengthCm,
  }) async {
    if (!connected || !canCreate || busy) {
      throw StateError('Chỉ Owner mới có thể cập nhật hồ sơ bé.');
    }
    await api!.request(
      'PUT',
      'child-profiles/${baby.id}',
      body: {
        'name': name,
        'date_of_birth': isoDate(birthDate),
        'gender': gender,
        'birth_weight_kg': birthWeightKg,
        'birth_length_cm': birthLengthCm,
        'expected_data_revision': baby.dataRevision,
      },
    );
    await refreshProfiles();
  }

  Future<void> saveMeasurement(
    Measurement record, {
    bool outlierConfirmed = false,
  }) async {
    if (!connected) {
      addMeasurement(record);
      return;
    }
    if (!canCreate || busy) {
      throw StateError('Bạn không có quyền hoặc đang lưu dữ liệu.');
    }
    final id = baby.id;
    busy = true;
    _emit();
    try {
      await api!.request(
        'POST',
        'health-records',
        body: {
          'child_profile_id': int.parse(id),
          'measured_at': isoDate(record.date),
          'height_cm': record.height,
          'weight_kg': record.weight,
          'measurement_method': record.method,
          'expected_data_revision': baby.dataRevision,
          'outlier_confirmed': outlierConfirmed,
        },
      );
      await refreshSelected();
    } on ApiException catch (e) {
      if (e.status == 409 || e.status == 403) await refreshSelected();
      rethrow;
    } finally {
      busy = false;
      _emit();
    }
  }

  Future<void> updateMeasurement(
    Measurement original,
    Measurement updated, {
    bool outlierConfirmed = false,
  }) async {
    if (!connected) {
      final list = measurements[baby.id];
      if (list == null) return;
      final index = list.indexOf(original);
      if (index >= 0) list[index] = updated;
      _emit();
      return;
    }
    if (!canCreate || busy || original.id == null) {
      throw StateError('Bạn không có quyền hoặc số đo không còn hợp lệ.');
    }
    busy = true;
    _emit();
    try {
      await api!.request(
        'PATCH',
        'health-records/${original.id}',
        body: {
          'measured_at': isoDate(updated.date),
          'height_cm': updated.height,
          'weight_kg': updated.weight,
          'measurement_method': updated.method,
          'expected_version': original.version,
          'expected_data_revision': baby.dataRevision,
          'outlier_confirmed': outlierConfirmed,
        },
      );
      await refreshSelected();
    } on ApiException catch (e) {
      if (e.status == 409 || e.status == 403) await refreshSelected();
      rethrow;
    } finally {
      busy = false;
      _emit();
    }
  }

  Future<void> deleteMeasurement(Measurement record) async {
    if (!connected) {
      measurements[baby.id]?.remove(record);
      _emit();
      return;
    }
    if (!canCreate || busy || record.id == null) {
      throw StateError('Bạn không có quyền hoặc số đo không còn hợp lệ.');
    }
    busy = true;
    _emit();
    try {
      await api!.request(
        'DELETE',
        'health-records/${record.id}',
        body: {
          'expected_version': record.version,
          'expected_data_revision': baby.dataRevision,
          'confirmed': true,
        },
      );
      await refreshSelected();
    } on ApiException catch (e) {
      if (e.status == 409 || e.status == 403) await refreshSelected();
      rethrow;
    } finally {
      busy = false;
      _emit();
    }
  }

  Future<void> _likeRemote(MemoryItem item) async {
    if (busy || item.id == null) return;
    busy = true;
    _emit();
    try {
      await api!.request(
        'POST',
        'memories/${item.id}/reactions',
        body: {'reaction_type': 'love'},
      );
      await refreshSelected(timelineOnly: true);
    } catch (e) {
      issue = e.toString();
    } finally {
      busy = false;
      _emit();
    }
  }

  Future<void> retryMemory(MemoryItem item) async {
    if (busy || item.id == null || !canContribute) return;
    busy = true;
    _emit();
    try {
      await api!.request('POST', 'memories/${item.id}/retry');
      await refreshSelected(timelineOnly: true);
    } finally {
      busy = false;
      _emit();
    }
  }

  Future<String> invite(String email, String type) async {
    final result = await api!.request(
      'POST',
      'family-spaces/${baby.spaceId}/invitations',
      body: {'email': email, 'invitation_type': type, 'confirmed': true},
    );
    final delivery = result['data']['delivery'] as String;
    await refreshSelected();
    if (delivery == 'failed') {
      throw const ApiException(
        503,
        'Đã lưu lời mời, nhưng gửi thư thất bại. Cần gửi lại lời mời.',
      );
    }
    return delivery;
  }

  Future<Map<String, dynamic>> createFamilyJoinLink() async {
    if (!connected || !canContribute || !hasBaby) {
      throw StateError('Bạn cần là thành viên gia đình để chia sẻ link.');
    }
    final result = await api!.request(
      'POST',
      'family-spaces/${baby.spaceId}/join-links',
    );
    final data = Map<String, dynamic>.from(result['data'] as Map);
    familyJoinLinks.insert(0, {
      'id': data['id'],
      'expires_at': data['expires_at'],
      'status': 'active',
      'created_at': DateTime.now().toUtc().toIso8601String(),
    });
    _emit();
    return data;
  }

  Future<void> revokeFamilyJoinLink(String id) async {
    await api!.request('DELETE', 'family-join-links/$id');
    await refreshSelected();
  }

  Future<Map<String, dynamic>> previewFamilyJoin(String token) async {
    final result = await api!.request(
      'POST',
      'family-space-join-requests/preview',
      body: {'token': token},
    );
    return Map<String, dynamic>.from(result['data'] as Map);
  }

  Future<Map<String, dynamic>> requestFamilyJoin(String token) async {
    final result = await api!.request(
      'POST',
      'family-space-join-requests',
      body: {'token': token},
    );
    return Map<String, dynamic>.from(result['data'] as Map);
  }

  Future<Map<String, dynamic>> familyJoinRequestStatus(String id) async {
    final result = await api!.request('GET', 'family-space-join-requests/$id');
    return Map<String, dynamic>.from(result['data'] as Map);
  }

  Future<void> approveFamilyJoinRequest(String id) async {
    await api!.request('POST', 'family-join-requests/$id/approve');
    await refreshSelected();
  }

  Future<void> declineFamilyJoinRequest(String id) async {
    await api!.request('POST', 'family-join-requests/$id/decline');
    await refreshSelected();
  }

  Future<void> revokeInvitation(String id) async {
    await api!.request('POST', 'family-invitations/$id/revoke');
    await refreshSelected();
  }

  Future<String> resendInvitation(String id) async {
    final result = await api!.request('POST', 'family-invitations/$id/resend');
    final delivery = result['data']['delivery'] as String;
    await refreshSelected();
    if (delivery == 'failed') {
      throw const ApiException(503, 'Không gửi lại được email lời mời.');
    }
    return delivery;
  }

  Future<void> demoteMember(String id) async {
    await api!.request(
      'PATCH',
      'family-members/$id',
      body: {'action': 'demote'},
    );
    await refreshSelected();
  }

  Future<void> removeMember(String id) async {
    await api!.request(
      'DELETE',
      'family-members/$id',
      body: {'confirmed': true},
    );
    await refreshSelected();
  }

  Future<void> acceptInvitation(String token) async {
    await api!.request(
      'POST',
      'family-invitations/accept',
      body: {'token': token},
    );
    await refreshProfiles();
  }

  Future<Map<String, dynamic>> previewInvitation(String token) async {
    final result = await api!.request(
      'POST',
      'family-invitations/preview',
      body: {'token': token},
    );
    return Map<String, dynamic>.from(result['data'] as Map);
  }

  Future<void> declineInvitation(String token) async {
    await api!.request(
      'POST',
      'family-invitations/decline',
      body: {'token': token},
    );
  }

  Future<void> saveAppointment(
    int scheduleId,
    DateTime? date,
    String source,
  ) async {
    await api!.request(
      'PUT',
      'vaccinations/$scheduleId/appointment',
      body: {
        'appointment_date': date == null ? null : isoDate(date),
        'appointment_source': source,
        'expected_data_revision': baby.dataRevision,
      },
    );
    await refreshSelected();
  }

  Future<void> markVaccinationPending(int scheduleId) async {
    await api!.request(
      'PATCH',
      'vaccinations/$scheduleId/mark-pending',
      body: {'confirmed': true, 'expected_data_revision': baby.dataRevision},
    );
    await refreshSelected();
  }

  Future<void> recordVaccination(
    int? scheduleId,
    String name,
    DateTime? date,
  ) async {
    await api!.request(
      'POST',
      'child-profiles/${baby.id}/vaccination-records',
      body: {
        'vaccine_name': name,
        'date_precision': date == null ? 'unknown' : 'exact',
        'administered_date': date == null ? null : isoDate(date),
        'vaccination_schedule_id': scheduleId,
        'source': 'parent_report',
        'expected_data_revision': baby.dataRevision,
      },
    );
    await refreshSelected();
  }

  Future<void> correctVaccination(
    Map<String, dynamic> record, {
    required String reason,
    required String vaccineName,
    required DateTime? administeredDate,
  }) async {
    await api!.request(
      'PATCH',
      'vaccination-records/${record['id']}',
      body: {
        'action': 'correct',
        'reason': reason,
        'expected_version': record['version'],
        'expected_data_revision': baby.dataRevision,
        'vaccine_name': vaccineName,
        'dose_number': record['dose_number'],
        'date_precision': administeredDate == null ? 'unknown' : 'exact',
        'administered_date': administeredDate == null
            ? null
            : isoDate(administeredDate),
        'vaccination_schedule_id': record['vaccination_schedule_id'],
        'facility': record['facility'],
        'source': record['source'],
        'notes': record['notes'],
      },
    );
    await refreshSelected();
  }

  Future<void> voidVaccination(
    Map<String, dynamic> record, {
    required String reason,
  }) async {
    await api!.request(
      'POST',
      'vaccination-records/${record['id']}/void',
      body: {
        'reason': reason,
        'expected_version': record['version'],
        'expected_data_revision': baby.dataRevision,
      },
    );
    await refreshSelected();
  }

  @override
  void dispose() {
    _disposed = true;
    _session++;
    _load++;
    _poll?.cancel();
    api?.onUnauthorized = null;
    api?.close();
    super.dispose();
  }
}
