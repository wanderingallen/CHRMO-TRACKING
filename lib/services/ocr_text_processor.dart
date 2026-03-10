/// OCR Text Processing Utilities
/// Provides text cleanup, normalization, and key extraction for searchable OCR content
library ocr_text_processor;

/// OCR Text Processor - cleans and normalizes OCR output for better searchability
class OcrTextProcessor {
  // Common OCR character substitution errors and their corrections
  static const Map<String, String> _commonOcrErrors = {
    // Number/letter confusion
    '0': 'O', // Context-dependent - handled separately
    '1': 'I', // Context-dependent
    '5': 'S', // Context-dependent
    '8': 'B', // Context-dependent
    // Common misreads
    'rn': 'm',
    'vv': 'w',
    'cl': 'd',
    'cI': 'd',
    'li': 'li', // Keep as is - commonly correct
    'Il': 'Il', // Keep as is
    // Special character errors
    '|': 'I',
    '¦': 'I',
    '!': 'I', // Sometimes misread
    // Punctuation errors
    ',,': ',',
    '..': '.',
    ';;': ';',
    // Quote normalization (curly quotes to straight quotes)
    '\u201C': '"', // Left double quote "
    '\u201D': '"', // Right double quote "
    '\u2018': "'", // Left single quote '
    '\u2019': "'", // Right single quote '
    '`': "'",
    '´': "'",
  };

  // Words commonly found in government/HR documents
  static const List<String> _governmentKeywords = [
    'payroll',
    'salary',
    'employee',
    'department',
    'memorandum',
    'memo',
    'office',
    'order',
    'resolution',
    'ordinance',
    'certificate',
    'clearance',
    'leave',
    'application',
    'request',
    'approval',
    'signature',
    'date',
    'name',
    'position',
    'designation',
    'division',
    'section',
    'unit',
    'budget',
    'fund',
    'disbursement',
    'voucher',
    'receipt',
    'invoice',
    'procurement',
    'purchase',
    'requisition',
    'bidding',
    'contract',
    'travel',
    'itinerary',
    'liquidation',
    'reimbursement',
    'allowance',
    'performance',
    'rating',
    'evaluation',
    'appraisal',
    'training',
    'appointment',
    'promotion',
    'transfer',
    'resignation',
    'retirement',
    'service',
    'record',
    'attendance',
    'overtime',
    'undertime',
    'absent',
    'government',
    'municipal',
    'city',
    'province',
    'barangay',
    'region',
    'republic',
    'philippines',
    'chrmo',
    'hrmo',
    'mayor',
    'governor',
  ];

  /// Clean and normalize OCR text output
  static String cleanOcrText(String rawText) {
    if (rawText.isEmpty) return '';

    String text = rawText;

    // 1. Normalize unicode and special characters
    text = _normalizeUnicode(text);

    // 2. Fix common OCR character errors
    text = _fixCommonErrors(text);

    // 3. Normalize whitespace
    text = _normalizeWhitespace(text);

    // 4. Fix word boundaries and spacing
    text = _fixWordBoundaries(text);

    // 5. Rejoin lines split mid-word by OCR
    text = _rejoinSplitLines(text);

    // 6. Correct common word misspellings using dictionary
    text = _correctCommonMisspellings(text);

    // 7. Normalize dates and numbers
    text = _normalizeDatesAndNumbers(text);

    return text.trim();
  }

  /// Rejoin lines that were split mid-word by OCR (no hyphen, lowercase start)
  static String _rejoinSplitLines(String text) {
    // If a line ends with a lowercase letter and the next line starts with
    // a lowercase letter, they were likely one word/sentence split by OCR.
    return text.replaceAllMapped(
      RegExp(r'([a-z,;])\n([a-z])'),
      (m) => '${m.group(1)} ${m.group(2)}',
    );
  }

  /// Normalize unicode characters to ASCII equivalents
  static String _normalizeUnicode(String text) {
    // Replace common unicode chars with ASCII
    return text
        .replaceAll('\u00A0', ' ') // Non-breaking space
        .replaceAll('\u2013', '-') // En dash
        .replaceAll('\u2014', '-') // Em dash
        .replaceAll('\u2018', "'") // Left single quote
        .replaceAll('\u2019', "'") // Right single quote
        .replaceAll('\u201C', '"') // Left double quote
        .replaceAll('\u201D', '"') // Right double quote
        .replaceAll('\u2022', '*') // Bullet
        .replaceAll('\u00B7', '*') // Middle dot
        .replaceAll('\u2026', '...') // Ellipsis
        .replaceAll('\u00AD', '') // Soft hyphen
        .replaceAll('\u200B', '') // Zero-width space
        .replaceAll('\u200C', '') // Zero-width non-joiner
        .replaceAll('\u200D', '') // Zero-width joiner
        .replaceAll('\uFEFF', ''); // BOM
  }

