import 'dart:convert';
import 'dart:io';
import 'package:path_provider/path_provider.dart';

class LocalStorage {
  // Get the path of the file where user data will be stored
  Future<File> _getLocalFile() async {
    final directory = await getApplicationDocumentsDirectory();
    final path = directory.path;
    return File('$path/users.json');
  }

  // Read the stored users data from the file
  Future<List<Map<String, dynamic>>> readUsers() async {
    try {
      final file = await _getLocalFile();
      if (await file.exists()) {
        final contents = await file.readAsString();
        final List<dynamic> jsonData = json.decode(contents);
        return List<Map<String, dynamic>>.from(jsonData);
      } else {
        return [];
      }
    } catch (e) {
      return [];
    }
  }

  // Write new user data to the file
  Future<void> writeUser(Map<String, dynamic> newUser) async {
    final users = await readUsers();
    users.add(newUser);
    final file = await _getLocalFile();
    await file.writeAsString(json.encode(users));
  }

  // Write all users data to the file
  Future<void> writeUsers(List<Map<String, dynamic>> users) async {
    final file = await _getLocalFile();
    await file.writeAsString(json.encode(users));
  }
}
