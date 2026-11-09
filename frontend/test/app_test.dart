import 'dart:io';
import 'dart:ui' as ui;

import 'package:babi/src/app.dart';
import 'package:babi/src/controller.dart';
import 'package:babi/src/models.dart';
import 'package:babi/src/screens/account.dart';
import 'package:babi/src/theme.dart';
import 'package:babi/src/widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

Future<void> loadFonts() async {
  final body = FontLoader('Be Vietnam Pro');
  for (final w in ['Regular', 'Medium', 'SemiBold', 'Bold']) {
    body.addFont(rootBundle.load('assets/fonts/BeVietnamPro-$w.ttf'));
  }
  await body.load();
  final icons = FontLoader('Material Symbols Rounded')
    ..addFont(rootBundle.load('assets/fonts/MaterialSymbolsRounded.ttf'));
  await icons.load();
}

Future<void> render(
  WidgetTester tester,
  BabiController app, {
  Size size = const Size(393, 852),
  double textScale = 1,
  bool previewChrome = true,
}) async {
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = size;
  await tester.pumpWidget(
    RepaintBoundary(
      key: const Key('screen-render'),
      child: MediaQuery(
        data: MediaQueryData(
          size: size,
          textScaler: TextScaler.linear(textScale),
        ),
        child: BabiApp(controller: app, previewChrome: previewChrome),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> exportRender(WidgetTester tester, String name) async {
  if (!const bool.fromEnvironment('BABI_EXPORT_RENDERS')) {
    return;
  }
  final boundary = tester.renderObject<RenderRepaintBoundary>(
    find.byKey(const Key('screen-render')),
  );
  await tester.runAsync(() async {
    final image = await boundary.toImage();
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    await Directory('artifacts').create(recursive: true);
    await File('artifacts/$name.png').writeAsBytes(bytes!.buffer.asUint8List());
    image.dispose();
  });
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(loadFonts);
  testWidgets('đăng nhập và đăng ký cuộn ổn trên khung điện thoại nhỏ', (
    tester,
  ) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(390, 844);
    final app = BabiController(seedDemo: false);
    addTearDown(app.dispose);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await tester.pumpWidget(
      RepaintBoundary(
        key: const Key('screen-render'),
        child: MaterialApp(
          theme: babiTheme(),
          home: AuthScreen(app: app),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await exportRender(tester, 'auth_login');
    expect(find.byKey(const Key('login-email')), findsOneWidget);
    expect(find.text('Quên mật khẩu?'), findsOneWidget);
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const Key('auth-register-tab')));
    await tester.pumpAndSettle();
    await exportRender(tester, 'auth_register');
    expect(find.byKey(const Key('login-name')), findsOneWidget);
    expect(find.byKey(const Key('auth-terms')), findsOneWidget);
    expect(tester.takeException(), isNull);
    tester.view.physicalSize = const Size(320, 640);
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
  testWidgets(
    'khởi động ở Timeline, người thân có Chụp nhưng không sửa sức khỏe',
    (tester) async {
      final app = BabiController();
      addTearDown(app.dispose);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await render(tester, app);
      expect(find.text('Bé Minh Khang'), findsOneWidget);
      expect(find.byKey(const Key('nav-capture')), findsOneWidget);
      await exportRender(tester, 'timeline');
      await tester.tap(find.byKey(const Key('nav-health')));
      await tester.pumpAndSettle();
      expect(find.text('Sức khỏe'), findsWidgets);
      expect(find.text('Nhật ký theo tháng'), findsOneWidget);
      expect(tester.takeException(), isNull);
      await exportRender(tester, 'health');
      await tester.tap(find.byKey(const Key('nav-vaccinations')));
      await tester.pumpAndSettle();
      expect(find.text('Lịch tiêm của bé'), findsOneWidget);
      expect(find.text('2–6 tháng'), findsWidgets);
      expect(tester.takeException(), isNull);
      await exportRender(tester, 'vaccinations');
      await tester.drag(find.byType(ListView).last, const Offset(0, -480));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('add-custom-vaccine')), findsOneWidget);
      await tester.drag(find.byType(ListView).last, const Offset(0, 900));
      await tester.pumpAndSettle();
      app.recordLocalVaccination(
        'Sởi · liều đầu',
        DateTime(2026, 10, 1),
        scheduleId: 'measles-1',
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Đã ghi nhận').first);
      await tester.pumpAndSettle();
      expect(find.text('Sởi · liều đầu'), findsOneWidget);
      app.setRole(FamilyRole.viewer);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('nav-capture')), findsOneWidget);
      expect(find.byType(ArcNavigation), findsOneWidget);
      await exportRender(tester, 'viewer');
      app.selectTab(AppTab.health);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('add-measurement')), findsNothing);
    },
  );
  testWidgets('caption lỗi hiện trên ảnh; nhập xong lưu về đầu Timeline', (
    tester,
  ) async {
    final app = BabiController();
    addTearDown(app.dispose);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    app.addMedia(const [
      MediaItem.asset('assets/figma/preview_media_illustration.svg'),
    ]);
    await render(tester, app);
    await exportRender(tester, 'preview');
    await tester.tap(find.byKey(const Key('save-memory')));
    await tester.pump();
    expect(find.byKey(const Key('caption-error')), findsOneWidget);
    expect(app.preview, isTrue);
    await tester.tap(find.byKey(const Key('caption-pill')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('caption-input')),
      'Kỷ niệm mới của bé',
    );
    await tester.tap(find.text('Xong'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('save-memory')));
    await tester.pumpAndSettle();
    expect(app.preview, isFalse);
    expect(app.memories.first.caption, 'Kỷ niệm mới của bé');
    expect(find.text('Đã lưu kỷ niệm'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
  testWidgets('chọn bé chưa có kỷ niệm có nút Chụp đầu tiên', (tester) async {
    final app = BabiController(seedDemo: false);
    addTearDown(app.dispose);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    await render(tester, app);
    expect(find.byKey(const Key('empty-capture')), findsOneWidget);
    app.setRole(FamilyRole.viewer);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('empty-capture')), findsOneWidget);
  });
  testWidgets('màn hình nhỏ và chữ lớn vẫn cuộn được, không tràn bố cục', (
    tester,
  ) async {
    final app = BabiController();
    addTearDown(app.dispose);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    for (final tab in [AppTab.home, AppTab.health, AppTab.vaccinations]) {
      app.selectTab(tab);
      await render(tester, app, size: const Size(320, 640), textScale: 1.3);
      final exception = tester.takeException();
      expect(exception, isNull, reason: 'Tab $tab');
    }
    app.addMedia(const [
      MediaItem.asset('assets/figma/preview_media_illustration.svg'),
    ]);
    await render(tester, app, size: const Size(320, 640), textScale: 1.3);
    expect(tester.takeException(), isNull);
  });
  if (const bool.fromEnvironment('BABI_DESIGN_PREVIEW')) {
    testWidgets('luồng ảnh mẫu Chụp → xem trước', (tester) async {
      final app = BabiController();
      addTearDown(app.dispose);
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      app.selectTab(AppTab.capture);
      await render(tester, app);
      await exportRender(tester, 'capture');
      expect(tester.takeException(), isNull);
      await tester.tap(find.byKey(const Key('shutter')));
      await tester.pumpAndSettle();
      expect(find.text('Lưu vào hồ sơ bé'), findsOneWidget);
    });
  }
}
