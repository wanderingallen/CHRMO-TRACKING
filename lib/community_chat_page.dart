import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'services/server_service.dart';

class CommunityChatPage extends StatefulWidget {
  const CommunityChatPage({super.key});

  @override
  State<CommunityChatPage> createState() => _CommunityChatPageState();
}

class _CommunityChatPageState extends State<CommunityChatPage> {
  final List<Map<String, dynamic>> _messages = [];
  final TextEditingController _controller = TextEditingController();
  final ScrollController _scroll = ScrollController();
  Timer? _poller;
  String _me = '';
  String _dept = '';
  bool _loading = false;
  int? _since; // last seen server timestamp (seconds)
  final Set<int> _seenIds = <int>{};
  final Set<int> _hiddenIds = <int>{}; // locally hidden message IDs (per user)
  int _hideBefore =
      0; // locally clear conversation before this timestamp (seconds)

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _clearAll() async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/share_feed.php');
      final r = await http.post(uri, body: {
        'action': 'clear_all',
      }).timeout(const Duration(seconds: 10));
      if (r.statusCode < 400) {
        setState(() {
          _messages.clear();
          _seenIds.clear();
          _since = null;
        });
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text('Clear conversation failed (${r.statusCode})')));
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Network error: $e')));
    }
  }

  Future<void> _init() async {
    final sp = await SharedPreferences.getInstance();
    _me = sp.getString('user_name') ?? 'Me';
    _dept = sp.getString('user_department') ?? '';
    _hideBefore = sp.getInt('community_chat_hide_before_$_me') ?? 0;
    final hiddenJson = sp.getString('community_chat_hidden_ids_$_me');
    if (hiddenJson != null && hiddenJson.isNotEmpty) {
      try {
        final List list = jsonDecode(hiddenJson) as List;
        _hiddenIds.addAll(list.map((e) => (e as num).toInt()));
      } catch (_) {}
    }
    await _load(initial: true);
    _poller = Timer.periodic(const Duration(seconds: 8), (_) => _load());
  }

  @override
  void dispose() {
    _poller?.cancel();
    _controller.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<String?> _getServerRoot() async {
    try {
      return await ServerService.getServerRoot();
    } catch (_) {
      return null;
    }
  }

  Future<void> _load({bool initial = false}) async {
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/share_feed.php')
          .replace(queryParameters: {
        'limit': '50',
        if (_since != null) 'since': _since.toString(),
      });
      final r = await http.get(uri).timeout(const Duration(seconds: 10));
      if (r.statusCode == 200 && r.body.isNotEmpty) {
        final Map<String, dynamic> jm = jsonDecode(r.body);
        final List list = (jm['items'] ?? []) as List;
        final items = list
            .map<Map<String, dynamic>>(
                (e) => Map<String, dynamic>.from(e as Map))
            .toList(); // newest first from API

        // Convert to ascending for chat UI
        final ascending = items.reversed
            .where((m) => (m['created_at'] ?? 0) > _hideBefore)
            .where((m) => !_hiddenIds.contains((m['id'] ?? 0) as int))
            .toList();

        if (_since == null) {
          // First load: take the last 50 in ascending order and set since to max created_at
          setState(() {
            _messages
              ..clear()
              ..addAll(ascending);
            _seenIds
              ..clear()
              ..addAll(ascending.map((m) => (m['id'] ?? 0) as int));
            if (items.isNotEmpty) {
              final maxTs = items
                  .map<int>((m) => (m['created_at'] ?? 0) as int)
                  .fold<int>(0, (a, b) => a > b ? a : b);
              _since = maxTs;
            }
          });
          if (initial) {
            await Future.delayed(const Duration(milliseconds: 150));
            if (_scroll.hasClients) {
              _scroll.jumpTo(_scroll.position.maxScrollExtent);
            }
          }
        } else {
          // Incremental: append only new items not seen and not locally hidden
          int appended = 0;
          setState(() {
            for (final m in ascending) {
              final id = (m['id'] ?? 0) as int;
              if (!_seenIds.contains(id) && !_hiddenIds.contains(id)) {
                _messages.add(m);
                _seenIds.add(id);
                appended++;
              }
              final ts = (m['created_at'] ?? 0) as int;
              if (ts > (_since ?? 0)) _since = ts;
            }
          });
          if (appended > 0 && mounted) {
            await Future.delayed(const Duration(milliseconds: 100));
            if (_scroll.hasClients) {
              _scroll.animateTo(
                _scroll.position.maxScrollExtent,
                duration: const Duration(milliseconds: 250),
                curve: Curves.easeOut,
              );
            }
          }
        }
      }
    } catch (_) {}
  }

  Future<void> _send() async {
    final text = _controller.text.trim();
    if (text.isEmpty) return;
    setState(() => _loading = true);
    try {
      final root = await _getServerRoot();
      if (root == null) return;
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/share_feed.php');
      http.Response r;
      try {
        r = await http
            .post(uri,
                headers: {'Content-Type': 'application/json'},
                body: jsonEncode(
                    {'username': _me, 'department': _dept, 'content': text}))
            .timeout(const Duration(seconds: 10));
      } catch (_) {
        r = await http.post(uri, body: {
          'username': _me,
          'department': _dept,
          'content': text
        }).timeout(const Duration(seconds: 10));
      }
      if (r.statusCode < 400) {
        _controller.clear();
        await _load();
        await Future.delayed(const Duration(milliseconds: 100));
        if (mounted) {
          _scroll.animateTo(
            _scroll.position.maxScrollExtent,
            duration: const Duration(milliseconds: 250),
            curve: Curves.easeOut,
          );
        }
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Send failed (${r.statusCode})')),
        );
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Network error: $e')),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title:
            const Text('Community Chat', style: TextStyle(color: Colors.white)),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          PopupMenuButton<String>(
            icon: const Icon(Icons.more_vert, color: Colors.white),
            onSelected: (v) async {
              if (v == 'clear_for_me') {
                final yes = await _confirm(
                    context,
                    'Clear conversation for you?',
                    'This will hide all messages until now on your device. Others will still see the conversation.');
                if (yes != true) return;
                await _clearConversationForMe();
              }
            },
            itemBuilder: (ctx) => [
              const PopupMenuItem(
                  value: 'clear_for_me',
                  child: Text('Clear conversation (for me)')),
            ],
          ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: ListView.builder(
              controller: _scroll,
              padding: const EdgeInsets.all(12),
              itemCount: _messages.length,
              itemBuilder: (context, index) {
                final m = _messages[index];
                final isMe = (m['username'] ?? '') == _me;
                return GestureDetector(
                  onLongPress: isMe ? () => _deleteMessage(m) : null,
                  child: _bubble(
                    isMe: isMe,
                    name: m['username']?.toString() ?? '',
                    dept: m['department']?.toString() ?? '',
                    text: m['content']?.toString() ?? '',
                    ts: (m['created_at'] ?? 0) as int,
                  ),
                );
              },
            ),
          ),
          _composer(),
        ],
      ),
    );
  }

  Widget _composer() {
    return SafeArea(
      top: false,
      child: Container(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.surface,
          boxShadow: [
            BoxShadow(
                color: Colors.black.withOpacity(0.05),
                blurRadius: 8,
                offset: const Offset(0, -2)),
          ],
        ),
        child: Row(
          children: [
            Expanded(
              child: TextField(
                controller: _controller,
                minLines: 1,
                maxLines: 4,
                decoration: const InputDecoration(
                  hintText: 'Write a message...',
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.all(Radius.circular(18))),
                  contentPadding:
                      EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                ),
              ),
            ),
            const SizedBox(width: 8),
            IconButton(
              onPressed: _loading ? null : _send,
              icon: const Icon(Icons.send),
              color: const Color(0xFF6868AC),
            )
          ],
        ),
      ),
    );
  }

  Widget _bubble(
      {required bool isMe,
      required String name,
      required String dept,
      required String text,
      required int ts}) {
    final bg = isMe ? const Color(0xFF6868AC) : Colors.grey.shade200;
    final fg = isMe ? Colors.white : Colors.black87;
    final align = isMe ? CrossAxisAlignment.end : CrossAxisAlignment.start;
    final radius = BorderRadius.only(
      topLeft: const Radius.circular(16),
      topRight: const Radius.circular(16),
      bottomLeft: Radius.circular(isMe ? 16 : 2),
      bottomRight: Radius.circular(isMe ? 2 : 16),
    );
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: align,
        children: [
          if (!isMe)
            Padding(
              padding: const EdgeInsets.only(left: 6, bottom: 2),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(name,
                      style: const TextStyle(
                          fontSize: 12, fontWeight: FontWeight.w600)),
                  if (dept.isNotEmpty) ...[
                    const SizedBox(width: 6),
                    const Icon(Icons.apartment, size: 12, color: Colors.grey),
                    const SizedBox(width: 2),
                    Text(dept,
                        style:
                            const TextStyle(fontSize: 11, color: Colors.grey)),
                  ]
                ],
              ),
            ),
          Row(
            mainAxisAlignment:
                isMe ? MainAxisAlignment.end : MainAxisAlignment.start,
            children: [
              Flexible(
                child: Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  decoration: BoxDecoration(color: bg, borderRadius: radius),
                  child: Text(text, style: TextStyle(color: fg, fontSize: 15)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 2),
          Padding(
            padding: EdgeInsets.only(left: isMe ? 0 : 6, right: isMe ? 6 : 0),
            child: Text(_timeAgo(ts),
                style: TextStyle(fontSize: 11, color: Colors.grey.shade600)),
          ),
        ],
      ),
    );
  }

  String _timeAgo(int seconds) {
    if (seconds <= 0) return '';
    final now = DateTime.now();
    final dt = DateTime.fromMillisecondsSinceEpoch(seconds * 1000);
    final diff = now.difference(dt);
    if (diff.inSeconds < 60) return '${diff.inSeconds}s ago';
    if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
    if (diff.inHours < 24) return '${diff.inHours}h ago';
    return '${diff.inDays}d ago';
  }

  Future<void> _deleteMessage(Map<String, dynamic> m) async {
    final id = (m['id'] ?? 0) as int;
    if (id <= 0) return;
    final yes = await _confirm(context, 'Remove this message for you?',
        'It will be hidden on your device only.');
    if (yes != true) return;
    final sp = await SharedPreferences.getInstance();
    setState(() {
      _hiddenIds.add(id);
      _messages.removeWhere((e) => (e['id'] ?? 0) == id);
      _seenIds.remove(id);
    });
    await sp.setString(
        'community_chat_hidden_ids_$_me', jsonEncode(_hiddenIds.toList()));
    if (!mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(const SnackBar(content: Text('Removed for you')));
  }

  Future<void> _clearConversationForMe() async {
    final nowSec = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    final sp = await SharedPreferences.getInstance();
    await sp.setInt('community_chat_hide_before_$_me', nowSec);
    await sp.setString('community_chat_hidden_ids_$_me', jsonEncode(<int>[]));
    setState(() {
      _hideBefore = nowSec;
      _hiddenIds.clear();
      _messages.clear();
      _since = null; // reload after clear to keep history hidden
    });
    await _load(initial: true);
  }

  Future<bool?> _confirm(BuildContext context, String title, String message) {
    return showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: Text(message),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context, false),
              child: const Text('Cancel')),
          TextButton(
              onPressed: () => Navigator.pop(context, true),
              child: const Text('Delete')),
        ],
      ),
    );
  }
}
