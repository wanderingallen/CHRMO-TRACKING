import 'package:flutter/material.dart';

/// Reusable skeleton loading widgets for CHRMO Document Tracker
/// Provides shimmer/pulse animations for loading states
class SkeletonLoading {
  /// Standard shimmer gradient for skeleton items
  static LinearGradient get shimmerGradient => LinearGradient(
        colors: [
          Colors.grey.shade300,
          Colors.grey.shade100,
          Colors.grey.shade300,
        ],
        stops: const [0.0, 0.5, 1.0],
        begin: const Alignment(-1.0, -0.3),
        end: const Alignment(1.0, 0.3),
      );

  /// Skeleton container with pulse animation
  static Widget box({
    double? width,
    double? height,
    double borderRadius = 8,
    EdgeInsets? margin,
  }) {
    return Container(
      width: width,
      height: height,
      margin: margin,
      decoration: BoxDecoration(
        color: Colors.grey.shade200,
        borderRadius: BorderRadius.circular(borderRadius),
      ),
    );
  }

  /// Skeleton for a single line of text
  static Widget textLine({
    double width = double.infinity,
    double height = 14,
    EdgeInsets margin = const EdgeInsets.only(bottom: 8),
  }) {
    return Container(
      width: width,
      height: height,
      margin: margin,
      decoration: BoxDecoration(
        color: Colors.grey.shade200,
        borderRadius: BorderRadius.circular(4),
      ),
    );
  }

  /// Skeleton for avatar/icon circle
  static Widget circle({double size = 48}) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: Colors.grey.shade200,
        shape: BoxShape.circle,
      ),
    );
  }
}

/// Animated shimmer effect wrapper
class ShimmerEffect extends StatefulWidget {
  final Widget child;
  final Duration duration;

  const ShimmerEffect({
    super.key,
    required this.child,
    this.duration = const Duration(milliseconds: 1500),
  });

  @override
  State<ShimmerEffect> createState() => _ShimmerEffectState();
}

class _ShimmerEffectState extends State<ShimmerEffect>
    with SingleTickerProviderStateMixin {
  late AnimationController _controller;
  late Animation<double> _animation;

  @override
  void initState() {
    super.initState();
    _controller = AnimationController(vsync: this, duration: widget.duration);
    _animation = Tween<double>(begin: 0.4, end: 1.0).animate(
      CurvedAnimation(parent: _controller, curve: Curves.easeInOut),
    );
    _controller.repeat(reverse: true);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _animation,
      builder: (context, child) => Opacity(
        opacity: _animation.value,
        child: widget.child,
      ),
    );
  }
}

/// Skeleton card for document list items
class DocumentCardSkeleton extends StatelessWidget {
  const DocumentCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return ShimmerEffect(
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          boxShadow: const [
            BoxShadow(
                color: Colors.black12, blurRadius: 4, offset: Offset(0, 2))
          ],
        ),
        child: Row(
          children: [
            SkeletonLoading.circle(size: 48),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SkeletonLoading.textLine(width: 180, height: 14),
                  const SizedBox(height: 4),
                  SkeletonLoading.textLine(width: double.infinity, height: 12),
                  const SizedBox(height: 4),
                  SkeletonLoading.textLine(width: 120, height: 10),
                ],
              ),
            ),
            const SizedBox(width: 12),
            SkeletonLoading.box(width: 60, height: 24, borderRadius: 12),
          ],
        ),
      ),
    );
  }
}

/// Skeleton card for notifications
class NotificationCardSkeleton extends StatelessWidget {
  const NotificationCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return ShimmerEffect(
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          boxShadow: const [BoxShadow(color: Colors.black12, blurRadius: 4)],
        ),
        child: Row(
          children: [
            SkeletonLoading.circle(size: 44),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SkeletonLoading.textLine(width: 160, height: 12),
                  const SizedBox(height: 10),
                  SkeletonLoading.textLine(width: double.infinity, height: 10),
                  const SizedBox(height: 6),
                  SkeletonLoading.textLine(width: 100, height: 10),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Skeleton for KPI/stats cards on dashboard
class KpiCardSkeleton extends StatelessWidget {
  const KpiCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return ShimmerEffect(
      child: Container(
        width: 100,
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: const [
            BoxShadow(
                color: Colors.black12, blurRadius: 6, offset: Offset(0, 2))
          ],
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SkeletonLoading.circle(size: 40),
            const SizedBox(height: 12),
            SkeletonLoading.textLine(width: 50, height: 20),
            const SizedBox(height: 8),
            SkeletonLoading.textLine(width: 70, height: 12),
          ],
        ),
      ),
    );
  }
}

/// Skeleton for activity/history list items
class ActivityItemSkeleton extends StatelessWidget {
  const ActivityItemSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return ShimmerEffect(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
        child: Row(
          children: [
            SkeletonLoading.circle(size: 36),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SkeletonLoading.textLine(width: 200, height: 13),
                  const SizedBox(height: 6),
                  SkeletonLoading.textLine(width: 140, height: 11),
                ],
              ),
            ),
            SkeletonLoading.textLine(width: 60, height: 10),
          ],
        ),
      ),
    );
  }
}

