import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
// ignore: depend_on_referenced_packages
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'services/routing_service.dart';
import 'services/server_service.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Document Viewer',
      theme: ThemeData(
        primarySwatch: Colors.blue,
      ),
      home: const GalleryPage(),
    );
  }
}

class DocumentDetailPage extends StatefulWidget {
  final String imagePath;
  final String textPath;
  final String fileName;

  const DocumentDetailPage({
    super.key,
    required this.imagePath,
    required this.textPath,
    required this.fileName,
  });

  @override
  State<DocumentDetailPage> createState() => _DocumentDetailPageState();
}

class _DocumentDetailPageState extends State<DocumentDetailPage> {
  String _ocrContent = '';
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadOCRContent();
  }

  Future<void> _loadOCRContent() async {
    try {
      final file = File(widget.textPath);
      if (await file.exists()) {
        final content = await file.readAsString();
        // Extract only the text content, removing metadata
        final extractedText = _extractOnlyText(content);
        setState(() {
          _ocrContent = extractedText;
          _isLoading = false;
        });
      } else {
        setState(() {
          _ocrContent = 'OCR content not available';
          _isLoading = false;
        });
      }
    } catch (e) {
      setState(() {
        _ocrContent = 'Error loading OCR content: $e';
        _isLoading = false;
      });
    }
  }

  /// Extracts only the extracted text portion, removing metadata
  String _extractOnlyText(String content) {
    // Look for common separators that indicate the start of extracted text
    final separators = [
      '--- Extracted Text ---',
      '---Extracted Text---',
      'Extracted Text:',
      '=== Extracted Text ===',
      'OCR Text:',
      '--- OCR Text ---',
    ];

    // Try to find the separator
    for (final separator in separators) {
      final index = content.indexOf(separator);
      if (index != -1) {
        // Return everything after the separator
        return content.substring(index + separator.length).trim();
      }
    }

    // If no separator found, try to detect and remove metadata patterns
    // Look for patterns like "Document Name:", "Document Type:", etc.
    final metadataPatterns = [
      RegExp(r'Document Name:\s*.*?(\n|$)'),
      RegExp(r'Document Type:\s*.*?(\n|$)'),
      RegExp(r'Scanned By:\s*.*?(\n|$)'),
      RegExp(r'User Email:\s*.*?(\n|$)'),
      RegExp(r'User Role:\s*.*?(\n|$)'),
      RegExp(r'Department:\s*.*?(\n|$)'),
      RegExp(r'Scan Date:\s*.*?(\n|$)'),
      RegExp(r'Confidence:\s*.*?(\n|$)'),
      RegExp(r'Detected Types:\s*.*?(\n|$)'),
    ];

    // Try to find where metadata ends and actual text begins
    // Look for lines that start with common document formats (like "RESIGNATION LETTER", etc.)
    final lines = content.split('\n');
    int textStartIndex = 0;

    // Find the first line that looks like actual document content
    // (not metadata, not empty, not a separator)
    for (int i = 0; i < lines.length; i++) {
      final line = lines[i].trim();

      // Skip empty lines
      if (line.isEmpty) continue;

      // Skip metadata lines
      bool isMetadata = false;
      for (final pattern in metadataPatterns) {
        if (pattern.hasMatch(line)) {
          isMetadata = true;
          break;
        }
      }

      // Skip separator lines
      if (separators.any((sep) => line.contains(sep))) {
        continue;
      }

      // If we find a line that's not metadata and looks like content, start from here
      if (!isMetadata && line.length > 3) {
        textStartIndex = i;
        break;
      }
    }

    // Return content starting from the detected text start
    if (textStartIndex > 0) {
      return lines.sublist(textStartIndex).join('\n').trim();
    }

    // Fallback: if we can't detect metadata, return original content
    return content.trim();
  }

  void _copyToClipboard() {
    Clipboard.setData(ClipboardData(text: _ocrContent));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('OCR content copied to clipboard!'),
        duration: Duration(seconds: 2),
      ),
    );
  }

  void _shareContent() {
    Share.share(_ocrContent, subject: 'OCR Content - ${widget.fileName}');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.fileName),
        backgroundColor: Colors.blue.shade700,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            onPressed: _copyToClipboard,
            icon: const Icon(Icons.copy),
            tooltip: 'Copy OCR Text',
          ),
          IconButton(
            onPressed: _shareContent,
            icon: const Icon(Icons.share),
            tooltip: 'Share OCR Text',
          ),
        ],
      ),
      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Image Preview
            Container(
              height: 300,
              margin: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                boxShadow: [
                  BoxShadow(
                    color: Colors.grey.withOpacity(0.3),
                    blurRadius: 8,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: File(widget.imagePath).existsSync()
                    ? Image.file(
                        File(widget.imagePath),
                        fit: BoxFit.cover,
                      )
                    : Container(
                        color: Colors.grey.shade200,
                        child: const Center(
                          child: Icon(
                            Icons.image_not_supported,
                            size: 64,
                            color: Colors.grey,
                          ),
                        ),
                      ),
              ),
            ),

            // OCR Content
            Container(
              margin: const EdgeInsets.all(16),
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                boxShadow: [
                  BoxShadow(
                    color: Colors.grey.withOpacity(0.2),
                    blurRadius: 8,
                    offset: const Offset(0, 2),
                  ),
                ],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(Icons.text_fields, color: Colors.blue.shade700),
                      const SizedBox(width: 8),
                      Text(
                        'OCR Content',
                        style: TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: Colors.blue.shade700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  _isLoading
                      ? const Center(
                          child: CircularProgressIndicator(),
                        )
                      : Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: Colors.grey.shade50,
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: Colors.grey.shade300),
                          ),
                          child: SelectableText(
                            _ocrContent,
                            style: const TextStyle(
                              fontSize: 16,
                              height: 1.6,
                              color: Colors.black87,
                              fontFamily: 'Roboto',
                            ),
                          ),
                        ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class GalleryPage extends StatefulWidget {
  const GalleryPage({super.key});

  @override
  State<GalleryPage> createState() => _GalleryPageState();
}

class _GalleryPageState extends State<GalleryPage> {
  List<Map<String, dynamic>> _archivedItems = [];
  bool _isLoading = true;
  String _searchQuery = '';
  String _activeFilter = 'all'; // all, image, pdf, text
  bool _selectMode = false;
  final Set<String> _selectedTimestamps = {};

  @override
  void initState() {
    super.initState();
    _loadArchivedItems();
  }

  Future<void> _loadArchivedItems() async {
    try {
      // Get user department for department-specific storage
      final prefs = await SharedPreferences.getInstance();
      final String userDepartment =
          prefs.getString('user_department') ?? 'General';

      final Directory extDir = await getApplicationDocumentsDirectory();
      final String archivePath = '${extDir.path}/Archive/$userDepartment';
      final Directory archiveDir = Directory(archivePath);

      if (await archiveDir.exists()) {
        final List<FileSystemEntity> files = archiveDir.listSync();
        final List<Map<String, dynamic>> items = [];

        // Group files by timestamp
        final Map<String, Map<String, String>> groupedFiles = {};

        for (FileSystemEntity file in files) {
          if (file is File) {
            final String fileName = file.path.split('/').last;
            if (fileName.startsWith('IMG_')) {
              final String timestamp =
                  fileName.substring(4, fileName.lastIndexOf('.'));
              groupedFiles[timestamp] ??= {};
              groupedFiles[timestamp]!['image'] = file.path;
            } else if (fileName.startsWith('OCR_')) {
              final String timestamp =
                  fileName.substring(4, fileName.lastIndexOf('.'));
              groupedFiles[timestamp] ??= {};
              groupedFiles[timestamp]!['text'] = file.path;
            } else if (fileName.startsWith('PDF_')) {
              final String timestamp =
                  fileName.substring(4, fileName.lastIndexOf('.'));
              groupedFiles[timestamp] ??= {};
              groupedFiles[timestamp]!['pdf'] = file.path;
            }
          }
        }

        // Create items from grouped files
        for (String timestamp in groupedFiles.keys) {
          final group = groupedFiles[timestamp]!;
          // Use any available file to derive stats
          final String? primaryPath =
              group['image'] ?? group['pdf'] ?? group['text'];
          if (primaryPath != null) {
            final file = File(primaryPath);
            if (await file.exists()) {
              final stat = await file.stat();
              items.add({
                'timestamp': timestamp,
                'imagePath': group['image'],
                'textPath': group['text'],
                'pdfPath': group['pdf'],
                'fileName': 'DOC_$timestamp',
                'dateModified': stat.modified,
                'size': stat.size,
              });
            }
          }
        }

        // Sort by date (newest first)
        items.sort((a, b) => b['dateModified'].compareTo(a['dateModified']));

        setState(() {
          _archivedItems = items;
          _isLoading = false;
        });
      } else {
        setState(() {
          _archivedItems = [];
          _isLoading = false;
        });
      }
    } catch (e) {
      setState(() {
        _archivedItems = [];
        _isLoading = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Error loading gallery: $e')),
      );
    }
  }

  // Send individual document to admin tracking system
  Future<void> _sendDocumentToAdmin({
    required Map<String, dynamic> item,
    required String userName,
    required String userEmail,
    required String userDepartment,
    String? nextDepartment,
    String? endLocation,
    String? sendToUser,
  }) async {
    try {
      // Read OCR content if available
      String ocrContent = '';
      String documentType = 'Scanned Document';

      if (item['textPath'] != null) {
        final textFile = File(item['textPath']);
        if (await textFile.exists()) {
          final content = await textFile.readAsString();
          ocrContent = content;

          // Extract document type from OCR content if available
          final lines = content.split('\n');
          for (final line in lines) {
            if (line.contains('Document Type:')) {
              documentType = line.split(':').last.trim();
              break;
            }
          }
        }
      }

      // Prepare document data for admin system
      final documentData = {
        'type': documentType,
        'employee_name': userName,
        'date_submitted':
            item['dateModified'].toString().split(' ')[0], // Format: YYYY-MM-DD
        'current_holder': nextDepartment ?? userDepartment,
        'end_location': endLocation ?? 'Mobile App Archive',
        'status': 'Pending',
        'department': nextDepartment ?? userDepartment,
        'file_type_icon': _getFileTypeIcon(item),
        'ocr_content': ocrContent,
        'mobile_timestamp':
            'GALLERY_${item['timestamp']}_${DateTime.now().millisecondsSinceEpoch}_${DateTime.now().microsecond}',
        'file_size': item['size'].toString(),
        'user_email': userEmail,
      };

      // Resolve server base URL: saved or quick fallbacks
      final prefs = await SharedPreferences.getInstance();
      String? baseUrl = prefs.getString('detected_server_url');
      baseUrl ??= await ServerService.detectServerUrl();
      baseUrl ??= await ServerService.getServerUrl();
      final root = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
      final String apiUrl = '$root/lib/OCR(UPDATED)/api/sync_document.php';

      final response = await http.post(
        Uri.parse(apiUrl),
        headers: {
          'Content-Type': 'application/json',
        },
        body: json.encode(documentData),
      );

      debugPrint('Sending request to: $apiUrl');
      debugPrint('Request data: ${json.encode(documentData)}');

      if (response.statusCode == 200) {
        final result = json.decode(response.body);
        debugPrint('Response: ${response.body}');
        if (result['success'] == true) {
          debugPrint('✅ Document synced successfully: ${item['fileName']}');

          // Route document and create notification if sending to another user/department
          final toDept = (nextDepartment ?? userDepartment);
          final fromDept = userDepartment;

          // Only route if sending to a different department or specific user
          final isDifferentDept = toDept.isNotEmpty &&
              toDept.trim().toUpperCase() != fromDept.trim().toUpperCase();
          final hasRecipient =
              sendToUser != null && sendToUser.trim().isNotEmpty;

          if (isDifferentDept || hasRecipient) {
            // Route to selected department/user via Firebase
            try {
              final imagePath = item['imagePath'] ?? '';
              await RoutingService.createRoute(
                documentName: item['fileName'] ?? 'Document',
                documentType: documentType,
                imagePath: imagePath,
                ocrContent: ocrContent,
                fromDepartment: fromDept,
                fromUser: userName,
                toDepartment: toDept,
                toUser: sendToUser,
              );
              debugPrint(
                  '✅ Document routed via Firebase to: $toDept${sendToUser != null ? ' ($sendToUser)' : ''}');
            } catch (e) {
              debugPrint('⚠️ Firestore route failed: $e');
            }

            // Create notification for the target department/user
            try {
              final root2 = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
              final url2 = '$root2/lib/OCR(UPDATED)/api/notifications.php';
              final deptTarget = toDept.trim();

              // Create notification payload
              final notificationPayload = <String, String>{
                'action': 'create',
                'type': 'mobile_message',
                'title': 'New Document from $userName',
                'content': '$documentType • ${item['fileName']}',
                'department': fromDept,
                'recipient_department': deptTarget,
                'sender_username': userName,
              };

              // If specific user was selected, add recipient_username
              if (sendToUser != null && sendToUser.trim().isNotEmpty) {
                notificationPayload['recipient_username'] = sendToUser.trim();
              }

              debugPrint('[notify-dept] POST -> $url2');
              debugPrint('[notify-dept] Payload: $notificationPayload');
              final resp2 = await http
                  .post(Uri.parse(url2), body: notificationPayload)
                  .timeout(const Duration(seconds: 10));

              if (resp2.statusCode == 200) {
                debugPrint(
                    '✅ Notification created successfully: ${resp2.body}');
              } else {
                debugPrint(
                    '⚠️ Notification creation failed: ${resp2.statusCode} - ${resp2.body}');
              }
            } catch (e) {
              debugPrint('❌ Notification creation error: $e');
            }
          }
        } else {
          debugPrint('❌ Failed to sync document: ${result['message']}');
        }
      } else {
        debugPrint('❌ HTTP Error: ${response.statusCode} - ${response.body}');
      }
    } catch (e) {
      debugPrint('Error sending document to admin: $e');
    }
  }

  // Determine file type icon based on available files
  String _getFileTypeIcon(Map<String, dynamic> item) {
    if (item['pdfPath'] != null) return 'pdf';
    if (item['imagePath'] != null) return 'jpg';
    if (item['textPath'] != null) return 'txt';
    return 'file';
  }

  // Show modal asking user if they want to upload specific document to admin tracking
  Future<void> _showUploadToTrackingModal(Map<String, dynamic> item) async {
    String selectedDepartment = 'CPDO';
    String selectedEndLocation = 'CPDO';

    final departments = [
      'CPDO',
      'GSO',
      'CBO',
      'CTO',
      'CACCO',
      'CADO',
      'CMO',
    ];

    final endLocations = [
      // End locations mirror the departments
      'CPDO',
      'GSO',
      'CBO',
      'CTO',
      'CACCO',
      'CADO',
      'CMO',
    ];

    return showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return StatefulBuilder(
          builder: (context, setState) {
            return AlertDialog(
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
              title: Row(
                children: [
                  Icon(Icons.cloud_upload,
                      color: Colors.purple.shade700, size: 28),
                  const SizedBox(width: 12),
                  const Expanded(
                    child: Text(
                      'Upload to Admin Tracking',
                      style:
                          TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Document: ${item['fileName']}',
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Size: ${_formatFileSize(item['size'])}',
                      style: TextStyle(color: Colors.grey.shade600),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Date: ${_formatDate(item['dateModified'])}',
                      style: TextStyle(color: Colors.grey.shade600),
                    ),
                    const SizedBox(height: 20),

                    // Department Dropdown
                    Text(
                      'Next Department:',
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        color: Colors.grey.shade800,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      decoration: BoxDecoration(
                        border: Border.all(color: Colors.purple.shade200),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          value: selectedDepartment,
                          isExpanded: true,
                          icon: Icon(Icons.arrow_drop_down,
                              color: Colors.purple.shade700),
                          items: departments.map((String dept) {
                            return DropdownMenuItem<String>(
                              value: dept,
                              child: Text(dept),
                            );
                          }).toList(),
                          onChanged: (String? newValue) {
                            if (newValue != null) {
                              setState(() {
                                selectedDepartment = newValue;
                              });
                            }
                          },
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),

                    // End Location Dropdown
                    Text(
                      'End Location:',
                      style: TextStyle(
                        fontWeight: FontWeight.w600,
                        color: Colors.grey.shade800,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      decoration: BoxDecoration(
                        border: Border.all(color: Colors.purple.shade200),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          value: selectedEndLocation,
                          isExpanded: true,
                          icon: Icon(Icons.arrow_drop_down,
                              color: Colors.purple.shade700),
                          items: endLocations.map((String loc) {
                            return DropdownMenuItem<String>(
                              value: loc,
                              child: Text(loc),
                            );
                          }).toList(),
                          onChanged: (String? newValue) {
                            if (newValue != null) {
                              setState(() {
                                selectedEndLocation = newValue;
                              });
                            }
                          },
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),

                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.purple.shade50,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: Colors.purple.shade200),
                      ),
                      child: Row(
                        children: [
                          Icon(Icons.info_outline,
                              color: Colors.purple.shade700, size: 20),
                          const SizedBox(width: 8),
                          const Expanded(
                            child: Text(
                              'This will send your document to the admin tracking system.',
                              style: TextStyle(fontSize: 13),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () {
                    Navigator.of(context).pop();
                  },
                  child: Text(
                    'Cancel',
                    style: TextStyle(color: Colors.grey.shade600),
                  ),
                ),
                ElevatedButton(
                  onPressed: () async {
                    Navigator.of(context).pop();

                    // Show uploading progress
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Row(
                          children: [
                            SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2, color: Colors.white),
                            ),
                            SizedBox(width: 12),
                            Text('Uploading to admin tracking...'),
                          ],
                        ),
                        duration: Duration(seconds: 3),
                      ),
                    );

                    // Upload to admin tracking with selected department and end location
                    await _sendDocumentToAdmin(
                      item: item,
                      userName: (await SharedPreferences.getInstance())
                              .getString('user_name') ??
                          'Unknown User',
                      userEmail: (await SharedPreferences.getInstance())
                              .getString('user_email') ??
                          '',
                      userDepartment: (await SharedPreferences.getInstance())
                              .getString('user_department') ??
                          'General',
                      nextDepartment: selectedDepartment,
                      endLocation: selectedEndLocation,
                    );

                    // Show success
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text(
                            '✅ Document uploaded to admin tracking successfully!'),
                        backgroundColor: Colors.green,
                      ),
                    );
                  },
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.purple.shade700,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(8),
                    ),
                  ),
                  child: const Text('Upload to Tracking'),
                ),
              ],
            );
          },
        );
      },
    );
  }

  List<Map<String, dynamic>> get _filteredItems {
    Iterable<Map<String, dynamic>> list = _archivedItems;
    // Filter by type
    if (_activeFilter != 'all') {
      list = list.where((item) {
        switch (_activeFilter) {
          case 'image':
            return item['imagePath'] != null;
          case 'pdf':
            return item['pdfPath'] != null;
          case 'text':
            return item['textPath'] != null;
          default:
            return true;
        }
      });
    }
    // Search
    if (_searchQuery.isNotEmpty) {
      list = list.where((item) {
        final hay = '${item['fileName']}'.toLowerCase();
        return hay.contains(_searchQuery.toLowerCase());
      });
    }
    return list.toList();
  }

  void _toggleSelect(String timestamp) {
    setState(() {
      _selectMode = true;
      if (_selectedTimestamps.contains(timestamp)) {
        _selectedTimestamps.remove(timestamp);
        if (_selectedTimestamps.isEmpty) _selectMode = false;
      } else {
        _selectedTimestamps.add(timestamp);
      }
    });
  }

  Future<void> _deleteSelected() async {
    final toDelete = _archivedItems
        .where((e) => _selectedTimestamps.contains(e['timestamp']))
        .toList();
    for (final item in toDelete) {
      for (final key in ['imagePath', 'textPath', 'pdfPath']) {
        final p = item[key];
        if (p != null) {
          try {
            await File(p).delete();
          } catch (_) {}
        }
      }
    }
    setState(() {
      _selectedTimestamps.clear();
      _selectMode = false;
    });
    await _loadArchivedItems();
  }

  String _formatFileSize(int bytes) {
    if (bytes < 1024) return '${bytes}B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)}KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)}MB';
  }

  String _formatDate(DateTime date) {
    final now = DateTime.now();
    final difference = now.difference(date);

    if (difference.inDays == 0) {
      return 'Today ${date.hour.toString().padLeft(2, '0')}:${date.minute.toString().padLeft(2, '0')}';
    } else if (difference.inDays == 1) {
      return 'Yesterday';
    } else if (difference.inDays < 7) {
      return '${difference.inDays} days ago';
    } else {
      return '${date.day}/${date.month}/${date.year}';
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
            _selectMode ? '${_selectedTimestamps.length} selected' : 'Gallery'),
        backgroundColor: Colors.blue.shade700,
        foregroundColor: Colors.white,
        elevation: 0,
        actions: [
          if (_selectMode)
            IconButton(
              tooltip: 'Delete Selected',
              icon: const Icon(Icons.delete_outline),
              onPressed: _deleteSelected,
            ),
          IconButton(
            onPressed: _loadArchivedItems,
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh Gallery',
          ),
        ],
      ),
      body: Column(
        children: [
          // Search Bar and Filters
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.blue.shade700,
              borderRadius: const BorderRadius.only(
                bottomLeft: Radius.circular(20),
                bottomRight: Radius.circular(20),
              ),
            ),
            child: Column(
              children: [
                // Search input
                Container(
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(25),
                  ),
                  child: TextField(
                    onChanged: (value) {
                      setState(() {
                        _searchQuery = value;
                      });
                    },
                    decoration: const InputDecoration(
                      hintText: 'Search archived documents...',
                      prefixIcon: Icon(Icons.search, color: Colors.grey),
                      border: InputBorder.none,
                      contentPadding:
                          EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                // Filter dropdown - More visible and professional
                Row(
                  children: [
                    const Icon(Icons.filter_list,
                        color: Colors.white, size: 20),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 16, vertical: 4),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(25),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.1),
                              blurRadius: 4,
                              offset: const Offset(0, 2),
                            ),
                          ],
                        ),
                        child: DropdownButtonHideUnderline(
                          child: DropdownButton<String>(
                            value: _activeFilter,
                            isExpanded: true,
                            icon: Icon(Icons.keyboard_arrow_down,
                                color: Colors.blue.shade700),
                            style: TextStyle(
                              color: Colors.blue.shade700,
                              fontSize: 14,
                              fontWeight: FontWeight.w500,
                            ),
                            items: const [
                              DropdownMenuItem(
                                  value: 'all',
                                  child: Text('📁 All Documents')),
                              DropdownMenuItem(
                                  value: 'image',
                                  child: Text('🖼️ Images Only')),
                              DropdownMenuItem(
                                  value: 'pdf', child: Text('📄 PDF Files')),
                              DropdownMenuItem(
                                  value: 'text', child: Text('📝 Text Files')),
                            ],
                            onChanged: (String? newValue) {
                              if (newValue != null) {
                                setState(() {
                                  _activeFilter = newValue;
                                });
                              }
                            },
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    // Download button
                    Container(
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withOpacity(0.1),
                            blurRadius: 4,
                            offset: const Offset(0, 2),
                          ),
                        ],
                      ),
                      child: IconButton(
                        onPressed: _downloadAllFiles,
                        icon: Icon(Icons.download, color: Colors.blue.shade700),
                        tooltip: 'Download All',
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),

          // Gallery Content
          Expanded(
            child: _isLoading
                ? const Center(
                    child: CircularProgressIndicator(),
                  )
                : _filteredItems.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.photo_library_outlined,
                              size: 80,
                              color: Colors.grey.shade400,
                            ),
                            const SizedBox(height: 16),
                            Text(
                              _searchQuery.isEmpty
                                  ? 'No archived documents found'
                                  : 'No documents match your search',
                              style: TextStyle(
                                fontSize: 18,
                                color: Colors.grey.shade600,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                            const SizedBox(height: 8),
                            Text(
                              _searchQuery.isEmpty
                                  ? 'Capture some documents to see them here'
                                  : 'Try a different search term',
                              style: TextStyle(
                                color: Colors.grey.shade500,
                                fontSize: 14,
                              ),
                            ),
                          ],
                        ),
                      )
                    : GridView.builder(
                        padding: const EdgeInsets.all(16),
                        gridDelegate:
                            const SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 2,
                          crossAxisSpacing: 12,
                          mainAxisSpacing: 12,
                          childAspectRatio: 0.60,
                        ),
                        itemCount: _filteredItems.length,
                        itemBuilder: (context, index) {
                          final item = _filteredItems[index];
                          final ts = item['timestamp'] as String;
                          final selected = _selectedTimestamps.contains(ts);
                          return Card(
                            elevation: 4,
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(16),
                            ),
                            child: InkWell(
                              onLongPress: () => _toggleSelect(ts),
                              onTap: () async {
                                if (_selectMode) {
                                  _toggleSelect(ts);
                                  return;
                                }
                                // Open behavior by available type
                                if (item['imagePath'] != null &&
                                    item['textPath'] != null) {
                                  // Open detail page
                                  // ignore: use_build_context_synchronously
                                  Navigator.push(
                                    context,
                                    MaterialPageRoute(
                                      builder: (context) => DocumentDetailPage(
                                        imagePath: item['imagePath'],
                                        textPath: item['textPath'],
                                        fileName: item['fileName'],
                                      ),
                                    ),
                                  );
                                } else {
                                  // Share the first available file
                                  final p = item['pdfPath'] ??
                                      item['imagePath'] ??
                                      item['textPath'];
                                  if (p != null) {
                                    Share.shareXFiles([XFile(p)],
                                        text: item['fileName']);
                                  }
                                }
                              },
                              borderRadius: BorderRadius.circular(16),
                              child: Stack(
                                children: [
                                  Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.stretch,
                                    children: [
                                      // Image Preview
                                      Expanded(
                                        flex: 3,
                                        child: Container(
                                          decoration: const BoxDecoration(
                                            borderRadius: BorderRadius.vertical(
                                              top: Radius.circular(16),
                                            ),
                                          ),
                                          child: ClipRRect(
                                            borderRadius:
                                                const BorderRadius.vertical(
                                              top: Radius.circular(16),
                                            ),
                                            child: (item['imagePath'] != null &&
                                                    File(item['imagePath'])
                                                        .existsSync())
                                                ? Image.file(
                                                    File(item['imagePath']),
                                                    fit: BoxFit.cover,
                                                  )
                                                : Container(
                                                    color: Colors.grey.shade200,
                                                    child: Icon(
                                                      item['pdfPath'] != null
                                                          ? Icons.picture_as_pdf
                                                          : Icons
                                                              .image_not_supported,
                                                      size: 48,
                                                      color:
                                                          Colors.grey.shade400,
                                                    ),
                                                  ),
                                          ),
                                        ),
                                      ),

                                      // Document Info
                                      Container(
                                        padding: const EdgeInsets.all(12),
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              item['fileName'],
                                              style: const TextStyle(
                                                fontWeight: FontWeight.bold,
                                                fontSize: 14,
                                              ),
                                              maxLines: 1,
                                              overflow: TextOverflow.ellipsis,
                                            ),
                                            const SizedBox(height: 4),
                                            Row(
                                              children: [
                                                Icon(
                                                  Icons.access_time,
                                                  size: 12,
                                                  color: Colors.grey.shade600,
                                                ),
                                                const SizedBox(width: 4),
                                                Expanded(
                                                  child: Text(
                                                    _formatDate(
                                                        item['dateModified']),
                                                    style: TextStyle(
                                                      color:
                                                          Colors.grey.shade600,
                                                      fontSize: 11,
                                                    ),
                                                    maxLines: 1,
                                                    overflow:
                                                        TextOverflow.ellipsis,
                                                  ),
                                                ),
                                              ],
                                            ),
                                            const SizedBox(height: 4),
                                            // File info row
                                            Row(
                                              children: [
                                                Icon(
                                                  Icons.storage,
                                                  size: 12,
                                                  color: Colors.grey.shade600,
                                                ),
                                                const SizedBox(width: 4),
                                                Expanded(
                                                  child: Text(
                                                    _formatFileSize(
                                                        item['size']),
                                                    style: TextStyle(
                                                      color:
                                                          Colors.grey.shade600,
                                                      fontSize: 11,
                                                    ),
                                                    overflow:
                                                        TextOverflow.ellipsis,
                                                  ),
                                                ),
                                                // Type badges - compact
                                                if (item['pdfPath'] != null)
                                                  Icon(Icons.picture_as_pdf,
                                                      size: 12,
                                                      color:
                                                          Colors.red.shade400),
                                                if (item['textPath'] != null)
                                                  Padding(
                                                    padding:
                                                        const EdgeInsets.only(
                                                            left: 2),
                                                    child: Icon(Icons.notes,
                                                        size: 12,
                                                        color: Colors
                                                            .blue.shade600),
                                                  ),
                                                if (item['imagePath'] != null)
                                                  Padding(
                                                    padding:
                                                        const EdgeInsets.only(
                                                            left: 2),
                                                    child: Icon(Icons.image,
                                                        size: 12,
                                                        color: Colors
                                                            .orange.shade600),
                                                  ),
                                              ],
                                            ),
                                            const SizedBox(height: 10),
                                            // Action buttons laid out in two rows to avoid horizontal overflow
                                            Column(
                                              children: [
                                                Row(
                                                  mainAxisAlignment:
                                                      MainAxisAlignment
                                                          .spaceEvenly,
                                                  children: [
                                                    // Upload to Tracking
                                                    Expanded(
                                                      child: Material(
                                                        color:
                                                            Colors.transparent,
                                                        child: InkWell(
                                                          onTap: () =>
                                                              _showUploadToTrackingModal(
                                                                  item),
                                                          borderRadius:
                                                              BorderRadius
                                                                  .circular(10),
                                                          child: Container(
                                                            padding:
                                                                const EdgeInsets
                                                                    .symmetric(
                                                                    vertical:
                                                                        14,
                                                                    horizontal:
                                                                        10),
                                                            decoration:
                                                                BoxDecoration(
                                                              color: Colors
                                                                  .purple
                                                                  .shade50,
                                                              borderRadius:
                                                                  BorderRadius
                                                                      .circular(
                                                                          10),
                                                            ),
                                                            child: Row(
                                                              mainAxisAlignment:
                                                                  MainAxisAlignment
                                                                      .center,
                                                              mainAxisSize:
                                                                  MainAxisSize
                                                                      .min,
                                                              children: [
                                                                Icon(
                                                                    Icons
                                                                        .cloud_upload,
                                                                    size: 24,
                                                                    color: Colors
                                                                        .purple
                                                                        .shade600),
                                                              ],
                                                            ),
                                                          ),
                                                        ),
                                                      ),
                                                    ),
                                                    const SizedBox(width: 8),
                                                    // Rename
                                                    Expanded(
                                                      child: Material(
                                                        color:
                                                            Colors.transparent,
                                                        child: InkWell(
                                                          onTap: () =>
                                                              _showRenameDialog(
                                                                  item),
                                                          borderRadius:
                                                              BorderRadius
                                                                  .circular(10),
                                                          child: Container(
                                                            padding:
                                                                const EdgeInsets
                                                                    .symmetric(
                                                                    vertical:
                                                                        14,
                                                                    horizontal:
                                                                        10),
                                                            decoration:
                                                                BoxDecoration(
                                                              color: Colors
                                                                  .blue.shade50,
                                                              borderRadius:
                                                                  BorderRadius
                                                                      .circular(
                                                                          10),
                                                            ),
                                                            child: Row(
                                                              mainAxisAlignment:
                                                                  MainAxisAlignment
                                                                      .center,
                                                              mainAxisSize:
                                                                  MainAxisSize
                                                                      .min,
                                                              children: [
                                                                Icon(Icons.edit,
                                                                    size: 24,
                                                                    color: Colors
                                                                        .blue
                                                                        .shade600),
                                                              ],
                                                            ),
                                                          ),
                                                        ),
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                                const SizedBox(height: 8),
                                                Row(
                                                  mainAxisAlignment:
                                                      MainAxisAlignment
                                                          .spaceEvenly,
                                                  children: [
                                                    // Delete
                                                    Expanded(
                                                      child: Material(
                                                        color:
                                                            Colors.transparent,
                                                        child: InkWell(
                                                          onTap: () =>
                                                              _showDeleteDialog(
                                                                  item),
                                                          borderRadius:
                                                              BorderRadius
                                                                  .circular(10),
                                                          child: Container(
                                                            padding:
                                                                const EdgeInsets
                                                                    .symmetric(
                                                                    vertical:
                                                                        14,
                                                                    horizontal:
                                                                        10),
                                                            decoration:
                                                                BoxDecoration(
                                                              color: Colors
                                                                  .red.shade50,
                                                              borderRadius:
                                                                  BorderRadius
                                                                      .circular(
                                                                          10),
                                                            ),
                                                            child: Row(
                                                              mainAxisAlignment:
                                                                  MainAxisAlignment
                                                                      .center,
                                                              mainAxisSize:
                                                                  MainAxisSize
                                                                      .min,
                                                              children: [
                                                                Icon(
                                                                    Icons
                                                                        .delete,
                                                                    size: 24,
                                                                    color: Colors
                                                                        .red
                                                                        .shade600),
                                                              ],
                                                            ),
                                                          ),
                                                        ),
                                                      ),
                                                    ),
                                                    const SizedBox(width: 8),
                                                    // Download
                                                    Expanded(
                                                      child: Material(
                                                        color:
                                                            Colors.transparent,
                                                        child: InkWell(
                                                          onTap: () =>
                                                              _downloadItem(
                                                                  item),
                                                          borderRadius:
                                                              BorderRadius
                                                                  .circular(10),
                                                          child: Container(
                                                            padding:
                                                                const EdgeInsets
                                                                    .symmetric(
                                                                    vertical:
                                                                        14,
                                                                    horizontal:
                                                                        10),
                                                            decoration:
                                                                BoxDecoration(
                                                              color: Colors
                                                                  .green
                                                                  .shade50,
                                                              borderRadius:
                                                                  BorderRadius
                                                                      .circular(
                                                                          10),
                                                            ),
                                                            child: Row(
                                                              mainAxisAlignment:
                                                                  MainAxisAlignment
                                                                      .center,
                                                              mainAxisSize:
                                                                  MainAxisSize
                                                                      .min,
                                                              children: [
                                                                Icon(
                                                                    Icons
                                                                        .download,
                                                                    size: 24,
                                                                    color: Colors
                                                                        .green
                                                                        .shade600),
                                                              ],
                                                            ),
                                                          ),
                                                        ),
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                              ],
                                            ),
                                          ],
                                        ),
                                      ),
                                    ],
                                  ),
                                  // Selection checkbox overlay
                                  Positioned(
                                    top: 8,
                                    right: 8,
                                    child: AnimatedOpacity(
                                      duration:
                                          const Duration(milliseconds: 200),
                                      opacity: _selectMode ? 1 : 0,
                                      child: CircleAvatar(
                                        radius: 12,
                                        backgroundColor: selected
                                            ? Colors.blue
                                            : Colors.white,
                                        child: Icon(
                                          selected
                                              ? Icons.check
                                              : Icons.circle_outlined,
                                          size: 16,
                                          color: selected
                                              ? Colors.white
                                              : Colors.grey.shade600,
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
        ],
      ),
    );
  }

  // Download all filtered files
  Future<void> _downloadAllFiles() async {
    try {
      final items = _filteredItems;
      if (items.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('No files to download')),
        );
        return;
      }

      // Show enhanced downloading progress modal
      _showDownloadProgressModal(items.length, isIndividual: false);

      // Get Downloads directory
      final Directory? downloadsDir = await _getDownloadsDirectory();
      if (downloadsDir == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not access Downloads folder')),
        );
        return;
      }

      int downloadedCount = 0;
      for (final item in items) {
        final downloaded = await _downloadItemFiles(item, downloadsDir);
        if (downloaded) downloadedCount++;
      }

      // Success is now handled by the modal dialogs
      debugPrint('Downloaded $downloadedCount of ${items.length} files');
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Row(
            children: [
              const Icon(Icons.error, color: Colors.white, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text('Download failed: $e',
                    style: const TextStyle(fontSize: 14)),
              ),
            ],
          ),
          backgroundColor: Colors.red.shade600,
        ),
      );
    }
  }

  // Download individual item
  Future<void> _downloadItem(Map<String, dynamic> item) async {
    try {
      // Show enhanced downloading progress modal for single item
      _showDownloadProgressModal(1, isIndividual: true);

      final Directory? downloadsDir = await _getDownloadsDirectory();
      if (downloadsDir == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not access Downloads folder')),
        );
        return;
      }

      final downloaded = await _downloadItemFiles(item, downloadsDir);
      // Success/failure is now handled by the modal dialogs
      if (downloaded) {
        debugPrint('Item downloaded successfully');
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Row(
            children: [
              const Icon(Icons.error, color: Colors.white, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text('Download failed: $e',
                    style: const TextStyle(fontSize: 14)),
              ),
            ],
          ),
          backgroundColor: Colors.red.shade600,
        ),
      );
    }
  }

  // Get Downloads directory
  Future<Directory?> _getDownloadsDirectory() async {
    try {
      if (Platform.isAndroid) {
        // For Android, use the Downloads directory
        return Directory('/storage/emulated/0/Download');
      } else {
        // For other platforms, use app documents directory
        return await getApplicationDocumentsDirectory();
      }
    } catch (e) {
      return await getApplicationDocumentsDirectory();
    }
  }

  // Download all files for an item with proper naming
  Future<bool> _downloadItemFiles(
      Map<String, dynamic> item, Directory downloadsDir) async {
    try {
      final fileName = item['fileName'] as String;
      final timestamp = DateTime.now().millisecondsSinceEpoch.toString();
      bool anyDownloaded = false;

      // Create a clean base name from the current file name
      String baseName =
          fileName.replaceFirst(RegExp(r'^(DOC_|IMG_|PDF_|OCR_)'), '');
      if (baseName.isEmpty) baseName = 'Document_$timestamp';

      // Download image if exists
      if (item['imagePath'] != null) {
        final imageFile = File(item['imagePath']);
        if (await imageFile.exists()) {
          final downloadPath =
              path.join(downloadsDir.path, '${baseName}_Image.jpg');
          await imageFile.copy(downloadPath);
          anyDownloaded = true;
        }
      }

      // Download text if exists
      if (item['textPath'] != null) {
        final textFile = File(item['textPath']);
        if (await textFile.exists()) {
          final downloadPath =
              path.join(downloadsDir.path, '${baseName}_Text.txt');
          await textFile.copy(downloadPath);
          anyDownloaded = true;
        }
      }

      // Download PDF if exists
      if (item['pdfPath'] != null) {
        final pdfFile = File(item['pdfPath']);
        if (await pdfFile.exists()) {
          final downloadPath =
              path.join(downloadsDir.path, '${baseName}_PDF.pdf');
          await pdfFile.copy(downloadPath);
          anyDownloaded = true;
        }
      }

      return anyDownloaded;
    } catch (e) {
      return false;
    }
  }

  // Show enhanced download progress modal with success transition
  void _showDownloadProgressModal(int fileCount,
      {required bool isIndividual}) async {
    // Show progress dialog
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return Dialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Animated download icon
                Container(
                  width: 60,
                  height: 60,
                  decoration: BoxDecoration(
                    color: Colors.blue.shade50,
                    borderRadius: BorderRadius.circular(30),
                  ),
                  child: const Icon(
                    Icons.cloud_download,
                    size: 30,
                    color: Colors.blue,
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'Downloading Files',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: Colors.grey.shade800,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'Downloading Files Please wait',
                  style: TextStyle(
                    fontSize: 14,
                    color: Colors.grey.shade600,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                Text(
                  'Location: Downloads folder',
                  style: TextStyle(
                    fontSize: 12,
                    color: Colors.grey.shade500,
                    fontStyle: FontStyle.italic,
                  ),
                ),
                const SizedBox(height: 20),
                // Progress indicator
                const LinearProgressIndicator(
                  backgroundColor: Colors.grey,
                  valueColor: AlwaysStoppedAnimation<Color>(Colors.blue),
                ),
              ],
            ),
          ),
        );
      },
    );

    // Wait for download to complete, then show success
    await Future.delayed(const Duration(seconds: 2));

    // Close progress dialog and show success
    if (Navigator.canPop(context)) {
      Navigator.pop(context);
      _showDownloadSuccessModal(fileCount);
    }
  }

  // Show download success modal
  void _showDownloadSuccessModal(int fileCount) {
    showDialog(
      context: context,
      barrierDismissible: true,
      builder: (BuildContext context) {
        return Dialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Success icon
                Container(
                  width: 60,
                  height: 60,
                  decoration: BoxDecoration(
                    color: Colors.green.shade50,
                    borderRadius: BorderRadius.circular(30),
                  ),
                  child: Icon(
                    Icons.check_circle,
                    size: 30,
                    color: Colors.green.shade600,
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'Download Complete!',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: Colors.grey.shade800,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'Successfully downloaded $fileCount ${fileCount == 1 ? 'file' : 'files'}',
                  style: TextStyle(
                    fontSize: 14,
                    color: Colors.grey.shade600,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                Text(
                  'Files saved to Downloads folder',
                  style: TextStyle(
                    fontSize: 12,
                    color: Colors.green.shade600,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                const SizedBox(height: 20),
                ElevatedButton(
                  onPressed: () => Navigator.pop(context),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.green.shade600,
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(8),
                    ),
                  ),
                  child: const Text('OK'),
                ),
              ],
            ),
          ),
        );
      },
    );

    // Auto close success dialog after 3 seconds
    Future.delayed(const Duration(seconds: 3), () {
      if (Navigator.canPop(context)) {
        Navigator.pop(context);
      }
    });
  }

  // Show rename dialog
  void _showRenameDialog(Map<String, dynamic> item) {
    final TextEditingController nameController = TextEditingController();
    final currentName = item['fileName'] as String;
    // Extract base name without prefix
    String baseName =
        currentName.replaceFirst(RegExp(r'^(DOC_|IMG_|PDF_|OCR_)'), '');
    nameController.text = baseName;

    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Text('Rename File'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Current name: $currentName',
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.grey.shade600,
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: nameController,
                decoration: InputDecoration(
                  labelText: 'New name',
                  hintText: 'Enter new file name',
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                  prefixIcon: const Icon(Icons.edit),
                ),
                maxLength: 50,
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: () {
                final newName = nameController.text.trim();
                if (newName.isNotEmpty && newName != baseName) {
                  _renameFiles(item, newName);
                }
                Navigator.pop(context);
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.blue,
                foregroundColor: Colors.white,
              ),
              child: const Text('Rename'),
            ),
          ],
        );
      },
    );
  }

  // Show delete confirmation dialog
  void _showDeleteDialog(Map<String, dynamic> item) {
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              Icon(Icons.warning, color: Colors.red.shade600),
              const SizedBox(width: 8),
              const Text('Delete File'),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Are you sure you want to delete this file?'),
              const SizedBox(height: 8),
              Text(
                item['fileName'] as String,
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  color: Colors.grey.shade700,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'This action cannot be undone.',
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.red.shade600,
                  fontStyle: FontStyle.italic,
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel'),
            ),
            ElevatedButton(
              onPressed: () {
                _deleteFiles(item);
                Navigator.pop(context);
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.red.shade600,
                foregroundColor: Colors.white,
              ),
              child: const Text('Delete'),
            ),
          ],
        );
      },
    );
  }

  // Delete all files for an item
  Future<void> _deleteFiles(Map<String, dynamic> item) async {
    try {
      bool anyDeleted = false;

      // Delete image file
      if (item['imagePath'] != null) {
        final imageFile = File(item['imagePath']);
        if (await imageFile.exists()) {
          await imageFile.delete();
          anyDeleted = true;
        }
      }

      // Delete text file
      if (item['textPath'] != null) {
        final textFile = File(item['textPath']);
        if (await textFile.exists()) {
          await textFile.delete();
          anyDeleted = true;
        }
      }

      // Delete PDF file
      if (item['pdfPath'] != null) {
        final pdfFile = File(item['pdfPath']);
        if (await pdfFile.exists()) {
          await pdfFile.delete();
          anyDeleted = true;
        }
      }

      if (anyDeleted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: const Row(
              children: [
                Icon(Icons.check_circle, color: Colors.white),
                SizedBox(width: 8),
                Text('File deleted successfully'),
              ],
            ),
            backgroundColor: Colors.green.shade600,
          ),
        );
        await _loadArchivedItems(); // Refresh the list
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Row(
            children: [
              const Icon(Icons.error, color: Colors.white),
              const SizedBox(width: 8),
              Text('Delete failed: $e'),
            ],
          ),
          backgroundColor: Colors.red.shade600,
        ),
      );
    }
  }

  // Rename all files for an item
  Future<void> _renameFiles(
      Map<String, dynamic> item, String newBaseName) async {
    try {
      final timestamp = DateTime.now().millisecondsSinceEpoch.toString();
      final Directory extDir = await getApplicationDocumentsDirectory();
      final String archivePath = '${extDir.path}/Archive';

      bool anyRenamed = false;

      // Rename image file
      if (item['imagePath'] != null) {
        final oldFile = File(item['imagePath']);
        if (await oldFile.exists()) {
          final newPath =
              path.join(archivePath, 'IMG_${newBaseName}_$timestamp.jpg');
          await oldFile.rename(newPath);
          anyRenamed = true;
        }
      }

      // Rename text file
      if (item['textPath'] != null) {
        final oldFile = File(item['textPath']);
        if (await oldFile.exists()) {
          final newPath =
              path.join(archivePath, 'OCR_${newBaseName}_$timestamp.txt');
          await oldFile.rename(newPath);
          anyRenamed = true;
        }
      }

      // Rename PDF file
      if (item['pdfPath'] != null) {
        final oldFile = File(item['pdfPath']);
        if (await oldFile.exists()) {
          final newPath =
              path.join(archivePath, 'PDF_${newBaseName}_$timestamp.pdf');
          await oldFile.rename(newPath);
          anyRenamed = true;
        }
      }

      if (anyRenamed) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Files renamed to: $newBaseName'),
            backgroundColor: Colors.green,
          ),
        );
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Error renaming files: $e'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }
}
