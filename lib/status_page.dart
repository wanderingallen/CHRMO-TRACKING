import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

// Define a data model for a Document Status Review item
class DocumentStatusReview {
  final String id;
  final String documentName;
  final String currentStatus;
  final String lastUpdated;
  final String reviewer;
  final String comments;

  DocumentStatusReview({
    required this.id,
    required this.documentName,
    required this.currentStatus,
    required this.lastUpdated,
    required this.reviewer,
    required this.comments,
  });
}

// Placeholder for a detailed status review page
class StatusReviewDetailsPage extends StatelessWidget {
  final DocumentStatusReview review;

  const StatusReviewDetailsPage({super.key, required this.review});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(review.documentName),
        backgroundColor: const Color(0xFF52528A),
        foregroundColor: Colors.white,
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFF3E5F5), // Lighter purple
              Color(0xFFE1BEE7) // Slightly darker purple
            ],
          ),
        ),
        padding: const EdgeInsets.all(20.0),
        child: Card(
          elevation: 8,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          child: Padding(
            padding: const EdgeInsets.all(20.0),
            child: ListView(
              children: [
                _buildDetailRow('Review ID:', review.id, Icons.assignment_ind),
                _buildDetailRow(
                    'Document Name:', review.documentName, Icons.description),
                _buildDetailRow('Current Status:', review.currentStatus,
                    Icons.info_outline),
                _buildDetailRow(
                    'Last Updated:', review.lastUpdated, Icons.calendar_today),
                _buildDetailRow('Reviewer:', review.reviewer, Icons.person),
                const SizedBox(height: 30),
                Text(
                  'Comments:',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: const Color(0xFF6868AC).withOpacity(0.15),
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  review.comments,
                  style: const TextStyle(fontSize: 16, color: Colors.black87),
                ),
                const SizedBox(height: 30),
                ElevatedButton.icon(
                  onPressed: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                          content:
                              Text('Opening document for further review...')),
                    );
                    // TODO: Implement logic to open the actual document or related page
                  },
                  icon: const Icon(Icons.open_in_new, color: Colors.white),
                  label: const Text('Open Document',
                      style: TextStyle(color: Colors.white)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF52528A),
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12)),
                    padding: const EdgeInsets.symmetric(vertical: 15),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildDetailRow(String label, String value, IconData icon) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 24, color: const Color(0xFF52528A)),
          const SizedBox(width: 15),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w500,
                    color: Colors.grey.shade700,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  value,
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.bold,
                    color: Colors.black87,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class StatusPage extends StatefulWidget {
  const StatusPage({super.key});

  @override
  State<StatusPage> createState() => _StatusPageState();
}

class _StatusPageState extends State<StatusPage> {
  String _searchQuery = '';
  String? _selectedStatusFilter;
  String? _selectedReviewerFilter;

  final List<DocumentStatusReview> _allReviews = [
    DocumentStatusReview(
      id: 'SR001',
      documentName: 'Q3 Financial Report',
      currentStatus: 'Pending Approval',
      lastUpdated: '2025-05-20',
      reviewer: 'Alice Johnson',
      comments: 'Awaiting final sign-off from finance director.',
    ),
    DocumentStatusReview(
      id: 'SR002',
      documentName: 'Employee Onboarding Policy V2',
      currentStatus: 'In Review',
      lastUpdated: '2025-05-18',
      reviewer: 'Bob Williams',
      comments: 'Reviewing compliance with new HR regulations.',
    ),
    DocumentStatusReview(
      id: 'SR003',
      documentName: 'Marketing Campaign Proposal',
      currentStatus: 'Approved',
      lastUpdated: '2025-05-15',
      reviewer: 'Charlie Davis',
      comments: 'Approved for execution. Budget allocated.',
    ),
    DocumentStatusReview(
      id: 'SR004',
      documentName: 'IT Security Audit Report',
      currentStatus: 'Action Required',
      lastUpdated: '2025-05-10',
      reviewer: 'Diana Miller',
      comments: 'Critical vulnerabilities identified. Immediate action needed.',
    ),
    DocumentStatusReview(
      id: 'SR005',
      documentName: 'Annual Performance Review Template',
      currentStatus: 'Pending Review',
      lastUpdated: '2025-05-22',
      reviewer: 'Alice Johnson',
      comments: 'Draft template submitted for HR department review.',
    ),
  ];

  List<DocumentStatusReview> _filteredReviews = [];

  @override
  void initState() {
    super.initState();
    _filterReviews(); // Initial filtering
  }

  void _filterReviews() {
    setState(() {
      _filteredReviews = _allReviews.where((review) {
        final matchesSearch = _searchQuery.isEmpty ||
            review.documentName
                .toLowerCase()
                .contains(_searchQuery.toLowerCase()) ||
            review.id.toLowerCase().contains(_searchQuery.toLowerCase()) ||
            review.reviewer.toLowerCase().contains(_searchQuery.toLowerCase());

        final matchesStatus = _selectedStatusFilter == null ||
            _selectedStatusFilter == 'All Statuses' ||
            review.currentStatus == _selectedStatusFilter;

        final matchesReviewer = _selectedReviewerFilter == null ||
            _selectedReviewerFilter == 'All Reviewers' ||
            review.reviewer == _selectedReviewerFilter;

        return matchesSearch && matchesStatus && matchesReviewer;
      }).toList();
    });
  }

