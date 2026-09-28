<?php

namespace Src\View;

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
        string $selectedQuery = ''
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
                @keyframes spin { to { transform: rotate(360deg); } }
                
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
                                $hasExistingLog = $hasPhone ? $this->hasExistingCallLog($place) : false;
                                $existingStatus = $hasPhone ? $this->getExistingCallLogStatus($place) : null;
                                ?>
                                <div class="result-item <?= $hasImage ? 'has-image' : '' ?>">
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
                                            
                                            <?php if ($hasPhone): ?>
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
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
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
                            // Submit the form to reload with new provider
                            form.submit();
                        });
                    }

                    // Initialize cities if province is already selected (on page load for Divar)
                    if (provinceSelect && provinceSelect.value) {
                        updateCities(provinceSelect.value);
                    }

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
                });
            </script>
        </body>
        </html>
        <?php
    }
}