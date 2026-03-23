# Firebase Realtime Setup Guide

## Current Status

Your Firebase project `chrmo-dta-capstone` is configured. The issue was:

1. **Collection mismatch**: Web listened to `tracking`, mobile wrote to `document_routing`
2. **Missing service account**: PHP needs the Firebase Admin SDK service account key

## Steps to Complete Setup

### Step 1: Download google-services.json (Already Done ✅)
