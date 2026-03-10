# Performance Optimization Guide for PHP Pages

## Summary of Changes Applied

### 1. Session Lock Release (Completed) ✓
Added `session_write_close()` after session checks in all five PHP pages:
- `dashboard.php`
- `archive.php`
- `stats.php`
- `tracking.php`
- `usercontrol.php`

**Why**: When a request holds an open session, concurrent requests from the same user are blocked waiting for the session to close. By calling `session_write_close()` after reading/modifying session data, we release the lock immediately, allowing concurrent requests to proceed without blocking each other. This is especially important when pages make AJAX calls or have multiple concurrent operations.

**Impact**: Eliminates session contention; multiple concurrent requests from the same user no longer block each other.

---

### 2. Removed Runtime DDL (Completed) ✓
Moved schema changes from `tracking.php` request path to one-time migration scripts:
- Removed: `ALTER TABLE tracking ADD COLUMN doc_hash...`
- Removed: `CREATE TABLE IF NOT EXISTS document_history...`
- Removed: Dynamic schema checks run on every page load

**Why**: Running DDL (ALTER TABLE, CREATE TABLE) on every request is expensive and unnecessary. Schema changes should be run once during deployment, not on every page load.

**Files Created**:
- `tools/migrate_tracking_schema.php` — Run once to add doc_hash column, index, and document_history table.
- `tools/migrate_add_indexes.php` — Run once to add performance indexes on frequently-queried columns.

**How to Run**:
```bash
# Option 1: Via CLI
php tools/migrate_tracking_schema.php
php tools/migrate_add_indexes.php

# Option 2: Via browser
http://yourserver/path/to/tools/migrate_tracking_schema.php
http://yourserver/path/to/tools/migrate_add_indexes.php
```

---

## Next Steps (To Be Implemented)

### Quick Wins (High Impact)

1. **Enable OPcache** (if not already enabled)
   - Check current status: Open `http://localhost/phpinfo.php` in browser or run `php -i | grep opcache`
   - If disabled, edit `c:\xampp\php\php.ini`:
     - Set `opcache.enable=1`
     - Set `opcache.memory_consumption=128` (or higher)
     - Restart Apache
   - **Expected impact**: 2–5x faster page execution time

2. **Run Migration Scripts** (Already created, needs execution)
   - Execute `tools/migrate_tracking_schema.php` once to set up schema
   - Execute `tools/migrate_add_indexes.php` once to add indexes
   - **Expected impact**: 5–50x faster queries (depending on table size)

3. **Replace Full-Table Scans with SQL Aggregates**
   - Instead of `SELECT *` then looping in PHP, use SQL to do aggregation/filtering
   - Examples:
     ```php
     // BAD: Loads all rows into memory
     $sql = "SELECT status FROM tracking";
     $result = $connection->query($sql);
     while ($row = $result->fetch_assoc()) {
         // ... PHP-side filtering, counting, randomization
     }
     
     // GOOD: SQL does the aggregation
     $sql = "SELECT COUNT(*) as count FROM tracking WHERE status = 'Pending'";
     $result = $connection->query($sql);
     ```

4. **Add Pagination to List Pages**
   - Archive, Tracking pages load all records into memory
   - Implement pagination: `LIMIT 50 OFFSET 0` to show 50 records per page
   - **Expected impact**: Faster page loads, reduced memory usage

---

### Medium-Term Improvements

1. **Cache Expensive Charts/Aggregates**
   - Store computed results in APCu or Redis with TTL (5–30 minutes)
   - Refresh cache on INSERT/UPDATE rather than recalculating
   - Applies to: dashboard charts, stats endpoints

2. **Move Heavy Business Logic to SQL**
   - Date parsing, status randomization, department normalization — move from PHP to SQL
   - Example: `DATE()`, `UPPER(TRIM())`, `CASE` statements

3. **Implement Query Logging**
   - Enable MySQL slow query log to identify slowest queries
   - In `my.ini`: `slow_query_log=1`, `long_query_time=0.5`
   - Then optimize the top slow queries with EXPLAIN

---

### Long-Term Monitoring

1. **Profile with Xdebug or Blackfire** (for exact bottlenecks)
   - Set up Xdebug trace collection or use Blackfire.io for production-safe profiling
   - Identify which functions consume most time

2. **Database Tuning**
   - Monitor `innodb_buffer_pool_size` — increase if < 50% hit rate
   - Check for missing indexes with SHOW VARIABLES LIKE 'slow_query_log'

3. **Content Delivery Optimization** (Client-side, low priority)
   - Enable gzip compression for responses
   - Add HTTP cache headers for static assets
   - Minify CSS/JS

---

## Environment Information Gathered

- **PHP Version**: 8.2.12 (CLI)
- **SAPI**: Command Line Interface (CLI) — Apache/mod_php likely for web
- **OPcache**: Unknown (need to check phpinfo or php.ini)
- **Xdebug**: Not active
- **MySQL**: Not found in PATH (XAMPP default, version unknown)
- **Slow Query Log**: Unknown (need to check my.ini)

---

## Files Modified

1. `lib/OCR(UPDATED)/dashboard.php` — Added session_write_close()
2. `lib/OCR(UPDATED)/archive.php` — Added session_write_close()
3. `lib/OCR(UPDATED)/stats.php` — Added session_write_close()
4. `lib/OCR(UPDATED)/tracking.php` — Added session_write_close() + removed DDL
5. `lib/OCR(UPDATED)/usercontrol.php` — Added session_write_close()

## Files Created (Migrations)

1. `tools/migrate_tracking_schema.php` — Schema migration script
2. `tools/migrate_add_indexes.php` — Index creation script

---

## Performance Expectations

### Current State (Baseline)
- Pages loading slowly due to session locks, missing indexes, full-table scans, runtime DDL

### After Session Lock Release
- **Improvement**: 10–20% faster for concurrent requests (AJAX, async loads)

### After Running Migrations (Schema + Indexes)
- **Improvement**: 5–50x faster for queries (depends on table size and query complexity)

### After OPcache Enabled
- **Improvement**: 2–5x faster page execution time overall

### After Query Optimization (removing full-table scans)
- **Improvement**: 2–10x faster for high-volume operations

**Total Expected Improvement**: 10–100x faster page loads (varies by operation)

---

## Recommended Immediate Action Plan

1. ✅ **Done**: Added session_write_close() to all five PHP pages
2. ✅ **Done**: Created migration scripts (move DDL from request path)
3. **TODO**: Run the two migration scripts (`migrate_tracking_schema.php` and `migrate_add_indexes.php`)
4. **TODO**: Verify OPcache is enabled; enable if not
5. **TODO**: Measure page load times before/after changes

---

## Testing After Changes

1. Open each page in browser and note load times (use browser DevTools Network tab)
2. Run load test or multiple concurrent requests
3. Check MySQL slow_query_log for any remaining slow queries
4. Verify no errors in PHP logs

---

For questions or further optimization, refer to the inline comments in the migration scripts or the README.md in the tools directory.
