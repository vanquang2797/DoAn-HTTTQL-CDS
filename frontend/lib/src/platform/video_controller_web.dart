import 'package:video_player/video_player.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:web/web.dart' as web;

VideoPlayerController videoController(String path) =>
    VideoPlayerController.networkUrl(Uri.parse(path));

Future<VideoPlayerController> remoteVideoController(
  String url,
  Map<String, String> headers, {
  String? mimeType,
}) async {
  final response = await http
      .get(Uri.parse(url), headers: headers)
      .timeout(const Duration(minutes: 2));
  if (response.statusCode != 200) {
    throw StateError('Không có quyền tải video hoặc tệp không còn khả dụng.');
  }
  final file = XFile.fromData(
    response.bodyBytes,
    mimeType: mimeType ?? 'video/mp4',
  );
  return VideoPlayerController.networkUrl(Uri.parse(file.path));
}

void releaseRemoteVideo(String path) {
  if (path.startsWith('blob:')) web.URL.revokeObjectURL(path);
}
