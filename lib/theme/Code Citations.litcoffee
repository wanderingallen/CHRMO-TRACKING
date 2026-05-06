# Code Citations

## License: MIT

https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

`````


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset
`````

## License: MIT

https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

`````


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset
`````

## License: MIT

https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

`````


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset
`````

## License: MIT

https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

`````


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset
`````

## License: MIT

https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

`````


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset
`````

## License: MIT

https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

`````


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset
`````

## License: MIT

https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

`````


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset($user['department']) ? $user['department'] : '';
// ...existing code...
`````

Now let me apply the department filter in tracking.php's `get_documents` handler:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// Near the top of the get_documents AJAX handler, after building the base query and before executing:
// Find the section that handles action=get_documents

if ($action === 'get_documents') {
    // ...existing code that builds the query...

    // ── Department-scoped filtering for department_user ──
    $isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($isDeptUser && $userDept !== '') {
        // Only show documents where the user's department is involved:
        // 1. current_department matches
        // 2. origin_department matches
        // 3. department appears in route_trail JSON
        $deptFilter = $conn->real_escape_string($userDept);
        $conditions[] = "(
            d.current_department = '$deptFilter'
            OR d.origin_department = '$deptFilter'
            OR d.route_trail LIKE '%\"$deptFilter\"%'
        )";
    }

    // ...existing code that assembles WHERE clause from $conditions...
```

Let me find the exact location in tracking.php where the query is built so I can place this precisely. Let me read the get_documents handler in detail:

Let me find more of the get_documents logic:

Let me continue reading:

Now I can see the exact structure. The `get_documents` handler:

1. Starts around the PHP AJAX section
2. Uses `$conditions[]` array to build WHERE clauses
3. Joins conditions with AND

Let me find the exact lines where `$conditions` is initialized and where the WHERE clause is assembled:

Now I have the full picture. Let me apply the fix precisely. The `$conditions` array is built up and then joined. I need to add the department filter right before the WHERE clause is assembled.

Let me also check what columns exist in the documents table:

Now let me see the full query assembly to find the exact insertion point:

Let me check if `origin_department` column exists or if it's called something else:

Good — the documents table has `current_department` and the route is tracked in `route_trail`. There's no `origin_department` column. Let me check what fields are actually in the documents table:

Now I have everything. The documents table has:

- `current_department` — where the document currently is
- `route_trail` — JSON array tracking the routing history
- `status` — current status

For a department user with department "CTO", a document should only appear if:

1. `current_department = 'CTO'` (document is currently at CTO), OR
2. CTO appears in the `route_trail` JSON (document has been through CTO)

This means:

- If HR routes to CBO, CTO does NOT see it (CTO not involved)
- When CBO routes to CTO, CTO sees it (current_department = CTO)
- After CTO routes to another dept, CTO still sees it (CTO is in route_trail history)

Let me now apply the precise fix:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// In the get_documents AJAX handler, right after all existing $conditions[] are set
// and BEFORE the line that assembles the WHERE clause (e.g., $where = implode(' AND ', $conditions))

    // ── Department-scoped filtering for department_user ──
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        // Show document only if user's department is the current holder
        // OR the department appears anywhere in the route_trail history
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"department\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"to\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"from\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%$deptSafe%'
        )";
    }

// ...existing code that does: $where = implode(' AND ', $conditions); ...
```

But I need to place this in the exact right spot. Let me read the precise lines around the WHERE assembly:

Now I can see the exact structure. Let me look for where `$conditions` is first defined and where it's joined:

I need to find the complete AJAX handler. Let me search more carefully:

Let me look at the PHP block at the very top of the file:

Let me search for the actual query execution:

Let me read more PHP at the top:

I can see the structure now. The PHP AJAX handlers are at the top of the file within `if ($_SERVER['REQUEST_METHOD'] === 'POST')` blocks. Let me find the `get_documents` action specifically:

Let me look at the full AJAX section more carefully:

Now I can see the structure. Let me find `get_documents` specifically:

I can see the document fetching uses a direct query. Let me find the exact query string:

Let me look further for the SELECT query:

Now I can see the structure clearly. Let me find where the main document listing query is built:

I see references to `$statusFilter` and filtering. Let me find the complete query:

Now let me look at how the JavaScript fetches documents — it might be a different endpoint or inline PHP:

Let me look at how the table is populated - it might be fetched via fetch() calls:

Let me search for the fetch/AJAX calls in the JavaScript:

Now I can see! The JavaScript `loadDocuments()` function calls `tracking.php` with POST action `get_documents`. Let me find the PHP handler for this action at the top of the file:

I can see the routing. It calls `tracking.php` with `action: 'get_documents'`. Let me find this handler in the PHP section at the very top:

I can see actions like `update_status`, `get_document`, `get_trail`. Let me search specifically for `get_documents`:

The action is likely handled as part of a larger if/elseif chain. Let me search with a broader pattern:

Let me try to find where documents are listed/queried:

I can see `$action === 'get_documents'` exists somewhere. Let me find it:

Let me read the full PHP section systematically - it must be in the first 200 lines:

Good, now I can see the structure. Let me continue:

This is the handler. Now let me see how it builds the query with conditions:

Perfect! Now I can see the exact structure. The `get_documents` handler:

1. Line ~82: `$conditions = ["1=1"];`
2. Lines ~83-100: Various filter conditions are added
3. Then a WHERE clause is built from `$conditions`

