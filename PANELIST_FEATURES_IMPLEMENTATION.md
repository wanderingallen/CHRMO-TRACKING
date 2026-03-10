# Panelist Features Implementation Plan
**Deadline: Tomorrow (Jan 26, 2026)**  
**Status: ✅ Phase 1 COMPLETED**

---

## Executive Summary
This document outlines the implementation workflow for features requested by the panelist. Each feature is broken down into phases with specific files to modify.

---

## Feature 1: Document Return, Comment & New Attachment

### Description
Allow users to return documents during the routing process, add comments/remarks, and attach new files.

### Database Changes
```sql
-- Add to chrmo_db
ALTER TABLE tracking ADD COLUMN remarks TEXT DEFAULT NULL;
ALTER TABLE tracking ADD COLUMN return_reason TEXT DEFAULT NULL;
ALTER TABLE tracking ADD COLUMN returned_by VARCHAR(255) DEFAULT NULL;
ALTER TABLE tracking ADD COLUMN returned_at DATETIME DEFAULT NULL;

-- New table for document attachments
CREATE TABLE document_attachments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tracking_id INT NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_type VARCHAR(50),
  uploaded_by VARCHAR(255),
  uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  remarks TEXT,
  FOREIGN KEY (tracking_id) REFERENCES tracking(id) ON DELETE CASCADE
);

-- New table for document comments
CREATE TABLE document_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tracking_id INT NOT NULL,
  user_id INT,
  username VARCHAR(255),
  department VARCHAR(255),
  comment TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tracking_id) REFERENCES tracking(id) ON DELETE CASCADE
);
```

### Backend API Changes
**File: `lib/OCR(UPDATED)/api/document_actions.php`** (NEW)
- `POST action=return_document` - Return document to sender with reason
- `POST action=add_comment` - Add comment to document
- `POST action=add_attachment` - Upload new attachment
- `GET action=get_comments` - Fetch comments for a document
- `GET action=get_attachments` - Fetch attachments for a document

### Flutter Changes
**File: `lib/dashboard_page.dart`**
- Add "Return Document" button in document action menu
- Add "Add Comment" dialog
- Add "Add Attachment" option
- Show comments/remarks timeline

**File: `lib/services/document_action_service.dart`** (NEW)
- `returnDocument(trackingId, reason, remarks)`
- `addComment(trackingId, comment)`
- `addAttachment(trackingId, file, remarks)`

---

## Feature 2: Edit/Update Document Type

### Description
Allow users to change document type during routing (e.g., mis-click on "Memo" but should be "Letter").

### Database Changes
```sql
-- Track document type changes in history
-- (Already have document_history table, will use 'edit_type' action)
```

### Backend API Changes
**File: `lib/OCR(UPDATED)/tracking.php`**
- Add `action=update_document_type` endpoint
- Log change to `document_history` table

### Flutter Changes
**File: `lib/dashboard_page.dart`**
- Add "Edit Document Type" option in document menu
- Show dropdown with available document types
- Confirm dialog before changing

---

## Feature 3: Document Tracking History with Version Control

### Description
Track all document actions with timestamps, show progress timeline.

### Current State
- `document_history` table already exists with: `doc_id`, `action`, `actor_user_id`, `from_status`, `to_status`, `from_holder`, `to_holder`, `notes`, `created_at`

### Enhancements Needed
```sql
-- Add version tracking
ALTER TABLE document_history ADD COLUMN version_number INT DEFAULT 1;
ALTER TABLE document_history ADD COLUMN ip_address VARCHAR(45);
ALTER TABLE document_history ADD COLUMN device_info VARCHAR(255);
```

### Backend API Changes
**File: `lib/OCR(UPDATED)/tracking.php`**
- Enhance `action=doc_detail` to include full history timeline
- Add `action=get_document_versions` endpoint

### Flutter Changes
**File: `lib/document_tracking_page.dart`**
- Add visual timeline showing all actions
- Show version badges
- Display who did what and when
- Progress indicator (e.g., Step 2 of 5)

---

## Feature 4: Improved Analytics

### Description
Enhance the analytics/reports dashboard with better metrics.

### Backend API Changes
**File: `lib/OCR(UPDATED)/api/analytics.php`** (NEW or ENHANCE)
```php
// Metrics to add:
// - Documents processed per day/week/month
// - Average processing time per document type
// - Department throughput
// - Document type distribution
// - Return rate (documents returned vs completed)
// - Peak hours analysis
// - User activity heatmap
```

### Flutter Changes
**File: `lib/reports_page.dart`**
- Add charts: bar, line, pie charts
- Time period selector (daily, weekly, monthly)
- Export to PDF/Excel option
- Department comparison view

