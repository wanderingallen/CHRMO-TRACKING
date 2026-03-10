import 'dart:math' as math;

import 'package:flutter/material.dart';

/// CHRMO Document Type classification result
class CHRMODocumentClassification {
  final CHRMODocumentType type;
  final double confidence;
  final List<String> matchedKeywords;
  final List<CHRMODocumentMatch> allMatches;

  CHRMODocumentClassification({
    required this.type,
    required this.confidence,
    required this.matchedKeywords,
    required this.allMatches,
  });

  /// Get a confidence label for UI display
  String get confidenceLabel {
    if (confidence >= 0.7) return 'High';
    if (confidence >= 0.4) return 'Medium';
    if (confidence >= 0.2) return 'Low';
    return 'Very Low';
  }

  /// Get confidence color for UI
  Color get confidenceColor {
    if (confidence >= 0.7) return Colors.green;
    if (confidence >= 0.4) return Colors.orange;
    if (confidence >= 0.2) return Colors.amber;
    return Colors.grey;
  }

  @override
  String toString() =>
      'CHRMODocumentClassification(type: ${type.displayName}, confidence: ${(confidence * 100).toStringAsFixed(1)}%, keywords: $matchedKeywords)';
}

/// Individual document type match result
class CHRMODocumentMatch {
  final CHRMODocumentType type;
  final double confidence;
  final List<String> matchedKeywords;

  CHRMODocumentMatch({
    required this.type,
    required this.confidence,
    required this.matchedKeywords,
  });
}

/// CHRMO Panabo document types enum
enum CHRMODocumentType {
  payroll,
  memo,
  travelOrder,
  activityDesign,
  purchaseRequest,
  purchaseOrder,
  advisory,
  announcement,
  general,
}

extension CHRMODocumentTypeExtension on CHRMODocumentType {
  String get displayName {
    switch (this) {
      case CHRMODocumentType.payroll:
        return 'Payroll';
      case CHRMODocumentType.memo:
        return 'Memo';
      case CHRMODocumentType.travelOrder:
        return 'Travel Order';
      case CHRMODocumentType.activityDesign:
        return 'Activity Design';
      case CHRMODocumentType.purchaseRequest:
        return 'Purchase Request';
      case CHRMODocumentType.purchaseOrder:
        return 'Purchase Order';
      case CHRMODocumentType.advisory:
        return 'Advisory';
      case CHRMODocumentType.announcement:
        return 'Announcement';
      case CHRMODocumentType.general:
        return 'General Document';
    }
  }

  IconData get icon {
    switch (this) {
      case CHRMODocumentType.payroll:
        return Icons.attach_money;
      case CHRMODocumentType.memo:
        return Icons.note_alt;
      case CHRMODocumentType.travelOrder:
        return Icons.flight_takeoff;
      case CHRMODocumentType.activityDesign:
        return Icons.event;
      case CHRMODocumentType.purchaseRequest:
        return Icons.request_quote;
      case CHRMODocumentType.purchaseOrder:
        return Icons.shopping_cart;
      case CHRMODocumentType.advisory:
        return Icons.info_outline;
      case CHRMODocumentType.announcement:
        return Icons.campaign;
      case CHRMODocumentType.general:
        return Icons.description;
    }
  }

  Color get color {
    switch (this) {
      case CHRMODocumentType.payroll:
        return Colors.green;
      case CHRMODocumentType.memo:
        return const Color(0xFF6868AC);
      case CHRMODocumentType.travelOrder:
        return Colors.orange;
      case CHRMODocumentType.activityDesign:
        return Colors.purple;
      case CHRMODocumentType.purchaseRequest:
        return Colors.teal;
      case CHRMODocumentType.purchaseOrder:
        return Colors.indigo;
      case CHRMODocumentType.advisory:
        return Colors.amber;
      case CHRMODocumentType.announcement:
        return Colors.red;
      case CHRMODocumentType.general:
        return Colors.grey;
    }
  }
}

/// AI-powered document classifier for CHRMO Panabo document types
/// Uses keyword/phrase scoring without any ML training required
class CHRMODocumentClassifier {
  /// Singleton instance for performance
  static final CHRMODocumentClassifier _instance =
      CHRMODocumentClassifier._internal();
  factory CHRMODocumentClassifier() => _instance;
  CHRMODocumentClassifier._internal();

  // ==================== KEYWORD DEFINITIONS ====================