I need to add the department filter right after the existing conditions are set (around line 100) and before the query is executed. Let me see lines 100-130:

Now I have the complete picture. Here's the exact structure:

- Line ~82: `$conditions = ["1=1"];`
- Lines 83-100: Filter conditions (type, status, department, date, search)
- Line ~105: `$where = implode(' AND ', $conditions);`
- Line ~108: Query execution

I'll add the department-user filter right before the `$where = implode(...)` line:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
    $conditions = ["1=1"];

    if (!empty($type))       $conditions[] = "d.document_type = '" . $conn->real_escape_string($type) . "'";
    if (!empty($status))     $conditions[] = "d.status = '" . $conn->real_escape_string($status) . "'";
    if (!empty($department)) $conditions[] = "d.current_department = '" . $conn->real_escape_string($department) . "'";
    if (!empty($dateFrom))   $conditions[] = "DATE(d.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
    if (!empty($dateTo))     $conditions[] = "DATE(d.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
    if (!empty($search)) {
        $s = $conn->real_escape_string($search);
        $conditions[] = "(d.title LIKE '%$s%' OR d.tracking_number LIKE '%$s%')";
    }

    // ── Department-scoped filtering for department_user ──
    // Department users only see documents where their department is involved
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';
    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"$deptSafe\"%'
        )";
    }

    $where = implode(' AND ', $conditions);
// ...existing code...
```

Now I also need to make sure the login sets `$_SESSION['user_department']`. Let me check the login handler again:

Let me see the full login query to check if it fetches the department column:

I need to ensure the login query includes the `department` column. Let me check the users table structure:

Good — the `users` table has a `department` column. Now let me check if the login query SELECTs it:

The login query uses `SELECT *`, so `$user['department']` is available. Now I just need to store it in the session. Let me find the exact session-setting lines:

Now let me apply the session fix:

`````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION
```


## License: MIT
https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

```


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset($user['department']) ? $user['department'] : '';
// ...existing code...
`````

Now let me apply the department filter in tracking.php's `get_documents` handler:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// Near the top of the get_documents AJAX handler, after building the base query and before executing:
// Find the section that handles action=get_documents

if ($action === 'get_documents') {
    // ...existing code that builds the query...

    // ── Department-scoped filtering for department_user ──
    $isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($isDeptUser && $userDept !== '') {
        // Only show documents where the user's department is involved:
        // 1. current_department matches
        // 2. origin_department matches
        // 3. department appears in route_trail JSON
        $deptFilter = $conn->real_escape_string($userDept);
        $conditions[] = "(
            d.current_department = '$deptFilter'
            OR d.origin_department = '$deptFilter'
            OR d.route_trail LIKE '%\"$deptFilter\"%'
        )";
    }

    // ...existing code that assembles WHERE clause from $conditions...
```

Let me find the exact location in tracking.php where the query is built so I can place this precisely. Let me read the get_documents handler in detail:

Let me find more of the get_documents logic:

Let me continue reading:

Now I can see the exact structure. The `get_documents` handler:

1. Starts around the PHP AJAX section
2. Uses `$conditions[]` array to build WHERE clauses
3. Joins conditions with AND

Let me find the exact lines where `$conditions` is initialized and where the WHERE clause is assembled:

Now I have the full picture. Let me apply the fix precisely. The `$conditions` array is built up and then joined. I need to add the department filter right before the WHERE clause is assembled.

Let me also check what columns exist in the documents table:

Now let me see the full query assembly to find the exact insertion point:

Let me check if `origin_department` column exists or if it's called something else:

Good — the documents table has `current_department` and the route is tracked in `route_trail`. There's no `origin_department` column. Let me check what fields are actually in the documents table:

Now I have everything. The documents table has:

- `current_department` — where the document currently is
- `route_trail` — JSON array tracking the routing history
- `status` — current status

For a department user with department "CTO", a document should only appear if:

1. `current_department = 'CTO'` (document is currently at CTO), OR
2. CTO appears in the `route_trail` JSON (document has been through CTO)

This means:

- If HR routes to CBO, CTO does NOT see it (CTO not involved)
- When CBO routes to CTO, CTO sees it (current_department = CTO)
- After CTO routes to another dept, CTO still sees it (CTO is in route_trail history)

Let me now apply the precise fix:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// In the get_documents AJAX handler, right after all existing $conditions[] are set
// and BEFORE the line that assembles the WHERE clause (e.g., $where = implode(' AND ', $conditions))

    // ── Department-scoped filtering for department_user ──
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        // Show document only if user's department is the current holder
        // OR the department appears anywhere in the route_trail history
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"department\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"to\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"from\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%$deptSafe%'
        )";
    }

