import 'package:camera_platform_interface/camera_platform_interface.dart';
import 'package:babi/src/controller.dart';
import 'package:babi/src/screens/capture.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('camera failure explains permissions and disables capture', (
    tester,
  ) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(393, 852);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
    final platform = _FakeCameraPlatform(
      initializeError: StateError('camera permission denied'),
    );
    final originalPlatform = CameraPlatform.instance;
    CameraPlatform.instance = platform;
    addTearDown(() => CameraPlatform.instance = originalPlatform);
    final app = BabiController();
    addTearDown(app.dispose);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SizedBox(
            height: 900,
            child: CaptureScreen(
              app: app,
              onGallery: () async {},
              onBaby: () {},
              referencePreview: false,
            ),
          ),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 100));
    await tester.pump(const Duration(milliseconds: 100));

    expect(
      find.textContaining('Không mở được camera hoặc micro'),
      findsOneWidget,
    );
    expect(
      find.textContaining('Kiểm tra quyền Camera/Microphone'),
      findsOneWidget,
    );
    expect(find.byKey(const Key('sample-photo')), findsOneWidget);
    final shutter = tester.widget<GestureDetector>(
      find.byKey(const Key('shutter')),
    );
    expect(shutter.onTap, isNull);
    expect(shutter.onLongPressStart, isNull);
    await tester.ensureVisible(find.byKey(const Key('sample-photo')));
    await tester.tap(find.byKey(const Key('sample-photo')));
    await tester.pump();
    expect(app.preview, isTrue);
  });

  testWidgets('photo tap and hold recording save image and WebM draft media', (
    tester,
  ) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(393, 723);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final platform = _FakeCameraPlatform();
    final originalPlatform = CameraPlatform.instance;
    CameraPlatform.instance = platform;
    addTearDown(() => CameraPlatform.instance = originalPlatform);
    final app = BabiController();
    addTearDown(app.dispose);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: SizedBox(
            child: CaptureScreen(
              app: app,
              onGallery: () async {},
              onBaby: () {},
              referencePreview: false,
            ),
          ),
          bottomNavigationBar: const SizedBox(height: 98),
        ),
      ),
    );
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    expect(find.byKey(const Key('shutter')), findsOneWidget);
    expect(
      tester.getRect(find.byKey(const Key('shutter'))).bottom,
      lessThanOrEqualTo(723 - 98),
      reason: 'The shutter must stay above the bottom navigation on a short screen.',
    );

    await tester.tap(find.byKey(const Key('shutter')));
    await tester.pump(const Duration(milliseconds: 100));
    expect(platform.photosTaken, 1);
    expect(app.draft.single.mimeType, 'image/png');

    await tester.longPress(find.byKey(const Key('shutter')));
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    expect(platform.recordingsStarted, 1);
    expect(platform.recordingsStopped, 1);
    expect(app.draft.map((media) => media.mimeType), [
      'image/png',
      'video/webm',
    ]);
  });

  testWidgets('recording stops automatically at the configured duration', (
    tester,
  ) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(393, 723);
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final platform = _FakeCameraPlatform();
    final originalPlatform = CameraPlatform.instance;
    CameraPlatform.instance = platform;
    addTearDown(() => CameraPlatform.instance = originalPlatform);
    final app = BabiController();
    addTearDown(app.dispose);

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: CaptureScreen(
            app: app,
            onGallery: () async {},
            onBaby: () {},
            referencePreview: false,
          ),
          bottomNavigationBar: const SizedBox(height: 98),
        ),
      ),
    );
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }

    final gesture = await tester.startGesture(
      tester.getCenter(find.byKey(const Key('shutter'))),
    );
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pump();
    expect(platform.recordingsStarted, 1);
    expect(platform.recordingsStopped, 0);

    await tester.pump(Duration(seconds: BabiController.maxVideoSeconds));
    await tester.pump();
    expect(platform.recordingsStopped, 1);
    expect(app.draft.single.mimeType, 'video/webm');

    await gesture.up();
    await tester.pump();
    expect(platform.recordingsStopped, 1);
  });
}

class _FakeCameraPlatform extends CameraPlatform {
  _FakeCameraPlatform({this.initializeError});

  final Object? initializeError;
  int photosTaken = 0;
  int recordingsStarted = 0;
  int recordingsStopped = 0;

  @override
  Future<List<CameraDescription>> availableCameras() async => const [
    CameraDescription(
      name: 'test camera',
      lensDirection: CameraLensDirection.back,
      sensorOrientation: 0,
    ),
  ];

  @override
  Future<int> createCamera(
    CameraDescription description,
    ResolutionPreset? resolutionPreset, {
    bool enableAudio = false,
  }) async => 1;

  @override
  Future<void> initializeCamera(
    int cameraId, {
    ImageFormatGroup imageFormatGroup = ImageFormatGroup.unknown,
  }) async {
    if (initializeError != null) throw initializeError!;
  }

  @override
  Stream<CameraInitializedEvent> onCameraInitialized(int cameraId) =>
      Stream.value(
        CameraInitializedEvent(
          cameraId,
          640,
          480,
          ExposureMode.auto,
          true,
          FocusMode.auto,
          true,
        ),
      );

  @override
  Stream<CameraErrorEvent> onCameraError(int cameraId) =>
      Stream.value(CameraErrorEvent(cameraId, ''));

  @override
  Stream<DeviceOrientationChangedEvent> onDeviceOrientationChanged() =>
      Stream.value(
        const DeviceOrientationChangedEvent(DeviceOrientation.portraitUp),
      );

  @override
  Widget buildPreview(int cameraId) => const ColoredBox(color: Colors.black);

  @override
  Future<XFile> takePicture(int cameraId) async {
    photosTaken++;
    return XFile.fromData(
      Uint8List.fromList(const [137, 80, 78, 71, 13, 10, 26, 10]),
      name: 'photo.png',
      mimeType: 'image/png',
    );
  }

  @override
  Future<void> startVideoCapturing(VideoCaptureOptions options) async {
    recordingsStarted++;
  }

  @override
  Future<XFile> stopVideoRecording(int cameraId) async {
    recordingsStopped++;
    return XFile.fromData(
      Uint8List.fromList(const [0x1A, 0x45, 0xDF, 0xA3, 0x08]),
      name: 'recording.webm',
      mimeType: 'video/webm',
    );
  }

  @override
  Future<void> dispose(int cameraId) async {}
}
