import 'dart:async';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/services.dart';

/// Performance utilities for Flutter app optimization
/// Provides shader warming, image caching, debouncing, and animation helpers
class PerformanceUtils {
  static final PerformanceUtils _instance = PerformanceUtils._internal();
  factory PerformanceUtils() => _instance;
  PerformanceUtils._internal();

  bool _shadersWarmed = false;
  final Map<String, ImageInfo?> _imageCache = {};
  final Map<String, Timer> _debounceTimers = {};

  /// Check if shaders have been warmed
  bool get shadersWarmed => _shadersWarmed;

  // ==================== SHADER WARMING ====================

  /// Warm up common shaders by drawing common shapes
  /// Call this during splash screen or app initialization
  Future<void> warmUpShaders(BuildContext context) async {
    if (_shadersWarmed) return;

    try {
      final recorder = ui.PictureRecorder();
      final canvas = Canvas(recorder);
      final size = MediaQuery.of(context).size;

      // Draw common shapes to compile shaders
      final paint = Paint()
        ..color = const Color(0xFF6868AC)
        ..style = PaintingStyle.fill;

      // Rounded rectangles (common in cards, buttons)
      canvas.drawRRect(
        RRect.fromRectAndRadius(
          Rect.fromLTWH(0, 0, size.width * 0.8, 100),
          const Radius.circular(12),
        ),
        paint,
      );

      // Shadows (common in elevated widgets)
      canvas.drawShadow(
        Path()
          ..addRRect(RRect.fromRectAndRadius(
            Rect.fromLTWH(0, 0, size.width * 0.8, 100),
            const Radius.circular(12),
          )),
        Colors.black,
        4.0,
        true,
      );

      // Circles (common in avatars, FABs)
      canvas.drawCircle(Offset(size.width / 2, 50), 30, paint);

      // Lines (common in dividers, borders)
      paint.style = PaintingStyle.stroke;
      paint.strokeWidth = 1;
      canvas.drawLine(Offset.zero, Offset(size.width, 0), paint);

      // Gradient paint (common in many UI elements)
      final gradientPaint = Paint()
        ..shader = const LinearGradient(
          colors: [Color(0xFF6868AC), Colors.purple],
        ).createShader(Rect.fromLTWH(0, 0, size.width, 100));
      canvas.drawRect(Rect.fromLTWH(0, 0, size.width, 100), gradientPaint);

      final picture = recorder.endRecording();
      await picture.toImage(size.width.toInt(), size.height.toInt());
      picture.dispose();

      _shadersWarmed = true;
      debugPrint('✅ Shaders warmed successfully');
    } catch (e) {
      debugPrint('⚠️ Shader warming failed: $e');
    }
  }

  // ==================== IMAGE CACHING ====================

  /// Precache images for smooth loading
  Future<void> precacheImages(
      BuildContext context, List<String> assetPaths) async {
    final futures = <Future>[];
    for (final path in assetPaths) {
      futures.add(
        precacheImage(AssetImage(path), context).catchError((e) {
          debugPrint('⚠️ Failed to precache image: $path');
        }),
      );
    }
    await Future.wait(futures);
    debugPrint('✅ Precached ${assetPaths.length} images');
  }

  /// Get cached image info or load and cache
  Future<ImageInfo?> getCachedImage(String path, ImageProvider provider) async {
    if (_imageCache.containsKey(path)) {
      return _imageCache[path];
    }

    try {
      final completer = Completer<ImageInfo?>();
      final stream = provider.resolve(ImageConfiguration.empty);
      stream.addListener(ImageStreamListener(
        (info, _) {
          _imageCache[path] = info;
          if (!completer.isCompleted) completer.complete(info);
        },
        onError: (e, _) {
          if (!completer.isCompleted) completer.complete(null);
        },
      ));
      return await completer.future.timeout(
        const Duration(seconds: 5),
        onTimeout: () => null,
      );
    } catch (e) {
      return null;
    }
  }

  /// Clear image cache
  void clearImageCache() {
    _imageCache.clear();
    PaintingBinding.instance.imageCache.clear();
  }

  // ==================== DEBOUNCING ====================

  /// Debounce function calls to reduce unnecessary executions
  void debounce(String key, Duration duration, VoidCallback action) {
    _debounceTimers[key]?.cancel();
    _debounceTimers[key] = Timer(duration, action);
  }

  /// Cancel a specific debounce timer
  void cancelDebounce(String key) {
    _debounceTimers[key]?.cancel();
    _debounceTimers.remove(key);
  }

  /// Cancel all debounce timers
  void cancelAllDebounce() {
    for (final timer in _debounceTimers.values) {
      timer.cancel();
    }
    _debounceTimers.clear();
  }

