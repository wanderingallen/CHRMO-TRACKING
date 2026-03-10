import 'dart:io';
import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';
import 'services/document_decryption_service.dart';
import 'services/document_encryption_service.dart';
import 'services/document_classifier.dart';

/// Secure document viewer for encrypted and regular files
class SecureDocumentViewer extends StatefulWidget {
  final String fileUrl;
  final String fileName;
  final bool isEncrypted;
  final String? originalFileName;
  final String? documentType;
  final String? ocrContent;

  const SecureDocumentViewer({
    super.key,
    required this.fileUrl,
    required this.fileName,
    this.isEncrypted = false,
    this.originalFileName,
    this.documentType,
    this.ocrContent,
  });

  @override
  State<SecureDocumentViewer> createState() => _SecureDocumentViewerState();
}

class _SecureDocumentViewerState extends State<SecureDocumentViewer> {
  File? _viewableFile;
  bool _isLoading = true;
  bool _isDecrypted = false;
  String? _errorMessage;
  DocumentClassification? _classification;

  @override
  void initState() {
    super.initState();
    _initializeViewer();
  }

  Future<void> _initializeViewer() async {
    try {
      // Classify document
      _classification = DocumentClassifier.classifyDocument(
        fileName: widget.originalFileName ?? widget.fileName,
        ocrContent: widget.ocrContent,
        documentType: widget.documentType,
      );

      // Prepare file for viewing
      final file = await DocumentDecryptionService.prepareFileForViewing(
        fileUrl: widget.fileUrl,
        fileName: widget.fileName,
        isEncrypted: widget.isEncrypted,
        originalFileName: widget.originalFileName,
      );

      if (file != null) {
        // Validate decrypted file
        bool isValid = await DocumentDecryptionService.validateDecryptedFile(
            file, widget.originalFileName ?? widget.fileName);

        if (isValid) {
          setState(() {
            _viewableFile = file;
            _isDecrypted = widget.isEncrypted ||
                DocumentEncryptionService.isEncryptedFile(widget.fileName);
            _isLoading = false;
          });
        } else {
          setState(() {
            _errorMessage =
                'File validation failed. The file may be corrupted.';
            _isLoading = false;
          });
        }
      } else {
        setState(() {
          _errorMessage = 'Failed to prepare file for viewing';
          _isLoading = false;
        });
      }
    } catch (e) {
      setState(() {
        _errorMessage = 'Error loading document: $e';
        _isLoading = false;
      });
    }
  }