  static String _normalizeForSearch(String input) {
    // Lowercase, replace non-alphanumerics with spaces, collapse whitespace.
    // This helps when OCR drops punctuation or inserts odd characters.
    final lower = input.toLowerCase();
    final spaced = lower.replaceAll(RegExp(r'[^a-z0-9]+'), ' ');
    return spaced.replaceAll(RegExp(r'\s+'), ' ').trim();
  }

  static String _compactForSearch(String input) {
    // Remove all non-alphanumerics.
    // This helps when OCR removes spaces: e.g. "PURCHASEREQUEST".
    return input.toLowerCase().replaceAll(RegExp(r'[^a-z0-9]+'), '');
  }

  static String _normalizeNeedle(String needle) {
    return _normalizeForSearch(needle);
  }

  static String _compactNeedle(String needle) {
    return _compactForSearch(needle);
  }

  /// Payroll document keywords - salary, wages, deductions
  static const List<String> _payrollKeywords = [
    'payroll',
    'salary',
    'wage',
    'wages',
    'net pay',
    'gross pay',
    'deduction',
    'deductions',
    'withholding tax',
    'sss',
    'gsis',
    'philhealth',
    'pag-ibig',
    'pagibig',
    'hdmf',
    'overtime',
    'ot pay',
    'bonus',
    'allowance',
    '13th month',
    'thirteenth month',
    'payslip',
    'pay slip',
    'earnings',
    'compensation',
    'daily rate',
    'monthly rate',
    'time record',
    'dtr',
    'attendance',
    'leave pay',
    'holiday pay',
    'night differential',
    'hazard pay',
    'rice subsidy',
    'meal allowance',
    'payroll register',
    'payroll summary',
    'payroll list',
    'payroll report',
  ];

  /// Payroll strong indicators (phrases worth more points)
  static const List<String> _payrollPhrases = [
    'payroll for the period',
    'net amount due',
    'total deductions',
    'total earnings',
    'employee payroll',
    'salary computation',
    'pay period',
    'salary grade',
    'step increment',
  ];

  /// Memo document keywords
  static const List<String> _memoKeywords = [
    'memorandum',
    'memo',
    'office order',
    'circular',
    'directive',
    // field markers often lose punctuation in OCR
    'to',
    'from',
    'subject',
    'thru',
    'through',
    're',
    'reference',
    'for compliance',
    'for information',
    'for your guidance',
    'effectivity',
    'immediate effect',
    'hereby',
    'office of',
    'department head',
    'concerned personnel',
    'all employees',
    'internal communication',
  ];

  static const List<String> _memoPhrases = [
    'office memorandum',
    'internal memorandum',
    'memorandum circular',
    'memorandum circular no',
    'memo to all',
    'for strict compliance',
    'for your information and guidance',
    'this is to inform',
    'please be informed',
    'you are hereby directed',
  ];

  /// Travel Order keywords
  static const List<String> _travelOrderKeywords = [
    'travel order',
    'travel authority',
    'official travel',
    'official business',
    'destination',
    'itinerary',
    'per diem',
    'transportation',
    'travel expenses',
    'departure',
    'arrival',
    'duration',
    'travel date',
    'inclusive dates',
    'mode of travel',
    'vehicle',
    'airfare',
    'land travel',
    'accommodation',
    'lodging',
    'travel allowance',
    'reimbursement',
    'purpose of travel',
    'approved travel',
  ];

  static const List<String> _travelOrderPhrases = [
    'travel order no',
    'travel order number',
    'itinerary of travel',
    'authority to travel',
    'official travel order',
    'approved to travel',
    'is authorized to travel',
    'travel expenses shall be charged',
    'inclusive of travel',
  ];

  /// Activity Design keywords
  static const List<String> _activityDesignKeywords = [
    'activity design',
    'activity proposal',
    'program design',
    'event design',
    'event proposal',
    'rationale',
    'objectives',
    'methodology',
    'expected output',
    'expected outcome',
    'target participants',
    'venue',
    'resource persons',
    'speaker',
    'facilitator',
    'budget breakdown',
    'program flow',
    'schedule of activities',
    'registration',
    'opening program',
    'closing program',
    'certificate',
    'seminar',
    'training',
    'workshop',
    'orientation',
    'conference',
    'summit',
    'assembly',
    'forum',
    'webinar',
  ];

  static const List<String> _activityDesignPhrases = [
    'activity design for',
    'proposed activity',
    'program of activities',
    'schedule of activities',
    'project rationale',
    'general objective',
    'specific objectives',
    'target beneficiaries',
    'expected participants',
    'resource speaker',
    'activity flow',
  ];

