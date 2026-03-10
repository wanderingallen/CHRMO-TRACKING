import 'dart:convert';
import 'dart:typed_data';
import 'package:encrypt/encrypt.dart';
import 'package:crypto/crypto.dart';
import 'package:shared_preferences/shared_preferences.dart';

class EncryptionService {
  static const String _encryptionKeyKey = 'encryption_key';
  static EncryptionService? _instance;
  late Encrypter _encrypter;
  late IV _iv;

  EncryptionService._() {
    _initializeEncryption();
  }

  static EncryptionService get instance {
    _instance ??= EncryptionService._();
    return _instance!;
  }

  Future<void> _initializeEncryption() async {
    final prefs = await SharedPreferences.getInstance();
    String? storedKey = prefs.getString(_encryptionKeyKey);

    if (storedKey == null) {
      // Generate a new encryption key if none exists
      final key = Key.fromSecureRandom(32); // 256-bit key
      final iv = IV.fromSecureRandom(16); // 128-bit IV

      // Store the key securely (in production, consider using flutter_secure_storage)
      await prefs.setString(_encryptionKeyKey, key.base64);

      _encrypter = Encrypter(AES(key));
      _iv = iv;
    } else {
      // Use existing key
      final key = Key.fromBase64(storedKey);
      _encrypter = Encrypter(AES(key));
      _iv = IV.fromSecureRandom(16); // Generate new IV for each session
    }
  }

  /// Encrypt file bytes for payroll documents
  Future<Uint8List> encryptPayrollDocument(Uint8List fileBytes) async {
    try {
      final encrypted = _encrypter.encryptBytes(fileBytes, iv: _iv);
      // Prepend IV to encrypted data for decryption later
      final combinedData = _iv.bytes + encrypted.bytes;
      return Uint8List.fromList(combinedData);
    } catch (e) {
      throw Exception('Failed to encrypt document: $e');
    }
  }

  /// Decrypt file bytes for payroll documents
  Future<Uint8List> decryptPayrollDocument(Uint8List encryptedData) async {
    try {
      // Extract IV from the first 16 bytes
      if (encryptedData.length < 16) {
        throw Exception('Invalid encrypted data format');
      }

      final ivBytes = encryptedData.sublist(0, 16);
      final cipherBytes = encryptedData.sublist(16);

      final iv = IV(ivBytes);
      final encrypted = Encrypted(cipherBytes);

      final decrypted = _encrypter.decryptBytes(encrypted, iv: iv);
      return Uint8List.fromList(decrypted);
    } catch (e) {
      throw Exception('Failed to decrypt document: $e');
    }
  }

  /// Generate SHA-256 hash for file integrity verification
  String generateFileHash(Uint8List fileBytes) {
    final digest = sha256.convert(fileBytes);
    return digest.toString();
  }

  /// Check if a file is encrypted (detects payroll documents by checking for common payroll keywords)
  bool isPayrollDocument(String fileName, String? ocrContent) {
    final fileNameLower = fileName.toLowerCase();
    final ocrLower = ocrContent?.toLowerCase() ?? '';

    final payrollKeywords = [
      'payroll',
      'salary',
      'payslip',
      'pay slip',
      'wage',
      'compensation',
      'deduction',
      'net pay',
      'gross pay',
      'income',
      'tax',
      'withholding',
      'employee id',
      'pay period',
      'pay date',
      'ytd',
      'year to date'
    ];

    // Check filename and OCR content for payroll keywords
    for (final keyword in payrollKeywords) {
      if (fileNameLower.contains(keyword) || ocrLower.contains(keyword)) {
        return true;
      }
    }

    return false;
  }

  /// Encrypt OCR text content
  Future<String> encryptText(String text) async {
    try {
      final encrypted = _encrypter.encrypt(text, iv: _iv);
      // Store IV with encrypted text
      return '${_iv.base64}:${encrypted.base64}';
    } catch (e) {
      throw Exception('Failed to encrypt text: $e');
    }
  }

  /// Decrypt OCR text content
  Future<String> decryptText(String encryptedText) async {
    try {
      final parts = encryptedText.split(':');
      if (parts.length != 2) {
        throw Exception('Invalid encrypted text format');
      }

      final iv = IV.fromBase64(parts[0]);
      final encrypted = Encrypted.fromBase64(parts[1]);

      return _encrypter.decrypt(encrypted, iv: iv);
    } catch (e) {
      throw Exception('Failed to decrypt text: $e');
    }
  }

  /// Generate a secure filename for encrypted files
  String generateSecureFilename(String originalFilename, String timestamp) {
    final extension = originalFilename.split('.').last.toLowerCase();
    final hash = generateFileHash(utf8.encode(originalFilename + timestamp));
    return 'ENC_${hash.substring(0, 16)}.$extension';
  }

  /// Add encryption metadata to OCR content
  String addEncryptionMetadata(String originalContent, bool isEncrypted) {
    final metadata = [
      '=== ENCRYPTION METADATA ===',
      'Encrypted: ${isEncrypted ? 'YES' : 'NO'}',
      'Encryption Date: ${DateTime.now().toIso8601String()}',
      'Encryption Method: AES-256-CBC',
      '============================',
      '',
      originalContent
    ].join('\n');

    return metadata;
  }
}
