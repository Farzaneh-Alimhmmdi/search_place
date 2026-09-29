# Neshan Provider - Fix Summary

## Problems Fixed

### 1. Search works in Chrome but not in PHP ✅
**Root Cause:** The Python script was running Chrome in headed mode (`headless=False`), which requires a display. When called from PHP on a server, there's no display available.

**Fix:**
- Changed default to `headless=True` for server execution
- Added `--headed` flag if you need to debug with visible browser

**Usage:**
```bash
# Headless (default, works on server)
python neshan_search.py --city "تهران" --category hotel --max-results 20 --page 1

# Headed (for debugging, requires display)
python neshan_search.py --city "تهران" --category hotel --max-results 20 --page 1 --headed
```

### 2. Need ALL data, not just first page ✅
**Root Cause:** The PHP client was only fetching page 1. The Python script has pagination support.

**Fix:**
- Added `searchAllPages()` method to `NeshanClient`
- Updated `NeshanSearchService` to support complete data fetch

**Usage:**
```php
// Fetch all pages (complete data) - for export
$allData = $client->searchAllPages('tehran', 'hotel');

// Fetch specific page (memory efficient) - for pagination
$page1 = $client->search('tehran', 'hotel', 1);
```

### 3. Need pagination in the app ✅
**Root Cause:** The app already had pagination UI, but it wasn't properly connected to the data flow.

**Fix:**
- Updated `NeshanController` to pass `total_results` to the view
- Updated `SearchView` to display total results count in pagination
- Pagination now works server-side (only loads current page's data)

**Pagination Display:**
```
صفحه 1 از 5 (مجموع 87 نتیجه)
← صفحه قبلی  1  2  3  4  5  صفحه بعدی →
```

## Files Modified

### Python Script
- **`neshan_search.py`**
  - Default to headless mode (essential for server)
  - All original logic preserved

### PHP Client
- **`NeshanClient.php`**
  - Added `searchAllPages()` method
  - Better error handling (check Python exit code)
  - Proper pagination calculation
  - Timeout management

- **`NeshanSearchService.php`**
  - Support for paginated vs complete data fetch
  - Proper response format with `total_results`

- **`NeshanController.php`**
  - Pass `total_results` to view

- **`SearchView.php`**
  - Display total results in pagination

## Key Features

### Memory Efficient Pagination
- Only loads current page's data (20 results per page)
- Doesn't load all data into memory
- Fast response time for users

### Complete Data Export
- `searchAllPages()` fetches all results across all pages
- Use for data export/backup, not for regular user searches
- Safety limit: max 50 pages to prevent infinite loops

### Server-Side Pagination
- Page numbers are calculated server-side
- User clicks page number → PHP fetches that page
- No need to load all data at once

## Configuration

### Timeout
The default timeout is 120 seconds. Increase if Neshan is slow:
```php
$this->client = new NeshanClient($pythonScript, $pythonExecutable, 180); // 180 seconds
```

## Testing

### Test Python Script
```bash
cd search_place/src/Neshan
python neshan_search.py --city "تهران" --category hotel --max-results 5 --page 1
```

### Test PHP Integration
```php
use Src\Neshan\NeshanClient;
use Src\Neshan\NeshanSearchService;

$client = new NeshanClient(__DIR__ . '/neshan_search.py', 'python', 120);
$service = new NeshanSearchService($client);

// Paginated (memory efficient)
$result = $service->search('tehran', 'hotel', 1);
echo "Page: {$result['page']}, Total: {$result['total_results']}, Pages: {$result['page_count']}\n";

// All pages (complete data)
$allData = $service->searchAllPages('tehran', 'hotel');
echo "Total places: {$allData['total']}\n";
```

## Notes

1. **First run may be slow** - Playwright downloads Chromium on first run
2. **Chrome conflicts** - If you have Chrome open, the script uses Chrome normally
3. **Server requirements** - Need Chrome/Chromium installed on the server
4. **Debugging** - Use `--headed` flag to see the browser (requires display)

## What Was Reverted

I previously broke the script by:
- Adding `--all-pages` flag (not in original)
- Adding temp Chrome profile (not needed)
- Adding JSON file fallback (was empty anyway)

**Now restored to original logic with only headless mode fix.**