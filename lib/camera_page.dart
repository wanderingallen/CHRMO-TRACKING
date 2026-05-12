// ignore_for_file: unused_field, unused_element
import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:camera/camera.dart';
import 'package:crypto/crypto.dart';
import 'package:cunning_document_scanner/cunning_document_scanner.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:http/http.dart' as http;
import 'package:image/image.dart' as img;
import 'package:image_picker/image_picker.dart';
// ignore: depend_on_referenced_packages
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'dashboard_page.dart';
import 'encryption_service.dart';
import 'gallery_page.dart';
import 'notification_service.dart';
import 'pdf_preview_page.dart';
import 'services/batch_document_processor.dart';
import 'services/chrmo_document_classifier.dart';
import 'services/ocr_text_processor.dart';
import 'services/routing_service.dart';
import 'services/server_service.dart';

// Helper class to store text elements with their spatial positioning
class _TextElementWithPosition {
  final String text;
  final Rect boundingBox;
  final double confidence;
  final int lineIndex;
  final int blockIndex;

  _TextElementWithPosition({
    required this.text,
    required this.boundingBox,
    required this.confidence,
    required this.lineIndex,
    required this.blockIndex,
  });
}

Future<List<String>> _fetchDepartmentsForMobile() async {
  try {
    final prefs = await SharedPreferences.getInstance();
    String normalizeRoot(String value) {
      var out = value.trim();
      out = out.replaceFirst(RegExp(r"/api/?$", caseSensitive: false), '');
      out = out.replaceFirst(
          RegExp(r"/lib/OCR\(UPDATED\)/api/?$", caseSensitive: false), '');
      return out;
    }

    final candidateRoots = <String>{
      normalizeRoot((prefs.getString('server_root') ?? '').trim()),
      normalizeRoot((prefs.getString('detected_server_url') ?? '').trim()),
      normalizeRoot(await ServerService.getServerUrl()),
      normalizeRoot(ServerService.defaultServerRoot),
    }..removeWhere((e) => e.isEmpty);

    List<String> extractDepartments(dynamic payload) {
      if (payload is! Map || payload['success'] != true) return const [];
      final raw = (payload['departments'] as List?) ?? const [];
      final out = <String>[];
      for (final item in raw) {
        if (item is String) {
          final value = item.trim();
          if (value.isNotEmpty) out.add(value);
          continue;
        }
        if (item is Map) {
          final value =
              (item['name'] ?? item['department'] ?? item['dept_name'] ?? '')
                  .toString()
                  .trim();
          if (value.isNotEmpty) out.add(value);
        }
      }
      return out;
    }

    for (final root in candidateRoots) {
      final urls = <String>[
        '$root/lib/OCR(UPDATED)/api/list_departments.php',
        '$root/api/list_departments.php',
        '$root/lib/OCR(UPDATED)/usercontrol.php?action=list_departments',
      ];

      for (final url in urls) {
        try {
          final r = await http
              .get(Uri.parse(url))
              .timeout(const Duration(seconds: 8));
          if (r.statusCode != 200 || r.body.isEmpty) continue;

          final decoded = json.decode(r.body);
          final list = extractDepartments(decoded)
              .map((e) => e.toUpperCase())
              .toSet()
              .toList()
            ..sort();

          if (list.isNotEmpty) return list;
        } catch (_) {
          continue;
        }
      }
    }

    return [];
  } catch (_) {
    return [];
  }
}

// Document processing modes
enum DocumentMode {
  single,
  batch,
}

enum ProcessingStage {
  capture,
  crop,
  filter,
  recognize,
  export,
}

enum DocumentFilter {
  original,
  grayscale,
  blackWhite,
  enhance,
}

// Document data structure
class DocumentData {
  final String id;
  final String imagePath;
  final String? croppedPath;
  final String? filteredPath;
  final String recognizedText;
  final List<String> pageTexts; // Per-page OCR text (page 1..n)
  final DateTime captureTime;
  final DocumentFilter appliedFilter;
  final double confidence;
  final Map<String, dynamic> keyInformation;
  final String mobileTimestamp;
  final String docHash;
  final List<String>
      additionalPages; // Additional pages for multi-page documents

  DocumentData({
    required this.id,
    required this.imagePath,
    this.croppedPath,
    this.filteredPath,
    required this.recognizedText,
    this.pageTexts = const [],
    required this.captureTime,
    required this.mobileTimestamp,
    required this.docHash,
    this.appliedFilter = DocumentFilter.original,
    this.confidence = 0.0,
    this.keyInformation = const {},
    this.additionalPages = const [],
  });

  /// Returns all page paths (primary + additional)
  List<String> get allPagePaths {
    final paths = <String>[imagePath];
    paths.addAll(additionalPages);
    return paths;
  }

  int get pageCount => 1 + additionalPages.length;

  Map<String, dynamic> toJson() => {
        'id': id,
        'imagePath': imagePath,
        'croppedPath': croppedPath,
        'filteredPath': filteredPath,
        'recognizedText': recognizedText,
        'pageTexts': pageTexts,
        'captureTime': captureTime.toIso8601String(),
        'appliedFilter': appliedFilter.toString(),
        'confidence': confidence,
        'keyInformation': keyInformation,
        'mobileTimestamp': mobileTimestamp,
        'docHash': docHash,
        'additionalPages': additionalPages,
      };

  factory DocumentData.fromJson(Map<String, dynamic> json) => DocumentData(
        id: json['id'],
        imagePath: json['imagePath'],
        croppedPath: json['croppedPath'],
        filteredPath: json['filteredPath'],
        recognizedText: json['recognizedText'],
        pageTexts: List<String>.from(json['pageTexts'] ?? const []),
        captureTime: DateTime.parse(json['captureTime']),
        appliedFilter: DocumentFilter.values.firstWhere(
          (e) => e.toString() == json['appliedFilter'],
          orElse: () => DocumentFilter.original,
        ),
        confidence: json['confidence'] ?? 0.0,
        keyInformation: json['keyInformation'] ?? {},
        mobileTimestamp: json['mobileTimestamp'] ?? '',
        docHash: json['docHash'] ?? '',
        additionalPages: List<String>.from(json['additionalPages'] ?? []),
      );

  DocumentData copyWith({
    String? imagePath,
    String? croppedPath,
    String? filteredPath,
    String? recognizedText,
    List<String>? pageTexts,
    DateTime? captureTime,
    DocumentFilter? appliedFilter,
    double? confidence,
    Map<String, dynamic>? keyInformation,
    String? mobileTimestamp,
    String? docHash,
    List<String>? additionalPages,
  }) {
    return DocumentData(
      id: id,
      imagePath: imagePath ?? this.imagePath,
      croppedPath: croppedPath ?? this.croppedPath,
      filteredPath: filteredPath ?? this.filteredPath,
      recognizedText: recognizedText ?? this.recognizedText,
      pageTexts: pageTexts ?? this.pageTexts,
      captureTime: captureTime ?? this.captureTime,
      appliedFilter: appliedFilter ?? this.appliedFilter,
      confidence: confidence ?? this.confidence,
      keyInformation: keyInformation ?? this.keyInformation,
      mobileTimestamp: mobileTimestamp ?? this.mobileTimestamp,
      docHash: docHash ?? this.docHash,
      additionalPages: additionalPages ?? this.additionalPages,
    );
  }
}

// Runs in an isolate via `compute`.
Uint8List _applyDocumentFilterIsolate(Map<String, dynamic> args) {
  final bytes = args['bytes'] as Uint8List;
  final filterName = (args['filter'] as String).toLowerCase();

  final decodedRaw = img.decodeImage(bytes);
  if (decodedRaw == null) return bytes;
  img.Image image = img.bakeOrientation(decodedRaw);

  // Always work in RGB8 for predictable pixel ops.
  image = img.copyResize(image,
      width: image.width,
      height: image.height,
      interpolation: img.Interpolation.linear);

  switch (filterName) {
    case 'grayscale':
      image = img.grayscale(image);
      break;
    case 'blackwhite':
    case 'black_white':
    case 'blackwhitefilter':
    case 'blackwhite ': // defensive
      {
        image = img.grayscale(image);

        // Sauvola-inspired local adaptive thresholding for uneven lighting.
        // Uses integral image for O(1) per-pixel local mean computation.
        final w = image.width;
        final h = image.height;
        final pixels = List<int>.generate(w * h, (i) {
          final y = i ~/ w;
          final x = i % w;
          return image.getPixel(x, y).r.toInt();
        });

        // Build integral image (sum table)
        final integral = List<int>.filled(w * h, 0);
        for (int y = 0; y < h; y++) {
          int rowSum = 0;
          for (int x = 0; x < w; x++) {
            rowSum += pixels[y * w + x];
            integral[y * w + x] =
                rowSum + (y > 0 ? integral[(y - 1) * w + x] : 0);
          }
        }

        // Window radius scales with image size (approx 1/16th of smaller dim)
        final winR = (w < h ? w : h) ~/ 32;
        final winSize = winR < 4 ? 8 : winR * 2;
        const double k = 0.08; // Sauvola sensitivity
        const double R = 128.0; // dynamic range of std dev

        for (int y = 0; y < h; y++) {
          for (int x = 0; x < w; x++) {
            final x1 = (x - winSize).clamp(0, w - 1);
            final y1 = (y - winSize).clamp(0, h - 1);
            final x2 = (x + winSize).clamp(0, w - 1);
            final y2 = (y + winSize).clamp(0, h - 1);
            final count = (x2 - x1 + 1) * (y2 - y1 + 1);
            final sum = integral[y2 * w + x2] -
                (x1 > 0 ? integral[y2 * w + (x1 - 1)] : 0) -
                (y1 > 0 ? integral[(y1 - 1) * w + x2] : 0) +
                (x1 > 0 && y1 > 0 ? integral[(y1 - 1) * w + (x1 - 1)] : 0);
            final mean = sum / count;
            // Approximate local threshold using Sauvola formula
            final threshold =
                mean * (1.0 + k * ((pixels[y * w + x] - mean).abs() / R - 1.0));
            final out = pixels[y * w + x] >= threshold ? 255 : 0;
            image.getPixel(x, y)
              ..r = out
              ..g = out
              ..b = out;
          }
        }
      }
      break;
    case 'enhance':
      {
        image = img.grayscale(image);

        // 1. Contrast stretch (clip 1% tails for robustness)
        final hist = List<int>.filled(256, 0);
        final totalPx = image.width * image.height;
        for (final p in image) {
          hist[p.r.toInt().clamp(0, 255)]++;
        }
        int cumLow = 0;
        int lowClip = 0;
        final clipCount = (totalPx * 0.01).round();
        for (int i = 0; i < 256; i++) {
          cumLow += hist[i];
          if (cumLow >= clipCount) {
            lowClip = i;
            break;
          }
        }
        int cumHigh = 0;
        int highClip = 255;
        for (int i = 255; i >= 0; i--) {
          cumHigh += hist[i];
          if (cumHigh >= clipCount) {
            highClip = i;
            break;
          }
        }
        final range = (highClip - lowClip).clamp(1, 255);
        for (final p in image) {
          final v = p.r.toInt();
          final stretched =
              (((v - lowClip) * 255) / range).round().clamp(0, 255);
          p
            ..r = stretched
            ..g = stretched
            ..b = stretched;
        }

        // 2. Unsharp mask sharpening for crisper text edges
        final blurred = img.gaussianBlur(image, radius: 1);
        const double sharpenAmount = 1.5;
        for (int y = 0; y < image.height; y++) {
          for (int x = 0; x < image.width; x++) {
            final orig = image.getPixel(x, y).r.toInt();
            final blur = blurred.getPixel(x, y).r.toInt();
            final sharpened =
                (orig + sharpenAmount * (orig - blur)).round().clamp(0, 255);
            image.getPixel(x, y)
              ..r = sharpened
              ..g = sharpened
              ..b = sharpened;
          }
        }
      }
      break;
    default:
      // original
      break;
  }

  return Uint8List.fromList(img.encodeJpg(image, quality: 98));
}

String _generateMobileTimestamp() {
  final now = DateTime.now();
  return 'MOBILE_${now.millisecondsSinceEpoch}_${now.microsecondsSinceEpoch % 1000}';
}

String _generateDocHash(String seed) {
  return sha256.convert(utf8.encode(seed)).toString();
}

DocumentData _createDocumentData(String imagePath,
    {List<String>? additionalPages}) {
  final ts = _generateMobileTimestamp();
  final hash = _generateDocHash('$imagePath|$ts');
  return DocumentData(
    id: ts,
    imagePath: imagePath,
    recognizedText: '',
    captureTime: DateTime.now(),
    mobileTimestamp: ts,
    docHash: hash,
    additionalPages: additionalPages ?? [],
  );
}

class CameraPage extends StatefulWidget {
  final bool autoScan;
  final String? galleryImagePath;
  final String? routingMobileTimestamp;
  final String? routingTrackingId;
  const CameraPage({
    super.key,
    this.autoScan = false,
    this.galleryImagePath,
    this.routingMobileTimestamp,
    this.routingTrackingId,
  });

  @override
  State<CameraPage> createState() => _CameraPageState();
}

class _CameraPageState extends State<CameraPage> with TickerProviderStateMixin {
  late CameraController _controller;
  late Future<void> _initializeControllerFuture;
  bool _isFlashOn = false;
  bool _isBackCamera = true;
  List<CameraDescription>? _cameras;
  String? _lastCapturedImagePath;

  // Document processing state
  DocumentMode _documentMode = DocumentMode.single;
  ProcessingStage _currentStage = ProcessingStage.capture;
  DocumentData? _currentDocument;
  DocumentFilter _selectedFilter = DocumentFilter.original;
  final List<DocumentData> _capturedDocuments = [];

  // OCR and recognition
  String _recognizedText = 'No text recognized yet';
  final List<TextBlock> _textBlocks = [];
  final List<TextLine> _textLines = [];
  final List<TextElement> _textElements = [];
  double _averageConfidence = 0.0;
  final Map<String, dynamic> _keyInformation = {};

  // Google ML Kit enhanced features
  bool _useEnhancedTextRecognition = true;
  final List<String> _documentTypes = [];
  double _documentConfidence = 0.0;

  // AI Document Type Suggestion (CHRMO Classifier)
  CHRMODocumentClassification? _aiClassification;
  final CHRMODocumentClassifier _chrmoClassifier = CHRMODocumentClassifier();
  String? _aiClassificationNote;

  // UI state
  bool _isProcessing = false;
  bool _showResults = false;
  bool _showConfirmation = false;
  bool _showCropInterface = false;
  bool _showFilterInterface = false;
  bool _showTextView = false; // Hidden by default; user expands via OCR Content
  DateTime? _captureTime;
  final ImagePicker _picker = ImagePicker();
  bool _sourceActionInProgress =
      false; // prevents auto-navigation on chooser dismiss

  // Results preview state (multi-page)
  final PageController _resultsPageController = PageController();
  int _resultsPageIndex = 0;
  // Routing args (may be supplied via constructor OR via Navigator arguments)
  String? _routingMobileTimestamp;
  String? _routingTrackingId;
  bool _routeArgsConsumed = false;
  // Animation controllers
  late AnimationController _scanController;
  late AnimationController _processingController;
  late AnimationController _batchController;

  // Animations
  double _scanValue = 0.0;

  // Non-modal saving banner
  bool _autoSaveInProgress = false;
  String _autoSaveMessage = 'Processing...';
  final String _selectedExport = 'word';

  // Batch mode and performance optimization
  int _batchCount = 0;
  bool _isBatchMode = false;

  // Batch document processor for handling 10-15+ documents
  late final BatchDocumentProcessor _batchProcessor;

  // Batch processing state
  int _batchProcessingCurrent = 0;
  int _batchProcessingTotal = 0;
  String _batchProcessingTask = '';
  bool _isBatchProcessing = false;

  // Crop box state (as percentages of the available image area)
  // Defaults to a large centered rectangle
  double _cropLeft = 0.1; // 10% from left
  double _cropTop = 0.1; // 10% from top
  double _cropWidth = 0.8; // 80% width
  double _cropHeight = 0.8; // 80% height
  Size? _lastCropAreaSize; // remembers LayoutBuilder available size for mapping

  void _moveCropBox(Offset delta, Size size) {
    final dx = delta.dx / size.width;
    final dy = delta.dy / size.height;
    setState(() {
      _cropLeft = (_cropLeft + dx).clamp(0.0, 1.0 - _cropWidth);
      _cropTop = (_cropTop + dy).clamp(0.0, 1.0 - _cropHeight);
    });
  }

