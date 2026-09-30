#!/usr/bin/env php
<?php

/**
 * Divar Phone Numbers Updater - Cron Job / CLI Worker
 *
 * Checks the database for accommodation records without phone numbers (contact_id IS NULL),
 * queries Divar Contact API with rate limiting, and saves phones into the contacts table.
 *
 * Usage:
 *   php cron/update_phone_numbers.php [options]
 *
 * Options:
 *   --limit=N          Max records to process in this run (default: 20)
 *   --delay=N          Base delay in milliseconds between requests (default: 3000)
 *   --city=NAME        Filter by city name or slug
 *   --retry-failed     Retry items previously marked as failed or no_phone
 *   --reset-failed     Reset all failed items to 'retry' status and exit
 *   --cookies=STRING   Import and save Divar cookie string (did=...; sAccessToken=...)
 *   --cookie-file=PATH Load cookies from a JSON or text file
 *   --dry-run          Simulate run without calling Divar API or updating DB
 *   --stats            Display current database and quota statistics and exit
 *   --quiet, -q        Suppress regular console output (only output errors)
 *   --help, -h         Display this help message
 *
 * Crontab Example:
 *   # Run every 15 minutes, log output
 *   * /15 * * * * cd /path/to/search_place && php cron/update_phone_numbers.php >> storage/logs/cron.log 2>&1
 */

declare(strict_types=1);

// Ensure we are in CLI mode
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line (CLI).\n";
    exit(1);
}

// Set up paths and autoloading
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/vendor/autoload.php';

use Src\Divar\AccommodationRepository;
use Src\Divar\DivarCookieManager;
use Src\Divar\DivarPhoneUpdaterService;
use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Support\Schema;

// Parse command line arguments
$options = getopt('qh', [
    'limit:',
    'delay:',
    'city:',
    'retry-failed',
    'reset-failed',
    'cookies:',
    'cookie-file:',
    'dry-run',
    'stats',
    'quiet',
    'help',
]);

$quiet = isset($options['q']) || isset($options['quiet']);
$showHelp = isset($options['h']) || isset($options['help']);

if ($showHelp) {
    printHelp();
    exit(0);
}

// Output helper that respects --quiet
$out = function (string $text) use ($quiet): void {
    if (!$quiet) {
        echo $text;
    }
};

$out("\n=======================================================\n");
$out(" 📞 Divar Phone Number Updater (بروزرسانی شماره‌های دیوار)\n");
$out("=======================================================\n");

// Ensure storage directories exist
$storageDir = $projectRoot . '/storage';
$logsDir = $projectRoot . '/storage/logs';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

// 1. Handle Cookie Import if passed via arguments
if (!empty($options['cookies'])) {
    $cookieStr = trim((string) $options['cookies']);
    $parsed = DivarCookieManager::parseCookieString($cookieStr);
    if (!empty($parsed)) {
        DivarCookieManager::setCookies($parsed);
        $out("✔ کوکی‌های دیوار با موفقیت ذخیره شدند (" . count($parsed) . " کوکی).\n");
    } else {
        fwrite(STDERR, "✖ خطا در پردازش رشته کوکی‌ها.\n");
        exit(1);
    }
}

if (!empty($options['cookie-file'])) {
    $filePath = (string) $options['cookie-file'];
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        if (is_array($data)) {
            $cookies = $data['cookies'] ?? $data;
            DivarCookieManager::setCookies($cookies);
            $out("✔ کوکی‌ها از فایل {$filePath} بارگذاری و ذخیره شدند.\n");
        } else {
            $parsed = DivarCookieManager::parseCookieString($content);
            if (!empty($parsed)) {
                DivarCookieManager::setCookies($parsed);
                $out("✔ کوکی‌های متنی از فایل بارگذاری شدند.\n");
            } else {
                fwrite(STDERR, "✖ فرمت فایل کوکی نامعتبر است.\n");
                exit(1);
            }
        }
    } else {
        fwrite(STDERR, "✖ فایل کوکی یافت نشد: {$filePath}\n");
        exit(1);
    }
}

// 2. Initialize Database Connection & Tables
Config::load($projectRoot . '/config/divar.php');

