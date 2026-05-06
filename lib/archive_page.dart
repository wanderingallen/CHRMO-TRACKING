import 'package:flutter/material.dart';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart'; // For date formatting
import 'package:file_picker/file_picker.dart'; // For actual file picking in New Archive Modal
import 'dart:math'; // For generating random file sizes/names

// Data model for an archived document
class ArchivedDocument {
  final String id;
  final String name;
  final String department;
  final String type;
  final String status; // e.g., 'Archived', 'Restored', 'Deleted'
  final String date; // Date archived
  final String size;
  final String fileType; // e.g., 'pdf', 'doc', 'jpg'

  ArchivedDocument({
    required this.id,
    required this.name,
    required this.department,
    required this.type,
    required this.status,
    required this.date,
    required this.size,
    required this.fileType,
  });
}

class ArchivePage extends StatefulWidget {
  const ArchivePage({super.key});

  @override
  State<ArchivePage> createState() => _ArchivePageState();
}

class _ArchivePageState extends State<ArchivePage>
    with SingleTickerProviderStateMixin {
  String _searchQuery = '';
  String? _selectedDepartmentFilter;
  String? _selectedDocumentTypeFilter;
  DateTime? _startDateFilter;
  DateTime? _endDateFilter;

  // For multi-selection
  final Set<String> _selectedDocumentIds = {};

  // For notification drawer animation
  late AnimationController _notificationController;
  late Animation<Offset> _slideAnimation;
  bool _isNotificationDrawerOpen = false;

  final List<String> notifications = [
    "Document #001 archived successfully",
    "Document #002 archived yesterday",
    "Reminder: Clean up old archives",
    "Archive backup completed",
    "New archive feature available",
  ];

  // Sample archived documents data
  final List<ArchivedDocument> _allArchivedDocuments = [];
  bool _isLoading = false;

  Future<void> _fetchArchivedDocuments() async {
    if (!mounted) return;
    setState(() => _isLoading = true);
    try {
      final prefs = await SharedPreferences.getInstance();
      final dept = prefs.getString('user_department') ?? '';
      final root = prefs.getString('server_root') ?? 'http://localhost/CHRMO-TRACKING-main';
      
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/archive_transfer.php').replace(queryParameters: {
        'action': 'list_archived',
        'department': dept,
      });
      
      final response = await http.get(uri).timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        if (data['success'] == true) {
          final List list = data['archived'] ?? [];
          setState(() {
            _allArchivedDocuments.clear();
            _allArchivedDocuments.addAll(list.map((m) => ArchivedDocument(
              id: m['id']?.toString() ?? '',
              name: m['name'] ?? '',
              department: m['department'] ?? '',
              type: m['type'] ?? '',
              status: m['status'] ?? '',
              date: m['date'] ?? '',
              size: m['size'] ?? '',
              fileType: m['fileType'] ?? 'pdf',
            )));
            _filterDocuments();
          });
        }
      }
    } catch (e) {
      debugPrint('Error fetching archived docs: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _downloadDocument(ArchivedDocument doc) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final root = prefs.getString('server_root') ?? 'http://localhost/CHRMO-TRACKING-main';
      final url = Uri.parse('$root/lib/OCR(UPDATED)/api/archive_download.php?id=${doc.id}&dl=1');
      
      if (await canLaunchUrl(url)) {
        await launchUrl(url, mode: LaunchMode.externalApplication);
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Could not launch download URL')),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e')),
        );
      }
    }
  }

  List<ArchivedDocument> _filteredArchivedDocuments = [];
  int _currentPage = 1;
  final int _documentsPerPage = 10; // Number of documents per page

  @override
  void initState() {
    super.initState();
    _notificationController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 250),
    );

    _slideAnimation = Tween<Offset>(
      begin: const Offset(1.0, 0),
      end: Offset.zero,
    ).animate(CurvedAnimation(
      parent: _notificationController,
      curve: Curves.easeOutCubic,
    ));

    _filterDocuments(); // Initial filtering
    _fetchArchivedDocuments(); // Load real data
  }

  @override
  void dispose() {
    _notificationController.dispose();
    super.dispose();
  }

  void toggleNotificationDrawer() {
    setState(() {
      if (_isNotificationDrawerOpen) {
        _notificationController.reverse();
      } else {
        _notificationController.forward();
      }
      _isNotificationDrawerOpen = !_isNotificationDrawerOpen;
    });
  }

  // Filter logic
  void _filterDocuments() {
    setState(() {
      _filteredArchivedDocuments = _allArchivedDocuments.where((doc) {
        final lowerQuery = _searchQuery.toLowerCase();
        final matchesSearch = doc.name.toLowerCase().contains(lowerQuery) ||
            doc.id.toLowerCase().contains(lowerQuery) ||
            doc.department.toLowerCase().contains(lowerQuery);

        final matchesDepartment = _selectedDepartmentFilter == null ||
            _selectedDepartmentFilter == 'All Departments' ||
            doc.department == _selectedDepartmentFilter;

        final matchesDocumentType = _selectedDocumentTypeFilter == null ||
            _selectedDocumentTypeFilter == 'All Types' ||
            doc.type == _selectedDocumentTypeFilter;

        bool matchesDateRange = true;
        if (_startDateFilter != null && _endDateFilter != null) {
          final docDate = DateTime.parse(doc.date
              .replaceAll(' ', '')); // Parse date from "YYYY-MM-DD" or similar
          matchesDateRange = docDate.isAfter(
                  _startDateFilter!.subtract(const Duration(days: 1))) &&
              docDate.isBefore(_endDateFilter!.add(const Duration(days: 1)));
        }

        return matchesSearch &&
            matchesDepartment &&
            matchesDocumentType &&
            matchesDateRange;
      }).toList();
      _currentPage = 1; // Reset to first page after filtering
    });
  }

  void _clearSearch() {
    setState(() {
      _searchQuery = '';
      _filterDocuments();
    });
  }

  void _clearFilters() {
    setState(() {
      _searchQuery = '';
      _selectedDepartmentFilter = null;
      _selectedDocumentTypeFilter = null;
      _startDateFilter = null;
      _endDateFilter = null;
      _selectedDocumentIds.clear(); // Clear selections
      _filterDocuments();
    });
  }

  void _toggleDocumentSelection(String docId) {
    setState(() {
      if (_selectedDocumentIds.contains(docId)) {
        _selectedDocumentIds.remove(docId);
      } else {
        _selectedDocumentIds.add(docId);
      }
    });
  }

  void _toggleSelectAll(bool? selectAll) {
    setState(() {
      _selectedDocumentIds.clear();
      if (selectAll == true) {
        for (var doc in _filteredArchivedDocuments) {
          _selectedDocumentIds.add(doc.id);
        }
      }
    });
  }

  Future<void> _showDocumentPreviewModal(ArchivedDocument doc) async {
    await showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          titlePadding: EdgeInsets.zero,
          contentPadding: EdgeInsets.zero,
          insetPadding: const EdgeInsets.all(16),
          title: Container(
            padding: const EdgeInsets.all(20),
            decoration: const BoxDecoration(
              color: Color(0xFF6868AC),
              borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    doc.name,
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.bold),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.white),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
          content: SingleChildScrollView(
            child: Padding(
              padding: const EdgeInsets.all(20.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    height: 200,
                    width: double.infinity,
                    decoration: BoxDecoration(
                      color: Colors.grey.shade200,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: Colors.grey.shade300),
                    ),
                    child: Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(_getFileIcon(doc.fileType),
                              size: 60,
                              color: const Color(0xFF6868AC).withOpacity(0.6)),
                          const SizedBox(height: 10),
                          Text(
                            'Document preview (simulated)',
                            style: TextStyle(color: Colors.grey.shade600),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 20),
                  _buildDetailRow(Icons.vpn_key, 'Document ID:', doc.id),
                  _buildDetailRow(
                      Icons.business, 'Department:', doc.department),
                  _buildDetailRow(Icons.category, 'Type:', doc.type),
                  _buildDetailRow(
                      Icons.calendar_today, 'Archived On:', doc.date),
                  _buildDetailRow(Icons.insert_drive_file, 'Size:', doc.size),
                  const SizedBox(height: 20),
                  const Text(
                    'Audit Trail / History',
                    style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF52528A)),
                  ),
                  const Divider(),
                  // Simulated Audit Log
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _buildAuditLogEntry(
                          'Document created by Admin on ${doc.date}'),
                      _buildAuditLogEntry('Archived by System on ${doc.date}'),
                      // Add more simulated entries as needed
                    ],
                  ),
                  const SizedBox(height: 20),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      TextButton(
                        onPressed: () {
                          Navigator.pop(context);
                          _downloadDocument(doc);
                        },
                        style: TextButton.styleFrom(
                          foregroundColor: const Color(0xFF6868AC),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10)),
                        ),
                        child: const Text('Download'),
                      ),
                      const SizedBox(width: 10),
                      ElevatedButton(
                        onPressed: () {
                          Navigator.pop(context); // Close preview modal
                          _confirmRestoreDocument(doc.id);
                        },
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.green.shade600,
                          foregroundColor: Colors.white,
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10)),
                        ),
                        child: const Text('Restore'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildDetailRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 20, color: const Color(0xFF6868AC)),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(fontSize: 13, color: Colors.grey.shade700),
                ),
                Text(
                  value,
                  style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w500,
                      color: Colors.black87),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildAuditLogEntry(String entry) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4.0),
      child: Text(
        '• $entry',
        style: TextStyle(fontSize: 14, color: Colors.grey.shade800),
      ),
    );
  }

  Future<void> _confirmDeleteSelectedDocuments() async {
    if (_selectedDocumentIds.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No documents selected for deletion.')),
      );
      return;
    }

    final bool confirm = await showDialog(
          context: context,
          builder: (BuildContext context) {
            return AlertDialog(
              title: const Text('Confirm Deletion'),
              content: Text(
                  'Are you sure you want to delete ${_selectedDocumentIds.length} selected document(s)? This action cannot be undone.'),
              actions: <Widget>[
                TextButton(
                  onPressed: () => Navigator.of(context).pop(false),
                  child: const Text('Cancel'),
                ),
                ElevatedButton(
                  onPressed: () => Navigator.of(context).pop(true),
                  style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
                  child: const Text('Delete',
                      style: TextStyle(color: Colors.white)),
                ),
              ],
            );
          },
        ) ??
        false;

    if (confirm) {
      setState(() {
        _allArchivedDocuments
            .removeWhere((doc) => _selectedDocumentIds.contains(doc.id));
        _selectedDocumentIds.clear();
        _filterDocuments(); // Re-filter after deletion
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(
                '${_selectedDocumentIds.length} document(s) deleted successfully!')),
      );
    }
  }

  Future<void> _restoreDocument(String docId) async {
    final docToRestore = _allArchivedDocuments.firstWhere((doc) => doc.id == docId);
    final confirm = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Restore Document'),
            content: Text(
                'Are you sure you want to restore "${docToRestore.name}" back to Tracking?'),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Cancel'),
              ),
              ElevatedButton(
                onPressed: () => Navigator.pop(context, true),
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF6868AC)),
                child: const Text('Restore', style: TextStyle(color: Colors.white)),
              ),
            ],
          ),
        ) ??
        false;

    if (confirm) {
      setState(() => _isLoading = true);
      try {
        final prefs = await SharedPreferences.getInstance();
        final root = prefs.getString('server_root') ?? 'http://localhost/CHRMO-TRACKING-main';
        
        final response = await http.post(
          Uri.parse('$root/lib/OCR(UPDATED)/api/archive_transfer.php'),
          body: {
            'action': 'restore',
            'id': docId,
          },
        ).timeout(const Duration(seconds: 15));
        
        if (response.statusCode == 200) {
          final data = jsonDecode(response.body);
          if (data['success'] == true) {
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Document "${docToRestore.name}" restored successfully!')),
              );
              _fetchArchivedDocuments(); // Refresh list
            }
            return;
          }
        }
        throw Exception('Failed to restore document');
      } catch (e) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Error: $e')),
          );
        }
      } finally {
        if (mounted) setState(() => _isLoading = false);
      }
    }
  }

  Future<void> _showNewArchiveModal() async {
    final TextEditingController nameController = TextEditingController();
    final TextEditingController departmentController = TextEditingController();
    final TextEditingController typeController = TextEditingController();
    String? selectedFileName;
    String? selectedFilePath;

    await showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          titlePadding: EdgeInsets.zero,
          contentPadding: EdgeInsets.zero,
          insetPadding: const EdgeInsets.all(16),
          title: Container(
            padding: const EdgeInsets.all(20),
            decoration: const BoxDecoration(
              color: Color(0xFF6868AC),
              borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Archive New Document',
                  style: TextStyle(
                      color: Colors.white,
                      fontSize: 20,
                      fontWeight: FontWeight.bold),
                ),
                IconButton(
                  icon: const Icon(Icons.close, color: Colors.white),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
          content: StatefulBuilder(
            // Use StatefulBuilder to update dialog UI
            builder: (BuildContext context, StateSetter setState) {
              return SingleChildScrollView(
                padding: const EdgeInsets.all(20.0),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    TextField(
                      controller: nameController,
                      decoration:
                          _inputDecoration('Document Name', Icons.description),
                    ),
                    const SizedBox(height: 15),
                    DropdownButtonFormField<String>(
                      initialValue: departmentController.text.isEmpty
                          ? null
                          : departmentController.text,
                      decoration:
                          _inputDecoration('Department', Icons.business),
                      items: <String>[
                        'Human Resources',
                        'Finance',
                        'Records',
                        'Administration',
                        'Other'
                      ].map<DropdownMenuItem<String>>((String value) {
                        return DropdownMenuItem<String>(
                            value: value, child: Text(value));
                      }).toList(),
                      onChanged: (String? newValue) {
                        setState(() {
                          departmentController.text = newValue!;
                        });
                      },
                    ),
                    const SizedBox(height: 15),
                    DropdownButtonFormField<String>(
                      initialValue: typeController.text.isEmpty
                          ? null
                          : typeController.text,
                      decoration:
                          _inputDecoration('Document Type', Icons.category),
                      items: <String>[
                        'Report',
                        'Memo',
                        'Payroll',
                        'Contract',
                        'Policy',
                        'Spreadsheet',
                        'Image',
                        'Other'
                      ].map<DropdownMenuItem<String>>((String value) {
                        return DropdownMenuItem<String>(
                            value: value, child: Text(value));
                      }).toList(),
                      onChanged: (String? newValue) {
                        setState(() {
                          typeController.text = newValue!;
                        });
                      },
                    ),
                    const SizedBox(height: 15),
                    // File Upload Area (simulated drag & drop / click to browse)
                    GestureDetector(
                      onTap: () async {
                        FilePickerResult? result =
                            await FilePicker.platform.pickFiles(
                          type: FileType.custom,
                          allowedExtensions: [
                            'pdf',
                            'doc',
                            'docx',
                            'xls',
                            'xlsx',
                            'png',
                            'jpg',
                            'jpeg',
                            'txt'
                          ],
                        );
                        if (result != null) {
                          setState(() {
                            selectedFileName = result.files.single.name;
                            selectedFilePath = result.files.single.path;
                          });
                        }
                      },
                      child: Container(
                        padding: const EdgeInsets.all(20),
                        decoration: BoxDecoration(
                          border: Border.all(
                              color: const Color(0xFF6868AC).withOpacity(0.35),
                              width: 2,
                              style: BorderStyle.solid),
                          borderRadius: BorderRadius.circular(10),
                          color: const Color(0xFF6868AC).withOpacity(0.05),
                        ),
                        child: Column(
                          children: [
                            const Icon(Icons.cloud_upload,
                                size: 50, color: Color(0xFF6868AC)),
                            const SizedBox(height: 10),
                            Text(
                              selectedFileName ??
                                  'Tap to select file or Drag & Drop',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                  color: selectedFileName != null
                                      ? Colors.black87
                                      : Colors.grey.shade700),
                            ),
                            if (selectedFileName != null)
                              Padding(
                                padding: const EdgeInsets.only(top: 8.0),
                                child: Text(
                                  'File: $selectedFileName',
                                  style: const TextStyle(
                                      fontSize: 12, color: Color(0xFF6868AC)),
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 20),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.end,
                      children: [
                        TextButton(
                          onPressed: () => Navigator.pop(context),
                          child: const Text('Cancel'),
                        ),
                        const SizedBox(width: 10),
                        ElevatedButton.icon(
                          onPressed: () {
                            if (nameController.text.isNotEmpty &&
                                departmentController.text.isNotEmpty &&
                                typeController.text.isNotEmpty &&
                                selectedFileName != null) {
                              _addNewArchivedDocument(
                                nameController.text,
                                departmentController.text,
                                typeController.text,
                                selectedFileName!,
                                selectedFilePath!, // In a real app, you'd upload this file
                              );
                              Navigator.pop(context);
                            } else {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(
                                    content: Text(
                                        'Please fill all fields and select a file.')),
                              );
                            }
                          },
                          icon: const Icon(Icons.upload_file,
                              color: Colors.white),
                          label: const Text('Archive Document',
                              style: TextStyle(color: Colors.white)),
                          style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF6868AC)),
                        ),
                      ],
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

  InputDecoration _inputDecoration(String labelText, IconData icon) {
    return InputDecoration(
      labelText: labelText,
      prefixIcon: Icon(icon, color: const Color(0xFF6868AC)),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide.none,
      ),
      filled: true,
      fillColor: Colors.white.withOpacity(0.9),
      contentPadding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
    );
  }

  void _addNewArchivedDocument(String name, String department, String type,
      String fileName, String filePath) {
    // Simulate file size (random for now)
    final random = Random();
    final sizeInMB =
        (1 + random.nextDouble() * 9).toStringAsFixed(1); // 1.0 to 10.0 MB

    setState(() {
      _allArchivedDocuments.insert(
        0, // Add to the beginning of the list
        ArchivedDocument(
          id: 'ARC${(_allArchivedDocuments.length + 1).toString().padLeft(3, '0')}',
          name: name,
          department: department,
          type: type,
          status: 'Archived',
          date: DateFormat('yyyy-MM-dd').format(DateTime.now()),
          size: '$sizeInMB MB',
          fileType: fileName.split('.').last.toLowerCase(),
        ),
      );
      _filterDocuments(); // Re-filter to update the displayed list
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Document "$name" archived successfully!')),
      );
    });
  }

  // Helper to get document icon based on file type
  IconData _getFileIcon(String fileType) {
    switch (fileType.toLowerCase()) {
      case 'pdf':
        return Icons.picture_as_pdf;
      case 'doc':
      case 'docx':
        return Icons.description; // or Icons.file_word if you have custom icons
      case 'xls':
      case 'xlsx':
        return Icons.table_chart; // or Icons.file_excel
      case 'jpg':
      case 'jpeg':
      case 'png':
        return Icons.image;
      case 'txt':
        return Icons.text_snippet;
      default:
        return Icons.insert_drive_file;
    }
  }

  @override
  Widget build(BuildContext context) {
    // Calculate pagination details
    final int totalPages =
        (_filteredArchivedDocuments.length / _documentsPerPage).ceil();
    final int startIndex = (_currentPage - 1) * _documentsPerPage;
    final int endIndex = (_currentPage * _documentsPerPage)
        .clamp(0, _filteredArchivedDocuments.length);
    final List<ArchivedDocument> paginatedDocuments =
        _filteredArchivedDocuments.sublist(startIndex, endIndex);

    final bool allSelected =
        _selectedDocumentIds.length == paginatedDocuments.length &&
            paginatedDocuments.isNotEmpty;
    final bool anySelected = _selectedDocumentIds.isNotEmpty;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Archived Documents'),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
        systemOverlayStyle: SystemUiOverlayStyle.light,
        actions: [
          IconButton(
            icon: Icon(
              _isNotificationDrawerOpen
                  ? Icons.notifications_off
                  : Icons.notifications,
              color: Colors.white,
            ),
            onPressed: toggleNotificationDrawer,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: Stack(
        children: [
          // Main content area with gradient background
          Container(
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Color(0xFFF0F0F8), // Very light periwinkle
                  Color(0xFFE8E8F2), // Slightly deeper periwinkle tint
                ],
              ),
            ),
            child: Column(
              children: [
                // Filter Bar
                Padding(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 16.0, vertical: 8.0),
                  child: Column(
                    children: [
                      TextField(
                        controller: TextEditingController(text: _searchQuery),
                        onChanged: (value) {
                          setState(() {
                            _searchQuery = value;
                            _filterDocuments();
                          });
                        },
                        decoration: InputDecoration(
                          hintText: 'Search archived documents...',
                          prefixIcon: const Icon(Icons.search,
                              color: Color(0xFF6868AC)),
                          suffixIcon: _searchQuery.isNotEmpty
                              ? IconButton(
                                  icon: const Icon(Icons.clear,
                                      color: Colors.grey),
                                  onPressed: _clearSearch,
                                )
                              : null,
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: BorderSide.none,
                          ),
                          filled: true,
                          fillColor: Colors.white.withOpacity(0.9),
                          contentPadding: const EdgeInsets.symmetric(
                              vertical: 14, horizontal: 16),
                          focusedBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: const BorderSide(
                                color: Color(0xFF6868AC), width: 2),
                          ),
                          enabledBorder: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(12),
                            borderSide: BorderSide(color: Colors.grey.shade300),
                          ),
                        ),
                      ),
                      const SizedBox(height: 15),
                      Row(
                        children: [
                          Expanded(
                            child: _buildFilterDropdown(
                              'Department',
                              [
                                'All Departments',
                                'Human Resources',
                                'Finance',
                                'Records',
                                'Administration',
                                'Other'
                              ],
                              _selectedDepartmentFilter,
                              (newValue) {
                                setState(() {
                                  _selectedDepartmentFilter = newValue;
                                  _filterDocuments();
                                });
                              },
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: _buildFilterDropdown(
                              'Document Type',
                              [
                                'All Types',
                                'Report',
                                'Memo',
                                'Payroll',
                                'Contract',
                                'Policy',
                                'Spreadsheet',
                                'Image',
                                'Other'
                              ],
                              _selectedDocumentTypeFilter,
                              (newValue) {
                                setState(() {
                                  _selectedDocumentTypeFilter = newValue;
                                  _filterDocuments();
                                });
                              },
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 15),
                      GestureDetector(
                        onTap: () async {
                          final picked = await showDateRangePicker(
                            context: context,
                            firstDate: DateTime(2000),
                            lastDate: DateTime(2101),
                            initialEntryMode: DatePickerEntryMode.calendarOnly,
                            builder: (context, child) {
                              return Theme(
                                data: ThemeData.light().copyWith(
                                  colorScheme: const ColorScheme.light(
                                    primary: Color(
                                        0xFF6868AC), // Header background color
                                    onPrimary:
                                        Colors.white, // Header text color
                                    onSurface:
                                        Colors.black87, // Body text color
                                  ),
                                  textButtonTheme: TextButtonThemeData(
                                    style: TextButton.styleFrom(
                                      foregroundColor: const Color(
                                          0xFF6868AC), // Button text color
                                    ),
                                  ),
                                ),
                                child: child!,
                              );
                            },
                          );
                          if (picked != null &&
                              (picked.start != _startDateFilter ||
                                  picked.end != _endDateFilter)) {
                            setState(() {
                              _startDateFilter = picked.start;
                              _endDateFilter = picked.end;
                              _filterDocuments();
                            });
                          }
                        },
                        child: AbsorbPointer(
                          child: TextField(
                            decoration: InputDecoration(
                              labelText: _startDateFilter == null
                                  ? 'Select Date Range'
                                  : '${DateFormat('MMM dd,yyyy').format(_startDateFilter!)} - ${DateFormat('MMM dd,yyyy').format(_endDateFilter!)}',
                              prefixIcon: const Icon(Icons.date_range,
                                  color: Color(0xFF6868AC)),
                              border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(10),
                                borderSide: BorderSide.none,
                              ),
                              filled: true,
                              fillColor: Colors.white.withOpacity(0.9),
                              contentPadding: const EdgeInsets.symmetric(
                                  vertical: 12, horizontal: 16),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(height: 15),
                      SizedBox(
                        width: double.infinity,
                        child: ElevatedButton.icon(
                          onPressed: _clearFilters,
                          icon: const Icon(Icons.refresh, color: Colors.white),
                          label: const Text('Clear Filters',
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
                ),
                // Archive Actions and Document List
                Expanded(
                  child: Card(
                    elevation: 0,
                    margin:
                        const EdgeInsets.symmetric(horizontal: 5, vertical: 5),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(20)),
                    child: Padding(
                      padding: const EdgeInsets.all(5.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              const Text(
                                'Archived Documents',
                                style: TextStyle(
                                  fontSize: 22,
                                  fontWeight: FontWeight.bold,
                                  color: Color(0xFF52528A),
                                ),
                              ),
                              // Changed from Row to Wrap for better mobile responsiveness
                              Wrap(
                                spacing:
                                    10, // Horizontal spacing between buttons
                                runSpacing:
                                    10, // Vertical spacing between lines of buttons
                                alignment: WrapAlignment
                                    .end, // Align buttons to the end
                                children: [
                                  if (anySelected) // Show only if any document is selected
                                    ElevatedButton.icon(
                                      onPressed: () {
                                        // Simulate export selected
                                        ScaffoldMessenger.of(context)
                                            .showSnackBar(
                                          SnackBar(
                                              content: Text(
                                                  'Exporting ${_selectedDocumentIds.length} selected documents...')),
                                        );
                                        // TODO: Implement actual export logic (e.g., generate CSV from selected docs)
                                      },
                                      icon: const Icon(Icons.file_download,
                                          color: Colors.white),
                                      label: const Text('Export Selected',
                                          style:
                                              TextStyle(color: Colors.white)),
                                      style: ElevatedButton.styleFrom(
                                        backgroundColor: Colors.teal.shade600,
                                        shape: RoundedRectangleBorder(
                                            borderRadius:
                                                BorderRadius.circular(10)),
                                      ),
                                    ),
                                  if (anySelected) // Show only if any document is selected
                                    ElevatedButton.icon(
                                      onPressed:
                                          _confirmDeleteSelectedDocuments,
                                      icon: const Icon(Icons.delete_forever,
                                          color: Colors.white),
                                      label: const Text('Delete Selected',
                                          style:
                                              TextStyle(color: Colors.white)),
                                      style: ElevatedButton.styleFrom(
                                        backgroundColor: Colors.red.shade600,
                                        shape: RoundedRectangleBorder(
                                            borderRadius:
                                                BorderRadius.circular(10)),
                                      ),
                                    ),
                                  ElevatedButton.icon(
                                    onPressed: _showNewArchiveModal,
                                    icon: const Icon(Icons.add,
                                        color: Colors.white),
                                    label: const Text('New Archive',
                                        style: TextStyle(color: Colors.white)),
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: const Color(0xFF6868AC),
                                      shape: RoundedRectangleBorder(
                                          borderRadius:
                                              BorderRadius.circular(50)),
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                          const Divider(height: 25, thickness: 1.5),
                          // Select All Checkbox (only if there are documents)
                          if (_filteredArchivedDocuments.isNotEmpty)
                            CheckboxListTile(
                              title: const Text('Select All Visible'),
                              value: allSelected,
                              onChanged: _toggleSelectAll,
                              controlAffinity: ListTileControlAffinity.leading,
                              activeColor: const Color(0xFF6868AC),
                            ),
                          _filteredArchivedDocuments.isEmpty
                              ? Center(
                                  child: Column(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Icon(Icons.archive_outlined,
                                          size: 80,
                                          color: const Color(0xFF6868AC)
                                              .withOpacity(0.4)),
                                      const SizedBox(height: 16),
                                      Text(
                                        _searchQuery.isEmpty
                                            ? 'No documents in archive yet.'
                                            : 'No results found for "$_searchQuery"',
                                        style: TextStyle(
                                            fontSize: 18,
                                            color: Colors.grey.shade600),
                                        textAlign: TextAlign.center,
                                      ),
                                    ],
                                  ),
                                )
                              : Expanded(
                                  child: ListView.builder(
                                    itemCount: paginatedDocuments.length,
                                    itemBuilder: (context, index) {
                                      final doc = paginatedDocuments[index];
                                      final isSelected =
                                          _selectedDocumentIds.contains(doc.id);
                                      return AnimatedContainer(
                                        duration:
                                            const Duration(milliseconds: 300),
                                        curve: Curves.easeInOut,
                                        margin: const EdgeInsets.symmetric(
                                            vertical: 8.0),
                                        decoration: BoxDecoration(
                                          color: isSelected
                                              ? const Color(0xFF6868AC)
                                                  .withOpacity(0.08)
                                              : Colors.white,
                                          borderRadius:
                                              BorderRadius.circular(16),
                                          boxShadow: [
                                            BoxShadow(
                                              color: Colors.black
                                                  .withOpacity(0.08),
                                              blurRadius: 8,
                                              offset: const Offset(0, 4),
                                            ),
                                          ],
                                        ),
                                        child: InkWell(
                                          onTap: () =>
                                              _showDocumentPreviewModal(doc),
                                          borderRadius:
                                              BorderRadius.circular(16),
                                          child: Padding(
                                            padding: const EdgeInsets.all(12.0),
                                            child: Row(
                                              children: [
                                                Checkbox(
                                                  value: isSelected,
                                                  onChanged: (bool? value) {
                                                    _toggleDocumentSelection(
                                                        doc.id);
                                                  },
                                                  activeColor:
                                                      const Color(0xFF6868AC),
                                                ),
                                                CircleAvatar(
                                                  backgroundColor:
                                                      const Color(0xFF6868AC)
                                                          .withOpacity(0.12),
                                                  child: Icon(
                                                      _getFileIcon(
                                                          doc.fileType),
                                                      color: const Color(
                                                          0xFF6868AC),
                                                      size: 24),
                                                ),
                                                const SizedBox(width: 12),
                                                Expanded(
                                                  child: Column(
                                                    crossAxisAlignment:
                                                        CrossAxisAlignment
                                                            .start,
                                                    children: [
                                                      Text(
                                                        doc.name,
                                                        style: const TextStyle(
                                                          fontWeight:
                                                              FontWeight.bold,
                                                          fontSize: 16,
                                                          color: Colors.black87,
                                                        ),
                                                        overflow: TextOverflow
                                                            .ellipsis,
                                                      ),
                                                      const SizedBox(height: 4),
                                                      Text(
                                                        'ID: ${doc.id} | ${doc.department}',
                                                        style: TextStyle(
                                                            fontSize: 13,
                                                            color: Colors
                                                                .grey.shade700),
                                                      ),
                                                      Text(
                                                        '${doc.type} | ${doc.date} | ${doc.size}',
                                                        style: TextStyle(
                                                            fontSize: 13,
                                                            color: Colors
                                                                .grey.shade700),
                                                      ),
                                                    ],
                                                  ),
                                                ),
                                                // Action buttons for each document
                                                Column(
                                                  children: [
                                                    IconButton(
                                                      icon: const Icon(
                                                          Icons.visibility,
                                                          color: Color(
                                                              0xFF6868AC)),
                                                      onPressed: () =>
                                                          _showDocumentPreviewModal(
                                                              doc),
                                                      tooltip: 'Preview',
                                                    ),
                                                    IconButton(
                                                      icon: const Icon(
                                                          Icons.delete,
                                                          color:
                                                              Colors.redAccent),
                                                      onPressed: () {
                                                        // Confirm before deleting individual document
                                                        _confirmDeleteSelectedDocuments(); // Re-using for single delete confirmation
                                                      },
                                                      tooltip: 'Delete',
                                                    ),
                                                  ],
                                                ),
                                              ],
                                            ),
                                          ),
                                        ),
                                      );
                                    },
                                  ),
                                ),
                          // Pagination Controls
                          if (_filteredArchivedDocuments.isNotEmpty)
                            Padding(
                              padding: const EdgeInsets.only(top: 16.0),
                              child: Row(
                                mainAxisAlignment: MainAxisAlignment.end,
                                children: [
                                  IconButton(
                                    icon: const Icon(Icons.chevron_left),
                                    onPressed: _currentPage > 1
                                        ? () {
                                            setState(() {
                                              _currentPage--;
                                            });
                                          }
                                        : null,
                                  ),
                                  Text('Page $_currentPage of $totalPages'),
                                  IconButton(
                                    icon: const Icon(Icons.chevron_right),
                                    onPressed: _currentPage < totalPages
                                        ? () {
                                            setState(() {
                                              _currentPage++;
                                            });
                                          }
                                        : null,
                                  ),
                                ],
                              ),
                            ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          // Notification Drawer (remains unchanged from original functionality)
          SlideTransition(
            position: _slideAnimation,
            child: Align(
              alignment: Alignment.centerRight,
              child: Material(
                elevation: 8,
                child: Container(
                  width: MediaQuery.of(context).size.width * 0.7,
                  height: double.infinity,
                  color: Colors.white,
                  child: Column(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(16),
                        color: const Color(0xFF6868AC),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text(
                              'Notifications',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 20,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            IconButton(
                              icon:
                                  const Icon(Icons.close, color: Colors.white),
                              onPressed: toggleNotificationDrawer,
                            ),
                          ],
                        ),
                      ),
                      Expanded(
                        child: notifications.isEmpty
                            ? const Center(
                                child: Text('No new notifications.'),
                              )
                            : ListView.separated(
                                padding:
                                    const EdgeInsets.symmetric(vertical: 8.0),
                                itemBuilder: (context, index) {
                                  return ListTile(
                                    leading: const Icon(
                                        Icons.notifications_active,
                                        color: Color(0xFF6868AC)),
                                    title: Text(
                                      notifications[index],
                                      style: const TextStyle(
                                          fontSize: 15, color: Colors.black87),
                                    ),
                                    contentPadding: const EdgeInsets.symmetric(
                                        horizontal: 10, vertical: 5),
                                    trailing: Icon(Icons.chevron_right,
                                        color: const Color(0xFF6868AC)
                                            .withOpacity(0.4)),
                                    onTap: () {
                                      ScaffoldMessenger.of(context)
                                          .showSnackBar(
                                        SnackBar(
                                          content: Text(
                                              'Tapped: ${notifications[index]}'),
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
                                itemCount: notifications.length,
                              ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  // Helper for filter dropdowns
  Widget _buildFilterDropdown(String hint, List<String> items,
      String? selectedValue, ValueChanged<String?> onChanged) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.9),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF6868AC).withOpacity(0.25)),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.03),
            spreadRadius: 1,
            blurRadius: 3,
            offset: const Offset(0, 2),
          ),
        ],
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
}