  void _resetFilters() {
    setState(() {
      _searchQuery = '';
      _selectedStatusFilter = null;
      _selectedReviewerFilter = null;
      _filterReviews();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Document Status Review'),
        backgroundColor: const Color(0xFF52528A),
        foregroundColor: Colors.white,
        systemOverlayStyle: SystemUiOverlayStyle.light,
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFF3E5F5), // Light purple
              Color(0xFFE1BEE7) // Slightly darker purple
            ],
          ),
        ),
        child: Column(
          children: [
            _buildSearchBarAndFilters(),
            Expanded(
              child: _filteredReviews.isEmpty
                  ? Center(
                      child: Text(
                        'No reviews found matching your criteria.',
                        style: TextStyle(
                            fontSize: 16, color: Colors.grey.shade600),
                      ),
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.all(16.0),
                      itemCount: _filteredReviews.length,
                      itemBuilder: (context, index) {
                        final review = _filteredReviews[index];
                        return _buildReviewCard(review);
                      },
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSearchBarAndFilters() {
    return Padding(
      padding: const EdgeInsets.all(16.0),
      child: Column(
        children: [
          TextField(
            onChanged: (value) {
              setState(() {
                _searchQuery = value;
                _filterReviews();
              });
            },
            decoration: InputDecoration(
              hintText: 'Search reviews by document, ID, or reviewer...',
              prefixIcon: const Icon(Icons.search, color: Color(0xFF6868AC)),
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide: BorderSide.none,
              ),
              filled: true,
              fillColor: Colors.white.withOpacity(0.9),
              contentPadding:
                  const EdgeInsets.symmetric(vertical: 10, horizontal: 15),
              suffixIcon: _searchQuery.isNotEmpty
                  ? IconButton(
                      icon: const Icon(Icons.clear, color: Colors.grey),
                      onPressed: () {
                        setState(() {
                          _searchQuery = '';
                          _filterReviews();
                        });
                      },
                    )
                  : null,
            ),
          ),
          const SizedBox(height: 15),
          Row(
            children: [
              Expanded(
                child: _buildFilterDropdown(
                  'Status',
                  [
                    'All Statuses',
                    'Pending Approval',
                    'In Review',
                    'Approved',
                    'Action Required'
                  ],
                  _selectedStatusFilter,
                  (newValue) {
                    setState(() {
                      _selectedStatusFilter = newValue;
                      _filterReviews();
                    });
                  },
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _buildFilterDropdown(
                  'Reviewer',
                  [
                    'All Reviewers',
                    'Alice Johnson',
                    'Bob Williams',
                    'Charlie Davis',
                    'Diana Miller'
                  ],
                  _selectedReviewerFilter,
                  (newValue) {
                    setState(() {
                      _selectedReviewerFilter = newValue;
                      _filterReviews();
                    });
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 15),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton.icon(
              onPressed: _resetFilters,
              icon: const Icon(Icons.refresh, color: Colors.white),
              label: const Text('Reset Filters',
                  style: TextStyle(color: Colors.white)),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF52528A),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10)),
                padding: const EdgeInsets.symmetric(vertical: 12),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterDropdown(String hint, List<String> items,
      String? selectedValue, ValueChanged<String?> onChanged) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withOpacity(0.9),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF52528A)),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<String>(
          isExpanded: true,
          value: selectedValue,
          hint: Text(hint, style: TextStyle(color: Colors.grey.shade700)),
          icon: const Icon(Icons.arrow_drop_down, color: Color(0xFF52528A)),
          onChanged: onChanged,
          items: items.map<DropdownMenuItem<String>>((String value) {
            return DropdownMenuItem<String>(
              value: value,
              child: Text(value),
            );
          }).toList(),
          style: const TextStyle(color: Colors.black87, fontSize: 16),
          dropdownColor: Colors.white,
        ),
      ),
    );
  }

  Widget _buildReviewCard(DocumentStatusReview review) {
    Color statusColor;
    switch (review.currentStatus) {
      case 'Approved':
        statusColor = Colors.green.shade600;
        break;
      case 'In Review':
        statusColor = Colors.orange.shade600;
        break;
      case 'Pending Approval':
        statusColor = const Color(0xFF6868AC);
        break;
      case 'Action Required':
        statusColor = Colors.red.shade600;
        break;
      default:
        statusColor = Colors.grey.shade600;
    }

    return GestureDetector(
      onTap: () {
        Navigator.push(
          context,
          MaterialPageRoute(
            builder: (context) => StatusReviewDetailsPage(review: review),
          ),
        );
      },
      child: Card(
        elevation: 5,
        margin: const EdgeInsets.symmetric(vertical: 8.0),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(15)),
        child: Container(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(15),
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                Colors.white,
                Colors.purple.shade50,
              ],
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        review.documentName,
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: Color(0xFF52528A),
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 8, vertical: 4),
                      decoration: BoxDecoration(
                        color: statusColor.withOpacity(0.2),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        review.currentStatus,
                        style: TextStyle(
                          color: statusColor,
                          fontWeight: FontWeight.w600,
                          fontSize: 13,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  'ID: ${review.id}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Reviewer: ${review.reviewer}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Last Updated: ${review.lastUpdated}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 15),
                Align(
                  alignment: Alignment.bottomRight,
                  child: ElevatedButton.icon(
                    onPressed: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (context) =>
                              StatusReviewDetailsPage(review: review),
                        ),
                      );
                    },
                    icon: const Icon(Icons.visibility, color: Colors.white),
                    label: const Text('View Details',
                        style: TextStyle(color: Colors.white)),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF52528A),
                      shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(10)),
                      elevation: 3,
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
