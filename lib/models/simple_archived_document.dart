// lib/models/simple_archived_document.dart

class ArchivedDocument {
  final String documentName;
  final String department;
  final String type;
  final String status;
  final DateTime dateArchived;
  final String size;

  const ArchivedDocument({
    required this.documentName,
    required this.department,
    required this.type,
    required this.status,
    required this.dateArchived,
    required this.size,
  });
}