  // ==================== ANIMATION OPTIMIZATION ====================

  /// Optimized animation curves for 60fps performance
  static const Curve fastOutSlowIn = Curves.fastOutSlowIn;
  static const Curve easeOutQuint = Cubic(0.22, 1.0, 0.36, 1.0);
  static const Curve easeOutExpo = Cubic(0.16, 1.0, 0.3, 1.0);
  static const Curve smoothStep = Cubic(0.4, 0.0, 0.2, 1.0);

  /// Recommended animation durations for smooth 60fps
  static const Duration ultraFast = Duration(milliseconds: 100);
  static const Duration fast = Duration(milliseconds: 200);
  static const Duration normal = Duration(milliseconds: 300);
  static const Duration slow = Duration(milliseconds: 400);

  /// Check if running on low-end device (reduces animations)
  static bool isLowEndDevice(BuildContext context) {
    // Simple heuristic based on device pixel ratio and screen size
    final mq = MediaQuery.of(context);
    final screenDiagonal =
        (mq.size.width * mq.size.width + mq.size.height * mq.size.height);
    // Very low resolution suggests older device
    return screenDiagonal < 500000;
  }

  /// Get recommended animation duration based on device capability
  static Duration getAdaptiveDuration(BuildContext context, Duration base) {
    if (isLowEndDevice(context)) {
      // Reduce animation duration on low-end devices
      return Duration(milliseconds: (base.inMilliseconds * 0.5).round());
    }
    return base;
  }

  // ==================== FRAME SCHEDULING ====================

  /// Schedule work after frame is rendered (prevents jank)
  static void scheduleAfterFrame(VoidCallback callback) {
    SchedulerBinding.instance.addPostFrameCallback((_) {
      callback();
    });
  }

  /// Schedule heavy work during idle time
  static Future<T> scheduleWhenIdle<T>(Future<T> Function() work) async {
    // Add small delay to let current frame complete
    await Future.delayed(const Duration(milliseconds: 16));
    return work();
  }

  // ==================== HAPTIC FEEDBACK ====================

  /// Light haptic feedback for button taps
  static void lightHaptic() {
    HapticFeedback.lightImpact();
  }

  /// Medium haptic feedback for important actions
  static void mediumHaptic() {
    HapticFeedback.mediumImpact();
  }

  /// Selection haptic for toggles and selections
  static void selectionHaptic() {
    HapticFeedback.selectionClick();
  }

  // ==================== THROTTLING ====================

  static final Map<String, DateTime> _throttleTimestamps = {};

  /// Throttle function calls to at most once per duration
  static bool throttle(String key, Duration duration) {
    final now = DateTime.now();
    final lastCall = _throttleTimestamps[key];
    if (lastCall == null || now.difference(lastCall) >= duration) {
      _throttleTimestamps[key] = now;
      return true;
    }
    return false;
  }

  // ==================== TEXT INPUT OPTIMIZATION ====================

  /// Optimized text input delay for smooth 60fps typing
  static const Duration textInputDebounce = Duration(milliseconds: 100);

  /// Delay for search/filter operations while typing
  static const Duration searchDebounce = Duration(milliseconds: 300);

  // ==================== NETWORK REQUEST OPTIMIZATION ====================

  /// Default timeout for API calls to prevent UI blocking
  static const Duration apiTimeout = Duration(seconds: 10);

  /// Quick ping timeout
  static const Duration pingTimeout = Duration(seconds: 3);

  // ==================== SCROLL OPTIMIZATION ====================

  /// Create optimized scroll physics for 60fps
  static const ScrollPhysics smoothScrollPhysics = BouncingScrollPhysics(
    parent: AlwaysScrollableScrollPhysics(),
  );

  /// Clamping scroll physics for lists
  static const ScrollPhysics clampingScrollPhysics = ClampingScrollPhysics(
    parent: AlwaysScrollableScrollPhysics(),
  );
}

/// Mixin for optimized animations in StatefulWidgets
mixin OptimizedAnimationMixin<T extends StatefulWidget>
    on State<T>, TickerProviderStateMixin<T> {
  /// Create an optimized animation controller
  AnimationController createOptimizedController({
    required Duration duration,
    double lowerBound = 0.0,
    double upperBound = 1.0,
  }) {
    return AnimationController(
      duration: duration,
      lowerBound: lowerBound,
      upperBound: upperBound,
      vsync: this,
    );
  }

  /// Create a curved animation with optimized curve
  Animation<double> createCurvedAnimation(
    AnimationController controller, {
    Curve curve = PerformanceUtils.fastOutSlowIn,
    Curve? reverseCurve,
  }) {
    return CurvedAnimation(
      parent: controller,
      curve: curve,
      reverseCurve: reverseCurve ?? curve.flipped,
    );
  }
}

