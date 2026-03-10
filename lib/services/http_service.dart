import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

/// Centralized HTTP helper with retry, timeout, and error handling.
///
/// Usage:
/// ```dart
/// final data = await HttpService.getJson(url);
/// final resp = await HttpService.postJson(url, body: {...});
/// ```
class HttpService {
  // ── Default timeouts ──
  static const Duration readTimeout = Duration(seconds: 10);
  static const Duration uploadTimeout = Duration(seconds: 30);
  static const Duration pingTimeout = Duration(seconds: 6);

  // ── Retry config ──
  static const int maxRetries = 3;

  /// GET request with automatic retry and timeout.
  static Future<http.Response> get(
    Uri url, {
    Map<String, String>? headers,
    Duration? timeout,
    int retries = maxRetries,
  }) async {
    return _withRetry(
      retries: retries,
      label: 'GET ${url.path}',
      action: () =>
          http.get(url, headers: headers).timeout(timeout ?? readTimeout),
    );
  }

  /// POST request with automatic retry and timeout.
  static Future<http.Response> post(
    Uri url, {
    Map<String, String>? headers,
    Object? body,
    Duration? timeout,
    int retries = maxRetries,
  }) async {
    return _withRetry(
      retries: retries,
      label: 'POST ${url.path}',
      action: () => http
          .post(url, headers: headers, body: body)
          .timeout(timeout ?? readTimeout),
    );
  }

  /// Convenience: GET and parse JSON response.
  /// Returns null if the response is not valid JSON or request fails after retries.
  static Future<Map<String, dynamic>?> getJson(
    Uri url, {
    Map<String, String>? headers,
    Duration? timeout,
    int retries = maxRetries,
  }) async {
    try {
      final resp =
          await get(url, headers: headers, timeout: timeout, retries: retries);
      if (resp.statusCode == 200) {
        return jsonDecode(resp.body) as Map<String, dynamic>;
      }
      debugPrint('[HttpService] GET ${url.path} status=${resp.statusCode}');
      return null;
    } catch (e) {
      debugPrint('[HttpService] getJson failed: $e');
      return null;
    }
  }

  /// Convenience: POST and parse JSON response.
  static Future<Map<String, dynamic>?> postJson(
    Uri url, {
    Map<String, String>? headers,
    Object? body,
    Duration? timeout,
    int retries = maxRetries,
  }) async {
    try {
      final resp = await post(url,
          headers: headers, body: body, timeout: timeout, retries: retries);
      if (resp.statusCode == 200) {
        return jsonDecode(resp.body) as Map<String, dynamic>;
      }
      debugPrint('[HttpService] POST ${url.path} status=${resp.statusCode}');
      return null;
    } catch (e) {
      debugPrint('[HttpService] postJson failed: $e');
      return null;
    }
  }

  /// Generic retry wrapper with exponential backoff.
  static Future<T> _withRetry<T>({
    required int retries,
    required String label,
    required Future<T> Function() action,
  }) async {
    int attempt = 0;
    while (true) {
      try {
        return await action();
      } on TimeoutException {
        attempt++;
        if (attempt >= retries) {
          debugPrint('[HttpService] $label timed out after $retries attempts');
          rethrow;
        }
        final delay = Duration(milliseconds: 500 * pow(2, attempt - 1).toInt());
        debugPrint(
            '[HttpService] $label timeout, retry $attempt/$retries in ${delay.inMilliseconds}ms');
        await Future.delayed(delay);
      } on http.ClientException catch (e) {
        attempt++;
        if (attempt >= retries) {
          debugPrint('[HttpService] $label failed after $retries attempts: $e');
          rethrow;
        }
        final delay = Duration(milliseconds: 500 * pow(2, attempt - 1).toInt());
        debugPrint(
            '[HttpService] $label error, retry $attempt/$retries in ${delay.inMilliseconds}ms');
        await Future.delayed(delay);
      } catch (e) {
        // Non-retryable errors (parsing, etc.) — fail immediately
        debugPrint('[HttpService] $label non-retryable error: $e');
        rethrow;
      }
    }
  }

  /// User-friendly error message from exception
  static String friendlyError(Object error) {
    if (error is TimeoutException) {
      return 'Request timed out. Please check your connection and try again.';
    }
    if (error is http.ClientException) {
      return 'Could not reach the server. Please check your network.';
    }
    final msg = error.toString();
    if (msg.contains('SocketException') || msg.contains('Connection refused')) {
      return 'Cannot connect to server. Make sure you are on the same network.';
    }
    if (msg.contains('HandshakeException') || msg.contains('CERTIFICATE')) {
      return 'Secure connection failed. Please try again.';
    }
    return 'Something went wrong. Please try again.';
  }
}
