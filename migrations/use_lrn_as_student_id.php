<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this migration from the command line.\n");
}

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();
$quoteIdentifier = static function ($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
};

try {
    $lrnColumnCheck = $db->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'students'
          AND COLUMN_NAME = 'lrn'
    ");
    $lrnColumnCheck->execute();
    $hasLrnColumn = (int) $lrnColumnCheck->fetchColumn() > 0;
    if ($hasLrnColumn) {
        $populatedLrns = (int) $db->query("SELECT COUNT(*) FROM students WHERE lrn IS NOT NULL AND lrn <> ''")->fetchColumn();
        if ($populatedLrns > 0) {
            throw new RuntimeException('students.lrn already contains values. Migrate those student IDs and all related records manually before rerunning.');
        }
    }

    $foreignKeys = $db->query("
        SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, r.DELETE_RULE
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
        JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
          ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA
         AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME
         AND k.TABLE_NAME = r.TABLE_NAME
        WHERE k.CONSTRAINT_SCHEMA = DATABASE()
          AND k.REFERENCED_TABLE_NAME = 'students'
          AND k.REFERENCED_COLUMN_NAME = 'student_id'
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($foreignKeys as $foreignKey) {
        $db->exec(
            'ALTER TABLE ' . $quoteIdentifier($foreignKey['TABLE_NAME']) .
            ' DROP FOREIGN KEY ' . $quoteIdentifier($foreignKey['CONSTRAINT_NAME'])
        );
    }

    $studentIdColumns = $db->query("
        SELECT TABLE_NAME, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'student_id'
        ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($studentIdColumns as $column) {
        $nullability = $column['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
        $db->exec(
            'ALTER TABLE ' . $quoteIdentifier($column['TABLE_NAME']) .
            ' MODIFY ' . $quoteIdentifier('student_id') .
            ' VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin ' . $nullability
        );
    }

    if ($hasLrnColumn) {
        $indexCheck = $db->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'students'
              AND INDEX_NAME = 'uq_students_lrn'
        ");
        $indexCheck->execute();
        if ((int) $indexCheck->fetchColumn() > 0) {
            $db->exec("ALTER TABLE `students` DROP INDEX `uq_students_lrn`");
        }
        $db->exec("ALTER TABLE `students` DROP COLUMN `lrn`");
    }

    foreach ($foreignKeys as $foreignKey) {
        $deleteRule = strtoupper($foreignKey['DELETE_RULE']);
        if (!in_array($deleteRule, ['CASCADE', 'RESTRICT', 'NO ACTION', 'SET NULL'], true)) {
            throw new RuntimeException('Unexpected delete rule for ' . $foreignKey['CONSTRAINT_NAME']);
        }
        $db->exec(
            'ALTER TABLE ' . $quoteIdentifier($foreignKey['TABLE_NAME']) .
            ' ADD CONSTRAINT ' . $quoteIdentifier($foreignKey['CONSTRAINT_NAME']) .
            ' FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`)' .
            ' ON DELETE ' . $deleteRule . ' ON UPDATE CASCADE'
        );
    }

    echo "Student IDs now use the LRN text value; existing student IDs were preserved." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
