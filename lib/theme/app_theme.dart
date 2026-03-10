import 'dart:ui';

import 'package:flutter/material.dart';

/// CHRMO Document Tracker — Centralized UI Design System
///
/// This file provides consistent visual constants used across the app.
/// It does NOT change any existing colors — it reuses the app's existing
/// periwinkle (#6868AC) palette and establishes consistent spacing,
/// border-radius, shadows, and typography helpers.
///
/// Usage:
///   import 'package:flutter_application_7/theme/app_theme.dart';
///
///   Container(decoration: AppTheme.cardDecoration(context));
///   Text('Title', style: AppTheme.heading(context));
///   AppTheme.statusBadge('Completed');
class AppTheme {
  AppTheme._();

  // ──────────── Existing App Colors (unchanged) ────────────
  static const Color periwinkle = Color(0xFF6868AC);
  static const Color periwinkleDark = Color(0xFF52528A);
  static const Color skyBlue = Color(0xFF38BDF8);
  static const Color skyBlueDark = Color(0xFF0EA5E9);
  static const Color cyanBg = Color(0xFFE0F7FA);
  static const Color textDark = Color(0xFF1B1B1F);
  static const Color textBody = Color(0xFF1F2937);
  static const Color textMuted = Color(0xFF6B7280);
  static const Color textHint = Color(0xFF9CA3AF);
  static const Color borderLight = Color(0xFFD1D5DB);
  static const Color statusGreen = Color(0xFF16A34A);
  static const Color statusRed = Color(0xFFDC2626);
  static const Color statusAmber = Color(0xFFF59E0B);
  static const Color statusBlue = Color(0xFF3B82F6);

  // ──────────── Consistent Design Tokens ────────────
  static const double radiusXS = 8.0;
  static const double radiusSM = 12.0;
  static const double radiusMD = 16.0;
  static const double radiusLG = 20.0;
  static const double radiusXL = 24.0;
  static const double radiusFull = 100.0;

  static const double spacingXS = 4.0;
  static const double spacingSM = 8.0;
  static const double spacingMD = 12.0;
  static const double spacingLG = 16.0;
  static const double spacingXL = 20.0;
  static const double spacingXXL = 24.0;

  // ──────────── Typography Helpers ────────────
  // These use the app's existing Poppins font, just with consistent sizing

  /// Large page title (28px bold)
  static TextStyle displayLarge(BuildContext context) => TextStyle(
        fontFamily: 'Poppins',
        fontSize: 28,
        fontWeight: FontWeight.w700,
        color: Theme.of(context).colorScheme.onSurface,
        letterSpacing: -0.5,
        height: 1.2,
      );

  /// Section title / headline (22px semibold)
  static TextStyle displayMedium(BuildContext context) => TextStyle(
        fontFamily: 'Poppins',
        fontSize: 22,
        fontWeight: FontWeight.w600,
        color: Theme.of(context).colorScheme.onSurface,
        letterSpacing: -0.3,
        height: 1.3,
      );

  /// Card / section header (18px semibold)
  static TextStyle heading(BuildContext context) => TextStyle(
        fontFamily: 'Poppins',
        fontSize: 18,
        fontWeight: FontWeight.w600,
        color: Theme.of(context).colorScheme.onSurface,
        height: 1.3,
      );

  /// Sub-heading / list title (16px medium)
  static TextStyle titleMedium(BuildContext context) => TextStyle(
        fontFamily: 'Poppins',
        fontSize: 16,
        fontWeight: FontWeight.w500,
        color: Theme.of(context).colorScheme.onSurface,
        height: 1.4,
      );

  /// Body text (14px regular)
  static TextStyle body(BuildContext context) => TextStyle(
        fontFamily: 'Poppins',
        fontSize: 14,
        fontWeight: FontWeight.w400,
        color: Theme.of(context).colorScheme.onSurface.withOpacity(0.75),
        height: 1.5,
      );

  /// Small / caption text (12px medium)
  static TextStyle caption(BuildContext context) => TextStyle(
        fontFamily: 'Poppins',
        fontSize: 12,
        fontWeight: FontWeight.w500,
        color: Theme.of(context).colorScheme.onSurface.withOpacity(0.55),
        height: 1.4,
      );

  /// Tiny label text (11px)
  static TextStyle label(BuildContext context) => TextStyle(
        fontFamily: 'Poppins',
        fontSize: 11,
        fontWeight: FontWeight.w500,
        color: Theme.of(context).colorScheme.onSurface.withOpacity(0.5),
        letterSpacing: 0.3,
        height: 1.3,
      );

  /// Button text (15px semibold)
  static const TextStyle buttonLabel = TextStyle(
    fontFamily: 'Poppins',
    fontSize: 15,
    fontWeight: FontWeight.w600,
    letterSpacing: 0.3,
    height: 1.0,
  );

  // ──────────── Card / Container Decorations ────────────

