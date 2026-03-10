// Batch Document Processor Service
// Optimized for processing 10-15+ documents with OCR efficiently on mobile devices
// Reduces lags, stutters, and memory issues through chunked processing

import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:image/image.dart' as img;
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';

/// Configuration for batch processing
class BatchProcessingConfig {
  /// Number of documents to process in each chunk
  final int chunkSize;

  /// Maximum image dimension for OCR (larger images are resized)
  final int maxImageDimension;

  /// JPEG compression quality (0-100)
  final int compressionQuality;

  /// Whether to use isolates for heavy processing
  final bool useIsolates;

  /// Delay between chunks to prevent UI freeze (milliseconds)
  final int chunkDelayMs;

  /// Maximum concurrent OCR operations
  final int maxConcurrentOcr;

  /// Memory threshold to trigger garbage collection (MB)
  final int memoryThresholdMb;

  const BatchProcessingConfig({
    this.chunkSize = 3,
    this.maxImageDimension = 1920,
    this.compressionQuality = 85,
    this.useIsolates = true,
    this.chunkDelayMs = 100,
    this.maxConcurrentOcr = 2,
    this.memoryThresholdMb = 150,
  });

  /// Optimized config for low-end devices
  static const BatchProcessingConfig lowEnd = BatchProcessingConfig(
    chunkSize: 2,
    maxImageDimension: 1280,
    compressionQuality: 75,
    useIsolates: true,
    chunkDelayMs: 200,
    maxConcurrentOcr: 1,
    memoryThresholdMb: 100,
  );

  /// Standard config for mid-range devices
  static const BatchProcessingConfig standard = BatchProcessingConfig(
    chunkSize: 3,
    maxImageDimension: 1920,
    compressionQuality: 85,
    useIsolates: true,
    chunkDelayMs: 100,
    maxConcurrentOcr: 2,
    memoryThresholdMb: 150,
  );

  /// High performance config for flagship devices
  static const BatchProcessingConfig highEnd = BatchProcessingConfig(
    chunkSize: 5,
    maxImageDimension: 2560,
    compressionQuality: 90,
    useIsolates: true,
    chunkDelayMs: 50,
    maxConcurrentOcr: 3,
    memoryThresholdMb: 256,
  );
}

/// Represents a processed document with all metadata
class ProcessedDocument {
  final String id;
  final String originalPath;
  final String? optimizedPath;
  final String ocrText;
  final List<String> pageTexts;
  final double confidence;
  final DateTime processedAt;
  final String? errorMessage;
  final Map<String, dynamic> metadata;

  ProcessedDocument({
    required this.id,
    required this.originalPath,
    this.optimizedPath,
    this.ocrText = '',
    this.pageTexts = const [],
    this.confidence = 0.0,
    DateTime? processedAt,
    this.errorMessage,
    this.metadata = const {},
  }) : processedAt = processedAt ?? DateTime.now();

  bool get hasError => errorMessage != null && errorMessage!.isNotEmpty;

  /// Generate a unique hash for document integrity verification
  String get documentHash {
    final content =
        '$id|$originalPath|$ocrText|${processedAt.toIso8601String()}';
    return sha256.convert(utf8.encode(content)).toString();
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'originalPath': originalPath,
        'optimizedPath': optimizedPath,
        'ocrText': ocrText,
        'pageTexts': pageTexts,
        'confidence': confidence,
        'processedAt': processedAt.toIso8601String(),
        'errorMessage': errorMessage,
        'metadata': metadata,
        'documentHash': documentHash,
      };

  factory ProcessedDocument.fromJson(Map<String, dynamic> json) {
    return ProcessedDocument(
      id: json['id'] ?? '',
      originalPath: json['originalPath'] ?? '',
      optimizedPath: json['optimizedPath'],
      ocrText: json['ocrText'] ?? '',
      pageTexts: List<String>.from(json['pageTexts'] ?? []),
      confidence: (json['confidence'] ?? 0.0).toDouble(),
      processedAt: json['processedAt'] != null
          ? DateTime.parse(json['processedAt'])
          : DateTime.now(),
      errorMessage: json['errorMessage'],
      metadata: Map<String, dynamic>.from(json['metadata'] ?? {}),
    );
  }
}

