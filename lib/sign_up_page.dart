import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart'; // Import for SystemChrome
import 'package:flutter_application_7/local_database.dart'; // Assuming this file exists and provides LocalStorage
import 'package:flutter_application_7/login_page.dart';

class SignUpPage extends StatefulWidget {
  const SignUpPage({super.key});

  @override
  State<SignUpPage> createState() => _SignUpPageState();
}

class _SignUpPageState extends State<SignUpPage> {
  TextEditingController nameController = TextEditingController();
  TextEditingController emailController = TextEditingController();
  TextEditingController passwordController = TextEditingController();

  bool isPasswordVisible = false;

  @override
  void initState() {
    super.initState();
    // Set system UI overlay style for a clean look
    SystemChrome.setSystemUIOverlayStyle(
      const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent, // Make status bar transparent
        statusBarIconBrightness:
            Brightness.dark, // Use dark icons for light background
      ),
    );
  }

  @override
  void dispose() {
    nameController.dispose();
    emailController.dispose();
    passwordController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors
          .transparent, // Make scaffold transparent to show container gradient
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
            colors: [
              Color(0xFFE0F7FA), // Very light cyan
              Color(0xFF81D4FA), // Light blue
            ],
          ),
        ),
        child: Center(
          child: SingleChildScrollView(
            padding:
                const EdgeInsets.symmetric(horizontal: 25.0, vertical: 40.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                // Logo and Title Section
                _buildTopSection(),
                const SizedBox(height: 40),

                // Sign Up Form Card
                _buildSignUpFormCard(),
                const SizedBox(height: 30),

                // Sign In Text
                _buildSignInText(),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildTopSection() {
    return Column(
      children: [
        Image.asset(
          "assets/images/Logo.png", // Path to your image
          width: 150, // Adjusted width for modern look
          height: 120, // Adjusted height
        ),
        const SizedBox(height: 15),
        const Text(
          "CHRMO DOCUMENT TRACKING", // Corrected title
          textAlign: TextAlign.center,
          style: TextStyle(
            fontFamily: "Roboto", // Ensure this font is available or remove
            color: Color.fromARGB(255, 25, 25, 112), // Darker blue for text
            fontSize: 26, // Larger font size
            fontWeight: FontWeight.w800, // Heavier weight
            letterSpacing: 1.2, // Slightly increased letter spacing
            shadows: [
              Shadow(
                offset: Offset(1.0, 1.0),
                blurRadius: 3.0,
                color: Color.fromARGB(100, 0, 0, 0), // Subtle shadow
              ),
            ],
          ),
        ),
        const SizedBox(height: 10),
        const Text(
          "Register to Manage Your Documents",
          textAlign: TextAlign.center,
          style: TextStyle(
            color: Color.fromARGB(255, 50, 50, 50),
            fontSize: 16,
            fontWeight: FontWeight.w500,
          ),
        ),
      ],
    );
  }

  Widget _buildSignUpFormCard() {
    return Card(
      elevation: 12, // Increased elevation for more depth
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(25), // More rounded corners
      ),
      margin: const EdgeInsets.symmetric(horizontal: 10), // Margin for card
      child: Padding(
        padding: const EdgeInsets.all(30.0), // Increased padding inside card
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              "Create Your Account",
              style: TextStyle(
                color: Color(0xFF52528A),
                fontSize: 22,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 30),
            _buildInputField(
              controller: nameController,
              labelText: "Full Name",
              icon: Icons.person,
              keyboardType: TextInputType.name,
            ),
            const SizedBox(height: 20),
            _buildInputField(
              controller: emailController,
              labelText: "Email Address",
              icon: Icons.email,
              keyboardType: TextInputType.emailAddress,
            ),
            const SizedBox(height: 20),
            _buildInputField(
              controller: passwordController,
              labelText: "Password",
              icon: Icons.lock,
              isPassword: true,
            ),
            const SizedBox(height: 30),
            _buildSignUpButton(),
          ],
        ),
      ),
    );
  }

  Widget _buildInputField({
    required TextEditingController controller,
    required String labelText,
    required IconData icon,
    bool isPassword = false,
    TextInputType keyboardType = TextInputType.text,
  }) {
    return TextField(
      controller: controller,
      obscureText: isPassword ? !isPasswordVisible : false,
      keyboardType: keyboardType,
      style: const TextStyle(color: Colors.black87),
      decoration: InputDecoration(
        labelText: labelText,
        labelStyle: TextStyle(color: Colors.grey.shade600),
        prefixIcon: Icon(icon, color: const Color(0xFF6868AC)),
        suffixIcon: isPassword
            ? IconButton(
                icon: Icon(
                  isPasswordVisible ? Icons.visibility : Icons.visibility_off,
                  color: Colors.grey.shade600,
                ),
                onPressed: () {
                  setState(() {
                    isPasswordVisible = !isPasswordVisible;
                  });
                },
              )
            : null,
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: BorderSide(
              color: const Color(0xFF6868AC).withOpacity(0.25), width: 1),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(12),
          borderSide: const BorderSide(color: Color(0xFF6868AC), width: 2),
        ),
        fillColor: const Color(0xFF6868AC).withOpacity(0.5), // Light fill color
        filled: true,
        contentPadding:
            const EdgeInsets.symmetric(vertical: 15, horizontal: 10),
      ),
    );
  }

  Widget _buildSignUpButton() {
    return Container(
      width: double.infinity, // Make button full width
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [Color(0xFF6868AC), Color(0xFF52528A)],
          begin: Alignment.centerLeft,
          end: Alignment.centerRight,
        ),
        borderRadius: BorderRadius.circular(15), // Rounded corners for button
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF6868AC).withOpacity(0.6),
            spreadRadius: 2,
            blurRadius: 10,
            offset: const Offset(0, 5),
          ),
        ],
      ),
      child: ElevatedButton(
        onPressed: _registerUser, // Call the register function
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors
              .transparent, // Make button background transparent to show gradient
          shadowColor: Colors.transparent, // Remove default shadow
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(15),
          ),
          padding:
              const EdgeInsets.symmetric(vertical: 18), // Increased padding
        ),
        child: const Text(
          "SIGN UP",
          style: TextStyle(
            color: Colors.white,
            fontSize: 18,
            fontWeight: FontWeight.bold,
            letterSpacing: 1.5,
          ),
        ),
      ),
    );
  }

  Widget _buildSignInText() {
    return RichText(
      textAlign: TextAlign.center,
      text: TextSpan(
        text: "Already have an account? ",
        style: const TextStyle(
          color: Colors.black87, // Darker text for better readability
          fontSize: 16,
        ),
        children: [
          TextSpan(
            text: "Sign In",
            style: const TextStyle(
              color: Color(0xFF6868AC), // Blue for clickable text
              fontWeight: FontWeight.bold,
              decoration:
                  TextDecoration.underline, // Underline for clickable text
            ),
            recognizer: TapGestureRecognizer()
              ..onTap = () {
                Navigator.push(
                  context,
                  MaterialPageRoute(builder: (context) => const LoginPage()),
                );
              },
          ),
        ],
      ),
    );
  }

  // Handle the registration of the user
  void _registerUser() async {
    final name = nameController.text.trim();
    final email = emailController.text.trim();
    final password = passwordController.text.trim();

    // Simple email validation regex
    final emailRegex =
        RegExp(r"^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$");

    if (name.isEmpty || email.isEmpty || password.isEmpty) {
      // Show message if any field is empty
      _showMessage('Please fill out all fields', success: false);
    } else if (!emailRegex.hasMatch(email)) {
      // Show message if email format is invalid
      _showMessage('Please enter a valid email address', success: false);
    } else {
      // Create a new user
      final newUser = {
        'name': name,
        'email': email,
        'password': password,
      };

      // Save the new user to local storage
      final localStorage = LocalStorage();
      await localStorage.writeUser(newUser);

      // Display success message
      debugPrint('User registered successfully!');
      _showMessage('Registration successful! Please log in.', success: true);

      // Navigate to the login page after registration
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (context) => const LoginPage()),
      );
    }
  }

  // Function to show the message
  void _showMessage(String message, {bool success = false}) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: success ? Colors.green.shade600 : Colors.red.shade600,
        behavior:
            SnackBarBehavior.floating, // Make it float for better visibility
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        margin: const EdgeInsets.all(20),
      ),
    );
  }
}
