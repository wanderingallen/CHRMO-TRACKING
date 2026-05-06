import 'dart:convert';
import 'dart:io';

import 'package:file_saver/file_saver.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
// ignore: depend_on_referenced_packages
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:permission_handler/permission_handler.dart';
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'encryption_service.dart';
import 'notification_service.dart';
import 'services/server_service.dart';

void main() {
  runApp(const MyApp());
}

class FullScreenImagePage extends StatefulWidget {
  final String imagePath;
  final String title;
  final String? textPath;

  const FullScreenImagePage(
      {super.key, required this.imagePath, required this.title, this.textPath});

  @override
  State<FullScreenImagePage> createState() => _FullScreenImagePageState();
}

class _FullScreenImagePageState extends State<FullScreenImagePage> {
  // No longer needed when using FileSaver/MediaStore, keep for non-Android only
  Future<Directory> _getDownloadsDir() async {
    if (!Platform.isAndroid) {
      final d = await getApplicationDocumentsDirectory();
      if (!(await d.exists())) await d.create(recursive: true);
      return d;
    }
    return Directory('/storage/emulated/0/Download');
  }

  Future<bool> _ensureStoragePermission() async {
    try {
      final storage = await Permission.storage.request();
      if (storage.isGranted) return true;
      final manage = await Permission.manageExternalStorage.request();
      return manage.isGranted;
    } catch (_) {
      return false;
    }
  }

