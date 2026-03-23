import 'dart:async';
import 'dart:io';
import 'dart:convert';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'services/server_service.dart';

class NotificationService {
  static final FlutterLocalNotificationsPlugin _fln =
      FlutterLocalNotificationsPlugin();
  static bool _initialized = false;

  // Background handler must be a top-level function.
  @pragma('vm:entry-point')
  static Future<void> firebaseMessagingBackgroundHandler(
      RemoteMessage message) async {
    await Firebase.initializeApp();
    _showLocal(message);
  }

  // Create a server notification so it appears in receivers' Recent Activity.
  // If recipientUsername is empty, recipientDepartment will be used.
  // Title should be the document type (e.g., Memo, Advisory) so UI renders "<type> from <sender>".
  static Future<bool> sendServerNotification({
    required String title,
    required String content,
    String recipientUsername = '',
    String recipientDepartment = '',
    String type = 'document_upload',
    String fileUrl = '',
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      String? baseUrl = prefs.getString('detected_server_url');
      baseUrl ??= await ServerService.getServerUrl();
      final root = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
      final url = '$root/lib/OCR(UPDATED)/api/notifications.php';
      final sender = (prefs.getString('user_name') ?? '').trim();
      final dept = (prefs.getString('user_department') ?? '').trim();

      final Map<String, String> payload = {
        'action': 'create',
        'type': type,
        'title': 'New Document from $sender',
        'content': content,
        'sender_username': sender,
        'department': dept,
        if (recipientUsername.trim().isNotEmpty)
          'recipient_username': recipientUsername.trim(),
        if (recipientDepartment.trim().isNotEmpty)
          'recipient_department': recipientDepartment.trim(),
        if (fileUrl.trim().isNotEmpty) 'file_url': fileUrl.trim(),
      };

      // POST first
      http.Response resp;
      try {
        resp = await http
            .post(Uri.parse(url), body: payload)
            .timeout(const Duration(seconds: 10));
      } catch (_) {
        resp = http.Response('', 599);
      }
      bool ok = false;
      if (resp.statusCode >= 200 && resp.statusCode < 400) {
        try {
          final m = json.decode(resp.body);
          ok = (m is Map && (m['success'] == true || m['id'] != null));
        } catch (_) {
          ok = resp.body.contains('success') || resp.body.contains('id');
        }
      }
      // GET fallback for strict servers
      if (!ok) {
        final uri = Uri.parse(url).replace(queryParameters: payload);
        final r2 = await http.get(uri).timeout(const Duration(seconds: 10));
        if (r2.statusCode >= 200 && r2.statusCode < 400) {
          try {
            final m = json.decode(r2.body);
            ok = (m is Map && (m['success'] == true || m['id'] != null));
          } catch (_) {
            ok = r2.body.contains('success') || r2.body.contains('id');
          }
        }
      }
      return ok;
    } catch (e) {
      debugPrint('sendServerNotification error: $e');
      return false;
    }
  }

  static Future<void> init() async {
    if (_initialized) return;

    // Firebase core init must be called by caller before this (in main.dart)
    FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

    // Local notifications setup
    const androidInit = AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosInit = DarwinInitializationSettings();
    const initSettings =
        InitializationSettings(android: androidInit, iOS: iosInit);
    await _fln.initialize(initSettings,
        onDidReceiveNotificationResponse: (resp) {
      // Handle tap on foreground notifications
    });

    // Permission
    final settings = await FirebaseMessaging.instance.requestPermission(
      alert: true,
      badge: true,
      sound: true,
      provisional: false,
    );
    debugPrint('🔔 FCM permission: ${settings.authorizationStatus}');

    // Foreground presentation options (iOS)
    await FirebaseMessaging.instance
        .setForegroundNotificationPresentationOptions(
            alert: true, badge: true, sound: true);

    // Register token
    await _registerFcmToken();

    // Foreground messages
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      _showLocal(message);
    });

    // App opened from a notification
    FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
      // Optionally handle deep links here
      debugPrint('🔔 onMessageOpenedApp: ${message.messageId}');
    });

    _initialized = true;
  }

  static Future<void> _registerFcmToken() async {
    try {
      final token = await FirebaseMessaging.instance.getToken();
      if (token == null || token.isEmpty) return;
      final prefs = await SharedPreferences.getInstance();
      final username = (prefs.getString('user_name') ?? '').trim();
      final department = (prefs.getString('user_department') ?? '').trim();
      if (username.isEmpty) return;

      // Resolve server root from detected_server_url
      String? baseUrl = prefs.getString('detected_server_url');
      baseUrl ??= await ServerService.getServerUrl();
      final root = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
      final Uri uri =
          Uri.parse('$root/lib/OCR(UPDATED)/api/register_token.php');

      final body = {
        'username': username,
        'department': department,
        'token': token,
        'platform': Platform.isAndroid
            ? 'android'
            : Platform.isIOS
                ? 'ios'
                : 'unknown',
      };
      debugPrint('🔔 Register FCM token -> $uri');
      final r =
          await http.post(uri, body: body).timeout(const Duration(seconds: 8));
      debugPrint('🔔 Register status ${r.statusCode}: ${r.body}');

      // Subscribe to department topic for routed notifications
      if (department.isNotEmpty) {
        final topic = 'dept_${department.toLowerCase()}';
        try {
          await FirebaseMessaging.instance.subscribeToTopic(topic);
          debugPrint('🔔 Subscribed topic: $topic');
        } catch (e) {
          debugPrint('🔔 Topic subscribe failed: $e');
        }
      }
    } catch (e) {
      debugPrint('🔔 Register token failed: $e');
    }
  }

  static Future<void> _showLocal(RemoteMessage message) async {
    try {
      final notification = message.notification;
      const androidDetails = AndroidNotificationDetails(
        'high_importance_channel',
        'General Notifications',
        channelDescription: 'FCM alerts',
        importance: Importance.max,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
        playSound: true,
        enableVibration: true,
      );
      const iosDetails = DarwinNotificationDetails();
      const details =
          NotificationDetails(android: androidDetails, iOS: iosDetails);

      final title = notification?.title ??
          (message.data['title']?.toString() ?? 'Notification');
      final body =
          notification?.body ?? (message.data['body']?.toString() ?? '');
      await _fln.show(
          DateTime.now().millisecondsSinceEpoch ~/ 1000, title, body, details,
          payload: jsonEncode(message.data));
    } catch (e) {
      debugPrint('🔔 showLocal error: $e');
    }
  }

  // Public helper to show a simple local notification without FCM
  static Future<void> showSimple(String title, String body) async {
    try {
      // Ensure plugin initialized at least for local notifications
      if (!_initialized) {
        const androidInit =
            AndroidInitializationSettings('@mipmap/ic_launcher');
        const iosInit = DarwinInitializationSettings();
        const initSettings =
            InitializationSettings(android: androidInit, iOS: iosInit);
        await _fln.initialize(initSettings);
        // Do not flip _initialized here to preserve full init path for FCM, but allow local show to work
      }
      const androidDetails = AndroidNotificationDetails(
        'high_importance_channel',
        'General Notifications',
        channelDescription: 'FCM alerts',
        importance: Importance.max,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
        playSound: true,
        enableVibration: true,
      );
      const iosDetails = DarwinNotificationDetails();
      const details =
          NotificationDetails(android: androidDetails, iOS: iosDetails);
      await _fln.show(
        DateTime.now().millisecondsSinceEpoch ~/ 1000,
        title,
        body,
        details,
      );
    } catch (e) {
      debugPrint('🔔 showSimple error: $e');
    }
  }
}