// ...existing code that does: $where = implode(' AND ', $conditions); ...
```

But I need to place this in the exact right spot. Let me read the precise lines around the WHERE assembly:

Now I can see the exact structure. Let me look for where `$conditions` is first defined and where it's joined:

I need to find the complete AJAX handler. Let me search more carefully:

Let me look at the PHP block at the very top of the file:

Let me search for the actual query execution:

Let me read more PHP at the top:

I can see the structure now. The PHP AJAX handlers are at the top of the file within `if ($_SERVER['REQUEST_METHOD'] === 'POST')` blocks. Let me find the `get_documents` action specifically:

Let me look at the full AJAX section more carefully:

Now I can see the structure. Let me find `get_documents` specifically:

I can see the document fetching uses a direct query. Let me find the exact query string:

Let me look further for the SELECT query:

Now I can see the structure clearly. Let me find where the main document listing query is built:

I see references to `$statusFilter` and filtering. Let me find the complete query:

Now let me look at how the JavaScript fetches documents — it might be a different endpoint or inline PHP:

Let me look at how the table is populated - it might be fetched via fetch() calls:

Let me search for the fetch/AJAX calls in the JavaScript:

Now I can see! The JavaScript `loadDocuments()` function calls `tracking.php` with POST action `get_documents`. Let me find the PHP handler for this action at the top of the file:

I can see the routing. It calls `tracking.php` with `action: 'get_documents'`. Let me find this handler in the PHP section at the very top:

I can see actions like `update_status`, `get_document`, `get_trail`. Let me search specifically for `get_documents`:

The action is likely handled as part of a larger if/elseif chain. Let me search with a broader pattern:

Let me try to find where documents are listed/queried:

I can see `$action === 'get_documents'` exists somewhere. Let me find it:

Let me read the full PHP section systematically - it must be in the first 200 lines:

Good, now I can see the structure. Let me continue:

This is the handler. Now let me see how it builds the query with conditions:

Perfect! Now I can see the exact structure. The `get_documents` handler:

1. Line ~82: `$conditions = ["1=1"];`
2. Lines ~83-100: Various filter conditions are added
3. Then a WHERE clause is built from `$conditions`

I need to add the department filter right after the existing conditions are set (around line 100) and before the query is executed. Let me see lines 100-130:

Now I have the complete picture. Here's the exact structure:

- Line ~82: `$conditions = ["1=1"];`
- Lines 83-100: Filter conditions (type, status, department, date, search)
- Line ~105: `$where = implode(' AND ', $conditions);`
- Line ~108: Query execution

I'll add the department-user filter right before the `$where = implode(...)` line:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
    $conditions = ["1=1"];

    if (!empty($type))       $conditions[] = "d.document_type = '" . $conn->real_escape_string($type) . "'";
    if (!empty($status))     $conditions[] = "d.status = '" . $conn->real_escape_string($status) . "'";
    if (!empty($department)) $conditions[] = "d.current_department = '" . $conn->real_escape_string($department) . "'";
    if (!empty($dateFrom))   $conditions[] = "DATE(d.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
    if (!empty($dateTo))     $conditions[] = "DATE(d.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
    if (!empty($search)) {
        $s = $conn->real_escape_string($search);
        $conditions[] = "(d.title LIKE '%$s%' OR d.tracking_number LIKE '%$s%')";
    }

    // ── Department-scoped filtering for department_user ──
    // Department users only see documents where their department is involved
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';
    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"$deptSafe\"%'
        )";
    }

    $where = implode(' AND ', $conditions);
// ...existing code...
```

Now I also need to make sure the login sets `$_SESSION['user_department']`. Let me check the login handler again:

Let me see the full login query to check if it fetches the department column:

I need to ensure the login query includes the `department` column. Let me check the users table structure:

Good — the `users` table has a `department` column. Now let me check if the login query SELECTs it:

The login query uses `SELECT *`, so `$user['department']` is available. Now I just need to store it in the session. Let me find the exact session-setting lines:

Now let me apply the session fix:

`````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION
```


## License: MIT
https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

```


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset($user['department']) ? $user['department'] : '';
// ...existing code...
`````

Now let me apply the department filter in tracking.php's `get_documents` handler:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// Near the top of the get_documents AJAX handler, after building the base query and before executing:
// Find the section that handles action=get_documents

if ($action === 'get_documents') {
    // ...existing code that builds the query...

    // ── Department-scoped filtering for department_user ──
    $isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($isDeptUser && $userDept !== '') {
        // Only show documents where the user's department is involved:
        // 1. current_department matches
        // 2. origin_department matches
        // 3. department appears in route_trail JSON
        $deptFilter = $conn->real_escape_string($userDept);
        $conditions[] = "(
            d.current_department = '$deptFilter'
            OR d.origin_department = '$deptFilter'
            OR d.route_trail LIKE '%\"$deptFilter\"%'
        )";
    }

    // ...existing code that assembles WHERE clause from $conditions...
```

Let me find the exact location in tracking.php where the query is built so I can place this precisely. Let me read the get_documents handler in detail:

Let me find more of the get_documents logic:

Let me continue reading:

Now I can see the exact structure. The `get_documents` handler:

1. Starts around the PHP AJAX section
2. Uses `$conditions[]` array to build WHERE clauses
3. Joins conditions with AND

Let me find the exact lines where `$conditions` is initialized and where the WHERE clause is assembled:

Now I have the full picture. Let me apply the fix precisely. The `$conditions` array is built up and then joined. I need to add the department filter right before the WHERE clause is assembled.

Let me also check what columns exist in the documents table:

Now let me see the full query assembly to find the exact insertion point:

Let me check if `origin_department` column exists or if it's called something else:

Good — the documents table has `current_department` and the route is tracked in `route_trail`. There's no `origin_department` column. Let me check what fields are actually in the documents table:

Now I have everything. The documents table has:

- `current_department` — where the document currently is
- `route_trail` — JSON array tracking the routing history
- `status` — current status

For a department user with department "CTO", a document should only appear if:

1. `current_department = 'CTO'` (document is currently at CTO), OR
2. CTO appears in the `route_trail` JSON (document has been through CTO)

This means:

- If HR routes to CBO, CTO does NOT see it (CTO not involved)
- When CBO routes to CTO, CTO sees it (current_department = CTO)
- After CTO routes to another dept, CTO still sees it (CTO is in route_trail history)

Let me now apply the precise fix:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// In the get_documents AJAX handler, right after all existing $conditions[] are set
// and BEFORE the line that assembles the WHERE clause (e.g., $where = implode(' AND ', $conditions))

    // ── Department-scoped filtering for department_user ──
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        // Show document only if user's department is the current holder
        // OR the department appears anywhere in the route_trail history
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"department\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"to\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"from\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%$deptSafe%'
        )";
    }

// ...existing code that does: $where = implode(' AND ', $conditions); ...
```

