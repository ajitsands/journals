<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

/**
 * Checks if a user is logged in
 * @return bool
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Gets the current logged-in user details
 * @return array|null
 */
function get_logged_in_user() {
    global $pdo;
    if (!is_logged_in()) {
        return null;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, username, email, fullname, role, subject_domain, is_blocked FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user && $user['is_blocked']) {
            session_unset();
            session_destroy();
            return null;
        }
        return $user;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Requires a specific role or list of roles to access a page.
 * Redirects if unauthorized.
 * @param string|array $allowed_roles
 */
function require_role($allowed_roles) {
    if (!is_logged_in()) {
        header("Location: /login.php");
        exit;
    }
    
    if (is_string($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    
    $user = get_logged_in_user();
    if (!$user || !in_array($user['role'], $allowed_roles)) {
        // Unauthorized role, redirect to appropriate index
        header("Location: /index.php?error=unauthorized");
        exit;
    }
}

/**
 * Clean user inputs
 * @param string $data
 * @return string
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Register a new user
 * @param string $username
 * @param string $email
 * @param string $password
 * @param string $fullname
 * @param string $role
 * @param string|null $subject_domain
 * @param string|null $phone
 * @return array [success => bool, message => string]
 */
function register_user($username, $email, $password, $fullname, $role, $subject_domain = null, $phone = null) {
    global $pdo;
    
    // Validate inputs
    if (empty($username) || empty($email) || empty($password) || empty($fullname) || empty($role)) {
        return ['success' => false, 'message' => 'All required fields must be filled out.'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email address format.'];
    }
    
    if (!in_array($role, ['author', 'reviewer'])) {
        return ['success' => false, 'message' => 'Invalid user role selected.'];
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    
    try {
        // Check for duplicate username or email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }
        
        // Insert new user
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password, fullname, role, subject_domain, phone) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $hashed_password, $fullname, $role, $subject_domain, $phone]);
        
        return ['success' => true, 'message' => 'Registration successful! You can now log in.'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Registration failed. Please try again. Error: ' . $e->getMessage()];
    }
}

/**
 * Logs in a user
 * @param string $email_or_username
 * @param string $password
 * @return array [success => bool, message => string]
 */
function login_user($email_or_username, $password) {
    global $pdo;
    
    if (empty($email_or_username) || empty($password)) {
        return ['success' => false, 'message' => 'Please fill in all fields.'];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$email_or_username, $email_or_username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_blocked']) {
                return ['success' => false, 'message' => 'Your account has been blocked by the administrator.'];
            }
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['fullname'] = $user['fullname'];
            
            return ['success' => true, 'message' => 'Login successful.'];
        } else {
            return ['success' => false, 'message' => 'Incorrect username/email or password.'];
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Login failed: ' . $e->getMessage()];
    }
}

/**
 * Gets a system setting value by key
 * @param string $key
 * @param string $default
 * @return string
 */
function rjpes_get_setting($key, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}
?>
