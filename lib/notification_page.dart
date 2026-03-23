import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_pdfview/flutter_pdfview.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import 'dashboard_document_preview.dart';

class NotificationPage extends StatefulWidget {
  const NotificationPage({super.key});

  @override
  State<NotificationPage> createState() => _NotificationPageState();
}

class _NotificationPageState extends State<NotificationPage>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  final ScrollController _scrollController = ScrollController();
  final List<Map<String, dynamic>> _notifications = [];
  bool _isLoading = true; // initial skeletons
  bool _isFetchingMore = false;
  bool _hasMore = true;
  int _page = 1;
  String _tab = 'all'; // all | unread | mentions
  // Filters
  String? _filterDept;
  String? _filterStatus;
  String? _filterType;
  DateTime? _filterStart;
  DateTime? _filterEnd;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    _tabController.addListener(() {
      if (_tabController.indexIsChanging) return;
      setState(() {
        _tab = _tabFromIndex(_tabController.index);
        _page = 1;
        _hasMore = true;
        _notifications.clear();
        _isLoading = true;
      });
      _fetchPage(reset: true);
    });
    _scrollController.addListener(_onScroll);
    _fetchPage(reset: true);
  }

  @override
  void dispose() {
    _tabController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  String _tabFromIndex(int i) {
    switch (i) {
      case 1:
        return 'unread';
      case 2:
        return 'mentions';
      default:
        return 'all';
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
      case 'comment':
        return Icons.comment;
      default:
        return Icons.notifications;
    }
  }

  Color _colorForType(String? t) {
    switch (t) {
      case 'upload':
        return const Color(0xFF6868AC);
      case 'document_upload':
        return Colors.indigo;
      case 'approval':
        return Colors.orange;
      case 'comment':
        return Colors.green;
      default:
        return Colors.indigo;
    }
  }

  Future<String?> _getServerBase() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      String? root = prefs.getString('server_root');
      root ??= prefs.getString('detected_server_url');
      if (root == null) return null;
      if (root.endsWith('/api')) root = root.substring(0, root.length - 4);
      return root;
    } catch (_) {
      return null;
    }
  }

  Future<void> _fetchPage({bool reset = false}) async {
    if (_isFetchingMore) return;
    if (!_hasMore && !reset) return;
    setState(() {
      if (!reset) {
        _isFetchingMore = true;
      } else {
        _isFetchingMore = false;
      }
    });
    try {
      final base = await _getServerBase();
      if (base == null) {
        // No server known yet: show empty with a hint
        await Future.delayed(const Duration(milliseconds: 400));
        setState(() {
          _isLoading = false;
          _isFetchingMore = false;
          _hasMore = false;
        });
        return;
      }
      final prefs = await SharedPreferences.getInstance();
      final uname = (prefs.getString('user_name') ?? '').trim();
      final dept = (prefs.getString('user_department') ?? '').trim();
      // Debug logging removed

      final rootUri = Uri.parse(base);
      final notifBase = rootUri.replace(pathSegments: [
        ...rootUri.pathSegments,
        'lib',
        'OCR(UPDATED)',
        'api',
        'notifications.php',
      ]);

      // Build up to two requests depending on tab
      final List<Map<String, dynamic>> merged = [];
      Future<void> fetchInto(Map<String, String> qp) async {
        final uri = notifBase.replace(queryParameters: qp);
        // Debug logging removed
        final resp = await http.get(uri).timeout(const Duration(seconds: 10));
        // Debug logging removed
        if (resp.statusCode == 200 && resp.body.isNotEmpty) {
          final data = _safeDecode(resp.body);
          final List list =
              (data['notifications'] ?? data['data'] ?? []) as List;
          merged.addAll(list.map((e) => Map<String, dynamic>.from(e as Map)));
          // Infer hasMore if API returns many items
          if (list.length < 20) {
            _hasMore = false;
          }
        }
      }

      // Common filters
      String limit = '20';
      // Page support (best-effort)
      final common = <String, String>{'action': 'list', 'limit': limit};
      if (_filterType != null && _filterType!.isNotEmpty) {
        common['type'] = _filterType!;
      }
      if (_filterStart != null) {
        common['start'] = _filterStart!.toIso8601String().substring(0, 10);
      }
      if (_filterEnd != null) {
        common['end'] = _filterEnd!.toIso8601String().substring(0, 10);
      }
      // Status filter
      final unreadOnly = _tab == 'unread' ||
          (_filterStatus != null && _filterStatus!.toLowerCase() == 'unread');

      if (_tab == 'mentions') {
        final qp = Map<String, String>.from(common)
          ..['recipient_department'] =
              _filterDept?.isNotEmpty == true ? _filterDept! : dept;
        if (unreadOnly) qp['status'] = 'unread';
        await fetchInto(qp);
      } else {
        // All or Unread: merge user-targeted + department-targeted
        if (uname.isNotEmpty) {
          final qp1 = Map<String, String>.from(common)
            ..['recipient_username'] = uname;
          if (unreadOnly) qp1['status'] = 'unread';
          await fetchInto(qp1);
        }
        final deptFilter =
            _filterDept?.isNotEmpty == true ? _filterDept! : dept;
        if (deptFilter.isNotEmpty) {
          final qp2 = Map<String, String>.from(common)
            ..['recipient_department'] = deptFilter;
          if (unreadOnly) qp2['status'] = 'unread';
          await fetchInto(qp2);
        }
      }

      // De-duplicate by id
      final Map<String, Map<String, dynamic>> byId = {};
      for (final m in merged) {
        final key = (m['id']?.toString().isNotEmpty == true)
            ? 'id:${m['id']}'
            : 'k:${m['title']}-${m['time']}-${m['created_at']}';
        byId[key] = m;
      }
      final list = byId.values.toList();

      setState(() {
        if (reset) _notifications.clear();
        _notifications.addAll(list);
        _hasMore = list.length >= 20; // conservative
        _isLoading = false;
        _isFetchingMore = false;
        _page += 1;
      });
      if (_notifications.isEmpty && mounted) {
        // Silently handle empty state - don't spam user with snackbar
      }
    } catch (_) {
      setState(() {
        _isLoading = false;
        _isFetchingMore = false;
      });
    }
  }

  Map<String, dynamic> _safeDecode(String raw) {
    try {
      final m = jsonDecode(raw);
      if (m is Map<String, dynamic>) return m;
    } catch (_) {}
    // Strip any leading non-JSON text
    final start = raw.indexOf('{');
    final end = raw.lastIndexOf('}');
    if (start != -1 && end != -1 && end > start) {
      final slice = raw.substring(start, end + 1);
      try {
        final m = jsonDecode(slice);
        if (m is Map<String, dynamic>) return m;
      } catch (_) {}
    }
    return {'notifications': [], 'data': []};
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 300) {
      if (!_isFetchingMore && _hasMore && !_isLoading) {
        _fetchPage();
      }
    }
  }

  static const Color _themeColor = Color(0xFF6868AC);

  @override
  Widget build(BuildContext context) {
    // Count unread for badge
    final unreadCount = _notifications.where((n) {
      return (n['isRead'] ?? n['read']) != true;
    }).length;

    return Scaffold(
      backgroundColor: const Color(0xFFF5F5FA),
      appBar: AppBar(
        backgroundColor: _themeColor,
        elevation: 0,
        leading: IconButton(
          icon: const Icon(Icons.arrow_back_rounded, color: Colors.white),
          onPressed: () => Navigator.pop(context),
        ),
        title: const Text(
          'Notifications',
          style: TextStyle(
            color: Colors.white,
            fontSize: 22,
            fontWeight: FontWeight.w700,
          ),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.done_all_rounded, color: Colors.white),
            tooltip: 'Mark all as read',
            onPressed: () {
              _markAllRead();
            },
          ),
          IconButton(
            icon: const Icon(Icons.delete_sweep_rounded, color: Colors.white),
            tooltip: 'Clear all read',
            onPressed: _clearAllRead,
          ),
        ],
        systemOverlayStyle: SystemUiOverlayStyle.light,
        titleSpacing: 0,
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: Colors.white,
          indicatorWeight: 3,
          labelColor: Colors.white,
          labelStyle:
              const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
          unselectedLabelColor: Colors.white60,
          unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.w400),
          tabs: [
            const Tab(text: 'All'),
            Tab(
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text('Unread'),
                  if (unreadCount > 0) ...[
                    const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 6, vertical: 1),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.25),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        unreadCount > 99 ? '99+' : '$unreadCount',
                        style: const TextStyle(
                            fontSize: 11, fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const Tab(text: 'Mentions'),
          ],
        ),
      ),
      body: SafeArea(
        top: false,
        child: RefreshIndicator(
          color: _themeColor,
          onRefresh: () async {
            setState(() {
              _page = 1;
              _hasMore = true;
            });
            await _fetchPage(reset: true);
          },
          child: _buildList(),
        ),
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openFilterSheet,
        backgroundColor: _themeColor,
        foregroundColor: Colors.white,
        elevation: 2,
        icon: const Icon(Icons.filter_list_rounded),
        label: const Text('Filters',
            style: TextStyle(fontWeight: FontWeight.w600)),
      ),
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Container(
            width: 100,
            height: 100,
            decoration: BoxDecoration(
              color: _themeColor.withOpacity(0.08),
              shape: BoxShape.circle,
            ),
            child: Icon(
              Icons.notifications_off_rounded,
              size: 48,
              color: _themeColor.withOpacity(0.4),
            ),
          ),
          const SizedBox(height: 20),
          Text(
            'No notifications',
            style: TextStyle(
              fontSize: 18,
              color: Colors.grey.shade700,
              fontWeight: FontWeight.w600,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'You\'re all caught up!',
            style: TextStyle(
              fontSize: 14,
              color: Colors.grey.shade500,
              height: 1.4,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildList() {
    if (_isLoading && _notifications.isEmpty) {
      return ListView.builder(
        controller: _scrollController,
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 100),
        itemCount: 6,
        itemBuilder: (_, i) => _buildSkeleton(),
      );
    }
    if (_notifications.isEmpty) return _buildEmptyState();
    return ListView.builder(
      controller: _scrollController,
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 100),
      addAutomaticKeepAlives: false,
      itemCount: _notifications.length + (_isFetchingMore ? 1 : 0),
      itemBuilder: (context, index) {
        if (index >= _notifications.length) return _buildLoadingMore();
        final notification = _notifications[index];
        return _buildNotificationCard(notification, index);
      },
    );
  }

  Widget _buildSkeleton() {
    final shimmer = Colors.grey.shade200;
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: shimmer,
              borderRadius: BorderRadius.circular(14),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                    height: 13,
                    width: 160,
                    decoration: BoxDecoration(
                        color: shimmer,
                        borderRadius: BorderRadius.circular(6))),
                const SizedBox(height: 10),
                Container(
                    height: 11,
                    width: double.infinity,
                    decoration: BoxDecoration(
                        color: shimmer,
                        borderRadius: BorderRadius.circular(6))),
                const SizedBox(height: 8),
                Container(
                    height: 11,
                    width: 100,
                    decoration: BoxDecoration(
                        color: shimmer,
                        borderRadius: BorderRadius.circular(6))),
              ],
            ),
          )
        ],
      ),
    );
  }

  Widget _buildLoadingMore() {
    return const Padding(
      padding: EdgeInsets.symmetric(vertical: 16),
      child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
    );
  }

  Widget _buildNotificationCard(Map<String, dynamic> notification, int index) {
    final bool isRead =
        (notification['isRead'] ?? notification['read']) == true;
    final String type = (notification['type'] ?? '').toString();
    final IconData icon = _iconForType(type);
    final Color color = _colorForType(type);

    return Dismissible(
      key: Key('notification_$index'),
      direction: DismissDirection.horizontal,
      confirmDismiss: (dir) async {
        if (dir == DismissDirection.startToEnd) {
          // Mark read/unread
          setState(() {
            notification['isRead'] = !isRead;
          });
          _markReadOnServer(notification);
          return false; // don't remove
        } else {
          // Delete notification only (do not remove underlying document)
          final confirmed = await showDialog<bool>(
            context: context,
            builder: (ctx) => AlertDialog(
              title: const Text('Delete notification?'),
              content: const Text(
                  'This will remove this notification from your list. The underlying document will not be deleted.'),
              actions: [
                TextButton(
                    onPressed: () => Navigator.pop(ctx, false),
                    child: const Text('Cancel')),
                ElevatedButton(
                    onPressed: () => Navigator.pop(ctx, true),
                    child: const Text('Delete')),
              ],
            ),
          );
          if (confirmed != true) return false;

          final ok = await _deleteOnServer(notification);
          if (ok) {
            _showSnack('Deleted');
            return true; // remove from list visually
          } else {
            _showSnack('Failed to delete');
            return false;
          }
        }
      },
      background: _swipeBg(
          color: Colors.green, icon: Icons.mark_email_read, alignStart: true),
      secondaryBackground: _swipeBg(
          color: Colors.red, icon: Icons.delete_forever, alignStart: false),
      child: Container(
        margin: const EdgeInsets.only(bottom: 10),
        decoration: BoxDecoration(
          color: isRead ? Colors.white : Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(
            color:
                isRead ? Colors.grey.shade200 : _themeColor.withOpacity(0.25),
            width: isRead ? 1 : 1.5,
          ),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(isRead ? 0.03 : 0.06),
              blurRadius: isRead ? 6 : 10,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: () {
              setState(() {
                notification['isRead'] = true;
              });
              _markReadOnServer(notification);
              Navigator.pop(context);
            },
            borderRadius: BorderRadius.circular(14),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Icon container
                  Container(
                    width: 48,
                    height: 48,
                    decoration: BoxDecoration(
                      color: color.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Center(
                      child: Icon(icon, color: color, size: 24),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            if (!isRead)
                              Container(
                                width: 8,
                                height: 8,
                                margin: const EdgeInsets.only(right: 8),
                                decoration: const BoxDecoration(
                                  color: _themeColor,
                                  shape: BoxShape.circle,
                                ),
                              ),
                            Expanded(
                              child: Text(
                                (notification['title'] ??
                                        notification['subject'] ??
                                        'Activity')
                                    .toString(),
                                style: TextStyle(
                                  fontSize: 15,
                                  fontWeight: isRead
                                      ? FontWeight.w500
                                      : FontWeight.w700,
                                  color: Colors.grey.shade900,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Text(
                          (notification['message'] ??
                                  notification['content'] ??
                                  '')
                              .toString(),
                          style: TextStyle(
                            fontSize: 13,
                            color: Colors.grey.shade600,
                            height: 1.4,
                          ),
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            // Type badge
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 6, vertical: 1),
                              decoration: BoxDecoration(
                                color: color.withOpacity(0.1),
                                borderRadius: BorderRadius.circular(4),
                              ),
                              child: Text(
                                type.isNotEmpty
                                    ? type.replaceAll('_', ' ').toUpperCase()
                                    : 'GENERAL',
                                style: TextStyle(
                                  fontSize: 9,
                                  fontWeight: FontWeight.w700,
                                  color: color,
                                  letterSpacing: 0.5,
                                ),
                              ),
                            ),
                            const SizedBox(width: 10),
                            Icon(Icons.access_time_rounded,
                                size: 13, color: Colors.grey.shade500),
                            const SizedBox(width: 3),
                            Text(
                              (notification['time'] ??
                                      notification['time_ago'] ??
                                      notification['created_at'] ??
                                      '')
                                  .toString(),
                              style: TextStyle(
                                fontSize: 12,
                                color: Colors.grey.shade500,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  // --- Helpers and actions (moved into State) ---
  Widget _swipeBg(
      {required Color color,
      required IconData icon,
      required bool alignStart}) {
    return Container(
      alignment: alignStart ? Alignment.centerLeft : Alignment.centerRight,
      padding: EdgeInsets.only(
          left: alignStart ? 20 : 0, right: alignStart ? 0 : 20),
      margin: const EdgeInsets.only(bottom: 10),
      decoration:
          BoxDecoration(color: color, borderRadius: BorderRadius.circular(14)),
      child: Icon(icon, color: Colors.white),
    );
  }

  Future<void> _markAllRead() async {
    setState(() {
      for (var n in _notifications) {
        n['isRead'] = true;
        n['status'] = 'read';
      }
    });
    // Show floating banner that auto-fades after 2 seconds
    _showFloatingBanner('All notifications marked as read ✓');
    // Best-effort: mark each notification as read on server
    for (final n in _notifications) {
      unawaited(_markReadOnServer(n));
    }
  }

  void _showFloatingBanner(String message) {
    late OverlayEntry entry;
    final controller = ValueNotifier<double>(0.0);

    entry = OverlayEntry(
      builder: (context) => Positioned(
        top: MediaQuery.of(context).padding.top + 8,
        left: 16,
        right: 16,
        child: Material(
          color: Colors.transparent,
          child: ValueListenableBuilder<double>(
            valueListenable: controller,
            builder: (_, opacity, __) => AnimatedOpacity(
              opacity: opacity,
              duration: const Duration(milliseconds: 400),
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                decoration: BoxDecoration(
                  color: _themeColor,
                  borderRadius: BorderRadius.circular(12),
                  boxShadow: [
                    BoxShadow(
                      color: _themeColor.withOpacity(0.3),
                      blurRadius: 12,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(4),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.2),
                        shape: BoxShape.circle,
                      ),
                      child: const Icon(Icons.done_all_rounded,
                          color: Colors.white, size: 18),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        message,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                          fontSize: 14,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );

    Overlay.of(context).insert(entry);
    // Fade in
    Future.microtask(() => controller.value = 1.0);
    // Fade out after 2 seconds, then remove
    Future.delayed(const Duration(seconds: 2), () {
      controller.value = 0.0;
      Future.delayed(const Duration(milliseconds: 450), () {
        entry.remove();
        controller.dispose();
      });
    });
  }

  Future<void> _clearAllRead() async {
    final readItems = _notifications.where((n) {
      return (n['isRead'] ?? n['read']) == true ||
          (n['status']?.toString().toLowerCase() == 'read');
    }).toList();

    if (readItems.isEmpty) {
      _showSnack('No read notifications to clear');
      return;
    }

    final confirmed = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Clear read notifications?'),
            content: Text(
                'This will remove ${readItems.length} read notification(s).'),
            actions: [
              TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: const Text('Cancel')),
              FilledButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: const Text('Clear')),
            ],
          ),
        ) ??
        false;

    if (!confirmed) return;

    final Set<String> readIds =
        readItems.map((n) => (n['id'] ?? '').toString()).toSet();

    setState(() {
      _notifications
          .removeWhere((n) => readIds.contains((n['id'] ?? '').toString()));
    });

    int deleted = 0;
    for (final n in readItems) {
      final ok = await _deleteOnServer(n);
      if (ok) deleted++;
    }

    _showSnack('Cleared $deleted read notification(s)');
  }

  Future<void> _markReadOnServer(Map<String, dynamic> n) async {
    try {
      final id = n['id'];
      if (id == null) return;
      final base = await _getServerBase();
      if (base == null) return;
      final rootUri = Uri.parse(base);
      final uri = rootUri.replace(pathSegments: [
        ...rootUri.pathSegments,
        'lib',
        'OCR(UPDATED)',
        'api',
        'notifications.php'
      ], queryParameters: {
        'id': id.toString()
      });
      try {
        // Try PUT JSON
        await http
            .put(
              uri,
              headers: {'Content-Type': 'application/json'},
              body: jsonEncode({'status': 'read'}),
            )
            .timeout(const Duration(seconds: 6));
      } catch (_) {
        // Fallback to form POST
        await http.post(uri, body: {
          'action': 'update',
          'id': id.toString(),
          'status': 'read'
        }).timeout(const Duration(seconds: 6));
      }
    } catch (_) {}
  }

  Future<void> _archiveOnServer(Map<String, dynamic> n) async {
    // Placeholder for archive endpoint (not provided). Just show a toast.
    _showSnack('Archived');
  }

  // Delete a notification on the server (do NOT delete underlying document)
  Future<bool> _deleteOnServer(Map<String, dynamic> n) async {
    try {
      final id = n['id'];
      if (id == null) return false;
      final base = await _getServerBase();
      if (base == null) return false;

      // 1) notifications.php via DELETE
      final notifBase = '$base/lib/OCR(UPDATED)/api/notifications.php';
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

      // 2) notifications.php via POST action=delete
      try {
        final resp = await http.post(Uri.parse(notifBase), body: {
          'action': 'delete',
          'id': id.toString()
        }).timeout(const Duration(seconds: 8));
        if (resp.statusCode >= 200 && resp.statusCode < 300) return true;
        try {
          final data = jsonDecode(resp.body);
          if (data is Map && (data['success'] == true)) return true;
        } catch (_) {}
      } catch (_) {}

      // 3) notifications.php via GET action=delete (last resort on very simple hosts)
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

  Future<void> _openDetails(Map<String, dynamic> n) async {
    try {
      final String type = (n['type'] ?? '').toString();
      final String title =
          (n['title'] ?? n['subject'] ?? 'Document').toString();
      final String message = (n['message'] ?? n['content'] ?? '').toString();
      final String? fileUrl = (n['file_url'] ?? n['url'])?.toString();
      final String recipientDept =
          (n['recipient_department'] ?? n['department'] ?? '').toString();

      // Parse name from message like "Type • Name"
      String name = message.trim();
      if (name.contains('•')) {
        final parts = name.split('•');
        if (parts.length >= 2) name = parts.last.trim();
      }
      if (name.isEmpty) name = title;

      if (type == 'mobile_message' && recipientDept.isNotEmpty) {
        await _openAllenDocumentDetail(
          subtitle: message.isNotEmpty ? message : title,
          recipientDepartment: recipientDept,
          fileUrl: fileUrl,
        );
        return;
      }
      await _openActivityFileOrPreview(
        fileUrl: fileUrl,
        fileName: name,
        recipientDepartment: recipientDept.isNotEmpty ? recipientDept : null,
      );
    } catch (e) {
      _showSnack('Open failed: $e');
    }
  }

  // --- Open helpers (aligned with Dashboard logic) ---
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
              if (!ok) _showSnack('No app available to open this file');
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

      final base = await _getServerBase();
      if (base == null) throw 'No server root';
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
            '$base/Archive/$dept/$v',
            '$base/lib/Archive/$dept/$v',
            '$base/lib/OCR(UPDATED)/Archive/$dept/$v',
            '$base/uploads/$dept/$v',
            '$base/Uploads/$dept/$v',
            '$base/flutter_application_7/Archive/$dept/$v',
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
      _showSnack('Invalid link: $e');
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
      final dept = recipientDepartment.trim();
      if (name.isEmpty) throw 'Missing info';

      // If fileUrl points to a local Android/app file, open it directly
      if (fileUrl != null && fileUrl.trim().isNotEmpty) {
        final trimmed = fileUrl.trim();
        final looksLocal = trimmed.startsWith('file://') ||
            trimmed.startsWith('/data/') ||
            trimmed.contains('/app_flutter/');
        if (looksLocal) {
          final localPath = trimmed.startsWith('file://')
              ? Uri.parse(trimmed).toFilePath()
              : trimmed;
          if (!mounted) return;
          await Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => DashboardDocumentPreview(
                title: name,
                imageUrl: localPath,
                ocrUrl: null,
              ),
            ),
          );
          return;
        }
      }

      // Otherwise, fall back to server-based lookup (as before)
      final base = await _getServerBase();
      if (base == null) throw 'No server root';
      if (dept.isEmpty) throw 'Missing info';

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
            '$base/Archive/$dept/$v',
            '$base/lib/Archive/$dept/$v',
            '$base/lib/OCR(UPDATED)/Archive/$dept/$v',
            '$base/uploads/$dept/$v',
            '$base/Uploads/$dept/$v',
            '$base/flutter_application_7/Archive/$dept/$v',
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
      _showSnack('Open failed: $e');
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
      _showSnack('PDF open error: $e');
    }
  }

  Future<void> _previewImage(String urlOrPath, {String? title}) async {
    try {
      final f = File(urlOrPath);
      if (await f.exists()) {
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
      _showSnack('Preview error: $e');
    }
  }

  Future<void> _openUrl(String url) async {
    try {
      String u = url.trim();
      if (u.isEmpty) throw 'Empty link';
      if (u.startsWith('http://') || u.startsWith('https://')) {
        final ok =
            await launchUrl(Uri.parse(u), mode: LaunchMode.externalApplication);
        if (!ok) _showSnack('Could not open link');
        return;
      }
      if (u.startsWith('/')) {
        final base = await _getServerBase();
        if (base != null) {
          String seg = u;
          const proj = '/flutter_application_7';
          if (seg.startsWith(proj)) {
            seg = seg.substring(proj.length);
            if (!seg.startsWith('/')) seg = '/$seg';
          }
          final httpUrl = '$base$seg';
          final ok = await launchUrl(Uri.parse(httpUrl),
              mode: LaunchMode.externalApplication);
          if (!ok) _showSnack('Could not open link');
          return;
        }
      }
      final file = File(u);
      if (await file.exists()) {
        final ok = await launchUrl(Uri.file(file.path),
            mode: LaunchMode.externalApplication);
        if (!ok) _showSnack('No app available to open this file');
        return;
      }
      final ok =
          await launchUrl(Uri.parse(u), mode: LaunchMode.externalApplication);
      if (!ok) _showSnack('Could not open link');
    } catch (e) {
      _showSnack('Invalid link: $e');
    }
  }

  void _showSnack(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
  }

  Future<void> _openFilterSheet() async {
    String? dept = _filterDept;
    String? status = _filterStatus;
    String? dtype = _filterType;
    DateTime? start = _filterStart;
    DateTime? end = _filterEnd;

    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(16))),
      builder: (ctx) {
        return Padding(
          padding:
              EdgeInsets.only(bottom: MediaQuery.of(ctx).viewInsets.bottom),
          child: StatefulBuilder(builder: (ctx, setS) {
            return SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Filters',
                      style:
                          TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 12),
                  Row(children: [
                    const Text('Department: '),
                    const SizedBox(width: 8),
                    Expanded(
                        child: DropdownButton<String>(
                      isExpanded: true,
                      value: dept,
                      hint: const Text('Any'),
                      items: const [
                        DropdownMenuItem(value: 'CACCO', child: Text('CACCO')),
                        DropdownMenuItem(value: 'CADO', child: Text('CADO')),
                        DropdownMenuItem(value: 'CBO', child: Text('CBO')),
                        DropdownMenuItem(value: 'CPDO', child: Text('CPDO')),
                        DropdownMenuItem(value: 'CTO', child: Text('CTO')),
                        DropdownMenuItem(value: 'GSO', child: Text('GSO')),
                      ],
                      onChanged: (v) {
                        setS(() {
                          dept = v;
                        });
                      },
                    )),
                  ]),
                  const SizedBox(height: 8),
                  Row(children: [
                    const Text('Status: '),
                    const SizedBox(width: 8),
                    Expanded(
                        child: DropdownButton<String>(
                      isExpanded: true,
                      value: status,
                      hint: const Text('Any'),
                      items: const [
                        DropdownMenuItem(
                            value: 'Pending', child: Text('Pending')),
                        DropdownMenuItem(
                            value: 'In Review', child: Text('In Review')),
                        DropdownMenuItem(
                            value: 'Approved', child: Text('Approved')),
                        DropdownMenuItem(
                            value: 'Completed', child: Text('Completed')),
                      ],
                      onChanged: (v) {
                        setS(() {
                          status = v;
                        });
                      },
                    )),
                  ]),
                  const SizedBox(height: 8),
                  Row(children: [
                    const Text('Type: '),
                    const SizedBox(width: 8),
                    Expanded(
                        child: DropdownButton<String>(
                      isExpanded: true,
                      value: dtype,
                      hint: const Text('Any'),
                      items: const [
                        DropdownMenuItem(
                            value: 'Payroll', child: Text('Payroll')),
                        DropdownMenuItem(value: 'Memo', child: Text('Memo')),
                        DropdownMenuItem(
                            value: 'Travel Order', child: Text('Travel Order')),
                      ],
                      onChanged: (v) {
                        setS(() {
                          dtype = v;
                        });
                      },
                    )),
                  ]),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                          child: OutlinedButton.icon(
                        icon: const Icon(Icons.date_range),
                        label: Text(start == null
                            ? 'Start date'
                            : start.toString().substring(0, 10)),
                        onPressed: () async {
                          final picked = await showDatePicker(
                              context: ctx,
                              initialDate: start ?? DateTime.now(),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2100));
                          if (picked != null) {
                            setS(() {
                              start = picked;
                            });
                          }
                        },
                      )),
                      const SizedBox(width: 8),
                      Expanded(
                          child: OutlinedButton.icon(
                        icon: const Icon(Icons.event),
                        label: Text(end == null
                            ? 'End date'
                            : end.toString().substring(0, 10)),
                        onPressed: () async {
                          final picked = await showDatePicker(
                              context: ctx,
                              initialDate: end ?? DateTime.now(),
                              firstDate: DateTime(2020),
                              lastDate: DateTime(2100));
                          if (picked != null) {
                            setS(() {
                              end = picked;
                            });
                          }
                        },
                      )),
                    ],
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                          child: OutlinedButton(
                        onPressed: () {
                          setS(() {
                            dept = null;
                            status = null;
                            dtype = null;
                            start = null;
                            end = null;
                          });
                        },
                        child: const Text('Clear'),
                      )),
                      const SizedBox(width: 12),
                      Expanded(
                          child: ElevatedButton(
                        onPressed: () {
                          Navigator.of(ctx).pop(true);
                        },
                        child: const Text('Apply'),
                      )),
                    ],
                  ),
                  const SizedBox(height: 16),
                ],
              ),
            );
          }),
        );
      },
    );

    // Apply
    setState(() {
      _filterDept = dept;
      _filterStatus = status;
      _filterType = dtype;
      _filterStart = start;
      _filterEnd = end;
      _page = 1;
      _hasMore = true;
      _isLoading = true;
      _notifications.clear();
    });
    await _fetchPage(reset: true);
  }
}
