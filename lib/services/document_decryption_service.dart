import 'dart:io';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';
import 'document_encryption_service.dart';

/// Document decryption service for viewing encrypted files
class DocumentDecryptionService {
  /// Decrypt and prepare file for viewing
  static Future<File?> prepareFileForViewing({
    required String fileUrl,
    required String fileName,
    bool isEncrypted = false,
    String? originalFileName,
  }) async {
    try {
      debugPrint('🔓 Preparing file for viewing: $fileName');

      // Download the file
      final downloadedFile = await _downloadFile(fileUrl, fileName);
      if (downloadedFile == null) {
        debugPrint('❌ Failed to download file');
        return null;
      }

      // Check if file needs decryption
      if (!isEncrypted &&
          !DocumentEncryptionService.isEncryptedFile(fileName)) {
        debugPrint('📄 File is not encrypted, returning as-is');
        return downloadedFile;
      }

      // Decrypt the file
      final decryptedFile =
          await _decryptFile(downloadedFile, originalFileName ?? fileName);

      // Clean up downloaded encrypted file
      try {
        await downloadedFile.delete();
      } catch (e) {
        debugPrint('⚠️ Could not clean up downloaded file: $e');
      }

      return decryptedFile;
    } catch (e) {
      debugPrint('❌ Error preparing file for viewing: $e');
      return null;
    }
  }

  /// Download file from URL
  static Future<File?> _downloadFile(String url, String fileName) async {
    try {
      final response = await http.get(Uri.parse(url));
      if (response.statusCode != 200) {
        throw Exception('Failed to download file: ${response.statusCode}');
      }

      final tempDir = await getTemporaryDirectory();
      final downloadedFile = File('${tempDir.path}/$fileName');
      await downloadedFile.writeAsBytes(response.bodyBytes);

      debugPrint('📥 File downloaded: ${downloadedFile.path}');
      return downloadedFile;
    } catch (e) {
      debugPrint('❌ Error downloading file: $e');
      return null;
    }
  }

  /// Decrypt file to temporary location
  static Future<File?> _decryptFile(
      File encryptedFile, String originalFileName) async {
    try {
      debugPrint('🔓 Decrypting file: ${encryptedFile.path}');

      // Read encrypted data
      final encryptedData = await encryptedFile.readAsBytes();

      // Decrypt the data
      final decryptedData =
          await DocumentEncryptionService.decryptFileData(encryptedData);

      // Save decrypted file with original extension
      final tempDir = await getTemporaryDirectory();
      final String decryptedFileName = _getDecryptedFileName(originalFileName);
      final decryptedFile = File('${tempDir.path}/$decryptedFileName');
      await decryptedFile.writeAsBytes(decryptedData);

      debugPrint('🔓 File decrypted: ${decryptedFile.path}');
      return decryptedFile;
    } catch (e) {
      debugPrint('❌ Error decrypting file: $e');
      return null;
    }
  }

  /// Get decrypted filename (remove .enc extension)
  static String _getDecryptedFileName(String fileName) {
    if (fileName.toLowerCase().endsWith('.enc')) {
      return fileName.substring(0, fileName.length - 4);
    }
    return fileName;
  }

  /// Check if file can be previewed based on type
  static bool canPreviewFile(String fileName) {
    final extension = fileName.split('.').last.toLowerCase();
    return _previewableExtensions.contains(extension);
  }

  /// Supported previewable file extensions
  static const List<String> _previewableExtensions = [
    'pdf',
    'jpg',
    'jpeg',
    'png',
    'gif',
    'bmp',
    'txt',
    'doc',
    'docx'
  ];

  /// Get file icon based on extension
  static IconData getFileIcon(String fileName) {
    final extension = fileName.split('.').last.toLowerCase();

    switch (extension) {
      case 'pdf':
        return Icons.picture_as_pdf;
      case 'doc':
      case 'docx':
        return Icons.description;
      case 'xls':
      case 'xlsx':
        return Icons.table_chart;
      case 'jpg':
      case 'jpeg':
      case 'png':
      case 'gif':
      case 'bmp':
        return Icons.image;
      case 'txt':
        return Icons.text_snippet;
      case 'enc':
        return Icons.lock;
      default:
        return Icons.insert_drive_file;
    }
  }

  /// Get file display name (remove encryption prefix if present)
  static String getDisplayName(String fileName, {String? originalFileName}) {
    if (originalFileName != null && originalFileName.isNotEmpty) {
      return originalFileName;
    }

    // Remove encryption prefixes
    String displayName = fileName;
    if (displayName.startsWith('enc_')) {
      displayName = displayName.substring(4);
    }

    // Remove timestamp part from encrypted filenames
    final parts = displayName.split('_');
    if (parts.length > 2) {
      // Try to identify timestamp part (usually numeric)
      for (int i = 1; i < parts.length - 1; i++) {
        if (RegExp(r'^\d+$').hasMatch(parts[i])) {
          parts.removeAt(i);
          break;
        }
      }
      displayName = parts.join('_');
    }

    return displayName;
  }

