# Entity Relationship Diagram (ERD) — CHRMO Document Tracking System

The Entity Relationship Diagram (ERD) illustrates the database design of the OCR-Based Document Tracking and Archival System by identifying the main entities and their relationships. The ERD serves as a blueprint for structuring data to support document storage, tracking, and retrieval within the system.

The diagram includes core entities such as Control (Users), Tracking (Documents), Departments, Document History, Archive, and Notifications. The Control entity stores information related to system users, including their roles, departments, and account status. The Tracking entity serves as the central document table, containing details about uploaded or scanned files such as document type, date submitted, current status, current holder, and OCR-extracted content. Each tracked document is associated with a department responsible for processing or handling it.

The Document History entity records the movement and status changes of documents as they pass through different departments and personnel, ensuring accountability and transparency. This entity maintains a direct relationship with the Tracking entity, capturing each action — such as create, receive, route, and archive — along with the originating and destination holders. When a document reaches end-of-life, it is moved to the Archive entity, and its full audit trail is preserved in Archive History. The Notifications entity facilitates real-time communication between departments by alerting recipients when documents are routed to them. Additionally, supporting entities such as Document Comments, Document Attachments, and Document Versions enable collaboration, supplementary file uploads, and file revision tracking. Authentication-related entities including Users, Password Resets, and Security Logs handle system access and audit logging, while the Share Feed and Share Reactions entities support social collaboration features among users.