But I need to place this in the exact right spot. Let me read the precise lines around the WHERE assembly:

Now I can see the exact structure. Let me look for where `$conditions` is first defined and where it's joined:

I need to find the complete AJAX handler. Let me search more carefully:

Let me look at the PHP block at the very top of the file:

Let me search for the actual query execution:

Let me read more PHP at the top:

I can see the structure now. The PHP AJAX handlers are at the top of the file within `if ($_SERVER['REQUEST_METHOD'] === 'POST')` blocks. Let me find the `get_documents` action specifically:

Let me look at the full AJAX section more carefully:

Now I can see the structure. Let me find `get_documents` specifically:

I can see the document fetching uses a direct query. Let me find the exact query string:

Let me look further for the SELECT query:

Now I can see the structure clearly. Let me find where the main document listing query is built:

I see references to `$statusFilter` and filtering. Let me find the complete query:

Now let me look at how the JavaScript fetches documents — it might be a different endpoint or inline PHP:

Let me look at how the table is populated - it might be fetched via fetch() calls:

Let me search for the fetch/AJAX calls in the JavaScript:

Now I can see! The JavaScript `loadDocuments()` function calls `tracking.php` with POST action `get_documents`. Let me find the PHP handler for this action at the top of the file:

I can see the routing. It calls `tracking.php` with `action: 'get_documents'`. Let me find this handler in the PHP section at the very top:

I can see actions like `update_status`, `get_document`, `get_trail`. Let me search specifically for `get_documents`:

The action is likely handled as part of a larger if/elseif chain. Let me search with a broader pattern:

Let me try to find where documents are listed/queried:

I can see `$action === 'get_documents'` exists somewhere. Let me find it:

Let me read the full PHP section systematically - it must be in the first 200 lines:

Good, now I can see the structure. Let me continue:

This is the handler. Now let me see how it builds the query with conditions:

Perfect! Now I can see the exact structure. The `get_documents` handler:

1. Line ~82: `$conditions = ["1=1"];`
2. Lines ~83-100: Various filter conditions are added
3. Then a WHERE clause is built from `$conditions`

I need to add the department filter right after the existing conditions are set (around line 100) and before the query is executed. Let me see lines 100-130:

Now I have the complete picture. Here's the exact structure:

- Line ~82: `$conditions = ["1=1"];`
- Lines 83-100: Filter conditions (type, status, department, date, search)
- Line ~105: `$where = implode(' AND ', $conditions);`
- Line ~108: Query execution

I'll add the department-user filter right before the `$where = implode(...)` line:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
    $conditions = ["1=1"];

    if (!empty($type))       $conditions[] = "d.document_type = '" . $conn->real_escape_string($type) . "'";
    if (!empty($status))     $conditions[] = "d.status = '" . $conn->real_escape_string($status) . "'";
    if (!empty($department)) $conditions[] = "d.current_department = '" . $conn->real_escape_string($department) . "'";
    if (!empty($dateFrom))   $conditions[] = "DATE(d.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
    if (!empty($dateTo))     $conditions[] = "DATE(d.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
    if (!empty($search)) {
        $s = $conn->real_escape_string($search);
        $conditions[] = "(d.title LIKE '%$s%' OR d.tracking_number LIKE '%$s%')";
    }

    // ── Department-scoped filtering for department_user ──
    // Department users only see documents where their department is involved
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';
    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"$deptSafe\"%'
        )";
    }

    $where = implode(' AND ', $conditions);
// ...existing code...
```

Now I also need to make sure the login sets `$_SESSION['user_department']`. Let me check the login handler again:

Let me see the full login query to check if it fetches the department column:

I need to ensure the login query includes the `department` column. Let me check the users table structure:

Good — the `users` table has a `department` column. Now let me check if the login query SELECTs it:

The login query uses `SELECT *`, so `$user['department']` is available. Now I just need to store it in the session. Let me find the exact session-setting lines:

Now let me apply the session fix:

`````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION
```


## License: MIT
https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

```


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset($user['department']) ? $user['department'] : '';
// ...existing code...
`````

Now let me apply the department filter in tracking.php's `get_documents` handler:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// Near the top of the get_documents AJAX handler, after building the base query and before executing:
// Find the section that handles action=get_documents

if ($action === 'get_documents') {
    // ...existing code that builds the query...

    // ── Department-scoped filtering for department_user ──
    $isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($isDeptUser && $userDept !== '') {
        // Only show documents where the user's department is involved:
        // 1. current_department matches
        // 2. origin_department matches
        // 3. department appears in route_trail JSON
        $deptFilter = $conn->real_escape_string($userDept);
        $conditions[] = "(
            d.current_department = '$deptFilter'
            OR d.origin_department = '$deptFilter'
            OR d.route_trail LIKE '%\"$deptFilter\"%'
        )";
    }

    // ...existing code that assembles WHERE clause from $conditions...
```

