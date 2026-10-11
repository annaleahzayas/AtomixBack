<?php
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `question_edit_requests` (
            `request_id` INT(11) NOT NULL AUTO_INCREMENT,
            `question_id` INT(11) NOT NULL,
            `requester_teacher_id` INT(11) NOT NULL,
            `question_author_teacher_id` INT(11) NOT NULL,
            `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `responded_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`request_id`),
            UNIQUE KEY `uq_question_edit_request` (`question_id`, `requester_teacher_id`),
            KEY `idx_question_edit_author_status` (`question_author_teacher_id`, `status`),
            CONSTRAINT `fk_qer_question` FOREIGN KEY (`question_id`)
                REFERENCES `questions_master` (`question_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_qer_requester` FOREIGN KEY (`requester_teacher_id`)
                REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE,
            CONSTRAINT `fk_qer_author` FOREIGN KEY (`question_author_teacher_id`)
                REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo 'question_edit_requests table created or already exists.' . PHP_EOL;
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Question edit request migration failed: ' . $e->getMessage());
    echo 'Question edit request migration failed. Check the server error log.' . PHP_EOL;
}
