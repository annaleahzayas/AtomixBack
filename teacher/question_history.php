<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/auth_check.php';

checkTeacherAuth();

$db = Database::getInstance()->getConnection();
requireTeacherPermission($db, 'can_manage_questions');
$teacher_id = getTeacherId();

$history = [];
$historyError = null;

try {
    $stmt = $db->prepare(
        "SELECT h.history_id, h.question_id, h.editor_name, h.before_data, h.after_data, h.edited_at,
                q.question_text AS current_question_text,
                l.lesson_title, c.chapter_title
         FROM question_edit_history h
         JOIN questions_master q ON q.question_id = h.question_id
         LEFT JOIN lessons l ON l.lesson_id = q.lesson_id
         LEFT JOIN chapters c ON c.chapter_id = l.chapter_id
         WHERE q.created_by_teacher_id = ?
         ORDER BY h.edited_at DESC, h.history_id DESC"
    );
    $stmt->execute([$teacher_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($history as &$entry) {
        $entry['before_data'] = json_decode($entry['before_data'], true) ?: [];
        $entry['after_data'] = json_decode($entry['after_data'], true) ?: [];
    }
    unset($entry);
} catch (PDOException $e) {
    error_log('question_history.php DB error: ' . $e->getMessage());
    $historyError = 'Question edit history is not available yet. Run the question edit history migration, then reload this page.';
}

function questionHistoryValue(array $data, string $key): string {
    if ($key === 'choices') {
        $lines = [];
        foreach ($data['choices'] ?? [] as $index => $choice) {
            $label = chr(65 + $index);
            $text = trim((string) ($choice['choice_text'] ?? ''));
            if (!empty($choice['is_correct'])) {
                $text .= ' (correct)';
            }
            $lines[] = $label . '. ' . $text;
        }
        return implode("\n", $lines);
    }

    if ($key === 'lesson_id') {
        return (string) ($data['lesson_id'] ?? '');
    }

    return (string) ($data[$key] ?? '');
}

$fieldLabels = [
    'question_text' => 'Question text',
    'lesson_id' => 'Lesson',
    'question_type' => 'Question type',
    'visibility' => 'Visibility',
    'choices' => 'Answer choices'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Edit History - Atomix</title>
    <link rel="stylesheet" href="../assets/css/teacher_style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .history-content {
            padding: 28px;
            border: 1px solid #e8edf5;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .06);
        }
        .history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 22px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        .history-header h2 {
            margin: 0 0 6px;
            color: #0f172a;
            font-size: 1.45rem;
        }
        .history-header p {
            margin: 0;
            color: #64748b;
            line-height: 1.5;
        }
        .history-search {
            width: 100%;
            max-width: 440px;
            min-height: 44px;
            margin-bottom: 18px;
            padding: 10px 14px;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            background: #f8fafc;
            font: inherit;
        }
        .history-entry {
            margin-bottom: 14px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            border-radius: 13px;
            background: #fff;
        }
        .history-entry:last-child { margin-bottom: 0; }
        .history-entry-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            padding: 17px 19px;
            background: #f8fafc;
        }
        .history-entry-question {
            margin: 0 0 7px;
            color: #0f172a;
            font-weight: 700;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }
        .history-entry-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 14px;
            color: #64748b;
            font-size: .84rem;
        }
        .history-editor {
            flex: 0 0 auto;
            color: #334155;
            font-size: .88rem;
            font-weight: 700;
            text-align: right;
        }
        .history-editor time {
            display: block;
            margin-top: 4px;
            color: #64748b;
            font-size: .8rem;
            font-weight: 400;
        }
        .history-changes { padding: 16px 19px; }
        .history-change {
            display: grid;
            grid-template-columns: minmax(110px, .25fr) minmax(0, 1fr) minmax(0, 1fr);
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid #eef2f7;
        }
        .history-change:first-child { padding-top: 0; }
        .history-change:last-child { padding-bottom: 0; border-bottom: 0; }
        .history-change-label {
            color: #475569;
            font-size: .82rem;
            font-weight: 700;
        }
        .history-value {
            min-width: 0;
            color: #334155;
            font-size: .86rem;
            line-height: 1.5;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }
        .history-value-label {
            display: block;
            margin-bottom: 4px;
            color: #94a3b8;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }
        .history-empty, .history-error {
            padding: 34px 20px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: #64748b;
            text-align: center;
            line-height: 1.6;
        }
        .history-empty i, .history-error i {
            display: block;
            margin-bottom: 10px;
            font-size: 1.5rem;
        }
        .history-error { border-color: #fecaca; background: #fff7f7; color: #991b1b; }
        .history-pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 20px;
            color: #64748b;
            font-size: .88rem;
        }
        .history-pagination-status { min-width: 190px; text-align: center; }
        .history-pagination button {
            min-height: 38px;
            padding: 8px 13px;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #fff;
            color: #334155;
            font: inherit;
            cursor: pointer;
        }
        .history-pagination button:hover:not(:disabled) {
            border-color: #a5b4fc;
            background: #eef2ff;
            color: #4338ca;
        }
        .history-pagination button:disabled {
            color: #94a3b8;
            cursor: not-allowed;
            opacity: .65;
        }
        @media (max-width: 700px) {
            .history-content { padding: 18px; }
            .history-header { align-items: flex-start; flex-direction: column; }
            .history-header .btn { width: 100%; justify-content: center; }
            .history-entry-header { flex-direction: column; gap: 10px; }
            .history-editor { text-align: left; }
            .history-change { grid-template-columns: 1fr; gap: 8px; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar"><?php include 'sidebar.php'; ?></aside>
        <main class="main-content">
            <header class="top-header">
                <h1>Question Edit History</h1>
                <a href="profile.php" class="user-info" title="My Profile">
                    <span>Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Teacher'); ?></span>
                    <i class="fas fa-user-circle"></i>
                </a>
            </header>
            <nav class="breadcrumb" aria-label="breadcrumb">
                <a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
                <a href="questions.php">Questions</a>
                <span class="breadcrumb-sep"><i class="fas fa-chevron-right"></i></span>
                <span class="breadcrumb-current">Edit History</span>
            </nav>

            <section class="history-content">
                <div class="history-header">
                    <div>
                        <h2><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> Edit history</h2>
                        <p>Review who edited your questions, when they were changed, and the before-and-after values.</p>
                    </div>
                    <a href="questions.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Questions</a>
                </div>

                <?php if ($historyError): ?>
                    <div class="history-error"><i class="fas fa-circle-exclamation"></i><?php echo htmlspecialchars($historyError); ?></div>
                <?php elseif (empty($history)): ?>
                    <div class="history-empty"><i class="fas fa-clock-rotate-left"></i>No question edits have been recorded yet.</div>
                <?php else: ?>
                    <input type="search" class="history-search" id="historySearch" placeholder="Search by question, editor, or lesson..." aria-label="Search edit history">
                    <div id="historyEntries">
                        <?php foreach ($history as $entry): ?>
                            <?php
                            $before = $entry['before_data'];
                            $after = $entry['after_data'];
                            $changedFields = [];
                            foreach ($fieldLabels as $field => $label) {
                                if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                                    $changedFields[] = $field;
                                }
                            }
                            $searchText = strtolower(
                                $entry['current_question_text'] . ' ' .
                                $entry['editor_name'] . ' ' .
                                ($entry['lesson_title'] ?? '') . ' ' .
                                ($entry['chapter_title'] ?? '')
                            );
                            ?>
                            <article class="history-entry" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                                <header class="history-entry-header">
                                    <div>
                                        <p class="history-entry-question"><?php echo htmlspecialchars($entry['current_question_text'] ?: ($after['question_text'] ?? 'Question')); ?></p>
                                        <div class="history-entry-meta">
                                            <span><i class="fas fa-book"></i> <?php echo htmlspecialchars(trim(($entry['chapter_title'] ?? '') . ' - ' . ($entry['lesson_title'] ?? ''), ' -')); ?></span>
                                            <span><i class="fas fa-hashtag"></i> Question <?php echo (int) $entry['question_id']; ?></span>
                                        </div>
                                    </div>
                                    <div class="history-editor">
                                        <span><i class="fas fa-user-pen"></i> <?php echo htmlspecialchars($entry['editor_name']); ?></span>
                                        <time><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($entry['edited_at']))); ?></time>
                                    </div>
                                </header>
                                <div class="history-changes">
                                    <?php if (!$changedFields): ?>
                                        <div class="history-change">
                                            <strong class="history-change-label">Edit</strong>
                                            <div class="history-value" style="grid-column: span 2;">Saved without a detectable field change.</div>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($changedFields as $field): ?>
                                            <div class="history-change">
                                                <strong class="history-change-label"><?php echo htmlspecialchars($fieldLabels[$field]); ?></strong>
                                                <div class="history-value">
                                                    <span class="history-value-label">Before</span>
                                                    <?php echo htmlspecialchars(questionHistoryValue($before, $field) ?: '—'); ?>
                                                </div>
                                                <div class="history-value">
                                                    <span class="history-value-label">After</span>
                                                    <?php echo htmlspecialchars(questionHistoryValue($after, $field) ?: '—'); ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="history-empty" id="historyNoResults" hidden><i class="fas fa-search"></i>No matching history entries.</div>
                    <nav class="history-pagination" id="historyPagination" aria-label="Question edit history pagination">
                        <button type="button" id="historyPreviousPage">Previous</button>
                        <span class="history-pagination-status" id="historyPaginationStatus" role="status" aria-live="polite"></span>
                        <button type="button" id="historyNextPage">Next</button>
                    </nav>
                <?php endif; ?>
            </section>
        </main>
    </div>
    <script>
        const historySearch = document.getElementById('historySearch');
        if (historySearch) {
            const entries = Array.from(document.querySelectorAll('.history-entry'));
            const pageSize = 10;
            let currentPage = 1;
            const previousButton = document.getElementById('historyPreviousPage');
            const nextButton = document.getElementById('historyNextPage');
            const status = document.getElementById('historyPaginationStatus');
            const noResults = document.getElementById('historyNoResults');

            function renderHistoryPage() {
                const query = historySearch.value.trim().toLowerCase();
                const filteredEntries = entries.filter(entry => entry.dataset.search.includes(query));
                const pageCount = Math.max(1, Math.ceil(filteredEntries.length / pageSize));
                currentPage = Math.min(currentPage, pageCount);
                const startIndex = (currentPage - 1) * pageSize;
                const visibleEntries = new Set(filteredEntries.slice(startIndex, startIndex + pageSize));

                entries.forEach(entry => {
                    entry.hidden = !visibleEntries.has(entry);
                });

                const first = filteredEntries.length ? startIndex + 1 : 0;
                const last = Math.min(startIndex + pageSize, filteredEntries.length);
                status.textContent = filteredEntries.length
                    ? `Showing ${first}-${last} of ${filteredEntries.length} entries (page ${currentPage} of ${pageCount})`
                    : 'No matching history entries.';
                previousButton.disabled = currentPage <= 1 || filteredEntries.length === 0;
                nextButton.disabled = currentPage >= pageCount || filteredEntries.length === 0;
                noResults.hidden = filteredEntries.length !== 0;
            }

            historySearch.addEventListener('input', function() {
                currentPage = 1;
                renderHistoryPage();
            });

            previousButton.addEventListener('click', function() {
                if (currentPage > 1) {
                    currentPage--;
                    renderHistoryPage();
                }
            });
            nextButton.addEventListener('click', function() {
                currentPage++;
                renderHistoryPage();
            });
            renderHistoryPage();
        }
    </script>
</body>
</html>