  /// Fix common OCR character substitution errors
  static String _fixCommonErrors(String text) {
    String result = text;

    // Fix quote variations
    _commonOcrErrors.forEach((error, correction) {
      if (!['0', '1', '5', '8'].contains(error)) {
        result = result.replaceAll(error, correction);
      }
    });

    // Fix 'rn' -> 'm' only when it makes sense (inside words)
    result = result.replaceAllMapped(
      RegExp(r'(\w)rn(\w)', caseSensitive: false),
      (m) {
        final before = m.group(1)!;
        final after = m.group(2)!;
        return '${before}m$after';
      },
    );

    // Fix 'vv' -> 'w' inside words
    result = result.replaceAllMapped(
      RegExp(r'(\w)vv(\w)', caseSensitive: false),
      (m) => '${m.group(1)}w${m.group(2)}',
    );

    // Fix isolated pipe characters that should be 'I' or 'l'
    result = result.replaceAllMapped(
      RegExp(r'(?<=[A-Za-z])\|(?=[A-Za-z])'),
      (m) => 'l',
    );

    return result;
  }

  /// Normalize whitespace - fix multiple spaces, tabs, unusual line breaks
  static String _normalizeWhitespace(String text) {
    return text
        // Replace tabs with spaces
        .replaceAll('\t', ' ')
        // Normalize line endings
        .replaceAll('\r\n', '\n')
        .replaceAll('\r', '\n')
        // Remove multiple consecutive blank lines
        .replaceAll(RegExp(r'\n{3,}'), '\n\n')
        // Replace multiple spaces with single space
        .replaceAll(RegExp(r' {2,}'), ' ')
        // Remove spaces before punctuation
        .replaceAll(RegExp(r' +([.,;:!?])'), r'$1')
        // Ensure space after punctuation (if followed by letter)
        .replaceAllMapped(
          RegExp(r'([.,;:!?])([A-Za-z])'),
          (m) => '${m.group(1)} ${m.group(2)}',
        );
  }

  /// Fix word boundaries where OCR may have split or merged words incorrectly
  static String _fixWordBoundaries(String text) {
    String result = text;

    // Fix words split by newlines that shouldn't be (hyphenation)
    result = result.replaceAllMapped(
      RegExp(r'(\w)-\n(\w)'),
      (m) => '${m.group(1)}${m.group(2)}',
    );

    // Fix common merged words (add space between lowercase-uppercase)
    result = result.replaceAllMapped(
      RegExp(r'([a-z])([A-Z])'),
      (m) => '${m.group(1)} ${m.group(2)}',
    );

    return result;
  }

