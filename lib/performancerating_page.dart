// performancerating_page.dart
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart'; // For date formatting
import 'archive_page.dart'; // Import the new ArchivePage
import 'addrating_page.dart'; // Import the new AddRatingPage

// New data model for PerformanceRating
class PerformanceRating {
  final String employeeName;
  final DateTime date;
  final String overallRating;
  final String reviewer;
  final String feedbackSummary;
  final String ratingId; // Unique ID for the rating

  PerformanceRating({
    required this.employeeName,
    required this.date,
    required this.overallRating,
    required this.reviewer,
    required this.feedbackSummary,
    required this.ratingId,
  });
}

class PerformanceRatingPage extends StatefulWidget {
  const PerformanceRatingPage({super.key});

  @override
  State<PerformanceRatingPage> createState() => _PerformanceRatingPageState();
}

class _PerformanceRatingPageState extends State<PerformanceRatingPage>
    with SingleTickerProviderStateMixin {
  String username = 'Loading...';
  String email = 'Loading...';
  String searchQuery = '';

  late AnimationController _notificationController;
  late Animation<Offset> _slideAnimation;
  bool _isNotificationDrawerOpen = false;

  final List<String> notifications = [
    "New performance review for John Doe",
    "Performance review cycle ends next week",
    "Reminder: Complete pending ratings",
    "Rating #PR-003 updated",
  ];

  // Sample data for Performance Ratings
  final List<PerformanceRating> _performanceRatings = [
    PerformanceRating(
      employeeName: 'John Doe',
      date: DateTime(2024, 1, 15),
      overallRating: 'Excellent',
      reviewer: 'Alice Smith',
      feedbackSummary:
          'Consistently exceeds expectations, strong leadership skills.',
      ratingId: 'PR-001',
    ),
    PerformanceRating(
      employeeName: 'Jane Smith',
      date: DateTime(2024, 3, 1),
      overallRating: 'Good',
      reviewer: 'Bob Johnson',
      feedbackSummary:
          'Meets all objectives, shows initiative in new projects.',
      ratingId: 'PR-002',
    ),
    PerformanceRating(
      employeeName: 'Peter Jones',
      date: DateTime(2023, 11, 20),
      overallRating: 'Needs Improvement',
      reviewer: 'Alice Smith',
      feedbackSummary:
          'Areas for development identified in communication and time management.',
      ratingId: 'PR-003',
    ),
    PerformanceRating(
      employeeName: 'Emily White',
      date: DateTime(2024, 5, 10),
      overallRating: 'Excellent',
      reviewer: 'Charlie Brown',
      feedbackSummary:
          'Exceptional problem-solving abilities and team collaboration.',
      ratingId: 'PR-004',
    ),
    PerformanceRating(
      employeeName: 'Michael Green',
      date: DateTime(2024, 2, 28),
      overallRating: 'Good',
      reviewer: 'Bob Johnson',
      feedbackSummary:
          'Reliable and consistent contributor, good progress on personal goals.',
      ratingId: 'PR-005',
    ),
  ];

  List<PerformanceRating> get _filteredRatings {
    if (searchQuery.isEmpty) {
      return _performanceRatings;
    }
    return _performanceRatings.where((rating) {
      final queryLower = searchQuery.toLowerCase();
      return rating.employeeName.toLowerCase().contains(queryLower) ||
          rating.reviewer.toLowerCase().contains(queryLower) ||
          rating.overallRating.toLowerCase().contains(queryLower) ||
          rating.ratingId.toLowerCase().contains(queryLower);
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
                      builder: (context) => const PerformanceRatingPage()),
                );
              }
            },
          ),
        ),
        title: const Text(
          'Performance Ratings',
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
                      builder: (context) => const PerformanceRatingPage(),
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
              // Removed Log Out ListTile as per previous request
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
                    hintText: 'Search performance ratings...',
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
                child: _filteredRatings.isEmpty
                    ? const Center(
                        child: Text(
                          'No performance ratings found.',
                          style: TextStyle(fontSize: 16, color: Colors.black54),
                        ),
                      )
                    : ListView.builder(
                        padding: const EdgeInsets.symmetric(horizontal: 16.0),
                        itemCount: _filteredRatings.length,
                        itemBuilder: (context, index) {
                          final rating = _filteredRatings[index];
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
                                    rating.employeeName,
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16,
                                      color: Color(0xFF6868AC),
                                    ),
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                      'Review Date: ${DateFormat('MMM dd,yyyy').format(rating.date)}',
                                      style: const TextStyle(
                                          fontSize: 13, color: Colors.black87)),
                                  const SizedBox(height: 4),
                                  Text('Reviewer: ${rating.reviewer}',
                                      style: const TextStyle(
                                          fontSize: 13, color: Colors.black87)),
                                  const SizedBox(height: 8),
                                  Row(
                                    children: [
                                      Icon(Icons.star,
                                          size: 16,
                                          color: _getRatingColor(
                                              rating.overallRating)),
                                      const SizedBox(width: 4),
                                      Text(
                                          'Overall Rating: ${rating.overallRating}',
                                          style: TextStyle(
                                              color: _getRatingColor(
                                                  rating.overallRating),
                                              fontWeight: FontWeight.bold)),
                                    ],
                                  ),
                                  const SizedBox(height: 8),
                                  Text(
                                    'Summary: ${rating.feedbackSummary}',
                                    style: const TextStyle(
                                        fontSize: 14, color: Colors.black54),
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
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
                                        icon: Icons.description,
                                        label: 'Details',
                                        color: const Color(0xFF6868AC),
                                        onPressed: () {
                                          ScaffoldMessenger.of(context)
                                              .showSnackBar(
                                            SnackBar(
                                                content: Text(
                                                    'Viewing details for ${rating.employeeName}')),
                                          );
                                        },
                                      ),
                                      _buildActionButton(
                                        icon: Icons.edit,
                                        label: 'Edit',
                                        color: Colors.orange.shade600,
                                        onPressed: () {
                                          ScaffoldMessenger.of(context)
                                              .showSnackBar(
                                            SnackBar(
                                                content: Text(
                                                    'Editing rating for ${rating.employeeName}')),
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
                                                    'Sharing rating for ${rating.employeeName}')),
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
          // Navigate to the AddRatingPage
          Navigator.push(
            context,
            MaterialPageRoute(builder: (context) => const AddRatingPage()),
          );
        },
        label: const Text('Add Rating', style: TextStyle(color: Colors.white)),
        icon: const Icon(Icons.add, color: Colors.white),
        backgroundColor: const Color(0xFF6868AC),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
    );
  }

  // Helper for action buttons (View, Edit, Share)
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

  // Helper to get status color for Performance Rating
  Color _getRatingColor(String rating) {
    switch (rating.toLowerCase()) {
      case 'excellent':
        return Colors.green.shade600;
      case 'good':
        return const Color(0xFF6868AC);
      case 'average': // Added 'Average' rating
        return Colors.amber.shade600;
      case 'needs improvement':
        return Colors.orange.shade600;
      case 'poor':
        return Colors.red.shade600;
      default:
        return Colors.grey.shade600;
    }
  }
}
