<?php

namespace Src\View;

use Src\Support\ProviderAccommodationMapper;

final class SearchView
{
    private array $provinces;
    private array $categories;
    private array $citySlugs;
    private ?array $results;
    private ?string $error;
    private string $selectedCity;
    private string $selectedCategory;
    private string $provider;
    private string $selectedProvince = '';
    private string $selectedQuery = '';
    private array $provinceCities = [];
    private array $allProvinceCities = [];
    private array $existingCallLogs = [];
    private int $currentPage = 1;
    private int $totalPages = 1;
    private int $totalResults = 0;
    private ?string $saveResultSetKey = null;
    private array $savedPlaceIds = [];

    public function __construct(
            array $provinces,
            array $categories,
            array $citySlugs,
            ?array $results,
            ?string $error,
            string $selectedCity,
            string $selectedCategory,
            string $provider = 'balad',
            string $selectedProvince = '',
            array $provinceCities = [],
            array $allProvinceCities = [],
            string $selectedQuery = '',
            int $currentPage = 1,
            int $totalPages = 1,
            int $totalResults = 0,
            ?string $saveResultSetKey = null,
            array $savedPlaceIds = []
    ) {
        $this->provinces = $provinces;
        $this->categories = $categories;
        $this->citySlugs = $citySlugs;
        $this->results = $results;
        $this->error = $error;
        $this->selectedCity = $selectedCity;
        $this->selectedCategory = $selectedCategory;
        $this->provider = $provider;
        $this->selectedProvince = $selectedProvince;
        $this->selectedQuery = $selectedQuery;
        $this->provinceCities = $provinceCities;
        $this->allProvinceCities = $allProvinceCities;
        $this->currentPage = max(1, $currentPage);
        $this->totalPages = max(1, $totalPages);
        $this->totalResults = $totalResults;
        $this->saveResultSetKey = $saveResultSetKey;
        $this->savedPlaceIds = $savedPlaceIds;
    }

    public function setExistingCallLogs(array $logs): void
    {
        $this->existingCallLogs = $logs;
    }

    private function hasExistingCallLog(array $place): bool
    {
        $placeId = $place['id'] ?? $place['token'] ?? $place['place_id'] ?? null;
        $phone = $place['telephone'] ?? $place['phone'] ?? null;
        if (!$placeId || !$phone) return false;
        return isset($this->existingCallLogs[$placeId][$phone]);
    }

    private function getExistingCallLogStatus(array $place): ?string
    {
        $placeId = $place['id'] ?? $place['token'] ?? $place['place_id'] ?? null;
        $phone = $place['telephone'] ?? $place['phone'] ?? null;
        if (!$placeId || !$phone) return null;
        return $this->existingCallLogs[$placeId][$phone] ?? null;
    }

