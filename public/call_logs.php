<?php
/**
 * Call Logs Admin Page
 * View and manage call logs
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Src\Support\Config;
use Src\Support\Db;

Config::load(__DIR__ . '/../config/balad.php');

Db::connect(
    Config::get('db_host', '127.0.0.1'),
    Config::get('db_port', 3306),
    Config::get('db_database', 'search_place'),
    Config::get('db_username', 'root'),
    Config::get('db_password', '')
);

$pdo = Db::getConnection();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $validStatuses = ['pending', 'completed', 'cancelled'];
    
    if ($id > 0 && in_array($status, $validStatuses)) {
        $stmt = $pdo->prepare("UPDATE call_logs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$status, $id]);
        header('Location: call_logs.php?updated=1');
        exit;
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM call_logs WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: call_logs.php?deleted=1');
        exit;
    }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filter by status
$statusFilter = $_GET['status'] ?? '';
$whereClause = '';
$params = [];
if ($statusFilter && in_array($statusFilter, ['pending', 'completed', 'cancelled'])) {
    $whereClause = 'WHERE status = ?';
    $params[] = $statusFilter;
}

// Get total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM call_logs $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

// Get logs
$logsStmt = $pdo->prepare("
    SELECT * FROM call_logs 
    $whereClause
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$params[] = $perPage;
$params[] = $offset;
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

// Get stats
$statsStmt = $pdo->query("
    SELECT status, COUNT(*) as count 
    FROM call_logs 
    GROUP BY status
");
$stats = $statsStmt->fetchAll(\PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت لاگ تماس‌ها</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f5f5f5; direction: rtl; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header h1 { color: #333; }
        .back-link { background: #4a90d9; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; }
        .back-link:hover { background: #357abd; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); flex: 1; min-width: 150px; text-align: center; }
        .stat-card.total { border-top: 4px solid #4a90d9; }
        .stat-card.pending { border-top: 4px solid #f39c12; }
        .stat-card.completed { border-top: 4px solid #27ae60; }
        .stat-card.cancelled { border-top: 4px solid #e74c3c; }
        .stat-value { font-size: 28px; font-weight: bold; color: #333; }
        .stat-label { color: #666; margin-top: 5px; }
        .filters { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .filter-form { display: flex; gap: 15px; align-items: end; flex-wrap: wrap; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; }
        .filter-group label { font-weight: 600; color: #555; }
        .filter-group select { padding: 8px 12px; border: 2px solid #ddd; border-radius: 6px; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn-primary { background: #4a90d9; color: #fff; }
        .btn-primary:hover { background: #357abd; }
        .btn-success { background: #27ae60; color: #fff; }
        .btn-success:hover { background: #219a52; }
        .btn-warning { background: #f39c12; color: #fff; }
        .btn-warning:hover { background: #e67e22; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-danger:hover { background: #c0392b; }
        .btn-small { padding: 5px 10px; font-size: 12px; }
        .table-container { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: right; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #555; white-space: nowrap; }
        tr:hover td { background: #f8f9fa; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .description-cell { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .actions { display: flex; gap: 5px; }
        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 14px; border: 1px solid #ddd; border-radius: 6px; text-decoration: none; color: #333; }
        .pagination a:hover { background: #4a90d9; color: #fff; border-color: #4a90d9; }
        .pagination .current { background: #4a90d9; color: #fff; border-color: #4a90d9; }
        .empty { text-align: center; padding: 40px; color: #999; }
        .flash { padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; }
        .flash-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📞 مدیریت لاگ تماس‌ها</h1>
            <a href="index.php" class="back-link">← بازگشت به جستجو</a>
        </div>

        <?php if (isset($_GET['updated'])): ?>
            <div class="flash flash-success">وضعیت با موفقیت به‌روزرسانی شد</div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="flash flash-success">رکورد با موفقیت حذف شد</div>
        <?php endif; ?>

        <div class="stats">
            <div class="stat-card total">
                <div class="stat-value"><?= $total ?></div>
                <div class="stat-label">کل تماس‌ها</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?= $stats['pending'] ?? 0 ?></div>
                <div class="stat-label">در انتظار</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-value"><?= $stats['completed'] ?? 0 ?></div>
                <div class="stat-label">تکمیل شده</div>
            </div>
            <div class="stat-card cancelled">
                <div class="stat-value"><?= $stats['cancelled'] ?? 0 ?></div>
                <div class="stat-label">لغو شده</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>فیلتر بر اساس وضعیت</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">همه</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>تکمیل شده</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                    </select>
                </div>
            </form>
        </div>

        <div class="table-container">
            <?php if (empty($logs)): ?>
                <div class="empty">هیچ لاگ تماسی یافت نشد</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>شناسه مکان</th>
                            <th>شماره تلفن</th>
                            <th>شهر</th>
                            <th>دسته‌بندی</th>
                            <th>توضیحات</th>
                            <th>وضعیت</th>
                            <th>آدرس IP</th>
                            <th>تاریخ ثبت</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $index => $log): ?>
                            <tr>
                                <td><?= $offset + $index + 1 ?></td>
                                <td><?= htmlspecialchars($log['place_id']) ?></td>
                                <td><?= htmlspecialchars($log['phone_number']) ?></td>
                                <td><?= htmlspecialchars($log['city']) ?></td>
                                <td><?= htmlspecialchars($log['category']) ?></td>
                                <td class="description-cell" title="<?= htmlspecialchars($log['description'] ?? '') ?>">
                                    <?= htmlspecialchars($log['description'] ?? '-') ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $log['status'] ?>">
                                        <?= ucfirst($log['status']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                                <td><?= $log['created_at'] ?></td>
                                <td>
                                    <div class="actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id" value="<?= $log['id'] ?>">
                                            <select name="status" onchange="this.form.submit()" class="btn-small" style="padding: 4px 8px;">
                                                <option value="pending" <?= $log['status'] === 'pending' ? 'selected' : '' ?>>در انتظار</option>
                                                <option value="completed" <?= $log['status'] === 'completed' ? 'selected' : '' ?>>تکمیل شده</option>
                                                <option value="cancelled" <?= $log['status'] === 'cancelled' ? 'selected' : '' ?>>لغو شده</option>
                                            </select>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('آیا از حذف این رکورد مطمئن هستید؟')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $log['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-small">حذف</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?= $statusFilter ? '&status=' . $statusFilter : '' ?>">ابتدا</a>
                    <a href="?page=<?= $page - 1 ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?>">قبلی</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?>">بعدی</a>
                    <a href="?page=<?= $totalPages ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?>">انتها</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>