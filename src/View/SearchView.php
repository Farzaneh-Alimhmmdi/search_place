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
            $providerLabel = 'نشان (Neshan)';
        } elseif ($this->provider === 'google_map') {
            $providerLabel = 'گوگل Map';
        } elseif ($this->provider === 'divar') {
            $providerLabel = 'دیوار (Divar)';
        } else {
            $providerLabel = 'بلد (Balad)';
        }
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>جستجوی مکان - <?= htmlspecialchars($providerLabel) ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f5f5f5; direction: rtl; }
                .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
                .form-box { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px; }
                .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
                .form-title { font-size: 24px; font-weight: bold; margin: 0; color: #333; }
                .form-row { display: flex; gap: 15px; flex-wrap: wrap; }
                .form-group { flex: 1; min-width: 200px; margin-bottom: 15px; }
                .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: #555; }
                .form-group select { width: 100%; padding: 10px 15px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; }
                .submit-btn { background: #4a90d9; color: #fff; padding: 12px 30px; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; }
                .submit-btn:hover { background: #357abd; }
                .results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; }
                .card { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .card h3 { font-size: 18px; margin-bottom: 10px; color: #333; }
                .card p { margin-bottom: 6px; color: #666; font-size: 14px; }
                .card .phone { color: #27ae60; }
                .card .website { color: #2980b9; text-decoration: none; }
                .card img { width: 100%; height: 150px; object-fit: cover; border-radius: 8px; margin-bottom: 10px; }
                .error { background: #fee; color: #c00; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
                .info { background: #e8f4fd; color: #333; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
                .provider-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; margin-left: 10px; }
                .provider-balad { background: #e3f2fd; color: #1565c0; }
                .provider-neshan { background: #f3e5f5; color: #7b1fa2; }
                .provider-google_map { background: #e8f5e9; color: #2e7d32; }
                .provider-divar { background: #fef3e2; color: #e67e22; }
                .call-logs-link { background: #27ae60; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-size: 14px; transition: background 0.2s; }
                .call-logs-link:hover { background: #219a52; }
                .call-btn {
                    background: #27ae60;
                    color: #fff;
                    border: none;
                    padding: 10px 20px;
                    border-radius: 6px;
                    font-size: 14px;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    margin-top: 10px;
                    transition: background 0.2s;
                }
                .call-btn:hover { background: #219a52; }
                .call-btn:disabled { background: #95a5a6; cursor: not-allowed; }
                .call-btn .spinner {
                    display: none;
                    width: 16px;
                    height: 16px;
                    border: 2px solid #fff;
                    border-top-color: transparent;
                    border-radius: 50%;
                    animation: spin 0.8s linear infinite;
                }
                .call-btn.loading .spinner { display: block; }
                .call-btn.loading .btn-text { opacity: 0.7; }
                .call-btn.call-btn-saved {
                    background: #95a5a6;
                    cursor: not-allowed;
                }
                .call-btn.call-btn-saved .btn-text {
                    opacity: 0.9;
                }
                @keyframes spin { to { transform: rotate(360deg); } }

                /* Status badge */
                .call-status {
                    display: inline-block;
                    padding: 4px 10px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 600;
                    margin-right: 10px;
                }
                .status-pending { background: #fff3cd; color: #856404; }
                .status-completed { background: #d4edda; color: #155724; }
                .status-cancelled { background: #f8d7da; color: #721c24; }

                /* Toast notifications */
                .toast-container {
                    position: fixed;
                    top: 20px;
                    left: 20px;
                    z-index: 9999;
                }
                .toast {
                    background: #fff;
                    padding: 15px 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    margin-bottom: 10px;
                    min-width: 300px;
                    animation: slideIn 0.3s ease;
                    border-left: 4px solid #27ae60;
                }
                .toast.error { border-left-color: #e74c3c; }
                .toast.warning { border-left-color: #f39c12; }
                @keyframes slideIn {
                    from { transform: translateX(-100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
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
                        <div class="info">
                            <strong>یافت نشد:</strong> هیچ
                            <?= htmlspecialchars($categoryLabel ?: $this->selectedCategory) ?>
                            ای در
                            <?= htmlspecialchars($this->selectedCity) ?>
                            پیدا نشد.
                        </div>
                    <?php else: ?>
                        <div class="info">
                            <strong><?= htmlspecialchars($this->results['title'] ?? '') ?></strong> -
                            یافت شد: <?= $this->results['total'] ?> مورد
                        </div>
                        <div class="results-grid">
                            <?php foreach ($this->results['places'] as $place): ?>
                                <div class="card">
                                    <?php if (!empty($place['image_preview'])): ?>
                                        <img src="<?= htmlspecialchars($place['image_preview']) ?>" alt="<?= htmlspecialchars($place['name']) ?>">
                                    <?php endif; ?>
                                    <h3><?= htmlspecialchars($place['name']) ?></h3>
                                    <p><strong>آدرس:</strong> <?= htmlspecialchars($place['address'] ?? '---') ?></p>
                                    <?php if (!empty($place['description'])): ?>
                                        <p><strong>توضیحات:</strong> <?= htmlspecialchars($place['description']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($place['price'])): ?>
                                        <p><strong>قیمت:</strong> <?= htmlspecialchars($place['price']) ?></p>
                                    <?php endif; ?>
                                    <p class="phone"><strong>تلفن:</strong> <?= htmlspecialchars($place['telephone'] ?? '---') ?></p>
                                    <?php if (!empty($place['website'])): ?>
                                        <p><strong>وب‌سایت:</strong>
                                            <a href="<?= htmlspecialchars($place['website']) ?>"
                                               target="_blank" class="website"><?= htmlspecialchars($place['website']) ?></a>
                                        </p>
                                    <?php endif; ?>
                                    <?php if ($this->provider === 'balad' && !empty($place['balad_url'])): ?>
                                        <p><a href="<?= htmlspecialchars($place['balad_url']) ?>" target="_blank">مشاهده در بلد ↗</a></p>
                                    <?php elseif ($this->provider === 'neshan' && !empty($place['neshan_url'])): ?>
                                        <p><a href="<?= htmlspecialchars($place['neshan_url']) ?>" target="_blank">مشاهده در نشان ↗</a></p>
                                    <?php elseif ($this->provider === 'google_map' && !empty($place['place_id'])): ?>
                                        <p><a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($place['name'] . ' ' . $place['address']) ?>" target="_blank">مشاهده در گوگل ↗</a></p>
                                    <?php elseif ($this->provider === 'divar' && !empty($place['divar_url'])): ?>
                                        <p><a href="<?= htmlspecialchars($place['divar_url']) ?>" target="_blank">مشاهده در دیوار ↗</a></p>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $phone = $place['telephone'] ?? ($place['phone'] ?? null);
                                    $hasPhone = !empty($phone) && $phone !== '---';
                                    if ($hasPhone): 
                                        $placeId = $place['id'] ?? $place['token'] ?? $place['place_id'] ?? '';
                                        $hasExistingLog = $this->hasExistingCallLog($place);
                                        $existingStatus = $this->getExistingCallLogStatus($place);
                                    ?>
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