  // Route a document to a specific user (same behavior as GalleryPage)
  Future<bool> _routeDocumentToUserFromCamera({
    required String fileName,
    required String filePath,
    required String type,
    required String receiverUsername,
    required String receiverDepartment,
    String? fileUrl,
    String? nextDepartment,
    String? endLocation,
    String? fileTypeIcon,
    String? fileSize,
    String? mobileTimestamp,
    String? docHash,
  }) async {
    try {
      final sp = await SharedPreferences.getInstance();
      final senderName = sp.getString('user_name') ?? 'User';
      final senderDept = sp.getString('user_department') ?? 'General';

      String? baseUrl = sp.getString('detected_server_url');
      baseUrl ??= await ServerService.getServerUrl();
      final root = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
      final uri = Uri.parse('$root/lib/OCR(UPDATED)/api/route_document.php');

      final ts =
          mobileTimestamp ?? DateTime.now().millisecondsSinceEpoch.toString();
      final body = <String, String>{
        'base': root,
        'sender_name': senderName,
        'sender_department': senderDept,
        'receiver_username': receiverUsername,
        'receiver_department': receiverDepartment,
        'file_name': fileName,
        'file_path': filePath,
        'mobile_timestamp': ts,
        'type': type,
        'end_location': endLocation ?? '',
        'next_department': nextDepartment ?? '',
        'file_type_icon': fileTypeIcon ?? 'file',
        'file_size': fileSize ?? '',
      };
      if (docHash != null && docHash.trim().isNotEmpty) {
        body['doc_hash'] = docHash.trim();
      }
      if (fileUrl != null && fileUrl.trim().isNotEmpty) {
        body['file_url'] = fileUrl.trim();
      }

      final resp = await http
          .post(
            uri,
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body,
          )
          .timeout(const Duration(seconds: 15));

      final ok = resp.statusCode < 400 &&
          (() {
            try {
              final m = jsonDecode(resp.body);
              return m is Map && m['success'] == true;
            } catch (_) {
              return false;
            }
          }());

      if (mounted) {
        if (ok) {
          HapticFeedback.mediumImpact();
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
                content:
                    Text('Sent to $receiverUsername ($receiverDepartment)')),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
                content:
                    Text('Failed to route (${resp.statusCode}): ${resp.body}')),
          );
        }
      }
      return ok;
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Route error: $e')),
        );
      }
      return false;
    }
  }

  // Build text using OCR blocks and lines, preserving visual line breaks
  String _reconstructLayoutText() {
    try {
      final sb = StringBuffer();
      if (_textLines.isNotEmpty) {
        for (final line in _textLines) {
          sb.writeln(line.toString());
        }
        return sb.toString();
      }
      return _recognizedText;
    } catch (_) {
      return _recognizedText;
    }
  }

  Future<void> _pickFromGalleryAndProceed() async {
    try {
      final XFile? file = await _picker.pickImage(source: ImageSource.gallery);
      if (file == null) {
        // User cancelled gallery; go back to dashboard
        if (mounted) {
          _sourceActionInProgress = false;
          Navigator.of(context).pushAndRemoveUntil(
            MaterialPageRoute(builder: (_) => const DashboardPage()),
            (route) => false,
          );
        }
        return;
      }
      _lastCapturedImagePath = file.path;
      _currentDocument = _createDocumentData(file.path);
      // Skip manual crop and go straight to filter/OCR automatically
      setState(() {
        _currentStage = ProcessingStage.filter;
        _showCropInterface = false;
      });
      // Auto-apply clean filter once gallery image is prepared
      await _autoApplyCleanFilter();
      _sourceActionInProgress = false;
    } catch (e) {
      _sourceActionInProgress = false;
      _showError('Failed to pick image: $e');
    }
  }

  Future<void> _launchCapture() async {
    _sourceActionInProgress = true;
    try {
      final List<String>? pictures = await CunningDocumentScanner.getPictures();
      if (pictures == null || pictures.isEmpty) {
        _sourceActionInProgress = false;
        // User cancelled scanner — go back to dashboard to avoid blank screen
        if (mounted) {
          Navigator.of(context).pushAndRemoveUntil(
            MaterialPageRoute(builder: (_) => const DashboardPage()),
            (route) => false,
          );
        }
        return;
      }
      // Use first image as primary, remaining as additional pages
      final scannedPath = pictures.first;
      final additionalPages =
          pictures.length > 1 ? pictures.sublist(1) : <String>[];
      _currentDocument =
          _createDocumentData(scannedPath, additionalPages: additionalPages);
      if (pictures.length > 1) {
        debugPrint('📄 Multi-page capture: ${pictures.length} pages');
      }
      if (mounted) {
        setState(() {
          _currentStage = ProcessingStage.filter;
        });
      }
      await _autoApplyCleanFilter();
    } catch (e) {
      if (mounted) {
        _showError('Capture failed: $e');
      }
    } finally {
      _sourceActionInProgress = false;
    }
  }

  Future<String> _autoCropFromGallery(String imagePath) async {
    try {
      final bytes = await File(imagePath).readAsBytes();
      final img.Image? decoded0 = img.decodeImage(bytes);
      if (decoded0 == null) return imagePath; // fallback
      final decoded = img.bakeOrientation(decoded0);
      final w = decoded.width;
      final h = decoded.height;
      // Scan for non-white-ish pixels to find content bounds
      const int threshold = 240; // 0..255 (higher = stricter white)
      int minX = w, minY = h, maxX = 0, maxY = 0;
      for (int y = 0; y < h; y += 2) {
        // step 2 for speed
        for (int x = 0; x < w; x += 2) {
          final p = decoded.getPixel(x, y); // Pixel type in image 4.x
          final r = p.r;
          final g = p.g;
          final b = p.b;
          if (r < threshold || g < threshold || b < threshold) {
            if (x < minX) minX = x;
            if (y < minY) minY = y;
            if (x > maxX) maxX = x;
            if (y > maxY) maxY = y;
          }
        }
      }
      if (minX >= maxX || minY >= maxY) return imagePath; // nothing detected
      // Add small margin
      const margin = 12;
      minX = (minX - margin).clamp(0, w - 1);
      minY = (minY - margin).clamp(0, h - 1);
      maxX = (maxX + margin).clamp(1, w - 1);
      maxY = (maxY + margin).clamp(1, h - 1);
      final cw = (maxX - minX).clamp(1, w - minX);
      final ch = (maxY - minY).clamp(1, h - minY);
      final cropped =
          img.copyCrop(decoded, x: minX, y: minY, width: cw, height: ch);
      final Directory extDir = await getApplicationDocumentsDirectory();
      final String dirPath = '${extDir.path}/Documents/Cropped';
      await Directory(dirPath).create(recursive: true);
      final String outPath = path.join(
          dirPath, 'CROPPED_G_${DateTime.now().millisecondsSinceEpoch}.jpg');
      await File(outPath).writeAsBytes(img.encodeJpg(cropped, quality: 90));
      return outPath;
    } catch (_) {
      return imagePath; // fallback to original if anything fails
    }
  }

  Future<void> _maybeAutoStart() async {
    try {
      // If route supplied a document, start processing it immediately
      if (_currentDocument != null) {
        if (mounted) {
          setState(() {
            _currentStage = ProcessingStage.filter;
          });
        }
        await _autoApplyCleanFilter();
        return;
      }
      if (widget.galleryImagePath != null &&
          widget.galleryImagePath!.isNotEmpty) {
        _currentDocument = _createDocumentData(widget.galleryImagePath!);
        if (mounted) {
          setState(() {
            _currentStage = ProcessingStage.filter;
          });
        }
        await _autoApplyCleanFilter();
      } else {
        await _launchCapture();
      }
    } catch (e) {
      if (mounted) {
        _showError('Auto start failed: $e');
      }
    }
  }

  Future<void> _startScanner() async {
    setState(() {
      _isProcessing = true;
    });
    try {
      final List<String>? pictures = await CunningDocumentScanner.getPictures();
      if (pictures == null || pictures.isEmpty) {
        setState(() {
          _isProcessing = false;
        });
        // User tapped X (cancel). Go back to dashboard.
        if (mounted) {
          _sourceActionInProgress = false;
          Navigator.of(context).pushAndRemoveUntil(
            MaterialPageRoute(builder: (_) => const DashboardPage()),
            (route) => false,
          );
        }
        return;
      }
      // Use first image as primary, remaining as additional pages
      final scannedPath = pictures.first;
      final additionalPages =
          pictures.length > 1 ? pictures.sublist(1) : <String>[];
      _currentDocument =
          _createDocumentData(scannedPath, additionalPages: additionalPages);
      if (pictures.length > 1) {
        debugPrint('📄 Multi-page scan: ${pictures.length} pages captured');
      }
      setState(() {
        _isProcessing = false;
        _currentStage = ProcessingStage.filter;
      });
      // Auto-apply filter and proceed directly to results
      await _autoApplyCleanFilter();
      _sourceActionInProgress = false;
    } catch (e) {
      setState(() {
        _isProcessing = false;
      });
      _sourceActionInProgress = false;
      _showError('Scanner failed: $e');
    }
  }

  // Resolve base server root from saved detection, stripping trailing /api if present.
  // If nothing has been detected yet, fall back to a known LAN URL so uploads
  // don't show "No server URL".
  Future<String?> _getServerBase() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      String? detected = prefs.getString('detected_server_url');
      if (detected == null || detected.isEmpty) {
        detected = await ServerService.getServerUrl();
        await prefs.setString('detected_server_url', detected);
      }

      String base = detected;
      if (base.endsWith('/api')) {
        base = base.substring(0, base.length - 4);
      }
      return base;
    } catch (_) {
      // Last-resort fallback; still return a usable root instead of null.
      return ServerService.defaultServerRoot;
    }
  }

  // Upload an image/PDF to the PHP archive endpoint and return a server-relative
  // encrypted file path (e.g. "uploads/archive/<id>_name.enc") that the admin
  // dashboard can use as tracking.file_path.
  Future<String?> _uploadArchiveFile({
    required String filePath,
    required String documentName,
    required String department,
    required String docType,
  }) async {
    try {
      final base = await _getServerBase();
      if (base == null) return null;
      final uri = Uri.parse('$base/lib/OCR(UPDATED)/api/upload_archive.php');
      final req = http.MultipartRequest('POST', uri);
      req.fields['name'] = documentName;
      req.fields['department'] = department;
      req.fields['type'] = docType;
      req.files.add(await http.MultipartFile.fromPath('file', filePath));

      final resp = await req.send();
      if (resp.statusCode != 200) return null;

      final body = await resp.stream.bytesToString();
      final decoded = jsonDecode(body);
      if (decoded is Map &&
          decoded['success'] == true &&
          decoded['file'] != null) {
        final fileName = decoded['file'].toString();
        // upload_archive.php writes to lib/OCR(UPDATED)/uploads/archive
        // Store the path relative to that folder so PHP can resolve it.
        return 'uploads/archive/$fileName';
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  // Attempt to map a display string to an exact username in control table
  Future<String?> _resolveRecipientUsername(String root, String input) async {
    try {
      String s = input.trim();
      if (s.isEmpty) return null;
      // Common UI forms: "Allen", "Allen - CPDO", "Allen (CPDO)"
      s = s.split(' - ').first.trim();
      s = s.split('(').first.trim();
      final uri =
          Uri.parse('$root/lib/OCR(UPDATED)/api/list_control_entities.php');
      final r = await http.get(uri).timeout(const Duration(seconds: 8));
      if (r.statusCode != 200 || r.body.isEmpty) return s;
      final m = json.decode(r.body) as Map<String, dynamic>;
      final users = (m['users'] as List?) ?? [];
      final target = s.toLowerCase();
      // 1) exact match on user
      for (final u in users) {
        final user = (u['user']?.toString() ?? '').trim();
        if (user.toLowerCase() == target) return user;
      }
      // 2) startsWith match
      for (final u in users) {
        final user = (u['user']?.toString() ?? '').trim();
        if (user.toLowerCase().startsWith(target)) return user;
      }
      return s;
    } catch (_) {
      return input.trim();
    }
  }

  // Send a mobile-to-mobile notification to a specific username (best-effort)
  Future<void> _sendMobileNotification({
    required String toUsername,
    required String title,
    required String content,
    required String department,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    String? baseUrl = prefs.getString('detected_server_url');
    baseUrl ??= await ServerService.getServerUrl();
    final root = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
    final url = '$root/lib/OCR(UPDATED)/api/notifications.php';
    // Try to resolve/normalize username first, in case the picker provided a display value
    final resolved = await _resolveRecipientUsername(root, toUsername);
    final finalRecipient = resolved ?? toUsername.trim();
    try {
      final Map<String, String> payload = {
        'action': 'create',
        'type': 'mobile_message',
        'title':
            'New Document from ${(prefs.getString('user_name') ?? '').trim()}',
        'content': content,
        'department': department,
        'recipient_username': finalRecipient,
        'sender_username': (prefs.getString('user_name') ?? '').trim(),
      };
      debugPrint('[notify] POST -> $url  body=$payload');
      final resp = await http
          .post(Uri.parse(url), body: payload)
          .timeout(const Duration(seconds: 8));
      debugPrint('[notify] POST status=${resp.statusCode} body=${resp.body}');
      bool ok = false;
      if (resp.statusCode < 400) {
        try {
          final m = json.decode(resp.body);
          ok = (m is Map && (m['success'] == true || m['id'] != null));
        } catch (_) {
          ok = resp.body.contains('success') || resp.body.contains('id');
        }
      }
      if (!ok) {
        // Retry with GET fallback (handles servers that drop POST bodies)
        final uri = Uri.parse(url).replace(queryParameters: payload);
        debugPrint('[notify] GET fallback -> $uri');
        final r2 = await http.get(uri).timeout(const Duration(seconds: 8));
        debugPrint('[notify] GET status=${r2.statusCode} body=${r2.body}');
      }
    } catch (e) {
      debugPrint('notify error $e');
    }
  }

  void _resizeCropBox({
    required Size size,
    double dLeft = 0,
    double dTop = 0,
    double dRight = 0,
    double dBottom = 0,
  }) {
    // Convert deltas from pixels to percentages
    final dl = dLeft / size.width;
    final dt = dTop / size.height;
    final dr = dRight / size.width;
    final db = dBottom / size.height;

    double newLeft = _cropLeft + dl;
    double newTop = _cropTop + dt;
    double newRight = _cropLeft + _cropWidth + dr;
    double newBottom = _cropTop + _cropHeight + db;

    // Constrain to [0,1]
    newLeft = newLeft.clamp(0.0, 1.0);
    newTop = newTop.clamp(0.0, 1.0);
    newRight = newRight.clamp(0.0, 1.0);
    newBottom = newBottom.clamp(0.0, 1.0);

    // Enforce minimum size
    const minFrac = 0.05; // 5% of side
    if (newRight - newLeft < minFrac) {
      // Prefer adjusting the side being dragged
      if (dr != 0) {
        newRight = newLeft + minFrac;
      } else {
        newLeft = newRight - minFrac;
      }
    }
    if (newBottom - newTop < minFrac) {
      if (db != 0) {
        newBottom = newTop + minFrac;
      } else {
        newTop = newBottom - minFrac;
      }
    }

    setState(() {
      _cropLeft = newLeft;
      _cropTop = newTop;
      _cropWidth = (newRight - newLeft).clamp(minFrac, 1.0);
      _cropHeight = (newBottom - newTop).clamp(minFrac, 1.0);
    });
  }

  @override
  void initState() {
    super.initState();
    _initializeAnimations();
    _initializeCamera();
    _initializeMLKit();
    _initializeBatchProcessor();
    // Auto-start scanner or gallery flow after first frame when requested
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _maybeAutoStart();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    if (_routeArgsConsumed) return;

    // Prefer constructor values, fall back to route arguments if provided
    final args = ModalRoute.of(context)?.settings.arguments;
    if (args != null) {
      if (args is DocumentData) {
        setState(() {
          _currentDocument = args;
          _currentStage = ProcessingStage.filter;
        });
      } else if (args is Map) {
        // Common patterns: {'document': <Map|DocumentData>}, or direct fields
        if (args.containsKey('document')) {
          final doc = args['document'];
          if (doc is DocumentData) {
            setState(() {
              _currentDocument = doc;
              _currentStage = ProcessingStage.filter;
            });
          } else if (doc is Map) {
            try {
              final m = Map<String, dynamic>.from(doc);
              setState(() {
                _currentDocument = DocumentData.fromJson(m);
                _currentStage = ProcessingStage.filter;
              });
            } catch (_) {}
          }
        }

        // Accept mobileTimestamp / trackingId as well
        if (_routingMobileTimestamp == null &&
            args['mobileTimestamp'] != null) {
          _routingMobileTimestamp = args['mobileTimestamp'].toString();
        }
        if (_routingTrackingId == null && args['trackingId'] != null) {
          _routingTrackingId = args['trackingId'].toString();
        }
      }
    }

    // If constructor provided values, make sure state reflects them
    if (_routingMobileTimestamp == null &&
        widget.routingMobileTimestamp != null) {
      _routingMobileTimestamp = widget.routingMobileTimestamp;
    }
    if (_routingTrackingId == null && widget.routingTrackingId != null) {
      _routingTrackingId = widget.routingTrackingId;
    }

    _routeArgsConsumed = true;
  }

  /// Initialize the batch document processor with optimal settings for device
  void _initializeBatchProcessor() {
    // Use standard config - can be adjusted based on device capabilities
    _batchProcessor = BatchDocumentProcessor(
      config: BatchProcessingConfig.standard,
    );
  }

  void _initializeAnimations() {
    // Scan animation for document detection
    _scanController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 2),
    )..addListener(() {
        setState(() {
          _scanValue = _scanController.value;
        });
      });

    // Processing animation for document processing stages
    _processingController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 1),
    );

    // Batch animation for batch mode
    _batchController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 800),
    );
  }

  Future<void> _initializeCamera() async {
    try {
      _cameras = await availableCameras();
      if (_cameras == null || _cameras!.isEmpty) {
        debugPrint('⚠️ No cameras available');
        return;
      }
      final cameraIndex = _isBackCamera ? 0 : (_cameras!.length > 1 ? 1 : 0);
      _controller = CameraController(
        _cameras![cameraIndex],
        ResolutionPreset.medium, // Faster capture than high
        enableAudio: false,
      );
      _initializeControllerFuture = _controller.initialize();
      if (mounted) setState(() {});
    } catch (e) {
      debugPrint('⚠️ Camera initialization failed: $e');
    }
  }

  // Initialize Google ML Kit enhanced text recognition
  void _initializeMLKit() {
    // Enhanced text recognition is ready
    _useEnhancedTextRecognition = true;
    debugPrint('✅ Google ML Kit enhanced text recognition initialized');
  }

  // CamScanner-like workflow methods
  Future<void> _captureDocument() async {
    try {
      final imagePath = await _takePicture();
      if (imagePath != null) {
        setState(() {
          _lastCapturedImagePath = imagePath;
          _currentStage = ProcessingStage.filter;
          _showCropInterface = false;
        });

        // Create document data
        _currentDocument = _createDocumentData(imagePath);

        if (_isBatchMode) {
          _batchCount++;
          _batchController.forward();
        }

        // Automatically apply clean filter after capture
        await _autoApplyCleanFilter();
      }
    } catch (e) {
      _showError('Failed to capture document: $e');
    }
  }

  // Resolve absolute server URL by using detected_server_url saved during login
  Future<String> _resolveServerPath(String relativePath,
      {required String fallback}) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      String? base = prefs.getString(
          'detected_server_url'); // e.g., http://ip/flutter_application_7/api
      if (base != null && base.isNotEmpty) {
        if (base.endsWith('/api')) base = base.substring(0, base.length - 4);
        if (relativePath.startsWith('/')) {
          return base + relativePath;
        }
        return '$base/$relativePath';
      }
    } catch (_) {}
    return fallback;
  }

  // Post a notification to the server notifications API so it appears in Recent Activity
  Future<void> _postRecentActivity({
    required String type,
    required String title,
    required String content,
    required String userName,
    required String department,
  }) async {
    final String url = await _resolveServerPath(
      '/lib/OCR(UPDATED)/api/notifications.php',
      fallback:
          '${ServerService.defaultServerRoot}/lib/OCR(UPDATED)/api/notifications.php',
    );

    final payload = {
      'action': 'create',
      'type': type,
      'title': title,
      'content': content,
      'user': userName,
      'department': department,
      'source': 'mobile_app',
    };

    final resp = await http
        .post(
          Uri.parse(url),
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode(payload),
        )
        .timeout(const Duration(seconds: 8));

    debugPrint('[RecentActivity] POST ${resp.statusCode} -> $url');
  }

  Future<String?> _takePicture() async {
    try {
      await _initializeControllerFuture;
      final Directory extDir = await getApplicationDocumentsDirectory();
      final String dirPath = '${extDir.path}/Documents/Captured';
      await Directory(dirPath).create(recursive: true);
      final String filePath = path.join(
          dirPath, 'DOC_${DateTime.now().millisecondsSinceEpoch}.jpg');

      final XFile picture = await _controller.takePicture();
      await File(picture.path).copy(filePath);
      return filePath;
    } catch (e) {
      debugPrint('Error taking picture: $e');
      return null;
    }
  }

  Future<void> _cropDocument() async {
    if (_currentDocument == null) return;

    setState(() {
      _isProcessing = true;
      _currentStage = ProcessingStage.filter;
      _showCropInterface = false;
    });

    try {
      // Use Cunning Document Scanner to detect, crop, and return a new image
      final List<String>? pictures = await CunningDocumentScanner.getPictures();
      if (pictures == null || pictures.isEmpty) {
        throw Exception('Cropping was cancelled');
      }
      final String croppedPath = pictures.first;
      _currentDocument = _currentDocument!.copyWith(
        croppedPath: croppedPath,
        recognizedText: _currentDocument!.recognizedText,
        captureTime: _currentDocument!.captureTime,
        appliedFilter: _selectedFilter,
      );

      setState(() {
        _isProcessing = false;
      });

      // Auto-apply filter and proceed directly to results
      await _autoApplyCleanFilter();
    } catch (e) {
      setState(() {
        _isProcessing = false;
      });
      _showError('Failed to crop document: $e');
    }
  }

  Future<String> _applyCropping(String imagePath) async {
    // Load original image bytes
    final inputFile = File(imagePath);
    final bytes = await inputFile.readAsBytes();
    final decodedRaw = img.decodeImage(bytes);
    if (decodedRaw == null) {
      throw Exception('Failed to decode image for cropping');
    }
    final decoded = img.bakeOrientation(decodedRaw);

    final imgW = decoded.width;
    final imgH = decoded.height;

    // If we know the on-screen area size, account for BoxFit.contain letterboxing
    // Compute the image render rect inside the available area
    double scale = 1.0;
    double renderW;
    double renderH;
    double offsetX = 0.0;
    double offsetY = 0.0;
    if (_lastCropAreaSize != null &&
        _lastCropAreaSize!.width > 0 &&
        _lastCropAreaSize!.height > 0) {
      final aw = _lastCropAreaSize!.width;
      final ah = _lastCropAreaSize!.height;
      final sx = aw / imgW;
      final sy = ah / imgH;
      scale = sx < sy ? sx : sy; // min for contain
      renderW = imgW * scale;
      renderH = imgH * scale;
      offsetX = (aw - renderW) / 2.0;
      offsetY = (ah - renderH) / 2.0;
    } else {
      // Fallback: assume image fills the area without letterboxing
      renderW = imgW.toDouble();
      renderH = imgH.toDouble();
      offsetX = 0;
      offsetY = 0;
      scale = 1.0;
    }

    // Crop rect in area pixels
    final areaLeftPx = _cropLeft * (_lastCropAreaSize?.width ?? renderW);
    final areaTopPx = _cropTop * (_lastCropAreaSize?.height ?? renderH);
    final areaWidthPx = _cropWidth * (_lastCropAreaSize?.width ?? renderW);
    final areaHeightPx = _cropHeight * (_lastCropAreaSize?.height ?? renderH);

    // Image render rect in area space
    final imgRectLeft = offsetX;
    final imgRectTop = offsetY;
    final imgRectRight = offsetX + renderW;
    final imgRectBottom = offsetY + renderH;

    // Intersect crop rect with image render rect
    final cropLeftArea = areaLeftPx.clamp(imgRectLeft, imgRectRight);
    final cropTopArea = areaTopPx.clamp(imgRectTop, imgRectBottom);
    final cropRightArea =
        (areaLeftPx + areaWidthPx).clamp(imgRectLeft, imgRectRight);
    final cropBottomArea =
        (areaTopPx + areaHeightPx).clamp(imgRectTop, imgRectBottom);

    double insideLeft = cropLeftArea - imgRectLeft;
    double insideTop = cropTopArea - imgRectTop;
    double insideW = (cropRightArea - cropLeftArea).clamp(1.0, renderW);
    double insideH = (cropBottomArea - cropTopArea).clamp(1.0, renderH);

    // Convert from render pixels back to image pixels by dividing by scale
    int leftPx = (insideLeft / scale).round();
    int topPx = (insideTop / scale).round();
    int widthPx = (insideW / scale).round();
    int heightPx = (insideH / scale).round();

    // Clamp within image bounds and enforce minimums
    if (widthPx < 1) widthPx = 1;
    if (heightPx < 1) heightPx = 1;
    if (leftPx < 0) leftPx = 0;
    if (topPx < 0) topPx = 0;
    if (leftPx + widthPx > imgW) {
      widthPx = imgW - leftPx;
    }
    if (topPx + heightPx > imgH) {
      heightPx = imgH - topPx;
    }

    final cropped = img.copyCrop(
      decoded,
      x: leftPx,
      y: topPx,
      width: widthPx,
      height: heightPx,
    );

    final Directory extDir = await getApplicationDocumentsDirectory();
    final String dirPath = '${extDir.path}/Documents/Cropped';
    await Directory(dirPath).create(recursive: true);
    final String croppedPath = path.join(
        dirPath, 'CROPPED_${DateTime.now().millisecondsSinceEpoch}.jpg');

    final outBytes = img.encodeJpg(cropped, quality: 90);
    await File(croppedPath).writeAsBytes(outBytes);
    return croppedPath;
  }

  Future<void> _applyFilter(DocumentFilter filter) async {
    if (_currentDocument == null) return;

    setState(() {
      _isProcessing = true;
      _selectedFilter = filter;
      _currentStage = ProcessingStage.recognize;
    });

    try {
      final filteredPath = await _applyDocumentFilter(
          _currentDocument!.croppedPath ?? _currentDocument!.imagePath, filter);

      _currentDocument = _currentDocument!.copyWith(
        croppedPath: _currentDocument!.croppedPath,
        filteredPath: filteredPath,
        recognizedText: _currentDocument!.recognizedText,
        captureTime: _currentDocument!.captureTime,
        appliedFilter: filter,
      );

      setState(() {
        _showFilterInterface = false;
        _isProcessing = false;
      });

      // Automatically proceed to text recognition
      await _recognizeKeyInformation();
    } catch (e) {
      setState(() {
        _isProcessing = false;
      });
      _showError('Failed to apply filter: $e');
    }
  }

  Future<void> _autoApplyCleanFilter() async {
    if (_currentDocument == null) return;
    // Auto-apply enhance filter for best OCR results; users can still manually pick other filters
    await _applyFilter(DocumentFilter.enhance);
  }

  Future<String> _applyDocumentFilter(
      String imagePath, DocumentFilter filter) async {
    final Directory extDir = await getApplicationDocumentsDirectory();
    final String dirPath = '${extDir.path}/Documents/Filtered';
    await Directory(dirPath).create(recursive: true);
    final String filteredPath = path.join(dirPath,
        'FILTERED_${filter.name}_${DateTime.now().millisecondsSinceEpoch}.jpg');

    // Fast path: original just copies
    if (filter == DocumentFilter.original) {
      await File(imagePath).copy(filteredPath);
      return filteredPath;
    }

    try {
      final bytes = await File(imagePath).readAsBytes();
      final outBytes = await compute(_applyDocumentFilterIsolate, {
        'bytes': bytes,
        'filter': filter.name,
      });
      await File(filteredPath).writeAsBytes(outBytes);
      return filteredPath;
    } catch (e) {
      // Fallback to copy if preprocessing fails
      debugPrint('⚠️ Filter processing failed, fallback to copy: $e');
      await File(imagePath).copy(filteredPath);
      return filteredPath;
    }
  }

  Future<void> _recognizeKeyInformation() async {
    if (_currentDocument == null) return;

    setState(() {
      _isProcessing = true;
      _autoSaveMessage = 'Recognizing key information...';
      _autoSaveInProgress = true;
    });

    try {
      final imagePath = _currentDocument!.filteredPath ??
          _currentDocument!.croppedPath ??
          _currentDocument!.imagePath;

      // Perform OCR on the primary page
      await _performAdvancedOCR(imagePath);

      // If there are additional pages, OCR each and keep per-page text
      final additionalPages = _currentDocument!.additionalPages;
      final List<String> pageTexts = <String>[];
      pageTexts.add(_recognizedText.trim().isNotEmpty
          ? _recognizedText.trim()
          : 'No text detected in image');

      if (additionalPages.isNotEmpty) {
        setState(() {
          _autoSaveMessage =
              'Processing ${additionalPages.length + 1} pages...';
        });

        for (int i = 0; i < additionalPages.length; i++) {
          final pagePath = additionalPages[i];
          try {
            final pageText = await _performOcrOnPage(pagePath);
            pageTexts.add(pageText.trim().isNotEmpty
                ? pageText.trim()
                : 'No text detected in image');
            debugPrint('📄 OCR completed for page ${i + 2}');
          } catch (e) {
            debugPrint('⚠️ OCR failed for page ${i + 2}: $e');
            pageTexts.add('No text detected in image');
          }
        }

        debugPrint(
            '📄 Multi-page OCR completed: ${additionalPages.length + 1} pages processed');
      }

      // Keep a combined text version for compatibility (uploads/exports)
      _recognizedText = pageTexts.join('\n\n').trim();

      // Re-run AI classification on combined OCR text (all pages).
      _classifyDocumentType(_recognizedText);

      // Extract key information will be done in the enhanced processing

      _currentDocument = _currentDocument!.copyWith(
        croppedPath: _currentDocument!.croppedPath,
        filteredPath: _currentDocument!.filteredPath,
        recognizedText: _recognizedText,
        pageTexts: pageTexts,
        captureTime: _currentDocument!.captureTime,
        appliedFilter: _currentDocument!.appliedFilter,
        confidence: _averageConfidence,
        keyInformation: _keyInformation,
      );

      // Add to captured documents
      _capturedDocuments.add(_currentDocument!);

      setState(() {
        _isProcessing = false;
        _autoSaveInProgress = false;
        _showResults = true;
        _showTextView = false; // Keep OCR collapsed until user taps OCR Content
        _currentStage = ProcessingStage.export;
        _resultsPageIndex = 0;
      });
    } catch (e) {
      setState(() {
        _isProcessing = false;
        _autoSaveInProgress = false;
      });
      _showError('Failed to recognize text: $e');
    }
  }

  /// Process multiple documents in batch with optimized memory usage
  /// Handles 10-15+ documents efficiently with chunked processing
  Future<void> _processBatchDocuments(List<String> imagePaths) async {
    if (imagePaths.isEmpty) return;

    setState(() {
      _isBatchProcessing = true;
      _batchProcessingCurrent = 0;
      _batchProcessingTotal = imagePaths.length;
      _batchProcessingTask = 'Initializing batch processing...';
      _autoSaveInProgress = true;
      _autoSaveMessage = 'Processing ${imagePaths.length} documents...';
    });

    try {
      final processedDocs = <ProcessedDocument>[];

      // Process documents in chunks to avoid memory issues
      await for (final chunk in _batchProcessor.processInChunks(
        imagePaths,
        onProgress: (current, total, task, progress) {
          if (mounted) {
            setState(() {
              _batchProcessingCurrent = current;
              _batchProcessingTotal = total;
              _batchProcessingTask = task;
              _autoSaveMessage = 'Processing $current of $total documents...';
            });
          }
        },
      )) {
        processedDocs.addAll(chunk);

        // Give UI time to update
        await Future.delayed(const Duration(milliseconds: 50));
      }

      // Convert processed documents to DocumentData
      for (final doc in processedDocs) {
        if (!doc.hasError) {
          final docData = DocumentData(
            id: doc.id,
            imagePath: doc.originalPath,
            filteredPath: doc.optimizedPath,
            recognizedText: doc.ocrText,
            pageTexts: doc.pageTexts,
            captureTime: doc.processedAt,
            mobileTimestamp: doc.id,
            docHash: _generateDocHash('${doc.originalPath}|${doc.id}'),
            confidence: doc.confidence,
          );
          _capturedDocuments.add(docData);
        }
      }

      setState(() {
        _isBatchProcessing = false;
        _autoSaveInProgress = false;
        _showResults = true;
        _currentStage = ProcessingStage.export;
      });

      if (mounted) {
        final successCount = processedDocs.where((d) => !d.hasError).length;
        final errorCount = processedDocs.where((d) => d.hasError).length;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              '✅ Processed $successCount documents${errorCount > 0 ? ' ($errorCount failed)' : ''}',
            ),
            backgroundColor: errorCount > 0 ? Colors.orange : Colors.green,
          ),
        );
      }

      // Clean up temporary files
      await _batchProcessor.clearTemporaryFiles();
    } catch (e) {
      setState(() {
        _isBatchProcessing = false;
        _autoSaveInProgress = false;
      });
      _showError('Batch processing failed: $e');
    }
  }

  /// Upload batch documents route-by-route to different departments
  Future<void> _uploadBatchByRoute(
    List<DocumentData> documents,
    List<Map<String, String>> departmentRoute,
  ) async {
    if (documents.isEmpty || departmentRoute.isEmpty) return;

    await SharedPreferences.getInstance();
    final base = await _getServerBase();
    if (base == null) {
      _showError('Server URL not configured');
      return;
    }

    setState(() {
      _autoSaveInProgress = true;
      _autoSaveMessage =
          'Uploading to ${departmentRoute.length} departments...';
    });

    try {
      // Distribute documents across route
      final docsPerDept = (documents.length / departmentRoute.length).ceil();
      int docIndex = 0;
      int successCount = 0;

      for (int routeIdx = 0; routeIdx < departmentRoute.length; routeIdx++) {
        final route = departmentRoute[routeIdx];
        final receiverUsername = route['username'] ?? '';
        final receiverDept = route['department'] ?? '';
        final isLastDept = routeIdx == departmentRoute.length - 1;

        // Get documents for this department
        final deptDocs = <DocumentData>[];
        for (int i = 0; i < docsPerDept && docIndex < documents.length; i++) {
          deptDocs.add(documents[docIndex]);
          docIndex++;
        }

        if (deptDocs.isEmpty) continue;

        setState(() {
          _autoSaveMessage =
              'Sending to $receiverDept (${routeIdx + 1}/${departmentRoute.length})...';
        });

        // Upload each document in this chunk
        for (final doc in deptDocs) {
          final success = await _routeDocumentToUserFromCamera(
            fileName: doc.id,
            filePath: doc.filteredPath ?? doc.imagePath,
            type: 'Scanned Document',
            receiverUsername: receiverUsername,
            receiverDepartment: receiverDept,
            mobileTimestamp: doc.mobileTimestamp,
            docHash: doc.docHash,
            // For last department, mark as final destination
            endLocation: isLastDept ? receiverDept : null,
            nextDepartment: isLastDept
                ? null
                : (routeIdx + 1 < departmentRoute.length
                    ? departmentRoute[routeIdx + 1]['department']
                    : null),
          );
          if (success) successCount++;

          // Small delay between uploads to prevent server overload
          await Future.delayed(const Duration(milliseconds: 200));
        }
      }

      setState(() {
        _autoSaveInProgress = false;
      });

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
                '✅ Uploaded $successCount of ${documents.length} documents'),
            backgroundColor:
                successCount == documents.length ? Colors.green : Colors.orange,
          ),
        );
      }
    } catch (e) {
      setState(() {
        _autoSaveInProgress = false;
      });
      _showError('Route upload failed: $e');
    }
  }

  /// Upload documents in batch using the server batch API
  /// More efficient than individual uploads for 10-15+ documents
  Future<Map<String, dynamic>> _batchUploadToServer(
    List<DocumentData> documents, {
    required String receiverUsername,
    required String receiverDepartment,
    String documentType = 'Scanned Document',
    String? endLocation,
    String? nextDepartment,
  }) async {
    final base = await _getServerBase();
    if (base == null) {
      return {'success': false, 'error': 'Server URL not configured'};
    }

    final prefs = await SharedPreferences.getInstance();
    final senderName = prefs.getString('user_name') ?? 'User';
    final senderDept = prefs.getString('user_department') ?? 'General';

    try {
      final uri = Uri.parse('$base/lib/OCR(UPDATED)/api/batch_upload.php');
      final request = http.MultipartRequest('POST', uri);

      // Add common fields
      request.fields['action'] = 'batch_upload';
      request.fields['sender_name'] = senderName;
      request.fields['sender_department'] = senderDept;
      request.fields['receiver_username'] = receiverUsername;
      request.fields['receiver_department'] = receiverDepartment;
      request.fields['document_type'] = documentType;
      if (endLocation != null) request.fields['end_location'] = endLocation;
      if (nextDepartment != null) {
        request.fields['next_department'] = nextDepartment;
      }

      // Add files, OCR content, and hashes
      for (int i = 0; i < documents.length; i++) {
        final doc = documents[i];
        final filePath = doc.filteredPath ?? doc.imagePath;

        // Add file
        request.files.add(await http.MultipartFile.fromPath(
          'documents[]',
          filePath,
          filename: '${doc.id}.jpg',
        ));

        // Add OCR content as parallel array (enriched for search)
        request.fields['ocr_content[$i]'] =
            OcrTextProcessor.generateSearchableContent(doc.recognizedText);

        // Add doc hash as parallel array
        request.fields['doc_hash[$i]'] = doc.docHash;

        // Send per-page OCR for multi-page search support
        if (doc.pageTexts.isNotEmpty) {
          for (int pageIdx = 0; pageIdx < doc.pageTexts.length; pageIdx++) {
            request.fields['ocr_pages[$i][$pageIdx]'] =
                doc.pageTexts[pageIdx].trim();
          }
        } else if (doc.recognizedText.trim().isNotEmpty) {
          request.fields['ocr_pages[$i][0]'] = doc.recognizedText.trim();
        }
      }

      // Send request with progress tracking
      final streamedResponse = await request.send().timeout(
            Duration(
                seconds: 60 +
                    (documents.length * 5)), // Scale timeout with doc count
          );

      final responseBody = await streamedResponse.stream.bytesToString();
      final decoded = jsonDecode(responseBody);

      return {
        'success': decoded['success'] ?? false,
        'uploaded': decoded['uploaded'] ?? 0,
        'failed': decoded['failed'] ?? 0,
        'results': decoded['results'] ?? [],
        'total': decoded['total'] ?? documents.length,
      };
    } catch (e) {
      debugPrint('❌ Batch upload failed: $e');
      return {'success': false, 'error': e.toString()};
    }
  }

  Future<void> _performAdvancedOCR(String imagePath) async {
    try {
      final inputImage = InputImage.fromFilePath(imagePath);

      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      final result = await recognizer.processImage(inputImage);
      recognizer.close();

      // Calculate average confidence
      double totalConfidence = 0.0;
      int elementCount = 0;
      for (final block in result.blocks) {
        for (final line in block.lines) {
          for (final element in line.elements) {
            if (element.confidence != null) {
              totalConfidence += element.confidence!;
              elementCount++;
            }
          }
        }
      }
      final avgConfidence =
          elementCount > 0 ? totalConfidence / elementCount : 0.0;
      _documentConfidence = avgConfidence;

      _classifyDocumentType(result.text);
      _processRecognizedTextEnhanced(result);
      debugPrint(
          '✅ Advanced OCR completed with ${(avgConfidence * 100).toStringAsFixed(1)}% confidence');
    } catch (e) {
      throw Exception('Advanced OCR processing failed: $e');
    }
  }

  List<String> _getPreviewPagePaths(DocumentData doc) {
    final first = doc.filteredPath ?? doc.croppedPath ?? doc.imagePath;
    return <String>[first, ...doc.additionalPages];
  }

  String _getOcrTextForPage(int pageIndex) {
    if (_currentDocument == null) return _recognizedText;
    final texts = _currentDocument!.pageTexts;
    if (pageIndex >= 0 && pageIndex < texts.length) {
      final t = texts[pageIndex].trim();
      return t.isNotEmpty ? t : 'No text detected in image';
    }
    return 'No text detected in image';
  }

  /// Performs OCR on a single page and returns just the recognized text (for multi-page processing)
  /// Preprocesses the image with enhance filter for better text recognition.
  Future<String> _performOcrOnPage(String imagePath) async {
    try {
      // Preprocess image for better OCR: apply enhance filter in isolate
      String ocrImagePath = imagePath;
      try {
        final bytes = await File(imagePath).readAsBytes();
        final enhancedBytes = await compute(_applyDocumentFilterIsolate, {
          'bytes': bytes,
          'filter': 'enhance',
        });
        final Directory extDir = await getApplicationDocumentsDirectory();
        final String dirPath = '${extDir.path}/Documents/OcrTemp';
        await Directory(dirPath).create(recursive: true);
        final tempPath = path.join(
            dirPath, 'OCR_${DateTime.now().millisecondsSinceEpoch}.jpg');
        await File(tempPath).writeAsBytes(enhancedBytes);
        ocrImagePath = tempPath;
      } catch (e) {
        debugPrint('[OCR] Image preprocessing failed, using original: $e');
      }

      final inputImage = InputImage.fromFilePath(ocrImagePath);
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);
      final result = await recognizer.processImage(inputImage);
      recognizer.close();

      // If preprocessed image yielded poor results, retry with original
      if (result.text.trim().length < 10 && ocrImagePath != imagePath) {
        debugPrint(
            '[OCR] Enhanced image yielded poor results, retrying with original...');
        final origInput = InputImage.fromFilePath(imagePath);
        final origRecognizer =
            TextRecognizer(script: TextRecognitionScript.latin);
        final origResult = await origRecognizer.processImage(origInput);
        origRecognizer.close();
        if (origResult.text.trim().length > result.text.trim().length) {
          return _buildCleanedOcrText(origResult);
        }
      }

      // Clean up temp file
      if (ocrImagePath != imagePath) {
        try {
          await File(ocrImagePath).delete();
        } catch (_) {}
      }

      return _buildCleanedOcrText(result);
    } catch (e) {
      debugPrint('OCR error on page $imagePath: $e');
      return '[OCR failed]';
    }
  }

  /// Builds cleaned OCR text from a RecognizedText result
  String _buildCleanedOcrText(RecognizedText result) {
    final buffer = StringBuffer();
    for (final block in result.blocks) {
      for (final line in block.lines) {
        buffer.writeln(line.text);
      }
      buffer.writeln(); // Extra line between blocks
    }
    final rawText = buffer.toString().trim();
    final cleanedText = OcrTextProcessor.cleanOcrText(rawText);
    debugPrint(
        '[OCR] Raw: ${rawText.length} chars -> Cleaned: ${cleanedText.length} chars');
    return cleanedText;
  }

  // Classify document type using AI-powered CHRMO Document Classifier
  void _classifyDocumentType(String text) {
    _documentTypes.clear();

    final normalized = text.trim();
    _aiClassificationNote = null;

    // If OCR output is too short, classification will be meaningless.
    // This commonly happens when the camera sees a logo/device label (e.g. "ThinkPad").
    if (normalized.length < 40) {
      _aiClassification = null;
      _aiClassificationNote =
          'OCR too short (${normalized.length} chars). Rescan closer / improve lighting.';
      _documentTypes.add('General Document');
      debugPrint(
          '🤖 AI Document Classification skipped: $_aiClassificationNote');
      return;
    }

    // Use CHRMO AI classifier for document type suggestion
    _aiClassification = _chrmoClassifier.classify(normalized);

    // Add the detected type to legacy list for backwards compatibility
    if (_aiClassification != null) {
      _documentTypes.add(_aiClassification!.type.displayName);
    } else {
      _documentTypes.add('General Document');
    }
  }

  void _showError(String message) {
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(message),
          backgroundColor: Colors.red,
          duration: const Duration(seconds: 3),
        ),
      );
    }
  }

  void _autoDetectDocument() {
    // Auto-detect document boundaries placeholder
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('🤖 AI auto-detecting document boundaries...'),
        backgroundColor: Colors.orange,
        duration: Duration(seconds: 2),
      ),
    );
    Future.delayed(const Duration(seconds: 1), () {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('✅ Document boundaries detected automatically!'),
          backgroundColor: Colors.green,
          duration: Duration(seconds: 2),
        ),
      );
    });
  }

  // Placeholder that mimics document corner detection (no OpenCV)
  Future<List<Offset>?> _detectDocumentCorners(String imagePath) async {
    // Not implemented; return null to indicate no detection.
    return null;
  }

  /// Builds a compact extracted keys summary for the Complete Scan dialog
  Widget _buildDialogExtractedKeys() {
    String fullText = '';
    if (_currentDocument != null && _currentDocument!.pageTexts.isNotEmpty) {
      fullText = _currentDocument!.pageTexts.join('\n\n').trim();
    } else if (_recognizedText.isNotEmpty) {
      fullText = _recognizedText;
    }
    if (fullText.isEmpty || fullText == 'No text recognized yet') {
      return const SizedBox.shrink();
    }
    final extracted = OcrTextProcessor.extractSearchableKeys(fullText);
    final items = <_ExtractedKeyItem>[];

    final docType = extracted['document_type'] as String?;
    if (docType != null) {
      items.add(_ExtractedKeyItem(
        Icons.description_outlined,
        'Type',
        docType[0].toUpperCase() + docType.substring(1),
      ));
    }
    for (final n in ((extracted['names'] as List<String>?) ?? []).take(2)) {
      items.add(_ExtractedKeyItem(Icons.person_outline, 'Name', n));
    }
    for (final d in ((extracted['dates'] as List<String>?) ?? []).take(2)) {
      items.add(_ExtractedKeyItem(Icons.calendar_today_outlined, 'Date', d));
    }
    for (final a in ((extracted['amounts'] as List<String>?) ?? []).take(2)) {
      items.add(_ExtractedKeyItem(Icons.payments_outlined, 'Amount', a));
    }
    for (final r
        in ((extracted['reference_numbers'] as List<String>?) ?? []).take(1)) {
      items.add(_ExtractedKeyItem(Icons.tag, 'Ref', r));
    }
    for (final s in ((extracted['subjects'] as List<String>?) ?? []).take(1)) {
      items.add(_ExtractedKeyItem(Icons.subject, 'Subject', s));
    }
    for (final p in ((extracted['positions'] as List<String>?) ?? []).take(1)) {
      items.add(_ExtractedKeyItem(Icons.badge_outlined, 'Position', p));
    }
    for (final dept
        in ((extracted['departments'] as List<String>?) ?? []).take(1)) {
      items.add(_ExtractedKeyItem(Icons.business_outlined, 'Dept', dept));
    }

    if (items.isEmpty) return const SizedBox.shrink();

    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Colors.grey[50],
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          const Row(
            children: [
              Icon(Icons.auto_awesome, size: 13, color: Color(0xFF6868AC)),
              SizedBox(width: 6),
              Text(
                'Extracted Keys',
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF6868AC),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 6,
            runSpacing: 4,
            children: items.map((item) {
              return Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(6),
                  border: Border.all(color: Colors.grey.shade200),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(item.icon, size: 12, color: Colors.grey[600]),
                    const SizedBox(width: 4),
                    Text(
                      '${item.label}: ',
                      style: TextStyle(
                        fontSize: 10,
                        color: Colors.grey[500],
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    Flexible(
                      child: Text(
                        item.value,
                        style: const TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w500,
                          color: Colors.black87,
                        ),
                        overflow: TextOverflow.ellipsis,
                        maxLines: 1,
                      ),
                    ),
                  ],
                ),
              );
            }).toList(),
          ),
        ],
      ),
    );
  }

  Future<void> _showKeyInformationResults() async {
    const fallbackDepartments = [
      'CPDO',
      'GSO',
      'CBO',
      'CTO',
      'CACCO',
      'CADO',
      'CMO',
      'HR',
    ];
    const supportedDocumentTypes = [
      'Payroll',
      'Memo',
      'Travel Order',
      'Activity Design',
      'Purchase Request',
      'Purchase Order',
      'Advisory',
      'Announcement',
    ];

    String normalizeDocumentType(String value) {
      final normalized = value.trim().toLowerCase();
      if (normalized.isEmpty) return 'Payroll';
      if (supportedDocumentTypes.any((d) => d.toLowerCase() == normalized)) {
        return supportedDocumentTypes
            .firstWhere((d) => d.toLowerCase() == normalized);
      }

      if (normalized == 'travel') return 'Travel Order';
      if (normalized.contains('travel')) return 'Travel Order';
      if (normalized.contains('activity')) return 'Activity Design';
      if (normalized.contains('purchase request')) return 'Purchase Request';
      if (normalized.contains('purchase order')) return 'Purchase Order';
      if (normalized.contains('announcement')) return 'Announcement';
      if (normalized.contains('advisory')) return 'Advisory';
      if (normalized.contains('memo')) return 'Memo';

      // Keep legacy fallback behavior for unknown labels (e.g. General Document).
      return 'Payroll';
    }

    final flowStopwatch = Stopwatch()..start();

    try {
      // Get logged-in user's name from SharedPreferences
      final prefs = await SharedPreferences.getInstance();
      if (!mounted) return;

      String documentName = prefs.getString('user_name') ?? 'User';
      final String userDepartment =
          prefs.getString('user_department')?.toUpperCase() ?? '';

      // Auto-select AI-suggested document type if confidence is high enough
      String selectedDocumentType = 'Payroll';
      if (_aiClassification != null && _aiClassification!.confidence >= 0.2) {
        selectedDocumentType = _aiClassification!.type.displayName;
      }
      selectedDocumentType = normalizeDocumentType(selectedDocumentType);

      // ── Routing state (merged from _showUploadToTrackingModal) ──
      List<String> allDepartments = <String>[];
      try {
        allDepartments = await _fetchDepartmentsForMobile().timeout(
          const Duration(seconds: 5),
          onTimeout: () {
            debugPrint(
                '⚠️ Complete dialog: department fetch timed out after 5s, using fallback list');
            return <String>[];
          },
        );
      } catch (e) {
        debugPrint('⚠️ Complete dialog: department fetch failed: $e');
      }
      if (!mounted) return;

      if (allDepartments.isEmpty) {
        allDepartments = List<String>.from(fallbackDepartments);
      }
      final availableDepartments = allDepartments
          .where((d) => d.toUpperCase() != userDepartment)
          .toList();
      final departments = availableDepartments.isNotEmpty
          ? availableDepartments
          : allDepartments;

      String selectedDepartment =
          departments.isNotEmpty ? departments.first : '';
      String selectedEndLocation =
          departments.isNotEmpty ? departments.first : '';
      final bool isRerouteFlow =
          (_routingTrackingId?.trim().isNotEmpty ?? false) ||
              (_routingMobileTimestamp?.trim().isNotEmpty ?? false);
      final String? lockedEndLocation =
          isRerouteFlow ? await _resolveExistingEndLocationForRouting() : null;
      if (!mounted) return;

      if ((lockedEndLocation ?? '').trim().isNotEmpty) {
        selectedEndLocation = lockedEndLocation!.trim();
      }

      // Payroll fixed route
      final List<String> payrollRoute = [
        'HR',
        'CBO',
        'ACCOUNTING',
        'CAO',
        'CTO',
      ];
      bool useCustomRoute = false;

      // Multi-send state
      bool isMultiSendMode = false;
      Set<String> selectedDepartments = {};

      if (!mounted) return;
      debugPrint(
          '✅ Complete dialog: opening in ${flowStopwatch.elapsedMilliseconds}ms');
      try {
        showDialog(
          context: context,
          barrierDismissible: false,
          builder: (BuildContext dialogContext) {
            return StatefulBuilder(
              builder: (context, setState) {
                final dialogMedia = MediaQuery.of(context);
                final dialogMaxHeight = dialogMedia.size.height *
                    (dialogMedia.size.height < 700 ? 0.84 : 0.78);
                final dialogMaxWidth = dialogMedia.size.width < 390
                    ? dialogMedia.size.width * 0.96
                    : dialogMedia.size.width * 0.9;
                final multiSelectMaxHeight =
                    max(140.0, min(240.0, dialogMaxHeight * 0.35));

                // Derived routing flags based on current document type
                final bool supportsMultiSend =
                    ['Memo', 'Announcement'].contains(selectedDocumentType);
                final bool isPayrollFixedRoute =
                    selectedDocumentType.toLowerCase() == 'payroll';
                final int payrollUploaderIndex = payrollRoute
                    .indexWhere((d) => d.toUpperCase() == userDepartment);
                final String payrollFixedNextDepartment =
                    payrollUploaderIndex >= 0
                        ? (payrollUploaderIndex < payrollRoute.length - 1
                            ? payrollRoute[payrollUploaderIndex + 1]
                            : payrollRoute.last)
                        : payrollRoute.first;

                return AlertDialog(
                  scrollable: false,
                  insetPadding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 24),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(20),
                  ),
                  title: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: const Color(0xFF6868AC).withOpacity(0.1),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: const Icon(Icons.check_circle_outline,
                            color: Color(0xFF6868AC), size: 24),
                      ),
                      const SizedBox(width: 12),
                      const Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Complete & Route',
                                style: TextStyle(
                                    fontSize: 18, fontWeight: FontWeight.bold)),
                            Text('Save document and send to department',
                                style: TextStyle(
                                    fontSize: 12, color: Colors.grey)),
                          ],
                        ),
                      ),
                      // Close button
                      Material(
                        color: Colors.transparent,
                        child: InkWell(
                          onTap: () => Navigator.of(dialogContext).pop(),
                          borderRadius: BorderRadius.circular(20),
                          child: Container(
                            padding: const EdgeInsets.all(6),
                            decoration: BoxDecoration(
                              color: Colors.grey.shade200,
                              shape: BoxShape.circle,
                            ),
                            child: Icon(Icons.close,
                                size: 18, color: Colors.grey.shade600),
                          ),
                        ),
                      ),
                    ],
                  ),
                  content: ConstrainedBox(
                    constraints: BoxConstraints(
                      maxHeight: dialogMaxHeight,
                      maxWidth: dialogMaxWidth,
                    ),
                    child: SingleChildScrollView(
                      padding: EdgeInsets.zero,
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // ═══════════════════════════════════════════
                          // SECTION 1: Document Information
                          // ═══════════════════════════════════════════
                          _buildSectionHeader(
                            icon: Icons.description_outlined,
                            label: 'Document Info',
                            stepNumber: '1',
                          ),
                          const SizedBox(height: 10),

                          // AI Document Type Suggestion
                          if (_aiClassification != null &&
                              _aiClassification!.confidence >= 0.15)
                            Container(
                              margin: const EdgeInsets.only(bottom: 12),
                              padding: const EdgeInsets.all(10),
                              decoration: BoxDecoration(
                                color: _aiClassification!.confidenceColor
                                    .withOpacity(0.08),
                                borderRadius: BorderRadius.circular(8),
                                border: Border.all(
                                  color: _aiClassification!.confidenceColor
                                      .withOpacity(0.3),
                                ),
                              ),
                              child: Row(
                                children: [
                                  Icon(Icons.auto_awesome,
                                      color: _aiClassification!.confidenceColor,
                                      size: 18),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: Text(
                                      'AI: ${_aiClassification!.type.displayName} (${(_aiClassification!.confidence * 100).toStringAsFixed(0)}%)',
                                      style: TextStyle(
                                        fontSize: 12,
                                        fontWeight: FontWeight.w600,
                                        color:
                                            _aiClassification!.confidenceColor,
                                      ),
                                    ),
                                  ),
                                  if (selectedDocumentType !=
                                      normalizeDocumentType(
                                          _aiClassification!.type.displayName))
                                    GestureDetector(
                                      onTap: () {
                                        setState(() {
                                          selectedDocumentType =
                                              normalizeDocumentType(
                                                  _aiClassification!
                                                      .type.displayName);
                                        });
                                      },
                                      child: Container(
                                        padding: const EdgeInsets.symmetric(
                                            horizontal: 8, vertical: 3),
                                        decoration: BoxDecoration(
                                          color: _aiClassification!
                                              .confidenceColor
                                              .withOpacity(0.15),
                                          borderRadius:
                                              BorderRadius.circular(6),
                                        ),
                                        child: const Text('Apply',
                                            style: TextStyle(fontSize: 11)),
                                      ),
                                    ),
                                ],
                              ),
                            ),

                          // Extracted keys summary
                          _buildDialogExtractedKeys(),

                          // User name (readonly)
                          _buildFieldLabel('Scanned by'),
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 10),
                            decoration: BoxDecoration(
                              color: Colors.grey[100],
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Row(
                              children: [
                                Container(
                                  width: 28,
                                  height: 28,
                                  decoration: const BoxDecoration(
                                    color: Color(0xFF6868AC),
                                    shape: BoxShape.circle,
                                  ),
                                  child: Center(
                                    child: Text(
                                      documentName.isNotEmpty
                                          ? documentName[0].toUpperCase()
                                          : 'U',
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontWeight: FontWeight.bold,
                                        fontSize: 14,
                                      ),
                                    ),
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Text(documentName,
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w500)),
                              ],
                            ),
                          ),
                          const SizedBox(height: 12),

                          // Document type selector
                          _buildFieldLabel('Document type'),
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.symmetric(
                                horizontal: 12, vertical: 2),
                            decoration: BoxDecoration(
                              color: Colors.grey[100],
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: DropdownButtonHideUnderline(
                              child: DropdownButton<String>(
                                value: selectedDocumentType,
                                icon: const Icon(Icons.keyboard_arrow_down,
                                    color: Color(0xFF6868AC)),
                                isExpanded: true,
                                items:
                                    supportedDocumentTypes.map((String value) {
                                  return DropdownMenuItem<String>(
                                    value: value,
                                    child: Text(value),
                                  );
                                }).toList(),
                                onChanged: (String? newValue) {
                                  if (newValue != null) {
                                    setState(() {
                                      selectedDocumentType = newValue;
                                      final supportsMultiSendByType = [
                                        'Memo',
                                        'Announcement'
                                      ].contains(newValue);
                                      if (!supportsMultiSendByType &&
                                          isMultiSendMode) {
                                        isMultiSendMode = false;
                                        selectedDepartments.clear();
                                      }
                                    });
                                  }
                                },
                              ),
                            ),
                          ),

                          // ═══════════════════════════════════════════
                          // DIVIDER
                          // ═══════════════════════════════════════════
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 16),
                            child: Row(
                              children: [
                                Expanded(
                                  child: Divider(
                                      color: Colors.grey.shade300,
                                      thickness: 1),
                                ),
                                Padding(
                                  padding:
                                      const EdgeInsets.symmetric(horizontal: 8),
                                  child: Icon(Icons.arrow_downward,
                                      size: 16, color: Colors.grey.shade400),
                                ),
                                Expanded(
                                  child: Divider(
                                      color: Colors.grey.shade300,
                                      thickness: 1),
                                ),
                              ],
                            ),
                          ),

                          // ═══════════════════════════════════════════
                          // SECTION 2: Routing
                          // ═══════════════════════════════════════════
                          _buildSectionHeader(
                            icon: Icons.send_outlined,
                            label: 'Route to Department',
                            stepNumber: '2',
                          ),
                          const SizedBox(height: 10),

                          // ── Payroll fixed route ──
                          if (isPayrollFixedRoute) ...[
                            Container(
                              padding: const EdgeInsets.all(10),
                              decoration: BoxDecoration(
                                color: Colors.deepPurple.shade50,
                                borderRadius: BorderRadius.circular(10),
                                border: Border.all(
                                    color: Colors.deepPurple.shade200),
                              ),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Icon(Icons.route,
                                          color: Colors.deepPurple.shade700,
                                          size: 18),
                                      const SizedBox(width: 6),
                                      Text('Fixed Payroll Route',
                                          style: TextStyle(
                                              fontWeight: FontWeight.w700,
                                              color: Colors.deepPurple.shade800,
                                              fontSize: 13)),
                                    ],
                                  ),
                                  const SizedBox(height: 8),
                                  // Compact route display
                                  SingleChildScrollView(
                                    scrollDirection: Axis.horizontal,
                                    child: Row(
                                      children: [
                                        for (int i = 0;
                                            i < payrollRoute.length;
                                            i++) ...[
                                          Container(
                                            padding: const EdgeInsets.symmetric(
                                                horizontal: 8, vertical: 4),
                                            decoration: BoxDecoration(
                                              color: Colors.deepPurple.shade100,
                                              borderRadius:
                                                  BorderRadius.circular(6),
                                            ),
                                            child: Text(payrollRoute[i],
                                                style: TextStyle(
                                                    fontSize: 11,
                                                    fontWeight: FontWeight.w600,
                                                    color: Colors
                                                        .deepPurple.shade700)),
                                          ),
                                          if (i < payrollRoute.length - 1)
                                            Padding(
                                              padding:
                                                  const EdgeInsets.symmetric(
                                                      horizontal: 4),
                                              child: Icon(Icons.arrow_forward,
                                                  size: 14,
                                                  color: Colors
                                                      .deepPurple.shade300),
                                            ),
                                        ],
                                      ],
                                    ),
                                  ),
                                  const SizedBox(height: 8),
                                  Row(
                                    children: [
                                      SizedBox(
                                        height: 28,
                                        child: Switch(
                                          value: useCustomRoute,
                                          activeThumbColor:
                                              Colors.deepPurple.shade600,
                                          onChanged: (value) {
                                            setState(
                                                () => useCustomRoute = value);
                                          },
                                        ),
                                      ),
                                      const SizedBox(width: 4),
                                      Text('Use custom route',
                                          style: TextStyle(
                                              fontSize: 12,
                                              color:
                                                  Colors.deepPurple.shade600)),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 12),
                          ],

                          // ── Multi-send toggle (Memo/Announcement) ──
                          if (supportsMultiSend) ...[
                            Container(
                              padding: const EdgeInsets.all(10),
                              decoration: BoxDecoration(
                                color: Colors.orange.shade50,
                                borderRadius: BorderRadius.circular(8),
                                border:
                                    Border.all(color: Colors.orange.shade200),
                              ),
                              child: Row(
                                children: [
                                  Icon(Icons.send_to_mobile,
                                      color: Colors.orange.shade700, size: 18),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: Text(
                                      isMultiSendMode
                                          ? 'Multi-Send: Multiple departments'
                                          : 'Single department',
                                      style: TextStyle(
                                          fontSize: 12,
                                          color: Colors.orange.shade800),
                                    ),
                                  ),
                                  SizedBox(
                                    height: 28,
                                    child: Switch(
                                      value: isMultiSendMode,
                                      activeThumbColor: Colors.orange.shade700,
                                      onChanged: (val) {
                                        setState(() {
                                          isMultiSendMode = val;
                                          if (!val) selectedDepartments.clear();
                                        });
                                      },
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            const SizedBox(height: 12),
                          ],

                          // ── Multi-select department list ──
                          if (supportsMultiSend && isMultiSendMode) ...[
                            Container(
                              constraints: BoxConstraints(
                                  maxHeight: multiSelectMaxHeight),
                              decoration: BoxDecoration(
                                border:
                                    Border.all(color: Colors.orange.shade200),
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: SingleChildScrollView(
                                child: Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    CheckboxListTile(
                                      title: Text(
                                        selectedDepartments.length ==
                                                departments.length
                                            ? 'Deselect All'
                                            : 'Select All',
                                        style: const TextStyle(
                                            fontWeight: FontWeight.w600,
                                            fontSize: 13),
                                      ),
                                      value: selectedDepartments.length ==
                                          departments.length,
                                      activeColor: Colors.orange.shade700,
                                      dense: true,
                                      onChanged: (val) {
                                        setState(() {
                                          if (val == true) {
                                            selectedDepartments =
                                                Set<String>.from(departments);
                                          } else {
                                            selectedDepartments.clear();
                                          }
                                        });
                                      },
                                    ),
                                    const Divider(height: 1),
                                    ...departments.map((dept) {
                                      return CheckboxListTile(
                                        title: Text(dept,
                                            style:
                                                const TextStyle(fontSize: 13)),
                                        value:
                                            selectedDepartments.contains(dept),
                                        activeColor: Colors.orange.shade700,
                                        dense: true,
                                        onChanged: (val) {
                                          setState(() {
                                            if (val == true) {
                                              selectedDepartments.add(dept);
                                            } else {
                                              selectedDepartments.remove(dept);
                                            }
                                          });
                                        },
                                      );
                                    }),
                                  ],
                                ),
                              ),
                            ),
                            if (selectedDepartments.isNotEmpty)
                              Padding(
                                padding: const EdgeInsets.only(top: 6),
                                child: Text(
                                  'Selected: ${selectedDepartments.join(", ")}',
                                  style: TextStyle(
                                      fontSize: 11,
                                      color: Colors.grey.shade600),
                                ),
                              ),
                          ]

                          // ── Normal single-department routing ──
                          else if (!isPayrollFixedRoute || useCustomRoute) ...[
                            _buildFieldLabel('Next Department'),
                            Container(
                              width: double.infinity,
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 12),
                              decoration: BoxDecoration(
                                border: Border.all(
                                    color: const Color(0xFF6868AC)
                                        .withOpacity(0.25)),
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: DropdownButtonHideUnderline(
                                child: DropdownButton<String>(
                                  value:
                                      departments.contains(selectedDepartment)
                                          ? selectedDepartment
                                          : (departments.isNotEmpty
                                              ? departments.first
                                              : null),
                                  isExpanded: true,
                                  icon: const Icon(Icons.arrow_drop_down,
                                      color: Color(0xFF6868AC)),
                                  items: departments
                                      .map((d) => DropdownMenuItem(
                                          value: d, child: Text(d)))
                                      .toList(),
                                  onChanged: (val) {
                                    if (val != null) {
                                      setState(() => selectedDepartment = val);
                                    }
                                  },
                                ),
                              ),
                            ),
                            if (!isRerouteFlow) ...[
                              const SizedBox(height: 12),
                              _buildFieldLabel('End Location'),
                              Container(
                                width: double.infinity,
                                padding:
                                    const EdgeInsets.symmetric(horizontal: 12),
                                decoration: BoxDecoration(
                                  border: Border.all(
                                      color: const Color(0xFF6868AC)
                                          .withOpacity(0.25)),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: DropdownButtonHideUnderline(
                                  child: DropdownButton<String>(
                                    value: departments
                                            .contains(selectedEndLocation)
                                        ? selectedEndLocation
                                        : (departments.isNotEmpty
                                            ? departments.first
                                            : null),
                                    isExpanded: true,
                                    icon: const Icon(Icons.arrow_drop_down,
                                        color: Color(0xFF6868AC)),
                                    items: departments
                                        .map((d) => DropdownMenuItem(
                                            value: d, child: Text(d)))
                                        .toList(),
                                    onChanged: (val) {
                                      if (val != null) {
                                        setState(
                                            () => selectedEndLocation = val);
                                      }
                                    },
                                  ),
                                ),
                              ),
                            ],
                          ],
                        ],
                      ),
                    ),
                  ),
                  actions: [
                    // Retake button
                    TextButton.icon(
                      onPressed: () {
                        Navigator.of(dialogContext).pop();
                        // User goes back to camera to retake
                      },
                      icon: const Icon(Icons.camera_alt_outlined, size: 18),
                      label: const Text('Retake'),
                      style: TextButton.styleFrom(
                        foregroundColor: Colors.grey.shade600,
                      ),
                    ),
                    // Save & Upload button
                    ElevatedButton.icon(
                      onPressed: (supportsMultiSend &&
                              isMultiSendMode &&
                              selectedDepartments.isEmpty)
                          ? null
                          : () async {
                              Navigator.of(dialogContext).pop();
                              // Small delay to let dialog close
                              await Future.delayed(
                                  const Duration(milliseconds: 150));
                              if (!mounted) return;

                              // Save document then upload directly
                              await _saveDocumentAndUpload(
                                documentName: documentName,
                                documentType: selectedDocumentType,
                                selectedDepartment: selectedDepartment,
                                selectedEndLocation: selectedEndLocation,
                                isRerouteFlow: isRerouteFlow,
                                lockedEndLocation: lockedEndLocation,
                                isPayrollFixedRoute: isPayrollFixedRoute,
                                useCustomRoute: useCustomRoute,
                                payrollRoute: payrollRoute,
                                payrollFixedNextDepartment:
                                    payrollFixedNextDepartment,
                                supportsMultiSend: supportsMultiSend,
                                isMultiSendMode: isMultiSendMode,
                                selectedDepartments: selectedDepartments,
                              );
                            },
                      icon: const Icon(Icons.cloud_upload_outlined, size: 18),
                      label: const Text('Save & Upload'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF6868AC),
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10),
                        ),
                        padding: const EdgeInsets.symmetric(
                            horizontal: 16, vertical: 10),
                      ),
                    ),
                  ],
                );
              },
            );
          },
        );
      } catch (e) {
        debugPrint('❌ Complete dialog: failed to open: $e');
        if (mounted) {
          _showError('Failed to open Complete dialog. Please try again.');
        }
      }
    } catch (e) {
      debugPrint('❌ Complete flow failed before dialog open: $e');
      if (mounted) {
        _showError('Unable to prepare Complete dialog. Please try again.');
      }
    }
  }

  /// Helper: section header for combined dialog
  Widget _buildSectionHeader({
    required IconData icon,
    required String label,
    required String stepNumber,
  }) {
    return Row(
      children: [
        Container(
          width: 24,
          height: 24,
          decoration: BoxDecoration(
            color: const Color(0xFF6868AC),
            borderRadius: BorderRadius.circular(6),
          ),
          child: Center(
            child: Text(stepNumber,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 12,
                    fontWeight: FontWeight.bold)),
          ),
        ),
        const SizedBox(width: 8),
        Icon(icon, size: 18, color: const Color(0xFF6868AC)),
        const SizedBox(width: 6),
        Expanded(
          child: Text(label,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w700,
                  color: Color(0xFF6868AC))),
        ),
      ],
    );
  }

  Future<String?> _resolveExistingEndLocationForRouting() async {
    final trackingId = (_routingTrackingId ?? '').trim();
    final mobileTimestamp = (_routingMobileTimestamp ?? '').trim();
    final docHash = (_currentDocument?.docHash ?? '').trim();
    if (trackingId.isEmpty && mobileTimestamp.isEmpty && docHash.isEmpty) {
      return null;
    }

    try {
      final String trackingUrl = await _resolveServerPath(
        '/lib/OCR(UPDATED)/tracking.php',
        fallback:
            '${ServerService.defaultServerRoot}/lib/OCR(UPDATED)/tracking.php',
      );

      final int? tid = int.tryParse(trackingId);
      Uri uri;
      if (tid != null && tid > 0) {
        uri = Uri.parse(trackingUrl).replace(queryParameters: {
          'action': 'doc_detail',
          'id': tid.toString(),
        });
      } else {
        uri = Uri.parse(trackingUrl).replace(queryParameters: {
          'action': 'resolve_identity',
          if (mobileTimestamp.isNotEmpty) 'mobile_timestamp': mobileTimestamp,
          if (docHash.isNotEmpty) 'doc_hash': docHash,
        });
      }

      final resp = await http.get(uri).timeout(const Duration(seconds: 8));
      if (resp.statusCode >= 400 || resp.body.isEmpty) return null;

      final decoded = jsonDecode(resp.body);
      if (decoded is! Map) return null;

      final Map source =
          (decoded['doc'] is Map) ? (decoded['doc'] as Map) : decoded;
      final String resolved =
          (source['end_location'] ?? source['endLocation'] ?? '')
              .toString()
              .trim();
      return resolved.isNotEmpty ? resolved : null;
    } catch (_) {
      return null;
    }
  }

  /// Helper: field label
  Widget _buildFieldLabel(String text) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Text(text,
          style: TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: Colors.grey.shade700)),
    );
  }

  // Save document to gallery with custom name and document type
  Future<void> _saveDocumentToGallery(
      String documentName, String documentType) async {
    if (_currentDocument == null) return;

    try {
      setState(() {
        _isProcessing = true;
        _autoSaveMessage = 'Saving document to gallery...';
        _autoSaveInProgress = true;
      });

      // Get user department for department-specific storage
      final prefs = await SharedPreferences.getInstance();
      final String userDepartment =
          prefs.getString('user_department') ?? 'General';

      final Directory extDir = await getApplicationDocumentsDirectory();
      final String archivePath = '${extDir.path}/Archive/$userDepartment';
      await Directory(archivePath).create(recursive: true);

      // Generate timestamp for unique identification
      final String timestamp = DateTime.now().millisecondsSinceEpoch.toString();

      // Get the source image paths (page 1 uses filtered/cropped/original; additional pages are original scan outputs)
      final List<String> sourcePagePaths =
          _getPreviewPagePaths(_currentDocument!);

      // Save image files (page 1 keeps legacy name; extra pages get suffixes)
      final List<String> savedPagePaths = <String>[];
      final String firstImageFileName = 'IMG_$timestamp.jpg';
      final String firstImagePath = path.join(archivePath, firstImageFileName);
      await File(sourcePagePaths.first).copy(firstImagePath);
      savedPagePaths.add(firstImagePath);

      for (int i = 1; i < sourcePagePaths.length; i++) {
        final fileName = 'IMG_${timestamp}_p${i + 1}.jpg';
        final dst = path.join(archivePath, fileName);
        await File(sourcePagePaths[i]).copy(dst);
        savedPagePaths.add(dst);
      }

      // Save OCR text file with document metadata
      final String textFileName = 'OCR_$timestamp.txt';
      final String textPath = path.join(archivePath, textFileName);

      // Get additional user information from SharedPreferences (prefs already declared above)
      final userEmail = prefs.getString('user_email') ?? '';
      final userRole = prefs.getString('user_role') ?? '';

      final StringBuffer textContent = StringBuffer();
      textContent.writeln('Document Name: $documentName');
      textContent.writeln('Document Type: $documentType');
      textContent.writeln('Scanned By: $documentName');
      if (userEmail.isNotEmpty) textContent.writeln('User Email: $userEmail');
      if (userRole.isNotEmpty) textContent.writeln('User Role: $userRole');
      if (userDepartment.isNotEmpty) {
        textContent.writeln('Department: $userDepartment');
      }
      textContent.writeln('Scan Date: ${DateTime.now().toString()}');
      textContent.writeln(
          'Confidence: ${(_documentConfidence * 100).toStringAsFixed(1)}%');
      if (_documentTypes.isNotEmpty) {
        textContent.writeln('Detected Types: ${_documentTypes.join(', ')}');
      }
      textContent.writeln('');
      textContent.writeln('--- Extracted Text ---');
      final perPageTexts = _currentDocument!.pageTexts;
      if (perPageTexts.isNotEmpty) {
        for (int i = 0; i < perPageTexts.length; i++) {
          textContent.writeln('');
          textContent.writeln('--- Page ${i + 1} ---');
          final t = perPageTexts[i].trim();
          textContent.writeln(t.isNotEmpty ? t : 'No text detected in image');
        }
      } else {
        textContent.writeln(_recognizedText.isNotEmpty
            ? _recognizedText
            : 'No text detected in image');
      }

      if (_keyInformation.isNotEmpty) {
        textContent.writeln('');
        textContent.writeln('--- Key Information ---');
        for (final entry in _keyInformation.entries) {
          textContent.writeln('${entry.key}: ${entry.value}');
        }
      }

      await File(textPath).writeAsString(textContent.toString());

      // Generate PDF or Word file if selected as document type
      if (documentType == 'PDF') {
        await _generatePdfFile(timestamp, archivePath);
      } else if (documentType == 'Word') {
        await _generateWordFile(timestamp, archivePath);
      }

      setState(() {
        _isProcessing = false;
        _autoSaveInProgress = false;
      });

      // Auto-generate PDF alongside image/text to avoid extra prompts
      String? generatedPdfPath;
      try {
        if (savedPagePaths.length == 1) {
          generatedPdfPath = await convertImageToPdf(
            imagePath: savedPagePaths.first,
            customFileName: 'PDF_$timestamp',
            includeOcrText: true,
          );
        } else {
          generatedPdfPath = await _generateMultiPagePdf(
            pagePaths: savedPagePaths,
            customFileName: 'PDF_$timestamp',
            outputDirPath: archivePath,
          );
        }
        debugPrint(
            '✅ Auto PDF generated for $documentName at $generatedPdfPath');
      } catch (e) {
        debugPrint('⚠️ Auto PDF generation failed: $e');
      }

      // Show success message
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('✅ "$documentName" saved (image, text, pdf)'),
            backgroundColor: Colors.green,
            duration: const Duration(seconds: 2),
          ),
        );
      }

      // PDF preview step removed per client request (#4)
      // Upload-to-tracking modal also removed — routing handled in combined dialog

      debugPrint('✅ Document saved to gallery: $documentName ($documentType)');
      debugPrint('📁 Image: $firstImagePath');
      debugPrint('📄 Text: $textPath');
    } catch (e) {
      setState(() {
        _isProcessing = false;
        _autoSaveInProgress = false;
      });

      debugPrint('❌ Error saving document to gallery: $e');
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('❌ Failed to save document: $e'),
            backgroundColor: Colors.red,
            duration: const Duration(seconds: 3),
          ),
        );
      }
    }
  }

  /// Combined save-and-upload method called from the merged dialog.
  /// Saves the document to gallery, then uploads to tracking with
  /// the routing parameters already collected from the single dialog.
  Future<void> _saveDocumentAndUpload({
    required String documentName,
    required String documentType,
    required String selectedDepartment,
    required String selectedEndLocation,
    required bool isRerouteFlow,
    String? lockedEndLocation,
    required bool isPayrollFixedRoute,
    required bool useCustomRoute,
    required List<String> payrollRoute,
    required String payrollFixedNextDepartment,
    required bool supportsMultiSend,
    required bool isMultiSendMode,
    required Set<String> selectedDepartments,
  }) async {
    // Step 1: Save locally (uses existing save logic)
    await _saveDocumentToGallery(documentName, documentType);

    if (!mounted) return;

    // Step 2: Upload to admin tracking
    final timestamp = _currentDocument?.mobileTimestamp ?? '';
    final firstImagePath = _currentDocument?.imagePath ?? '';
    final textPath = firstImagePath.isNotEmpty
        ? firstImagePath.replaceFirst(
            RegExp(r'\.(jpg|jpeg|png)$', caseSensitive: false), '.txt')
        : '';
    String? generatedPdfPath;
    try {
      final pdfDir = await getApplicationDocumentsDirectory();
      final pdfFile = File('${pdfDir.path}/documents/$documentName.pdf');
      if (await pdfFile.exists()) generatedPdfPath = pdfFile.path;
    } catch (_) {}

    final uploadFilePath =
        (generatedPdfPath != null && generatedPdfPath.isNotEmpty)
            ? generatedPdfPath
            : firstImagePath;

    // Show uploading progress
    if (mounted) {
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
    }

    // Handle multi-send (Memo/Announcement)
    if (supportsMultiSend &&
        isMultiSendMode &&
        selectedDepartments.isNotEmpty) {
      if (documentType == 'Announcement') {
        int successCount = 0;
        int failCount = 0;
        for (final dept in selectedDepartments) {
          final ok = await _uploadToTrackingPhp(
            timestamp: '${timestamp}_$dept',
            documentName: documentName,
            documentType: documentType,
            uploadFilePath: uploadFilePath,
            textPath: textPath,
            nextDepartment: dept,
            endLocation: dept,
            trackingId: _routingTrackingId,
            isBroadcast: true,
          );
          if (ok) {
            successCount++;
          } else {
            failCount++;
          }
        }
        if (!mounted) return;
        if (failCount == 0) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content:
                  Text('✅ Announcement sent to $successCount department(s)!'),
              backgroundColor: Colors.green,
            ),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('⚠️ Sent to $successCount, failed: $failCount'),
              backgroundColor: Colors.orange,
            ),
          );
        }
      } else {
        // Memo: sequential routing
        final firstDept = selectedDepartments.first;
        final lastDept = selectedDepartments.last;
        final routingQueue = selectedDepartments.join(',');
        final ok = await _uploadToTrackingPhp(
          timestamp: timestamp,
          documentName: documentName,
          documentType: documentType,
          uploadFilePath: uploadFilePath,
          textPath: textPath,
          nextDepartment: firstDept,
          endLocation: lastDept,
          trackingId: _routingTrackingId,
          isBroadcast: false,
          routingQueue: routingQueue,
        );
        if (!mounted) return;
        if (ok) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                  '✅ Memo routed to ${selectedDepartments.length} department(s)! Starting with $firstDept'),
              backgroundColor: Colors.green,
            ),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('❌ Failed to send memo'),
              backgroundColor: Colors.red,
            ),
          );
        }
      }
    } else if (isPayrollFixedRoute && !useCustomRoute) {
      // Payroll fixed route
      final routingQueue = payrollRoute.join(',');
      final ok = await _uploadToTrackingPhp(
        timestamp: timestamp,
        documentName: documentName,
        documentType: documentType,
        uploadFilePath: uploadFilePath,
        textPath: textPath,
        nextDepartment: payrollFixedNextDepartment,
        endLocation: payrollRoute.last,
        trackingId: _routingTrackingId,
        isBroadcast: false,
        routingQueue: routingQueue,
      );
      if (!mounted) return;
      if (ok) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('✅ Payroll routed: ${payrollRoute.join(' → ')}'),
            backgroundColor: Colors.green,
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('❌ Failed to upload payroll'),
            backgroundColor: Colors.red,
          ),
        );
      }
    } else {
      // Normal single-send
      final String effectiveEndLocation =
          (isRerouteFlow && (lockedEndLocation ?? '').trim().isNotEmpty)
              ? lockedEndLocation!.trim()
              : selectedEndLocation;
      final ok = await _uploadToTrackingPhp(
        timestamp: timestamp,
        documentName: documentName,
        documentType: documentType,
        uploadFilePath: uploadFilePath,
        textPath: textPath,
        nextDepartment: selectedDepartment,
        endLocation: effectiveEndLocation,
        trackingId: _routingTrackingId,
      );
      if (!mounted) return;
      if (ok) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('✅ Document uploaded to tracking successfully!'),
            backgroundColor: Colors.green,
          ),
        );
      }
    }

    // Navigate home after upload
    await Future.delayed(const Duration(milliseconds: 500));
    if (mounted) {
      Navigator.of(context).pushNamedAndRemoveUntil('/home', (route) => false);
    }
  }

  // Enhanced PDF conversion function extracted from pdf.dart
  Future<String?> convertImageToPdf({
    required String imagePath,
    String? customFileName,
    bool includeOcrText = true,
    PdfPageFormat pageFormat = PdfPageFormat.a4,
  }) async {
    try {
      // Create PDF document
      final pdf = pw.Document();

      // Load image
      final imageFile = File(imagePath);
      if (!await imageFile.exists()) {
        throw Exception('Image file not found: $imagePath');
      }

      final imageBytes = await imageFile.readAsBytes();
      final image = pw.MemoryImage(imageBytes);

      // Determine OCR text for PDF: use the same recognized text shown in
      // the preview UI, with a friendly fallback.
      final String effectiveText = (_recognizedText.isNotEmpty &&
              _recognizedText != 'No text recognized yet')
          ? _recognizedText.trim()
          : 'No text recognized';

      // Debug: log a snippet of the text that will be embedded in the PDF
      try {
        final snippet = effectiveText.length > 200
            ? effectiveText.substring(0, 200)
            : effectiveText;
        debugPrint('[convertImageToPdf] effectiveText snippet: $snippet');
      } catch (_) {}

      // Add page with centered header, image, and text
      pdf.addPage(
        pw.Page(
          pageFormat: pageFormat,
          build: (pw.Context context) {
            return pw.Center(
              child: pw.ConstrainedBox(
                constraints: pw.BoxConstraints(
                  maxWidth: pageFormat.availableWidth * 0.8,
                ),
                child: pw.Column(
                  crossAxisAlignment: pw.CrossAxisAlignment.center,
                  children: [
                    // Header
                    pw.Container(
                      width: double.infinity,
                      padding: const pw.EdgeInsets.all(16),
                      decoration: pw.BoxDecoration(
                        color: PdfColor.fromHex('#EBF8FF'),
                        borderRadius: pw.BorderRadius.circular(8),
                      ),
                      child: pw.Column(
                        crossAxisAlignment: pw.CrossAxisAlignment.center,
                        children: [
                          pw.Text(
                            'Scanned Document',
                            textAlign: pw.TextAlign.center,
                            style: pw.TextStyle(
                              fontSize: 24,
                              fontWeight: pw.FontWeight.bold,
                              color: PdfColor.fromHex('#1E40AF'),
                            ),
                          ),
                          pw.SizedBox(height: 4),
                          pw.Text(
                            'Generated on ${DateTime.now().toString().split('.')[0]}',
                            textAlign: pw.TextAlign.center,
                            style: pw.TextStyle(
                              fontSize: 12,
                              color: PdfColor.fromHex('#6B7280'),
                            ),
                          ),
                          if (_averageConfidence > 0) ...[
                            pw.SizedBox(height: 4),
                            pw.Text(
                              'OCR Confidence: ${(_averageConfidence * 100).toStringAsFixed(1)}%',
                              textAlign: pw.TextAlign.center,
                              style: pw.TextStyle(
                                fontSize: 12,
                                color: PdfColor.fromHex('#15803D'),
                                fontWeight: pw.FontWeight.bold,
                              ),
                            ),
                          ],
                        ],
                      ),
                    ),

                    pw.SizedBox(height: 20),

                    // Image section (centered) with bounded height so text fits
                    pw.Container(
                      width: double.infinity,
                      decoration: pw.BoxDecoration(
                        border:
                            pw.Border.all(color: PdfColor.fromHex('#D1D5DB')),
                        borderRadius: pw.BorderRadius.circular(8),
                      ),
                      child: pw.SizedBox(
                        height: pageFormat.availableHeight * 0.45,
                        child: pw.FittedBox(
                          fit: pw.BoxFit.contain,
                          alignment: pw.Alignment.center,
                          child: pw.Image(image),
                        ),
                      ),
                    ),

                    // OCR Text section (always shown if includeOcrText is true)
                    if (includeOcrText) ...[
                      pw.SizedBox(height: 20),
                      pw.Container(
                        width: double.infinity,
                        padding: const pw.EdgeInsets.all(16),
                        decoration: pw.BoxDecoration(
                          color: PdfColor.fromHex('#F9FAFB'),
                          border:
                              pw.Border.all(color: PdfColor.fromHex('#D1D5DB')),
                          borderRadius: pw.BorderRadius.circular(8),
                        ),
                        child: pw.Column(
                          crossAxisAlignment: pw.CrossAxisAlignment.center,
                          children: [
                            pw.Text(
                              'Extracted Text Content',
                              textAlign: pw.TextAlign.center,
                              style: pw.TextStyle(
                                fontSize: 16,
                                fontWeight: pw.FontWeight.bold,
                                color: PdfColor.fromHex('#1F2937'),
                              ),
                            ),
                            pw.SizedBox(height: 12),
                            pw.Text(
                              _normalizeOcrText(effectiveText),
                              textAlign: pw.TextAlign.center,
                              style: pw.TextStyle(
                                fontSize: 11,
                                lineSpacing: 1.4,
                                color: PdfColor.fromHex('#374151'),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            );
          },
        ),
      );

      // Generate filename
      final timestamp = DateTime.now().millisecondsSinceEpoch.toString();
      final fileName = customFileName ?? 'ScannedDoc_$timestamp';

      // Get user department for department-specific storage
      final prefs = await SharedPreferences.getInstance();
      final String userDepartment =
          prefs.getString('user_department') ?? 'General';

      // Get storage directory
      final Directory extDir = await getApplicationDocumentsDirectory();
      final String archivePath = '${extDir.path}/Archive/$userDepartment';
      await Directory(archivePath).create(recursive: true);

      // Save PDF file
      final String pdfPath = path.join(archivePath, '$fileName.pdf');
      final pdfBytes = await pdf.save();
      await File(pdfPath).writeAsBytes(pdfBytes);

      debugPrint('✅ Enhanced PDF generated: $pdfPath');
      return pdfPath;
    } catch (e) {
      debugPrint('❌ Error converting image to PDF: $e');
      return null;
    }
  }

  Future<void> _showPdfPreviewDialog({
    required String pdfPath,
    required String documentName,
  }) async {
    // Collect current page texts to pass to preview for editing
    final List<String> currentPageTexts =
        _currentDocument?.pageTexts.isNotEmpty == true
            ? List<String>.from(_currentDocument!.pageTexts)
            : (_recognizedText.isNotEmpty ? [_recognizedText] : []);

    final result = await Navigator.of(context).push<dynamic>(
      MaterialPageRoute(
        builder: (_) => PdfPreviewPage(
          pdfPath: pdfPath,
          documentName: documentName,
          pageTexts: currentPageTexts,
        ),
      ),
    );

    // Handle edited OCR texts returned from preview
    if (result is Map && result['editedTexts'] != null && mounted) {
      final editedTexts = List<String>.from(result['editedTexts'] as List);
      setState(() {
        if (_currentDocument != null) {
          _currentDocument = _currentDocument!.copyWith(
            pageTexts: editedTexts,
            recognizedText: editedTexts.join('\n\n'),
          );
        }
        _recognizedText = editedTexts.join('\n\n');
      });
      debugPrint('[PdfPreview] OCR text updated by user from PDF preview');
    }
  }

  // Merge broken lines into paragraphs and keep blank lines as paragraph breaks
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

  // Generate PDF file for the document (supports multi-page documents)
  Future<void> _generatePdfFile(String timestamp, String archivePath) async {
    try {
      // Get all page paths for multi-page PDF (use filtered/cropped for page 1)
      final allPages = _getPreviewPagePaths(_currentDocument!);

      if (allPages.length == 1) {
        // Single page - use existing method
        final String sourceImagePath = _currentDocument!.filteredPath ??
            _currentDocument!.croppedPath ??
            _currentDocument!.imagePath;

        final pdfPath = await convertImageToPdf(
          imagePath: sourceImagePath,
          customFileName: 'PDF_$timestamp',
          includeOcrText: true,
        );

        if (pdfPath != null) {
          debugPrint('✅ PDF generated: $pdfPath');
        }
      } else {
        // Multi-page - generate PDF with all pages
        final pdfPath = await _generateMultiPagePdf(
          pagePaths: allPages,
          pageTexts: _currentDocument!.pageTexts,
          customFileName: 'PDF_$timestamp',
          outputDirPath: archivePath,
        );

        if (pdfPath != null) {
          debugPrint(
              '✅ Multi-page PDF generated (${allPages.length} pages): $pdfPath');
        }
      }
    } catch (e) {
      debugPrint('❌ Error generating PDF: $e');
    }
  }

  /// Generate a PDF containing multiple page images
  Future<String?> _generateMultiPagePdf({
    required List<String> pagePaths,
    List<String>? pageTexts,
    required String customFileName,
    String? outputDirPath,
  }) async {
    try {
      final pdf = pw.Document();

      for (int i = 0; i < pagePaths.length; i++) {
        final pagePath = pagePaths[i];
        final imageBytes = await File(pagePath).readAsBytes();
        final pdfImage = pw.MemoryImage(imageBytes);

        final String ocrText = (pageTexts != null && i < pageTexts.length)
            ? (pageTexts[i].trim().isNotEmpty
                ? pageTexts[i].trim()
                : 'No text detected in image')
            : 'No text detected in image';

        pdf.addPage(
          pw.Page(
            pageFormat: PdfPageFormat.a4,
            margin: const pw.EdgeInsets.all(20),
            build: (pw.Context context) {
              return pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                children: [
                  pw.Expanded(
                    child: pw.Center(
                      child: pw.Image(pdfImage, fit: pw.BoxFit.contain),
                    ),
                  ),
                  pw.SizedBox(height: 12),
                  pw.Container(
                    width: double.infinity,
                    padding: const pw.EdgeInsets.all(10),
                    decoration: pw.BoxDecoration(
                      color: PdfColor.fromHex('#F9FAFB'),
                      border: pw.Border.all(color: PdfColor.fromHex('#D1D5DB')),
                      borderRadius: pw.BorderRadius.circular(8),
                    ),
                    child: pw.Text(
                      _normalizeOcrText(ocrText),
                      style: pw.TextStyle(
                        fontSize: 10,
                        lineSpacing: 1.3,
                        color: PdfColor.fromHex('#374151'),
                      ),
                    ),
                  ),
                ],
              );
            },
          ),
        );
      }

      final Directory outputDir = outputDirPath != null
          ? Directory(outputDirPath)
          : Directory(
              '${(await getApplicationDocumentsDirectory()).path}/Documents',
            );
      await outputDir.create(recursive: true);

      final pdfPath = path.join(outputDir.path, '$customFileName.pdf');
      final file = File(pdfPath);
      await file.writeAsBytes(await pdf.save());

      return pdfPath;
    } catch (e) {
      debugPrint('❌ Multi-page PDF generation failed: $e');
      return null;
    }
  }

  // Show PDF conversion modal with enhanced design
  void _showPdfConversionModal(
      {required String imagePath, required String documentName}) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (BuildContext context) {
        final ValueNotifier<bool> isConverting = ValueNotifier<bool>(false);
        bool includeOcrText = true;
        String selectedFormat = 'A4';
        String customFileName = documentName;

        return StatefulBuilder(
          builder: (context, setModalState) {
            return Container(
              height: MediaQuery.of(context).size.height * 0.7,
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.only(
                  topLeft: Radius.circular(25),
                  topRight: Radius.circular(25),
                ),
              ),
              child: Column(
                children: [
                  // Handle bar
                  Container(
                    width: 40,
                    height: 4,
                    margin: const EdgeInsets.only(top: 12),
                    decoration: BoxDecoration(
                      color: Colors.grey[300],
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),

                  // Header
                  Container(
                    padding: const EdgeInsets.all(20),
                    child: Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              colors: [
                                Colors.red.shade400,
                                Colors.red.shade600
                              ],
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                            ),
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: const Icon(
                            Icons.picture_as_pdf,
                            color: Colors.white,
                            size: 24,
                          ),
                        ),
                        const SizedBox(width: 16),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                'Convert to PDF',
                                style: TextStyle(
                                  fontSize: 20,
                                  fontWeight: FontWeight.bold,
                                  color: Colors.black87,
                                ),
                              ),
                              Text(
                                'Create a professional PDF document',
                                style: TextStyle(
                                  fontSize: 14,
                                  color: Colors.grey[600],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),

                  Expanded(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          // Preview section
                          Container(
                            width: double.infinity,
                            height: 200,
                            decoration: BoxDecoration(
                              color: Colors.grey[100],
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: Colors.grey[300]!),
                            ),
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(12),
                              child: Image.file(
                                File(imagePath),
                                fit: BoxFit.cover,
                              ),
                            ),
                          ),

                          const SizedBox(height: 24),

                          // File name input
                          const Text(
                            'File Name',
                            style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w600,
                              color: Colors.black87,
                            ),
                          ),
                          const SizedBox(height: 8),
                          TextFormField(
                            initialValue: customFileName,
                            onChanged: (value) => customFileName = value,
                            decoration: InputDecoration(
                              hintText: 'Enter PDF file name',
                              prefixIcon: const Icon(Icons.edit,
                                  color: Color(0xFF6868AC)),
                              border: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(12),
                                borderSide:
                                    BorderSide(color: Colors.grey[300]!),
                              ),
                              focusedBorder: OutlineInputBorder(
                                borderRadius: BorderRadius.circular(12),
                                borderSide: const BorderSide(
                                    color: Color(0xFF6868AC), width: 2),
                              ),
                            ),
                          ),

                          const SizedBox(height: 24),

                          // Page format selection
                          const Text(
                            'Page Format',
                            style: TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w600,
                              color: Colors.black87,
                            ),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            children: ['A4', 'Letter', 'Legal'].map((format) {
                              final isSelected = selectedFormat == format;
                              return Expanded(
                                child: GestureDetector(
                                  onTap: () => setModalState(
                                      () => selectedFormat = format),
                                  child: Container(
                                    margin: const EdgeInsets.only(right: 8),
                                    padding: const EdgeInsets.symmetric(
                                        vertical: 12),
                                    decoration: BoxDecoration(
                                      color: isSelected
                                          ? const Color(0xFF6868AC)
                                          : Colors.grey[100],
                                      borderRadius: BorderRadius.circular(8),
                                      border: Border.all(
                                        color: isSelected
                                            ? const Color(0xFF6868AC)
                                            : Colors.grey[300]!,
                                      ),
                                    ),
                                    child: Text(
                                      format,
                                      textAlign: TextAlign.center,
                                      style: TextStyle(
                                        color: isSelected
                                            ? Colors.white
                                            : Colors.black87,
                                        fontWeight: isSelected
                                            ? FontWeight.w600
                                            : FontWeight.normal,
                                      ),
                                    ),
                                  ),
                                ),
                              );
                            }).toList(),
                          ),

                          const SizedBox(height: 24),

                          // Include OCR text option
                          Container(
                            padding: const EdgeInsets.all(16),
                            decoration: BoxDecoration(
                              color: const Color(0xFF6868AC).withOpacity(0.08),
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(
                                  color: const Color(0xFF6868AC)
                                      .withOpacity(0.25)),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.text_fields,
                                    color: Color(0xFF6868AC), size: 24),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      const Text(
                                        'Include OCR Text',
                                        style: TextStyle(
                                          fontSize: 16,
                                          fontWeight: FontWeight.w600,
                                          color: Colors.black87,
                                        ),
                                      ),
                                      Text(
                                        'Add extracted text below the image',
                                        style: TextStyle(
                                          fontSize: 12,
                                          color: Colors.grey[600],
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                Switch(
                                  value: includeOcrText,
                                  onChanged: (value) => setModalState(
                                      () => includeOcrText = value),
                                  thumbColor:
                                      WidgetStateProperty.resolveWith<Color?>(
                                          (states) {
                                    if (states.contains(WidgetState.selected)) {
                                      return const Color(0xFF6868AC);
                                    }
                                    return null; // default
                                  }),
                                ),
                              ],
                            ),
                          ),

                          const SizedBox(height: 32),
                        ],
                      ),
                    ),
                  ),

                  // Action buttons
                  Container(
                    padding: const EdgeInsets.all(20),
                    decoration: BoxDecoration(
                      color: Colors.grey[50],
                      borderRadius: const BorderRadius.only(
                        topLeft: Radius.circular(20),
                        topRight: Radius.circular(20),
                      ),
                    ),
                    child: Row(
                      children: [
                        Expanded(
                          child: OutlinedButton(
                            onPressed: isConverting.value
                                ? null
                                : () => Navigator.pop(context),
                            style: OutlinedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(vertical: 16),
                              side: BorderSide(color: Colors.grey[400]!),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                              ),
                            ),
                            child: const Text(
                              'Cancel',
                              style: TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.w600,
                                color: Colors.black54,
                              ),
                            ),
                          ),
                        ),
                        const SizedBox(width: 16),
                        Expanded(
                          flex: 2,
                          child: ElevatedButton(
                            onPressed: isConverting.value
                                ? null
                                : () async {
                                    setModalState(
                                        () => isConverting.value = true);

                                    // Get page format
                                    PdfPageFormat pageFormat;
                                    switch (selectedFormat) {
                                      case 'Letter':
                                        pageFormat = PdfPageFormat.letter;
                                        break;
                                      case 'Legal':
                                        pageFormat = PdfPageFormat.legal;
                                        break;
                                      default:
                                        pageFormat = PdfPageFormat.a4;
                                    }

                                    // Convert to PDF
                                    final pdfPath = await convertImageToPdf(
                                      imagePath: imagePath,
                                      customFileName: customFileName.isNotEmpty
                                          ? customFileName
                                          : documentName,
                                      includeOcrText: includeOcrText,
                                      pageFormat: pageFormat,
                                    );

                                    if (pdfPath != null) {
                                      Navigator.pop(context);
                                      _showPdfSuccessNotification(
                                          pdfPath, customFileName);
                                    } else {
                                      setModalState(
                                          () => isConverting.value = false);
                                      ScaffoldMessenger.of(context)
                                          .showSnackBar(
                                        const SnackBar(
                                          content: Text(
                                              '❌ Failed to convert to PDF'),
                                          backgroundColor: Colors.red,
                                        ),
                                      );
                                    }
                                  },
                            style: ElevatedButton.styleFrom(
                              backgroundColor: Colors.red,
                              padding: const EdgeInsets.symmetric(vertical: 16),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                              ),
                            ),
                            child: isConverting.value
                                ? const Row(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      SizedBox(
                                        width: 20,
                                        height: 20,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          valueColor:
                                              AlwaysStoppedAnimation<Color>(
                                                  Colors.white),
                                        ),
                                      ),
                                      SizedBox(width: 12),
                                      Text(
                                        'Converting...',
                                        style: TextStyle(
                                          fontSize: 16,
                                          fontWeight: FontWeight.w600,
                                          color: Colors.white,
                                        ),
                                      ),
                                    ],
                                  )
                                : const Row(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Icon(Icons.picture_as_pdf,
                                          color: Colors.white),
                                      SizedBox(width: 8),
                                      Text(
                                        'Convert to PDF',
                                        style: TextStyle(
                                          fontSize: 16,
                                          fontWeight: FontWeight.w600,
                                          color: Colors.white,
                                        ),
                                      ),
                                    ],
                                  ),
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
      },
    );
  }

  // Show enhanced success notification for PDF conversion
  void _showPdfSuccessNotification(String pdfPath, String fileName) {
    // Show animated success notification
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          contentPadding: EdgeInsets.zero,
          content: Container(
            width: 300,
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(20),
              gradient: LinearGradient(
                colors: [Colors.green.shade400, Colors.green.shade600],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Success icon with animation
                Container(
                  width: 80,
                  height: 80,
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.2),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.check_circle,
                    color: Colors.white,
                    size: 50,
                  ),
                ),

                const SizedBox(height: 20),

                const Text(
                  'PDF Created Successfully!',
                  style: TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                    color: Colors.white,
                  ),
                  textAlign: TextAlign.center,
                ),

                const SizedBox(height: 12),

                Text(
                  'Your document has been converted to PDF and saved to device storage.',
                  style: TextStyle(
                    fontSize: 14,
                    color: Colors.white.withOpacity(0.9),
                  ),
                  textAlign: TextAlign.center,
                ),

                const SizedBox(height: 8),

                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.white.withOpacity(0.2),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.folder, color: Colors.white, size: 16),
                      const SizedBox(width: 8),
                      Flexible(
                        child: Text(
                          fileName,
                          style: const TextStyle(
                            fontSize: 12,
                            color: Colors.white,
                            fontWeight: FontWeight.w500,
                          ),
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                    ],
                  ),
                ),

                const SizedBox(height: 24),

                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () {
                          Navigator.pop(context);
                          // Open file location or share
                          Share.shareXFiles([XFile(pdfPath)],
                              text: 'PDF Document: $fileName');
                        },
                        style: OutlinedButton.styleFrom(
                          side: const BorderSide(color: Colors.white, width: 2),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                        ),
                        child: const Text(
                          'Share',
                          style: TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: ElevatedButton(
                        onPressed: () => Navigator.pop(context),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.white,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                        ),
                        child: Text(
                          'Done',
                          style: TextStyle(
                            color: Colors.green.shade600,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );

    // Auto-dismiss after 5 seconds
    Timer(const Duration(seconds: 5), () {
      if (mounted && Navigator.canPop(context)) {
        Navigator.pop(context);
      }
    });

    // Also show a snackbar for quick feedback
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Row(
          children: [
            const Icon(Icons.picture_as_pdf, color: Colors.white),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Text(
                    'PDF Saved Successfully!',
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      color: Colors.white,
                    ),
                  ),
                  FutureBuilder<String>(
                    future: SharedPreferences.getInstance().then((prefs) =>
                        prefs.getString('user_department') ?? 'General'),
                    builder: (context, snapshot) {
                      final department = snapshot.data ?? 'General';
                      return Text(
                        'Saved to: Archive/$department/$fileName.pdf',
                        style: TextStyle(
                          fontSize: 12,
                          color: Colors.white.withOpacity(0.9),
                        ),
                      );
                    },
                  ),
                ],
              ),
            ),
          ],
        ),
        backgroundColor: Colors.green,
        duration: const Duration(seconds: 4),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        margin: const EdgeInsets.all(16),
        action: SnackBarAction(
          label: 'Share',
          textColor: Colors.white,
          onPressed: () => Share.shareXFiles([XFile(pdfPath)],
              text: 'PDF Document: $fileName'),
        ),
      ),
    );
  }

  // Generate Word file for the document
  Future<void> _generateWordFile(String timestamp, String archivePath) async {
    try {
      final String wordFileName = 'WORD_$timestamp.rtf';
      final String wordPath = path.join(archivePath, wordFileName);

      // Create RTF content (Rich Text Format - compatible with Word)
      final StringBuffer rtfContent = StringBuffer();
      rtfContent.writeln(r'{\rtf1\ansi\deff0');
      rtfContent.writeln(r'{\fonttbl{\f0 Times New Roman;}}');
      rtfContent.writeln(r'\f0\fs24');

      // Add title
      rtfContent.writeln(r'{\b\fs32 Scanned Document}\par\par');

      // Add metadata
      final prefs = await SharedPreferences.getInstance();
      final userName = prefs.getString('user_name') ?? 'User';
      rtfContent.writeln('Scanned by: $userName\\par');
      rtfContent.writeln('Scan Date: ${DateTime.now().toString()}\\par');
      rtfContent.writeln(
          'Confidence: ${(_documentConfidence * 100).toStringAsFixed(1)}%\\par\\par');

      // Add extracted text
      if (_recognizedText.isNotEmpty) {
        rtfContent.writeln(r'{\b Extracted Text:}\par');
        // Escape special RTF characters
        final escapedText = _recognizedText
            .replaceAll('\\', '\\\\')
            .replaceAll('{', r'\{')
            .replaceAll('}', r'\}')
            .replaceAll('\n', '\\par ');
        rtfContent.writeln('$escapedText\\par\\par');
      }

      // Add key information
      if (_keyInformation.isNotEmpty) {
        rtfContent.writeln(r'{\b Key Information:}\par');
        for (final entry in _keyInformation.entries) {
          rtfContent.writeln('${entry.key}: ${entry.value}\\par');
        }
      }

      rtfContent.writeln('}');

      // Save RTF file
      await File(wordPath).writeAsString(rtfContent.toString());

      debugPrint('✅ Word document generated: $wordPath');
    } catch (e) {
      debugPrint('❌ Error generating Word document: $e');
    }
  }

  // Sync document with admin tracking system
  Future<void> _syncDocumentWithAdmin({
    required String timestamp,
    required String documentName,
    required String documentType,
    required String textPath,
    required String imagePath,
    String? nextDepartment,
    String? endLocation,
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final userName = prefs.getString('user_name') ?? documentName;
      final userEmail = prefs.getString('user_email') ?? '';
      final userDepartment = prefs.getString('user_department') ?? 'General';

      // Read OCR content
      String ocrContent = '';
      final doc = _currentDocument;
      if (doc != null && doc.pageTexts.isNotEmpty) {
        ocrContent = doc.pageTexts.join('\n\n').trim();
      } else if (_recognizedText.isNotEmpty &&
          _recognizedText.trim() != 'No text recognized yet') {
        ocrContent = _recognizedText.trim();
      } else if (await File(textPath).exists()) {
        // Fallback: the on-device text file may include metadata; still better than empty.
        ocrContent = (await File(textPath).readAsString()).trim();
      }
      if (ocrContent.isEmpty) {
        ocrContent = 'No text recognized';
      }

      // Check if this is a payroll document and handle encryption
      final encryptionService = EncryptionService.instance;
      bool isPayrollDocument =
          encryptionService.isPayrollDocument(documentName, ocrContent);
      Uint8List? encryptedFileData;
      String? originalFileName;

      if (isPayrollDocument) {
        originalFileName = documentName;

        // Encrypt the image file
        final imageFile = File(imagePath);
        if (await imageFile.exists()) {
          final fileBytes = await imageFile.readAsBytes();
          encryptedFileData =
              await encryptionService.encryptPayrollDocument(fileBytes);

          // Update OCR content to indicate encryption
          ocrContent =
              encryptionService.addEncryptionMetadata(ocrContent, true);

          debugPrint(
              '🔒 Payroll document encrypted during upload: $documentName');
        }
      }

      // Get file size
      final imageFile = File(imagePath);
      final fileSize =
          await imageFile.exists() ? (await imageFile.stat()).size : 0;

      // Prepare document data for admin system (match gallery logic)
      final stableTimestamp = _currentDocument?.mobileTimestamp ?? timestamp;
      final canonicalHashSeed = _currentDocument?.docHash != null
          ? null
          : '$documentType|$userName|${DateTime.now().toString().split(' ')[0]}|${nextDepartment ?? userDepartment}|${nextDepartment ?? userDepartment}|${endLocation ?? 'Mobile App Archive'}';

      // Update document type if encrypted
      final finalDocumentType =
          isPayrollDocument ? 'Encrypted Payroll Document' : documentType;

      // Extract searchable keys from OCR content for database indexing
      // IMPORTANT: Tracking/archive currently search against `ocr_content`.
      // So we store the enriched searchable content into `ocr_content`.
      final originalOcrContent = ocrContent;
      final searchableContent =
          OcrTextProcessor.generateSearchableContent(originalOcrContent);
      final extractedKeys =
          OcrTextProcessor.extractSearchableKeys(originalOcrContent);
      debugPrint('[OCR Keys] Extracted: ${extractedKeys['keywords']}');

      final documentData = {
        // Try to align type with gallery behavior
        'type': finalDocumentType,
        'employee_name': userName,
        'date_submitted':
            DateTime.now().toString().split(' ')[0], // Format: YYYY-MM-DD
        'current_holder': nextDepartment ?? userDepartment,
        'end_location': endLocation ?? 'Mobile App Archive',
        'status': 'Pending',
        'department': nextDepartment ?? userDepartment,
        'file_type_icon': _getFileTypeFromDocumentType(documentType),
        'ocr_content': searchableContent,
        'searchable_content': searchableContent,
        'original_ocr_content': originalOcrContent,
        'ocr_keywords': (extractedKeys['keywords'] as List?)?.join(',') ?? '',
        'ocr_names': (extractedKeys['names'] as List?)?.join('|') ?? '',
        'ocr_document_type': extractedKeys['document_type'] ?? '',
        'mobile_timestamp': stableTimestamp,
        'doc_hash':
            _currentDocument?.docHash ?? _generateDocHash(canonicalHashSeed!),
        'file_size': fileSize.toString(),
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

      // Upload the captured file to the PHP archive so the admin dashboard
      // has a web-accessible encrypted file and store its relative path.
      final String? uploadedPath = await _uploadArchiveFile(
        filePath: imagePath,
        documentName: documentName,
        department: userDepartment,
        docType: documentType,
      );
      if (uploadedPath != null && uploadedPath.isNotEmpty) {
        documentData['file_url'] = uploadedPath;
      }

      // Resolve server base URL from saved detection, fallback to known IPs
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

      if (response.statusCode == 200) {
        final result = json.decode(response.body);
        if (result['success'] == true) {
          if (isPayrollDocument) {
            debugPrint(
                '🔒 Encrypted payroll document synced with admin system: $documentName');
          } else {
            debugPrint('✅ Document synced with admin system: $documentName');
          }
          // Route to selected department via Firebase if different from user's department
          final prefs = await SharedPreferences.getInstance();
          final fromDept = prefs.getString('user_department') ?? 'General';
          final fromUser = prefs.getString('user_name') ?? 'User';
          final toDept = (nextDepartment ?? fromDept);

          // Route in Firestore when department differs (kept behavior),
          // but create a department notification ALWAYS so receiver sees it in Recent Activity.
          if (toDept.isNotEmpty &&
              toDept.trim().toUpperCase() != fromDept.trim().toUpperCase()) {
            try {
              await RoutingService.createRoute(
                documentName: documentName,
                documentType: documentType,
                imagePath: imagePath,
                ocrContent: ocrContent,
                fromDepartment: fromDept,
                fromUser: fromUser,
                toDepartment: toDept,
                toUser: null,
              );
              debugPrint(
                  '✅ Document routed to department: $toDept via Firebase');
            } catch (e) {
              debugPrint('⚠️ Firestore route failed: $e');
            }
          }

          // Department notification (match gallery behavior): simple payload, single POST
          try {
            String? baseUrl2 = prefs.getString('detected_server_url');
            baseUrl2 ??= await ServerService.getServerUrl();
            final root2 = baseUrl2.replaceFirst(RegExp(r"/api/?$"), '');
            final url2 = '$root2/lib/OCR(UPDATED)/api/notifications.php';

            final deptTarget = toDept.trim();
            // Use the local captured image path as file_url so mobile can open it directly
            final String fileUrl = imagePath;
            final notificationPayload = <String, String>{
              'action': 'create',
              'type': 'mobile_message',
              'title': 'New Document from $fromUser',
              'content': '$finalDocumentType • $documentName',
              'department': fromDept,
              'recipient_department': deptTarget,
              'sender_username': fromUser,
              'file_url': fileUrl,
              // Add encryption metadata to notification
              if (isPayrollDocument) ...{
                'is_encrypted': 'true',
                'encryption_method': 'AES-256-CBC',
              },
            };
            debugPrint('[notify-dept] POST -> $url2');
            debugPrint('[notify-dept] Payload: $notificationPayload');
            final r = await http
                .post(Uri.parse(url2), body: notificationPayload)
                .timeout(const Duration(seconds: 10));
            if (r.statusCode == 200) {
              debugPrint('✅ Notification created successfully: ${r.body}');
            } else {
              debugPrint(
                  '⚠️ Notification creation failed: ${r.statusCode} - ${r.body}');
            }
          } catch (e) {
            debugPrint('notify error $e');
          }
        } else {
          debugPrint('❌ Failed to sync document: ${result['message']}');
        }
      } else {
        debugPrint('❌ HTTP Error syncing document: ${response.statusCode}');
        // Even if sync failed, still attempt to notify so receiver sees something
        try {
          final prefs = await SharedPreferences.getInstance();
          final fromDept = prefs.getString('user_department') ?? 'General';
          final fromUser = prefs.getString('user_name') ?? 'User';
          final toDept = (nextDepartment ?? fromDept);
          await _createDeptNotificationSimple(
            documentName: documentName,
            documentType: documentType,
            fromDept: fromDept,
            fromUser: fromUser,
            toDept: toDept,
            uploadedUrl: imagePath,
          );
        } catch (_) {}
      }
    } catch (e) {
      debugPrint('❌ Error syncing document with admin: $e');
      // Also attempt notification on unexpected error
      try {
        final prefs = await SharedPreferences.getInstance();
        final fromDept = prefs.getString('user_department') ?? 'General';
        final fromUser = prefs.getString('user_name') ?? 'User';
        final toDept = (nextDepartment ?? fromDept);
        await _createDeptNotificationSimple(
          documentName: documentName,
          documentType: documentType,
          fromDept: fromDept,
          fromUser: fromUser,
          toDept: toDept,
          uploadedUrl: imagePath,
        );
      } catch (_) {}
    }
  }

  // Minimal helper to create notification using same robust logic
  Future<void> _createDeptNotificationSimple({
    required String documentName,
    required String documentType,
    required String fromDept,
    required String fromUser,
    required String toDept,
    String uploadedUrl = '',
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      String? baseUrl = prefs.getString('detected_server_url');
      baseUrl ??= await ServerService.getServerUrl();
      final root = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
      final url = '$root/lib/OCR(UPDATED)/api/notifications.php';
      final payload = <String, String>{
        'action': 'create',
        'type': 'mobile_message',
        'title': 'New Document from $fromUser',
        'content': '$documentType • $documentName',
        'department': fromDept,
        'recipient_department': toDept.trim(),
        'sender_username': fromUser,
      };
      if (uploadedUrl.isNotEmpty) {
        payload['file_url'] = uploadedUrl;
      }
      final r = await http
          .post(Uri.parse(url), body: payload)
          .timeout(const Duration(seconds: 10));
      if (r.statusCode == 200) {
        debugPrint('✅ Notification created successfully: ${r.body}');
      } else {
        debugPrint(
            '⚠️ Notification creation failed: ${r.statusCode} - ${r.body}');
      }
    } catch (_) {}
  }

  // Get file type icon based on document type
  String _getFileTypeFromDocumentType(String documentType) {
    final type = documentType.toLowerCase();
    if (type.contains('pdf')) return 'pdf';
    if (type.contains('word')) return 'doc';
    if (type.contains('excel') || type.contains('spreadsheet')) return 'xls';
    if (type.contains('image') || type.contains('photo')) return 'jpg';
    return 'file';
  }

  // Batch upload multiple documents to server
  Future<void> _uploadBatchToServer() async {
    // Ensure we have documents to upload and that the server URL is correct
    if (_capturedDocuments.isEmpty) {
      debugPrint('❌ No documents to upload');
      return;
    }

    // Get user data from SharedPreferences
    final prefs = await SharedPreferences.getInstance();
    final userName = prefs.getString('user_name') ?? 'Unknown User';
    final userEmail = prefs.getString('user_email') ?? '';
    final userDepartment = prefs.getString('user_department') ?? 'General';

    // Use the existing tracking.php endpoint URL (derived from detected server if possible)
    final String uploadUrl = await _resolveServerPath(
      '/lib/OCR(UPDATED)/tracking.php',
      fallback:
          '${ServerService.defaultServerRoot}/lib/OCR(UPDATED)/tracking.php',
    );

    try {
      // Show loading indicator
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Row(
              children: [
                const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                      strokeWidth: 2, color: Colors.white),
                ),
                const SizedBox(width: 12),
                Text('Uploading ${_capturedDocuments.length} documents...'),
              ],
            ),
            duration: const Duration(seconds: 10),
          ),
        );
      }

      // 1. Create a MultipartRequest
      var request = http.MultipartRequest('POST', Uri.parse(uploadUrl));

      // 2. Add standard fields for tracking.php
      request.fields['type'] =
          'Batch Document Upload (${_capturedDocuments.length} files)';
      request.fields['employee'] = userName;
      request.fields['date'] =
          DateTime.now().toString().split(' ')[0]; // Format: YYYY-MM-DD
      request.fields['holder'] = userDepartment;
      request.fields['endLocation'] = 'Mobile App Archive';
      request.fields['status'] = 'Completed';
      request.fields['department'] = userDepartment;
      request.fields['fileTypeIcon'] = 'jpg'; // Default for batch uploads

      // 3. Loop through all captured documents and add them as files
      for (int i = 0; i < _capturedDocuments.length; i++) {
        DocumentData doc = _capturedDocuments[i];

        // Use the best available file path (filtered, cropped, or original)
        String filePath = doc.filteredPath ?? doc.croppedPath ?? doc.imagePath;

        // CORRECT for multiple files! The [] makes it an array
        request.files.add(
          await http.MultipartFile.fromPath(
            'document_files[]', // <--- FIX: The [] makes it an array
            filePath,
            filename: path.basename(filePath),
          ),
        );

        // Add document-specific data (OCR text and metadata)
        String ocrText = '';
        if (doc.pageTexts.isNotEmpty) {
          ocrText = doc.pageTexts.join('\n\n').trim();
        } else {
          ocrText = doc.recognizedText.trim();
        }

        // If OCR was never computed for this captured image, compute it now
        if (ocrText.isEmpty || ocrText == 'No text recognized yet') {
          try {
            final computed = await _performOcrOnPage(filePath);
            ocrText = computed.trim();
            doc = doc.copyWith(
              recognizedText: ocrText,
              pageTexts:
                  ocrText.isNotEmpty ? <String>[ocrText] : const <String>[],
            );
            _capturedDocuments[i] = doc;
          } catch (_) {
            // Keep empty; server will store blank/placeholder
          }
        }

        request.fields['ocr_content[$i]'] = ocrText;
        request.fields['document_id[$i]'] = doc.id;
        request.fields['confidence[$i]'] = doc.confidence.toString();
        request.fields['capture_time[$i]'] = doc.captureTime.toIso8601String();

        request.fields['mobile_timestamp[$i]'] = doc.mobileTimestamp;
        request.fields['doc_hash[$i]'] = doc.docHash;

        // Send per-page OCR for multi-page search support
        if (doc.pageTexts.isNotEmpty) {
          for (int pageIdx = 0; pageIdx < doc.pageTexts.length; pageIdx++) {
            request.fields['ocr_pages[$i][$pageIdx]'] =
                doc.pageTexts[pageIdx].trim();
          }
        } else if (ocrText.isNotEmpty) {
          request.fields['ocr_pages[$i][0]'] = ocrText;
        }
      }

      // Add batch metadata
      request.fields['batch_size'] = _capturedDocuments.length.toString();
      request.fields['batch_timestamp'] =
          DateTime.now().millisecondsSinceEpoch.toString();
      request.fields['user_email'] = userEmail;

      // 4. Send the request
      var response = await request.send();

      // 5. Check the response status
      if (response.statusCode == 200) {
        final responseBody = await response.stream.bytesToString();
        debugPrint('✅ Batch upload successful: $responseBody');

        // Create a single Recent Activity notification on the server so mobile can see it
        try {
          final title = 'Batch upload (${_capturedDocuments.length})';
          final content = 'Uploaded by $userName • $userDepartment';
          await _postRecentActivity(
            type: 'document_upload',
            title: title,
            content: content,
            userName: userName,
            department: userDepartment,
          );
        } catch (e) {
          debugPrint('⚠️ Failed to post recent activity: $e');
        }

        if (mounted) {
          ScaffoldMessenger.of(context).hideCurrentSnackBar();
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                  '✅ Uploaded ${_capturedDocuments.length} document(s) successfully'),
              backgroundColor: Colors.green,
            ),
          );
        }

        // Optional: Clear the batch list after successful upload
        // setState(() {
        //   _capturedDocuments.clear();
        // });
      } else {
        debugPrint('❌ Batch upload failed with status: ${response.statusCode}');
        final responseBody = await response.stream.bytesToString();
        debugPrint('Response body: $responseBody');

        if (mounted) {
          ScaffoldMessenger.of(context).hideCurrentSnackBar();
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content:
                  Text('❌ Upload failed with status: ${response.statusCode}'),
              backgroundColor: Colors.red,
            ),
          );
        }
      }
    } catch (e) {
      debugPrint('❌ Batch upload error: $e');

      if (mounted) {
        ScaffoldMessenger.of(context).hideCurrentSnackBar();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('❌ Upload error: $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  /// Optimized batch upload using chunked processing
  /// Handles 10-15+ documents efficiently with progress feedback
  /// Reduces memory usage and prevents mobile UI from freezing
  Future<void> _uploadBatchChunked({
    required List<DocumentData> documents,
    required String receiverDepartment,
    String? endLocation,
    int chunkSize = 3,
    void Function(int current, int total, String message)? onProgress,
  }) async {
    if (documents.isEmpty) return;

    final prefs = await SharedPreferences.getInstance();
    final userName = prefs.getString('user_name') ?? 'Unknown User';
    final userDepartment = prefs.getString('user_department') ?? 'General';

    final base = await _getServerBase();
    if (base == null) {
      _showError('Server URL not configured');
      return;
    }

    final batchId = 'BATCH_${DateTime.now().millisecondsSinceEpoch}';
    final total = documents.length;
    int successCount = 0;
    int errorCount = 0;

    setState(() {
      _autoSaveInProgress = true;
      _autoSaveMessage = 'Uploading $total documents...';
    });

    try {
      // Process in chunks to prevent memory issues
      for (int i = 0; i < total; i += chunkSize) {
        final chunkEnd = (i + chunkSize).clamp(0, total);
        final chunk = documents.sublist(i, chunkEnd);
        final chunkNum = (i / chunkSize).floor() + 1;
        final totalChunks = (total / chunkSize).ceil();

        onProgress?.call(
            i, total, 'Uploading chunk $chunkNum of $totalChunks...');

        setState(() {
          _autoSaveMessage = 'Uploading ${i + 1}-$chunkEnd of $total...';
        });

        // Upload this chunk
        final chunkSuccess = await _uploadDocumentChunk(
          chunk: chunk,
          batchId: batchId,
          senderName: userName,
          senderDepartment: userDepartment,
          receiverDepartment: receiverDepartment,
          endLocation: endLocation ?? receiverDepartment,
          startIndex: i,
          baseUrl: base,
        );

        successCount += chunkSuccess;
        errorCount += chunk.length - chunkSuccess;

        // Small delay between chunks to let UI update and reduce server load
        if (chunkEnd < total) {
          await Future.delayed(const Duration(milliseconds: 150));
        }
      }

      setState(() {
        _autoSaveInProgress = false;
      });

      if (mounted) {
        final allSuccess = errorCount == 0;
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              allSuccess
                  ? '✅ Uploaded $successCount documents successfully'
                  : '⚠️ Uploaded $successCount of $total ($errorCount failed)',
            ),
            backgroundColor: allSuccess ? Colors.green : Colors.orange,
          ),
        );
      }

      // Post batch activity notification
      try {
        await _postRecentActivity(
          type: 'batch_upload',
          title: 'Batch Upload ($successCount docs)',
          content: 'Uploaded by $userName to $receiverDepartment',
          userName: userName,
          department: userDepartment,
        );
      } catch (_) {}
    } catch (e) {
      setState(() {
        _autoSaveInProgress = false;
      });
      _showError('Batch upload failed: $e');
    }
  }

  /// Upload a single chunk of documents
  Future<int> _uploadDocumentChunk({
    required List<DocumentData> chunk,
    required String batchId,
    required String senderName,
    required String senderDepartment,
    required String receiverDepartment,
    required String endLocation,
    required int startIndex,
    required String baseUrl,
  }) async {
    int successCount = 0;

    try {
      final uri = Uri.parse('$baseUrl/lib/OCR(UPDATED)/api/batch_upload.php');
      final request = http.MultipartRequest('POST', uri);

      // Add batch metadata
      request.fields['action'] = 'upload_batch';
      request.fields['batch_id'] = batchId;
      request.fields['sender_name'] = senderName;
      request.fields['sender_department'] = senderDepartment;
      request.fields['receiver_department'] = receiverDepartment;
      request.fields['end_location'] = endLocation;
      request.fields['document_type'] = 'Scanned Document';

      // Add each document in chunk
      for (int i = 0; i < chunk.length; i++) {
        final doc = chunk[i];
        final filePath = doc.filteredPath ?? doc.croppedPath ?? doc.imagePath;

        // Add file
        request.files.add(
          await http.MultipartFile.fromPath(
            'documents[]',
            filePath,
            filename: path.basename(filePath),
          ),
        );

        // Add metadata
        final ocrText = doc.pageTexts.isNotEmpty
            ? doc.pageTexts.join('\n\n').trim()
            : doc.recognizedText.trim();

        request.fields['ocr_texts[$i]'] = ocrText;
        request.fields['mobile_timestamps[$i]'] = doc.mobileTimestamp;
        request.fields['doc_hashes[$i]'] = doc.docHash;
        request.fields['page_numbers[$i]'] = (startIndex + i + 1).toString();

        // Send per-page OCR for multi-page search support
        if (doc.pageTexts.isNotEmpty) {
          for (int pageIdx = 0; pageIdx < doc.pageTexts.length; pageIdx++) {
            request.fields['ocr_pages[$i][$pageIdx]'] =
                doc.pageTexts[pageIdx].trim();
          }
        } else if (doc.recognizedText.trim().isNotEmpty) {
          // Single page fallback
          request.fields['ocr_pages[$i][0]'] = doc.recognizedText.trim();
        }
      }

      // Send request with timeout
      final streamResponse = await request.send().timeout(
            Duration(seconds: 30 + (chunk.length * 5)),
          );

      if (streamResponse.statusCode == 200) {
        final responseBody = await streamResponse.stream.bytesToString();
        try {
          final result = json.decode(responseBody);
          successCount = result['success_count'] ?? chunk.length;
          debugPrint('✅ Chunk upload: $successCount/${chunk.length} succeeded');
        } catch (_) {
          // Assume all succeeded if we can't parse response
          successCount = chunk.length;
        }
      } else {
        debugPrint('❌ Chunk upload failed: ${streamResponse.statusCode}');
      }
    } catch (e) {
      debugPrint('❌ Chunk upload error: $e');
    }

    return successCount;
  }

  /// Upload documents for routing through multiple departments
  /// Ensures all attachments stay together and integrity is maintained
  Future<void> _uploadBatchForRouting({
    required List<DocumentData> documents,
    required List<Map<String, String>> route,
  }) async {
    if (documents.isEmpty || route.isEmpty) return;

    final firstDept = route.first;
    final lastDept = route.last;

    // Upload to first department with end_location set to last department
    await _uploadBatchChunked(
      documents: documents,
      receiverDepartment: firstDept['department'] ?? '',
      endLocation: lastDept['department'],
      onProgress: (current, total, message) {
        setState(() {
          _autoSaveMessage = message;
        });
      },
    );
  }

  // Show modal asking user if they want to upload to admin tracking
  Future<void> _showUploadToTrackingModal({
    required String documentName,
    required String documentType,
    required String timestamp,
    required String textPath,
    required String imagePath,
    String? pdfPath,
  }) async {
    // Get the user's current department to exclude from options
    final prefs = await SharedPreferences.getInstance();
    final userDepartment =
        prefs.getString('user_department')?.toUpperCase() ?? '';

    // All available departments (dynamic from server; fallback to defaults)
    var allDepartments = await _fetchDepartmentsForMobile();
    if (allDepartments.isEmpty) {
      allDepartments = [
        'CPDO',
        'GSO',
        'CBO',
        'CTO',
        'CACCO',
        'CADO',
        'CMO',
        'HR',
      ];
    }

    // Filter out user's own department from options
    final availableDepartments =
        allDepartments.where((d) => d.toUpperCase() != userDepartment).toList();

    // Check if document type supports multi-send (Memo, Announcement)
    final bool supportsMultiSend =
        ['Memo', 'Announcement'].contains(documentType);

    // Payroll uses a fixed route: HR → CBO → ACCOUNTING → CAO → CTO
    final bool isPayrollFixedRoute = documentType.toLowerCase() == 'payroll';
    final List<String> payrollRoute = ['HR', 'CBO', 'ACCOUNTING', 'CAO', 'CTO'];
    final int payrollUploaderIndex =
        payrollRoute.indexWhere((d) => d.toUpperCase() == userDepartment);
    final String payrollFixedNextDepartment = payrollUploaderIndex >= 0
        ? (payrollUploaderIndex < payrollRoute.length - 1
            ? payrollRoute[payrollUploaderIndex + 1]
            : payrollRoute.last)
        : payrollRoute.first;
    bool useCustomRoute = false; // only relevant if isPayrollFixedRoute

    // State variables for multi-send
    bool isMultiSendMode = false;
    Set<String> selectedDepartments = {};

    // Set default selection to first available department
    String selectedDepartment = availableDepartments.isNotEmpty
        ? availableDepartments.first
        : allDepartments.first;

    // Use filtered list for routing dropdown
    final departments =
        availableDepartments.isNotEmpty ? availableDepartments : allDepartments;

    return showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return StatefulBuilder(builder: (context, setState) {
          return AlertDialog(
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(20),
            ),
            title: const Row(
              children: [
                Icon(Icons.cloud_upload, color: Color(0xFF6868AC), size: 28),
                SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'Upload to Admin Tracking?',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
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
                    'Type: $documentType',
                    style: TextStyle(color: Colors.grey.shade600),
                  ),
                  const SizedBox(height: 16),

                  // ─── Payroll fixed route section ───
                  if (isPayrollFixedRoute) ...[
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.deepPurple.shade50,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: Colors.deepPurple.shade200),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Icon(Icons.route,
                                  color: Colors.deepPurple.shade700, size: 20),
                              const SizedBox(width: 8),
                              Text(
                                'Fixed Route',
                                style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  color: Colors.deepPurple.shade800,
                                  fontSize: 14,
                                ),
                              ),
                              const Spacer(),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 8, vertical: 2),
                                decoration: BoxDecoration(
                                  color: Colors.deepPurple.shade100,
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Text(
                                  'PAYROLL',
                                  style: TextStyle(
                                    fontSize: 9,
                                    fontWeight: FontWeight.w700,
                                    color: Colors.deepPurple.shade700,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 12),
                          // Route stepper visual
                          Row(
                            children: [
                              for (int i = 0; i < payrollRoute.length; i++) ...[
                                Expanded(
                                  child: Column(
                                    children: [
                                      Container(
                                        padding: const EdgeInsets.all(8),
                                        decoration: BoxDecoration(
                                          color: Colors.deepPurple.shade100,
                                          shape: BoxShape.circle,
                                        ),
                                        child: Text(
                                          '${i + 1}',
                                          style: TextStyle(
                                            fontSize: 12,
                                            fontWeight: FontWeight.w700,
                                            color: Colors.deepPurple.shade800,
                                          ),
                                        ),
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        payrollRoute[i],
                                        style: TextStyle(
                                          fontSize: 11,
                                          fontWeight: FontWeight.w600,
                                          color: Colors.deepPurple.shade700,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                if (i < payrollRoute.length - 1)
                                  Padding(
                                    padding: const EdgeInsets.only(bottom: 16),
                                    child: Icon(Icons.arrow_forward,
                                        size: 16,
                                        color: Colors.deepPurple.shade400),
                                  ),
                              ],
                            ],
                          ),
                          const SizedBox(height: 10),
                          Row(
                            children: [
                              Switch(
                                value: useCustomRoute,
                                activeThumbColor: Colors.deepPurple.shade600,
                                onChanged: (value) {
                                  setState(() => useCustomRoute = value);
                                },
                              ),
                              Text(
                                'Use custom route instead',
                                style: TextStyle(
                                  fontSize: 12,
                                  color: Colors.deepPurple.shade600,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],

                  // Multi-send toggle for Memo/Announcement
                  if (supportsMultiSend) ...[
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: Colors.orange.shade50,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: Colors.orange.shade200),
                      ),
                      child: Row(
                        children: [
                          Icon(Icons.send_to_mobile,
                              color: Colors.orange.shade700, size: 20),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              isMultiSendMode
                                  ? 'Multi-Send Mode: Send to multiple departments'
                                  : 'Normal Mode: Send to single department',
                              style: TextStyle(
                                  fontSize: 13, color: Colors.orange.shade800),
                            ),
                          ),
                          Switch(
                            value: isMultiSendMode,
                            activeThumbColor: Colors.orange.shade700,
                            onChanged: (val) {
                              setState(() {
                                isMultiSendMode = val;
                                if (!val) selectedDepartments.clear();
                              });
                            },
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],

                  // Multi-select departments (when multi-send is enabled)
                  if (supportsMultiSend && isMultiSendMode) ...[
                    Text('Select Departments to Send:',
                        style: TextStyle(
                            fontWeight: FontWeight.w600,
                            color: Colors.grey.shade800)),
                    const SizedBox(height: 8),
                    Container(
                      decoration: BoxDecoration(
                        border: Border.all(color: Colors.orange.shade200),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Column(
                        children: [
                          // Select All / Deselect All
                          CheckboxListTile(
                            title: Text(
                              selectedDepartments.length == departments.length
                                  ? 'Deselect All'
                                  : 'Select All',
                              style:
                                  const TextStyle(fontWeight: FontWeight.w600),
                            ),
                            value: selectedDepartments.length ==
                                departments.length,
                            activeColor: Colors.orange.shade700,
                            dense: true,
                            onChanged: (val) {
                              setState(() {
                                if (val == true) {
                                  selectedDepartments =
                                      Set<String>.from(departments);
                                } else {
                                  selectedDepartments.clear();
                                }
                              });
                            },
                          ),
                          const Divider(height: 1),
                          ...departments.map((dept) {
                            return CheckboxListTile(
                              title: Text(dept),
                              value: selectedDepartments.contains(dept),
                              activeColor: Colors.orange.shade700,
                              dense: true,
                              onChanged: (val) {
                                setState(() {
                                  if (val == true) {
                                    selectedDepartments.add(dept);
                                  } else {
                                    selectedDepartments.remove(dept);
                                  }
                                });
                              },
                            );
                          }),
                        ],
                      ),
                    ),
                    if (selectedDepartments.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Text(
                          'Selected: ${selectedDepartments.join(", ")}',
                          style: TextStyle(
                              fontSize: 12, color: Colors.grey.shade600),
                        ),
                      ),
                    const SizedBox(height: 16),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: const Color(0xFF6868AC).withOpacity(0.08),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                            color: const Color(0xFF6868AC).withOpacity(0.25)),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.info_outline,
                              color: Color(0xFF6868AC), size: 20),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              documentType == 'Announcement'
                                  ? 'Announcement multi-send is broadcast: each selected department receives its own copy and only needs to acknowledge (Received → Completed).'
                                  : 'Memo multi-send: each selected department receives its own copy of the memo.',
                              style: const TextStyle(fontSize: 13),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ] else if (!isPayrollFixedRoute || useCustomRoute) ...[
                    // Normal single-send mode (or custom payroll route)
                    // Next Department
                    Text('Next Department:',
                        style: TextStyle(
                            fontWeight: FontWeight.w600,
                            color: Colors.grey.shade800)),
                    const SizedBox(height: 8),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      decoration: BoxDecoration(
                        border: Border.all(
                            color: const Color(0xFF6868AC).withOpacity(0.25)),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          value: selectedDepartment,
                          isExpanded: true,
                          icon: const Icon(Icons.arrow_drop_down,
                              color: Color(0xFF6868AC)),
                          items: departments
                              .map((d) =>
                                  DropdownMenuItem(value: d, child: Text(d)))
                              .toList(),
                          onChanged: (val) {
                            if (val != null) {
                              setState(() {
                                selectedDepartment = val;
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
                        color: const Color(0xFF6868AC).withOpacity(0.08),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                            color: const Color(0xFF6868AC).withOpacity(0.25)),
                      ),
                      child: const Row(
                        children: [
                          Icon(Icons.info_outline,
                              color: Color(0xFF6868AC), size: 20),
                          SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'This will send your document to the admin tracking system for processing and approval.',
                              style: TextStyle(fontSize: 13),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
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
                onPressed: (supportsMultiSend &&
                        isMultiSendMode &&
                        selectedDepartments.isEmpty)
                    ? null // Disable if multi-send but no departments selected
                    : () async {
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

                        // Upload to admin tracking
                        final uploadFilePath =
                            (pdfPath != null && pdfPath.isNotEmpty)
                                ? pdfPath
                                : imagePath;

                        // Handle multi-select for Memo/Announcement
                        if (supportsMultiSend &&
                            isMultiSendMode &&
                            selectedDepartments.isNotEmpty) {
                          if (documentType == 'Announcement') {
                            // Announcement broadcast: Upload to each selected department
                            int successCount = 0;
                            int failCount = 0;

                            for (final dept in selectedDepartments) {
                              final ok = await _uploadToTrackingPhp(
                                timestamp:
                                    '${timestamp}_$dept', // Unique timestamp per dept
                                documentName: documentName,
                                documentType: documentType,
                                uploadFilePath: uploadFilePath,
                                textPath: textPath,
                                nextDepartment: dept,
                                endLocation:
                                    dept, // End location is same as target for broadcast
                                isBroadcast:
                                    true, // Mark as broadcast/multi-send
                              );
                              if (ok) {
                                successCount++;
                              } else {
                                failCount++;
                              }
                            }

                            if (!mounted) return;

                            if (failCount == 0) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(
                                      '✅ Announcement sent to $successCount department(s)!'),
                                  backgroundColor: Colors.green,
                                ),
                              );
                            } else {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(
                                      '⚠️ Sent to $successCount, failed: $failCount'),
                                  backgroundColor: Colors.orange,
                                ),
                              );
                            }
                          } else {
                            // Memo: sequential routing — send to FIRST department only,
                            // store the full ordered list as routing_queue so the system
                            // auto-advances after each department completes.
                            final firstDept = selectedDepartments.first;
                            final lastDept = selectedDepartments.last;
                            final routingQueue = selectedDepartments.join(',');

                            final ok = await _uploadToTrackingPhp(
                              timestamp: timestamp,
                              documentName: documentName,
                              documentType: documentType,
                              uploadFilePath: uploadFilePath,
                              textPath: textPath,
                              nextDepartment: firstDept,
                              endLocation: lastDept,
                              isBroadcast: false,
                              routingQueue: routingQueue,
                            );

                            if (!mounted) return;

                            if (ok) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(
                                      '✅ Memo routed sequentially to ${selectedDepartments.length} department(s)! Starting with $firstDept'),
                                  backgroundColor: Colors.green,
                                ),
                              );
                            } else {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(
                                  content: Text('❌ Failed to send memo'),
                                  backgroundColor: Colors.red,
                                ),
                              );
                            }
                          }

                          await Future.delayed(
                              const Duration(milliseconds: 500));
                          if (mounted) {
                            Navigator.of(context).pushNamedAndRemoveUntil(
                                '/home', (route) => false);
                          }
                        } else if (isPayrollFixedRoute && !useCustomRoute) {
                          // Payroll fixed route: HR → CBO → ACCOUNTING → CAO → CTO
                          final routingQueue = payrollRoute.join(',');
                          final ok = await _uploadToTrackingPhp(
                            timestamp: timestamp,
                            documentName: documentName,
                            documentType: documentType,
                            uploadFilePath: uploadFilePath,
                            textPath: textPath,
                            nextDepartment: payrollFixedNextDepartment,
                            endLocation: payrollRoute.last,
                            isBroadcast: false,
                            routingQueue: routingQueue,
                          );

                          if (!mounted) return;

                          if (ok) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(
                                    '✅ Payroll routed: ${payrollRoute.join(' → ')}'),
                                backgroundColor: Colors.green,
                              ),
                            );
                          } else {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                content: Text('❌ Failed to upload payroll'),
                                backgroundColor: Colors.red,
                              ),
                            );
                          }

                          await Future.delayed(
                              const Duration(milliseconds: 500));
                          if (mounted) {
                            Navigator.of(context).pushNamedAndRemoveUntil(
                                '/home', (route) => false);
                          }
                        } else {
                          // Normal single-send
                          final ok = await _uploadToTrackingPhp(
                            timestamp: timestamp,
                            documentName: documentName,
                            documentType: documentType,
                            uploadFilePath: uploadFilePath,
                            textPath: textPath,
                            nextDepartment: selectedDepartment,
                            endLocation: selectedDepartment,
                          );

                          if (!mounted) return;

                          if (ok) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                content: Text(
                                    '✅ Document uploaded to tracking successfully!'),
                                backgroundColor: Colors.green,
                              ),
                            );

                            await Future.delayed(
                                const Duration(milliseconds: 500));
                            if (mounted) {
                              Navigator.of(context).pushNamedAndRemoveUntil(
                                  '/home', (route) => false);
                            }
                          }
                        }
                      },
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF6868AC),
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(8),
                  ),
                ),
                child: const Text('Upload to Tracking'),
              ),
            ],
          );
        });
      },
    );
  }

  Future<bool> _uploadToTrackingPhp({
    required String timestamp,
    required String documentName,
    required String documentType,
    required String uploadFilePath,
    required String textPath,
    required String nextDepartment,
    required String endLocation,
    String? trackingId,
    bool isBroadcast = false,
    String? routingQueue,
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final userName = prefs.getString('user_name') ?? documentName;
      final userEmail = prefs.getString('user_email') ?? '';
      final userDepartment = prefs.getString('user_department') ?? 'General';

      // Read OCR content
      String ocrContent = '';
      if (await File(textPath).exists()) {
        ocrContent = await File(textPath).readAsString();
      }

      // Resolve server base URL
      final String uploadUrl = await _resolveServerPath(
        '/lib/OCR(UPDATED)/tracking.php',
        fallback:
            '${ServerService.defaultServerRoot}/lib/OCR(UPDATED)/tracking.php',
      );

      // Build request to the batch endpoint (single file = batch size 1)
      final req = http.MultipartRequest('POST', Uri.parse(uploadUrl));

      req.fields['type'] = documentType;
      req.fields['employee'] = userName;

      // Mark as broadcast/multi-send if applicable
      if (isBroadcast) {
        req.fields['is_broadcast'] = '1';
      }
      // Sequential routing queue for memos
      if (routingQueue != null && routingQueue.isNotEmpty) {
        req.fields['routing_queue'] = routingQueue;
      }
      req.fields['date'] = DateTime.now().toString().split(' ')[0];
      req.fields['holder'] = nextDepartment;
      req.fields['endLocation'] = endLocation;
      req.fields['status'] = 'Pending'; // server normalizes to Pending
      req.fields['department'] = userDepartment;
      req.fields['user_email'] = userEmail;

      // Doc-level metadata (arrays expected by tracking.php)
      final stableTimestamp = _currentDocument?.mobileTimestamp ?? timestamp;
      final docHash = _currentDocument?.docHash ??
          _generateDocHash(
            '$documentType|$userName|${DateTime.now().toString().split(' ')[0]}|$nextDepartment|$nextDepartment|$endLocation',
          );

      req.fields['ocr_content[0]'] = ocrContent;
      req.fields['document_id[0]'] = stableTimestamp;
      req.fields['confidence[0]'] = (_averageConfidence).toString();
      req.fields['capture_time[0]'] =
          (_currentDocument?.captureTime ?? DateTime.now()).toIso8601String();
      req.fields['mobile_timestamp[0]'] = stableTimestamp;
      req.fields['doc_hash[0]'] = docHash;
      req.fields['batch_size'] = '1';
      req.fields['batch_timestamp'] =
          DateTime.now().millisecondsSinceEpoch.toString();

      final String resolvedTrackingId = (trackingId ?? '').trim();
      if (resolvedTrackingId.isNotEmpty) {
        req.fields['tracking_id'] = resolvedTrackingId;
      }

      // Send per-page OCR for multi-page search support
      final pageTexts = _currentDocument?.pageTexts ?? [];
      if (pageTexts.isNotEmpty) {
        for (int pageIdx = 0; pageIdx < pageTexts.length; pageIdx++) {
          req.fields['ocr_pages[0][$pageIdx]'] = pageTexts[pageIdx].trim();
        }
      } else if (ocrContent.trim().isNotEmpty) {
        req.fields['ocr_pages[0][0]'] = ocrContent.trim();
      }

      // Attach file
      final file = File(uploadFilePath);
      if (!await file.exists()) {
        debugPrint('❌ Upload file does not exist: $uploadFilePath');
        return false;
      }

      req.files.add(
        await http.MultipartFile.fromPath(
          'document_files[]',
          uploadFilePath,
          filename: path.basename(uploadFilePath),
        ),
      );

      final streamed = await req.send().timeout(const Duration(seconds: 60));
      final body = await streamed.stream.bytesToString();

      if (streamed.statusCode != 200) {
        return false;
      }

      bool uploadSuccess = false;
      try {
        final decoded = json.decode(body);
        if (decoded is Map) {
          final statusRaw = (decoded['status'] ?? '').toString().trim();
          final successRaw = decoded['success'];
          final messageRaw =
              (decoded['message'] ?? decoded['msg'] ?? '').toString();

          final statusOk = statusRaw.toLowerCase() == 'success';
          final successOk = successRaw == true ||
              successRaw == 1 ||
              successRaw?.toString().toLowerCase() == 'true';
          final messageOk =
              RegExp(r'success|uploaded|saved', caseSensitive: false)
                  .hasMatch(messageRaw);

          uploadSuccess = statusOk || successOk || messageOk;
        }
      } catch (e) {
        debugPrint('⚠️ JSON decode failed: $e');
        // Some PHP responses can include notices before JSON.
        // Accept explicit success signatures in raw response text.
        final normalized = body.toLowerCase();
        uploadSuccess =
            RegExp(r'"status"\s*:\s*"success"').hasMatch(normalized) ||
                RegExp(r'"success"\s*:\s*(true|1)').hasMatch(normalized) ||
                normalized.contains('upload successful') ||
                normalized.contains('saved successfully');
      }

      if (!uploadSuccess && body.isNotEmpty) {
        final preview =
            body.length > 220 ? '${body.substring(0, 220)}...' : body;
        debugPrint(
            '⚠️ Upload returned 200 but was not recognized as success. Body: $preview');
      }

      // --- Routing & Notifications (regardless of upload result so receiver sees it) ---
      await _routeDocumentToDepartment(
        documentName: documentName,
        documentType: documentType,
        filePath: uploadFilePath,
        ocrContent: ocrContent,
        fromDept: userDepartment,
        fromUser: userName,
        toDept: nextDepartment,
        endLocation: endLocation,
      );

      if (!uploadSuccess) {
        final verified = await _verifyUploadPersisted(
          mobileTimestamp: stableTimestamp,
          docHash: docHash,
        );
        if (verified) {
          uploadSuccess = true;
          debugPrint(
              '✅ Upload verified via tracking identity lookup despite ambiguous response body.');
        }
      }

      return uploadSuccess;
    } catch (e) {
      debugPrint('❌ Upload exception: $e');
      return false;
    }
  }

  Future<bool> _verifyUploadPersisted({
    required String mobileTimestamp,
    required String docHash,
  }) async {
    try {
      final String trackingUrl = await _resolveServerPath(
        '/lib/OCR(UPDATED)/tracking.php',
        fallback:
            '${ServerService.defaultServerRoot}/lib/OCR(UPDATED)/tracking.php',
      );

      final uri = Uri.parse(trackingUrl).replace(queryParameters: {
        'action': 'resolve_identity',
        if (mobileTimestamp.trim().isNotEmpty)
          'mobile_timestamp': mobileTimestamp.trim(),
        if (docHash.trim().isNotEmpty) 'doc_hash': docHash.trim(),
      });

      final r = await http.get(uri).timeout(const Duration(seconds: 10));
      if (r.statusCode != 200 || r.body.isEmpty) return false;

      final decoded = json.decode(r.body);
      if (decoded is Map) {
        if (decoded['success'] == true) return true;
        final doc = decoded['doc'];
        if (doc is Map && doc.isNotEmpty) return true;
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  /// Routes document to the target department via Firestore and creates a notification.
  Future<void> _routeDocumentToDepartment({
    required String documentName,
    required String documentType,
    required String filePath,
    required String ocrContent,
    required String fromDept,
    required String fromUser,
    required String toDept,
    required String endLocation,
    void Function(String)? log,
  }) async {
    log ??= (_) {};

    // 1. Route via Firestore if departments differ
    if (toDept.isNotEmpty &&
        toDept.trim().toUpperCase() != fromDept.trim().toUpperCase()) {
      try {
        await RoutingService.createRoute(
          documentName: documentName,
          documentType: documentType,
          imagePath: filePath,
          ocrContent: ocrContent,
          fromDepartment: fromDept,
          fromUser: fromUser,
          toDepartment: toDept,
          toUser: null,
          endLocation: endLocation,
        );
        log('✅ Document routed to department: $toDept via Firebase');
      } catch (e) {
        log('⚠️ Firestore route failed: $e');
      }
    }

    // 2. Create PHP notification so the receiving department sees it in Recent Activity
    try {
      final prefs = await SharedPreferences.getInstance();
      String? baseUrl = prefs.getString('detected_server_url');
      baseUrl ??= await ServerService.getServerUrl();
      final root = baseUrl.replaceFirst(RegExp(r"/api/?$"), '');
      final url = '$root/lib/OCR(UPDATED)/api/notifications.php';

      final payload = <String, String>{
        'action': 'create',
        'type': 'mobile_message',
        'title': 'New Document from $fromUser',
        'content': '$documentType • $documentName',
        'department': fromDept,
        'recipient_department': toDept.trim(),
        'sender_username': fromUser,
        'file_url': filePath,
      };

      log('[notify-dept] POST -> $url');
      log('[notify-dept] Payload: $payload');

      final r = await http
          .post(Uri.parse(url), body: payload)
          .timeout(const Duration(seconds: 10));
      if (r.statusCode == 200) {
        log('✅ Notification created: ${r.body}');
      } else {
        log('⚠️ Notification failed: ${r.statusCode} - ${r.body}');
      }
    } catch (e) {
      log('⚠️ Notification error: $e');
    }
  }

  String _generateLayoutPreservedText(List<_TextElementWithPosition> elements) {
    if (elements.isEmpty) return 'No text recognized';

    final StringBuffer result = StringBuffer();
    double? previousY;
    double? previousX;
    double? previousRight;

    // Calculate average character width and line height for spacing
    double totalWidth = 0;
    double totalHeight = 0;
    int validElements = 0;

    for (var element in elements) {
      if (element.text.trim().isNotEmpty) {
        totalWidth += element.boundingBox.width;
        totalHeight += element.boundingBox.height;
        validElements++;
      }
    }

    double avgCharWidth =
        validElements > 0 ? (totalWidth / validElements) / 10 : 10;
    double avgLineHeight = validElements > 0 ? totalHeight / validElements : 20;

    for (int i = 0; i < elements.length; i++) {
      final element = elements[i];
      final currentY = element.boundingBox.top;
      final currentX = element.boundingBox.left;
      final currentRight = element.boundingBox.right;

      // Check if this is a new line
      bool isNewLine = false;
      if (previousY == null ||
          (currentY - previousY).abs() > avgLineHeight * 0.5) {
        isNewLine = true;
      }

      if (isNewLine) {
        // Add appropriate line breaks
        if (previousY != null) {
          // Calculate number of line breaks needed based on Y distance
          double yDistance = currentY - previousY;
          int lineBreaks = max(1, (yDistance / avgLineHeight).round());

          for (int j = 0; j < lineBreaks; j++) {
            result.writeln();
          }
        }

        // Add left margin/indentation based on X position
        if (i > 0) {
          // Find the leftmost X position to use as baseline
          double minX = elements.map((e) => e.boundingBox.left).reduce(min);
          double leftMargin = currentX - minX;
          int spaces = max(0, (leftMargin / avgCharWidth).round());
          result.write(' ' * spaces);
        }

        previousX = currentX;
      } else {
        // Same line - add horizontal spacing
        if (previousRight != null && previousX != null) {
          double horizontalGap = currentX - previousRight;

          // Add spaces based on the gap
          if (horizontalGap > avgCharWidth * 0.5) {
            int spaces = max(1, (horizontalGap / avgCharWidth).round());
            result.write(' ' * spaces);
          } else if (horizontalGap > 0) {
            result.write(' ');
          }
        }
      }

      // Add the actual text
      result.write(element.text);

      // Update tracking variables
      previousY = currentY;
      previousX = currentX;
      previousRight = currentRight;
    }

    return result.toString();
  }

  // Export to Word document with preserved formatting
  Future<void> _exportToWord() async {
    if (_recognizedText.isEmpty) return;

    setState(() {
      _autoSaveMessage = 'Creating Word Document...';
      _autoSaveInProgress = true;
    });

    try {
      // Get user department for department-specific storage
      final prefs = await SharedPreferences.getInstance();
      final String userDepartment =
          prefs.getString('user_department') ?? 'General';

      final Directory extDir = await getApplicationDocumentsDirectory();
      final String archivePath = '${extDir.path}/Archive/$userDepartment';
      await Directory(archivePath).create(recursive: true);
      final String timestamp = DateTime.now().millisecondsSinceEpoch.toString();

      // Create Word-compatible RTF document
      final String rtfPath = path.join(archivePath, 'WORD_$timestamp.rtf');
      final String docContent = _createRTFDocument(_recognizedText);

      await File(rtfPath).writeAsString(docContent);

      // Also save image and text files for complete gallery integration
      if (_lastCapturedImagePath != null) {
        final String archiveImagePath =
            path.join(archivePath, 'IMG_$timestamp.jpg');
        final String archiveTextPath =
            path.join(archivePath, 'OCR_$timestamp.txt');

        // Copy image
        await File(_lastCapturedImagePath!).copy(archiveImagePath);

        // Save enhanced text file
        final String ocrContent = '''
OCR SCAN RESULT (Word Export)
=============================
Document: DOC_$timestamp
Word Document: WORD_$timestamp.rtf
Capture Time: ${_captureTime?.toString() ?? DateTime.now().toString()}
Confidence Score: ${(_averageConfidence * 100).toStringAsFixed(1)}%

${_keyInformation.isNotEmpty ? '''
KEY INFORMATION EXTRACTED:
${_keyInformation.entries.map((e) => '${e.key}: ${e.value}').join('\n')}

''' : ''}--- EXTRACTED TEXT ---
$_recognizedText

--- END OF DOCUMENT ---
Generated by CamScanner App
${DateTime.now().toString()}
''';
        await File(archiveTextPath).writeAsString(ocrContent);
      }

      if (mounted) {
        setState(() {
          _autoSaveMessage = '✅ Word Document saved to Gallery';
          _showConfirmation = false;
        });
        await _showCheckAnimation();
        Future.delayed(const Duration(milliseconds: 1500), () {
          if (!mounted) return;
          setState(() => _autoSaveInProgress = false);
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _autoSaveMessage = 'Failed to create Word document: $e';
        });
        Future.delayed(const Duration(milliseconds: 1500), () {
          if (!mounted) return;
          setState(() => _autoSaveInProgress = false);
        });
      }
    }
  }

  String _createRTFDocument(String text) {
    // Create RTF (Rich Text Format) document - compatible with Word
    final StringBuffer rtf = StringBuffer();

    rtf.writeln(r'{\rtf1\ansi\deff0 {\fonttbl {\f0 Times New Roman;}}');
    rtf.writeln(r'\f0\fs24'); // Font and size

    // Document header
    rtf.writeln(r'{\b\fs28 CamScanner Document\par}');
    rtf.writeln(
        '\\fs20 Scanned: ${DateTime.now().toString().split('.')[0]}\\par');
    rtf.writeln(
        '\\fs20 Confidence: ${(_averageConfidence * 100).toStringAsFixed(1)}%\\par');
    rtf.writeln(r'\par'); // Empty line

    // Key information section
    if (_keyInformation.isNotEmpty) {
      rtf.writeln(r'{\b\fs22 Key Information:\par}');
      for (String key in _keyInformation.keys) {
        rtf.writeln(r'{\b ' + key + r':} ' + _keyInformation[key]! + r'\par');
      }
      rtf.writeln(r'\par');
    }

    // Main content
    rtf.writeln(r'{\b\fs22 Extracted Text:\par}');
    rtf.writeln(r'\par');

    // Convert text to RTF format
    String rtfText = text
        .replaceAll(r'\', r'\\')
        .replaceAll(r'{', r'\{')
        .replaceAll(r'}', r'\}')
        .replaceAll('\n', r'\par ');

    rtf.writeln(rtfText);
    rtf.writeln(r'\par');
    rtf.writeln(r'\par');
    rtf.writeln(r'{\i Generated by CamScanner App}');
    rtf.writeln('}');

    return rtf.toString();
  }

  void _toggleBatchMode() {
    setState(() {
      _isBatchMode = !_isBatchMode;
      _documentMode = _isBatchMode ? DocumentMode.batch : DocumentMode.single;
      if (!_isBatchMode) {
        _batchCount = 0;
      }
    });
  }

  void _retakePhoto() {
    setState(() {
      _lastCapturedImagePath = null;
      _currentDocument = null;
      _recognizedText = 'No text recognized yet';
      _textBlocks.clear();
      _textLines.clear();
      _textElements.clear();
      _averageConfidence = 0.0;
      _keyInformation.clear();
      _showResults = false;
      _showConfirmation = false;
      _showCropInterface = false;
      _showFilterInterface = false;
      _showTextView = true;
      _captureTime = null;
      _currentStage = ProcessingStage.capture;
    });
    _startScanner();
  }

  /// Show OCR Manual Correction Dialog
  Future<void> _showOcrCorrectionDialog() async {
    final currentText = _getOcrTextForPage(_resultsPageIndex);
    final controller = TextEditingController(text: currentText);

    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.edit_note, color: Color(0xFF6868AC)),
            SizedBox(width: 8),
            Text('Edit OCR Text'),
          ],
        ),
        content: SizedBox(
          width: double.maxFinite,
          height: 400,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Page ${_resultsPageIndex + 1} - Correct any OCR errors:',
                style: TextStyle(fontSize: 12, color: Colors.grey[600]),
              ),
              const SizedBox(height: 12),
              Expanded(
                child: TextField(
                  controller: controller,
                  maxLines: null,
                  expands: true,
                  textAlignVertical: TextAlignVertical.top,
                  decoration: InputDecoration(
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(8),
                    ),
                    hintText: 'Edit the recognized text here...',
                    contentPadding: const EdgeInsets.all(12),
                  ),
                  style: const TextStyle(fontSize: 14, height: 1.5),
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
          ElevatedButton.icon(
            onPressed: () => Navigator.pop(ctx, controller.text),
            icon: const Icon(Icons.save, size: 18),
            label: const Text('Save Changes'),
            style: ElevatedButton.styleFrom(
              backgroundColor: Colors.green.shade700,
              foregroundColor: Colors.white,
            ),
          ),
        ],
      ),
    );

    if (result == null || !mounted) return;

    // Update the OCR text locally
    setState(() {
      if (_currentDocument != null && _currentDocument!.pageTexts.isNotEmpty) {
        // Update specific page text
        final updatedPageTexts = List<String>.from(_currentDocument!.pageTexts);
        if (_resultsPageIndex < updatedPageTexts.length) {
          updatedPageTexts[_resultsPageIndex] = result;
        }
        _currentDocument = _currentDocument!.copyWith(
          pageTexts: updatedPageTexts,
          recognizedText: updatedPageTexts.join('\n\n'),
        );
        _recognizedText = updatedPageTexts.join('\n\n');
      } else {
        _recognizedText = result;
        if (_currentDocument != null) {
          _currentDocument = _currentDocument!.copyWith(
            recognizedText: result,
            pageTexts: [result],
          );
        }
      }
    });

    // Re-classify document type based on corrected text
    _classifyDocumentType(_recognizedText);

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('OCR text updated successfully'),
          backgroundColor: Colors.green,
        ),
      );
    }
  }

  Future<void> _saveAsImageWithText() async {
    // Save image and text separately for gallery compatibility
    if (_lastCapturedImagePath == null) return;
    try {
      setState(() {
        _autoSaveMessage = 'Saving to Gallery & PDF...';
        _autoSaveInProgress = true;
      });

      // Save to gallery
      await _saveToGallery();

      // Also auto-generate and save PDF version
      await _saveAsPdf();

      if (mounted) {
        setState(() {
          _autoSaveMessage = '✅ Saved to Gallery & PDF';
          _showConfirmation = false;
        });
        await _showCheckAnimation();
        Future.delayed(const Duration(milliseconds: 1500), () {
          if (!mounted) return;
          setState(() => _autoSaveInProgress = false);
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _autoSaveMessage = 'Failed to save: $e';
        });
        Future.delayed(const Duration(milliseconds: 1500), () {
          if (!mounted) return;
          setState(() => _autoSaveInProgress = false);
        });
      }
    }
  }

  Future<void> _saveAsPdf() async {
    if (_lastCapturedImagePath == null) return;
    try {
      setState(() {
        _autoSaveMessage = 'Generating PDF...';
        _autoSaveInProgress = true;
      });

      // Get user department for department-specific storage
      final prefs = await SharedPreferences.getInstance();
      final String userDepartment =
          prefs.getString('user_department') ?? 'General';

      final Directory extDir = await getApplicationDocumentsDirectory();
      final String archivePath = '${extDir.path}/Archive/$userDepartment';
      await Directory(archivePath).create(recursive: true);
      final String timestamp = DateTime.now().millisecondsSinceEpoch.toString();
      final String pdfPath = path.join(archivePath, 'PDF_$timestamp.pdf');
      final String imgPath = path.join(archivePath, 'IMG_$timestamp.jpg');
      final String txtPath = path.join(archivePath, 'OCR_$timestamp.txt');

      final pdf = pw.Document();
      final imageBytes = await File(_lastCapturedImagePath!).readAsBytes();
      final pdfImage = pw.MemoryImage(imageBytes);
      final decoded = img.decodeImage(imageBytes);
      final imgW = decoded?.width ?? 1000;
      final imgH = decoded?.height ?? 1400;

      // Reconstruct text preserving original line/block layout from OCR
      final reconstructed = _reconstructLayoutText().trim().isNotEmpty
          ? _reconstructLayoutText()
          : (_recognizedText.isNotEmpty
              ? _recognizedText
              : '(No text extracted)');

      const pageFormat = PdfPageFormat.a4;
      const margin = 20.0;
      final renderW = pageFormat.width - margin * 2;
      final renderH = renderW * imgH / imgW;

      pdf.addPage(
        pw.Page(
          pageFormat: pageFormat,
          margin: const pw.EdgeInsets.all(margin),
          build: (context) {
            return pw.Column(
              crossAxisAlignment: pw.CrossAxisAlignment.start,
              children: [
                pw.Center(
                  child: pw.Container(
                    width: renderW,
                    height: renderH,
                    child: pw.Stack(
                      children: [
                        pw.Positioned.fill(
                          child: pw.Image(pdfImage, fit: pw.BoxFit.contain),
                        ),
                        // Invisible text overlay for selectable/searchable content
                        ..._textElements.map((e) {
                          final rect = e.boundingBox;
                          final left = rect.left / imgW * renderW;
                          final top = rect.top / imgH * renderH;
                          final w = rect.width / imgW * renderW;
                          final h = rect.height / imgH * renderH;
                          return pw.Positioned(
                            left: left,
                            top: top,
                            child: pw.SizedBox(
                              width: w,
                              height: h,
                              child: pw.Opacity(
                                opacity: 0.0,
                                child: pw.Text(
                                  e.text,
                                  style: const pw.TextStyle(fontSize: 12),
                                ),
                              ),
                            ),
                          );
                        }),
                      ],
                    ),
                  ),
                ),
                // Visible extracted text removed per request; overlay remains invisible for search/select
              ],
            );
          },
        ),
      );

      // Save PDF
      final file = File(pdfPath);
      await file.writeAsBytes(await pdf.save());

      try {
        final prefs2 = await SharedPreferences.getInstance();
        final uname = prefs2.getString('user_name') ?? 'User';
        await _uploadArchiveFile(
          filePath: pdfPath,
          documentName: uname,
          department: userDepartment,
          docType:
              (_documentTypes.isNotEmpty ? _documentTypes.first : 'Document'),
        );
      } catch (_) {}

      // Also save image and text separately for gallery compatibility
      await File(_lastCapturedImagePath!).copy(imgPath);
      await File(txtPath).writeAsString('''
OCR SCAN RESULT
===============
Capture Time: ${_captureTime?.toString() ?? 'Unknown'}
Confidence Score: ${(_averageConfidence * 100).toStringAsFixed(1)}%
Blocks: ${_textBlocks.length}
Lines: ${_textLines.length}
Elements: ${_textElements.length}

--- EXTRACTED TEXT (LAYOUT PRESERVED) ---
$reconstructed
''');

      // Local notification to inform user
      NotificationService.showSimple(
        'PDF saved',
        'Saved PDF to Archive/$userDepartment',
      );

      if (mounted) {
        setState(() {
          _autoSaveMessage = '✅ PDF saved to department archive';
          _showConfirmation = false;
        });
        await _showCheckAnimation();
        Future.delayed(const Duration(milliseconds: 1500), () {
          if (!mounted) return;
          setState(() => _autoSaveInProgress = false);
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _autoSaveMessage = 'Failed to create PDF: $e';
        });
        Future.delayed(const Duration(milliseconds: 1500), () {
          if (!mounted) return;
          setState(() => _autoSaveInProgress = false);
        });
      }
    }
  }

  Future<void> _toggleCamera() async {
    if (_cameras!.length < 2) return;

    setState(() {
      _isBackCamera = !_isBackCamera;
    });

    await _controller.dispose();
    _initializeCamera();
  }

  Future<void> _toggleFlash() async {
    if (!_controller.value.isInitialized) return;

    setState(() {
      _isFlashOn = !_isFlashOn;
    });

    await _controller.setFlashMode(
      _isFlashOn ? FlashMode.torch : FlashMode.off,
    );
  }

  Future<String?> _pickImageFromGallery() async {
    try {
      final XFile? image = await _picker.pickImage(
        source: ImageSource.gallery,
        imageQuality: 85,
      );

      if (image != null) {
        // Copy the image to our app directory
        final Directory extDir = await getApplicationDocumentsDirectory();
        final String dirPath = '${extDir.path}/Pictures/flutter_camera';
        await Directory(dirPath).create(recursive: true);
        final String filePath = path.join(
            dirPath, 'gallery_${DateTime.now().millisecondsSinceEpoch}.jpg');

        await File(image.path).copy(filePath);
        return filePath;
      }
      return null;
    } catch (e) {
      debugPrint('Error picking image from gallery: $e');
      return null;
    }
  }

  void _processRecognizedTextEnhanced(RecognizedText recognizedText) {
    // Clear previous scan caches to avoid mixing old/new elements
    _textBlocks.clear();
    _textLines.clear();
    _textElements.clear();

    final List<_TextElementWithPosition> allElements = [];
    double totalConfidence = 0.0;
    int elementCount = 0;

    // Process blocks with enhanced spatial awareness for document layout preservation
    for (int blockIndex = 0;
        blockIndex < recognizedText.blocks.length;
        blockIndex++) {
      final TextBlock block = recognizedText.blocks[blockIndex];
      _textBlocks.add(block);

      // Sort lines by vertical position for proper reading order
      final List<TextLine> sortedLines = List.from(block.lines);
      sortedLines
          .sort((a, b) => a.boundingBox.top.compareTo(b.boundingBox.top));

      for (int lineIndex = 0; lineIndex < sortedLines.length; lineIndex++) {
        final TextLine line = sortedLines[lineIndex];
        _textLines.add(line);

        // Sort elements by horizontal position within each line
        final List<TextElement> sortedElements = List.from(line.elements);
        sortedElements
            .sort((a, b) => a.boundingBox.left.compareTo(b.boundingBox.left));

        for (int elementIndex = 0;
            elementIndex < sortedElements.length;
            elementIndex++) {
          final TextElement element = sortedElements[elementIndex];
          _textElements.add(element);

          // Track elements with precise position for layout preservation
          allElements.add(_TextElementWithPosition(
            text: element.text,
            boundingBox: element.boundingBox,
            confidence: element.confidence ?? 0.0,
            lineIndex: lineIndex,
            blockIndex: blockIndex,
          ));

          totalConfidence += element.confidence ?? 0.0;
          elementCount++;
        }
      }
    }

    // Calculate average confidence
    _averageConfidence =
        elementCount > 0 ? totalConfidence / elementCount : 0.0;

    // Create structured text that preserves document layout
    final structuredText = _createStructuredText(allElements).trim();
    final rawText = recognizedText.text.trim();

    // If layout reconstruction misses content, fall back to raw full text.
    final String chosen = (structuredText.isNotEmpty &&
            structuredText.length >= (rawText.length * 0.7).round())
        ? structuredText
        : (rawText.isNotEmpty ? rawText : structuredText);

    final enhanced = _enhanceTextFormatting(chosen);

    setState(() {
      _recognizedText =
          enhanced.isNotEmpty ? enhanced : 'No text detected in image';
    });

    // Extract key information with enhanced accuracy
    _extractKeyInformation(_recognizedText, allElements);
  }

  String _createStructuredText(List<_TextElementWithPosition> elements) {
    if (elements.isEmpty) return '';

    // Group elements by lines based on vertical position
    final Map<int, List<_TextElementWithPosition>> lineGroups = {};

    // Sort all elements by vertical position first
    elements.sort((a, b) => a.boundingBox.top.compareTo(b.boundingBox.top));

    // Calculate average element height for adaptive line grouping
    double totalHeight = 0;
    for (var e in elements) {
      totalHeight += e.boundingBox.height;
    }
    final avgHeight = elements.isNotEmpty ? totalHeight / elements.length : 20;
    // Use half of average height as line threshold (more accurate grouping)
    final lineThreshold = max(8.0, avgHeight * 0.6);

    // Group elements into lines with adaptive tolerance
    double currentLineY = elements[0].boundingBox.top;
    int currentLineIndex = 0;
    lineGroups[currentLineIndex] = [];

    for (var element in elements) {
      // If element is significantly below current line, start new line
      if (element.boundingBox.top > currentLineY + lineThreshold) {
        currentLineIndex++;
        currentLineY = element.boundingBox.top;
        lineGroups[currentLineIndex] = [];
      }
      lineGroups[currentLineIndex]!.add(element);
    }

    // Build text with proper spacing and alignment
    final StringBuffer result = StringBuffer();

    for (int lineIndex in lineGroups.keys) {
      final lineElements = lineGroups[lineIndex]!;

      // Sort elements in line by horizontal position
      lineElements
          .sort((a, b) => a.boundingBox.left.compareTo(b.boundingBox.left));

      final StringBuffer lineBuffer = StringBuffer();
      double lastRight = 0;

      for (int i = 0; i < lineElements.length; i++) {
        final element = lineElements[i];

        if (i > 0) {
          // Calculate spacing based on actual document layout
          final double spacing = element.boundingBox.left - lastRight;
          final prevLen = max(1, lineElements[i - 1].text.length);
          final double avgCharWidth =
              lineElements[i - 1].boundingBox.width / prevLen;

          // Prefer spaces over tabs for consistent rendering.
          if (spacing > avgCharWidth * 1.5) {
            lineBuffer.write('  ');
          } else if (spacing > avgCharWidth * 0.2) {
            lineBuffer.write(' ');
          }
        }

        lineBuffer.write(element.text);
        lastRight = element.boundingBox.right;
      }

      // Add line to result
      if (lineBuffer.isNotEmpty) {
        result.writeln(lineBuffer.toString());
      }
    }

    return result.toString().trim();
  }

  String _enhanceTextFormatting(String text) {
    // Remove excessive line breaks
    text = text.replaceAll(RegExp(r'\n{3,}'), '\n\n');

    // Fix common OCR errors - be more conservative to avoid false corrections
    // Only replace isolated single characters that are clearly wrong
    text = text.replaceAll(
        RegExp(r'(?<![A-Za-z])0(?![0-9])'), 'O'); // Isolated 0 to O
    text = text.replaceAll(
        RegExp(r'(?<![A-Za-z])l(?![a-z])'), 'I'); // Isolated l to I
    text = text.replaceAll(
        RegExp(r'\|(?=[A-Za-z])'), 'I'); // pipe before letter to I

    // Fix merged words: add space between lowercase followed by uppercase
    text = text.replaceAll(RegExp(r'([a-z])([A-Z])'), r'$1 $2');

    // Fix missing space after punctuation before capital letter
    text = text.replaceAll(RegExp(r'([.!?:;,])([A-Z])'), r'$1 $2');

    // Fix merged words with common patterns (government docs)
    text = text.replaceAll(RegExp(r'OFTHE', caseSensitive: false), 'OF THE');
    text = text.replaceAll(RegExp(r'ANDTHE', caseSensitive: false), 'AND THE');
    text = text.replaceAll(RegExp(r'FORTHE', caseSensitive: false), 'FOR THE');
    text = text.replaceAll(RegExp(r'TOTHE', caseSensitive: false), 'TO THE');
    text = text.replaceAll(RegExp(r'INTHE', caseSensitive: false), 'IN THE');
    text = text.replaceAll(RegExp(r'ONTHE', caseSensitive: false), 'ON THE');
    text = text.replaceAll(RegExp(r'ATTHE', caseSensitive: false), 'AT THE');
    text = text.replaceAll(RegExp(r'BYTHE', caseSensitive: false), 'BY THE');
    text =
        text.replaceAll(RegExp(r'WITHTHE', caseSensitive: false), 'WITH THE');
    text =
        text.replaceAll(RegExp(r'FROMTHE', caseSensitive: false), 'FROM THE');
    text = text.replaceAll(RegExp(r'CITYOF', caseSensitive: false), 'CITY OF');
    text =
        text.replaceAll(RegExp(r'OFFICEOF', caseSensitive: false), 'OFFICE OF');
    text = text.replaceAll(
        RegExp(r'DEPARTMENTOF', caseSensitive: false), 'DEPARTMENT OF');
    text = text.replaceAll(
        RegExp(r'REPUBLICOF', caseSensitive: false), 'REPUBLIC OF');

    // Fix common word boundaries but preserve newlines
    text = text.replaceAll(RegExp(r'[ \t]{2,}'), ' '); // collapse spaces/tabs

    return text.trim();
  }

  void _extractKeyInformation(
      String text, List<_TextElementWithPosition> elements) {
    _keyInformation.clear();

    // Enhanced pattern matching for key information
    final Map<String, RegExp> patterns = {
      'Email': RegExp(r'\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b'),
      'Phone': RegExp(
          r'(\+?1?[-.\s]?)?\(?([0-9]{3})\)?[-.\s]?([0-9]{3})[-.\s]?([0-9]{4})'),
      'Date': RegExp(
          r'\b\d{1,2}[/-]\d{1,2}[/-]\d{2,4}\b|\b\d{4}[/-]\d{1,2}[/-]\d{1,2}\b'),
      'Amount': RegExp(
          r'\$\s*\d{1,3}(?:,\d{3})*(?:\.\d{2})?|\b\d{1,3}(?:,\d{3})*(?:\.\d{2})?\s*(?:USD|dollars?)\b'),
      'ID Number': RegExp(r'\b\d{3}-?\d{2}-?\d{4}\b|\b[A-Z]{2}\d{6,8}\b'),
      'Address': RegExp(
          r'\d+\s+[A-Za-z\s]+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Lane|Ln|Drive|Dr)'),
    };

    for (String key in patterns.keys) {
      final matches = patterns[key]!.allMatches(text);
      if (matches.isNotEmpty) {
        _keyInformation[key] = matches.map((m) => m.group(0)!).join(', ');
      }
    }
  }

  void _shareText(String text) {
    Share.share(text, subject: 'OCR Extracted Text');
  }

  void _navigateToGallery() {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const GalleryPage()),
    );
  }

  void _goHome() {
    Navigator.of(context).pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const DashboardPage()),
      (route) => false,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      // Light background so there’s no black screen behind the scanner
      backgroundColor: Colors.white,
      appBar: null,
      body: _buildBody(),
    );
  }

  PreferredSizeWidget _buildAppBar() {
    return AppBar(
      backgroundColor: Colors.black,
      elevation: 0,
      leading: IconButton(
        icon: const Icon(Icons.close, color: Colors.white),
        onPressed: () => Navigator.pop(context),
      ),
      title: Text(
        _isBatchMode ? 'Batch' : 'Single',
        style: const TextStyle(color: Colors.white, fontSize: 18),
      ),
      centerTitle: true,
      actions: [
        IconButton(
          icon: Icon(
            _isFlashOn ? Icons.flash_on : Icons.flash_off,
            color: Colors.white,
          ),
          onPressed: _toggleFlash,
        ),
        if (_cameras != null && _cameras!.length > 1)
          IconButton(
            icon: const Icon(Icons.cameraswitch, color: Colors.white),
            onPressed: _toggleCamera,
          ),
        IconButton(
          icon: const Icon(Icons.more_vert, color: Colors.white),
          onPressed: () => _showOptionsMenu(),
        ),
      ],
    );
  }

  Widget _buildBody() {
    if (_showCropInterface) {
      return _buildCropInterface();
    } else if (_showFilterInterface) {
      return _buildFilterInterface();
    } else if (_showResults) {
      return _buildResultsInterface();
    } else {
      return _buildCameraInterface();
    }
  }

  Widget _buildCameraInterface() {
    return Stack(
      children: [
        // Camera Preview
        // Remove camera preview to avoid the black screen; rely on scanner UI only
        const SizedBox.shrink(),

        // Processing overlay
        if (_isProcessing) _buildProcessingOverlay(),
      ],
    );
  }

  Widget _buildCropInterface() {
    return LayoutBuilder(
      builder: (context, constraints) {
        final Size area = Size(constraints.maxWidth, constraints.maxHeight);
        _lastCropAreaSize = area;
        final double leftPx = _cropLeft * area.width;
        final double topPx = _cropTop * area.height;
        final double widthPx = _cropWidth * area.width;
        final double heightPx = _cropHeight * area.height;

        return Stack(
          children: [
            // Document image
            if (_currentDocument != null)
              Positioned.fill(
                child: Image.file(
                  File(_currentDocument!.imagePath),
                  fit: BoxFit.contain,
                ),
              ),

            // Dark overlay with transparent hole for crop area (simple: draw border only)
            Positioned(
              left: leftPx,
              top: topPx,
              width: widthPx,
              height: heightPx,
              child: GestureDetector(
                onPanUpdate: (d) => _moveCropBox(d.delta, area),
                child: Container(
                  decoration: BoxDecoration(
                    border:
                        Border.all(color: const Color(0xFF6868AC), width: 3),
                    color: const Color(0xFF6868AC).withOpacity(0.08),
                  ),
                ),
              ),
            ),

            // Corner handles (NW, NE, SW, SE)
            ...[
              // NW
              Positioned(
                left: leftPx - 12,
                top: topPx - 12,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dLeft: dx, dTop: dy);
                }),
              ),
              // NE
              Positioned(
                left: leftPx + widthPx - 12,
                top: topPx - 12,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dRight: dx, dTop: dy);
                }),
              ),
              // SW
              Positioned(
                left: leftPx - 12,
                top: topPx + heightPx - 12,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dLeft: dx, dBottom: dy);
                }),
              ),
              // SE
              Positioned(
                left: leftPx + widthPx - 12,
                top: topPx + heightPx - 12,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dRight: dx, dBottom: dy);
                }),
              ),
            ],

            // Side handles (N, S, W, E)
            ...[
              // N
              Positioned(
                left: leftPx + widthPx / 2 - 10,
                top: topPx - 10,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dTop: dy);
                }),
              ),
              // S
              Positioned(
                left: leftPx + widthPx / 2 - 10,
                top: topPx + heightPx - 10,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dBottom: dy);
                }),
              ),
              // W
              Positioned(
                left: leftPx - 10,
                top: topPx + heightPx / 2 - 10,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dLeft: dx);
                }),
              ),
              // E
              Positioned(
                left: leftPx + widthPx - 10,
                top: topPx + heightPx / 2 - 10,
                child: _buildHandle(onDrag: (dx, dy) {
                  _resizeCropBox(size: area, dRight: dx);
                }),
              ),
            ],

            // Instructions
            // Bottom controls
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: Container(
                height: 100,
                color: Colors.black,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        IconButton(
                          onPressed: _retakePhoto,
                          icon: const Icon(Icons.refresh,
                              color: Colors.white, size: 30),
                        ),
                        const Text('Retake',
                            style:
                                TextStyle(color: Colors.white, fontSize: 12)),
                      ],
                    ),
                    Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        IconButton(
                          onPressed: _cropDocument,
                          icon: const Icon(Icons.check,
                              color: Colors.green, size: 40),
                        ),
                        const Text('Crop',
                            style:
                                TextStyle(color: Colors.white, fontSize: 12)),
                      ],
                    ),
                  ],
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Widget _buildHandle({required void Function(double dx, double dy) onDrag}) {
    return GestureDetector(
      onPanUpdate: (d) => onDrag(d.delta.dx, d.delta.dy),
      child: Container(
        width: 20,
        height: 20,
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: const Color(0xFF6868AC), width: 2),
          borderRadius: BorderRadius.circular(4),
          boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 2)],
        ),
      ),
    );
  }

  Widget _buildFilterInterface() {
    return Stack(
      children: [
        // Document image with current filter
        if (_currentDocument != null)
          Positioned.fill(
            child: Image.file(
              File(
                  _currentDocument!.croppedPath ?? _currentDocument!.imagePath),
              fit: BoxFit.contain,
            ),
          ),

        // Filter options
        Positioned(
          bottom: 0,
          left: 0,
          right: 0,
          child: Container(
            height: 150,
            color: Colors.black,
            child: Column(
              children: [
                // Filter buttons
                // Removed 'Original' label/button section after cropping
                // Action buttons
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    IconButton(
                      onPressed: () => _applyFilter(_selectedFilter),
                      icon: const Icon(Icons.check,
                          color: Colors.green, size: 40),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildResultsInterface() {
    return Container(
      color: Theme.of(context).scaffoldBackgroundColor,
      child: Column(
        children: [
          // Main content area - combined image + centered text view
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  if (_currentDocument != null)
                    Builder(
                      builder: (context) {
                        final pagePaths =
                            _getPreviewPagePaths(_currentDocument!);
                        return Column(
                          children: [
                            Container(
                              decoration: BoxDecoration(
                                borderRadius: BorderRadius.circular(14),
                                border: Border.all(color: Colors.grey.shade200),
                                boxShadow: [
                                  BoxShadow(
                                    color: Colors.black.withOpacity(0.06),
                                    blurRadius: 12,
                                    offset: const Offset(0, 4),
                                  ),
                                ],
                              ),
                              clipBehavior: Clip.antiAlias,
                              child: AspectRatio(
                                aspectRatio: 3 / 4,
                                child: PageView.builder(
                                  controller: _resultsPageController,
                                  itemCount: pagePaths.length,
                                  onPageChanged: (idx) {
                                    setState(() => _resultsPageIndex = idx);
                                  },
                                  itemBuilder: (context, index) {
                                    return Image.file(
                                      File(pagePaths[index]),
                                      fit: BoxFit.contain,
                                    );
                                  },
                                ),
                              ),
                            ),
                            const SizedBox(height: 10),
                            Text(
                              'Page ${_resultsPageIndex + 1} / ${pagePaths.length}',
                              style: TextStyle(
                                fontFamily: 'Poppins',
                                fontSize: 12,
                                color: Colors.grey[500],
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ],
                        );
                      },
                    ),
                  const SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: () {
                        setState(() {
                          _showTextView = !_showTextView;
                        });
                      },
                      icon: Icon(
                        _showTextView
                            ? Icons.visibility_off_outlined
                            : Icons.visibility_outlined,
                        size: 18,
                        color: const Color(0xFF6868AC),
                      ),
                      label: Text(
                        _showTextView ? 'Hide OCR Content' : 'OCR Content',
                        style: const TextStyle(
                          fontFamily: 'Poppins',
                          fontSize: 13,
                          fontWeight: FontWeight.w600,
                          color: Color(0xFF6868AC),
                        ),
                      ),
                      style: OutlinedButton.styleFrom(
                        side: BorderSide(
                            color: const Color(0xFF6868AC).withOpacity(0.35)),
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                    ),
                  ),
                  if (_showTextView) ...[
                    const SizedBox(height: 12),
                    _buildTextView(
                      overrideText: _getOcrTextForPage(_resultsPageIndex),
                    ),
                    const SizedBox(height: 16),
                    _buildExtractedKeysCard(),
                  ],
                ],
              ),
            ),
          ),

          // Action buttons — fixed bottom bar
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: Theme.of(context).cardColor,
              boxShadow: [
                BoxShadow(
                  color: Colors.black.withOpacity(0.05),
                  blurRadius: 12,
                  offset: const Offset(0, -3),
                ),
              ],
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Primary action row
                Row(
                  children: [
                    Expanded(
                      child: _buildActionButton(
                        Icons.home_outlined,
                        'Home',
                        () => _goHome(),
                      ),
                    ),
                    Expanded(
                      child: _buildActionButton(
                        Icons.auto_awesome,
                        'Info',
                        () => _showExtractedInfoSheet(),
                      ),
                    ),
                    Expanded(
                      child: _buildActionButton(
                        Icons.refresh,
                        'Retake',
                        () => _retakePhoto(),
                      ),
                    ),
                    Expanded(
                      child: _buildActionButton(
                        Icons.check_circle_outline,
                        'Complete',
                        () {
                          _showKeyInformationResults().catchError((e) {
                            debugPrint('❌ Complete button handler error: $e');
                            if (mounted) {
                              _showError(
                                  'Unable to open Complete dialog. Please try again.');
                            }
                          });
                        },
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                // Edit OCR row — below primary actions, per-page editing
                SizedBox(
                  width: double.infinity,
                  child: TextButton.icon(
                    onPressed: () => _showOcrCorrectionDialog(),
                    icon: const Icon(Icons.edit_note,
                        size: 18, color: Color(0xFF6868AC)),
                    label: Text(
                      _currentDocument != null &&
                              _currentDocument!.pageCount > 1
                          ? 'Edit OCR (Page ${_resultsPageIndex + 1} of ${_currentDocument!.pageCount})'
                          : 'Edit OCR Text',
                      style: const TextStyle(
                          fontFamily: 'Poppins',
                          fontSize: 13,
                          fontWeight: FontWeight.w500,
                          color: Color(0xFF6868AC)),
                    ),
                    style: TextButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      backgroundColor:
                          const Color(0xFF6868AC).withOpacity(0.08),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12)),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildToggleButton(
      String label, bool isActive, VoidCallback onPressed) {
    return GestureDetector(
      onTap: onPressed,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 10),
        decoration: BoxDecoration(
          color: isActive ? const Color(0xFF6868AC) : Colors.grey[200],
          borderRadius: BorderRadius.circular(25),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: isActive ? Colors.white : Colors.black87,
            fontWeight: isActive ? FontWeight.bold : FontWeight.normal,
            fontSize: 14,
          ),
        ),
      ),
    );
  }

  /// Shows a bottom sheet with extracted OCR information
  void _showExtractedInfoSheet() {
    String fullText = '';
    if (_currentDocument != null && _currentDocument!.pageTexts.isNotEmpty) {
      fullText = _currentDocument!.pageTexts.join('\n\n').trim();
    } else if (_recognizedText.isNotEmpty) {
      fullText = _recognizedText;
    }

    if (fullText.isEmpty || fullText == 'No text recognized yet') {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No extracted information available')),
      );
      return;
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return DraggableScrollableSheet(
          initialChildSize: 0.55,
          minChildSize: 0.3,
          maxChildSize: 0.85,
          builder: (_, scrollController) {
            return Container(
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
              ),
              child: SingleChildScrollView(
                controller: scrollController,
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                child: Column(
                  children: [
                    // Drag handle
                    Container(
                      width: 40,
                      height: 4,
                      margin: const EdgeInsets.only(bottom: 16),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade300,
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                    _buildExtractedKeysCard(),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  /// Builds a card showing extracted key information from OCR text
  Widget _buildExtractedKeysCard() {
    // Combine all page texts for extraction
    String fullText = '';
    if (_currentDocument != null && _currentDocument!.pageTexts.isNotEmpty) {
      fullText = _currentDocument!.pageTexts.join('\n\n').trim();
    } else if (_recognizedText.isNotEmpty) {
      fullText = _recognizedText;
    }

    if (fullText.isEmpty || fullText == 'No text recognized yet') {
      return const SizedBox.shrink();
    }

    final extracted = OcrTextProcessor.extractSearchableKeys(fullText);
    final docType = extracted['document_type'] as String?;
    final names = (extracted['names'] as List<String>?) ?? [];
    final dates = (extracted['dates'] as List<String>?) ?? [];
    final amounts = (extracted['amounts'] as List<String>?) ?? [];
    final departments = (extracted['departments'] as List<String>?) ?? [];
    final refs = (extracted['reference_numbers'] as List<String>?) ?? [];
    final subjects = (extracted['subjects'] as List<String>?) ?? [];
    final positions = (extracted['positions'] as List<String>?) ?? [];

    // If nothing was extracted, don't show the card
    final hasData = docType != null ||
        names.isNotEmpty ||
        dates.isNotEmpty ||
        amounts.isNotEmpty ||
        departments.isNotEmpty ||
        refs.isNotEmpty ||
        subjects.isNotEmpty ||
        positions.isNotEmpty;
    if (!hasData) return const SizedBox.shrink();

    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(top: 4),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFF6868AC).withOpacity(0.12)),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF6868AC).withOpacity(0.06),
            blurRadius: 12,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Header
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: const Color(0xFF6868AC).withOpacity(0.06),
              borderRadius: const BorderRadius.only(
                topLeft: Radius.circular(15),
                topRight: Radius.circular(15),
              ),
            ),
            child: Row(
              children: [
                const Icon(Icons.auto_awesome,
                    size: 16, color: Color(0xFF6868AC)),
                const SizedBox(width: 8),
                const Text(
                  'Extracted Information',
                  style: TextStyle(
                    fontFamily: 'Poppins',
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF52528A),
                  ),
                ),
                const Spacer(),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: const Color(0xFF6868AC).withOpacity(0.12),
                    borderRadius: BorderRadius.circular(100),
                  ),
                  child: const Text(
                    'OCR',
                    style: TextStyle(
                      fontFamily: 'Poppins',
                      fontSize: 9,
                      fontWeight: FontWeight.w700,
                      color: Color(0xFF6868AC),
                      letterSpacing: 0.5,
                    ),
                  ),
                ),
              ],
            ),
          ),
          // Content
          Padding(
            padding: const EdgeInsets.all(14),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                if (docType != null)
                  _buildKeyChip(
                    Icons.description_outlined,
                    'Type',
                    docType[0].toUpperCase() + docType.substring(1),
                    Colors.indigo,
                  ),
                for (final name in names.take(3))
                  _buildKeyChip(
                    Icons.person_outline,
                    'Name',
                    name,
                    Colors.teal,
                  ),
                for (final date in dates.take(3))
                  _buildKeyChip(
                    Icons.calendar_today_outlined,
                    'Date',
                    date,
                    Colors.orange.shade800,
                  ),
                for (final amount in amounts.take(3))
                  _buildKeyChip(
                    Icons.payments_outlined,
                    'Amount',
                    amount,
                    Colors.green.shade700,
                  ),
                for (final dept in departments.take(2))
                  _buildKeyChip(
                    Icons.business_outlined,
                    'Dept',
                    dept,
                    Colors.purple,
                  ),
                for (final ref in refs.take(2))
                  _buildKeyChip(
                    Icons.tag,
                    'Ref',
                    ref,
                    Colors.blueGrey,
                  ),
                for (final subj in subjects.take(2))
                  _buildKeyChip(
                    Icons.subject,
                    'Subject',
                    subj,
                    Colors.deepOrange,
                  ),
                for (final pos in positions.take(2))
                  _buildKeyChip(
                    Icons.badge_outlined,
                    'Position',
                    pos,
                    Colors.brown,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Builds a single key-value chip for extracted OCR data
  Widget _buildKeyChip(IconData icon, String label, String value, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: color.withOpacity(0.06),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withOpacity(0.15)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: color),
          const SizedBox(width: 6),
          Flexible(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 9,
                    fontWeight: FontWeight.w700,
                    color: color.withOpacity(0.7),
                    letterSpacing: 0.3,
                  ),
                ),
                Text(
                  value,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: color,
                  ),
                  overflow: TextOverflow.ellipsis,
                  maxLines: 1,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTextView({String? overrideText}) {
    // CamScanner-style blank document page with formatted text (matching template format)
    final String effectiveText = (overrideText != null)
        ? overrideText
        : (_recognizedText.isNotEmpty
            ? _recognizedText
            : 'No text detected in image');
    return Container(
      color: Colors.white,
      child: SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Document header (optional metadata)
            if (_currentDocument != null)
              Padding(
                padding: const EdgeInsets.only(bottom: 20),
                child: Center(
                  child: Text(
                    'Scanned Document',
                    style: TextStyle(
                      fontSize: 12,
                      color: Colors.grey[600],
                      fontWeight: FontWeight.w500,
                    ),
                    textAlign: TextAlign.center,
                  ),
                ),
              ),

            // Formatted text content - matching template style
            SelectableText.rich(
              _buildFormattedText(effectiveText),
              textAlign: TextAlign.left,
              style: const TextStyle(
                fontSize: 16,
                height: 1.6,
                color: Colors.black87,
                fontFamily: 'Roboto',
              ),
            ),

            // Confidence indicator (smaller, at bottom)
            if (_averageConfidence > 0)
              Padding(
                padding: const EdgeInsets.only(top: 20),
                child: Row(
                  children: [
                    Icon(
                      Icons.check_circle,
                      color: _averageConfidence > 0.8
                          ? Colors.green
                          : Colors.orange,
                      size: 14,
                    ),
                    const SizedBox(width: 6),
                    Text(
                      'Confidence: ${(_averageConfidence * 100).toStringAsFixed(0)}%',
                      style: TextStyle(
                        fontSize: 11,
                        color: Colors.grey[600],
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

  TextSpan _buildFormattedText(String text) {
    // Split text into paragraphs and preserve formatting
    final lines = text.split('\n');
    final List<TextSpan> spans = [];

    for (int i = 0; i < lines.length; i++) {
      final line = lines[i].trim();
      final originalLine = lines[i];

      // Preserve empty lines for document structure
      if (line.isEmpty) {
        if (i < lines.length - 1) {
          spans.add(const TextSpan(text: '\n'));
        }
        continue;
      }

      // Check if line is a document title (all caps, short, at the start)
      // Examples: "RESIGNATION LETTER", "INVOICE", "RECEIPT"
      final isTitle = line.length < 50 &&
          line.length > 3 &&
          line == line.toUpperCase() &&
          !line.contains(':') &&
          (i == 0 || lines[max(0, i - 1)].trim().isEmpty) &&
          !RegExp(r'^[0-9\s]+$').hasMatch(line);

      // Check if line is a field label (contains colon, like "From:", "Address:", etc.)
      String? fieldLabel;
      String? fieldValue;
      bool isFieldLabel = false;
      if (line.contains(':')) {
        final colonIndex = line.indexOf(':');
        if (colonIndex >= 0) {
          fieldLabel = line.substring(0, colonIndex + 1).trim();
          fieldValue = colonIndex < line.length - 1
              ? line.substring(colonIndex + 1).trim()
              : null;
          isFieldLabel = fieldLabel.length < 40;
        }
      }

      // Check if line is indented (starts with tab or leading spaces in original)
      final isIndented = originalLine.startsWith('\t') ||
          (originalLine.isNotEmpty &&
              originalLine.trim() != originalLine &&
              originalLine
                      .substring(0,
                          originalLine.length - originalLine.trimLeft().length)
                      .length >=
                  2);

      // Format based on content type
      if (isTitle) {
        // Document title - bold, larger, centered style (template format)
        spans.add(TextSpan(
          text: line,
          style: const TextStyle(
            fontSize: 20,
            fontWeight: FontWeight.bold,
            color: Colors.black,
            letterSpacing: 0.5,
          ),
        ));
      } else if (isFieldLabel && fieldLabel != null) {
        // Field labels like "From:", "Address:", "Date:" - bold label, normal value (template format)
        spans.add(TextSpan(
          text: fieldLabel,
          style: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.bold,
            color: Colors.black,
          ),
        ));
        if (fieldValue != null && fieldValue.isNotEmpty) {
          spans.add(TextSpan(
            text: ' $fieldValue',
            style: const TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.normal,
              color: Colors.black87,
            ),
          ));
        }
      } else if (isIndented) {
        // Indented content - slightly smaller
        spans.add(TextSpan(
          text: line,
          style: const TextStyle(
            fontSize: 15,
            color: Colors.black87,
            height: 1.5,
          ),
        ));
      } else {
        // Regular body text - clean, readable format
        spans.add(TextSpan(
          text: line,
          style: const TextStyle(
            fontSize: 16,
            color: Colors.black87,
            height: 1.6,
          ),
        ));
      }

      // Add line break except for last line
      if (i < lines.length - 1) {
        spans.add(const TextSpan(text: '\n'));
      }
    }

    return TextSpan(children: spans);
  }

  Widget _buildImageView() {
    return Container(
      margin: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(12),
        boxShadow: [
          BoxShadow(
            color: Colors.grey.withOpacity(0.3),
            blurRadius: 10,
            offset: const Offset(0, 5),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: _currentDocument != null
            ? Image.file(
                File(_currentDocument!.filteredPath ??
                    _currentDocument!.croppedPath ??
                    _currentDocument!.imagePath),
                fit: BoxFit.contain,
              )
            : Container(color: Colors.grey[300]),
      ),
    );
  }

  Widget _buildFilterButton(String label, DocumentFilter filter) {
    final isSelected = _selectedFilter == filter;
    return GestureDetector(
      onTap: () => setState(() => _selectedFilter = filter),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        decoration: BoxDecoration(
          color: isSelected ? const Color(0xFF6868AC) : Colors.transparent,
          border: Border.all(color: Colors.white),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: isSelected ? Colors.white : Colors.white,
            fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
          ),
        ),
      ),
    );
  }

  Widget _buildActionButton(
      IconData icon, String label, VoidCallback onPressed) {
    return SizedBox(
      height: 76,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onPressed,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFF6868AC).withOpacity(0.08),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, size: 22, color: const Color(0xFF6868AC)),
              ),
              const SizedBox(height: 4),
              Text(
                label,
                style: TextStyle(
                  fontFamily: 'Poppins',
                  fontSize: 11,
                  fontWeight: FontWeight.w500,
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

  Widget _buildExportButton(
      IconData icon, String label, Color color, VoidCallback onPressed) {
    return Expanded(
      child: Container(
        margin: const EdgeInsets.symmetric(horizontal: 8),
        child: ElevatedButton.icon(
          onPressed: onPressed,
          icon: Icon(icon, size: 22),
          label: Text(
            label,
            style: const TextStyle(
              fontFamily: 'Poppins',
              fontSize: 14,
              fontWeight: FontWeight.w600,
            ),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: color,
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 12),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(14),
            ),
            elevation: 0,
          ),
        ),
      ),
    );
  }

  Widget _buildProcessingOverlay() {
    return Container(
      color: Colors.black.withOpacity(0.7),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const CircularProgressIndicator(color: Colors.white),
            const SizedBox(height: 16),
            Text(
              _autoSaveMessage,
              style: const TextStyle(color: Colors.white, fontSize: 16),
            ),
          ],
        ),
      ),
    );
  }

  void _showOptionsMenu() {
    // Show options menu
  }

  void _shareDocument() {
    if (_currentDocument != null) {
      Share.share(_currentDocument!.recognizedText);
    }
  }

  void _signDocument() {
    // Implement document signing
  }

  // removed duplicate dispose (see final dispose at end of file)

  // Legacy methods for compatibility - will be removed
  Widget _buildScanOverlay() {
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [
            Colors.transparent,
            const Color(0xFF6868AC).withOpacity(0.1),
            Colors.transparent,
          ],
          stops: [0.0, _scanValue, 1.0],
        ),
      ),
    );
  }

  Widget _buildControlButton({
    required IconData icon,
    required String label,
    required VoidCallback onPressed,
    required Color color,
  }) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        IconButton(
          onPressed: onPressed,
          icon: Icon(icon, color: color, size: 28),
          style: IconButton.styleFrom(
            backgroundColor: color.withOpacity(0.1),
            padding: const EdgeInsets.all(12),
          ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: TextStyle(fontSize: 12, color: color),
        ),
      ],
    );
  }

  // Helper method to preserve text formatting for PDF generation
  String _preserveTextFormatting(String text) {
    // Split text into lines and preserve indentation
    List<String> lines = text.split('\n');
    StringBuffer formatted = StringBuffer();

    for (String line in lines) {
      // Preserve leading spaces and tabs
      String trimmedLine = line.trimRight();
      if (trimmedLine.isNotEmpty) {
        formatted.writeln(trimmedLine);
      } else {
        formatted.writeln(); // Preserve empty lines
      }
    }

    return formatted.toString();
  }

  // Helper method to split text into chunks for PDF
  List<String> _splitTextIntoChunks(String text, int maxChunkSize) {
    List<String> chunks = [];
    List<String> lines = text.split('\n');
    StringBuffer currentChunk = StringBuffer();

    for (String line in lines) {
      if (currentChunk.length + line.length + 1 > maxChunkSize &&
          currentChunk.isNotEmpty) {
        chunks.add(currentChunk.toString());
        currentChunk.clear();
      }

      if (currentChunk.isNotEmpty) {
        currentChunk.writeln();
      }
      currentChunk.write(line);
    }

    if (currentChunk.isNotEmpty) {
      chunks.add(currentChunk.toString());
    }

    return chunks.isEmpty ? [''] : chunks;
  }

  // Save scanned document to gallery
  Future<void> _saveToGallery() async {
    if (_lastCapturedImagePath == null) return;

    try {
      // Get user department for department-specific storage
      final prefs = await SharedPreferences.getInstance();
      final String userDepartment =
          prefs.getString('user_department') ?? 'General';

      final Directory extDir = await getApplicationDocumentsDirectory();
      final String archivePath = '${extDir.path}/Archive/$userDepartment';
      await Directory(archivePath).create(recursive: true);

      final String timestamp = DateTime.now().millisecondsSinceEpoch.toString();
      final String archiveImagePath =
          path.join(archivePath, 'IMG_$timestamp.jpg');
      final String archiveTextPath =
          path.join(archivePath, 'OCR_$timestamp.txt');

      // Save image to gallery
      await File(_lastCapturedImagePath!).copy(archiveImagePath);

      // Save OCR text with enhanced format for gallery
      final String ocrContent = '''
OCR SCAN RESULT
===============
Document: DOC_$timestamp
Capture Time: ${_captureTime?.toString() ?? DateTime.now().toString()}
Confidence Score: ${(_averageConfidence * 100).toStringAsFixed(1)}%
Processing Details:
- Text Blocks: ${_textBlocks.length}
- Text Lines: ${_textLines.length}
- Text Elements: ${_textElements.length}

${_keyInformation.isNotEmpty ? '''
KEY INFORMATION EXTRACTED:
${_keyInformation.entries.map((e) => '${e.key}: ${e.value}').join('\n')}

''' : ''}--- EXTRACTED TEXT ---
$_recognizedText

--- END OF DOCUMENT ---
Generated by CamScanner App
${DateTime.now().toString()}
''';

      await File(archiveTextPath).writeAsString(ocrContent);

      // If we have a current document with additional processing, save that info too
      if (_currentDocument != null) {
        // Save document metadata for gallery integration
        final Map<String, dynamic> documentMetadata = {
          'id': _currentDocument!.id,
          'timestamp': timestamp,
          'imagePath': archiveImagePath,
          'textPath': archiveTextPath,
          'fileName': 'DOC_$timestamp',
          'captureTime': _currentDocument!.captureTime.toIso8601String(),
          'confidence': _averageConfidence,
          'appliedFilter': _currentDocument!.appliedFilter.toString(),
          'keyInformation': _keyInformation,
          'recognizedText': _recognizedText,
        };

        // Save metadata file for advanced gallery features
        final String metadataPath =
            path.join(archivePath, 'META_$timestamp.json');
        await File(metadataPath).writeAsString(jsonEncode(documentMetadata));
      }

      debugPrint('✅ Document saved to gallery: DOC_$timestamp');
    } catch (e) {
      debugPrint('❌ Error saving to gallery: $e');
      rethrow;
    }
  }

  // Show check animation after successful save
  Future<void> _showCheckAnimation() async {
    // Simple delay for visual feedback
    await Future.delayed(const Duration(milliseconds: 300));
  }

  @override
  void dispose() {
    // Clean up animation controllers
    _scanController.dispose();
    _processingController.dispose();
    _batchController.dispose();

    _resultsPageController.dispose();

    // Clean up camera controller
    _controller.dispose();

    // Clean up batch processor temp files
    _batchProcessor.clearTemporaryFiles();

    debugPrint('✅ Camera page resources disposed');
    super.dispose();
  }
}

/// Simple data holder for extracted key items in the dialog
class _ExtractedKeyItem {
  final IconData icon;
  final String label;
  final String value;
  const _ExtractedKeyItem(this.icon, this.label, this.value);
}
