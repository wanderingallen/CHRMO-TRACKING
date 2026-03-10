// add_personal_data_page.dart
import 'package:flutter/material.dart';
import 'package:intl/intl.dart'; // For date formatting

class AddPersonalDataPage extends StatefulWidget {
  const AddPersonalDataPage({super.key});

  @override
  State<AddPersonalDataPage> createState() => _AddPersonalDataPageState();
}

class _AddPersonalDataPageState extends State<AddPersonalDataPage> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _fullNameController = TextEditingController();
  final TextEditingController _employeeIdController = TextEditingController();
  DateTime? _dateOfBirth;
  final TextEditingController _addressController = TextEditingController();
  final TextEditingController _phoneNumberController = TextEditingController();
  final TextEditingController _emailController = TextEditingController();
  final TextEditingController _emergencyContactNameController =
      TextEditingController();
  final TextEditingController _emergencyContactNumberController =
      TextEditingController();
  DateTime? _hireDate;
  final TextEditingController _positionController = TextEditingController();

  Future<void> _selectDate(BuildContext context, {required bool isDOB}) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: isDOB
          ? (_dateOfBirth ?? DateTime.now())
          : (_hireDate ?? DateTime.now()),
      firstDate: DateTime(1900),
      lastDate: DateTime(2101),
    );
    if (picked != null) {
      setState(() {
        if (isDOB) {
          _dateOfBirth = picked;
        } else {
          _hireDate = picked;
        }
      });
    }
  }

  void _submitForm() {
    if (_formKey.currentState!.validate()) {
      // Process data
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Processing Data')),
      );
      // Example of how you might create a PersonalData object
      // PersonalData newPersonalData = PersonalData(
      //   fullName: _fullNameController.text,
      //   employeeId: _employeeIdController.text,
      //   dateOfBirth: _dateOfBirth!,
      //   address: _addressController.text,
      //   phoneNumber: _phoneNumberController.text,
      //   email: _emailController.text,
      //   emergencyContactName: _emergencyContactNameController.text,
      //   emergencyContactNumber: _emergencyContactNumberController.text,
      //   hireDate: _hireDate!,
      //   position: _positionController.text,
      // );
      //
      // print('New Personal Data: $newPersonalData');

      // Navigate back to PersonalDataPage
      Navigator.pop(context);
    }
  }

  @override
  void dispose() {
    _fullNameController.dispose();
    _employeeIdController.dispose();
    _addressController.dispose();
    _phoneNumberController.dispose();
    _emailController.dispose();
    _emergencyContactNameController.dispose();
    _emergencyContactNumberController.dispose();
    _positionController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Add Personal Data'),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white, // Ensures title and icon are white
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFE3F2FF), // Very light blue
              Color(0xFFBBDEFB), // Slightly darker light blue
            ],
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(16.0),
          child: Form(
            key: _formKey,
            child: ListView(
              children: <Widget>[
                _buildTextField(
                  _fullNameController,
                  'Full Name',
                  Icons.person,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter full name';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 15),
                _buildTextField(
                  _employeeIdController,
                  'Employee ID',
                  Icons.badge,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter employee ID';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 15),
                _buildDatePickerField(
                  context,
                  isDOB: true,
                  labelText: 'Date of Birth',
                  selectedDate: _dateOfBirth,
                ),
                const SizedBox(height: 15),
                _buildTextField(
                  _addressController,
                  'Address',
                  Icons.home,
                  maxLines: 3,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter address';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 15),
                _buildTextField(
                  _phoneNumberController,
                  'Phone Number',
                  Icons.phone,
                  keyboardType: TextInputType.phone,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter phone number';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 15),
                _buildTextField(
                  _emailController,
                  'Email',
                  Icons.email,
                  keyboardType: TextInputType.emailAddress,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter email';
                    }
                    if (!value.contains('@')) {
                      return 'Please enter a valid email';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 15),
                _buildTextField(
                  _emergencyContactNameController,
                  'Emergency Contact Name',
                  Icons.contact_emergency,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter emergency contact name';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 15),
                _buildTextField(
                  _emergencyContactNumberController,
                  'Emergency Contact Number',
                  Icons.phone_in_talk,
                  keyboardType: TextInputType.phone,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter emergency contact number';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 15),
                _buildDatePickerField(
                  context,
                  isDOB: false,
                  labelText: 'Hire Date',
                  selectedDate: _hireDate,
                ),
                const SizedBox(height: 15),
                _buildTextField(
                  _positionController,
                  'Position',
                  Icons.work,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter position';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 30),
                ElevatedButton.icon(
                  onPressed: _submitForm,
                  icon: const Icon(Icons.save, color: Colors.white),
                  label: const Text('Save Personal Data',
                      style: TextStyle(fontSize: 18, color: Colors.white)),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF6868AC),
                    padding: const EdgeInsets.symmetric(vertical: 15),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildTextField(
    TextEditingController controller,
    String labelText,
    IconData icon, {
    int maxLines = 1,
    TextInputType keyboardType = TextInputType.text,
    String? Function(String?)? validator,
  }) {
    return TextFormField(
      controller: controller,
      decoration: InputDecoration(
        labelText: labelText,
        prefixIcon: Icon(icon, color: const Color(0xFF6868AC)),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: BorderSide.none,
        ),
        filled: true,
        fillColor: Colors.white.withOpacity(0.9),
        contentPadding:
            const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
      ),
      maxLines: maxLines,
      keyboardType: keyboardType,
      validator: validator,
    );
  }

  Widget _buildDatePickerField(
    BuildContext context, {
    required bool isDOB,
    required String labelText,
    required DateTime? selectedDate,
  }) {
    return GestureDetector(
      onTap: () => _selectDate(context, isDOB: isDOB),
      child: AbsorbPointer(
        child: TextFormField(
          decoration: InputDecoration(
            labelText: selectedDate == null
                ? labelText
                : '$labelText: ${DateFormat('MMM dd,yyyy').format(selectedDate)}',
            prefixIcon:
                const Icon(Icons.calendar_today, color: Color(0xFF6868AC)),
            border: OutlineInputBorder(
              borderRadius: BorderRadius.circular(10),
              borderSide: BorderSide.none,
            ),
            filled: true,
            fillColor: Colors.white.withOpacity(0.9),
            contentPadding:
                const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
          ),
          validator: (value) {
            // This validator checks if selectedDate is null AFTER the date picker is closed.
            // The date picker itself sets the date.
            if (selectedDate == null) {
              return 'Please select a date';
            }
            return null;
          },
        ),
      ),
    );
  }
}
