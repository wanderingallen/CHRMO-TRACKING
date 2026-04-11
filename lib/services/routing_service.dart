// NOTE: This file requires cloud_firestore package to be installed.
// Run 'flutter pub get' to install dependencies.
// The analyzer errors you see are expected until the package is installed.

import 'dart:async';

import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

class RoutingService {
  static final FirebaseFirestore _firestore = FirebaseFirestore.instance;
  static const String _collection = 'document_routing';

  static String _normDept(String v) => v.trim().toUpperCase();

  /// Create a routing record in Firestore
  static Future<void> createRoute({
    required String documentName,
    required String documentType,
    required String imagePath,
    required String ocrContent,
    required String fromDepartment,
    required String fromUser,
    required String toDepartment,
    String? toUser,
    String? trackingId,
    String? mobileTimestamp,
    String? endLocation,
  }) async {
    try {
      final docRef = _firestore.collection(_collection).doc();
      final now = FieldValue.serverTimestamp();

      final fromDeptNorm = _normDept(fromDepartment);
      final toDeptNorm = _normDept(toDepartment);

      await docRef.set({
        'documentName': documentName,
        'documentType': documentType,
        'imagePath': imagePath,
        'ocrContent': ocrContent,
        'fromDepartment': fromDeptNorm,
        'fromUser': fromUser,
        'toDepartment': toDeptNorm,
        'toUser': toUser ?? '',
        'trackingId': trackingId ?? '',
        'mobileTimestamp': mobileTimestamp ?? '',
        'endLocation': endLocation ?? '',
        'status': 'pending', // pending, routed, confirmed
        'createdAt': now,
        'updatedAt': now,
        'id': docRef.id,
      });

      debugPrint('✅ Route created in Firestore: ${docRef.id}');
    } on FirebaseException catch (e) {
      debugPrint(
          '❌ Firestore createRoute failed (${e.code}): ${e.message ?? e.toString()}');
      // Fail silently to not disrupt main upload flow
    } catch (e) {
      debugPrint('❌ Error creating route in Firestore: $e');
    }
  }

  /// Subscribe to routing items for a department
  static Stream<List<Map<String, dynamic>>> listenForDepartment(
      String department) {
    final deptNorm = _normDept(department);

    final collection = _firestore.collection(_collection);
    final primaryQuery = collection
        .where('toDepartment', isEqualTo: deptNorm)
        .orderBy('createdAt', descending: true)
        .limit(50);
    final fallbackQuery =
        collection.where('toDepartment', isEqualTo: deptNorm).limit(200);

    final controller = StreamController<List<Map<String, dynamic>>>();
    StreamSubscription<QuerySnapshot<Map<String, dynamic>>>? sub;
    bool switchedToFallback = false;

    List<Map<String, dynamic>> mapDocs(
      List<QueryDocumentSnapshot<Map<String, dynamic>>> docs,
    ) {
      return docs
          .map((doc) {
            final data = doc.data();
            final String status = (data['status'] ?? 'pending').toString();
            if (status != 'pending' && status != 'routed') {
              return null;
            }

            final createdAtMs = _timestampToMs(data['createdAt']) |
                _mobileTimestampToMs(data['mobileTimestamp']?.toString());

            return {
              'id': doc.id,
              'fs_id': doc.id,
              'documentName': data['documentName'] ?? '',
              'documentType': data['documentType'] ?? '',
              'fromDepartment': data['fromDepartment'] ?? '',
              'fromUser': data['fromUser'] ?? '',
              'toDepartment': data['toDepartment'] ?? '',
              'toUser': data['toUser'] ?? '',
              'status': status,
              'ocrContent': data['ocrContent'] ?? '',
              'imagePath': data['imagePath'] ?? '',
              'mobileTimestamp': data['mobileTimestamp'] ?? '',
              'endLocation': data['endLocation'] ?? '',
              'currentHolder':
                  data['currentHolder'] ?? data['toDepartment'] ?? '',
              'createdAt': data['createdAt'],
              'createdAtMs': createdAtMs,
              'title': 'Routed Document from ${data['fromUser'] ?? 'Unknown'}',
              'subtitle':
                  '${data['documentType'] ?? 'Document'} • ${data['documentName'] ?? ''}',
              'time': _formatTimestamp(data['createdAt']),
              'icon': Icons.rule_folder_outlined,
              'recipientDepartment': deptNorm,
            };
          })
          .whereType<Map<String, dynamic>>()
          .toList();
    }

    void attachFallback() {
      sub = fallbackQuery.snapshots().listen(
        (snapshot) {
          final items = mapDocs(snapshot.docs)
            ..sort((a, b) => ((b['createdAtMs'] as int?) ?? 0)
                .compareTo((a['createdAtMs'] as int?) ?? 0));
          controller.add(items.take(50).toList());
        },
        onError: (e, st) {
          if (e is FirebaseException) {
            debugPrint(
                '❌ Firestore fallback listen failed (${e.code}): ${e.message ?? e.toString()}');
          } else {
            debugPrint('❌ Firestore fallback listen failed: $e');
          }
          controller.addError(e, st);
        },
      );
    }

    sub = primaryQuery.snapshots().listen(
      (snapshot) {
        controller.add(mapDocs(snapshot.docs));
      },
      onError: (e, st) async {
        if (!switchedToFallback &&
            e is FirebaseException &&
            e.code == 'failed-precondition') {
          switchedToFallback = true;
          debugPrint(
              '⚠️ Firestore index missing for routing listener; using fallback query for $deptNorm');
          await sub?.cancel();
          attachFallback();
          return;
        }

        if (e is FirebaseException) {
          debugPrint(
              '❌ Firestore listenForDepartment failed (${e.code}): ${e.message ?? e.toString()}');
        } else {
          debugPrint('❌ Firestore listenForDepartment failed: $e');
        }
        controller.addError(e, st);
      },
    );

    controller.onCancel = () async {
      await sub?.cancel();
    };

    return controller.stream;
  }

