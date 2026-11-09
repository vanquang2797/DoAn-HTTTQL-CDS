import 'dart:async';

import 'package:babi/src/models.dart';
import 'package:babi/src/widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:video_player_platform_interface/video_player_platform_interface.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('phát video riêng tư bằng Bearer và hiện điều khiển phát/dừng', (
    tester,
  ) async {
    final platform = _FakeVideoPlayerPlatform();
    VideoPlayerPlatform.instance = platform;
    const url = 'http://localhost:8000/api/media-files/14';
    const token = 'Bearer test-token';

    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: MediaView(
            MediaItem.remote(
              url,
              headers: {'Authorization': token},
              isVideo: true,
              mimeType: 'video/webm',
            ),
            interactive: true,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(VideoPreview), findsOneWidget);
    expect(find.text('Không thể phát video'), findsNothing);
    expect(platform.sources, hasLength(1));
    expect(find.byTooltip('Phát video'), findsOneWidget);
    expect(platform.sources.single.uri, url);
    expect(platform.sources.single.httpHeaders['Authorization'], token);

    await tester.tap(find.byTooltip('Phát video'));
    await tester.pump();
    expect(platform.playCalls, 1);
    expect(find.byTooltip('Tạm dừng video'), findsOneWidget);

    await tester.pumpWidget(const SizedBox.shrink());
    await tester.pump();
    await tester.runAsync(() => Future<void>.delayed(Duration.zero));
    expect(platform.disposeCalls, 1);
  });
}

class _FakeVideoPlayerPlatform extends VideoPlayerPlatform {
  int _nextId = 1;
  final sources = <DataSource>[];
  final streams = <int, StreamController<VideoEvent>>{};
  var playCalls = 0;
  var disposeCalls = 0;

  @override
  Future<void> init() async {}

  @override
  Future<int?> createWithOptions(VideoCreationOptions options) async {
    final id = _nextId++;
    sources.add(options.dataSource);
    final stream = StreamController<VideoEvent>();
    streams[id] = stream;
    stream.add(
      VideoEvent(
        eventType: VideoEventType.initialized,
        size: const Size(16, 9),
        duration: const Duration(seconds: 5),
      ),
    );
    return id;
  }

  @override
  Stream<VideoEvent> videoEventsFor(int playerId) => streams[playerId]!.stream;

  @override
  Future<void> setPreventsDisplaySleepDuringVideoPlayback(
    int playerId,
    bool preventsDisplaySleepDuringVideoPlayback,
  ) async {}

  @override
  Future<void> setLooping(int playerId, bool looping) async {}

  @override
  Future<void> setVolume(int playerId, double volume) async {}

  @override
  Future<void> setPlaybackSpeed(int playerId, double speed) async {}

  @override
  Future<void> play(int playerId) async {
    playCalls++;
  }

  @override
  Future<void> pause(int playerId) async {}

  @override
  Future<Duration> getPosition(int playerId) async => Duration.zero;

  @override
  Future<void> dispose(int playerId) async {
    disposeCalls++;
    await streams.remove(playerId)?.close();
  }

  @override
  Widget buildView(int playerId) => const ColoredBox(color: Colors.black);
}
