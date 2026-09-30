<?php

namespace Src\View;

/**
 * Page of the /divar_collect route.
 *
 * The selection part of the form is intentionally identical to the Divar search
 * page (province -> city -> category -> optional keyword). What changes is the
 * result part: there is no list and no pagination at all, only a live progress
 * panel, because every fetched ad goes straight into the `accommodations`
 * table.
 *
 * @see \Src\Controller\DivarCollectController
 */
final class DivarCollectView
{
    /** @var array<string,mixed> */
    private array $data;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * @return mixed
     */
    private function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function render(): void
    {
        $provinces = (array) $this->get('provinces', []);
        $categories = (array) $this->get('categories', []);
        $provinceCities = (array) $this->get('province_cities', []);
        $allProvinceCities = (array) $this->get('all_province_cities', []);

        $selectedProvince = (string) $this->get('selected_province', '');
        $selectedCity = (string) $this->get('selected_city', '');
        $selectedCityName = (string) $this->get('selected_city_name', '');
        $selectedCategory = (string) $this->get('selected_category', '');
        $selectedQuery = (string) $this->get('selected_query', '');

        $cityInfo = $this->get('city_info');
        $cityInfo = is_array($cityInfo) ? $cityInfo : null;

        $error = $this->get('error');
        $fatalError = $this->get('fatal_error');

        $job = $this->get('job');
        $job = is_array($job) ? $job : null;

        $endpoint = (string) $this->get('endpoint', '');
        $searchUrl = (string) $this->get('search_url', '');
        $autoStart = (bool) $this->get('auto_start', false);
        $options = (array) $this->get('options', []);

        $dbTotal = $options['db_total'] ?? null;
        $contextRows = $options['context_rows'] ?? null;
        $schemaReady = (bool) ($options['schema_ready'] ?? false);

        $maxSteps = (int) ($options['max_steps'] ?? 0);
        $stepDelay = (int) ($options['step_delay_ms'] ?? 800);

        $jsConfig = [
            'endpoint' => $endpoint,
            'step_delay_ms' => $stepDelay,
            'max_retries' => (int) ($options['max_retries'] ?? 6),
            'retry_base_ms' => (int) ($options['retry_base_ms'] ?? 2000),
            'auto_start' => $autoStart,
            'has_resumable_job' => $job !== null,
        ];
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>جمع‌آوری دیوار - ذخیره در دیتابیس</title>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: 'Vazirmatn', 'Segoe UI', Tahoma, sans-serif;
                    background: #f8f9fa;
                    direction: rtl;
                    color: #333;
                    line-height: 1.6;
                }
                .container { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; }

