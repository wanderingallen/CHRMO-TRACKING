import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

// Import pages
import 'camera_page.dart';
import 'notification_page.dart';
import 'login_page.dart';

/// Mobile-first Dashboard with Bottom Navigation
class MobileDashboardPage extends StatefulWidget {
  final String? username;
  final String? email;
  
  const MobileDashboardPage({super.key, this.username, this.email});

  @override
  State<MobileDashboardPage> createState() => _MobileDashboardPageState();
}

class _MobileDashboardPageState extends State<MobileDashboardPage> 
    with SingleTickerProviderStateMixin {
  int _currentIndex = 0;
  String username = 'Loading...';
  String email = 'Loading...';
  String searchQuery = '';
  bool _showSearch = false;
  
  late AnimationController _fabController;
  late Animation<double> _fabAnimation;

  // Sample KPI data
  final List<Map<String, dynamic>> _kpiData = [
    {'title': 'Pending Review', 'count': 23, 'icon': Icons.hourglass_empty, 'color': Colors.orange},
    {'title': 'In Transit', 'count': 15, 'icon': Icons.local_shipping, 'color': Colors.blue},
    {'title': 'Completed', 'count': 156, 'icon': Icons.check_circle, 'color': Colors.green},
    {'title': 'Overdue', 'count': 3, 'icon': Icons.warning, 'color': Colors.red},
  ];

  // Sample recent activity
  final List<Map<String, dynamic>> _recentActivity = [
    {'title': 'Payroll Document', 'subtitle': 'Approved by Finance', 'time': '2 min ago', 'icon': Icons.attach_money},
    {'title': 'Travel Order #1234', 'subtitle': 'Routed to Admin', 'time': '15 min ago', 'icon': Icons.flight_takeoff},
    {'title': 'Memo - Policy Update', 'subtitle': 'Pending Review', 'time': '1 hr ago', 'icon': Icons.description},
    {'title': 'Purchase Request', 'subtitle': 'Completed', 'time': '2 hrs ago', 'icon': Icons.shopping_cart},
    {'title': 'Activity Design', 'subtitle': 'In Transit', 'time': '3 hrs ago', 'icon': Icons.event},
    {'title': 'Announcement', 'subtitle': 'Archived', 'time': '5 hrs ago', 'icon': Icons.campaign},
  ];

  @override
  void initState() {
    super.initState();
    _fabController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 300),
    );
    _fabAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _fabController, curve: Curves.easeOutCubic),
    );
    _fabController.forward();
    _fetchUserData();
  }

  @override
  void dispose() {
    _fabController.dispose();
    super.dispose();
  }

  Future<void> _fetchUserData() async {
    await Future.delayed(const Duration(milliseconds: 300));
    final routeArgs = ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?;
    
    String? savedUsername;
    String? savedEmail;
    
    try {
      final prefs = await SharedPreferences.getInstance();
      savedUsername = prefs.getString('user_name');
      savedEmail = prefs.getString('user_email');
    } catch (e) {
      debugPrint('Error loading user data: $e');
    }
    
    if (mounted) {
      setState(() {
        username = widget.username ?? 
                   routeArgs?['username'] ?? 
                   savedUsername ??
                   'User';
        email = widget.email ?? 
                routeArgs?['email'] ?? 
                savedEmail ??
                'user@example.com';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.grey[50],
      appBar: AppBar(
        backgroundColor: Colors.blue.shade700,
        elevation: 0,
        title: _showSearch ? _buildSearchField() : Text(
          _getPageTitle(),
          style: const TextStyle(
            color: Colors.white,
            fontSize: 20,
            fontWeight: FontWeight.w600,
          ),
        ),
        actions: [
          IconButton(
            icon: Icon(_showSearch ? Icons.close : Icons.search, color: Colors.white),
            onPressed: () => setState(() => _showSearch = !_showSearch),
          ),
          IconButton(
            icon: const Icon(Icons.notifications_outlined, color: Colors.white),
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (context) => const NotificationPage()),
            ),
          ),
        ],
        systemOverlayStyle: SystemUiOverlayStyle.light,
      ),
      body: _buildBody(),
      floatingActionButton: _buildFAB(),
      floatingActionButtonLocation: FloatingActionButtonLocation.centerDocked,
      bottomNavigationBar: _buildBottomNav(),
    );
  }

  String _getPageTitle() {
    switch (_currentIndex) {
      case 0: return 'Dashboard';
      case 1: return 'Tracking';
      case 2: return 'Archive';
      case 3: return 'Profile';
      default: return 'Dashboard';
    }
  }

  Widget _buildSearchField() {
    return TextField(
      autofocus: true,
      onChanged: (value) => setState(() => searchQuery = value),
      decoration: const InputDecoration(
        hintText: 'Search documents...',
        hintStyle: TextStyle(color: Colors.white70),
        border: InputBorder.none,
      ),
      style: const TextStyle(color: Colors.white, fontSize: 18),
      cursorColor: Colors.white,
    );
  }

  Widget _buildBody() {
    switch (_currentIndex) {
      case 0: return _buildDashboardContent();
      case 1: return const Center(child: Text('Tracking Page'));
      case 2: return const Center(child: Text('Archive Page'));
      case 3: return _buildProfileContent();
      default: return _buildDashboardContent();
    }
  }

  Widget _buildDashboardContent() {
    return RefreshIndicator(
      onRefresh: () async {
        await Future.delayed(const Duration(seconds: 1));
        setState(() {});
      },
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.only(bottom: 80),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildWelcomeBanner(),
            const SizedBox(height: 16),
            _buildKPITiles(),
            const SizedBox(height: 24),
            _buildSectionHeader('Recent Activity', onTap: () {}),
            _buildRecentActivityFeed(),
          ],
        ),
      ),
    );
  }

  Widget _buildWelcomeBanner() {
    return Container(
      margin: const EdgeInsets.all(16),
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [Colors.blue.shade600, Colors.blue.shade800],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.blue.withOpacity(0.3),
            blurRadius: 8,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        children: [
          CircleAvatar(
            radius: 30,
            backgroundColor: Colors.white,
            child: Icon(Icons.person, size: 35, color: Colors.blue.shade700),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Welcome back,',
                  style: TextStyle(
                    color: Colors.white70,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  username,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildKPITiles() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          crossAxisSpacing: 12,
          mainAxisSpacing: 12,
          childAspectRatio: 1.3,
        ),
        itemCount: _kpiData.length,
        itemBuilder: (context, index) {
          final kpi = _kpiData[index];
          return _buildKPITile(
            title: kpi['title'],
            count: kpi['count'],
            icon: kpi['icon'],
            color: kpi['color'],
          );
        },
      ),
    );
  }

  Widget _buildKPITile({
    required String title,
    required int count,
    required IconData icon,
    required Color color,
  }) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      elevation: 2,
      child: InkWell(
        onTap: () {
          // Navigate to filtered view
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('Viewing $title documents')),
          );
        },
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: color.withOpacity(0.1),
                  shape: BoxShape.circle,
                ),
                child: Icon(icon, color: color, size: 32),
              ),
              const SizedBox(height: 12),
              Text(
                count.toString(),
                style: TextStyle(
                  fontSize: 28,
                  fontWeight: FontWeight.bold,
                  color: color,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                title,
                style: TextStyle(
                  fontSize: 13,
                  color: Colors.grey[700],
                  fontWeight: FontWeight.w500,
                ),
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildSectionHeader(String title, {VoidCallback? onTap}) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            title,
            style: const TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.bold,
              color: Colors.black87,
            ),
          ),
          if (onTap != null)
            TextButton(
              onPressed: onTap,
              child: const Text('See All'),
            ),
        ],
      ),
    );
  }

  Widget _buildRecentActivityFeed() {
    return ListView.separated(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: 16),
      itemCount: _recentActivity.length,
      separatorBuilder: (context, index) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final activity = _recentActivity[index];
        return _buildActivityItem(
          title: activity['title'],
          subtitle: activity['subtitle'],
          time: activity['time'],
          icon: activity['icon'],
        );
      },
    );
  }

  Widget _buildActivityItem({
    required String title,
    required String subtitle,
    required String time,
    required IconData icon,
  }) {
    return Material(
      color: Colors.white,
      child: InkWell(
        onTap: () {
          // Navigate to document details
        },
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 12),
          constraints: const BoxConstraints(minHeight: 72),
          child: Row(
            children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(
                  color: Colors.blue.shade50,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, color: Colors.blue.shade700, size: 24),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(
                      title,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        color: Colors.black87,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      subtitle,
                      style: TextStyle(
                        fontSize: 13,
                        color: Colors.grey[600],
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ],
                ),
              ),
              Text(
                time,
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.grey[500],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildProfileContent() {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          CircleAvatar(
            radius: 50,
            backgroundColor: Colors.blue.shade100,
            child: Icon(Icons.person, size: 60, color: Colors.blue.shade700),
          ),
          const SizedBox(height: 16),
          Text(
            username,
            style: const TextStyle(
              fontSize: 24,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            email,
            style: TextStyle(
              fontSize: 16,
              color: Colors.grey[600],
            ),
          ),
          const SizedBox(height: 32),
          _buildProfileOption(Icons.person_outline, 'Edit Profile', () {}),
          _buildProfileOption(Icons.settings_outlined, 'Settings', () {}),
          _buildProfileOption(Icons.help_outline, 'Help & Support', () {}),
          _buildProfileOption(Icons.logout, 'Logout', () {
            Navigator.pushReplacement(
              context,
              MaterialPageRoute(builder: (context) => const LoginPage()),
            );
          }, isDestructive: true),
        ],
      ),
    );
  }

  Widget _buildProfileOption(IconData icon, String title, VoidCallback onTap, {bool isDestructive = false}) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: Icon(icon, color: isDestructive ? Colors.red : Colors.blue.shade700),
        title: Text(
          title,
          style: TextStyle(
            color: isDestructive ? Colors.red : Colors.black87,
            fontWeight: FontWeight.w500,
          ),
        ),
        trailing: const Icon(Icons.chevron_right),
        onTap: onTap,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      ),
    );
  }

  Widget _buildFAB() {
    return ScaleTransition(
      scale: _fabAnimation,
      child: FloatingActionButton.extended(
        onPressed: () {
          Navigator.push(
            context,
            MaterialPageRoute(builder: (context) => const CameraPage()),
          );
        },
        backgroundColor: Colors.blue.shade700,
        elevation: 8,
        icon: const Icon(Icons.camera_alt, size: 28),
        label: const Text(
          'Scan Document',
          style: TextStyle(
            fontSize: 16,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }

  Widget _buildBottomNav() {
    return BottomAppBar(
      shape: const CircularNotchedRectangle(),
      notchMargin: 8,
      elevation: 8,
      child: SizedBox(
        height: 60,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceAround,
          children: [
            _buildNavItem(Icons.dashboard_outlined, Icons.dashboard, 'Dashboard', 0),
            _buildNavItem(Icons.track_changes_outlined, Icons.track_changes, 'Tracking', 1),
            const SizedBox(width: 48), // Space for FAB
            _buildNavItem(Icons.archive_outlined, Icons.archive, 'Archive', 2),
            _buildNavItem(Icons.person_outline, Icons.person, 'Profile', 3),
          ],
        ),
      ),
    );
  }

  Widget _buildNavItem(IconData icon, IconData activeIcon, String label, int index) {
    final isActive = _currentIndex == index;
    return Expanded(
      child: InkWell(
        onTap: () => setState(() => _currentIndex = index),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              isActive ? activeIcon : icon,
              color: isActive ? Colors.blue.shade700 : Colors.grey,
              size: 26,
            ),
            const SizedBox(height: 4),
            Text(
              label,
              style: TextStyle(
                fontSize: 11,
                color: isActive ? Colors.blue.shade700 : Colors.grey,
                fontWeight: isActive ? FontWeight.w600 : FontWeight.normal,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildDrawer() {
    return Drawer(
      child: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [Colors.blue.shade700, Colors.blue.shade900],
          ),
        ),
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            DrawerHeader(
              decoration: BoxDecoration(
                color: Colors.blue.shade800,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  CircleAvatar(
                    radius: 30,
                    backgroundColor: Colors.white,
                    child: Icon(Icons.person, size: 35, color: Colors.blue.shade700),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    username,
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  Text(
                    email,
                    style: const TextStyle(
                      color: Colors.white70,
                      fontSize: 14,
                    ),
                  ),
                ],
              ),
            ),
            _buildDrawerItem(Icons.dashboard, 'Dashboard', () {
              Navigator.pop(context);
              setState(() => _currentIndex = 0);
            }),
            _buildDrawerItem(Icons.track_changes, 'Document Tracking', () {
              Navigator.pop(context);
              setState(() => _currentIndex = 1);
            }),
            _buildDrawerItem(Icons.archive, 'Archive', () {
              Navigator.pop(context);
              setState(() => _currentIndex = 2);
            }),
            _buildDrawerItem(Icons.notifications, 'Notifications', () {
              Navigator.pop(context);
              Navigator.push(
                context,
                MaterialPageRoute(builder: (context) => const NotificationPage()),
              );
            }),
            const Divider(color: Colors.white24, height: 32),
            _buildDrawerItem(Icons.settings, 'Settings', () {}),
            _buildDrawerItem(Icons.help, 'Help & Support', () {}),
            _buildDrawerItem(Icons.logout, 'Logout', () {
              Navigator.pushReplacement(
                context,
                MaterialPageRoute(builder: (context) => const LoginPage()),
              );
            }),
          ],
        ),
      ),
    );
  }

  Widget _buildDrawerItem(IconData icon, String title, VoidCallback onTap) {
    return ListTile(
      leading: Icon(icon, color: Colors.white),
      title: Text(
        title,
        style: const TextStyle(
          color: Colors.white,
          fontSize: 16,
        ),
      ),
      onTap: onTap,
      contentPadding: const EdgeInsets.symmetric(horizontal: 24, vertical: 4),
    );
  }
}
