<?php
header("Content-Type: application/json");
include 'db_connect.php';

$response = array();
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    switch ($action) {
        case 'login':
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';

            if (empty($email) || empty($password)) {
                $response['status'] = "error";
                $response['message'] = "Email and password are required.";
            } else {
                $sql = "SELECT id, name, email, password FROM users WHERE email = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    // Verify the password using password_verify()
                    if (password_verify($password, $user['password'])) {
                        $response['status'] = "success";
                        $response['message'] = "Login successful!";
                        $response['user'] = array(
                            'id' => $user['id'],
                            'name' => $user['name'],
                            'email' => $user['email']
                        );
                    } else {
                        $response['status'] = "error";
                        $response['message'] = "Invalid email or password.";
                    }
                } else {
                    $response['status'] = "error";
                    $response['message'] = "Invalid email or password.";
                }
            }
            break;

        case 'register':
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $password = isset($_POST['password']) ? $_POST['password'] : '';

            if (empty($name) || empty($email) || empty($password)) {
                $response['status'] = "error";
                $response['message'] = "All fields are required.";
            } else {
                // Check if email already exists
                $sql = "SELECT id FROM users WHERE email = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $response['status'] = "error";
                    $response['message'] = "Email already registered.";
                } else {
                    // Hash the password before storing
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "INSERT INTO users (name, email, password) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("sss", $name, $email, $hashed_password);

                    if ($stmt->execute()) {
                        $response['status'] = "success";
                        $response['message'] = "Registration successful!";
                    } else {
                        $response['status'] = "error";
                        $response['message'] = "Registration failed: " . $conn->error;
                    }
                }
            }
            break;

        default:
            $response['status'] = "error";
            $response['message'] = "Invalid action.";
    }
} else {
    $response['status'] = "error";
    $response['message'] = "Invalid request method.";
}

$conn->close();
echo json_encode($response);
?>
