<?php

namespace Src\Neshan;

/**
 * Calls the Python/Playwright Neshan searcher and normalizes its JSON response.
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
        $this->timeout = max(1, $timeout);
    }

    /**
     * Search for a single page of places.
     *
     * @return array{success:bool, places:array, total:int, total_results:?int,
     *               page:int, page_count:int, has_more:bool, error?:string}
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        $page = max(1, $page);
        $cityName = $this->slugToCityName($citySlug);
        $command = [
            $this->pythonExecutable,
            $this->pythonScriptPath,
            '--city', $cityName,
            '--category', $category,
            '--max-results', '20',
            '--page', (string)$page,
            '--output', '-',
        ];

        $execution = $this->runPython($command);
        if (!$execution['success']) {
            return $this->failure($execution['error'], $page);
        }

        $data = $this->decodeJsonOutput($execution['stdout']);
        if (!is_array($data)) {
            $details = trim($execution['stderr']);
            $message = 'Neshan returned an invalid response.';
            if ($details !== '') {
                $message .= ' ' . substr($details, 0, 500);
            }
            error_log('Neshan response could not be decoded: ' . $details);
            return $this->failure($message, $page);
        }

        if (!empty($data['error'])) {
            $message = (string)$data['error'];
            error_log('Neshan search error: ' . $message);
            return $this->failure($message, $page);
        }

        if ($execution['exit_code'] > 0) {
            $details = trim($execution['stderr']);
            $message = 'Neshan search process failed.';
            if ($details !== '') {
                $message .= ' ' . substr($details, 0, 500);
            }
            error_log($message);
            return $this->failure($message, $page);
        }

        $places = $data[$category] ?? null;
        if (!is_array($places)) {
            return $this->failure('Neshan response did not include the requested category.', $page);
        }

        $pagination = $data['_pagination'][$category] ?? [];
        $currentPage = max(1, (int)($pagination['page'] ?? $page));
        $hasMore = (bool)($pagination['has_more'] ?? false);
        $knownTotal = $pagination['total_results'] ?? null;
        $knownTotal = is_numeric($knownTotal) ? (int)$knownTotal : null;
        $pageCount = max(1, (int)($pagination['page_count'] ?? ($hasMore ? $currentPage + 1 : $currentPage)));

        return [
            'success'       => true,
            'places'        => $places,
            'total'         => count($places),
            'total_results' => $knownTotal,
            'loaded_results'=> (int)($pagination['loaded_results'] ?? count($places)),
            'page'          => $currentPage,
            'page_count'    => $pageCount,
            'has_more'      => $hasMore,
            'complete'      => (bool)($pagination['complete'] ?? !$hasMore),
        ];
    }

    /**
     * Fetch every page (for export jobs; normal requests should use search()).
     */
    public function searchAllPages(string $citySlug, string $category): array
    {
        $allPlaces = [];
        $page = 1;
        $maxPages = 50;

        while ($page <= $maxPages) {
            $result = $this->search($citySlug, $category, $page);
            if (!$result['success']) {
                return $result;
            }

            foreach ($result['places'] as $place) {
                $identity = $place['place_id'] ?? $place['neshan_url'] ?? json_encode($place);
                $allPlaces[(string)$identity] = $place;
            }

            if (empty($result['has_more'])) {
                break;
            }
            $page++;
        }

        $places = array_values($allPlaces);
        return [
            'success'       => true,
            'places'        => $places,
            'total'         => count($places),
            'total_results' => count($places),
            'loaded_results'=> count($places),
            'page'          => $page,
            'page_count'    => $page,
            'has_more'      => false,
            'complete'      => true,
        ];
    }

    /**
     * Start the process without a shell so Persian text and paths are passed as
     * exact arguments (and cannot be broken by nested quoting).
     *
     * @return array{success:bool, stdout:string, stderr:string, exit_code:int, error:string}
     */
    private function runPython(array $command): array
    {
        if (!is_file($this->pythonScriptPath)) {
            return [
                'success' => false,
                'stdout' => '',
                'stderr' => '',
                'exit_code' => -1,
                'error' => 'Neshan search script was not found.',
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, dirname($this->pythonScriptPath));
        if (!is_resource($process)) {
            return [
                'success' => false,
                'stdout' => '',
                'stderr' => '',
                'exit_code' => -1,
                'error' => 'Could not start the Neshan search process.',
            ];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $exitCode = -1;
        $deadline = microtime(true) + $this->timeout;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = (int)$status['exitcode'];
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, 15);
                $terminateDeadline = microtime(true) + 2;
                do {
                    usleep(100000);
                    $status = proc_get_status($process);
                } while (($status['running'] ?? false) && microtime(true) < $terminateDeadline);

                if ($status['running'] ?? false) {
                    proc_terminate($process, 9);
                }
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200000);
            foreach ($read as $readyPipe) {
                $chunk = fread($readyPipe, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($readyPipe === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        foreach ([1, 2] as $index) {
            if (is_resource($pipes[$index])) {
                fclose($pipes[$index]);
            }
        }
        $closeCode = proc_close($process);
        if ($exitCode < 0 && $closeCode >= 0) {
            $exitCode = $closeCode;
        }

        if ($timedOut) {
            return [
                'success' => false,
                'stdout' => $stdout,
                'stderr' => $stderr,
                'exit_code' => $exitCode,
                'error' => 'Neshan search timed out. Please try again.',
            ];
        }

        return [
            'success' => $exitCode === 0 || trim($stdout) !== '',
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
            'error' => $exitCode === 0 ? '' : 'Python script failed.',
        ];
    }

    private function decodeJsonOutput(string $output): ?array
    {
        $decoded = json_decode(trim($output), true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $lines = preg_split('/\R/', $output) ?: [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode(trim($line), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function failure(string $error, int $page): array
    {
        return [
            'success'       => false,
            'error'         => $error,
            'places'        => [],
            'total'         => 0,
            'total_results' => null,
            'page'          => $page,
            'page_count'    => 1,
            'has_more'      => false,
        ];
    }

    /** Convert a city slug back to its Persian display name. */
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
            'urmia' => 'ارومیه',
            'mashhad' => 'مشهد',
            'karaj' => 'کرج',
            'zahedan' => 'زاهدان',
        ];

        return $slugMap[$citySlug] ?? rawurldecode($citySlug);
    }
}