  /// Purchase Request keywords
  static const List<String> _purchaseRequestKeywords = [
    'purchase request',
    'requisition',
    'request for purchase',
    'item description',
    'quantity',
    'unit cost',
    'estimated cost',
    'total cost',
    'unit of measure',
    'purpose of purchase',
    'requesting office',
    'end user',
    'budget',
    'appropriation',
    'charged to',
    'availability of funds',
    'procurement',
    'specification',
    'specs',
    'brand',
    'model',
    'prepared by',
    'requested by',
    'approved by',
  ];

  static const List<String> _purchaseRequestPhrases = [
    'purchase request no',
    'purchase request number',
    'p r no',
    'pr no',
    'pr number',
    'request to purchase',
    'items requested',
    'purpose of request',
    'fund availability',
    'certified availability of funds',
    'requested by',
    'approved for purchase',
  ];

  /// Purchase Order keywords
  static const List<String> _purchaseOrderKeywords = [
    'purchase order',
    'supplier',
    'vendor',
    'delivery date',
    'delivery schedule',
    'payment terms',
    'terms of payment',
    'warranty',
    'amount',
    'total amount',
    'unit price',
    'extended price',
    'mode of procurement',
    'direct contracting',
    'public bidding',
    'shopping',
    'negotiated procurement',
    'award',
    'contract',
    'conforme',
    'place of delivery',
    'delivery period',
  ];

  static const List<String> _purchaseOrderPhrases = [
    'purchase order no',
    'purchase order number',
    'p o no',
    'po no',
    'po number',
    'supplier name',
    'delivery period',
    'place of delivery',
    'terms and conditions',
    'mode of payment',
    'contract price',
    'total contract price',
    'awarded to',
  ];

  /// Advisory keywords
  static const List<String> _advisoryKeywords = [
    'advisory',
    'public advisory',
    'notice',
    'public notice',
    'attention',
    'alert',
    'warning',
    'reminder',
    'important',
    'urgent',
    'information',
    'guidelines',
    'precaution',
    'safety',
    'health',
    'weather',
    'suspension',
    'cancellation',
    'postponement',
    'rescheduled',
    'effective immediately',
    'until further notice',
  ];

  static const List<String> _advisoryPhrases = [
    'public advisory',
    'office advisory',
    'this is to advise',
    'please be advised',
    'kindly be advised',
    'for your information',
    'the public is advised',
    'effective immediately',
    'until further notice',
    'suspension of classes',
    'suspension of work',
  ];

  /// Announcement keywords
  static const List<String> _announcementKeywords = [
    'announcement',
    'announcing',
    'we are pleased',
    'congratulations',
    'welcome',
    'new appointment',
    'appointment',
    'promotion',
    'retirement',
    'recognition',
    'award',
    'achievement',
    'milestone',
    'celebration',
    'invitation',
    'you are invited',
    'cordially invites',
    'event',
    'ceremony',
    'program',
    'launching',
    'opening',
    'inauguration',
  ];

  static const List<String> _announcementPhrases = [
    'we are pleased to announce',
    'it is with great pleasure',
    'announcing the appointment',
    'please join us',
    'you are cordially invited',
    'in celebration of',
    'we congratulate',
    'happy to announce',
    'this is to announce',
  ];

  // ==================== CLASSIFICATION LOGIC ====================