> Copy the Mermaid code block below and paste it into [Mermaid Live Editor](https://mermaid.live) to render the diagram.

```mermaid
erDiagram

    %% ===== CORE ENTITIES =====

    departments {
        int id PK
        varchar department_name
        varchar head
        int employees
        varchar contact
        varchar location
    }

    control {
        int id PK
        varchar user
        varchar email
        varchar role
        varchar department FK
        varchar status
        date last_active
        varchar file_type_icon
        varchar password
    }

    tracking {
        int id PK
        varchar type
        varchar employee_name
        date date_submitted
        varchar current_holder
        varchar end_location
        varchar status
        varchar department FK
        varchar file_type_icon
        text ocr_content
        varchar mobile_timestamp
        varchar file_size
        varchar user_email
        varchar file_path
        char doc_hash
        tinyint is_hidden
        timestamp created_at
    }

    document_history {
        int id PK
        int doc_id FK
        varchar action
        int actor_user_id FK
        varchar from_status
        varchar to_status
        varchar from_holder
        varchar to_holder
        text notes
        datetime created_at
    }

    archive {
        int id PK
        varchar document_name
        varchar department FK
        varchar type
        varchar status
        date date_archived
        varchar size
        varchar file_path
        varchar file_type_icon
    }

    archive_history {
        int id PK
        int archive_id FK
        varchar action
        int actor_user_id
        varchar from_status
        varchar to_status
        varchar from_holder
        varchar to_holder
        text notes
        datetime created_at
    }

    notifications {
        int id PK
        varchar title
        text content
        varchar type
        varchar recipient_username FK
        varchar sender_username FK
        varchar department FK
        varchar recipient_department
        varchar status
        text file_url
        timestamp created_at
        text meta
        int tracking_id FK
        varchar mobile_timestamp
        varchar end_location
        varchar current_holder
        varchar doc_status
    }

    document_comments {
        int id PK
        int tracking_id FK
        int user_id FK
        varchar username
        varchar department
        text comment
        datetime created_at
    }

    document_attachments {
        int id PK
        int tracking_id FK
        varchar file_path
        varchar file_name
        varchar file_type
        int file_size
        varchar uploaded_by
        varchar department
        text remarks
        datetime created_at
    }

    document_versions {
        int id PK
        int tracking_id FK
        int version_number
        varchar file_path
        int file_size
        varchar uploaded_by
        varchar department
        enum version_type
        text ocr_content
        datetime created_at
    }

    stats {
        int id PK
        varchar document
        varchar department FK
        varchar status
        date date
        varchar file_type_icon
    }

    sla_predictions {
        int id PK
        varchar document_id UK
        varchar document_type
        varchar department FK
        decimal predicted_total_days
        decimal elapsed_days
        int sla_days
        decimal risk_score
        timestamp created_at
    }

    users {
        int id PK
        varchar name
        varchar email UK
        varchar password
        varchar role
        datetime created_at
    }

    password_resets {
        int id PK
        int user_id FK
        varchar email
        varchar token_hash
        datetime expires_at
        datetime used_at
        varchar ip_address
        text user_agent
        datetime created_at
    }

    password_resets_mobile {
        int id PK
        int user_id FK
        varchar username
        varchar email
        varchar code
        int created_at
        int expires_at
        tinyint used
    }

    security_logs {
        int id PK
        varchar event_type
        int user_id FK
        varchar email
        varchar ip_address
        text user_agent
        text details
        datetime created_at
    }

    share_feed {
        int id PK
        varchar username FK
        varchar department FK
        text content
        int created_at
        tinyint pinned
        json reactions
    }

    share_reactions {
        int id PK
        int share_id FK
        varchar username FK
        int created_at
        varchar type
    }

    fcm_tokens {
        int id PK
        varchar username FK
        varchar department FK
        text token
        varchar platform
        timestamp updated_at
        timestamp created_at
    }

    %% ===== RELATIONSHIPS =====

    %% Department ↔ Users & Analytics
    departments ||--o{ control : "has members"
    departments ||--o{ stats : "generates"
    departments ||--o{ sla_predictions : "monitors"

    %% Users (control) ↔ Documents & Actions
    control ||--o{ tracking : "submits"
    control ||--o{ document_history : "acts on"
    control ||--o{ document_comments : "writes"
    control ||--o{ document_attachments : "uploads"
    control ||--o{ notifications : "receives"
    control ||--o{ fcm_tokens : "registers device"
    control ||--o{ password_resets_mobile : "requests reset"
    control ||--o{ share_feed : "posts"

    %% Tracking (Document) ↔ History & Sub-tables
    tracking ||--o{ document_history : "has history"
    tracking ||--o{ document_comments : "has comments"
    tracking ||--o{ document_attachments : "has attachments"
    tracking ||--o{ document_versions : "has versions"
    tracking ||--o{ notifications : "triggers"

    %% Tracking → Archive lifecycle
    tracking ||--o| archive : "archived as"
    archive ||--o{ archive_history : "has history"

    %% Auth relationships
    users ||--o{ password_resets : "requests reset"
    users ||--o{ security_logs : "generates logs"

    %% Social
    share_feed ||--o{ share_reactions : "receives reactions"
```

## Table Summary

| # | Table | Purpose |
|---|-------|---------|
| 1 | `departments` | Department master list (name, head, location) |
| 2 | `control` | System users / accounts (login, role, department) |
| 3 | `tracking` | **Core** — active document tracking (one row per document) |
| 4 | `document_history` | Audit trail of all actions on a tracked document |
| 5 | `archive` | Completed/archived documents moved from tracking |
| 6 | `archive_history` | Copied history for archived documents |
| 7 | `notifications` | Push/in-app notifications for routing & actions |
| 8 | `document_comments` | User comments on tracked documents |
| 9 | `document_attachments` | File attachments added to tracked documents |
| 10 | `document_versions` | Version history when documents are updated/returned |
| 11 | `stats` | Reporting snapshots for document generation reports |
| 12 | `sla_predictions` | SLA risk predictions per document |
| 13 | `users` | Web admin users (separate from mobile `control` table) |
| 14 | `password_resets` | Web password reset tokens |
| 15 | `password_resets_mobile` | Mobile app password reset codes |
| 16 | `security_logs` | Security event audit log |
| 17 | `share_feed` | Social feed posts between users |
| 18 | `share_reactions` | Reactions (likes) on feed posts |
| 19 | `fcm_tokens` | Firebase Cloud Messaging device tokens |

## Document Lifecycle Flow

```
Create/Upload → Pending → Receive (In Review) → Route (Pending) → ... → Update (Ready for Archive) → Complete → Archive
```