/// Progress callback for batch processing
typedef BatchProgressCallback = void Function(
  int current,
  int total,
  String currentTask,
  double overallProgress,
);

/// Main batch document processor class
class BatchDocumentProcessor {
  final BatchProcessingConfig config;
  final List<String> _tempFiles = [];
  bool _isProcessing = false;
  bool _cancelRequested = false;

  BatchDocumentProcessor({
    this.config = const BatchProcessingConfig(),
  });

  /// Check if currently processing
  bool get isProcessing => _isProcessing;

  /// Request cancellation of current batch
  void requestCancel() {
    _cancelRequested = true;
  }

  /// Process documents in chunks, yielding results as each chunk completes
  Stream<List<ProcessedDocument>> processInChunks(
    List<String> imagePaths, {
    BatchProgressCallback? onProgress,
  }) async* {
    if (_isProcessing) {
      throw StateError('Batch processing already in progress');
    }

    _isProcessing = true;
    _cancelRequested = false;

    try {
      final total = imagePaths.length;
      debugPrint(
          '📦 Starting batch processing of $total documents (chunk size: ${config.chunkSize})');

      // Process in chunks
      for (int i = 0; i < total; i += config.chunkSize) {
        if (_cancelRequested) {
          debugPrint('❌ Batch processing cancelled');
          break;
        }

        final chunkEnd = (i + config.chunkSize).clamp(0, total);
        final chunk = imagePaths.sublist(i, chunkEnd);
        final chunkNumber = (i / config.chunkSize).floor() + 1;
        final totalChunks = (total / config.chunkSize).ceil();

        debugPrint(
            '📄 Processing chunk $chunkNumber/$totalChunks (${chunk.length} documents)');

        onProgress?.call(
          i,
          total,
          'Processing chunk $chunkNumber of $totalChunks...',
          i / total,
        );

        // Process this chunk
        final chunkResults = await _processChunk(chunk, i, total, onProgress);
        yield chunkResults;

        // Delay between chunks to let UI update and prevent memory buildup
        if (i + config.chunkSize < total) {
          await Future.delayed(Duration(milliseconds: config.chunkDelayMs));
          // Suggest garbage collection after each chunk
          _suggestGarbageCollection();
        }
      }

      onProgress?.call(total, total, 'Complete!', 1.0);
      debugPrint('✅ Batch processing complete');
    } finally {
      _isProcessing = false;
      _cancelRequested = false;
    }
  }

  /// Process a single chunk of documents
  Future<List<ProcessedDocument>> _processChunk(
    List<String> paths,
    int startIndex,
    int total,
    BatchProgressCallback? onProgress,
  ) async {
    final results = <ProcessedDocument>[];

    // Process documents with limited concurrency
    final semaphore = _Semaphore(config.maxConcurrentOcr);

    final futures = paths.asMap().entries.map((entry) async {
      await semaphore.acquire();
      try {
        final globalIndex = startIndex + entry.key;
        onProgress?.call(
          globalIndex,
          total,
          'Processing document ${globalIndex + 1} of $total...',
          globalIndex / total,
        );

        final result = await _processDocument(entry.value, globalIndex);
        results.add(result);

        return result;
      } finally {
        semaphore.release();
      }
    }).toList();

    await Future.wait(futures);

    return results;
  }

  /// Process a single document: optimize image + OCR
  Future<ProcessedDocument> _processDocument(
      String imagePath, int index) async {
    final id = 'DOC_${DateTime.now().millisecondsSinceEpoch}_$index';

    try {
      // Step 1: Optimize image for OCR
      final optimizedPath = await _optimizeImage(imagePath, id);

      // Step 2: Perform OCR
      final ocrResult = await _performOcr(optimizedPath ?? imagePath);

      return ProcessedDocument(
        id: id,
        originalPath: imagePath,
        optimizedPath: optimizedPath,
        ocrText: ocrResult.text,
        pageTexts: [ocrResult.text],
        confidence: ocrResult.confidence,
        metadata: {
          'processingTimeMs': ocrResult.processingTimeMs,
          'blockCount': ocrResult.blockCount,
          'lineCount': ocrResult.lineCount,
        },
      );
    } catch (e) {
      debugPrint('❌ Error processing document $index: $e');
      return ProcessedDocument(
        id: id,
        originalPath: imagePath,
        errorMessage: e.toString(),
      );
    }
  }

