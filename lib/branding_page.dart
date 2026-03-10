import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'splash_page.dart';

class BrandingPage extends StatefulWidget {
  const BrandingPage({super.key});

  @override
  State<BrandingPage> createState() => _BrandingPageState();
}

class _BrandingPageState extends State<BrandingPage>
    with TickerProviderStateMixin {
  late AnimationController _fadeInController;
  late AnimationController _fadeOutController;
  late Animation<double> _fadeInAnimation;
  late Animation<double> _fadeOutAnimation;

  @override
  void initState() {
    super.initState();

    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.light,
      ),
    );

    _fadeInController = AnimationController(
      duration: const Duration(milliseconds: 800),
      vsync: this,
    );
    _fadeOutController = AnimationController(
      duration: const Duration(milliseconds: 400),
      vsync: this,
    );

    _fadeInAnimation = CurvedAnimation(
      parent: _fadeInController,
      curve: Curves.easeOut,
    );
    _fadeOutAnimation = Tween<double>(begin: 1.0, end: 0.0).animate(
      CurvedAnimation(parent: _fadeOutController, curve: Curves.easeIn),
    );

    _runSequence();
  }

  Future<void> _runSequence() async {
    // Small delay before fade-in
    await Future.delayed(const Duration(milliseconds: 300));
    if (!mounted) return;
    _fadeInController.forward();

    // Hold for 1.5s after fade-in completes
    await Future.delayed(const Duration(milliseconds: 2300));
    if (!mounted) return;

    // Fade out
    _fadeOutController.forward();
    await Future.delayed(const Duration(milliseconds: 400));
    if (!mounted) return;

    // Navigate to splash
    Navigator.of(context).pushReplacement(
      PageRouteBuilder(
        pageBuilder: (_, __, ___) => const SplashPage(),
        transitionDuration: const Duration(milliseconds: 500),
        transitionsBuilder: (_, animation, __, child) {
          return FadeTransition(opacity: animation, child: child);
        },
      ),
    );
  }

  @override
  void dispose() {
    _fadeInController.dispose();
    _fadeOutController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0xFF0F172A), // Dark navy
              Color(0xFF1E293B), // Slightly lighter navy
            ],
          ),
        ),
        child: FadeTransition(
          opacity: _fadeInAnimation,
          child: FadeTransition(
            opacity: _fadeOutAnimation,
            child: Center(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // "Application by" — subtle, small
                  Text(
                    'Application by',
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w300,
                      color: Colors.white.withOpacity(0.6),
                      letterSpacing: 2.0,
                    ),
                  ),
                  const SizedBox(height: 12),
                  // "ACCA TECH" — bold, prominent
                  Text(
                    'ACCA TECH',
                    style: TextStyle(
                      fontSize: 36,
                      fontWeight: FontWeight.w800,
                      color: Colors.white.withOpacity(0.95),
                      letterSpacing: 6.0,
                      shadows: [
                        Shadow(
                          color: const Color(0xFF38BDF8).withOpacity(0.4),
                          blurRadius: 20,
                          offset: const Offset(0, 0),
                        ),
                        Shadow(
                          color: const Color(0xFF38BDF8).withOpacity(0.2),
                          blurRadius: 40,
                          offset: const Offset(0, 0),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 8),
                  // Subtle divider line
                  Container(
                    width: 40,
                    height: 2,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(1),
                      gradient: LinearGradient(
                        colors: [
                          const Color(0xFF38BDF8).withOpacity(0.0),
                          const Color(0xFF38BDF8).withOpacity(0.6),
                          const Color(0xFF38BDF8).withOpacity(0.0),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
