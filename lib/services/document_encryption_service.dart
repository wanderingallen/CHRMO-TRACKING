import 'dart:convert';
import 'dart:io';

import 'package:collection/collection.dart';
import 'package:crypto/crypto.dart';
import 'package:encrypt/encrypt.dart' as encrypt;
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'document_classifier.dart';

/// Document encryption service for securing sensitive files like payroll
class DocumentEncryptionService {
  static const String _encryptionKeyPref = 'document_encryption_key';
  static const String _sensitiveTypesKey = 'sensitive_document_types';

  // Default sensitive document types that should be encrypted
  static const List<String> _defaultSensitiveTypes = [
    'payroll',
    'salary',
    'payslip',
    'compensation',
    'benefits',
    'hr',
    'employee',
    'personal',
    'confidential',
    'financial',
    'bank',
    'account',
    'tax',
    'sss',
    'philhealth',
    'pagibig'
  ];

  /// Get or create encryption key for document encryption
  static Future<encrypt.Key> getEncryptionKey() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      String? storedKey = prefs.getString(_encryptionKeyPref);

      if (storedKey == null || storedKey.isEmpty) {
        // Generate new encryption key
        final key = encrypt.Key.fromSecureRandom(32);
        storedKey = key.base64;
        await prefs.setString(_encryptionKeyPref, storedKey);
        debugPrint('🔐 Generated new encryption key for documents');
        return key;
      }