  @override
  void dispose() {
    // Clean up temporary files
    if (_viewableFile != null) {
      try {
        _viewableFile!.delete();
      } catch (e) {
        debugPrint('⚠️ Could not clean up viewable file: $e');
      }
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          DocumentDecryptionService.getDisplayName(
            widget.fileName,
            originalFileName: widget.originalFileName,
          ),
          overflow: TextOverflow.ellipsis,
        ),
        backgroundColor: _getAppBarColor(),
        actions: [
          if (_classification != null) _buildClassificationIndicator(),
          _buildShareButton(),
          _buildInfoButton(),
        ],
      ),
      body: _buildBody(),
    );
  }

  Color _getAppBarColor() {
    if (_classification != null) {
      return DocumentClassifier.getSensitivityColor(
          _classification!.sensitivityLevel);
    }
    return widget.isEncrypted ? Colors.green : Theme.of(context).primaryColor;
  }

  Widget _buildClassificationIndicator() {
    if (_classification == null) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(right: 8.0),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(
          color: Colors.white.withOpacity(0.2),
          borderRadius: BorderRadius.circular(12),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              DocumentClassifier.getDocumentTypeIcon(_classification!.type),
              size: 16,
              color: Colors.white,
            ),
            const SizedBox(width: 4),
            Text(
              DocumentClassifier.getDocumentTypeDisplayName(
                  _classification!.type),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 12,
                fontWeight: FontWeight.bold,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildShareButton() {
    if (_viewableFile == null) return const SizedBox.shrink();

    return IconButton(
      icon: const Icon(Icons.share),
      onPressed: _shareDocument,
      tooltip: 'Share Document',
    );
  }

  Widget _buildInfoButton() {
    return IconButton(
      icon: const Icon(Icons.info_outline),
      onPressed: _showDocumentInfo,
      tooltip: 'Document Info',
    );
  }

  Widget _buildBody() {
    if (_isLoading) {
      return const Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text('Loading document...'),
          ],
        ),
      );
    }

    if (_errorMessage != null) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const Icon(Icons.error, color: Colors.red, size: 64),
            const SizedBox(height: 16),
            Text(
              'Error Loading Document',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 8),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 32),
              child: Text(
                _errorMessage!,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.grey),
              ),
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Go Back'),
            ),
          ],
        ),
      );
    }

    if (_viewableFile == null) {
      return const Center(
        child: Text('No file available for viewing'),
      );
    }

    return Column(
      children: [
        if (_isDecrypted) _buildDecryptionBanner(),
        Expanded(
          child: DocumentDecryptionService.createFileViewer(
            _viewableFile!,
            widget.originalFileName ?? widget.fileName,
          ),
        ),
      ],
    );
  }

  Widget _buildDecryptionBanner() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      color: Colors.green.withOpacity(0.1),
      child: Row(
        children: [
          const Icon(Icons.lock_open, color: Colors.green),
          const SizedBox(width: 12),
          const Expanded(
            child: Text(
              'This document was decrypted for secure viewing',
              style: TextStyle(color: Colors.green),
            ),
          ),
          TextButton(
            onPressed: _showEncryptionInfo,
            child: const Text('Learn More'),
          ),
        ],
      ),
    );
  }

  Future<void> _shareDocument() async {
    if (_viewableFile == null) return;

    try {
      final box = context.findRenderObject() as RenderBox?;
      await Share.shareXFiles(
        [XFile(_viewableFile!.path)],
        sharePositionOrigin: box!.localToGlobal(Offset.zero) & box.size,
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error sharing document: $e')),
        );
      }
    }
  }

  void _showDocumentInfo() {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Document Information'),
        content: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              _buildInfoRow(
                  'File Name', widget.originalFileName ?? widget.fileName),
              _buildInfoRow('Encrypted', widget.isEncrypted ? 'Yes' : 'No'),
              _buildInfoRow('Decrypted', _isDecrypted ? 'Yes' : 'No'),
              if (_classification != null) ...[
                _buildInfoRow(
                    'Type',
                    DocumentClassifier.getDocumentTypeDisplayName(
                        _classification!.type)),
                _buildInfoRow(
                    'Sensitivity',
                    DocumentClassifier.getSensitivityDisplayName(
                        _classification!.sensitivityLevel)),
                if (_classification!.keywords.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  const Text('Detected Keywords:',
                      style: TextStyle(fontWeight: FontWeight.bold)),
                  Wrap(
                    spacing: 4,
                    children: _classification!.keywords
                        .map((keyword) => Chip(
                              label: Text(keyword,
                                  style: const TextStyle(fontSize: 12)),
                              materialTapTargetSize:
                                  MaterialTapTargetSize.shrinkWrap,
                            ))
                        .toList(),
                  ),
                ],
              ],
              if (_viewableFile != null) ...[
                _buildInfoRow(
                    'File Size', _formatFileSize(_viewableFile!.lengthSync())),
                _buildInfoRow(
                    'Preview Available',
                    DocumentDecryptionService.canPreviewFile(
                            widget.originalFileName ?? widget.fileName)
                        ? 'Yes'
                        : 'No'),
              ],
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Close'),
          ),
        ],
      ),
    );
  }

  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 120,
            child: Text(
              '$label:',
              style: const TextStyle(fontWeight: FontWeight.bold),
            ),
          ),
          Expanded(child: Text(value)),
        ],
      ),
    );
  }

  void _showEncryptionInfo() {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Document Encryption'),
        content: const Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'This document was encrypted to protect sensitive information.',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
            SizedBox(height: 16),
            Text('🔐 Encryption Features:'),
            SizedBox(height: 8),
            Text('• AES-256-GCM encryption algorithm'),
            Text('• Client-side encryption before upload'),
            Text('• Secure key management'),
            Text('• Automatic classification of sensitive documents'),
            SizedBox(height: 16),
            Text(
              'The document is temporarily decrypted for viewing only on your device.',
              style: TextStyle(fontStyle: FontStyle.italic),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Got it'),
          ),
        ],
      ),
    );
  }

  String _formatFileSize(int bytes) {
    if (bytes < 1024) return '$bytes B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
}
