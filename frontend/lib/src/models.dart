import 'dart:typed_data';

import 'package:image_picker/image_picker.dart';

enum AppTab { vaccinations, home, capture, family, health }

enum FamilyRole { owner, viewer }

class Baby {
  const Baby(
    this.id,
    this.name,
    this.birthDate, {
    this.spaceId = '',
    this.gender = 'female',
    this.dataRevision = 0,
    this.avatarUrl,
    this.birthWeightKg,
    this.birthLengthCm,
  });
  final String id, name;
  final DateTime birthDate;
  final String spaceId, gender;
  String get displayName =>
      name.toLowerCase().startsWith('bé ') ? name : 'Bé $name';
  final int dataRevision;
  final String? avatarUrl;
  final double? birthWeightKg, birthLengthCm;
  factory Baby.fromJson(Map<String, dynamic> data) => Baby(
    '${data['id']}',
    data['name'] as String,
    DateTime.parse(data['date_of_birth'] as String),
    spaceId: '${data['family_space_id']}',
    gender: data['gender'] as String,
    dataRevision: (data['data_revision'] as num).toInt(),
    avatarUrl: data['avatar_url'] as String?,
    birthWeightKg: (data['birth_weight_kg'] as num?)?.toDouble(),
    birthLengthCm: (data['birth_length_cm'] as num?)?.toDouble(),
  );
}

class MediaItem {
  const MediaItem.asset(this.asset, {this.isVideo = false})
    : bytes = null,
      path = null,
      mimeType = null,
      url = null,
      headers = const {};
  const MediaItem.file({
    required this.bytes,
    required this.path,
    required this.isVideo,
    String? mimeType,
  }) : mimeType = mimeType ?? (isVideo ? 'video/mp4' : 'image/jpeg'),
       asset = null,
       url = null,
       headers = const {};
  const MediaItem.remote(
    this.url, {
    required this.headers,
    this.isVideo = false,
    this.mimeType,
  }) : asset = null,
       bytes = null,
       path = null;
  final String? asset, path;
  final String? url;
  final String? mimeType;
  final Map<String, String> headers;
  final Uint8List? bytes;
  final bool isVideo;
  static Future<MediaItem> fromFile(XFile file) async {
    final length = await file.length();
    final header = <int>[];
    await for (final chunk in file.openRead(0, length < 12 ? length : 12)) {
      header.addAll(chunk);
    }
    final png =
        header.length >= 8 &&
        [
          137,
          80,
          78,
          71,
          13,
          10,
          26,
          10,
        ].asMap().entries.every((e) => header[e.key] == e.value);
    final jpeg =
        header.length >= 3 &&
        header[0] == 255 &&
        header[1] == 216 &&
        header[2] == 255;
    final fileName = file.name.toLowerCase();
    final declaredMime = file.mimeType?.toLowerCase().split(';').first;
    final mp4 =
        header.length >= 12 &&
        String.fromCharCodes(header.sublist(4, 8)) == 'ftyp';
    final webm =
        header.length >= 4 &&
        header[0] == 0x1A &&
        header[1] == 0x45 &&
        header[2] == 0xDF &&
        header[3] == 0xA3 &&
        (declaredMime == 'video/webm' || fileName.endsWith('.webm'));
    if (!png &&
        !jpeg &&
        !(mp4 && (declaredMime == 'video/mp4' || fileName.endsWith('.mp4'))) &&
        !webm) {
      throw const FormatException(
        'Chỉ hỗ trợ ảnh JPEG, PNG và video MP4 hoặc WebM.',
      );
    }
    if (length > ((mp4 || webm) ? 50 : 10) * 1024 * 1024) {
      throw FormatException(
        (mp4 || webm) ? 'Video vượt quá 50 MB.' : 'Ảnh vượt quá 10 MB.',
      );
    }
    return MediaItem.file(
      bytes: await file.readAsBytes(),
      path: file.path,
      isVideo: mp4 || webm,
      mimeType: webm
          ? 'video/webm'
          : mp4
          ? 'video/mp4'
          : png
          ? 'image/png'
          : 'image/jpeg',
    );
  }
}

class MemoryItem {
  MemoryItem({
    required this.babyId,
    required this.caption,
    required this.date,
    required this.media,
    this.milestone,
    this.author = 'Mẹ Quỳnh',
    this.likes = 0,
    this.comments = 0,
    this.id,
    this.status = 'published',
  });
  final String babyId, caption, date, author;
  final String? id;
  final String status;
  final List<MediaItem> media;
  final String? milestone;
  int likes;
  final int comments;
  bool liked = false;
}

class Measurement {
  const Measurement(
    this.date,
    this.height,
    this.weight,
    this.method, {
    this.id,
    this.version = 0,
  });
  final DateTime date;
  final double height, weight;
  final String method;
  final String? id;
  final int version;
}

String calendarDate(DateTime date) =>
    '${date.day.toString().padLeft(2, '0')}/${date.month.toString().padLeft(2, '0')}/${date.year}';
String decimal(double value) => value.toStringAsFixed(1).replaceAll('.', ',');
String isoDate(DateTime date) =>
    '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}';