  /// Optimize image for OCR processing - resize and compress
  Future<String?> _optimizeImage(String imagePath, String docId) async {
    try {
      final bytes = await File(imagePath).readAsBytes();

      // Decode in isolate if enabled
      final decoded = config.useIsolates
          ? await compute(_decodeImageIsolate, bytes)
          : img.decodeImage(bytes);

      if (decoded == null) return null;

      // Check if resizing is needed
      final maxDim = config.maxImageDimension;
      img.Image optimized = decoded;

      if (decoded.width > maxDim || decoded.height > maxDim) {
        // Calculate new dimensions maintaining aspect ratio
        final ratio = decoded.width / decoded.height;
        int newWidth, newHeight;

        if (decoded.width > decoded.height) {
          newWidth = maxDim;
          newHeight = (maxDim / ratio).round();
        } else {
          newHeight = maxDim;
          newWidth = (maxDim * ratio).round();
        }

        // Resize in isolate if enabled
        if (config.useIsolates) {
          optimized = await compute(
            _resizeImageIsolate,
            _ResizeParams(decoded, newWidth, newHeight),
          );
        } else {
          optimized =
              img.copyResize(decoded, width: newWidth, height: newHeight);
        }

        debugPrint(
            '📏 Resized ${decoded.width}x${decoded.height} → ${newWidth}x$newHeight');
      }

      // Apply document enhancement filter
      optimized = img.adjustColor(optimized, contrast: 1.1, brightness: 1.02);

      // Save optimized image
      final dir = await getTemporaryDirectory();
      final optimizedPath = path.join(dir.path, 'opt_$docId.jpg');

      final compressedBytes = config.useIsolates
          ? await compute(
              _encodeJpegIsolate,
              _EncodeParams(optimized, config.compressionQuality),
            )
          : img.encodeJpg(optimized, quality: config.compressionQuality);

      await File(optimizedPath).writeAsBytes(compressedBytes);
      _tempFiles.add(optimizedPath);

      return optimizedPath;
    } catch (e) {
      debugPrint('⚠️ Image optimization failed: $e');
      return null;
    }
  }

  /// Perform OCR on an image
  Future<_OcrResult> _performOcr(String imagePath) async {
    final stopwatch = Stopwatch()..start();

    try {
      final inputImage = InputImage.fromFilePath(imagePath);
      final recognizer = TextRecognizer(script: TextRecognitionScript.latin);

      try {
        final result = await recognizer.processImage(inputImage);

        // Build text preserving structure
        final buffer = StringBuffer();
        double totalConfidence = 0;
        int elementCount = 0;
        int lineCount = 0;

        for (final block in result.blocks) {
          for (final line in block.lines) {
            buffer.writeln(line.text);
            lineCount++;
            for (final element in line.elements) {
              if (element.confidence != null) {
                totalConfidence += element.confidence!;
                elementCount++;
              }
            }
          }
          buffer.writeln(); // Block separator
        }

        stopwatch.stop();

        return _OcrResult(
          text: buffer.toString().trim(),
          confidence: elementCount > 0 ? totalConfidence / elementCount : 0.0,
          blockCount: result.blocks.length,
          lineCount: lineCount,
          processingTimeMs: stopwatch.elapsedMilliseconds,
        );
      } finally {
        recognizer.close();
      }
    } catch (e) {
      stopwatch.stop();
      debugPrint('❌ OCR error: $e');
      return _OcrResult(
        text: '[OCR failed: $e]',
        confidence: 0.0,
        blockCount: 0,
        lineCount: 0,
        processingTimeMs: stopwatch.elapsedMilliseconds,
      );
    }
  }