try {
    Db::connect(
        (string) Config::get('db_host', '127.0.0.1'),
        (int) Config::get('db_port', 3306),
        (string) Config::get('db_database', 'search_place'),
        (string) Config::get('db_username', 'root'),
        (string) Config::get('db_password', '')
    );

    Schema::ensureTables();
} catch (Throwable $e) {
    fwrite(STDERR, "✖ خطا در اتصال به دیتابیس: " . $e->getMessage() . "\n");
    Logger::error('Phone updater database error', ['error' => $e->getMessage()]);
    exit(1);
}

$pdo = Db::getConnection();
$service = new DivarPhoneUpdaterService($pdo);

// 3. Handle --reset-failed option
if (isset($options['reset-failed'])) {
    $cityFilter = $options['city'] ?? null;
    $count = $service->resetFailedStatuses($cityFilter);
    $out("✔ وضعیت {$count} آگهی با خطا یا بدون شماره به حالت 'retry' بازنشانی شد.\n\n");
    exit(0);
}

// 4. Handle --stats option
if (isset($options['stats'])) {
    $cityFilter = $options['city'] ?? null;
    $stats = $service->getDatabaseStats($cityFilter);
    printStatsTable($stats, $cityFilter);
    exit(0);
}

// 5. Build run options
$runOptions = [];
if (!empty($options['limit'])) {
    $runOptions['limit'] = (int) $options['limit'];
}
if (!empty($options['delay'])) {
    $runOptions['delay_ms'] = (int) $options['delay'];
}
if (!empty($options['city'])) {
    $runOptions['city'] = (string) $options['city'];
}
if (isset($options['retry-failed'])) {
    $runOptions['retry_failed'] = true;
}
if (isset($options['dry-run'])) {
    $runOptions['dry_run'] = true;
    $out("⚠️ حالت آزمایشی (Dry Run) فعال است: هیچ تماس یا تغییری در دیتابیس ثبت نمی‌شود.\n");
}

// 6. Set up progress callback
$runOptions['progress_callback'] = function (string $event, array $data) use ($out): void {
    switch ($event) {
        case 'batch_start':
            $out(sprintf("▶ شروع بررسی %d آگهی (سقف دسته: %d)...\n\n", $data['count'], $data['limit']));
            break;

        case 'item_start':
            $titleShort = mb_substr($data['title'], 0, 35, 'UTF-8');
            $out(sprintf("  [%d/%d] توکن: %s | %s ... ", $data['index'], $data['total'], $data['token'], $titleShort));
            break;

        case 'item_found':
            $out("✔ تلفن: " . $data['phone'] . "\n");
            break;

        case 'item_no_phone':
            $out("⚪ فاقد شماره (چت یا مخفی)\n");
            break;

        case 'item_expired':
            $out("❌ آگهی منقضی یا حذف شده (404)\n");
            break;

        case 'item_error':
            $out("⚠️ خطا: " . $data['error'] . "\n");
            break;

        case 'delay':
            $out(sprintf("     ⏳ مکث به مدت %.1f ثانیه جهت رعایت محدودیت دیوار...\n", $data['delay_ms'] / 1000));
            break;

        case 'rate_limited':
            $out("⛔ اخطار: محدودیت نرخ دیوار (HTTP 429) رخ داد! فرآیند موقتا متوقف شد.\n");
            break;

        case 'auth_missing':
            $out("🔑 خطا: اعتبار ورود به دیوار منقضی شده یا وجود ندارد.\n");
            break;

        case 'daily_limit':
            $out("🛑 سقف روزانه مجاز درخواست‌ها تکمیل شده است.\n");
            break;
    }
};

// 7. Execute the updater
$out("در حال آماده‌سازی و استعلام از دیتابیس...\n");
$result = $service->run($runOptions);