                .form-box, .panel {
                    background: #fff;
                    padding: 24px 28px;
                    border-radius: 16px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.08);
                    margin-bottom: 20px;
                    border: 1px solid #eef0f2;
                }
                .header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 20px;
                    flex-wrap: wrap;
                    gap: 12px;
                    padding-bottom: 14px;
                    border-bottom: 1px solid #f0f0f0;
                }
                .form-title {
                    font-size: 21px;
                    font-weight: 700;
                    color: #1a1a2e;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .form-title::before { content: '🗄️'; font-size: 22px; }
                .provider-badge {
                    font-size: 12px;
                    font-weight: 600;
                    padding: 4px 12px;
                    border-radius: 999px;
                    background: #ffe9e0;
                    color: #b6410f;
                }
                .subtitle { color: #666; font-size: 14px; margin-bottom: 18px; }
                .back-link { font-size: 13px; color: #4a90d9; text-decoration: none; }
                .back-link:hover { text-decoration: underline; }

                .form-row { display: flex; gap: 16px; flex-wrap: wrap; }
                .form-group { flex: 1; min-width: 200px; margin-bottom: 14px; }
                .form-group label {
                    display: block;
                    margin-bottom: 6px;
                    font-weight: 600;
                    color: #444;
                    font-size: 14px;
                }
                .form-group select, .form-group input[type="text"], .form-group input[type="number"] {
                    width: 100%;
                    padding: 11px 14px;
                    border: 2px solid #e8e8e8;
                    border-radius: 10px;
                    font-size: 15px;
                    font-family: inherit;
                    background: #fafafa;
                    transition: all 0.2s ease;
                }
                .form-group select:focus, .form-group input:focus {
                    outline: none;
                    border-color: #4a90d9;
                    background: #fff;
                    box-shadow: 0 0 0 3px rgba(74,144,217,0.15);
                }
                .hint { font-size: 12px; color: #888; margin-top: 4px; }

                .submit-btn {
                    background: linear-gradient(135deg, #2f9e6e 0%, #1f7d55 100%);
                    color: #fff;
                    padding: 14px 28px;
                    border: none;
                    border-radius: 10px;
                    font-size: 16px;
                    font-weight: 700;
                    font-family: inherit;
                    cursor: pointer;
                    width: 100%;
                    margin-top: 6px;
                    box-shadow: 0 2px 6px rgba(31,125,85,0.3);
                    transition: all .2s ease;
                }
                .submit-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(31,125,85,0.4); }
                .submit-btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }

                .btn {
                    padding: 10px 18px;
                    border: 2px solid #dfe3e8;
                    background: #fff;
                    border-radius: 10px;
                    font-size: 14px;
                    font-weight: 600;
                    font-family: inherit;
                    cursor: pointer;
                    color: #333;
                    transition: all .2s ease;
                }
                .btn:hover:not(:disabled) { border-color: #b9c2cc; background: #f7f9fb; }
                .btn:disabled { opacity: .5; cursor: not-allowed; }
                .btn-stop { color: #b42318; border-color: #f3c9c4; }
                .btn-stop:hover:not(:disabled) { background: #fff5f4; border-color: #e7a49c; }
                .btn-primary { color: #fff; background: linear-gradient(135deg, #4a90d9 0%, #357abd 100%); border-color: transparent; }

                .error {
                    background: #fff4f4;
                    border: 1px solid #f5c6c6;
                    color: #a12626;
                    padding: 14px 18px;
                    border-radius: 12px;
                    margin-bottom: 18px;
                    font-size: 14px;
                }
                .info {
                    background: #f2f8ff;
                    border: 1px solid #cfe3fb;
                    color: #23527c;
                    padding: 14px 18px;
                    border-radius: 12px;
                    margin-bottom: 18px;
                    font-size: 14px;
                }
                .warn {
                    background: #fffbf0;
                    border: 1px solid #f5e2b3;
                    color: #7a5b12;
                    padding: 14px 18px;
                    border-radius: 12px;
                    margin-bottom: 18px;
                    font-size: 14px;
                }
                [hidden] { display: none !important; }

                .status-line { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
                .status-pill {
                    font-size: 13px;
                    font-weight: 700;
                    padding: 5px 14px;
                    border-radius: 999px;
                    background: #eef1f4;
                    color: #555;
                }
                .status-pill.running { background: #e6f7ee; color: #1f7d55; }
                .status-pill.paused  { background: #fff4e0; color: #96650a; }
                .status-pill.error   { background: #fdeaea; color: #b42318; }
                .status-pill.done    { background: #e8f1fd; color: #23527c; }
                .spinner {
                    width: 14px; height: 14px;
                    border: 2px solid #bfe6d2; border-top-color: #1f7d55;
                    border-radius: 50%;
                    animation: spin 0.9s linear infinite;
                    display: inline-block;
                }
                @keyframes spin { to { transform: rotate(360deg); } }

                .bar-track {
                    height: 10px;
                    background: #eef1f4;
                    border-radius: 999px;
                    overflow: hidden;
                    margin-bottom: 18px;
                }
                .bar-fill {
                    height: 100%;
                    width: 35%;
                    border-radius: 999px;
                    background: linear-gradient(90deg, #2f9e6e, #6fd3a4, #2f9e6e);
                    background-size: 200% 100%;
                    animation: slide 1.4s linear infinite;
                }
                .bar-fill.idle { width: 100%; background: #dfe3e8; animation: none; }
                .bar-fill.done { width: 100%; background: linear-gradient(90deg,#4a90d9,#7fb6ea); animation: none; }
                @keyframes slide {
                    0%   { transform: translateX(180%); background-position: 0% 0; }
                    100% { transform: translateX(-320%); background-position: 200% 0; }
                }

                .counters { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 16px; }
                .counter {
                    background: #fafbfc;
                    border: 1px solid #eef0f2;
                    border-radius: 12px;
                    padding: 12px 10px;
                    text-align: center;
                }
                .counter .value { font-size: 20px; font-weight: 700; color: #1a1a2e; }
                .counter .label { font-size: 12px; color: #777; margin-top: 2px; }
                .counter.ok .value { color: #1f7d55; }
                .counter.warnv .value { color: #96650a; }
                .counter.errv .value { color: #b42318; }

                .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }

                .log {
                    background: #14181f;
                    color: #d6dee8;
                    border-radius: 12px;
                    padding: 12px 14px;
                    font-family: 'Vazirmatn', 'Courier New', monospace;
                    font-size: 12.5px;
                    line-height: 1.9;
                    height: 240px;
                    overflow-y: auto;
                    direction: rtl;
                    white-space: pre-wrap;
                    word-break: break-word;
                }
                .log .t { color: #7d8899; margin-left: 6px; }
                .log .ok { color: #7ee2ac; }
                .log .err { color: #ff9b93; }
                .log .info { color: #9ecbff; }
                .log .dim { color: #8b95a3; }

                .db-list { font-size: 13px; color: #555; }
                .db-list li { margin-bottom: 6px; }
                code {
                    background: #f1f3f5;
                    border-radius: 6px;
                    padding: 1px 6px;
                    font-size: 12px;
                    direction: ltr;
                    display: inline-block;
                }
                @media (max-width: 640px) {
                    .form-group { min-width: 100%; }
                    .log { height: 190px; }
                }
            </style>
        </head>
        <body>
        <div class="container">

            <?php if ($fatalError): ?>
                <div class="error">
                    <strong>دیتابیس در دسترس نیست.</strong><br>
                    <?= htmlspecialchars((string) $fatalError) ?><br>
                    <span class="hint">
                        تنظیمات اتصال در <code>.env</code> / <code>config/divar.php</code> و وجود فایل
                        <code>database/schema.sql</code> را بررسی کنید.
                    </span>
                </div>
            <?php endif; ?>

            <div class="form-box">
                <div class="header">
                    <div class="form-title">
                        جمع‌آوری و ذخیره‌ی آگهی‌ها
                        <span class="provider-badge">دیوار</span>
                    </div>
                    <?php if ($searchUrl !== ''): ?>
                        <form method="POST" action="<?= htmlspecialchars($searchUrl) ?>" style="display:inline;">
                            <input type="hidden" name="provider" value="divar">
                            <button type="submit" class="back-link" style="background:none;border:none;cursor:pointer;font-family:inherit;">
                                ← بازگشت به صفحه جستجوی دیوار
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <p class="subtitle">
                    انتخاب‌ها دقیقاً مثل صفحه جستجو است؛ تفاوت اینجاست که نتیجه صفحه‌بندی و نمایش داده
                    <strong>نمی‌شود</strong>. همه‌ی آگهی‌های این جستجو، صفحه به صفحه از دیوار گرفته و مستقیماً در جدول
                    <code>accommodations</code> ذخیره می‌شوند تا تمام شوند.
                </p>

                <?php if ($error): ?>
                    <div class="error" id="serverError"><?= htmlspecialchars((string) $error) ?></div>
                <?php else: ?>
                    <div class="error" id="serverError" hidden></div>
                <?php endif; ?>

                <form method="POST" id="collectForm" action="<?= htmlspecialchars($endpoint) ?>">
                    <input type="hidden" name="provider" value="divar">
                    <input type="hidden" name="start" id="startFlag" value="1">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="provinceSelect">استان</label>
                            <select name="province" id="provinceSelect" required>
                                <option value="">-- انتخاب استان --</option>
                                <?php foreach ($provinces as $province): ?>
                                    <?php $provinceName = is_array($province) ? (string) ($province['name'] ?? '') : (string) $province; ?>
                                    <?php if ($provinceName === '') {
                                        continue;
                                    } ?>
                                    <option value="<?= htmlspecialchars($provinceName) ?>"
                                        <?= ($selectedProvince === $provinceName) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($provinceName) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="citySelect">شهر</label>
                            <select name="city" id="citySelect" required>
                                <option value="">-- ابتدا استان را انتخاب کنید --</option>
                                <?php foreach ($provinceCities as $slug => $name): ?>
                                    <option value="<?= htmlspecialchars((string) $slug) ?>"
                                        <?= ($selectedCity === (string) $slug) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($cityInfo !== null): ?>
                                <div class="hint">
                                    <?= htmlspecialchars($selectedCityName !== '' ? $selectedCityName : (string) ($cityInfo['name'] ?? '')) ?>
                                    — شناسه‌ی دیوار: <code><?= htmlspecialchars((string) ($cityInfo['divar_city_id'] ?? '-')) ?></code>
                                    / اسلاگ: <code><?= htmlspecialchars((string) ($cityInfo['divar_slug'] ?? '-')) ?></code>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="placeSelect">نوع مکان / دسته‌بندی</label>
                            <select name="place" id="placeSelect">
                                <?php foreach ($categories as $category): ?>
                                    <?php
                                    $value = is_array($category) ? (string) ($category['value'] ?? '') : (string) $category;
                                    $label = is_array($category) ? (string) ($category['label'] ?? $value) : (string) $category;
                                    ?>
                                    <option value="<?= htmlspecialchars($value) ?>"
                                        <?= ($selectedCategory === $value) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="queryInput">جستجوی کلمه کلیدی (اختیاری)</label>
                            <input type="text" name="query" id="queryInput"
                                   placeholder="مثال: بوم گردی، ویلا، آپارتمان..."
                                   value="<?= htmlspecialchars($selectedQuery) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="maxStepsInput">حداکثر صفحات (۰ = بدون محدودیت)</label>
                            <input type="number" name="max_steps" id="maxStepsInput" min="0" max="1000000" step="1"
                                   value="<?= (int) $maxSteps ?>">
                            <div class="hint">هر صفحه ≈ ۲۴ آگهی. برای گرفتن همه‌ی آگهی‌ها همان ۰ بگذارید.</div>
                        </div>

                        <div class="form-group">
                            <label for="stepDelayInput">تأخیر بین درخواست‌ها (میلی‌ثانیه)</label>
                            <input type="number" name="step_delay_ms" id="stepDelayInput" min="0" max="20000" step="100"
                                   value="<?= (int) $stepDelay ?>">
                            <div class="hint">برای فشار نیامدن به دیوار، مقدار پیشنهادی ۸۰۰ است.</div>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="startBtn">
                        شروع جمع‌آوری و ذخیره در دیتابیس
                    </button>
                </form>
            </div>

            <?php if ($schemaReady): ?>
                <div class="info">
                    <strong>وضعیت دیتابیس:</strong>
                    جدول‌های <code>contacts</code> و <code>accommodations</code> آماده‌اند.
                    <?php if ($dbTotal !== null): ?>
                        تاکنون <strong><?= number_format((int) $dbTotal) ?></strong> رکورد دیوار در
                        <code>accommodations</code> ذخیره شده است<?php if ($contextRows !== null): ?>،
                        که <strong><?= number_format((int) $contextRows) ?></strong> مورد آن مربوط به همین
                        شهر/دسته‌بندی است<?php endif; ?>.
                    <?php endif; ?>
                    <br>
                    <span class="hint">
                        ذخیره‌سازی به‌صورت upsert روی کلید یکتای <code>(provider, external_id)</code> انجام می‌شود؛
                        یعنی اجرای دوباره‌ی همان جستجو رکورد تکراری نمی‌سازد.
                    </span>
                </div>
            <?php endif; ?>

            <div class="warn" id="resumeBox" <?= $job === null ? 'hidden' : '' ?>>
                <strong>یک جمع‌آوری نیمه‌تمام برای همین انتخاب وجود دارد.</strong>
                <?php if ($job !== null): ?>
                    <div id="resumeInfo">
                        صفحه‌های انجام‌شده: <strong><?= (int) ($job['steps'] ?? 0) ?></strong> /
                        آگهی‌های گرفته‌شده: <strong><?= number_format((int) ($job['fetched'] ?? 0)) ?></strong> /
                        ذخیره‌شده: <strong><?= number_format((int) ($job['inserted'] ?? 0)) ?></strong>
                        (وضعیت: <?= htmlspecialchars((string) ($job['status'] ?? '')) ?>)
                    </div>
                <?php endif; ?>
                <div class="actions" style="margin-top:12px;">
                    <button type="button" class="btn btn-primary" id="resumeBtn">ادامه‌ی همان جمع‌آوری</button>
                    <button type="button" class="btn" id="restartBtn">شروع از اول</button>
                </div>
            </div>

            <div class="panel" id="progressPanel" hidden>
                <div class="header" style="margin-bottom:14px;">
                    <div class="form-title" style="font-size:18px;">پیشرفت جمع‌آوری</div>
                    <div class="status-line" style="margin:0;">
                        <span class="status-pill" id="statusPill">آماده</span>
                        <span class="spinner" id="spinner" hidden></span>
                    </div>
                </div>

                <div class="bar-track"><div class="bar-fill idle" id="barFill"></div></div>

                <div class="counters">
                    <div class="counter"><div class="value" id="cPages">۰</div><div class="label">صفحه دریافتی</div></div>
                    <div class="counter"><div class="value" id="cFetched">۰</div><div class="label">آگهی گرفته‌شده</div></div>
                    <div class="counter ok"><div class="value" id="cInserted">۰</div><div class="label">رکورد جدید</div></div>
                    <div class="counter warnv"><div class="value" id="cUpdated">۰</div><div class="label">به‌روزشده</div></div>
                    <div class="counter"><div class="value" id="cUnchanged">۰</div><div class="label">تکراری/بدون تغییر</div></div>
                    <div class="counter errv"><div class="value" id="cErrors">۰</div><div class="label">خطا</div></div>
                    <div class="counter"><div class="value" id="cElapsed">۰۰:۰۰</div><div class="label">زمان</div></div>
                    <div class="counter"><div class="value" id="cRate">۰</div><div class="label">آگهی/دقیقه</div></div>
                </div>

                <div class="actions">
                    <button type="button" class="btn btn-stop" id="stopBtn" disabled>توقف</button>
                    <button type="button" class="btn" id="continueBtn" disabled>ادامه</button>
                    <span class="hint" id="lastMessage" style="align-self:center;"></span>
                </div>

                <div class="log" id="logBox"></div>

                <p class="hint" style="margin-top:12px;">
                    این برگه را باز و فعال نگه دارید؛ جمع‌آوری با درخواست‌های کوچک و پشت‌سرهم از همین مرورگر
                    انجام می‌شود. با بستن یا رفرش کردن صفحه، کار متوقف می‌شود ولی وضعیت در نشست سرور می‌ماند و
                    می‌توانید با دکمه‌ی «ادامه» از همان صفحه‌ی دیوار ادامه دهید.
                </p>
            </div>

            <div class="panel">
                <div class="header" style="margin-bottom:12px;">
                    <div class="form-title" style="font-size:17px;">این صفحه چطور کار می‌کند؟</div>
                </div>
                <ol class="db-list" style="padding-right:18px;">
                    <li>
                        انتخاب‌ها (استان، شهر، دسته‌بندی، کلمه کلیدی) دقیقاً همان ورودی‌های صفحه جستجوی دیوار است.
                    </li>
                    <li>
                        چون نتیجه ممکن است هزاران آگهی باشد، کار به قدم‌های کوچک تقسیم می‌شود:
                        هر درخواست مرورگر = <strong>یک صفحه</strong> از دیوار (حدود ۲۴ آگهی).
                    </li>
                    <li>
                        هر صفحه بلافاصله در یک تراکنش در جدول <code>accommodations</code> ذخیره می‌شود و سپس از
                        حافظه آزاد می‌شود؛ بنابراین نه تایم‌اوت PHP مشکل‌ساز است نه حافظه.
                    </li>
                    <li>
                        مکان صفحه‌ی بعدی (cursor دیوار) در نشست سرور نگه داشته می‌شود، پس توقف/ادامه و اجرای
                        دوباره بدون رکورد تکراری ممکن است.
                    </li>
                    <li>
                        چیزی صفحه‌بندی یا نمایش داده نمی‌شود؛ فقط شمارنده‌ها و گزارش زنده را می‌بینید.
                        فیلد <code>contact_id</code> فعلاً خالی می‌ماند تا در مرحله‌ی بعد شماره‌های تلفن
                        گرفته و در جدول <code>contacts</code> ذخیره شود.
                    </li>
                </ol>
            </div>
        </div>

        <script>
            (function () {
                'use strict';

                const CFG = <?= json_encode($jsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                const ALL_PROVINCE_CITIES = <?= json_encode($allProvinceCities, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                const SERVER_JOB = <?= json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

                const $ = function (id) { return document.getElementById(id); };

                const form = $('collectForm');
                const provinceSelect = $('provinceSelect');
                const citySelect = $('citySelect');
                const startBtn = $('startBtn');
                const stopBtn = $('stopBtn');
                const continueBtn = $('continueBtn');
                const resumeBtn = $('resumeBtn');
                const restartBtn = $('restartBtn');
                const resumeBox = $('resumeBox');
                const panel = $('progressPanel');
                const logBox = $('logBox');
                const statusPill = $('statusPill');
                const spinner = $('spinner');
                const barFill = $('barFill');
                const errorBox = $('serverError');
                const lastMessage = $('lastMessage');

                const state = {
                    job: null,
                    running: false,
                    stopRequested: false,
                    failures: 0,
                    startedAt: 0,
                    timer: null,
                    dbTotal: null
                };

                /* ------------------------------------------------------ *
                 * Province -> city dropdown (same behaviour as search UI)
                 * ------------------------------------------------------ */
                function updateCities(provinceName) {
                    if (!provinceSelect || !citySelect) return;

                    const cities = ALL_PROVINCE_CITIES[provinceName] || {};

                    citySelect.innerHTML = '<option value="">-- شهر را انتخاب کنید --</option>';

                    Object.keys(cities).forEach(function (slug) {
                        const option = document.createElement('option');
                        option.value = slug;
                        option.textContent = cities[slug];
                        citySelect.appendChild(option);
                    });
                }

                if (provinceSelect) {
                    provinceSelect.addEventListener('change', function () {
                        updateCities(this.value);
                        hideResumeBox();
                    });
                }

                if (citySelect) {
                    citySelect.addEventListener('change', hideResumeBox);
                }

                if (provinceSelect && provinceSelect.value) {
                    const previousCity = citySelect ? citySelect.value : '';
                    updateCities(provinceSelect.value);
                    if (citySelect && previousCity) {
                        citySelect.value = previousCity;
                    }
                }

                function hideResumeBox() {
                    if (resumeBox) resumeBox.hidden = true;
                }

                /* ------------------------- *
                 * Small helpers
                 * ------------------------- */
                function sleep(ms) {
                    return new Promise(function (resolve) { setTimeout(resolve, ms); });
                }

                function noop(e) {
                    log('خطای غیرمنتظره: ' + (e && e.message ? e.message : e), 'err');
                    finishLoop();
                }

                function faNumber(value) {
                    const number = Number(value || 0);
                    return number.toLocaleString('fa-IR');
                }

                function clock(seconds) {
                    const total = Math.max(0, Math.floor(seconds));
                    const mm = String(Math.floor(total / 60)).padStart(2, '0');
                    const ss = String(total % 60).padStart(2, '0');
                    return mm + ':' + ss;
                }

                function now() {
                    const d = new Date();
                    const p = function (n) { return String(n).padStart(2, '0'); };
                    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
                }

                function log(message, kind) {
                    if (!logBox) return;

                    const line = document.createElement('div');
                    const time = document.createElement('span');
                    time.className = 't';
                    time.textContent = '[' + now() + ']';

                    const text = document.createElement('span');
                    text.className = kind || 'dim';
                    text.textContent = message;

                    line.appendChild(time);
                    line.appendChild(text);
                    logBox.appendChild(line);

                    while (logBox.childNodes.length > 300) {
                        logBox.removeChild(logBox.firstChild);
                    }

                    logBox.scrollTop = logBox.scrollHeight;
                }

                function showError(message) {
                    if (!errorBox) return;
                    errorBox.textContent = message;
                    errorBox.hidden = false;
                }

                function hideError() {
                    if (!errorBox) return;
                    errorBox.textContent = '';
                    errorBox.hidden = true;
                }

                function setStatus(status, text) {
                    if (!statusPill) return;
                    statusPill.className = 'status-pill ' + (status || '');
                    statusPill.textContent = text;
                }

                function setBusy(busy) {
                    if (startBtn) startBtn.disabled = busy;
                    if (stopBtn) stopBtn.disabled = !busy || state.stopRequested;
                    if (continueBtn) continueBtn.disabled = busy || !state.job;
                }

                function formValues() {
                    return {
                        province: provinceSelect ? provinceSelect.value : '',
                        city: citySelect ? citySelect.value : '',
                        place: $('placeSelect') ? $('placeSelect').value : '',
                        query: $('queryInput') ? $('queryInput').value.trim() : '',
                        max_steps: $('maxStepsInput') ? $('maxStepsInput').value : '',
                        step_delay_ms: $('stepDelayInput') ? $('stepDelayInput').value : ''
                    };
                }

                async function api(payload) {
                    const body = new URLSearchParams();

                    Object.keys(payload).forEach(function (key) {
                        const value = payload[key];
                        body.append(key, (value === null || value === undefined) ? '' : String(value));
                    });

                    let response;

                    try {
                        response = await fetch(CFG.endpoint, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: body.toString()
                        });
                    } catch (e) {
                        throw new Error('ارتباط با سرور برقرار نشد (' + (e && e.message ? e.message : e) + ')');
                    }

                    const text = await response.text();

                    let data;

                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('پاسخ سرور قابل خواندن نیست (HTTP ' + response.status + '): ' + text.slice(0, 140));
                    }

                    return data;
                }

                /* ------------------------- *
                 * Rendering
                 * ------------------------- */
                function renderJob(job, batch) {
                    if (!job) return;

                    state.job = job;

                    if ($('cPages')) $('cPages').textContent = faNumber(job.steps);
                    if ($('cFetched')) $('cFetched').textContent = faNumber(job.fetched);
                    if ($('cInserted')) $('cInserted').textContent = faNumber(job.inserted);
                    if ($('cUpdated')) $('cUpdated').textContent = faNumber(job.updated);
                    if ($('cUnchanged')) $('cUnchanged').textContent = faNumber(job.unchanged);
                    if ($('cErrors')) $('cErrors').textContent = faNumber(job.errors);

                    const elapsed = ((job.finished_at || Math.floor(Date.now() / 1000)) - (job.started_at || 0));

                    if ($('cElapsed')) $('cElapsed').textContent = clock(elapsed);

                    if ($('cRate')) {
                        const minutes = elapsed > 0 ? elapsed / 60 : 0;
                        $('cRate').textContent = minutes > 0
                            ? faNumber(Math.round(Number(job.fetched || 0) / minutes))
                            : faNumber(0);
                    }

                    const labels = {
                        running: 'در حال جمع‌آوری',
                        paused: 'متوقف‌شده',
                        error: 'خطا',
                        done: 'پایان یافت'
                    };

                    setStatus(job.status, labels[job.status] || job.status || '');

                    if (barFill) {
                        barFill.className = 'bar-fill' + (job.status === 'running' ? '' : (job.status === 'done' ? ' done' : ' idle'));
                    }

                    if (spinner) spinner.hidden = job.status !== 'running';

                    if (lastMessage && batch) {
                        lastMessage.textContent = 'آخرین صفحه: ' + batch.fetched + ' آگهی در ' + batch.elapsed_ms + 'ms';
                    }

                    if (job.status === 'done' || job.status === 'paused') {
                        hideResumeBox();
                    }
                }

                function logBatch(batch, titles) {
                    if (!batch) return;

                    log(
                        'صفحهٔ ' + batch.page + ': ' + batch.fetched + ' آگهی — جدید ' + batch.inserted +
                        '، به‌روز ' + batch.updated + '، تکراری ' + batch.unchanged +
                        (batch.failed > 0 ? '، ناموفق ' + batch.failed : ''),
                        batch.fetched > 0 ? 'ok' : 'info'
                    );

                    if (titles && titles.length) {
                        log('نمونه: ' + titles.join(' | '), 'dim');
                    }

                    if (batch.failed_ids && batch.failed_ids.length) {
                        log('شناسه‌های ناموفق: ' + batch.failed_ids.slice(0, 5).join(', '), 'err');
                    }
                }

                function setDbTotal(total) {
                    state.dbTotal = total;
                }

                /* ------------------------- *
                 * The harvest loop
                 * ------------------------- */
                async function startHarvest(resume) {
                    const values = formValues();

                    if (!values.province) { showError('لطفاً استان را انتخاب کنید.'); return; }
                    if (!values.city) { showError('لطفاً شهر را انتخاب کنید.'); return; }

                    hideError();
                    hideResumeBox();

                    if (panel) panel.hidden = false;
                    if (logBox) logBox.innerHTML = '';

                    state.stopRequested = false;
                    state.failures = 0;
                    state.startedAt = Date.now();
                    state.running = true;

                    setBusy(true);
                    setStatus('running', 'در حال شروع...');
                    if (spinner) spinner.hidden = false;

                    log(resume ? 'درخواست ادامه‌ی جمع‌آوری قبلی...' : 'درخواست شروع جمع‌آوری...', 'info');

                    let data;

                    try {
                        data = await api(Object.assign({
                            action: 'collect_start',
                            resume: resume ? '1' : '0'
                        }, values));
                    } catch (e) {
                        state.running = false;
                        setBusy(false);
                        setStatus('error', 'خطا');
                        if (spinner) spinner.hidden = true;
                        if (barFill) barFill.className = 'bar-fill idle';
                        showError(e.message);
                        log(e.message, 'err');
                        return;
                    }

                    if (!data || !data.success) {
                        state.running = false;
                        setBusy(false);
                        setStatus('error', 'خطا');
                        if (spinner) spinner.hidden = true;
                        if (barFill) barFill.className = 'bar-fill idle';
                        const message = (data && data.error) ? data.error : 'شروع جمع‌آوری ناموفق بود.';
                        showError(message);
                        log(message, 'err');
                        return;
                    }

                    if (typeof data.stored_rows === 'number') {
                        log('رکوردهای ذخیره‌شده‌ی قبلی برای این شهر/دسته: ' + data.stored_rows, 'info');
                    }

                    log(data.message || (data.resumed ? 'ادامه‌ی جمع‌آوری' : 'جمع‌آوری شروع شد'), 'ok');

                    renderJob(data.job);

                    await runLoop();
                }

                async function runLoop() {
                    while (state.running && !state.stopRequested && state.job) {
                        let data;

                        try {
                            data = await api({ action: 'collect_step', job_id: state.job.id });
                            state.failures = 0;
                        } catch (e) {
                            state.failures++;
                            log('خطای ارتباطی (' + state.failures + '): ' + e.message, 'err');

                            if (state.failures > CFG.max_retries) {
                                log('تعداد تلاش‌های پیاپی از حد گذشت؛ جمع‌آوری متوقف شد. با «ادامه» از همان‌جا ادامه دهید.', 'err');
                                state.stopRequested = true;
                                break;
                            }

                            await sleep(CFG.retry_base_ms * state.failures);
                            continue;
                        }

                        if (!data || !data.success) {
                            const message = (data && data.error) ? data.error : 'پاسخ نامعتبر از سرور.';

                            if (data && (data.fatal || data.restart)) {
                                log(message, 'err');
                                showError(message);
                                state.stopRequested = true;
                                break;
                            }

                            state.failures++;
                            log('خطا (' + state.failures + '): ' + message, 'err');

                            if (!data || data.retry === false || state.failures > CFG.max_retries) {
                                state.stopRequested = true;
                                break;
                            }

                            await sleep(CFG.retry_base_ms * state.failures);
                            continue;
                        }

                        if (data.job) {
                            renderJob(data.job, data.batch);
                        }

                        logBatch(data.batch, data.titles);

                        if (data.done) {
                            if (typeof data.db_total === 'number') setDbTotal(data.db_total);
                            log('پایان: ' + (data.message || 'جمع‌آوری کامل شد'), 'ok');
                            if (state.dbTotal !== null) {
                                log('مجموع رکوردهای دیوار در دیتابیس: ' + state.dbTotal, 'info');
                            }
                            state.stopRequested = true;
                            break;
                        }

                        const delay = (state.job && typeof state.job.step_delay_ms === 'number')
                            ? state.job.step_delay_ms
                            : CFG.step_delay_ms;

                        if (delay > 0) {
                            await sleep(delay);
                        }
                    }

                    /*
                     * Whatever ended the loop (user, error, limit): when the job
                     * is not finished, the server must be told so the session
                     * state becomes "paused" and can be resumed later.
                     */
                    if (state.stopRequested && state.job && state.job.status !== 'done') {
                        await pauseOnServer();
                    }

                    finishLoop();
                }

                async function pauseOnServer() {
                    if (!state.job || !state.job.id) return;

                    try {
                        const data = await api({ action: 'collect_stop', job_id: state.job.id });
                        if (data && data.job) renderJob(data.job);
                        if (data && data.message) log(data.message, 'info');
                    } catch (e) {
                        log('ثبت توقف روی سرور ناموفق بود: ' + e.message, 'err');
                    }
                }

                function finishLoop() {
                    state.running = false;

                    setBusy(false);

                    const status = state.job ? state.job.status : 'paused';

                    if (status === 'done') {
                        setStatus('done', 'پایان یافت');
                        if (spinner) spinner.hidden = true;
                        if (barFill) barFill.className = 'bar-fill done';
                        if (continueBtn) continueBtn.disabled = true;
                    } else {
                        setStatus(status === 'error' ? 'error' : 'paused', status === 'error' ? 'خطا' : 'متوقف‌شده');
                        if (spinner) spinner.hidden = true;
                        if (barFill) barFill.className = 'bar-fill idle';
                        if (continueBtn) continueBtn.disabled = false;
                    }

                    if (stopBtn) stopBtn.disabled = true;
                    if (startBtn) startBtn.disabled = false;
                }

                /* ------------------------- *
                 * Events
                 * ------------------------- */
                if (form) {
                    form.addEventListener('submit', function (event) {
                        event.preventDefault();

                        if (state.running) return;

                        startHarvest(false).catch(function (e) {
                            log('خطای غیرمنتظره: ' + (e && e.message ? e.message : e), 'err');
                            finishLoop();
                        });
                    });
                }

                if (stopBtn) {
                    stopBtn.addEventListener('click', async function () {
                        if (!state.running) return;

                        state.stopRequested = true;
                        stopBtn.disabled = true;
                        log('درخواست توقف توسط کاربر...', 'info');
                        setStatus('paused', 'در حال توقف...');
                    });
                }

                if (continueBtn) {
                    continueBtn.addEventListener('click', function () {
                        if (state.running) return;

                        if (state.job && state.job.id) {
                            state.stopRequested = false;
                            state.failures = 0;
                            state.running = true;
                            setBusy(true);
                            setStatus('running', 'در حال جمع‌آوری');
                            if (spinner) spinner.hidden = false;
                            if (barFill) barFill.className = 'bar-fill';
                            log('ادامه‌ی جمع‌آوری از صفحه‌ی ' + (Number(state.job.steps || 0) + 1), 'info');
                            runLoop().catch(function (e) {
                                log('خطای غیرمنتظره: ' + (e && e.message ? e.message : e), 'err');
                                finishLoop();
                            });
                            return;
                        }

                        startHarvest(true);
                    });
                }

                if (resumeBtn) {
                    resumeBtn.addEventListener('click', function () {
                        if (state.running) return;
                        startHarvest(true).catch(noop);
                    });
                }

                if (restartBtn) {
                    restartBtn.addEventListener('click', function () {
                        if (state.running) return;
                        startHarvest(false).catch(noop);
                    });
                }

                window.addEventListener('beforeunload', function (event) {
                    if (!state.running) return;

                    event.preventDefault();
                    event.returnValue = '';
                    return '';
                });

                /* ------------------------- *
                 * Boot
                 * ------------------------- */
                if (SERVER_JOB && panel) {
                    panel.hidden = false;
                    renderJob(SERVER_JOB);
                    log('اجرای نیمه‌تمام قبلی بارگذاری شد (صفحه‌های انجام‌شده: ' + (SERVER_JOB.steps || 0) + ').', 'info');
                    if (continueBtn) continueBtn.disabled = false;
                }

                if (CFG.auto_start && !state.running) {
                    log('شروع خودکار جمع‌آوری...', 'info');
                    startHarvest(CFG.has_resumable_job).catch(noop);
                }
            })();
        </script>
        </body>
        </html>
        <?php
    }
}
