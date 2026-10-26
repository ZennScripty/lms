<?php
declare(strict_types=1);
/**
 * Dependency-free unit tests for the pure business rules (no database needed).
 * Run:  php tests/run.php
 * Integration / security scenarios that need a database and browser are listed in tests/MANUAL_CHECKLIST.md
 */
require __DIR__ . '/../app/autoload.php';

use App\Core\{Csrf, Validator};
use App\Services\{SyncService, TestService, TranslationService, UnlockService};

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $name . "\n";
}
function throws(callable $fn, string $class = \InvalidArgumentException::class): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

echo "Pass rule (module_pass_percent=50, rule=gte)\n";
check('49% fails', !UnlockService::passes(49, 100, 50, 'gte'));
check('50% passes with gte', UnlockService::passes(50, 100, 50, 'gte'));
check('50% fails with gt', !UnlockService::passes(50, 100, 50, 'gt'));
check('51% passes with gt', UnlockService::passes(51, 100, 50, 'gt'));
check('1 of 2 marks = 50% passes', UnlockService::passes(1, 2, 50, 'gte'));
check('16 of 33 marks (48.48%) fails', !UnlockService::passes(16, 33, 50, 'gte'));
check('0 total marks never passes', !UnlockService::passes(0, 0, 50, 'gte'));

echo "Module unlock rules\n";
check('passed status unlocks next', UnlockService::modulePassed('passed', true, false, 3));
check('in-progress with test stays locked even if lessons done', !UnlockService::modulePassed('in_progress', true, true, 3));
check('no test: lessons done passes module', UnlockService::modulePassed(null, false, true, 3));
check('no test: lessons pending does not pass', !UnlockService::modulePassed(null, false, false, 3));
check('15 day due date', UnlockService::testDueAt('2026-01-01 10:00:00', 15) === '2026-01-16 10:00:00');
check('test not due before date', !UnlockService::isTestDue('2026-01-16 10:00:00', '2026-01-15 23:59:59'));
check('test due on date', UnlockService::isTestDue('2026-01-16 10:00:00', '2026-01-16 10:00:00'));

echo "Server-side scoring\n";
$qs = [
    1 => ['marks' => 1, 'correct' => [11], 'options' => [10, 11, 12]],
    2 => ['marks' => 2, 'correct' => [21], 'options' => [20, 21]],
    3 => ['marks' => 1, 'correct' => [31], 'options' => [30, 31]],
];
$r = TestService::calculate($qs, [1 => 11, 2 => 21, 3 => 30]);
check('earned 3 of 4', $r['earned'] === 3 && $r['total'] === 4 && $r['percent'] === 75.0);
$r = TestService::calculate($qs, [1 => 99, 2 => '21', 3 => 31]);
check('option not belonging to question scores zero', $r['detail'][1]['correct'] === false && $r['detail'][2]['correct'] === true);
$r = TestService::calculate($qs, []);
check('no answers = 0%', $r['earned'] === 0 && $r['percent'] === 0.0);
$exact = [];
for ($i = 1; $i <= 100; $i++) {
    $exact[$i] = ['marks' => 1, 'correct' => [$i * 10], 'options' => [$i * 10, $i * 10 + 1]];
}
$ans49 = [];
$ans50 = [];
for ($i = 1; $i <= 100; $i++) {
    $ans49[$i] = $i <= 49 ? $i * 10 : $i * 10 + 1;
    $ans50[$i] = $i <= 50 ? $i * 10 : $i * 10 + 1;
}
$c49 = TestService::calculate($exact, $ans49);
$c50 = TestService::calculate($exact, $ans50);
check('49/100 -> fail', !UnlockService::passes($c49['earned'], $c49['total'], 50, 'gte'));
check('50/100 -> pass', UnlockService::passes($c50['earned'], $c50['total'], 50, 'gte'));

echo "Translation fallback chain\n";
check('chain follows fallbacks then default', TranslationService::buildChain('mr', ['mr' => 'hi', 'hi' => 'en'], 'en') === ['mr', 'hi', 'en']);
check('loop-safe', TranslationService::buildChain('a', ['a' => 'b', 'b' => 'a'], 'en') === ['a', 'b', 'en']);
check('default appended when missing', TranslationService::buildChain('hi', [], 'en') === ['hi', 'en']);

echo "CSRF\n";
$_SESSION = [];
$tok = Csrf::token();
check('token is 64 hex chars', (bool) preg_match('/^[0-9a-f]{64}$/', $tok));
check('valid token accepted', Csrf::verify($tok));
check('wrong token rejected', !Csrf::verify(str_repeat('a', 64)));
check('empty / null rejected', !Csrf::verify('') && !Csrf::verify(null));
$_SESSION = [];
check('no session token rejects everything', !Csrf::verify($tok));

echo "Validator\n";
check('int accepts digits', Validator::int('42', 'x', 1) === 42);
check('int rejects SQL-ish input', throws(fn () => Validator::int('1 OR 1=1', 'x')));
check('int rejects out of range', throws(fn () => Validator::int('0', 'x', 1)));
check('date accepts valid', Validator::date('2026-02-28') === '2026-02-28');
check('date rejects 2026-02-30', throws(fn () => Validator::date('2026-02-30')));
check('uuid valid', Validator::uuid('123e4567-e89b-42d3-a456-426614174000') !== '');
check('uuid invalid', throws(fn () => Validator::uuid('not-a-uuid')));
check('inList strict', throws(fn () => Validator::inList('Present', ['present', 'absent'], 'x')));
check('email normalised', Validator::email('A@B.com') === 'a@b.com');
check('password min length', throws(fn () => Validator::password('short')));
check('intList dedupes and drops junk', Validator::intList(['1', '1', 'x', '-3', '7']) === [1, 7]);

echo "Sync helpers\n";
$now = strtotime('2026-06-30 12:00:00');
check('future client time clamped to now', SyncService::effectiveTime('2030-01-01T00:00:00Z', $now) === date('Y-m-d H:i:s', $now));
check('very old client time clamped to 30 days', SyncService::effectiveTime('2020-01-01T00:00:00Z', $now) === date('Y-m-d H:i:s', $now - 30 * 86400));
check('garbage time -> now', SyncService::effectiveTime('garbage', $now) === date('Y-m-d H:i:s', $now));
$_POST = ['events' => 'not json'];
check('parseEvents rejects non-JSON', throws(fn () => SyncService::parseEvents()));
$_POST = ['events' => json_encode([['event_uuid' => 'b', 'client_ts' => '2026-01-02'], ['event_uuid' => 'a', 'client_ts' => '2026-01-01']])];
check('parseEvents orders by client time', SyncService::parseEvents()[0]['event_uuid'] === 'a');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
