<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/Support/staff_dispatch.php';

use App\Core\{Auth, Authorization, Csrf, Database, Validator};
use App\Repositories\{LearningRepository, ProgressRepository};
use App\Services\{LearningService, SyncService, TranslationService};

$user = Auth::requireLogin();
$uid = (int) $user['id'];
$isStudent = Authorization::isStudent();

/** Server-side lesson completion rules: enrolled, unlocked, minimum study time. Throws DomainException on rejection. */
function complete_lesson_for(int $studentId, int $lessonId, int $seconds, bool $queuedOffline, string $when): string
{
    $state = LearningService::lessonState($studentId, $lessonId);
    if (!$state) {
        throw new DomainException('Lesson not available for this student.');
    }
    if (!$state['lesson_state']['unlocked']) {
        throw new DomainException('Lesson is locked. Complete the previous lesson first.');
    }
    if ($state['lesson_state']['completed']) {
        return 'Already completed.';
    }
    $seconds = max(0, min($seconds, 4 * 3600));
    if (!$queuedOffline) {
        // Online completions can be checked against the server's own "opened" timestamp.
        $opened = ProgressRepository::firstOpenedAt($studentId, $lessonId);
        if ($opened === null) {
            throw new DomainException('Open the lesson before completing it.');
        }
        $seconds = min($seconds, max(0, time() - (int) strtotime($opened)) + 5);
    }
    $min = (int) setting('lesson_min_seconds', 30);
    if ($seconds < $min) {
        throw new DomainException("Study at least $min seconds before completing this lesson.");
    }
    ProgressRepository::completeLesson($studentId, $state['lesson'], $seconds, $when);
    return 'Lesson completed.';
}

// ---- Same-page JSON action: offline sync of lesson completion events -------------------------
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
    $results = SyncService::process($events, ['lesson_complete'], $uid, static function (array $ev) use ($uid): array {
        $p = $ev['payload'];
        $lessonId = Validator::int($p['lesson_id'] ?? '', 'Lesson', 1);
        $msg = complete_lesson_for($uid, $lessonId, (int) ($p['seconds_spent'] ?? 0), !empty($ev['queued_offline']), SyncService::effectiveTime($ev['client_ts'] ?? null));
        return ['message' => $msg, 'data' => ['lesson_id' => $lessonId]];
    });
    json_response(['ok' => true, 'results' => $results, 'server_time' => date('c')]);
}

// ---- Normal POST (no-JS fallback): complete_lesson -------------------------------------------
$lessonId = 0;
try {
    $lessonId = Validator::int($_GET['id'] ?? '', 'Lesson', 1);
} catch (\InvalidArgumentException) {
    not_found('Lesson not found.');
}

if (is_post() && request_action() === 'complete_lesson') {
    Csrf::verifyRequest();
    try {
        if (!$isStudent) {
            forbidden();
        }
        flash('success', complete_lesson_for($uid, $lessonId, (int) ($_POST['seconds_spent'] ?? 0), false, now()));
    } catch (\Throwable $e) {
        flash('error', handle_exception($e));
    }
    redirect_self();
}

// ---- Page render ------------------------------------------------------------------------------
$lang = TranslationService::currentCode();
if ($isStudent) {
    Authorization::require('learn.access');
    $state = LearningService::lessonState($uid, $lessonId);
    if (!$state) {
        forbidden('This lesson is not available to you.');
    }
    if (!$state['lesson_state']['unlocked']) {
        forbidden('This lesson is locked. Complete the previous lesson first.');
    }
    $lesson = $state['lesson'];
    $completed = $state['lesson_state']['completed'];
    ProgressRepository::markOpened($uid, $lessonId);
} else {
    Authorization::require('modules.view');
    $lesson = LearningRepository::lesson($lessonId);
    if (!$lesson) {
        not_found('Lesson not found.');
    }
    if (!Authorization::canAccessSubject((int) $lesson['subject_id'])) {
        forbidden();
    }
    $completed = false;
}
$tr = TranslationService::bulkLessons([$lessonId], $lang)[$lessonId] ?? null;
$title = $tr['title'] ?? $lesson['title'];
$body = $tr['body'] ?? $lesson['body'];
$siblings = LearningRepository::lessons((int) $lesson['module_id'], true);
$next = null;
foreach ($siblings as $i => $s) {
    if ((int) $s['id'] === $lessonId && isset($siblings[$i + 1])) {
        $next = (int) $siblings[$i + 1]['id'];
    }
}
$minSeconds = (int) setting('lesson_min_seconds', 30);

page_header($title);
?>
<div class="card" data-lesson data-lesson-id="<?= $lessonId ?>" data-min-seconds="<?= $minSeconds ?>" data-page="lesson.php" data-student="<?= $isStudent ? '1' : '0' ?>" data-completed="<?= $completed ? '1' : '0' ?>">
    <div class="toolbar">
        <button type="button" class="btn btn-sm" data-tts-target="lesson-body" data-tts-lang="<?= e(TranslationService::ttsLocale($lang)) ?>" aria-label="Read aloud" title="Read aloud">🔊 Listen</button>
        <span class="tts-notice muted" data-tts-notice hidden></span>
    </div>
    <article id="lesson-body" lang="<?= e($lang) ?>"><?= nl2br(e((string) $body)) ?></article>
</div>
<?php if ($isStudent): ?>
<div class="card">
    <?php if ($completed): ?>
        <p>✔ Lesson completed.</p>
        <?php if ($next): ?><a class="btn btn-primary" href="lesson.php?id=<?= $next ?>">Next lesson</a><?php endif; ?>
        <a class="btn" href="dashboard.php">Dashboard</a>
    <?php else: ?>
        <p class="muted">Study time: <span data-lesson-timer>0</span>s / <?= $minSeconds ?>s required.</p>
        <form method="post" data-complete-form><?= csrf_field() ?>
            <input type="hidden" name="action" value="complete_lesson"><input type="hidden" name="seconds_spent" value="0" data-seconds-field>
            <button class="btn btn-primary" data-complete-lesson disabled>Mark lesson complete</button>
        </form>
        <p class="muted" data-lesson-status></p>
    <?php endif; ?>
</div>
<?php endif;
page_footer();
