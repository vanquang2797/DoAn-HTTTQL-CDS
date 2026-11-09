import 'dart:io';

import 'package:video_player/video_player.dart';

VideoPlayerController videoController(String path) =>
    VideoPlayerController.file(File(path));

Future<VideoPlayerController> remoteVideoController(
  String url,
  Map<String, String> headers, {
  String? mimeType,
}) async =>
    VideoPlayerController.networkUrl(Uri.parse(url), httpHeaders: headers);
void releaseRemoteVideo(String path) {}
