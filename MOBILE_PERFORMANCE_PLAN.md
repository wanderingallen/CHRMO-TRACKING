# Mobile Performance & Stability Improvement Plan

## 1. Network & API Layer

### 1.1 Retry & Timeout Strategy

- Add exponential backoff retry (3 attempts) for all HTTP calls
- Standardize timeouts: 10s for reads, 30s for uploads, 6s for background pings
- Show user-friendly error messages instead of raw exceptions

### 1.2 Offline-First Caching

- Cache recent API responses (dashboard stats, notifications) in SharedPreferences with TTL
- Display cached data immediately on load, then refresh silently in background
- Queue failed uploads (tracking, OCR) locally and auto-retry when connectivity returns
- Use `connectivity_plus` to detect online/offline and show a status banner

### 1.3 Request Deduplication

- Prevent duplicate concurrent calls to the same endpoint (e.g., double-tap on upload)
- Use a loading flag or `Completer` per request to coalesce identical calls

---

## 2. Image & File Handling

### 2.1 Image Compression Before Upload

- Compress camera captures before uploading (target: 1280px longest side, quality 80)
- Use `flutter_image_compress` to reduce file size by 60-80%
- Show original vs. compressed size to user

### 2.2 Thumbnail Caching

- Generate small thumbnails (100x100) for gallery list items
- Cache thumbnails in app temp directory to avoid re-reading full images
- Use `ImageCache` with appropriate size limits

### 2.3 Lazy Loading in Gallery

- Load only visible items + 5 buffer items in the gallery list
- Use `ListView.builder` with `addAutomaticKeepAlives: false` (already partially done)
- Defer image loading until the tile is on-screen

---

## 3. OCR Processing

### 3.1 Background Isolate for OCR Parsing

- Move heavy text parsing (`_extractKeys`, regex matching) to a separate `Isolate`
- Prevents UI jank during post-scan processing
- Use `compute()` for simple parallel tasks

### 3.2 Incremental Key Extraction

- Parse extracted OCR text progressively (show partial results as they come in)
- Prioritize high-value fields (name, date, doc type) first

---

## 4. Memory Management

### 4.1 Dispose Resources Properly

- Ensure all `ScrollController`, `TabController`, `TextEditingController`, `AnimationController` are disposed in `dispose()`
- Audit all StatefulWidgets for missing dispose calls
- Cancel any pending `Timer` or `Future` on page dispose

### 4.2 Image Memory Limits

- Set `PaintingBinding.instance.imageCache.maximumSizeBytes` to 100MB
- Evict large images from cache after navigating away from detail pages
- Avoid holding multiple full-resolution images in memory simultaneously

### 4.3 List Optimization

- Use `AutomaticKeepAliveClientMixin` only where scroll position must be preserved
- Add `key: ValueKey(...)` to list items to help Flutter diff efficiently
- Limit notification list to 100 items in memory; paginate server-side

---

## 5. Startup Performance

### 5.1 Deferred Loading

- Use `deferred` imports for heavy pages (camera, gallery, notification) so they load on demand
- Show a lightweight splash/skeleton while heavy pages initialize

### 5.2 Reduce Init Work

- Move SharedPreferences reads to a single batch at startup
- Cache user session data (username, department, server URL) in a singleton
- Delay non-critical initializations (notification polling, analytics) by 2-3 seconds

### 5.3 Splash Screen Optimization

- Use `flutter_native_splash` (already present) — ensure it doesn't block past 2s
- Transition to app content as soon as the first frame renders

---

## 6. Error Handling & Crash Prevention

### 6.1 Global Error Boundaries

- Wrap the entire app in `FlutterError.onError` + `PlatformDispatcher.instance.onError`
- Log crashes to a local file for debugging
- Show a user-friendly fallback UI instead of red error screens

### 6.2 Null Safety Guards

- Audit all API response parsing for null/missing fields
- Use `?.` and `?? defaultValue` consistently
- Validate JSON structure before accessing nested fields

### 6.3 Permission Handling

- Check camera/storage permissions before attempting operations
- Show clear permission request dialogs with explanations
- Gracefully handle permission denial without crashing

---

## 7. Database & Local Storage

### 7.1 SQLite for Local Archive

- Consider migrating gallery metadata from JSON files to SQLite (`sqflite`)
- Enables faster queries, sorting, and filtering on large archives
- Supports full-text search on OCR content

### 7.2 Batch Operations

- Group multiple file operations (rename, delete) into single transactions
- Avoid individual setState() calls for each file — batch state updates

---

## 8. UI Performance

### 8.1 Reduce Rebuilds

- Use `const` constructors wherever possible (already partially done)
- Extract frequently rebuilt widgets into separate StatelessWidget classes
- Use `RepaintBoundary` around expensive widgets (image thumbnails, charts)

### 8.2 Animation Performance

- Ensure animations use `AnimatedContainer` / `AnimatedSwitcher` (already done)
- Avoid `setState` during animations — use `AnimationController` with `addListener`
- Profile with Flutter DevTools to find jank frames

### 8.3 Build Optimization

- Enable `--release` mode testing regularly to catch debug-only performance issues
- Use `profile` mode with DevTools to identify >16ms frames
- Consider `RepaintBoundary` around the camera preview widget

---

## Priority Implementation Order

| Priority | Item                           | Impact                     | Effort |
| -------- | ------------------------------ | -------------------------- | ------ |
| **P0**   | 6.1 Global error boundaries    | Crash prevention           | Low    |
| **P0**   | 1.1 Retry & timeout strategy   | Network reliability        | Medium |
| **P0**   | 4.1 Dispose resources properly | Memory leaks               | Low    |
| **P1**   | 2.1 Image compression          | Upload speed, data usage   | Medium |
| **P1**   | 1.2 Offline-first caching      | User experience            | Medium |
| **P1**   | 5.2 Reduce init work           | Startup speed              | Low    |
| **P2**   | 2.2 Thumbnail caching          | Gallery scroll performance | Medium |
| **P2**   | 3.1 Background isolate for OCR | UI responsiveness          | Medium |
| **P2**   | 7.1 SQLite for local archive   | Scale & search             | High   |
| **P3**   | 8.1 Reduce rebuilds            | UI smoothness              | Medium |
| **P3**   | 5.1 Deferred loading           | Startup speed              | Low    |

---

## Quick Wins (can implement now)

1. **Add `try-catch` around all HTTP calls** with user-friendly fallback messages
2. **Set explicit image cache limits** in `main.dart`
3. **Add `const` to all static widgets** that don't depend on runtime state
4. **Cancel pending HTTP calls** in `dispose()` using `http.Client` instead of top-level functions
5. **Add loading debounce** to prevent double-tap uploads (300ms cooldown)
