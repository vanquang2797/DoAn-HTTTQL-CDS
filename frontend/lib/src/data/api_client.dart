import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import '../models.dart';

class ApiException implements Exception {
  const ApiException(this.status, this.message, [this.errors = const {}]);
  final int status;
  final String message;
  final Map<String, dynamic> errors;
  @override
  String toString() => message;
}

abstract class TokenStore {
  Future<String?> read();
  Future<void> write(String token);
  Future<void> clear();
}

class SecureTokenStore implements TokenStore {
  const SecureTokenStore();
  static const _storage = FlutterSecureStorage();
  static const _key = 'babi_access_token';
  @override
  Future<String?> read() => _storage.read(key: _key);
  @override
  Future<void> write(String token) => _storage.write(key: _key, value: token);
  @override
  Future<void> clear() => _storage.delete(key: _key);
}

String operationKey() {
  final random = Random.secure();
  final bytes = List.generate(16, (_) => random.nextInt(256));
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  final h = bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  return '${h.substring(0, 8)}-${h.substring(8, 12)}-${h.substring(12, 16)}-${h.substring(16, 20)}-${h.substring(20)}';
}

class ApiClient {
  ApiClient({http.Client? client, TokenStore? tokenStore, String? baseUrl})
    : client = client ?? http.Client(),
      tokenStore = tokenStore ?? const SecureTokenStore(),
      baseUrl = (baseUrl ?? _defaultUrl).replaceFirst(RegExp(r'/+$'), '');
  static String get _defaultUrl =>
      const String.fromEnvironment('BABI_API_URL', defaultValue: '').isNotEmpty
      ? const String.fromEnvironment('BABI_API_URL')
      : !kIsWeb && defaultTargetPlatform == TargetPlatform.android
      ? 'http://10.0.2.2:8000/api'
      : 'http://127.0.0.1:8000/api';
  final http.Client client;
  final TokenStore tokenStore;
  final String baseUrl;
  String? token;
  VoidCallback? onUnauthorized;

  Uri uri(String path) =>
      Uri.parse('$baseUrl/${path.replaceFirst(RegExp(r'^/+'), '')}');
  Map<String, String> get headers => {
    'Accept': 'application/json',
    if (token != null) 'Authorization': 'Bearer $token',
  };
  Future<void> restore() async => token = await tokenStore.read();
  Future<void> clear() async {
    token = null;
    await tokenStore.clear();
  }