  /// Create PDF viewer widget for decrypted PDF
  static Widget createPDFViewer(File pdfFile) {
    // For now, return a placeholder since flutter_pdfview isn't properly integrated
    return Container(
      padding: const EdgeInsets.all(16),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          const Icon(Icons.picture_as_pdf, size: 64, color: Colors.grey),
          const SizedBox(height: 16),
          const Text(
            'PDF Viewer',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 8),
          Text(
            'PDF file: ${pdfFile.path}',
            style: const TextStyle(color: Colors.grey),
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 16),
          const Text(
            'Note: Full PDF viewing requires additional setup',
            style: TextStyle(fontSize: 12, color: Colors.orange),
          ),
        ],
      ),
    );
  }

  /// Create image viewer widget
  static Widget createImageViewer(File imageFile) {
    return Image.file(
      imageFile,
      fit: BoxFit.contain,
      errorBuilder: (context, error, stackTrace) {
        return const Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(Icons.error, color: Colors.red, size: 48),
              SizedBox(height: 8),
              Text('Failed to load image'),
            ],
          ),
        );
      },
    );
  }

  /// Create text viewer widget
  static Widget createTextViewer(File textFile) {
    return FutureBuilder<String>(
      future: textFile.readAsString(),
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }

        if (snapshot.hasError) {
          return Center(
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.error, color: Colors.red, size: 48),
                const SizedBox(height: 8),
                Text('Error: ${snapshot.error}'),
              ],
            ),
          );
        }

        return SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: SelectableText(
            snapshot.data ?? '',
            style: const TextStyle(
              fontFamily: 'monospace',
              fontSize: 14,
            ),
          ),
        );
      },
    );
  }

  /// Get appropriate viewer widget based on file type
  static Widget createFileViewer(File file, String fileName) {
    final extension = fileName.split('.').last.toLowerCase();

    switch (extension) {
      case 'pdf':
        return createPDFViewer(file);
      case 'jpg':
      case 'jpeg':
      case 'png':
      case 'gif':
      case 'bmp':
        return createImageViewer(file);
      case 'txt':
        return createTextViewer(file);
      default:
        return _createUnsupportedFileViewer(fileName);
    }
  }

  /// Create widget for unsupported file types
  static Widget _createUnsupportedFileViewer(String fileName) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            getFileIcon(fileName),
            size: 64,
            color: Colors.grey,
          ),
          const SizedBox(height: 16),
          const Text(
            'Preview not available',
            style: TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'File: $fileName',
            style: const TextStyle(color: Colors.grey),
            textAlign: TextAlign.center,
          ),
        ],
      ),
    );
  }

  /// Clean up temporary files
  static Future<void> cleanupTempFiles() async {
    try {
      final tempDir = await getTemporaryDirectory();
      final files = await tempDir.list().toList();

      for (final file in files) {
        if (file is File) {
          try {
            await file.delete();
            debugPrint('🗑️ Cleaned up temp file: ${file.path}');
          } catch (e) {
            debugPrint('⚠️ Could not delete temp file ${file.path}: $e');
          }
        }
      }
    } catch (e) {
      debugPrint('❌ Error cleaning up temp files: $e');
    }
  }

  /// Validate decrypted file integrity
  static Future<bool> validateDecryptedFile(
      File decryptedFile, String originalFileName) async {
    try {
      if (!await decryptedFile.exists()) {
        return false;
      }

      final fileSize = await decryptedFile.length();
      if (fileSize == 0) {
        debugPrint('❌ Decrypted file is empty');
        return false;
      }

      // Basic file type validation based on extension
      final extension = originalFileName.split('.').last.toLowerCase();
      final bytes = await decryptedFile.readAsBytes();

      switch (extension) {
        case 'pdf':
          // PDF files should start with %PDF
          if (bytes.length < 4 ||
              !String.fromCharCodes(bytes.sublist(0, 4)).startsWith('%PDF')) {
            debugPrint('❌ Invalid PDF file signature');
            return false;
          }
          break;

        case 'jpg':
        case 'jpeg':
          // JPEG files should start with FF D8 FF
          if (bytes.length < 3 ||
              bytes[0] != 0xFF ||
              bytes[1] != 0xD8 ||
              bytes[2] != 0xFF) {
            debugPrint('❌ Invalid JPEG file signature');
            return false;
          }
          break;

        case 'png':
          // PNG files should start with 89 50 4E 47 (PNG signature)
          if (bytes.length < 8 ||
              !String.fromCharCodes(bytes.sublist(0, 8))
                  .startsWith('\x89PNG')) {
            debugPrint('❌ Invalid PNG file signature');
            return false;
          }
          break;
      }

      debugPrint('✅ Decrypted file validation passed');
      return true;
    } catch (e) {
      debugPrint('❌ Error validating decrypted file: $e');
      return false;
    }
  }
}
