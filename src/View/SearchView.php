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

    public function __construct(
        array $provinces,
        array $categories,
        array $citySlugs,
        ?array $results,
        ?string $error,
        string $selectedCity,
        string $selectedCategory,
        string $provider = 'balad'
    ) {
        $this->provinces = $provinces;
        $this->categories = $categories;
        $this->citySlugs = $citySlugs;
        $this->results = $results;
        $this->error = $error;
        $this->selectedCity = $selectedCity;
        $this->selectedCategory = $selectedCategory;
        $this->provider = $provider;
    }

    public function render(): void
    {
        $providerLabel = $this->provider === 'neshan' ? 'نشان (Neshan)' : 'بلد (Balad)';
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
        .form-title { font-size: 24px; font-weight: bold; margin-bottom: 20px; color: #333; }
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
    </style>
</head>
<body>
    <div class="container">
        <div class="form-box">
            <div class="form-title">
                جستجوی مکان
                <span class="provider-badge provider-<?= $this->provider ?>">
                    <?= htmlspecialchars($providerLabel) ?>
                </span>
            </div>
            <form method="POST">
                <input type="hidden" name="provider" value="<?= htmlspecialchars($this->provider) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>پارامتر جستجو</label>
                        <select name="provider" onchange="this.form.submit()">
                            <option value="balad" <?= ($this->provider === 'balad') ? 'selected' : '' ?>>بلد (Balad)</option>
                            <option value="neshan" <?= ($this->provider === 'neshan') ? 'selected' : '' ?>>نشان (Neshan)</option>
                        </select>
                    </div>
                </div>
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
                    <div class="form-group">
                        <label>نوع مکان</label>
                        <select name="place">
                            <?php foreach ($this->categories as $cat): ?>
                                <option value="<?= $cat['value'] ?>"
                                    <?= ($this->selectedCategory === $cat['value']) ? 'selected' : '' ?>>
                                    <?= $cat['label'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
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
                    if ($cat['value'] === $this->selectedCategory) {
                        $categoryLabel = $cat['label'];
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
                        <p class="phone"><strong>تلفن:</strong> <?= htmlspecialchars($place['telephone'] ?? '---') ?></p>
                        <?php if (!empty($place['website'])): ?>
                            <p><strong>وب‌سایت:</strong>
                                <a href="http://<?= htmlspecialchars($place['website']) ?>"
                                   target="_blank" class="website"><?= htmlspecialchars($place['website']) ?></a>
                            </p>
                        <?php endif; ?>
                        <?php if ($this->provider === 'balad' && !empty($place['balad_url'])): ?>
                            <p><a href="<?= htmlspecialchars($place['balad_url']) ?>" target="_blank">مشاهده در بلد ↗</a></p>
                        <?php elseif ($this->provider === 'neshan' && !empty($place['neshan_url'])): ?>
                            <p><a href="<?= htmlspecialchars($place['neshan_url']) ?>" target="_blank">مشاهده در نشان ↗</a></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</body>
</html>
        <?php
    }
}