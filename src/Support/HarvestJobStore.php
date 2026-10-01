<?php

namespace Src\Support;

/**
 * Keeps the state of a Divar collection run ("harvest job") in the PHP session.
 *
 * Why the session and not a table?
 *
 *  - The Divar collector uses only contacts and accommodations; call_logs is a
 *    separate call-tracking table, not collection bookkeeping.
 *    The collector must not add its own bookkeeping table.
 *  - The harvest is driven by the browser: one HTTP request fetches ONE Divar
 *    page, stores it and returns. The only thing that must survive between
 *    those requests is Divar's cursor plus a few counters - which is exactly
 *    what a session is for.
 *  - Because the cursor lives server side, closing and reopening the page can
 *    resume the very same run instead of starting over.
 */
final class HarvestJobStore
{
    private const SESSION_KEY = 'divar_collect_jobs';

    /**
     * How many jobs are remembered per browser session.
     */
    private const MAX_JOBS = 5;

    /**
     * Log lines kept server side (the browser keeps a longer log itself).
     */
    private const LOG_TAIL = 8;

    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ERROR = 'error';
    public const STATUS_DONE = 'done';

    public static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        if (headers_sent()) {
            return;
        }

        session_start();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        self::ensureSession();

        $jobs = $_SESSION[self::SESSION_KEY] ?? [];

        return is_array($jobs) ? $jobs : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $jobId): ?array
    {
        $jobs = self::all();

        $job = $jobs[$jobId] ?? null;

        return is_array($job) ? $job : null;
    }

    /**
     * Create a new job.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function create(array $params): array
    {
        self::ensureSession();

        $now = time();

        $job = [
            'id' => self::uuid(),
            'signature' => (string) ($params['signature'] ?? ''),
            'status' => self::STATUS_RUNNING,
            'provider' => 'divar',

            'province' => (string) ($params['province'] ?? ''),
            'city' => (string) ($params['city'] ?? ''),
            'city_slug' => (string) ($params['city_slug'] ?? ''),
            'divar_city_id' => (string) ($params['divar_city_id'] ?? ''),
            'divar_slug' => (string) ($params['divar_slug'] ?? ''),
            'category' => (string) ($params['category'] ?? ''),
            'category_label' => (string) ($params['category_label'] ?? ''),
            'query' => (string) ($params['query'] ?? ''),

            'cursor' => null,
            'page_limit' => max(1, (int) ($params['page_limit'] ?? 24)),
            'max_steps' => max(0, (int) ($params['max_steps'] ?? 0)),
            'step_delay_ms' => max(0, (int) ($params['step_delay_ms'] ?? 800)),
            'max_empty_pages' => max(1, (int) ($params['max_empty_pages'] ?? 30)),

            'steps' => 0,
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'errors' => 0,
            'empty_pages' => 0,
            'last_error' => null,
            'stop_reason' => null,

            'started_at' => $now,
            'updated_at' => $now,
            'finished_at' => null,

            'log' => [],
        ];

        self::persist($job);

        return $job;
    }

    /**
     * Store a changed job.
     *
     * @param array<string,mixed> $job
     */
    public static function save(array $job): void
    {
        $job['updated_at'] = time();

        self::persist($job);
    }

    /**
     * Append one line to the (short) server side job log.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function pushLog(array $job, string $message): array
    {
        $log = $job['log'] ?? [];

        if (!is_array($log)) {
            $log = [];
        }

        $log[] = [
            'time' => date('H:i:s'),
            'message' => $message,
        ];

        if (count($log) > self::LOG_TAIL) {
            $log = array_slice($log, -self::LOG_TAIL);
        }

        $job['log'] = $log;

        return $job;
    }

    /**
     * Most recent job that is not finished yet.
     *
     * When a signature is given, only jobs of that exact search are considered,
     * which is what makes "resume after a page reload" safe: a Tehran/villa run
     * is never resumed by a Shiraz/apartment run.
     */
    public static function unfinished(?string $signature = null): ?array
    {
        $jobs = self::all();

        $best = null;

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $status = $job['status'] ?? '';

            if ($status === self::STATUS_DONE) {
                continue;
            }

            if ($signature !== null && ($job['signature'] ?? '') !== $signature) {
                continue;
            }

            if ($best === null || (int) ($job['updated_at'] ?? 0) > (int) ($best['updated_at'] ?? 0)) {
                $best = $job;
            }
        }

        return $best;
    }

    public static function forget(string $jobId): void
    {
        self::ensureSession();

        $jobs = self::all();

        unset($jobs[$jobId]);

        $_SESSION[self::SESSION_KEY] = $jobs;
    }

    /**
     * Forget every job (used by the "شروع از اول" button).
     */
    public static function flush(): void
    {
        self::ensureSession();

        $_SESSION[self::SESSION_KEY] = [];
    }

    /**
     * Identity of a search: same selection = same job.
     */
    public static function signature(
        string $divarCityId,
        string $city,
        string $category,
        string $query
    ): string {
        return substr(
            hash(
                'sha256',
                implode('|', ['divar-collect', $divarCityId, $city, $category, $query])
            ),
            0,
            32
        );
    }

    /**
     * Job without the internal cursor, safe to send to the browser.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function publicView(array $job): array
    {
        $public = $job;

        unset($public['cursor'], $public['signature']);

        $public['has_cursor'] = is_string($job['cursor'] ?? null) && ($job['cursor'] ?? '') !== '';

        return $public;
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function persist(array $job): void
    {
        self::ensureSession();

        $id = (string) ($job['id'] ?? '');

        if ($id === '') {
            return;
        }

        $jobs = self::all();

        $jobs[$id] = $job;

        $_SESSION[self::SESSION_KEY] = self::prune($jobs);
    }

    /**
     * Keep only the newest jobs so a session never grows without limit.
     *
     * @param array<string,array<string,mixed>> $jobs
     * @return array<string,array<string,mixed>>
     */
    private static function prune(array $jobs): array
    {
        if (count($jobs) <= self::MAX_JOBS) {
            return $jobs;
        }

        uasort(
            $jobs,
            static fn (array $a, array $b): int =>
                (int) ($b['updated_at'] ?? 0) <=> (int) ($a['updated_at'] ?? 0)
        );

        return array_slice($jobs, 0, self::MAX_JOBS, true);
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);

        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }
}
