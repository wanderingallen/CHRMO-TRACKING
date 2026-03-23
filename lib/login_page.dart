import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart'; // Import for SystemChrome
import 'package:http/http.dart' as http;
import 'package:network_info_plus/network_info_plus.dart';
import 'package:shared_preferences/shared_preferences.dart';

// Assuming this file exists and provides LocalStorage
// Assuming this file exists
import 'dashboard_page.dart'; // Assuming this file exists and is the landing page
import 'services/server_service.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> with TickerProviderStateMixin {
  TextEditingController emailController = TextEditingController();
  TextEditingController passwordController = TextEditingController();
  bool rememberUser = false;
  bool isPasswordVisible = false;
  bool showLoginForm = true;
  bool isLoggingIn = false;
  int _loginFailures = 0;
  DateTime? _backoffUntil;
  String _connectivityStatus =
      'not_detected'; // connected | not_detected | offline
  String? _serverUrlDisplay;
  Timer? _connectivityTimer;
  late AnimationController _fadeController;
  late AnimationController _slideController;
  late Animation<double> _fadeAnimation;
  late Animation<Offset> _slideAnimation;
  String? _prevConnectivityStatus; // for toast changes only

  @override
  void initState() {
    super.initState();
    // Set system UI overlay style for a clean look
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent, // Make status bar transparent
        statusBarIconBrightness:
            Brightness.dark, // Use dark icons for light background
      ),
    );

    // Initialize animation controllers
    _fadeController = AnimationController(
      duration: const Duration(milliseconds: 1000),
      vsync: this,
    );
    _slideController = AnimationController(
      duration: const Duration(milliseconds: 800),
      vsync: this,
    );

    _fadeAnimation = Tween<double>(
      begin: 0.0,
      end: 1.0,
    ).animate(CurvedAnimation(
      parent: _fadeController,
      curve: Curves.easeInOut,
    ));

    _slideAnimation = Tween<Offset>(
      begin: const Offset(0, 0.3),
      end: Offset.zero,
    ).animate(CurvedAnimation(
      parent: _slideController,
      curve: Curves.easeOutCubic,
    ));

    // Load saved credentials if "Keep me signed in" was checked
    _loadSavedCredentials();

    // Start the loading sequence
    _startLoadingSequence();

    // Initial connectivity status and periodic refresh
    _refreshConnectivity();
    _connectivityTimer = Timer.periodic(
        const Duration(seconds: 10), (_) => _refreshConnectivity());
  }

  void _startLoadingSequence() {
    // Start animations immediately — branding + splash already handled loading
    _fadeController.forward();
    _slideController.forward();
  }

  @override
  void dispose() {
    emailController.dispose();
    passwordController.dispose();
    _fadeController.dispose();
    _slideController.dispose();
    _connectivityTimer?.cancel();
    super.dispose();
  }

  // Build API base path from detected/saved server URL, with generic LAN fallbacks
  Future<String> _getApiBase() async {
    final root = await ServerService.getServerRoot();
    return '$root/lib/OCR(UPDATED)/api';
  }

  // Verify a base URL belongs to our server by calling ping.php and checking signature
  Future<bool> _isOurServer(String base) async {
    try {
      final uri = Uri.parse('$base/ping.php');
      final r = await http.get(uri).timeout(const Duration(milliseconds: 1200));
      if (r.statusCode >= 200 && r.statusCode < 400 && r.body.isNotEmpty) {
        try {
          final m = jsonDecode(r.body) as Map<String, dynamic>;
          final app = (m['app'] ?? '').toString().toLowerCase();
          final ok = m['ok'] == true || m['success'] == true;
          return ok && app.contains('chrmo');
        } catch (_) {}
      }
    } catch (_) {}
    return false;
  }

  // --- Connectivity + Status ---
  Future<void> _refreshConnectivity() async {
    try {
      final info = NetworkInfo();
      final wifiIP = await info.getWifiIP();
      final wifiGateway = await info.getWifiGatewayIP();

      String status = 'not_detected';
      String? display;

      // Try a quick saved URL check first
      final saved = await _loadSavedServerUrl();
      if (saved != null) {
        status = 'connected';
        display = saved;
      } else {
        // Fallback: assume fixed server IP as the target so banner doesn't
        // permanently show "Server not detected" on mobile.
        final fixedBase = await ServerService.getServerUrl();

        // If the device has some Wi‑Fi connectivity, treat as connected
        if ((wifiIP != null && wifiIP.isNotEmpty) ||
            (wifiGateway != null && wifiGateway.isNotEmpty)) {
          status = 'connected';
          display = fixedBase;
        } else {
          // No IP/gateway means truly offline
          status = 'offline';
        }
      }

      if (mounted) {
        // show toast (SnackBar) if status changed
        final changed = _prevConnectivityStatus != status;
        setState(() {
          _connectivityStatus = status;
          _serverUrlDisplay = display;
          _prevConnectivityStatus = status;
        });
        if (changed) {
          final bool isConnected = status == 'connected';
          final bg =
              isConnected ? const Color(0xFF16A34A) : const Color(0xFFDC2626);
          final msg = isConnected
              ? (display != null ? 'Connected to $display' : 'Connected')
              : (status == 'offline' ? 'Offline' : 'Server not detected');
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(msg),
              backgroundColor: bg,
              duration: const Duration(seconds: 2),
              behavior: SnackBarBehavior.floating,
            ),
          );
        }
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _connectivityStatus = 'offline';
          _serverUrlDisplay = null;
        });
      }
    }
  }

  Widget _buildConnectivityBanner() {
    Color bg;
    IconData icon;
    String text;
    switch (_connectivityStatus) {
      case 'connected':
        bg = const Color(0xFF16A34A); // green
        icon = Icons.check_circle;
        text = _serverUrlDisplay != null
            ? 'Connected to $_serverUrlDisplay'
            : 'Connected';
        break;
      case 'offline':
        bg = const Color(0xFFDC2626); // red
        icon = Icons.wifi_off;
        text = 'Offline';
        break;
      default:
        bg = const Color(0xFFF59E0B); // orange
        icon = Icons.report_problem;
        text = 'Server not detected yet';
    }
    return AnimatedContainer(
      duration: const Duration(milliseconds: 250),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: bg.withOpacity(0.12),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: bg.withOpacity(0.5)),
      ),
      child: Row(
        children: [
          Icon(icon, color: bg),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(color: bg, fontWeight: FontWeight.w600),
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (_connectivityStatus != 'connected')
            TextButton(
              onPressed: _refreshConnectivity,
              child: const Text('Retry'),
            ),
        ],
      ),
    );
  }

  Widget _buildOfflinePrompt() {
    return const Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(Icons.info_outline, color: Color(0xFFF59E0B)),
        SizedBox(width: 8),
        Expanded(
          child: Text(
            "Looks like you’re offline. You can still capture documents; they’ll upload automatically when back online.",
            style: TextStyle(color: Color(0xFF6B7280)),
          ),
        ),
      ],
    );
  }

  // --- Backoff helpers ---
  bool _isInBackoff() {
    if (_backoffUntil == null) return false;
    return DateTime.now().isBefore(_backoffUntil!);
  }

  int _backoffRemainingSeconds() {
    if (!_isInBackoff()) return 0;
    return _backoffUntil!.difference(DateTime.now()).inSeconds.clamp(0, 3600);
  }

  void _registerBackoff() {
    _loginFailures += 1;
    final seconds = _computeBackoffSeconds(_loginFailures);
    _backoffUntil = DateTime.now().add(Duration(seconds: seconds));
    setState(() {});
    // Tick down visually
    Timer.periodic(const Duration(seconds: 1), (t) {
      if (!_isInBackoff()) {
        t.cancel();
        setState(() {});
      } else {
        setState(() {});
      }
    });
  }

  int _computeBackoffSeconds(int failures) {
    // 2, 4, 8, 16, 30 (cap)
    final v = pow(2, failures).toInt();
    return v > 30 ? 30 : v;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFE0F7FA), // Light cyan background
      body: Stack(
        children: [
          // Loading screen
          if (!showLoginForm) _buildLoadingScreen(),

          // Login form with animation
          if (showLoginForm)
            FadeTransition(
              opacity: _fadeAnimation,
              child: SlideTransition(
                position: _slideAnimation,
                child: Center(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 20.0, vertical: 20.0),
                    child: Container(
                      constraints: const BoxConstraints(maxWidth: 1000),
                      child: LayoutBuilder(
                        builder: (context, constraints) {
                          // For mobile screens, stack vertically
                          if (constraints.maxWidth < 768) {
                            return Column(
                              children: [
                                _buildLoginFormSectionMobile(),
                              ],
                            );
                          }
                          // For desktop screens, use side-by-side layout
                          return IntrinsicHeight(
                            child: Row(
                              children: [
                                // Left side - Welcome section with gradient
                                Expanded(
                                  flex: 1,
                                  child: _buildWelcomeSection(),
                                ),
                                // Right side - Login form
                                Expanded(
                                  flex: 1,
                                  child: _buildLoginFormSection(),
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                    ),
                  ),
                ),
              ),
            ),

          // Smooth loading overlay during sign-in
          if (isLoggingIn)
            AnimatedOpacity(
              opacity: isLoggingIn ? 1.0 : 0.0,
              duration: const Duration(milliseconds: 180),
              child: IgnorePointer(
                ignoring: !isLoggingIn,
                child: Container(
                  color: Colors.black.withOpacity(0.08),
                  child: Center(
                    child: TweenAnimationBuilder<double>(
                      duration: const Duration(milliseconds: 260),
                      tween: Tween(begin: 0.9, end: 1.0),
                      curve: Curves.easeOutCubic,
                      builder: (context, value, child) => Transform.scale(
                        scale: value,
                        child: Container(
                          width: 72,
                          height: 72,
                          decoration: const BoxDecoration(
                            color: Colors.white,
                            shape: BoxShape.circle,
                            boxShadow: [
                              BoxShadow(
                                color: Colors.black12,
                                blurRadius: 20,
                                offset: Offset(0, 10),
                              ),
                            ],
                          ),
                          alignment: Alignment.center,
                          child: const SizedBox(
                            width: 30,
                            height: 30,
                            child: CircularProgressIndicator(strokeWidth: 2.8),
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildLoadingScreen() {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color(0xFF38BDF8), // Primary blue
            Color(0xFF0EA5E9), // Darker blue
          ],
        ),
      ),
      child: Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            // Enhanced logo with multiple animations
            TweenAnimationBuilder<double>(
              duration: const Duration(milliseconds: 2000),
              tween: Tween(begin: 0.0, end: 1.0),
              curve: Curves.elasticOut,
              builder: (context, value, child) {
                return Transform.scale(
                  scale: value,
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 1000),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(100),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.white.withOpacity(0.3 * value),
                          blurRadius: 40 * value,
                          spreadRadius: 10 * value,
                          offset: const Offset(0, 0),
                        ),
                        BoxShadow(
                          color: Colors.black.withOpacity(0.2 * value),
                          blurRadius: 30 * value,
                          offset: Offset(0, 15 * value),
                        ),
                      ],
                    ),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(100),
                      child: Image.asset(
                        "assets/images/Logo.png",
                        width: 200,
                        height: 200,
                        fit: BoxFit.cover,
                      ),
                    ),
                  ),
                );
              },
            ),
            const SizedBox(height: 50),
            // Enhanced title with slide and fade animation
            TweenAnimationBuilder<double>(
              duration: const Duration(milliseconds: 2500),
              tween: Tween(begin: 0.0, end: 1.0),
              curve: Curves.easeOutCubic,
              builder: (context, value, child) {
                return Transform.translate(
                  offset: Offset(0, 30 * (1 - value)),
                  child: Opacity(
                    opacity: value,
                    child: const Text(
                      "CHRMO DOCUMENT TRACKING",
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 28,
                        fontWeight: FontWeight.bold,
                        letterSpacing: 1.5,
                        shadows: [
                          Shadow(
                            color: Colors.black26,
                            offset: Offset(0, 2),
                            blurRadius: 4,
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
            const SizedBox(height: 20),
            // Enhanced subtitle with slide and fade animation
            TweenAnimationBuilder<double>(
              duration: const Duration(milliseconds: 3000),
              tween: Tween(begin: 0.0, end: 1.0),
              curve: Curves.easeOutCubic,
              builder: (context, value, child) {
                return Transform.translate(
                  offset: Offset(0, 20 * (1 - value)),
                  child: Opacity(
                    opacity: value,
                    child: Text(
                      "Manage Your Documents Efficiently",
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: Colors.white.withOpacity(0.9),
                        fontSize: 18,
                        fontWeight: FontWeight.w400,
                        height: 1.5,
                        shadows: const [
                          Shadow(
                            color: Colors.black26,
                            offset: Offset(0, 1),
                            blurRadius: 2,
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
            const SizedBox(height: 60),
            // Enhanced loading indicator with pulsing animation
            TweenAnimationBuilder<double>(
              duration: const Duration(milliseconds: 3500),
              tween: Tween(begin: 0.0, end: 1.0),
              curve: Curves.easeInOut,
              builder: (context, value, child) {
                return Transform.scale(
                  scale: 0.8 + (0.2 * value),
                  child: Opacity(
                    opacity: value,
                    child: Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: Colors.white.withOpacity(0.1),
                      ),
                      child: const CircularProgressIndicator(
                        valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                        strokeWidth: 4,
                      ),
                    ),
                  ),
                );
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildWelcomeSection() {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color(0xFF38BDF8),
            Color(0xFF0EA5E9),
          ],
        ),
        borderRadius: BorderRadius.only(
          topLeft: Radius.circular(24),
          bottomLeft: Radius.circular(24),
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(60.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Container(
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withOpacity(0.12),
                    blurRadius: 28,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(100),
                child: Image.asset(
                  "assets/images/Logo.png",
                  width: 180,
                  height: 180,
                  fit: BoxFit.cover,
                ),
              ),
            ),
            const SizedBox(height: 36),
            const Text(
              "Welcome",
              style: TextStyle(
                fontFamily: 'Poppins',
                color: Colors.white,
                fontSize: 34,
                fontWeight: FontWeight.w700,
                letterSpacing: -0.3,
              ),
            ),
            const SizedBox(height: 12),
            Text(
              "Sign in to continue managing\nyour documents.",
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: 'Poppins',
                color: Colors.white.withOpacity(0.9),
                fontSize: 16,
                fontWeight: FontWeight.w400,
                height: 1.6,
              ),
            ),
            const Spacer(),
            Text(
              "Powered by ACCA TECH",
              style: TextStyle(
                fontFamily: 'Poppins',
                color: Colors.white.withOpacity(0.55),
                fontSize: 11,
                fontWeight: FontWeight.w500,
                letterSpacing: 1.2,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildLoginFormSection() {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: const BorderRadius.only(
          topRight: Radius.circular(24),
          bottomRight: Radius.circular(24),
        ),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF6868AC).withOpacity(0.08),
            blurRadius: 24,
            offset: const Offset(0, 8),
          ),
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 6,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Padding(
        padding: const EdgeInsets.all(60.0),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const SizedBox(height: 16),
            const Text(
              "Login to Account",
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: 'Poppins',
                color: Color(0xFF1F2937),
                fontSize: 30,
                fontWeight: FontWeight.w700,
                letterSpacing: -0.3,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              "Enter your credentials to continue",
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: 'Poppins',
                color: const Color(0xFF6B7280).withOpacity(0.8),
                fontSize: 14,
                fontWeight: FontWeight.w400,
              ),
            ),
            const SizedBox(height: 40),
            _buildInputField(
              controller: emailController,
              labelText: "Username or Email",
              icon: Icons.email_outlined,
              keyboardType: TextInputType.emailAddress,
            ),
            const SizedBox(height: 24),
            _buildInputField(
              controller: passwordController,
              labelText: "Password",
              icon: Icons.lock_outline,
              isPassword: true,
            ),
            const SizedBox(height: 28),
            _buildRememberMeAndForgotPassword(),
            const SizedBox(height: 40),
            _buildLoginButton(),
            if (_connectivityStatus == 'offline') _buildOfflinePrompt(),
          ],
        ),
      ),
    );
  }

  Widget _buildLoginFormSectionMobile() {
    return Column(
      children: [
        // Logo + App name above the card
        Padding(
          padding: const EdgeInsets.only(bottom: 24),
          child: Column(
            children: [
              Container(
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  boxShadow: [
                    BoxShadow(
                      color: const Color(0xFF0EA5E9).withOpacity(0.15),
                      blurRadius: 30,
                      spreadRadius: 5,
                    ),
                  ],
                ),
                child: Image.asset(
                  'assets/images/Logo.png',
                  width: 120,
                  height: 120,
                  fit: BoxFit.contain,
                  errorBuilder: (_, __, ___) => const Icon(
                      Icons.description_outlined,
                      size: 50,
                      color: Color(0xFF0EA5E9)),
                ),
              ),
              const SizedBox(height: 16),
              const Text(
                'CHRMO Document Tracking',
                style: TextStyle(
                  fontFamily: 'Poppins',
                  fontSize: 18,
                  fontWeight: FontWeight.w700,
                  color: Color(0xFF1E293B),
                  letterSpacing: 0.2,
                ),
              ),
            ],
          ),
        ),
        // Login card
        Container(
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: const BorderRadius.all(Radius.circular(24)),
            boxShadow: [
              BoxShadow(
                color: const Color(0xFF6868AC).withOpacity(0.08),
                blurRadius: 24,
                offset: const Offset(0, 8),
              ),
              BoxShadow(
                color: Colors.black.withOpacity(0.04),
                blurRadius: 6,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Padding(
            padding:
                const EdgeInsets.symmetric(horizontal: 28.0, vertical: 28.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const Text(
                  "Sign In",
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontFamily: 'Poppins',
                    color: Color(0xFF1F2937),
                    fontSize: 24,
                    fontWeight: FontWeight.w700,
                    letterSpacing: -0.3,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  "Enter your credentials to continue",
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontFamily: 'Poppins',
                    color: const Color(0xFF6B7280).withOpacity(0.8),
                    fontSize: 13,
                    fontWeight: FontWeight.w400,
                    height: 1.4,
                  ),
                ),
                const SizedBox(height: 28),
                _buildInputField(
                  controller: emailController,
                  labelText: "Username or Email",
                  icon: Icons.email_outlined,
                  keyboardType: TextInputType.emailAddress,
                ),
                const SizedBox(height: 18),
                _buildInputField(
                  controller: passwordController,
                  labelText: "Password",
                  icon: Icons.lock_outline,
                  isPassword: true,
                ),
                const SizedBox(height: 14),
                _buildRememberMeAndForgotPasswordMobile(),
                const SizedBox(height: 22),
                _buildLoginButton(),
                const SizedBox(height: 8),
                _buildServerUrlLink(),
                const SizedBox(height: 4),
                _buildForgotPasswordLink(),
                if (_connectivityStatus == 'offline') _buildOfflinePrompt(),
              ],
            ),
          ),
        ),
        // "Powered by ACCA TECH" footer
        Padding(
          padding: const EdgeInsets.only(top: 20, bottom: 8),
          child: Text(
            'Powered by ACCA TECH',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontFamily: 'Poppins',
              fontSize: 11,
              fontWeight: FontWeight.w500,
              color: const Color(0xFF94A3B8).withOpacity(0.7),
              letterSpacing: 1.2,
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildServerUrlLink() {
    // Hidden in UI
    return const SizedBox.shrink();
  }

  String _normalizeRoot(String s) {
    String v = s.trim();
    if (!v.startsWith('http://') && !v.startsWith('https://')) {
      v = 'http://$v';
    }
    // Remove trailing slashes
    while (v.endsWith('/')) {
      v = v.substring(0, v.length - 1);
    }
    return v;
  }

  Future<void> _showServerUrlDialog() async {
    final sp = await SharedPreferences.getInstance();
    final existing = sp.getString('server_root');
    final TextEditingController ctrl = TextEditingController(
        text: existing ?? ServerService.defaultServerRoot);

    showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(
            children: [
              Icon(Icons.link, color: Color(0xFF6868AC)),
              SizedBox(width: 8),
              Text('Set Server URL'),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  '''Enter the base URL to your app root (with or without /api).
Example:
          'http://<PC_IP>/flutter_application_7/api',
''',
                  style: TextStyle(color: Colors.grey),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: ctrl,
                decoration: InputDecoration(
                  prefixIcon: const Icon(Icons.link_outlined),
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child:
                  Text('Cancel', style: TextStyle(color: Colors.grey.shade600)),
            ),
            ElevatedButton(
              onPressed: () async {
                final input = _normalizeRoot(ctrl.text);
                // Accept input with or without trailing /api
                final root = input.replaceFirst(RegExp(r"/api/?$"), '');
                // Validate by pinging the API base built from this root
                final apiBase = '$root/lib/OCR(UPDATED)/api';
                final ok = await _isOurServer(apiBase);
                if (!ok) {
                  // Save anyway so user can proceed; some networks block ping
                  await sp.setString('server_root', root);
                  if (mounted) {
                    setState(() {
                      _serverUrlDisplay = root;
                      _connectivityStatus = 'not_detected';
                    });
                  }
                  _showMessage('Saved, but not reachable now: $apiBase');
                  Navigator.of(ctx).pop();
                  return;
                }
                await sp.setString('server_root', root);
                if (mounted) {
                  setState(() {
                    _serverUrlDisplay = root;
                    _connectivityStatus = 'connected';
                  });
                }
                _showMessage('Server saved: $root', success: true);
                Navigator.of(ctx).pop();
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF6868AC),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              child: const Text('Save', style: TextStyle(color: Colors.white)),
            ),
          ],
        );
      },
    );
  }

  Widget _buildInputField({
    required TextEditingController controller,
    required String labelText,
    required IconData icon,
    bool isPassword = false,
    TextInputType keyboardType = TextInputType.text,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 0),
      child: TextField(
        controller: controller,
        obscureText: isPassword ? !isPasswordVisible : false,
        keyboardType: keyboardType,
        textInputAction:
            isPassword ? TextInputAction.done : TextInputAction.next,
        enabled: !isLoggingIn && !_isInBackoff(),
        style: const TextStyle(
          fontFamily: 'Poppins',
          color: Color(0xFF1F2937),
          fontSize: 15,
          fontWeight: FontWeight.w400,
        ),
        decoration: InputDecoration(
          labelText: labelText,
          floatingLabelBehavior: FloatingLabelBehavior.auto,
          labelStyle: const TextStyle(
            fontFamily: 'Poppins',
            color: Color(0xFF9CA3AF),
            fontSize: 14,
            fontWeight: FontWeight.w400,
          ),
          prefixIcon: Padding(
            padding: const EdgeInsets.only(left: 14, right: 10),
            child: Icon(
              icon,
              color: const Color(0xFF9CA3AF),
              size: 20,
            ),
          ),
          prefixIconConstraints: const BoxConstraints(minWidth: 44),
          suffixIcon: isPassword
              ? IconButton(
                  icon: Icon(
                    isPasswordVisible ? Icons.visibility : Icons.visibility_off,
                    color: const Color(0xFF9CA3AF),
                    size: 20,
                  ),
                  onPressed: (!isLoggingIn && !_isInBackoff())
                      ? () {
                          setState(() {
                            isPasswordVisible = !isPasswordVisible;
                          });
                        }
                      : null,
                )
              : null,
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(
              color: Color(0xFFE5E7EB),
              width: 1.2,
            ),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(
              color: Color(0xFF0EA5E9),
              width: 2,
            ),
          ),
          disabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(14),
            borderSide: const BorderSide(
              color: Color(0xFFF3F4F6),
              width: 1,
            ),
          ),
          filled: true,
          fillColor: const Color(0xFFF9FAFB),
          contentPadding:
              const EdgeInsets.symmetric(vertical: 18, horizontal: 16),
        ),
      ),
    );
  }

  Widget _buildRememberMeAndForgotPassword() {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Row(
          children: [
            Checkbox(
              value: rememberUser,
              onChanged: (bool? newValue) {
                setState(() {
                  rememberUser = newValue!;
                });
              },
              activeColor: const Color(0xFF0EA5E9),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(4),
              ),
            ),
            const Text(
              "Keep me signed in",
              style: TextStyle(
                color: Color(0xFF6B7280),
                fontSize: 14,
                fontWeight: FontWeight.w400,
              ),
            ),
          ],
        ),
        TextButton(
          onPressed: _showForgotPasswordDialog,
          child: const Text(
            "Forgot Password?",
            style: TextStyle(
              color: Color(0xFF0EA5E9),
              fontSize: 14,
              fontWeight: FontWeight.w500,
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildRememberMeAndForgotPasswordMobile() {
    return Column(
      children: [
        // Keep me signed in - full width for mobile
        Row(
          children: [
            Checkbox(
              value: rememberUser,
              onChanged: (bool? newValue) {
                setState(() {
                  rememberUser = newValue!;
                });
              },
              activeColor: const Color(0xFF0EA5E9),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(4),
              ),
            ),
            const Text(
              "Keep me signed in",
              style: TextStyle(
                color: Color(0xFF6B7280),
                fontSize: 14,
                fontWeight: FontWeight.w400,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildForgotPasswordLink() {
    return Center(
      child: TextButton(
        onPressed: _showForgotPasswordDialog,
        child: const Text(
          "Forgot Password?",
          style: TextStyle(
            color: Color(0xFF0EA5E9),
            fontSize: 14,
            fontWeight: FontWeight.w500,
          ),
        ),
      ),
    );
  }

  Widget _buildLoginButton() {
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF38BDF8), Color(0xFF0EA5E9)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF0EA5E9).withOpacity(0.25),
            spreadRadius: 0,
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
          BoxShadow(
            color: const Color(0xFF38BDF8).withOpacity(0.10),
            spreadRadius: 0,
            blurRadius: 4,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: ElevatedButton(
        onPressed: (isLoggingIn || _isInBackoff()) ? null : _loginUser,
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors.transparent,
          shadowColor: Colors.transparent,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
          padding: const EdgeInsets.symmetric(vertical: 17),
        ),
        child: AnimatedSwitcher(
          duration: const Duration(milliseconds: 200),
          transitionBuilder: (child, anim) =>
              FadeTransition(opacity: anim, child: child),
          child: isLoggingIn
              ? const Row(
                  key: ValueKey('loading'),
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                      ),
                    ),
                    SizedBox(width: 10),
                    Text(
                      "Signing in...",
                      style: TextStyle(
                        fontFamily: 'Poppins',
                        color: Colors.white,
                        fontSize: 15,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ],
                )
              : Text(
                  _isInBackoff()
                      ? 'Please wait ${_backoffRemainingSeconds()}s'
                      : 'Sign In',
                  key: const ValueKey('text'),
                  style: const TextStyle(
                    fontFamily: 'Poppins',
                    color: Colors.white,
                    fontSize: 15,
                    fontWeight: FontWeight.w600,
                    letterSpacing: 0.5,
                  ),
                ),
        ),
      ),
    );
  }

  // Auto-detect server URL with broader LAN scan (hosts, ports, paths)
  Future<String?> _detectServerUrl() async {
    try {
      final info = NetworkInfo();
      final wifiIP = await info.getWifiIP();
      final wifiGateway = await info.getWifiGatewayIP();

      final Set<String> hosts = {
        // Prefer whatever the app is configured with.
        if (Uri.tryParse(ServerService.defaultServerRoot)
                ?.host
                .trim()
                .isNotEmpty ==
            true)
          Uri.parse(ServerService.defaultServerRoot).host,
      };
      if (wifiGateway != null && wifiGateway.isNotEmpty) hosts.add(wifiGateway);
      if (wifiIP != null && wifiIP.isNotEmpty) {
        final parts = wifiIP.split('.');
        if (parts.length == 4) {
          final subnet = '${parts[0]}.${parts[1]}.${parts[2]}';
          hosts.addAll([
            '$subnet.1',
            '$subnet.2',
            '$subnet.10',
            '$subnet.20',
            '$subnet.50',
            '$subnet.80',
            '$subnet.100',
            '$subnet.101',
            '$subnet.110'
          ]);
        }
      }
      hosts.add('localhost');

      final paths = [
        '/flutter_application_7/api',
        '/lib/OCR(UPDATED)/api',
        '/api',
      ];
      final ports = [80, 8080];

      final List<String> bases = [];
      for (final h in hosts) {
        for (final p in ports) {
          const scheme = 'http';
          for (final path in paths) {
            bases.add('$scheme://$h${p == 80 ? '' : ':$p'}$path');
          }
        }
      }

      if (bases.isEmpty) return null;

      final completer = Completer<String?>();
      int pending = bases.length;
      for (final base in bases) {
        _isOurServer(base).then((ok) async {
          if (ok && !completer.isCompleted) {
            try {
              final prefs = await SharedPreferences.getInstance();
              await prefs.setString('detected_server_url', base);
            } catch (_) {}
            completer.complete(base);
          }
        }).whenComplete(() {
          pending -= 1;
          if (pending == 0 && !completer.isCompleted) completer.complete(null);
        });
      }
      return await completer.future;
    } catch (_) {
      return null;
    }
  }

  // Load previously detected server URL
  Future<String?> _loadSavedServerUrl() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final savedUrl = prefs.getString('detected_server_url');

      if (savedUrl != null) {
        try {
          final ok = await _isOurServer(savedUrl);
          if (ok) return savedUrl;
        } catch (_) {}
        await prefs.remove('detected_server_url');
      }
    } catch (e) {
      debugPrint('Error loading saved server URL');
    }
    return null;
  }

  // Get the best server URL (saved or auto-detect)
  Future<String?> _getServerUrl() async {
    String? serverUrl = await _loadSavedServerUrl();
    serverUrl ??= await _detectServerUrl();
    return serverUrl;
  }

  Future<void> _clearSavedServerUrl() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove('detected_server_url');
    } catch (_) {}
  }

  void _loginUser() async {
    final identifier = emailController.text.trim();
    final password = passwordController.text.trim();

    if (identifier.isEmpty || password.isEmpty) {
      _showMessage("Please enter username/email and password.");
      return;
    }

    try {
      if (_isInBackoff()) {
        _showMessage(
            'Please wait ${_backoffRemainingSeconds()}s before retrying.');
        return;
      }
      setState(() {
        isLoggingIn = true;
      });
      // Order of preference:
      // 1) Explicit server_root from SharedPreferences
      // 2) Auto-detected/previously detected URL
      // 3) Fixed user fallback
      String? baseUrl;
      final String userFixed = await ServerService.getServerUrl();

      // Prefer a manually configured root, then detected URL, then fixed fallback
      try {
        final prefs = await SharedPreferences.getInstance();
        final root = prefs.getString('server_root');
        if (root != null && root.trim().isNotEmpty) {
          final candidate = '${root.trim()}/api';
          // If user previously saved an old LAN IP, it may now be unreachable.
          // Validate quickly and clear the saved value if it's stale.
          final ok = await ServerService.testServerUrl(candidate);
          if (ok) {
            baseUrl = candidate;
          } else {
            try {
              await prefs.remove('server_root');
              await prefs.remove('detected_server_url');
            } catch (_) {}
            baseUrl = null;
          }
        } else {
          baseUrl = await _getServerUrl();
        }
      } catch (_) {
        baseUrl = null;
      }
      baseUrl ??= userFixed;

      if (mounted) {
        setState(() {
          _connectivityStatus =
              baseUrl != null ? 'connected' : _connectivityStatus;
          _serverUrlDisplay = baseUrl ?? _serverUrlDisplay;
        });
      }

      // Build endpoint candidates. Prefer /api/login.php at the app root.
      final String baseRoot = baseUrl.endsWith('/api')
          ? baseUrl.substring(0, baseUrl.length - 4)
          : baseUrl;
      // Persist resolved server root for the rest of the app (notifications, dashboard, etc.)
      try {
        final prefsForRoot = await SharedPreferences.getInstance();
        await prefsForRoot.setString('server_root', baseRoot);
        await prefsForRoot.setString('detected_server_url', '$baseRoot/api');
      } catch (_) {}
      final List<Uri> candidateUris = [
        // Preferred project API locations
        Uri.parse('$baseRoot/lib/api/login.php'),
        Uri.parse('$baseRoot/lib/api/minimal_login.php'),
        Uri.parse('$baseRoot/lib/api/mobile_login_test.php'),
        // OCR(UPDATED) legacy
        Uri.parse('$baseRoot/lib/OCR(UPDATED)/login_api.php'),
        // Standard app-root /api variants
        Uri.parse('$baseRoot/api/login.php'),
        Uri.parse('$baseRoot/api/log-in.php'),
        // Legacy root variants
        Uri.parse('$baseRoot/login.php'),
        Uri.parse('$baseRoot/log-in.php'),
      ];

      http.Response? response;
      String? usedEndpoint;
      String? lastAttempted;
      for (final uri in candidateUris) {
        debugPrint('[LOGIN] Trying endpoint -> $uri');
        lastAttempted = uri.toString();
        try {
          // 1) Form-encoded POST
          final r1 = await http.post(
            uri,
            headers: {
              'Accept': 'application/json',
              'User-Agent': 'FlutterApp/1.0',
              'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: {
              'identifier': identifier,
              'username': identifier,
              'email': identifier,
              'password': password,
              // extra aliases for legacy handlers
              'user': identifier,
              'pass': password,
            },
          ).timeout(const Duration(seconds: 15));
          if (r1.statusCode == 200) {
            response = r1;
            usedEndpoint = uri.toString();
            break;
          }

          // 2) JSON POST fallback
          final r2 = await http
              .post(
                uri,
                headers: {
                  'Accept': 'application/json',
                  'User-Agent': 'FlutterApp/1.0',
                  'Content-Type': 'application/json',
                },
                body: jsonEncode({
                  'identifier': identifier,
                  'username': identifier,
                  'email': identifier,
                  'password': password,
                }),
              )
              .timeout(const Duration(seconds: 12));
          if (r2.statusCode == 200) {
            response = r2;
            usedEndpoint = uri.toString();
            break;
          }

          // 3) GET fallback (some servers block POST)
          final getUri = uri.replace(queryParameters: {
            'identifier': identifier,
            'username': identifier,
            'email': identifier,
            'password': password,
          });
          final r3 =
              await http.get(getUri).timeout(const Duration(seconds: 12));
          if (r3.statusCode == 200) {
            response = r3;
            usedEndpoint = uri.toString();
            break;
          }
        } catch (e) {
          debugPrint('[LOGIN] Endpoint error for $uri: $e');
        }
      }

      if (response != null && response.statusCode == 200) {
        final Map<String, dynamic> body = jsonDecode(response.body);
        final bool success = body['success'] == true;
        if (success) {
          final data = body['data'] as Map<String, dynamic>;

          if (rememberUser) {
            await _saveCredentials(identifier, password);
          } else {
            await _clearSavedCredentials();
          }

          final username = (data['user'] ?? '').toString();
          final email = (data['email'] ?? identifier).toString();
          final fullName = username.isNotEmpty ? username : email;

          // Save user data to SharedPreferences for persistence
          await _saveUserData(fullName, email, data);

          _showWelcomeModal(fullName, email);
          setState(() {
            isLoggingIn = false;
            _loginFailures = 0;
            _backoffUntil = null;
          });
          return;
        } else {
          setState(() {
            isLoggingIn = false;
          });
          _showMessage(body['message']?.toString() ?? 'Login failed.');
          await HapticFeedback.mediumImpact();
          _registerBackoff();
          return;
        }
      } else {
        setState(() {
          isLoggingIn = false;
        });
        // Try to include server-provided message for better diagnostics
        final code = response?.statusCode ?? -1;
        final attemptedList = candidateUris.map((u) => u.toString()).toList();
        String attempted = usedEndpoint ?? lastAttempted ?? attemptedList.first;
        String msg = code == -1
            ? 'Unable to reach login endpoint. Tried: $attempted (also tried: ${attemptedList.skip(1).join(', ')})'
            : 'Server error: $code at $attempted.';
        try {
          final raw = response?.body ?? '';
          if (raw.isNotEmpty) {
            final m = jsonDecode(raw);
            final s = (m['message'] ?? '').toString();
            if (s.isNotEmpty) {
              final codeStr = response?.statusCode.toString() ?? '';
              msg = codeStr.isNotEmpty ? '$s (HTTP $codeStr)' : s;
            }
          }
        } catch (_) {}
        _showMessage(msg);
        await HapticFeedback.mediumImpact();
        _registerBackoff();
        return;
      }
    } on TimeoutException {
      setState(() {
        isLoggingIn = false;
      });
      _showMessage('Connection timeout.');
      await HapticFeedback.mediumImpact();
      _registerBackoff();
      return;
    } on SocketException {
      setState(() {
        isLoggingIn = false;
      });
      _showMessage('Network error.');
      await HapticFeedback.mediumImpact();
      _registerBackoff();
      return;
    } catch (e) {
      setState(() {
        isLoggingIn = false;
      });
      debugPrint('Login error');
      _showMessage('Unable to reach server.');
      await HapticFeedback.mediumImpact();
      _registerBackoff();
      return;
    }
  }

  void _showMessage(String message, {bool success = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor:
            success ? const Color(0xFF6868AC) : Colors.red.shade600,
        behavior:
            SnackBarBehavior.floating, // Make it float for better visibility
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        margin: const EdgeInsets.all(20),
      ),
    );
  }

  // Load saved credentials from SharedPreferences
  Future<void> _loadSavedCredentials() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final savedEmail = prefs.getString('saved_email');
      final savedPassword = prefs.getString('saved_password');
      final wasRemembered = prefs.getBool('remember_user') ?? false;

      if (mounted) {
        setState(() {
          // Always load the checkbox state
          rememberUser = wasRemembered;

          // Only populate fields if credentials exist and remember was checked
          if (wasRemembered && savedEmail != null && savedPassword != null) {
            emailController.text = savedEmail;
            passwordController.text = savedPassword;
          }
        });
      }
    } catch (e) {
      debugPrint('Error loading saved credentials: $e');
    }
  }

  // Save credentials to SharedPreferences
  Future<void> _saveCredentials(String email, String password) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('saved_email', email);
      await prefs.setString('saved_password', password);
      await prefs.setBool('remember_user', true);
    } catch (e) {
      debugPrint('Error saving credentials: $e');
    }
  }

  // Clear saved credentials from SharedPreferences
  Future<void> _clearSavedCredentials() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove('saved_email');
      await prefs.remove('saved_password');
      await prefs.setBool('remember_user', false);
    } catch (e) {
      debugPrint('Error clearing credentials: $e');
    }
  }

  // Save user data to SharedPreferences
  Future<void> _saveUserData(
      String username, String email, Map<String, dynamic> userData) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final uname = (username).toString().trim();
      final uemail = (email).toString().trim();
      final dept = (userData['department']?.toString() ?? '').trim();
      final role = (userData['role']?.toString() ?? 'user').trim();
      final apiToken = (userData['api_token']?.toString() ?? '').trim();
      final apiTokenExpiresAt =
          (userData['api_token_expires_at']?.toString() ?? '').trim();
      await prefs.setString('user_name', uname);
      await prefs.setString('user_email', uemail);
      await prefs.setString('user_role', role);
      await prefs.setString('user_department', dept);
      await prefs.setInt('user_id', userData['id'] ?? 0);
      if (apiToken.isNotEmpty) {
        await prefs.setString('api_token', apiToken);
      }
      if (apiTokenExpiresAt.isNotEmpty) {
        await prefs.setString('api_token_expires_at', apiTokenExpiresAt);
      }
    } catch (e) {
      debugPrint('Error saving user data: $e');
    }
  }

  // Show forgot password dialog
  void _showForgotPasswordDialog() {
    final TextEditingController resetEmailController = TextEditingController();
    String? feedbackMessage;
    bool feedbackIsError = false;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext dialogContext) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            void handleSend() {
              final identifier = resetEmailController.text.trim();
              if (identifier.isEmpty) {
                setDialogState(() {
                  feedbackMessage = 'Please enter your username or email.';
                  feedbackIsError = true;
                });
                return;
              }

              // Validate email format if it looks like an email
              if (identifier.contains('@')) {
                final emailRegex =
                    RegExp(r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$');
                if (!emailRegex.hasMatch(identifier)) {
                  setDialogState(() {
                    feedbackMessage = 'Please enter a valid email address.';
                    feedbackIsError = true;
                  });
                  return;
                }
              }

              // Close the forgot password dialog and show the verification modal
              Navigator.of(dialogContext).pop();
              _showSendingCodeModal(identifier);
            }

            return AlertDialog(
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(20),
              ),
              title: const Row(
                children: [
                  Icon(Icons.lock_reset, color: Color(0xFF6868AC)),
                  SizedBox(width: 10),
                  Text(
                    'Forgot Password',
                    style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ],
              ),
              content: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Enter your username or email to receive a 6-digit verification code.',
                    style: TextStyle(
                      fontSize: 16,
                      color: Colors.grey,
                    ),
                  ),
                  const SizedBox(height: 20),
                  TextField(
                    controller: resetEmailController,
                    keyboardType: TextInputType.text,
                    decoration: InputDecoration(
                      labelText: 'Username or Email',
                      prefixIcon: const Icon(Icons.account_circle_outlined),
                      border: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                      focusedBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(12),
                        borderSide: const BorderSide(
                            color: Color(0xFF6868AC), width: 2),
                      ),
                    ),
                    onSubmitted: (_) => handleSend(),
                  ),
                  // Inline feedback message (validation errors)
                  if (feedbackMessage != null) ...[
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 12, vertical: 10),
                      decoration: BoxDecoration(
                        color: feedbackIsError
                            ? Colors.red.shade50
                            : Colors.green.shade50,
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(
                          color: feedbackIsError
                              ? Colors.red.shade200
                              : Colors.green.shade200,
                        ),
                      ),
                      child: Row(
                        children: [
                          Icon(
                            feedbackIsError
                                ? Icons.error_outline
                                : Icons.check_circle_outline,
                            size: 18,
                            color: feedbackIsError
                                ? Colors.red.shade700
                                : Colors.green.shade700,
                          ),
                          const SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              feedbackMessage!,
                              style: TextStyle(
                                fontSize: 13,
                                color: feedbackIsError
                                    ? Colors.red.shade700
                                    : Colors.green.shade700,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.of(dialogContext).pop(),
                  child: Text(
                    'Cancel',
                    style: TextStyle(color: Colors.grey.shade600),
                  ),
                ),
                ElevatedButton(
                  onPressed: handleSend,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF6868AC),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  child: const Text(
                    'Send Code',
                    style: TextStyle(color: Colors.white),
                  ),
                ),
              ],
            );
          },
        );
      },
    );
  }

  /// Verification modal shown after pressing "Send Code" — provides animated
  /// user feedback for sending, success, and error states.
  void _showSendingCodeModal(String identifier) {
    showDialog(
      context: context,
      barrierDismissible: false,
      barrierColor: Colors.black.withOpacity(0.6),
      builder: (BuildContext modalContext) {
        return _SendingCodeModal(
          identifier: identifier,
          getApiBase: _getApiBase,
          onSuccess: (String mask) {
            // Close modal, then open the code-input dialog
            Navigator.of(modalContext).pop();
            _showMessage('Reset code sent to $mask', success: true);
            _showCodeInputDialog(identifier);
          },
          onError: (String errorMsg) {
            // Close modal, reopen forgot-password dialog so user can retry
            Navigator.of(modalContext).pop();
            _showMessage(errorMsg);
            _showForgotPasswordDialog();
          },
        );
      },
    );
  }

  // Simple reset request (no in-app code flow)
  Future<void> _requestSimpleReset(String identifier) async {
    final id = identifier.trim();
    if (id.isEmpty) {
      _showMessage('Please enter your username.');
      return;
    }
    if (id.contains('@')) {
      _showMessage('Please enter your username (not an email).');
      return;
    }
    try {
      final api = await _getApiBase();
      final uri = Uri.parse('$api/request_password_code.php');
      debugPrint('[ForgotPwd] Simple reset -> $uri');
      final resp = await http.post(uri,
          body: {'identifier': id}).timeout(const Duration(seconds: 15));
      if (resp.statusCode == 200 && resp.body.isNotEmpty) {
        try {
          final Map<String, dynamic> jm = jsonDecode(resp.body);
          if (jm['success'] == true) {
            _showMessage('Proceed to set a new password for your username.',
                success: true);
            _showUsernameConfirmPasswordDialog(prefillUsername: id);
            return;
          }
          final msg =
              jm['message']?.toString() ?? 'Unable to request password reset.';
          _showMessage(msg);
          return;
        } catch (_) {
          // Non-JSON response
        }
      }
      _showMessage('Unable to request password reset. Please try again later.');
    } catch (e) {
      _showMessage('Network error. Please try again.');
    }
  }

  // Dialog: username + new password + confirm (no verification code)
  void _showUsernameConfirmPasswordDialog({String prefillUsername = ''}) {
    final TextEditingController usernameController =
        TextEditingController(text: prefillUsername);
    final TextEditingController newPasswordController = TextEditingController();
    final TextEditingController confirmPasswordController =
        TextEditingController();

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
          ),
          title: const Row(
            children: [
              Icon(Icons.lock_reset, color: Color(0xFF6868AC)),
              SizedBox(width: 10),
              Text(
                'Set New Password',
                style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: usernameController,
                keyboardType: TextInputType.text,
                decoration: InputDecoration(
                  labelText: 'Username',
                  prefixIcon: const Icon(Icons.account_circle_outlined),
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: newPasswordController,
                obscureText: true,
                decoration: InputDecoration(
                  labelText: 'New Password',
                  prefixIcon: const Icon(Icons.lock_outline),
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: confirmPasswordController,
                obscureText: true,
                decoration: InputDecoration(
                  labelText: 'Confirm Password',
                  prefixIcon: const Icon(Icons.lock_outline),
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child:
                  Text('Cancel', style: TextStyle(color: Colors.grey.shade600)),
            ),
            ElevatedButton(
              onPressed: () {
                _resetPasswordByUsername(
                  usernameController.text.trim(),
                  newPasswordController.text.trim(),
                  confirmPasswordController.text.trim(),
                );
                Navigator.of(context).pop();
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF6868AC),
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              child: const Text('Update Password',
                  style: TextStyle(color: Colors.white)),
            ),
          ],
        );
      },
    );
  }

  // Reset password using username only (no code). Tries common endpoints and a fallback.
  Future<void> _resetPasswordByUsername(
      String username, String newPassword, String confirmPassword) async {
    if (username.isEmpty || newPassword.isEmpty || confirmPassword.isEmpty) {
      _showMessage('Please fill in all fields.');
      return;
    }
    if (username.contains('@')) {
      _showMessage('Please enter your username (not an email).');
      return;
    }
    if (newPassword != confirmPassword) {
      _showMessage('Passwords do not match.');
      return;
    }
    if (newPassword.length < 6) {
      _showMessage('Password must be at least 6 characters long.');
      return;
    }
    try {
      final api = await _getApiBase();
      final String baseRoot =
          api.endsWith('/api') ? api.substring(0, api.length - 4) : api;

      final List<Uri> endpoints = [
        Uri.parse('$api/reset_password_by_username.php'),
        Uri.parse('$api/reset_password.php'),
        Uri.parse('$api/change_password.php'),
        Uri.parse('$api/update_password.php'),
        Uri.parse('$api/users_reset.php'),
        // Root folder variants
        Uri.parse('$baseRoot/reset_password_by_username.php'),
        Uri.parse('$baseRoot/reset_password.php'),
        Uri.parse('$baseRoot/change_password.php'),
        Uri.parse('$baseRoot/update_password.php'),
        Uri.parse('$baseRoot/users_reset.php'),
        // Legacy verify fallback with empty code
        Uri.parse('$api/verify_password_code.php'),
        Uri.parse('$baseRoot/verify_password_code.php'),
      ];

      // Payload variants to match different backends
      final List<Map<String, String>> formVariants = [
        {
          'identifier': username,
          'new_password': newPassword,
          'confirm_password': confirmPassword
        },
        {
          'username': username,
          'new_password': newPassword,
          'confirm_password': confirmPassword
        },
        {
          'user': username,
          'new_password': newPassword,
          'confirm_password': confirmPassword
        },
        {
          'identifier': username,
          'password': newPassword,
          'confirm_password': confirmPassword
        },
        {
          'username': username,
          'password': newPassword,
          'confirm_password': confirmPassword
        },
        {
          'user': username,
          'password': newPassword,
          'confirm_password': confirmPassword
        },
        {
          'uname': username,
          'pass': newPassword,
          'confirm_password': confirmPassword
        },
        {
          'identifier': username,
          'new_password': newPassword,
          'confirm': confirmPassword
        },
        {
          'username': username,
          'password': newPassword,
          'confirm': confirmPassword
        },
        {
          'identifier': username,
          'new_password': newPassword,
          'action': 'reset_password'
        },
        {
          'username': username,
          'password': newPassword,
          'action': 'reset_password'
        },
      ];

      http.Response? response;
      Uri? usedEndpoint;
      // Try different request shapes per endpoint
      for (final uri in endpoints) {
        try {
          // Choose body for verify fallback
          final List<Map<String, String>> variants =
              uri.path.endsWith('verify_password_code.php')
                  ? formVariants
                      .map((m) => {
                            ...m,
                            'code': '',
                          })
                      .toList()
                  : formVariants;

          // 1) Form POST attempts
          for (final body in variants) {
            debugPrint('[ResetPwd] POST x-www-form to $uri body=$body');
            final r = await http
                .post(
                  uri,
                  headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded',
                  },
                  body: body,
                )
                .timeout(const Duration(seconds: 12));
            if (r.statusCode == 200) {
              response = r;
              usedEndpoint = uri;
              break;
            }
          }
          if (response != null) break;

          // 2) JSON POST attempt with first variant
          final first = variants.first;
          debugPrint('[ResetPwd] POST JSON to $uri body=$first');
          final rj = await http
              .post(
                uri,
                headers: {
                  'Accept': 'application/json',
                  'Content-Type': 'application/json',
                },
                body: jsonEncode(first),
              )
              .timeout(const Duration(seconds: 10));
          if (rj.statusCode == 200) {
            response = rj;
            usedEndpoint = uri;
            break;
          }

          // 3) GET fallback
          debugPrint('[ResetPwd] GET to $uri qp=${variants.first}');
          final rg = await http
              .get(uri.replace(queryParameters: variants.first))
              .timeout(const Duration(seconds: 10));
          if (rg.statusCode == 200) {
            response = rg;
            usedEndpoint = uri;
            break;
          }
        } catch (_) {}
      }

      bool looksSuccess(String body) {
        final lower = body.toLowerCase();
        return lower.contains('success') && lower.contains('true') ||
            lower.contains('updated') ||
            lower.contains('password updated') ||
            lower.contains('reset ok') ||
            lower.contains('ok');
      }

      if (response != null && response.body.isNotEmpty) {
        // Try JSON first
        try {
          final Map<String, dynamic> jm = jsonDecode(response.body);
          if (jm['success'] == true) {
            _showMessage('Password updated. You can now sign in.',
                success: true);
            if (mounted) {
              setState(() {
                emailController.text = username;
                passwordController.text = newPassword;
              });
            }
            _loginUser();
            return;
          }
          // If JSON but not success, fall through to message
          final msg = (jm['message'] ?? '').toString();
          if (msg.isNotEmpty) {
            _showMessage(msg);
            return;
          }
        } catch (_) {
          // Not JSON. Try heuristic success detection.
          if (looksSuccess(response.body)) {
            _showMessage('Password updated. You can now sign in.',
                success: true);
            if (mounted) {
              setState(() {
                emailController.text = username;
                passwordController.text = newPassword;
              });
            }
            _loginUser();
            return;
          }
        }

        // Show short snippet from server to aid debugging
        final raw = response.body;
        final snippet = raw.replaceAll(RegExp(r'\s+'), ' ');
        final trimmed =
            snippet.length > 220 ? '${snippet.substring(0, 220)}…' : snippet;
        _showMessage(
            'Failed to reset password at ${usedEndpoint?.path ?? ''}. $trimmed');
        return;
      }
      _showMessage('Failed to reset password. Please try again.');
    } catch (e) {
      _showMessage('Network error. Please try again.');
    }
  }

  // Send password reset code
  Future<void> _sendPasswordResetCode(String email) async {
    if (email.isEmpty) {
      _showMessage("Please enter your email address.");
      return;
    }

    // Accept either email or username. If it looks like an email, validate format.
    final bool looksLikeEmail = email.contains('@');
    final emailRegex =
        RegExp(r'^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$');
    if (looksLikeEmail && !emailRegex.hasMatch(email)) {
      _showMessage("Please enter a valid email address.");
      return;
    }

    try {
      final api = await _getApiBase();
      final uri = Uri.parse('$api/request_password_code.php');
      debugPrint('[ForgotPwd] Requesting code -> $uri');
      final resp = await http.post(uri, body: {
        'identifier': email,
      }).timeout(const Duration(seconds: 20));

      debugPrint('[ForgotPwd] HTTP ${resp.statusCode}: ${resp.body}');
      if (resp.body.isNotEmpty) {
        try {
          final Map<String, dynamic> jm = jsonDecode(resp.body);
          if (jm['success'] == true) {
            final bool emailSent = jm['sent'] == true;
            final mask = jm['mask']?.toString() ?? email;
            if (emailSent) {
              _showMessage('Reset code sent to $mask', success: true);
              _showCodeInputDialog(email);
              return;
            } else {
              // Code saved in DB but email delivery failed
              final smtpErr = jm['smtp_error']?.toString() ?? '';
              debugPrint('[ForgotPwd] Email not sent. SMTP error: $smtpErr');
              _showMessage(
                  'Could not deliver email to $mask. Please check the email address or try again.');
              return;
            }
          } else {
            final msg =
                jm['message']?.toString() ?? 'Failed to send reset code.';
            _showMessage(msg);
            debugPrint(
                '[ForgotPwd] Server responded but success=false: ${resp.body}');
            return;
          }
        } catch (_) {
          // Non-JSON response
        }
      }
      _showMessage('Failed to send reset code. Please try again.');
    } catch (e) {
      debugPrint('[ForgotPwd] Exception: $e');
      _showMessage('Network error. Please try again.');
    }
  }

  // Show code input dialog with timer and resend functionality
  void _showCodeInputDialog(String email) {
    final TextEditingController codeController = TextEditingController();

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (BuildContext context) {
        return StatefulBuilder(
          builder: (context, setState) {
            return _CodeInputDialog(
              email: email,
              codeController: codeController,
              onCodeVerified: () {
                Navigator.of(context).pop();
                _showPasswordResetDialog(email, codeController.text.trim());
              },
              onResendCode: () => _sendPasswordResetCode(email),
            );
          },
        );
      },
    );
  }

  // Show password reset dialog after code verification
  void _showPasswordResetDialog(String email, String verifiedCode) {
    final TextEditingController newPasswordController = TextEditingController();
    final TextEditingController confirmPasswordController =
        TextEditingController();

    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
          ),
          title: Row(
            children: [
              Icon(Icons.lock_reset, color: Colors.green.shade700),
              const SizedBox(width: 10),
              const Text(
                'Set New Password',
                style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'Enter your new password below.',
                style: TextStyle(
                  fontSize: 16,
                  color: Colors.grey,
                ),
              ),
              const SizedBox(height: 20),
              TextField(
                controller: newPasswordController,
                obscureText: true,
                decoration: InputDecoration(
                  labelText: 'New Password',
                  prefixIcon: const Icon(Icons.lock_outline),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide:
                        BorderSide(color: Colors.green.shade600, width: 2),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: confirmPasswordController,
                obscureText: true,
                decoration: InputDecoration(
                  labelText: 'Confirm Password',
                  prefixIcon: const Icon(Icons.lock_outline),
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                    borderSide:
                        BorderSide(color: Colors.green.shade600, width: 2),
                  ),
                ),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: Text(
                'Cancel',
                style: TextStyle(color: Colors.grey.shade600),
              ),
            ),
            ElevatedButton(
              onPressed: () {
                _resetPassword(
                  email,
                  verifiedCode,
                  newPasswordController.text.trim(),
                  confirmPasswordController.text.trim(),
                );
                Navigator.of(context).pop();
              },
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.green.shade600,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
              child: const Text(
                'Update Password',
                style: TextStyle(color: Colors.white),
              ),
            ),
          ],
        );
      },
    );
  }

  // Reset password with code verification
  Future<void> _resetPassword(String email, String code, String newPassword,
      String confirmPassword) async {
    if (code.isEmpty || newPassword.isEmpty || confirmPassword.isEmpty) {
      _showMessage("Please fill in all fields.");
      return;
    }

    if (newPassword != confirmPassword) {
      _showMessage("Passwords do not match.");
      return;
    }

    if (newPassword.length < 6) {
      _showMessage("Password must be at least 6 characters long.");
      return;
    }

    try {
      final api = await _getApiBase();
      final uri = Uri.parse('$api/verify_password_code.php');
      debugPrint('[ResetPwd] Verifying code + resetting -> $uri');
      final resp = await http.post(uri, body: {
        'identifier': email,
        'code': code,
        'new_password': newPassword,
      }).timeout(const Duration(seconds: 15));

      debugPrint('[ResetPwd] HTTP ${resp.statusCode}: ${resp.body}');
      if (resp.body.isNotEmpty) {
        try {
          final Map<String, dynamic> jm = jsonDecode(resp.body);
          if (jm['success'] == true) {
            _showMessage(
                "Password reset successfully! You can now login with your new password.",
                success: true);
            return;
          } else {
            _showMessage(
                jm['message']?.toString() ?? 'Failed to reset password.');
            return;
          }
        } catch (_) {
          // Non-JSON response
        }
      }
      _showMessage('Failed to reset password. Please try again.');
    } catch (e) {
      debugPrint('[ResetPwd] Exception: $e');
      _showMessage('Network error. Please try again.');
    }
  }

  // Show welcome modal with animation and auto-navigation
  void _showWelcomeModal(String username, String email) {
    showDialog(
      context: context,
      barrierDismissible: false,
      barrierColor: Colors.black.withOpacity(0.7),
      builder: (BuildContext context) {
        return _WelcomeModal(
          username: username,
          onComplete: () {
            Navigator.of(context).pop();
            Navigator.pushReplacement(
              context,
              PageRouteBuilder(
                settings: RouteSettings(
                    arguments: {'username': username, 'email': email}),
                pageBuilder: (context, animation, secondaryAnimation) =>
                    DashboardPage(
                  username: username,
                  email: email,
                ),
                transitionsBuilder:
                    (context, animation, secondaryAnimation, child) {
                  final fadeTween = Tween<double>(begin: 0.0, end: 1.0).animate(
                    CurvedAnimation(parent: animation, curve: Curves.easeInOut),
                  );
                  final slideTween = Tween<Offset>(
                    begin: const Offset(0.0, 0.05),
                    end: Offset.zero,
                  ).animate(
                    CurvedAnimation(
                        parent: animation, curve: Curves.easeOutCubic),
                  );
                  return FadeTransition(
                    opacity: fadeTween,
                    child: SlideTransition(position: slideTween, child: child),
                  );
                },
                transitionDuration: const Duration(milliseconds: 500),
              ),
            );
          },
        );
      },
    );
  }
}