  /// Correct common misspellings using government document vocabulary
  static String _correctCommonMisspellings(String text) {
    String result = text;

    // Common OCR misspellings in government documents
    final corrections = <String, String>{
      'ernployee': 'employee',
      'ernployer': 'employer',
      'deparlment': 'department',
      'departrnent': 'department',
      'governrnent': 'government',
      'rnemorandom': 'memorandum',
      'rnemorandum': 'memorandum',
      'memoranoum': 'memorandum',
      'memonandum': 'memorandum',
      'offce': 'office',
      'offlce': 'office',
      'siganture': 'signature',
      'signalure': 'signature',
      'appoinlment': 'appointment',
      'appointrnent': 'appointment',
      'certiticate': 'certificate',
      'cerliticate': 'certificate',
      'clearence': 'clearance',
      'payroil': 'payroll',
      'payroli': 'payroll',
      'salery': 'salary',
      'positon': 'position',
      'requisiton': 'requisition',
      'procurernent': 'procurement',
      'disburserment': 'disbursement',
      'recelpt': 'receipt',
      'lnvoice': 'invoice',
      'perforrnance': 'performance',
      'evaluaton': 'evaluation',
      'transter': 'transfer',
      'retirernent': 'retirement',
      'resignaton': 'resignation',
      'applicaton': 'application',
      // Additional government/DILG document terms
      'circuiar': 'circular',
      'circulan': 'circular',
      'annoucement': 'announcement',
      'announcment': 'announcement',
      'advisiory': 'advisory',
      'purchace': 'purchase',
      'reouest': 'request',
      'travei': 'travel',
      'oroer': 'order',
      'oepertment': 'department',
      'oepartment': 'department',
      'dlilg': 'dilg',
      'dilc': 'dilg',
      'napoi com': 'napolcom',
      'napolc0m': 'napolcom',
      'provinciai': 'provincial',
      'municipai': 'municipal',
      'ouezon': 'quezon',
      'maniia': 'manila',
      'phiiippines': 'philippines',
      'philippnes': 'philippines',
      'subjeci': 'subject',
      'oate': 'date',
      'signeo': 'signed',
      'approveo': 'approved',
      'receiveo': 'received',
      'attentiom': 'attention',
      'concerneo': 'concerned',
      // Additional common OCR errors in Filipino government docs
      'cornpliance': 'compliance',
      'cornplete': 'complete',
      'cornmittee': 'committee',
      'cornmunication': 'communication',
      'irnplementation': 'implementation',
      'irnmediate': 'immediate',
      'irnportant': 'important',
      'rnanagement': 'management',
      'rnanager': 'manager',
      'docurnent': 'document',
      'requirernent': 'requirement',
      'assessrnent': 'assessment',
      'developrnent': 'development',
      'environrnent': 'environment',
      'staternent': 'statement',
      'payrnent': 'payment',
      'treatrnent': 'treatment',
      'achievernent': 'achievement',
      'attachrnent': 'attachment',
      'arnount': 'amount',
      'arnended': 'amended',
      'ernployment': 'employment',
      'cornpensation': 'compensation',
      'recornmendation': 'recommendation',
      'irnplement': 'implement',
      'cornmunity': 'community',
      'adrninistration': 'administration',
      'adrnin': 'admin',
      'inforrn': 'inform',
      'inforrned': 'informed',
      'confirrn': 'confirm',
      'confirrned': 'confirmed',
      'perforrn': 'perform',
      'perforrned': 'performed',
      'reforrn': 'reform',
      'platforrn': 'platform',
      'uniforrn': 'uniform',
      'norrnalize': 'normalize',
      'forrnulate': 'formulate',
      'subrn': 'submit',
      'subrnit': 'submit',
      'subrnitted': 'submitted',
    };

    corrections.forEach((wrong, correct) {
      result = result.replaceAll(RegExp(wrong, caseSensitive: false), correct);
    });

    return result;
  }

  /// Normalize dates and numbers for consistent formatting
  static String _normalizeDatesAndNumbers(String text) {
    String result = text;

    // Fix common date OCR errors (O instead of 0, etc.)
    result = result.replaceAllMapped(
      RegExp(r'(\d{1,2})[/\-](\d{1,2})[/\-](\d{2,4})'),
      (m) {
        final month = m.group(1)!.replaceAll('O', '0').replaceAll('o', '0');
        final day = m.group(2)!.replaceAll('O', '0').replaceAll('o', '0');
        final year = m.group(3)!.replaceAll('O', '0').replaceAll('o', '0');
        return '$month/$day/$year';
      },
    );

    // Fix peso amounts (common in Philippine documents)
    result = result.replaceAllMapped(
      RegExp(r'[Pp][Hh][Pp]?\s*(\d[\d,\.]*)', caseSensitive: false),
      (m) => 'PHP ${m.group(1)}',
    );

    // Fix currency symbol followed by amount
    result = result.replaceAllMapped(
      RegExp(r'₱\s*(\d)'),
      (m) => '₱${m.group(1)}',
    );

    return result;
  }