// 8. Output Final Summary
$out("\n-------------------------------------------------------\n");
$out(" 📊 گزارش نهایی اجرای کرون‌جاب (Execution Summary):\n");
$out("-------------------------------------------------------\n");
$out(sprintf(" • تعداد کل پردازش شده: %d\n", $result['processed']));
$out(sprintf(" • شماره‌های تماس یافت و ثبت شده: %d\n", $result['phones_found']));
$out(sprintf(" • بدون شماره / چت تنها: %d\n", $result['no_phone']));
$out(sprintf(" • آگهی‌های منقضی/حذف شده (404): %d\n", $result['expired']));
$out(sprintf(" • خطاهای سرور/شبکه: %d\n", $result['errors']));
$out(sprintf(" • سهمیه روزانه مصرف شده: %d از %d\n", $result['daily_requests_used'], $result['daily_requests_limit']));
$out(sprintf(" • مدت زمان اجرا: %.2f ثانیه\n", $result['elapsed_seconds']));
$out(sprintf(" • وضعیت پایان: %s\n", $result['stopped_reason']));

if (!empty($result['error_message'])) {
    $out(" • پیام: " . $result['error_message'] . "\n");
}
$out("-------------------------------------------------------\n\n");

// Determine exit code
if (in_array($result['stopped_reason'], ['fatal_exception'], true)) {
    exit(1);
}

exit(0);

/**
 * Print help information.
 */
function printHelp(): void
{
    echo <<<HELP
Divar Phone Numbers Updater - Cron Job / CLI Worker

استفاده:
  php cron/update_phone_numbers.php [گزینه‌ها]

گزینه‌ها:
  --limit=N          حداکثر تعداد آگهی برای بررسی در این اجرا (پیش‌فرض: 20)
  --delay=N          تاخیر پایه بین درخواست‌ها به میلی‌ثانیه (پیش‌فرض: 3000)
  --city=NAME        فیلتر بر اساس نام یا اسلاگ شهر
  --retry-failed     تلاش مجدد برای آگهی‌هایی که قبلا فاقد شماره یا دارای خطا بودند
  --reset-failed     بازنشانی وضعیت آگهی‌های دارای خطا به حالت اول و خروج
  --cookies=STR      ثبت و ذخیره رشته کوکی‌های نشست دیوار
  --cookie-file=PATH خواندن کوکی‌ها از فایل JSON یا متنی
  --dry-run          اجرای آزمایشی بدون ارسال درخواست به دیوار یا تغییر دیتابیس
  --stats            نمایش آمار آگهی‌ها، شماره‌ها و سهمیه و خروج
  --quiet, -q        اجرای بی‌صدا (مناسب کرون‌تاب)
  --help, -h         نمایش این راهنما

نمونه تنظیم در crontab (اجرا هر ۱۵ دقیقه):
  */15 * * * * cd /path/to/search_place && php cron/update_phone_numbers.php -q >> storage/logs/cron.log 2>&1

HELP;
}

/**
 * Print statistics table.
 */
function printStatsTable(array $stats, ?string $city): void
{
    echo "\n=======================================================\n";
    echo " 📊 آمار وضعیت دیتابیس و سهمیه دیوار " . ($city ? "($city)" : "") . "\n";
    echo "=======================================================\n";
    printf(" • کل آگهی‌های دیوار در دیتابیس:  %d\n", $stats['total_divar_accommodations']);
    printf(" • آگهی‌های دارای شماره تلفن:      %d\n", $stats['with_phone']);
    printf(" • آگهی‌های در انتظار شماره:       %d\n", $stats['pending_phone']);
    echo "-------------------------------------------------------\n";
    printf(" • وضعیت اعتبار کوکی‌های دیوار:     %s\n", $stats['auth_valid'] ? 'معتبر (Active)' : 'نامعتبر یا منقضی (Expired/Missing)');
    printf(" • وضعیت محدودیت نرخ (429):        %s\n", $stats['rate_limited'] ? "محدود (باقی‌مانده: {$stats['rate_limit_remaining_sec']} ثانیه)" : 'آزاد (Normal)');
    printf(" • مصرف سهمیه امروز:              %d از %d درخواست\n", $stats['daily_requests_used'], $stats['daily_requests_limit']);
    printf(" • سهمیه باقی‌مانده امروز:          %d درخواست\n", $stats['daily_requests_remaining']);
    printf(" • تنظیمات: اندازه دسته: %d | تاخیر پایه: %d میلی‌ثانیه\n", $stats['batch_size'], $stats['request_delay_ms']);
    echo "=======================================================\n\n";
}