Let me find the exact location in tracking.php where the query is built so I can place this precisely. Let me read the get_documents handler in detail:

Let me find more of the get_documents logic:

Let me continue reading:

Now I can see the exact structure. The `get_documents` handler:

1. Starts around the PHP AJAX section
2. Uses `$conditions[]` array to build WHERE clauses
3. Joins conditions with AND

Let me find the exact lines where `$conditions` is initialized and where the WHERE clause is assembled:

Now I have the full picture. Let me apply the fix precisely. The `$conditions` array is built up and then joined. I need to add the department filter right before the WHERE clause is assembled.

Let me also check what columns exist in the documents table:

Now let me see the full query assembly to find the exact insertion point:

Let me check if `origin_department` column exists or if it's called something else:

Good — the documents table has `current_department` and the route is tracked in `route_trail`. There's no `origin_department` column. Let me check what fields are actually in the documents table:

Now I have everything. The documents table has:

- `current_department` — where the document currently is
- `route_trail` — JSON array tracking the routing history
- `status` — current status

For a department user with department "CTO", a document should only appear if:

1. `current_department = 'CTO'` (document is currently at CTO), OR
2. CTO appears in the `route_trail` JSON (document has been through CTO)

This means:

- If HR routes to CBO, CTO does NOT see it (CTO not involved)
- When CBO routes to CTO, CTO sees it (current_department = CTO)
- After CTO routes to another dept, CTO still sees it (CTO is in route_trail history)

Let me now apply the precise fix:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// In the get_documents AJAX handler, right after all existing $conditions[] are set
// and BEFORE the line that assembles the WHERE clause (e.g., $where = implode(' AND ', $conditions))

    // ── Department-scoped filtering for department_user ──
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        // Show document only if user's department is the current holder
        // OR the department appears anywhere in the route_trail history
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"department\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"to\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"from\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%$deptSafe%'
        )";
    }

// ...existing code that does: $where = implode(' AND ', $conditions); ...
```

But I need to place this in the exact right spot. Let me read the precise lines around the WHERE assembly:

Now I can see the exact structure. Let me look for where `$conditions` is first defined and where it's joined:

I need to find the complete AJAX handler. Let me search more carefully:

Let me look at the PHP block at the very top of the file:

Let me search for the actual query execution:

Let me read more PHP at the top:

I can see the structure now. The PHP AJAX handlers are at the top of the file within `if ($_SERVER['REQUEST_METHOD'] === 'POST')` blocks. Let me find the `get_documents` action specifically:

Let me look at the full AJAX section more carefully:

Now I can see the structure. Let me find `get_documents` specifically:

I can see the document fetching uses a direct query. Let me find the exact query string:

Let me look further for the SELECT query:

Now I can see the structure clearly. Let me find where the main document listing query is built:

I see references to `$statusFilter` and filtering. Let me find the complete query:

Now let me look at how the JavaScript fetches documents — it might be a different endpoint or inline PHP:

Let me look at how the table is populated - it might be fetched via fetch() calls:

Let me search for the fetch/AJAX calls in the JavaScript:

Now I can see! The JavaScript `loadDocuments()` function calls `tracking.php` with POST action `get_documents`. Let me find the PHP handler for this action at the top of the file:

I can see the routing. It calls `tracking.php` with `action: 'get_documents'`. Let me find this handler in the PHP section at the very top:

I can see actions like `update_status`, `get_document`, `get_trail`. Let me search specifically for `get_documents`:

The action is likely handled as part of a larger if/elseif chain. Let me search with a broader pattern:

Let me try to find where documents are listed/queried:

I can see `$action === 'get_documents'` exists somewhere. Let me find it:

Let me read the full PHP section systematically - it must be in the first 200 lines:

Good, now I can see the structure. Let me continue:

This is the handler. Now let me see how it builds the query with conditions:

Perfect! Now I can see the exact structure. The `get_documents` handler:

1. Line ~82: `$conditions = ["1=1"];`
2. Lines ~83-100: Various filter conditions are added
3. Then a WHERE clause is built from `$conditions`

I need to add the department filter right after the existing conditions are set (around line 100) and before the query is executed. Let me see lines 100-130:

Now I have the complete picture. Here's the exact structure:

- Line ~82: `$conditions = ["1=1"];`
- Lines 83-100: Filter conditions (type, status, department, date, search)
- Line ~105: `$where = implode(' AND ', $conditions);`
- Line ~108: Query execution

I'll add the department-user filter right before the `$where = implode(...)` line:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
    $conditions = ["1=1"];

    if (!empty($type))       $conditions[] = "d.document_type = '" . $conn->real_escape_string($type) . "'";
    if (!empty($status))     $conditions[] = "d.status = '" . $conn->real_escape_string($status) . "'";
    if (!empty($department)) $conditions[] = "d.current_department = '" . $conn->real_escape_string($department) . "'";
    if (!empty($dateFrom))   $conditions[] = "DATE(d.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
    if (!empty($dateTo))     $conditions[] = "DATE(d.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
    if (!empty($search)) {
        $s = $conn->real_escape_string($search);
        $conditions[] = "(d.title LIKE '%$s%' OR d.tracking_number LIKE '%$s%')";
    }

    // ── Department-scoped filtering for department_user ──
    // Department users only see documents where their department is involved
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';
    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"$deptSafe\"%'
        )";
    }

    $where = implode(' AND ', $conditions);
// ...existing code...
```