  /// Extract searchable keywords from OCR text
  static Map<String, dynamic> extractSearchableKeys(String ocrText) {
    final cleanedText = cleanOcrText(ocrText);
    final words = cleanedText.toLowerCase().split(RegExp(r'\s+'));

    // ── Document type detection (expanded, priority-ordered) ──
    String? documentType;
    // Check multi-word types first
    final lowerText = cleanedText.toLowerCase();
    final multiWordTypes = <String, String>{
      'travel order': 'travel order',
      'executive order': 'executive order',
      'office order': 'office order',
      'special order': 'special order',
      'purchase order': 'purchase order',
      'purchase request': 'purchase request',
      'leave application': 'leave application',
      'leave of absence': 'leave',
      'notice of meeting': 'notice',
      'certificate of employment': 'certificate',
      'certificate of appearance': 'certificate',
      'salary grade': 'payroll',
      'personal data sheet': 'personal data sheet',
      'performance rating': 'evaluation',
      'daily time record': 'daily time record',
      'notice of salary': 'payroll',
      'disbursement voucher': 'voucher',
      'obligation request': 'obligation request',
    };
    for (final entry in multiWordTypes.entries) {
      if (lowerText.contains(entry.key)) {
        documentType = entry.value;
        break;
      }
    }
    // Fall back to single-word match
    if (documentType == null) {
      for (final type in [
        'payroll',
        'memorandum',
        'memo',
        'certificate',
        'clearance',
        'leave',
        'appointment',
        'order',
        'resolution',
        'ordinance',
        'voucher',
        'receipt',
        'invoice',
        'requisition',
        'contract',
        'travel',
        'liquidation',
        'evaluation',
        'appraisal',
        'circular',
        'advisory',
        'proclamation',
        'notice',
        'endorsement',
        'indorsement',
        'communication',
        'report',
        'itinerary',
        'bid',
        'abstract',
        'canvass',
        'inventory',
        'payslip',
        'certification',
        'attendance',
        'permit',
      ]) {
        if (words.contains(type)) {
          documentType = type;
          break;
        }
      }
    }

    // ── Name extraction (expanded patterns for Filipino documents) ──
    final names = <String>{};
    final namePatterns = [
      // Labeled name fields
      RegExp(
          r'(?:name|employee|staff|personnel|applicant|claimant|payee|grantee)\s*[:\-]?\s*([A-Z][a-zA-Z\u00f1\u00d1.]+(?:\s+[A-Z][a-zA-Z\u00f1\u00d1.]+){1,4})',
          caseSensitive: false),
      // Action attributions
      RegExp(
          r'(?:prepared by|submitted by|approved by|certified by|attested by|recommending approval|noted by|reviewed by|received by|authorized by|requested by|endorsed by|verified by|conforme|certified correct)\s*[:\-]?\s*([A-Z][a-zA-Z\u00f1\u00d1.]+(?:\s+[A-Z][a-zA-Z\u00f1\u00d1.]+){1,4})',
          caseSensitive: false),
      // Titles before names (common in PH government docs)
      RegExp(
          r'(?:HON\.|Hon\.|DR\.|Dr\.|ENGR\.|Engr\.|ATTY\.|Atty\.|MR\.|Mr\.|MS\.|Ms\.|MRS\.|Mrs\.)\s+([A-Z][a-zA-Z\u00f1\u00d1.]+(?:\s+[A-Z][a-zA-Z\u00f1\u00d1.]+){1,4})',
          caseSensitive: false),
      // ALL-CAPS names (very common in Filipino docs: DELA CRUZ, JUAN PEDRO)
      RegExp(
          r'\b([A-Z][A-Z\s\u00d1.]{3,}(?:\s+(?:JR|SR|III|IV|II)\.?)?)\b(?=\s*\n|\s*$|\s*,)'),
    ];
    for (final pattern in namePatterns) {
      for (final match in pattern.allMatches(cleanedText)) {
        String? name = match.group(1)?.trim();
        if (name != null && name.length > 2) {
          // Filter out common false positives
          final lower = name.toLowerCase();
          if (!_falsePositiveNames.contains(lower) &&
              !RegExp(r'^\d').hasMatch(name)) {
            // Title-case the name if all uppercase
            if (name == name.toUpperCase() && name.length > 3) {
              name = name.split(RegExp(r'\s+')).map((w) {
                if (w.length <= 3 &&
                    ['JR', 'SR', 'II', 'III', 'IV', 'DE', 'DEL', 'LA', 'LOS']
                        .contains(w.toUpperCase())) {
                  return w.toLowerCase();
                }
                return w[0].toUpperCase() + w.substring(1).toLowerCase();
              }).join(' ');
            }
            names.add(name);
          }
        }
      }
    }

    // ── Date extraction (improved for Filipino date formats) ──
    final dates = <String>{};
    final datePatterns = [
      // Standard numeric dates: 01/15/2025, 2025-01-15
      RegExp(r'\b(\d{1,2}[/\-]\d{1,2}[/\-]\d{2,4})\b'),
      RegExp(r'\b(\d{4}[/\-]\d{1,2}[/\-]\d{1,2})\b'),
      // Written dates: January 15, 2025 or 15 January 2025
      RegExp(
          r'\b((?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},?\s+\d{4})\b',
          caseSensitive: false),
      RegExp(
          r'\b(\d{1,2}\s+(?:January|February|March|April|May|June|July|August|September|October|November|December),?\s+\d{4})\b',
          caseSensitive: false),
      // Abbreviated months: Jan 15, 2025
      RegExp(
          r'\b((?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+\d{1,2},?\s+\d{4})\b',
          caseSensitive: false),
    ];
    for (final pattern in datePatterns) {
      for (final match in pattern.allMatches(cleanedText)) {
        dates.add(match.group(1) ?? match.group(0)!);
      }
    }

    // ── Amount extraction (peso amounts, plain numbers with comma formatting) ──
    final amounts = <String>{};
    final amountPatterns = [
      RegExp(r'(?:₱|PHP|Php|P)\s*[\d,]+(?:\.\d{1,2})?'),
      RegExp(
          r'(?:amount|total|salary|gross|net|deduction|tax)\s*[:\-]?\s*(?:₱|PHP|Php|P)?\s*([\d,]+(?:\.\d{1,2})?)',
          caseSensitive: false),
    ];
    for (final pattern in amountPatterns) {
      for (final match in pattern.allMatches(cleanedText)) {
        final amt = match.group(0)!.trim();
        // Only add if it looks like a real amount (has at least 2 digits)
        if (RegExp(r'\d.*\d').hasMatch(amt)) {
          amounts.add(amt);
        }
      }
    }

    // ── Department / Office extraction (improved for PH government) ──
    final departments = <String>{};
    final deptPatterns = [
      RegExp(
          r'(?:department|office|division|section|unit|bureau|commission)\s+(?:of\s+)?([A-Za-z\s]+?)(?:\n|$|[,.])',
          caseSensitive: false),
      // Common PH department abbreviations
      RegExp(
          r'\b(CHRMO|HRMO|CMO|CBO|CTO|CACCO|CADO|CPDO|GSO|DILG|DENR|DBM|COA|CSC|DOH|DEPED|DSWD|LGU|DPWH|PNP|BFP|BJMP)\b',
          caseSensitive: false),
    ];
    for (final pattern in deptPatterns) {
      for (final match in pattern.allMatches(cleanedText)) {
        final dept = (match.group(1) ?? match.group(0))?.trim();
        if (dept != null && dept.length >= 2) {
          departments.add(dept);
        }
      }
    }

    // ── Reference / Control numbers ──
    final referenceNumbers = <String>{};
    final refPatterns = [
      RegExp(
          r'(?:ref|reference|control|tracking)\s*(?:no|number|#)?[.\s:]*([A-Z0-9][\w\-]+)',
          caseSensitive: false),
      RegExp(r'(?:no|number)\s*[.:]\s*([A-Z0-9][\w\-]{3,})',
          caseSensitive: false),
      // Common PH reference formats: 2025-001, CHRMO-2025-001
      RegExp(r'\b([A-Z]{2,6}[\-]\d{4}[\-]\d{1,6})\b'),
      RegExp(r'\b(\d{4}[\-]\d{3,6})\b'),
    ];
    for (final pattern in refPatterns) {
      for (final match in pattern.allMatches(cleanedText)) {
        final ref = (match.group(1) ?? match.group(0))?.trim();
        if (ref != null && ref.length > 3) {
          referenceNumbers.add(ref);
        }
      }
    }

    // ── Subject / RE line extraction ──
    final subjects = <String>[];
    final subjectPatterns = [
      RegExp(r'(?:subject|re|subj|regarding)\s*[:\-]\s*(.+?)(?:\n|$)',
          caseSensitive: false),
      RegExp(r'(?:purpose)\s*[:\-]\s*(.+?)(?:\n|$)', caseSensitive: false),
    ];
    for (final pattern in subjectPatterns) {
      for (final match in pattern.allMatches(cleanedText)) {
        final subj = match.group(1)?.trim();
        if (subj != null && subj.length > 3) {
          subjects.add(subj);
        }
      }
    }

    // ── Position / Designation extraction ──
    final positions = <String>[];
    final posPatterns = [
      RegExp(r'(?:position|designation|rank|title)\s*[:\-]\s*(.+?)(?:\n|$)',
          caseSensitive: false),
    ];
    for (final pattern in posPatterns) {
      for (final match in pattern.allMatches(cleanedText)) {
        final pos = match.group(1)?.trim();
        if (pos != null && pos.length > 2) {
          positions.add(pos);
        }
      }
    }

    // ── Build searchable keywords from all extracted data ──
    final keywords = <String>{};

    // Add document type
    if (documentType != null) {
      keywords.addAll(documentType.toLowerCase().split(RegExp(r'\s+')));
    }

    // Add names (split into individual words for searching)
    for (final name in names) {
      keywords.addAll(name.toLowerCase().split(RegExp(r'\s+')));
    }

    // Add matched government keywords
    for (final word in words) {
      if (_governmentKeywords.contains(word)) {
        keywords.add(word);
      }
    }

    // Add department keywords
    for (final dept in departments) {
      keywords.addAll(dept.toLowerCase().split(RegExp(r'\s+')));
    }

    // Add subject keywords
    for (final subj in subjects) {
      for (final w in subj.toLowerCase().split(RegExp(r'\s+'))) {
        if (w.length > 2) keywords.add(w);
      }
    }

    // Add position keywords
    for (final pos in positions) {
      for (final w in pos.toLowerCase().split(RegExp(r'\s+'))) {
        if (w.length > 2) keywords.add(w);
      }
    }

    return {
      'cleaned_text': cleanedText,
      'document_type': documentType,
      'names': names.toList(),
      'dates': dates.toList(),
      'amounts': amounts.toList(),
      'departments': departments.toList(),
      'reference_numbers': referenceNumbers.toList(),
      'subjects': subjects,
      'positions': positions,
      'keywords': keywords.toList(),
      'original_length': ocrText.length,
      'cleaned_length': cleanedText.length,
    };
  }