  /// Classify OCR text and return the best matching document type with confidence
  CHRMODocumentClassification classify(String ocrText) {
    if (ocrText.trim().isEmpty) {
      return CHRMODocumentClassification(
        type: CHRMODocumentType.general,
        confidence: 0.0,
        matchedKeywords: [],
        allMatches: [],
      );
    }

    final searchText = _normalizeForSearch(ocrText);
    final compactText = _compactForSearch(ocrText);
    final allMatches = <CHRMODocumentMatch>[];

    // Calculate scores for each document type
    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.payroll,
      searchText,
      compactText,
      _payrollKeywords,
      _payrollPhrases,
    ));

    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.memo,
      searchText,
      compactText,
      _memoKeywords,
      _memoPhrases,
    ));

    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.travelOrder,
      searchText,
      compactText,
      _travelOrderKeywords,
      _travelOrderPhrases,
    ));

    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.activityDesign,
      searchText,
      compactText,
      _activityDesignKeywords,
      _activityDesignPhrases,
    ));

    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.purchaseRequest,
      searchText,
      compactText,
      _purchaseRequestKeywords,
      _purchaseRequestPhrases,
    ));

    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.purchaseOrder,
      searchText,
      compactText,
      _purchaseOrderKeywords,
      _purchaseOrderPhrases,
    ));

    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.advisory,
      searchText,
      compactText,
      _advisoryKeywords,
      _advisoryPhrases,
    ));

    allMatches.add(_scoreDocumentType(
      CHRMODocumentType.announcement,
      searchText,
      compactText,
      _announcementKeywords,
      _announcementPhrases,
    ));

    // Sort by confidence descending
    allMatches.sort((a, b) => b.confidence.compareTo(a.confidence));

    // Get the best match
    final bestMatch = allMatches.first;

    // Only fall back to General when there are literally no matches.
    if (bestMatch.matchedKeywords.isEmpty || bestMatch.confidence <= 0.0) {
      return CHRMODocumentClassification(
        type: CHRMODocumentType.general,
        confidence: 0.0,
        matchedKeywords: [],
        allMatches: allMatches,
      );
    }

    return CHRMODocumentClassification(
      type: bestMatch.type,
      confidence: bestMatch.confidence,
      matchedKeywords: bestMatch.matchedKeywords,
      allMatches: allMatches,
    );
  }

  /// Score a single document type against the OCR text
  CHRMODocumentMatch _scoreDocumentType(
    CHRMODocumentType type,
    String searchText,
    String compactText,
    List<String> keywords,
    List<String> phrases,
  ) {
    final matchedKeywords = <String>[];
    double score = 0.0;

    bool containsNeedle(String needle) {
      final n = _normalizeNeedle(needle);
      if (n.isEmpty) return false;
      if (searchText.contains(n)) return true;
      // If OCR removed spaces/punctuation, try compact match for multi-word needles.
      final cn = _compactNeedle(needle);
      return cn.length >= 6 && compactText.contains(cn);
    }

    // Count phrase matches first (stronger indicators)
    for (final phrase in phrases) {
      if (containsNeedle(phrase)) {
        matchedKeywords.add(phrase);
        score += 5.0;
      }
    }

    // Count keyword matches (lighter indicators)
    for (final keyword in keywords) {
      // Avoid noisy 2-letter abbreviations causing false positives.
      final k = keyword.trim();
      if (k.length <= 2) continue;
      if (containsNeedle(k)) {
        matchedKeywords.add(k);
        score += 1.0;
      }
    }

    // Convert raw score into a usable confidence that isn't diluted by long lists.
    // This behaves well in practice: a few strong matches => medium/high confidence.
    //  - score ~3  => ~0.39
    //  - score ~6  => ~0.63
    //  - score ~9  => ~0.78
    final confidence =
        score <= 0 ? 0.0 : (1.0 - math.exp(-score / 6.0)).clamp(0.0, 1.0);

    return CHRMODocumentMatch(
      type: type,
      confidence: confidence,
      matchedKeywords: matchedKeywords,
    );
  }

  /// Get top N document type suggestions
  List<CHRMODocumentMatch> getTopSuggestions(String ocrText, {int count = 3}) {
    final result = classify(ocrText);
    return result.allMatches
        .where((m) => m.confidence > 0.1)
        .take(count)
        .toList();
  }

  /// Quick check if text likely belongs to a specific type
  bool isLikelyType(String ocrText, CHRMODocumentType type) {
    final result = classify(ocrText);
    return result.type == type && result.confidence >= 0.3;
  }

  /// Get all document type options for dropdown
  static List<String> get allDocumentTypes => [
        'Payroll',
        'Memo',
        'Travel Order',
        'Activity Design',
        'Purchase Request',
        'Purchase Order',
        'Advisory',
        'Announcement',
      ];

  /// Convert display name to enum
  static CHRMODocumentType fromDisplayName(String name) {
    switch (name.toLowerCase()) {
      case 'payroll':
        return CHRMODocumentType.payroll;
      case 'memo':
        return CHRMODocumentType.memo;
      case 'travel order':
        return CHRMODocumentType.travelOrder;
      case 'activity design':
        return CHRMODocumentType.activityDesign;
      case 'purchase request':
        return CHRMODocumentType.purchaseRequest;
      case 'purchase order':
        return CHRMODocumentType.purchaseOrder;
      case 'advisory':
        return CHRMODocumentType.advisory;
      case 'announcement':
        return CHRMODocumentType.announcement;
      default:
        return CHRMODocumentType.general;
    }
  }
}
