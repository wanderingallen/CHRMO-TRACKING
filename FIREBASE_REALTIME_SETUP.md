# Firebase Realtime Setup Guide

## Current Status

Your Firebase project `chrmo-dta-capstone` is configured. The issue was:

1. **Collection mismatch**: Web listened to `tracking`, mobile wrote to `document_routing`
2. **Missing service account**: PHP needs the Firebase Admin SDK service account key

## Steps to Complete Setup

### Step 1: Download google-services.json (Already Done ✅)

The file already exists at: `android/app/google-services.json`

### Step 2: Download Firebase Admin SDK Service Account Key

1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Select project **chrmo-dta-capstone**
3. Click ⚙️ **Settings** > **Project settings**
4. Go to **Service accounts** tab
5. Click **"Generate new private key"**
6. Save the file to: `c:\xampp\htdocs\flutter_application_7\secure\`
7. Rename it to: `firebase-service-account.json`
   (Or keep the auto-generated name like `chrmo-dta-capstone-firebase-adminsdk-*.json`)

### Step 3: Verify Firestore Security Rules

In Firebase Console > Firestore Database > Rules, ensure you have:

```javascript
rules_version = '2';
service cloud.firestore {
  match /databases/{database}/documents {
    // Allow read/write for authenticated users
    match /document_routing/{document=**} {
      allow read, write: if true; // For development - restrict in production
    }
    match /notification/{document=**} {
      allow read, write: if true;
    }
    match /tracking/{document=**} {
      allow read, write: if true;
    }
  }
}
```

### Step 4: Rebuild Flutter App

Run these commands:

```bash
cd c:\xampp\htdocs\flutter_application_7
flutter clean
flutter pub get
flutter run
```

## How Realtime Now Works

### Mobile → Web Flow:

1. User uploads document from mobile app
2. Flutter calls PHP API to save to MySQL
3. Flutter also writes to Firestore `document_routing` collection via `RoutingService.createRoute()`
4. Web tracking.php listens to BOTH `tracking` and `document_routing` collections
5. When Firestore detects changes, web UI updates automatically (no refresh needed)

### Collections Used:

| Collection         | Purpose                 | Written By  |
| ------------------ | ----------------------- | ----------- |
| `document_routing` | Mobile document routing | Flutter app |
| `tracking`         | Web document tracking   | PHP web app |
| `notification`     | Notifications           | Both        |

## Firebase Config Values

These are already configured in your project:

```
Project ID: chrmo-dta-capstone
API Key: ROTATED_AND_STORE_OUTSIDE_SOURCE_CONTROL
App ID (Android): 1:654853931664:android:20a059aca3f63068218e15
App ID (Web): 1:654853931664:web:2ff43fa7891ab848218e15
Messaging Sender ID: 654853931664
Storage Bucket: chrmo-dta-capstone.firebasestorage.app
```

Do not commit Firebase API keys directly into this repository. Load the
rotated key from environment-specific configuration or a server-side secret
store instead.

## Troubleshooting

### Web not updating in realtime?

1. Open browser DevTools (F12) > Console
2. Look for `[Firestore]` log messages
3. If you see errors, check Firestore security rules

### Mobile not writing to Firestore?

1. Check `android/app/google-services.json` exists
2. Verify package name matches: `com.example.flutter_application_7`
3. Run `flutter clean && flutter pub get`

### PHP not writing to Firestore?

1. Check service account JSON exists in `secure/` folder
2. Check Apache error logs for "firestore_upsert_document" errors
3. Verify OpenSSL is enabled in PHP
