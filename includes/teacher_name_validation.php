<?php

function teacherNameExists(PDO $db, string $firstName, string $lastName, ?int $excludeTeacherId = null): bool
{
    $normalizedName = strtolower(preg_replace('/\s+/', ' ', trim($firstName . ' ' . $lastName)));
    $sql = "
        SELECT COUNT(*)
        FROM teachers
        WHERE LOWER(TRIM(CONCAT(TRIM(first_name), ' ', TRIM(last_name)))) = ?
    ";
    $params = [$normalizedName];

    if ($excludeTeacherId !== null) {
        $sql .= " AND teacher_id <> ?";
        $params[] = $excludeTeacherId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}