  /// Process multi-page document (e.g., document with 10-15 attachments)
  Future<ProcessedDocument> processMultiPageDocument(
    List<String> pageImagePaths, {
    BatchProgressCallback? onProgress,
  }) async {
    final id = 'MULTIPG_${DateTime.now().millisecondsSinceEpoch}';
    final pageTexts = <String>[];
    final combinedText = StringBuffer();
    double totalConfidence = 0;
    int pageCount = 0;

    try {
      for (int i = 0; i < pageImagePaths.length; i++) {
        if (_cancelRequested) break;

        onProgress?.call(
          i,
          pageImagePaths.length,
          'Processing page ${i + 1} of ${pageImagePaths.length}...',
          i / pageImagePaths.length,
        );

        // Optimize and OCR each page
        final optimizedPath =
            await _optimizeImage(pageImagePaths[i], '${id}_p$i');
        final ocrResult = await _performOcr(optimizedPath ?? pageImagePaths[i]);

        pageTexts.add(ocrResult.text);
        combinedText.writeln('=== Page ${i + 1} ===');
        combinedText.writeln(ocrResult.text);
        combinedText.writeln();

        totalConfidence += ocrResult.confidence;
        pageCount++;

        // Delay between pages to prevent UI freeze
        if (i < pageImagePaths.length - 1) {
          await Future.delayed(
              Duration(milliseconds: config.chunkDelayMs ~/ 2));
        }
      }

      return ProcessedDocument(
        id: id,
        originalPath: pageImagePaths.first,
        ocrText: combinedText.toString().trim(),
        pageTexts: pageTexts,
        confidence: pageCount > 0 ? totalConfidence / pageCount : 0.0,
        metadata: {
          'pageCount': pageCount,
          'allPagePaths': pageImagePaths,
        },
      );
    } catch (e) {
      return ProcessedDocument(
        id: id,
        originalPath: pageImagePaths.isNotEmpty ? pageImagePaths.first : '',
        errorMessage: e.toString(),
      );
    }
  }

  /// Clear temporary files created during processing
  Future<void> clearTemporaryFiles() async {
    for (final filePath in _tempFiles) {
      try {
        final file = File(filePath);
        if (await file.exists()) {
          await file.delete();
        }
      } catch (e) {
        debugPrint('⚠️ Failed to delete temp file: $filePath');
      }
    }
    _tempFiles.clear();
    debugPrint('🧹 Cleared ${_tempFiles.length} temporary files');
  }

  /// Suggest garbage collection (helps with memory on large batches)
  void _suggestGarbageCollection() {
    // In Flutter, we can't force GC, but we can release references
    // and let the system decide when to collect
    debugPrint('💾 Suggesting garbage collection...');
  }

  /// Get memory usage estimate
  Future<int> estimateMemoryUsageMb() async {
    // This is a rough estimate based on temp files
    int totalBytes = 0;
    for (final filePath in _tempFiles) {
      try {
        final file = File(filePath);
        if (await file.exists()) {
          totalBytes += await file.length();
        }
      } catch (_) {}
    }
    return totalBytes ~/ (1024 * 1024);
  }
}

/// Simple semaphore for limiting concurrent operations
class _Semaphore {
  final int maxCount;
  int _currentCount = 0;
  final List<Completer<void>> _waiters = [];

  _Semaphore(this.maxCount);

  Future<void> acquire() async {
    if (_currentCount < maxCount) {
      _currentCount++;
      return;
    }
    final completer = Completer<void>();
    _waiters.add(completer);
    return completer.future;
  }

  void release() {
    if (_waiters.isNotEmpty) {
      final waiter = _waiters.removeAt(0);
      waiter.complete();
    } else {
      _currentCount--;
    }
  }
}

/// OCR result container
class _OcrResult {
  final String text;
  final double confidence;
  final int blockCount;
  final int lineCount;
  final int processingTimeMs;

  _OcrResult({
    required this.text,
    required this.confidence,
    required this.blockCount,
    required this.lineCount,
    required this.processingTimeMs,
  });
}

/// Isolate-safe resize parameters
class _ResizeParams {
  final img.Image image;
  final int width;
  final int height;

  _ResizeParams(this.image, this.width, this.height);
}

/// Isolate-safe encode parameters
class _EncodeParams {
  final img.Image image;
  final int quality;

  _EncodeParams(this.image, this.quality);
}

// Isolate functions (must be top-level or static)
img.Image? _decodeImageIsolate(Uint8List bytes) {
  return img.decodeImage(bytes);
}

img.Image _resizeImageIsolate(_ResizeParams params) {
  return img.copyResize(params.image,
      width: params.width, height: params.height);
}

Uint8List _encodeJpegIsolate(_EncodeParams params) {
  return Uint8List.fromList(
      img.encodeJpg(params.image, quality: params.quality));
}