  Future<Map<String, dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? operation,
  }) async {
    final request = http.Request(method, uri(path));
    request.headers.addAll({
      ...headers,
      'Content-Type': 'application/json',
      'X-Operation-Key': ?operation,
    });
    if (body != null) request.body = jsonEncode(body);
    try {
      final sentToken = token;
      final response = await client
          .send(request)
          .then(http.Response.fromStream)
          .timeout(const Duration(seconds: 30));
      return await _decode(response, sentToken: sentToken);
    } on TimeoutException {
      throw const ApiException(
        0,
        'Kết nối quá thời gian. Yêu cầu có thể đã được lưu; hãy kiểm tra trước khi gửi lại.',
      );
    } on http.ClientException {
      throw const ApiException(
        0,
        'Không kết nối được máy chủ. Kiểm tra mạng và địa chỉ API.',
      );
    }
  }

  Future<Map<String, dynamic>> _decode(
    http.Response response, {
    String? sentToken,
  }) async {
    Map<String, dynamic> data;
    try {
      data = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      throw ApiException(response.statusCode, 'Phản hồi máy chủ không hợp lệ.');
    }
    if (response.statusCode < 200 || response.statusCode >= 300) {
      if (response.statusCode == 401 && token != null && token == sentToken) {
        await clear();
        onUnauthorized?.call();
      }
      throw ApiException(
        response.statusCode,
        data['message'] as String? ?? 'Không thể hoàn tất yêu cầu.',
        data['errors'] is Map
            ? Map<String, dynamic>.from(data['errors'] as Map)
            : const {},
      );
    }
    return data;
  }

  Future<Map<String, dynamic>> authenticate(
    String email,
    String password, {
    String? name,
  }) async {
    final result = await request(
      'POST',
      name == null ? 'auth/login' : 'auth/register',
      body: {
        'email': email,
        'password': password,
        if (name != null) ...{'name': name, 'password_confirmation': password},
      },
    );
    final data = result['data'] as Map<String, dynamic>;
    token = data['token'] as String;
    await tokenStore.write(token!);
    return data['user'] as Map<String, dynamic>;
  }

  Future<List<Map<String, dynamic>>> allPages(String path) async {
    final result = <Map<String, dynamic>>[];
    var page = 1;
    while (true) {
      final response = await request(
        'GET',
        '$path${path.contains('?') ? '&' : '?'}per_page=100&page=$page',
      );
      final data = response['data'] as Map<String, dynamic>;
      result.addAll((data['data'] as List).cast<Map<String, dynamic>>());
      if (page >= (data['last_page'] as num).toInt()) return result;
      page++;
    }
  }

  Future<Map<String, dynamic>> createChildProfile(
    Map<String, dynamic> fields, {
    required String operation,
    MediaItem? avatar,
  }) async {
    if (avatar == null) {
      return request(
        'POST',
        'child-profiles',
        body: fields,
        operation: operation,
      );
    }
    if (avatar.isVideo ||
        avatar.bytes == null ||
        avatar.bytes!.length > 10 * 1024 * 1024) {
      throw const ApiException(
        422,
        'Ảnh đại diện phải là JPEG/PNG tối đa 10 MB.',
      );
    }
    final upload = http.MultipartRequest('POST', uri('child-profiles'));
    upload.headers.addAll({...headers, 'X-Operation-Key': operation});
    for (final entry in fields.entries) {
      upload.fields[entry.key] = entry.value is bool
          ? (entry.value == true ? '1' : '0')
          : '${entry.value}';
    }
    upload.files.add(
      http.MultipartFile.fromBytes(
        'avatar',
        avatar.bytes!,
        filename: avatar.mimeType == 'image/png' ? 'avatar.png' : 'avatar.jpg',
      ),
    );
    try {
      final sentToken = token;
      final response = await client
          .send(upload)
          .then(http.Response.fromStream)
          .timeout(const Duration(seconds: 60));
      return await _decode(response, sentToken: sentToken);
    } on TimeoutException {
      throw const ApiException(
        0,
        'Chưa nhận được kết quả tạo hồ sơ. Giữ form và thử lại bằng cùng mã thao tác.',
      );
    } on http.ClientException {
      throw const ApiException(
        0,
        'Mất kết nối khi tạo hồ sơ. Giữ form để thử lại.',
      );
    }
  }

  Future<Map<String, dynamic>> uploadMemory({
    required String childId,
    required String caption,
    required String memoryDate,
    required List<MediaItem> files,
    required String operation,
    int? milestoneId,
  }) async {
    final request = http.MultipartRequest('POST', uri('memories'));
    request.headers.addAll({...headers, 'X-Operation-Key': operation});
    request.fields.addAll({
      'child_profile_id': childId,
      'caption': caption,
      'memory_date': memoryDate,
      if (milestoneId != null) 'milestone_id': '$milestoneId',
    });
    for (var i = 0; i < files.length; i++) {
      final item = files[i];
      if (item.bytes == null) {
        throw const ApiException(
          422,
          'Ảnh mẫu chỉ dùng trong chế độ xem thiết kế.',
        );
      }
      request.files.add(
        http.MultipartFile.fromBytes(
          'files[]',
          item.bytes!,
          filename:
              'media-$i.${item.mimeType == 'video/webm'
                  ? 'webm'
                  : item.isVideo
                  ? 'mp4'
                  : item.mimeType == 'image/png'
                  ? 'png'
                  : 'jpg'}',
        ),
      );
    }
    try {
      final sentToken = token;
      return await _decode(
        await client
            .send(request)
            .then(http.Response.fromStream)
            .timeout(const Duration(minutes: 3)),
        sentToken: sentToken,
      );
    } on TimeoutException {
      throw const ApiException(
        0,
        'Chưa nhận được kết quả tải lên. Giữ bản nháp và kiểm tra lại bằng cùng mã thao tác.',
      );
    } on http.ClientException {
      throw const ApiException(
        0,
        'Mất kết nối khi tải lên. Giữ bản nháp để kiểm tra hoặc gửi lại cùng mã.',
      );
    }
  }

  void close() => client.close();
}