Now I also need to make sure the login sets `$_SESSION['user_department']`. Let me check the login handler again:

Let me see the full login query to check if it fetches the department column:

I need to ensure the login query includes the `department` column. Let me check the users table structure:

Good — the `users` table has a `department` column. Now let me check if the login query SELECTs it:

The login query uses `SELECT *`, so `$user['department']` is available. Now I just need to store it in the session. Let me find the exact session-setting lines:

Now let me apply the session fix:

`````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION
```


## License: MIT
https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

```


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset($user['department']) ? $user['department'] : '';
// ...existing code...
`````

Now let me apply the department filter in tracking.php's `get_documents` handler:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// Near the top of the get_documents AJAX handler, after building the base query and before executing:
// Find the section that handles action=get_documents

if ($action === 'get_documents') {
    // ...existing code that builds the query...

    // ── Department-scoped filtering for department_user ──
    $isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($isDeptUser && $userDept !== '') {
        // Only show documents where the user's department is involved:
        // 1. current_department matches
        // 2. origin_department matches
        // 3. department appears in route_trail JSON
        $deptFilter = $conn->real_escape_string($userDept);
        $conditions[] = "(
            d.current_department = '$deptFilter'
            OR d.origin_department = '$deptFilter'
            OR d.route_trail LIKE '%\"$deptFilter\"%'
        )";
    }

    // ...existing code that assembles WHERE clause from $conditions...
```

Let me find the exact location in tracking.php where the query is built so I can place this precisely. Let me read the get_documents handler in detail:

Let me find more of the get_documents logic:

Let me continue reading:

Now I can see the exact structure. The `get_documents` handler:

1. Starts around the PHP AJAX section
2. Uses `$conditions[]` array to build WHERE clauses
3. Joins conditions with AND

Let me find the exact lines where `$conditions` is initialized and where the WHERE clause is assembled:

Now I have the full picture. Let me apply the fix precisely. The `$conditions` array is built up and then joined. I need to add the department filter right before the WHERE clause is assembled.

Let me also check what columns exist in the documents table:

Now let me see the full query assembly to find the exact insertion point:

Let me check if `origin_department` column exists or if it's called something else:

Good — the documents table has `current_department` and the route is tracked in `route_trail`. There's no `origin_department` column. Let me check what fields are actually in the documents table:

Now I have everything. The documents table has:

- `current_department` — where the document currently is
- `route_trail` — JSON array tracking the routing history
- `status` — current status

For a department user with department "CTO", a document should only appear if:

1. `current_department = 'CTO'` (document is currently at CTO), OR
2. CTO appears in the `route_trail` JSON (document has been through CTO)

This means:

- If HR routes to CBO, CTO does NOT see it (CTO not involved)
- When CBO routes to CTO, CTO sees it (current_department = CTO)
- After CTO routes to another dept, CTO still sees it (CTO is in route_trail history)

Let me now apply the precise fix:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// In the get_documents AJAX handler, right after all existing $conditions[] are set
// and BEFORE the line that assembles the WHERE clause (e.g., $where = implode(' AND ', $conditions))

    // ── Department-scoped filtering for department_user ──
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        // Show document only if user's department is the current holder
        // OR the department appears anywhere in the route_trail history
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"department\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"to\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"from\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%$deptSafe%'
        )";
    }

// ...existing code that does: $where = implode(' AND ', $conditions); ...
```

But I need to place this in the exact right spot. Let me read the precise lines around the WHERE assembly:

Now I can see the exact structure. Let me look for where `$conditions` is first defined and where it's joined:

I need to find the complete AJAX handler. Let me search more carefully:

Let me look at the PHP block at the very top of the file:

Let me search for the actual query execution:

Let me read more PHP at the top:

I can see the structure now. The PHP AJAX handlers are at the top of the file within `if ($_SERVER['REQUEST_METHOD'] === 'POST')` blocks. Let me find the `get_documents` action specifically:

Let me look at the full AJAX section more carefully:

Now I can see the structure. Let me find `get_documents` specifically:

I can see the document fetching uses a direct query. Let me find the exact query string:

Let me look further for the SELECT query:

Now I can see the structure clearly. Let me find where the main document listing query is built:

I see references to `$statusFilter` and filtering. Let me find the complete query:

Now let me look at how the JavaScript fetches documents — it might be a different endpoint or inline PHP:

Let me look at how the table is populated - it might be fetched via fetch() calls:

Let me search for the fetch/AJAX calls in the JavaScript:

Now I can see! The JavaScript `loadDocuments()` function calls `tracking.php` with POST action `get_documents`. Let me find the PHP handler for this action at the top of the file:

I can see the routing. It calls `tracking.php` with `action: 'get_documents'`. Let me find this handler in the PHP section at the very top:

I can see actions like `update_status`, `get_document`, `get_trail`. Let me search specifically for `get_documents`:

The action is likely handled as part of a larger if/elseif chain. Let me search with a broader pattern:

Let me try to find where documents are listed/queried:

I can see `$action === 'get_documents'` exists somewhere. Let me find it:

Let me read the full PHP section systematically - it must be in the first 200 lines:

Good, now I can see the structure. Let me continue:

This is the handler. Now let me see how it builds the query with conditions:

Perfect! Now I can see the exact structure. The `get_documents` handler:

1. Line ~82: `$conditions = ["1=1"];`
2. Lines ~83-100: Various filter conditions are added
3. Then a WHERE clause is built from `$conditions`

