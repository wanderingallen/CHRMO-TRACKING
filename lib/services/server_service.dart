import 'dart:async';

import 'package:http/http.dart' as http;
import 'package:network_info_plus/network_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Service for managing server connections and IP configurations
class ServerService {
  static const String _serverUrlKey = 'detected_server_url';
  static const String _serverRootKey = 'server_root';

  // Build-time overrides (recommended for different networks/devices)
  // Example:
  // flutter run --dart-define=API_HOST=<PC_IP>
  // flutter run --dart-define=SERVER_ROOT=http://<PC_IP>/flutter_application_7
  // flutter run --dart-define=API_BASE_URL=http://<PC_IP>/flutter_application_7/api
  static const String _envApiBaseUrl =
      String.fromEnvironment('API_BASE_URL', defaultValue: '');
  static const String _envServerRoot =
      String.fromEnvironment('SERVER_ROOT', defaultValue: '');
  static const String _envApiHost =
      String.fromEnvironment('API_HOST', defaultValue: '10.161.44.36');
  static const String _envApiScheme =
      String.fromEnvironment('API_SCHEME', defaultValue: 'http');
  static const String _envProjectPath = String.fromEnvironment(
      'API_PROJECT_PATH',
      defaultValue: '/flutter_application_7');

  static String _trimTrailingSlash(String value) {
    var v = value.trim();
    while (v.endsWith('/')) {
      v = v.substring(0, v.length - 1);
    }
    return v;
  }

  static bool get _hasExplicitEnvOverride {
    return _envApiBaseUrl.trim().isNotEmpty || _envServerRoot.trim().isNotEmpty;
  }

  static bool get _isLocalhostDefault {
    final host = _envApiHost.trim().toLowerCase();
    return host.isEmpty || host == 'localhost' || host == '127.0.0.1';
  }

  /// Default server root (e.g. http://<PC_IP>/flutter_application_7)
  static String get defaultServerRoot {
    if (_envServerRoot.trim().isNotEmpty) {
      return _trimTrailingSlash(_envServerRoot);
    }

    final projectPath =
        _envProjectPath.startsWith('/') ? _envProjectPath : '/$_envProjectPath';
    return _trimTrailingSlash('$_envApiScheme://$_envApiHost$projectPath');
  }

  /// Default API base URL (e.g. http://<PC_IP>/flutter_application_7/api)
  static String get defaultServerUrl {
    if (_envApiBaseUrl.trim().isNotEmpty) {
      return _trimTrailingSlash(_envApiBaseUrl);
    }
    return '$defaultServerRoot/api';
  }

  /// Test if a server URL is valid
  static Future<bool> testServerUrl(String url) async {
    try {
      final trimmed = _trimTrailingSlash(url);
      final candidates = <String>{
        trimmed,
        if (!trimmed.endsWith('/api')) '$trimmed/api',
      };

      Future<bool> probe(String base) async {
        final endpoints = <String>[
          // Prefer simple health checks
          '$base/ping.php',
          '$base/test_connection.php',
          // Fallback to login handler
          '$base/login.php',
        ];

        for (final endpoint in endpoints) {
          try {
            final response = await http.get(
              Uri.parse(endpoint),
              headers: {'Content-Type': 'application/json'},
            ).timeout(const Duration(seconds: 3));

            if (response.statusCode >= 200 && response.statusCode < 400) {
              return true;
            }
          } catch (_) {
            // continue to next endpoint
          }
        }
        return false;
      }

      for (final base in candidates) {
        if (await probe(base)) return true;
      }
      return false;
    } catch (e) {
      return false;
    }
  }

