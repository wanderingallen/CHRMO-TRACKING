import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'login_page.dart';
import 'services/performance_utils.dart';

class SplashPage extends StatefulWidget {
  const SplashPage({super.key});

  @override
  State<SplashPage> createState() => _SplashPageState();
}

class _SplashPageState extends State<SplashPage> with TickerProviderStateMixin {
  final PerformanceUtils _perfUtils = PerformanceUtils();

  // Logo entrance: scale + fade
  late AnimationController _logoController;
  late Animation<double> _logoScale;
  late Animation<double> _logoFade;

  // Glow pulse around logo
  late AnimationController _glowController;
  late Animation<double> _glowAnimation;

  // Text slide-up + fade
  late AnimationController _textController;
  late Animation<double> _textFade;
  late Animation<Offset> _textSlide;

  // Scanning line sweeps over logo
  late AnimationController _scanController;
  late Animation<double> _scanPosition;

  // Subtitle + loader fade
  late AnimationController _subtitleController;
  late Animation<double> _subtitleFade;

  // Exit fade-out
  late AnimationController _exitController;
  late Animation<double> _exitFade;

  @override
  void initState() {
    super.initState();

    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
      ),
    );

    // 1) Logo: elastic scale-in + fade
    _logoController = AnimationController(
      duration: const Duration(milliseconds: 1200),
      vsync: this,
    );
    _logoScale = Tween<double>(begin: 0.3, end: 1.0).animate(
      CurvedAnimation(parent: _logoController, curve: Curves.elasticOut),
    );
    _logoFade = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _logoController,
        curve: const Interval(0.0, 0.4, curve: Curves.easeOut),
      ),
    );

    // 2) Glow pulse (loops)
    _glowController = AnimationController(
      duration: const Duration(milliseconds: 2000),
      vsync: this,
    );
    _glowAnimation = Tween<double>(begin: 0.15, end: 0.45).animate(
      CurvedAnimation(parent: _glowController, curve: Curves.easeInOut),
    );

    // 3) Title text: slide up + fade
    _textController = AnimationController(
      duration: const Duration(milliseconds: 800),
      vsync: this,
    );
    _textFade = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _textController, curve: Curves.easeOut),
    );
    _textSlide = Tween<Offset>(
      begin: const Offset(0, 0.4),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(parent: _textController, curve: Curves.easeOutCubic),
    );

    // 4) Scan line across logo
    _scanController = AnimationController(
      duration: const Duration(milliseconds: 1400),
      vsync: this,
    );
    _scanPosition = Tween<double>(begin: -1.0, end: 2.0).animate(
      CurvedAnimation(parent: _scanController, curve: Curves.easeInOut),
    );

    // 5) Subtitle + loader
    _subtitleController = AnimationController(
      duration: const Duration(milliseconds: 600),
      vsync: this,
    );
    _subtitleFade = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _subtitleController, curve: Curves.easeOut),
    );

    // 6) Exit fade-out
    _exitController = AnimationController(
      duration: const Duration(milliseconds: 500),
      vsync: this,
    );
    _exitFade = Tween<double>(begin: 1.0, end: 0.0).animate(
      CurvedAnimation(parent: _exitController, curve: Curves.easeIn),
    );

    _runAnimationSequence();
  }

  Future<void> _runAnimationSequence() async {
    // Warm up shaders in parallel
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (mounted) await _perfUtils.warmUpShaders(context);
    });

    // Step 1: Logo scales in (0ms → 1200ms)
    await Future.delayed(const Duration(milliseconds: 200));
    if (!mounted) return;
    _logoController.forward();

    // Step 2: Start glow pulse after logo starts appearing (400ms)
    await Future.delayed(const Duration(milliseconds: 400));
    if (!mounted) return;
    _glowController.repeat(reverse: true);

    // Step 3: Scan line sweeps across logo (800ms after start)
    await Future.delayed(const Duration(milliseconds: 400));
    if (!mounted) return;
    _scanController.forward();

    // Step 4: Title text slides up (after scan starts)
    await Future.delayed(const Duration(milliseconds: 500));
    if (!mounted) return;
    _textController.forward();

    // Step 5: Subtitle + loader fade in
    await Future.delayed(const Duration(milliseconds: 600));
    if (!mounted) return;
    _subtitleController.forward();

    // Hold for viewing
    await Future.delayed(const Duration(milliseconds: 1200));
    if (!mounted) return;

    // Step 6: Everything fades out
    _exitController.forward();
    _glowController.stop();
    await Future.delayed(const Duration(milliseconds: 500));
    if (!mounted) return;

    // Navigate to login
    Navigator.of(context).pushReplacement(
      PageRouteBuilder(
        pageBuilder: (_, __, ___) => const LoginPage(),
        transitionDuration: const Duration(milliseconds: 500),
        transitionsBuilder: (_, animation, __, child) {
          return FadeTransition(
            opacity: CurvedAnimation(
              parent: animation,
              curve: Curves.easeInOut,
            ),
            child: child,
          );
        },
      ),
    );
  }

  @override
  void dispose() {
    _logoController.dispose();
    _glowController.dispose();
    _textController.dispose();
    _scanController.dispose();
    _subtitleController.dispose();
    _exitController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: AnimatedBuilder(
        animation: _exitFade,
        builder: (context, child) {
          return Opacity(
            opacity: _exitFade.value,
            child: Container(
              width: double.infinity,
              height: double.infinity,
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [
                    Color(0xFF0284C7), // Sky-600
                    Color(0xFF0369A1), // Sky-700
                    Color(0xFF075985), // Sky-800
                  ],
                  stops: [0.0, 0.5, 1.0],
                ),
              ),
              child: Stack(
                children: [
                  // Subtle background particles
                  ..._buildBackgroundDots(),

                  // Center content
                  Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        // Logo with glow + scan line
                        _buildAnimatedLogo(),
                        const SizedBox(height: 28),
                        // Title text
                        _buildAnimatedTitle(),
                        const SizedBox(height: 10),
                        // Subtitle
                        _buildAnimatedSubtitle(),
                      ],
                    ),
                  ),

                  // Loader at bottom
                  _buildBottomLoader(),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildAnimatedLogo() {
    return AnimatedBuilder(
      animation:
          Listenable.merge([_logoController, _glowController, _scanController]),
      builder: (context, child) {
        return Transform.scale(
          scale: _logoScale.value,
          child: Opacity(
            opacity: _logoFade.value,
            child: Container(
              width: 200,
              height: 200,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white,
                boxShadow: [
                  // Animated glow
                  BoxShadow(
                    color: const Color(0xFF38BDF8)
                        .withOpacity(_glowAnimation.value),
                    blurRadius: 40,
                    spreadRadius: 8,
                  ),
                  // Drop shadow
                  BoxShadow(
                    color: Colors.black.withOpacity(0.15),
                    blurRadius: 20,
                    offset: const Offset(0, 8),
                  ),
                ],
              ),
              child: ClipOval(
                child: Stack(
                  children: [
                    // White background for the seal
                    Container(
                      width: 200,
                      height: 200,
                      color: Colors.white,
                    ),
                    // Logo image — contain so nothing is cropped
                    Padding(
                      padding: const EdgeInsets.all(8),
                      child: Image.asset(
                        'assets/images/Logo.png',
                        width: 184,
                        height: 184,
                        fit: BoxFit.contain,
                        errorBuilder: (_, __, ___) => Container(
                          width: 184,
                          height: 184,
                          color: Colors.white,
                          child: const Icon(Icons.document_scanner_outlined,
                              size: 80, color: Color(0xFF0EA5E9)),
                        ),
                      ),
                    ),
                    // Scanning line overlay
                    if (_scanPosition.value >= -1.0 &&
                        _scanPosition.value <= 1.0)
                      Positioned(
                        top: _scanPosition.value * 200,
                        left: 0,
                        right: 0,
                        child: Container(
                          height: 3,
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              colors: [
                                Colors.white.withOpacity(0.0),
                                Colors.white.withOpacity(0.8),
                                const Color(0xFF38BDF8).withOpacity(0.9),
                                Colors.white.withOpacity(0.8),
                                Colors.white.withOpacity(0.0),
                              ],
                            ),
                            boxShadow: [
                              BoxShadow(
                                color: const Color(0xFF38BDF8).withOpacity(0.5),
                                blurRadius: 12,
                                spreadRadius: 2,
                              ),
                            ],
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildAnimatedTitle() {
    return SlideTransition(
      position: _textSlide,
      child: FadeTransition(
        opacity: _textFade,
        child: Column(
          children: [
            Text(
              'CHRMO DOCUMENT',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 28,
                height: 1.15,
                letterSpacing: 2.0,
                fontWeight: FontWeight.w800,
                color: Colors.white.withOpacity(0.95),
                shadows: [
                  Shadow(
                    color: Colors.black.withOpacity(0.2),
                    blurRadius: 10,
                    offset: const Offset(0, 3),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 4),
            Text(
              'TRACKING',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 28,
                height: 1.15,
                letterSpacing: 6.0,
                fontWeight: FontWeight.w800,
                color: Colors.white.withOpacity(0.95),
                shadows: [
                  Shadow(
                    color: Colors.black.withOpacity(0.2),
                    blurRadius: 10,
                    offset: const Offset(0, 3),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAnimatedSubtitle() {
    return FadeTransition(
      opacity: _subtitleFade,
      child: Column(
        children: [
          // Decorative line
          Container(
            width: 50,
            height: 2,
            margin: const EdgeInsets.only(bottom: 12),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(1),
              gradient: LinearGradient(
                colors: [
                  Colors.white.withOpacity(0.0),
                  Colors.white.withOpacity(0.7),
                  Colors.white.withOpacity(0.0),
                ],
              ),
            ),
          ),
          Text(
            'Manage Your Documents Efficiently',
            style: TextStyle(
              fontSize: 14,
              letterSpacing: 0.5,
              fontWeight: FontWeight.w500,
              color: Colors.white.withOpacity(0.85),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildBottomLoader() {
    return Positioned(
      left: 0,
      right: 0,
      bottom: 40 + MediaQuery.of(context).padding.bottom,
      child: FadeTransition(
        opacity: _subtitleFade,
        child: Column(
          children: [
            const SizedBox(
              height: 24,
              width: 24,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'Loading...',
              style: TextStyle(
                fontSize: 12,
                letterSpacing: 1.0,
                fontWeight: FontWeight.w400,
                color: Colors.white.withOpacity(0.6),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // Subtle floating dots in background
  List<Widget> _buildBackgroundDots() {
    final rng = Random(42); // fixed seed for deterministic positions
    return List.generate(15, (i) {
      final size = 4.0 + rng.nextDouble() * 6;
      final left = rng.nextDouble() * 400;
      final top = rng.nextDouble() * 800;
      final opacity = 0.05 + rng.nextDouble() * 0.1;
      return Positioned(
        left: left,
        top: top,
        child: FadeTransition(
          opacity: _logoFade,
          child: Container(
            width: size,
            height: size,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white.withOpacity(opacity),
            ),
          ),
        ),
      );
    });
  }
}