I need to add the department filter right after the existing conditions are set (around line 100) and before the query is executed. Let me see lines 100-130:

Now I have the complete picture. Here's the exact structure:

- Line ~82: `$conditions = ["1=1"];`
- Lines 83-100: Filter conditions (type, status, department, date, search)
- Line ~105: `$where = implode(' AND ', $conditions);`
- Line ~108: Query execution

I'll add the department-user filter right before the `$where = implode(...)` line:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
    $conditions = ["1=1"];

    if (!empty($type))       $conditions[] = "d.document_type = '" . $conn->real_escape_string($type) . "'";
    if (!empty($status))     $conditions[] = "d.status = '" . $conn->real_escape_string($status) . "'";
    if (!empty($department)) $conditions[] = "d.current_department = '" . $conn->real_escape_string($department) . "'";
    if (!empty($dateFrom))   $conditions[] = "DATE(d.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
    if (!empty($dateTo))     $conditions[] = "DATE(d.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
    if (!empty($search)) {
        $s = $conn->real_escape_string($search);
        $conditions[] = "(d.title LIKE '%$s%' OR d.tracking_number LIKE '%$s%')";
    }

    // ── Department-scoped filtering for department_user ──
    // Department users only see documents where their department is involved
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';
    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"$deptSafe\"%'
        )";
    }

    $where = implode(' AND ', $conditions);
// ...existing code...
```

Now I also need to make sure the login sets `$_SESSION['user_department']`. Let me check the login handler again:

Let me see the full login query to check if it fetches the department column:

I need to ensure the login query includes the `department` column. Let me check the users table structure:

Good — the `users` table has a `department` column. Now let me check if the login query SELECTs it:

The login query uses `SELECT *`, so `$user['department']` is available. Now I just need to store it in the session. Let me find the exact session-setting lines:

Now let me apply the session fix:

`````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION
```


## License: MIT
https://github.com/zayed259/php_blog/blob/2ec7df28287384d1b5c6b84d8432a0d336163d0f/login.php

```


Let me examine the tracking.php file thoroughly to understand the current data fetching and filtering logic, then apply the department-scoped filtering for department users.

Now let me see the PHP data-fetching logic and the JavaScript that loads tracking data:

Let me see more of the file to find the AJAX/data loading logic:

Now let me find the JavaScript that fetches and renders tracking data:

Let me find the fetchDocuments or similar AJAX call:

Let me see the full fetch logic and how documents are rendered:

Let me see the rendering and the PHP endpoint that returns documents:

Now let me check the session variables available — specifically how department is stored for the logged-in user:

Now let me check the security.php to understand what session vars are set at login:

Let me see the login handler to understand what session variables are set:

Now I have the full picture. Let me trace the flow:

1. **Login** sets `$_SESSION['user_role']` and `$_SESSION['user_name']` but does NOT set `$_SESSION['user_department']`
2. **tracking.php** fetches documents via `action=get_documents` which queries the `documents` table
3. Department users should only see documents where their department appears in the routing flow

I need to:
1. Set `$_SESSION['user_department']` at login time
2. Filter the `get_documents` query in tracking.php to only return documents relevant to the user's department
3. A document is relevant to department X if X appears as `current_department`, `origin_department`, or in the `route_trail` JSON

Let me implement this:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            // Store department for department-scoped filtering
            $_SESSION['user_department'] = isset($user['department']) ? $user['department'] : '';
// ...existing code...
`````

Now let me apply the department filter in tracking.php's `get_documents` handler:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// Near the top of the get_documents AJAX handler, after building the base query and before executing:
// Find the section that handles action=get_documents

if ($action === 'get_documents') {
    // ...existing code that builds the query...

    // ── Department-scoped filtering for department_user ──
    $isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($isDeptUser && $userDept !== '') {
        // Only show documents where the user's department is involved:
        // 1. current_department matches
        // 2. origin_department matches
        // 3. department appears in route_trail JSON
        $deptFilter = $conn->real_escape_string($userDept);
        $conditions[] = "(
            d.current_department = '$deptFilter'
            OR d.origin_department = '$deptFilter'
            OR d.route_trail LIKE '%\"$deptFilter\"%'
        )";
    }

    // ...existing code that assembles WHERE clause from $conditions...
```

Let me find the exact location in tracking.php where the query is built so I can place this precisely. Let me read the get_documents handler in detail:

Let me find more of the get_documents logic:

Let me continue reading:

Now I can see the exact structure. The `get_documents` handler:

1. Starts around the PHP AJAX section
2. Uses `$conditions[]` array to build WHERE clauses
3. Joins conditions with AND

Let me find the exact lines where `$conditions` is initialized and where the WHERE clause is assembled:

Now I have the full picture. Let me apply the fix precisely. The `$conditions` array is built up and then joined. I need to add the department filter right before the WHERE clause is assembled.

Let me also check what columns exist in the documents table:

Now let me see the full query assembly to find the exact insertion point:

Let me check if `origin_department` column exists or if it's called something else:

Good — the documents table has `current_department` and the route is tracked in `route_trail`. There's no `origin_department` column. Let me check what fields are actually in the documents table:

Now I have everything. The documents table has:

- `current_department` — where the document currently is
- `route_trail` — JSON array tracking the routing history
- `status` — current status

For a department user with department "CTO", a document should only appear if:

1. `current_department = 'CTO'` (document is currently at CTO), OR
2. CTO appears in the `route_trail` JSON (document has been through CTO)