  Future<void> _downloadAsPdf() async {
    try {
      // Ensure storage permission on Android
      if (Platform.isAndroid) {
        final ok = await _ensureStoragePermission();
        if (!ok) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
                content: Text('Storage permission denied. Cannot save file.')),
          );
          return;
        }
      }
      // FileSaver uses MediaStore on Android 10+ and Downloads on older
      final ts = DateTime.now().millisecondsSinceEpoch;
      final base = widget.title.isNotEmpty ? widget.title : 'Document';

      final pdf = pw.Document();
      final imgBytes = await File(widget.imagePath).readAsBytes();
      final pwImg = pw.MemoryImage(imgBytes);

      pdf.addPage(
        pw.Page(
          pageFormat: PdfPageFormat.a4,
          build: (ctx) => pw.Center(
            child: pw.Image(pwImg, fit: pw.BoxFit.contain),
          ),
        ),
      );
      final bytes = await pdf.save();
      await FileSaver.instance.saveFile(
        name: '${base}_$ts',
        bytes: bytes,
        ext: 'pdf',
        mimeType: MimeType.pdf,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Saved PDF to Downloads')),
      );
      NotificationService.showSimple(
          'Download complete', 'Saved ${base}_$ts.pdf to Downloads');
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('PDF download failed: $e')),
      );
    }
  }

  Future<void> _downloadAsDoc() async {
    try {
      // Ensure storage permission on Android
      if (Platform.isAndroid) {
        final ok = await _ensureStoragePermission();
        if (!ok) {
          if (!mounted) return;
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
                content: Text('Storage permission denied. Cannot save file.')),
          );
          return;
        }
      }
      final ts = DateTime.now().millisecondsSinceEpoch;
      final base = widget.title.isNotEmpty ? widget.title : 'Document';

      String content = '';
      if (widget.textPath != null) {
        final tf = File(widget.textPath!);
        if (await tf.exists()) content = await tf.readAsString();
      }
      if (content.trim().isEmpty) {
        content = 'No OCR text available for this image.';
      }

      final bytes = Uint8List.fromList(utf8.encode(content));
      await FileSaver.instance.saveFile(
        name: '${base}_$ts',
        bytes: bytes,
        ext: 'doc',
        mimeType: MimeType.other,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Saved DOC to Downloads')),
      );
      NotificationService.showSimple(
          'Download complete', 'Saved ${base}_$ts.doc to Downloads');
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('DOC download failed: $e')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final file = File(widget.imagePath);
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.download),
            tooltip: 'Download',
            onPressed: () async {
              showModalBottomSheet(
                context: context,
                builder: (_) => SafeArea(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      ListTile(
                        leading: const Icon(Icons.picture_as_pdf),
                        title: const Text('Download as PDF'),
                        onTap: () async {
                          Navigator.pop(context);
                          await _downloadAsPdf();
                        },
                      ),
                      ListTile(
                        leading: const Icon(Icons.description),
                        title: const Text('Download as DOC'),
                        onTap: () async {
                          Navigator.pop(context);
                          await _downloadAsDoc();
                        },
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        ],
      ),
      backgroundColor: Colors.black,
      body: file.existsSync()
          ? InteractiveViewer(
              minScale: 1.0,
              maxScale: 5.0,
              child: SizedBox.expand(
                child: Image.file(
                  file,
                  fit: BoxFit.cover,
                ),
              ),
            )
          : const Center(
              child: Icon(Icons.broken_image, color: Colors.white70, size: 80),
            ),
    );
  }
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Document Viewer',
      theme: ThemeData(
        colorSchemeSeed: const Color(0xFF6868AC),
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
        setState(() {
          _ocrContent = content;
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
      backgroundColor: Colors.black,
      body: SafeArea(
        child: Center(
          child: InteractiveViewer(
            child: File(widget.imagePath).existsSync()
                ? Image.file(
                    File(widget.imagePath),
                    fit: BoxFit.contain,
                    width: double.infinity,
                    height: double.infinity,
                  )
                : Container(
                    color: Colors.grey.shade900,
                    child: const Center(
                      child: Icon(
                        Icons.image_not_supported,
                        size: 64,
                        color: Colors.white70,
                      ),
                    ),
                  ),
          ),
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
  final String _activeFilter = 'all'; // all, image, pdf, text
  bool _selectMode = false;
  final Set<String> _selectedTimestamps = {};

  // Check if document is encrypted (payroll document)
  bool _isDocumentEncrypted(Map<String, dynamic> item) {
    try {
      final fileName = (item['fileName'] ?? '').toString().toLowerCase();
      final ocrContent = (item['ocrContent'] ?? '').toString().toLowerCase();

      // Check for encryption metadata
      if (fileName.contains('encrypted') || ocrContent.contains('encrypted')) {
        return true;
      }

      // Check for payroll keywords
      final encryptionService = EncryptionService.instance;
      return encryptionService.isPayrollDocument(fileName, ocrContent);
    } catch (e) {
      return false;
    }
  }

  // Show encrypted document warning dialog
  void _showEncryptedDocumentDialog() {
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
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
                'For security reasons, encrypted payroll documents cannot be opened directly from the gallery.',
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

  List<Map<String, dynamic>> _currentFilteredItems() {
    final q = _searchQuery.trim().toLowerCase();
    Iterable<Map<String, dynamic>> list = _archivedItems;
    if (_activeFilter != 'all') {
      list = list.where((e) => (e['type']?.toString() ?? '') == _activeFilter);
    }
    if (q.isNotEmpty) {
      list = list.where(
          (e) => (e['fileName']?.toString() ?? '').toLowerCase().contains(q));
    }
    return List<Map<String, dynamic>>.from(list);
  }

  @override
  void initState() {
    super.initState();
    _loadArchivedItems();
  }

  Future<String> _deriveServerRoot() async {
    try {
      return await ServerService.getServerRoot();
    } catch (_) {
      return ServerService.defaultServerRoot;
    }
  }

  Future<void> _showUploadToTrackingModal(Map<String, dynamic> item) async {
    // Simple full-width bottom sheet with just title and two buttons.
    // ignore: use_build_context_synchronously
    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) {
        return SafeArea(
          child: Padding(
            padding: EdgeInsets.only(
              top: 16,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 16,
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Row(
                    children: [
                      Icon(Icons.cloud_upload, color: Color(0xFF6868AC)),
                      SizedBox(width: 8),
                      Text(
                        'Upload to Admin Tracking?',
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.pop(ctx),
                          child: const Text('Cancel'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: ElevatedButton(
                          onPressed: () async {
                            Navigator.pop(ctx);
                            HapticFeedback.lightImpact();

                            // Upload only to admin web (sync_document.php)
                            final prefs = await SharedPreferences.getInstance();
                            final userName =
                                prefs.getString('user_name') ?? 'User';
                            final userEmail =
                                prefs.getString('user_email') ?? '';
                            final userDept =
                                prefs.getString('user_department') ?? 'General';

                            await _sendDocumentToAdmin(
                              item: item,
                              userName: userName,
                              userEmail: userEmail,
                              userDepartment: userDept,
                              nextDepartment: null,
                              endLocation: null,
                            );
                          },
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF6868AC),
                          ),
                          child: const Text('Upload to Tracking'),
                        ),
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

  // Ask for storage permission (Android)
  Future<bool> _ensureStoragePermission() async {
    try {
      final storage = await Permission.storage.request();
      if (storage.isGranted) return true;
      final manage = await Permission.manageExternalStorage.request();
      return manage.isGranted;
    } catch (_) {
      return false;
    }
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

              // Derive a human-friendly name from OCR metadata if available
              String displayName = 'DOC_$timestamp';
              final String? ocrPath = group['text'];
              if (ocrPath != null) {
                try {
                  final lines = await File(ocrPath).readAsLines();
                  String? docType;
                  String? docName;
                  for (final l in lines) {
                    final line = l.trim();
                    if (line.toLowerCase().startsWith('document type:')) {
                      docType = line.split(':').last.trim();
                    } else if (line
                        .toLowerCase()
                        .startsWith('document name:')) {
                      docName = line.split(':').last.trim();
                    }
                    if (docType != null && docName != null) break;
                  }
                  // Prefer the user's selected type; fallback to explicit name
                  if (docType != null && docType.isNotEmpty) {
                    displayName = docType;
                  } else if (docName != null && docName.isNotEmpty) {
                    displayName = docName;
                  }
                } catch (_) {}
              }

              items.add({
                'timestamp': timestamp,
                'imagePath': group['image'],
                'textPath': group['text'],
                'pdfPath': group['pdf'],
                'fileName': displayName,
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
  }) async {
    try {
      // Read OCR content if available
      String ocrContent = '';
      String documentType = 'Scanned Document';
      bool isPayrollDocument = false;
      Uint8List? encryptedFileData;
      String? originalFileName;

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

      // Check if this is a payroll document and handle encryption
      final encryptionService = EncryptionService.instance;
      isPayrollDocument =
          encryptionService.isPayrollDocument(item['fileName'], ocrContent);

      if (isPayrollDocument) {
        originalFileName = item['fileName'];

        // Encrypt the primary file (image or PDF)
        String? filePath = item['imagePath'] ?? item['pdfPath'];
        if (filePath != null) {
          final file = File(filePath);
          if (await file.exists()) {
            final fileBytes = await file.readAsBytes();
            encryptedFileData =
                await encryptionService.encryptPayrollDocument(fileBytes);

            // Update OCR content to indicate encryption
            ocrContent =
                encryptionService.addEncryptionMetadata(ocrContent, true);
            documentType = 'Encrypted Payroll Document';

            debugPrint('🔒 Payroll document encrypted: ${item['fileName']}');
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
        // Use the standard MOBILE_ prefix so sync_document.php treats this
        // as a normal tracking entry instead of the special gallery-only
        // archive path (which bypasses the tracking table).
        'mobile_timestamp':
            'MOBILE_${item['timestamp']}_${DateTime.now().millisecondsSinceEpoch}_${DateTime.now().microsecond}',
        'file_size': item['size'].toString(),
        'user_email': userEmail,
        // Add encryption metadata
        'is_encrypted': isPayrollDocument,
        'original_filename': originalFileName,
        'encryption_method': isPayrollDocument ? 'AES-256-CBC' : null,
      };

      // If encrypted, include the encrypted file data
      if (encryptedFileData != null) {
        documentData['encrypted_file_data'] = base64.encode(encryptedFileData);
      }

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
          if (isPayrollDocument) {
            debugPrint(
                '🔒 Encrypted payroll document synced successfully: ${item['fileName']}');
          } else {
            debugPrint('✅ Document synced successfully: ${item['fileName']}');
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

  // (Old modal removed; new targeted routing modal is defined above.)

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

  Map<String, dynamic>? _safeDecodeMap(String raw) {
    final trimmed = raw.trim();
    if (trimmed.isEmpty) return null;
    try {
      final decoded = jsonDecode(trimmed);
      if (decoded is Map<String, dynamic>) {
        return decoded;
      }
    } catch (_) {
      final start = trimmed.indexOf('{');
      final end = trimmed.lastIndexOf('}');
      if (start != -1 && end != -1 && end > start) {
        final slice = trimmed.substring(start, end + 1);
        try {
          final decoded = jsonDecode(slice);
          if (decoded is Map<String, dynamic>) {
            return decoded;
          }
        } catch (_) {}
      }
    }
    return null;
  }

  static const Color _themeColor = Color(0xFF6868AC);

  @override
  Widget build(BuildContext context) {
    final filteredCount = _currentFilteredItems().length;
    return Scaffold(
      appBar: AppBar(
        title: _selectMode
            ? Text('${_selectedTimestamps.length} selected')
            : const Text('My Documents',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 22)),
        backgroundColor: _themeColor,
        foregroundColor: Colors.white,
        elevation: 0,
        toolbarHeight: _selectMode ? 50 : 56,
        bottom: _selectMode
            ? null
            : PreferredSize(
                preferredSize: const Size.fromHeight(60),
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(14, 0, 14, 10),
                  child: Container(
                    decoration: BoxDecoration(
                      color: Colors.white.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: TextField(
                      onChanged: (value) {
                        setState(() {
                          _searchQuery = value;
                        });
                      },
                      style: const TextStyle(color: Colors.white),
                      decoration: InputDecoration(
                        hintText: 'Search archived documents...',
                        hintStyle: TextStyle(
                          color: Colors.white.withOpacity(0.6),
                        ),
                        prefixIcon: Icon(Icons.search,
                            color: Colors.white.withOpacity(0.6)),
                        border: InputBorder.none,
                        contentPadding: const EdgeInsets.symmetric(
                            horizontal: 14, vertical: 12),
                      ),
                    ),
                  ),
                ),
              ),
        actions: [
          if (_selectMode) ...[
            IconButton(
              tooltip:
                  _selectedTimestamps.length == _currentFilteredItems().length
                      ? 'Clear Selection'
                      : 'Select All',
              icon: Icon(
                _selectedTimestamps.length == _currentFilteredItems().length
                    ? Icons.deselect
                    : Icons.select_all,
              ),
              onPressed: () {
                setState(() {
                  if (_selectedTimestamps.length ==
                      _currentFilteredItems().length) {
                    _selectedTimestamps.clear();
                    _selectMode = false;
                  } else {
                    final now = _currentFilteredItems();
                    _selectedTimestamps
                      ..clear()
                      ..addAll(now.map((e) => e['timestamp'] as String));
                    _selectMode = true;
                  }
                });
                HapticFeedback.selectionClick();
              },
            ),
            IconButton(
              tooltip: 'Delete Selected',
              icon: const Icon(Icons.delete_outline),
              onPressed: _deleteSelected,
            ),
          ],
        ],
      ),
      body: Column(
        children: [
          // Gallery Content with pull-to-refresh
          Expanded(
            child: RefreshIndicator(
              onRefresh: () async {
                await _loadArchivedItems();
              },
              child: _isLoading ? _buildShimmerList() : _buildGalleryList(),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildShimmerList() {
    return ListView.separated(
      padding: const EdgeInsets.all(12),
      itemCount: 8,
      separatorBuilder: (_, __) => const SizedBox(height: 12),
      itemBuilder: (ctx, i) {
        return _shimmerTile();
      },
    );
  }

  // Simple skeleton tile without external dependencies
  Widget _shimmerTile() {
    final base =
        Theme.of(context).colorScheme.surfaceContainerHighest.withOpacity(0.4);
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
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
              width: 56,
              height: 56,
              decoration: BoxDecoration(
                  color: base, borderRadius: BorderRadius.circular(14))),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                    height: 14,
                    width: 140,
                    decoration: BoxDecoration(
                        color: base, borderRadius: BorderRadius.circular(6))),
                const SizedBox(height: 10),
                Container(
                    height: 12,
                    width: double.infinity,
                    decoration: BoxDecoration(
                        color: base, borderRadius: BorderRadius.circular(6))),
                const SizedBox(height: 8),
                Container(
                    height: 12,
                    width: 90,
                    decoration: BoxDecoration(
                        color: base, borderRadius: BorderRadius.circular(6))),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildGalleryList() {
    if (_filteredItems.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 80),
          Center(
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
                    _searchQuery.isEmpty
                        ? Icons.folder_open_rounded
                        : Icons.search_off_rounded,
                    size: 48,
                    color: _themeColor.withOpacity(0.5),
                  ),
                ),
                const SizedBox(height: 20),
                Text(
                  _searchQuery.isEmpty
                      ? 'No archived documents'
                      : 'No results found',
                  style: TextStyle(
                    fontSize: 18,
                    color: Colors.grey.shade700,
                    fontWeight: FontWeight.w600,
                  ),
                ),
                const SizedBox(height: 8),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 48),
                  child: Text(
                    _searchQuery.isEmpty
                        ? 'Scanned documents will appear here.\nPull down to refresh.'
                        : 'Try a different search term',
                    style: TextStyle(
                      color: Colors.grey.shade500,
                      fontSize: 14,
                      height: 1.4,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 200),
        ],
      );
    }

    final items = _currentFilteredItems();
    return ListView.separated(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 14),
      itemCount: items.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, index) {
        final item = items[index];
        final ts = item['timestamp'] as String;
        final selected = _selectedTimestamps.contains(ts);
        final hasImage =
            item['imagePath'] != null && File(item['imagePath']).existsSync();
        final hasPdf = item['pdfPath'] != null;
        final hasText = item['textPath'] != null;

        // Determine file type color & icon
        Color typeColor;
        IconData typeIcon;
        String typeLabel;
        if (hasPdf) {
          typeColor = Colors.red.shade600;
          typeIcon = Icons.picture_as_pdf_rounded;
          typeLabel = 'PDF';
        } else if (hasText) {
          typeColor = const Color(0xFF6868AC);
          typeIcon = Icons.description_rounded;
          typeLabel = 'TXT';
        } else if (hasImage) {
          typeColor = Colors.orange.shade600;
          typeIcon = Icons.image_rounded;
          typeLabel = 'IMG';
        } else {
          typeColor = Colors.grey.shade600;
          typeIcon = Icons.insert_drive_file_rounded;
          typeLabel = 'FILE';
        }

        return Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onLongPress: () => _toggleSelect(ts),
            onTap: () async {
              if (_selectMode) {
                _toggleSelect(ts);
                return;
              }

              // Check if document is encrypted before opening
              if (_isDocumentEncrypted(item)) {
                _showEncryptedDocumentDialog();
                return;
              }

              if (item['imagePath'] != null && item['textPath'] != null) {
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
                final p =
                    item['pdfPath'] ?? item['imagePath'] ?? item['textPath'];
                if (p != null) {
                  Share.shareXFiles([XFile(p)], text: item['fileName']);
                }
              }
            },
            child: AnimatedScale(
              scale: selected ? 0.97 : 1.0,
              duration: const Duration(milliseconds: 160),
              curve: Curves.easeOutCubic,
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 180),
                curve: Curves.easeOutCubic,
                padding:
                    const EdgeInsets.symmetric(vertical: 12, horizontal: 14),
                decoration: BoxDecoration(
                  color: selected
                      ? _themeColor.withOpacity(0.06)
                      : Theme.of(context).cardColor,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(
                    color: selected
                        ? _themeColor.withOpacity(0.35)
                        : Colors.grey.shade200,
                    width: selected ? 1.5 : 1,
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withOpacity(selected ? 0.06 : 0.03),
                      blurRadius: selected ? 10 : 6,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.center,
                  children: [
                    Container(
                      width: 56,
                      height: 56,
                      decoration: BoxDecoration(
                        color: typeColor.withOpacity(0.08),
                        borderRadius: BorderRadius.circular(14),
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: hasImage
                          ? Image.file(File(item['imagePath']),
                              fit: BoxFit.cover)
                          : Center(
                              child: Icon(typeIcon, color: typeColor, size: 28),
                            ),
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  item['fileName'],
                                  style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.w600,
                                    color:
                                        Theme.of(context).colorScheme.onSurface,
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              if (_isDocumentEncrypted(item)) ...[
                                const SizedBox(width: 8),
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                      horizontal: 6, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: Colors.green.shade600,
                                    borderRadius: BorderRadius.circular(10),
                                  ),
                                  child: const Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Icon(Icons.lock,
                                          size: 10, color: Colors.white),
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
                          const SizedBox(height: 6),
                          Row(
                            children: [
                              // Type badge
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 6, vertical: 1),
                                decoration: BoxDecoration(
                                  color: typeColor.withOpacity(0.1),
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: Text(
                                  typeLabel,
                                  style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.w700,
                                    color: typeColor,
                                  ),
                                ),
                              ),
                              const SizedBox(width: 4),
                              const Icon(Icons.notes_rounded,
                                  size: 14, color: Color(0xFF6868AC)),
                              if (hasImage) ...[
                                const SizedBox(width: 4),
                                Icon(Icons.image_rounded,
                                    size: 14, color: Colors.orange.shade500),
                              ],
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: 6),
                    // Action buttons
                    AnimatedSwitcher(
                      duration: const Duration(milliseconds: 180),
                      switchInCurve: Curves.easeOutCubic,
                      switchOutCurve: Curves.easeInCubic,
                      child: Row(
                        key: ValueKey(_selectMode),
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          if (_selectMode)
                            Transform.scale(
                              scale: 0.9,
                              child: Checkbox(
                                value: selected,
                                activeColor: _themeColor,
                                onChanged: (_) => _toggleSelect(ts),
                              ),
                            ),
                          if (_selectMode) ...[
                            _buildItemAction(
                              icon: Icons.edit_outlined,
                              color: const Color(0xFF6868AC),
                              tooltip: 'Rename',
                              onPressed: () => _showRenameDialog(item),
                            ),
                            _buildItemAction(
                              icon: Icons.delete_outline,
                              color: Colors.red.shade600,
                              tooltip: 'Delete',
                              onPressed: () => _showDeleteDialog(item),
                            ),
                          ],
                          if (!_selectMode)
                            _buildItemAction(
                              icon: Icons.download_rounded,
                              color: Colors.green.shade600,
                              tooltip: 'Download',
                              onPressed: () => _downloadItem(item),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildItemAction({
    required IconData icon,
    required Color color,
    required String tooltip,
    required VoidCallback onPressed,
  }) {
    return IconButton(
      tooltip: tooltip,
      padding: EdgeInsets.zero,
      constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
      icon: Icon(icon, color: color, size: 24),
      onPressed: onPressed,
    );
  }

  // Download individual item
  Future<void> _downloadItem(Map<String, dynamic> item) async {
    try {
      // Ensure storage permission on Android so we can write to /Download
      if (Platform.isAndroid) {
        final granted = await _ensureStoragePermission();
        if (!granted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
                content: Text('Storage permission required to save files')),
          );
          return;
        }
      }
      // Show enhanced downloading progress modal for single item
      _showDownloadProgressModal(1, isIndividual: true);

      final Directory? downloadsDir = await _getDownloadsDirectory();
      if (downloadsDir == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not access Downloads folder')),
        );
        return;
      }

      await _downloadItemFiles(item, downloadsDir);
      // Success/failure is now handled by the modal dialogs
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
        final dir = Directory('/storage/emulated/0/Download');
        if (!(await dir.exists())) {
          await dir.create(recursive: true);
        }
        return dir;
      } else {
        // For other platforms, use app documents directory
        final d = await getApplicationDocumentsDirectory();
        if (!(await d.exists())) {
          await d.create(recursive: true);
        }
        return d;
      }
    } catch (e) {
      final d = await getApplicationDocumentsDirectory();
      if (!(await d.exists())) {
        await d.create(recursive: true);
      }
      return d;
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

      // 1) Ensure a PDF exists that contains both image and OCR text
      // If pdfPath is null or file is missing but we have image/text, generate it now
      if ((item['pdfPath'] == null ||
              !(await File(item['pdfPath']).exists())) &&
          item['imagePath'] != null) {
        final generated = await _generatePdfForItem(
          imagePath: item['imagePath'],
          textPath: item['textPath'],
          outDir: downloadsDir,
          baseName: baseName,
        );
        if (generated != null) {
          // Save path back to item map in memory for immediate reuse
          item['pdfPath'] = generated.path;
        }
      }

      // 2) Download image if exists
      if (item['imagePath'] != null) {
        final imageFile = File(item['imagePath']);
        if (await imageFile.exists()) {
          final downloadPath =
              path.join(downloadsDir.path, '${baseName}_Image.jpg');
          await imageFile.copy(downloadPath);
          anyDownloaded = true;
        }
      }

      // 3) Download text if exists
      if (item['textPath'] != null) {
        final textFile = File(item['textPath']);
        if (await textFile.exists()) {
          final downloadPath =
              path.join(downloadsDir.path, '${baseName}_Text.txt');
          await textFile.copy(downloadPath);
          anyDownloaded = true;
        }
      }

      // 4) Download PDF (generated or existing)
      if (item['pdfPath'] != null) {
        final pdfFile = File(item['pdfPath']);
        if (await pdfFile.exists()) {
          final downloadPath =
              path.join(downloadsDir.path, '${baseName}_PDF.pdf');
          if (pdfFile.path != downloadPath) {
            await pdfFile.copy(downloadPath);
          }
          anyDownloaded = true;
        }
      }

      return anyDownloaded;
    } catch (e) {
      return false;
    }
  }

  // Create a PDF that includes the photo and OCR text (if available)
  Future<File?> _generatePdfForItem({
    required String imagePath,
    String? textPath,
    required Directory outDir,
    required String baseName,
  }) async {
    try {
      final imgFile = File(imagePath);
      if (!await imgFile.exists()) return null;
      final bytes = await imgFile.readAsBytes();
      final image = pw.MemoryImage(bytes);

      String ocrText = '';
      if (textPath != null) {
        final t = File(textPath);
        if (await t.exists()) {
          ocrText = await t.readAsString();
        }
      }

      final pdf = pw.Document();
      pdf.addPage(
        pw.Page(
          pageFormat: PdfPageFormat.a4,
          build: (context) => pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.stretch,
            children: [
              pw.Expanded(
                flex: ocrText.isNotEmpty ? 3 : 4,
                child: pw.Container(
                  decoration: pw.BoxDecoration(
                    border: pw.Border.all(color: PdfColor.fromHex('#D1D5DB')),
                  ),
                  child: pw.Image(image, fit: pw.BoxFit.contain),
                ),
              ),
              if (ocrText.isNotEmpty) ...[
                pw.SizedBox(height: 12),
                pw.Expanded(
                  flex: 2,
                  child: pw.Container(
                    padding: const pw.EdgeInsets.all(12),
                    decoration: pw.BoxDecoration(
                      border: pw.Border.all(color: PdfColor.fromHex('#E5E7EB')),
                    ),
                    child: pw.Text(
                      _normalizeOcrText(ocrText),
                      textAlign: pw.TextAlign.justify,
                      style: const pw.TextStyle(fontSize: 11),
                    ),
                  ),
                ),
              ],
            ],
          ),
        ),
      );

      final outPath = path.join(outDir.path, '${baseName}_PDF.pdf');
      final outFile = File(outPath);
      await outFile.writeAsBytes(await pdf.save());
      return outFile;
    } catch (_) {
      return null;
    }
  }

  // Merge broken lines into paragraphs for better readability in PDF
  String _normalizeOcrText(String input) {
    try {
      final lines = input.split(RegExp(r"\r?\n"));
      final List<String> paragraphs = [];
      final StringBuffer buf = StringBuffer();
      for (final line in lines) {
        final trimmed = line.trim();
        if (trimmed.isEmpty) {
          if (buf.isNotEmpty) {
            paragraphs.add(buf.toString().trim());
            buf.clear();
          }
        } else {
          if (buf.isNotEmpty) buf.write(' ');
          buf.write(trimmed);
        }
      }
      if (buf.isNotEmpty) paragraphs.add(buf.toString().trim());
      return paragraphs.join('\n\n');
    } catch (_) {
      return input;
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
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
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
                    color: const Color(0xFF6868AC).withOpacity(0.08),
                    borderRadius: BorderRadius.circular(30),
                  ),
                  child: const Icon(
                    Icons.cloud_download,
                    size: 30,
                    color: Color(0xFF6868AC),
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  'Downloading...',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: Colors.grey.shade800,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'Please wait',
                  style: TextStyle(
                    fontSize: 14,
                    color: Colors.grey.shade600,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 16),
                // Progress indicator
                const LinearProgressIndicator(
                  backgroundColor: Colors.grey,
                  valueColor: AlwaysStoppedAnimation<Color>(Color(0xFF6868AC)),
                ),
              ],
            ),
          ),
        );
      },
    );

    // Wait a brief moment for file IO to complete (UI polish only)
    await Future.delayed(const Duration(milliseconds: 400));

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
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
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
                      borderRadius: BorderRadius.circular(12),
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

    // Also notify the user via a local notification
    NotificationService.showSimple(
      'Download complete',
      'Saved $fileCount ${fileCount == 1 ? 'file' : 'files'} to Downloads',
    );
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
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
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
                    borderRadius: BorderRadius.circular(12),
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
                backgroundColor: const Color(0xFF6868AC),
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
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
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
