import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'newarchive_page.dart';
import 'models/simple_archived_document.dart';

class ApplicationPage extends StatefulWidget {
  const ApplicationPage({super.key});

  @override
  State<ApplicationPage> createState() => _ApplicationPageState();
}

class _ApplicationPageState extends State<ApplicationPage> {
  final List<ArchivedDocument> _archivedDocuments = [
    ArchivedDocument(
      documentName: 'Q1 Financial Report',
      department: 'Finance',
      type: 'Report',
      status: 'Archived',
      dateArchived: DateTime(2025, 5, 15),
      size: '2.3 MB',
    ),
    ArchivedDocument(
      documentName: 'Employee Handbook',
      department: 'Human Resources',
      type: 'Policy',
      status: 'Archived',
      dateArchived: DateTime(2025, 4, 28),
      size: '1.8 MB',
    ),
    ArchivedDocument(
      documentName: 'Annual Budget',
      department: 'Finance',
      type: 'Spreadsheet',
      status: 'Archived',
      dateArchived: DateTime(2025, 4, 20),
      size: '3.5 MB',
    ),
  ];

  // Define your custom colors here as final fields (not const at class level)
  final Color _primaryColor = const Color(0xFF6A1B9A); // Deep purple
  final Color _secondaryColor = const Color(0xFF26A69A); // Teal
  final Color _accentColor =
      const Color(0xFFFFAB00); // Amber (example, if you use it)
  final Color _errorColor = const Color(0xFFD32F2F); // Red for errors (example)

  // Function to add a new document
  void _addNewDocument(ArchivedDocument newDocument) {
    setState(() {
      _archivedDocuments.insert(0, newDocument); // Add to the top of the list
    });
  }

  // Navigate to New Archive Form
  void _navigateToNewArchiveForm(BuildContext context) async {
    final prevLen = _archivedDocuments.length;
    await Navigator.push(
      context,
      MaterialPageRoute(
        builder: (context) => NewArchivePage(
          onSave: (ArchivedDocument doc) {
            _addNewDocument(doc);
            // Do not pop here; the form page handles popping after save.
          },
        ),
      ),
    );

    if (mounted && _archivedDocuments.length > prevLen) {
      final added = _archivedDocuments.first; // last added at top
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('${added.documentName} added successfully!'),
          backgroundColor: Colors.green,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Archived Documents'),
        actions: [
          IconButton(
            icon: const Icon(Icons.download),
            onPressed: () => _showSnackbar(context, 'Export button pressed'),
          ),
          Padding(
            padding: const EdgeInsets.only(right: 8.0),
            child: FilledButton.icon(
              onPressed: () => _navigateToNewArchiveForm(context),
              icon: const Icon(Icons.add),
              label: const Text('New Archive'),
              // Ensure button styling uses your defined colors if desired
              style: FilledButton.styleFrom(
                backgroundColor: _primaryColor, // Use your primary color
                foregroundColor: Colors.white,
              ),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          // Search bar
          Padding(
            padding: const EdgeInsets.all(16.0),
            child: TextField(
              decoration: InputDecoration(
                hintText: 'Search archived documents...',
                prefixIcon: Icon(Icons.search, color: _primaryColor),
                suffixIcon: Tooltip(
                  message: 'Filter',
                  child: IconButton(
                    icon: const Icon(Icons.tune),
                    color: _primaryColor,
                    onPressed: () => _showSnackbar(context, 'Filter button pressed'),
                  ),
                ),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide.none,
                ),
                filled: true,
                fillColor: Colors.white,
                contentPadding: const EdgeInsets.symmetric(vertical: 14, horizontal: 16),
              ),
              onChanged: (value) {
                // If you want to wire this to actual search in this page later
                // you can add filtering logic here.
              },
            ),
          ),

          // Document list
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 16),
              itemCount: _archivedDocuments.length,
              itemBuilder: (context, index) {
                final doc = _archivedDocuments[index];
                return _buildDocumentCard(doc);
              },
            ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: () => _navigateToNewArchiveForm(context),
        backgroundColor: _accentColor, // Use your accent color
        foregroundColor: Colors.black, // Adjust text/icon color for visibility
        child: const Icon(Icons.add),
      ),
    );
  }