This means:

- If HR routes to CBO, CTO does NOT see it (CTO not involved)
- When CBO routes to CTO, CTO sees it (current_department = CTO)
- After CTO routes to another dept, CTO still sees it (CTO is in route_trail history)

Let me now apply the precise fix:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
// In the get_documents AJAX handler, right after all existing $conditions[] are set
// and BEFORE the line that assembles the WHERE clause (e.g., $where = implode(' AND ', $conditions))

    // ── Department-scoped filtering for department_user ──
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';

    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        // Show document only if user's department is the current holder
        // OR the department appears anywhere in the route_trail history
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"department\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"to\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%\"from\":\"$deptSafe\"%'
            OR d.route_trail LIKE '%$deptSafe%'
        )";
    }

// ...existing code that does: $where = implode(' AND ', $conditions); ...
```

But I need to place this in the exact right spot. Let me read the precise lines around the WHERE assembly:

Now I can see the exact structure. Let me look for where `$conditions` is first defined and where it's joined:

I need to find the complete AJAX handler. Let me search more carefully:

Let me look at the PHP block at the very top of the file:

Let me search for the actual query execution:

Let me read more PHP at the top:

I can see the structure now. The PHP AJAX handlers are at the top of the file within `if ($_SERVER['REQUEST_METHOD'] === 'POST')` blocks. Let me find the `get_documents` action specifically:

Let me look at the full AJAX section more carefully:

Now I can see the structure. Let me find `get_documents` specifically:

I can see the document fetching uses a direct query. Let me find the exact query string:

Let me look further for the SELECT query:

Now I can see the structure clearly. Let me find where the main document listing query is built:

I see references to `$statusFilter` and filtering. Let me find the complete query:

Now let me look at how the JavaScript fetches documents — it might be a different endpoint or inline PHP:

Let me look at how the table is populated - it might be fetched via fetch() calls:

Let me search for the fetch/AJAX calls in the JavaScript:

Now I can see! The JavaScript `loadDocuments()` function calls `tracking.php` with POST action `get_documents`. Let me find the PHP handler for this action at the top of the file:

I can see the routing. It calls `tracking.php` with `action: 'get_documents'`. Let me find this handler in the PHP section at the very top:

I can see actions like `update_status`, `get_document`, `get_trail`. Let me search specifically for `get_documents`:

The action is likely handled as part of a larger if/elseif chain. Let me search with a broader pattern:

Let me try to find where documents are listed/queried:

I can see `$action === 'get_documents'` exists somewhere. Let me find it:

Let me read the full PHP section systematically - it must be in the first 200 lines:

Good, now I can see the structure. Let me continue:

This is the handler. Now let me see how it builds the query with conditions:

Perfect! Now I can see the exact structure. The `get_documents` handler:

1. Line ~82: `$conditions = ["1=1"];`
2. Lines ~83-100: Various filter conditions are added
3. Then a WHERE clause is built from `$conditions`

I need to add the department filter right after the existing conditions are set (around line 100) and before the query is executed. Let me see lines 100-130:

Now I have the complete picture. Here's the exact structure:

- Line ~82: `$conditions = ["1=1"];`
- Lines 83-100: Filter conditions (type, status, department, date, search)
- Line ~105: `$where = implode(' AND ', $conditions);`
- Line ~108: Query execution

I'll add the department-user filter right before the `$where = implode(...)` line:

```php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\tracking.php
// ...existing code...
    $conditions = ["1=1"];

    if (!empty($type))       $conditions[] = "d.document_type = '" . $conn->real_escape_string($type) . "'";
    if (!empty($status))     $conditions[] = "d.status = '" . $conn->real_escape_string($status) . "'";
    if (!empty($department)) $conditions[] = "d.current_department = '" . $conn->real_escape_string($department) . "'";
    if (!empty($dateFrom))   $conditions[] = "DATE(d.created_at) >= '" . $conn->real_escape_string($dateFrom) . "'";
    if (!empty($dateTo))     $conditions[] = "DATE(d.created_at) <= '" . $conn->real_escape_string($dateTo) . "'";
    if (!empty($search)) {
        $s = $conn->real_escape_string($search);
        $conditions[] = "(d.title LIKE '%$s%' OR d.tracking_number LIKE '%$s%')";
    }

    // ── Department-scoped filtering for department_user ──
    // Department users only see documents where their department is involved
    $__isDeptUser = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'department_user');
    $__userDept   = isset($_SESSION['user_department']) ? trim($_SESSION['user_department']) : '';
    if ($__isDeptUser && $__userDept !== '') {
        $deptSafe = $conn->real_escape_string($__userDept);
        $conditions[] = "(
            d.current_department = '$deptSafe'
            OR d.route_trail LIKE '%\"$deptSafe\"%'
        )";
    }

    $where = implode(' AND ', $conditions);
// ...existing code...
```

Now I also need to make sure the login sets `$_SESSION['user_department']`. Let me check the login handler again:

Let me see the full login query to check if it fetches the department column:

I need to ensure the login query includes the `department` column. Let me check the users table structure:

Good — the `users` table has a `department` column. Now let me check if the login query SELECTs it:

The login query uses `SELECT *`, so `$user['department']` is available. Now I just need to store it in the session. Let me find the exact session-setting lines:

Now let me apply the session fix:

````php
// filepath: c:\xampp\htdocs\flutter_application_7\lib\OCR(UPDATED)\log-in.php
// ...existing code...
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION
```

````
