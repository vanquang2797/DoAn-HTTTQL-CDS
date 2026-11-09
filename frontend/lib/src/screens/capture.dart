import 'dart:async';
import 'dart:math' as math;

import 'package:app_settings/app_settings.dart';
import 'package:camera/camera.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';

import '../controller.dart';
import '../models.dart';
import '../theme.dart';
import '../widgets.dart';

class CaptureScreen extends StatefulWidget {
  const CaptureScreen({
    super.key,
    required this.app,
    required this.onGallery,
    required this.onBaby,
    this.referencePreview =
        const bool.fromEnvironment('BABI_DESIGN_PREVIEW') &&
        !const bool.fromEnvironment('BABI_CAMERA_TEST'),
  });
  final BabiController app;
  final Future<void> Function() onGallery;
  final VoidCallback onBaby;
  final bool referencePreview;
  @override
  State<CaptureScreen> createState() => _CaptureScreenState();
}

class _CaptureScreenState extends State<CaptureScreen>
    with WidgetsBindingObserver {
  CameraController? _camera;
  List<CameraDescription> _cameras = [];
  int _lens = 0, _generation = 0;
  bool _loading = true,
      _taking = false,
      _recording = false,
      _starting = false,
      _held = false,
      _flash = false;
  String? _cameraIssue;
  Timer? _limit;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_open());
  }

  Future<void> _open() async {
    if (widget.referencePreview) {
      setState(() => _loading = false);
      return;
    }
    final generation = ++_generation;
    if (mounted) {
      setState(() {
        _loading = true;
        _cameraIssue = null;
      });
    }
    CameraController? pending;
    try {
      final old = _camera;
      _camera = null;
      await old?.dispose();
      _cameras = await availableCameras().timeout(const Duration(seconds: 15));
      if (_cameras.isEmpty) {
        throw StateError('Không có camera.');
      }
      pending = CameraController(
        _cameras[_lens % _cameras.length],
        ResolutionPreset.high,
        enableAudio: true,
        imageFormatGroup: ImageFormatGroup.jpeg,
      );
      await pending.initialize().timeout(const Duration(seconds: 15));
      if (!mounted || generation != _generation) {
        await pending.dispose();
        return;
      }
      _camera = pending;
      setState(() {
        _loading = false;
        _cameraIssue = null;
      });
    } catch (_) {
      await pending?.dispose();
      if (mounted && generation == _generation) {
        setState(() {
          _camera = null;
          _loading = false;
          _cameraIssue = kIsWeb
              ? 'Không mở được camera hoặc micro. Kiểm tra quyền Camera/Microphone, đóng ứng dụng khác đang dùng camera và mở Babi trên localhost hoặc HTTPS.'
              : 'Không mở được camera hoặc micro. Kiểm tra quyền Camera/Microphone trong cài đặt thiết bị.';
        });
      }
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.inactive) {
      _generation++;
      _limit?.cancel();
      final camera = _camera;
      _camera = null;
      _held = false;
      _recording = false;
      unawaited(camera?.dispose());
    }
    if (state == AppLifecycleState.resumed && mounted) {
      unawaited(_open());
    }
  }

  Future<void> _photo() async {
    if (widget.referencePreview) {
      widget.app.addMedia(const [
        MediaItem.asset('assets/figma/preview_media_illustration.svg'),
      ]);
      return;
    }
    final camera = _camera;
    if (camera == null || _taking || _recording || _starting) {
      return;
    }
    setState(() => _taking = true);
    try {
      final file = await camera.takePicture();
      final item = await MediaItem.fromFile(file);
      if (mounted) {
        widget.app.addMedia([item]);
      }
    } catch (e) {
      if (mounted) {
        showIssue(context, e);
      }
    } finally {
      if (mounted) {
        setState(() => _taking = false);
      }
    }
  }

  Future<void> _startVideo() async {
    final camera = _camera;
    if (camera == null || _taking || _recording || _starting) {
      return;
    }
    _held = true;
    _starting = true;
    try {
      await camera.startVideoRecording();
      if (!mounted) {
        return;
      }
      setState(() => _recording = true);
      _limit = Timer(
        Duration(seconds: BabiController.maxVideoSeconds),
        () => unawaited(_stopVideo()),
      );
      if (!_held) {
        await _stopVideo();
      }
    } catch (e) {
      if (mounted) {
        showIssue(context, e);
      }
    } finally {
      _starting = false;
    }
  }

  Future<void> _stopVideo() async {
    _held = false;
    if (!_recording || _camera == null) {
      return;
    }
    _limit?.cancel();
    setState(() {
      _recording = false;
      _taking = true;
    });
    try {
      final file = await _camera!.stopVideoRecording();
      final item = await MediaItem.fromFile(file);
      if (mounted) {
        widget.app.addMedia([item]);
      }
    } catch (e) {
      if (mounted) {
        showIssue(context, e);
      }
    } finally {
      if (mounted) {
        setState(() => _taking = false);
      }
    }
  }

  Future<void> _settings() async {
    if (kIsWeb) {
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Cho phép truy cập camera'),
          content: const Text(
            'Mở phần quyền camera của trang trong trình duyệt, chọn Cho phép, rồi thử lại.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Đã hiểu'),
            ),
          ],
        ),
      );
    } else {
      await AppSettings.openAppSettings();
    }
  }

  @override
  void dispose() {
    ++_generation;
    WidgetsBinding.instance.removeObserver(this);
    _limit?.cancel();
    unawaited(_camera?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cameraUnavailable =
        !widget.referencePreview && (_camera == null || _loading);
    return Column(
      children: [
        SizedBox(
          height: 70,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: _taking || _recording ? null : widget.onBaby,
                    style: OutlinedButton.styleFrom(
                      backgroundColor: Colors.white,
                      foregroundColor: BabiColors.ink,
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16),
                      ),
                      side: const BorderSide(color: BabiColors.border),
                      minimumSize: const Size(44, 44),
                    ),
                    child: Row(
                      children: [
                        const FigmaArt(
                          'assets/figma/camera_avatar.svg',
                          width: 28,
                          height: 28,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Bé ${widget.app.baby.name}',
                            style: textStyle(
                              15,
                              line: 20,
                              weight: FontWeight.w500,
                            ),
                          ),
                        ),
                        const BabiIcon(Icons.expand_more, size: 20),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                WhiteIconButton(
                  icon: _flash
                      ? Icons.flash_on_outlined
                      : Icons.flash_off_outlined,
                  label: 'Bật/tắt flash',
                  onPressed: _taking || _recording
                      ? null
                      : () async {
                          if (_camera == null) {
                            showIssue(
                              context,
                              FormatException(
                                _cameraIssue ?? 'Hãy cho phép Babi dùng camera trước khi bật đèn flash.',
                              ),
                            );
                            return;
                          }
                          try {
                            await _camera!.setFlashMode(
                              _flash ? FlashMode.off : FlashMode.torch,
                            );
                            if (mounted) {
                              setState(() => _flash = !_flash);
                            }
                          } catch (_) {
                            if (context.mounted) {
                              showIssue(
                                context,
                                const FormatException(
                                  'Camera này không hỗ trợ đèn flash.',
                                ),
                              );
                            }
                          }
                        },
                ),
              ],
            ),
          ),
        ),
        Expanded(
          child: LayoutBuilder(
            builder: (context, viewport) {
              // Keep the shutter visible in shorter browser windows and on
              // compact phones. The 3:4 preview grows back to its full size
              // when there is enough vertical room.
              final previewWidth = viewport.maxWidth - 40;
              final previewHeight = math.min(
                previewWidth * 4 / 3,
                math.max(260.0, viewport.maxHeight - 164),
              );
              return SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                child: Column(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(24),
                      child: SizedBox(
                        width: double.infinity,
                        height: previewHeight,
                        child: widget.referencePreview
                            ? const FigmaArt(
                                'assets/figma/camera_media_illustration.svg',
                              )
                            : _loading
                            ? const ColoredBox(
                                color: Colors.white,
                                child: Center(
                                  child: CircularProgressIndicator(),
                                ),
                              )
                            : _camera != null
                            ? CameraPreview(_camera!)
                            : ColoredBox(
                                color: Colors.white,
                                child: LayoutBuilder(
                                  builder: (context, constraints) =>
                                      SingleChildScrollView(
                                        padding: const EdgeInsets.all(16),
                                        child: ConstrainedBox(
                                          constraints: BoxConstraints(
                                            minHeight:
                                                constraints.maxHeight - 32,
                                          ),
                                          child: Center(
                                            child: Column(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                Text(
                                                  _cameraIssue ?? 'Babi cần quyền truy cập camera để chụp kỷ niệm',
                                                  textAlign: TextAlign.center,
                                                  style: textStyle(
                                                    16,
                                                    line: 24,
                                                  ),
                                                ),
                                                if (kIsWeb) ...[
                                                  const SizedBox(height: 8),
                                                  Text(
                                                    'Trên localhost hoặc HTTPS, bấm biểu tượng quyền trang cạnh thanh địa chỉ, cho phép Camera (và Micro nếu quay video), rồi bấm Thử lại.',
                                                    textAlign: TextAlign.center,
                                                    style: textStyle(
                                                      13,
                                                      line: 18,
                                                      color:
                                                          BabiColors.secondary,
                                                    ),
                                                  ),
                                                ],
                                                if (widget.app.seedDemo) ...[
                                                  const SizedBox(height: 12),
                                                  TextButton(
                                                    key: const Key(
                                                      'sample-photo',
                                                    ),
                                                    onPressed: () => widget.app
                                                        .addMedia(const [
                                                          MediaItem.asset(
                                                            'assets/figma/preview_media_illustration.svg',
                                                          ),
                                                        ]),
                                                    child: const Text(
                                                      'Dùng ảnh mẫu',
                                                    ),
                                                  ),
                                                ],
                                                const SizedBox(height: 8),
                                                FilledButton(
                                                  onPressed: _settings,
                                                  child: const Text(
                                                    'Mở cài đặt',
                                                  ),
                                                ),
                                                const SizedBox(height: 8),
                                                OutlinedButton(
                                                  onPressed: widget.onGallery,
                                                  child: const Text(
                                                    'Chọn từ thư viện',
                                                  ),
                                                ),
                                                TextButton(
                                                  onPressed: _open,
                                                  child: const Text('Thử lại'),
                                                ),
                                              ],
                                            ),
                                          ),
                                        ),
                                      ),
                                ),
                              ),
                      ),
                    ),
                    const SizedBox(height: 12),
                    SizedBox(
                      height: 90,
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          SizedBox(
                            width: 96,
                            child: Column(
                              children: [
                                GestureDetector(
                                  onTap: _taking || _recording
                                      ? null
                                      : widget.onGallery,
                                  child: Semantics(
                                    label: 'Thư viện',
                                    button: true,
                                    child: SizedBox.square(
                                      dimension: 48,
                                      child: FigmaArt(
                                        'assets/figma/camera_recent_thumbnail.svg',
                                      ),
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'Thư viện',
                                  style: textStyle(
                                    13,
                                    line: 18,
                                    color: BabiColors.secondary,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Expanded(
                            child: Center(
                              heightFactor: 1,
                              child: Semantics(
                                label: 'Chụp ảnh; giữ để quay video',
                                button: true,
                                child: GestureDetector(
                                  key: const Key('shutter'),
                                  onTap: cameraUnavailable ? null : _photo,
                                  onLongPressStart: cameraUnavailable
                                      ? null
                                      : (_) => _startVideo(),
                                  onLongPressEnd: cameraUnavailable
                                      ? null
                                      : (_) => _stopVideo(),
                                  child: Container(
                                    width: 68,
                                    height: 68,
                                    padding: const EdgeInsets.all(6),
                                    decoration: BoxDecoration(
                                      color: Colors.white,
                                      shape: BoxShape.circle,
                                      border: Border.all(
                                        color: BabiColors.border,
                                      ),
                                    ),
                                    child: Container(
                                      decoration: BoxDecoration(
                                        color: _recording
                                            ? BabiColors.error
                                            : BabiColors.primary,
                                        shape: _recording
                                            ? BoxShape.rectangle
                                            : BoxShape.circle,
                                        borderRadius: _recording
                                            ? BorderRadius.circular(12)
                                            : null,
                                      ),
                                      child: _taking
                                          ? const Padding(
                                              padding: EdgeInsets.all(12),
                                              child: CircularProgressIndicator(
                                                color: BabiColors.ink,
                                              ),
                                            )
                                          : null,
                                    ),
                                  ),
                                ),
                              ),
                            ),
                          ),
                          SizedBox(
                            width: 96,
                            child: Column(
                              children: [
                                WhiteIconButton(
                                  icon: Icons.cameraswitch_outlined,
                                  label: 'Đổi camera',
                                  size: 48,
                                  onPressed: _taking || _recording
                                      ? null
                                      : () {
                                          if (_camera == null ||
                                              _cameras.length < 2) {
                                            showIssue(
                                              context,
                                              FormatException(
                                                _cameraIssue ?? 'Thiết bị hiện tại không có camera thứ hai để chuyển.',
                                              ),
                                            );
                                            return;
                                          }
                                          _lens++;
                                          _flash = false;
                                          _open();
                                        },
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'Đổi camera',
                                  style: textStyle(
                                    13,
                                    line: 18,
                                    color: BabiColors.secondary,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 12),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: Text(
                        _recording
                            ? 'Đang quay · Thả tay để dừng'
                            : 'Chạm để chụp · Giữ để quay video',
                        style: textStyle(
                          13,
                          line: 18,
                          color: BabiColors.secondary,
                        ),
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
      ],
    );
  }
}
