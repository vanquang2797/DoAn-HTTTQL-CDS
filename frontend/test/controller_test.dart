import 'dart:typed_data';

import 'package:babi/src/controller.dart';
import 'package:babi/src/models.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';

const photo = MediaItem.asset('assets/figma/preview_media_illustration.svg');
void main() {
  test('caption trống giữ nguyên bản nháp, không tạo kỷ niệm', () async {
    final app = BabiController(seedDemo: false);
    app.addMedia([photo]);
    app.setCaption('   ');
    await expectLater(app.saveDraft(), throwsFormatException);
    expect(app.draft, hasLength(1));
    expect(app.memories, isEmpty);
    expect(app.preview, isTrue);
    app.dispose();
  });
  test(
    'lưu đúng bé, trim caption, chặn gửi hai lần và trở về Timeline',
    () async {
      final app = BabiController(seedDemo: false);
      app.selectBaby(1);
      app.addMedia([photo]);
      app.setCaption('  Một ngày vui  ');
      final saving = app.saveDraft();
      await expectLater(app.saveDraft(), throwsStateError);
      await saving;
      expect(app.memories.single.babyId, 'nhi');
      expect(app.memories.single.caption, 'Một ngày vui');
      expect(app.draft, isEmpty);
      expect(app.tab, AppTab.home);
      expect(app.busy, isFalse);
      app.dispose();
    },
  );
  test('Người thân đóng góp kỷ niệm nhưng không sửa số đo', () {
    final app = BabiController(seedDemo: false);
    app.addMedia([photo]);
    app.setRole(FamilyRole.viewer);
    expect(app.draft, hasLength(1));
    expect(app.tab, AppTab.capture);
    app.selectTab(AppTab.capture);
    expect(app.tab, AppTab.capture);
    app.addMedia([photo]);
    expect(app.draft, hasLength(2));
    expect(app.canCreate, isFalse);
    app.dispose();
  });
  test('vượt giới hạn tệp không làm mất bản nháp hiện tại', () {
    final app = BabiController(seedDemo: false);
    app.addMedia([photo]);
    expect(
      () => app.addMedia(List.filled(BabiController.maxFiles, photo)),
      throwsFormatException,
    );
    expect(app.draft, hasLength(1));
    app.dispose();
  });
  test('xóa tệp cuối trở về Chụp, không còn caption cũ', () {
    final app = BabiController(seedDemo: false);
    app.addMedia([photo]);
    app.setCaption('Caption');
    app.removeCurrent();
    expect(app.preview, isFalse);
    expect(app.caption, isEmpty);
    app.dispose();
  });
  test('đổi hồ sơ bé khi lưu không làm mất ảnh đang chọn', () {
    final app = BabiController(seedDemo: false);
    app.addMedia([photo]);
    app.setCaption('Khoảnh khắc của con');
    app.selectBaby(1);
    expect(app.baby.id, 'nhi');
    expect(app.draft, [photo]);
    expect(app.caption, 'Khoảnh khắc của con');
    expect(app.preview, isTrue);
    app.dispose();
  });
  test('không nhận tệp chỉ đổi đuôi thành PNG', () async {
    final file = XFile.fromData(
      Uint8List.fromList([1, 2, 3, 4, 5, 6, 7, 8]),
      name: 'fake.png',
      mimeType: 'image/png',
    );
    await expectLater(MediaItem.fromFile(file), throwsFormatException);
  });
  test('ảnh PNG quá 10 MB bị từ chối trước khi nạp toàn bộ', () async {
    final bytes = Uint8List(10 * 1024 * 1024 + 1)
      ..setAll(0, [137, 80, 78, 71, 13, 10, 26, 10]);
    await expectLater(
      MediaItem.fromFile(
        XFile.fromData(bytes, name: 'large.png', mimeType: 'image/png'),
      ),
      throwsFormatException,
    );
  });
  test(
    'số đo không ghi đè ngầm cùng ngày, từ chối số âm và ngày tương lai',
    () {
      final app = BabiController(seedDemo: false);
      final date = DateTime(2026, 5, 1);
      app.addMeasurement(Measurement(date, 60, 6, 'lying'));
      expect(
        () => app.addMeasurement(Measurement(date, 61, 6.2, 'lying')),
        throwsFormatException,
      );
      expect(
        () => app.addMeasurement(
          Measurement(DateTime(2026, 5, 2), -1, 6, 'lying'),
        ),
        throwsFormatException,
      );
      expect(
        () => app.addMeasurement(
          Measurement(
            DateTime.now().add(const Duration(days: 1)),
            60,
            6,
            'lying',
          ),
        ),
        throwsFormatException,
      );
      expect(app.records, hasLength(1));
      app.dispose();
    },
  );
}
