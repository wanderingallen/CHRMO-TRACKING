import 'package:flutter/material.dart';

/// Document classification service for identifying sensitive document types
class DocumentClassifier {
  /// Classify document based on filename, OCR content, and context
  static DocumentClassification classifyDocument({
    required String fileName,
    String? ocrContent,
    String? documentType,
  }) {
    final searchText = [
      fileName.toLowerCase(),
      ocrContent?.toLowerCase() ?? '',
      documentType?.toLowerCase() ?? '',
    ].join(' ');

    // Priority-based classification
    if (_isPayrollDocument(searchText)) {
      return DocumentClassification(
        type: DocumentType.payroll,
        sensitivityLevel: SensitivityLevel.high,
        requiresEncryption: true,
        confidence: _calculateConfidence(searchText, _payrollKeywords),
        keywords: _findMatchingKeywords(searchText, _payrollKeywords),
      );
    }

    if (_isHRDocument(searchText)) {
      return DocumentClassification(
        type: DocumentType.hr,
        sensitivityLevel: SensitivityLevel.high,
        requiresEncryption: true,
        confidence: _calculateConfidence(searchText, _hrKeywords),
        keywords: _findMatchingKeywords(searchText, _hrKeywords),
      );
    }

    if (_isFinancialDocument(searchText)) {
      return DocumentClassification(
        type: DocumentType.financial,
        sensitivityLevel: SensitivityLevel.medium,
        requiresEncryption: true,
        confidence: _calculateConfidence(searchText, _financialKeywords),
        keywords: _findMatchingKeywords(searchText, _financialKeywords),
      );
    }

    if (_isPersonalDocument(searchText)) {
      return DocumentClassification(
        type: DocumentType.personal,
        sensitivityLevel: SensitivityLevel.medium,
        requiresEncryption: true,
        confidence: _calculateConfidence(searchText, _personalKeywords),
        keywords: _findMatchingKeywords(searchText, _personalKeywords),
      );
    }

    // Default classification
    return DocumentClassification(
      type: DocumentType.general,
      sensitivityLevel: SensitivityLevel.low,
      requiresEncryption: false,
      confidence: 0.0,
      keywords: [],
    );
  }

  /// Check if document should be automatically encrypted
  static bool shouldEncrypt({
    required String fileName,
    String? ocrContent,
    String? documentType,
  }) {
    final classification = classifyDocument(
      fileName: fileName,
      ocrContent: ocrContent,
      documentType: documentType,
    );
    return classification.requiresEncryption;
  }

  // Payroll-specific keywords
  static const List<String> _payrollKeywords = [
    'payroll',
    'payslip',
    'salary',
    'wage',
    'compensation',
    'net pay',
    'gross pay',
    'deduction',
    'withholding tax',
    'sss',
    'philhealth',
    'pagibig',
    'tin',
    'employee id',
    'pay period',
    'hourly rate',
    'overtime',
    'bonus',
    'commission',
    '13th month',
    'holiday pay',
    'leave pay',
    'salary loan',
    'cash advance',
    'payroll register',
    'payroll summary',
    'payroll report'
  ];

  // HR-related keywords
  static const List<String> _hrKeywords = [
    'employee',
    'staff',
    'personnel',
    'human resources',
    'hr',
    'recruitment',
    'hiring',
    'termination',
    'resignation',
    'performance',
    'evaluation',
    'attendance',
    'time sheet',
    'leave',
    'vacation',
    'sick leave',
    'maternity',
    'benefits',
    'insurance',
    'retirement',
    'pension',
    'separation',
    'employment contract',
    'job description',
    'organizational chart'
  ];

  // Financial keywords
  static const List<String> _financialKeywords = [
    'bank',
    'account',
    'statement',
    'transaction',
    'deposit',
    'withdrawal',
    'balance',
    'invoice',
    'receipt',
    'payment',
    'expense',
    'budget',
    'audit',
    'financial',
    'accounting',
    'tax',
    'vat',
    'income',
    'revenue',
    'cost',
    'profit',
    'loss',
    'asset',
    'liability',
    'equity',
    'capital',
    'investment'
  ];

