<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Google\Cloud\Firestore\FirestoreClient;
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;

/**
 * Initialize Firestore using service account JSON.
 * Returns FirestoreClient instance or null on failure.
 */
function init_firebase() {
    $serviceAccountPath = __DIR__ . '/../../secure/firebase_service_account.json';
    if (!file_exists($serviceAccountPath)) {
        error_log('Firebase service account file not found at: ' . $serviceAccountPath);
        return null;
    }

    try {
        // Option 1: Using Google Cloud Firestore client directly
        $firestore = new FirestoreClient([
            'keyFilePath' => $serviceAccountPath,
            'projectId' => 'chrmo-21269',
        ]);
        return $firestore;
    } catch (Exception $e) {
        error_log('Failed to initialize Firebase: ' . $e->getMessage());
        return null;
    }
}

/**
 * Optional: Initialize full Firebase app (if you need Auth, Storage, etc.)
 */
function init_firebase_app() {
    $serviceAccountPath = __DIR__ . '/../../secure/firebase_service_account.json';
    if (!file_exists($serviceAccountPath)) {
        error_log('Firebase service account file not found at: ' . $serviceAccountPath);
        return null;
    }

    try {
        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath)
            ->withDatabaseUri('https://chrmo-21269-default-rtdb.firebaseio.com');
        return $factory;
    } catch (Exception $e) {
        error_log('Failed to initialize Firebase app: ' . $e->getMessage());
        return null;
    }
}

/**
 * Write a document to Firestore.
 *
 * @param FirestoreClient $firestore
 * @param string $collection
 * @param string $documentId
 * @param array $data
 * @return bool
 */
function firestore_write($firestore, $collection, $documentId, $data) {
    try {
        $docRef = $firestore->collection($collection)->document($documentId);
        $docRef->set($data);
        return true;
    } catch (Exception $e) {
        error_log('Firestore write error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Read a document from Firestore.
 *
 * @param FirestoreClient $firestore
 * @param string $collection
 * @param string $documentId
 * @return array|null
 */
function firestore_read($firestore, $collection, $documentId) {
    try {
        $docRef = $firestore->collection($collection)->document($documentId);
        $snapshot = $docRef->snapshot();
        if ($snapshot->exists()) {
            return $snapshot->data();
        }
        return null;
    } catch (Exception $e) {
        error_log('Firestore read error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Listen to a collection in realtime (server-side long polling example).
 * Note: For true realtime on the web, keep using the client-side listener in tracking.php.
 */
function firestore_listen($firestore, $collection, $callback) {
    // This is a placeholder; true server-side realtime requires websockets or long polling.
    // For most cases, use the client-side onSnapshot in JavaScript.
    error_log('firestore_listen is a placeholder; use client-side listener for true realtime.');
}