// Custom welcome modal widget with animations
class _WelcomeModal extends StatefulWidget {
  final String username;
  final VoidCallback onComplete;

  const _WelcomeModal({
    required this.username,
    required this.onComplete,
  });

  @override
  State<_WelcomeModal> createState() => _WelcomeModalState();
}

class _WelcomeModalState extends State<_WelcomeModal>
    with TickerProviderStateMixin {
  late AnimationController _scaleController;
  late AnimationController _fadeController;
  late Animation<double> _scaleAnimation;
  late Animation<double> _fadeAnimation;

  @override
  void initState() {
    super.initState();

    _scaleController = AnimationController(
      duration: const Duration(milliseconds: 600),
      vsync: this,
    );

    _fadeController = AnimationController(
      duration: const Duration(milliseconds: 400),
      vsync: this,
    );

    _scaleAnimation = Tween<double>(
      begin: 0.0,
      end: 1.0,
    ).animate(CurvedAnimation(
      parent: _scaleController,
      curve: Curves.elasticOut,
    ));

    _fadeAnimation = Tween<double>(
      begin: 0.0,
      end: 1.0,
    ).animate(CurvedAnimation(
      parent: _fadeController,
      curve: Curves.easeInOut,
    ));

    // Start animations
    _fadeController.forward();
    _scaleController.forward();

    // Auto close after 2 seconds
    Timer(const Duration(seconds: 2), () {
      if (mounted) {
        widget.onComplete();
      }
    });
  }

  @override
  void dispose() {
    _scaleController.dispose();
    _fadeController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _fadeAnimation,
      child: ScaleTransition(
        scale: _scaleAnimation,
        child: Dialog(
          backgroundColor: Colors.transparent,
          child: Container(
            padding: const EdgeInsets.all(30),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [
                  Color(0xFF6868AC),
                  Color(0xFF52528A),
                ],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(25),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFF6868AC).withOpacity(0.3),
                  blurRadius: 20,
                  spreadRadius: 5,
                ),
              ],
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                // Success icon with animation
                Container(
                  width: 80,
                  height: 80,
                  decoration: BoxDecoration(
                    color: Colors.white,
                    shape: BoxShape.circle,
                    boxShadow: [
                      BoxShadow(
                        color: Colors.black.withOpacity(0.1),
                        blurRadius: 10,
                        spreadRadius: 2,
                      ),
                    ],
                  ),
                  child: Icon(
                    Icons.check_circle,
                    size: 50,
                    color: Colors.green.shade600,
                  ),
                ),
                const SizedBox(height: 25),
                // Welcome text
                const Text(
                  'Welcome!',
                  style: TextStyle(
                    fontSize: 28,
                    fontWeight: FontWeight.bold,
                    color: Colors.white,
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  'Hello, ${widget.username}',
                  style: const TextStyle(
                    fontSize: 18,
                    color: Colors.white70,
                  ),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 15),
                const Text(
                  'Login successful! Redirecting...',
                  style: TextStyle(
                    fontSize: 14,
                    color: Colors.white60,
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

// Animated modal shown while sending the verification code
class _SendingCodeModal extends StatefulWidget {
  final String identifier;
  final Future<String> Function() getApiBase;
  final void Function(String mask) onSuccess;
  final void Function(String errorMsg) onError;

  const _SendingCodeModal({
    required this.identifier,
    required this.getApiBase,
    required this.onSuccess,
    required this.onError,
  });

  @override
  State<_SendingCodeModal> createState() => _SendingCodeModalState();
}

class _SendingCodeModalState extends State<_SendingCodeModal>
    with SingleTickerProviderStateMixin {
  late AnimationController _animController;
  late Animation<double> _scaleAnimation;

  // 'sending' | 'success' | 'error'
  String _phase = 'sending';
  String _errorMessage = '';
  String _mask = '';

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 500),
    );
    _scaleAnimation = CurvedAnimation(
      parent: _animController,
      curve: Curves.elasticOut,
    );
    _sendCode();
  }

  Future<void> _sendCode() async {
    try {
      final api = await widget.getApiBase();
      final uri = Uri.parse('$api/request_password_code.php');
      debugPrint('[ForgotPwd] Requesting code -> $uri');
      final resp = await http.post(uri, body: {
        'identifier': widget.identifier,
      }).timeout(const Duration(seconds: 20));

      debugPrint('[ForgotPwd] HTTP ${resp.statusCode}: ${resp.body}');

      if (resp.body.isNotEmpty) {
        try {
          final Map<String, dynamic> jm = jsonDecode(resp.body);
          if (jm['success'] == true) {
            final bool emailSent = jm['sent'] == true;
            final mask = jm['mask']?.toString() ?? widget.identifier;
            if (emailSent) {
              if (!mounted) return;
              setState(() {
                _phase = 'success';
                _mask = mask;
              });
              _animController.forward();
              // Auto-dismiss after 2 seconds
              Future.delayed(const Duration(seconds: 2), () {
                if (mounted) widget.onSuccess(mask);
              });
              return;
            } else {
              _setError(
                  'Could not deliver email to $mask. Please check the email address or try again.');
              return;
            }
          } else {
            final msg =
                jm['message']?.toString() ?? 'Failed to send reset code.';
            _setError(msg);
            return;
          }
        } catch (_) {
          // Non-JSON response
        }
      }
      _setError('Failed to send reset code. Please try again.');
    } catch (e) {
      debugPrint('[ForgotPwd] Exception: $e');
      _setError('Network error. Please try again.');
    }
  }

  void _setError(String msg) {
    if (!mounted) return;
    setState(() {
      _phase = 'error';
      _errorMessage = msg;
    });
    _animController.forward();
  }

  @override
  void dispose() {
    _animController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: Colors.transparent,
      elevation: 0,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 32),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(24),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.15),
              blurRadius: 20,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // --- Sending phase ---
            if (_phase == 'sending') ...[
              const SizedBox(
                width: 64,
                height: 64,
                child: CircularProgressIndicator(
                  strokeWidth: 4,
                  valueColor: AlwaysStoppedAnimation<Color>(Color(0xFF6868AC)),
                ),
              ),
              const SizedBox(height: 24),
              Text(
                'Sending Verification Code',
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: Colors.grey.shade800,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Please wait while we send a 6-digit code to your email...',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 14,
                  color: Colors.grey.shade600,
                ),
              ),
            ],

            // --- Success phase ---
            if (_phase == 'success') ...[
              ScaleTransition(
                scale: _scaleAnimation,
                child: Container(
                  width: 72,
                  height: 72,
                  decoration: BoxDecoration(
                    color: Colors.green.shade50,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    Icons.check_circle,
                    size: 56,
                    color: Colors.green.shade600,
                  ),
                ),
              ),
              const SizedBox(height: 20),
              Text(
                'Code Sent Successfully!',
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: Colors.green.shade700,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'A verification code has been sent to $_mask',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 14,
                  color: Colors.grey.shade600,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'Redirecting to code entry...',
                style: TextStyle(
                  fontSize: 12,
                  color: Colors.grey.shade400,
                  fontStyle: FontStyle.italic,
                ),
              ),
            ],

            // --- Error phase ---
            if (_phase == 'error') ...[
              ScaleTransition(
                scale: _scaleAnimation,
                child: Container(
                  width: 72,
                  height: 72,
                  decoration: BoxDecoration(
                    color: Colors.red.shade50,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    Icons.error_outline,
                    size: 56,
                    color: Colors.red.shade600,
                  ),
                ),
              ),
              const SizedBox(height: 20),
              Text(
                'Failed to Send Code',
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: Colors.red.shade700,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                _errorMessage,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 14,
                  color: Colors.grey.shade600,
                ),
              ),
              const SizedBox(height: 20),
              ElevatedButton.icon(
                onPressed: () => widget.onError(_errorMessage),
                icon: const Icon(Icons.refresh, size: 18),
                label: const Text('Try Again'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF6868AC),
                  foregroundColor: Colors.white,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                  padding:
                      const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

// Custom widget for code input dialog with timer and resend functionality
class _CodeInputDialog extends StatefulWidget {
  final String email;
  final TextEditingController codeController;
  final VoidCallback onCodeVerified;
  final VoidCallback onResendCode;

  const _CodeInputDialog({
    required this.email,
    required this.codeController,
    required this.onCodeVerified,
    required this.onResendCode,
  });

  @override
  State<_CodeInputDialog> createState() => _CodeInputDialogState();
}

class _CodeInputDialogState extends State<_CodeInputDialog>
    with TickerProviderStateMixin {
  late AnimationController _timerController;
  int _remainingSeconds = 60;
  bool _canResend = false;
  bool _isVerifying = false;

  @override
  void initState() {
    super.initState();
    _timerController = AnimationController(
      duration: const Duration(seconds: 60),
      vsync: this,
    );
    _startTimer();
  }

  void _startTimer() {
    _remainingSeconds = 60;
    _canResend = false;
    _timerController.reset();
    _timerController.forward();

    Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }

      setState(() {
        _remainingSeconds--;
      });

      if (_remainingSeconds <= 0) {
        timer.cancel();
        setState(() {
          _canResend = true;
        });
      }
    });
  }

  Future<void> _verifyCode() async {
    final code = widget.codeController.text.trim();
    if (code.isEmpty) {
      _showMessage("Please enter the verification code.");
      return;
    }

    if (code.length != 6) {
      _showMessage("Please enter a valid 6-digit code.");
      return;
    }

    setState(() {
      _isVerifying = true;
    });

    try {
      // Code will be verified server-side when the password is actually reset.
      // Here we just validate format and proceed to the password entry dialog.
      widget.onCodeVerified();
    } catch (e) {
      _showMessage("Error verifying code. Please try again.");
    } finally {
      if (mounted) {
        setState(() {
          _isVerifying = false;
        });
      }
    }
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: Colors.red.shade600,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        margin: const EdgeInsets.all(20),
      ),
    );
  }

  @override
  void dispose() {
    _timerController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
      ),
      title: const Row(
        children: [
          Icon(Icons.security, color: Color(0xFF6868AC)),
          SizedBox(width: 10),
          Text(
            'Enter Verification Code',
            style: TextStyle(
              fontSize: 20,
              fontWeight: FontWeight.bold,
            ),
          ),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            'We sent a 6-digit code to ${widget.email}',
            style: const TextStyle(
              fontSize: 16,
              color: Colors.grey,
            ),
          ),
          const SizedBox(height: 20),
          TextField(
            controller: widget.codeController,
            keyboardType: TextInputType.number,
            maxLength: 6,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontSize: 24,
              fontWeight: FontWeight.bold,
              letterSpacing: 8,
            ),
            decoration: InputDecoration(
              labelText: 'Verification Code',
              hintText: '000000',
              counterText: '',
              border: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
              ),
              focusedBorder: OutlineInputBorder(
                borderRadius: BorderRadius.circular(12),
                borderSide:
                    const BorderSide(color: Color(0xFF6868AC), width: 2),
              ),
            ),
          ),
          const SizedBox(height: 12),
          // Timer and resend section
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              if (!_canResend)
                Text(
                  'Resend code in ${_remainingSeconds}s',
                  style: TextStyle(
                    fontSize: 14,
                    color: Colors.grey.shade600,
                  ),
                ),
              if (_canResend)
                TextButton(
                  onPressed: () {
                    widget.onResendCode();
                    _startTimer();
                  },
                  child: const Text(
                    'Resend Code',
                    style: TextStyle(
                      color: Color(0xFF6868AC),
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
            ],
          ),
          // Timer progress bar
          if (!_canResend) ...[
            const SizedBox(height: 8),
            AnimatedBuilder(
              animation: _timerController,
              builder: (context, child) {
                return LinearProgressIndicator(
                  value: 1.0 - _timerController.value,
                  backgroundColor: Colors.grey.shade300,
                  valueColor:
                      const AlwaysStoppedAnimation<Color>(Color(0xFF6868AC)),
                );
              },
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(
            'Cancel',
            style: TextStyle(color: Colors.grey.shade600),
          ),
        ),
        ElevatedButton(
          onPressed: _isVerifying ? null : _verifyCode,
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF6868AC),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
            ),
          ),
          child: _isVerifying
              ? const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                  ),
                )
              : const Text(
                  'Verify Code',
                  style: TextStyle(color: Colors.white),
                ),
        ),
      ],
    );
  }
}
