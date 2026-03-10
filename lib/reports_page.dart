import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

// Define a data model for a Report item
class Report {
  final String id;
  final String title;
  final String type; // e.g., 'Summary', 'Detailed', 'Trend'
  final String generatedDate;
  final String period; // e.g., 'Q1 2025', 'Monthly', 'Annual'
  final String status; // e.g., 'Ready', 'Generating', 'Failed'
  final String description;

  Report({
    required this.id,
    required this.title,
    required this.type,
    required this.generatedDate,
    required this.period,
    required this.status,
    required this.description,
  });
}

// Placeholder for a detailed Report view page
class ReportDetailsPage extends StatelessWidget {
  final Report report;

  const ReportDetailsPage({super.key, required this.report});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(report.title),
        backgroundColor: Colors.deepOrange.shade700,
        foregroundColor: Colors.white,
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color.fromARGB(255, 251, 250, 250), // Light orange
              Color.fromARGB(255, 250, 249, 247) // Slightly darker orange
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
                _buildDetailRow('Report ID:', report.id, Icons.vpn_key),
                _buildDetailRow('Type:', report.type, Icons.category),
                _buildDetailRow('Generated Date:', report.generatedDate,
                    Icons.calendar_today),
                _buildDetailRow('Period:', report.period, Icons.date_range),
                _buildDetailRow('Status:', report.status, Icons.info_outline),
                const SizedBox(height: 30),
                Text(
                  'Description:',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: Colors.deepOrange.shade800,
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  report.description,
                  style: const TextStyle(fontSize: 16, color: Colors.black87),
                ),
                const SizedBox(height: 30),
                ElevatedButton.icon(
                  onPressed: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                          content: Text('Downloading report... (Simulated)')),
                    );
                    // TODO: Implement actual report download logic
                  },
                  icon: const Icon(Icons.download, color: Colors.white),
                  label: const Text('Download Report',
                      style: TextStyle(color: Colors.white)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.deepOrange.shade600,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12)),
                    padding: const EdgeInsets.symmetric(vertical: 15),
                  ),
                ),
                const SizedBox(height: 15),
                ElevatedButton.icon(
                  onPressed: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                          content: Text('Sharing report... (Simulated)')),
                    );
                    // TODO: Implement actual report sharing logic
                  },
                  icon: const Icon(Icons.share, color: Colors.white),
                  label: const Text('Share Report',
                      style: TextStyle(color: Colors.white)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.orange.shade600,
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
          Icon(icon, size: 24, color: Colors.deepOrange.shade600),
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

class ReportsPage extends StatefulWidget {
  const ReportsPage({super.key});

  @override
  State<ReportsPage> createState() => _ReportsPageState();
}

class _ReportsPageState extends State<ReportsPage> {
  String _searchQuery = '';
  String? _selectedReportTypeFilter;
  String? _selectedPeriodFilter;
  String? _selectedStatusFilter;

  final List<Report> _allReports = [
    Report(
      id: 'REP001',
      title: 'Q1 2025 Financial Performance',
      type: 'Financial Summary',
      generatedDate: '2025-04-10',
      period: 'Q1 2025',
      status: 'Ready',
      description:
          'Comprehensive overview of financial performance for the first quarter of 2025.',
    ),
    Report(
      id: 'REP002',
      title: 'Monthly Employee Onboarding Trends',
      type: 'HR Trend Analysis',
      generatedDate: '2025-05-05',
      period: 'Monthly',
      status: 'Ready',
      description:
          'Analysis of new employee onboarding rates and efficiency over the past month.',
    ),
    Report(
      id: 'REP003',
      title: 'Marketing Campaign ROI Analysis',
      type: 'Marketing Detailed',
      generatedDate: '2025-05-12',
      period: 'Annual',
      status: 'Generating',
      description:
          'Detailed report on the return on investment for all marketing campaigns conducted in 2024.',
    ),
    Report(
      id: 'REP004',
      title: 'IT Infrastructure Usage Report',
      type: 'IT Usage',
      generatedDate: '2025-05-19',
      period: 'Weekly',
      status: 'Ready',
      description:
          'Weekly summary of IT resource utilization, network traffic, and server performance.',
    ),
    Report(
      id: 'REP005',
      title: 'Sales Performance by Region',
      type: 'Sales Summary',
      generatedDate: '2025-05-21',
      period: 'Monthly',
      status: 'Failed',
      description:
          'Report showing sales figures and performance metrics broken down by geographical region. (Data processing error)',
    ),
  ];

  List<Report> _filteredReports = [];

  @override
  void initState() {
    super.initState();
    _filterReports(); // Initial filtering
  }

  void _filterReports() {
    setState(() {
      _filteredReports = _allReports.where((report) {
        final matchesSearch = _searchQuery.isEmpty ||
            report.title.toLowerCase().contains(_searchQuery.toLowerCase()) ||
            report.id.toLowerCase().contains(_searchQuery.toLowerCase()) ||
            report.description
                .toLowerCase()
                .contains(_searchQuery.toLowerCase());

        final matchesType = _selectedReportTypeFilter == null ||
            _selectedReportTypeFilter == 'All Types' ||
            report.type == _selectedReportTypeFilter;

        final matchesPeriod = _selectedPeriodFilter == null ||
            _selectedPeriodFilter == 'All Periods' ||
            report.period == _selectedPeriodFilter;

        final matchesStatus = _selectedStatusFilter == null ||
            _selectedStatusFilter == 'All Statuses' ||
            report.status == _selectedStatusFilter;

        return matchesSearch && matchesType && matchesPeriod && matchesStatus;
      }).toList();
    });
  }

  void _resetFilters() {
    setState(() {
      _searchQuery = '';
      _selectedReportTypeFilter = null;
      _selectedPeriodFilter = null;
      _selectedStatusFilter = null;
      _filterReports();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Reports & Analytics'),
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
              Color.fromARGB(255, 245, 245, 244), // Light orange
              Color.fromARGB(255, 249, 249, 248) // Slightly darker orange
            ],
          ),
        ),
        child: Column(
          children: [
            _buildSearchBarAndFilters(),
            Expanded(
              child: _filteredReports.isEmpty
                  ? Center(
                      child: Text(
                        'No reports found matching your criteria.',
                        style: TextStyle(
                            fontSize: 16, color: Colors.grey.shade600),
                      ),
                    )
                  : ListView.builder(
                      padding: const EdgeInsets.all(16.0),
                      itemCount: _filteredReports.length,
                      itemBuilder: (context, index) {
                        final report = _filteredReports[index];
                        return _buildReportCard(report);
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
                _filterReports();
              });
            },
            decoration: InputDecoration(
              hintText: 'Search reports by title, ID, or description...',
              prefixIcon:
                  const Icon(Icons.search, color: Colors.deepOrangeAccent),
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
                          _filterReports();
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
                  'Type',
                  [
                    'All Types',
                    'Financial Summary',
                    'HR Trend Analysis',
                    'Marketing Detailed',
                    'IT Usage',
                    'Sales Summary'
                  ],
                  _selectedReportTypeFilter,
                  (newValue) {
                    setState(() {
                      _selectedReportTypeFilter = newValue;
                      _filterReports();
                    });
                  },
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _buildFilterDropdown(
                  'Period',
                  ['All Periods', 'Q1 2025', 'Monthly', 'Annual', 'Weekly'],
                  _selectedPeriodFilter,
                  (newValue) {
                    setState(() {
                      _selectedPeriodFilter = newValue;
                      _filterReports();
                    });
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          _buildFilterDropdown(
            'Status',
            ['All Statuses', 'Ready', 'Generating', 'Failed'],
            _selectedStatusFilter,
            (newValue) {
              setState(() {
                _selectedStatusFilter = newValue;
                _filterReports();
              });
            },
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

  Widget _buildReportCard(Report report) {
    Color statusColor;
    switch (report.status) {
      case 'Ready':
        statusColor = Colors.green.shade600;
        break;
      case 'Generating':
        statusColor = Colors.orange.shade600;
        break;
      case 'Failed':
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
            builder: (context) => ReportDetailsPage(report: report),
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
                Colors.deepOrange.shade50,
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
                        report.title,
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
                        report.status,
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
                  'ID: ${report.id}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Type: ${report.type}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Period: ${report.period}',
                  style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                ),
                const SizedBox(height: 4),
                Text(
                  'Generated: ${report.generatedDate}',
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
                              ReportDetailsPage(report: report),
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
