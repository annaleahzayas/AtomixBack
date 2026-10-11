<?php
// upload_students.php: Handles Excel/CSV upload, validation, and preview
ob_start();
require_once '../config/database.php';
$db = Database::getInstance()->getConnection();
require_once '../includes/auth_check.php';
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    restoreSessionFromJWT();
}
$isTeacherRequest = ($_SESSION['role'] ?? '') === 'teacher';
if ($isTeacherRequest) {
    checkTeacherAuthAPI();
    if (!hasTeacherPermission($db, 'can_manage_classes')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'You do not have permission to manage classes']);
        exit;
    }
} else {
    checkAdminAuth();
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['student_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

if ($_FILES['student_file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server limit.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form limit.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION => 'File upload stopped by extension.',
    ];
    $errorCode = $_FILES['student_file']['error'];
    $errorMessage = $uploadErrors[$errorCode] ?? 'Unknown upload error';
    http_response_code(400);
    echo json_encode(['error' => 'Upload failed: ' . $errorMessage]);
    exit;
}

$classId = isset($_POST['class_id']) ? (int) $_POST['class_id'] : 0;
if ($classId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Class ID is required']);
    exit;
}

 if ($isTeacherRequest) {
     $classCheck = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? AND teacher_id = ? LIMIT 1");
     $classCheck->execute([$classId, $_SESSION['teacher_id'] ?? 0]);
 } else {
     $classCheck = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? LIMIT 1");
     $classCheck->execute([$classId]);
 }

 if (!$classCheck->fetchColumn()) {
     http_response_code($isTeacherRequest ? 403 : 400);
     echo json_encode(['error' => $isTeacherRequest ? 'Selected class is not assigned to you' : 'Invalid class']);
     exit;
 }

 $maxFileSize = 2 * 1024 * 1024;
 if ($_FILES['student_file']['size'] > $maxFileSize) {
     http_response_code(400);
     echo json_encode(['error' => 'File too large. Maximum upload size is 2MB.']);
     exit;
 }

$file = $_FILES['student_file']['tmp_name'];
$ext = strtolower(pathinfo($_FILES['student_file']['name'], PATHINFO_EXTENSION));

require_once '../vendor/autoload.php'; // For PhpSpreadsheet when present
use PhpOffice\PhpSpreadsheet\IOFactory;

// Enable error reporting for troubleshooting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (($ext === 'xlsx' || $ext === 'xls') && !class_exists('ZipArchive')) {
    http_response_code(500);
    echo json_encode([
        'error' => 'PHP ZipArchive extension is required to import Excel files. Please enable ZipArchive in php.ini and restart your server, or upload a CSV file instead.'
    ]);
    exit;
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

$rows = [];
if ($ext === 'xlsx' || $ext === 'xls') {
    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($sheet->toArray() as $row) {
            $rows[] = $row;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to read Excel file: ' . $e->getMessage()]);
        exit;
    }
} elseif ($ext === 'csv') {
    // Parse CSV
    if (($handle = fopen($file, 'r')) !== false) {
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to read CSV file']);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only Excel (.xlsx) or CSV allowed.']);
    exit;
}

// Validate rows
$preview = [];
$classes = $db->query("SELECT class_name FROM classes")->fetchAll(PDO::FETCH_COLUMN);

// Map header columns to normalized keys (case-insensitive, trimmed)
if (count($rows) === 0) {
    ob_end_clean();
    echo json_encode(['preview' => []]);
    exit;
}

$rawHeader = $rows[0];
$normalizedHeader = [];
foreach ($rawHeader as $col) {
    $normalized = strtolower(trim((string) $col));
    if ($normalized === 'lrn (12 digits)') {
        $normalized = 'lrn';
    }
    $normalizedHeader[] = $normalized;
}

// Define expected keys
$expectedKeys = [
    'lrn' => null,
    'first name' => null,
    'last name' => null,
    'gender' => null
];

$existingStudentsById = [];
$existingStudentsByName = [];
$existingStudentsStmt = $db->query("SELECT student_id, first_name, last_name FROM students");
foreach ($existingStudentsStmt->fetchAll(PDO::FETCH_ASSOC) as $existingStudent) {
    $existingStudentsById[(string) $existingStudent['student_id']] = $existingStudent;
    $nameKey = json_encode([
        strtolower(preg_replace('/\s+/', ' ', trim($existingStudent['first_name']))),
        strtolower(preg_replace('/\s+/', ' ', trim($existingStudent['last_name'])))
    ]);
    $existingStudentsByName[$nameKey] = $existingStudent;
}

// Build a mapping from normalized header to expected keys
$headerMap = [];
foreach ($normalizedHeader as $i => $col) {
    foreach ($expectedKeys as $expected => $v) {
        if ($col === $expected) {
            $headerMap[$expected] = $i;
        }
    }
}

$seenLrns = [];
$seenNames = [];
for ($i = 1; $i < count($rows); $i++) {
    $rowData = $rows[$i];
    $row = [];
    foreach ($expectedKeys as $key => $v) {
        $row[$key] = isset($headerMap[$key]) && isset($rowData[$headerMap[$key]]) ? $rowData[$headerMap[$key]] : null;
    }

    // Skip fully empty rows.
    $isCompletelyEmpty = true;
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            $isCompletelyEmpty = false;
            break;
        }
    }
    if ($isCompletelyEmpty) {
        continue;
    }

    $valid = true;
    $errors = [];

    // Validate required fields.
    if (empty(trim((string) $row['first name']))) {
        $valid = false;
        $errors[] = 'Missing first name';
    }
    if (empty(trim((string) $row['last name']))) {
        $valid = false;
        $errors[] = 'Missing last name';
    }

    $lrn = trim((string) ($row['lrn'] ?? ''));
    if (!preg_match('/^[0-9]{12}$/', $lrn)) {
        $valid = false;
        $errors[] = 'Missing or invalid LRN (must be exactly 12 digits)';
    }

    // Validate gender.
    $validGenders = ['male', 'female', 'others', 'other'];
    $gender = strtolower(trim((string)$row['gender']));
    if (!$gender) {
        $valid = false;
        $errors[] = 'Missing gender (must be male, female, or other)';
    } else if (!in_array($gender, $validGenders, true)) {
        $valid = false;
        $errors[] = 'Invalid gender (must be male, female, or other)';
    }

    $previewIndex = count($preview);
    $normalizedFirstName = strtolower(preg_replace('/\s+/', ' ', trim((string) $row['first name'])));
    $normalizedLastName = strtolower(preg_replace('/\s+/', ' ', trim((string) $row['last name'])));
    $nameKey = json_encode([$normalizedFirstName, $normalizedLastName]);
    $normalizedName = $normalizedFirstName . ' ' . $normalizedLastName;

    if (preg_match('/^[0-9]{12}$/', $lrn)) {
        if (isset($existingStudentsById[$lrn])) {
            $existingStudent = $existingStudentsById[$lrn];
            $existingName = strtolower(preg_replace('/\s+/', ' ', trim(
                $existingStudent['first_name'] . ' ' . $existingStudent['last_name']
            )));
            $valid = false;
            $errors[] = $existingName === $normalizedName
                ? 'This LRN and first and last name already belong to an existing student'
                : 'This LRN is already used by an existing student';
        }

        if (isset($seenLrns[$lrn])) {
            $previousIndex = $seenLrns[$lrn];
            $previousName = strtolower(preg_replace('/\s+/', ' ', trim(
                (string) $preview[$previousIndex]['row']['first name'] . ' ' .
                (string) $preview[$previousIndex]['row']['last name']
            )));
            $duplicateName = $previousName === $normalizedName;
            $duplicateError = $duplicateName
                ? 'Duplicate LRN and first and last name in this upload'
                : 'Duplicate LRN in this upload';

            $preview[$previousIndex]['valid'] = false;
            if (!in_array($duplicateError, $preview[$previousIndex]['errors'], true)) {
                $preview[$previousIndex]['errors'][] = $duplicateError;
            }

            $valid = false;
            $errors[] = $duplicateError;
        } else {
            $seenLrns[$lrn] = $previewIndex;
        }
    }

    if ($normalizedFirstName !== '' && $normalizedLastName !== '') {
        if (isset($existingStudentsByName[$nameKey])) {
            $existingStudent = $existingStudentsByName[$nameKey];
            if ((string) $existingStudent['student_id'] !== $lrn) {
                $valid = false;
                $errors[] = 'A student with this first name and last name already exists';
            }
        }

        if (isset($seenNames[$nameKey])) {
            $duplicateError = 'Duplicate first name and last name in this upload';
            foreach ($seenNames[$nameKey] as $previousIndex) {
                $preview[$previousIndex]['valid'] = false;
                if (!in_array($duplicateError, $preview[$previousIndex]['errors'], true)) {
                    $preview[$previousIndex]['errors'][] = $duplicateError;
                }
            }
            $valid = false;
            $errors[] = $duplicateError;
        }
        $seenNames[$nameKey][] = $previewIndex;
    }

    $preview[] = [
        'row' => $row,
        'valid' => $valid,
        'errors' => $errors
    ];
}

ob_end_clean();
echo json_encode(['preview' => $preview]);
