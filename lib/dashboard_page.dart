import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:cunning_document_scanner/cunning_document_scanner.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_pdfview/flutter_pdfview.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:http/http.dart' as http;
import 'package:image/image.dart' as img;
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:pro_image_editor/pro_image_editor.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

// Import pages
import 'camera_page.dart';
import 'dashboard_document_preview.dart';
import 'encryption_service.dart';
import 'gallery_page.dart';
import 'login_page.dart';
import 'main.dart' as app_main;
import 'notification_page.dart';
import 'services/chrmo_document_classifier.dart';
import 'services/performance_utils.dart';
import 'services/routing_service.dart';
import 'services/server_service.dart';

/// Mobile-first Dashboard with Bottom Navigation
class DashboardPage extends StatefulWidget {
  final String? username;
  final String? email;

  const DashboardPage({super.key, this.username, this.email});

  @override
  State<DashboardPage> createState() => _DashboardPageState();
}

class _DashboardPageState extends State<DashboardPage>
    with SingleTickerProviderStateMixin, WidgetsBindingObserver {
  int _currentIndex = 0;
  String username = 'Loading...';
  String email = 'Loading...';
  String _userDepartment = '';
  String searchQuery = '';
  final bool _showSearch = false;
  final FocusNode _searchFocusNode = FocusNode();
  Timer? _searchDebounce;
  List<Map<String, String>> _searchSuggestions = [];
  Timer? _activityTimer;
  String? _lastActivityRefresh;
  int? _lastSeenActivityMs; // last seen createdAtMs to notify on new
  // Notifications state
  int _unreadCount = 0;
  List<Map<String, dynamic>> _notifications = [];
  Timer? _notifTimer;
  StreamSubscription<List<Map<String, dynamic>>>? _routingSub;
  int _lastRealtimeRouteMs = 0;
  String _lastNotifSig = ''; // signature to skip redundant setState
  String _lastActivitySig = ''; // signature to skip redundant setState
  bool _debugToolsEnabled = false;
  Map<String, dynamic>? _lastRouteDebug;
  Map<String, dynamic>? _lastReceiveDebug;

  late PageController _pageController;

  // KPI data (empty until wired to a real API)
  final List<Map<String, dynamic>> _kpiData = [];

  // Recent activity (loaded from backend)
  final List<Map<String, dynamic>> _recentActivity = [];
  // Cache of known document identities, keyed by file path/name hints
  final Map<String, Map<String, String>> _identityCache = {};

  // Cache username -> department (for displaying sender department)
  final Map<String, String> _deptByUsername = {};
  DateTime? _deptByUsernameFetchedAt;

  final Set<String> _receivedItemKeys = <String>{};
  final Set<String> _receivingKeys = <String>{};

  String _pad2(int v) => v < 10 ? '0$v' : '$v';

  String _formatLocalDateTime(int? ms) {
    if (ms == null || ms <= 0) return '';
    final dt = DateTime.fromMillisecondsSinceEpoch(ms);
    final hour12 = ((dt.hour + 11) % 12) + 1;
    final ampm = dt.hour >= 12 ? 'PM' : 'AM';
    return '${dt.year}-${_pad2(dt.month)}-${_pad2(dt.day)} ${_pad2(hour12)}:${_pad2(dt.minute)} $ampm';
  }

  String _safeFilePart(String input) {
    final s = input.trim();
    if (s.isEmpty) return 'Document';
    return s
        .replaceAll(RegExp(r'\s+'), '_')
        .replaceAll(RegExp(r'[^A-Za-z0-9_\-\.]'), '_')
        .replaceAll(RegExp(r'_+'), '_')
        .replaceAll(RegExp(r'^_+|_+$'), '');
  }

  Future<void> _ensureDeptCache() async {
    try {
      final now = DateTime.now();
      if (_deptByUsername.isNotEmpty && _deptByUsernameFetchedAt != null) {
        final age = now.difference(_deptByUsernameFetchedAt!);
        if (age.inMinutes < 10) return; // keep fresh enough
      }

      final users = await _fetchAllUsers();
      if (users.isEmpty) return;

      _deptByUsername.clear();
      for (final u in users) {
        final user =
            (u['user'] ?? u['username'] ?? u['name'] ?? '').toString().trim();
        final dept = (u['department'] ?? u['dept'] ?? '')
            .toString()
            .trim()
            .toUpperCase();
        if (user.isEmpty || dept.isEmpty) continue;
        _deptByUsername[user.toLowerCase()] = dept;
      }
      _deptByUsernameFetchedAt = now;
      // Debug logging removed
    } catch (_) {}
  }

  String _senderDeptLabel(String senderUsername, String? fallbackDept) {
    final key = senderUsername.trim().toLowerCase();
    final cached = key.isNotEmpty ? _deptByUsername[key] : null;
    if (cached != null && cached.isNotEmpty) return cached;
    final fb = (fallbackDept ?? '').trim().toUpperCase();
    return fb;
  }

  Future<Map<String, dynamic>?> _fetchRoutingMeta({
    String? trackingId,
    String? mobileTimestamp,
    String? docHash,
    String? filePath,
  }) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return null;

      final tid = (trackingId ?? '').trim();
      final int? id = tid.isNotEmpty ? int.tryParse(tid) : null;

      Uri uri;
      if (id != null && id > 0) {
        uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
          queryParameters: {'action': 'doc_detail', 'id': id.toString()},
        );
      } else {
        final mt = (mobileTimestamp ?? '').trim();
        final dh = (docHash ?? '').trim();
        final fp = (filePath ?? '').trim();
        if (mt.isEmpty && dh.isEmpty && fp.isEmpty) return null;
        uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
          queryParameters: {
            'action': 'resolve_identity',
            if (mt.isNotEmpty) 'mobile_timestamp': mt,
            if (dh.isNotEmpty) 'doc_hash': dh,
            if (fp.isNotEmpty) 'file_path': fp,
          },
        );
      }

      final r = await http.get(uri).timeout(const Duration(seconds: 6));
      if (r.statusCode >= 400 || r.body.isEmpty) return null;
      final decoded = jsonDecode(r.body);
      if (decoded is! Map) return null;

      // doc_detail -> {success:true, history:..., ...row fields...}
      // resolve_identity -> {success:true, doc:{...}}
      if (decoded['success'] != true) return null;
      final doc = (decoded['doc'] is Map)
          ? Map<String, dynamic>.from(decoded['doc'])
          : Map<String, dynamic>.from(decoded);
      return doc;
    } catch (_) {
      return null;
    }
  }

  Future<Map<String, String>> _resolveIdentityFromNotificationId({
    required int notificationId,
    String? fallbackFilePath,
    String? fallbackType,
    String? fallbackEndLocation,
  }) async {
    final out = <String, String>{};
    if (notificationId <= 0) return out;

    String normalize(dynamic value) {
      if (value == null) return '';
      final s = value.toString().trim();
      return s;
    }

    try {
      final root = await _getServerRoot();
      if (root == null) return out;

      final notifUri = Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
          .replace(queryParameters: {
        'action': 'get',
        'id': notificationId.toString(),
      });
      final nr = await http.get(notifUri).timeout(const Duration(seconds: 8));
      if (nr.statusCode == 200 && nr.body.isNotEmpty) {
        final decoded = jsonDecode(nr.body);
        final notifRaw = (decoded is Map)
            ? (decoded['notification'] ?? decoded['data'] ?? decoded)
            : null;
        if (notifRaw is Map) {
          final notif = Map<String, dynamic>.from(notifRaw);
          final tid = normalize(notif['tracking_id'] ?? notif['trackingId']);
          final mts =
              normalize(notif['mobile_timestamp'] ?? notif['mobileTimestamp']);
          final dh = normalize(notif['doc_hash'] ?? notif['docHash']);
          final fp = normalize(notif['file_url'] ??
              notif['file_path'] ??
              notif['fileUrl'] ??
              fallbackFilePath);
          final t = normalize(notif['type'] ?? fallbackType);
          final end = normalize(notif['end_location'] ??
              notif['endLocation'] ??
              fallbackEndLocation);

          if (tid.isNotEmpty) out['trackingId'] = tid;
          if (mts.isNotEmpty) out['mobileTimestamp'] = mts;
          if (dh.isNotEmpty) out['docHash'] = dh;
          if (fp.isNotEmpty) out['filePath'] = fp;
          if (t.isNotEmpty) out['type'] = t;
          if (end.isNotEmpty) out['endLocation'] = end;
        }
      }

      if (!out.containsKey('trackingId') ||
          !out.containsKey('mobileTimestamp') ||
          !out.containsKey('docHash')) {
        final meta = await _fetchRoutingMeta(
          trackingId: out['trackingId'],
          mobileTimestamp: out['mobileTimestamp'],
          docHash: out['docHash'],
          filePath: out['filePath'] ?? fallbackFilePath,
        );
        if (meta != null) {
          final id = normalize(meta['id']);
          final mts =
              normalize(meta['mobile_timestamp'] ?? meta['mobileTimestamp']);
          final dh = normalize(meta['doc_hash'] ?? meta['docHash']);
          final fp = normalize(meta['file_path'] ?? meta['filePath']);
          final t = normalize(meta['type']);
          final end = normalize(meta['end_location'] ?? meta['endLocation']);
          if (id.isNotEmpty) out['trackingId'] = id;
          if (mts.isNotEmpty) out['mobileTimestamp'] = mts;
          if (dh.isNotEmpty) out['docHash'] = dh;
          if (fp.isNotEmpty) out['filePath'] = fp;
          if (t.isNotEmpty) out['type'] = t;
          if (end.isNotEmpty) out['endLocation'] = end;
        }
      }
    } catch (_) {}

    return out;
  }

  Future<void> _showEditUpdateDocumentDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String docTitle,
    required String? currentType,
    required String? currentStatus,
    required String? currentDepartment,
    required String? currentHolder,
    required String? currentEndLocation,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'EditUpdate',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text(
                  'Cannot update: missing tracking identity (trackingId/mobileTimestamp/docHash)')),
        );
      }
      return;
    }

    final commentCtrl = TextEditingController();

    final documentTypes = CHRMODocumentClassifier.allDocumentTypes;
    final departments = [
      'CPDO',
      'GSO',
      'CBO',
      'CTO',
      'CACCO',
      'CADO',
      'CMO',
      'HR',
    ];

    String? matchOption(String? value, List<String> options) {
      final v = (value ?? '').trim();
      if (v.isEmpty) return null;
      for (final o in options) {
        if (o.toLowerCase() == v.toLowerCase()) return o;
      }
      return null;
    }

    String? selectedType = matchOption(currentType, documentTypes);
    String? selectedHolder = matchOption(currentHolder, departments);
    String? selectedEndLocation = matchOption(currentEndLocation, departments);

    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Edit / Update Document'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Document: $docTitle',
                    style: const TextStyle(fontWeight: FontWeight.w500)),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: selectedType,
                  decoration: const InputDecoration(
                    labelText: 'Type',
                    border: OutlineInputBorder(),
                  ),
                  items: documentTypes
                      .map((t) => DropdownMenuItem(value: t, child: Text(t)))
                      .toList(),
                  onChanged: (v) => setDialogState(() => selectedType = v),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  initialValue: selectedHolder,
                  decoration: const InputDecoration(
                    labelText: 'Current Holder',
                    border: OutlineInputBorder(),
                  ),
                  items: departments
                      .map((d) => DropdownMenuItem(value: d, child: Text(d)))
                      .toList(),
                  onChanged: (v) => setDialogState(() => selectedHolder = v),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  initialValue: selectedEndLocation,
                  decoration: const InputDecoration(
                    labelText: 'End Location',
                    border: OutlineInputBorder(),
                  ),
                  items: departments
                      .map((d) => DropdownMenuItem(value: d, child: Text(d)))
                      .toList(),
                  onChanged: (v) =>
                      setDialogState(() => selectedEndLocation = v),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: commentCtrl,
                  maxLines: 3,
                  decoration: const InputDecoration(
                    labelText: 'Comment (optional)',
                    border: OutlineInputBorder(),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: () {
                Navigator.pop(ctx, {
                  'type': (selectedType ?? '').trim(),
                  'current_holder': (selectedHolder ?? '').trim(),
                  'end_location': (selectedEndLocation ?? '').trim(),
                  'comment': commentCtrl.text.trim(),
                });
              },
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );

    if (result == null || !mounted) return;

    try {
      final root = await _getServerRoot();
      if (root == null) throw 'Server not configured';

      final body = <String, String>{
        'action': 'update_document',
        'tracking_id': resolvedTrackingId,
        'updated_by': username,
      };
      if ((result['type'] ?? '').trim().isNotEmpty) {
        body['type'] = (result['type'] ?? '').trim();
      }
      if ((result['current_holder'] ?? '').trim().isNotEmpty) {
        body['current_holder'] = (result['current_holder'] ?? '').trim();
      }
      if ((result['end_location'] ?? '').trim().isNotEmpty) {
        body['end_location'] = (result['end_location'] ?? '').trim();
      }
      if ((result['comment'] ?? '').trim().isNotEmpty) {
        body['comment'] = (result['comment'] ?? '').trim();
      }

      final response = await http.post(
        Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
        body: body,
      );
      final data = jsonDecode(response.body);
      if (data is Map && data['success'] == true) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Document updated successfully'),
              backgroundColor: Colors.green,
            ),
          );
          await _fetchRecentActivity();
        }
      } else {
        final err = (data is Map)
            ? (data['error'] ?? data['message'] ?? 'Failed to update document')
            : 'Failed to update document';
        throw err.toString();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  // Helpers to split activity into "received" vs "user's own" actions
  List<Map<String, dynamic>> _getReceivedActivityItems() {
    final current = username.trim().toLowerCase();
    return _recentActivity.where((m) {
      final sender = (m['sender'] ?? '').toString().trim().toLowerCase();
      // If we don't know the user name yet or sender is empty, keep in Recent Activity
      if (current.isEmpty || current == 'loading...') return true;
      if (sender.isEmpty) return true;
      return sender != current;
    }).toList();
  }

  Future<Map<String, dynamic>> _markInReview(
    String? trackingId, {
    String? docType,
    String? receiverDepartment,
    String? endLocation,
    int? notificationId,
  }) async {
    try {
      final root = await _getServerRoot();
      if (root == null) {
        return {'ok': false};
      }

      final id = trackingId?.trim();
      final int? numericId =
          (id != null && id.isNotEmpty) ? int.tryParse(id) : null;
      // Strategy 1: Direct tracking ID
      if (numericId != null && numericId > 0) {
        final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
          queryParameters: {
            'action': 'mark_in_review',
            'id': numericId.toString(),
            if ((receiverDepartment ?? '').trim().isNotEmpty)
              'receiver_department': receiverDepartment!.trim(),
          },
        );
        final r = await http.get(uri).timeout(const Duration(seconds: 12));
        if (r.statusCode >= 200 && r.statusCode < 300) {
          try {
            final data = jsonDecode(r.body);
            if (data is Map && data['success'] == true) {
              return {'ok': true};
            }
          } catch (_) {
            return {'ok': true};
          }
        }
      }

      // Strategy 2: Resolve tracking_id from notification ID
      if (notificationId != null && notificationId > 0) {
        try {
          final lookupUri =
              Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
                  .replace(queryParameters: {
            'action': 'get',
            'id': notificationId.toString(),
          });
          final lr =
              await http.get(lookupUri).timeout(const Duration(seconds: 8));
          if (lr.statusCode == 200 && lr.body.isNotEmpty) {
            final ldata = jsonDecode(lr.body);
            final notif = (ldata is Map)
                ? (ldata['notification'] ?? ldata['data'] ?? ldata)
                : null;
            if (notif is Map) {
              final resolvedTid = notif['tracking_id']?.toString().trim();
              final int? resolvedNumericId =
                  (resolvedTid != null && resolvedTid.isNotEmpty)
                      ? int.tryParse(resolvedTid)
                      : null;
              if (resolvedNumericId != null && resolvedNumericId > 0) {
                final uri2 = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php')
                    .replace(queryParameters: {
                  'action': 'mark_in_review',
                  'id': resolvedNumericId.toString(),
                  if ((receiverDepartment ?? '').trim().isNotEmpty)
                    'receiver_department': receiverDepartment!.trim(),
                });
                final r2 =
                    await http.get(uri2).timeout(const Duration(seconds: 12));
                if (r2.statusCode >= 200 && r2.statusCode < 300) {
                  try {
                    final data2 = jsonDecode(r2.body);
                    if (data2 is Map && data2['success'] == true) {
                      return {'ok': true};
                    }
                  } catch (_) {
                    return {'ok': true};
                  }
                }
              }
            }
          }
        } catch (_) {}

        // Strategy 2b: Let tracking.php resolve notification_id server-side
        try {
          final uri2b = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php')
              .replace(queryParameters: {
            'action': 'mark_in_review',
            'notification_id': notificationId.toString(),
            if ((receiverDepartment ?? '').trim().isNotEmpty)
              'receiver_department': receiverDepartment!.trim(),
            if ((endLocation ?? '').trim().isNotEmpty)
              'end_location': endLocation!.trim(),
            if ((docType ?? '').trim().isNotEmpty) 'type': docType!.trim(),
          });
          final r2b =
              await http.get(uri2b).timeout(const Duration(seconds: 12));
          if (r2b.statusCode >= 200 && r2b.statusCode < 300) {
            try {
              final data2b = jsonDecode(r2b.body);
              if (data2b is Map && data2b['success'] == true) {
                return {'ok': true};
              }
            } catch (_) {
              return {'ok': true};
            }
          }
        } catch (_) {}
      }

      // Strategy 3: Search by type + end_location/receiver_department
      final String t = (docType ?? '').trim();
      final String d = (receiverDepartment ?? '').trim();
      final String finalDept = (endLocation ?? '').trim();
      if (t.isNotEmpty && d.isNotEmpty) {
        final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
          queryParameters: {
            'action': 'mark_in_review',
            'type': t,
            'end_location': finalDept.isNotEmpty ? finalDept : d,
            'receiver_department': d,
            if (notificationId != null && notificationId > 0)
              'notification_id': notificationId.toString(),
          },
        );
        final r = await http.get(uri).timeout(const Duration(seconds: 12));
        if (r.statusCode >= 200 && r.statusCode < 300) {
          try {
            final data = jsonDecode(r.body);
            if (data is Map && data['success'] == true) {
              return {'ok': true};
            }
          } catch (_) {
            return {'ok': true};
          }
        }
      }

      // Strategy 3b: If type was generic or Strategy 3 failed, try with just receiver_department
      // This triggers the server's broad fallback (search by current_holder = receiver_department)
      if (d.isNotEmpty) {
        final uri3b = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
          queryParameters: {
            'action': 'mark_in_review',
            'receiver_department': d,
            if (finalDept.isNotEmpty) 'end_location': finalDept,
            if (notificationId != null && notificationId > 0)
              'notification_id': notificationId.toString(),
          },
        );
        final r3b = await http.get(uri3b).timeout(const Duration(seconds: 12));
        if (r3b.statusCode >= 200 && r3b.statusCode < 300) {
          try {
            final data3b = jsonDecode(r3b.body);
            if (data3b is Map && data3b['success'] == true) {
              return {'ok': true};
            }
          } catch (_) {
            return {'ok': true};
          }
        }
      }

      // Strategy 4: Update notification status directly as last resort
      if (notificationId != null && notificationId > 0) {
        try {
          final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php');
          final r = await http.post(uri, body: {
            'action': 'update_status',
            'id': notificationId.toString(),
            'status': 'in_review',
          }).timeout(const Duration(seconds: 8));
          if (r.statusCode >= 200 && r.statusCode < 300) {
            return {'ok': true};
          }
        } catch (_) {}
      }
      return {'ok': false};
    } catch (_) {
      return {'ok': false};
    }
  }

  List<Map<String, dynamic>> _getUserHistoryItems() {
    final current = username.trim().toLowerCase();
    if (current.isEmpty || current == 'loading...') return const [];
    return _recentActivity.where((m) {
      final sender = (m['sender'] ?? '').toString().trim().toLowerCase();
      return sender.isNotEmpty && sender == current;
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _pageController = PageController(initialPage: _currentIndex);
    _fetchUserData();
    _fetchRecentActivity();
    _fetchNotifications();
    // Observe app lifecycle to refresh when returning to foreground
    WidgetsBinding.instance.addObserver(this);
    // Periodic auto-refresh so web uploads appear on mobile Recent Activity
    _activityTimer = Timer.periodic(const Duration(seconds: 15), (_) {
      _fetchRecentActivity();
    });
    // Periodic notifications refresh
    _notifTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      _fetchNotifications();
    });
  }

  void _startRealtimeRoutingListener(String deptRaw) {
    final dept = deptRaw.trim().toUpperCase();
    if (dept.isEmpty) return;

    _routingSub?.cancel();
    _routingSub = RoutingService.listenForDepartment(dept).listen(
      (routes) async {
        if (!mounted) return;

        if (routes.isEmpty) {
          await _fetchRecentActivity();
          return;
        }

        final newestMs = routes.fold<int>(0, (acc, m) {
          final v = (m['createdAtMs'] as int?) ?? 0;
          return max(acc, v);
        });

        if (newestMs > 0) {
          if (newestMs <= _lastRealtimeRouteMs) return;
          _lastRealtimeRouteMs = newestMs;
        }

        await _fetchRecentActivity();
      },
      onError: (e) {
        // listener error silenced
      },
    );
  }

  Future<void> _showDocumentHistoryDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String docTitle,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'History',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Cannot view history: missing tracking identity'),
          ),
        );
      }
      return;
    }

    final root = await _getServerRoot();
    if (root == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Server not configured')),
        );
      }
      return;
    }

    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) {
        bool loading = true;
        String? error;
        List<Map<String, dynamic>> history = [];

        Future<void> load(StateSetter setState) async {
          setState(() {
            loading = true;
            error = null;
          });
          try {
            final uri =
                Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php')
                    .replace(queryParameters: {
              'action': 'get_history',
              'tracking_id': resolvedTrackingId,
            });
            final r = await http.get(uri).timeout(const Duration(seconds: 12));
            final data = jsonDecode(r.body);
            if (data is Map && data['success'] == true) {
              final list = (data['history'] ?? []) as List;
              history = list
                  .whereType<Map>()
                  .map((e) => Map<String, dynamic>.from(e))
                  .toList();
              setState(() {
                loading = false;
              });
              return;
            }
            setState(() {
              loading = false;
              error = (data is Map)
                  ? (data['error']?.toString() ?? 'Failed to load history')
                  : 'Failed to load history';
            });
          } catch (e) {
            setState(() {
              loading = false;
              error = e.toString();
            });
          }
        }

        return StatefulBuilder(
          builder: (ctx, setState) {
            if (loading && error == null && history.isEmpty) {
              Future.microtask(() => load(setState));
            }

            return AlertDialog(
              title: Text('History - $docTitle'),
              content: SizedBox(
                width: double.maxFinite,
                child: loading
                    ? const Padding(
                        padding: EdgeInsets.all(24),
                        child: Center(child: CircularProgressIndicator()),
                      )
                    : (error != null)
                        ? Text('Error: $error')
                        : (history.isEmpty)
                            ? const Text('No history found.')
                            : ListView.separated(
                                shrinkWrap: true,
                                itemCount: history.length,
                                separatorBuilder: (_, __) =>
                                    const Divider(height: 16),
                                itemBuilder: (_, i) {
                                  final row = history[i];
                                  final action =
                                      (row['action'] ?? '').toString().trim();
                                  final fromHolder =
                                      (row['from_holder'] ?? '').toString();
                                  final toHolder =
                                      (row['to_holder'] ?? '').toString();
                                  final fromStatus =
                                      (row['from_status'] ?? '').toString();
                                  final toStatus =
                                      (row['to_status'] ?? '').toString();
                                  final notes =
                                      (row['notes'] ?? '').toString().trim();
                                  final when =
                                      (row['created_at'] ?? '').toString();

                                  return ListTile(
                                    dense: true,
                                    title: Text(
                                      action.isEmpty ? 'event' : action,
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w600),
                                    ),
                                    subtitle: Text([
                                      if (fromHolder.isNotEmpty ||
                                          toHolder.isNotEmpty)
                                        '$fromHolder → $toHolder',
                                      if (fromStatus.isNotEmpty ||
                                          toStatus.isNotEmpty)
                                        '$fromStatus → $toStatus',
                                      if (notes.isNotEmpty) notes,
                                      if (when.isNotEmpty) when,
                                    ].join('\n')),
                                  );
                                },
                              ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: const Text('Close'),
                ),
                TextButton(
                  onPressed: loading ? null : () => load(setState),
                  child: const Text('Refresh'),
                ),
              ],
            );
          },
        );
      },
    );
  }

  Future<void> _showViewAttachmentsDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String docTitle,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'ViewAttachments',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Cannot view attachments: missing tracking identity'),
          ),
        );
      }
      return;
    }

    final root = await _getServerRoot();
    if (root == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Server not configured')),
        );
      }
      return;
    }

    // Helper to build full URL for attachment
    String buildFullUrl(String path) {
      String url;
      if (path.startsWith('http://') || path.startsWith('https://')) {
        url = path;
      } else if (path.startsWith('/')) {
        url = '$root$path';
      } else {
        // New uploads go to lib/OCR(UPDATED)/uploads/...
        // Legacy uploads were at lib/uploads/...
        // The API returns file_url with the correct resolved path, so this
        // fallback is only needed when file_url is empty.
        if (path.startsWith('uploads/')) {
          url = '$root/lib/OCR(UPDATED)/$path';
        } else {
          url = '$root/$path';
        }
      }

      // Encode special characters in URLs (e.g. parentheses in OCR(UPDATED)).
      // Without encoding, Android networking can fail to load the resource.
      return Uri.encodeFull(url);
    }

    // Helper to check if file is an image
    bool isImage(String path) {
      final ext = path.split('.').last.toLowerCase();
      return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].contains(ext);
    }

    await showDialog<void>(
      context: context,
      builder: (ctx) {
        bool loading = true;
        String? error;
        List<Map<String, dynamic>> attachments = [];

        Future<void> replaceAttachmentWithFile({
          required int attachmentId,
          required File file,
          required String remarks,
        }) async {
          final request = http.MultipartRequest(
            'POST',
            Uri.parse(
                '$root/lib/OCR(UPDATED)/api/document_actions.php?action=replace_attachment'),
          );
          request.fields['attachment_id'] = attachmentId.toString();
          request.fields['uploaded_by'] = username;
          request.fields['department'] = _userDepartment;
          request.fields['remarks'] = remarks;
          request.files
              .add(await http.MultipartFile.fromPath('file', file.path));

          final streamedResponse = await request.send();
          final response = await http.Response.fromStream(streamedResponse);
          final data = jsonDecode(response.body);
          if (!(data is Map && data['success'] == true)) {
            final err = (data is Map)
                ? (data['error'] ?? data['message'] ?? 'Replace failed')
                : 'Replace failed';
            throw err.toString();
          }
        }

        Future<void> replaceAttachmentWithBytes({
          required int attachmentId,
          required Uint8List bytes,
          required String filename,
          required String remarks,
        }) async {
          final request = http.MultipartRequest(
            'POST',
            Uri.parse(
                '$root/lib/OCR(UPDATED)/api/document_actions.php?action=replace_attachment'),
          );
          request.fields['attachment_id'] = attachmentId.toString();
          request.fields['uploaded_by'] = username;
          request.fields['department'] = _userDepartment;
          request.fields['remarks'] = remarks;
          request.files.add(
              http.MultipartFile.fromBytes('file', bytes, filename: filename));

          final streamedResponse = await request.send();
          final response = await http.Response.fromStream(streamedResponse);
          final data = jsonDecode(response.body);
          if (!(data is Map && data['success'] == true)) {
            final err = (data is Map)
                ? (data['error'] ?? data['message'] ?? 'Replace failed')
                : 'Replace failed';
            throw err.toString();
          }
        }

        Future<void> editPdfAndReplace({
          required int attachmentId,
          required String pdfUrl,
          required String displayName,
          required String remarks,
        }) async {
          final client = http.Client();
          late Uint8List pdfBytes;
          try {
            final resp = await client
                .get(Uri.parse(pdfUrl))
                .timeout(const Duration(seconds: 30));
            if (resp.statusCode < 200 || resp.statusCode >= 300) {
              throw 'Failed to download PDF (${resp.statusCode})';
            }
            pdfBytes = resp.bodyBytes;
          } finally {
            client.close();
          }

          final tmpDir = await getTemporaryDirectory();
          final srcPdf = File(
              '${tmpDir.path}/src_${DateTime.now().millisecondsSinceEpoch}.pdf');
          await srcPdf.writeAsBytes(pdfBytes, flush: true);

          final editedPages = <Uint8List>[];

          int pageIndex = 0;
          await for (final page in Printing.raster(
            pdfBytes,
            dpi: 150,
          )) {
            pageIndex++;
            final originalPng = await page.toPng();

            Uint8List? outBytes;
            if (!ctx.mounted) return;
            await Navigator.of(ctx).push(
              MaterialPageRoute(
                builder: (editCtx) {
                  return ProImageEditor.memory(
                    originalPng,
                    configs: const ProImageEditorConfigs(
                      paintEditor: PaintEditorConfigs(
                        enableModeEraser: true,
                        eraserMode: EraserMode.partial,
                        eraserSize: 24,
                        initialPaintMode: PaintMode.eraser,
                      ),
                    ),
                    callbacks: ProImageEditorCallbacks(
                      onImageEditingComplete: (Uint8List bytes) async {
                        outBytes = bytes;
                        if (editCtx.mounted) {
                          Navigator.pop(editCtx);
                        }
                      },
                    ),
                  );
                },
              ),
            );

            editedPages.add(outBytes ?? originalPng);
          }

          if (editedPages.isEmpty) {
            throw 'No pages rendered from PDF';
          }

          final outPdf = pw.Document();
          for (final imgBytes in editedPages) {
            final mem = pw.MemoryImage(imgBytes);
            outPdf.addPage(
              pw.Page(
                pageFormat: PdfPageFormat.a4,
                margin: pw.EdgeInsets.zero,
                build: (pw.Context ctx2) {
                  return pw.Center(
                    child: pw.Image(mem, fit: pw.BoxFit.contain),
                  );
                },
              ),
            );
          }

          final outPath =
              '${tmpDir.path}/edited_${DateTime.now().millisecondsSinceEpoch}.pdf';
          final outFile = File(outPath);
          await outFile.writeAsBytes(await outPdf.save(), flush: true);

          await replaceAttachmentWithFile(
            attachmentId: attachmentId,
            file: outFile,
            remarks: remarks,
          );
        }

        Future<void> load(StateSetter setState) async {
          setState(() {
            loading = true;
            error = null;
          });
          try {
            final uri =
                Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php')
                    .replace(queryParameters: {
              'action': 'get_attachments',
              'tracking_id': resolvedTrackingId,
            });
            final r = await http.get(uri).timeout(const Duration(seconds: 12));
            final data = jsonDecode(r.body);
            if (data is Map && data['success'] == true) {
              final list = (data['attachments'] ?? []) as List;
              attachments = list
                  .whereType<Map>()
                  .map((e) => Map<String, dynamic>.from(e))
                  .toList();
              setState(() {
                loading = false;
              });
              return;
            }
            setState(() {
              loading = false;
              error = (data is Map)
                  ? (data['error']?.toString() ?? 'Failed to load attachments')
                  : 'Failed to load attachments';
            });
          } catch (e) {
            setState(() {
              loading = false;
              error = e.toString();
            });
          }
        }

        return StatefulBuilder(
          builder: (context, setState) {
            if (loading && attachments.isEmpty && error == null) {
              Future.microtask(() => load(setState));
            }

            return Dialog(
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16)),
              child: Container(
                constraints:
                    const BoxConstraints(maxWidth: 500, maxHeight: 600),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // Header
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: const BoxDecoration(
                        color: Color(0xFF6868AC),
                        borderRadius: BorderRadius.only(
                          topLeft: Radius.circular(16),
                          topRight: Radius.circular(16),
                        ),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.attach_file,
                              color: Colors.white, size: 24),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'Attachments',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 18,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                                Text(
                                  docTitle,
                                  style: TextStyle(
                                    color: Colors.white.withOpacity(0.9),
                                    fontSize: 13,
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ],
                            ),
                          ),
                          IconButton(
                            icon: const Icon(Icons.close, color: Colors.white),
                            onPressed: () => Navigator.pop(ctx),
                          ),
                        ],
                      ),
                    ),
                    // Content
                    Flexible(
                      child: loading
                          ? const Padding(
                              padding: EdgeInsets.all(40),
                              child: Center(child: CircularProgressIndicator()),
                            )
                          : (error != null)
                              ? Padding(
                                  padding: const EdgeInsets.all(20),
                                  child: Column(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(Icons.error_outline,
                                          size: 48, color: Colors.red.shade400),
                                      const SizedBox(height: 12),
                                      Text('Error: $error',
                                          textAlign: TextAlign.center),
                                    ],
                                  ),
                                )
                              : (attachments.isEmpty)
                                  ? Padding(
                                      padding: const EdgeInsets.all(40),
                                      child: Column(
                                        mainAxisSize: MainAxisSize.min,
                                        children: [
                                          Icon(Icons.folder_open,
                                              size: 64,
                                              color: Colors.grey.shade400),
                                          const SizedBox(height: 16),
                                          Text(
                                            'No attachments yet',
                                            style: TextStyle(
                                                fontSize: 16,
                                                color: Colors.grey.shade600),
                                          ),
                                          const SizedBox(height: 8),
                                          Text(
                                            'Attachments added during routing will appear here',
                                            style: TextStyle(
                                                fontSize: 13,
                                                color: Colors.grey.shade500),
                                            textAlign: TextAlign.center,
                                          ),
                                        ],
                                      ),
                                    )
                                  : ListView.builder(
                                      shrinkWrap: true,
                                      padding: const EdgeInsets.all(12),
                                      itemCount: attachments.length,
                                      itemBuilder: (context, i) {
                                        final a = attachments[i];
                                        final attachmentId = int.tryParse(
                                                (a['id'] ?? '').toString()) ??
                                            0;
                                        final name = (a['file_name'] ??
                                                a['fileName'] ??
                                                a['name'] ??
                                                '')
                                            .toString();
                                        final path = (a['file_path'] ??
                                                a['filePath'] ??
                                                a['path'] ??
                                                '')
                                            .toString();
                                        final remarks =
                                            (a['remarks'] ?? a['comment'] ?? '')
                                                .toString();
                                        final by = (a['uploaded_by'] ??
                                                a['uploadedBy'] ??
                                                a['uploader'] ??
                                                '')
                                            .toString();
                                        final dept =
                                            (a['department'] ?? a['dept'] ?? '')
                                                .toString();
                                        final created = (a['created_at'] ??
                                                a['createdAt'] ??
                                                '')
                                            .toString();
                                        final display = name.isNotEmpty
                                            ? name
                                            : (path.isNotEmpty
                                                ? path.split('/').last
                                                : 'Attachment');
                                        final apiUrl = (a['file_url'] ??
                                                a['fileUrl'] ??
                                                a['url'] ??
                                                '')
                                            .toString();
                                        final fullUrl = buildFullUrl(
                                            apiUrl.trim().isNotEmpty
                                                ? apiUrl
                                                : path);
                                        final showImage = isImage(path);
                                        final lowerPath = path.toLowerCase();
                                        final isPdf =
                                            lowerPath.endsWith('.pdf') ||
                                                (name
                                                    .toLowerCase()
                                                    .endsWith('.pdf'));

                                        return Card(
                                          elevation: 2,
                                          margin:
                                              const EdgeInsets.only(bottom: 12),
                                          shape: RoundedRectangleBorder(
                                              borderRadius:
                                                  BorderRadius.circular(12)),
                                          child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                              // Image preview for image files
                                              if (showImage &&
                                                  path.trim().isNotEmpty)
                                                ClipRRect(
                                                  borderRadius:
                                                      const BorderRadius.only(
                                                    topLeft:
                                                        Radius.circular(12),
                                                    topRight:
                                                        Radius.circular(12),
                                                  ),
                                                  child: GestureDetector(
                                                    onTap: () =>
                                                        _openUrl(fullUrl),
                                                    child: Container(
                                                      height: 180,
                                                      width: double.infinity,
                                                      color:
                                                          Colors.grey.shade200,
                                                      child: Image.network(
                                                        fullUrl,
                                                        fit: BoxFit.cover,
                                                        loadingBuilder: (ctx,
                                                            child, progress) {
                                                          if (progress ==
                                                              null) {
                                                            return child;
                                                          }
                                                          return Center(
                                                            child:
                                                                CircularProgressIndicator(
                                                              value: progress
                                                                          .expectedTotalBytes !=
                                                                      null
                                                                  ? progress
                                                                          .cumulativeBytesLoaded /
                                                                      progress
                                                                          .expectedTotalBytes!
                                                                  : null,
                                                            ),
                                                          );
                                                        },
                                                        errorBuilder:
                                                            (ctx, err, stack) =>
                                                                Center(
                                                          child: Column(
                                                            mainAxisAlignment:
                                                                MainAxisAlignment
                                                                    .center,
                                                            children: [
                                                              Icon(
                                                                  Icons
                                                                      .broken_image,
                                                                  size: 48,
                                                                  color: Colors
                                                                      .grey
                                                                      .shade400),
                                                              const SizedBox(
                                                                  height: 8),
                                                              Text(
                                                                  'Failed to load',
                                                                  style: TextStyle(
                                                                      color: Colors
                                                                          .grey
                                                                          .shade500,
                                                                      fontSize:
                                                                          12)),
                                                            ],
                                                          ),
                                                        ),
                                                      ),
                                                    ),
                                                  ),
                                                ),
                                              // File info
                                              Padding(
                                                padding:
                                                    const EdgeInsets.all(12),
                                                child: Column(
                                                  crossAxisAlignment:
                                                      CrossAxisAlignment.start,
                                                  children: [
                                                    Row(
                                                      children: [
                                                        Icon(
                                                          showImage
                                                              ? Icons.image
                                                              : Icons
                                                                  .insert_drive_file,
                                                          size: 20,
                                                          color: Colors
                                                              .blue.shade600,
                                                        ),
                                                        const SizedBox(
                                                            width: 8),
                                                        Expanded(
                                                          child: Text(
                                                            display,
                                                            style: const TextStyle(
                                                                fontWeight:
                                                                    FontWeight
                                                                        .w600,
                                                                fontSize: 14),
                                                            maxLines: 2,
                                                            overflow:
                                                                TextOverflow
                                                                    .ellipsis,
                                                          ),
                                                        ),
                                                        if (attachmentId > 0)
                                                          IconButton(
                                                            tooltip: 'Edit',
                                                            onPressed:
                                                                () async {
                                                              try {
                                                                if (isPdf) {
                                                                  await editPdfAndReplace(
                                                                    attachmentId:
                                                                        attachmentId,
                                                                    pdfUrl:
                                                                        fullUrl,
                                                                    displayName:
                                                                        display,
                                                                    remarks:
                                                                        remarks,
                                                                  );
                                                                  await load(
                                                                      setState);
                                                                  if (ctx
                                                                      .mounted) {
                                                                    ScaffoldMessenger.of(
                                                                            ctx)
                                                                        .showSnackBar(
                                                                      const SnackBar(
                                                                        content:
                                                                            Text('PDF attachment updated'),
                                                                        backgroundColor:
                                                                            Colors.green,
                                                                      ),
                                                                    );
                                                                  }
                                                                  return;
                                                                }

                                                                if (!showImage ||
                                                                    fullUrl
                                                                        .trim()
                                                                        .isEmpty) {
                                                                  return;
                                                                }

                                                                if (!ctx
                                                                    .mounted) {
                                                                  return;
                                                                }
                                                                await Navigator
                                                                        .of(ctx)
                                                                    .push(
                                                                  MaterialPageRoute(
                                                                    builder:
                                                                        (editCtx) {
                                                                      return ProImageEditor
                                                                          .network(
                                                                        fullUrl,
                                                                        configs:
                                                                            const ProImageEditorConfigs(
                                                                          paintEditor:
                                                                              PaintEditorConfigs(
                                                                            enableModeEraser:
                                                                                true,
                                                                            eraserMode:
                                                                                EraserMode.partial,
                                                                            eraserSize:
                                                                                24,
                                                                            initialPaintMode:
                                                                                PaintMode.eraser,
                                                                          ),
                                                                        ),
                                                                        callbacks:
                                                                            ProImageEditorCallbacks(
                                                                          onImageEditingComplete:
                                                                              (Uint8List bytes) async {
                                                                            final fn = (display.trim().isNotEmpty
                                                                                ? display
                                                                                : 'edited.jpg');
                                                                            final safeName = fn.toLowerCase().endsWith('.png')
                                                                                ? fn
                                                                                : (fn.toLowerCase().endsWith('.jpg') || fn.toLowerCase().endsWith('.jpeg'))
                                                                                    ? fn
                                                                                    : 'edited.jpg';
                                                                            await replaceAttachmentWithBytes(
                                                                              attachmentId: attachmentId,
                                                                              bytes: bytes,
                                                                              filename: safeName,
                                                                              remarks: remarks,
                                                                            );
                                                                            if (editCtx.mounted) {
                                                                              Navigator.pop(editCtx);
                                                                            }
                                                                          },
                                                                        ),
                                                                      );
                                                                    },
                                                                  ),
                                                                );

                                                                await load(
                                                                    setState);
                                                                if (ctx
                                                                    .mounted) {
                                                                  ScaffoldMessenger
                                                                          .of(ctx)
                                                                      .showSnackBar(
                                                                    const SnackBar(
                                                                      content: Text(
                                                                          'Attachment updated'),
                                                                      backgroundColor:
                                                                          Colors
                                                                              .green,
                                                                    ),
                                                                  );
                                                                }
                                                              } catch (e) {
                                                                if (ctx
                                                                    .mounted) {
                                                                  ScaffoldMessenger
                                                                          .of(ctx)
                                                                      .showSnackBar(
                                                                    SnackBar(
                                                                      content: Text(
                                                                          'Edit failed: $e'),
                                                                      backgroundColor:
                                                                          Colors
                                                                              .red,
                                                                    ),
                                                                  );
                                                                }
                                                              }
                                                            },
                                                            icon: const Icon(
                                                                Icons.edit,
                                                                size: 18),
                                                          ),
                                                      ],
                                                    ),
                                                    const SizedBox(height: 8),
                                                    // Metadata row
                                                    Wrap(
                                                      spacing: 12,
                                                      runSpacing: 4,
                                                      children: [
                                                        if (by
                                                            .trim()
                                                            .isNotEmpty)
                                                          Row(
                                                            mainAxisSize:
                                                                MainAxisSize
                                                                    .min,
                                                            children: [
                                                              Icon(Icons.person,
                                                                  size: 14,
                                                                  color: Colors
                                                                      .grey
                                                                      .shade600),
                                                              const SizedBox(
                                                                  width: 4),
                                                              Text(by,
                                                                  style: TextStyle(
                                                                      fontSize:
                                                                          12,
                                                                      color: Colors
                                                                          .grey
                                                                          .shade700)),
                                                            ],
                                                          ),
                                                        if (dept
                                                            .trim()
                                                            .isNotEmpty)
                                                          Row(
                                                            mainAxisSize:
                                                                MainAxisSize
                                                                    .min,
                                                            children: [
                                                              Icon(
                                                                  Icons
                                                                      .business,
                                                                  size: 14,
                                                                  color: Colors
                                                                      .grey
                                                                      .shade600),
                                                              const SizedBox(
                                                                  width: 4),
                                                              Text(dept,
                                                                  style: TextStyle(
                                                                      fontSize:
                                                                          12,
                                                                      color: Colors
                                                                          .grey
                                                                          .shade700)),
                                                            ],
                                                          ),
                                                        if (created
                                                            .trim()
                                                            .isNotEmpty)
                                                          Row(
                                                            mainAxisSize:
                                                                MainAxisSize
                                                                    .min,
                                                            children: [
                                                              Icon(
                                                                  Icons
                                                                      .access_time,
                                                                  size: 14,
                                                                  color: Colors
                                                                      .grey
                                                                      .shade600),
                                                              const SizedBox(
                                                                  width: 4),
                                                              Text(created,
                                                                  style: TextStyle(
                                                                      fontSize:
                                                                          12,
                                                                      color: Colors
                                                                          .grey
                                                                          .shade700)),
                                                            ],
                                                          ),
                                                      ],
                                                    ),
                                                    if (remarks
                                                        .trim()
                                                        .isNotEmpty) ...[
                                                      const SizedBox(height: 8),
                                                      Container(
                                                        padding:
                                                            const EdgeInsets
                                                                .all(8),
                                                        decoration:
                                                            BoxDecoration(
                                                          color: Colors
                                                              .grey.shade100,
                                                          borderRadius:
                                                              BorderRadius
                                                                  .circular(8),
                                                        ),
                                                        child: Row(
                                                          crossAxisAlignment:
                                                              CrossAxisAlignment
                                                                  .start,
                                                          children: [
                                                            Icon(Icons.notes,
                                                                size: 16,
                                                                color: Colors
                                                                    .grey
                                                                    .shade600),
                                                            const SizedBox(
                                                                width: 8),
                                                            Expanded(
                                                              child: Text(
                                                                remarks,
                                                                style: TextStyle(
                                                                    fontSize:
                                                                        12,
                                                                    color: Colors
                                                                        .grey
                                                                        .shade700),
                                                              ),
                                                            ),
                                                          ],
                                                        ),
                                                      ),
                                                    ],
                                                    const SizedBox(height: 12),
                                                    // Action buttons
                                                    Row(
                                                      children: [
                                                        Expanded(
                                                          child: SizedBox(
                                                            height: 40,
                                                            child:
                                                                OutlinedButton
                                                                    .icon(
                                                              onPressed: path
                                                                      .trim()
                                                                      .isEmpty
                                                                  ? null
                                                                  : () async {
                                                                      await Clipboard.setData(
                                                                          ClipboardData(
                                                                              text: fullUrl));
                                                                      if (ctx
                                                                          .mounted) {
                                                                        ScaffoldMessenger.of(ctx)
                                                                            .showSnackBar(
                                                                          const SnackBar(
                                                                              content: Text('Link copied')),
                                                                        );
                                                                      }
                                                                    },
                                                              icon: const Icon(
                                                                  Icons.copy,
                                                                  size: 16),
                                                              label: const Text(
                                                                  'Copy Link'),
                                                              style:
                                                                  OutlinedButton
                                                                      .styleFrom(
                                                                padding:
                                                                    const EdgeInsets
                                                                        .symmetric(
                                                                        vertical:
                                                                            8),
                                                                minimumSize:
                                                                    const Size(
                                                                        0, 40),
                                                              ),
                                                            ),
                                                          ),
                                                        ),
                                                        const SizedBox(
                                                            width: 8),
                                                        Expanded(
                                                          child: SizedBox(
                                                            height: 40,
                                                            child:
                                                                OutlinedButton
                                                                    .icon(
                                                              onPressed:
                                                                  () async {
                                                                if (!ctx
                                                                    .mounted) {
                                                                  return;
                                                                }
                                                                Navigator.pop(
                                                                    ctx);
                                                                await _showViewCommentsDialog(
                                                                  trackingId:
                                                                      resolvedTrackingId,
                                                                  mobileTimestamp:
                                                                      mobileTimestamp,
                                                                  docHash:
                                                                      docHash,
                                                                  filePath:
                                                                      filePath,
                                                                  docTitle:
                                                                      docTitle,
                                                                );
                                                              },
                                                              icon: const Icon(
                                                                  Icons.comment,
                                                                  size: 16),
                                                              label: const Text(
                                                                  'Comments'),
                                                              style:
                                                                  OutlinedButton
                                                                      .styleFrom(
                                                                padding:
                                                                    const EdgeInsets
                                                                        .symmetric(
                                                                        vertical:
                                                                            8),
                                                                minimumSize:
                                                                    const Size(
                                                                        0, 40),
                                                              ),
                                                            ),
                                                          ),
                                                        ),
                                                        const SizedBox(
                                                            width: 8),
                                                        Expanded(
                                                          child: SizedBox(
                                                            height: 40,
                                                            child:
                                                                ElevatedButton
                                                                    .icon(
                                                              onPressed: path
                                                                      .trim()
                                                                      .isEmpty
                                                                  ? null
                                                                  : () => _openUrl(
                                                                      fullUrl),
                                                              icon: const Icon(
                                                                  Icons
                                                                      .open_in_new,
                                                                  size: 16),
                                                              label: const Text(
                                                                  'Open'),
                                                              style:
                                                                  ElevatedButton
                                                                      .styleFrom(
                                                                padding:
                                                                    const EdgeInsets
                                                                        .symmetric(
                                                                        vertical:
                                                                            8),
                                                                minimumSize:
                                                                    const Size(
                                                                        0, 40),
                                                                backgroundColor:
                                                                    const Color(
                                                                        0xFF6868AC),
                                                                foregroundColor:
                                                                    Colors
                                                                        .white,
                                                              ),
                                                            ),
                                                          ),
                                                        ),
                                                      ],
                                                    ),
                                                  ],
                                                ),
                                              ),
                                            ],
                                          ),
                                        );
                                      },
                                    ),
                    ),
                    // Footer
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade50,
                        borderRadius: const BorderRadius.only(
                          bottomLeft: Radius.circular(16),
                          bottomRight: Radius.circular(16),
                        ),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text(
                            '${attachments.length} attachment${attachments.length == 1 ? '' : 's'}',
                            style: TextStyle(
                                color: Colors.grey.shade600, fontSize: 13),
                          ),
                          TextButton.icon(
                            onPressed: () => load(setState),
                            icon: const Icon(Icons.refresh, size: 18),
                            label: const Text('Refresh'),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final bottomPad = MediaQuery.of(context).padding.bottom + 80;

    return Scaffold(
      appBar: _currentIndex == 1
          ? null
          : AppBar(
              elevation: 0,
              title: _showSearch ? _buildSearchField() : Text(_getPageTitle()),
              actions: [
                IconButton(
                  icon: Stack(
                    clipBehavior: Clip.none,
                    children: [
                      const Icon(Icons.notifications_none, color: Colors.white),
                      if (_unreadCount > 0)
                        Positioned(
                          right: -2,
                          top: -2,
                          child: Container(
                            padding: const EdgeInsets.all(2),
                            decoration: const BoxDecoration(
                              color: Colors.red,
                              shape: BoxShape.circle,
                            ),
                            constraints: const BoxConstraints(
                                minWidth: 18, minHeight: 18),
                            child: Center(
                              child: Text(
                                _unreadCount > 9
                                    ? '9+'
                                    : _unreadCount.toString(),
                                style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                  onPressed: () async {
                    await Navigator.push(
                      context,
                      MaterialPageRoute(
                          builder: (_) => const NotificationPage()),
                    );
                    if (mounted) {
                      _fetchNotifications();
                    }
                  },
                  tooltip: 'Notifications',
                ),
                // Profile button on the right side of notification
                IconButton(
                  icon: const Icon(Icons.person_outline, color: Colors.white),
                  onPressed: () {
                    _pageController.animateToPage(
                      2,
                      duration: const Duration(milliseconds: 250),
                      curve: Curves.easeInOut,
                    );
                    setState(() => _currentIndex = 2);
                  },
                  tooltip: 'Profile',
                ),
              ],
              systemOverlayStyle: SystemUiOverlayStyle.light,
            ),
      body: SafeArea(
        top: false,
        bottom: true,
        child: PageView(
          controller: _pageController,
          physics: const BouncingScrollPhysics(), // Smoother swiping
          onPageChanged: (index) {
            setState(() => _currentIndex = index);
            PerformanceUtils.lightHaptic(); // Optimized haptic feedback
          },
          children: [
            // Wrap each page in RepaintBoundary to prevent unnecessary repaints
            RepaintBoundary(
                child: _buildDashboardContent(bottomPad: bottomPad)),
            const RepaintBoundary(child: GalleryPage()),
            RepaintBoundary(child: _buildProfileContent(bottomPad: bottomPad)),
          ],
        ),
      ),
      floatingActionButton: _buildFAB(),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
      bottomNavigationBar: SafeArea(
        top: false,
        child: _buildBottomNav(),
      ),
    );
  }

  Future<void> _openAllenDocumentDetail({
    required String subtitle,
    required String recipientDepartment,
    String? fileUrl,
  }) async {
    try {
      // Derive document name from subtitle like "Type • Name"
      String name = subtitle.trim();
      if (name.contains('•')) {
        final parts = name.split('•');
        if (parts.length >= 2) name = parts.last.trim();
      }
      final root = await _getServerRoot();
      if (root == null) throw 'No server root';
      final dept = recipientDepartment.trim();
      if (dept.isEmpty || name.isEmpty) throw 'Missing info';

      // Build candidates as in opener, try with common extensions if needed
      String ext = '';
      final i = name.lastIndexOf('.');
      if (i != -1 && i < name.length - 1) {
        ext = name.substring(i + 1).toLowerCase();
      }
      final names = <String>[name];
      if (ext.isEmpty) {
        names.addAll(['$name.jpg', '$name.jpeg', '$name.png', '$name.pdf']);
      }
      final paths = <String>[];
      for (final n in names) {
        final e = Uri.encodeComponent(n);
        // Try both encoded and raw to handle servers expecting raw spaces.
        for (final nameVariant in [e, n]) {
          paths.addAll([
            '$root/Archive/$dept/$nameVariant',
            '$root/lib/Archive/$dept/$nameVariant',
            '$root/lib/OCR(UPDATED)/Archive/$dept/$nameVariant',
            '$root/uploads/$dept/$nameVariant',
            '$root/Uploads/$dept/$nameVariant',
            '$root/flutter_application_7/Archive/$dept/$nameVariant',
          ]);
        }
      }

      // Prefer provided URL if works
      String? imageOrPdf;
      if (fileUrl != null && fileUrl.trim().isNotEmpty) {
        try {
          final r = await http
              .head(Uri.parse(fileUrl))
              .timeout(const Duration(seconds: 5));
          if (r.statusCode < 400) {
            imageOrPdf = fileUrl;
          } else {
            // Even if HEAD fails (some servers), try using the URL anyway.
            imageOrPdf = fileUrl;
          }
        } catch (_) {
          imageOrPdf = fileUrl; // optimistic use
        }
      }
      if (imageOrPdf == null) {
        for (final u in paths) {
          try {
            final r = await http
                .head(Uri.parse(u))
                .timeout(const Duration(seconds: 5));
            if (r.statusCode < 400) {
              imageOrPdf = u;
              break;
            }
          } catch (_) {
            // Ignore and continue; we will fall back to first candidate
          }
        }
      }
      imageOrPdf ??= paths.isNotEmpty ? paths.first : null;
      if (imageOrPdf == null) throw 'Missing filename/path';

      // If it's a PDF, open with PDF viewer
      final lowerUrl = imageOrPdf.toLowerCase();
      if (lowerUrl.endsWith('.pdf')) {
        await _openPdf(imageOrPdf, title: name);
        return;
      }

      // Try to guess OCR text URL from image URL
      String? ocrUrl;
      try {
        final uri = Uri.parse(imageOrPdf);
        final p = uri.path.toLowerCase();
        String txtCandidate;
        if (p.endsWith('.jpg') || p.endsWith('.jpeg') || p.endsWith('.png')) {
          txtCandidate = uri.toString().replaceAll(
              RegExp(r'\.(jpg|jpeg|png)$', caseSensitive: false), '.txt');
        } else {
          // If pdf or unknown, try a sibling .txt with the same base name
          final base = uri.toString();
          final lastDot = base.lastIndexOf('.');
          txtCandidate = lastDot != -1
              ? ('${base.substring(0, lastDot)}.txt')
              : ('$base.txt');
        }
        final head = await http
            .head(Uri.parse(txtCandidate))
            .timeout(const Duration(seconds: 4));
        if (head.statusCode == 200) ocrUrl = txtCandidate;
      } catch (_) {}

      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => DashboardDocumentPreview(
            title: name,
            imageUrl: imageOrPdf!,
            ocrUrl: ocrUrl,
          ),
        ),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Open failed: $e')));
      }
    }
  }

  Future<bool> _deleteTrackingById(int id) async {
    try {
      if (id <= 0) return false;
      final root = await _getServerRoot();
      if (root == null) return false;

      final uri = Uri.parse('$root/api/recent_activity.php')
          .replace(queryParameters: {'action': 'delete', 'id': id.toString()});
      final r = await http.post(uri).timeout(const Duration(seconds: 8));
      if (r.statusCode >= 200 && r.statusCode < 300) {
        try {
          final data = jsonDecode(r.body);
          if (data is Map && data['success'] == true) return true;
        } catch (_) {
          return true;
        }
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  Map<String, String?> _extractIdentityFields(Map source) {
    final Map<String, String?> out = {};

    void capture(String key, dynamic value) {
      if (value == null) return;
      final str = value.toString().trim();
      if (str.isEmpty) return;
      out[key] = str;
    }

    dynamic metaRaw = source['meta'];
    if (metaRaw is String) {
      try {
        final decoded = jsonDecode(metaRaw);
        if (decoded is Map) metaRaw = decoded;
      } catch (_) {}
    }
    if (metaRaw is Map) {
      capture('tracking_id', metaRaw['tracking_id']);
      capture('mobile_timestamp', metaRaw['mobile_timestamp']);
      capture('doc_hash', metaRaw['doc_hash']);
      capture('file_path', metaRaw['file_path']);
    }

    // If this source looks like a tracking table row (from tracking.php?action=notifications
    // or recent_activity.php), the row `id` is the tracking record id.
    // Do NOT do this for notifications.php rows (their `id` is notification id).
    final bool looksLikeNotificationRow =
        source.containsKey('recipient_username') ||
            source.containsKey('recipient_department') ||
            source.containsKey('sender_username') ||
            source.containsKey('title');
    final bool looksLikeTrackingRow = !looksLikeNotificationRow &&
        (source.containsKey('employee_name') ||
            source.containsKey('current_holder') ||
            source.containsKey('date_submitted') ||
            source.containsKey('mobile_timestamp'));
    if (looksLikeTrackingRow) {
      capture('tracking_id',
          source['tracking_id'] ?? source['doc_id'] ?? source['id']);
    }

    capture('tracking_id', source['tracking_id'] ?? source['trackingId']);
    capture('mobile_timestamp',
        source['mobile_timestamp'] ?? source['mobileTimestamp']);
    capture('doc_hash', source['doc_hash'] ?? source['docHash']);
    capture('file_path', source['file_path'] ?? source['filePath']);

    return out;
  }

  Map<String, dynamic> _decorateActivityWithIdentity(
      Map<String, dynamic> activity, Map source) {
    // When the source is already a map of fields (from notifications API), we
    // can safely store it so later fetches or UI cards can rehydrate even if
    // the immediate entry lacks docHash/trackingId/mobileTimestamp.
    if (source.isNotEmpty) {
      _rememberIdentityForActivity(Map<String, dynamic>.from(source));
    }

    final identity = _extractIdentityFields(source);
    if (identity.isEmpty) {
      _hydrateActivityFromCache(activity);
      return activity;
    }

    void assign(String targetKey, String identityKey) {
      final value = identity[identityKey];
      if (value != null && value.isNotEmpty) {
        activity[targetKey] = value;
      }
    }

    assign('trackingId', 'tracking_id');
    assign('mobileTimestamp', 'mobile_timestamp');
    assign('docHash', 'doc_hash');
    assign('filePath', 'file_path');

    final filePath = identity['file_path'];
    if ((activity['fileUrl'] == null ||
            activity['fileUrl'].toString().trim().isEmpty) &&
        filePath != null &&
        filePath.isNotEmpty) {
      activity['fileUrl'] = filePath;
    }

    _rememberIdentityForActivity(activity);

    return activity;
  }

  List<String> _identityKeyCandidates(Map activity) {
    final List<String> keys = [];
    String? normalize(dynamic value) {
      if (value == null) return null;
      final str = value.toString().trim();
      return str.isEmpty ? null : str.toLowerCase();
    }

    final filePath = normalize(activity['filePath']);
    if (filePath != null) keys.add('path:$filePath');

    final subtitle = normalize(activity['subtitle']);
    if (subtitle != null) keys.add('name:$subtitle');

    final title = normalize(activity['title']);
    if (title != null) keys.add('title:$title');

    final combo = [activity['title'], activity['subtitle'], activity['sender']]
        .whereType<String>()
        .map((e) => e.trim())
        .where((e) => e.isNotEmpty)
        .join('|')
        .toLowerCase();
    if (combo.isNotEmpty) keys.add('combo:$combo');

    return keys.isEmpty ? ['fallback:${activity.hashCode}'] : keys;
  }

  void _rememberIdentityForActivity(Map<String, dynamic> activity) {
    String? readValue(String key) {
      final v = activity[key];
      if (v == null) return null;
      final trimmed = v.toString().trim();
      return trimmed.isEmpty ? null : trimmed;
    }

    String? readAny(List<String> keys) {
      for (final key in keys) {
        final value = readValue(key);
        if (value != null) return value;
      }
      return null;
    }

    final Map<String, String> entry = {};

    void store(List<String> sourceKeys, String targetKey) {
      final value = readAny(sourceKeys);
      if (value != null) entry[targetKey] = value;
    }

    store(const ['trackingId', 'tracking_id', 'trackingID'], 'trackingId');
    store(const ['mobileTimestamp', 'mobile_timestamp'], 'mobileTimestamp');
    store(const ['docHash', 'doc_hash'], 'docHash');
    store(const ['filePath', 'file_path', 'fileUrl', 'file_url'], 'filePath');

    if (entry.isEmpty) return;

    for (final key in _identityKeyCandidates(activity)) {
      final existing = _identityCache[key] ?? <String, String>{};
      existing.addAll(entry);
      _identityCache[key] = existing;
    }
  }

  void _hydrateActivityFromCache(Map<String, dynamic> activity) {
    bool needs(String field) =>
        activity[field] == null || activity[field].toString().trim().isEmpty;

    if (!needs('trackingId') &&
        !needs('mobileTimestamp') &&
        !needs('docHash') &&
        !needs('filePath')) {
      return;
    }

    for (final key in _identityKeyCandidates(activity)) {
      final cached = _identityCache[key];
      if (cached == null) continue;
      for (final field in [
        'trackingId',
        'mobileTimestamp',
        'docHash',
        'filePath'
      ]) {
        if (needs(field) && cached[field]?.isNotEmpty == true) {
          activity[field] = cached[field];
        }
      }
      if (!needs('trackingId') &&
          !needs('mobileTimestamp') &&
          !needs('docHash') &&
          !needs('filePath')) {
        break;
      }
    }
  }

  Future<void> _openPdf(String urlOrPath, {String? title}) async {
    try {
      String pathToOpen = urlOrPath;
      if (urlOrPath.startsWith('http://') || urlOrPath.startsWith('https://')) {
        // Download to temp
        final r = await http
            .get(Uri.parse(urlOrPath))
            .timeout(const Duration(seconds: 20));
        if (r.statusCode >= 400) throw 'Download failed (${r.statusCode})';
        final dir = await getTemporaryDirectory();
        final name = title?.isNotEmpty == true
            ? title!
            : 'document_${DateTime.now().millisecondsSinceEpoch}.pdf';
        final file = File('${dir.path}/$name');
        await file.writeAsBytes(r.bodyBytes);
        pathToOpen = file.path;
      }

      if (!await File(pathToOpen).exists()) {
        throw 'File not found';
      }

      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => Scaffold(
            appBar: AppBar(
                title: Text(title?.isNotEmpty == true ? title! : 'Document')),
            body: PDFView(
              filePath: pathToOpen,
              enableSwipe: true,
              swipeHorizontal: true,
              autoSpacing: true,
              pageFling: true,
            ),
          ),
        ),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('PDF open error: $e')));
      }
    }
  }

  // Try to open the real server file for a recent activity item. If file_url is missing
  // or points to a local/app-internal path, we attempt to build a proper HTTP URL from
  // the server root, department and file name. If the file looks like an image, show
  // an in-app preview; otherwise open externally.
  Future<void> _openActivityFileOrPreview({
    String? fileUrl,
    String? fileName,
    String? recipientDepartment,
  }) async {
    try {
      // Resolve extension; also handle content patterns like "Type • DocumentName"
      String nameGuess = (fileName ?? '').trim();
      if (nameGuess.contains('•')) {
        final parts = nameGuess.split('•');
        if (parts.length >= 2) {
          nameGuess = parts.last.trim();
        }
      }
      String urlGuess = (fileUrl ?? '').trim();
      String ext = '';
      String lower = nameGuess.toLowerCase();
      final dot = lower.lastIndexOf('.');
      if (dot != -1 && dot < lower.length - 1) ext = lower.substring(dot + 1);
      if (ext.isEmpty && urlGuess.isNotEmpty) {
        final lu = urlGuess.toLowerCase();
        final d2 = lu.lastIndexOf('.');
        if (d2 != -1 && d2 < lu.length - 1) ext = lu.substring(d2 + 1);
      }
      final isImage =
          ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'].contains(ext);
      final isPdf = ext == 'pdf';

      // Prefer provided file_url, but if it's an Android app-internal path, don't prefix server root.
      if (urlGuess.isNotEmpty) {
        final looksLocal = urlGuess.startsWith('file://') ||
            urlGuess.startsWith('/data/') ||
            urlGuess.contains('/app_flutter/');
        if (looksLocal) {
          // If file exists locally, preview/open directly; otherwise fall through to server candidates
          final localPath = urlGuess.startsWith('file://')
              ? Uri.parse(urlGuess).toFilePath()
              : urlGuess;
          final f = File(localPath);
          if (await f.exists()) {
            if (isImage) {
              await _previewImage(localPath);
            } else {
              if (isPdf) {
                await _openPdf(localPath,
                    title: nameGuess.isNotEmpty ? nameGuess : 'Document');
              } else {
                // For other local types, attempt external open (best-effort)
                final ok = await launchUrl(Uri.file(localPath),
                    mode: LaunchMode.externalApplication);
                if (!ok && mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                      content: Text('No app available to open this file')));
                }
              }
            }
            return;
          }
          // else: fall through to build server candidates using dept+filename
        } else {
          if (isImage) {
            await _previewImage(urlGuess,
                localFallbackName: nameGuess,
                recipientDepartment: recipientDepartment);
          } else {
            await _openUrl(urlGuess);
          }
          return;
        }
      }

      // Build from server root + candidate paths and probe which exists.
      // If no extension, try common ones.
      final root = await _getServerRoot();
      if (root == null) throw 'No server root';
      final dept = (recipientDepartment ?? '').trim();
      final name = nameGuess;
      if (dept.isEmpty || name.isEmpty) throw 'Missing file info';
      final namesToTry = <String>[];
      namesToTry.add(name);
      if (ext.isEmpty) {
        for (final e in ['jpg', 'jpeg', 'png', 'pdf']) {
          namesToTry.add('$name.$e');
        }
      }
      final candidates = <String>[];
      for (final n in namesToTry) {
        final encodedName = Uri.encodeComponent(n);
        candidates.addAll([
          '$root/Archive/$dept/$encodedName',
          '$root/lib/Archive/$dept/$encodedName',
          '$root/lib/OCR(UPDATED)/Archive/$dept/$encodedName',
          '$root/uploads/$dept/$encodedName',
          '$root/Uploads/$dept/$encodedName',
          '$root/flutter_application_7/Archive/$dept/$encodedName',
        ]);
      }

      String? working;
      for (final u in candidates) {
        try {
          final resp =
              await http.head(Uri.parse(u)).timeout(const Duration(seconds: 4));
          if (resp.statusCode == 200) {
            working = u;
            break;
          }
        } catch (_) {}
      }
      working ??= candidates.first; // fallback

      if (isImage) {
        await _previewImage(working,
            localFallbackName: name, recipientDepartment: recipientDepartment);
      } else {
        final ok = await launchUrl(Uri.parse(working),
            mode: LaunchMode.externalApplication);
        if (!ok && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Could not open link')));
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Invalid link: $e')));
      }
    }
  }

  Future<void> _previewImage(String urlOrPath,
      {String? localFallbackName, String? recipientDepartment}) async {
    try {
      // If local file exists, show it.
      final f = File(urlOrPath);
      if (await f.exists()) {
        await Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => Scaffold(
              appBar: AppBar(
                  title: Text(localFallbackName?.isNotEmpty == true
                      ? localFallbackName!
                      : 'Image')),
              body: Center(
                child: InteractiveViewer(
                  child: Image.file(f, fit: BoxFit.contain),
                ),
              ),
            ),
          ),
        );
        return;
      }

      // Fallback to network (full-screen)
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => Scaffold(
            appBar: AppBar(
                title: Text(localFallbackName?.isNotEmpty == true
                    ? localFallbackName!
                    : 'Image')),
            body: Center(
              child: InteractiveViewer(
                child: Image.network(
                  urlOrPath,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) {
                    return Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.broken_image, size: 48),
                          const SizedBox(height: 12),
                          const Text('Could not load image'),
                          const SizedBox(height: 12),
                          OutlinedButton(
                            onPressed: () {
                              _openUrl(urlOrPath);
                            },
                            child: const Text('Open externally'),
                          ),
                        ],
                      ),
                    );
                  },
                ),
              ),
            ),
          ),
        ),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Preview error: $e')));
      }
    }
  }

  Future<List<Map<String, dynamic>>> _fetchAllUsers() async {
    try {
      final root = await _getServerRoot();
      if (root == null) return [];
      final uri =
          Uri.parse('$root/lib/OCR(UPDATED)/api/list_control_entities.php');
      final r = await http.get(uri).timeout(const Duration(seconds: 10));
      if (r.statusCode >= 400 || r.body.isEmpty) return [];
      final data = json.decode(r.body) as Map<String, dynamic>;
      final List list = (data['users'] ?? []) as List;
      return list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
    } catch (_) {
      return [];
    }
  }

  String _deriveDocType(String text, String? fallbackType) {
    try {
      String t = text.trim();
      // Pattern: "Purchase Request • Carl" -> take left side
      if (t.contains('•')) {
        t = t.split('•').first.trim();
      }
      // Pattern: "New Document from Carl" -> take left side
      if (t.contains(' from ')) {
        t = t.split(' from ').first.trim();
      }
      if (t.isNotEmpty) {
        return t;
      }
      if (fallbackType != null && fallbackType.trim().isNotEmpty) {
        return fallbackType.trim();
      }
      return 'Document';
    } catch (_) {
      return fallbackType?.trim().isNotEmpty == true
          ? fallbackType!.trim()
          : 'Document';
    }
  }

  Future<void> _openRouteDialogWithDetails({
    required String initialReceiverDept,
    required String docType,
    required String fileName,
    required String filePath,
    String? mobileTimestamp,
    String? docHash,
    String? trackingId,
    int? activityId,
    String? endLocation,
    String? currentHolder,
  }) async {
    final deptCtrl = TextEditingController();
    final typeCtrl = TextEditingController(text: docType);
    String resolvedEnd = (endLocation ?? '').trim().isNotEmpty
        ? (endLocation ?? '').trim()
        : initialReceiverDept;
    String resolvedType = docType.trim();
    // Payroll fixed routing chain: HR → CBO → ACCOUNTING → CAO → CTO
    const payrollFixedRoute = ['HR', 'CBO', 'ACCOUNTING', 'CAO', 'CTO'];
    bool isPayrollLocked = false;
    String? payrollNextDept;
    String resolvedCurrentHolder = (currentHolder ?? '').trim().toUpperCase();
    int? resolvedRouteStep;
    List<String> resolvedRoutingQueue = List<String>.from(payrollFixedRoute);

    try {
      final root = await _getServerRoot();
      if (root != null) {
        final tid = (trackingId ?? '').trim();
        final int? id = tid.isNotEmpty ? int.tryParse(tid) : null;
        Uri uri;
        if (id != null && id > 0) {
          uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
              queryParameters: {'action': 'doc_detail', 'id': id.toString()});
        } else {
          final mt = (mobileTimestamp ?? '').trim();
          final dh = (docHash ?? '').trim();
          uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
            queryParameters: {
              'action': 'resolve_identity',
              if (mt.isNotEmpty) 'mobile_timestamp': mt,
              if (dh.isNotEmpty) 'doc_hash': dh,
            },
          );
        }
        final r = await http.get(uri).timeout(const Duration(seconds: 6));
        if (r.statusCode < 400 && r.body.isNotEmpty) {
          final decoded = jsonDecode(r.body);
          if (decoded is Map && decoded['success'] == true) {
            final Map doc =
                (decoded['doc'] is Map) ? (decoded['doc'] as Map) : decoded;
            final endFromDb = (doc['end_location'] ?? doc['endLocation'] ?? '')
                .toString()
                .trim();
            if (endFromDb.isNotEmpty) resolvedEnd = endFromDb;
            final typeFromDb = (doc['type'] ?? '').toString().trim();
            if (typeFromDb.isNotEmpty) resolvedType = typeFromDb;
            final holderFromDb =
                (doc['current_holder'] ?? doc['currentHolder'] ?? '')
                    .toString()
                    .trim()
                    .toUpperCase();
            if (holderFromDb.isNotEmpty) {
              resolvedCurrentHolder = holderFromDb;
            }
            final routeStepRaw = (doc['route_step'] ?? '').toString().trim();
            final parsedStep = int.tryParse(routeStepRaw);
            if (parsedStep != null && parsedStep >= 0) {
              resolvedRouteStep = parsedStep;
            }
            final rqRaw = (doc['routing_queue'] ?? '').toString().trim();
            if (rqRaw.isNotEmpty) {
              final parts = rqRaw
                  .split(',')
                  .map((e) => e.trim().toUpperCase())
                  .where((e) => e.isNotEmpty)
                  .toList();
              if (parts.isNotEmpty) {
                resolvedRoutingQueue = parts;
              }
            }
          }
        }
      }
    } catch (_) {}

    if (resolvedType.isNotEmpty) typeCtrl.text = resolvedType;
    final lockedEndLocation = resolvedEnd.trim().isNotEmpty
        ? resolvedEnd.trim()
        : initialReceiverDept;

    // Fixed routing: determine the locked next department.
    // Applies ONLY to payroll document types.
    {
      int idxFromHint(String hint, List<String> route) {
        final up = hint.toUpperCase();
        for (int i = 0; i < route.length; i++) {
          if (up == route[i] || up.contains(route[i])) return i;
        }
        return -1;
      }

      final activeRoute = resolvedRoutingQueue.isNotEmpty
          ? resolvedRoutingQueue
          : payrollFixedRoute;

      int idx = -1;
      if (resolvedRouteStep != null && resolvedRouteStep >= 0) {
        idx = resolvedRouteStep;
      }
      final holderIdx = resolvedCurrentHolder.isNotEmpty
          ? idxFromHint(resolvedCurrentHolder, activeRoute)
          : -1;
      if (holderIdx >= 0 && idx >= 0 && holderIdx != idx) {
        // Use live holder when step hint is stale to avoid skipping departments.
        idx = holderIdx;
      }
      if (idx < 0) {
        final holderSeed = resolvedCurrentHolder.isNotEmpty
            ? resolvedCurrentHolder
            : initialReceiverDept.trim().toUpperCase();
        idx = idxFromHint(holderSeed, activeRoute);
      }

      final isPayrollType = resolvedType.toLowerCase().contains('payroll');

      // Lock routing only for payroll documents when current holder is in chain.
      if (isPayrollType && idx >= 0) {
        isPayrollLocked = true;
        if (idx + 1 < activeRoute.length) {
          payrollNextDept = activeRoute[idx + 1];
        } else {
          // Already at the final department.
          payrollNextDept = activeRoute.last;
        }
        deptCtrl.text = payrollNextDept;
      }
    }

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (ctx) {
        final mq = MediaQuery.of(ctx);
        return Padding(
          padding: EdgeInsets.only(
              left: 16, right: 16, top: 16, bottom: mq.viewInsets.bottom + 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text('Route Document',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
              const SizedBox(height: 10),
              FutureBuilder<List<Map<String, dynamic>>>(
                future: _fetchAllUsers(),
                builder: (c, snap) {
                  final users = snap.data ?? [];
                  if (snap.connectionState == ConnectionState.waiting) {
                    return const LinearProgressIndicator(minHeight: 2);
                  }

                  // Build distinct list of departments from users
                  final Set<String> depts = {
                    'CPDO',
                    'GSO',
                    'CBO',
                    'CTO',
                    'CACCO',
                    'CADO',
                    'CMO',
                    for (final u in users)
                      (u['department'] ?? '').toString().trim().toUpperCase(),
                  }..removeWhere((e) => e.trim().isEmpty);

                  // Helper: when a department is chosen, auto-pick a default user
                  void selectDept(String deptName) {
                    deptCtrl.text = deptName;
                    setState(() {});
                  }

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      if (isPayrollLocked && payrollNextDept != null) ...[
                        // Payroll: show locked fixed route indicator
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 12, vertical: 14),
                          decoration: BoxDecoration(
                            color: const Color(0xFFF0F0FF),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(
                                color: const Color(0xFF6868AC), width: 1),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.lock_outline,
                                  size: 18, color: Color(0xFF6868AC)),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      'Next: $payrollNextDept',
                                      style: const TextStyle(
                                          fontSize: 15,
                                          fontWeight: FontWeight.w700,
                                          color: Color(0xFF6868AC)),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      'Payroll fixed route: ${(resolvedRoutingQueue.isNotEmpty ? resolvedRoutingQueue : payrollFixedRoute).join(' → ')}',
                                      style: TextStyle(
                                          fontSize: 11,
                                          color: Colors.grey.shade600),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ] else ...[
                        DropdownButtonFormField<String>(
                          initialValue: depts.contains(deptCtrl.text)
                              ? deptCtrl.text
                              : null,
                          items: depts
                              .map((d) => DropdownMenuItem<String>(
                                    value: d,
                                    child: Text(d.isNotEmpty ? d : 'Unknown'),
                                  ))
                              .toList(),
                          onChanged: (v) {
                            if (v == null) return;
                            selectDept(v);
                          },
                          decoration: const InputDecoration(
                            labelText: 'Next Department',
                          ),
                        ),
                      ],
                    ],
                  );
                },
              ),
              const SizedBox(height: 8),
              TextField(
                controller: typeCtrl,
                readOnly: true,
                decoration: const InputDecoration(labelText: 'Document Type'),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () {
                        Navigator.pop(ctx);
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('Routing cancelled')),
                          );
                        }
                      },
                      child: const Text('Cancel'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: ElevatedButton(
                      onPressed: () async {
                        final nextDept = deptCtrl.text.trim();
                        final endLocation = lockedEndLocation;
                        final dtype = typeCtrl.text.trim().isNotEmpty
                            ? typeCtrl.text.trim()
                            : 'Document';
                        final fname = fileName;
                        Navigator.pop(ctx);
                        await _routeDocument(
                          nextDepartment: nextDept,
                          endLocation: endLocation,
                          type: dtype,
                          fileName: fname,
                          filePath: filePath,
                          mobileTimestamp: mobileTimestamp,
                          docHash: docHash,
                          trackingId: trackingId,
                          activityId: activityId,
                        );
                      },
                      child: const Text('Route'),
                    ),
                  )
                ],
              )
            ],
          ),
        );
      },
    );
  }

  Future<void> _routeDocument({
    required String nextDepartment,
    required String endLocation,
    required String type,
    required String fileName,
    required String filePath,
    String? mobileTimestamp,
    String? docHash,
    String? trackingId,
    int? activityId,
  }) async {
    var stableTs = mobileTimestamp?.trim() ?? '';
    var tid = trackingId?.trim() ?? '';
    var resolvedDocHash = docHash?.trim() ?? '';
    var resolvedFilePath = filePath.trim();
    var effectiveType = type.trim();
    var effectiveEndLocation = endLocation.trim();
    final nextDeptUpper = nextDepartment.trim().toUpperCase();
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final prefs = await SharedPreferences.getInstance();
      final sender = prefs.getString('user_name') ?? '';
      final senderDept = prefs.getString('user_department') ?? '';
      if (sender.isEmpty || senderDept.isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Missing sender info. Re-login.')));
        }
        return;
      }
      if (nextDepartment.isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Provide next department')));
        }
        return;
      }

      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/route_document.php');
      final int notifId = activityId ?? 0;
      final bool hasNotifId = notifId > 0;

      // If identity fields are missing on this card, try recovering them from
      // notification id before enforcing strict route preflight.
      if (tid.isEmpty &&
          (stableTs.isEmpty || resolvedDocHash.isEmpty) &&
          hasNotifId) {
        final recovered = await _resolveIdentityFromNotificationId(
          notificationId: notifId,
          fallbackFilePath: resolvedFilePath,
          fallbackType: effectiveType,
          fallbackEndLocation: effectiveEndLocation,
        );
        final recTid = (recovered['trackingId'] ?? '').trim();
        final recTs = (recovered['mobileTimestamp'] ?? '').trim();
        final recHash = (recovered['docHash'] ?? '').trim();
        final recPath = (recovered['filePath'] ?? '').trim();
        final recType = (recovered['type'] ?? '').trim();
        final recEnd = (recovered['endLocation'] ?? '').trim();

        if (recTid.isNotEmpty) tid = recTid;
        if (recTs.isNotEmpty) stableTs = recTs;
        if (recHash.isNotEmpty) resolvedDocHash = recHash;
        if (recPath.isNotEmpty) resolvedFilePath = recPath;
        if (recType.isNotEmpty) effectiveType = recType;
        if (recEnd.isNotEmpty) effectiveEndLocation = recEnd;
      }

      // CRITICAL: For routing to work (updating existing row), we need either
      // a valid tracking_id or a stable mobile_timestamp from the original document.
      // Do NOT generate a new timestamp - that will create a new row!
      final hasTrackingId = tid.isNotEmpty;
      final hasStrongIdentity =
          hasTrackingId || (stableTs.isNotEmpty && resolvedDocHash.isNotEmpty);
      if (!hasStrongIdentity && !hasNotifId) {
        final missing = <String>[];
        if (!hasTrackingId) {
          if (stableTs.isEmpty) missing.add('mobile_timestamp');
          if (resolvedDocHash.isEmpty) missing.add('doc_hash');
        }
        final dbg = {
          'error': 'identity_missing',
          'requires': 'tracking_id OR (mobile_timestamp + doc_hash)',
          'missing': missing,
          'trackingId': tid,
          'mobileTimestamp': stableTs,
          'docHash': resolvedDocHash,
          'filePath': resolvedFilePath,
          'fileName': fileName,
          'type': effectiveType,
          'endLocation': effectiveEndLocation,
          'nextDepartment': nextDepartment,
          'activityId': activityId?.toString() ?? '',
        };
        if (mounted) {
          final short = missing.isNotEmpty
              ? 'missing=${missing.join('+')}'
              : 'tid=$tid ts=$stableTs hash=$resolvedDocHash';
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(
                'Cannot route: requires tracking_id or mobile_timestamp+doc_hash. $short'),
            action: SnackBarAction(
              label: 'COPY',
              onPressed: () {
                Clipboard.setData(ClipboardData(text: dbg.toString()));
              },
            ),
          ));
        }
        return;
      }

      // When identity exists, prefer canonical server values to avoid
      // accidentally changing document type/end location during re-routing.
      if (tid.isNotEmpty || stableTs.isNotEmpty || resolvedDocHash.isNotEmpty) {
        try {
          final meta = await _fetchRoutingMeta(
            trackingId: tid.isNotEmpty ? tid : null,
            mobileTimestamp: stableTs.isNotEmpty ? stableTs : null,
            docHash: resolvedDocHash.isNotEmpty ? resolvedDocHash : null,
          );
          if (meta != null) {
            final serverType = (meta['type'] ?? '').toString().trim();
            if (serverType.isNotEmpty) {
              effectiveType = serverType;
            }
            final serverEnd =
                (meta['end_location'] ?? meta['endLocation'] ?? '')
                    .toString()
                    .trim();
            if (serverEnd.isNotEmpty) {
              effectiveEndLocation = serverEnd;
            }
          }
        } catch (_) {}
      }

      final payload = {
        'sender_name': sender,
        'sender_department': senderDept,
        'receiver_username': '',
        'receiver_department': nextDepartment,
        'type': effectiveType,
        'file_name': fileName,
        'file_path': resolvedFilePath,
        'mobile_timestamp': stableTs,
        'base': root,
        'next_department': nextDepartment,
        'end_location': effectiveEndLocation,
        'debug': '1',
      };
      // For payroll documents, pass the fixed routing queue
      if (effectiveType.toLowerCase().contains('payroll')) {
        payload['routing_queue'] = 'HR,CBO,ACCOUNTING,CAO,CTO';
      }
      if (notifId > 0) {
        // Fallback: if the activity came from notifications and is missing tracking_id/mobile_timestamp,
        // allow server to resolve identifiers from notifications.id
        payload['notification_id'] = notifId.toString();
      }
      if (resolvedDocHash.isNotEmpty) {
        payload['doc_hash'] = resolvedDocHash;
      }
      if (tid.isNotEmpty) {
        payload['tracking_id'] = tid;
      }
      final r = await http
          .post(uri, body: payload)
          .timeout(const Duration(seconds: 12));
      if (mounted) {
        dynamic decodedBody;
        try {
          decodedBody = jsonDecode(r.body);
        } catch (_) {
          decodedBody = {'raw': r.body};
        }

        Map<String, dynamic>? postState;
        try {
          postState = await _fetchRoutingMeta(
            trackingId: tid.isNotEmpty ? tid : null,
            mobileTimestamp: stableTs.isNotEmpty ? stableTs : null,
            docHash: resolvedDocHash.isNotEmpty ? resolvedDocHash : null,
            filePath: resolvedFilePath,
          );
        } catch (_) {
          postState = null;
        }

        final routeDebug = <String, dynamic>{
          'action': 'route',
          'request': payload,
          'response': decodedBody,
          'http_status': r.statusCode,
          'post_state': postState,
          'timestamp': DateTime.now().toIso8601String(),
        };
        _lastRouteDebug = routeDebug;
        final routeDebugText = _buildDebugSummaryAndJson(
          action: 'route',
          payload: routeDebug,
        );

        // Check if server returned PHP source code (indicates server misconfiguration)
        if (r.body.contains('<?php') ||
            (r.body.contains('function ') && r.body.contains('\$'))) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text('Server error: PHP not executing. URL: $uri'),
              action: _debugToolsEnabled
                  ? SnackBarAction(
                      label: 'DEBUG',
                      onPressed: () {
                        _showCopyableDebugDialog(
                          title: 'Route Debug',
                          message: routeDebugText,
                          copyText: routeDebugText,
                        );
                      },
                    )
                  : null));
          return;
        }
        if (r.statusCode < 400) {
          bool ok = true;
          try {
            final data = jsonDecode(r.body);
            if (data is Map && data.containsKey('success')) {
              ok = data['success'] == true;
            }
          } catch (_) {}
          if (!ok) {
            String errorMsg = r.body;
            try {
              final data = jsonDecode(r.body);
              if (data is Map && data['message'] != null) {
                errorMsg = data['message'].toString();
              }
            } catch (_) {}
            ScaffoldMessenger.of(context).showSnackBar(SnackBar(
                content: Text('Route failed: $errorMsg'),
                action: _debugToolsEnabled
                    ? SnackBarAction(
                        label: 'DEBUG',
                        onPressed: () {
                          _showCopyableDebugDialog(
                            title: 'Route Debug',
                            message: routeDebugText,
                            copyText: routeDebugText,
                          );
                        },
                      )
                    : null));
            return;
          }

          // After successful routing, remove this item from the dashboard feed
          // so it doesn't stay in Recent Documents.
          // IMPORTANT: do not delete tracking rows here (routing should not erase history).
          final int? notifId = activityId;
          if (notifId != null && notifId > 0) {
            // Best-effort: mark as routed then delete the notification so it no longer shows.
            try {
              await _updateNotificationStatus(notifId, 'routed');
            } catch (_) {}
            try {
              await _deleteNotificationById(notifId);
            } catch (_) {}
          }

          // Also remove locally immediately (in case backend still returns it until next refresh)
          if (mounted && activityId != null) {
            setState(() {
              _recentActivity.removeWhere((m) {
                final mid = m['id'];
                if (mid == null) return false;
                return mid.toString() == activityId.toString();
              });
            });
          }

          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: const Text('Routed'),
            action: _debugToolsEnabled
                ? SnackBarAction(
                    label: 'DEBUG',
                    onPressed: () {
                      _showCopyableDebugDialog(
                        title: 'Route Debug',
                        message: routeDebugText,
                        copyText: routeDebugText,
                      );
                    },
                  )
                : null,
          ));
          try {
            await _fetchRecentActivity();
          } catch (_) {}
        } else {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text('Route failed (${r.statusCode}): ${r.body}'),
              action: _debugToolsEnabled
                  ? SnackBarAction(
                      label: 'DEBUG',
                      onPressed: () {
                        _showCopyableDebugDialog(
                          title: 'Route Debug',
                          message: routeDebugText,
                          copyText: routeDebugText,
                        );
                      },
                    )
                  : null));
        }
      }
    } catch (e) {
      bool recoveredAsSuccess = false;
      try {
        if (e is TimeoutException || e is SocketException) {
          final meta = await _fetchRoutingMeta(
            trackingId: tid.isNotEmpty ? tid : null,
            mobileTimestamp: stableTs.isNotEmpty ? stableTs : null,
            docHash:
                (docHash?.trim().isNotEmpty ?? false) ? docHash!.trim() : null,
            filePath: filePath,
          );
          final holder =
              (meta?['current_holder'] ?? meta?['currentHolder'] ?? '')
                  .toString()
                  .trim()
                  .toUpperCase();
          final status =
              (meta?['status'] ?? '').toString().trim().toUpperCase();
          recoveredAsSuccess =
              (nextDeptUpper.isNotEmpty && holder == nextDeptUpper) ||
                  status == 'COMPLETED' ||
                  status == 'APPROVED' ||
                  status == 'ARCHIVED';
        }
      } catch (_) {
        recoveredAsSuccess = false;
      }

      if (recoveredAsSuccess) {
        final int? notifId = activityId;
        if (notifId != null && notifId > 0) {
          try {
            await _updateNotificationStatus(notifId, 'routed');
          } catch (_) {}
          try {
            await _deleteNotificationById(notifId);
          } catch (_) {}
        }

        if (mounted && activityId != null) {
          setState(() {
            _recentActivity.removeWhere((m) {
              final mid = m['id'];
              if (mid == null) return false;
              return mid.toString() == activityId.toString();
            });
          });
        }

        if (mounted) {
          ScaffoldMessenger.of(context)
              .showSnackBar(const SnackBar(content: Text('Routed')));
        }
        try {
          await _fetchRecentActivity();
        } catch (_) {}
        return;
      }

      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Network error: $e')));
      }
    }
  }

  // Local helper to delete a notification by id for Confirm actions
  Future<bool> _deleteNotificationById(int id) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return false;

      final notifBase = '$root/lib/OCR(UPDATED)/api/notifications.php';

      // 1) Try DELETE
      try {
        final resp = await http
            .delete(Uri.parse('$notifBase?id=$id'))
            .timeout(const Duration(seconds: 8));
        if (resp.statusCode >= 200 && resp.statusCode < 300) return true;
        try {
          final data = jsonDecode(resp.body);
          if (data is Map && (data['success'] == true)) return true;
        } catch (_) {}
      } catch (_) {}

      // 2) Try POST action=delete
      try {
        final resp = await http.post(Uri.parse(notifBase), body: {
          'action': 'delete',
          'id': id.toString(),
        }).timeout(const Duration(seconds: 8));
        if (resp.statusCode >= 200 && resp.statusCode < 300) return true;
        try {
          final data = jsonDecode(resp.body);
          if (data is Map && (data['success'] == true)) return true;
        } catch (_) {}
      } catch (_) {}

      // 3) Fallback GET action=delete
      try {
        final resp = await http
            .get(Uri.parse('$notifBase?action=delete&id=$id'))
            .timeout(const Duration(seconds: 8));
        if (resp.statusCode >= 200 && resp.statusCode < 300) return true;
        try {
          final data = jsonDecode(resp.body);
          if (data is Map && (data['success'] == true)) return true;
        } catch (_) {}
      } catch (_) {}

      return false;
    } catch (_) {
      return false;
    }
  }

  Future<void> _confirmLogout() async {
    final confirmed = await showDialog<bool>(
      context: context,
      barrierDismissible: true,
      builder: (ctx) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Text(
            'Do you want to logout?',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
          ),
          content: const Text(
            'You can log back in anytime. This will close your current session.',
            style: TextStyle(fontSize: 14),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(false),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: () => Navigator.of(ctx).pop(true),
              child: const Text('Logout'),
            ),
          ],
        );
      },
    );
    if (confirmed == true && mounted) {
      // Show brief goodbye modal
      showDialog(
        context: context,
        barrierDismissible: false,
        barrierColor: Colors.black38,
        builder: (ctx) {
          return Center(
            child: TweenAnimationBuilder<double>(
              duration: const Duration(milliseconds: 300),
              tween: Tween(begin: 0.8, end: 1.0),
              curve: Curves.easeOutBack,
              builder: (_, scale, child) =>
                  Transform.scale(scale: scale, child: child),
              child: Material(
                color: Colors.transparent,
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 32, vertical: 28),
                  decoration: BoxDecoration(
                    color: Theme.of(context).colorScheme.surface,
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: const [
                      BoxShadow(
                          color: Colors.black12,
                          blurRadius: 24,
                          offset: Offset(0, 8)),
                    ],
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 52,
                        height: 52,
                        decoration: BoxDecoration(
                          color: const Color(0xFF16A34A).withOpacity(0.12),
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.check_circle_rounded,
                            color: Color(0xFF16A34A), size: 32),
                      ),
                      const SizedBox(height: 14),
                      const Text(
                        'Logged out successfully',
                        style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.w600),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'See you next time!',
                        style: TextStyle(
                            fontSize: 13,
                            color: Theme.of(context)
                                .colorScheme
                                .onSurface
                                .withOpacity(0.5)),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      );

      // Hold for a moment then navigate
      await Future.delayed(const Duration(milliseconds: 1200));
      if (!mounted) return;
      Navigator.of(context).pushAndRemoveUntil(
        PageRouteBuilder(
          pageBuilder: (_, __, ___) => const LoginPage(),
          transitionsBuilder: (_, anim, __, child) {
            final fade =
                CurvedAnimation(parent: anim, curve: Curves.easeOutCubic);
            return FadeTransition(opacity: fade, child: child);
          },
          transitionDuration: const Duration(milliseconds: 400),
        ),
        (route) => false,
      );
    }
  }

  Future<void> _openSettingsDialog() async {
    final app = app_main.MyApp.of(context);
    ThemeMode current = Theme.of(context).brightness == Brightness.dark
        ? ThemeMode.dark
        : ThemeMode.light;
    ThemeMode selectedMode = current;
    Color selectedSeed = Theme.of(context).colorScheme.primary;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Theme.of(context).colorScheme.surface,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (sheetCtx) {
        final mq = MediaQuery.of(sheetCtx);
        return Padding(
          padding: EdgeInsets.only(bottom: mq.viewInsets.bottom + 16),
          child: StatefulBuilder(
            builder: (context, setStateDialog) {
              return SafeArea(
                top: false,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SizedBox(height: 12),
                    Center(
                      child: Container(
                        width: 40,
                        height: 5,
                        decoration: BoxDecoration(
                          color:
                              Theme.of(context).dividerColor.withOpacity(0.6),
                          borderRadius: BorderRadius.circular(3),
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
                      child: Text(
                        'Dark mode',
                        style: TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w800,
                            color: Theme.of(context).colorScheme.onSurface),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Theme',
                              style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  color: Theme.of(context)
                                      .colorScheme
                                      .onSurface
                                      .withOpacity(0.9))),
                          const SizedBox(height: 10),
                          Container(
                            decoration: BoxDecoration(
                              color: Theme.of(context)
                                  .colorScheme
                                  .surfaceContainerHighest
                                  .withOpacity(0.4),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Row(
                              children: [
                                Expanded(
                                  child: InkWell(
                                    borderRadius: BorderRadius.circular(12),
                                    onTap: () => setStateDialog(
                                        () => selectedMode = ThemeMode.light),
                                    child: AnimatedContainer(
                                      duration:
                                          const Duration(milliseconds: 180),
                                      padding: const EdgeInsets.symmetric(
                                          vertical: 12),
                                      alignment: Alignment.center,
                                      decoration: BoxDecoration(
                                        color: selectedMode == ThemeMode.light
                                            ? Theme.of(context)
                                                .colorScheme
                                                .primary
                                                .withOpacity(0.12)
                                            : Colors.transparent,
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                      child: Text('Light',
                                          style: TextStyle(
                                              fontWeight: FontWeight.w600,
                                              color: selectedMode ==
                                                      ThemeMode.light
                                                  ? Theme.of(context)
                                                      .colorScheme
                                                      .primary
                                                  : Theme.of(context)
                                                      .colorScheme
                                                      .onSurface)),
                                    ),
                                  ),
                                ),
                                Expanded(
                                  child: InkWell(
                                    borderRadius: BorderRadius.circular(12),
                                    onTap: () => setStateDialog(
                                        () => selectedMode = ThemeMode.dark),
                                    child: AnimatedContainer(
                                      duration:
                                          const Duration(milliseconds: 180),
                                      padding: const EdgeInsets.symmetric(
                                          vertical: 12),
                                      alignment: Alignment.center,
                                      decoration: BoxDecoration(
                                        color: selectedMode == ThemeMode.dark
                                            ? Theme.of(context)
                                                .colorScheme
                                                .primary
                                                .withOpacity(0.12)
                                            : Colors.transparent,
                                        borderRadius: BorderRadius.circular(12),
                                      ),
                                      child: Text('Dark',
                                          style: TextStyle(
                                              fontWeight: FontWeight.w600,
                                              color:
                                                  selectedMode == ThemeMode.dark
                                                      ? Theme.of(context)
                                                          .colorScheme
                                                          .primary
                                                      : Theme.of(context)
                                                          .colorScheme
                                                          .onSurface)),
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 16),
                          Text('Accent color',
                              style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  color: Theme.of(context)
                                      .colorScheme
                                      .onSurface
                                      .withOpacity(0.9))),
                          const SizedBox(height: 10),
                          Wrap(
                            spacing: 12,
                            runSpacing: 12,
                            children: [
                              Colors.teal,
                              const Color(0xFF6868AC),
                              Colors.indigo,
                              Colors.purple,
                              Colors.green,
                              Colors.orange,
                              Colors.red,
                              Colors.pink,
                            ].map((c) {
                              final isSel = selectedSeed.value == c.value;
                              return InkWell(
                                customBorder: const CircleBorder(),
                                onTap: () =>
                                    setStateDialog(() => selectedSeed = c),
                                child: AnimatedContainer(
                                  duration: const Duration(milliseconds: 160),
                                  width: 36,
                                  height: 36,
                                  decoration: BoxDecoration(
                                    color: c,
                                    shape: BoxShape.circle,
                                    boxShadow: isSel
                                        ? [
                                            BoxShadow(
                                                color: c.withOpacity(0.45),
                                                blurRadius: 10,
                                                spreadRadius: 1)
                                          ]
                                        : null,
                                    border: Border.all(
                                        color: isSel
                                            ? Colors.white
                                            : Colors.transparent,
                                        width: 2),
                                  ),
                                ),
                              );
                            }).toList(),
                          ),
                          const SizedBox(height: 20),
                          Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: Theme.of(context).cardColor,
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(
                                  color: Theme.of(context)
                                      .dividerColor
                                      .withOpacity(0.3)),
                            ),
                            child: Row(
                              children: [
                                Icon(Icons.palette_outlined,
                                    color: selectedSeed),
                                const SizedBox(width: 12),
                                const Expanded(
                                    child: Text('Preview',
                                        style: TextStyle(
                                            fontWeight: FontWeight.w600))),
                                Container(
                                    width: 18,
                                    height: 18,
                                    decoration: BoxDecoration(
                                        color: selectedSeed,
                                        shape: BoxShape.circle)),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
                      child: Row(
                        children: [
                          Expanded(
                            child: OutlinedButton(
                              onPressed: () => Navigator.pop(context),
                              style: OutlinedButton.styleFrom(
                                  padding:
                                      const EdgeInsets.symmetric(vertical: 14),
                                  shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(14))),
                              child: const Text('Close'),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: ElevatedButton(
                              onPressed: () async {
                                if (app != null) {
                                  await app.setThemeMode(selectedMode);
                                  await app.setSeedColor(selectedSeed);
                                }
                                if (mounted) Navigator.pop(context);
                              },
                              style: ElevatedButton.styleFrom(
                                  padding:
                                      const EdgeInsets.symmetric(vertical: 14),
                                  shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(14))),
                              child: const Text('Apply'),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
        );
      },
    );
  }

  Future<void> _openViewProfileDialog() async {
    String dept = 'Unknown';
    String uname = username;
    String uemail = email;
    try {
      final prefs = await SharedPreferences.getInstance();
      dept = prefs.getString('user_department') ?? dept;
      uname = prefs.getString('user_name') ?? uname;
      uemail = prefs.getString('user_email') ?? uemail;
    } catch (_) {}
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) {
        return SafeArea(
          top: false,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: double.infinity,
                padding: const EdgeInsets.fromLTRB(24, 24, 24, 20),
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                    colors: [Color(0xFF6868AC), Color(0xFF52528A)],
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                  ),
                  borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
                ),
                child: Column(
                  children: [
                    const CircleAvatar(
                      radius: 42,
                      backgroundColor: Colors.white,
                      child: Icon(Icons.person,
                          size: 48, color: Color(0xFF6868AC)),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      uname,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.w700),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.15),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.apartment,
                              color: Colors.white, size: 16),
                          const SizedBox(width: 6),
                          Text(dept,
                              style: const TextStyle(
                                  color: Colors.white, fontSize: 12)),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _profileTile(
                        icon: Icons.badge_outlined,
                        label: 'Username',
                        value: uname),
                    const SizedBox(height: 10),
                    _profileTile(
                        icon: Icons.apartment_outlined,
                        label: 'Department',
                        value: dept),
                    const SizedBox(height: 16),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF6868AC),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 14),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(14)),
                        ),
                        onPressed: () => Navigator.pop(ctx),
                        child: const Text('Close'),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _profileTile(
      {required IconData icon, required String label, required String value}) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.05),
            blurRadius: 8,
            offset: const Offset(0, 4),
          ),
        ],
        border:
            Border.all(color: Theme.of(context).dividerColor.withOpacity(0.3)),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: const Color(0xFF6868AC).withOpacity(0.1),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Icon(icon, color: const Color(0xFF6868AC)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label,
                    style: TextStyle(
                        color: Theme.of(context).hintColor, fontSize: 12)),
                const SizedBox(height: 4),
                Text(value,
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.w600)),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _updateUserName(String newName) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final prefs = await SharedPreferences.getInstance();
      // Read as int first to avoid type cast error when the key was stored as int
      String? userId = prefs.getInt('user_id')?.toString();
      userId ??= prefs.getString('user_id');
      final userEmail = prefs.getString('user_email') ?? '';
      if (userId == null || userId.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('User ID not found. Please re-login.')),
        );
        return;
      }
      final rootUri = Uri.parse(root);
      final uri = rootUri.replace(
        pathSegments: [
          ...rootUri.pathSegments,
          'lib',
          'OCR(UPDATED)',
          'api',
          'update_profile.php',
        ],
      );

      // Try JSON first
      http.Response response;
      try {
        response = await http
            .post(
              uri,
              headers: {'Content-Type': 'application/json'},
              body: jsonEncode(
                  {'user_id': userId, 'email': userEmail, 'name': newName}),
            )
            .timeout(const Duration(seconds: 10));
      } catch (e) {
        // Fallback to form-urlencoded if JSON fails at network level
        response = await http.post(uri, body: {
          'user_id': userId,
          'email': userEmail,
          'name': newName
        }).timeout(const Duration(seconds: 10));
      }

      if (response.statusCode >= 200 && response.statusCode < 400) {
        // optimistic update + persist
        await prefs.setString('user_name', newName);
        if (mounted) {
          setState(() {
            username = newName;
          });
        }
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Profile updated')),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(
                  'Update failed (${response.statusCode}): ${response.body.isNotEmpty ? response.body : 'Server error'}')),
        );
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Network error while updating profile: $e')),
      );
    }
  }

  // ============ ATTACHMENT METHODS ============

  /// Fetch attachments for a tracking document
  Future<List<Map<String, dynamic>>> _fetchAttachments(int trackingId) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return [];

      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php')
          .replace(queryParameters: {
        'action': 'get_attachments',
        'tracking_id': trackingId.toString(),
      });

      final response = await http.get(uri).timeout(const Duration(seconds: 10));
      if (response.statusCode == 200 && response.body.isNotEmpty) {
        final data = jsonDecode(response.body);
        if (data['success'] == true && data['attachments'] != null) {
          return List<Map<String, dynamic>>.from(data['attachments']);
        }
      }
    } catch (e) {
      // error silenced
    }
    return [];
  }

  /// Show attachment picker and upload
  Future<void> _addAttachment(int trackingId) async {
    final picker = ImagePicker();

    // Show options dialog
    final choice = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Add Attachment'),
        content: const Text('Choose how to add an attachment:'),
        actions: [
          TextButton.icon(
            onPressed: () => Navigator.pop(ctx, 'camera'),
            icon: const Icon(Icons.camera_alt),
            label: const Text('Camera'),
          ),
          TextButton.icon(
            onPressed: () => Navigator.pop(ctx, 'gallery'),
            icon: const Icon(Icons.photo_library),
            label: const Text('Gallery'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
        ],
      ),
    );

    if (choice == null || !mounted) return;

    XFile? pickedFile;
    if (choice == 'camera') {
      pickedFile =
          await picker.pickImage(source: ImageSource.camera, imageQuality: 85);
    } else if (choice == 'gallery') {
      pickedFile =
          await picker.pickImage(source: ImageSource.gallery, imageQuality: 85);
    }

    if (pickedFile == null || !mounted) return;

    // Optional remarks
    final remarksController = TextEditingController();
    final remarks = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Add Remarks (Optional)'),
        content: TextField(
          controller: remarksController,
          decoration: const InputDecoration(
            hintText: 'Enter remarks about this attachment...',
            border: OutlineInputBorder(),
          ),
          maxLines: 3,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, ''),
            child: const Text('Skip'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, remarksController.text),
            child: const Text('Continue'),
          ),
        ],
      ),
    );

    if (!mounted) return;

    // Show uploading indicator
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Row(
          children: [
            SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2)),
            SizedBox(width: 12),
            Text('Uploading attachment...'),
          ],
        ),
        duration: Duration(seconds: 30),
      ),
    );

    try {
      final root = await _getServerRoot();
      if (root == null) throw Exception('Server not configured');

      final prefs = await SharedPreferences.getInstance();
      final currentUser = prefs.getString('user_name') ?? username;
      final currentDept = prefs.getString('user_department') ?? '';

      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php');
      final request = http.MultipartRequest('POST', uri);

      request.fields['action'] = 'add_attachment';
      request.fields['tracking_id'] = trackingId.toString();
      request.fields['uploaded_by'] = currentUser;
      request.fields['department'] = currentDept;
      request.fields['remarks'] = remarks ?? '';

      request.files
          .add(await http.MultipartFile.fromPath('file', pickedFile.path));

      final streamedResponse =
          await request.send().timeout(const Duration(seconds: 60));
      final response = await http.Response.fromStream(streamedResponse);

      if (!mounted) return;
      ScaffoldMessenger.of(context).hideCurrentSnackBar();

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Attachment uploaded successfully!'),
              backgroundColor: Colors.green,
            ),
          );
          // Refresh the activity feed
          _fetchRecentActivity();
        } else {
          throw Exception(data['error'] ?? 'Upload failed');
        }
      } else {
        throw Exception('Server error: ${response.statusCode}');
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).hideCurrentSnackBar();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Failed to upload: $e'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  /// Show full activity details
  void _showActivityDetails(Map<String, dynamic> activity) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      isScrollControlled: true,
      builder: (ctx) {
        String v(String key) => (activity[key]?.toString() ?? '').trim();
        String vAny(List<String> keys) {
          for (final k in keys) {
            final vv = v(k);
            if (vv.isNotEmpty) return vv;
          }
          return '';
        }

        final trackingId = v('trackingId');
        final mobileTs = v('mobileTimestamp');
        final docHash = v('docHash');
        final fileUrl = v('fileUrl');
        final filePath = v('filePath');
        final endLocation = vAny(['endLocation', 'end_location']);
        final currentHolder = vAny(['currentHolder', 'current_holder']);

        Future<Map<String, String>> loadDetails() async {
          String resolvedTrackingId = trackingId;
          String resolvedType = (activity['type']?.toString() ?? '').trim();
          String resolvedEndLocation = endLocation;
          String resolvedCurrentHolder = currentHolder;
          String resolvedStatus = (activity['status']?.toString() ?? '').trim();
          final identityFilePath = filePath.isNotEmpty
              ? filePath
              : (fileUrl.startsWith('/') ? fileUrl : '');

          if (resolvedTrackingId.isEmpty) {
            final tid = await _resolveTrackingIdForAction(
              actionLabel: 'Details',
              trackingId: null,
              mobileTimestamp: mobileTs.isNotEmpty ? mobileTs : null,
              docHash: docHash.isNotEmpty ? docHash : null,
              filePath: identityFilePath.isNotEmpty ? identityFilePath : null,
            );
            if (tid != null && tid.trim().isNotEmpty) {
              resolvedTrackingId = tid.trim();
            }
          }

          final meta = await _fetchRoutingMeta(
            trackingId:
                resolvedTrackingId.isNotEmpty ? resolvedTrackingId : null,
            mobileTimestamp: mobileTs.isNotEmpty ? mobileTs : null,
            docHash: docHash.isNotEmpty ? docHash : null,
            filePath: identityFilePath.isNotEmpty ? identityFilePath : null,
          );

          if (meta != null) {
            final typeFromDb = (meta['type'] ?? '').toString().trim();
            if (typeFromDb.isNotEmpty) resolvedType = typeFromDb;

            final endFromDb =
                (meta['end_location'] ?? meta['endLocation'] ?? '')
                    .toString()
                    .trim();
            if (endFromDb.isNotEmpty) resolvedEndLocation = endFromDb;

            final holderFromDb =
                (meta['current_holder'] ?? meta['currentHolder'] ?? '')
                    .toString()
                    .trim();
            if (holderFromDb.isNotEmpty) resolvedCurrentHolder = holderFromDb;

            final statusFromDb = (meta['status'] ?? '').toString().trim();
            if (statusFromDb.isNotEmpty) resolvedStatus = statusFromDb;

            if (resolvedTrackingId.isEmpty) {
              final idFromDb = (meta['id'] ?? '').toString().trim();
              if (idFromDb.isNotEmpty) resolvedTrackingId = idFromDb;
            }
          }

          return {
            'trackingId': resolvedTrackingId,
            'type': resolvedType,
            'endLocation': resolvedEndLocation,
            'currentHolder': resolvedCurrentHolder,
            'status': resolvedStatus,
          };
        }

        final detailsFuture = loadDetails();
        Widget detailRow(
          IconData icon,
          String label,
          String value,
        ) {
          return Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, size: 16, color: const Color(0xFF6868AC)),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    '$label: $value',
                    style: TextStyle(
                      fontSize: 12,
                      color: Theme.of(context)
                          .colorScheme
                          .onSurface
                          .withOpacity(0.75),
                    ),
                  ),
                ),
              ],
            ),
          );
        }

        final mq = MediaQuery.of(ctx);
        return SafeArea(
          child: SingleChildScrollView(
            padding: EdgeInsets.only(
              left: 16,
              right: 16,
              top: 16,
              bottom: mq.viewInsets.bottom + 16,
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      width: 48,
                      height: 48,
                      decoration: BoxDecoration(
                        color: const Color(0xFF6868AC).withOpacity(0.08),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Icon(
                        activity['icon'] as IconData? ?? Icons.description,
                        color: const Color(0xFF6868AC),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            activity['title']?.toString() ?? 'Activity',
                            style: const TextStyle(
                                fontSize: 16, fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            activity['time']?.toString() ?? '',
                            style: const TextStyle(
                                fontSize: 12, color: Colors.grey),
                          ),
                        ],
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close),
                      onPressed: () => Navigator.pop(context),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  activity['subtitle']?.toString() ?? '',
                  style: TextStyle(
                    fontSize: 14,
                    color: Theme.of(context).colorScheme.onSurface,
                  ),
                ),
                FutureBuilder<Map<String, String>>(
                  future: detailsFuture,
                  builder: (ctx, snapshot) {
                    final details = snapshot.data ??
                        <String, String>{
                          'trackingId': trackingId,
                          'type': (activity['type']?.toString() ?? '').trim(),
                          'endLocation': endLocation,
                          'currentHolder': currentHolder,
                          'status':
                              (activity['status']?.toString() ?? '').trim(),
                        };

                    final resolvedType = (details['type'] ?? '').trim();
                    final resolvedEndLocation =
                        (details['endLocation'] ?? '').trim();
                    final resolvedCurrentHolder =
                        (details['currentHolder'] ?? '').trim();
                    final resolvedStatus = (details['status'] ?? '').trim();

                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        if (snapshot.connectionState == ConnectionState.waiting)
                          const Padding(
                            padding: EdgeInsets.only(top: 10),
                            child: LinearProgressIndicator(minHeight: 2),
                          ),
                        if (activity['id'] != null)
                          detailRow(
                            Icons.tag,
                            'ID',
                            activity['id'].toString(),
                          ),
                        detailRow(
                          Icons.description_outlined,
                          'Type',
                          resolvedType.isNotEmpty ? resolvedType : 'Document',
                        ),
                        detailRow(
                          Icons.flag_outlined,
                          'End Location',
                          resolvedEndLocation.isNotEmpty
                              ? resolvedEndLocation
                              : 'Not available',
                        ),
                        detailRow(
                          Icons.apartment_outlined,
                          'Current Holder',
                          resolvedCurrentHolder.isNotEmpty
                              ? resolvedCurrentHolder
                              : 'Not available',
                        ),
                        detailRow(
                          Icons.info_outline,
                          'Status',
                          resolvedStatus.isNotEmpty
                              ? resolvedStatus
                              : 'Not available',
                        ),
                      ],
                    );
                  },
                ),
                const SizedBox(height: 12),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  void dispose() {
    _activityTimer?.cancel();
    _notifTimer?.cancel();
    _routingSub?.cancel();
    _pageController.dispose();
    _searchDebounce?.cancel();
    _searchFocusNode.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      // Refresh immediately when user returns to the app
      _fetchRecentActivity();
      _fetchNotifications();
      // Restart timers if they were cancelled
      _activityTimer ??= Timer.periodic(const Duration(seconds: 15), (_) {
        _fetchRecentActivity();
      });
      _notifTimer ??= Timer.periodic(const Duration(seconds: 30), (_) {
        _fetchNotifications();
      });
    } else if (state == AppLifecycleState.paused ||
        state == AppLifecycleState.inactive) {
      // Pause polling when app is in background to save battery/CPU
      _activityTimer?.cancel();
      _activityTimer = null;
      _notifTimer?.cancel();
      _notifTimer = null;
    }
  }

  Future<void> _fetchNotifications() async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final prefs = await SharedPreferences.getInstance();
      final uname = prefs.getString('user_name') ?? username;
      if (uname.isEmpty) return;

      final rootUri = Uri.parse(root);
      final uri = rootUri.replace(
        pathSegments: [
          ...rootUri.pathSegments,
          'lib',
          'OCR(UPDATED)',
          'api',
          'notifications.php',
        ],
        queryParameters: {
          'action': 'list',
          'recipient_username': uname,
          'limit': '25',
        },
      );
      final resp = await http.get(uri).timeout(const Duration(seconds: 10));
      if (resp.statusCode == 200 && resp.body.isNotEmpty) {
        final data = jsonDecode(resp.body);
        if (data is Map && data['success'] == true) {
          final list = (data['notifications'] as List<dynamic>? ?? [])
              .map<Map<String, dynamic>>((e) => Map<String, dynamic>.from(e))
              .toList();
          // Skip redundant rebuilds when data hasn't changed
          final sig = list.map((n) => '${n['id']}_${n['status']}').join(',');
          if (sig == _lastNotifSig) return;
          _lastNotifSig = sig;
          if (mounted) {
            setState(() {
              _notifications = list;
              // Treat status!='read' as unread
              _unreadCount = list
                  .where((n) => (n['status']?.toString() ?? 'new') != 'read')
                  .length;
            });
          }
        }
      }
    } catch (e) {}
  }

  Future<void> _fetchUserData() async {
    await Future.delayed(const Duration(milliseconds: 300));
    final routeArgs =
        ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?;

    String? savedUsername;
    String? savedEmail;
    String? savedDept;

    try {
      final prefs = await SharedPreferences.getInstance();
      savedUsername = prefs.getString('user_name');
      savedEmail = prefs.getString('user_email');
      savedDept = prefs.getString('user_department');

      final role = (prefs.getString('user_role') ??
              prefs.getString('role') ??
              prefs.getString('user_type') ??
              '')
          .trim()
          .toLowerCase();
      final isAdminFlag = prefs.getBool('is_admin') == true;
      final debugOverride = prefs.getBool('enable_route_debug_tools') == true;
      final usernameSeed =
          (savedUsername ?? widget.username ?? '').trim().toLowerCase();
      _debugToolsEnabled = kDebugMode ||
          debugOverride ||
          isAdminFlag ||
          role == 'admin' ||
          usernameSeed == 'admin';
    } catch (e) {}

    if (mounted) {
      setState(() {
        username = widget.username ??
            routeArgs?['username'] ??
            savedUsername ??
            'User';
        email = widget.email ??
            routeArgs?['email'] ??
            savedEmail ??
            'user@example.com';
        _userDepartment = (savedDept ?? '').trim();
      });
    }

    _startRealtimeRoutingListener((savedDept ?? '').trim());
  }

  String _getPageTitle() {
    switch (_currentIndex) {
      case 0:
        return 'Dashboard';
      case 1:
        return 'Archive';
      case 2:
        return 'Profile';
      default:
        return 'Dashboard';
    }
  }

  Widget _buildSearchField() {
    return SizedBox(
      height: 40,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          TextField(
            focusNode: _searchFocusNode,
            autofocus: true,
            onChanged: (value) {
              setState(() => searchQuery = value);
              _searchDebounce?.cancel();
              _searchDebounce = Timer(const Duration(milliseconds: 300), () {
                _fetchSearchSuggestions(value);
              });
            },
            decoration: const InputDecoration(
              hintText: 'Search documents, departments, OCR...',
              hintStyle: TextStyle(color: Colors.white70),
              border: InputBorder.none,
            ),
            style: const TextStyle(color: Colors.white, fontSize: 18),
          ),
          if (_searchSuggestions.isNotEmpty)
            Container(
              constraints: const BoxConstraints(maxHeight: 180),
              margin: const EdgeInsets.only(top: 4),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(8),
              ),
              child: ListView.builder(
                itemCount: _searchSuggestions.length,
                shrinkWrap: true,
                itemBuilder: (context, index) {
                  final suggestion = _searchSuggestions[index];
                  return ListTile(
                    dense: true,
                    title: Text(suggestion['label'] ?? ''),
                    subtitle:
                        Text((suggestion['field'] ?? '').replaceAll('_', ' ')),
                    onTap: () {
                      setState(() {
                        searchQuery = suggestion['label'] ?? '';
                        _searchSuggestions = [];
                      });
                      _searchFocusNode.unfocus();
                    },
                  );
                },
              ),
            ),
        ],
      ),
    );
  }

  Future<void> _fetchSearchSuggestions(String query) async {
    if (query.trim().length < 2) {
      setState(() => _searchSuggestions = []);
      return;
    }
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
        queryParameters: {'action': 'search_suggest', 'q': query},
      );
      final resp = await http.get(uri).timeout(const Duration(seconds: 8));
      if (resp.statusCode == 200 && resp.body.isNotEmpty) {
        final data = jsonDecode(resp.body);
        final suggestions = (data['suggestions'] as List?)
                ?.whereType<Map>()
                .map((e) => {
                      'label': e['label']?.toString() ?? '',
                      'field': e['field']?.toString() ?? '',
                    })
                .where((e) => (e['label'] ?? '').isNotEmpty)
                .toList() ??
            [];
        setState(() => _searchSuggestions = suggestions);
      }
    } catch (_) {}
  }

  Widget _buildBody({required double bottomPad}) {
    switch (_currentIndex) {
      case 0:
        return _buildDashboardContent(bottomPad: bottomPad);
      case 1:
        return const GalleryPage();
      case 2:
        return _buildProfileContent(bottomPad: bottomPad);
      default:
        return _buildDashboardContent(bottomPad: bottomPad);
    }
  }

  Widget _buildDashboardContent({required double bottomPad}) {
    return RefreshIndicator(
      onRefresh: () async {
        await Future.delayed(const Duration(seconds: 1));
        setState(() {});
      },
      child: ListView(
        padding: EdgeInsets.only(bottom: bottomPad),
        children: [
          _buildWelcomeBanner(),
          const SizedBox(height: 16),
          _buildSectionHeader('Recent Documents', onTap: () async {
            final changed = await Navigator.push<bool>(
              context,
              MaterialPageRoute(
                builder: (_) => RecentUploadPage(
                  items: List<Map<String, dynamic>>.from(
                    _getReceivedActivityItems(),
                  ),
                  title: 'Recent Documents',
                ),
              ),
            );
            if (changed == true) {
              await _fetchRecentActivity();
            }
          }),
          _buildRecentActivityFeed(),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  Widget _buildWelcomeBanner() {
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 16, 16, 8),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF6868AC), Color(0xFF52528A)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF6868AC).withOpacity(0.25),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
          BoxShadow(
            color: const Color(0xFF52528A).withOpacity(0.10),
            blurRadius: 4,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 56,
            height: 56,
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.2),
              shape: BoxShape.circle,
              border:
                  Border.all(color: Colors.white.withOpacity(0.3), width: 2),
            ),
            child: const Icon(Icons.person, size: 30, color: Colors.white),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Welcome back,',
                  style: TextStyle(
                    fontFamily: 'Poppins',
                    color: Colors.white.withOpacity(0.8),
                    fontSize: 13,
                    fontWeight: FontWeight.w400,
                    height: 1.3,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  username,
                  style: const TextStyle(
                    fontFamily: 'Poppins',
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -0.3,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  // Build server root from saved preferences, preferring explicit server_root
  Future<String?> _getServerRoot() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      var root = (prefs.getString('server_root') ?? '').trim();
      if (root.isEmpty) {
        final detected = (prefs.getString('detected_server_url') ?? '').trim();
        if (detected.isNotEmpty) {
          root = detected;
        }
      }
      if (root.isEmpty) {
        root = (await ServerService.getServerUrl()).trim();
      }
      if (root.endsWith('/api')) root = root.substring(0, root.length - 4);

      // Ensure the root includes the project path.
      // The PHP backend is hosted under /flutter_application_7 on XAMPP.
      // If root is just http://<ip> (or http://<ip>/), API calls like
      // /lib/OCR(UPDATED)/... will 404.
      const projectPath = '/flutter_application_7';
      final normalized = root.trim().replaceAll(RegExp(r'/+$'), '');
      if (normalized.startsWith('http://') ||
          normalized.startsWith('https://')) {
        final lower = normalized.toLowerCase();
        if (!lower.contains(projectPath)) {
          root = '$normalized$projectPath';
        } else {
          root = normalized;
        }
      }

      // Persist normalized root so subsequent calls are consistent.
      final saved = (prefs.getString('server_root') ?? '').trim();
      if (root.isNotEmpty && saved != root) {
        await prefs.setString('server_root', root);
      }
      return root;
    } catch (_) {
      return null;
    }
  }

  Future<void> _archiveActivityFile({
    required String? fileUrl,
    required String displayName,
  }) async {
    try {
      final url = (fileUrl ?? '').trim();
      if (url.isEmpty) return;

      String resolved = url;
      if (!resolved.contains('://')) {
        final root = await _getServerRoot();
        if (root == null) return;
        if (resolved.startsWith('/')) {
          resolved = '$root$resolved';
        } else {
          resolved = '$root/$resolved';
        }
      }

      final resp = await http.get(Uri.parse(resolved)).timeout(
            const Duration(seconds: 15),
          );
      if (resp.statusCode >= 400) return;

      final prefs = await SharedPreferences.getInstance();
      final userDepartment = prefs.getString('user_department') ?? 'General';
      final dir = await getApplicationDocumentsDirectory();
      final archiveDir = Directory('${dir.path}/Archive/$userDepartment');
      if (!await archiveDir.exists()) {
        await archiveDir.create(recursive: true);
      }

      final now = DateTime.now().millisecondsSinceEpoch;
      String ext = '';
      final uriPath = Uri.parse(resolved).path;
      final dot = uriPath.lastIndexOf('.');
      if (dot != -1 && dot < uriPath.length - 1) {
        ext = uriPath.substring(dot);
      }
      String prefix = 'IMG_';
      if (ext.toLowerCase() == '.pdf') {
        prefix = 'PDF_';
      }
      final fileName = '$prefix$now${ext.isNotEmpty ? ext : '.bin'}';
      final outFile = File('${archiveDir.path}/$fileName');
      await outFile.writeAsBytes(resp.bodyBytes, flush: true);

      final metaPath = '${archiveDir.path}/OCR_$now.txt';
      final metaFile = File(metaPath);
      final typeLine = 'Document Type: $displayName';
      final nameLine = 'Document Name: $displayName';
      await metaFile.writeAsString('$typeLine\n$nameLine');
    } catch (_) {}
  }

  Future<void> _confirmDeleteActivity(Map<String, dynamic> activity) async {
    final title = activity['title']?.toString() ?? 'this item';
    final bool? ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete Activity'),
        content: Text(
            'Are you sure you want to delete "$title" from Recent Activity?'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel')),
          ElevatedButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Delete')),
        ],
      ),
    );
    if (ok == true) {
      await _deleteActivity(activity);
      await _fetchRecentActivity();
    }
  }

  Future<void> _deleteActivity(Map<String, dynamic> activity) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final id = activity['id']?.toString();
      if (id == null || id.isEmpty) return;

      // Try modern API: /api/recent_activity.php?action=delete&id=ID (POST)
      try {
        final uri = Uri.parse('$root/api/recent_activity.php')
            .replace(queryParameters: {'action': 'delete', 'id': id});
        final r = await http.post(uri).timeout(const Duration(seconds: 8));
        if (r.statusCode < 400) return; // assume success
      } catch (_) {}

      // Do NOT touch notifications.php here; this helper is for
      // Recent Activity/Uploads and should only affect document/route data.
    } catch (_) {}
  }

  Future<void> _fetchRecentActivity() async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final prefs = await SharedPreferences.getInstance();
      final currentUser = prefs.getString('user_name') ?? '';
      final currentDept = prefs.getString('user_department') ?? '';

      // Ensure we can show sender department labels
      await _ensureDeptCache();

      // Helper: check if notification is explicitly for the current user
      bool isForCurrentUser(Map m, String user) {
        final ru = (m['recipient_username'] ??
                m['recipient'] ??
                m['to_user'] ??
                m['assigned_to'] ??
                m['assignee'])
            ?.toString()
            .trim()
            .toLowerCase();
        final uu = user.trim().toLowerCase();
        return ru != null && ru.isNotEmpty && ru == uu;
      }

      // Helper: notification is for this user or for their department
      String normalizeDepartment(String raw) {
        final up = raw.trim().toUpperCase();
        if (up.isEmpty) return '';
        if (up.contains('ACCOUNTING')) return 'ACCOUNTING';
        if (up == 'HR' || up.contains('HUMAN RESOURCE')) return 'HR';
        if (up.contains('CBO')) return 'CBO';
        if (up.contains('CAO')) return 'CAO';
        if (up.contains('CTO')) return 'CTO';
        if (up.contains('CPDO')) return 'CPDO';
        if (up.contains('GSO')) return 'GSO';
        if (up.contains('CACCO')) return 'CACCO';
        if (up.contains('CADO')) return 'CADO';
        if (up.contains('CMO')) return 'CMO';
        return up;
      }

      bool isForCurrentUserOrDepartment(Map m, String user, String dept) {
        if (isForCurrentUser(m, user)) {
          return true;
        }
        final rd = normalizeDepartment(
          (m['recipient_department'] ??
                  m['recipientDepartment'] ??
                  m['recipient_dept'] ??
                  m['dept'] ??
                  m['current_holder'] ??
                  m['currentHolder'] ??
                  m['end_location'] ??
                  m['endLocation'] ??
                  '')
              .toString(),
        );
        final ud = normalizeDepartment(dept);
        if (rd.isNotEmpty && rd == ud) {
          return true;
        }
        return false;
      }

      bool shouldDisplayNotification(Map m) {
        final docStatus = (m['doc_status'] ?? m['docStatus'] ?? '')
            .toString()
            .trim()
            .toLowerCase();
        if (docStatus == 'completed' ||
            docStatus == 'archived' ||
            docStatus == 'approved') {
          return false;
        }

        final notifStatus = (m['status'] ?? '').toString().trim().toLowerCase();
        if (notifStatus == 'completed') {
          final hasTrackingIdentity =
              (m['tracking_id']?.toString().trim().isNotEmpty ?? false) ||
                  (m['trackingId']?.toString().trim().isNotEmpty ?? false) ||
                  (m['mobile_timestamp']?.toString().trim().isNotEmpty ??
                      false) ||
                  (m['mobileTimestamp']?.toString().trim().isNotEmpty ?? false);
          if (!hasTrackingIdentity) return false;
        }

        return true;
      }

      // 1) Prefer notifications API, then filter client-side for this user/department
      List<Map<String, dynamic>> items = [];
      bool success = false;
      Uri notifUri = Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
          .replace(queryParameters: {
        'action': 'list',
        'limit': '50',
      });
      try {
        final r = await http.get(notifUri).timeout(const Duration(seconds: 10));
        if (r.statusCode == 200 && r.body.isNotEmpty) {
          final Map<String, dynamic> jm = jsonDecode(r.body);
          final List listRaw =
              (jm['notifications'] ?? jm['data'] ?? []) as List;
          // Keep only items explicitly for this user or their department
          final List list = listRaw
              .whereType<Map>()
              .where((m) => shouldDisplayNotification(m))
              .where((m) =>
                  isForCurrentUserOrDepartment(m, currentUser, currentDept))
              .toList();
          int? extractCreatedAtMs(Map m) {
            dynamic v = m['created_at'] ??
                m['createdAt'] ??
                m['timestamp'] ??
                m['time'];
            if (v == null) return null;
            try {
              if (v is int) return v;
              final s = v.toString().trim();
              if (RegExp(r'^\d{10,13}$').hasMatch(s)) {
                final n = int.tryParse(s);
                if (n == null) return null;
                return s.length >= 13 ? n : n * 1000;
              }
              final dt = DateTime.tryParse(s);
              return dt?.millisecondsSinceEpoch;
            } catch (_) {
              return null;
            }
          }

          items = list.take(10).map<Map<String, dynamic>>((e) {
            final m = Map<String, dynamic>.from(e as Map);
            final sender =
                (m['sender_username'] ?? m['from_user'] ?? m['creator'])
                        ?.toString() ??
                    '';
            final content =
                (m['content']?.toString() ?? m['message']?.toString() ?? '')
                    .trim();
            final parsedDocType = content.contains('•')
                ? content.split('•').first.trim()
                : content;
            // Prefer canonical type fields from API, then parsed message fallback.
            final apiDocType =
                (m['document_type'] ?? m['documentType'] ?? m['type'] ?? '')
                    .toString()
                    .trim();
            final ignoredTypes = {
              'mobile_message',
              'document_upload',
              'upload',
              'notification',
              'system_update',
            };
            final canonicalType =
                ignoredTypes.contains(apiDocType.toLowerCase())
                    ? ''
                    : apiDocType;
            final displayTitle = canonicalType.isNotEmpty
                ? canonicalType
                : (parsedDocType.isNotEmpty ? parsedDocType : 'Document');

            final senderDept = _senderDeptLabel(
              sender,
              (m['sender_department'] ?? m['from_department'] ?? '')
                  ?.toString(),
            );

            final createdAtMs = extractCreatedAtMs(m);
            final when = _formatLocalDateTime(createdAtMs);
            final subtitleParts = <String>[];
            if (senderDept.isNotEmpty) subtitleParts.add(senderDept);
            if (when.isNotEmpty) subtitleParts.add(when);
            final displaySubtitle = subtitleParts.isNotEmpty
                ? subtitleParts.join(' • ')
                : (m['time_ago']?.toString() ??
                    m['time']?.toString() ??
                    m['created_at']?.toString() ??
                    '');
            final activity = {
              'title': displayTitle,
              'subtitle': displaySubtitle,
              'time': m['time_ago']?.toString() ??
                  m['time']?.toString() ??
                  m['created_at']?.toString() ??
                  '',
              'icon': Icons.description_outlined,
              'id': m['id'],
              'type': displayTitle,
              'createdAtMs': createdAtMs,
              'fileUrl':
                  (m['file_url'] ?? m['url'] ?? m['attachment'] ?? m['link'])
                      ?.toString(),
              'sender': sender,
              'senderDepartment': senderDept,
              'recipientDepartment':
                  (m['recipient_department'] ?? m['dept'])?.toString(),
              // IMPORTANT: notifications.status is NOT the document status.
              // Prefer doc_status/docStatus (tracking.status), fallback to notifications.status.
              'status':
                  (m['doc_status'] ?? m['docStatus'] ?? m['status'] ?? 'new')
                      .toString(),
              'endLocation':
                  (m['end_location'] ?? m['endLocation'])?.toString(),
              'currentHolder':
                  (m['current_holder'] ?? m['currentHolder'])?.toString(),
            };
            return _decorateActivityWithIdentity(activity, m);
          }).toList();
          // Exclude self-sent items
          // Show items even if created by the current user (e.g., department-targeted)
          // Do not exclude self-sent items here.
          success = items.isNotEmpty || list.isNotEmpty;

          // Fetch department-targeted notifications and merge
          if (currentDept.isNotEmpty) {
            final deptUri =
                Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
                    .replace(queryParameters: {
              'action': 'list',
              'limit': '20',
              'recipient_department': currentDept,
            });
            try {
              final rd =
                  await http.get(deptUri).timeout(const Duration(seconds: 10));
              if (rd.statusCode == 200 && rd.body.isNotEmpty) {
                final Map<String, dynamic> jm = jsonDecode(rd.body);
                final List listD =
                    (jm['notifications'] ?? jm['data'] ?? []) as List;
                final deptItems = listD
                    .whereType<Map>()
                    .where((m) => shouldDisplayNotification(m))
                    .take(10)
                    .map<Map<String, dynamic>>((e) {
                  final m = Map<String, dynamic>.from(e);
                  final sender =
                      (m['sender_username'] ?? m['from_user'] ?? m['creator'])
                              ?.toString() ??
                          '';
                  final content = (m['content']?.toString() ??
                          m['message']?.toString() ??
                          '')
                      .trim();
                  final parsedDocType = content.contains('•')
                      ? content.split('•').first.trim()
                      : content;
                  final apiDocType = (m['document_type'] ??
                          m['documentType'] ??
                          m['type'] ??
                          '')
                      .toString()
                      .trim();
                  final ignoredTypes = {
                    'mobile_message',
                    'document_upload',
                    'upload',
                    'notification',
                    'system_update',
                  };
                  final canonicalType =
                      ignoredTypes.contains(apiDocType.toLowerCase())
                          ? ''
                          : apiDocType;
                  final displayTitle = canonicalType.isNotEmpty
                      ? canonicalType
                      : (parsedDocType.isNotEmpty ? parsedDocType : 'Document');

                  final senderDept = _senderDeptLabel(
                    sender,
                    (m['sender_department'] ?? m['from_department'] ?? '')
                        ?.toString(),
                  );
                  final createdAtMs = extractCreatedAtMs(m);
                  final when = _formatLocalDateTime(createdAtMs);
                  final subtitleParts = <String>[];
                  if (senderDept.isNotEmpty) subtitleParts.add(senderDept);
                  if (when.isNotEmpty) subtitleParts.add(when);
                  final displaySubtitle = subtitleParts.isNotEmpty
                      ? subtitleParts.join(' • ')
                      : (m['time_ago']?.toString() ??
                          m['time']?.toString() ??
                          m['created_at']?.toString() ??
                          '');
                  final activity = {
                    'title': displayTitle,
                    'subtitle': displaySubtitle,
                    'time': m['time_ago']?.toString() ??
                        m['time']?.toString() ??
                        m['created_at']?.toString() ??
                        '',
                    'icon': Icons.description_outlined,
                    'id': m['id'],
                    'type': displayTitle,
                    'createdAtMs': createdAtMs,
                    'fileUrl': (m['file_url'] ??
                            m['url'] ??
                            m['attachment'] ??
                            m['link'])
                        ?.toString(),
                    'sender': sender,
                    'senderDepartment': senderDept,
                    'recipientDepartment':
                        (m['recipient_department'] ?? m['dept'])?.toString(),
                    'status': (m['doc_status'] ??
                            m['docStatus'] ??
                            m['status'] ??
                            'new')
                        .toString(),
                    'endLocation':
                        (m['end_location'] ?? m['endLocation'])?.toString(),
                    'currentHolder':
                        (m['current_holder'] ?? m['currentHolder'])?.toString(),
                  };
                  return _decorateActivityWithIdentity(activity, m);
                }).toList();
                // Merge & dedupe by id (do not exclude self-sent department items)
                final Map<String, Map<String, dynamic>> mapById = {
                  for (final it in items)
                    (it['id']?.toString() ?? '${it['title']}_${it['time']}'): it
                };
                for (final it in deptItems) {
                  final key =
                      it['id']?.toString() ?? '${it['title']}_${it['time']}';
                  mapById[key] = it;
                }
                items = mapById.values.toList();
                // Sort newest first
                items.sort((a, b) => (b['createdAtMs'] as int? ?? 0)
                    .compareTo(a['createdAtMs'] as int? ?? 0));
              }
            } catch (_) {}
          }
          // If empty, retry without type filter to avoid mismatch
          if (!success) {
            final notifUri2 =
                Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
                    .replace(queryParameters: {
              'action': 'list',
              'limit': '20',
              'recipient_username': currentUser,
              if (currentDept.trim().isNotEmpty)
                'recipient_department': currentDept.trim(),
            });
            final r2 =
                await http.get(notifUri2).timeout(const Duration(seconds: 10));
            if (r2.statusCode == 200 && r2.body.isNotEmpty) {
              final Map<String, dynamic> jm2 = jsonDecode(r2.body);
              final List list2 =
                  (jm2['notifications'] ?? jm2['data'] ?? []) as List;
              items = list2.take(10).map<Map<String, dynamic>>((e) {
                final m = Map<String, dynamic>.from(e as Map);
                final sender =
                    (m['sender_username'] ?? m['from_user'] ?? m['creator'])
                            ?.toString() ??
                        '';
                final content =
                    (m['content']?.toString() ?? m['message']?.toString() ?? '')
                        .trim();
                final parsedDocType = content.contains('•')
                    ? content.split('•').first.trim()
                    : content;
                final apiDocType =
                    (m['document_type'] ?? m['documentType'] ?? '')
                        .toString()
                        .trim();
                final displayTitle = apiDocType.isNotEmpty
                    ? apiDocType
                    : (parsedDocType.isNotEmpty ? parsedDocType : 'Document');
                final activity = {
                  'title': displayTitle,
                  'subtitle': content,
                  'time': m['time_ago']?.toString() ??
                      m['time']?.toString() ??
                      m['created_at']?.toString() ??
                      '',
                  'icon': Icons.description_outlined,
                  'id': m['id'],
                  'type': displayTitle,
                  'createdAtMs': extractCreatedAtMs(m),
                  'fileUrl': (m['file_url'] ??
                          m['url'] ??
                          m['attachment'] ??
                          m['link'])
                      ?.toString(),
                  'sender': sender,
                  'endLocation':
                      (m['end_location'] ?? m['endLocation'])?.toString(),
                  'currentHolder':
                      (m['current_holder'] ?? m['currentHolder'])?.toString(),
                };
                return _decorateActivityWithIdentity(activity, m);
              }).toList();
              items = items
                  .where((m) =>
                      (m['sender']?.toString().toLowerCase() ?? '') !=
                      currentUser.toLowerCase())
                  .toList();
              success = items.isNotEmpty || list2.isNotEmpty;
            }
          }
        }
      } catch (_) {}

      // 2) Fallback: New lightweight endpoint: api/recent_activity.php (filtered client-side)
      final simpleUri = Uri.parse('$root/api/recent_activity.php')
          .replace(queryParameters: {'limit': '20'});
      http.Response resp;
      try {
        resp = await http.get(simpleUri).timeout(const Duration(seconds: 10));
      } catch (_) {
        resp = http.Response('', 599);
      }
      int? extractCreatedAtMs(Map m) {
        dynamic v =
            m['created_at'] ?? m['createdAt'] ?? m['timestamp'] ?? m['time'];
        if (v == null) return null;
        try {
          if (v is int) return v;
          final s = v.toString().trim();
          // numeric seconds or ms
          if (RegExp(r'^\d{10,13}$').hasMatch(s)) {
            final n = int.tryParse(s);
            if (n == null) return null;
            return s.length >= 13 ? n : n * 1000;
          }
          final dt = DateTime.tryParse(s);
          return dt?.millisecondsSinceEpoch;
        } catch (_) {
          return null;
        }
      }

      if (!success && resp.statusCode == 200 && resp.body.isNotEmpty) {
        try {
          final jsonMap = jsonDecode(resp.body) as Map<String, dynamic>;
          final List listRaw =
              (jsonMap['notifications'] ?? jsonMap['data'] ?? []) as List;
          final List list = listRaw
              .whereType<Map>()
              .where((m) =>
                  isForCurrentUserOrDepartment(m, currentUser, currentDept))
              .toList();
          items = list.take(10).map<Map<String, dynamic>>((e) {
            final m = Map<String, dynamic>.from(e as Map);
            final activity = {
              'title': m['title']?.toString() ?? 'Activity',
              'subtitle':
                  m['content']?.toString() ?? m['message']?.toString() ?? '',
              'time': m['time_ago']?.toString() ??
                  m['time']?.toString() ??
                  m['created_at']?.toString() ??
                  '',
              'icon': _iconForType(m['type']?.toString()),
              'id': m['id'],
              'type': m['type']?.toString(),
              'createdAtMs': extractCreatedAtMs(m),
              'fileUrl': (m['file_url'] ??
                      m['fileUrl'] ??
                      m['url'] ??
                      m['attachment'] ??
                      m['link'] ??
                      m['file_path'] ??
                      m['filePath'])
                  ?.toString(),
              'sender': (m['sender_username'] ?? m['from_user'] ?? m['creator'])
                  ?.toString(),
              'recipientDepartment': (m['recipient_department'] ??
                      m['recipient_dept'] ??
                      m['dept'] ??
                      m['current_holder'] ??
                      m['currentHolder'])
                  ?.toString(),
              'status': (m['doc_status'] ?? m['docStatus'] ?? m['status'] ?? '')
                  .toString(),
              'endLocation':
                  (m['end_location'] ?? m['endLocation'])?.toString(),
              'currentHolder':
                  (m['current_holder'] ?? m['currentHolder'])?.toString(),
            };
            return _decorateActivityWithIdentity(activity, m);
          }).toList();
          success = items.isNotEmpty || (list.isNotEmpty);
        } catch (_) {}
      }

      // 2b) Direct user-targeted list from notifications API (recipient_username)
      if (!success) {
        final userUri =
            Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
                .replace(queryParameters: {
          'action': 'list',
          'limit': '20',
          'recipient_username': currentUser,
          if (currentDept.trim().isNotEmpty)
            'recipient_department': currentDept.trim(),
        });
        try {
          final ru =
              await http.get(userUri).timeout(const Duration(seconds: 10));
          if (ru.statusCode == 200 && ru.body.isNotEmpty) {
            final Map<String, dynamic> jm = jsonDecode(ru.body);
            final List list = (jm['notifications'] ?? jm['data'] ?? []) as List;
            items = list.take(20).map<Map<String, dynamic>>((e) {
              final m = Map<String, dynamic>.from(e as Map);
              final activity = {
                'title': m['title']?.toString() ?? 'Activity',
                'subtitle':
                    m['content']?.toString() ?? m['message']?.toString() ?? '',
                'time': m['time_ago']?.toString() ??
                    m['time']?.toString() ??
                    m['created_at']?.toString() ??
                    '',
                'icon': _iconForType(m['type']?.toString()),
                'id': m['id'],
                'type': m['type']?.toString(),
                'createdAtMs': extractCreatedAtMs(m),
                'fileUrl':
                    (m['file_url'] ?? m['url'] ?? m['attachment'] ?? m['link'])
                        ?.toString(),
                'sender':
                    (m['sender_username'] ?? m['from_user'] ?? m['creator'])
                        ?.toString(),
                'recipientDepartment':
                    (m['recipient_department'] ?? m['dept'])?.toString(),
                'status':
                    (m['doc_status'] ?? m['docStatus'] ?? m['status'] ?? 'new')
                        .toString(),
                'endLocation':
                    (m['end_location'] ?? m['endLocation'])?.toString(),
                'currentHolder':
                    (m['current_holder'] ?? m['currentHolder'])?.toString(),
              };
              return _decorateActivityWithIdentity(activity, m);
            }).toList();
            success = items.isNotEmpty || list.isNotEmpty;
          }
        } catch (_) {}
      }

      // 3) Try the original notifications API under lib/OCR(UPDATED) if needed (filtered client-side)
      if (!success) {
        final notificationsUri =
            Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
                .replace(queryParameters: {'action': 'list', 'limit': '20'});
        try {
          final r2 = await http
              .get(notificationsUri)
              .timeout(const Duration(seconds: 10));
          if (r2.statusCode == 200 && r2.body.isNotEmpty) {
            final jsonMap = jsonDecode(r2.body) as Map<String, dynamic>;
            final List listRaw =
                (jsonMap['notifications'] ?? jsonMap['data'] ?? []) as List;
            final List list = listRaw
                .whereType<Map>()
                .where((m) =>
                    isForCurrentUserOrDepartment(m, currentUser, currentDept))
                .toList();
            items = list.take(10).map<Map<String, dynamic>>((e) {
              final m = Map<String, dynamic>.from(e as Map);
              final activity = {
                'title': m['title']?.toString() ?? 'Activity',
                'subtitle':
                    m['content']?.toString() ?? m['message']?.toString() ?? '',
                'time': m['time_ago']?.toString() ??
                    m['time']?.toString() ??
                    m['created_at']?.toString() ??
                    '',
                'icon': _iconForType(m['type']?.toString()),
                'id': m['id'],
                'type': m['type']?.toString(),
                'createdAtMs': extractCreatedAtMs(m),
                'fileUrl':
                    (m['file_url'] ?? m['url'] ?? m['attachment'] ?? m['link'])
                        ?.toString(),
                'sender':
                    (m['sender_username'] ?? m['from_user'] ?? m['creator'])
                        ?.toString(),
              };
              return _decorateActivityWithIdentity(activity, m);
            }).toList();
            success = items.isNotEmpty || (list.isNotEmpty);
          }
        } catch (_) {}
      }

      // 4) Final fallback to tracking.php?action=notifications (filtered client-side)
      if (!success) {
        final fallbackUri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php')
            .replace(queryParameters: {
          'action': 'notifications',
          'page': '1',
          'limit': '20',
        });
        try {
          final r2 =
              await http.get(fallbackUri).timeout(const Duration(seconds: 10));
          if (r2.statusCode == 200) {
            final jsonMap = jsonDecode(r2.body) as Map<String, dynamic>;
            final List listRaw = (jsonMap['notifications'] ?? []) as List;
            final List list = listRaw
                .whereType<Map>()
                .where((m) => isForCurrentUser(m, currentUser))
                .toList();
            items = list.take(10).map<Map<String, dynamic>>((e) {
              final m = Map<String, dynamic>.from(e as Map);
              final activity = {
                'title': m['title']?.toString() ?? 'Activity',
                'subtitle': m['content']?.toString() ?? '',
                'time': m['time']?.toString() ?? '',
                'icon': _iconForType(m['type']?.toString()),
                'id': m['id'],
                'type': m['type']?.toString(),
                'createdAtMs': extractCreatedAtMs(m),
                'fileUrl':
                    (m['file_url'] ?? m['url'] ?? m['attachment'] ?? m['link'])
                        ?.toString(),
                'sender':
                    (m['sender_username'] ?? m['from_user'] ?? m['creator'])
                        ?.toString(),
              };
              return _decorateActivityWithIdentity(activity, m);
            }).toList();
          }
        } catch (_) {}
      }

      // No generic fallback: we now merge user + department; if still empty, show empty state.

      // Notify on new items compared to last seen
      final newestMs = items
          .map<int?>((m) => m['createdAtMs'] as int?)
          .whereType<int>()
          .fold<int>(0, (p, c) => c > p ? c : p);
      final prefs2 = await SharedPreferences.getInstance();
      final key = 'last_seen_activity_ms_${currentUser.toLowerCase()}';
      final prev = _lastSeenActivityMs ?? prefs2.getInt(key);
      // We still track last seen timestamp, but no longer show a SnackBar/banner.
      if (prev != null && newestMs > prev) {
        // Intentionally no UI banner here per request.
      }
      _lastSeenActivityMs = newestMs == 0 ? _lastSeenActivityMs : newestMs;
      await prefs2.setInt(key, _lastSeenActivityMs ?? 0);

      // Build signature to skip redundant rebuilds
      final actSig = items.map((i) => '${i['id']}_${i['status']}').join(',');
      if (actSig == _lastActivitySig && actSig.isNotEmpty) return;
      _lastActivitySig = actSig;

      if (mounted) {
        setState(() {
          if (currentDept.trim().isNotEmpty) {
            _userDepartment = currentDept.trim();
          }
          // Merge with existing Firestore items instead of clearing
          final Map<String, Map<String, dynamic>> activityMap = {};

          // Keep existing Firestore items (those with fs_id)
          for (final item in _recentActivity) {
            // Prefer stable server `id` when available so PHP items can enrich/overwrite
            // the same notification later (Firestore items often have fs_id + id).
            final key = item['id']?.toString() ??
                item['fs_id']?.toString() ??
                '${item['title']}_${item['time']}';
            // Only keep Firestore items, we'll merge PHP notifications below
            if (item['fs_id'] != null) {
              activityMap[key] = item;
            }
          }

          // Add/Update PHP notification items
          for (final item in items) {
            final key =
                item['id']?.toString() ?? '${item['title']}_${item['time']}';
            if (!activityMap.containsKey(key)) {
              activityMap[key] = item;
              continue;
            }

            // Merge: keep Firestore card identity, but enrich missing routing fields from PHP.
            final existing = activityMap[key]!;
            bool isEmptyVal(dynamic v) =>
                v == null || (v is String && v.trim().isEmpty);

            for (final entry in item.entries) {
              final k = entry.key;
              final v = entry.value;
              if (!existing.containsKey(k) || isEmptyVal(existing[k])) {
                if (!isEmptyVal(v)) {
                  existing[k] = v;
                }
              }
            }

            // Prefer PHP for these routing-critical fields when non-empty.
            for (final k in const [
              'endLocation',
              'end_location',
              'currentHolder',
              'current_holder',
              'recipientDepartment',
              'recipient_department',
              'trackingId',
              'tracking_id',
              'mobileTimestamp',
              'mobile_timestamp',
              'docHash',
              'doc_hash',
            ]) {
              if (item.containsKey(k) && !isEmptyVal(item[k])) {
                existing[k] = item[k];
              }
            }

            activityMap[key] = existing;
          }

          _recentActivity.clear();
          _recentActivity.addAll(activityMap.values);

          // Sort by creation time (newest first)
          _recentActivity.sort((a, b) {
            final aMs = a['createdAtMs'] as int? ?? 0;
            final bMs = b['createdAtMs'] as int? ?? 0;
            if (aMs == 0 && bMs == 0) return 0;
            return bMs.compareTo(aMs);
          });

          // Auto-hide completed items on the dashboard
          _recentActivity.removeWhere((m) {
            final s = (m['status'] ?? '').toString().trim().toLowerCase();
            return s == 'completed' || s == 'archived' || s == 'approved';
          });

          _lastActivityRefresh =
              TimeOfDay.fromDateTime(DateTime.now()).format(context);
        });
      }
    } catch (_) {
      // silent fail to avoid UI disruption
    }
  }

  IconData _iconForType(String? t) {
    switch (t) {
      case 'upload':
        return Icons.upload_file;
      case 'document_upload':
        return Icons.description_outlined;
      case 'approval':
        return Icons.approval;
      case 'approval_required':
        return Icons.rule_folder_outlined;
      case 'comment':
        return Icons.comment;
      case 'archive':
        return Icons.archive;
      case 'system_update':
        return Icons.system_update_alt;
      default:
        return Icons.notifications;
    }
  }

  Widget _buildKPITiles() {
    if (_kpiData.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          crossAxisSpacing: 10,
          mainAxisSpacing: 10,
          // Make tiles taller to avoid bottom overflow on small screens
          childAspectRatio: 1.1,
        ),
        itemCount: _kpiData.length,
        itemBuilder: (context, index) {
          final kpi = _kpiData[index];
          return _buildKPITile(
            title: kpi['title'],
            count: kpi['count'],
            icon: kpi['icon'],
            color: kpi['color'],
          );
        },
      ),
    );
  }

  Widget _buildKPITile({
    required String title,
    required int count,
    required IconData icon,
    required Color color,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(18),
        border:
            isDark ? Border.all(color: Colors.white.withOpacity(0.06)) : null,
        boxShadow: isDark
            ? null
            : [
                BoxShadow(
                  color: color.withOpacity(0.08),
                  blurRadius: 14,
                  offset: const Offset(0, 4),
                ),
                BoxShadow(
                  color: Colors.black.withOpacity(0.03),
                  blurRadius: 4,
                  offset: const Offset(0, 1),
                ),
              ],
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          onTap: () {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text('Viewing $title documents')),
            );
          },
          borderRadius: BorderRadius.circular(18),
          child: Container(
            padding: const EdgeInsets.all(14),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: color.withOpacity(0.1),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(icon, color: color, size: 22),
                ),
                const SizedBox(height: 10),
                Text(
                  count.toString(),
                  style: TextStyle(
                    fontFamily: 'Poppins',
                    fontSize: 24,
                    fontWeight: FontWeight.w700,
                    color: color,
                    letterSpacing: -0.5,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  title,
                  style: TextStyle(
                    fontFamily: 'Poppins',
                    fontSize: 11,
                    color: Theme.of(context)
                        .colorScheme
                        .onSurface
                        .withOpacity(0.6),
                    fontWeight: FontWeight.w500,
                    height: 1.3,
                  ),
                  textAlign: TextAlign.center,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildSectionHeader(String title, {VoidCallback? onTap}) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      child: Row(
        children: [
          Container(
            width: 4,
            height: 20,
            decoration: BoxDecoration(
              color: const Color(0xFF6868AC),
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              title,
              style: TextStyle(
                fontFamily: 'Poppins',
                fontSize: 17,
                fontWeight: FontWeight.w700,
                color: Theme.of(context).colorScheme.onSurface.withOpacity(0.9),
                letterSpacing: -0.2,
              ),
            ),
          ),
          if (title == 'Recent Documents' && _lastActivityRefresh != null)
            Padding(
              padding: const EdgeInsets.only(right: 8.0),
              child: Text(
                'Updated ${_lastActivityRefresh!}',
                style: TextStyle(
                  fontFamily: 'Poppins',
                  fontSize: 11,
                  fontWeight: FontWeight.w500,
                  color:
                      Theme.of(context).colorScheme.onSurface.withOpacity(0.45),
                ),
              ),
            ),
          if (title == 'Recent Documents' && _debugToolsEnabled)
            IconButton(
              tooltip: 'Open Debug Center',
              onPressed: _openDebugCenterDialog,
              icon: const Icon(Icons.bug_report_outlined,
                  color: Color(0xFF6868AC), size: 20),
            ),
          if (onTap != null)
            TextButton(
              onPressed: onTap,
              style: TextButton.styleFrom(
                foregroundColor: const Color(0xFF6868AC),
                textStyle: const TextStyle(
                  fontFamily: 'Poppins',
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                ),
              ),
              child: const Text('See All'),
            ),
        ],
      ),
    );
  }

  Widget _buildRecentActivityFeed() {
    // Show only first 3 items, rest available via "See All"
    final displayItems = _getReceivedActivityItems().take(3).toList();

    if (displayItems.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  decoration: BoxDecoration(
                    color: const Color(0xFF6868AC).withOpacity(0.08),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  padding: const EdgeInsets.all(10),
                  child: const Icon(Icons.notifications_none,
                      color: Color(0xFF6868AC), size: 22),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'No recent documents\nPull down to refresh',
                    style: TextStyle(
                        fontFamily: 'Poppins',
                        fontSize: 14,
                        fontWeight: FontWeight.w500,
                        color: Theme.of(context)
                            .colorScheme
                            .onSurface
                            .withOpacity(0.5)),
                  ),
                ),
              ],
            ),
          ],
        ),
      );
    }

    return ListView.separated(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 16),
      itemCount: displayItems.length,
      separatorBuilder: (context, index) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final activity = displayItems[index];
        return _buildActivityItem(
          title: activity['title'],
          subtitle: activity['subtitle'],
          time: activity['time'],
          icon: activity['icon'],
          onTap: () => _showActivityDetails(activity),
          onLongPress: () => _confirmDeleteActivity(activity),
          fileUrl: (activity['fileUrl'] ?? '').toString(),
          id: activity['id'] is int
              ? activity['id'] as int
              : int.tryParse('${activity['id']}'),
          status: (activity['status'] ?? '').toString(),
          recipientDepartment:
              (activity['recipientDepartment'] ?? '').toString(),
          fsId: (activity['fs_id'] ?? '').toString(),
          docType: (activity['type'] ?? '').toString(),
          mobileTimestamp: activity['mobileTimestamp']?.toString(),
          docHash: activity['docHash']?.toString(),
          trackingId: activity['trackingId']?.toString(),
          storedFilePath: activity['filePath']?.toString(),
          isEncrypted: _isActivityEncrypted(activity),
          endLocation:
              (activity['endLocation'] ?? activity['end_location'])?.toString(),
          currentHolder:
              (activity['currentHolder'] ?? activity['current_holder'])
                  ?.toString(),
        );
      },
    );
  }

  bool _sameDept(String? a, String? b) {
    String norm(String? v) => (v ?? '').trim().replaceAll(RegExp(r'\s+'), ' ');
    final aa = norm(a).toUpperCase();
    final bb = norm(b).toUpperCase();
    if (aa.isEmpty || bb.isEmpty) return false;
    return aa == bb;
  }

  Future<void> _captureAndUploadFinalDocument({
    required int trackingId,
    int? activityId,
  }) async {
    // Show simplified multi-capture dialog
    final List<File>? capturedImages = await showDialog<List<File>>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => _MultiImageCaptureDialog(trackingId: trackingId),
    );

    if (capturedImages == null || capturedImages.isEmpty || !mounted) return;

    // Materialize captured images into unique temp files so we never
    // reference stale/reused scanner paths (prevents wrong-page updates).
    final materialized = await _materializeCapturedImages(capturedImages);
    if (materialized.isEmpty || !mounted) return;

    try {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
                'Running OCR + converting ${materialized.length} page(s) to PDF...'),
            duration: const Duration(seconds: 10),
          ),
        );
      }

      // OCR per page so each page can embed its own text into the PDF
      final List<String> pageTexts = <String>[];
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      try {
        for (final imgFile in materialized) {
          try {
            final input = InputImage.fromFile(imgFile);
            final recognized = await recognizer.processImage(input);
            final t = (recognized.text).trim();
            pageTexts.add(t.isNotEmpty ? t : 'No text detected');
          } catch (_) {
            pageTexts.add('No text detected');
          }
        }
      } finally {
        await recognizer.close();
      }

      // Generate PDF bytes (final document should be image-only; OCR is stored separately for search)
      final pdfBytes = await _generatePdfFromImages(
        materialized,
        pageTexts: pageTexts,
        embedOcrTextInPdf: false,
      );

      // Save PDF to temp file for preview + upload
      final tempDir = await getTemporaryDirectory();
      final pdfPath =
          '${tempDir.path}/final_doc_${trackingId}_${DateTime.now().millisecondsSinceEpoch}.pdf';
      final pdfFile = File(pdfPath);
      await pdfFile.writeAsBytes(pdfBytes);

      // Preview the generated PDF (avoid printing PdfPreview cache issues)
      final List<String>? editedTexts = await showDialog<List<String>>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => _PdfPreviewDialog(
          pdfFilePath: pdfFile.path,
          pageCount: materialized.length,
          pageTexts: pageTexts,
        ),
      );

      if (editedTexts == null || editedTexts.isEmpty || !mounted) {
        // User cancelled - clean up temp files
        for (final f in materialized) {
          try {
            f.delete();
          } catch (_) {}
        }
        try {
          pdfFile.delete();
        } catch (_) {}
        return;
      }

      final List<String> finalPageTexts = editedTexts;

      final root = await _getServerRoot();
      if (root == null || root.trim().isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Server not configured')),
          );
        }
        return;
      }

      // Upload PDF to tracking.php so archiving uses the same PDF content
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php');
      final req = http.MultipartRequest('POST', uri);
      req.fields['action'] = 'update_final_document';
      req.fields['doc_id'] = trackingId.toString();
      req.fields['page_count'] = materialized.length.toString();
      req.fields['ocr_content'] = finalPageTexts.join('\n\n').trim();

      // Send per-page OCR for multi-page search support
      for (int pageIdx = 0; pageIdx < finalPageTexts.length; pageIdx++) {
        req.fields['ocr_pages[$pageIdx]'] = finalPageTexts[pageIdx].trim();
      }

      // Provide metadata for server-side naming
      try {
        final meta = await _fetchRoutingMeta(trackingId: trackingId.toString());
        final type = (meta?['type'] ?? '').toString().trim();
        final date = (meta?['date_submitted'] ?? meta?['created_at'] ?? '')
            .toString()
            .trim();
        if (type.isNotEmpty) req.fields['doc_type'] = type;
        if (date.isNotEmpty) req.fields['doc_date'] = date;
      } catch (_) {}
      req.fields['debug'] = '1';

      // Upload PDF file
      final safeType = _safeFilePart(typeCtrlValue(req.fields['doc_type']));
      final stamp =
          _safeFilePart((req.fields['doc_date'] ?? '').split(' ').first);
      final uploadName =
          'Final_${safeType.isNotEmpty ? safeType : 'Document'}_${stamp.isNotEmpty ? stamp : _safeFilePart(DateTime.now().toIso8601String().split('T').first)}.pdf';
      req.files.add(await http.MultipartFile.fromPath(
        'file',
        pdfFile.path,
        filename: uploadName,
      ));

      final streamed = await req.send().timeout(const Duration(seconds: 60));
      final body = await streamed.stream.bytesToString();
      bool ok = streamed.statusCode < 400;
      String msg = 'Document completed successfully';
      try {
        final decoded = jsonDecode(body);
        if (decoded is Map) {
          ok = ok && (decoded['success'] == true);
          msg = decoded['message']?.toString() ??
              decoded['error']?.toString() ??
              msg;
        }
      } catch (_) {
        if (!ok && body.trim().isNotEmpty) msg = body.trim();
      }

      // Clean up temp files
      for (final f in materialized) {
        try {
          f.delete();
        } catch (_) {}
      }
      try {
        pdfFile.delete();
      } catch (_) {}

      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ok ? msg : 'Update failed: $msg'),
          backgroundColor: ok ? Colors.green : Colors.red,
        ),
      );

      if (ok) {
        // Best-effort: mark/delete the notification and remove this item locally
        if (activityId != null && activityId > 0) {
          try {
            await _updateNotificationStatus(activityId, 'completed');
          } catch (_) {}
          try {
            await _deleteNotificationById(activityId);
          } catch (_) {}

          if (mounted) {
            setState(() {
              _recentActivity.removeWhere((m) {
                final mid = m['id'];
                if (mid == null) return false;
                return mid.toString() == activityId.toString();
              });
            });
          }
        }

        // Also remove by tracking id (covers cases where id is tracking.id)
        if (mounted) {
          setState(() {
            _recentActivity.removeWhere((m) {
              final tid =
                  (m['trackingId'] ?? m['tracking_id'] ?? '').toString();
              return tid.isNotEmpty && tid == trackingId.toString();
            });
          });
        }
        await _fetchRecentActivity();
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Update error: $e')),
      );
    }
  }

  /// Capture and upload a returned document — replaces file + OCR but does NOT
  /// mark the document as Completed. Returns `true` if upload succeeded so the
  /// caller can open the Route dialog afterwards.
  Future<bool> _captureAndUploadReturnedDocument({
    required int trackingId,
    int? activityId,
  }) async {
    // Show multi-capture dialog (same as final capture)
    final List<File>? capturedImages = await showDialog<List<File>>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => _MultiImageCaptureDialog(trackingId: trackingId),
    );

    if (capturedImages == null || capturedImages.isEmpty || !mounted) {
      return false;
    }

    final materialized = await _materializeCapturedImages(capturedImages);
    if (materialized.isEmpty || !mounted) return false;

    try {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
                'Running OCR + converting ${materialized.length} page(s) to PDF...'),
            duration: const Duration(seconds: 10),
          ),
        );
      }

      // OCR per page using Google ML Kit
      final List<String> pageTexts = <String>[];
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      try {
        for (final imgFile in materialized) {
          try {
            final input = InputImage.fromFile(imgFile);
            final recognized = await recognizer.processImage(input);
            final t = (recognized.text).trim();
            pageTexts.add(t.isNotEmpty ? t : 'No text detected');
          } catch (_) {
            pageTexts.add('No text detected');
          }
        }
      } finally {
        await recognizer.close();
      }

      // Generate PDF
      final pdfBytes = await _generatePdfFromImages(
        materialized,
        pageTexts: pageTexts,
        embedOcrTextInPdf: false,
      );

      final tempDir = await getTemporaryDirectory();
      final pdfPath =
          '${tempDir.path}/returned_doc_${trackingId}_${DateTime.now().millisecondsSinceEpoch}.pdf';
      final pdfFile = File(pdfPath);
      await pdfFile.writeAsBytes(pdfBytes);

      // Preview + edit OCR
      final List<String>? editedTexts = await showDialog<List<String>>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => _PdfPreviewDialog(
          pdfFilePath: pdfFile.path,
          pageCount: materialized.length,
          pageTexts: pageTexts,
        ),
      );

      if (editedTexts == null || editedTexts.isEmpty || !mounted) {
        for (final f in materialized) {
          try {
            f.delete();
          } catch (_) {}
        }
        try {
          pdfFile.delete();
        } catch (_) {}
        return false;
      }

      final List<String> finalPageTexts = editedTexts;

      final root = await _getServerRoot();
      if (root == null || root.trim().isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Server not configured')),
          );
        }
        return false;
      }

      // Upload using update_returned_document action (does NOT mark Completed)
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php');
      final req = http.MultipartRequest('POST', uri);
      req.fields['action'] = 'update_returned_document';
      req.fields['doc_id'] = trackingId.toString();
      req.fields['page_count'] = materialized.length.toString();
      req.fields['ocr_content'] = finalPageTexts.join('\n\n').trim();

      for (int pageIdx = 0; pageIdx < finalPageTexts.length; pageIdx++) {
        req.fields['ocr_pages[$pageIdx]'] = finalPageTexts[pageIdx].trim();
      }

      try {
        final meta = await _fetchRoutingMeta(trackingId: trackingId.toString());
        final type = (meta?['type'] ?? '').toString().trim();
        final date = (meta?['date_submitted'] ?? meta?['created_at'] ?? '')
            .toString()
            .trim();
        if (type.isNotEmpty) req.fields['doc_type'] = type;
        if (date.isNotEmpty) req.fields['doc_date'] = date;
      } catch (_) {}
      req.fields['debug'] = '1';

      final safeType = _safeFilePart(typeCtrlValue(req.fields['doc_type']));
      final stamp =
          _safeFilePart((req.fields['doc_date'] ?? '').split(' ').first);
      final uploadName =
          'Returned_${safeType.isNotEmpty ? safeType : 'Document'}_${stamp.isNotEmpty ? stamp : _safeFilePart(DateTime.now().toIso8601String().split('T').first)}.pdf';
      req.files.add(await http.MultipartFile.fromPath(
        'file',
        pdfFile.path,
        filename: uploadName,
      ));

      final streamed = await req.send().timeout(const Duration(seconds: 60));
      final body = await streamed.stream.bytesToString();
      bool ok = streamed.statusCode < 400;
      String msg = 'Returned document updated successfully';
      try {
        final decoded = jsonDecode(body);
        if (decoded is Map) {
          ok = ok && (decoded['success'] == true);
          msg = decoded['message']?.toString() ??
              decoded['error']?.toString() ??
              msg;
        }
      } catch (_) {
        if (!ok && body.trim().isNotEmpty) msg = body.trim();
      }

      // Clean up temp files
      for (final f in materialized) {
        try {
          f.delete();
        } catch (_) {}
      }
      try {
        pdfFile.delete();
      } catch (_) {}

      if (!mounted) return false;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ok ? msg : 'Update failed: $msg'),
          backgroundColor: ok ? Colors.green : Colors.red,
        ),
      );

      return ok;
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Update error: $e')),
        );
      }
      return false;
    }
  }

  /// Capture new pages and append them to the existing main document PDF.
  /// Downloads the current PDF from the server, rasterizes its pages,
  /// captures new pages via camera + OCR, combines all into one PDF,
  /// and uploads the combined PDF as a replacement (no status change).
  Future<bool> _captureAndAppendToDocument({
    required int trackingId,
  }) async {
    final root = await _getServerRoot();
    if (root == null || root.trim().isEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Server not configured')),
        );
      }
      return false;
    }

    // --- Step 1: Download existing PDF from server ---
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Row(children: [
            SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2)),
            SizedBox(width: 12),
            Expanded(child: Text('Downloading existing document...')),
          ]),
          duration: Duration(seconds: 30),
        ),
      );
    }

    Uint8List? existingPdfBytes;
    try {
      final downloadUrl =
          '$root/lib/OCR(UPDATED)/download.php?id=$trackingId&inline=1&t=${DateTime.now().millisecondsSinceEpoch}';
      final resp = await http
          .get(Uri.parse(downloadUrl))
          .timeout(const Duration(seconds: 30));
      if (resp.statusCode >= 200 &&
          resp.statusCode < 300 &&
          resp.bodyBytes.length > 100) {
        existingPdfBytes = resp.bodyBytes;
      }
    } catch (_) {
      // If download fails, we'll just capture new pages without existing ones
    }

    if (mounted) ScaffoldMessenger.of(context).hideCurrentSnackBar();

    // --- Step 2: Rasterize existing PDF pages to temp image files ---
    final List<File> existingPageFiles = <File>[];
    final List<String> existingPageTexts = <String>[];
    if (existingPdfBytes != null && existingPdfBytes.isNotEmpty) {
      try {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Row(children: [
                SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2)),
                SizedBox(width: 12),
                Expanded(child: Text('Processing existing pages...')),
              ]),
              duration: Duration(seconds: 30),
            ),
          );
        }

        final tempDir = await getTemporaryDirectory();
        int pageIdx = 0;
        await for (final page in Printing.raster(existingPdfBytes, dpi: 200)) {
          final pngBytes = await page.toPng();
          final tmpFile = File(
              '${tempDir.path}/existing_page_${pageIdx}_${DateTime.now().millisecondsSinceEpoch}.png');
          await tmpFile.writeAsBytes(pngBytes, flush: true);
          existingPageFiles.add(tmpFile);
          existingPageTexts
              .add(''); // OCR already stored server-side for existing pages
          pageIdx++;
        }

        if (mounted) ScaffoldMessenger.of(context).hideCurrentSnackBar();
      } catch (e) {
        if (mounted) ScaffoldMessenger.of(context).hideCurrentSnackBar();
        // Continue without existing pages if rasterization fails
      }
    }

    // --- Step 3: Capture new pages via camera ---
    final List<File>? capturedImages = await showDialog<List<File>>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => _MultiImageCaptureDialog(trackingId: trackingId),
    );

    if (capturedImages == null || capturedImages.isEmpty || !mounted) {
      // Clean up existing page temp files
      for (final f in existingPageFiles) {
        try {
          f.delete();
        } catch (_) {}
      }
      return false;
    }

    final materialized = await _materializeCapturedImages(capturedImages);
    if (materialized.isEmpty || !mounted) {
      for (final f in existingPageFiles) {
        try {
          f.delete();
        } catch (_) {}
      }
      return false;
    }

    try {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content:
                Text('Running OCR on ${materialized.length} new page(s)...'),
            duration: const Duration(seconds: 10),
          ),
        );
      }

      // --- Step 4: OCR new pages ---
      final List<String> newPageTexts = <String>[];
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      try {
        for (final imgFile in materialized) {
          try {
            final input = InputImage.fromFile(imgFile);
            final recognized = await recognizer.processImage(input);
            final t = (recognized.text).trim();
            newPageTexts.add(t.isNotEmpty ? t : 'No text detected');
          } catch (_) {
            newPageTexts.add('No text detected');
          }
        }
      } finally {
        await recognizer.close();
      }

      // --- Step 5: Combine existing + new pages into one PDF ---
      final allImages = <File>[...existingPageFiles, ...materialized];
      final allTexts = <String>[...existingPageTexts, ...newPageTexts];

      final pdfBytes = await _generatePdfFromImages(
        allImages,
        pageTexts: allTexts,
        embedOcrTextInPdf: false,
      );

      final tempDir = await getTemporaryDirectory();
      final pdfPath =
          '${tempDir.path}/appended_doc_${trackingId}_${DateTime.now().millisecondsSinceEpoch}.pdf';
      final pdfFile = File(pdfPath);
      await pdfFile.writeAsBytes(pdfBytes);

      // --- Step 6: Preview + edit OCR (only new pages' OCR) ---
      final List<String>? editedTexts = await showDialog<List<String>>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => _PdfPreviewDialog(
          pdfFilePath: pdfFile.path,
          pageCount: allImages.length,
          pageTexts: allTexts,
        ),
      );

      if (editedTexts == null || editedTexts.isEmpty || !mounted) {
        for (final f in existingPageFiles) {
          try {
            f.delete();
          } catch (_) {}
        }
        for (final f in materialized) {
          try {
            f.delete();
          } catch (_) {}
        }
        try {
          pdfFile.delete();
        } catch (_) {}
        return false;
      }

      final List<String> finalPageTexts = editedTexts;

      // --- Step 7: Upload combined PDF using update_returned_document (no status change) ---
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php');
      final req = http.MultipartRequest('POST', uri);
      req.fields['action'] = 'update_returned_document';
      req.fields['doc_id'] = trackingId.toString();
      req.fields['page_count'] = allImages.length.toString();
      req.fields['ocr_content'] = finalPageTexts.join('\n\n').trim();

      for (int i = 0; i < finalPageTexts.length; i++) {
        req.fields['ocr_pages[$i]'] = finalPageTexts[i].trim();
      }

      try {
        final meta = await _fetchRoutingMeta(trackingId: trackingId.toString());
        final type = (meta?['type'] ?? '').toString().trim();
        final date = (meta?['date_submitted'] ?? meta?['created_at'] ?? '')
            .toString()
            .trim();
        if (type.isNotEmpty) req.fields['doc_type'] = type;
        if (date.isNotEmpty) req.fields['doc_date'] = date;
      } catch (_) {}
      req.fields['debug'] = '1';

      final safeType = _safeFilePart(typeCtrlValue(req.fields['doc_type']));
      final stamp =
          _safeFilePart((req.fields['doc_date'] ?? '').split(' ').first);
      final uploadName =
          'Appended_${safeType.isNotEmpty ? safeType : 'Document'}_${stamp.isNotEmpty ? stamp : _safeFilePart(DateTime.now().toIso8601String().split('T').first)}.pdf';
      req.files.add(await http.MultipartFile.fromPath(
        'file',
        pdfFile.path,
        filename: uploadName,
      ));

      final streamed = await req.send().timeout(const Duration(seconds: 60));
      final body = await streamed.stream.bytesToString();
      bool ok = streamed.statusCode < 400;
      String msg = 'Pages appended successfully';
      try {
        final decoded = jsonDecode(body);
        if (decoded is Map) {
          ok = ok && (decoded['success'] == true);
          msg = decoded['message']?.toString() ??
              decoded['error']?.toString() ??
              msg;
        }
      } catch (_) {
        if (!ok && body.trim().isNotEmpty) msg = body.trim();
      }

      // Clean up all temp files
      for (final f in existingPageFiles) {
        try {
          f.delete();
        } catch (_) {}
      }
      for (final f in materialized) {
        try {
          f.delete();
        } catch (_) {}
      }
      try {
        pdfFile.delete();
      } catch (_) {}

      if (!mounted) return false;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ok ? msg : 'Append failed: $msg'),
          backgroundColor: ok ? Colors.green : Colors.red,
        ),
      );

      if (ok) {
        _fetchRecentActivity();
      }

      return ok;
    } catch (e) {
      // Clean up on error
      for (final f in existingPageFiles) {
        try {
          f.delete();
        } catch (_) {}
      }
      for (final f in materialized) {
        try {
          f.delete();
        } catch (_) {}
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Append error: $e')),
        );
      }
      return false;
    }
  }

  String typeCtrlValue(String? v) {
    final s = (v ?? '').trim();
    return s.isEmpty ? 'Document' : s;
  }

  Future<void> _captureAndUploadFinalDocumentByIdentity({
    String? mobileTimestamp,
    String? docHash,
    int? activityId,
  }) async {
    final mt = (mobileTimestamp ?? '').trim();
    final dh = (docHash ?? '').trim();
    if (mt.isEmpty && dh.isEmpty) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Missing document identity')),
        );
      }
      return;
    }

    // Capture pages for the final document
    final List<File>? capturedImages = await showDialog<List<File>>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => const _MultiImageCaptureDialog(trackingId: 0),
    );

    if (capturedImages == null || capturedImages.isEmpty || !mounted) return;

    final materialized = await _materializeCapturedImages(capturedImages);
    if (materialized.isEmpty || !mounted) return;

    try {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
                'Running OCR + converting ${materialized.length} page(s) to PDF...'),
            duration: const Duration(seconds: 10),
          ),
        );
      }

      final List<String> pageTexts = <String>[];
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      try {
        for (final imgFile in materialized) {
          try {
            final input = InputImage.fromFile(imgFile);
            final recognized = await recognizer.processImage(input);
            final t = (recognized.text).trim();
            pageTexts.add(t.isNotEmpty ? t : 'No text detected');
          } catch (_) {
            pageTexts.add('No text detected');
          }
        }
      } finally {
        await recognizer.close();
      }

      final pdfBytes = await _generatePdfFromImages(
        materialized,
        pageTexts: pageTexts,
        embedOcrTextInPdf: false,
      );

      final tempDir = await getTemporaryDirectory();
      final pdfPath =
          '${tempDir.path}/final_doc_identity_${DateTime.now().millisecondsSinceEpoch}.pdf';
      final pdfFile = File(pdfPath);
      await pdfFile.writeAsBytes(pdfBytes);

      final List<String>? editedTexts = await showDialog<List<String>>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => _PdfPreviewDialog(
          pdfFilePath: pdfFile.path,
          pageCount: materialized.length,
          pageTexts: pageTexts,
        ),
      );

      if (editedTexts == null || editedTexts.isEmpty || !mounted) {
        for (final f in materialized) {
          try {
            f.delete();
          } catch (_) {}
        }
        try {
          pdfFile.delete();
        } catch (_) {}
        return;
      }

      final List<String> finalPageTexts = editedTexts;

      final root = await _getServerRoot();
      if (root == null || root.trim().isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Server not configured')),
          );
        }
        return;
      }

      final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php');
      final req = http.MultipartRequest('POST', uri);
      req.fields['action'] = 'update_final_document';
      if (mt.isNotEmpty) req.fields['mobile_timestamp'] = mt;
      if (dh.isNotEmpty) req.fields['doc_hash'] = dh;
      req.fields['page_count'] = materialized.length.toString();
      req.fields['ocr_content'] = finalPageTexts.join('\n\n').trim();

      for (int pageIdx = 0; pageIdx < finalPageTexts.length; pageIdx++) {
        req.fields['ocr_pages[$pageIdx]'] = finalPageTexts[pageIdx].trim();
      }

      try {
        final meta = await _fetchRoutingMeta(
          trackingId: null,
          mobileTimestamp: mt,
          docHash: dh,
        );
        final type = (meta?['type'] ?? '').toString().trim();
        final date = (meta?['date_submitted'] ?? meta?['created_at'] ?? '')
            .toString()
            .trim();
        if (type.isNotEmpty) req.fields['doc_type'] = type;
        if (date.isNotEmpty) req.fields['doc_date'] = date;
      } catch (_) {}
      req.fields['debug'] = '1';

      req.files.add(await http.MultipartFile.fromPath(
        'file',
        pdfFile.path,
        filename:
            'Final_${_safeFilePart(typeCtrlValue(req.fields['doc_type']))}_${_safeFilePart((req.fields['doc_date'] ?? '').split(' ').first)}.pdf',
      ));

      final streamed = await req.send().timeout(const Duration(seconds: 60));
      final body = await streamed.stream.bytesToString();
      bool ok = streamed.statusCode < 400;
      String msg = 'Document completed successfully';
      try {
        final decoded = jsonDecode(body);
        if (decoded is Map) {
          ok = ok && (decoded['success'] == true);
          msg = decoded['message']?.toString() ??
              decoded['error']?.toString() ??
              msg;
        }
      } catch (_) {
        if (!ok && body.trim().isNotEmpty) msg = body.trim();
      }

      for (final f in materialized) {
        try {
          f.delete();
        } catch (_) {}
      }
      try {
        pdfFile.delete();
      } catch (_) {}

      if (!mounted) return;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ok ? msg : 'Update failed: $msg'),
          backgroundColor: ok ? Colors.green : Colors.red,
        ),
      );

      if (ok) {
        if (activityId != null && activityId > 0) {
          try {
            await _updateNotificationStatus(activityId, 'completed');
          } catch (_) {}
          try {
            await _deleteNotificationById(activityId);
          } catch (_) {}

          if (mounted) {
            setState(() {
              _recentActivity.removeWhere((m) {
                final mid = m['id'];
                if (mid == null) return false;
                return mid.toString() == activityId.toString();
              });
            });
          }
        }

        if (mounted) {
          setState(() {
            _recentActivity.removeWhere((m) {
              final mts = (m['mobileTimestamp'] ?? m['mobile_timestamp'] ?? '')
                  .toString()
                  .trim();
              final dhs =
                  (m['docHash'] ?? m['doc_hash'] ?? '').toString().trim();
              if (mt.isNotEmpty && mts.isNotEmpty && mt == mts) return true;
              if (dh.isNotEmpty && dhs.isNotEmpty && dh == dhs) return true;
              return false;
            });
          });
        }

        await _fetchRecentActivity();
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Update error: $e')),
      );
    }
  }

  /// Generate PDF from images using the same approach as doc-scanner project
  Future<Uint8List> _generatePdfFromImages(
    List<File> images, {
    List<String>? pageTexts,
    bool embedOcrTextInPdf = true,
  }) async {
    final doc = pw.Document(pageMode: PdfPageMode.outlines);

    for (int i = 0; i < images.length; i++) {
      final imageFile = images[i];
      // Read image bytes asynchronously to avoid UI freeze
      final imageBytes = await imageFile.readAsBytes();
      final normalizedBytes = _normalizeImageBytesForPdf(imageBytes);
      if (normalizedBytes == null) {
        // Skip broken images to avoid pdf numeric assertions (NaN/Infinity)
        doc.addPage(
          pw.Page(
            pageTheme: pw.PageTheme(
              pageFormat: PdfPageFormat.a4.copyWith(
                marginBottom: 20,
                marginLeft: 20,
                marginRight: 20,
                marginTop: 20,
              ),
              orientation: pw.PageOrientation.portrait,
            ),
            build: (context) {
              return pw.Center(
                child: pw.Text('Page ${i + 1}: image could not be processed'),
              );
            },
          ),
        );
        continue;
      }

      final pdfImage = pw.MemoryImage(normalizedBytes);
      final String ocrText =
          (embedOcrTextInPdf && pageTexts != null && i < pageTexts.length)
              ? pageTexts[i].trim()
              : '';

      doc.addPage(
        pw.Page(
          pageTheme: pw.PageTheme(
            pageFormat: PdfPageFormat.a4.copyWith(
              marginBottom: 20,
              marginLeft: 20,
              marginRight: 20,
              marginTop: 20,
            ),
            orientation: pw.PageOrientation.portrait,
          ),
          build: (context) {
            return pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.stretch,
              children: [
                pw.Expanded(
                  child: pw.Center(
                    child: pw.Image(pdfImage, fit: pw.BoxFit.contain),
                  ),
                ),
                if (embedOcrTextInPdf && ocrText.isNotEmpty) ...[
                  pw.SizedBox(height: 12),
                  pw.Container(
                    padding: const pw.EdgeInsets.all(10),
                    decoration: pw.BoxDecoration(
                      border: pw.Border.all(color: PdfColors.grey300),
                      borderRadius: pw.BorderRadius.circular(6),
                    ),
                    child: pw.Text(
                      ocrText,
                      style: const pw.TextStyle(fontSize: 9),
                    ),
                  ),
                ],
              ],
            );
          },
        ),
      );
    }

    return await doc.save();
  }

  Future<List<File>> _materializeCapturedImages(List<File> captured) async {
    try {
      final tmp = await getTemporaryDirectory();
      final out = <File>[];
      for (final f in captured) {
        try {
          final bytes = await f.readAsBytes();
          if (bytes.isEmpty) continue;
          final name =
              'cap_${DateTime.now().millisecondsSinceEpoch}_${out.length}.jpg';
          final target = File('${tmp.path}/$name');
          await target.writeAsBytes(bytes, flush: true);
          out.add(target);
        } catch (_) {
          // ignore per-file
        }
      }
      return out;
    } catch (_) {
      return const <File>[];
    }
  }

  Uint8List? _normalizeImageBytesForPdf(Uint8List bytes) {
    try {
      final decoded = img.decodeImage(bytes);
      if (decoded == null) return null;
      final baked = img.bakeOrientation(decoded);
      if (baked.width <= 0 || baked.height <= 0) return null;
      // Encode as JPEG to keep pdf image decoder stable.
      return Uint8List.fromList(img.encodeJpg(baked, quality: 92));
    } catch (_) {
      return null;
    }
  }

  // ============ DOCUMENT ACTION DIALOGS ============

  Future<String?> _resolveTrackingIdForAction({
    required String actionLabel,
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
  }) async {
    String? normalize(String? v) {
      if (v == null) return null;
      final t = v.trim();
      return t.isEmpty ? null : t;
    }

    final tId = normalize(trackingId);
    final mt = normalize(mobileTimestamp);
    final dh = normalize(docHash);
    final fp = normalize(filePath);

    // Only accept numeric tracking ids here. Non-numeric values (e.g. doc refs)
    // will break backend actions that require a real tracking.id.
    if (tId != null) {
      final parsed = int.tryParse(tId);
      if (parsed != null && parsed > 0) return tId;
    }
    if (mt == null && dh == null && fp == null) return null;

    final root = await _getServerRoot();
    if (root == null) return null;

    final uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
      queryParameters: {
        'action': 'resolve_identity',
        if (mt != null) 'mobile_timestamp': mt,
        if (dh != null) 'doc_hash': dh,
        if (fp != null) 'file_path': fp,
      },
    );

    try {
      final resp = await http.get(uri);
      if (resp.statusCode != 200) {
        return null;
      }
      final data = jsonDecode(resp.body);
      String? resolved;
      if (data is Map) {
        if (data['tracking_id'] != null) {
          resolved = data['tracking_id'].toString();
        } else if (data['doc'] is Map && (data['doc'] as Map)['id'] != null) {
          resolved = (data['doc'] as Map)['id'].toString();
        } else if (data['doc_id'] != null) {
          resolved = data['doc_id'].toString();
        }
      }
      return normalize(resolved);
    } catch (e) {
      return null;
    }
  }

  Future<void> _showCopyableDebugDialog({
    required String title,
    required String message,
    String? copyText,
  }) async {
    if (!mounted) return;
    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: SingleChildScrollView(
          child: SelectableText(message),
        ),
        actions: [
          if (copyText != null && copyText.trim().isNotEmpty)
            TextButton.icon(
              onPressed: () async {
                await Clipboard.setData(ClipboardData(text: copyText));
                if (ctx.mounted) {
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('Copied')),
                  );
                }
              },
              icon: const Icon(Icons.copy),
              label: const Text('Copy'),
            ),
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  String _buildDebugSummaryAndJson({
    required String action,
    required Map<String, dynamic> payload,
  }) {
    final now = DateTime.now().toIso8601String();
    final req = (payload['request'] is Map)
        ? Map<String, dynamic>.from(payload['request'])
        : <String, dynamic>{};
    final diagnosis = (payload['response'] is Map &&
            (payload['response'] as Map)['debug'] is Map &&
            ((payload['response'] as Map)['debug'] as Map)['diagnosis'] is Map)
        ? Map<String, dynamic>.from(
            ((payload['response'] as Map)['debug'] as Map)['diagnosis'] as Map)
        : <String, dynamic>{};

    final summary = <String>[
      'Action: ${action.toUpperCase()}',
      'From: ${(req['sender_department'] ?? req['receiver_department'] ?? 'n/a')}',
      'To: ${(req['receiver_department'] ?? 'n/a')}',
      'Tracking ID: ${(req['tracking_id'] ?? payload['tracking_id'] ?? 'n/a')}',
      'Expected Next: ${(diagnosis['expected_next_department'] ?? 'n/a')}',
      'Actual Next: ${(diagnosis['actual_next_department'] ?? ((payload['post_state'] as Map?)?['current_holder'] ?? 'n/a'))}',
      'Skip Detected: ${(diagnosis['skip_detected'] ?? false)}',
      'Captured At: $now',
    ].join('\n');

    final prettyJson = const JsonEncoder.withIndent('  ').convert(payload);
    return '$summary\n\nJSON:\n$prettyJson';
  }

  Future<void> _openDebugCenterDialog() async {
    if (!_debugToolsEnabled || !mounted) return;

    final routeDebug = _lastRouteDebug;
    final receiveDebug = _lastReceiveDebug;
    final hasAny = routeDebug != null || receiveDebug != null;

    if (!hasAny) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No debug traces captured yet.')),
      );
      return;
    }

    await showDialog<void>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Routing Debug Center'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (routeDebug != null)
              ElevatedButton.icon(
                onPressed: () {
                  final text = _buildDebugSummaryAndJson(
                    action: 'route',
                    payload: routeDebug,
                  );
                  _showCopyableDebugDialog(
                    title: 'Route Debug',
                    message: text,
                    copyText: text,
                  );
                },
                icon: const Icon(Icons.alt_route),
                label: const Text('Latest Route Debug'),
              ),
            if (receiveDebug != null)
              ElevatedButton.icon(
                onPressed: () {
                  final text = _buildDebugSummaryAndJson(
                    action: 'receive',
                    payload: receiveDebug,
                  );
                  _showCopyableDebugDialog(
                    title: 'Receive Debug',
                    message: text,
                    copyText: text,
                  );
                },
                icon: const Icon(Icons.download_done),
                label: const Text('Latest Receive Debug'),
              ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  /// Show Return Document Dialog
  Future<void> _showReturnDocumentDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String? senderDepartment,
    required String docTitle,
    int? activityId,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'Return',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text(
                  'Cannot return: missing tracking identity (trackingId/mobileTimestamp/docHash)')),
        );
      }
      return;
    }

    final reasonController = TextEditingController();
    final departments = [
      'CPDO',
      'GSO',
      'CBO',
      'CTO',
      'CACCO',
      'CADO',
      'CMO',
      'HR',
      'ACCOUNTING',
      'CAO',
    ];
    String? selectedDept = senderDepartment;

    final result = await showDialog<Map<String, String>>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Return Document'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Document: $docTitle',
                    style: const TextStyle(fontWeight: FontWeight.w500)),
                const SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: selectedDept,
                  decoration: const InputDecoration(
                    labelText: 'Return to Department',
                    border: OutlineInputBorder(),
                  ),
                  items: departments
                      .map((d) => DropdownMenuItem(value: d, child: Text(d)))
                      .toList(),
                  onChanged: (v) => setDialogState(() => selectedDept = v),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: reasonController,
                  decoration: const InputDecoration(
                    labelText: 'Reason for Return *',
                    hintText:
                        'e.g., Missing signature, incorrect information...',
                    border: OutlineInputBorder(),
                  ),
                  maxLines: 3,
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: () {
                if (reasonController.text.trim().isEmpty) {
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('Please provide a reason')),
                  );
                  return;
                }
                if (selectedDept == null) {
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('Please select a department')),
                  );
                  return;
                }
                Navigator.pop(ctx, {
                  'reason': reasonController.text.trim(),
                  'department': selectedDept!,
                });
              },
              style: ElevatedButton.styleFrom(backgroundColor: Colors.orange),
              child:
                  const Text('Return', style: TextStyle(color: Colors.white)),
            ),
          ],
        ),
      ),
    );

    if (result == null || !mounted) return;

    try {
      final root = await _getServerRoot();
      if (root == null) throw 'Server not configured';

      final response = await http.post(
        Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
        body: {
          'action': 'return_document',
          'tracking_id': resolvedTrackingId,
          'reason': result['reason']!,
          'returned_by': username,
          'returned_by_department': _userDepartment,
          'return_to_department': result['department']!,
        },
      );

      Map<String, dynamic> data;
      try {
        final decoded = jsonDecode(response.body);
        data = (decoded is Map<String, dynamic>)
            ? decoded
            : <String, dynamic>{'success': false, 'error': 'Invalid JSON'};
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Return failed: invalid server response ($e)'),
              backgroundColor: Colors.red,
            ),
          );
        }
        return;
      }
      if (data['success'] == true) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Document returned to ${result['department']}'),
              backgroundColor: Colors.green,
            ),
          );

          // Best-effort: mark/delete the originating notification so it disappears immediately.
          if (activityId != null && activityId > 0) {
            try {
              await _updateNotificationStatus(activityId, 'completed');
            } catch (_) {}
            try {
              await _deleteNotificationById(activityId);
            } catch (_) {}
          }

          setState(() {
            _recentActivity.removeWhere((m) {
              final mid = m['id'];
              if (activityId != null && mid != null) {
                if (mid.toString() == activityId.toString()) return true;
              }
              final tid =
                  (m['trackingId'] ?? m['tracking_id'] ?? '').toString().trim();
              if (resolvedTrackingId.isNotEmpty &&
                  tid == resolvedTrackingId.toString()) {
                return true;
              }
              final mts = (m['mobileTimestamp'] ?? m['mobile_timestamp'] ?? '')
                  .toString()
                  .trim();
              if ((mobileTimestamp ?? '').trim().isNotEmpty &&
                  mts == (mobileTimestamp ?? '').trim()) {
                return true;
              }
              final dhs =
                  (m['docHash'] ?? m['doc_hash'] ?? '').toString().trim();
              if ((docHash ?? '').trim().isNotEmpty &&
                  dhs == (docHash ?? '').trim()) {
                return true;
              }
              final fp =
                  (m['filePath'] ?? m['file_path'] ?? '').toString().trim();
              if ((filePath ?? '').trim().isNotEmpty &&
                  fp == (filePath ?? '').trim()) {
                return true;
              }
              return false;
            });
          });
          await _fetchRecentActivity();
        }
      } else {
        throw data['error'] ?? 'Failed to return document';
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  /// Show View Comments Dialog - displays all comments with add/edit/delete
  Future<void> _showViewCommentsDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String docTitle,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'View Comments',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('Cannot view comments: missing tracking identity')),
        );
      }
      return;
    }

    List<Map<String, dynamic>> comments = [];
    bool isLoading = true;
    String? errorMsg;
    final commentController = TextEditingController();

    Future<void> loadComments(StateSetter setDialogState) async {
      setDialogState(() {
        isLoading = true;
        errorMsg = null;
      });
      try {
        final root = await _getServerRoot();
        if (root == null) throw 'Server not configured';
        final response = await http.get(
          Uri.parse(
              '$root/lib/OCR(UPDATED)/api/document_actions.php?action=get_comments&tracking_id=$resolvedTrackingId'),
        );
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          setDialogState(() {
            comments = List<Map<String, dynamic>>.from(data['comments'] ?? []);
            isLoading = false;
          });
        } else {
          throw data['error'] ?? 'Failed to load comments';
        }
      } catch (e) {
        setDialogState(() {
          errorMsg = e.toString();
          isLoading = false;
        });
      }
    }

    Future<void> addComment(StateSetter setDialogState) async {
      final text = commentController.text.trim();
      if (text.isEmpty) return;
      try {
        final root = await _getServerRoot();
        if (root == null) throw 'Server not configured';
        final response = await http.post(
          Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
          body: {
            'action': 'add_comment',
            'tracking_id': resolvedTrackingId,
            'comment': text,
            'username': username,
            'department': _userDepartment,
          },
        );
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          commentController.clear();
          await loadComments(setDialogState);
        } else {
          throw data['error'] ?? 'Failed to add comment';
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
          );
        }
      }
    }

    Future<void> editComment(
        StateSetter setDialogState, int commentId, String currentText) async {
      final editController = TextEditingController(text: currentText);
      final newText = await showDialog<String>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Edit Comment'),
          content: TextField(
            controller: editController,
            decoration: const InputDecoration(
              labelText: 'Comment',
              border: OutlineInputBorder(),
            ),
            maxLines: 4,
            autofocus: true,
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Cancel')),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx, editController.text.trim()),
              child: const Text('Save'),
            ),
          ],
        ),
      );
      if (newText == null || newText.isEmpty || newText == currentText) return;
      try {
        final root = await _getServerRoot();
        if (root == null) throw 'Server not configured';
        final response = await http.post(
          Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
          body: {
            'action': 'edit_comment',
            'comment_id': commentId.toString(),
            'comment': newText,
            'username': username,
            'department': _userDepartment,
          },
        );
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          await loadComments(setDialogState);
        } else {
          throw data['error'] ?? 'Failed to edit comment';
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
          );
        }
      }
    }

    Future<void> deleteComment(
        StateSetter setDialogState, int commentId) async {
      final confirm = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: const Text('Delete Comment'),
          content: const Text('Are you sure you want to delete this comment?'),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(ctx, false),
                child: const Text('Cancel')),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx, true),
              style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
              child: const Text('Delete'),
            ),
          ],
        ),
      );
      if (confirm != true) return;
      try {
        final root = await _getServerRoot();
        if (root == null) throw 'Server not configured';
        final response = await http.post(
          Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
          body: {
            'action': 'delete_comment',
            'comment_id': commentId.toString(),
            'username': username,
            'department': _userDepartment,
          },
        );
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          await loadComments(setDialogState);
        } else {
          throw data['error'] ?? 'Failed to delete comment';
        }
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
          );
        }
      }
    }

    await showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) {
          if (isLoading && comments.isEmpty && errorMsg == null) {
            loadComments(setDialogState);
          }
          return AlertDialog(
            title: Row(
              children: [
                const Icon(Icons.comment, color: Color(0xFF6868AC)),
                const SizedBox(width: 8),
                Expanded(
                    child: Text('Comments: $docTitle',
                        overflow: TextOverflow.ellipsis)),
              ],
            ),
            content: SizedBox(
              width: double.maxFinite,
              height: 400,
              child: Column(
                children: [
                  Expanded(
                    child: isLoading
                        ? const Center(child: CircularProgressIndicator())
                        : errorMsg != null
                            ? Center(
                                child: Text('Error: $errorMsg',
                                    style: const TextStyle(color: Colors.red)))
                            : comments.isEmpty
                                ? const Center(
                                    child: Text('No comments yet',
                                        style: TextStyle(color: Colors.grey)))
                                : ListView.builder(
                                    itemCount: comments.length,
                                    itemBuilder: (ctx, i) {
                                      final c = comments[i];
                                      final canEdit = c['username'] ==
                                              username ||
                                          c['department'] == _userDepartment;
                                      final createdAt = DateTime.tryParse(
                                          c['created_at'] ?? '');
                                      final dateStr = createdAt != null
                                          ? '${createdAt.day}/${createdAt.month}/${createdAt.year} ${createdAt.hour}:${createdAt.minute.toString().padLeft(2, '0')}'
                                          : '';
                                      return Card(
                                        margin:
                                            const EdgeInsets.only(bottom: 8),
                                        child: Padding(
                                          padding: const EdgeInsets.all(12),
                                          child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                              Row(
                                                mainAxisAlignment:
                                                    MainAxisAlignment
                                                        .spaceBetween,
                                                children: [
                                                  Expanded(
                                                    child: Row(
                                                      children: [
                                                        Text(
                                                            c['username'] ??
                                                                'Unknown',
                                                            style: const TextStyle(
                                                                fontWeight:
                                                                    FontWeight
                                                                        .bold)),
                                                        const SizedBox(
                                                            width: 8),
                                                        Text(
                                                            c['department'] ??
                                                                '',
                                                            style: TextStyle(
                                                                color: Colors
                                                                    .grey[600],
                                                                fontSize: 12)),
                                                      ],
                                                    ),
                                                  ),
                                                  Text(dateStr,
                                                      style: TextStyle(
                                                          color:
                                                              Colors.grey[500],
                                                          fontSize: 11)),
                                                ],
                                              ),
                                              const SizedBox(height: 8),
                                              Text(c['comment'] ?? ''),
                                              if (canEdit) ...[
                                                const SizedBox(height: 8),
                                                Row(
                                                  mainAxisAlignment:
                                                      MainAxisAlignment.end,
                                                  children: [
                                                    TextButton.icon(
                                                      onPressed: () =>
                                                          editComment(
                                                              setDialogState,
                                                              c['id'],
                                                              c['comment'] ??
                                                                  ''),
                                                      icon: const Icon(
                                                          Icons.edit,
                                                          size: 16),
                                                      label: const Text('Edit'),
                                                      style: TextButton.styleFrom(
                                                          foregroundColor:
                                                              const Color(
                                                                  0xFF6868AC)),
                                                    ),
                                                    TextButton.icon(
                                                      onPressed: () =>
                                                          deleteComment(
                                                              setDialogState,
                                                              c['id']),
                                                      icon: const Icon(
                                                          Icons.delete,
                                                          size: 16),
                                                      label:
                                                          const Text('Delete'),
                                                      style:
                                                          TextButton.styleFrom(
                                                              foregroundColor:
                                                                  Colors.red),
                                                    ),
                                                  ],
                                                ),
                                              ],
                                            ],
                                          ),
                                        ),
                                      );
                                    },
                                  ),
                  ),
                  const Divider(),
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: commentController,
                          decoration: const InputDecoration(
                            hintText: 'Add a comment...',
                            border: OutlineInputBorder(),
                            contentPadding: EdgeInsets.symmetric(
                                horizontal: 12, vertical: 8),
                          ),
                          maxLines: 2,
                        ),
                      ),
                      const SizedBox(width: 8),
                      IconButton(
                        onPressed: () => addComment(setDialogState),
                        icon: const Icon(Icons.send),
                        color: const Color(0xFF6868AC),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Close'),
              ),
            ],
          );
        },
      ),
    );
  }

  /// Show Add Comment Dialog
  Future<void> _showAddCommentDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String docTitle,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'Comment',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text(
                  'Cannot comment: missing tracking identity (trackingId/mobileTimestamp/docHash)')),
        );
      }
      return;
    }

    final commentController = TextEditingController();

    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Add Comment'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Document: $docTitle',
                  style: const TextStyle(fontWeight: FontWeight.w500)),
              const SizedBox(height: 16),
              TextField(
                controller: commentController,
                decoration: const InputDecoration(
                  labelText: 'Your Comment *',
                  hintText: 'Add remarks or notes about this document...',
                  border: OutlineInputBorder(),
                ),
                maxLines: 4,
                autofocus: true,
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          ElevatedButton(
            onPressed: () {
              if (commentController.text.trim().isEmpty) {
                ScaffoldMessenger.of(ctx).showSnackBar(
                  const SnackBar(content: Text('Please enter a comment')),
                );
                return;
              }
              Navigator.pop(ctx, commentController.text.trim());
            },
            child: const Text('Add Comment'),
          ),
        ],
      ),
    );

    if (result == null || !mounted) return;

    try {
      final root = await _getServerRoot();
      if (root == null) throw 'Server not configured';

      final response = await http.post(
        Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
        body: {
          'action': 'add_comment',
          'tracking_id': resolvedTrackingId,
          'comment': result,
          'username': username,
          'department': _userDepartment,
        },
      );

      final data = jsonDecode(response.body);
      if (data['success'] == true) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Comment added successfully'),
              backgroundColor: Colors.green,
            ),
          );
        }
      } else {
        throw data['error'] ?? 'Failed to add comment';
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  /// Show Add Attachment Dialog — captures a separate attachment document
  /// (does NOT replace the main document).
  Future<void> _showAddAttachmentDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String docTitle,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'Attach',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text(
                  'Cannot attach: missing tracking identity (trackingId/mobileTimestamp/docHash)')),
        );
      }
      return;
    }

    final tid = int.tryParse(resolvedTrackingId);
    if (tid == null || tid <= 0) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Invalid tracking ID')),
        );
      }
      return;
    }

    // Capture and upload as a separate attachment record
    await _captureAndUploadAttachmentDocument(trackingId: tid);
  }

  Future<bool> _captureAndUploadAttachmentDocument({
    required int trackingId,
  }) async {
    final List<File>? capturedImages = await showDialog<List<File>>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => _MultiImageCaptureDialog(trackingId: trackingId),
    );

    if (capturedImages == null || capturedImages.isEmpty || !mounted) {
      return false;
    }

    final materialized = await _materializeCapturedImages(capturedImages);
    if (materialized.isEmpty || !mounted) return false;

    try {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
                'Running OCR + converting ${materialized.length} page(s) to PDF...'),
            duration: const Duration(seconds: 10),
          ),
        );
      }

      final List<String> pageTexts = <String>[];
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      try {
        for (final imgFile in materialized) {
          try {
            final input = InputImage.fromFile(imgFile);
            final recognized = await recognizer.processImage(input);
            final t = (recognized.text).trim();
            pageTexts.add(t.isNotEmpty ? t : 'No text detected');
          } catch (_) {
            pageTexts.add('No text detected');
          }
        }
      } finally {
        await recognizer.close();
      }

      final pdfBytes = await _generatePdfFromImages(
        materialized,
        pageTexts: pageTexts,
        embedOcrTextInPdf: false,
      );

      final tempDir = await getTemporaryDirectory();
      final pdfPath =
          '${tempDir.path}/attachment_${trackingId}_${DateTime.now().millisecondsSinceEpoch}.pdf';
      final pdfFile = File(pdfPath);
      await pdfFile.writeAsBytes(pdfBytes, flush: true);

      final List<String>? editedTexts = await showDialog<List<String>>(
        context: context,
        barrierDismissible: false,
        builder: (ctx) => _PdfPreviewDialog(
          pdfFilePath: pdfFile.path,
          pageCount: materialized.length,
          pageTexts: pageTexts,
        ),
      );

      if (editedTexts == null || editedTexts.isEmpty || !mounted) {
        for (final f in materialized) {
          try {
            f.delete();
          } catch (_) {}
        }
        try {
          pdfFile.delete();
        } catch (_) {}
        return false;
      }

      final root = await _getServerRoot();
      if (root == null || root.trim().isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Server not configured')),
          );
        }
        return false;
      }

      final request = http.MultipartRequest(
        'POST',
        Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
      );
      request.fields['action'] = 'add_attachment';
      request.fields['tracking_id'] = trackingId.toString();
      request.fields['uploaded_by'] = username;
      request.fields['department'] = _userDepartment;
      request.fields['remarks'] = '';
      request.fields['ocr_text'] = editedTexts.join('\n\n').trim();

      String uploadName =
          'Attachment_${trackingId}_${DateTime.now().millisecondsSinceEpoch}.pdf';
      try {
        final meta = await _fetchRoutingMeta(trackingId: trackingId.toString());
        final type = (meta?['type'] ?? '').toString().trim();
        final date = (meta?['date_submitted'] ?? meta?['created_at'] ?? '')
            .toString()
            .trim();
        final safeType = _safeFilePart(typeCtrlValue(type));
        final stamp = _safeFilePart(date.split(' ').first);
        uploadName =
            'Attachment_${safeType.isNotEmpty ? safeType : 'Document'}_${stamp.isNotEmpty ? stamp : _safeFilePart(DateTime.now().toIso8601String().split('T').first)}.pdf';
      } catch (_) {}

      request.files.add(await http.MultipartFile.fromPath(
        'file',
        pdfFile.path,
        filename: uploadName,
      ));

      final streamed =
          await request.send().timeout(const Duration(seconds: 60));
      final body = await streamed.stream.bytesToString();
      bool ok = streamed.statusCode < 400;
      String msg = 'Attachment uploaded successfully';
      try {
        final decoded = jsonDecode(body);
        if (decoded is Map) {
          ok = ok && (decoded['success'] == true);
          msg = decoded['message']?.toString() ??
              decoded['error']?.toString() ??
              msg;
        }
      } catch (_) {
        if (!ok && body.trim().isNotEmpty) msg = body.trim();
      }

      for (final f in materialized) {
        try {
          f.delete();
        } catch (_) {}
      }
      try {
        pdfFile.delete();
      } catch (_) {}

      if (!mounted) return false;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ok ? msg : 'Upload failed: $msg'),
          backgroundColor: ok ? Colors.green : Colors.red,
        ),
      );

      if (ok) {
        _fetchRecentActivity();
      }

      return ok;
    } catch (e) {
      for (final f in materialized) {
        try {
          f.delete();
        } catch (_) {}
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Attachment error: $e')),
        );
      }
      return false;
    }
  }

  /// Show Edit Document Type Dialog
  Future<void> _showEditDocumentTypeDialog({
    required String? trackingId,
    required String? mobileTimestamp,
    required String? docHash,
    required String? filePath,
    required String docTitle,
    required String? currentType,
  }) async {
    final resolvedTrackingId = await _resolveTrackingIdForAction(
      actionLabel: 'EditType',
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
      filePath: filePath,
    );

    if (resolvedTrackingId == null) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text(
                  'Cannot edit type: missing tracking identity (trackingId/mobileTimestamp/docHash)')),
        );
      }
      return;
    }

    final documentTypes = [
      'Advisory',
      'Advisories',
      'Announcement',
      'Activity Design',
      'Memo',
      'Payroll',
      'Purchase Order',
      'Purchase Request',
      'Travel Order',
    ];

    String? selectedType = currentType;
    if (selectedType != null && !documentTypes.contains(selectedType)) {
      // Add current type if not in list
      documentTypes.insert(0, selectedType);
    }

    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Edit Document Type'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Document: $docTitle',
                    style: const TextStyle(fontWeight: FontWeight.w500)),
                const SizedBox(height: 8),
                Text('Current type: ${currentType ?? "Unknown"}',
                    style: TextStyle(fontSize: 12, color: Colors.grey[600])),
                const SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: selectedType,
                  decoration: const InputDecoration(
                    labelText: 'New Document Type',
                    border: OutlineInputBorder(),
                  ),
                  items: documentTypes
                      .map((t) => DropdownMenuItem(value: t, child: Text(t)))
                      .toList(),
                  onChanged: (v) => setDialogState(() => selectedType = v),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: selectedType == null || selectedType == currentType
                  ? null
                  : () => Navigator.pop(ctx, selectedType),
              child: const Text('Update Type'),
            ),
          ],
        ),
      ),
    );

    if (result == null || !mounted) return;

    try {
      final root = await _getServerRoot();
      if (root == null) throw 'Server not configured';

      final response = await http.post(
        Uri.parse('$root/lib/OCR(UPDATED)/api/document_actions.php'),
        body: {
          'action': 'update_document_type',
          'tracking_id': resolvedTrackingId,
          'document_type': result,
          'updated_by': username,
        },
      );

      final data = jsonDecode(response.body);
      if (data['success'] == true) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Document type changed to "$result"'),
              backgroundColor: Colors.green,
            ),
          );
          await _fetchRecentActivity();
        }
      } else {
        throw data['error'] ?? 'Failed to update document type';
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e'), backgroundColor: Colors.red),
        );
      }
    }
  }

  // Check if activity item is encrypted (payroll document)
  bool _isActivityEncrypted(Map<String, dynamic> activity) {
    try {
      final title = (activity['title'] ?? '').toString().toLowerCase();
      final docType = (activity['type'] ?? '').toString().toLowerCase();
      final subtitle = (activity['subtitle'] ?? '').toString().toLowerCase();

      // Check for encryption metadata in title or type
      if (title.contains('encrypted') || docType.contains('encrypted')) {
        return true;
      }

      // Check for payroll keywords
      final encryptionService = EncryptionService.instance;
      return encryptionService.isPayrollDocument(title, subtitle);
    } catch (e) {
      return false;
    }
  }

  /// Build status badge with appropriate color for document status
  Widget _buildStatusBadge(String status) {
    final lowerStatus = status.toLowerCase().trim();

    Color badgeColor;
    IconData? icon;

    switch (lowerStatus) {
      case 'returned':
        badgeColor = Colors.orange.shade600;
        icon = Icons.reply;
        break;
      case 'pending':
        badgeColor = Colors.amber.shade700;
        icon = Icons.schedule;
        break;
      case 'in review':
      case 'in_review':
        badgeColor = const Color(0xFF6868AC);
        icon = Icons.visibility;
        break;
      case 'completed':
      case 'done':
        badgeColor = Colors.green.shade600;
        icon = Icons.check_circle;
        break;
      case 'archived':
        badgeColor = Colors.grey.shade600;
        icon = Icons.archive;
        break;
      case 'routed':
        badgeColor = Colors.purple.shade600;
        icon = Icons.send;
        break;
      default:
        badgeColor = Colors.blueGrey.shade500;
        icon = Icons.info_outline;
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: badgeColor.withOpacity(0.12),
        borderRadius: BorderRadius.circular(100),
        border: Border.all(color: badgeColor.withOpacity(0.25), width: 1),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 10, color: badgeColor),
          const SizedBox(width: 3),
          Text(
            status,
            style: TextStyle(
              fontFamily: 'Poppins',
              fontSize: 10,
              color: badgeColor,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildActivityItem({
    required String title,
    required String subtitle,
    required String time,
    required IconData icon,
    required VoidCallback onTap,
    VoidCallback? onLongPress,
    String? fileUrl,
    int? id,
    String? status,
    String? recipientDepartment,
    String? fsId,
    String? docType,
    String? mobileTimestamp,
    String? docHash,
    String? trackingId,
    String? storedFilePath,
    bool isEncrypted = false,
    String? endLocation,
    String? currentHolder,
  }) {
    String? normalize(String? value) {
      if (value == null) return null;
      final trimmed = value.trim();
      return trimmed.isEmpty ? null : trimmed;
    }

    final resolvedFilePath =
        normalize(storedFilePath) ?? normalize(fileUrl) ?? '';

    String? deriveMobileTimestampFromPath(String path) {
      final m =
          RegExp(r'PDF_(\d{10,13})', caseSensitive: false).firstMatch(path);
      if (m == null) return null;
      final ts = (m.group(1) ?? '').trim();
      return ts.isNotEmpty ? ts : null;
    }

    final normalizedMobileTimestamp = normalize(mobileTimestamp) ??
        deriveMobileTimestampFromPath(resolvedFilePath);
    final normalizedDocHash = normalize(docHash);
    final normalizedTrackingId = normalize(trackingId);

    final int? numericTrackingId = (normalizedTrackingId != null)
        ? int.tryParse(normalizedTrackingId)
        : null;
    final bool hasIdentity =
        (numericTrackingId != null && numericTrackingId > 0) ||
            (normalizedMobileTimestamp != null) ||
            (normalizedDocHash != null);

    // Build receive key WITHOUT title so it persists after document type edit
    final receiveKey =
        '${normalizedTrackingId ?? ''}|${normalizedMobileTimestamp ?? ''}|$resolvedFilePath|${recipientDepartment ?? ''}';

    // Check server-side status to determine if already received (in_review, routed, etc.)
    final serverStatus = (status ?? '').trim().toLowerCase();
    final serverIndicatesReceived = [
      'in_review',
      'in review',
      'routed',
      'completed',
      'done',
      'returned'
    ].contains(serverStatus);

    final alreadyReceived =
        _receivedItemKeys.contains(receiveKey) || serverIndicatesReceived;

    bool isAnnouncementType(String? raw) {
      final t = (raw ?? '').trim().toLowerCase();
      if (t.isEmpty) return false;
      // Handle exact type and batch suffix like "Announcement (File 1 of 3)"
      return t == 'announcement' || t.startsWith('announcement ');
    }

    final bool isAnnouncement =
        isAnnouncementType(docType) || isAnnouncementType(title);

    // IMPORTANT:
    // - `end_location` is the final destination department.
    // - "Update" (final capture/upload) must only be available when:
    //    1) the document is currently held by the end location, AND
    //    2) the logged-in user belongs to that end location.
    // Otherwise, show "Route" (for current holder departments).
    final normalizedEndLocation = normalize(endLocation);
    final effectiveHolderDept =
        normalize(currentHolder) ?? normalize(recipientDepartment);
    final isDocAtEndLocation =
        (normalizedEndLocation != null && effectiveHolderDept != null)
            ? _sameDept(effectiveHolderDept, normalizedEndLocation)
            : false;
    final isUserEndLocation = (normalizedEndLocation != null)
        ? _sameDept(_userDepartment, normalizedEndLocation)
        : false;
    final atEndLocation = isDocAtEndLocation && isUserEndLocation;

    // Allow routing only for the current holder's department.
    final isUserCurrentHolder = effectiveHolderDept != null
        ? _sameDept(_userDepartment, effectiveHolderDept)
        : _sameDept(_userDepartment, recipientDepartment);

    // Ensure identities for this item are cached (route button might rely on cache)
    _rememberIdentityForActivity({
      'trackingId': normalizedTrackingId,
      'mobileTimestamp': normalizedMobileTimestamp,
      'docHash': normalizedDocHash,
      'filePath': resolvedFilePath,
      'title': title,
      'subtitle': subtitle,
      'sender': recipientDepartment,
    });

    return Material(
      color: Theme.of(context).cardColor,
      child: InkWell(
        onTap: onTap,
        onLongPress: onLongPress,
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 14),
          constraints: const BoxConstraints(minHeight: 72),
          child: Row(
            children: [
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  color: const Color(0xFF6868AC).withOpacity(0.08),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, color: const Color(0xFF6868AC), size: 22),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      title,
                      style: TextStyle(
                        fontFamily: 'Poppins',
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        color: Theme.of(context).colorScheme.onSurface,
                        height: 1.3,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if ((status != null && status.isNotEmpty) || isEncrypted)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Wrap(
                          spacing: 6,
                          runSpacing: 4,
                          children: [
                            if (status != null && status.isNotEmpty)
                              _buildStatusBadge(status),
                            if (isEncrypted)
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(
                                  color:
                                      Colors.green.shade600.withOpacity(0.12),
                                  borderRadius: BorderRadius.circular(100),
                                  border: Border.all(
                                      color: Colors.green.shade600
                                          .withOpacity(0.25),
                                      width: 1),
                                ),
                                child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(Icons.lock,
                                        size: 10, color: Colors.green.shade600),
                                    const SizedBox(width: 3),
                                    Text(
                                      'Encrypted',
                                      style: TextStyle(
                                        fontFamily: 'Poppins',
                                        fontSize: 10,
                                        color: Colors.green.shade600,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                          ],
                        ),
                      ),
                    const SizedBox(height: 4),
                    Text(
                      subtitle,
                      style: TextStyle(
                        fontFamily: 'Poppins',
                        fontSize: 12,
                        fontWeight: FontWeight.w400,
                        color: Theme.of(context)
                            .colorScheme
                            .onSurface
                            .withOpacity(0.55),
                        height: 1.3,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    if ((effectiveHolderDept != null &&
                            effectiveHolderDept.isNotEmpty) &&
                        (status == null || status != 'confirmed')) ...[
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          // Receive (first)
                          Expanded(
                            child: SizedBox(
                              height: 36,
                              child: ElevatedButton(
                                onPressed: (alreadyReceived ||
                                        _receivingKeys.contains(receiveKey))
                                    ? null
                                    : () async {
                                        setState(() =>
                                            _receivingKeys.add(receiveKey));
                                        try {
                                          final prefs = await SharedPreferences
                                              .getInstance();
                                          final myDept = (prefs.getString(
                                                      'user_department') ??
                                                  '')
                                              .trim();
                                          final receiverDept = myDept.isNotEmpty
                                              ? myDept
                                              : effectiveHolderDept;

                                          String? effectiveTrackingId =
                                              normalizedTrackingId;
                                          if ((effectiveTrackingId
                                                      ?.isNotEmpty ??
                                                  false) ==
                                              false) {
                                            try {
                                              effectiveTrackingId =
                                                  await _resolveTrackingIdForAction(
                                                actionLabel: 'Receive',
                                                trackingId:
                                                    normalizedTrackingId,
                                                mobileTimestamp:
                                                    normalizedMobileTimestamp,
                                                docHash: normalizedDocHash,
                                                filePath: resolvedFilePath,
                                              );
                                            } catch (_) {
                                              effectiveTrackingId =
                                                  normalizedTrackingId;
                                            }
                                          }

                                          final hasStrongIdentity =
                                              (effectiveTrackingId
                                                          ?.isNotEmpty ??
                                                      false) ||
                                                  ((normalizedMobileTimestamp
                                                              ?.isNotEmpty ??
                                                          false) &&
                                                      (normalizedDocHash
                                                              ?.isNotEmpty ??
                                                          false));
                                          if (!hasStrongIdentity) {
                                            final dbgPayload = {
                                              'action': 'receive',
                                              'identity_error':
                                                  'identity_missing',
                                              'tracking_id':
                                                  normalizedTrackingId,
                                              'mobile_timestamp':
                                                  normalizedMobileTimestamp,
                                              'doc_hash': normalizedDocHash,
                                              'notification_id': id,
                                            };
                                            final txt =
                                                const JsonEncoder.withIndent(
                                                        '  ')
                                                    .convert(dbgPayload);
                                            _lastReceiveDebug =
                                                Map<String, dynamic>.from(
                                                    dbgPayload);
                                            ScaffoldMessenger.of(context)
                                                .showSnackBar(
                                              SnackBar(
                                                content: const Text(
                                                    'Cannot receive: requires tracking_id or mobile_timestamp+doc_hash.'),
                                                action: SnackBarAction(
                                                  label: 'DEBUG',
                                                  textColor: Colors.white,
                                                  onPressed: () {
                                                    _showCopyableDebugDialog(
                                                      title: 'Receive Debug',
                                                      message: txt,
                                                      copyText: txt,
                                                    );
                                                  },
                                                ),
                                                backgroundColor:
                                                    Colors.red.shade700,
                                              ),
                                            );
                                            return;
                                          }

                                          final result = await _markInReview(
                                            effectiveTrackingId,
                                            docType: (docType ?? title),
                                            receiverDepartment: receiverDept,
                                            endLocation: normalizedEndLocation,
                                            notificationId: id,
                                          );
                                          if (!mounted) return;

                                          final ok = result['ok'] == true;

                                          Map<String, dynamic>? postState;
                                          try {
                                            postState = await _fetchRoutingMeta(
                                              trackingId: (normalizedTrackingId
                                                          ?.isNotEmpty ??
                                                      false)
                                                  ? normalizedTrackingId
                                                  : null,
                                              mobileTimestamp:
                                                  (normalizedMobileTimestamp
                                                              ?.isNotEmpty ??
                                                          false)
                                                      ? normalizedMobileTimestamp
                                                      : null,
                                              docHash: (normalizedDocHash
                                                          ?.isNotEmpty ??
                                                      false)
                                                  ? normalizedDocHash
                                                  : null,
                                              filePath:
                                                  resolvedFilePath.isNotEmpty
                                                      ? resolvedFilePath
                                                      : null,
                                            );
                                          } catch (_) {
                                            postState = null;
                                          }

                                          final receiveDebug =
                                              <String, dynamic>{
                                            'action': 'receive',
                                            'request': {
                                              'tracking_id':
                                                  effectiveTrackingId,
                                              'mobile_timestamp':
                                                  normalizedMobileTimestamp,
                                              'doc_hash': normalizedDocHash,
                                              'file_path': resolvedFilePath,
                                              'receiver_department':
                                                  receiverDept,
                                              'notification_id': id,
                                            },
                                            'response': result,
                                            'post_state': postState,
                                            'timestamp': DateTime.now()
                                                .toIso8601String(),
                                          };
                                          _lastReceiveDebug = receiveDebug;
                                          final receiveDebugText =
                                              _buildDebugSummaryAndJson(
                                            action: 'receive',
                                            payload: receiveDebug,
                                          );

                                          // Show success/failure feedback
                                          if (ok) {
                                            ScaffoldMessenger.of(context)
                                                .showSnackBar(
                                              SnackBar(
                                                content: const Row(
                                                  children: [
                                                    Icon(Icons.check_circle,
                                                        color: Colors.white,
                                                        size: 18),
                                                    SizedBox(width: 8),
                                                    Expanded(
                                                        child: Text(
                                                            'Document received successfully')),
                                                  ],
                                                ),
                                                backgroundColor:
                                                    Colors.green.shade700,
                                                duration:
                                                    const Duration(seconds: 2),
                                                action: _debugToolsEnabled
                                                    ? SnackBarAction(
                                                        label: 'DEBUG',
                                                        textColor: Colors.white,
                                                        onPressed: () {
                                                          _showCopyableDebugDialog(
                                                            title:
                                                                'Receive Debug',
                                                            message:
                                                                receiveDebugText,
                                                            copyText:
                                                                receiveDebugText,
                                                          );
                                                        },
                                                      )
                                                    : null,
                                              ),
                                            );
                                          } else {
                                            ScaffoldMessenger.of(context)
                                                .showSnackBar(
                                              SnackBar(
                                                content: const Row(
                                                  children: [
                                                    Icon(Icons.error_outline,
                                                        color: Colors.white,
                                                        size: 18),
                                                    SizedBox(width: 8),
                                                    Expanded(
                                                        child: Text(
                                                            'Failed to receive document. Please try again.')),
                                                  ],
                                                ),
                                                backgroundColor:
                                                    Colors.red.shade700,
                                                duration:
                                                    const Duration(seconds: 3),
                                                action: _debugToolsEnabled
                                                    ? SnackBarAction(
                                                        label: 'DEBUG',
                                                        textColor: Colors.white,
                                                        onPressed: () {
                                                          _showCopyableDebugDialog(
                                                            title:
                                                                'Receive Debug',
                                                            message:
                                                                receiveDebugText,
                                                            copyText:
                                                                receiveDebugText,
                                                          );
                                                        },
                                                      )
                                                    : null,
                                              ),
                                            );
                                          }

                                          if (!ok) return;

                                          // Record the local received key to avoid repeat taps.
                                          setState(() {
                                            _receivedItemKeys.add(receiveKey);
                                          });

                                          // Announcements: receiving auto-completes server-side and should
                                          // immediately disappear from the dashboard.
                                          if (isAnnouncement) {
                                            // Best-effort: mark/delete associated notification and remove locally.
                                            if (id != null && id > 0) {
                                              try {
                                                await _updateNotificationStatus(
                                                    id, 'completed');
                                              } catch (_) {}
                                              try {
                                                await _deleteNotificationById(
                                                    id);
                                              } catch (_) {}
                                            }

                                            if (mounted) {
                                              setState(() {
                                                // Remove by activity id if present
                                                if (id != null) {
                                                  _recentActivity
                                                      .removeWhere((m) {
                                                    final mid = m['id'];
                                                    return mid != null &&
                                                        mid.toString() ==
                                                            id.toString();
                                                  });
                                                }

                                                // Remove by tracking identity as a fallback
                                                _recentActivity
                                                    .removeWhere((m) {
                                                  final tid = (m[
                                                              'trackingId'] ??
                                                          m['tracking_id'] ??
                                                          '')
                                                      .toString()
                                                      .trim();
                                                  if (normalizedTrackingId !=
                                                          null &&
                                                      normalizedTrackingId
                                                          .isNotEmpty &&
                                                      tid ==
                                                          normalizedTrackingId) {
                                                    return true;
                                                  }
                                                  final mts = (m[
                                                              'mobileTimestamp'] ??
                                                          m['mobile_timestamp'] ??
                                                          '')
                                                      .toString()
                                                      .trim();
                                                  final dhs = (m['docHash'] ??
                                                          m['doc_hash'] ??
                                                          '')
                                                      .toString()
                                                      .trim();
                                                  if (normalizedMobileTimestamp !=
                                                          null &&
                                                      normalizedMobileTimestamp
                                                          .isNotEmpty &&
                                                      mts ==
                                                          normalizedMobileTimestamp) {
                                                    return true;
                                                  }
                                                  if (normalizedDocHash !=
                                                          null &&
                                                      normalizedDocHash
                                                          .isNotEmpty &&
                                                      dhs ==
                                                          normalizedDocHash) {
                                                    return true;
                                                  }
                                                  return false;
                                                });
                                              });
                                            }

                                            // Refresh in background to keep state consistent.
                                            // (We already removed the card immediately.)
                                            unawaited(_fetchRecentActivity());
                                            return;
                                          }

                                          // Memos: update local status immediately so UI reflects change,
                                          // then refresh from server in background.
                                          if (ok && mounted) {
                                            setState(() {
                                              for (final item
                                                  in _recentActivity) {
                                                final tid = (item[
                                                            'trackingId'] ??
                                                        item['tracking_id'] ??
                                                        '')
                                                    .toString()
                                                    .trim();
                                                final mid =
                                                    item['id']?.toString();
                                                final matchById = id != null &&
                                                    mid != null &&
                                                    mid == id.toString();
                                                final matchByTid =
                                                    normalizedTrackingId !=
                                                            null &&
                                                        normalizedTrackingId
                                                            .isNotEmpty &&
                                                        tid ==
                                                            normalizedTrackingId;
                                                if (matchById || matchByTid) {
                                                  item['status'] = 'In Review';
                                                  break;
                                                }
                                              }
                                            });
                                            // Also refresh from server to keep in sync
                                            unawaited(_fetchRecentActivity());
                                          }
                                        } finally {
                                          if (mounted) {
                                            setState(() => _receivingKeys
                                                .remove(receiveKey));
                                          }
                                        }
                                      },
                                style: ElevatedButton.styleFrom(
                                  minimumSize: const Size(0, 36),
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 8, vertical: 6),
                                  backgroundColor: Colors.green.shade700,
                                  foregroundColor: Colors.white,
                                ),
                                child: _receivingKeys.contains(receiveKey)
                                    ? const SizedBox(
                                        width: 16,
                                        height: 16,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          color: Colors.white,
                                        ),
                                      )
                                    : const Text(
                                        'Receive',
                                        style: TextStyle(
                                            fontSize: 12,
                                            fontWeight: FontWeight.w500),
                                      ),
                              ),
                            ),
                          ),
                          if (!isAnnouncement) ...[
                            // Icon-only capture button for returned documents
                            if (serverStatus == 'returned') ...[
                              const SizedBox(width: 6),
                              SizedBox(
                                width: 40,
                                height: 36,
                                child: IconButton(
                                  onPressed: () async {
                                    final tid = int.tryParse(
                                        (normalizedTrackingId ?? '').trim());
                                    if (tid == null || tid <= 0) {
                                      if (mounted) {
                                        ScaffoldMessenger.of(context)
                                            .showSnackBar(const SnackBar(
                                                content: Text(
                                                    'Cannot capture: missing tracking info')));
                                      }
                                      return;
                                    }
                                    // Capture → OCR → edit → upload (does NOT mark Completed)
                                    final ok =
                                        await _captureAndUploadReturnedDocument(
                                      trackingId: tid,
                                      activityId: id,
                                    );
                                    if (!ok || !mounted) return;
                                    // After successful capture, open Route dialog
                                    await _openRouteDialogWithDetails(
                                      initialReceiverDept:
                                          recipientDepartment ?? '',
                                      docType: (docType ?? title),
                                      fileName: title,
                                      filePath: resolvedFilePath,
                                      mobileTimestamp:
                                          normalizedMobileTimestamp,
                                      docHash: normalizedDocHash,
                                      trackingId: normalizedTrackingId,
                                      activityId: id,
                                      endLocation: normalizedEndLocation,
                                      currentHolder: effectiveHolderDept,
                                    );
                                  },
                                  icon: Icon(Icons.camera_alt,
                                      size: 20, color: Colors.orange.shade700),
                                  tooltip: 'Capture updated document',
                                  padding: EdgeInsets.zero,
                                  constraints: const BoxConstraints(),
                                  style: IconButton.styleFrom(
                                    backgroundColor: Colors.orange.shade50,
                                    shape: RoundedRectangleBorder(
                                      borderRadius: BorderRadius.circular(8),
                                      side: BorderSide(
                                          color: Colors.orange.shade300),
                                    ),
                                  ),
                                ),
                              ),
                            ],
                            const SizedBox(width: 6),
                            // Route/Update (second) - memos only
                            Expanded(
                              child: SizedBox(
                                height: 36,
                                child: OutlinedButton(
                                  onPressed: alreadyReceived
                                      ? () async {
                                          if (atEndLocation) {
                                            final tid = int.tryParse(
                                                (normalizedTrackingId ?? '')
                                                    .trim());

                                            if (tid != null && tid > 0) {
                                              await _captureAndUploadFinalDocument(
                                                trackingId: tid,
                                                activityId: id,
                                              );
                                              return;
                                            }

                                            if (normalizedMobileTimestamp !=
                                                    null ||
                                                normalizedDocHash != null) {
                                              await _captureAndUploadFinalDocumentByIdentity(
                                                mobileTimestamp:
                                                    normalizedMobileTimestamp,
                                                docHash: normalizedDocHash,
                                                activityId: id,
                                              );
                                              return;
                                            }

                                            if (mounted) {
                                              ScaffoldMessenger.of(context)
                                                  .showSnackBar(const SnackBar(
                                                      content: Text(
                                                          'Cannot update: missing tracking info')));
                                            }
                                            return;
                                          }

                                          final bool hasRouteAnchor =
                                              (id ?? 0) > 0;
                                          if (!hasIdentity && !hasRouteAnchor) {
                                            if (mounted) {
                                              ScaffoldMessenger.of(context)
                                                  .showSnackBar(const SnackBar(
                                                      content: Text(
                                                          'Cannot route: missing tracking info and route anchor. Open from server notification details, then retry.')));
                                            }
                                            return;
                                          }

                                          await _openRouteDialogWithDetails(
                                            initialReceiverDept:
                                                effectiveHolderDept,
                                            docType: (docType ?? title),
                                            fileName: title,
                                            filePath: resolvedFilePath,
                                            mobileTimestamp:
                                                normalizedMobileTimestamp,
                                            docHash: normalizedDocHash,
                                            trackingId: normalizedTrackingId,
                                            activityId: id,
                                            endLocation: normalizedEndLocation,
                                            currentHolder: effectiveHolderDept,
                                          );
                                        }
                                      : null,
                                  style: OutlinedButton.styleFrom(
                                    minimumSize: const Size(0, 36),
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 8, vertical: 6),
                                    foregroundColor: alreadyReceived
                                        ? Colors.green.shade700
                                        : Colors.grey.shade600,
                                    side: BorderSide(
                                      color: alreadyReceived
                                          ? Colors.green.shade700
                                          : Colors.grey.shade400,
                                    ),
                                  ),
                                  child: FittedBox(
                                    fit: BoxFit.scaleDown,
                                    child: Row(
                                      mainAxisSize: MainAxisSize.min,
                                      mainAxisAlignment:
                                          MainAxisAlignment.center,
                                      children: [
                                        if (!alreadyReceived) ...[
                                          Icon(Icons.lock,
                                              size: 14,
                                              color: Colors.grey.shade600),
                                          const SizedBox(width: 3),
                                        ] else if (atEndLocation) ...[
                                          Icon(Icons.camera_alt,
                                              size: 14,
                                              color: Colors.green.shade700),
                                          const SizedBox(width: 3),
                                        ],
                                        Text(
                                          alreadyReceived
                                              ? (atEndLocation
                                                  ? 'Update'
                                                  : 'Route')
                                              : 'Locked',
                                          softWrap: false,
                                          style: TextStyle(
                                            fontSize: 12,
                                            fontWeight: FontWeight.w500,
                                            color: alreadyReceived
                                                ? Colors.green.shade700
                                                : Colors.grey.shade600,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                ),
                              ),
                            ),
                            const SizedBox(width: 6),
                            // Overflow actions (third) - memos only
                            SizedBox(
                              width: 40,
                              height: 36,
                              child: PopupMenuButton<String>(
                                tooltip: 'More actions',
                                padding: EdgeInsets.zero,
                                icon: const Icon(Icons.more_vert, size: 20),
                                onSelected: (value) {
                                  switch (value) {
                                    case 'return':
                                      _showReturnDocumentDialog(
                                        trackingId: normalizedTrackingId,
                                        mobileTimestamp:
                                            normalizedMobileTimestamp,
                                        docHash: normalizedDocHash,
                                        filePath: resolvedFilePath,
                                        senderDepartment: recipientDepartment,
                                        docTitle: title,
                                        activityId: id,
                                      );
                                      break;
                                    case 'attach':
                                      _showAddAttachmentDialog(
                                        trackingId: normalizedTrackingId,
                                        mobileTimestamp:
                                            normalizedMobileTimestamp,
                                        docHash: normalizedDocHash,
                                        filePath: resolvedFilePath,
                                        docTitle: title,
                                      );
                                      break;
                                    case 'view_attachments':
                                      _showViewAttachmentsDialog(
                                        trackingId: normalizedTrackingId,
                                        mobileTimestamp:
                                            normalizedMobileTimestamp,
                                        docHash: normalizedDocHash,
                                        filePath: resolvedFilePath,
                                        docTitle: title,
                                      );
                                      break;
                                    case 'edit_update':
                                      _showEditUpdateDocumentDialog(
                                        trackingId: normalizedTrackingId,
                                        mobileTimestamp:
                                            normalizedMobileTimestamp,
                                        docHash: normalizedDocHash,
                                        filePath: resolvedFilePath,
                                        docTitle: title,
                                        currentType: docType,
                                        currentStatus: status,
                                        currentDepartment: recipientDepartment,
                                        currentHolder: currentHolder,
                                        currentEndLocation: endLocation,
                                      );
                                      break;
                                    case 'history':
                                      _showDocumentHistoryDialog(
                                        trackingId: normalizedTrackingId,
                                        mobileTimestamp:
                                            normalizedMobileTimestamp,
                                        docHash: normalizedDocHash,
                                        filePath: resolvedFilePath,
                                        docTitle: title,
                                      );
                                      break;
                                    case 'comment':
                                      _showViewCommentsDialog(
                                        trackingId: normalizedTrackingId,
                                        mobileTimestamp:
                                            normalizedMobileTimestamp,
                                        docHash: normalizedDocHash,
                                        filePath: resolvedFilePath,
                                        docTitle: title,
                                      );
                                      break;
                                  }
                                },
                                itemBuilder: (context) => [
                                  PopupMenuItem<String>(
                                    value: 'return',
                                    enabled: alreadyReceived,
                                    child: const Text('Return'),
                                  ),
                                  PopupMenuItem<String>(
                                    value: 'attach',
                                    enabled: alreadyReceived,
                                    child: const Text('Attach'),
                                  ),
                                  const PopupMenuItem<String>(
                                    value: 'view_attachments',
                                    child: Text('View Attachments'),
                                  ),
                                  PopupMenuItem<String>(
                                    value: 'edit_update',
                                    enabled: alreadyReceived,
                                    child: const Text('Edit / Update'),
                                  ),
                                  const PopupMenuItem<String>(
                                    value: 'history',
                                    child: Text('History'),
                                  ),
                                  const PopupMenuItem<String>(
                                    value: 'comment',
                                    child: Text('Comment'),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ],
                      ),
                    ],
                  ],
                ),
              ),
              Text(
                time,
                style: TextStyle(
                  fontSize: 12,
                  color:
                      Theme.of(context).colorScheme.onSurface.withOpacity(0.6),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _openUrl(String url) async {
    try {
      String u = url.trim();
      if (u.isEmpty) throw 'Empty link';

      // 1) http/https links -> open directly
      if (u.startsWith('http://') || u.startsWith('https://')) {
        final uri = Uri.parse(u);
        final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
        if (!ok && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Could not open link')));
        }
        return;
      }

      // 2) If looks like a server-relative path (starts with '/') -> prefix server root
      if (u.startsWith('/')) {
        final root = await _getServerRoot();
        if (root != null) {
          // Avoid duplicating the project segment if u already contains it
          String seg = u;
          const proj = '/flutter_application_7';
          if (seg.startsWith(proj)) {
            seg =
                seg.substring(proj.length); // keep leading slash removal result
            if (!seg.startsWith('/')) seg = '/$seg';
          }
          final httpUrl = '$root$seg';
          final uri = Uri.parse(httpUrl);
          final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
          if (!ok && mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Could not open link')));
          }
          return;
        }
      }

      // 2b) Relative path without leading slash like 'Archive/CTO/...' -> prefix with root
      if (!u.contains('://')) {
        final root = await _getServerRoot();
        if (root != null) {
          // If this came from an Android app-internal path, try to extract the archive segment
          final appIdx = u.indexOf('/app_flutter/');
          String suffix = u;
          if (appIdx != -1) {
            final pos = appIdx + '/app_flutter/'.length;
            if (pos < u.length) suffix = u.substring(pos);
          }

          if (!suffix.startsWith('/')) suffix = '/$suffix';
          final httpUrl = '$root$suffix';
          final uri = Uri.parse(httpUrl);
          final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
          if (!ok && mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Could not open link')));
          }
          return;
        }
      }

      // 3) Local file path -> try to open via file://
      final file = File(u);
      if (await file.exists()) {
        final uri = Uri.file(file.path);
        final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
        if (!ok && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
              content: Text('No app available to open this file')));
        }
        return;
      }

      // 4) Last resort: attempt to treat as URL
      final uri = Uri.parse(u);
      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!ok && mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Could not open link')));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Invalid link: $e')));
      }
    }
  }

  void _showEncryptedDocumentDialog() {
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          title: Row(
            children: [
              Icon(Icons.lock, color: Colors.green.shade600, size: 28),
              const SizedBox(width: 12),
              const Expanded(
                child: Text(
                  'Encrypted Document',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'This document contains sensitive payroll information and is encrypted for security purposes.',
                style: TextStyle(fontSize: 14),
              ),
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.green.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.green.shade200),
                ),
                child: Row(
                  children: [
                    Icon(Icons.security,
                        color: Colors.green.shade600, size: 20),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text(
                        'Protected with AES-256 encryption',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w500,
                          color: Colors.green,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              const Text(
                'For security reasons, encrypted payroll documents cannot be opened directly from the Recent Documents list.',
                style: TextStyle(fontSize: 13, color: Colors.grey),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: Text(
                'Understood',
                style: TextStyle(
                  color: Colors.green.shade600,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Future<void> _updateNotificationStatus(int id, String status) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
          .replace(queryParameters: {
        'action': 'update_status',
        'id': id.toString(),
        'status': status,
      });
      final r = await http.post(uri).timeout(const Duration(seconds: 8));
      if (r.statusCode < 400) {
        if (mounted) {
          ScaffoldMessenger.of(context)
              .showSnackBar(SnackBar(content: Text('Updated: $status')));
          await _fetchRecentActivity();
        }
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text('Update failed (${r.statusCode})')));
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Network error: $e')));
      }
    }
  }

  Widget _buildProfileContent({required double bottomPad}) {
    return ListView(
      padding: EdgeInsets.only(left: 16, right: 16, top: 16, bottom: bottomPad),
      children: [
        Center(
          child: CircleAvatar(
            radius: 50,
            backgroundColor: const Color(0xFF6868AC).withOpacity(0.15),
            child: const Icon(Icons.person, size: 60, color: Color(0xFF6868AC)),
          ),
        ),
        const SizedBox(height: 16),
        Center(
          child: Text(
            username,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontSize: 24,
              fontWeight: FontWeight.bold,
            ),
          ),
        ),
        const SizedBox(height: 32),
        _buildProfileOption(Icons.person_outline, 'View Profile', () {
          _openViewProfileDialog();
        }),
        _buildProfileOption(Icons.logout, 'Logout', () {
          _confirmLogout();
        }, isDestructive: true),
      ],
    );
  }

  Widget _buildProfileOption(IconData icon, String title, VoidCallback onTap,
      {bool isDestructive = false}) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: Icon(icon,
            color: isDestructive ? Colors.red : const Color(0xFF6868AC)),
        title: Text(
          title,
          style: TextStyle(
            color: isDestructive
                ? Colors.red
                : Theme.of(context).colorScheme.onSurface,
            fontWeight: isDestructive ? FontWeight.w600 : FontWeight.normal,
          ),
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      ),
    );
  }

  void _showSmallSheet(Widget child) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (_) => Padding(
        padding: const EdgeInsets.all(24),
        child: Row(
          children: [
            const Icon(Icons.info_outline, color: Color(0xFF6868AC), size: 28),
            const SizedBox(width: 16),
            Expanded(
                child: DefaultTextStyle(
              style: TextStyle(
                  fontSize: 16, color: Theme.of(context).colorScheme.onSurface),
              child: child,
            )),
          ],
        ),
      ),
    );
  }

  Widget _buildFAB() {
    return GestureDetector(
      onLongPress: () async {
        try {
          final picker = ImagePicker();
          final XFile? file =
              await picker.pickImage(source: ImageSource.gallery);
          if (file == null || !mounted) return;
          await Future.microtask(() {
            if (!mounted) return;
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (context) => CameraPage(
                  galleryImagePath: file.path,
                ),
              ),
            );
          });
        } catch (e) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Failed to open gallery: $e')),
          );
        }
      },
      child: Container(
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          boxShadow: [
            BoxShadow(
              color: const Color(0xFF6868AC).withOpacity(0.35),
              blurRadius: 16,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: FloatingActionButton(
          heroTag: 'mainCameraFab',
          onPressed: () {
            Future.microtask(() {
              if (!mounted) return;
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (context) => const CameraPage(autoScan: true),
                ),
              );
            });
          },
          backgroundColor: const Color(0xFF6868AC),
          elevation: 0,
          child: const Icon(Icons.camera_alt, size: 26, color: Colors.white),
        ),
      ),
    );
  }

  Widget _buildBottomNav() {
    return BottomAppBar(
      shape: const CircularNotchedRectangle(),
      notchMargin: 8,
      elevation: 0,
      surfaceTintColor: Colors.transparent,
      child: SizedBox(
        height: 60,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceAround,
          children: [
            _buildNavItem(
                Icons.dashboard_outlined, Icons.dashboard, 'Dashboard', 0),
            const SizedBox(width: 48),
            _buildNavItem(Icons.archive_outlined, Icons.archive, 'Archive', 1),
          ],
        ),
      ),
    );
  }

  Widget _buildNavItem(
      IconData icon, IconData activeIcon, String label, int index) {
    final isActive = _currentIndex == index;
    return Expanded(
      child: InkWell(
        onTap: () {
          if (_currentIndex != index) {
            _pageController.animateToPage(
              index,
              duration: const Duration(milliseconds: 220),
              curve: Curves.easeOutCubic,
            );
            HapticFeedback.selectionClick();
          }
        },
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            AnimatedContainer(
              duration: const Duration(milliseconds: 200),
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              decoration: BoxDecoration(
                color: isActive
                    ? const Color(0xFF6868AC).withOpacity(0.1)
                    : Colors.transparent,
                borderRadius: BorderRadius.circular(20),
              ),
              child: Icon(
                isActive ? activeIcon : icon,
                color: isActive
                    ? const Color(0xFF6868AC)
                    : Theme.of(context).colorScheme.onSurface.withOpacity(0.4),
                size: 24,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: TextStyle(
                fontFamily: 'Poppins',
                fontSize: 11,
                color: isActive
                    ? const Color(0xFF6868AC)
                    : Theme.of(context).colorScheme.onSurface.withOpacity(0.4),
                fontWeight: isActive ? FontWeight.w600 : FontWeight.w500,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

extension _RecentUploadOpeners on _RecentUploadPageState {
  bool get _debugRecentUpload => false;

  Future<Map<String, dynamic>?> _fetchRoutingMetaRecent({
    String? trackingId,
    String? mobileTimestamp,
    String? docHash,
  }) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return null;

      final tid = (trackingId ?? '').trim();
      final int? id = tid.isNotEmpty ? int.tryParse(tid) : null;

      Uri uri;
      if (id != null && id > 0) {
        uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
          queryParameters: {'action': 'doc_detail', 'id': id.toString()},
        );
      } else {
        final mt = (mobileTimestamp ?? '').trim();
        final dh = (docHash ?? '').trim();
        if (mt.isEmpty && dh.isEmpty) return null;
        uri = Uri.parse('$root/lib/OCR(UPDATED)/tracking.php').replace(
          queryParameters: {
            'action': 'resolve_identity',
            if (mt.isNotEmpty) 'mobile_timestamp': mt,
            if (dh.isNotEmpty) 'doc_hash': dh,
          },
        );
      }

      final r = await http.get(uri).timeout(const Duration(seconds: 6));
      if (r.statusCode >= 400 || r.body.isEmpty) return null;
      final decoded = jsonDecode(r.body);
      if (decoded is! Map || decoded['success'] != true) return null;
      final doc = (decoded['doc'] is Map)
          ? Map<String, dynamic>.from(decoded['doc'])
          : Map<String, dynamic>.from(decoded);
      return doc;
    } catch (_) {
      return null;
    }
  }

  Future<List<Map<String, dynamic>>> _fetchAllUsersRecent() async {
    try {
      final root = await _getServerRoot();
      if (root == null) return [];
      final uri =
          Uri.parse('$root/lib/OCR(UPDATED)/api/list_control_entities.php');
      final r = await http.get(uri).timeout(const Duration(seconds: 10));
      if (r.statusCode >= 400 || r.body.isEmpty) return [];
      final data = json.decode(r.body) as Map<String, dynamic>;
      final List list = (data['users'] ?? []) as List;
      return list.map((e) => Map<String, dynamic>.from(e as Map)).toList();
    } catch (_) {
      return [];
    }
  }

  Future<void> _updateNotificationStatus(int id, String status) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
          .replace(queryParameters: {'id': id.toString()});
      try {
        await http
            .put(
              uri,
              headers: {'Content-Type': 'application/json'},
              body: jsonEncode({'status': status}),
            )
            .timeout(const Duration(seconds: 6));
      } catch (_) {
        try {
          await http.post(uri, body: {
            'action': 'update',
            'id': id.toString(),
            'status': status
          }).timeout(const Duration(seconds: 6));
        } catch (_) {}
      }
    } catch (_) {}
  }

  // Delete a notification on the server by ID. This mirrors the
  // logic in NotificationPage._deleteOnServer but is scoped here
  // so that Confirm actions can also clean up notifications.
  Future<bool> _deleteNotificationById(int id) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return false;

      final base = root;
      final notifBase = '$base/lib/OCR(UPDATED)/api/notifications.php';

      // 1) DELETE
      try {
        final resp = await http
            .delete(Uri.parse('$notifBase?id=$id'))
            .timeout(const Duration(seconds: 8));
        if (resp.statusCode >= 200 && resp.statusCode < 300) return true;
        try {
          final data = jsonDecode(resp.body);
          if (data is Map && (data['success'] == true)) return true;
        } catch (_) {}
      } catch (_) {}

      // 2) POST action=delete
      try {
        final resp = await http.post(Uri.parse(notifBase), body: {
          'action': 'delete',
          'id': id.toString(),
        }).timeout(const Duration(seconds: 8));
        if (resp.statusCode >= 200 && resp.statusCode < 300) return true;
        try {
          final data = jsonDecode(resp.body);
          if (data is Map && (data['success'] == true)) return true;
        } catch (_) {}
      } catch (_) {}

      // 3) GET action=delete (fallback)
      try {
        final resp = await http
            .get(Uri.parse('$notifBase?action=delete&id=$id'))
            .timeout(const Duration(seconds: 8));
        if (resp.statusCode >= 200 && resp.statusCode < 300) return true;
        try {
          final data = jsonDecode(resp.body);
          if (data is Map && (data['success'] == true)) return true;
        } catch (_) {}
      } catch (_) {}

      return false;
    } catch (_) {
      return false;
    }
  }

  Future<void> _openRouteDialogWithDetails({
    required String initialReceiverDept,
    required String docType,
    required String fileName,
    required String filePath,
    String? mobileTimestamp,
    String? docHash,
    String? trackingId,
    int? activityId,
    String? endLocation,
  }) async {
    final deptCtrl = TextEditingController();
    final typeCtrl = TextEditingController(text: docType);

    // Resolve the true end_location from the tracking row (do not trust notification payload)
    String resolvedEnd = (endLocation ?? '').trim();
    String resolvedType = docType.trim();
    final meta = await _fetchRoutingMetaRecent(
      trackingId: trackingId,
      mobileTimestamp: mobileTimestamp,
      docHash: docHash,
    );
    if (meta != null) {
      final endFromDb =
          (meta['end_location'] ?? meta['endLocation'] ?? '').toString().trim();
      if (endFromDb.isNotEmpty) resolvedEnd = endFromDb;
      final typeFromDb = (meta['type'] ?? '').toString().trim();
      if (typeFromDb.isNotEmpty) resolvedType = typeFromDb;
    }
    if (resolvedType.isNotEmpty) typeCtrl.text = resolvedType;
    final lockedEndLocation = resolvedEnd.trim().isNotEmpty
        ? resolvedEnd.trim()
        : initialReceiverDept;
    // Debug logging removed

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) {
        final mq = MediaQuery.of(ctx);
        return Padding(
          padding: EdgeInsets.only(
            left: 16,
            right: 16,
            top: 16,
            bottom: mq.viewInsets.bottom + 16,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Route Document',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
              ),
              const SizedBox(height: 10),
              FutureBuilder<List<Map<String, dynamic>>>(
                future: _fetchAllUsersRecent(),
                builder: (c, snap) {
                  final users = snap.data ?? [];
                  if (snap.connectionState == ConnectionState.waiting) {
                    return const LinearProgressIndicator(minHeight: 2);
                  }

                  // Build distinct list of departments from users
                  final Set<String> depts = {
                    'CPDO',
                    'GSO',
                    'CBO',
                    'CTO',
                    'CACCO',
                    'CADO',
                    'CMO',
                    for (final u in users)
                      (u['department'] ?? '').toString().trim().toUpperCase(),
                  }..removeWhere((e) => e.trim().isEmpty);

                  void selectDept(String deptName) {
                    deptCtrl.text = deptName;
                  }

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      DropdownButtonFormField<String>(
                        initialValue: depts.contains(deptCtrl.text)
                            ? deptCtrl.text
                            : null,
                        items: depts
                            .map(
                              (d) => DropdownMenuItem<String>(
                                value: d,
                                child: Text(d.isNotEmpty ? d : 'Unknown'),
                              ),
                            )
                            .toList(),
                        onChanged: (v) {
                          if (v == null) return;
                          selectDept(v);
                        },
                        decoration: const InputDecoration(
                          labelText: 'Next Department',
                        ),
                      ),
                    ],
                  );
                },
              ),
              const SizedBox(height: 8),
              TextField(
                controller: typeCtrl,
                readOnly: true,
                decoration: const InputDecoration(labelText: 'Document Type'),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () => Navigator.pop(ctx),
                      child: const Text('Cancel'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: ElevatedButton(
                      onPressed: () async {
                        Navigator.pop(ctx);
                        await _routeDocument(
                          nextDepartment: deptCtrl.text.trim(),
                          endLocation: lockedEndLocation,
                          type: typeCtrl.text.trim().isNotEmpty
                              ? typeCtrl.text.trim()
                              : 'Document',
                          fileName: fileName,
                          filePath: filePath,
                          mobileTimestamp: mobileTimestamp,
                          docHash: docHash,
                          trackingId: trackingId,
                          activityId: activityId,
                        );
                      },
                      child: const Text('Route'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _routeDocument({
    required String nextDepartment,
    required String endLocation,
    required String type,
    required String fileName,
    required String filePath,
    String? mobileTimestamp,
    String? docHash,
    String? trackingId,
    int? activityId,
  }) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final prefs = await SharedPreferences.getInstance();
      final sender = prefs.getString('user_name') ?? '';
      final senderDept = prefs.getString('user_department') ?? '';
      if (sender.isEmpty || senderDept.isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Missing sender info. Re-login.')));
        }
        return;
      }
      if (nextDepartment.isEmpty) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Provide next department')));
        }
        return;
      }

      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/route_document.php');
      final stableTs = (mobileTimestamp?.trim().isNotEmpty ?? false)
          ? mobileTimestamp!.trim()
          : '';
      final hasTrackingId = (trackingId?.trim().isNotEmpty ?? false);
      if (stableTs.isEmpty && !hasTrackingId) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
              content: Text(
                  'Cannot route: missing tracking id (open from notification).')));
        }
        return;
      }
      final payload = {
        'sender_name': sender,
        'sender_department': senderDept,
        'receiver_username': '',
        'receiver_department': nextDepartment,
        'type': type,
        'file_name': fileName,
        'file_path': filePath,
        'mobile_timestamp': stableTs,
        'base': root,
        'next_department': nextDepartment,
        'end_location': endLocation,
      };
      if (docHash?.trim().isNotEmpty ?? false) {
        payload['doc_hash'] = docHash!.trim();
      }
      if (trackingId?.trim().isNotEmpty ?? false) {
        payload['tracking_id'] = trackingId!.trim();
      }
      final r = await http
          .post(uri, body: payload)
          .timeout(const Duration(seconds: 12));
      if (mounted) {
        // Check if server returned PHP source code (indicates server misconfiguration)
        if (r.body.contains('<?php') ||
            (r.body.contains('function ') && r.body.contains('\$'))) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text('Server error: PHP not executing. URL: $uri')));
          return;
        }
        if (r.statusCode < 400) {
          bool ok = true;
          try {
            final data = jsonDecode(r.body);
            if (data is Map && data.containsKey('success')) {
              ok = data['success'] == true;
            }
          } catch (_) {}
          if (!ok) {
            String errorMsg = r.body;
            try {
              final data = jsonDecode(r.body);
              if (data is Map && data['message'] != null) {
                errorMsg = data['message'].toString();
              }
            } catch (_) {}
            ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Route failed: $errorMsg')));
            return;
          }

          // After successful routing, remove the originating notification
          // so it doesn't stay in the list.
          final int? notifId = activityId;
          if (notifId != null && notifId > 0) {
            try {
              await _updateNotificationStatus(notifId, 'routed');
            } catch (_) {}
            try {
              await _deleteNotificationById(notifId);
            } catch (_) {}
          }

          ScaffoldMessenger.of(context)
              .showSnackBar(const SnackBar(content: Text('Routed')));
        } else {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(
              content: Text('Route failed (${r.statusCode}): ${r.body}')));
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Network error: $e')));
      }
    }
  }

  Future<void> _openFromActivity(Map<String, dynamic> activity) async {
    final String? fileUrl = activity['fileUrl']?.toString();
    final String subtitle = activity['subtitle']?.toString() ?? '';
    final String? docType = activity['type']?.toString();
    final String? recipientDepartment =
        activity['recipientDepartment']?.toString();

    // Check if document is encrypted
    final bool isEncrypted = _isActivityEncrypted(activity);

    if (isEncrypted) {
      // Show encrypted document warning
      _showEncryptedDocumentDialog();
      return;
    }

    if ((docType == 'mobile_message') &&
        (recipientDepartment?.isNotEmpty ?? false)) {
      await _openAllenDocumentDetail(
        subtitle: subtitle.isNotEmpty
            ? subtitle
            : (activity['title']?.toString() ?? 'Document'),
        recipientDepartment: recipientDepartment!,
        fileUrl: fileUrl,
      );
      return;
    }
    await _openActivityFileOrPreview(
      fileUrl: fileUrl,
      fileName: subtitle.isNotEmpty
          ? subtitle
          : (activity['title']?.toString() ?? 'Document'),
      recipientDepartment: recipientDepartment,
    );
  }

  // Show encrypted document warning dialog
  void _showEncryptedDocumentDialog() {
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          title: Row(
            children: [
              Icon(Icons.lock, color: Colors.green.shade600, size: 28),
              const SizedBox(width: 12),
              const Expanded(
                child: Text(
                  'Encrypted Document',
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 16,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'This document contains sensitive payroll information and is encrypted for security purposes.',
                style: TextStyle(fontSize: 14),
              ),
              const SizedBox(height: 12),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.green.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.green.shade200),
                ),
                child: Row(
                  children: [
                    Icon(Icons.security,
                        color: Colors.green.shade600, size: 20),
                    const SizedBox(width: 8),
                    const Expanded(
                      child: Text(
                        'Protected with AES-256 encryption',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w500,
                          color: Colors.green,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              const Text(
                'For security reasons, encrypted payroll documents cannot be opened directly from the Recent Documents list.',
                style: TextStyle(fontSize: 13, color: Colors.grey),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: Text(
                'Understood',
                style: TextStyle(
                  color: Colors.green.shade600,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Future<void> _openActivityFileOrPreview({
    String? fileUrl,
    String? fileName,
    String? recipientDepartment,
  }) async {
    try {
      String nameGuess = (fileName ?? '').trim();
      if (nameGuess.contains('•')) {
        final parts = nameGuess.split('•');
        if (parts.length >= 2) nameGuess = parts.last.trim();
      }
      String urlGuess = (fileUrl ?? '').trim();
      String ext = '';
      final lower = nameGuess.toLowerCase();
      final dot = lower.lastIndexOf('.');
      if (dot != -1 && dot < lower.length - 1) ext = lower.substring(dot + 1);
      if (ext.isEmpty && urlGuess.isNotEmpty) {
        final lu = urlGuess.toLowerCase();
        final d2 = lu.lastIndexOf('.');
        if (d2 != -1 && d2 < lu.length - 1) ext = lu.substring(d2 + 1);
      }
      final isImage =
          ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'].contains(ext);
      final isPdf = ext == 'pdf';

      if (urlGuess.isNotEmpty) {
        final looksLocal = urlGuess.startsWith('file://') ||
            urlGuess.startsWith('/data/') ||
            urlGuess.contains('/app_flutter/');
        if (looksLocal) {
          final localPath = urlGuess.startsWith('file://')
              ? Uri.parse(urlGuess).toFilePath()
              : urlGuess;
          final f = File(localPath);
          if (await f.exists()) {
            if (isImage) {
              await _previewImage(localPath, title: nameGuess);
            } else if (isPdf) {
              await _openPdf(localPath,
                  title: nameGuess.isNotEmpty ? nameGuess : 'Document');
            } else {
              final ok = await launchUrl(Uri.file(localPath),
                  mode: LaunchMode.externalApplication);
              if (!ok && mounted) {
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
                    content: Text('No app available to open this file')));
              }
            }
            return;
          }
        } else {
          if (isImage) {
            await _previewImage(urlGuess, title: nameGuess);
          } else if (isPdf) {
            await _openPdf(urlGuess, title: nameGuess);
          } else {
            await _openUrl(urlGuess);
          }
          return;
        }
      }

      final root = await _getServerRoot();
      if (root == null) throw 'No server root';
      final dept = (recipientDepartment ?? '').trim();
      final name = nameGuess;
      if (dept.isEmpty || name.isEmpty) throw 'Missing file info';
      final namesToTry = <String>[name];
      if (ext.isEmpty) {
        namesToTry
            .addAll(['$name.jpg', '$name.jpeg', '$name.png', '$name.pdf']);
      }
      final candidates = <String>[];
      for (final n in namesToTry) {
        final e = Uri.encodeComponent(n);
        for (final v in [e, n]) {
          candidates.addAll([
            '$root/Archive/$dept/$v',
            '$root/lib/Archive/$dept/$v',
            '$root/lib/OCR(UPDATED)/Archive/$dept/$v',
            '$root/uploads/$dept/$v',
            '$root/Uploads/$dept/$v',
            '$root/flutter_application_7/Archive/$dept/$v',
          ]);
        }
      }
      String? working;
      for (final u in candidates) {
        try {
          final r =
              await http.head(Uri.parse(u)).timeout(const Duration(seconds: 5));
          if (r.statusCode < 400) {
            working = u;
            break;
          }
        } catch (_) {}
      }
      working ??= candidates.isNotEmpty ? candidates.first : null;
      if (working == null) throw 'File not found';

      final lw = working.toLowerCase();
      if (lw.endsWith('.pdf')) {
        await _openPdf(working, title: name);
      } else {
        await _previewImage(working, title: name);
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Invalid link: $e')));
    }
  }

  Future<void> _openAllenDocumentDetail({
    required String subtitle,
    required String recipientDepartment,
    String? fileUrl,
  }) async {
    try {
      String name = subtitle.trim();
      if (name.contains('•')) {
        final parts = name.split('•');
        if (parts.length >= 2) name = parts.last.trim();
      }
      final root = await _getServerRoot();
      if (root == null) throw 'No server root';
      final dept = recipientDepartment.trim();
      if (dept.isEmpty || name.isEmpty) throw 'Missing info';

      String ext = '';
      final i = name.lastIndexOf('.');
      if (i != -1 && i < name.length - 1) {
        ext = name.substring(i + 1).toLowerCase();
      }
      final names = <String>[name];
      if (ext.isEmpty) {
        names.addAll(['$name.jpg', '$name.jpeg', '$name.png', '$name.pdf']);
      }
      final paths = <String>[];
      for (final n in names) {
        final e = Uri.encodeComponent(n);
        for (final v in [e, n]) {
          paths.addAll([
            '$root/Archive/$dept/$v',
            '$root/lib/Archive/$dept/$v',
            '$root/lib/OCR(UPDATED)/Archive/$dept/$v',
            '$root/uploads/$dept/$v',
            '$root/Uploads/$dept/$v',
            '$root/flutter_application_7/Archive/$dept/$v',
          ]);
        }
      }
      String? imageOrPdf;
      if (fileUrl != null && fileUrl.trim().isNotEmpty) {
        try {
          final r = await http
              .head(Uri.parse(fileUrl))
              .timeout(const Duration(seconds: 5));
          if (r.statusCode < 400) {
            imageOrPdf = fileUrl;
          } else {
            imageOrPdf = fileUrl;
          }
        } catch (_) {
          imageOrPdf = fileUrl;
        }
      }
      if (imageOrPdf == null) {
        for (final u in paths) {
          try {
            final r = await http
                .head(Uri.parse(u))
                .timeout(const Duration(seconds: 5));
            if (r.statusCode < 400) {
              imageOrPdf = u;
              break;
            }
          } catch (_) {}
        }
      }
      imageOrPdf ??= paths.isNotEmpty ? paths.first : null;
      if (imageOrPdf == null) throw 'Missing filename/path';

      if (imageOrPdf.toLowerCase().endsWith('.pdf')) {
        await _openPdf(imageOrPdf, title: name);
        return;
      }

      String? ocrUrl;
      try {
        final uri = Uri.parse(imageOrPdf);
        final p = uri.path.toLowerCase();
        String txtCandidate;
        if (p.endsWith('.jpg') || p.endsWith('.jpeg') || p.endsWith('.png')) {
          txtCandidate = uri.toString().replaceAll(
              RegExp(r'\.(jpg|jpeg|png)$', caseSensitive: false), '.txt');
        } else {
          final baseUrl = uri.toString();
          final lastDot = baseUrl.lastIndexOf('.');
          txtCandidate = lastDot != -1
              ? ('${baseUrl.substring(0, lastDot)}.txt')
              : ('$baseUrl.txt');
        }
        final head = await http
            .head(Uri.parse(txtCandidate))
            .timeout(const Duration(seconds: 4));
        if (head.statusCode == 200) ocrUrl = txtCandidate;
      } catch (_) {}

      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => DashboardDocumentPreview(
            title: name,
            imageUrl: imageOrPdf!,
            ocrUrl: ocrUrl,
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Open failed: $e')));
    }
  }

  Future<void> _openPdf(String urlOrPath, {String? title}) async {
    try {
      String pathToOpen = urlOrPath;
      if (urlOrPath.startsWith('http://') || urlOrPath.startsWith('https://')) {
        final r = await http
            .get(Uri.parse(urlOrPath))
            .timeout(const Duration(seconds: 20));
        if (r.statusCode >= 400) throw 'Download failed (${r.statusCode})';
        final dir = await getTemporaryDirectory();
        final name = title?.isNotEmpty == true
            ? title!
            : 'document_${DateTime.now().millisecondsSinceEpoch}.pdf';
        final file = File('${dir.path}/$name');
        await file.writeAsBytes(r.bodyBytes);
        pathToOpen = file.path;
      }
      if (!await File(pathToOpen).exists()) throw 'File not found';
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => Scaffold(
            appBar: AppBar(
                title: Text(title?.isNotEmpty == true ? title! : 'Document')),
            body: PDFView(
              filePath: pathToOpen,
              enableSwipe: true,
              swipeHorizontal: true,
              autoSpacing: true,
              pageFling: true,
            ),
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('PDF open error: $e')));
    }
  }

  Future<void> _previewImage(String urlOrPath, {String? title}) async {
    try {
      final f = File(urlOrPath);
      if (await f.exists()) {
        if (!mounted) return;
        await Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => Scaffold(
              appBar: AppBar(
                  title: Text(title?.isNotEmpty == true ? title! : 'Image')),
              body: Center(
                  child: InteractiveViewer(
                      child: Image.file(f, fit: BoxFit.contain))),
            ),
          ),
        );
        return;
      }
      if (!mounted) return;
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => Scaffold(
            appBar: AppBar(
                title: Text(title?.isNotEmpty == true ? title! : 'Image')),
            body: Center(
              child: InteractiveViewer(
                child: Image.network(
                  urlOrPath,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) =>
                      const Icon(Icons.broken_image, size: 48),
                ),
              ),
            ),
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Preview error: $e')));
    }
  }

  Future<void> _openUrl(String url) async {
    try {
      String u = url.trim();
      if (u.isEmpty) throw 'Empty link';
      if (u.startsWith('http://') || u.startsWith('https://')) {
        final uri = Uri.parse(u);
        final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
        if (!ok && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Could not open link')));
        }
        return;
      }
      if (u.startsWith('/')) {
        final root = await _getServerRoot();
        if (root != null) {
          String seg = u;
          const proj = '/flutter_application_7';
          if (seg.startsWith(proj)) {
            seg = seg.substring(proj.length);
            if (!seg.startsWith('/')) seg = '/$seg';
          }
          final httpUrl = '$root$seg';
          final ok = await launchUrl(Uri.parse(httpUrl),
              mode: LaunchMode.externalApplication);
          if (!ok && mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Could not open link')));
          }
          return;
        }
      }
      final file = File(u);
      if (await file.exists()) {
        final ok = await launchUrl(Uri.file(file.path),
            mode: LaunchMode.externalApplication);
        if (!ok && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
              content: Text('No app available to open this file')));
        }
        return;
      }
      final ok =
          await launchUrl(Uri.parse(u), mode: LaunchMode.externalApplication);
      if (!ok && mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Could not open link')));
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Invalid link: $e')));
    }
  }

  String _deriveDocType(String text, String? fallbackType) {
    try {
      String t = text.trim();
      if (t.contains('•')) {
        t = t.split('•').first.trim();
      }
      if (t.contains(' from ')) {
        t = t.split(' from ').first.trim();
      }
      if (t.isNotEmpty) {
        return t;
      }
      if (fallbackType != null && fallbackType.trim().isNotEmpty) {
        return fallbackType.trim();
      }
      return 'Document';
    } catch (_) {
      return fallbackType?.trim().isNotEmpty == true
          ? fallbackType!.trim()
          : 'Document';
    }
  }

  Future<void> _archiveActivityFile({
    required String? fileUrl,
    required String displayName,
  }) async {
    try {
      final url = (fileUrl ?? '').trim();
      if (url.isEmpty) return;

      String resolved = url;
      if (!resolved.contains('://')) {
        final root = await _getServerRoot();
        if (root == null) return;
        if (resolved.startsWith('/')) {
          resolved = '$root$resolved';
        } else {
          resolved = '$root/$resolved';
        }
      }

      final resp = await http
          .get(Uri.parse(resolved))
          .timeout(const Duration(seconds: 15));
      if (resp.statusCode >= 400) return;

      final prefs = await SharedPreferences.getInstance();
      final userDepartment = prefs.getString('user_department') ?? 'General';
      final dir = await getApplicationDocumentsDirectory();
      final archiveDir = Directory('${dir.path}/Archive/$userDepartment');
      if (!await archiveDir.exists()) {
        await archiveDir.create(recursive: true);
      }

      final now = DateTime.now().millisecondsSinceEpoch;
      String ext = '';
      final uriPath = Uri.parse(resolved).path;
      final dot = uriPath.lastIndexOf('.');
      if (dot != -1 && dot < uriPath.length - 1) {
        ext = uriPath.substring(dot);
      }
      String prefix = 'IMG_';
      if (ext.toLowerCase() == '.pdf') {
        prefix = 'PDF_';
      }
      final fileName = '$prefix$now${ext.isNotEmpty ? ext : '.bin'}';
      final outFile = File('${archiveDir.path}/$fileName');
      await outFile.writeAsBytes(resp.bodyBytes, flush: true);

      final metaPath = '${archiveDir.path}/OCR_$now.txt';
      final metaFile = File(metaPath);
      final typeLine = 'Document Type: $displayName';
      final nameLine = 'Document Name: $displayName';
      await metaFile.writeAsString('$typeLine\n$nameLine');
    } catch (_) {}
  }
}

// Page that lists activity items passed in. Title can be customized
// so that dashboard uses "Recent Activity" while profile uses "History".
class RecentUploadPage extends StatefulWidget {
  final List<Map<String, dynamic>> items;
  final String title;

  const RecentUploadPage({
    super.key,
    required this.items,
    this.title = 'Recent Activity',
  });

  @override
  State<RecentUploadPage> createState() => _RecentUploadPageState();
}

class _RecentUploadPageState extends State<RecentUploadPage> {
  Timer? _ticker;
  late List<Map<String, dynamic>> _items;
  final Set<String> _deletedItemIds = {}; // Track deleted item IDs
  static const String _deletedItemsKey = 'deleted_recent_upload_items';
  bool _changed = false; // if true, caller should refresh dashboard

  bool _selectionMode = false;
  final Set<String> _selectedIds = {};

  @override
  void initState() {
    super.initState();
    _loadDeletedItems().then((_) {
      _updateItems();
    });
    // Rebuild every minute so time-ago stays fresh
    _ticker = Timer.periodic(const Duration(minutes: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  // Load deleted item IDs from SharedPreferences
  Future<void> _loadDeletedItems() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final deletedItems = prefs.getStringList(_deletedItemsKey) ?? [];
      setState(() {
        _deletedItemIds.addAll(deletedItems);
      });
    } catch (e) {}
  }

  // Save deleted item IDs to SharedPreferences
  Future<void> _saveDeletedItems() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setStringList(_deletedItemsKey, _deletedItemIds.toList());
    } catch (e) {}
  }

  // Update items list, filtering out deleted items
  void _updateItems() {
    _items = widget.items
        .where((item) => !_deletedItemIds.contains(item['id']?.toString()))
        .toList();
    // Save the updated list of deleted items
    _saveDeletedItems();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    // Save deleted items when the widget is disposed
    _saveDeletedItems();
    super.dispose();
  }

  String _timeAgoFromMs(int? createdAtMs, String fallback) {
    if (createdAtMs == null) return fallback;
    final now = DateTime.now().millisecondsSinceEpoch;
    final diffMs = now - createdAtMs;
    if (diffMs < 0) return 'Just now';
    final d = Duration(milliseconds: diffMs);
    if (d.inSeconds < 60) return 'Just now';
    if (d.inMinutes < 60) return '${d.inMinutes} min ago';
    if (d.inHours < 24) return '${d.inHours} hr ago';
    if (d.inDays < 7) return '${d.inDays} day${d.inDays == 1 ? '' : 's'} ago';
    final dt = DateTime.fromMillisecondsSinceEpoch(createdAtMs);
    final mm = dt.month.toString().padLeft(2, '0');
    final dd = dt.day.toString().padLeft(2, '0');
    return '$mm/$dd/${dt.year}';
  }

  Future<String?> _getServerRoot() async {
    try {
      final sp = await SharedPreferences.getInstance();
      String? saved = sp.getString('server_root')?.trim();
      saved ??= sp.getString('detected_server_url')?.trim();
      if (saved == null || saved.isEmpty) return null;
      if (saved.endsWith('/api')) saved = saved.substring(0, saved.length - 4);
      return saved;
    } catch (_) {
      return null;
    }
  }

  // Check if activity item is encrypted (payroll document)
  bool _isActivityEncrypted(Map<String, dynamic> activity) {
    try {
      final title = (activity['title'] ?? '').toString().toLowerCase();
      final docType = (activity['type'] ?? '').toString().toLowerCase();
      final subtitle = (activity['subtitle'] ?? '').toString().toLowerCase();

      // Check for encryption metadata in title or type
      if (title.contains('encrypted') || docType.contains('encrypted')) {
        return true;
      }

      // Check for payroll keywords
      final encryptionService = EncryptionService.instance;
      return encryptionService.isPayrollDocument(title, subtitle);
    } catch (e) {
      return false;
    }
  }

  Future<bool> _deleteActivityPermanent(Map<String, dynamic> activity) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return false;
      final id = activity['id']?.toString();
      if (id == null || id.isEmpty) return false;

      // 1) Primary: delete the underlying tracking/document entry (Recent Activity / Uploads)
      final candidates = <Uri>[
        Uri.parse('$root/lib/OCR(UPDATED)/tracking.php?delete_id=$id'),
        Uri.parse('$root/../lib/OCR(UPDATED)/tracking.php?delete_id=$id'),
      ];
      for (final uri in candidates) {
        try {
          final r = await http.get(uri).timeout(const Duration(seconds: 8));
          if (r.statusCode < 400) return true; // success or redirect
        } catch (_) {}
      }

      try {
        final uri = Uri.parse('$root/api/recent_activity.php')
            .replace(queryParameters: {'action': 'delete', 'id': id});
        final r = await http.post(uri).timeout(const Duration(seconds: 8));
        if (r.statusCode < 400) return true;
      } catch (_) {}

      // 3) As a last resort, also attempt to delete the notification entry
      // associated with this activity so the notification list stays in sync.
      try {
        final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php')
            .replace(queryParameters: {'id': id});
        final r = await http.delete(uri).timeout(const Duration(seconds: 8));
        if (r.statusCode < 400) return true;
      } catch (_) {}

      try {
        final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/notifications.php');
        final r = await http.post(uri, body: {
          'action': 'delete',
          'id': id
        }).timeout(const Duration(seconds: 8));
        if (r.statusCode < 400) return true;
      } catch (_) {}
    } catch (_) {}
    return false;
  }

  Future<bool> _showDeleteConfirmation(
      int index, Map<String, dynamic> activity) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete Item'),
        content: const Text(
            'Are you sure you want to delete this item? This action cannot be undone.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(
              foregroundColor: Colors.red,
            ),
            child: const Text('Delete'),
          ),
        ],
      ),
    );

    if (confirmed == true && mounted) {
      final ok = await _deleteActivityPermanent(activity);
      if (ok && mounted) {
        setState(() {
          _items.removeAt(index);
        });
        _changed = true;
        if (!mounted) return true;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Item deleted permanently')),
        );
        return true;
      } else {
        if (!mounted) return false;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Failed to delete. Please try again.')),
        );
        return false;
      }
    }
    return false;
  }

  Future<void> _showActivityInfo(Map<String, dynamic> activity) async {
    final String title = activity['title']?.toString() ?? 'Document';
    final String subtitle = activity['subtitle']?.toString() ?? '';
    final String? recipientDepartment =
        activity['recipientDepartment']?.toString();
    final String? trackingId = activity['trackingId']?.toString();
    final String? docHash = activity['docHash']?.toString();
    final String? mobileTimestamp = activity['mobileTimestamp']?.toString();

    String line(String label, String? value) {
      final v = (value ?? '').trim();
      return '$label: ${v.isEmpty ? 'N/A' : v}';
    }

    String? normalize(dynamic value) {
      if (value == null) return null;
      final str = value.toString().trim();
      return str.isEmpty ? null : str;
    }

    final int? numericTrackingId = int.tryParse((trackingId ?? '').trim());
    final bool hasIdentity =
        (numericTrackingId != null && numericTrackingId > 0) ||
            (normalize(docHash) != null) ||
            (normalize(mobileTimestamp) != null);
    final int activityId =
        int.tryParse((activity['id'] ?? '').toString().trim()) ?? 0;
    final bool hasRouteAnchor = activityId > 0;
    final bool canRoute = (recipientDepartment ?? '').trim().isNotEmpty &&
        (hasIdentity || hasRouteAnchor);
    final bool isEncrypted = _isActivityEncrypted(activity);

    await showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Document Info'),
        content: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
              if (subtitle.trim().isNotEmpty) ...[
                const SizedBox(height: 6),
                Text(subtitle),
              ],
              const SizedBox(height: 12),
              Text(line('Recipient Department', recipientDepartment)),
              Text(line('Tracking ID', trackingId)),
              Text(line('Doc Hash', docHash)),
              Text(line('Mobile Timestamp', mobileTimestamp)),
              if (isEncrypted) ...[
                const SizedBox(height: 10),
                const Text(
                    'This document is encrypted and cannot be opened from this list.'),
              ],
            ],
          ),
        ),
        actions: [
          if (!isEncrypted)
            TextButton(
              onPressed: () async {
                Navigator.of(context).pop();
                await _openFromActivity(activity);
              },
              child: const Text('Open'),
            ),
          if (canRoute)
            TextButton(
              onPressed: () async {
                Navigator.of(context).pop();

                final String? fileUrl = activity['fileUrl']?.toString();
                final String? storedFilePath = activity['filePath']?.toString();
                final resolvedFilePath =
                    normalize(storedFilePath) ?? normalize(fileUrl) ?? '';
                final int? id = activity['id'] is int
                    ? activity['id'] as int
                    : int.tryParse(activity['id']?.toString() ?? '');

                if (!canRoute) return;

                await _openRouteDialogWithDetails(
                  initialReceiverDept: recipientDepartment!.trim(),
                  docType: activity['subtitle']?.toString() ??
                      activity['title']?.toString() ??
                      'Document',
                  fileName: activity['subtitle']?.toString() ?? '',
                  filePath: resolvedFilePath,
                  mobileTimestamp: normalize(activity['mobileTimestamp']),
                  docHash: normalize(activity['docHash']),
                  trackingId: normalize(activity['trackingId']),
                  activityId: id,
                );
              },
              child: const Text('Route'),
            ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  Future<void> _confirmDeleteSelected() async {
    if (_selectedIds.isEmpty) return;
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete Selected'),
        content: Text(
            'Delete ${_selectedIds.length} selected item(s)? This action cannot be undone.'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(context).pop(true),
            style: TextButton.styleFrom(foregroundColor: Colors.red),
            child: const Text('Confirm'),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    final selected = Set<String>.from(_selectedIds);
    bool anyDeleted = false;
    for (final id in selected) {
      final idx = _items.indexWhere((it) => (it['id']?.toString() ?? '') == id);
      if (idx == -1) continue;
      final ok = await _deleteActivityPermanent(_items[idx]);
      if (!mounted) return;
      if (ok) {
        anyDeleted = true;
        setState(() {
          _items.removeAt(idx);
        });
      }
    }

    if (!mounted) return;
    setState(() {
      _selectedIds.clear();
      _selectionMode = false;
    });
    if (anyDeleted) {
      _changed = true;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Deleted selected items')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    return WillPopScope(
      onWillPop: () async {
        Navigator.pop(context, _changed);
        return false;
      },
      child: Scaffold(
        appBar: AppBar(
          title:
              Text(widget.title, style: const TextStyle(color: Colors.white)),
          backgroundColor: const Color(0xFF6868AC),
          foregroundColor: Colors.white,
          elevation: 0,
          actions: [
            IconButton(
              tooltip: _selectionMode ? 'Cancel multi-select' : 'Multi-select',
              onPressed: () {
                setState(() {
                  _selectionMode = !_selectionMode;
                  _selectedIds.clear();
                });
              },
              icon: Icon(_selectionMode ? Icons.close : Icons.select_all),
            ),
            if (_selectionMode)
              IconButton(
                tooltip: 'Delete selected',
                onPressed: _selectedIds.isEmpty ? null : _confirmDeleteSelected,
                icon: const Icon(Icons.delete_outline),
              ),
          ],
        ),
        body: items.isEmpty
            ? Center(
                child: Padding(
                  padding: const EdgeInsets.all(24),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(
                        Icons.upload_file_outlined,
                        size: 64,
                        color: Theme.of(context)
                            .colorScheme
                            .onSurface
                            .withOpacity(0.4),
                      ),
                      const SizedBox(height: 16),
                      Text(
                        'No recent uploads',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.w500,
                          color: Theme.of(context).colorScheme.onSurface,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Your deleted items will appear here when you upload new files',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 14,
                          color: Theme.of(context)
                              .colorScheme
                              .onSurface
                              .withOpacity(0.6),
                        ),
                      ),
                    ],
                  ),
                ),
              )
            : ListView.separated(
                padding: const EdgeInsets.all(16),
                itemCount: items.length,
                separatorBuilder: (_, __) => Divider(
                  height: 1,
                  color: Theme.of(context).dividerColor,
                ),
                itemBuilder: (context, index) {
                  final activity = items[index];
                  final IconData icon = (activity['icon'] is IconData)
                      ? activity['icon'] as IconData
                      : Icons.description_outlined;
                  final String title =
                      activity['title']?.toString() ?? 'Upload';
                  final String subtitle =
                      activity['subtitle']?.toString() ?? '';
                  final String timeFallback =
                      activity['time']?.toString() ?? '';
                  final String time = _timeAgoFromMs(
                      activity['createdAtMs'] as int?, timeFallback);
                  final String rowId = activity['id']?.toString() ?? '';
                  final bool selected = _selectedIds.contains(rowId);
                  return Material(
                    color: Theme.of(context).cardColor,
                    child: InkWell(
                      onTap: () {
                        if (_selectionMode) {
                          setState(() {
                            if (selected) {
                              _selectedIds.remove(rowId);
                            } else if (rowId.isNotEmpty) {
                              _selectedIds.add(rowId);
                            }
                          });
                          return;
                        }
                        _showActivityInfo(activity);
                      },
                      onLongPress: () {
                        if (_selectionMode) return;
                        _showDeleteConfirmation(index, activity);
                      },
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                            vertical: 12, horizontal: 12),
                        child: Row(
                          children: [
                            if (_selectionMode) ...[
                              Checkbox(
                                value: selected,
                                onChanged: (v) {
                                  setState(() {
                                    if (v == true && rowId.isNotEmpty) {
                                      _selectedIds.add(rowId);
                                    } else {
                                      _selectedIds.remove(rowId);
                                    }
                                  });
                                },
                              ),
                              const SizedBox(width: 4),
                            ],
                            Container(
                              width: 48,
                              height: 48,
                              decoration: BoxDecoration(
                                color:
                                    const Color(0xFF6868AC).withOpacity(0.08),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Icon(icon,
                                  color: const Color(0xFF6868AC), size: 24),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Row(
                                    children: [
                                      Expanded(
                                        child: Text(
                                          title,
                                          style: TextStyle(
                                            fontSize: 15,
                                            fontWeight: FontWeight.w600,
                                            color: Theme.of(context)
                                                .colorScheme
                                                .onSurface,
                                          ),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                      if (_isActivityEncrypted(activity)) ...[
                                        const SizedBox(width: 8),
                                        Container(
                                          padding: const EdgeInsets.symmetric(
                                              horizontal: 6, vertical: 2),
                                          decoration: BoxDecoration(
                                            color: Colors.green.shade600,
                                            borderRadius:
                                                BorderRadius.circular(10),
                                          ),
                                          child: const Row(
                                            mainAxisSize: MainAxisSize.min,
                                            children: [
                                              Icon(Icons.lock,
                                                  size: 10,
                                                  color: Colors.white),
                                              SizedBox(width: 2),
                                              Text(
                                                'Encrypted',
                                                style: TextStyle(
                                                  fontSize: 9,
                                                  color: Colors.white,
                                                  fontWeight: FontWeight.w600,
                                                ),
                                              ),
                                            ],
                                          ),
                                        ),
                                      ],
                                    ],
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    subtitle,
                                    style: TextStyle(
                                      fontSize: 13,
                                      color: Theme.of(context)
                                          .colorScheme
                                          .onSurface
                                          .withOpacity(0.7),
                                    ),
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                  const SizedBox(height: 8),
                                  Text(
                                    time,
                                    style: TextStyle(
                                      fontSize: 12,
                                      color: Theme.of(context)
                                          .colorScheme
                                          .onSurface
                                          .withOpacity(0.6),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(width: 8),
                            if ((activity['recipientDepartment']
                                    ?.toString()
                                    .isEmpty ??
                                true))
                              Text(
                                time,
                                style: TextStyle(
                                  fontSize: 12,
                                  color: Theme.of(context)
                                      .colorScheme
                                      .onSurface
                                      .withOpacity(0.6),
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
      ),
    );
  }
}

/// Dialog for capturing multiple images for final document
class _MultiImageCaptureDialog extends StatefulWidget {
  final int trackingId;

  const _MultiImageCaptureDialog({required this.trackingId});

  @override
  State<_MultiImageCaptureDialog> createState() =>
      _MultiImageCaptureDialogState();
}

class _MultiImageCaptureDialogState extends State<_MultiImageCaptureDialog> {
  final List<File> _capturedImages = [];
  bool _isCapturing = false;
  final DateTime _openedAt = DateTime.now();

  Future<void> _captureImage() async {
    setState(() => _isCapturing = true);
    try {
      // Prefer document-scanner UI (auto-detect edges + crop) for better final-doc quality.
      // Falls back to plain camera if scanner isn't available.
      try {
        final paths = await CunningDocumentScanner.getPictures() ?? <String>[];
        if (paths.isNotEmpty && mounted) {
          final fresh = <File>[];
          for (final p in paths) {
            try {
              final f = File(p);
              if (!await f.exists()) continue;
              final st = await f.stat();
              // Guard against stale images returned by the scanner plugin.
              if (st.modified
                  .isBefore(_openedAt.subtract(const Duration(seconds: 2)))) {
                continue;
              }
              fresh.add(f);
            } catch (_) {
              // ignore
            }
          }

          if (fresh.isNotEmpty) {
            setState(() {
              for (final f in fresh) {
                final exists = _capturedImages.any((e) => e.path == f.path);
                if (!exists) _capturedImages.add(f);
              }
            });
            return;
          }
        }
      } catch (e) {}

      final picker = ImagePicker();
      final XFile? file = await picker.pickImage(
        source: ImageSource.camera,
        imageQuality: 90,
      );
      if (file != null && mounted) {
        setState(() {
          _capturedImages.add(File(file.path));
        });
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Camera error: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isCapturing = false);
    }
  }

  void _removeImage(int index) {
    setState(() {
      final removed = _capturedImages.removeAt(index);
      removed.delete().catchError((_) => removed);
    });
  }

  void _previewImage(File imageFile, int index) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => Scaffold(
          backgroundColor: Colors.black,
          appBar: AppBar(
            backgroundColor: Colors.black,
            foregroundColor: Colors.white,
            title: Text('Page ${index + 1}'),
            actions: [
              IconButton(
                icon: const Icon(Icons.delete, color: Colors.red),
                onPressed: () {
                  Navigator.of(context).pop();
                  _removeImage(index);
                },
              ),
            ],
          ),
          body: Center(
            child: InteractiveViewer(
              child: Image.file(imageFile),
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Row(
        children: [
          Icon(Icons.camera_alt, color: Color(0xFF6868AC)),
          SizedBox(width: 8),
          Expanded(
            child: Text(
              'Capture Final Document',
              style: TextStyle(fontSize: 18),
            ),
          ),
        ],
      ),
      content: SizedBox(
        width: double.maxFinite,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'Take photos of all pages. Tap image to preview.',
              style: TextStyle(
                fontSize: 13,
                color: Colors.grey.shade600,
              ),
            ),
            const SizedBox(height: 16),
            // Image count and preview
            if (_capturedImages.isNotEmpty) ...[
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.green.shade50,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    Icon(Icons.check_circle, color: Colors.green.shade700),
                    const SizedBox(width: 8),
                    Text(
                      '${_capturedImages.length} page(s) captured',
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        color: Colors.green.shade700,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              // Thumbnail grid - tap to preview
              SizedBox(
                height: 80,
                child: ListView.builder(
                  scrollDirection: Axis.horizontal,
                  itemCount: _capturedImages.length,
                  itemBuilder: (ctx, index) {
                    return Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: GestureDetector(
                        onTap: () =>
                            _previewImage(_capturedImages[index], index),
                        child: Stack(
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(8),
                              child: Image.file(
                                _capturedImages[index],
                                width: 60,
                                height: 80,
                                fit: BoxFit.cover,
                                cacheWidth: 120, // Optimize memory
                              ),
                            ),
                            Positioned(
                              top: 0,
                              right: 0,
                              child: GestureDetector(
                                onTap: () => _removeImage(index),
                                child: Container(
                                  padding: const EdgeInsets.all(2),
                                  decoration: const BoxDecoration(
                                    color: Colors.red,
                                    shape: BoxShape.circle,
                                  ),
                                  child: const Icon(
                                    Icons.close,
                                    size: 14,
                                    color: Colors.white,
                                  ),
                                ),
                              ),
                            ),
                            Positioned(
                              bottom: 4,
                              left: 4,
                              child: Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 4, vertical: 2),
                                decoration: BoxDecoration(
                                  color: Colors.black54,
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: Text(
                                  '${index + 1}',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              ),
            ] else ...[
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Colors.grey.shade300),
                ),
                child: Column(
                  children: [
                    Icon(Icons.photo_camera,
                        size: 48, color: Colors.grey.shade400),
                    const SizedBox(height: 8),
                    Text(
                      'No pages captured yet',
                      style: TextStyle(color: Colors.grey.shade600),
                    ),
                  ],
                ),
              ),
            ],
            const SizedBox(height: 16),
            // Add page button
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: _isCapturing ? null : _captureImage,
                icon: _isCapturing
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.add_a_photo),
                label: Text(_capturedImages.isEmpty
                    ? 'Capture First Page'
                    : 'Add Another Page'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF6868AC),
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () {
            // Clean up temp files
            for (final f in _capturedImages) {
              f.delete().catchError((_) => f);
            }
            Navigator.of(context).pop(null);
          },
          child: const Text('Cancel'),
        ),
        ElevatedButton(
          onPressed: _capturedImages.isEmpty
              ? null
              : () {
                  Navigator.of(context).pop(_capturedImages);
                },
          style: ElevatedButton.styleFrom(
            backgroundColor: Colors.green.shade700,
            foregroundColor: Colors.white,
          ),
          child: Text(_capturedImages.isEmpty
              ? 'Complete'
              : 'Complete (${_capturedImages.length} pages)'),
        ),
      ],
    );
  }
}

/// PDF Preview Dialog - Uses same approach as doc-scanner project
/// Shows PdfPreview widget before uploading
class _PdfPreviewDialog extends StatefulWidget {
  final String pdfFilePath;
  final int pageCount;
  final List<String>? pageTexts;

  const _PdfPreviewDialog({
    required this.pdfFilePath,
    required this.pageCount,
    this.pageTexts,
  });

  @override
  State<_PdfPreviewDialog> createState() => _PdfPreviewDialogState();
}

class _PdfPreviewDialogState extends State<_PdfPreviewDialog> {
  late final List<TextEditingController> _controllers;
  int _currentOcrPage = 0;

  @override
  void initState() {
    super.initState();
    final texts = widget.pageTexts ?? const <String>[];
    _controllers = List<TextEditingController>.generate(
      widget.pageCount,
      (i) => TextEditingController(text: i < texts.length ? texts[i] : ''),
    );
  }

  @override
  void dispose() {
    for (final c in _controllers) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final hasMultiplePages = _controllers.length > 1;
    return Dialog(
      insetPadding: const EdgeInsets.all(16),
      child: Container(
        width: double.maxFinite,
        height: MediaQuery.of(context).size.height * 0.8,
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            // Header
            Row(
              children: [
                Icon(Icons.picture_as_pdf, color: Colors.red.shade700),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'PDF Preview (${widget.pageCount} pages)',
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => Navigator.of(context).pop(null),
                ),
              ],
            ),
            const Divider(),
            if (_controllers.isNotEmpty) ...[
              Row(
                children: [
                  const Expanded(
                    child: Text(
                      'Extracted Text',
                      style: TextStyle(fontWeight: FontWeight.w600),
                    ),
                  ),
                  TextButton.icon(
                    onPressed: () async {
                      final text = _controllers
                          .map((c) => c.text.trim())
                          .where((t) => t.isNotEmpty)
                          .join('\n\n')
                          .trim();
                      await Clipboard.setData(ClipboardData(text: text));
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('OCR copied')),
                        );
                      }
                    },
                    icon: const Icon(Icons.copy, size: 16),
                    label: const Text('Copy'),
                  ),
                ],
              ),
              const SizedBox(height: 4),
              // Page navigation tabs (only if multi-page)
              if (hasMultiplePages)
                SizedBox(
                  height: 34,
                  child: Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.chevron_left, size: 20),
                        padding: EdgeInsets.zero,
                        constraints: const BoxConstraints(minWidth: 32),
                        onPressed: _currentOcrPage > 0
                            ? () => setState(() => _currentOcrPage--)
                            : null,
                      ),
                      Expanded(
                        child: SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: Row(
                            children: List.generate(_controllers.length, (i) {
                              final selected = i == _currentOcrPage;
                              return Padding(
                                padding:
                                    const EdgeInsets.symmetric(horizontal: 2),
                                child: ChoiceChip(
                                  label: Text('Page ${i + 1}',
                                      style: TextStyle(
                                          fontSize: 11,
                                          fontWeight: selected
                                              ? FontWeight.w700
                                              : FontWeight.w400)),
                                  selected: selected,
                                  onSelected: (_) =>
                                      setState(() => _currentOcrPage = i),
                                  visualDensity: VisualDensity.compact,
                                  materialTapTargetSize:
                                      MaterialTapTargetSize.shrinkWrap,
                                ),
                              );
                            }),
                          ),
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.chevron_right, size: 20),
                        padding: EdgeInsets.zero,
                        constraints: const BoxConstraints(minWidth: 32),
                        onPressed: _currentOcrPage < _controllers.length - 1
                            ? () => setState(() => _currentOcrPage++)
                            : null,
                      ),
                    ],
                  ),
                ),
              const SizedBox(height: 4),
              // Single page OCR editor (current page)
              Container(
                height: 130,
                decoration: BoxDecoration(
                  border: Border.all(color: Theme.of(context).dividerColor),
                  borderRadius: BorderRadius.circular(8),
                ),
                padding: const EdgeInsets.all(8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (!hasMultiplePages)
                      Text(
                        'Page ${_currentOcrPage + 1}',
                        style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    if (!hasMultiplePages) const SizedBox(height: 4),
                    Expanded(
                      child: TextField(
                        controller: _controllers[_currentOcrPage],
                        minLines: null,
                        maxLines: null,
                        expands: true,
                        textAlignVertical: TextAlignVertical.top,
                        decoration: InputDecoration(
                          isDense: true,
                          border: const OutlineInputBorder(),
                          hintText:
                              'Edit OCR text for page ${_currentOcrPage + 1}...',
                        ),
                      ),
                    ),
                  ],
                ),
              ),
              const Divider(),
            ],
            // PDF Preview using flutter_pdfview (more reliable on Android)
            Expanded(
              child: PDFView(
                filePath: widget.pdfFilePath,
                enableSwipe: true,
                swipeHorizontal: true,
                autoSpacing: true,
                pageFling: true,
                onError: (error) {
                  // Keep UI intact; just close and report a snack if preview fails
                  Navigator.of(context).pop(null);
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text('PDF preview error: $error')),
                  );
                },
              ),
            ),
            const SizedBox(height: 16),
            // Action buttons
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(null),
                    child: const Text('Cancel'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: () {
                      final out = _controllers.map((c) => c.text).toList();
                      Navigator.of(context).pop(out);
                    },
                    icon: const Icon(Icons.cloud_upload),
                    label: const Text('Upload PDF'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.green.shade700,
                      foregroundColor: Colors.white,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
