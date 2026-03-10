import 'dart:io';
import 'package:flutter/material.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

// Test widget to verify department-specific storage
class TestDepartmentStorage extends StatefulWidget {
  const TestDepartmentStorage({super.key});

  @override
  State<TestDepartmentStorage> createState() => _TestDepartmentStorageState();
}

class _TestDepartmentStorageState extends State<TestDepartmentStorage> {
  String _currentDepartment = 'Unknown';
  List<String> _departmentFolders = [];
  Map<String, List<String>> _departmentFiles = {};

  @override
  void initState() {
    super.initState();
    _loadDepartmentInfo();
  }

  Future<void> _loadDepartmentInfo() async {
    // Get current user's department
    final prefs = await SharedPreferences.getInstance();
    final department = prefs.getString('user_department') ?? 'General';

    // Get all department folders
    final Directory extDir = await getApplicationDocumentsDirectory();
    final String archiveBasePath = '${extDir.path}/Archive';
    final Directory archiveDir = Directory(archiveBasePath);

    List<String> folders = [];
    Map<String, List<String>> files = {};

    if (await archiveDir.exists()) {
      final List<FileSystemEntity> entities = archiveDir.listSync();

      for (FileSystemEntity entity in entities) {
        if (entity is Directory) {
          final String folderName = entity.path.split('/').last;
          folders.add(folderName);

          // Get files in this department folder
          final List<FileSystemEntity> departmentFiles = entity.listSync();
          files[folderName] = departmentFiles
              .whereType<File>()
              .map((f) => f.path.split('/').last)
              .toList();
        }
      }
    }

    setState(() {
      _currentDepartment = department;
      _departmentFolders = folders;
      _departmentFiles = files;
    });
  }

  Future<void> _createTestFile() async {
    final prefs = await SharedPreferences.getInstance();
    final department = prefs.getString('user_department') ?? 'General';

    final Directory extDir = await getApplicationDocumentsDirectory();
    final String archivePath = '${extDir.path}/Archive/$department';
    await Directory(archivePath).create(recursive: true);

    final String timestamp = DateTime.now().millisecondsSinceEpoch.toString();
    final String testFilePath = '$archivePath/TEST_$timestamp.txt';

    await File(testFilePath).writeAsString(
        'Test file created by $department department at ${DateTime.now()}');

    _loadDepartmentInfo(); // Refresh the display

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Test file created in $department department folder'),
        backgroundColor: Colors.green,
      ),
    );
  }

  Future<void> _switchDepartment(String newDepartment) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('user_department', newDepartment);
    _loadDepartmentInfo();

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('Switched to $newDepartment department'),
        backgroundColor: const Color(0xFF6868AC),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Department Storage Test'),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
      ),
      body: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Current Department Info
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Current Department: $_currentDepartment',
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 8),
                    ElevatedButton(
                      onPressed: _createTestFile,
                      child: const Text('Create Test File in My Department'),
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Department Switcher
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Switch Department (for testing):',
                      style: TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    const SizedBox(height: 8),
                    Wrap(
                      spacing: 8,
                      children: [
                        'CPDO',
                        'GSO',
                        'CBO',
                        'CTO',
                        'CACCO',
                        'CADO',
                        'CMO'
                      ]
                          .map((dept) => ElevatedButton(
                                onPressed: () => _switchDepartment(dept),
                                style: ElevatedButton.styleFrom(
                                  backgroundColor: _currentDepartment == dept
                                      ? Colors.green
                                      : Colors.grey,
                                ),
                                child: Text(dept),
                              ))
                          .toList(),
                    ),
                  ],
                ),
              ),
            ),

            const SizedBox(height: 16),

            // Department Folders and Files
            const Text(
              'Department Folders & Files:',
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
              ),
            ),

            const SizedBox(height: 8),

            Expanded(
              child: _departmentFolders.isEmpty
                  ? const Center(
                      child: Text(
                        'No department folders found.\nCreate some files first!',
                        textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 16),
                      ),
                    )
                  : ListView.builder(
                      itemCount: _departmentFolders.length,
                      itemBuilder: (context, index) {
                        final department = _departmentFolders[index];
                        final files = _departmentFiles[department] ?? [];

                        return Card(
                          child: ExpansionTile(
                            title: Text(
                              '$department Department',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                color: _currentDepartment == department
                                    ? Colors.green
                                    : Colors.black,
                              ),
                            ),
                            subtitle: Text('${files.length} files'),
                            children: files.isEmpty
                                ? [
                                    const Padding(
                                      padding: EdgeInsets.all(16.0),
                                      child:
                                          Text('No files in this department'),
                                    )
                                  ]
                                : files
                                    .map((file) => ListTile(
                                          leading: const Icon(
                                              Icons.insert_drive_file),
                                          title: Text(file),
                                          dense: true,
                                        ))
                                    .toList(),
                          ),
                        );
                      },
                    ),
            ),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: _loadDepartmentInfo,
        child: const Icon(Icons.refresh),
      ),
    );
  }
}

void main() {
  runApp(const MaterialApp(
    home: TestDepartmentStorage(),
    title: 'Department Storage Test',
  ));
}
