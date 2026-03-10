// personaldata_page.dart
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart'; // For date formatting

import 'addpersonaldata_page.dart'; // Import the AddPersonalDataPage
import 'archive_page.dart'; // Import the new ArchivePage
// Import dashboard_page.dart for navigation

// New data model for PersonalData
class PersonalData {
  final String fullName;
  final String employeeId;
  final DateTime dateOfBirth;
  final String address;
  final String phoneNumber;
  final String email;
  final String emergencyContactName;
  final String emergencyContactNumber;
  final DateTime hireDate;
  final String position;

  PersonalData({
    required this.fullName,
    required this.employeeId,
    required this.dateOfBirth,
    required this.address,
    required this.phoneNumber,
    required this.email,
    required this.emergencyContactName,
    required this.emergencyContactNumber,
    required this.hireDate,
    required this.position,
  });
}

class PersonalDataPage extends StatefulWidget {
  const PersonalDataPage({super.key});

  @override
  State<PersonalDataPage> createState() => _PersonalDataPageState();
}

class _PersonalDataPageState extends State<PersonalDataPage>
    with SingleTickerProviderStateMixin {
  String username = 'Loading...';
  String email = 'Loading...';
  String searchQuery = '';

  late AnimationController _notificationController;
  late Animation<Offset> _slideAnimation;
  bool _isNotificationDrawerOpen = false;

  final List<String> notifications = [
    "Personal data for John Doe updated",
    "New employee personal data added",
    "Reminder: Review employee personal data",
  ];

  List<PersonalData> personalDataList = [
    // Sample Data
    PersonalData(
      fullName: 'John Doe',
      employeeId: 'EMP001',
      dateOfBirth: DateTime(1990, 5, 15),
      address: '123 Main St, Anytown, USA',
      phoneNumber: '555-1234',
      email: 'john.doe@example.com',
      emergencyContactName: 'Jane Doe',
      emergencyContactNumber: '555-5678',
      hireDate: DateTime(2018, 1, 10),
      position: 'Software Engineer',
    ),
    PersonalData(
      fullName: 'Jane Smith',
      employeeId: 'EMP002',
      dateOfBirth: DateTime(1988, 11, 22),
      address: '456 Oak Ave, Anytown, USA',
      phoneNumber: '555-9876',
      email: 'jane.smith@example.com',
      emergencyContactName: 'John Smith',
      emergencyContactNumber: '555-4321',
      hireDate: DateTime(2019, 7, 1),
      position: 'HR Manager',
    ),
    // Add more sample data as needed
  ];

  List<PersonalData> filteredPersonalData = [];

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

    _fetchUserData();
    _filterPersonalData(); // Initialize filtered list
  }

  @override
  void dispose() {
    _notificationController.dispose();
    super.dispose();
  }

  void _fetchUserData() async {
    // Simulate a network delay
    await Future.delayed(const Duration(seconds: 1));
    setState(() {
      username = 'Welcome, Admin';
      email = 'admin@chrmo.com';
    });
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

  void _filterPersonalData() {
    setState(() {
      if (searchQuery.isEmpty) {
        filteredPersonalData = List.from(personalDataList);
      } else {
        filteredPersonalData = personalDataList
            .where((data) =>
                data.fullName
                    .toLowerCase()
                    .contains(searchQuery.toLowerCase()) ||
                data.employeeId
                    .toLowerCase()
                    .contains(searchQuery.toLowerCase()) ||
                data.position.toLowerCase().contains(searchQuery.toLowerCase()))
            .toList();
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Personal Data Sheets'),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
        systemOverlayStyle: SystemUiOverlayStyle.light,
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications),
            onPressed: toggleNotificationDrawer,
          ),
          IconButton(
            icon: const Icon(Icons.settings),
            onPressed: () {
              // Handle settings action
            },
          ),
        ],
      ),
      drawer: Drawer(
        child: ListView(
          padding: EdgeInsets.zero,
          children: <Widget>[
            UserAccountsDrawerHeader(
              accountName: Text(username),
              accountEmail: Text(email),
              currentAccountPicture: const CircleAvatar(
                backgroundColor: Colors.white,
                child: Icon(
                  Icons.person,
                  color: Color(0xFF6868AC),
                  size: 40,
                ),
              ),
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [
                    const Color(0xFF6868AC),
                    const Color(0xFF6868AC).withOpacity(0.6)
                  ],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
            ),
            ListTile(
              leading: const Icon(Icons.archive),
              title: const Text('Archive'),
              onTap: () {
                Navigator.pop(context); // Close the drawer
                Navigator.pushReplacement(
                  context,
                  MaterialPageRoute(builder: (context) => const ArchivePage()),
                );
              },
            ),
            ListTile(
              leading: const Icon(Icons.person),
              title: const Text('Personal Data'),
              onTap: () {
                Navigator.pop(context); // Close the drawer
                // Already on Personal Data page
              },
            ),
            // Add other navigation items here if needed
          ],
        ),
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFE3F2FD), // Lightest blue
              Color(0xFFBBDEFB) // Lighter blue
            ],
          ),
        ),
        child: Stack(
          children: [
            Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(8.0),
                  child: TextField(
                    onChanged: (value) {
                      searchQuery = value;
                      _filterPersonalData();
                    },
                    decoration: InputDecoration(
                      hintText: 'Search by Name, Employee ID, or Position',
                      prefixIcon:
                          const Icon(Icons.search, color: Color(0xFF6868AC)),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(10),
                        borderSide: BorderSide.none,
                      ),
                      filled: true,
                      fillColor: Colors.white.withOpacity(0.9),
                    ),
                  ),
                ),
                Expanded(
                  child: filteredPersonalData.isEmpty
                      ? Center(
                          child: Text(
                            searchQuery.isEmpty
                                ? 'No personal data available.'
                                : 'No results found for "$searchQuery"',
                            style: const TextStyle(
                                fontSize: 16, color: Colors.black54),
                          ),
                        )
                      : ListView.builder(
                          itemCount: filteredPersonalData.length,
                          itemBuilder: (context, index) {
                            final data = filteredPersonalData[index];
                            return Card(
                              margin: const EdgeInsets.symmetric(
                                  horizontal: 10, vertical: 5),
                              elevation: 3,
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(12)),
                              child: Padding(
                                padding: const EdgeInsets.all(16.0),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      data.fullName,
                                      style: const TextStyle(
                                        fontSize: 18,
                                        fontWeight: FontWeight.bold,
                                        color: Color(0xFF52528A),
                                      ),
                                    ),
                                    const SizedBox(height: 5),
                                    _buildInfoRow(
                                        Icons.badge, 'ID: ${data.employeeId}'),
                                    _buildInfoRow(Icons.work, data.position),
                                    _buildInfoRow(Icons.calendar_today,
                                        'DOB: ${DateFormat('MMM dd, yyyy').format(data.dateOfBirth)}'),
                                    _buildInfoRow(Icons.date_range,
                                        'Hire Date: ${DateFormat('MMM dd, yyyy').format(data.hireDate)}'),
                                    const SizedBox(height: 10),
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.end,
                                      children: [
                                        _buildActionButton(
                                          icon: Icons.visibility,
                                          label: 'View',
                                          color: Colors.green,
                                          onPressed: () {
                                            ScaffoldMessenger.of(context)
                                                .showSnackBar(SnackBar(
                                                    content: Text(
                                                        'View ${data.fullName}')));
                                            // TODO: Implement view details
                                          },
                                        ),
                                        const SizedBox(width: 10),
                                        _buildActionButton(
                                          icon: Icons.edit,
                                          label: 'Edit',
                                          color: Colors.orange,
                                          onPressed: () {
                                            ScaffoldMessenger.of(context)
                                                .showSnackBar(SnackBar(
                                                    content: Text(
                                                        'Edit ${data.fullName}')));
                                            // TODO: Implement edit functionality
                                          },
                                        ),
                                        const SizedBox(width: 10),
                                        _buildActionButton(
                                          icon: Icons.delete,
                                          label: 'Delete',
                                          color: Colors.red,
                                          onPressed: () {
                                            ScaffoldMessenger.of(context)
                                                .showSnackBar(SnackBar(
                                                    content: Text(
                                                        'Delete ${data.fullName}')));
                                            // TODO: Implement delete functionality
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
            // Notification Drawer
            Positioned(
              right: 0,
              top: 0,
              bottom: 0,
              child: SlideTransition(
                position: _slideAnimation,
                child: Visibility(
                  visible: _isNotificationDrawerOpen,
                  child: Container(
                    width: MediaQuery.of(context).size.width * 0.7,
                    decoration: BoxDecoration(
                      color: const Color(0xFF6868AC).withOpacity(0.08),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withOpacity(0.2),
                          blurRadius: 10,
                          offset: const Offset(-5, 0),
                        ),
                      ],
                    ),
                    child: Column(
                      children: [
                        const Padding(
                          padding: EdgeInsets.all(16.0),
                          child: Text(
                            'Notifications',
                            style: TextStyle(
                              fontSize: 20,
                              fontWeight: FontWeight.bold,
                              color: Color(0xFF52528A),
                            ),
                          ),
                        ),
                        Expanded(
                          child: notifications.isEmpty
                              ? const Center(
                                  child: Text('No new notifications.'),
                                )
                              : ListView.separated(
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
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () {
          // Navigate to the AddPersonalDataPage
          Navigator.push(
            context,
            MaterialPageRoute(
                builder: (context) => const AddPersonalDataPage()),
          );
        },
        label: const Text('Add Personal Data',
            style: TextStyle(color: Colors.white)),
        icon: const Icon(Icons.add, color: Colors.white),
        backgroundColor: const Color(0xFF6868AC),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
    );
  }

  Widget _buildInfoRow(IconData icon, String text) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4.0),
      child: Row(
        children: [
          Icon(icon, size: 18, color: const Color(0xFF6868AC)),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: const TextStyle(fontSize: 14, color: Colors.black87),
            ),
          ),
        ],
      ),
    );
  }

  // Helper for action buttons (View, Edit, Delete)
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
}
