<?php
// import_students.php: Imports valid students from preview
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ob_start();
require_once '../config/database.php';
require_once '../includes/email_service.php';
require_once '../includes/auth_check.php';
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
	restoreSessionFromJWT();
}
$isTeacherRequest = ($_SESSION['role'] ?? '') === 'teacher';
if ($isTeacherRequest) {
	checkTeacherAuthAPI();
} else {
	checkAdminAuth();
}

// Custom error handler to capture errors and return as JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
	http_response_code(500);
	echo json_encode([
		'error' => "PHP Error [$errno]: $errstr in $errfile on line $errline"
	]);
	exit;
});

set_exception_handler(function($exception) {
	http_response_code(500);
	echo json_encode([
		'error' => 'Uncaught Exception: ' . $exception->getMessage()
	]);
	exit;
});

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
$students = $data['students'] ?? [];
$class_id = filter_var($data['class_id'] ?? null, FILTER_VALIDATE_INT);

$db = Database::getInstance()->getConnection();

if (!$class_id || !is_array($students)) {
	http_response_code(400);
	echo json_encode(['error' => 'Class ID and student rows are required']);
	exit;
}

if ($isTeacherRequest && !hasTeacherPermission($db, 'can_manage_classes')) {
	http_response_code(403);
	echo json_encode(['error' => 'You do not have permission to manage classes']);
	exit;
}

// Teachers may only import into classes they own.
if ($isTeacherRequest) {
	$classCheck = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? AND teacher_id = ? LIMIT 1");
	$classCheck->execute([$class_id, $_SESSION['teacher_id'] ?? 0]);
} else {
	$classCheck = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? LIMIT 1");
	$classCheck->execute([$class_id]);
}
if (!$classCheck->fetchColumn()) {
	http_response_code($isTeacherRequest ? 403 : 400);
	echo json_encode(['error' => $isTeacherRequest ? 'Selected class is not assigned to you' : 'Invalid class']);
	exit;
}
$existingUsernames = $db->query("SELECT username FROM users")->fetchAll(PDO::FETCH_COLUMN);

$result = [
	'imported' => 0,
	'skipped' => 0,
	'failed' => 0,
	'reasons' => []
];
$lrnOccurrences = [];
$nameOccurrences = [];
foreach ($students as $row) {
	$lrn = trim((string) ($row['lrn'] ?? ''));
	$firstName = strtolower(preg_replace('/\s+/', ' ', trim($row['first name'] ?? '')));
	$lastName = strtolower(preg_replace('/\s+/', ' ', trim($row['last name'] ?? '')));
	$nameKey = json_encode([$firstName, $lastName]);
	if ($firstName !== '' && $lastName !== '') {
		$nameOccurrences[$nameKey] = ($nameOccurrences[$nameKey] ?? 0) + 1;
	}
	if (preg_match('/^[0-9]{12}$/', $lrn)) {
		$lrnOccurrences[$lrn][] = [
			'first name' => trim($row['first name'] ?? ''),
			'last name' => trim($row['last name'] ?? '')
		];
	}
}

