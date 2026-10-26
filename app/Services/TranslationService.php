<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth, Database};

final class TranslationService
{
    /** @var array<int,array<string,mixed>>|null */
    private static ?array $languages = null;
    private static ?string $current = null;
    /** @var array<string,string>|null */
    private static ?array $ui = null;

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return self::$languages ??= Database::all("SELECT *, is_active AS is_enabled FROM languages WHERE code IN ('hi','en') ORDER BY sort_order, name");
    }

    /** @return array<int,array<string,mixed>> */
    public static function enabled(): array
    {
        return array_values(array_filter(self::all(), static fn ($l) => (int) $l['is_enabled'] === 1));
    }

    public static function isEnabled(string $code): bool
    {
        foreach (self::enabled() as $l) {
            if ($l['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    public static function defaultCode(): string
    {
        foreach (self::all() as $l) {
            if ((int) $l['is_default'] === 1) {
                return (string) $l['code'];
            }
        }
        return 'en';
    }

    public static function currentCode(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $candidates = [$_GET['lang'] ?? null, $_SESSION['lang'] ?? null, Auth::user()['preferred_language'] ?? null];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && self::isEnabled($c)) {
                return self::$current = $c;
            }
        }
        return self::$current = self::defaultCode();
    }

    /** Pure: builds the fallback chain, loop-safe. $fallbackMap = [code => fallbackCode]. */
    public static function buildChain(string $code, array $fallbackMap, string $default): array
    {
        $chain = [];
        $cur = $code;
        $guard = 0;
        while ($cur !== '' && !in_array($cur, $chain, true) && $guard++ < 10) {
            $chain[] = $cur;
            $cur = (string) ($fallbackMap[$cur] ?? '');
        }
        if (!in_array($default, $chain, true)) {
            $chain[] = $default;
        }
        return $chain;
    }

    public static function chain(?string $code = null): array
    {
        $map = [];
        foreach (self::all() as $l) {
            $map[$l['code']] = (string) ($l['fallback_code'] ?? '');
        }
        return self::buildChain($code ?? self::currentCode(), $map, self::defaultCode());
    }

    public static function ttsLocale(?string $code = null): string
    {
        $code ??= self::currentCode();
        foreach (self::all() as $l) {
            if ($l['code'] === $code) {
                return (string) (($l['tts_locale'] ?? '') !== '' ? $l['tts_locale'] : $code);
            }
        }
        return $code;
    }

    public static function ui(string $key, ?string $default = null): string
    {
        if (self::$ui === null) {
            self::$ui = [];
            try {
                $chain = array_reverse(self::chain());
                foreach ($chain as $code) {
                    foreach (Database::all('SELECT t.string_key AS translation_key, t.string_value AS translation_value FROM ui_translations t JOIN languages l ON l.id = t.language_id WHERE l.code = ?', [$code]) as $r) {
                        self::$ui[$r['translation_key']] = (string) $r['translation_value'];
                    }
                }
            } catch (\Throwable) {
                self::$ui = [];
            }
        }
        return self::$ui[$key] ?? tr($default ?? $key);
    }

    /** Pick the first chain language that has a non-empty title. */
    private static function pick(array $byLang, array $chain): ?array
    {
        foreach ($chain as $code) {
            if (isset($byLang[$code]) && trim((string) ($byLang[$code]['title'] ?? '')) !== '') {
                return $byLang[$code];
            }
        }
        return null;
    }

    /** @return array<int,array{title:string,description:?string}> keyed by module id (only ids that have a translation) */
    public static function bulkModules(array $ids, ?string $lang = null): array
    {
        if (!$ids) {
            return [];
        }
        $chain = self::chain($lang);
        $rows = Database::all(
            'SELECT module_id, language_code, title, description FROM module_translations WHERE module_id IN (' . Database::in($ids) . ') AND language_code IN (' . Database::in($chain) . ')',
            array_merge($ids, $chain)
        );
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['module_id']][$r['language_code']] = $r;
        }
        $out = [];
        foreach ($by as $id => $langs) {
            if ($p = self::pick($langs, $chain)) {
                $out[$id] = ['title' => (string) $p['title'], 'description' => $p['description']];
            }
        }
        return $out;
    }

    /** @return array<int,array{title:string,body:?string}> */
    public static function bulkLessons(array $ids, ?string $lang = null): array
    {
        if (!$ids) {
            return [];
        }
        $chain = self::chain($lang);
        $rows = Database::all(
            'SELECT content_item_id, language_code, title, body FROM content_item_translations WHERE content_item_id IN (' . Database::in($ids) . ') AND language_code IN (' . Database::in($chain) . ')',
            array_merge($ids, $chain)
        );
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['content_item_id']][$r['language_code']] = $r;
        }
        $out = [];
        foreach ($by as $id => $langs) {
            if ($p = self::pick($langs, $chain)) {
                $out[$id] = ['title' => (string) $p['title'], 'body' => $p['body']];
            }
        }
        return $out;
    }
}

