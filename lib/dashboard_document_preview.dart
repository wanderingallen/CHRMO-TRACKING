import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:io';

class DashboardDocumentPreview extends StatefulWidget {
  final String title;
  final String imageUrl; // network or local path
  final String? ocrUrl;  // optional network txt

  const DashboardDocumentPreview({super.key, required this.title, required this.imageUrl, this.ocrUrl});

  @override
  State<DashboardDocumentPreview> createState() => _DashboardDocumentPreviewState();
}

class _DashboardDocumentPreviewState extends State<DashboardDocumentPreview> {
  String? _ocrText;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _loadOcr();
  }

  Future<void> _loadOcr() async {
    final url = widget.ocrUrl;
    if (url == null || url.isEmpty) return;
    setState(() => _loading = true);
    try {
      final r = await http.get(Uri.parse(url)).timeout(const Duration(seconds: 10));
      if (r.statusCode == 200 && r.body.isNotEmpty) {
        setState(() => _ocrText = r.body);
      }
    } catch (_) {
      // ignore
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: Center(
          child: InteractiveViewer(
            child: widget.imageUrl.startsWith('http')
                ? Image.network(
                    widget.imageUrl,
                    fit: BoxFit.contain,
                    width: double.infinity,
                    height: double.infinity,
                    errorBuilder: (_, __, ___) => const Center(
                        child: Icon(Icons.broken_image, size: 48)),
                  )
                : Image.file(
                    File(widget.imageUrl),
                    fit: BoxFit.contain,
                    width: double.infinity,
                    height: double.infinity,
                    errorBuilder: (_, __, ___) => const Center(
                        child: Icon(Icons.broken_image, size: 48)),
                  ),
          ),
        ),
      ),
    );
  }
}
