<?php

namespace Src\Neshan;

use Src\Exceptions\NeshanRequestException;

/**
 * NeshanClient - Calls the Python Neshan search script to get results.
 * 
 * Since Neshan APIs are restricted, we use a Python script with Chromium-like
 * headers to simulate a real browser and scrape the search results.
 * 
 * Fixed: Default to headless mode for server execution
 */
final class NeshanClient
{
    private string $pythonScriptPath;
    private string $pythonExecutable;
    private int $timeout;

    public function __construct(
        string $pythonScriptPath,
        string $pythonExecutable = 'python',
        int $timeout = 120
    ) {
        $this->pythonScriptPath = $pythonScriptPath;
        $this->pythonExecutable = $pythonExecutable;
        $this->timeout = $timeout;
    }

    /**
     * Search for places using the Python script
     * 
     * @param string $citySlug URL-safe city identifier (e.g. 'tehran')
     * @param string $category Category value from categories.php (e.g. 'hotel')
     * @param int $page Page number
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        // Map city slug back to Persian name for the Python script
        $cityName = $this->slugToCityName($citySlug);
        
        $cmd = sprintf(
            '%s "%s" --city "%s" --category "%s" --max-results 20 --page %d --output - 2>&1',
            escapeshellarg($this->pythonExecutable),
            escapeshellarg($this->pythonScriptPath),
            escapeshellarg($cityName),
            escapeshellarg($category),
            $page
        );

        $output = [];
        $returnVar = 0;
        
        // Use proc_open for better control
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        
        $process = proc_open($cmd, $descriptorspec, $pipes);
        
        if (!is_resource($process)) {
            return ['success' => false, 'error' => 'Failed to start Python process'];
        }
        
        // Set timeout
        stream_set_timeout($pipes[1], $this->timeout);
        stream_set_timeout($pipes[2], $this->timeout);
        
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        
        $returnVar = proc_close($process);
        
        // Check for Python errors
        if ($returnVar !== 0) {
            error_log("Neshan Python exited with code: $returnVar, stderr: $stderr");
            return [
                'success' => false, 
                'error' => 'Python script failed',
                'stderr' => $stderr,
                'places' => [],
                'total' => 0,
                'page' => $page,
                'page_count' => 1,
            ];
        }
        
        // Parse JSON from stdout (last line should be JSON)
        $lines = explode("\n", $stdout);
        $jsonLine = '';
        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{') && str_ends_with($line, '}')) {
                $jsonLine = $line;
                break;
            }
        }
        
        if ($jsonLine) {
            $data = json_decode($jsonLine, true);
            
            // Check for Python script errors
            if (isset($data['error'])) {
                error_log("Neshan Python error: " . $data['error']);
                return [
                    'success' => false,
                    'error' => $data['error'],
                    'places' => [],
                    'total' => 0,
                    'page' => $page,
                    'page_count' => 1,
                ];
            }
            
            if (json_last_error() === JSON_ERROR_NONE && isset($data[$category])) {
                $places = $data[$category] ?? [];
                
                // Get pagination info
                $pagination = $data['_pagination'][$category] ?? ['has_more' => false, 'page' => $page];
                $hasMore = $pagination['has_more'] ?? false;
                $currentPage = $pagination['page'] ?? $page;
                
                // Calculate total pages (estimate based on total results and max per page)
                $perPage = 20;
                $pageCount = $hasMore ? ceil(count($places) / $perPage) : ($currentPage > 1 ? $currentPage : 1);
                
                return [
                    'success' => true,
                    'places' => $places,
                    'total' => count($places),
                    'page' => $currentPage,
                    'page_count' => $pageCount,
                    'has_more' => $hasMore,
                    'total_results' => count($places),
                ];
            }
        }
        
        // If JSON parsing failed, check stderr
        if ($stderr) {
            error_log("Neshan Python stderr: $stderr");
        }
        
        // Return empty results for consistent handling
        return [
            'success' => true,
            'places' => [],
            'total' => 0,
            'page' => $page,
            'page_count' => 1,
            'has_more' => false,
            'total_results' => 0,
        ];
    }

    /**
     * Fetch all results across all pages (for complete data export)
     * 
     * @param string $citySlug URL-safe city identifier
     * @param string $category Category value
     * @return array All places across all pages
     */
    public function searchAllPages(string $citySlug, string $category): array
    {
        $allPlaces = [];
        $page = 1;
        $hasMore = true;
        $maxPages = 50; // Safety limit to prevent infinite loops
        
        while ($hasMore && $page <= $maxPages) {
            $result = $this->search($citySlug, $category, $page);
            
            if (!$result['success']) {
                break;
            }
            
            $places = $result['places'] ?? [];
            $allPlaces = array_merge($allPlaces, $places);
            
            $hasMore = $result['has_more'] ?? false;
            $page++;
            
            // Small delay between pages to avoid overloading
            if ($hasMore) {
                usleep(500000); // 0.5 second
            }
        }
        
        return [
            'success' => true,
            'places' => $allPlaces,
            'total' => count($allPlaces),
            'page' => $page - 1,
            'page_count' => $page - 1,
            'has_more' => false,
            'total_results' => count($allPlaces),
        ];
    }

    /**
     * Convert city slug to Persian city name
     */
    private function slugToCityName(string $citySlug): string
    {
        $slugMap = [
            'tehran' => 'تهران',
            'isfahan' => 'اصفهان',
            'shiraz' => 'شیراز',
            'tabriz' => 'تبریز',
            'ahvaz' => 'اهواز',
            'kerman' => 'کرمان',
            'rafsanjan' => 'رفسنجان',
            'sanandaj' => 'سنندج',
            'hamadan' => 'همدان',
            'arak' => 'اراک',
            'semnan' => 'سمنان',
            'gorgan' => 'گرگان',
            'bushehr' => 'بوشهر',
            'hormozgan' => 'هرمزگان',
            'khuzestan' => 'خوزستان',
            'loristan' => 'لرستان',
            'kurdistan' => 'کردستان',
            'chaharmahal-and-bakhtiari' => 'چهارمحال و بختیاری',
            'golestan' => 'گلستان',
            'fars' => 'فارس',
            'khorasan-e-razavi' => 'خراسان رضوی',
            'khorasan-e-shomali' => 'خراسان شمالی',
            'khorasan-e-jonubi' => 'خراسان جنوبی',
            'azarbayjan-e-sharqi' => 'آذربایجان شرقی',
            'azarbayjan-e-gharbi' => 'آذربایجان غربی',
            'zanjan' => 'زنجان',
            'alborz' => 'البرز',
            'ilam' => 'ایلام',
            'ghazvin' => 'قزوین',
            'qom' => 'قم',
            'kermanshah' => 'کرمانشاه',
            'mazandaran' => 'مازندران',
            'markazi' => 'مرکزی',
            'sistan-and-baluchistan' => 'سیستان و بلوچستان',
            'yazd' => 'یزد',
            'rasht' => 'رشت',
            'ardabil' => 'اردبیل',
            'bandar-abbas' => 'بندرعباس',
            'kohgiluyeh-and-boyer-ahmad' => 'کهگیلویه و بویراحمد',
        ];
        
        return $slugMap[$citySlug] ?? $citySlug;
    }
}