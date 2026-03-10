import 'dart:convert';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_mlkit_text_recognition/google_mlkit_text_recognition.dart';
import 'package:http/http.dart' as http;
import 'package:share_plus/share_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'camera_page.dart';

// This page will now use the file_picker package for actual file selection.

class UploadPage extends StatefulWidget {
  const UploadPage({super.key});

  @override
  State<UploadPage> createState() => _UploadPageState();
}

class _UploadPageState extends State<UploadPage>
    with SingleTickerProviderStateMixin {
  final List<PlatformFile> _selectedFiles = []; // Stores actual file objects
  bool _isUploading = false;
  double _uploadProgress = 0.0;
  String _uploadMessage = '';

  // OCR Results
  String _recognizedText = '';
  List<TextBlock> _textBlocks = [];
  List<TextLine> _textLines = [];
  List<TextElement> _textElements = [];
  double _averageConfidence = 0.0;
  int _selectedTab = 0; // 0: Raw Text, 1: Organized, 2: Analysis
  bool _hasOCRResults = false;

  // Simulate upload history
  final List<Map<String, String>> _uploadHistory = [
    {
      'name': 'Contract_2024_Q4.pdf',
      'status': 'Uploaded',
      'date': '2025-05-20'
    },
    {
      'name': 'Marketing_Plan_V3.docx',
      'status': 'Uploaded',
      'date': '2025-05-18'
    },
    {
      'name': 'Employee_Photo_John.jpg',
      'status': 'Uploaded',
      'date': '2025-05-15'
    },
  ];

  late AnimationController _animationController;
  late Animation<double> _fadeAnimation;

  @override
  void initState() {
    super.initState();
    _animationController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 400),
    );
    _fadeAnimation =
        Tween<double>(begin: 0.0, end: 1.0).animate(CurvedAnimation(
      parent: _animationController,
      curve: Curves.easeOutCubic,
    ));
  }

  @override
  void dispose() {
    _animationController.dispose();
    super.dispose();
  }

  Future<void> _uploadExtractedContentToServer({
    required String extractedText,
    required String docRef,
    required String title,
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final token = (prefs.getString('api_token') ?? '').trim();
      if (token.isEmpty) {
        return;
      }

      String? root = (prefs.getString('server_root') ?? '').trim();
      root = root.isEmpty
          ? (prefs.getString('detected_server_url') ?? '').trim()
          : root;
      if (root.isEmpty) {
        return;
      }
      // detected_server_url is often saved as <root>/api
      if (root.endsWith('/api')) {
        root = root.substring(0, root.length - 4);
      }

      final uri = Uri.parse('$root/api/extracted_content.php');
      final resp = await http
          .post(
            uri,
            headers: {
              'Content-Type': 'application/json',
              'Authorization': 'Bearer $token',
              'Accept': 'application/json',
            },
            body: jsonEncode({
              'doc_ref': docRef,
              'title': title,
              'extracted_text': extractedText,
            }),
          )
          .timeout(const Duration(seconds: 12));

      if (!mounted) return;

      if (resp.statusCode >= 200 && resp.statusCode < 300) {
        // Keep silent; extracted content stored securely.
        return;
      }
    } catch (_) {
      // Best-effort only.
    }
  }

  Future<void> _pickFile() async {
    FilePickerResult? result = await FilePicker.platform.pickFiles(
      type: FileType.custom,
      allowedExtensions: ['jpg', 'jpeg', 'png', 'pdf'],
      allowMultiple: true,
    );

    if (result != null) {
      setState(() {
        _selectedFiles.addAll(result.files);
        _uploadMessage = '';
        _hasOCRResults = false;
        _recognizedText = '';
        _textBlocks.clear();
        _textLines.clear();
        _textElements.clear();
        _averageConfidence = 0.0;

        if (!_animationController.isAnimating &&
            _animationController.status != AnimationStatus.completed) {
          _animationController.forward(from: 0.0);
        }
      });

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
            content: Text(
                '${result.files.length} file(s) selected for OCR processing!')),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('File selection cancelled.')),
      );
    }
  }

  Future<void> _uploadFiles() async {
    if (_selectedFiles.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
            content: Text('Please select at least one file to process.')),
      );
      return;
    }

    setState(() {
      _isUploading = true;
      _uploadProgress = 0.0;
      _uploadMessage = 'Starting OCR processing...';
      _hasOCRResults = false;
    });

    try {
      final StringBuffer allRecognizedText = StringBuffer();
      final List<TextBlock> allBlocks = [];
      final List<TextLine> allLines = [];
      final List<TextElement> allElements = [];
      double totalConfidence = 0.0;
      int confidenceCount = 0;

      for (int fileIndex = 0; fileIndex < _selectedFiles.length; fileIndex++) {
        final file = _selectedFiles[fileIndex];

        setState(() {
          _uploadProgress = (fileIndex / _selectedFiles.length) * 0.8;
          _uploadMessage =
              'Processing ${file.name}... (${fileIndex + 1}/${_selectedFiles.length})';
        });

        if (file.path != null && _isImageFile(file.name)) {
          try {
            final inputImage = InputImage.fromFilePath(file.path!);
            final textRecognizer =
                TextRecognizer(script: TextRecognitionScript.latin);
            final RecognizedText recognizedText =
                await textRecognizer.processImage(inputImage);

            // Add file separator
            if (allRecognizedText.isNotEmpty) {
              allRecognizedText.writeln('\n\n=== ${file.name} ===\n');
            } else {
              allRecognizedText.writeln('=== ${file.name} ===\n');
            }

            // Process each block
            for (TextBlock block in recognizedText.blocks) {
              allBlocks.add(block);

              for (TextLine line in block.lines) {
                allLines.add(line);

                if (line.confidence != null) {
                  totalConfidence += line.confidence!;
                  confidenceCount++;
                }

                final StringBuffer lineBuffer = StringBuffer();
                for (TextElement element in line.elements) {
                  allElements.add(element);
                  if (element.confidence == null || element.confidence! > 0.5) {
                    lineBuffer.write('${element.text} ');
                  }
                }

                final lineText = lineBuffer.toString().trim();
                if (lineText.isNotEmpty) {
                  allRecognizedText.writeln(lineText);
                }
              }
            }

            textRecognizer.close();
          } catch (e) {
            allRecognizedText.writeln('Error processing ${file.name}: $e');
          }
        }

        await Future.delayed(const Duration(milliseconds: 300));
      }

      setState(() {
        _uploadProgress = 0.9;
        _uploadMessage = 'Finalizing OCR results...';
      });

      await Future.delayed(const Duration(milliseconds: 500));

      setState(() {
        _isUploading = false;
        _uploadProgress = 1.0;
        _uploadMessage = 'OCR Processing Complete!';
        _recognizedText = allRecognizedText.toString().trim();
        _textBlocks = allBlocks;
        _textLines = allLines;
        _textElements = allElements;
        _averageConfidence =
            confidenceCount > 0 ? totalConfidence / confidenceCount : 0.0;
        _hasOCRResults = _recognizedText.isNotEmpty;

        // Add to history
        for (var file in _selectedFiles) {
          _uploadHistory.insert(0, {
            'name': file.name,
            'status': 'Processed',
            'date': DateTime.now().toIso8601String().substring(0, 10),
          });
        }

        _selectedFiles.clear();
        _animationController.reverse();
      });

      if (_hasOCRResults) {
        final now = DateTime.now().millisecondsSinceEpoch;
        final docRef = 'OCR_$now';
        await _uploadExtractedContentToServer(
          extractedText: _recognizedText,
          docRef: docRef,
          title: 'OCR Scan',
        );
      }

      if (_hasOCRResults) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('OCR processing completed successfully!')),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
              content: Text('No text found in the selected images.')),
        );
      }
    } catch (e) {
      setState(() {
        _isUploading = false;
        _uploadMessage = 'Error during OCR processing: $e';
      });

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('OCR processing failed: $e')),
      );
    }
  }

  bool _isImageFile(String fileName) {
    final lowerCase = fileName.toLowerCase();
    return lowerCase.endsWith('.jpg') ||
        lowerCase.endsWith('.jpeg') ||
        lowerCase.endsWith('.png');
  }

  void _removeFile(int index) {
    setState(() {
      _selectedFiles.removeAt(index);
      if (_selectedFiles.isEmpty) {
        _animationController.reverse();
        _hasOCRResults = false;
        _recognizedText = '';
        _textBlocks.clear();
        _textLines.clear();
        _textElements.clear();
        _averageConfidence = 0.0;
      }
    });
  }

  void _clearAllSelectedFiles() {
    setState(() {
      _selectedFiles.clear();
      _animationController.reverse();
      _uploadMessage = '';
      _hasOCRResults = false;
      _recognizedText = '';
      _textBlocks.clear();
      _textLines.clear();
      _textElements.clear();
      _averageConfidence = 0.0;
    });
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(content: Text('All selected files cleared.')),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('OCR Scan - Gallery'),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
        systemOverlayStyle: SystemUiOverlayStyle.light,
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFE3F2FD), // Very light blue
              Color(0xFFBBDEFB) // Slightly darker light blue
            ],
          ),
        ),
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16.0),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _buildOCRActionButtons(),
              const SizedBox(height: 20),
              _buildUploadArea(),
              const SizedBox(height: 20),
              _buildSelectedFilesList(),
              if (_hasOCRResults) ...[
                const SizedBox(height: 20),
                _buildOCRResults(),
              ],
              if (_selectedFiles.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 10.0),
                  child: Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      onPressed: _clearAllSelectedFiles,
                      icon: const Icon(Icons.clear_all, color: Colors.red),
                      label: const Text('Clear All',
                          style: TextStyle(color: Colors.red)),
                    ),
                  ),
                ),
              if (_isUploading || _uploadMessage.isNotEmpty) ...[
                const SizedBox(height: 20),
                _buildUploadProgress(),
              ],
              const SizedBox(height: 30),
              _buildUploadButton(),
              const SizedBox(height: 30),
              _buildUploadHistory(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildOCRActionButtons() {
    return Card(
      elevation: 8,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [Colors.white, const Color(0xFF6868AC).withOpacity(0.08)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Column(
          children: [
            const Text(
              'OCR Scan Options',
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.bold,
                color: Color(0xFF52528A),
              ),
            ),
            const SizedBox(height: 20),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceEvenly,
              children: [
                // Camera button
                _buildOCRActionButton(
                  icon: Icons.camera_alt,
                  label: 'Take Photo',
                  onPressed: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(
                          builder: (context) => const CameraPage()),
                    );
                  },
                ),
                // Gallery button
                _buildOCRActionButton(
                  icon: Icons.photo_library,
                  label: 'Choose from Gallery',
                  onPressed: _pickFile,
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildOCRActionButton({
    required IconData icon,
    required String label,
    required VoidCallback onPressed,
  }) {
    return Column(
      children: [
        InkWell(
          onTap: onPressed,
          borderRadius: BorderRadius.circular(50),
          child: Container(
            width: 80,
            height: 80,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              gradient: const LinearGradient(
                colors: [Color(0xFF6868AC), Color(0xFF52528A)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFF6868AC).withOpacity(0.5),
                  spreadRadius: 3,
                  blurRadius: 15,
                  offset: const Offset(0, 8),
                ),
              ],
            ),
            child: Icon(
              icon,
              color: Colors.white,
              size: 40,
            ),
          ),
        ),
        const SizedBox(height: 12),
        Text(
          label,
          style: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.bold,
            color: Colors.black87,
          ),
        ),
      ],
    );
  }

  Widget _buildUploadArea() {
    return Card(
      elevation: 8,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      child: InkWell(
        onTap: _isUploading ? null : _pickFile, // Disable tap if uploading
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [Colors.white, const Color(0xFF6868AC).withOpacity(0.08)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(20),
            boxShadow: [
              BoxShadow(
                color: const Color(0xFF6868AC).withOpacity(0.5),
                spreadRadius: 2,
                blurRadius: 10,
                offset: const Offset(0, 5),
              ),
            ],
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(
                Icons.cloud_upload,
                size: 80,
                color: Color(0xFF6868AC),
              ),
              const SizedBox(height: 15),
              Text(
                _isUploading
                    ? 'OCR processing...'
                    : 'Select images for OCR scanning',
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 18,
                  color: Color(0xFF52528A),
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Supported formats: JPG, PNG, PDF. Images will be processed for text extraction.',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 13,
                  color: Colors.grey.shade600,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildSelectedFilesList() {
    return FadeTransition(
      opacity: _fadeAnimation,
      child: _selectedFiles.isEmpty
          ? const SizedBox.shrink()
          : Card(
              elevation: 4,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(15)),
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Selected Files (${_selectedFiles.length})',
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF52528A),
                      ),
                    ),
                    const Divider(height: 20, thickness: 1),
                    ListView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: _selectedFiles.length,
                      itemBuilder: (context, index) {
                        final file = _selectedFiles[index];
                        return ListTile(
                          leading: Icon(_getFileIcon(file.name),
                              color: const Color(0xFF6868AC).withOpacity(0.6)),
                          title: Text(file.name),
                          subtitle: Text(
                              '${(file.size / 1024).toStringAsFixed(1)} KB'),
                          trailing: IconButton(
                            icon: const Icon(Icons.remove_circle,
                                color: Colors.red),
                            onPressed: () => _removeFile(index),
                          ),
                        );
                      },
                    ),
                  ],
                ),
              ),
            ),
    );
  }

  Widget _buildUploadProgress() {
    return Column(
      children: [
        LinearProgressIndicator(
          value: _uploadProgress,
          backgroundColor: Colors.grey.shade300,
          valueColor: AlwaysStoppedAnimation<Color>(Colors.green.shade500),
          minHeight: 10,
          borderRadius: BorderRadius.circular(5),
        ),
        const SizedBox(height: 10),
        Text(
          _uploadMessage,
          style: const TextStyle(
            fontSize: 16,
            color: Color(0xFF52528A),
            fontWeight: FontWeight.w500,
          ),
        ),
      ],
    );
  }

  Widget _buildUploadButton() {
    return ElevatedButton.icon(
      onPressed: _isUploading || _selectedFiles.isEmpty
          ? null
          : _uploadFiles, // Disable if uploading or no files selected
      icon: _isUploading
          ? const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(
                color: Colors.white,
                strokeWidth: 2,
              ),
            )
          : const Icon(Icons.upload_file, color: Colors.white),
      label: Text(
        _isUploading ? 'Processing...' : 'Process OCR',
        style: const TextStyle(color: Colors.white, fontSize: 18),
      ),
      style: ElevatedButton.styleFrom(
        backgroundColor: const Color(0xFF6868AC),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
        padding: const EdgeInsets.symmetric(vertical: 15),
        elevation: 8,
      ),
    );
  }

  Widget _buildUploadHistory() {
    return Card(
      elevation: 6,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'OCR Processing History',
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.bold,
                color: Color(0xFF52528A),
              ),
            ),
            const Divider(height: 25, thickness: 1.5),
            _uploadHistory.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(16.0),
                      child: Text(
                        'No OCR processing history yet.',
                        style: TextStyle(
                            fontSize: 16, color: Colors.grey.shade600),
                      ),
                    ),
                  )
                : ListView.builder(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    itemCount: _uploadHistory.length,
                    itemBuilder: (context, index) {
                      final entry = _uploadHistory[index];
                      return Card(
                        elevation: 2,
                        margin: const EdgeInsets.symmetric(vertical: 6),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(10)),
                        child: ListTile(
                          leading: Icon(_getFileIcon(entry['name']!),
                              color: Colors.green.shade400),
                          title: Text(entry['name']!),
                          subtitle: Text(
                              'Status: ${entry['status']} | Date: ${entry['date']}'),
                          trailing: Icon(Icons.check_circle,
                              color: Colors.green.shade600),
                          onTap: () {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                  content: Text('Tapped on ${entry['name']}')),
                            );
                            // TODO: Implement viewing details of past upload
                          },
                        ),
                      );
                    },
                  ),
          ],
        ),
      ),
    );
  }

  Widget _buildOCRResults() {
    return Card(
      elevation: 8,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [Colors.green.shade50, Colors.green.shade100],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(20),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              '📄 OCR Results',
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.bold,
                color: Colors.green.shade800,
              ),
            ),
            const SizedBox(height: 16),

            // Stats Row
            if (_averageConfidence > 0)
              Container(
                padding: const EdgeInsets.all(12),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: Colors.green.shade200),
                ),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  children: [
                    _buildStatItem('Confidence',
                        '${(_averageConfidence * 100).toStringAsFixed(1)}%'),
                    _buildStatItem('Blocks', '${_textBlocks.length}'),
                    _buildStatItem('Lines', '${_textLines.length}'),
                    _buildStatItem('Elements', '${_textElements.length}'),
                  ],
                ),
              ),

            // Tab Bar
            Container(
              decoration: BoxDecoration(
                color: Colors.grey[200],
                borderRadius: BorderRadius.circular(25),
              ),
              child: Row(
                children: [
                  _buildTabButton('Raw Text', 0),
                  _buildTabButton('Organized', 1),
                  _buildTabButton('Analysis', 2),
                ],
              ),
            ),

            const SizedBox(height: 16),

            // Action Buttons
            Row(
              children: [
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: () => _copyToClipboard(_getCurrentTabText()),
                    icon: const Icon(Icons.copy, size: 18),
                    label: const Text('Copy'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF6868AC),
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: () => _shareText(_getCurrentTabText()),
                    icon: const Icon(Icons.share, size: 18),
                    label: const Text('Share'),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: Colors.green,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                  ),
                ),
              ],
            ),

            const SizedBox(height: 16),

            // OCR Content
            Container(
              constraints: const BoxConstraints(maxHeight: 300),
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.grey[50],
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: Colors.grey.shade300),
              ),
              child: SingleChildScrollView(
                child: SelectableText(
                  _getCurrentTabText(),
                  style: const TextStyle(
                    fontSize: 14,
                    height: 1.5,
                    fontFamily: 'monospace',
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTabButton(String title, int index) {
    final isSelected = _selectedTab == index;
    return Expanded(
      child: GestureDetector(
        onTap: () => setState(() => _selectedTab = index),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12),
          decoration: BoxDecoration(
            color: isSelected ? Colors.green : Colors.transparent,
            borderRadius: BorderRadius.circular(20),
          ),
          child: Text(
            title,
            textAlign: TextAlign.center,
            style: TextStyle(
              color: isSelected ? Colors.white : Colors.grey[600],
              fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
              fontSize: 12,
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildStatItem(String label, String value) {
    return Column(
      children: [
        Text(
          value,
          style: const TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.bold,
            color: Colors.green,
          ),
        ),
        Text(
          label,
          style: TextStyle(
            fontSize: 12,
            color: Colors.grey[600],
          ),
        ),
      ],
    );
  }

  String _getCurrentTabText() {
    switch (_selectedTab) {
      case 0:
        return _recognizedText.isNotEmpty
            ? _recognizedText
            : 'No text recognized yet';
      case 1:
        return _getOrganizedText();
      case 2:
        return _getDetailedAnalysis();
      default:
        return _recognizedText.isNotEmpty
            ? _recognizedText
            : 'No text recognized yet';
    }
  }

  String _getOrganizedText() {
    if (_textBlocks.isEmpty) return 'No text organized yet';

    final StringBuffer buffer = StringBuffer();
    buffer.writeln('📄 DOCUMENT ANALYSIS');
    buffer.writeln('=' * 30);
    buffer.writeln(
        '📊 Confidence Score: ${(_averageConfidence * 100).toStringAsFixed(1)}%');
    buffer.writeln('📝 Total Blocks: ${_textBlocks.length}');
    buffer.writeln('📋 Total Lines: ${_textLines.length}');
    buffer.writeln('🔤 Total Elements: ${_textElements.length}');
    buffer.writeln('\n📖 EXTRACTED CONTENT');
    buffer.writeln('=' * 30);

    for (int i = 0; i < _textBlocks.length; i++) {
      buffer.writeln('\n📦 BLOCK ${i + 1}:');
      buffer.writeln('-' * 20);

      int lineCount = 1;
      for (TextLine line in _textBlocks[i].lines) {
        final lineText = line.elements.map((e) => e.text).join(' ').trim();
        if (lineText.isNotEmpty) {
          final confidence = line.confidence != null
              ? ' (${(line.confidence! * 100).toStringAsFixed(0)}%)'
              : '';
          buffer.writeln('$lineCount. $lineText$confidence');
          lineCount++;
        }
      }
    }

    return buffer.toString();
  }

  String _getDetailedAnalysis() {
    if (_textElements.isEmpty) return 'No detailed analysis available';

    final StringBuffer buffer = StringBuffer();
    buffer.writeln('🔍 DETAILED TEXT ANALYSIS');
    buffer.writeln('=' * 35);

    // Group elements by confidence ranges
    final highConfidence = _textElements
        .where((e) => e.confidence != null && e.confidence! > 0.8)
        .toList();
    final mediumConfidence = _textElements
        .where((e) =>
            e.confidence != null && e.confidence! > 0.5 && e.confidence! <= 0.8)
        .toList();
    final lowConfidence = _textElements
        .where((e) => e.confidence != null && e.confidence! <= 0.5)
        .toList();

    buffer.writeln(
        '\n🟢 HIGH CONFIDENCE (>80%): ${highConfidence.length} elements');
    buffer.writeln(
        '🟡 MEDIUM CONFIDENCE (50-80%): ${mediumConfidence.length} elements');
    buffer
        .writeln('🔴 LOW CONFIDENCE (<50%): ${lowConfidence.length} elements');

    buffer.writeln('\n📋 ALL RECOGNIZED ELEMENTS:');
    buffer.writeln('-' * 30);

    for (int i = 0; i < _textElements.length; i++) {
      final element = _textElements[i];
      final confidence = element.confidence != null
          ? '${(element.confidence! * 100).toStringAsFixed(1)}%'
          : 'N/A';
      final confidenceIcon = element.confidence != null
          ? (element.confidence! > 0.8
              ? '🟢'
              : element.confidence! > 0.5
                  ? '🟡'
                  : '🔴')
          : '⚪';

      buffer
          .writeln('${i + 1}. $confidenceIcon "${element.text}" ($confidence)');
    }

    return buffer.toString();
  }

  void _copyToClipboard(String text) {
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(
      const SnackBar(
        content: Text('Text copied to clipboard!'),
        duration: Duration(seconds: 2),
      ),
    );
  }

  void _shareText(String text) {
    Share.share(text, subject: 'OCR Extracted Text');
  }

  IconData _getFileIcon(String fileName) {
    final lowerCaseFileName = fileName.toLowerCase();
    if (lowerCaseFileName.endsWith('.pdf')) {
      return Icons.picture_as_pdf;
    } else if (lowerCaseFileName.endsWith('.docx') ||
        lowerCaseFileName.endsWith('.doc')) {
      return Icons.description;
    } else if (lowerCaseFileName.endsWith('.jpg') ||
        lowerCaseFileName.endsWith('.jpeg')) {
      return Icons.image;
    } else if (lowerCaseFileName.endsWith('.png')) {
      return Icons.image;
    } else {
      return Icons.insert_drive_file;
    }
  }
}
