import 'dart:convert';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'services/chrmo_document_classifier.dart';
import 'services/server_service.dart';

// Define a simple data model for a document
class Document {
  final String id;
  final String name;
  final String type;
  final String status;
  final String department;
  final String date;

  Document({
    required this.id,
    required this.name,
    required this.type,
    required this.status,
    required this.department,
    required this.date,
  });
}

// Placeholder for Document Details Page
class DocumentDetailsPage extends StatefulWidget {
  final Document document;

  const DocumentDetailsPage({super.key, required this.document});

  @override
  State<DocumentDetailsPage> createState() => _DocumentDetailsPageState();
}

class _DocumentDetailsPageState extends State<DocumentDetailsPage>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _fadeAnimation;
  late Animation<Offset> _slideAnimation;

  bool _isBusy = false;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 400),
    );
    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic),
    );
    _slideAnimation = Tween<Offset>(
      begin: const Offset(0, 0.1),
      end: Offset.zero,
    ).animate(CurvedAnimation(parent: _controller, curve: Curves.easeOutCubic));
    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.document.name),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
      ),
      body: FadeTransition(
        opacity: _fadeAnimation,
        child: SlideTransition(
          position: _slideAnimation,
          child: Container(
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Color(0xFFE0F7FA), // Light blue
                  Color(0xFFB2EBF2) // Slightly darker light blue
                ],
              ),
            ),
            padding: const EdgeInsets.all(20.0),
            child: Card(
              elevation: 8,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(20)),
              child: Padding(
                padding: const EdgeInsets.all(20.0),
                child: ListView(
                  physics: const BouncingScrollPhysics(),
                  children: [
                    _buildDetailRow(
                        'Document ID:', widget.document.id, Icons.vpn_key),
                    _buildDetailRow(
                        'Name:', widget.document.name, Icons.description),
                    _buildDetailRow(
                        'Type:', widget.document.type, Icons.category),
                    _buildDetailRow(
                        'Status:', widget.document.status, Icons.info_outline),
                    _buildDetailRow('Department:', widget.document.department,
                        Icons.business),
                    _buildDetailRow(
                        'Date:', widget.document.date, Icons.calendar_today),
                    const SizedBox(height: 30),
                    const Text(
                      'Full Description:',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF52528A),
                      ),
                    ),
                    const SizedBox(height: 10),
                    Text(
                      'This is a detailed description for ${widget.document.name}. '
                      'It contains all relevant information about the document, '
                      'its purpose, and any associated notes. This section can be '
                      'expanded to include more complex data, attachments, or '
                      'interactive elements as needed.',
                      style:
                          const TextStyle(fontSize: 16, color: Colors.black87),
                    ),
                    const SizedBox(height: 30),
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 200),
                      child: ElevatedButton.icon(
                        onPressed: () {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                                content: Text('Document routed successfully!')),
                          );
                          // TODO: Implement actual document routing logic
                        },
                        icon: const Icon(Icons.send, color: Colors.white),
                        label: const Text('Route Document',
                            style: TextStyle(color: Colors.white)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.green.shade600,
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 15),
                          elevation: 4,
                        ),
                      ),
                    ),
                    const SizedBox(height: 15),
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 200),
                      child: ElevatedButton.icon(
                        onPressed:
                            _isBusy ? null : () => _showEditDialog(context),
                        icon: const Icon(Icons.edit, color: Colors.white),
                        label: const Text('Edit Document',
                            style: TextStyle(color: Colors.white)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.orange.shade600,
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 15),
                          elevation: 4,
                        ),
                      ),
                    ),
                    const SizedBox(height: 15),
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 200),
                      child: ElevatedButton.icon(
                        onPressed:
                            _isBusy ? null : () => _showReturnDialog(context),
                        icon: const Icon(Icons.reply, color: Colors.white),
                        label: const Text('Return Document',
                            style: TextStyle(color: Colors.white)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.red.shade600,
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 15),
                          elevation: 4,
                        ),
                      ),
                    ),
                    const SizedBox(height: 15),
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 200),
                      child: ElevatedButton.icon(
                        onPressed: _isBusy ? null : () => _showHistory(context),
                        icon: const Icon(Icons.history, color: Colors.white),
                        label: const Text('View History',
                            style: TextStyle(color: Colors.white)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.blueGrey.shade600,
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 15),
                          elevation: 4,
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
  }

  Widget _buildDetailRow(String label, String value, IconData icon) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 24, color: const Color(0xFF6868AC)),
          const SizedBox(width: 15),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w500,
                    color: Colors.grey.shade700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  value,
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<Map<String, String>> _getUserContext() async {
    final prefs = await SharedPreferences.getInstance();
    final username =
        (prefs.getString('user_name') ?? prefs.getString('user_username') ?? '')
            .trim();
    final department = (prefs.getString('user_department') ??
            prefs.getString('department') ??
            '')
        .trim();
    return {
      'username': username.isNotEmpty ? username : 'mobile',
      'department': department,
    };
  }

  Future<Uri> _documentActionsUri() async {
    final url = await ServerService.buildApiUrl(
        'lib/OCR(UPDATED)/api/document_actions.php');
    return Uri.parse(url);
  }

  Future<void> _showEditDialog(BuildContext context) async {
    final documentTypes = CHRMODocumentClassifier.allDocumentTypes;
    final departments = <String>[
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

    String? selectedType = matchOption(widget.document.type, documentTypes);
    String? selectedDepartment =
        matchOption(widget.document.department, departments);
    String? selectedHolder =
        matchOption(widget.document.department, departments);
    String? selectedEndLocation;
    final commentCtrl = TextEditingController();

    PlatformFile? attachment;

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            return AlertDialog(
              title: const Text('Edit / Update Document'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    DropdownButtonFormField<String>(
                      initialValue: selectedType,
                      decoration: const InputDecoration(labelText: 'Type'),
                      items: documentTypes
                          .map(
                              (t) => DropdownMenuItem(value: t, child: Text(t)))
                          .toList(),
                      onChanged: (v) => setDialogState(() => selectedType = v),
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      initialValue: selectedDepartment,
                      decoration:
                          const InputDecoration(labelText: 'Department'),
                      items: departments
                          .map(
                              (d) => DropdownMenuItem(value: d, child: Text(d)))
                          .toList(),
                      onChanged: (v) =>
                          setDialogState(() => selectedDepartment = v),
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      initialValue: selectedHolder,
                      decoration:
                          const InputDecoration(labelText: 'Current Holder'),
                      items: departments
                          .map(
                              (d) => DropdownMenuItem(value: d, child: Text(d)))
                          .toList(),
                      onChanged: (v) =>
                          setDialogState(() => selectedHolder = v),
                    ),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      initialValue: selectedEndLocation,
                      decoration:
                          const InputDecoration(labelText: 'End Location'),
                      items: departments
                          .map(
                              (d) => DropdownMenuItem(value: d, child: Text(d)))
                          .toList(),
                      onChanged: (v) =>
                          setDialogState(() => selectedEndLocation = v),
                    ),
                    const SizedBox(height: 10),
                    TextField(
                      controller: commentCtrl,
                      decoration: const InputDecoration(
                          labelText: 'Comment (optional)'),
                      minLines: 2,
                      maxLines: 4,
                    ),
                    const SizedBox(height: 12),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton.icon(
                        onPressed: () async {
                          final res = await FilePicker.platform.pickFiles(
                            allowMultiple: false,
                            type: FileType.custom,
                            allowedExtensions: ['jpg', 'jpeg', 'png', 'pdf'],
                          );
                          if (res != null && res.files.isNotEmpty) {
                            attachment = res.files.first;
                          }
                        },
                        icon: const Icon(Icons.attach_file),
                        label: const Text('Add Attachment (optional)'),
                      ),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx, false),
                  child: const Text('Cancel'),
                ),
                ElevatedButton(
                  onPressed: () => Navigator.pop(ctx, true),
                  child: const Text('Save'),
                ),
              ],
            );
          },
        );
      },
    );

    if (ok != true) return;
    await _updateDocument(
      type: (selectedType ?? '').trim(),
      department: (selectedDepartment ?? '').trim(),
      currentHolder: (selectedHolder ?? '').trim(),
      endLocation: (selectedEndLocation ?? '').trim(),
      comment: commentCtrl.text.trim(),
      attachment: attachment,
    );
  }

  Future<void> _showReturnDialog(BuildContext context) async {
    final reasonCtrl = TextEditingController();
    final returnToDeptCtrl = TextEditingController();
    PlatformFile? attachment;

    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          title: const Text('Return Document'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: returnToDeptCtrl,
                  decoration:
                      const InputDecoration(labelText: 'Return to Department'),
                ),
                TextField(
                  controller: reasonCtrl,
                  decoration:
                      const InputDecoration(labelText: 'Reason / Comment'),
                  minLines: 2,
                  maxLines: 5,
                ),
                const SizedBox(height: 12),
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () async {
                      final res = await FilePicker.platform.pickFiles(
                        allowMultiple: false,
                        type: FileType.custom,
                        allowedExtensions: ['jpg', 'jpeg', 'png', 'pdf'],
                      );
                      if (res != null && res.files.isNotEmpty) {
                        attachment = res.files.first;
                      }
                    },
                    icon: const Icon(Icons.attach_file),
                    label: const Text('Add Attachment (optional)'),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('Return'),
            ),
          ],
        );
      },
    );

    if (ok != true) return;
    if (returnToDeptCtrl.text.trim().isEmpty ||
        reasonCtrl.text.trim().isEmpty) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Return department and reason are required.')),
      );
      return;
    }
    await _returnDocument(
      returnToDepartment: returnToDeptCtrl.text.trim(),
      reason: reasonCtrl.text.trim(),
      attachment: attachment,
    );
  }

  Future<void> _returnDocument({
    required String returnToDepartment,
    required String reason,
    PlatformFile? attachment,
  }) async {
    setState(() => _isBusy = true);
    try {
      final ctx = await _getUserContext();
      final uri = await _documentActionsUri();
      final resp = await http.post(
        uri,
        body: {
          'action': 'return_document',
          'tracking_id': widget.document.id,
          'reason': reason,
          'remarks': reason,
          'returned_by': ctx['username'] ?? 'mobile',
          'returned_by_department': ctx['department'] ?? '',
          'return_to_department': returnToDepartment,
        },
      ).timeout(const Duration(seconds: 20));
      final decoded = jsonDecode(resp.body);
      if (decoded is Map && decoded['success'] == true) {
        if (attachment != null) {
          await _uploadAttachment(
            attachment: attachment,
            remarks: 'Return attachment',
          );
        }
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Document returned successfully.')),
        );
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(
                  'Return failed: ${decoded is Map ? (decoded['error'] ?? decoded['message'] ?? 'Unknown') : 'Unknown'}')),
        );
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Return failed: $e')),
      );
    } finally {
      if (mounted) setState(() => _isBusy = false);
    }
  }

  Future<void> _updateDocument({
    String? type,
    String? department,
    String? currentHolder,
    String? endLocation,
    String? comment,
    PlatformFile? attachment,
  }) async {
    setState(() => _isBusy = true);
    try {
      final ctx = await _getUserContext();
      final uri = await _documentActionsUri();
      final body = <String, String>{
        'action': 'update_document',
        'tracking_id': widget.document.id,
        'updated_by': ctx['username'] ?? 'mobile',
      };
      if (type != null && type.trim().isNotEmpty) body['type'] = type.trim();
      if (department != null && department.trim().isNotEmpty) {
        body['department'] = department.trim();
      }
      if (currentHolder != null && currentHolder.trim().isNotEmpty) {
        body['current_holder'] = currentHolder.trim();
      }
      if (endLocation != null && endLocation.trim().isNotEmpty) {
        body['end_location'] = endLocation.trim();
      }
      if (comment != null && comment.trim().isNotEmpty) {
        body['comment'] = comment.trim();
      }

      final resp =
          await http.post(uri, body: body).timeout(const Duration(seconds: 20));
      final decoded = jsonDecode(resp.body);
      if (decoded is Map && decoded['success'] == true) {
        if (attachment != null) {
          await _uploadAttachment(
            attachment: attachment,
            remarks: 'Update attachment',
          );
        }
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Document updated successfully.')),
        );
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(
                  'Update failed: ${decoded is Map ? (decoded['error'] ?? decoded['message'] ?? 'Unknown') : 'Unknown'}')),
        );
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Update failed: $e')),
      );
    } finally {
      if (mounted) setState(() => _isBusy = false);
    }
  }

  Future<void> _uploadAttachment({
    required PlatformFile attachment,
    required String remarks,
  }) async {
    final ctx = await _getUserContext();
    final uri = await _documentActionsUri();

    final req = http.MultipartRequest('POST', uri);
    req.fields['action'] = 'add_attachment';
    req.fields['tracking_id'] = widget.document.id;
    req.fields['uploaded_by'] = ctx['username'] ?? 'mobile';
    req.fields['department'] = ctx['department'] ?? '';
    req.fields['remarks'] = remarks;

    if (attachment.path == null || attachment.path!.isEmpty) {
      throw Exception('Attachment file path is missing');
    }
    req.files.add(await http.MultipartFile.fromPath('file', attachment.path!,
        filename: attachment.name));
    final streamed = await req.send().timeout(const Duration(seconds: 30));
    final body = await streamed.stream.bytesToString();
    final decoded = jsonDecode(body);
    if (!(decoded is Map && decoded['success'] == true)) {
      throw Exception(decoded is Map
          ? (decoded['error'] ??
              decoded['message'] ??
              'Attachment upload failed')
          : 'Attachment upload failed');
    }
  }

  Future<void> _showHistory(BuildContext context) async {
    setState(() => _isBusy = true);
    try {
      final uri = await _documentActionsUri();
      final resp = await http.post(uri, body: {
        'action': 'get_history',
        'tracking_id': widget.document.id,
      }).timeout(const Duration(seconds: 20));

      final decoded = jsonDecode(resp.body);
      final items = (decoded is Map && decoded['success'] == true)
          ? (decoded['history'] as List? ?? const [])
          : const [];

      if (!context.mounted) return;
      await showDialog<void>(
        context: context,
        builder: (ctx) {
          return AlertDialog(
            title: const Text('Document History'),
            content: SizedBox(
              width: double.maxFinite,
              child: items.isEmpty
                  ? const Text('No history found.')
                  : ListView.separated(
                      shrinkWrap: true,
                      itemCount: items.length,
                      separatorBuilder: (_, __) => const Divider(height: 16),
                      itemBuilder: (_, i) {
                        final row = items[i] as Map;
                        final action = (row['action'] ?? '').toString();
                        final fromHolder =
                            (row['from_holder'] ?? '').toString();
                        final toHolder = (row['to_holder'] ?? '').toString();
                        final fromStatus =
                            (row['from_status'] ?? '').toString();
                        final toStatus = (row['to_status'] ?? '').toString();
                        final notes = (row['notes'] ?? '').toString();
                        final when = (row['created_at'] ?? '').toString();
                        return ListTile(
                          title: Text(action.isEmpty ? 'event' : action),
                          subtitle: Text([
                            if (fromHolder.isNotEmpty || toHolder.isNotEmpty)
                              '$fromHolder -> $toHolder',
                            if (fromStatus.isNotEmpty || toStatus.isNotEmpty)
                              '$fromStatus -> $toStatus',
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
            ],
          );
        },
      );
    } catch (e) {
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Failed to load history: $e')),
      );
    } finally {
      if (mounted) setState(() => _isBusy = false);
    }
  }
}

class DocumentTrackingPage extends StatefulWidget {
  const DocumentTrackingPage({super.key});

  @override
  State<DocumentTrackingPage> createState() => _DocumentTrackingPageState();
}

class _DocumentTrackingPageState extends State<DocumentTrackingPage>
    with SingleTickerProviderStateMixin {
  String _searchQuery = '';
  String? _selectedDocumentType;
  String? _selectedStatus;
  String? _selectedDepartment;

  final List<Document> _allDocuments = [
    Document(
        id: 'DOC001',
        name: 'Employee Onboarding Forms',
        type: 'HR',
        status: 'In Progress',
        department: 'HR',
        date: '2025-05-20'),
    Document(
        id: 'DOC002',
        name: 'Q2 Budget Proposal',
        type: 'Finance',
        status: 'Approved',
        department: 'Finance',
        date: '2025-05-18'),
    Document(
        id: 'DOC003',
        name: 'Marketing Campaign Plan',
        type: 'Marketing',
        status: 'Pending Review',
        department: 'Marketing',
        date: '2025-05-15'),
    Document(
        id: 'DOC004',
        name: 'IT Infrastructure Upgrade',
        type: 'IT',
        status: 'Completed',
        department: 'IT',
        date: '2025-05-10'),
    Document(
        id: 'DOC005',
        name: 'Annual Performance Review',
        type: 'HR',
        status: 'In Progress',
        department: 'HR',
        date: '2025-05-22'),
    Document(
        id: 'DOC006',
        name: 'Sales Report Q1',
        type: 'Sales',
        status: 'Approved',
        department: 'Sales',
        date: '2025-04-30'),
  ];

  List<Document> _filteredDocuments = [];

  // For notification drawer animation
  late AnimationController _notificationController;
  late Animation<Offset> _slideAnimation;
  bool _isNotificationDrawerOpen = false;

  final List<String> _notifications = [
    "Document DOC001 status updated to 'In Progress'",
    "New document DOC007 uploaded: 'Project Proposal'",
    "Reminder: DOC003 review is due tomorrow",
    "System backup completed successfully",
  ];

  @override
  void initState() {
    super.initState();
    _filterDocuments(); // Initial filtering
    _notificationController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 300),
    );
    _slideAnimation = Tween<Offset>(
      begin: const Offset(1.0, 0),
      end: Offset.zero,
    ).animate(CurvedAnimation(
      parent: _notificationController,
      curve: Curves.easeInOut,
    ));
  }

  @override
  void dispose() {
    _notificationController.dispose();
    super.dispose();
  }

  void _filterDocuments() {
    setState(() {
      _filteredDocuments = _allDocuments.where((doc) {
        final matchesSearch = _searchQuery.isEmpty ||
            doc.name.toLowerCase().contains(_searchQuery.toLowerCase()) ||
            doc.id.toLowerCase().contains(_searchQuery.toLowerCase());

        final matchesType = _selectedDocumentType == null ||
            _selectedDocumentType == 'All Types' ||
            doc.type == _selectedDocumentType;

        final matchesStatus = _selectedStatus == null ||
            _selectedStatus == 'All Statuses' ||
            doc.status == _selectedStatus;

        final matchesDepartment = _selectedDepartment == null ||
            _selectedDepartment == 'All Departments' ||
            doc.department == _selectedDepartment;

        return matchesSearch &&
            matchesType &&
            matchesStatus &&
            matchesDepartment;
      }).toList();
    });
  }

  void _resetFilters() {
    setState(() {
      _selectedDocumentType = null;
      _selectedStatus = null;
      _selectedDepartment = null;
      _searchQuery = '';
      _filterDocuments();
    });
  }

  void _toggleNotificationDrawer() {
    setState(() {
      if (_isNotificationDrawerOpen) {
        _notificationController.reverse();
      } else {
        _notificationController.forward();
      }
      _isNotificationDrawerOpen = !_isNotificationDrawerOpen;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Document Tracking'),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
        systemOverlayStyle: SystemUiOverlayStyle.light,
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications),
            onPressed: _toggleNotificationDrawer,
          ),
        ],
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFE0F7FA), // Light blue
              Color(0xFFB2EBF2) // Slightly darker light blue
            ],
          ),
        ),
        child: Stack(
          children: [
            Column(
              children: [
                _buildSearchBarAndFilters(),
                Expanded(
                  child: _filteredDocuments.isEmpty
                      ? Center(
                          child: Text(
                            'No documents found matching your criteria.',
                            style: TextStyle(
                                fontSize: 16, color: Colors.grey.shade600),
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.all(16.0),
                          itemCount: _filteredDocuments.length,
                          itemBuilder: (context, index) {
                            final doc = _filteredDocuments[index];
                            return _buildDocumentCard(doc);
                          },
                        ),
                ),
              ],
            ),
            _buildNotificationDrawer(),
          ],
        ),
      ),
    );
  }

  Widget _buildSearchBarAndFilters() {
    return Padding(
      padding: const EdgeInsets.all(16.0),
      child: Column(
        children: [
          // Search Bar
          TextField(
            onChanged: (value) {
              setState(() {
                _searchQuery = value;
                _filterDocuments();
              });
            },
            decoration: InputDecoration(
              hintText: 'Search documents by name or ID...',
              prefixIcon: const Icon(Icons.search, color: Color(0xFF6868AC)),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: BorderSide.none,
              ),
              filled: true,
              fillColor: Colors.white.withOpacity(0.9),
              contentPadding:
                  const EdgeInsets.symmetric(vertical: 10, horizontal: 15),
              suffixIcon: _searchQuery.isNotEmpty
                  ? IconButton(
                      icon: const Icon(Icons.clear, color: Colors.grey),
                      onPressed: () {
                        setState(() {
                          _searchQuery = '';
                          _filterDocuments();
                        });
                      },
                    )
                  : null,
            ),
          ),
          const SizedBox(height: 15),
          // Filter Dropdowns
          Row(
            children: [
              Expanded(
                child: _buildFilterDropdown(
                  'Document Type',
                  ['All Types', 'HR', 'Finance', 'Marketing', 'IT', 'Sales'],
                  _selectedDocumentType,
                  (newValue) {
                    setState(() {
                      _selectedDocumentType = newValue;
                      _filterDocuments();
                    });
                  },
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _buildFilterDropdown(
                  'Status',
                  [
                    'All Statuses',
                    'In Progress',
                    'Approved',
                    'Pending Review',
                    'Completed'
                  ],
                  _selectedStatus,
                  (newValue) {
                    setState(() {
                      _selectedStatus = newValue;
                      _filterDocuments();
                    });
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          _buildFilterDropdown(
            'Department',
            ['All Departments', 'HR', 'Finance', 'Marketing', 'IT', 'Sales'],
            _selectedDepartment,
            (newValue) {
              setState(() {
                _selectedDepartment = newValue;
                _filterDocuments();
              });
            },
          ),
          const SizedBox(height: 15),
          // Reset Filters Button
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: _resetFilters,
              icon: const Icon(Icons.refresh, color: Colors.white),
              label: const Text('Reset Filters',
                  style: TextStyle(color: Colors.white)),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF6868AC),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10)),
                padding: const EdgeInsets.symmetric(vertical: 12),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterDropdown(String hint, List<String> items,
      String? selectedValue, ValueChanged<String?> onChanged) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.9),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF6868AC).withOpacity(0.25)),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          isExpanded: true,
          value: selectedValue,
          hint: Text(hint, style: TextStyle(color: Colors.grey.shade700)),
          icon: const Icon(Icons.arrow_drop_down, color: Color(0xFF6868AC)),
          onChanged: onChanged,
          items: items.map<DropdownMenuItem<String>>((String value) {
            return DropdownMenuItem<String>(
              value: value,
              child: Text(value),
            );
          }).toList(),
          style: const TextStyle(color: Colors.black87, fontSize: 16),
          dropdownColor: Colors.white,
        ),
      ),
    );
  }

  Widget _buildDocumentCard(Document doc) {
    Color statusColor;
    switch (doc.status) {
      case 'Approved':
        statusColor = Colors.green.shade600;
        break;
      case 'In Progress':
        statusColor = Colors.orange.shade600;
        break;
      case 'Pending Review':
        statusColor = const Color(0xFF6868AC);
        break;
      case 'Completed':
        statusColor = Colors.purple.shade600;
        break;
      default:
        statusColor = Colors.grey.shade600;
    }

    return GestureDetector(
      onTap: () {
        // Navigate to DocumentDetailsPage when the card is tapped
        Navigator.push(
          context,
          MaterialPageRoute(
            builder: (context) => DocumentDetailsPage(document: doc),
          ),
        );
      },
      child: Card(
        elevation: 5,
        margin: const EdgeInsets.symmetric(vertical: 8.0),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(15),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                Colors.white,
                const Color(0xFF6868AC).withOpacity(0.08),
              ],
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        doc.name,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF52528A),
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: statusColor.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        doc.status,
                        style: TextStyle(
                          color: statusColor,
                          fontWeight: FontWeight.w600,
                          fontSize: 13,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  'ID: ${doc.id}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Type: ${doc.type}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Department: ${doc.department}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Date: ${doc.date}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 15),
                // Buttons for "View Details" and "Route Document"
                Row(
                  mainAxisAlignment: MainAxisAlignment.end,
                  children: [
                    ElevatedButton.icon(
                      onPressed: () {
                        // Navigate to DocumentDetailsPage
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (context) =>
                                DocumentDetailsPage(document: doc),
                          ),
                        );
                      },
                      icon: const Icon(Icons.visibility, color: Colors.white),
                      label: const Text('View',
                          style: TextStyle(color: Colors.white)),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF6868AC),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(10)),
                        elevation: 3,
                        padding: const EdgeInsets.symmetric(
                            horizontal: 15, vertical: 10),
                      ),
                    ),
                    const SizedBox(width: 10),
                    ElevatedButton.icon(
                      onPressed: () {
                        _showRouteDocumentOptions(context, doc);
                      },
                      icon: const Icon(Icons.send, color: Colors.white),
                      label: const Text('Route',
                          style: TextStyle(color: Colors.white)),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.green.shade600,
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(10)),
                        elevation: 3,
                        padding: const EdgeInsets.symmetric(
                            horizontal: 15, vertical: 10),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  void _showRouteDocumentOptions(BuildContext context, Document doc) {
    showModalBottomSheet(
      context: context,
      backgroundColor:
          Colors.transparent, // Make background transparent for rounded corners
      builder: (BuildContext context) {
        return Container(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(25)),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withOpacity(0.15),
                blurRadius: 10,
                spreadRadius: 2,
              ),
            ],
          ),
          padding: const EdgeInsets.all(20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Center(
                child: Container(
                  width: 50,
                  height: 5,
                  decoration: BoxDecoration(
                    color: Colors.grey.shade300,
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
              ),
              const SizedBox(height: 20),
              Text(
                'Route Document: ${doc.name}',
                style: const TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF52528A),
                ),
              ),
              const SizedBox(height: 15),
              _buildRouteOption(
                context,
                icon: Icons.person_add,
                label: 'To HR Department',
                onTap: () {
                  Navigator.pop(context); // Close bottom sheet
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text('Document ${doc.id} routed to HR.')),
                  );
                },
              ),
              _buildRouteOption(
                context,
                icon: Icons.attach_money,
                label: 'To Finance Department',
                onTap: () {
                  Navigator.pop(context);
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                        content: Text('Document ${doc.id} routed to Finance.')),
                  );
                },
              ),
              _buildRouteOption(
                context,
                icon: Icons.check_circle_outline,
                label: 'Mark as Completed',
                onTap: () {
                  Navigator.pop(context);
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                        content:
                            Text('Document ${doc.id} marked as Completed.')),
                  );
                  // TODO: Update document status in your data source
                },
              ),
              const SizedBox(height: 10),
            ],
          ),
        );
      },
    );
  }

  Widget _buildRouteOption(BuildContext context,
      {required IconData icon,
      required String label,
      required VoidCallback onTap}) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 12.0, horizontal: 8.0),
        child: Row(
          children: [
            Icon(icon, color: const Color(0xFF6868AC), size: 28),
            const SizedBox(width: 15),
            Text(
              label,
              style: const TextStyle(fontSize: 17, color: Colors.black87),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildNotificationDrawer() {
    return AnimatedPositioned(
      duration: const Duration(milliseconds: 300),
      curve: Curves.easeInOut,
      right: _isNotificationDrawerOpen ? 0 : -MediaQuery.of(context).size.width,
      top: 0,
      bottom: 0,
      width: MediaQuery.of(context).size.width * 0.8, // Slightly wider drawer
      child: SlideTransition(
        position: _slideAnimation,
        child: Material(
          elevation: 10,
          color: Colors.white,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(
                15.0, 50.0, 15.0, 20.0), // Adjusted top padding
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Notifications',
                      style: TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF52528A),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close, color: Colors.blueGrey),
                      onPressed: _toggleNotificationDrawer,
                    ),
                  ],
                ),
                const Divider(height: 20, thickness: 1.0, color: Colors.grey),
                Expanded(
                  child: _notifications.isEmpty
                      ? Center(
                          child: Text(
                            'No new notifications.',
                            style: TextStyle(
                                fontSize: 16, color: Colors.grey.shade600),
                          ),
                        )
                      : ListView.separated(
                          itemBuilder: (context, index) {
                            return ListTile(
                              leading: const Icon(Icons.notifications_active,
                                  color: Color(0xFF6868AC)),
                              title: Text(
                                _notifications[index],
                                style: const TextStyle(
                                    fontSize: 15, color: Colors.black87),
                              ),
                              contentPadding: const EdgeInsets.symmetric(
                                  horizontal: 10, vertical: 5),
                              trailing: Icon(Icons.chevron_right,
                                  color:
                                      const Color(0xFF6868AC).withOpacity(0.4)),
                              onTap: () {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  SnackBar(
                                    content: Text(
                                        'Tapped: ${_notifications[index]}'),
                                  ),
                                );
                              },
                            );
                          },
                          separatorBuilder: (context, index) => Divider(
                            color: Colors.grey.shade200,
                            indent: 10,
                            endIndent: 10,
                          ),
                          itemCount: _notifications.length,
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