  // Personal information keywords
  static const List<String> _personalKeywords = [
    'personal',
    'private',
    'confidential',
    'address',
    'phone',
    'email',
    'birth',
    'age',
    'gender',
    'civil status',
    'dependents',
    'emergency',
    'contact',
    'identification',
    'id',
    'passport',
    'driver license',
    'medical',
    'health',
    'record',
    'history',
    'diagnosis',
    'treatment'
  ];

  static bool _isPayrollDocument(String searchText) {
    return _containsKeywords(searchText, _payrollKeywords, minMatches: 2);
  }

  static bool _isHRDocument(String searchText) {
    return _containsKeywords(searchText, _hrKeywords, minMatches: 2);
  }

  static bool _isFinancialDocument(String searchText) {
    return _containsKeywords(searchText, _financialKeywords, minMatches: 2);
  }

  static bool _isPersonalDocument(String searchText) {
    return _containsKeywords(searchText, _personalKeywords, minMatches: 2);
  }

  static bool _containsKeywords(String text, List<String> keywords,
      {int minMatches = 1}) {
    int matches = 0;
    for (final keyword in keywords) {
      if (text.contains(keyword)) {
        matches++;
        if (matches >= minMatches) return true;
      }
    }
    return false;
  }

  static double _calculateConfidence(String text, List<String> keywords) {
    int matches = 0;
    for (final keyword in keywords) {
      if (text.contains(keyword)) matches++;
    }
    return matches / keywords.length;
  }

  static List<String> _findMatchingKeywords(
      String text, List<String> keywords) {
    return keywords.where((keyword) => text.contains(keyword)).toList();
  }

  /// Get document type display name
  static String getDocumentTypeDisplayName(DocumentType type) {
    switch (type) {
      case DocumentType.payroll:
        return 'Payroll';
      case DocumentType.hr:
        return 'HR Document';
      case DocumentType.financial:
        return 'Financial';
      case DocumentType.personal:
        return 'Personal';
      case DocumentType.general:
        return 'General';
    }
  }

  /// Get sensitivity level display name
  static String getSensitivityDisplayName(SensitivityLevel level) {
    switch (level) {
      case SensitivityLevel.high:
        return 'High';
      case SensitivityLevel.medium:
        return 'Medium';
      case SensitivityLevel.low:
        return 'Low';
    }
  }

  /// Get sensitivity color for UI
  static Color getSensitivityColor(SensitivityLevel level) {
    switch (level) {
      case SensitivityLevel.high:
        return Colors.red;
      case SensitivityLevel.medium:
        return Colors.orange;
      case SensitivityLevel.low:
        return Colors.green;
    }
  }

  /// Get appropriate icon for document type
  static IconData getDocumentTypeIcon(DocumentType type) {
    switch (type) {
      case DocumentType.payroll:
        return Icons.attach_money;
      case DocumentType.hr:
        return Icons.people;
      case DocumentType.financial:
        return Icons.account_balance;
      case DocumentType.personal:
        return Icons.person;
      case DocumentType.general:
        return Icons.description;
    }
  }
}

/// Document classification result
class DocumentClassification {
  final DocumentType type;
  final SensitivityLevel sensitivityLevel;
  final bool requiresEncryption;
  final double confidence;
  final List<String> keywords;

  DocumentClassification({
    required this.type,
    required this.sensitivityLevel,
    required this.requiresEncryption,
    required this.confidence,
    required this.keywords,
  });

  @override
  String toString() {
    return 'DocumentClassification(type: $type, sensitivity: $sensitivityLevel, encrypt: $requiresEncryption, confidence: ${(confidence * 100).toStringAsFixed(1)}%)';
  }

  Map<String, dynamic> toJson() {
    return {
      'type': type.toString(),
      'sensitivity_level': sensitivityLevel.toString(),
      'requires_encryption': requiresEncryption,
      'confidence': confidence,
      'keywords': keywords,
    };
  }
}

/// Document types enum
enum DocumentType {
  payroll,
  hr,
  financial,
  personal,
  general,
}

/// Sensitivity levels enum
enum SensitivityLevel {
  high,
  medium,
  low,
}
