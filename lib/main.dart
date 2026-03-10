import 'dart:io';
import 'dart:ui';

import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_application_7/login_page.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'branding_page.dart';
import 'dashboard_page.dart';
import 'gallery_page.dart';
import 'notification_service.dart';

// A smooth, consistent page transition for all platforms (fade + subtle slide)
class SmoothFadeSlideTransitionsBuilder extends PageTransitionsBuilder {
  const SmoothFadeSlideTransitionsBuilder();

  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    final curved = CurvedAnimation(
        parent: animation,
        curve: Curves.easeOutCubic,
        reverseCurve: Curves.easeInCubic);
    return FadeTransition(
      opacity: curved,
      child: SlideTransition(
        position:
            Tween<Offset>(begin: const Offset(0.04, 0.0), end: Offset.zero)
                .animate(curved),
        child: child,
      ),
    );
  }
}

class AppScrollBehavior extends ScrollBehavior {
  @override
  Widget buildOverscrollIndicator(
      BuildContext context, Widget child, ScrollableDetails details) {
    // Remove overscroll glow for smoother feel on all platforms
    return child;
  }
}

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // ── P0: Global error boundaries ──
  FlutterError.onError = (FlutterErrorDetails details) {
    FlutterError.presentError(details); // keep default red-screen in debug
    _logCrash('FlutterError', details.exceptionAsString(),
        details.stack?.toString() ?? '');
  };
  PlatformDispatcher.instance.onError = (Object error, StackTrace stack) {
    _logCrash('PlatformError', error.toString(), stack.toString());
    return true; // handled — prevent crash
  };

  // ── Quick win: Image cache limits (100 MB, 200 images) ──
  PaintingBinding.instance.imageCache.maximumSizeBytes = 100 * 1024 * 1024;
  PaintingBinding.instance.imageCache.maximumSize = 200;

  try {
    if (kIsWeb) {
      await Firebase.initializeApp(
        options: const FirebaseOptions(
          apiKey: 'YOUR_API_KEY',
          appId: 'YOUR_APP_ID',
          messagingSenderId: 'YOUR_SENDER_ID',
          projectId: 'YOUR_PROJECT_ID',
        ),
      );
    } else {
      await Firebase.initializeApp();
    }
  } catch (e) {
    debugPrint('[Firebase] Initialization skipped: $e');
  }
  final prefs = await SharedPreferences.getInstance();
  // ── P1: Batch SharedPreferences reads at startup ──
  final modeStr = prefs.getString('theme_mode') ?? 'system';
  final seedValue = prefs.getInt('theme_seed') ?? const Color(0xFF6868AC).value;
  // Pre-read frequently used values so pages don't need to await them
  SessionCache.userName = prefs.getString('user_name') ?? '';
  SessionCache.department = prefs.getString('user_department') ?? '';
  SessionCache.serverUrl = prefs.getString('server_url') ?? '';
  SessionCache.userId = prefs.getInt('user_id') ?? 0;

  ThemeMode initialMode = ThemeMode.system;
  if (modeStr == 'light') initialMode = ThemeMode.light;
  if (modeStr == 'dark') initialMode = ThemeMode.dark;
  runApp(MyApp(initialMode: initialMode, initialSeed: Color(seedValue)));
  // Initialize notifications after runApp; guard in case Firebase isn't configured
  try {
    NotificationService.init();
  } catch (e) {
    debugPrint('[Notification] Init skipped: $e');
  }
}

/// Lightweight session cache — avoids repeated SharedPreferences reads
class SessionCache {
  static String userName = '';
  static String department = '';
  static String serverUrl = '';
  static int userId = 0;

  /// Refresh from SharedPreferences (call after login/logout)
  static Future<void> refresh() async {
    final prefs = await SharedPreferences.getInstance();
    userName = prefs.getString('user_name') ?? '';
    department = prefs.getString('user_department') ?? '';
    serverUrl = prefs.getString('server_url') ?? '';
    userId = prefs.getInt('user_id') ?? 0;
  }
}

/// Log crash details to a local file for debugging
Future<void> _logCrash(String type, String error, String stackTrace) async {
  try {
    if (kIsWeb) return;
    final dir = await getApplicationDocumentsDirectory();
    final file = File('${dir.path}/crash_log.txt');
    final timestamp = DateTime.now().toIso8601String();
    final entry = '[$timestamp] $type\n$error\n$stackTrace\n---\n';
    await file.writeAsString(entry, mode: FileMode.append);
    // Keep log file under 500 KB
    if (await file.length() > 500 * 1024) {
      final content = await file.readAsString();
      await file.writeAsString(content.substring(content.length ~/ 2));
    }
  } catch (_) {
    // Silently ignore — crash logging should never crash the app
  }
}

