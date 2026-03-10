import 'package:flutter/material.dart';

class EmployeeRecordsPage extends StatefulWidget {
  const EmployeeRecordsPage({super.key});

  @override
  State<EmployeeRecordsPage> createState() => _EmployeeRecordsPageState();
}

class _EmployeeRecordsPageState extends State<EmployeeRecordsPage> {
  // Simplified data for mobile
  final List<Map<String, dynamic>> _allEmployeeDocuments = [
    {
      'name': 'John Doe Contract',
      'dept': 'HR',
      'type': 'Contract',
      'status': 'Achived',
      'date': 'Jun 10',
      'size': '1.2MB',
    },
    {
      'name': 'Jane Smith Review',
      'dept': 'Mgmt',
      'type': 'Review',
      'status': 'Archived',
      'date': 'May 25',
      'size': '500KB',
    },
    {
      'name': 'Peter Jones NDA',
      'dept': 'Legal',
      'type': 'Agreement',
      'status': 'Achived',
      'date': 'Apr 15',
      'size': '800KB',
    },
    {
      'name': 'Alice Brown Offer',
      'dept': 'HR',
      'type': 'Letter',
      'status': 'Archived',
      'date': 'Mar 1',
      'size': '300KB',
    },
    {
      'name': 'Bob White Medical',
      'dept': 'Health',
      'type': 'Report',
      'status': 'Achived',
      'date': 'Jul 1',
      'size': '750KB',
    },
  ];

  int _currentPage = 1;
  final int _itemsPerPage = 5;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Employee Records'),
        actions: [
          IconButton(
            icon: const Icon(Icons.filter_alt),
            onPressed: () => _showFilterDialog(context),
            tooltip: 'Filter',
          ),
        ],
      ),
      body: Padding(
        padding: const EdgeInsets.all(8.0),
        child: Column(
          children: [
            // Search and filter row
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 8.0),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      decoration: InputDecoration(
                        hintText: 'Search documents...',
                        prefixIcon: const Icon(Icons.search),
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(8),
                        ),
                        isDense: true,
                        contentPadding: const EdgeInsets.symmetric(vertical: 8),
                      ),
                    ),
                  ),
                ],
              ),
            ),

            // Document list
            Expanded(
              child: ListView.builder(
                itemCount: _allEmployeeDocuments.length,
                itemBuilder: (context, index) {
                  final doc = _allEmployeeDocuments[index];
                  return Card(
                    margin: const EdgeInsets.symmetric(vertical: 4),
                    child: Padding(
                      padding: const EdgeInsets.all(8.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Expanded(
                                child: Text(
                                  doc['name'],
                                  style: const TextStyle(
                                    fontWeight: FontWeight.bold,
                                    fontSize: 14,
                                  ),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                    horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(
                                  color: _getStatusColor(doc['status']),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Text(
                                  doc['status'],
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 12,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              _buildInfoChip('Dept: ${doc['dept']}'),
                              const SizedBox(width: 4),
                              _buildInfoChip('Type: ${doc['type']}'),
                              const Spacer(),
                              Text(
                                doc['date'],
                                style: const TextStyle(
                                  fontSize: 12,
                                  color: Colors.grey,
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Row(
                            children: [
                              Text(
                                'Size: ${doc['size']}',
                                style: const TextStyle(fontSize: 12),
                              ),
                              const Spacer(),
                              Row(
                                children: [
                                  IconButton(
                                    icon:
                                        const Icon(Icons.visibility, size: 18),
                                    onPressed: () => _viewDocument(doc),
                                    padding: EdgeInsets.zero,
                                    constraints: const BoxConstraints(),
                                  ),
                                  IconButton(
                                    icon: const Icon(Icons.download, size: 18),
                                    onPressed: () => _downloadDocument(doc),
                                    padding: EdgeInsets.zero,
                                    constraints: const BoxConstraints(),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
            ),

            // Pagination controls
            SizedBox(
              height: 40,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  IconButton(
                    icon: const Icon(Icons.chevron_left),
                    onPressed: _currentPage > 1
                        ? () {
                            setState(() => _currentPage--);
                          }
                        : null,
                  ),
                  Text('Page $_currentPage'),
                  IconButton(
                    icon: const Icon(Icons.chevron_right),
                    onPressed: _currentPage <
                            (_allEmployeeDocuments.length / _itemsPerPage)
                                .ceil()
                        ? () {
                            setState(() => _currentPage++);
                          }
                        : null,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'Active':
        return Colors.green;
      case 'Archived':
        return const Color(0xFF6868AC);
      default:
        return Colors.grey;
    }
  }

  Widget _buildInfoChip(String text) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
      decoration: BoxDecoration(
        color: Colors.grey[200],
        borderRadius: BorderRadius.circular(4),
      ),
      child: Text(
        text,
        style: const TextStyle(fontSize: 12),
      ),
    );
  }

  void _viewDocument(Map<String, dynamic> doc) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Viewing ${doc['name']}')),
    );
  }

  void _downloadDocument(Map<String, dynamic> doc) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Downloading ${doc['name']}')),
    );
  }

  Future<void> _showFilterDialog(BuildContext context) async {
    await showDialog(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('Filter Options'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              CheckboxListTile(
                title: const Text('Active Documents'),
                value: true,
                onChanged: (val) {},
              ),
              CheckboxListTile(
                title: const Text('Archived Documents'),
                value: true,
                onChanged: (val) {},
              ),
              const Divider(),
              const Text('Document Types'),
              Wrap(
                spacing: 4.0,
                children: ['Contract', 'Review', 'Letter', 'Report', 'Other']
                    .map((type) => FilterChip(
                          label: Text(type),
                          onSelected: (val) {},
                        ))
                    .toList(),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Apply'),
            ),
          ],
        );
      },
    );
  }
}
