<?php
// student_api.php: Handles adding students and associating them with classes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';
require_once '../includes/email_service.php';
require_once '../includes/auth_check.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    restoreSessionFromJWT();
}

$action = $_GET['action'] ?? '';
$isTeacherRequest = ($_SESSION['role'] ?? '') === 'teacher';
if ($isTeacherRequest) {
    checkTeacherAuthAPI();
    if ($action === 'reset_password') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        exit;
    }
} else {
    checkAdminAuth();
}

// Handle admin reset password for students
if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['user_id'] ?? 0;
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID required']);
        exit;
    }
    $db = Database::getInstance()->getConnection();
    $defaultPassword = 'changeme';
    $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password = ?, must_change_password = 1 WHERE user_id = ? AND role = 'student'");
    $stmt->execute([$hashedPassword, $userId]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Student not found or update failed']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully to the default password.',
        'temp_password' => $defaultPassword
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$required = ['class_id', 'first_name', 'last_name', 'gender', 'lrn'];
foreach ($required as $field) {
    if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
        http_response_code(400);
        echo json_encode(['error' => "Missing field: $field"]);
        exit;
    }
}

$db = Database::getInstance()->getConnection();
if ($isTeacherRequest && !hasTeacherPermission($db, 'can_manage_classes')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to manage classes']);
    exit;
}

$gender = strtolower(trim((string)$data['gender']));
if ($gender === 'other') {
    $gender = 'others';
}
if (!in_array($gender, ['male', 'female', 'others'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid gender value']);
    exit;
}

$studentId = trim((string) $data['lrn']);
if (!preg_match('/^[0-9]{12}$/', $studentId)) {
    http_response_code(400);
    echo json_encode(['error' => 'LRN must contain exactly 12 digits']);
    exit;
}

// Teachers may only add students to classes they own.
if ($isTeacherRequest) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE class_id = ? AND teacher_id = ?");
    $stmt->execute([$data['class_id'], $_SESSION['teacher_id'] ?? 0]);
} else {
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE class_id = ?");
    $stmt->execute([$data['class_id']]);
}
if ($stmt->fetchColumn() == 0) {
    http_response_code($isTeacherRequest ? 403 : 400);
    echo json_encode(['error' => $isTeacherRequest ? 'Selected class is not assigned to you' : 'Selected class does not exist']);
    exit;
}

$studentIdCheck = $db->prepare("SELECT first_name, last_name FROM students WHERE student_id = ? LIMIT 1");
$studentIdCheck->execute([$studentId]);
$existingStudent = $studentIdCheck->fetch(PDO::FETCH_ASSOC);
if ($existingStudent) {
    http_response_code(409);
    $normalizeName = static function ($name) {
        return strtolower(preg_replace('/\s+/', ' ', trim($name)));
    };
    $sameName = $normalizeName($existingStudent['first_name']) === $normalizeName($data['first_name'])
        && $normalizeName($existingStudent['last_name']) === $normalizeName($data['last_name']);
    echo json_encode([
        'error' => $sameName
            ? 'A student with this LRN, first name, and last name already exists.'
            : 'This LRN is already assigned to another student.'
    ]);
    exit;
}

$sameNameCheck = $db->prepare("
    SELECT student_id
    FROM students
    WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(?))
      AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))
    LIMIT 1
");
$sameNameCheck->execute([trim($data['first_name']), trim($data['last_name'])]);
if ($sameNameCheck->fetchColumn()) {
    http_response_code(409);
    echo json_encode(['error' => 'A student with this first name and last name already exists.']);
    exit;
}

// Validate email if provided
if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email format']);
    exit;
}

// Generate a unique username: lastname + 3 random digits
$base = preg_replace('/[^a-z0-9]+/i', '', strtolower($data['last_name']));
$username = $base . rand(100, 999);
$check = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
while (true) {
    $check->execute([$username]);
    if ($check->fetchColumn() == 0) break;
    $username = $base . rand(100, 999);
}

// Determine raw password (use 'changeme' if blank)
if (!empty($data['password'])) {
    $raw_password = $data['password'];
} else {
    $raw_password = 'changeme';
}

$password = password_hash($raw_password, PASSWORD_DEFAULT);
$email = !empty($data['email']) ? $data['email'] : null;

// Check email uniqueness (only when an email is actually provided)
if ($email !== null) {
    $emailCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $emailCheck->execute([$email]);
    if ($emailCheck->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already exists']);
        exit;
    }
}

$verificationCodeHash = null;
$verificationExpiresAt = null;
$status = 'active';
$emailSent = true;

if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $status = 'pending';
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $verificationCodeHash = hash('sha256', $code);
    $verificationExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $emailSent = false;
}

try {
    $db->beginTransaction();
    // Insert into users (force must_change_password on first login)
    $stmt = $db->prepare("INSERT INTO users (email, password, username, role, must_change_password, status, email_verified_at, email_verification_code_hash, email_verification_code_expires_at) VALUES (?, ?, ?, 'student', 1, ?, ?, ?, ?)");
    $stmt->execute([
        $email,
        $password,
        $username,
        $status,
        $status === 'active' ? date('Y-m-d H:i:s') : null,
        $verificationCodeHash,
        $verificationExpiresAt
    ]);
    $user_id = $db->lastInsertId();

    // Insert into students (no section)
    $stmt = $db->prepare("INSERT INTO students (student_id, user_id, first_name, last_name, gender) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $studentId,
        $user_id,
        $data['first_name'],
        $data['last_name'],
        $gender
    ]);
    $student_id = $studentId;

    // Associate with class
    $stmt = $db->prepare("INSERT INTO class_students (class_id, student_id, joined_at) VALUES (?, ?, NOW())");
    $stmt->execute([$data['class_id'], $student_id]);

    $db->commit();

    if ($verificationCodeHash !== null) {
        $studentFullName = trim($data['first_name'] . ' ' . $data['last_name']);
        $emailSent = sendAccountVerificationCodeEmail($email, $studentFullName ?: $email, $code, MAIL_FROM_NAME);
    }

    echo json_encode([
        'success'    => true,
        'student_id' => $student_id,
        'username'   => $username,
        'email'      => $email,
        'password'   => $raw_password,
        'email_sent' => $emailSent
    ]);
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
