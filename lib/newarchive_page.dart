// newarchive_page.dart
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import 'models/simple_archived_document.dart';

class NewArchivePage extends StatefulWidget {
  // Callback to return the newly created ArchivedDocument
  final ValueChanged<ArchivedDocument> onSave;

  const NewArchivePage({super.key, required this.onSave});

  @override
  State<NewArchivePage> createState() => _NewArchivePageState();
}

class _NewArchivePageState extends State<NewArchivePage> {
  final _formKey = GlobalKey<FormState>(); // Key for form validation

  // Text Editing Controllers for Document Archive
  final TextEditingController _documentNameController = TextEditingController();
  final TextEditingController _sizeController = TextEditingController();
  final TextEditingController _dateArchivedController = TextEditingController();

  // Dropdown values for Document Archive
  String? _selectedDepartment;
  String? _selectedType;
  String? _selectedStatus;
  DateTime? _selectedDate;

  // Dropdown lists for Document Archive
  final List<String> _departments = [
    'Human Resources',
    'Finance',
    'IT Department',
    'Operations',
    'Marketing',
    'Legal',
    'Administration'
  ];
  final List<String> _documentTypes = [
    'Report',
    'Policy',
    'Spreadsheet',
    'Image',
    'Presentation',
    'Contract',
    'Other'
  ];
  final List<String> _documentStatuses = [
    'Archived',
    'Active',
    'Draft',
    'Pending Review'
  ];

  @override
  void dispose() {
    _documentNameController.dispose();
    _sizeController.dispose();
    _dateArchivedController.dispose();
    super.dispose();
  }