class MyApp extends StatefulWidget {
  final ThemeMode initialMode;
  final Color initialSeed;
  const MyApp(
      {super.key, required this.initialMode, required this.initialSeed});

  static MyAppState? of(BuildContext context) =>
      context.findAncestorStateOfType<MyAppState>();

  @override
  State<MyApp> createState() => MyAppState();
}

class MyAppState extends State<MyApp> {
  static MyAppState? instance;
  late ThemeMode _themeMode;
  late Color _seedColor;

  @override
  void initState() {
    super.initState();
    instance = this;
    _themeMode = widget.initialMode;
    _seedColor = widget.initialSeed;
    _subscribeDepartmentTopic();
  }

  Future<void> _subscribeDepartmentTopic() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final dept = (prefs.getString('user_department') ?? '').trim();
      if (dept.isEmpty) return;
      // Topic subscription handled in NotificationService via register, but keep placeholder here
    } catch (_) {}
  }

  Future<void> setThemeMode(ThemeMode m) async {
    setState(() => _themeMode = m);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
        'theme_mode',
        m == ThemeMode.dark
            ? 'dark'
            : m == ThemeMode.light
                ? 'light'
                : 'system');
  }

  Future<void> setSeedColor(Color c) async {
    setState(() => _seedColor = c);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setInt('theme_seed', c.value);
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'CHRMO Document Tracking',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorSchemeSeed: const Color(0xFF6868AC),
        fontFamily: 'Poppins',
        visualDensity: VisualDensity.adaptivePlatformDensity,
        splashFactory: InkRipple.splashFactory,
        textTheme: ThemeData.light().textTheme.apply(
              fontFamily: 'Poppins',
              bodyColor: const Color(0xFF1B1B1F),
              displayColor: const Color(0xFF1B1B1F),
            ),
        appBarTheme: const AppBarTheme(
          backgroundColor: Color(0xFF6868AC),
          foregroundColor: Colors.white,
          elevation: 0,
          centerTitle: false,
          scrolledUnderElevation: 2,
          titleTextStyle: TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 20,
              color: Colors.white,
              letterSpacing: -0.3),
        ),
        cardTheme: CardThemeData(
          elevation: 0,
          color: Colors.white,
          surfaceTintColor: Colors.transparent,
          margin: const EdgeInsets.symmetric(vertical: 6, horizontal: 0),
          shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(16),
              side: BorderSide(color: Colors.black.withOpacity(0.05))),
        ),
        iconTheme: const IconThemeData(color: Color(0xFF1B1B1F)),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF6868AC),
            foregroundColor: Colors.white,
            elevation: 0,
            padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 24),
            textStyle: const TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 15,
              letterSpacing: 0.3,
            ),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
        outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
            foregroundColor: const Color(0xFF6868AC),
            side: const BorderSide(color: Color(0xFF6868AC), width: 1.5),
            padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
            textStyle: const TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 15,
            ),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
        textButtonTheme: TextButtonThemeData(
          style: TextButton.styleFrom(
            foregroundColor: const Color(0xFF6868AC),
            textStyle: const TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 14,
            ),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          ),
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: Colors.white,
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: Colors.grey.shade300),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: Colors.grey.shade300),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: Color(0xFF6868AC), width: 2),
          ),
          labelStyle: const TextStyle(
            fontFamily: 'Poppins',
            fontSize: 14,
            fontWeight: FontWeight.w400,
          ),
          hintStyle: TextStyle(
            fontFamily: 'Poppins',
            fontSize: 14,
            color: Colors.grey.shade400,
          ),
        ),
        snackBarTheme: SnackBarThemeData(
          behavior: SnackBarBehavior.floating,
          backgroundColor: const Color(0xFF1B1B1F),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          contentTextStyle: const TextStyle(
            fontFamily: 'Poppins',
            fontSize: 14,
            fontWeight: FontWeight.w500,
          ),
        ),
        dialogTheme: DialogThemeData(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          surfaceTintColor: Colors.transparent,
          titleTextStyle: const TextStyle(
            fontFamily: 'Poppins',
            fontSize: 20,
            fontWeight: FontWeight.w600,
            color: Color(0xFF1B1B1F),
          ),
        ),
        bottomSheetTheme: const BottomSheetThemeData(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
          ),
          surfaceTintColor: Colors.transparent,
          showDragHandle: true,
        ),
        chipTheme: ChipThemeData(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
          side: BorderSide.none,
          labelStyle: const TextStyle(
            fontFamily: 'Poppins',
            fontSize: 13,
            fontWeight: FontWeight.w500,
          ),
        ),
        dividerTheme: DividerThemeData(
          color: Colors.grey.shade200,
          thickness: 1,
          space: 0,
        ),
        listTileTheme: const ListTileThemeData(
            iconColor: Color(0xFF6868AC), textColor: null),
        pageTransitionsTheme: const PageTransitionsTheme(builders: {
          TargetPlatform.android: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.iOS: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.windows: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.linux: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.macOS: SmoothFadeSlideTransitionsBuilder(),
        }),
      ),
      darkTheme: ThemeData(
        useMaterial3: true,
        colorSchemeSeed: _seedColor,
        fontFamily: 'Poppins',
        brightness: Brightness.dark,
        visualDensity: VisualDensity.adaptivePlatformDensity,
        splashFactory: InkRipple.splashFactory,
        appBarTheme: const AppBarTheme(
          backgroundColor: Color(0xFF6868AC),
          foregroundColor: Colors.white,
          elevation: 0,
          scrolledUnderElevation: 2,
          titleTextStyle: TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 20,
              color: Colors.white,
              letterSpacing: -0.3),
        ),
        textTheme: ThemeData.dark().textTheme.apply(
              fontFamily: 'Poppins',
              bodyColor: Colors.white,
              displayColor: Colors.white,
            ),
        iconTheme: const IconThemeData(color: Colors.white70),
        listTileTheme: const ListTileThemeData(
            textColor: Colors.white, iconColor: Colors.white70),
        scaffoldBackgroundColor: const Color(0xFF0F1115),
        cardColor: const Color(0xFF171A21),
        cardTheme: CardThemeData(
          color: const Color(0xFF171A21),
          elevation: 0,
          surfaceTintColor: Colors.transparent,
          margin: const EdgeInsets.symmetric(vertical: 6, horizontal: 0),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
            side: BorderSide(color: Colors.white.withOpacity(0.06)),
          ),
        ),
        dividerColor: Colors.white12,
        dividerTheme: const DividerThemeData(
          color: Colors.white12,
          thickness: 1,
          space: 0,
        ),
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF6868AC),
            foregroundColor: Colors.white,
            elevation: 0,
            padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 24),
            textStyle: const TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 15,
            ),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
        outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
            foregroundColor: const Color(0xFF6868AC),
            side: const BorderSide(color: Color(0xFF6868AC), width: 1.5),
            padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
            textStyle: const TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 15,
            ),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          ),
        ),
        textButtonTheme: TextButtonThemeData(
          style: TextButton.styleFrom(
            foregroundColor: const Color(0xFF6868AC),
            textStyle: const TextStyle(
              fontFamily: 'Poppins',
              fontWeight: FontWeight.w600,
              fontSize: 14,
            ),
          ),
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: const Color(0xFF1E2128),
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide(color: Colors.white.withOpacity(0.1)),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: const BorderSide(color: Color(0xFF6868AC), width: 2),
          ),
        ),
        snackBarTheme: SnackBarThemeData(
          behavior: SnackBarBehavior.floating,
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          contentTextStyle: const TextStyle(
            fontFamily: 'Poppins',
            fontSize: 14,
            fontWeight: FontWeight.w500,
          ),
        ),
        dialogTheme: DialogThemeData(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          surfaceTintColor: Colors.transparent,
          titleTextStyle: const TextStyle(
            fontFamily: 'Poppins',
            fontSize: 20,
            fontWeight: FontWeight.w600,
            color: Colors.white,
          ),
        ),
        bottomSheetTheme: const BottomSheetThemeData(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
          ),
          surfaceTintColor: Colors.transparent,
          showDragHandle: true,
        ),
        chipTheme: ChipThemeData(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(100)),
          side: BorderSide.none,
          labelStyle: const TextStyle(
            fontFamily: 'Poppins',
            fontSize: 13,
            fontWeight: FontWeight.w500,
          ),
        ),
        pageTransitionsTheme: const PageTransitionsTheme(builders: {
          TargetPlatform.android: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.iOS: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.windows: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.linux: SmoothFadeSlideTransitionsBuilder(),
          TargetPlatform.macOS: SmoothFadeSlideTransitionsBuilder(),
        }),
      ),
      themeMode: _themeMode,
      builder: (context, child) {
        return ScrollConfiguration(
          behavior: AppScrollBehavior(),
          child: MediaQuery(
            data: MediaQuery.of(context)
                .copyWith(textScaler: const TextScaler.linear(1.0)),
            child: child!,
          ),
        );
      },
      initialRoute: '/',
      routes: {
        '/': (context) => const BrandingPage(),
        '/home': (context) => const DashboardPage(),
        '/landing': (context) => const DashboardPage(),
        '/gallery': (context) => const GalleryPage(),
        '/login': (context) => const LoginPage(),
      },
    );
  }
}