    public function render(): void
    {
        if ($this->provider === 'neshan') {
            $providerLabel = 'نشان';
        } elseif ($this->provider === 'google_map') {
            $providerLabel = 'گوگل';
        } elseif ($this->provider === 'divar') {
            $providerLabel = 'دیوار';
        } else {
            $providerLabel = 'بلد';
        }
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>جستجوی مکان - <?= htmlspecialchars($providerLabel) ?></title>
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
                .container { max-width: 900px; margin: 0 auto; padding: 24px 16px; }

                /* Form Section */
                .form-box {
                    background: #fff;
                    padding: 28px;
                    border-radius: 16px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.08);
                    margin-bottom: 24px;
                    border: 1px solid #eef0f2;
                }
                .header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 24px;
                    flex-wrap: wrap;
                    gap: 16px;
                    padding-bottom: 16px;
                    border-bottom: 1px solid #f0f0f0;
                }
                .form-title {
                    font-size: 22px;
                    font-weight: 700;
                    margin: 0;
                    color: #1a1a2e;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .form-title::before {
                    content: '🔍';
                    font-size: 24px;
                }

                .form-row { display: flex; gap: 16px; flex-wrap: wrap; }
                .form-group { flex: 1; min-width: 200px; }
                .form-group label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 600;
                    color: #444;
                    font-size: 14px;
                }
                .form-group select {
                    width: 100%;
                    padding: 12px 16px;
                    border: 2px solid #e8e8e8;
                    border-radius: 10px;
                    font-size: 15px;
                    background: #fafafa;
                    transition: all 0.2s ease;
                    cursor: pointer;
                }
                .form-group select:focus {
                    outline: none;
                    border-color: #4a90d9;
                    background: #fff;
                    box-shadow: 0 0 0 3px rgba(74, 144, 217, 0.15);
                }
                .form-group select:hover { border-color: #d0d0d0; }

                .submit-btn {
                    background: linear-gradient(135deg, #4a90d9 0%, #357abd 100%);
                    color: #fff;
                    padding: 14px 32px;
                    border: none;
                    border-radius: 10px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    box-shadow: 0 2px 6px rgba(74, 144, 217, 0.3);
                    width: 100%;
                    margin-top: 8px;
                }
                .submit-btn:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(74, 144, 217, 0.4);
                }
                .submit-btn:active { transform: translateY(0); }

                /* Results List */
                .results-list {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                }
                .result-item {
                    background: #fff;
                    border-radius: 14px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06), 0 1px 3px rgba(0,0,0,0.05);
                    border: 1px solid #eef0f2;
                    overflow: hidden;
                    transition: all 0.25s ease;
                }
                .result-item:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 24px rgba(0,0,0,0.08), 0 2px 6px rgba(0,0,0,0.06);
                    border-color: #e0e4e8;
                }
                .result-item.has-image { display: grid; grid-template-columns: 140px 1fr; }

                .result-image {
                    width: 100%;
                    height: 100%;
                    min-height: 140px;
                    object-fit: cover;
                    background: linear-gradient(135deg, #f0f2f5 0%, #e8ebef 100%);
                }

                .result-content {
                    padding: 18px 20px;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    gap: 10px;
                }

                .result-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    gap: 12px;
                    flex-wrap: wrap;
                }

                .result-name {
                    font-size: 17px;
                    font-weight: 700;
                    color: #1a1a2e;
                    margin: 0;
                    line-height: 1.4;
                }

                .provider-tag {
                    font-size: 11px;
                    font-weight: 700;
                    padding: 4px 10px;
                    border-radius: 20px;
                    white-space: nowrap;
                    flex-shrink: 0;
                    margin-top: 2px;
                }
                .provider-balad { background: #e8f0fe; color: #1a73e8; }
                .provider-neshan { background: #f3e8ff; color: #9c27b0; }
                .provider-google_map { background: #e6f4ea; color: #1e7e34; }
                .provider-divar { background: #fff4e5; color: #e67e22; }

                .result-meta {
                    display: flex;
                    flex-direction: column;
                    gap: 6px;
                }

                .meta-row {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    font-size: 14px;
                    color: #555;
                }
                .meta-row i {
                    width: 20px;
                    text-align: center;
                    color: #888;
                    font-size: 15px;
                }
                .meta-row .label {
                    font-weight: 500;
                    color: #666;
                    min-width: 60px;
                }
                .meta-row .value {
                    color: #333;
                    word-break: break-word;
                }
                .meta-row .phone-value { color: #27ae60; font-weight: 600; }
                .meta-row a.value { color: #2980b9; text-decoration: none; }
                .meta-row a.value:hover { text-decoration: underline; }

                .result-actions {
                    display: flex;
                    gap: 10px;
                    margin-top: 4px;
                    padding-top: 12px;
                    border-top: 1px solid #f0f0f0;
                    flex-wrap: wrap;
                }

                .btn-link {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 8px 14px;
                    font-size: 13px;
                    font-weight: 500;
                    border-radius: 8px;
                    text-decoration: none;
                    transition: all 0.2s ease;
                    border: 1px solid transparent;
                }
                .btn-link-primary {
                    background: #e8f0fe;
                    color: #1a73e8;
                    border-color: #d2e3fc;
                }
                .btn-link-primary:hover { background: #d2e3fc; }
                .btn-link-secondary {
                    background: #f5f5f5;
                    color: #555;
                    border-color: #e8e8e8;
                }
                .btn-link-secondary:hover { background: #eee; }

                /* Call Button */
                .call-action { width: 100%; }
                .call-btn {
                    width: 100%;
                    background: linear-gradient(135deg, #27ae60 0%, #219a52 100%);
                    color: #fff;
                    border: none;
                    padding: 14px 24px;
                    border-radius: 10px;
                    font-size: 15px;
                    font-weight: 600;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    transition: all 0.2s ease;
                    box-shadow: 0 2px 6px rgba(39, 174, 96, 0.3);
                }
                .call-btn:hover:not(:disabled) {
                    transform: translateY(-1px);
                    box-shadow: 0 4px 12px rgba(39, 174, 96, 0.4);
                }
                .call-btn:active:not(:disabled) { transform: translateY(0); }
                .call-btn:disabled {
                    background: #bdc3c7;
                    cursor: not-allowed;
                    box-shadow: none;
                    transform: none;
                }
                .call-btn .spinner {
                    display: none;
                    width: 18px;
                    height: 18px;
                    border: 2px solid #fff;
                    border-top-color: transparent;
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                }
                .call-btn.loading .spinner { display: block; }
                .call-btn.loading .btn-text { opacity: 0.7; }
                .call-btn.call-btn-saved {
                    background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
                    box-shadow: none;
                }
                .call-btn.call-btn-saved .btn-text { opacity: 1; }

                /* Saved accommodation state, shared by all providers */
                .result-item.is-saved {
                    background: #eaf7ee;
                    border-color: #27ae60;
                    box-shadow: 0 3px 12px rgba(39, 174, 96, 0.16);
                }
                .result-item.is-saved .result-content { background: #eaf7ee; }
                .save-place-action { width: 100%; }
                .save-place-btn {
                    width: 100%;
                    background: linear-gradient(135deg, #27ae60 0%, #219a52 100%);
                    color: #fff;
                    border: none;
                    padding: 12px 20px;
                    border-radius: 10px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    transition: all 0.2s ease;
                }
                .save-place-btn:hover:not(:disabled) { transform: translateY(-1px); }
                .save-place-btn:disabled {
                    cursor: not-allowed;
                    opacity: 0.78;
                }
                .save-place-btn.is-saved {
                    background: linear-gradient(135deg, #238b4c 0%, #1d743f 100%);
                    opacity: 1;
                }
                .save-place-btn .spinner {
                    display: none;
                    width: 16px;
                    height: 16px;
                    border: 2px solid #fff;
                    border-top-color: transparent;
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                }
                .save-place-btn.loading .spinner { display: block; }

                @keyframes spin { to { transform: rotate(360deg); } }

                /* Divar phone reveal button */
                .divar-phone-action { width: 100%; }
                .divar-phone-btn {
                    width: 100%;
                    background: linear-gradient(135deg, #4a90d9 0%, #357abd 100%);
                    color: #fff;
                    border: none;
                    padding: 12px 20px;
                    border-radius: 10px;
                    font-size: 14px;
                    font-weight: 600;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    transition: all 0.2s ease;
                }
                .divar-phone-btn:hover:not(:disabled) { transform: translateY(-1px); }
                .divar-phone-btn:disabled { background: #bdc3c7; cursor: not-allowed; }
                .divar-phone-btn.loading .spinner { display: block; }
                .divar-phone-btn .spinner {
                    display: none;
                    width: 16px; height: 16px;
                    border: 2px solid #fff;
                    border-top-color: transparent;
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                }
                .divar-phone-result {
                    display: flex; align-items: center; gap: 8px;
                    font-size: 15px; font-weight: 700; color: #27ae60;
                    padding: 10px 0;
                }

                /* Divar OTP login modal */
                .divar-modal-overlay {
                    display: none;
                    position: fixed; inset: 0;
                    background: rgba(0,0,0,0.5);
                    z-index: 10000;
                    align-items: center; justify-content: center;
                }
                .divar-modal-overlay.open { display: flex; }
                .divar-modal {
                    background: #fff;
                    border-radius: 16px;
                    padding: 28px;
                    width: 90%;
                    max-width: 380px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
                }
                .divar-modal h3 { margin-bottom: 16px; font-size: 18px; color: #1a1a2e; }
                .divar-modal p { font-size: 13px; color: #777; margin-bottom: 16px; }
                .divar-modal input {
                    width: 100%;
                    padding: 12px 16px;
                    border: 2px solid #e8e8e8;
                    border-radius: 10px;
                    font-size: 16px;
                    margin-bottom: 12px;
                    text-align: center;
                    letter-spacing: 1px;
                }
                .divar-modal input:focus { outline: none; border-color: #4a90d9; }
                .divar-modal-actions { display: flex; gap: 10px; margin-top: 8px; }
                .divar-modal-actions button {
                    flex: 1;
                    padding: 12px;
                    border-radius: 10px;
                    border: none;
                    font-weight: 600;
                    cursor: pointer;
                    font-size: 14px;
                }
                .divar-modal-submit { background: #4a90d9; color: #fff; }
                .divar-modal-submit:disabled { background: #bdc3c7; cursor: not-allowed; }
                .divar-modal-cancel { background: #f5f5f5; color: #555; }
                .divar-modal-error {
                    color: #c53030; font-size: 13px; margin-bottom: 10px; display: none;
                }
                .divar-modal-error.show { display: block; }

                /* Status badge inline */
                .status-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 6px 12px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    white-space: nowrap;
                }
                .status-pending { background: #fff3cd; color: #856404; }
                .status-completed { background: #d4edda; color: #155724; }
                .status-cancelled { background: #f8d7da; color: #721c24; }

                /* Toast notifications */
                .toast-container {
                    position: fixed;
                    top: 24px;
                    left: 24px;
                    z-index: 9999;
                }
                .toast {
                    background: #fff;
                    padding: 16px 22px;
                    border-radius: 10px;
                    box-shadow: 0 8px 24px rgba(0,0,0,0.12), 0 2px 6px rgba(0,0,0,0.08);
                    margin-bottom: 10px;
                    min-width: 300px;
                    max-width: 400px;
                    animation: slideIn 0.35s cubic-bezier(0.4, 0, 0.2, 1);
                    border-left: 4px solid #27ae60;
                    font-size: 14px;
                }
                .toast.error { border-left-color: #e74c3c; }
                .toast.warning { border-left-color: #f39c12; }
                @keyframes slideIn {
                    from { transform: translateX(-100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }

                /* Messages */
                .error {
                    background: #fef2f2;
                    color: #c53030;
                    padding: 16px 20px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    border: 1px solid #fecaca;
                    font-size: 14px;
                }
                .info {
                    background: #eff6ff;
                    color: #1e40af;
                    padding: 16px 20px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    border: 1px solid #bfdbfe;
                    font-size: 14px;
                }
                .info strong { color: #1e3a8a; }

                /* Empty state */
                .empty-state {
                    text-align: center;
                    padding: 60px 20px;
                    color: #888;
                }
                .empty-state-icon {
                    font-size: 48px;
                    margin-bottom: 16px;
                    opacity: 0.5;
                }
                .empty-state-title {
                    font-size: 18px;
                    font-weight: 600;
                    color: #555;
                    margin-bottom: 8px;
                }
                .empty-state-desc {
                    font-size: 14px;
                    color: #888;
                }

                /* Responsive */
                @media (max-width: 600px) {
                    .container { padding: 16px 12px; }
                    .form-box { padding: 20px; border-radius: 12px; }
                    .form-row { flex-direction: column; gap: 0; }
                    .form-group { min-width: 100%; }
                    .result-item.has-image { grid-template-columns: 1fr; }
                    .result-image { min-height: 180px; }
                    .result-content { padding: 16px; }
                    .result-header { flex-direction: column; align-items: flex-start; }
                    .toast-container { left: 12px; right: 12px; }
                    .toast { min-width: auto; max-width: none; }
                }

                @media (max-width: 480px) {
                    .meta-row { flex-wrap: wrap; }
                    .meta-row .label { min-width: auto; width: max-content; }
                }
            </style>
        </head>
        <body>
        <div class="container">
            <div class="form-box">
                <div class="header">
                    <div class="form-title">
                        جستجوی مکان
                        <span class="provider-badge provider-<?= $this->provider ?>">
                                <?= htmlspecialchars($providerLabel) ?>
                            </span>
                    </div>
                </div>
                <form method="POST" id="searchForm">
                    <input type="hidden" name="provider" value="<?= htmlspecialchars($this->provider) ?>">
                    <input type="hidden" name="page" id="pageInput" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label>منبع جستجو</label>
                            <select name="provider" id="providerSelect">
                                <option value="balad" <?= ($this->provider === 'balad') ? 'selected' : '' ?>>بلد (Balad)</option>
                                <option value="neshan" <?= ($this->provider === 'neshan') ? 'selected' : '' ?>>نشان (Neshan)</option>
                                <option value="google_map" <?= ($this->provider === 'google_map') ? 'selected' : '' ?>>گوگلMap</option>
                                <option value="divar" <?= ($this->provider === 'divar') ? 'selected' : '' ?>>دیوار (Divar)</option>
                            </select>
                        </div>
                    </div>

                    <?php if ($this->provider === 'divar'): ?>
                        <!-- Divar uses province -> city selection -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>استان</label>
                                <select name="province" id="provinceSelect" required>
                                    <option value="">-- انتخاب استان --</option>
                                    <?php foreach ($this->provinces as $prov): ?>
                                        <option value="<?= htmlspecialchars($prov['name']) ?>"
                                                <?= ($this->selectedProvince === $prov['name']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prov['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>شهر</label>
                                <select name="city" id="citySelect" required>
                                    <option value="">-- ابتدا استان را انتخاب کنید --</option>
                                    <?php foreach ($this->provinceCities as $slug => $name): ?>
                                        <option value="<?= htmlspecialchars($slug) ?>"
                                                <?= ($this->selectedCity === $slug) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($name) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Other providers use single city selection -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>شهر / استان</label>
                                <select name="city" required>
                                    <option value="">-- انتخاب --</option>
                                    <?php foreach ($this->provinces as $prov): ?>
                                        <option value="<?= htmlspecialchars($prov['name']) ?>"
                                                <?= ($this->selectedCity === $prov['name']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prov['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label>نوع مکان / دسته‌بندی</label>
                            <select name="place">
                                <?php foreach ($this->categories as $cat): ?>
                                    <option value="<?= $cat['value'] ?? $cat ?>"
                                            <?= ($this->selectedCategory === ($cat['value'] ?? $cat)) ? 'selected' : '' ?>>
                                        <?= $cat['label'] ?? $cat ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if ($this->provider === 'divar'): ?>
                        <!-- Divar search query input -->
                        <div class="form-row">
                            <div class="form-group">
                                <label>جستجوی کلمه کلیدی (اختیاری)</label>
                                <input type="text" name="query" placeholder="مثال: بوم گردی، ویلا، آپارتمان..."
                                       value="<?= htmlspecialchars($this->selectedQuery) ?>"
                                       style="width: 100%; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px;">
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="submit-btn">جستجو</button>
                </form>
            </div>

            <?php if ($this->error): ?>
                <div class="error">خطا: <?= htmlspecialchars($this->error) ?></div>
            <?php endif; ?>

            <?php if ($this->results): ?>
                <?php
                $categoryLabel = '';
                foreach ($this->categories as $cat) {
                    $catValue = $cat['value'] ?? $cat;
                    if ($catValue === $this->selectedCategory) {
                        $categoryLabel = $cat['label'] ?? $cat;
                        break;
                    }
                }
                $hasPlaces = !empty($this->results['places']);
                ?>
                <?php if (!$hasPlaces): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🔍</div>
                        <div class="empty-state-title">نتیجه‌ای یافت نشد</div>
                        <div class="empty-state-desc">
                            هیچ <?= htmlspecialchars($categoryLabel ?: $this->selectedCategory) ?>
                            ای در <?= htmlspecialchars($this->selectedCity) ?> پیدا نشد.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="info">
                        <strong><?= htmlspecialchars($this->results['title'] ?? '') ?></strong> -
                        یافت شد: <?= $this->results['total'] ?> مورد
                    </div>
                    <div class="results-list">
                        <?php foreach ($this->results['places'] as $place): ?>
                            <?php
                            $hasImage = !empty($place['image_preview']);
                            $phone = $place['telephone'] ?? ($place['phone'] ?? null);
                            $hasPhone = !empty($phone) && $phone !== '---';
                            $placeId = $place['id'] ?? $place['token'] ?? $place['place_id'] ?? '';
                            $savePlaceId = ProviderAccommodationMapper::externalId($this->provider, $place);
                            $isSaved = $savePlaceId !== null && isset($this->savedPlaceIds[$savePlaceId]);
                            $hasCallAction = $this->provider !== 'balad' && $hasPhone;
                            $hasExistingLog = $hasCallAction ? $this->hasExistingCallLog($place) : false;
                            $existingStatus = $hasCallAction ? $this->getExistingCallLogStatus($place) : null;
                            ?>
                            <div class="result-item<?= $hasImage ? ' has-image' : '' ?><?= $isSaved ? ' is-saved' : '' ?>">
                                <?php if ($hasImage): ?>
                                    <img src="<?= htmlspecialchars($place['image_preview']) ?>"
                                         alt="<?= htmlspecialchars($place['name']) ?>"
                                         class="result-image"
                                         loading="lazy">
                                <?php endif; ?>
                                <div class="result-content">
                                    <div class="result-header">
                                        <h3 class="result-name"><?= htmlspecialchars($place['name']) ?></h3>
                                        <span class="provider-tag provider-<?= $this->provider ?>">
                                                <?= $this->provider === 'balad' ? 'بلد' : ($this->provider === 'neshan' ? 'نشان' : ($this->provider === 'google_map' ? 'گوگل' : 'دیوار')) ?>
                                            </span>
                                    </div>

                                    <div class="result-meta">
                                        <?php if (!empty($place['address'])): ?>
                                            <div class="meta-row">
                                                <i>📍</i>
                                                <span class="label">آدرس:</span>
                                                <span class="value"><?= htmlspecialchars($place['address']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($place['description'])): ?>
                                            <div class="meta-row">
                                                <i>📝</i>
                                                <span class="label">توضیحات:</span>
                                                <span class="value"><?= htmlspecialchars($place['description']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($place['price'])): ?>
                                            <div class="meta-row">
                                                <i>💰</i>
                                                <span class="label">قیمت:</span>
                                                <span class="value"><?= htmlspecialchars($place['price']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($hasPhone): ?>
                                            <div class="meta-row">
                                                <i>📞</i>
                                                <span class="label">تلفن:</span>
                                                <span class="value phone-value"><?= htmlspecialchars($phone) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($place['website'])): ?>
                                            <div class="meta-row">
                                                <i>🌐</i>
                                                <span class="label">وب‌سایت:</span>
                                                <span class="value">
                                                        <a href="<?= htmlspecialchars($place['website']) ?>" target="_blank" class="value">
                                                            <?= htmlspecialchars($place['website']) ?>
                                                        </a>
                                                    </span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($place['rating'])): ?>
                                            <div class="meta-row">
                                                <i>⭐</i>
                                                <span class="label">امتیاز:</span>
                                                <span class="value"><?= htmlspecialchars($place['rating']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($place['instagram_id'])): ?>
                                            <div class="meta-row">
                                                <i>📷</i>
                                                <span class="label">اینستاگرام:</span>
                                                <span class="value">
                                                        <a href="https://instagram.com/<?= htmlspecialchars($place['instagram_id']) ?>" target="_blank" class="value">
                                                            @<?= htmlspecialchars($place['instagram_id']) ?>
                                                        </a>
                                                    </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="result-actions">
                                        <?php if ($this->saveResultSetKey !== null && $savePlaceId !== null): ?>
                                            <div class="save-place-action">
                                                <button type="button"
                                                        class="save-place-btn<?= $isSaved ? ' is-saved' : '' ?>"
                                                        data-place-id="<?= htmlspecialchars($savePlaceId, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-result-set-key="<?= htmlspecialchars($this->saveResultSetKey, ENT_QUOTES, 'UTF-8') ?>"
                                                        data-provider="<?= htmlspecialchars($this->provider, ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= $isSaved ? 'disabled aria-disabled="true"' : '' ?>>
                                                    <span class="spinner"></span>
                                                    <span class="btn-text"><?= $isSaved ? '✅ ذخیره شده' : 'ذخیره این اقامتگاه' ?></span>
                                                </button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($this->provider === 'balad' && !empty($place['balad_url'])): ?>
                                            <a href="<?= htmlspecialchars($place['balad_url']) ?>" target="_blank" class="btn-link btn-link-secondary">
                                                مشاهده در بلد ↗
                                            </a>
                                        <?php elseif ($this->provider === 'neshan' && !empty($place['neshan_url'])): ?>
                                            <a href="<?= htmlspecialchars($place['neshan_url']) ?>" target="_blank" class="btn-link btn-link-secondary">
                                                مشاهده در نشان ↗
                                            </a>
                                        <?php elseif ($this->provider === 'google_map' && !empty($place['place_id'])): ?>
                                            <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($place['name'] . ' ' . ($place['address'] ?? '')) ?>" target="_blank" class="btn-link btn-link-secondary">
                                                مشاهده در گوگل ↗
                                            </a>
                                        <?php elseif ($this->provider === 'divar' && !empty($place['divar_url'])): ?>
                                            <a href="<?= htmlspecialchars($place['divar_url']) ?>" target="_blank" class="btn-link btn-link-secondary">
                                                مشاهده در دیوار ↗
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($hasCallAction): ?>
                                            <div class="call-action">
                                                <?php if ($hasExistingLog): ?>
                                                    <button type="button" class="call-btn call-btn-saved" disabled>
                                                            <span class="btn-text">
                                                                <?php
                                                                $statusLabel = $existingStatus === 'completed' ? '✅ تکمیل شده' :
                                                                        ($existingStatus === 'cancelled' ? '❌ لغو شده' : '⏳ در انتظار');
                                                                echo $statusLabel;
                                                                ?>
                                                            </span>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="call-btn"
                                                            data-place-id="<?= htmlspecialchars($placeId) ?>"
                                                            data-phone="<?= htmlspecialchars($phone) ?>"
                                                            data-name="<?= htmlspecialchars($place['name']) ?>"
                                                            data-city="<?= htmlspecialchars($this->selectedCity) ?>"
                                                            data-category="<?= htmlspecialchars($this->selectedCategory) ?>">
                                                        <span class="spinner"></span>
                                                        <span class="btn-text">📞 تماس و ثبت</span>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($this->provider === 'divar' && !$hasPhone && $placeId): ?>
                                            <div class="divar-phone-action">
                                                <button type="button" class="divar-phone-btn"
                                                        data-place-id="<?= htmlspecialchars($placeId) ?>"
                                                        data-name="<?= htmlspecialchars($place['name']) ?>">
                                                    <span class="spinner"></span>
                                                    <span class="btn-text">📞 دریافت شماره تماس</span>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($this->provider === 'divar'): ?>

                        <?php
                        /*
                         * ---------------------------------------------------------
                         * DIVAR CURSOR PAGINATION
                         * ---------------------------------------------------------
                         *
                         * Divar does not expose a normal "total pages" value.
                         *
                         * Instead:
                         *
                         *     page 1 -> next_cursor -> page 2
                         *     page 2 -> next_cursor -> page 3
                         *     page 3 -> next_cursor -> page 4
                         *
                         * The controller stores these cursors in the PHP session.
                         */

                        $divarHasNextPage =
                                (bool)($this->results['divar_has_next_page'] ?? false);

                        $divarCurrentPage =
                                max(
                                        1,
                                        (int)($this->results['divar_current_page'] ?? $this->currentPage)
                                );

                        $divarCanGoPrevious =
                                (bool)($this->results['divar_can_go_previous'] ?? false);

                        $divarKnownLastPage =
                                max(
                                        $divarCurrentPage,
                                        (int)($this->results['divar_known_last_page'] ?? $divarCurrentPage)
                                );
                        ?>

                        <?php if ($divarCurrentPage > 1 || $divarHasNextPage): ?>

                            <div
                                    class="pagination"
                                    style="
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 12px;
                margin-top: 24px;
                padding: 16px 0;
                flex-wrap: wrap;
            "
                            >

                                <!-- Previous -->
                                <form method="POST" style="display: inline;">

                                    <input
                                            type="hidden"
                                            name="provider"
                                            value="<?= htmlspecialchars($this->provider) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="city"
                                            value="<?= htmlspecialchars($this->selectedCity) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="place"
                                            value="<?= htmlspecialchars($this->selectedCategory) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="query"
                                            value="<?= htmlspecialchars($this->selectedQuery) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="page"
                                            value="<?= max(1, $divarCurrentPage - 1) ?>"
                                    >

                                    <button
                                            type="submit"
                                            class="btn-link btn-link-primary"
                                            <?= !$divarCanGoPrevious ? 'disabled' : '' ?>
                                            style="
                                            <?= !$divarCanGoPrevious
                                                    ? 'opacity: 0.5; cursor: not-allowed;'
                                                    : ''
                                            ?>
                                                    "
                                    >
                                        ← صفحه قبلی
                                    </button>

                                </form>


                                <!-- Current page -->
                                <div
                                        class="pagination-info"
                                        style="
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-size: 14px;
                    color: #555;
                "
                                >

                <span>
                    صفحه <?= $divarCurrentPage ?>
                </span>

                                    <?php if ($this->totalResults > 0): ?>

                                        <span style="color: #888;">
                        (<?= $this->totalResults ?> نتیجه در این صفحه)
                    </span>

                                    <?php endif; ?>

                                </div>


                                <!-- Next -->
                                <form method="POST" style="display: inline;">

                                    <input
                                            type="hidden"
                                            name="provider"
                                            value="<?= htmlspecialchars($this->provider) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="city"
                                            value="<?= htmlspecialchars($this->selectedCity) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="place"
                                            value="<?= htmlspecialchars($this->selectedCategory) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="query"
                                            value="<?= htmlspecialchars($this->selectedQuery) ?>"
                                    >

                                    <input
                                            type="hidden"
                                            name="page"
                                            value="<?= $divarCurrentPage + 1 ?>"
                                    >

                                    <button
                                            type="submit"
                                            class="btn-link btn-link-primary"
                                            <?= !$divarHasNextPage ? 'disabled' : '' ?>
                                            style="
                                            <?= !$divarHasNextPage
                                                    ? 'opacity: 0.5; cursor: not-allowed;'
                                                    : ''
                                            ?>
                                                    "
                                    >
                                        صفحه بعدی →
                                    </button>

                                </form>

                            </div>

                        <?php endif; ?>


                    <?php elseif ($this->totalPages > 1): ?>

                        <!--
                            EXISTING BALAD / NEShan / GOOGLE PAGINATION

                            DO NOT CHANGE THIS PART.
                        -->

                        <div
                                class="pagination"
                                style="
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            padding: 16px 0;
            flex-wrap: wrap;
        "
                        >

                            <form method="POST" style="display: inline;">

                                <input
                                        type="hidden"
                                        name="provider"
                                        value="<?= htmlspecialchars($this->provider) ?>"
                                >

                                <input
                                        type="hidden"
                                        name="city"
                                        value="<?= htmlspecialchars($this->selectedCity) ?>"
                                >

                                <input
                                        type="hidden"
                                        name="place"
                                        value="<?= htmlspecialchars($this->selectedCategory) ?>"
                                >

                                <input
                                        type="hidden"
                                        name="page"
                                        value="<?= max(1, $this->currentPage - 1) ?>"
                                >

                                <button
                                        type="submit"
                                        class="btn-link btn-link-primary"
                                        <?= $this->currentPage <= 1 ? 'disabled' : '' ?>
                                        style="
                                        <?= $this->currentPage <= 1
                                                ? 'opacity: 0.5; cursor: not-allowed;'
                                                : ''
                                        ?>
                                                "
                                >
                                    ← صفحه قبلی
                                </button>

                            </form>


                            <div
                                    class="pagination-info"
                                    style="
                display: flex;
                align-items: center;
                gap: 12px;
                font-size: 14px;
                color: #555;
            "
                            >

            <span>
                صفحه <?= $this->currentPage ?>
                از <?= $this->totalPages ?>
            </span>

                                <?php if ($this->totalResults > 0): ?>

                                    <span style="color: #888;">
                    (مجموع <?= $this->totalResults ?> نتیجه)
                </span>

                                <?php endif; ?>


                                <?php
                                $startPage =
                                        max(
                                                1,
                                                $this->currentPage - 2
                                        );

                                $endPage =
                                        min(
                                                $this->totalPages,
                                                $startPage + 4
                                        );

                                if (
                                        $endPage - $startPage < 4
                                ) {
                                    $startPage =
                                            max(
                                                    1,
                                                    $endPage - 4
                                            );
                                }
                                ?>

                                <div style="display: flex; gap: 4px;">

                                    <?php
                                    for (
                                            $p = $startPage;
                                            $p <= $endPage;
                                            $p++
                                    ):
                                        ?>

                                        <form
                                                method="POST"
                                                style="display: inline;"
                                        >

                                            <input
                                                    type="hidden"
                                                    name="provider"
                                                    value="<?= htmlspecialchars($this->provider) ?>"
                                            >

                                            <input
                                                    type="hidden"
                                                    name="city"
                                                    value="<?= htmlspecialchars($this->selectedCity) ?>"
                                            >

                                            <input
                                                    type="hidden"
                                                    name="place"
                                                    value="<?= htmlspecialchars($this->selectedCategory) ?>"
                                            >

                                            <input
                                                    type="hidden"
                                                    name="page"
                                                    value="<?= $p ?>"
                                            >

                                            <button
                                                    type="submit"
                                                    class="btn-link <?= $p === $this->currentPage
                                                            ? 'btn-link-primary'
                                                            : 'btn-link-secondary'
                                                    ?>"
                                                    style="
                                                            padding: 6px 12px;
                                                            min-width: 40px;
                                                    <?= $p === $this->currentPage
                                                            ? 'font-weight: 700;'
                                                            : ''
                                                    ?>
                                                            "
                                            >
                                                <?= $p ?>
                                            </button>

                                        </form>

                                    <?php endfor; ?>

                                </div>

                            </div>


                            <form method="POST" style="display: inline;">

                                <input
                                        type="hidden"
                                        name="provider"
                                        value="<?= htmlspecialchars($this->provider) ?>"
                                >

                                <input
                                        type="hidden"
                                        name="city"
                                        value="<?= htmlspecialchars($this->selectedCity) ?>"
                                >

                                <input
                                        type="hidden"
                                        name="place"
                                        value="<?= htmlspecialchars($this->selectedCategory) ?>"
                                >

                                <input
                                        type="hidden"
                                        name="page"
                                        value="<?= min(
                                                $this->totalPages,
                                                $this->currentPage + 1
                                        ) ?>"
                                >

                                <button
                                        type="submit"
                                        class="btn-link btn-link-primary"
                                        <?= $this->currentPage >= $this->totalPages
                                                ? 'disabled'
                                                : ''
                                        ?>
                                        style="
                                        <?= $this->currentPage >= $this->totalPages
                                                ? 'opacity: 0.5; cursor: not-allowed;'
                                                : ''
                                        ?>
                                                "
                                >
                                    صفحه بعدی →
                                </button>

                            </form>

                        </div>

                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <div class="divar-modal-overlay" id="divarModalOverlay">
                <div class="divar-modal">
                    <div id="divarModalPhoneStep">
                        <h3>ورود به دیوار</h3>
                        <p>برای دریافت شماره تماس، ابتدا با شماره موبایل خودتان وارد شوید.</p>
                        <div class="divar-modal-error" id="divarPhoneError"></div>
                        <input type="tel" id="divarPhoneInput" placeholder="09xxxxxxxxx" maxlength="11">
                        <div class="divar-modal-actions">
                            <button type="button" class="divar-modal-cancel" id="divarCancelPhone">انصراف</button>
                            <button type="button" class="divar-modal-submit" id="divarSendCodeBtn">ارسال کد</button>
                        </div>
                    </div>
                    <div id="divarModalOtpStep" style="display:none;">
                        <h3>کد تأیید</h3>
                        <p id="divarOtpSubtitle">کد ۶ رقمی ارسال شده را وارد کنید.</p>
                        <div class="divar-modal-error" id="divarOtpError"></div>
                        <input type="tel" id="divarOtpInput" placeholder="------" maxlength="6">
                        <div class="divar-modal-actions">
                            <button type="button" class="divar-modal-cancel" id="divarCancelOtp">انصراف</button>
                            <button type="button" class="divar-modal-submit" id="divarVerifyCodeBtn">تأیید</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            // Dynamic city loading for Divar
            document.addEventListener('DOMContentLoaded', function() {
                const providerSelect = document.getElementById('providerSelect');
                const provinceSelect = document.getElementById('provinceSelect');
                const citySelect = document.getElementById('citySelect');
                const form = document.getElementById('searchForm');

                // All provinces with their cities (for dynamic loading)
                const allProvinceCities = <?= json_encode($this->allProvinceCities) ?>;

                // Function to update city dropdown based on selected province
                function updateCities(provinceName) {
                    if (!provinceSelect || !citySelect) return;

                    const cities = allProvinceCities[provinceName] || {};
                    citySelect.innerHTML = '<option value="">-- شهر را انتخاب کنید --</option>';
                    for (const [slug, name] of Object.entries(cities)) {
                        const option = document.createElement('option');
                        option.value = slug;
                        option.textContent = name;
                        citySelect.appendChild(option);
                    }
                }

                // Handle province change
                if (provinceSelect) {
                    provinceSelect.addEventListener('change', function() {
                        updateCities(this.value);
                    });
                }

                // Handle provider change - reload page with new provider
                if (providerSelect) {
                    providerSelect.addEventListener('change', function() {
                        // Reset page to 1 when provider changes
                        document.getElementById('pageInput').value = '1';
                        // Submit the form to reload with new provider
                        form.submit();
                    });
                }

                // Reset page to 1 when city changes
                const citySelectMain = document.querySelector('select[name="city"]');
                if (citySelectMain) {
                    citySelectMain.addEventListener('change', function() {
                        document.getElementById('pageInput').value = '1';
                    });
                }

                // Reset page to 1 when category changes
                const placeSelect = document.querySelector('select[name="place"]');
                if (placeSelect) {
                    placeSelect.addEventListener('change', function() {
                        document.getElementById('pageInput').value = '1';
                    });
                }

                // Initialize cities if province is already selected (on page load for Divar)
                if (provinceSelect && provinceSelect.value) {
                    updateCities(provinceSelect.value);
                }

                function markResultAsSaved(element) {
                    const resultItem = element.closest('.result-item');
                    if (!resultItem) return;

                    resultItem.classList.add('is-saved');
                    const saveButton = resultItem.querySelector('.save-place-btn');
                    if (!saveButton) return;

                    saveButton.classList.remove('loading');
                    saveButton.classList.add('is-saved');
                    saveButton.innerHTML = '<span class="btn-text">✅ ذخیره شده</span>';
                    saveButton.disabled = true;
                    saveButton.setAttribute('aria-disabled', 'true');
                }

                // Save the selected accommodation for any provider.
                const savePlaceButtons = document.querySelectorAll('.save-place-btn:not(:disabled)');
                savePlaceButtons.forEach(btn => {
                    btn.addEventListener('click', function() {
                        const formData = new FormData();
                        formData.append('action', 'save_place');
                        formData.append('provider', this.dataset.provider || '');
                        formData.append('place_id', this.dataset.placeId || '');
                        formData.append('result_set_key', this.dataset.resultSetKey || '');

                        this.classList.add('loading');
                        this.disabled = true;

                        fetch('', {
                            method: 'POST',
                            body: formData
                        })
                            .then(response => response.json().then(data => ({ response, data })))
                            .then(({ response, data }) => {
                                this.classList.remove('loading');

                                if (response.ok && data.success) {
                                    markResultAsSaved(this);
                                    alert(data.contact_saved
                                        ? 'اقامتگاه و شماره تماس آن ذخیره شد.'
                                        : (data.message || 'اقامتگاه ذخیره شد.'));
                                    return;
                                }

                                if (data.already_saved) {
                                    markResultAsSaved(this);
                                    return;
                                }

                                this.disabled = false;
                                alert(data.message || 'خطا در ذخیره اقامتگاه');
                            })
                            .catch(error => {
                                this.classList.remove('loading');
                                this.disabled = false;
                                alert('خطا در ارتباط با سرور هنگام ذخیره اقامتگاه');
                                console.error('Selected-place save error:', error);
                            });
                    });
                });

                // Handle call button clicks
                const callButtons = document.querySelectorAll('.call-btn');
                callButtons.forEach(btn => {
                    btn.addEventListener('click', function() {
                        const placeId = this.dataset.placeId;
                        const phone = this.dataset.phone;
                        const name = this.dataset.name;
                        const city = this.dataset.city;
                        const category = this.dataset.category;

                        if (!placeId) {
                            alert('شناسه مکان یافت نشد');
                            return;
                        }

                        // Auto-generate description from place info
                        const description = `تماس با ${name} (${category}) در ${city} - شماره: ${phone}`;

                        // Disable button and show loading
                        this.classList.add('loading');
                        this.disabled = true;

                        // Send AJAX request
                        const formData = new FormData();
                        formData.append('action', 'call');
                        formData.append('place_id', placeId);
                        formData.append('phone', phone);
                        formData.append('city', city);
                        formData.append('category', category);
                        formData.append('description', description);

                        fetch('', {
                            method: 'POST',
                            body: formData
                        })
                            .then(response => response.json())
                            .then(data => {
                                this.classList.remove('loading');
                                this.disabled = false;

                                if (data.success) {
                                    alert('تماس با موفقیت ثبت شد (وضعیت: در انتظار)');
                                    // Update button to show it's been logged
                                    this.innerHTML = '<span class="btn-text">✅ ثبت شده</span>';
                                    this.style.background = '#27ae60';
                                    this.disabled = true;
                                } else {
                                    alert(data.message || 'خطا در ثبت تماس');
                                }
                            })
                            .catch(error => {
                                this.classList.remove('loading');
                                this.disabled = false;
                                alert('خطا در ارتباط با سرور');
                                console.error('Call log error:', error);
                            });
                    });
                });

                // ----- Divar phone reveal + OTP login -----
                const divarModalOverlay = document.getElementById('divarModalOverlay');
                const divarModalPhoneStep = document.getElementById('divarModalPhoneStep');
                const divarModalOtpStep = document.getElementById('divarModalOtpStep');
                const divarPhoneInput = document.getElementById('divarPhoneInput');
                const divarOtpInput = document.getElementById('divarOtpInput');
                const divarPhoneError = document.getElementById('divarPhoneError');
                const divarOtpError = document.getElementById('divarOtpError');

                let pendingPlaceButton = null; // the .divar-phone-btn element to resume after login

                function openDivarModal() {
                    divarModalOverlay.classList.add('open');
                    divarModalPhoneStep.style.display = 'block';
                    divarModalOtpStep.style.display = 'none';
                    divarPhoneError.classList.remove('show');
                    divarOtpError.classList.remove('show');
                    divarPhoneInput.value = '';
                    divarOtpInput.value = '';
                    setTimeout(() => divarPhoneInput.focus(), 50);
                }

                function closeDivarModal() {
                    divarModalOverlay.classList.remove('open');
                    pendingPlaceButton = null;
                }

                document.getElementById('divarCancelPhone').addEventListener('click', closeDivarModal);
                document.getElementById('divarCancelOtp').addEventListener('click', closeDivarModal);

                document.getElementById('divarSendCodeBtn').addEventListener('click', function() {
                    const phone = divarPhoneInput.value.trim();
                    if (!/^09\d{9}$/.test(phone)) {
                        divarPhoneError.textContent = 'شماره موبایل باید به فرم 09xxxxxxxxx باشد';
                        divarPhoneError.classList.add('show');
                        return;
                    }
                    divarPhoneError.classList.remove('show');
                    this.disabled = true;
                    this.textContent = 'در حال ارسال...';

                    const fd = new FormData();
                    fd.append('provider', 'divar');
                    fd.append('action', 'divar_send_otp');
                    fd.append('phone', phone);

                    fetch('', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            this.disabled = false;
                            this.textContent = 'ارسال کد';
                            if (data.success) {
                                divarModalPhoneStep.style.display = 'none';
                                divarModalOtpStep.style.display = 'block';
                                document.getElementById('divarOtpSubtitle').textContent =
                                    'کد ارسال شده به ' + phone + ' را وارد کنید.';
                                setTimeout(() => divarOtpInput.focus(), 50);
                            } else {
                                divarPhoneError.textContent = data.message || 'ارسال کد ناموفق بود';
                                divarPhoneError.classList.add('show');
                            }
                        })
                        .catch(() => {
                            this.disabled = false;
                            this.textContent = 'ارسال کد';
                            divarPhoneError.textContent = 'خطا در ارتباط با سرور';
                            divarPhoneError.classList.add('show');
                        });
                });

                document.getElementById('divarVerifyCodeBtn').addEventListener('click', function() {
                    const code = divarOtpInput.value.trim();
                    if (!/^\d{4,8}$/.test(code)) {
                        divarOtpError.textContent = 'کد نامعتبر است';
                        divarOtpError.classList.add('show');
                        return;
                    }
                    divarOtpError.classList.remove('show');
                    this.disabled = true;
                    this.textContent = 'در حال بررسی...';

                    const fd = new FormData();
                    fd.append('provider', 'divar');
                    fd.append('action', 'divar_verify_otp');
                    fd.append('code', code);

                    fetch('', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            this.disabled = false;
                            this.textContent = 'تأیید';
                            if (data.success) {
                                closeDivarModal();
                                if (pendingPlaceButton) {
                                    fetchDivarPhone(pendingPlaceButton);
                                }
                            } else {
                                divarOtpError.textContent = data.message || 'کد وارد شده صحیح نیست';
                                divarOtpError.classList.add('show');
                            }
                        })
                        .catch(() => {
                            this.disabled = false;
                            this.textContent = 'تأیید';
                            divarOtpError.textContent = 'خطا در ارتباط با سرور';
                            divarOtpError.classList.add('show');
                        });
                });

                const divarPhoneFailureMessage = 'وارد سایت دیوار شوید و کپجا را حل کنیدتا دسترسی شما باز شود';

                function fetchDivarPhone(btn) {
                    const placeId = btn.dataset.placeId;
                    btn.classList.add('loading');
                    btn.disabled = true;

                    const fd = new FormData();
                    fd.append('provider', 'divar');
                    fd.append('action', 'get_phone');
                    fd.append('place_id', placeId);

                    fetch('', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(data => {
                            btn.classList.remove('loading');
                            if (data.success) {
                                markResultAsSaved(btn);
                                const wrapper = document.createElement('div');
                                wrapper.className = 'divar-phone-result';
                                wrapper.innerHTML = '📞 ' + data.phone_number;
                                btn.parentNode.replaceChild(wrapper, btn);
                            } else if (data.authentication_required && !data.phone_fetch_failed) {
                                pendingPlaceButton = btn;
                                btn.disabled = false;
                                openDivarModal();
                            } else {
                                btn.disabled = false;
                                alert(data.message || divarPhoneFailureMessage);
                            }
                        })
                        .catch(() => {
                            btn.classList.remove('loading');
                            btn.disabled = false;
                            alert(divarPhoneFailureMessage);
                        });
                }

                document.querySelectorAll('.divar-phone-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        fetchDivarPhone(this);
                    });
                });
            });
        </script>
        </body>
        </html>
        <?php
    }
}