/// Optimized page route with faster transitions
class OptimizedPageRoute<T> extends PageRouteBuilder<T> {
  OptimizedPageRoute({
    required Widget Function(BuildContext) builder,
    Duration duration = const Duration(milliseconds: 250),
    Curve curve = PerformanceUtils.easeOutQuint,
  }) : super(
          pageBuilder: (context, animation, secondaryAnimation) =>
              builder(context),
          transitionDuration: duration,
          reverseTransitionDuration: duration,
          transitionsBuilder: (context, animation, secondaryAnimation, child) {
            final curved = CurvedAnimation(parent: animation, curve: curve);
            return FadeTransition(
              opacity: curved,
              child: SlideTransition(
                position: Tween<Offset>(
                  begin: const Offset(0.03, 0),
                  end: Offset.zero,
                ).animate(curved),
                child: child,
              ),
            );
          },
        );
}

/// Lazy-loading list builder for better performance with large lists
class LazyListBuilder extends StatefulWidget {
  final int itemCount;
  final Widget Function(BuildContext, int) itemBuilder;
  final Widget? placeholder;
  final int initialItemCount;
  final ScrollController? controller;
  final EdgeInsets? padding;

  const LazyListBuilder({
    super.key,
    required this.itemCount,
    required this.itemBuilder,
    this.placeholder,
    this.initialItemCount = 10,
    this.controller,
    this.padding,
  });

  @override
  State<LazyListBuilder> createState() => _LazyListBuilderState();
}

class _LazyListBuilderState extends State<LazyListBuilder> {
  late int _loadedCount;
  late ScrollController _scrollController;
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _loadedCount = widget.initialItemCount.clamp(0, widget.itemCount);
    _scrollController = widget.controller ?? ScrollController();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    if (widget.controller == null) {
      _scrollController.dispose();
    }
    super.dispose();
  }

  void _onScroll() {
    if (_isLoading || _loadedCount >= widget.itemCount) return;

    final maxScroll = _scrollController.position.maxScrollExtent;
    final currentScroll = _scrollController.position.pixels;
    final threshold = maxScroll * 0.8;

    if (currentScroll >= threshold) {
      _loadMore();
    }
  }

  Future<void> _loadMore() async {
    if (_isLoading) return;
    setState(() => _isLoading = true);

    // Small delay to prevent jank
    await Future.delayed(const Duration(milliseconds: 16));

    if (mounted) {
      setState(() {
        _loadedCount = (_loadedCount + 10).clamp(0, widget.itemCount);
        _isLoading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView.builder(
      controller: _scrollController,
      padding: widget.padding,
      itemCount: _loadedCount + (_loadedCount < widget.itemCount ? 1 : 0),
      itemBuilder: (context, index) {
        if (index < _loadedCount) {
          return widget.itemBuilder(context, index);
        }
        // Loading indicator at the bottom
        return widget.placeholder ??
            const Center(
              child: Padding(
                padding: EdgeInsets.all(16),
                child: SizedBox(
                  width: 24,
                  height: 24,
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              ),
            );
      },
    );
  }
}

/// RepaintBoundary wrapper for isolating expensive repaints
class IsolatedRepaint extends StatelessWidget {
  final Widget child;

  const IsolatedRepaint({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return RepaintBoundary(child: child);
  }
}

/// Optimized image widget with caching and placeholder
class OptimizedImage extends StatelessWidget {
  final ImageProvider image;
  final double? width;
  final double? height;
  final BoxFit fit;
  final Widget? placeholder;
  final Widget? errorWidget;

  const OptimizedImage({
    super.key,
    required this.image,
    this.width,
    this.height,
    this.fit = BoxFit.cover,
    this.placeholder,
    this.errorWidget,
  });

  @override
  Widget build(BuildContext context) {
    return Image(
      image: image,
      width: width,
      height: height,
      fit: fit,
      frameBuilder: (context, child, frame, wasSynchronouslyLoaded) {
        if (wasSynchronouslyLoaded) return child;
        return AnimatedSwitcher(
          duration: const Duration(milliseconds: 200),
          child: frame != null
              ? child
              : placeholder ?? Container(color: Colors.grey[200]),
        );
      },
      errorBuilder: (context, error, stackTrace) =>
          errorWidget ??
          Container(
            color: Colors.grey[200],
            child: const Icon(Icons.broken_image, color: Colors.grey),
          ),
    );
  }
}
