import 'package:flutter/material.dart';
import 'package:intl/intl.dart'; // For date formatting

// You might want to pass the PerformanceRating object back to the previous page
// or handle its submission here. For this design, we'll just show input fields.

class AddRatingPage extends StatefulWidget {
  const AddRatingPage({super.key});

  @override
  State<AddRatingPage> createState() => _AddRatingPageState();
}

class _AddRatingPageState extends State<AddRatingPage> {
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _employeeNameController = TextEditingController();
  final TextEditingController _reviewerController = TextEditingController();
  final TextEditingController _feedbackSummaryController =
      TextEditingController();
  DateTime? _selectedDate;
  String? _overallRating;

  final List<String> _ratingOptions = [
    'Excellent',
    'Good',
    'Average',
    'Needs Improvement',
    'Poor'
  ];

  Future<void> _selectDate(BuildContext context) async {
    final DateTime? picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate ?? DateTime.now(),
      firstDate: DateTime(2000),
      lastDate: DateTime(2101),
    );
    if (picked != null && picked != _selectedDate) {
      setState(() {
        _selectedDate = picked;
      });
    }
  }

  void _submitForm() {
    if (_formKey.currentState!.validate()) {
      // Process data
      String employeeName = _employeeNameController.text;
      String reviewer = _reviewerController.text;
      String feedbackSummary = _feedbackSummaryController.text;

      // For demonstration, just print the values
      print('New Performance Rating:');
      print('Employee: $employeeName');
      print(
          'Date: ${_selectedDate != null ? DateFormat('yyyy-MM-dd').format(_selectedDate!) : 'Not selected'}');
      print('Rating: $_overallRating');
      print('Reviewer: $reviewer');
      print('Feedback: $feedbackSummary');

      // In a real application, you would save this data (e.g., to a database)
      // and then navigate back or show a success message.
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Performance Rating Added Successfully!')),
      );

      // Optionally navigate back after submission
      Navigator.pop(context);
    }
  }

  @override
  void dispose() {
    _employeeNameController.dispose();
    _reviewerController.dispose();
    _feedbackSummaryController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Add New Performance Rating'),
        backgroundColor: const Color(0xFF6868AC),
        foregroundColor: Colors.white,
      ),
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              Color(0xFFE0F7FA),
              Color(0xFFB2EBF2)
            ], // Light blue gradient
          ),
        ),
        child: Padding(
          padding: const EdgeInsets.all(16.0),
          child: Form(
            key: _formKey,
            child: ListView(
              children: [
                _buildTextField(
                  controller: _employeeNameController,
                  labelText: 'Employee Name',
                  icon: Icons.person,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter employee name';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                _buildDatePickerField(context),
                const SizedBox(height: 16),
                _buildDropdownField(),
                const SizedBox(height: 16),
                _buildTextField(
                  controller: _reviewerController,
                  labelText: 'Reviewer Name',
                  icon: Icons.person_outline,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter reviewer name';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                _buildTextField(
                  controller: _feedbackSummaryController,
                  labelText: 'Feedback Summary',
                  icon: Icons.feedback,
                  maxLines: 4,
                  validator: (value) {
                    if (value == null || value.isEmpty) {
                      return 'Please enter feedback summary';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 24),
                ElevatedButton.icon(
                  onPressed: _submitForm,
                  icon: const Icon(Icons.save),
                  label: const Text('Save Rating'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF6868AC),
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                    textStyle: const TextStyle(fontSize: 18),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildTextField({
    required TextEditingController controller,
    required String labelText,
    required IconData icon,
    int? maxLines,
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
      validator: validator,
    );
  }

  Widget _buildDatePickerField(BuildContext context) {
    return GestureDetector(
      onTap: () => _selectDate(context),
      child: AbsorbPointer(
        child: TextFormField(
          decoration: InputDecoration(
            labelText: _selectedDate == null
                ? 'Select Review Date'
                : 'Review Date: ${DateFormat('MMM dd, yyyy').format(_selectedDate!)}',
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
            if (_selectedDate == null) {
              return 'Please select a date';
            }
            return null;
          },
        ),
      ),
    );
  }

  Widget _buildDropdownField() {
    return DropdownButtonFormField<String>(
      initialValue: _overallRating,
      decoration: InputDecoration(
        labelText: 'Overall Rating',
        prefixIcon: const Icon(Icons.star_half, color: Color(0xFF6868AC)),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: BorderSide.none,
        ),
        filled: true,
        fillColor: Colors.white.withOpacity(0.9),
        contentPadding:
            const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
      ),
      hint: const Text('Select Overall Rating'),
      items: _ratingOptions.map((String rating) {
        return DropdownMenuItem<String>(
          value: rating,
          child: Text(rating),
        );
      }).toList(),
      onChanged: (String? newValue) {
        setState(() {
          _overallRating = newValue;
        });
      },
      validator: (value) {
        if (value == null || value.isEmpty) {
          return 'Please select an overall rating';
        }
        return null;
      },
    );
  }
}
