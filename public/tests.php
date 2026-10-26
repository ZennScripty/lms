<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Database, Logger, Validator};
use App\Repositories\LearningRepository;
use App\Services\{LearningService, SyncService, TestService};

$user = Auth::requireLogin();
$uid = (int) $user['id'];
$isStudent = Authorization::isStudent();
$canManage = Authorization::can('tests.manage');

/** Resolve a test row and verify subject scope for authors. */
function author_test(int $testId): array
{
    $t = Database::one('SELECT t.*, m.subject_id FROM tests t JOIN modules m ON m.id = t.module_id WHERE t.id = ?', [$testId]);
    if (!$t || !Authorization::canAccessSubject((int) $t['subject_id'])) {
        forbidden();
    }
    return $t;
}

// ---- Same-page JSON action: offline test submissions ----------------------------------------
if (is_post() && request_action() === 'sync_push') {
    Csrf::verifyRequest();
    if (!$isStudent || !Authorization::can('learn.access')) {
        json_response(['ok' => false, 'error' => 'forbidden'], 403);
    }
    try {
        $events = SyncService::parseEvents();
    } catch (\InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    $results = SyncService::process($events, ['test_submit'], $uid, static function (array $ev) use ($uid): array {
        $p = $ev['payload'];
        $testId = Validator::int($p['test_id'] ?? '', 'Test', 1);
        $answers = is_array($p['answers'] ?? null) ? $p['answers'] : [];
        $r = TestService::submit($uid, $testId, $answers, SyncService::effectiveTime($p['started_at'] ?? null), 'offline_sync', SyncService::effectiveTime($ev['client_ts'] ?? null));
        return ['message' => sprintf('Score %.2f%% — %s', $r['percent'], $r['passed'] ? 'passed' : 'not passed'), 'data' => $r];
    });
    json_response(['ok' => true, 'results' => $results, 'server_time' => date('c')]);
}

if (is_post()) {
    Csrf::verifyRequest();
    $redirectTo = null;
    try {
        $action = request_action();
        if ($action === 'submit_test') {
            if (!$isStudent) {
                forbidden();
            }
            $testId = Validator::int($_POST['test_id'] ?? '', 'Test', 1);
            $r = TestService::submit($uid, $testId, is_array($_POST['answers'] ?? null) ? $_POST['answers'] : [], null, 'online', now());
            $redirectTo = 'tests.php?result=' . $r['attempt_id'];
        } else {
            if (!$canManage) {
                forbidden();
            }
            if ($action === 'save_test') {
                $m = LearningRepository::module(Validator::int($_POST['module_id'] ?? '', 'Module', 1));
                if (!$m || !Authorization::canAccessSubject((int) $m['subject_id'])) {
                    forbidden();
                }
                $title = Validator::str($_POST['title'] ?? '', 'Title', 2, 200);
                $status = Validator::inList($_POST['status'] ?? 'draft', ['draft', 'published'], 'Status');
                $id = Database::insert('INSERT INTO tests (module_id, title, status, created_at) VALUES (?,?,?,NOW())', [(int) $m['id'], $title, $status]);
                Logger::audit($uid, 'test_created', 'test', $id);
                flash('success', 'Test created.');
            } elseif ($action === 'set_test_status') {
                $t = author_test(Validator::int($_POST['test_id'] ?? '', 'Test', 1));
                Database::run('UPDATE tests SET status = ? WHERE id = ?', [Validator::inList($_POST['status'] ?? '', ['draft', 'published'], 'Status'), $t['id']]);
                flash('success', 'Test updated.');
            } elseif ($action === 'delete_test') {
                $t = author_test(Validator::int($_POST['test_id'] ?? '', 'Test', 1));
                Database::run('DELETE FROM tests WHERE id = ?', [$t['id']]);
                flash('success', 'Test deleted.');
            } elseif ($action === 'add_question') {
                $t = author_test(Validator::int($_POST['test_id'] ?? '', 'Test', 1));
                $text = Validator::str($_POST['question_text'] ?? '', 'Question', 3, 2000);
                $marks = Validator::int($_POST['marks'] ?? '1', 'Marks', 1, 100);
                $opts = array_values(array_filter(array_map('trim', is_array($_POST['options'] ?? null) ? $_POST['options'] : []), static fn ($o) => $o !== ''));
                $correct = Validator::int($_POST['correct'] ?? '', 'Correct option', 1, 10) - 1;
                if (count($opts) < 2 || !isset($opts[$correct])) {
                    throw new InvalidArgumentException('Enter at least two options and mark a valid correct one.');
                }
                Database::transaction(static function () use ($t, $text, $marks, $opts, $correct) {
                    $qid = Database::insert('INSERT INTO questions (test_id, question_text, marks) VALUES (?,?,?)', [(int) $t['id'], $text, $marks]);
                    foreach ($opts as $i => $o) {
                        Database::run('INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?,?,?)', [$qid, mb_substr($o, 0, 500), $i === $correct ? 1 : 0]);
                    }
                });
                flash('success', 'Question added.');
            } elseif ($action === 'delete_question') {
                $q = Database::one('SELECT id, test_id FROM questions WHERE id = ?', [Validator::int($_POST['question_id'] ?? '', 'Question', 1)]);
                if (!$q) {
                    throw new DomainException('Question not found.');
                }
                author_test((int) $q['test_id']);
                Database::run('DELETE FROM questions WHERE id = ?', [$q['id']]);
                flash('success', 'Question deleted.');
            }
        }
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    $redirectTo ? redirect($redirectTo) : redirect_self();
}

// ---- Result page ------------------------------------------------------------------------------
if (isset($_GET['result']) && $isStudent) {
    $a = Database::one('SELECT ta.*, t.title FROM test_attempts ta JOIN tests t ON t.id = ta.test_id WHERE ta.id = ? AND ta.student_id = ?', [Validator::int($_GET['result'], 'Attempt', 1), $uid]);
    if (!$a) {
        forbidden();
    }
    page_header('Test result');
    echo '<div class="card"><h2>' . e($a['title']) . '</h2><p class="big">' . e((string) $a['score_percent']) . '%</p><p>' . ((int) $a['passed'] === 1
        ? '<span class="pill ok">Passed</span> The next module is unlocked.' : '<span class="pill lock">Not passed</span> The next module stays locked. You can retake the test.') . '</p>';
    echo '<p><a class="btn" href="dashboard.php">Dashboard</a></p></div>';
    page_footer();
    exit;
}

// ---- Take a test ------------------------------------------------------------------------------
if (isset($_GET['id']) && $isStudent) {
    Authorization::require('learn.access');
    $testId = Validator::int($_GET['id'], 'Test', 1);
    $test = Database::one("SELECT id, module_id, title FROM tests WHERE id = ? AND status = 'published'", [$testId]);
    $state = $test ? LearningService::moduleState($uid, (int) $test['module_id']) : null;
    if (!$test || !$state || !$state['unlocked']) {
        forbidden('This test is not available to you.');
    }
    page_header($test['title']);
    if (!$state['test_available'] || (int) $state['test']['id'] !== $testId) {
        echo '<div class="card"><p>' . ($state['passed'] ? 'You have already passed this module.' : 'This test is not open yet. Finish all lessons and wait until ' . e(fmt_dt($state['test_due_at'])) . '.') . '</p></div>';
        page_footer();
        exit;
    }
    $questions = TestService::questionsForTaking($testId);
    ?>
    <form method="post" class="card" data-offline-test data-test-id="<?= $testId ?>" data-page="tests.php" data-started="<?= e(date('c')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="submit_test"><input type="hidden" name="test_id" value="<?= $testId ?>">
        <?php foreach ($questions as $i => $q): ?>
            <fieldset><legend><?= $i + 1 ?>. <?= e($q['question_text']) ?> <small class="muted">(<?= (int) $q['marks'] ?> mark<?= (int) $q['marks'] > 1 ? 's' : '' ?>)</small></legend>
            <?php foreach ($q['options'] as $o): ?>
                <label class="check"><input type="radio" name="answers[<?= (int) $q['id'] ?>]" value="<?= (int) $o['id'] ?>" required> <?= e($o['option_text']) ?></label>
            <?php endforeach; ?></fieldset>
        <?php endforeach; ?>
        <button class="btn btn-primary">Submit test</button>
        <p class="muted" data-test-status>Answers are saved as a draft on this device. Final scoring is done by the server.</p>
    </form>
    <?php
    page_footer();
    exit;
}

// ---- Listing ----------------------------------------------------------------------------------
page_header(t('nav.tests', 'Tests'));
if ($isStudent) {
    $any = false;
    foreach (LearningService::subjectsOf($uid) as $s) {
        foreach (LearningService::tree($uid, (int) $s['id']) as $m) {
            if (!$m['test']) {
                continue;
            }
            $any = true;
            echo '<div class="card"><h3>' . e($s['name']) . ' — ' . e($m['title']) . '</h3>';
            if ($m['passed']) {
                echo '<span class="pill ok">Passed' . ($m['best_score'] !== null ? ' (' . e((string) $m['best_score']) . '%)' : '') . '</span>';
            } elseif ($m['test_available']) {
                echo '<a class="btn btn-primary" href="tests.php?id=' . (int) $m['test']['id'] . '">Take test</a>';
            } elseif (!$m['unlocked']) {
                echo '<span class="pill lock">Locked</span>';
            } else {
                echo '<span class="muted">Opens after all lessons are completed' . ($m['test_due_at'] ? ' and on/after ' . e(fmt_dt($m['test_due_at'])) : '') . '.</span>';
            }
            echo '</div>';
        }
    }
    if (!$any) {
        echo '<div class="card muted">No module tests yet.</div>';
    }
} elseif ($canManage) {
    $scope = Authorization::visibleSubjectIds();
    $modules = [];
    foreach (LearningRepository::subjects($scope) as $s) {
        foreach (LearningRepository::modules((int) $s['id']) as $m) {
            $m['subject_name'] = $s['name'];
            $modules[] = $m;
        }
    }
    foreach ($modules as $m) {
        $tests = Database::all('SELECT * FROM tests WHERE module_id = ? ORDER BY id', [(int) $m['id']]);
        echo '<div class="card"><h3>' . e($m['subject_name']) . ' — ' . e($m['title']) . '</h3>';
        foreach ($tests as $t) {
            echo '<h4>' . e($t['title']) . ' <span class="pill ' . ($t['status'] === 'published' ? 'ok' : 'lock') . '">' . e($t['status']) . '</span></h4>';
            echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="set_test_status"><input type="hidden" name="test_id" value="' . (int) $t['id'] . '"><input type="hidden" name="status" value="' . ($t['status'] === 'published' ? 'draft' : 'published') . '"><button class="btn btn-sm">' . ($t['status'] === 'published' ? 'Unpublish' : 'Publish') . '</button></form> ';
            echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="delete_test"><input type="hidden" name="test_id" value="' . (int) $t['id'] . '"><button class="btn btn-sm btn-danger" data-confirm="Delete test?">Delete</button></form>';
            echo '<ol>';
            foreach (Database::all('SELECT q.id, q.question_text, q.marks FROM questions q WHERE q.test_id = ? ORDER BY q.id', [(int) $t['id']]) as $q) {
                echo '<li>' . e($q['question_text']) . ' <small class="muted">(' . (int) $q['marks'] . ')</small> <form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="delete_question"><input type="hidden" name="question_id" value="' . (int) $q['id'] . '"><button class="btn btn-sm btn-danger" data-confirm="Delete question?">×</button></form></li>';
            }
            echo '</ol>';
            ?>
            <details><summary>Add question</summary>
            <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add_question"><input type="hidden" name="test_id" value="<?= (int) $t['id'] ?>">
                <label class="wide">Question <textarea name="question_text" rows="2" required></textarea></label>
                <label>Marks <input type="number" name="marks" value="1" min="1" max="100"></label>
                <?php for ($i = 1; $i <= 4; $i++): ?><label>Option <?= $i ?> <input name="options[]" maxlength="500"></label><?php endfor; ?>
                <label>Correct option (1–4) <input type="number" name="correct" min="1" max="4" required></label>
                <div><button class="btn btn-primary">Add</button></div>
            </form></details>
            <?php
        }
        ?>
        <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_test"><input type="hidden" name="module_id" value="<?= (int) $m['id'] ?>">
            <input name="title" placeholder="New test title" required maxlength="200"><select name="status"><option>draft</option><option>published</option></select><button class="btn">Create test</button></form>
        </div>
        <?php
    }
} else {
    echo '<div class="card">Nothing to show.</div>';
}
page_footer();