  // Function to show date picker
  Future<void> _selectDate(BuildContext context) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate ?? DateTime.now(),
      firstDate: DateTime(1900),
      lastDate: DateTime(2101),
      builder: (context, child) {
        return Theme(
          data: ThemeData.light().copyWith(
            colorScheme: const ColorScheme.light(
              primary: Color(0xFF6868AC),
              onPrimary: Colors.white,
              onSurface: Colors.black87,
            ),
            textButtonTheme: TextButtonThemeData(
              style: TextButton.styleFrom(
                foregroundColor: const Color(0xFF6868AC),
              ),
            ),
          ),
          child: child!,
        );
      },
    );
    if (picked != null && picked != _selectedDate) {
      setState(() {
        _selectedDate = picked;
        _dateArchivedController.text = DateFormat('yyyy-MM-dd').format(picked);
      });
    }
  }

  // Function to handle form submission
  void _submitForm() {
    if (_formKey.currentState!.validate()) {
      // Create a new ArchivedDocument object
      final newDocument = ArchivedDocument(
        documentName: _documentNameController.text,
        department: _selectedDepartment ?? 'N/A',
        type: _selectedType ?? 'N/A',
        status: _selectedStatus ?? 'Archived',
        dateArchived: _selectedDate ?? DateTime.now(),
        size: _sizeController.text.isEmpty ? '0 MB' : _sizeController.text,
      );

      // Pass the new document back to the previous page using the onSave callback
      widget.onSave(newDocument);

      // Optionally clear the form
      _formKey.currentState!.reset();
      _documentNameController.clear();
      _sizeController.clear();
      _dateArchivedController.clear();
      setState(() {
        _selectedDepartment = null;
        _selectedType = null;
        _selectedStatus = null;
        _selectedDate = null;
      });

      // Pop the current route (New Archive Page)
      Navigator.pop(context);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        backgroundColor: const Color(0xFF6868AC),
        elevation: 0,
        leading: IconButton(
          icon:
              const Icon(Icons.arrow_back_ios_new_rounded, color: Colors.white),
          onPressed: () => Navigator.pop(context),
        ),
        title: const Text(
          'NEW ARCHIVE ENTRY', // Changed title to reflect document archiving
          style: TextStyle(
            color: Colors.white,
            fontWeight: FontWeight.bold,
            fontSize: 20,
          ),
        ),
        centerTitle: true,
        actions: [
          IconButton(
            icon: const Icon(Icons.settings_outlined,
                color: Colors.white), // Changed icon
            onPressed: () {
              // Handle settings icon tap
            },
          ),
        ],
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [const Color(0xFF6868AC).withOpacity(0.15), Colors.white],
          ),
        ),
        child: Center(
          child: Padding(
            padding:
                const EdgeInsets.symmetric(horizontal: 16.0, vertical: 20.0),
            child: Card(
              elevation: 8,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(15),
              ),
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(24.0),
                child: Form(
                  key: _formKey,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      _buildSectionTitle('Document Details'),
                      const SizedBox(height: 15),
                      _buildFormField(
                        controller: _documentNameController,
                        labelText: 'Document Name',
                        hintText: 'Enter document name',
                        icon: Icons.description_outlined,
                        validator: (value) {
                          if (value == null || value.isEmpty) {
                            return 'Please enter document name';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 15),
                      _buildFormField(
                        isDropdown: true,
                        labelText: 'Department',
                        hintText: 'Select Department',
                        icon: Icons.apartment_outlined,
                        dropdownValue: _selectedDepartment,
                        dropdownItems: _departments,
                        onDropdownChanged: (String? newValue) {
                          setState(() {
                            _selectedDepartment = newValue;
                          });
                        },
                        validator: (value) {
                          if (value == null || value.isEmpty) {
                            return 'Please select department';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 15),
                      _buildFormField(
                        isDropdown: true,
                        labelText: 'Document Type',
                        hintText: 'Select Document Type',
                        icon: Icons.category_outlined,
                        dropdownValue: _selectedType,
                        dropdownItems: _documentTypes,
                        onDropdownChanged: (String? newValue) {
                          setState(() {
                            _selectedType = newValue;
                          });
                        },
                        validator: (value) {
                          if (value == null || value.isEmpty) {
                            return 'Please select document type';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 15),
                      _buildFormField(
                        isDropdown: true,
                        labelText: 'Status',
                        hintText: 'Select Status',
                        icon: Icons.check_circle_outline,
                        dropdownValue: _selectedStatus,
                        dropdownItems: _documentStatuses,
                        onDropdownChanged: (String? newValue) {
                          setState(() {
                            _selectedStatus = newValue;
                          });
                        },
                        validator: (value) {
                          if (value == null || value.isEmpty) {
                            return 'Please select status';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 15),
                      _buildFormField(
                        controller: _sizeController,
                        labelText: 'File Size',
                        hintText: 'e.g., 2.5 MB or 100 KB',
                        icon: Icons.data_usage_outlined,
                        keyboardType: TextInputType.text,
                        validator: (value) {
                          if (value == null || value.isEmpty) {
                            return 'Please enter file size';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 15),
                      _buildFormField(
                        controller: _dateArchivedController,
                        labelText: 'Archived Date',
                        hintText: 'Select archived date',
                        icon: Icons.calendar_today_outlined,
                        readOnly: true,
                        onTap: () => _selectDate(context),
                        validator: (value) {
                          if (value == null || value.isEmpty) {
                            return 'Please select archived date';
                          }
                          return null;
                        },
                      ),
                      const SizedBox(height: 40),
                      Center(
                        child: ElevatedButton(
                          onPressed: _submitForm,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF6868AC),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(
                                horizontal: 60, vertical: 15),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10),
                            ),
                            elevation: 5,
                          ),
                          child: const Text(
                            'CREATE ARCHIVE', // Changed button text
                            style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                              letterSpacing: 1.2,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  // Helper widget for consistent section titles
  Widget _buildSectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8.0),
      child: Text(
        title,
        style: const TextStyle(
          fontSize: 20,
          fontWeight: FontWeight.bold,
          color: Color(0xFF52528A),
        ),
      ),
    );
  }

  // Unified helper widget for TextFormFields and DropdownButtonFormFields
  Widget _buildFormField({
    TextEditingController? controller,
    String? labelText,
    String? hintText,
    IconData? icon,
    TextInputType keyboardType = TextInputType.text,
    int maxLines = 1,
    String? Function(String?)? validator,
    bool readOnly = false,
    VoidCallback? onTap, // For date picker or similar fields
    bool isDropdown = false,
    String? dropdownValue,
    List<String>? dropdownItems,
    ValueChanged<String?>? onDropdownChanged,
  }) {
    // Common input decoration for all fields
    final InputDecoration commonDecoration = InputDecoration(
      labelText: labelText,
      hintText: hintText,
      prefixIcon:
          icon != null ? Icon(icon, color: const Color(0xFF6868AC)) : null,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide.none, // No default border
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide.none,
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: BorderSide.none,
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Colors.red, width: 1.5),
      ),
      focusedErrorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Colors.red, width: 2.0),
      ),
      filled: true,
      fillColor: const Color(0xFF6868AC)
          .withOpacity(0.08), // Light blue background for fields
      labelStyle: const TextStyle(color: Color(0xFF6868AC)),
      hintStyle: TextStyle(color: Colors.grey.shade500),
      contentPadding:
          const EdgeInsets.symmetric(vertical: 15.0, horizontal: 15.0),
    );

    return Container(
      decoration: BoxDecoration(
        color: const Color(0xFF6868AC)
            .withOpacity(0.08), // Background color for the field container
        borderRadius: BorderRadius.circular(10),
        border: Border.all(
            color: const Color(0xFF6868AC).withOpacity(0.25),
            width: 1), // Subtle border
      ),
      child: isDropdown
          ? DropdownButtonFormField<String>(
              decoration: commonDecoration,
              initialValue: dropdownValue,
              hint: Text(hintText ?? 'Select an option'),
              items: dropdownItems?.map((String item) {
                return DropdownMenuItem<String>(
                  value: item,
                  child: Text(item),
                );
              }).toList(),
              onChanged: onDropdownChanged,
              validator: validator,
              borderRadius: BorderRadius.circular(10),
              icon: const Icon(Icons.arrow_drop_down, color: Color(0xFF6868AC)),
            )
          : TextFormField(
              controller: controller,
              decoration: commonDecoration,
              keyboardType: keyboardType,
              maxLines: maxLines,
              validator: validator,
              readOnly: readOnly,
              onTap: onTap, // Only used for fields like date picker
              style: const TextStyle(color: Colors.black87),
            ),
    );
  }
}
