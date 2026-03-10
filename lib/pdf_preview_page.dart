import 'package:flutter/material.dart';
import 'package:flutter_pdfview/flutter_pdfview.dart';

class PdfPreviewPage extends StatefulWidget {
  final String pdfPath;
  final String documentName;
  final List<String> pageTexts;

  const PdfPreviewPage({
    super.key,
    required this.pdfPath,
    required this.documentName,
    this.pageTexts = const [],
  });

  @override
  State<PdfPreviewPage> createState() => _PdfPreviewPageState();
}

class _PdfPreviewPageState extends State<PdfPreviewPage> {
  int _pages = 0;
  int _currentPage = 0;
  bool _isReady = false;
  String? _errorMessage;
  late List<String> _editableTexts;

  @override
  void initState() {
    super.initState();
    _editableTexts = List<String>.from(widget.pageTexts);
  }

  /// Show an OCR text editing dialog for a specific page
  Future<void> _showEditOcrDialog({int pageIndex = 0}) async {
    final int totalTextPages = _editableTexts.length;
    if (totalTextPages == 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('No OCR text available to edit.'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    // Clamp pageIndex to valid range
    int editPageIndex = pageIndex.clamp(0, totalTextPages - 1);
    final controller =
        TextEditingController(text: _editableTexts[editPageIndex]);

    final result = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (ctx) {
        int currentEditPage = editPageIndex;
        return StatefulBuilder(
          builder: (context, setDialogState) {
            return AlertDialog(
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
              ),
              title: Row(
                children: [
                  const Icon(Icons.edit_note, color: Color(0xFF6868AC)),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      totalTextPages > 1
                          ? 'Edit OCR - Page ${currentEditPage + 1}/$totalTextPages'
                          : 'Edit OCR Text',
                      style: const TextStyle(fontSize: 18),
                    ),
                  ),
                ],
              ),
              content: SizedBox(
                width: double.maxFinite,
                height: 400,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Correct any OCR errors before uploading:',
                      style: TextStyle(fontSize: 12, color: Colors.grey[600]),
                    ),
                    const SizedBox(height: 8),
                    // Page navigation for multi-page
                    if (totalTextPages > 1) ...[
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          IconButton(
                            onPressed: currentEditPage > 0
                                ? () {
                                    // Save current page text before switching
                                    _editableTexts[currentEditPage] =
                                        controller.text;
                                    setDialogState(() {
                                      currentEditPage--;
                                      controller.text =
                                          _editableTexts[currentEditPage];
                                    });
                                  }
                                : null,
                            icon: const Icon(Icons.arrow_back_ios, size: 18),
                          ),
                          Text(
                            'Page ${currentEditPage + 1} of $totalTextPages',
                            style: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                              color: Color(0xFF6868AC),
                            ),
                          ),
                          IconButton(
                            onPressed: currentEditPage < totalTextPages - 1
                                ? () {
                                    // Save current page text before switching
                                    _editableTexts[currentEditPage] =
                                        controller.text;
                                    setDialogState(() {
                                      currentEditPage++;
                                      controller.text =
                                          _editableTexts[currentEditPage];
                                    });
                                  }
                                : null,
                            icon: const Icon(Icons.arrow_forward_ios, size: 18),
                          ),
                        ],
                      ),
                      const SizedBox(height: 4),
                    ],
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
                  onPressed: () {
                    // Save current page text
                    _editableTexts[currentEditPage] = controller.text;
                    Navigator.pop(ctx, {
                      'edited': true,
                      'texts': List<String>.from(_editableTexts),
                    });
                  },
                  icon: const Icon(Icons.save, size: 18),
                  label: const Text('Save Changes'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.green.shade700,
                    foregroundColor: Colors.white,
                  ),
                ),
              ],
            );
          },
        );
      },
    );

    if (result != null && result['edited'] == true && mounted) {
      setState(() {
        _editableTexts = List<String>.from(result['texts'] as List);
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content:
              const Text('OCR text updated. Changes will apply on upload.'),
          backgroundColor: Colors.green.shade600,
          duration: const Duration(seconds: 2),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.documentName.isNotEmpty ? widget.documentName : 'PDF Preview',
        ),
        actions: [
          if (_editableTexts.isNotEmpty)
            IconButton(
              icon: const Icon(Icons.edit_note),
              tooltip: 'Edit OCR Text',
              onPressed: () => _showEditOcrDialog(
                  pageIndex: _currentPage > 0 ? _currentPage - 1 : 0),
            ),
        ],
      ),
      body: Stack(
        children: [
          PDFView(
            filePath: widget.pdfPath,
            enableSwipe: true,
            swipeHorizontal: true,
            autoSpacing: true,
            pageFling: true,
            onError: (error) {
              setState(() {
                _errorMessage = error.toString();
              });
            },
            onRender: (pages) {
              setState(() {
                _pages = pages ?? 0;
                _isReady = true;
              });
            },
            onViewCreated: (controller) {},
            onPageChanged: (page, total) {
              setState(() {
                _currentPage = (page ?? 0) + 1;
                _pages = total ?? _pages;
              });
            },
          ),
          if (!_isReady && _errorMessage == null)
            const Center(child: CircularProgressIndicator()),
          if (_errorMessage != null)
            Center(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  'Failed to load PDF: ${_errorMessage!}',
                  textAlign: TextAlign.center,
                ),
              ),
            ),
          Positioned(
            bottom: 16,
            right: 16,
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.black.withOpacity(0.6),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Text(
                    _pages > 0 ? 'Page $_currentPage / $_pages' : 'Loading...',
                    style: const TextStyle(color: Colors.white, fontSize: 12),
                  ),
                ),
                if (_editableTexts.isNotEmpty) ...[
                  const SizedBox(width: 8),
                  ElevatedButton.icon(
                    onPressed: () => _showEditOcrDialog(
                        pageIndex: _currentPage > 0 ? _currentPage - 1 : 0),
                    icon: const Icon(Icons.edit_note, size: 18),
                    label: const Text('Edit OCR'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF6868AC),
                      foregroundColor: Colors.white,
                    ),
                  ),
                ],
                const SizedBox(width: 8),
                ElevatedButton(
                  onPressed: () => Navigator.of(context).maybePop({
                    'proceed': true,
                    'editedTexts':
                        _editableTexts.isNotEmpty ? _editableTexts : null,
                  }),
                  child: const Text('Proceed to Upload'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