foreach ($students as $row) {
	$first = trim($row['first name'] ?? '');
	$last = trim($row['last name'] ?? '');
	$lrn = trim((string) ($row['lrn'] ?? ''));
	$studentFullName = trim($first . ' ' . $last);
	$gender = strtolower(trim((string) ($row['gender'] ?? '')));
	if ($gender === 'other') {
		$gender = 'others';
	}
	if (!in_array($gender, ['male', 'female', 'others'], true)) {
		$result['failed']++;
		$result['reasons'][] = "$studentFullName: gender must be male, female, or other";
		continue;
	}

	if ($first === '' || $last === '') {
		$result['failed']++;
		$result['reasons'][] = "Missing first or last name for a row";
		continue;
	}

	if (!preg_match('/^[0-9]{12}$/', $lrn)) {
		$result['failed']++;
		$result['reasons'][] = "$studentFullName: LRN is required and must contain exactly 12 digits";
		continue;
	}

	if (count($lrnOccurrences[$lrn] ?? []) > 1) {
		$matchingNameCount = 0;
		foreach ($lrnOccurrences[$lrn] as $occurrence) {
			if (strcasecmp(
				preg_replace('/\s+/', ' ', trim($occurrence['first name'] . ' ' . $occurrence['last name'])),
				preg_replace('/\s+/', ' ', $studentFullName)
			) === 0) {
				$matchingNameCount++;
			}
		}
		$reason = $matchingNameCount > 1
			? 'LRN and first and last name are duplicated in this upload'
			: 'LRN is duplicated in this upload';
		$result['failed']++;
		$result['reasons'][] = "$studentFullName: $reason";
		continue;
	}

	$nameKey = json_encode([
		strtolower(preg_replace('/\s+/', ' ', $first)),
		strtolower(preg_replace('/\s+/', ' ', $last))
	]);
	if (($nameOccurrences[$nameKey] ?? 0) > 1) {
		$result['failed']++;
		$result['reasons'][] = "$studentFullName: first name and last name are duplicated in this upload";
		continue;
	}

	try {
		$db->beginTransaction();

		$studentIdCheck = $db->prepare("SELECT first_name, last_name FROM students WHERE student_id = ?");
		$studentIdCheck->execute([$lrn]);
		$existingStudent = $studentIdCheck->fetch(PDO::FETCH_ASSOC);
		if ($existingStudent) {
			$db->rollBack();
			$result['failed']++;
			$existingName = strtolower(preg_replace('/\s+/', ' ', trim(
				$existingStudent['first_name'] . ' ' . $existingStudent['last_name']
			)));
			$submittedName = strtolower(preg_replace('/\s+/', ' ', $studentFullName));
			$duplicateReason = $existingName === $submittedName
				? 'LRN and first and last name already belong to an existing student'
				: 'LRN is already used as a student ID';
			$result['reasons'][] = "$studentFullName: $duplicateReason";
			continue;
		}

		$sameNameCheck = $db->prepare("
			SELECT student_id
			FROM students
			WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(?))
			  AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))
			LIMIT 1
		");
		$sameNameCheck->execute([$first, $last]);
		if ($sameNameCheck->fetchColumn()) {
			$db->rollBack();
			$result['failed']++;
			$result['reasons'][] = "$studentFullName: a student with this first name and last name already exists";
			continue;
		}

		// Generate a unique username: lastname + 3 random digits
		$base = preg_replace('/[^a-z0-9]+/i', '', strtolower($last));
		do {
			$username = $base . rand(100, 999);
		} while (in_array($username, $existingUsernames));
		$existingUsernames[] = $username;

		$raw_password = 'changeme';
		$password = password_hash($raw_password, PASSWORD_DEFAULT);

		$stmt = $db->prepare("INSERT INTO users (email, password, username, role, must_change_password, status, email_verified_at) VALUES (NULL, ?, ?, 'student', 1, 'active', NOW())");
		$stmt->execute([$password, $username]);
		$user_id = $db->lastInsertId();
		$stmt = $db->prepare("INSERT INTO students (student_id, user_id, first_name, last_name, gender) VALUES (?, ?, ?, ?, ?)");
		$stmt->execute([$lrn, $user_id, $first, $last, $gender]);
		$student_id = $lrn;
		$stmt = $db->prepare("INSERT INTO class_students (student_id, class_id) VALUES (?, ?)");
		$stmt->execute([$student_id, $class_id]);

		$db->commit();

		$result['imported']++;
	} catch (Exception $ex) {
		$db->rollBack();
		$result['failed']++;
		$result['reasons'][] = "$studentFullName: " . $ex->getMessage();
	}
}

while (ob_get_level() > 0) { ob_end_clean(); }
echo json_encode(['result' => $result]);