  /// Modern elevated card with soft multi-layer shadow
  static BoxDecoration cardDecoration(
    BuildContext context, {
    Color? color,
    double radius = radiusMD,
    Color? accentColor,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return BoxDecoration(
      color: color ?? Theme.of(context).cardColor,
      borderRadius: BorderRadius.circular(radius),
      border: isDark ? Border.all(color: Colors.white.withOpacity(0.06)) : null,
      boxShadow: isDark
          ? null
          : [
              BoxShadow(
                color:
                    (accentColor ?? const Color(0xFF6868AC)).withOpacity(0.06),
                blurRadius: 16,
                offset: const Offset(0, 6),
              ),
              BoxShadow(
                color: Colors.black.withOpacity(0.03),
                blurRadius: 4,
                offset: const Offset(0, 1),
              ),
            ],
    );
  }

  /// Subtle card (less shadow, for nested/secondary cards)
  static BoxDecoration cardDecorationSubtle(
    BuildContext context, {
    Color? color,
    double radius = radiusSM,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return BoxDecoration(
      color: color ?? Theme.of(context).cardColor,
      borderRadius: BorderRadius.circular(radius),
      border: isDark
          ? Border.all(color: Colors.white.withOpacity(0.06))
          : Border.all(color: Colors.black.withOpacity(0.04)),
      boxShadow: isDark
          ? null
          : [
              BoxShadow(
                color: Colors.black.withOpacity(0.04),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
    );
  }

  /// Glassmorphic overlay (for scanner/camera screens)
  static Widget glassCard({
    required Widget child,
    double radius = radiusLG,
    EdgeInsets padding = const EdgeInsets.all(16),
    double blur = 10,
    Color? backgroundColor,
  }) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: blur, sigmaY: blur),
        child: Container(
          padding: padding,
          decoration: BoxDecoration(
            color: backgroundColor ?? Colors.white.withOpacity(0.15),
            borderRadius: BorderRadius.circular(radius),
            border: Border.all(color: Colors.white.withOpacity(0.2), width: 1),
          ),
          child: child,
        ),
      ),
    );
  }

  // ──────────── Status Badge ────────────

  static Color statusColorFor(String status) {
    switch (status.toLowerCase().trim()) {
      case 'pending':
        return statusAmber;
      case 'in review':
      case 'in_review':
        return statusBlue;
      case 'completed':
        return statusGreen;
      case 'routed':
      case 'routing':
        return const Color(0xFF8B5CF6);
      case 'received':
        return const Color(0xFF06B6D4);
      case 'archived':
        return textMuted;
      case 'rejected':
        return statusRed;
      default:
        return textMuted;
    }
  }

  /// A consistent status pill badge
  static Widget statusBadge(String status, {double fontSize = 12}) {
    final color = statusColorFor(status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(radiusFull),
        border: Border.all(color: color.withOpacity(0.25), width: 1),
      ),
      child: Text(
        status,
        style: TextStyle(
          fontFamily: 'Poppins',
          fontSize: fontSize,
          fontWeight: FontWeight.w600,
          color: color,
          height: 1.2,
        ),
      ),
    );
  }

  // ──────────── Section Header ────────────

  /// A consistent section header with accent bar
  static Widget sectionHeader(
    BuildContext context,
    String title, {
    VoidCallback? onSeeAll,
    Widget? trailing,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(
          horizontal: spacingLG, vertical: spacingSM),
      child: Row(
        children: [
          Container(
            width: 4,
            height: 22,
            decoration: BoxDecoration(
              color: periwinkle,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              title,
              style: heading(context),
            ),
          ),
          if (trailing != null) trailing,
          if (onSeeAll != null)
            TextButton(
              onPressed: onSeeAll,
              child: const Text(
                'See All',
                style: TextStyle(
                  fontFamily: 'Poppins',
                  fontSize: 13,
                  fontWeight: FontWeight.w500,
                  color: periwinkle,
                ),
              ),
            ),
        ],
      ),
    );
  }

  // ──────────── Input Field Decoration ────────────

  /// Consistent text input styling (preserves existing border colors)
  static InputDecoration inputDecoration({
    required String label,
    String? hint,
    IconData? prefixIcon,
    Widget? suffix,
    Color focusColor = skyBlueDark,
  }) {
    return InputDecoration(
      labelText: label,
      hintText: hint,
      floatingLabelBehavior: FloatingLabelBehavior.auto,
      labelStyle: const TextStyle(
        fontFamily: 'Poppins',
        color: textHint,
        fontSize: 14,
        fontWeight: FontWeight.w400,
      ),
      hintStyle: const TextStyle(
        fontFamily: 'Poppins',
        color: textHint,
        fontSize: 14,
      ),
      prefixIcon: prefixIcon != null
          ? Icon(prefixIcon, color: textHint, size: 20)
          : null,
      suffixIcon: suffix,
      filled: true,
      fillColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(radiusSM),
        borderSide: const BorderSide(color: borderLight, width: 1),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(radiusSM),
        borderSide: const BorderSide(color: borderLight, width: 1),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(radiusSM),
        borderSide: BorderSide(color: focusColor, width: 2),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(radiusSM),
        borderSide: const BorderSide(color: statusRed, width: 1.5),
      ),
    );
  }

  // ──────────── Gradient Helpers ────────────

  /// The app's primary periwinkle gradient
  static const LinearGradient primaryGradient = LinearGradient(
    colors: [periwinkle, periwinkleDark],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  /// Login / splash sky-blue gradient
  static const LinearGradient loginGradient = LinearGradient(
    colors: [skyBlue, skyBlueDark],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  // ──────────── Empty State ────────────

  /// Consistent empty state placeholder
  static Widget emptyState(
    BuildContext context, {
    required IconData icon,
    required String title,
    String? subtitle,
    Widget? action,
  }) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(40),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: periwinkle.withOpacity(0.08),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 44, color: periwinkle.withOpacity(0.35)),
            ),
            const SizedBox(height: 20),
            Text(
              title,
              style: heading(context).copyWith(
                color:
                    Theme.of(context).colorScheme.onSurface.withOpacity(0.55),
              ),
              textAlign: TextAlign.center,
            ),
            if (subtitle != null) ...[
              const SizedBox(height: 8),
              Text(
                subtitle,
                style: body(context),
                textAlign: TextAlign.center,
              ),
            ],
            if (action != null) ...[const SizedBox(height: 20), action],
          ],
        ),
      ),
    );
  }
}