  /// Update status of a routed document
  static Future<void> updateStatus({
    required String department,
    required String id,
    required String status,
  }) async {
    try {
      await _firestore.collection(_collection).doc(id).update({
        'status': status,
        'updatedAt': FieldValue.serverTimestamp(),
      });
      debugPrint('✅ Route status updated: $id -> $status');
    } on FirebaseException catch (e) {
      debugPrint(
          '❌ Firestore updateStatus failed (${e.code}): ${e.message ?? e.toString()}');
    } catch (e) {
      debugPrint('❌ Error updating route status: $e');
    }
  }

  /// Helper to fetch current user/department from SharedPreferences
  static Future<Map<String, String>> getCurrentIdentity() async {
    final sp = await SharedPreferences.getInstance();
    return {
      'user': sp.getString('user_name') ?? 'User',
      'department': sp.getString('user_department') ?? 'General',
    };
  }

  /// Convert Firestore timestamp to milliseconds
  static int _timestampToMs(dynamic timestamp) {
    if (timestamp == null) return 0;
    try {
      // Timestamp is from cloud_firestore package
      if (timestamp is Timestamp) {
        return timestamp.toDate().millisecondsSinceEpoch;
      }
    } catch (e) {
      debugPrint('Error converting timestamp to ms: $e');
    }
    return 0;
  }

  static int _mobileTimestampToMs(String? mobileTimestamp) {
    final s = (mobileTimestamp ?? '').trim();
    if (s.isEmpty) return 0;
    // Expected format: MOBILE_<msSinceEpoch>_<microsRemainder>
    final parts = s.split('_');
    if (parts.length < 2) return 0;
    final ms = int.tryParse(parts[1]);
    return ms ?? 0;
  }

  /// Format Firestore timestamp for display
  static String _formatTimestamp(dynamic timestamp) {
    if (timestamp == null) return 'Unknown';
    try {
      // Timestamp is from cloud_firestore package
      if (timestamp is Timestamp) {
        final dt = timestamp.toDate();
        final now = DateTime.now();
        final diff = now.difference(dt);
        if (diff.inSeconds < 60) return 'Just now';
        if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
        if (diff.inHours < 24) return '${diff.inHours}h ago';
        if (diff.inDays < 7) return '${diff.inDays}d ago';
        return '${dt.day}/${dt.month}/${dt.year}';
      }
    } catch (e) {
      debugPrint('Error formatting timestamp: $e');
    }
    return 'Unknown';
  }
}