      return encrypt.Key.fromBase64(storedKey);
    } catch (e) {
      debugPrint('❌ Error getting encryption key: $e');
      // Fallback to derived key from device info
      return _generateFallbackKey();
    }
  }

  /// Generate fallback encryption key from device-specific data
  static encrypt.Key _generateFallbackKey() {
    final timestamp = DateTime.now().millisecondsSinceEpoch.toString();
    final deviceSeed = 'flutter_doc_enc_${timestamp}_fallback';
    final keyBytes = sha256.convert(utf8.encode(deviceSeed)).bytes;
    return encrypt.Key(Uint8List.fromList(keyBytes));
  }

  /// Check if a document type should be encrypted based on content and filename
  static bool shouldEncryptDocument({
    required String documentType,
    required String fileName,
    String? ocrContent,
  }) {
    // Use the document classifier for more accurate classification
    final classification = DocumentClassifier.classifyDocument(
      fileName: fileName,
      ocrContent: ocrContent,
      documentType: documentType,
    );

    debugPrint('🔍 Document classification: $classification');
    return classification.requiresEncryption;
  }

  /// Encrypt file data before upload
  static Future<Uint8List> encryptFileData(Uint8List fileData) async {
    try {
      final key = await getEncryptionKey();
      final encrypter =
          encrypt.Encrypter(encrypt.AES(key, mode: encrypt.AESMode.gcm));

      // Generate random IV
      final iv = encrypt.IV.fromSecureRandom(16);

      // Encrypt the data
      final encrypted = encrypter.encryptBytes(fileData, iv: iv);

      // Combine IV and encrypted data for storage
      final combinedData = <int>[];
      combinedData.addAll(iv.bytes);
      combinedData.addAll(encrypted.bytes);

      debugPrint('🔐 Successfully encrypted ${fileData.length} bytes');
      return Uint8List.fromList(combinedData);
    } catch (e) {
      debugPrint('❌ Error encrypting file data: $e');
      rethrow;
    }
  }

  /// Decrypt file data for viewing
  static Future<Uint8List> decryptFileData(Uint8List encryptedData) async {
    try {
      if (encryptedData.length < 16) {
        throw Exception('Invalid encrypted data: too short');
      }

      final key = await getEncryptionKey();
      final encrypter =
          encrypt.Encrypter(encrypt.AES(key, mode: encrypt.AESMode.gcm));

      // Extract IV (first 16 bytes)
      final ivBytes = encryptedData.sublist(0, 16);
      final iv = encrypt.IV(ivBytes);

      // Extract encrypted content
      final cipherText = encryptedData.sublist(16);

      // Decrypt the data
      final decrypted =
          encrypter.decryptBytes(encrypt.Encrypted(cipherText), iv: iv);

      debugPrint('🔓 Successfully decrypted ${encryptedData.length} bytes');
      return Uint8List.fromList(decrypted);
    } catch (e) {
      debugPrint('❌ Error decrypting file data: $e');
      rethrow;
    }
  }

  /// Encrypt file and save to new path
  static Future<String> encryptFile(String inputPath, String outputPath) async {
    try {
      final inputFile = File(inputPath);
      if (!await inputFile.exists()) {
        throw Exception('Input file does not exist: $inputPath');
      }

      final fileData = await inputFile.readAsBytes();
      final encryptedData = await encryptFileData(fileData);

      final outputFile = File(outputPath);
      await outputFile.writeAsBytes(encryptedData);

      debugPrint('🔐 File encrypted: $inputPath -> $outputPath');
      return outputPath;
    } catch (e) {
      debugPrint('❌ Error encrypting file: $e');
      rethrow;
    }
  }

  /// Decrypt file and save to new path
  static Future<String> decryptFile(String inputPath, String outputPath) async {
    try {
      final inputFile = File(inputPath);
      if (!await inputFile.exists()) {
        throw Exception('Input file does not exist: $inputPath');
      }

      final encryptedData = await inputFile.readAsBytes();
      final decryptedData = await decryptFileData(encryptedData);

      final outputFile = File(outputPath);
      await outputFile.writeAsBytes(decryptedData);

      debugPrint('🔓 File decrypted: $inputPath -> $outputPath');
      return outputPath;
    } catch (e) {
      debugPrint('❌ Error decrypting file: $e');
      rethrow;
    }
  }

  /// Generate encrypted filename
  static String generateEncryptedFileName(String originalFileName) {
    final timestamp = DateTime.now().millisecondsSinceEpoch;
    final extension = originalFileName.split('.').last.toLowerCase();
    final hash = sha256
        .convert(utf8.encode('$originalFileName$timestamp'))
        .toString()
        .substring(0, 8);
    return 'enc_${timestamp}_$hash.$extension.enc';
  }

  /// Check if file is encrypted (based on extension)
  static bool isEncryptedFile(String fileName) {
    return fileName.toLowerCase().endsWith('.enc');
  }

  /// Get file metadata for encrypted uploads
  static Map<String, String> getEncryptedFileMetadata({
    required String originalFileName,
    required String encryptedFileName,
    required bool isEncrypted,
  }) {
    return {
      'original_filename': originalFileName,
      'encrypted_filename': encryptedFileName,
      'is_encrypted': isEncrypted.toString(),
      'encryption_version': '1.0',
      'encryption_timestamp': DateTime.now().toIso8601String(),
    };
  }

  /// Validate encryption/decryption integrity
  static Future<bool> validateEncryptionIntegrity(
      String originalPath, String encryptedPath) async {
    try {
      final originalFile = File(originalPath);
      final encryptedFile = File(encryptedPath);

      if (!await originalFile.exists() || !await encryptedFile.exists()) {
        return false;
      }

      final originalData = await originalFile.readAsBytes();
      final encryptedData = await encryptedFile.readAsBytes();

      final decryptedData = await decryptFileData(encryptedData);

      // Compare original and decrypted data
      return const ListEquality().equals(originalData, decryptedData);
    } catch (e) {
      debugPrint('❌ Error validating encryption integrity: $e');
      return false;
    }
  }

  /// Clear stored encryption key (for security purposes)
  static Future<void> clearEncryptionKey() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_encryptionKeyPref);
      debugPrint('🗑️ Encryption key cleared from storage');
    } catch (e) {
      debugPrint('❌ Error clearing encryption key: $e');
    }
  }

  /// Get encryption status information
  static Map<String, dynamic> getEncryptionStatus() {
    return {
      'service_available': true,
      'algorithm': 'AES-256-GCM',
      'key_length': 256,
      'iv_length': 128,
      'supported_formats': ['PDF', 'JPG', 'PNG', 'DOC', 'DOCX'],
      'sensitive_types': _defaultSensitiveTypes,
    };
  }
}
