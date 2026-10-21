<?php

namespace App\Services;

class SystemLogReader
{
    public function readConfigured(array $logging, array $filters = [], array $secrets = []): array
    {
        $paths = [];
        $visited = [];
        $collect = function (string $name) use (&$collect, &$paths, &$visited, $logging, $filters): void {
            if (isset($visited[$name])) {
                return;
            }
            $visited[$name] = true;
            $channel = $logging['channels'][$name] ?? [];
            if (($channel['driver'] ?? null) === 'stack') {
                foreach ($channel['channels'] ?? [] as $child) {
                    $collect(trim($child));
                }

                return;
            }
            if (! in_array($channel['driver'] ?? null, ['single', 'daily'], true)
                || empty($channel['path']) || str_contains($channel['path'], '://')) {
                return;
            }
            $path = $channel['path'];
            if ($channel['driver'] === 'single') {
                $paths[] = $path;

                return;
            }
            $info = pathinfo($path);
            $prefix = $info['dirname'].DIRECTORY_SEPARATOR.$info['filename'].'-';
            $suffix = isset($info['extension']) ? '.'.$info['extension'] : '';
            $found = false;
            // Scan names rather than globbing a configurable path containing metacharacters.
            $files = is_dir($info['dirname']) && is_readable($info['dirname']) ? scandir($info['dirname']) : [];
            foreach ($files ?: [] as $file) {
                if (preg_match('/^'.preg_quote($info['filename'].'-', '/').'(\d{4}-\d{2}-\d{2})'.preg_quote($suffix, '/').'$/D', $file, $match)
                    && (empty($filters['date']) || $filters['date'] === $match[1])) {
                    $paths[] = $info['dirname'].DIRECTORY_SEPARATOR.$file;
                    $found = true;
                }
            }
            // A valid, empty log directory is normal before the first log entry.
            if (! $found && is_dir($info['dirname']) && is_readable($info['dirname']) && is_writable($info['dirname'])) {
                $paths[] = $prefix.($filters['date'] ?? date('Y-m-d')).$suffix;
            }
        };
        $collect($logging['default'] ?? 'stack');
        $paths = array_values(array_unique($paths));
        rsort($paths, SORT_STRING);
        $entries = [];
        $remaining = 1024 * 1024;
        $available = false;
        $limited = false;
        foreach ($paths as $path) {
            if (! is_file($path)) {
                $available = $available || (is_dir(dirname($path)) && is_readable(dirname($path)) && is_writable(dirname($path)));

                continue;
            }
            if ($remaining === 0) {
                $limited = true;
                break;
            }
            $result = $this->readFile($path, $filters, $secrets, $remaining);
            if ($result['unavailable']) {
                continue;
            }
            $available = $available || ! $result['unavailable'];
            $limited = $limited || $result['limited'];
            $entries = array_merge($entries, $result['entries']);
            $remaining -= min($remaining, (int) @filesize($path));
        }
        usort($entries, fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return ['entries' => array_slice($entries, 0, 200), 'unavailable' => ! $available, 'limited' => $limited || count($entries) > 200];
    }

    public function read(string $path, array $filters = [], array $secrets = []): array
    {
        return $this->readFile($path, $filters, $secrets, 1024 * 1024);
    }

    private function readFile(string $path, array $filters, array $secrets, int $maxBytes): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return ['entries' => [], 'unavailable' => true, 'limited' => false];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ['entries' => [], 'unavailable' => true, 'limited' => false];
        }
        try {
            $size = fstat($handle)['size'];
            $offset = max(0, $size - $maxBytes);
            fseek($handle, $offset);
            $text = stream_get_contents($handle, $maxBytes);
        } finally {
            fclose($handle);
        }
        // Discard the first partial line when starting in the middle of a file.
        if ($offset > 0) {
            $newline = strpos($text, "\n");
            $text = $newline === false ? '' : substr($text, $newline + 1);
        }
        preg_match_all('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] [^\r\n]+?\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY): ?/m', $text, $headers, PREG_OFFSET_CAPTURE);
        $entries = [];
        $limited = $offset > 0;
        for ($i = count($headers[0]) - 1; $i >= 0; $i--) {
            $date = $headers[1][$i][0];
            $level = $headers[2][$i][0];
            if ((! empty($filters['level']) && $filters['level'] !== $level)
                || (! empty($filters['date']) && ! str_starts_with($date, $filters['date']))) {
                continue;
            }
            $start = $headers[0][$i][1] + strlen($headers[0][$i][0]);
            $end = $headers[0][$i + 1][1] ?? strlen($text);
            $details = $this->redact(trim(substr($text, $start, $end - $start)), $secrets);
            if (! empty($filters['search']) && mb_stripos($details, $filters['search']) === false) {
                continue;
            }
            if (count($entries) === 200) {
                $limited = true;
                break;
            }
            $details = mb_strcut($details, 0, 16384, 'UTF-8').(strlen($details) > 16384 ? "\n[Запись сокращена]" : '');
            $entries[] = ['date' => $date, 'level' => $level, 'message' => mb_substr(explode("\n", $details)[0], 0, 300), 'details' => $details];
        }

        return ['entries' => $entries, 'unavailable' => false, 'limited' => $limited];
    }

    private function redact(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace([$secret, str_replace('/', '\\/', $secret)], '[СКРЫТО]', $text);
            }
        }
        $text = preg_replace('~\b\d{6,}:[A-Za-z0-9_-]{20,}\b~', '[СКРЫТО]', $text);
        $text = preg_replace('~(Bearer|Basic)\s+[A-Za-z0-9+/=._-]+~i', '$1 [СКРЫТО]', $text);
        $text = preg_replace('~(https?://)[^\s/@:]+:[^\s/@]+@~i', '$1[СКРЫТО]@', $text);
        $text = preg_replace('~((?:["\x27]?[\w.-]*(?:password|passwd|secret|token|authorization|cookie|api[_-]?key)[\w.-]*["\x27]?)\s*[:=]\s*)("(?:\\\\.|[^"\\\\])*"|\x27[^\x27]*\x27|[^\s,;&}\r\n]+)~i', '$1[СКРЫТО]', $text);

        return $text;
    }
}
