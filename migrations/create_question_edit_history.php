<?php
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `question_edit_history` (
            `history_id` INT(11) NOT NULL AUTO_INCREMENT,
            `question_id` INT(11) NOT NULL,
            `editor_teacher_id` INT(11) DEFAULT NULL,
            `editor_name` VARCHAR(255) NOT NULL,
            `before_data` LONGTEXT NOT NULL,
            `after_data` LONGTEXT NOT NULL,
            `edited_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`history_id`),
            KEY `idx_qeh_question_edited` (`question_id`, `edited_at`),
            KEY `idx_qeh_editor` (`editor_teacher_id`),
            CONSTRAINT `fk_qeh_question` FOREIGN KEY (`question_id`)
                REFERENCES `questions_master` (`question_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_qeh_editor` FOREIGN KEY (`editor_teacher_id`)
                REFERENCES `teachers` (`teacher_id`) ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo 'question_edit_history table created or already exists.' . PHP_EOL;
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Question edit history migration failed: ' . $e->getMessage());
    echo 'Question edit history migration failed. Check the server error log.' . PHP_EOL;
}
