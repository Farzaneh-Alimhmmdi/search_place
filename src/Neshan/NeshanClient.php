<?php

namespace Src\Neshan;

use Src\Exceptions\NeshanRequestException;

/**
 * NeshanClient - Calls the Python Neshan search script to get results.
 * 
 * Since Neshan APIs are restricted, we use a Python script with Chromium-like
 * headers to simulate a real browser and scrape the search results.
 */
final class NeshanClient
{
    private string $pythonScriptPath;
    private string $pythonExecutable;
    private int $timeout;

    public function __construct(
        string $pythonScriptPath,
        string $pythonExecutable = 'python',
        int $timeout = 60
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
     * @return array ['success' => true, 'tokens' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        // Map city slug back to Persian name for the Python script
        $cityName = $this->slugToCityName($citySlug);
        
        $cmd = sprintf(
            '%s "%s" --city "%s" --category "%s" --max-results 20 --output - 2>&1',
            escapeshellarg($this->pythonExecutable),
            escapeshellarg($this->pythonScriptPath),
            escapeshellarg($cityName),
            escapeshellarg($category)
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
            if (json_last_error() === JSON_ERROR_NONE && isset($data[$category])) {
                $places = $data[$category] ?? [];
                return [
                    'success' => true,
                    'tokens' => array_map(fn($p) => $p['name'] ?? '', $places),
                    'title' => $category,
                    'page_count' => 1,
                    'total' => count($places),
                    '_raw_places' => $places, // Pass raw places to avoid re-fetching
                ];
            }
        }
        
        // If JSON parsing failed, check stderr
        if ($stderr) {
            error_log("Neshan Python stderr: $stderr");
        }
        
        // Return success with empty results for consistent "no results" handling
        return [
            'success' => true,
            'tokens' => [],
            'title' => $category,
            'page_count' => 1,
            'total' => 0,
            '_raw_places' => [],
        ];
    }

    /**
     * Get details for places - but since Python script already returns full details,
     * we just return what we already have
     * 
     * @param array $tokens Not used for Neshan (we already have full data)
     * @return array ['success' => true, 'items' => [...places...]]
     */
    public function getDetails(array $tokens): array
    {
        // The search() method already returns full place details in _raw_places
        // This method exists for interface compatibility with BaladClient
        return ['success' => true, 'items' => []];
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
        ];
        
        return $slugMap[$citySlug] ?? $citySlug;
    }
}