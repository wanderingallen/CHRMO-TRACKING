import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart'; // For date formatting

import 'archive_page.dart'; // Import the new ArchivePage
// Removed: import 'login_page.dart';

// New data model for Certificate
class Certificate {
  final String title;
  final String recipient;
  final DateTime issueDate;
  final DateTime expiryDate; // Added expiry date for certificates
  final String certificateId;
  final String type; // e.g., 'Achievement', 'Completion', 'Attendance'
  final String status; // e.g., 'Valid', 'Expired', 'Revoked'

  Certificate({
    required this.title,
    required this.recipient,
    required this.issueDate,
    required this.expiryDate,
    required this.certificateId,
    required this.type,
    required this.status,
  });
}

class CertificatePage extends StatefulWidget {
  const CertificatePage({super.key});

  @override
  State<CertificatePage> createState() => _CertificatePageState();
}

class _CertificatePageState extends State<CertificatePage>
    with SingleTickerProviderStateMixin {
  String username = 'Loading...';
  String email = 'Loading...';
  String searchQuery = '';

  late AnimationController _notificationController;
  late Animation<Offset> _slideAnimation;
  bool _isNotificationDrawerOpen = false;

  final List<String> notifications = [
    "Certificate #CRT-001 issued",
    "Certificate #CRT-005 nearing expiry",
    "Reminder: Archive old certificates",
    "New certificate template available",
  ];

  // Sample data for Certificates
  final List<Certificate> _certificates = [
    Certificate(
      title: 'Project Completion',
      recipient: 'Alice Johnson',
      issueDate: DateTime(2023, 1, 15),
      expiryDate: DateTime(2025, 1, 15),
      certificateId: 'CRT-001',
      type: 'Completion',
      status: 'Valid',
    ),
    Certificate(
      title: 'Employee of the Month',
      recipient: 'Bob Williams',
      issueDate: DateTime(2024, 3, 1),
      expiryDate: DateTime(2024, 12, 31),
      certificateId: 'CRT-002',
      type: 'Achievement',
      status: 'Valid',
    ),
    Certificate(
      title: 'Training Participation',
      recipient: 'Charlie Brown',
      issueDate: DateTime(2024, 6, 10),
      expiryDate: DateTime(2024, 6, 10), // No expiry for attendance
      certificateId: 'CRT-003',
      type: 'Attendance',
      status: 'Valid',
    ),
    Certificate(
      title: 'Advanced Skills Certification',
      recipient: 'Diana Prince',
      issueDate: DateTime(2022, 10, 20),
      expiryDate: DateTime(2024, 10, 19),
      certificateId: 'CRT-004',
      type: 'Certification',
      status: 'Expired',
    ),
    Certificate(
      title: 'Service Award',
      recipient: 'Eve Adams',
      issueDate: DateTime(2021, 7, 1),
      expiryDate: DateTime(2031, 7, 1),
      certificateId: 'CRT-005',
      type: 'Award',
      status: 'Valid',
    ),
  ];

  List<Certificate> get _filteredCertificates {
    if (searchQuery.isEmpty) {
      return _certificates;
    }
    return _certificates.where((certificate) {
      final queryLower = searchQuery.toLowerCase();
      return certificate.title.toLowerCase().contains(queryLower) ||
          certificate.recipient.toLowerCase().contains(queryLower) ||
          certificate.certificateId.toLowerCase().contains(queryLower) ||
          certificate.type.toLowerCase().contains(queryLower) ||
          certificate.status.toLowerCase().contains(queryLower);
    }).toList();
  }

  @override
  void initState() {
    super.initState();
    _notificationController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 300),
    );

    _slideAnimation = Tween<Offset>(
      begin: const Offset(1.0, 0),
      end: Offset.zero,
    ).animate(CurvedAnimation(
      parent: _notificationController,
      curve: Curves.easeInOut,
    ));
  }

  @override
  void dispose() {
    _notificationController.dispose();
    super.dispose();
  }

  void toggleNotificationDrawer() {
    setState(() {
      if (_isNotificationDrawerOpen) {
        _notificationController.reverse();
      } else {
        _notificationController.forward();
      }
      _isNotificationDrawerOpen = !_isNotificationDrawerOpen;
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final args =
        ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?;
    if (args != null) {
      username = args['username'] ?? 'Guest';
      email = args['email'] ?? 'guest@example.com';
    }
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
      ),
    );

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        leading: Builder(
          builder: (context) => IconButton(
            icon: const Icon(Icons.arrow_back, color: Colors.black),
            onPressed: () {
              if (Navigator.of(context).canPop()) {
                Navigator.of(context).pop();
              } else {
                Navigator.pushReplacement(
                  context,
                  MaterialPageRoute(
                      builder: (context) => const CertificatePage()),
                );
              }
            },
          ),
        ),
        title: const Text(
          'Certificates',
          style: TextStyle(
            color: Colors.black,
            fontWeight: FontWeight.bold,
          ),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications, color: Colors.black),
            onPressed: toggleNotificationDrawer,
          ),
          Builder(
            builder: (context) => IconButton(
              icon: const Icon(Icons.menu, color: Colors.black),
              onPressed: () {
                Scaffold.of(context).openDrawer();
              },
            ),
          ),
        ],
      ),
      drawer: Drawer(
        child: Container(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [Color.fromARGB(255, 186, 189, 196), Color(0xFF3B82F6)],
            ),
          ),
          child: ListView(
            padding: EdgeInsets.zero,
            children: <Widget>[
              UserAccountsDrawerHeader(
                decoration: const BoxDecoration(
                  gradient: LinearGradient(
                      begin: Alignment.topLeft,
                      end: Alignment.bottomRight,
                      colors: [
                        Color.fromARGB(255, 186, 189, 196),
                        Color(0xFF3B82F6)
                      ]),
                ),
                accountName: Text(
                  username,
                  style:
                      const TextStyle(color: Colors.white, fontFamily: "Kanit"),
                ),
                accountEmail: Text(
                  email,
                  style: const TextStyle(color: Colors.white70),
                ),
                currentAccountPicture: Container(
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(color: Colors.white, width: 2),
                  ),
                  child: const CircleAvatar(
                    backgroundColor: Colors.white,
                    child: Icon(Icons.person,
                        size: 40, color: Color.fromARGB(255, 1, 1, 1)),
                  ),
                ),
              ),
              ListTile(
                leading: Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: const Color.fromARGB(255, 2, 2, 2).withOpacity(0.2),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.home_outlined, color: Colors.white),
                ),
                title:
                    const Text('Home', style: TextStyle(color: Colors.white)),
                onTap: () {
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (context) => const CertificatePage(),
                    ),
                  );
                },
              ),
              ListTile(
                leading: Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: const Color.fromARGB(255, 2, 2, 2).withOpacity(0.2),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child:
                      const Icon(Icons.archive_outlined, color: Colors.white),
                ),
                title: const Text('Archive',
                    style: TextStyle(color: Colors.white)),
                onTap: () {
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (context) => const ArchivePage(),
                    ),
                  );
                },
              ),
              // Removed the Log Out ListTile
              /*
              ListTile(
                leading: Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: const Color.fromARGB(255, 2, 2, 2).withOpacity(0.2),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.logout, color: Colors.white),
                ),
                title: const Text('Log Out',
                    style: TextStyle(color: Colors.white)),
                onTap: () {
                  showDialog(
                    context: context,
                    builder: (BuildContext context) {
                      return Dialog(
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(16),
                        ),
                        child: Container(
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              begin: Alignment.topLeft,
                              end: Alignment.bottomRight,
                              colors: [const Color(0xFF6868AC).withOpacity(0.08), Colors.white],
                            ),
                            borderRadius: BorderRadius.circular(16),
                          ),
                          padding: const EdgeInsets.all(20),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              const Text(
                                'Confirm Logout',
                                style: TextStyle(
                                  color: Color(0xFF6868AC),
                                  fontFamily: 'Roboto',
                                  fontWeight: FontWeight.bold,
                                  fontSize: 20,
                                ),
                              ),
                              const SizedBox(height: 16),
                              const Text(
                                'Are you sure you want to log out?',
                                style: TextStyle(
                                  color: Colors.black87,
                                  fontFamily: 'Roboto',
                                  fontSize: 16,
                                ),
                              ),
                              const SizedBox(height: 24),
                              Row(
                                mainAxisAlignment:
                                    MainAxisAlignment.spaceAround,
                                children: [
                                  Expanded(
                                    child: OutlinedButton(
                                      onPressed: () => Navigator.pop(context),
                                      style: OutlinedButton.styleFrom(
                                        padding: const EdgeInsets.symmetric(
                                            vertical: 12),
                                        side: const BorderSide(
                                            color: Color(0xFF6868AC)),
                                        shape: RoundedRectangleBorder(
                                          borderRadius:
                                              BorderRadius.circular(8),
                                        ),
                                      ),
                                      child: const Text('Cancel'),
                                    ),
                                  ),
                                  const SizedBox(width: 16),
                                  Expanded(
                                    child: ElevatedButton(
                                      onPressed: () {
                                        Navigator.of(context).pop();
                                        Navigator.pushReplacement(
                                          context,
                                          MaterialPageRoute(
                                            builder: (context) =>
                                                const LoginPage(), // This line refers to LoginPage
                                          ),
                                        );
                                      },
                                      style: ElevatedButton.styleFrom(
                                        backgroundColor: const Color(0xFF6868AC),
                                        foregroundColor: Colors.white,
                                        padding: const EdgeInsets.symmetric(
                                            vertical: 12),
                                        shape: RoundedRectangleBorder(
                                          borderRadius:
                                              BorderRadius.circular(8),
                                        ),
                                      ),
                                      child: const Text('Log Out'),
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  );
                },
              ),
              */
            ],
          ),
        ),
      ),
      body: Stack(
        children: [
          Container(
            width: double.infinity,
            height: MediaQuery.of(context).size.height,
            decoration: const BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Color(0xFFE0F7FA),
                  Color(0xFFB2EBF2)
                ], // Light blue gradient
              ),
            ),
          ),
          Column(
            children: [
              Padding(
                padding: EdgeInsets.only(
                  top: MediaQuery.of(context).padding.top +
                      kToolbarHeight +
                      20.0,
                  left: 20.0,
                  right: 20.0,
                  bottom: 20.0,
                ),
                child: TextField(
                  onChanged: (value) {
                    setState(() {
                      searchQuery = value;
                    });
                  },
                  decoration: InputDecoration(
                    hintText: 'Search certificates...',
                    prefixIcon: const Icon(Icons.search, color: Colors.grey),
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10),
                      borderSide: BorderSide.none,
                    ),
                    filled: true,
                    fillColor: Colors.white.withOpacity(0.8),
                    contentPadding: const EdgeInsets.symmetric(vertical: 0),
                  ),
                ),
              ),
              Expanded(
                child: _filteredCertificates.isEmpty
                    ? const Center(
                        child: Text(
                          'No certificates found.',
                          style: TextStyle(fontSize: 16, color: Colors.black54),
                        ),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.symmetric(horizontal: 16.0),
                        itemCount: _filteredCertificates.length,
                        itemBuilder: (context, index) {
                          final certificate = _filteredCertificates[index];
                          return Card(
                            margin: const EdgeInsets.only(bottom: 10.0),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12),
                            ),
                            elevation: 3,
                            child: Padding(
                              padding: const EdgeInsets.all(12.0),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    certificate.title,
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16,
                                      color: Color(0xFF6868AC),
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text('Recipient: ${certificate.recipient}',
                                      style: const TextStyle(
                                          fontSize: 13, color: Colors.black87)),
                                  const SizedBox(height: 4),
                                  Text('ID: ${certificate.certificateId}',
                                      style: const TextStyle(
                                          fontSize: 12, color: Colors.grey)),
                                  const SizedBox(height: 8),
                                  Row(
                                    children: [
                                      Icon(Icons.category,
                                          size: 16,
                                          color: Colors.grey.shade600),
                                      const SizedBox(width: 4),
                                      Text('Type: ${certificate.type}'),
                                    ],
                                  ),
                                  const SizedBox(height: 4),
                                  Row(
                                    children: [
                                      Icon(Icons.calendar_today,
                                          size: 16,
                                          color: Colors.grey.shade600),
                                      const SizedBox(width: 4),
                                      Expanded(
                                        child: Text(
                                          'Issued: ${DateFormat('MMM dd, yyyy').format(certificate.issueDate)} | Expires: ${DateFormat('MMM dd, yyyy').format(certificate.expiryDate)}',
                                          style: const TextStyle(fontSize: 13),
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 8),
                                  Align(
                                    alignment: Alignment.centerRight,
                                    child: Container(
                                      padding: const EdgeInsets.symmetric(
                                          horizontal: 8, vertical: 4),
                                      decoration: BoxDecoration(
                                        color:
                                            _getStatusColor(certificate.status),
                                        borderRadius: BorderRadius.circular(8),
                                      ),
                                      child: Text(
                                        certificate.status,
                                        style: const TextStyle(
                                            fontSize: 12, color: Colors.white),
                                      ),
                                    ),
                                  ),
                                  const Divider(
                                      height: 20,
                                      thickness: 1,
                                      color: Colors.grey),
                                  Row(
                                    mainAxisAlignment:
                                        MainAxisAlignment.spaceAround,
                                    children: [
                                      _buildActionButton(
                                        icon: Icons.visibility,
                                        label: 'View',
                                        color: const Color(0xFF6868AC),
                                        onPressed: () {
                                          ScaffoldMessenger.of(context)
                                              .showSnackBar(
                                            SnackBar(
                                                content: Text(
                                                    'Viewing ${certificate.title}')),
                                          );
                                        },
                                      ),
                                      _buildActionButton(
                                        icon: Icons.download,
                                        label: 'Download',
                                        color: Colors.green.shade600,
                                        onPressed: () {
                                          ScaffoldMessenger.of(context)
                                              .showSnackBar(
                                            SnackBar(
                                                content: Text(
                                                    'Downloading ${certificate.title}')),
                                          );
                                        },
                                      ),
                                      _buildActionButton(
                                        icon: Icons.share,
                                        label: 'Share',
                                        color: Colors.purple.shade600,
                                        onPressed: () {
                                          ScaffoldMessenger.of(context)
                                              .showSnackBar(
                                            SnackBar(
                                                content: Text(
                                                    'Sharing ${certificate.title}')),
                                          );
                                        },
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
            ],
          ),
          // Notification drawer
          SlideTransition(
            position: _slideAnimation,
            child: Align(
              alignment: Alignment.centerRight,
              child: Container(
                width: 200,
                height: 530,
                decoration: BoxDecoration(
                  color: Colors.deepPurple.shade50,
                  borderRadius: BorderRadius.circular(10),
                  boxShadow: const [
                    BoxShadow(
                      color: Colors.black26,
                      blurRadius: 100,
                      spreadRadius: 20,
                      offset: Offset(-2, 0),
                    ),
                  ],
                ),
                child: SafeArea(
                  child: Column(
                    children: [
                      Padding(
                        padding: const EdgeInsets.symmetric(
                            vertical: 0, horizontal: 10),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text(
                              "Notifications",
                              style: TextStyle(
                                  fontSize: 20,
                                  fontWeight: FontWeight.bold,
                                  color: Colors.lightBlueAccent),
                            ),
                            IconButton(
                              icon: const Icon(Icons.close,
                                  color: Colors.lightBlueAccent),
                              onPressed: toggleNotificationDrawer,
                            ),
                          ],
                        ),
                      ),
                      Divider(color: Colors.lightBlueAccent.shade100),
                      Expanded(
                        child: notifications.isEmpty
                            ? Center(
                                child: Text(
                                  'No notification',
                                  style: TextStyle(
                                      fontSize: 10,
                                      color: Colors.lightBlueAccent.shade200),
                                ),
                              )
                            : ListView.separated(
                                padding:
                                    const EdgeInsets.symmetric(vertical: 8),
                                itemBuilder: (context, index) {
                                  return ListTile(
                                    leading: const Icon(
                                        Icons.notifications_active,
                                        color: Colors.lightBlueAccent),
                                    title: Text(notifications[index]),
                                    trailing: Icon(Icons.chevron_right,
                                        color: Colors.lightBlue.shade300),
                                    onTap: () {
                                      ScaffoldMessenger.of(context)
                                          .showSnackBar(
                                        SnackBar(
                                          content: Text(
                                              'Tapped: ${notifications[index]}'),
                                        ),
                                      );
                                    },
                                  );
                                },
                                separatorBuilder: (context, index) => Divider(
                                  color: Colors.deepPurple.shade100,
                                  indent: 10,
                                  endIndent: 10,
                                ),
                                itemCount: notifications.length,
                              ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Add New Certificate button pressed')),
          );
        },
        label: const Text('Add Certificate',
            style: TextStyle(color: Colors.white)),
        icon: const Icon(Icons.add, color: Colors.white),
        backgroundColor: const Color(0xFF6868AC),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
    );
  }

  // Helper for action buttons (View, Download, Share)
  Widget _buildActionButton({
    required IconData icon,
    required String label,
    required Color color,
    required VoidCallback onPressed,
  }) {
    return Column(
      children: [
        InkWell(
          onTap: onPressed,
          borderRadius: BorderRadius.circular(8),
          child: Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: color.withOpacity(0.1),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Icon(icon, color: color, size: 20),
          ),
        ),
        const SizedBox(height: 4),
        Text(
          label,
          style: TextStyle(fontSize: 10, color: color),
        ),
      ],
    );
  }

  // Helper to get status color for Certificates
  Color _getStatusColor(String status) {
    switch (status.toLowerCase()) {
      case 'valid':
        return Colors.green.shade600;
      case 'expired':
        return Colors.red.shade600;
      case 'revoked':
        return Colors.red.shade900;
      default:
        return Colors.grey.shade600;
    }
  }
}
