import 'package:flutter/material.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      home: LandingPage(),
    );
  }
}

// ignore: must_be_immutable
class LandingPage extends StatelessWidget {
  LandingPage({super.key});
  String username = '';
  String email = '';

  @override
  Widget build(BuildContext context) {
    final mediaSize = MediaQuery.of(context).size;
    final args =
        ModalRoute.of(context)!.settings.arguments as Map<String, dynamic>;
    username = args['username'];
    email = args['email'];

    Widget buildTop() {
      return SizedBox(
        width: mediaSize.width,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 100),
              child: Image.asset(
                "assets/images/Logo.png", // Path to your image
                width: 200, // Set width for the image
                height: 150, // Set height for the image
              ),
            ),
          ],
        ),
      );
    }

    return WillPopScope(
      onWillPop: () async => false, // Prevent back navigation
      child: Scaffold(
        body: Stack(
          children: [
            // Background image covering the entire page
            Container(
              width: double.infinity,
              height: double.infinity,
              decoration: const BoxDecoration(
                image: DecorationImage(
                  image: AssetImage(
                      "assets/images/background.jpg"), // Your background image path
                  fit: BoxFit.cover, // Ensures the image covers the screen
                ),
              ),
            ),
            // Foreground content
            SingleChildScrollView(
              child: Column(
                children: [
                  buildTop(),
                  const SizedBox(height: 10),
                  const Text(
                    "CHROM",
                    style: TextStyle(
                      fontFamily: "Bold",
                      shadows: [
                        Shadow(
                          offset: Offset(
                              2.0, 2.0), // Horizontal and vertical offset
                          blurRadius: 4.0, // Blur effect
                          color: Color.fromARGB(255, 9, 9, 9), // Shadow color
                        ),
                      ],
                      fontSize: 32,
                      fontWeight: FontWeight.bold,
                      color: Color.fromARGB(255, 3, 3,
                          3), // White text for better visibility on dark background
                    ),
                  ),
                  const SizedBox(height: 30),
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 20.0),
                    child: Text(
                      "",
                      textAlign: TextAlign.center,
                      style: TextStyle(
                          fontSize: 17,
                          color: Color.fromARGB(255, 0, 0, 0)), // White text
                    ),
                  ),
                  const SizedBox(height: 20),
                  ElevatedButton(
                    onPressed: () {
                      Navigator.pushNamed(context, '/home',
                          arguments: {'username': username, 'email': email});
                    },
                    style: ElevatedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(
                          vertical: 15, horizontal: 40),
                      backgroundColor: const Color.fromARGB(255, 255, 255, 255),
                      shape: RoundedRectangleBorder(
                        borderRadius:
                            BorderRadius.circular(20), // Rounded corners
                        side: const BorderSide(
                          color: Color.fromARGB(255, 8, 8, 8), // Border color
                          width: 1, // Border width
                        ),
                      ),
                    ),
                    child: const Text(
                      "Get Started",
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color.fromARGB(255, 7, 7, 7),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