  static Future<void> _clearSavedServerUrlUnsafe() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_serverUrlKey);
    await prefs.remove(_serverRootKey);
  }

  /// Save server URL to preferences
  static Future<void> saveServerUrl(String url) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_serverUrlKey, url);

    // Also save server root
    final root = url.replaceFirst(RegExp(r'/api/?$'), '');
    await prefs.setString(_serverRootKey, root);
  }

  /// Get saved server URL
  static Future<String?> getSavedServerUrl() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_serverUrlKey);
  }

  /// Get saved server root
  static Future<String?> getSavedServerRoot() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_serverRootKey);
  }

  /// Get the best server URL (saved or default)
  static Future<String> getServerUrl() async {
    final saved = await getSavedServerUrl();

    if (saved != null && saved.trim().isNotEmpty) {
      // Guard against stale IPs saved from old networks/devices.
      final ok = await testServerUrl(saved.trim());
      if (ok) {
        return saved;
      }
      try {
        await _clearSavedServerUrlUnsafe();
      } catch (_) {
        // ignore
      }
    }

    // If no explicit host is configured, try LAN auto-detect before falling back.
    if (!_hasExplicitEnvOverride && _isLocalhostDefault) {
      final detected = await detectServerUrl();
      if (detected != null && detected.trim().isNotEmpty) {
        return detected;
      }
    }

    return defaultServerUrl;
  }

  /// Get the best server root
  static Future<String> getServerRoot() async {
    final saved = await getSavedServerRoot();
    if (saved != null && saved.trim().isNotEmpty) {
      final candidate = _trimTrailingSlash(saved.trim());
      // Validate root by checking its /api.
      final ok = await testServerUrl('$candidate/api');
      if (ok) {
        return candidate;
      }
      try {
        await _clearSavedServerUrlUnsafe();
      } catch (_) {
        // ignore
      }
    }
    return defaultServerRoot;
  }

  /// Auto-detect server URL
  static Future<String?> detectServerUrl() async {
    try {
      final info = NetworkInfo();
      final wifiIP = await info.getWifiIP();
      final wifiGateway = await info.getWifiGatewayIP();

      final Set<String> hosts = {};
      final envHost = _envApiHost.trim();
      if (envHost.isNotEmpty && envHost.toLowerCase() != 'localhost') {
        hosts.add(envHost);
      }
      if (wifiGateway != null && wifiGateway.trim().isNotEmpty) {
        hosts.add(wifiGateway.trim());
      }
      if (wifiIP != null && wifiIP.trim().isNotEmpty) {
        final parts = wifiIP.trim().split('.');
        if (parts.length == 4) {
          final subnet = '${parts[0]}.${parts[1]}.${parts[2]}';
          hosts.addAll([
            '$subnet.1',
            '$subnet.2',
            '$subnet.5',
            '$subnet.10',
            '$subnet.20',
            '$subnet.50',
            '$subnet.60',
            '$subnet.80',
            '$subnet.100',
            '$subnet.101',
            '$subnet.109',
            '$subnet.110',
            '$subnet.222',
          ]);
        }
      }

      final projectPath = _envProjectPath.startsWith('/')
          ? _envProjectPath
          : '/$_envProjectPath';
      final ports = <int>[80, 8080];
      final paths = <String>[
        '$projectPath/api',
        '$projectPath/lib/api',
        '$projectPath/lib/OCR(UPDATED)/api',
      ];

      final List<String> candidates = [];
      for (final host in hosts) {
        for (final port in ports) {
          for (final path in paths) {
            candidates.add(
              _trimTrailingSlash(
                '$_envApiScheme://$host${port == 80 ? '' : ':$port'}$path',
              ),
            );
          }
        }
      }

      // Always try any explicit defaults last.
      candidates.add(defaultServerUrl);

      if (candidates.isEmpty) return null;

      final completer = Completer<String?>();
      var pending = candidates.length;

      for (final url in candidates) {
        testServerUrl(url).then((ok) async {
          if (ok && !completer.isCompleted) {
            await saveServerUrl(url);
            completer.complete(url);
          }
        }).whenComplete(() {
          pending -= 1;
          if (pending == 0 && !completer.isCompleted) {
            completer.complete(null);
          }
        });
      }

      return completer.future;
    } catch (_) {
      // Fallback to a simple single check.
      if (await testServerUrl(defaultServerUrl)) {
        await saveServerUrl(defaultServerUrl);
        return defaultServerUrl;
      }
      return null;
    }
  }

  /// Build full API URL from relative path
  static Future<String> buildApiUrl(String relativePath) async {
    final root = await getServerRoot();

    if (relativePath.startsWith('/')) {
      return '$root$relativePath';
    }
    return '$root/$relativePath';
  }

  /// Clear saved server configuration
  static Future<void> clearServerConfig() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_serverUrlKey);
    await prefs.remove(_serverRootKey);
  }
}