  /// Common words that look like names but aren't
  static const Set<String> _falsePositiveNames = {
    'the',
    'and',
    'for',
    'with',
    'from',
    'this',
    'that',
    'which',
    'shall',
    'will',
    'have',
    'been',
    'were',
    'are',
    'not',
    'but',
    'all',
    'can',
    'had',
    'her',
    'was',
    'one',
    'our',
    'out',
    'day',
    'get',
    'has',
    'him',
    'his',
    'how',
    'its',
    'may',
    'new',
    'now',
    'old',
    'see',
    'way',
    'who',
    'did',
    'let',
    'say',
    'she',
    'too',
    'use',
    'republic',
    'philippines',
    'department',
    'office',
    'municipal',
    'city',
    'province',
    'government',
    'order',
    'memorandum',
    'certificate',
    'resolution',
    'ordinance',
    'document',
    'tracking',
    'system',
    'page',
    'date',
    'subject',
    'total',
    'amount',
    'name',
    'address',
    'position',
    'signature',
    'approved',
    'pending',
    'completed',
    'received',
    'submitted',
    'certified',
  };

  /// Generate a searchable string optimized for database FULLTEXT or LIKE queries
  static String generateSearchableContent(String ocrText) {
    final extracted = extractSearchableKeys(ocrText);
    final parts = <String>[];

    // Add document type prominently
    if (extracted['document_type'] != null) {
      parts.add('TYPE:${extracted['document_type'].toString().toUpperCase()}');
    }

    // Add names
    final names = extracted['names'] as List<String>? ?? [];
    for (final name in names) {
      parts.add('NAME:$name');
    }

    // Add departments
    final departments = extracted['departments'] as List<String>? ?? [];
    for (final dept in departments) {
      parts.add('DEPT:$dept');
    }

    // Add reference numbers
    final refs = extracted['reference_numbers'] as List<String>? ?? [];
    for (final ref in refs) {
      parts.add('REF:$ref');
    }

    // Add amounts
    final amounts = extracted['amounts'] as List<String>? ?? [];
    for (final amount in amounts) {
      parts.add('AMOUNT:$amount');
    }

    // Add subjects
    final subjects = extracted['subjects'] as List<String>? ?? [];
    for (final subj in subjects) {
      parts.add('SUBJECT:$subj');
    }

    // Add positions
    final positions = extracted['positions'] as List<String>? ?? [];
    for (final pos in positions) {
      parts.add('POSITION:$pos');
    }

    // Add keywords
    final keywords = extracted['keywords'] as List<String>? ?? [];
    if (keywords.isNotEmpty) {
      parts.add('KEYWORDS:${keywords.join(',')}');
    }

    // Add cleaned text
    parts.add(extracted['cleaned_text'] as String? ?? '');

    return parts.join('\n\n');
  }
}