  Widget _buildDocumentCard(ArchivedDocument doc) {
    return Card(
      margin:
          const EdgeInsets.symmetric(vertical: 8), // Added margin for spacing
      child: ListTile(
        leading: Icon(
          _getDocumentIcon(doc.type), // Dynamic icon based on type
          size: 40,
          color: _getDocumentColor(doc.type), // Dynamic color
        ),
        title: Text(
          doc.documentName,
          style: const TextStyle(fontWeight: FontWeight.bold),
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('${doc.department} • ${doc.type}'),
            const SizedBox(height: 4),
            Row(
              children: [
                const Icon(Icons.calendar_today, size: 14),
                const SizedBox(width: 4),
                Text(DateFormat('MMM dd, yyyy').format(doc.dateArchived)),
                const Spacer(),
                const Icon(Icons.save, size: 14), // Added a save icon for size
                const SizedBox(width: 4),
                Text(doc.size),
              ],
            ),
            const SizedBox(height: 8), // Added spacing
            Align(
              alignment: Alignment.bottomRight,
              child: Chip(
                label: Text(doc.status),
                backgroundColor:
                    _secondaryColor.withOpacity(0.2), // Example status chip
                labelStyle: TextStyle(color: _secondaryColor),
              ),
            ),
          ],
        ),
        trailing: PopupMenuButton<String>(
          icon: const Icon(Icons.more_vert),
          onSelected: (value) {
            if (value == 'view') {
              _showDocumentDetails(context, doc);
            } else if (value == 'download') {
              _showSnackbar(context, 'Downloading ${doc.documentName}');
            } else if (value == 'delete') {
              _confirmDelete(context, doc);
            }
          },
          itemBuilder: (BuildContext context) => <PopupMenuEntry<String>>[
            const PopupMenuItem<String>(
              value: 'view',
              child: Text('View'),
            ),
            const PopupMenuItem<String>(
              value: 'download',
              child: Text('Download'),
            ),
            const PopupMenuItem<String>(
              value: 'delete',
              child: Text('Delete'),
            ),
          ],
        ),
        // Removed onTap from ListTile to use PopupMenuButton for actions
        // If you want onTap for details and a separate icon for actions, you can adjust.
      ),
    );
  }

  // Helper functions for icons and colors
  IconData _getDocumentIcon(String type) {
    switch (type.toLowerCase()) {
      case 'report':
        return Icons.article_outlined;
      case 'policy':
        return Icons.policy_outlined;
      case 'spreadsheet':
        return Icons.grid_on_outlined;
      case 'image':
        return Icons.image_outlined;
      case 'presentation':
        return Icons.slideshow_outlined;
      case 'contract':
        return Icons.assignment_outlined;
      default:
        return Icons.description_outlined;
    }
  }

  Color _getDocumentColor(String type) {
    switch (type.toLowerCase()) {
      case 'report':
        return _primaryColor;
      case 'policy':
        return _secondaryColor;
      case 'spreadsheet':
        return Colors.green.shade600;
      case 'image':
        return Colors.orange.shade600;
      case 'presentation':
        return Colors.purple.shade600;
      case 'contract':
        return Colors.brown.shade600;
      default:
        return Colors.blueGrey.shade600;
    }
  }

  void _showDocumentDetails(BuildContext context, ArchivedDocument doc) {
    showModalBottomSheet(
      context: context,
      builder: (context) {
        return Padding(
          padding: const EdgeInsets.all(16.0),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                doc.documentName,
                style: Theme.of(context).textTheme.titleLarge,
              ),
              const SizedBox(height: 16),
              _buildDetailRow('Department', doc.department),
              _buildDetailRow('Type', doc.type),
              _buildDetailRow('Status', doc.status),
              _buildDetailRow('Date Archived',
                  DateFormat('MMM dd, yyyy').format(doc.dateArchived)),
              _buildDetailRow('Size', doc.size),
              const SizedBox(height: 24),
              Row(
                children: [
                  Expanded(
                    child: FilledButton(
                      onPressed: () => Navigator.pop(context),
                      child: const Text('Close'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildDetailRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          SizedBox(
            width: 120,
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.bold),
            ),
          ),
          Text(value),
        ],
      ),
    );
  }

  void _showSnackbar(BuildContext context, String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }

  void _confirmDelete(BuildContext context, ArchivedDocument doc) {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Confirm Deletion'),
        content: Text('Are you sure you want to delete "${doc.documentName}"?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () {
              setState(() {
                _archivedDocuments.remove(doc);
              });
              Navigator.pop(context); // Close the dialog
              _showSnackbar(context, '${doc.documentName} deleted.');
            },
            style: FilledButton.styleFrom(
                backgroundColor: _errorColor),
            child: const Text('Delete'), // Use error color
          ),
        ],
      ),
    );
  }
}
 