/// Skeleton for routing/received document cards (with action buttons)
class RoutingCardSkeleton extends StatelessWidget {
  const RoutingCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return ShimmerEffect(
      child: Container(
        margin: const EdgeInsets.only(bottom: 16),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.grey.shade200),
          boxShadow: [
            BoxShadow(
                color: Colors.black.withOpacity(0.08),
                blurRadius: 8,
                offset: const Offset(0, 2))
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header row
            Row(
              children: [
                SkeletonLoading.circle(size: 40),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      SkeletonLoading.textLine(width: 150, height: 14),
                      const SizedBox(height: 6),
                      SkeletonLoading.textLine(width: 100, height: 11),
                    ],
                  ),
                ),
                SkeletonLoading.box(width: 70, height: 24, borderRadius: 12),
              ],
            ),
            const SizedBox(height: 16),
            // Content lines
            SkeletonLoading.textLine(width: double.infinity, height: 12),
            SkeletonLoading.textLine(width: 200, height: 12),
            const SizedBox(height: 16),
            // Action buttons row
            Row(
              children: [
                Expanded(
                    child: SkeletonLoading.box(height: 40, borderRadius: 20)),
                const SizedBox(width: 12),
                Expanded(
                    child: SkeletonLoading.box(height: 40, borderRadius: 20)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Skeleton list builder for lazy loading
class SkeletonListView extends StatelessWidget {
  final int itemCount;
  final Widget Function(BuildContext, int) skeletonBuilder;
  final EdgeInsets padding;

  const SkeletonListView({
    super.key,
    this.itemCount = 5,
    required this.skeletonBuilder,
    this.padding = const EdgeInsets.all(16),
  });

  @override
  Widget build(BuildContext context) {
    return ListView.builder(
      padding: padding,
      physics: const NeverScrollableScrollPhysics(),
      shrinkWrap: true,
      itemCount: itemCount,
      itemBuilder: skeletonBuilder,
    );
  }
}

/// Gallery image skeleton
class GalleryImageSkeleton extends StatelessWidget {
  const GalleryImageSkeleton({super.key});

  @override
  Widget build(BuildContext context) {
    return ShimmerEffect(
      child: Container(
        decoration: BoxDecoration(
          color: Colors.grey.shade200,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          children: [
            Expanded(
              child: Container(
                decoration: BoxDecoration(
                  color: Colors.grey.shade300,
                  borderRadius:
                      const BorderRadius.vertical(top: Radius.circular(12)),
                ),
              ),
            ),
            Container(
              padding: const EdgeInsets.all(8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SkeletonLoading.textLine(width: double.infinity, height: 12),
                  const SizedBox(height: 4),
                  SkeletonLoading.textLine(width: 80, height: 10),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Overdue badge widget for documents
class OverdueBadge extends StatelessWidget {
  final int daysOverdue;
  final bool showIcon;

  const OverdueBadge({
    super.key,
    required this.daysOverdue,
    this.showIcon = true,
  });

  @override
  Widget build(BuildContext context) {
    if (daysOverdue <= 0) return const SizedBox.shrink();

    final Color bgColor;
    final Color textColor;
    final String label;

    // With the new SLA, we start surfacing badges at ~4–5 days.
    // `daysOverdue` here represents "days in department" once it crosses threshold.
    if (daysOverdue >= 10) {
      bgColor = Colors.red.shade100;
      textColor = Colors.red.shade800;
      label = 'Critical: ${daysOverdue}d';
    } else if (daysOverdue >= 5) {
      bgColor = Colors.orange.shade100;
      textColor = Colors.orange.shade800;
      label = 'Overdue: ${daysOverdue}d';
    } else {
      bgColor = Colors.amber.shade100;
      textColor = Colors.amber.shade800;
      label = 'Due soon: ${daysOverdue}d';
    }

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (showIcon) ...[
            Icon(Icons.warning_amber_rounded, size: 14, color: textColor),
            const SizedBox(width: 4),
          ],
          Text(
            label,
            style: TextStyle(
              fontSize: 11,
              fontWeight: FontWeight.w600,
              color: textColor,
            ),
          ),
        ],
      ),
    );
  }
}

/// Calculate overdue days from a date
int calculateOverdueDays(DateTime? createdAt, {int thresholdDays = 4}) {
  if (createdAt == null) return 0;
  final now = DateTime.now();
  final diff = now.difference(createdAt).inDays;
  // Return total age (days in dept) once it crosses the threshold.
  return diff >= thresholdDays ? diff : 0;
}

/// Parse date string to DateTime
DateTime? parseDocumentDate(dynamic dateValue) {
  if (dateValue == null) return null;
  if (dateValue is DateTime) return dateValue;
  if (dateValue is int) {
    return DateTime.fromMillisecondsSinceEpoch(dateValue);
  }
  if (dateValue is String) {
    try {
      return DateTime.parse(dateValue);
    } catch (_) {
      return null;
    }
  }
  return null;
}