---

## Feature 5: HR Account Type for Mobile Login

### Description
Add "HR" role to user control so mobile users can login with HR privileges.

### Database Changes
```sql
-- Update control table to support HR role
-- Role values: 'admin', 'user', 'hr'
-- Example:
INSERT INTO control (user, email, role, department, status, password) 
VALUES ('HRAdmin', 'hr@company.com', 'hr', 'HR', 'active', '$2y$10$...');
```

### Backend API Changes
**File: `lib/api/login.php`**
- Already supports role field
- No changes needed, just add HR users to database

**File: `lib/OCR(UPDATED)/api/user_management.php`** (ENHANCE)
- Add HR role option in user creation
- HR-specific permissions

### Flutter Changes
**File: `lib/login_page.dart`**
- Handle 'hr' role in login response
- Route to appropriate dashboard

**File: `lib/dashboard_page.dart`**
- Show HR-specific menu items
- HR can view all departments' documents

---

## Feature 6: OCR Manual Correction in Mobile

### Description
Allow users to manually correct OCR text on mobile devices.

### Current State
- `tracking.php` already has `action=save_ocr_correction` endpoint

### Flutter Changes
**File: `lib/camera_page.dart`**
- Add "Edit OCR Text" button after scan
- Show editable text field with OCR result
- Save button to submit correction

**File: `lib/services/ocr_correction_service.dart`** (NEW)
```dart
class OcrCorrectionService {
  static Future<bool> saveCorrection(int docId, String correctedText);
}
```

---

## Feature 7: Action Attachments with Routing Tracking

### Description
When a document is routed, track:
- Date returned/forwarded
- Which department handled it
- Completion status
- Option to add new attachments

### Database Changes
```sql
-- Extend document_history for routing tracking
ALTER TABLE document_history ADD COLUMN attachment_id INT DEFAULT NULL;
ALTER TABLE document_history ADD COLUMN route_completed BOOLEAN DEFAULT FALSE;
ALTER TABLE document_history ADD COLUMN completion_date DATETIME DEFAULT NULL;
```

### Workflow
1. User receives document
2. User can:
   - Mark as "Complete" → route to next department
   - "Return" with reason → goes back to sender
   - Add attachment → additional file added
   - Add comment → remarks for record
3. All actions tracked with timestamp and user info

---

## Implementation Priority & Timeline

### Phase 1: Critical (Morning - 4 hours)
| Priority | Feature | Est. Time |
|----------|---------|-----------|
| 1 | HR Account Type | 30 min |
| 2 | Document Return with Comments | 2 hours |
| 3 | Document Type Edit | 1 hour |

### Phase 2: Important (Afternoon - 4 hours)
| Priority | Feature | Est. Time |
|----------|---------|-----------|
| 4 | Tracking History/Version Control | 2 hours |
| 5 | Action Attachments | 2 hours |

### Phase 3: Enhancement (Evening - 2 hours)
| Priority | Feature | Est. Time |
|----------|---------|-----------|
| 6 | OCR Manual Correction Mobile | 1 hour |
| 7 | Improved Analytics | 1 hour |

---

## Files to Create/Modify

### New Files
1. `lib/OCR(UPDATED)/api/document_actions.php` - Return, comment, attachment APIs
2. `lib/services/document_action_service.dart` - Flutter service for document actions
3. `lib/services/ocr_correction_service.dart` - OCR correction service
4. `lib/OCR(UPDATED)/migrations/panelist_features.sql` - Database migrations

### Modified Files
1. `lib/dashboard_page.dart` - Add action buttons, dialogs
2. `lib/document_tracking_page.dart` - History timeline, version control
3. `lib/camera_page.dart` - OCR correction UI
4. `lib/reports_page.dart` - Enhanced analytics
5. `lib/login_page.dart` - HR role handling
6. `lib/api/login.php` - (minimal, already supports roles)
7. `lib/OCR(UPDATED)/tracking.php` - New endpoints

---

## Testing Checklist

- [ ] HR user can login on mobile
- [ ] Document can be returned with reason
- [ ] Comments can be added to documents
- [ ] Attachments can be added during routing
- [ ] Document type can be changed
- [ ] History timeline shows all actions
- [ ] OCR text can be corrected on mobile
- [ ] Analytics show improved metrics

---

## Rollback Plan

If issues arise:
1. Database migrations have `DOWN` scripts
2. Keep backup of modified files (already have `.backup` files)
3. Feature flags can disable new features

---

## Ready to Start?

Reply with which feature you want me to implement first, or say "start" to begin with Phase 1 (HR Account Type → Document Return → Document Type Edit).
