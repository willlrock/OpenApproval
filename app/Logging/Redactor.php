<?php

namespace App\Logging;

use Throwable;

class Redactor
{
    public function text(string $text): string
    {
        if (preg_match('/SQLSTATE\[([A-Z0-9]+)\]/', $text, $match)) {
            return 'Database operation failed. SQLSTATE['.$match[1].']';
        }
        foreach ([config('app.key'), config('nutgram.token'), config('nutgram.webhook_secret'), config('database.connections.pgsql.password'), config('mail.mailers.smtp.password'), config('sentry.dsn')] as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace([$secret, rawurlencode($secret)], '[СКРЫТО]', $text);
            }
        }

        $text = preg_replace('~\b\d{6,}:[A-Za-z0-9_-]{20,}\b~', '[СКРЫТО]', $text);
        $text = preg_replace('~\b(Bearer|Basic)\s+[A-Za-z0-9+/=._-]+~i', '$1 [СКРЫТО]', $text);
        $text = preg_replace('~(https?://)[^\s/@:]+:[^\s/@]+@~i', '$1[СКРЫТО]@', $text);
        $text = preg_replace('~((?:["\x27]?[\w.-]*(?:password|passwd|secret|token|authorization|cookie|api[_-]?key)[\w.-]*["\x27]?)\s*[:=]\s*)("(?:\\\\.|[^"\\\\])*"|\x27[^\x27]*\x27|[^\s,;&}\r\n]+)~i', '$1[СКРЫТО]', $text);

        return str_replace(["\r", "\n", "\0"], ['\\r', '\\n', ''], mb_strcut($text, 0, 16384, 'UTF-8'));
    }

    public function value(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[TRUNCATED]';
        }
        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'message' => $this->text($value->getMessage()),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
                // Never serialize arguments, SQL bindings, HTTP bodies or object properties.
                'trace' => array_map(fn (array $frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function'])), array_slice($value->getTrace(), 0, 40)),
                'previous' => $value->getPrevious() ? $this->value($value->getPrevious(), $depth + 1) : null,
            ];
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                $result[$key] = is_string($key) && preg_match('/password|passwd|secret|token|authorization|cookie|api[_-]?key|\bdsn\b|email|phone|full_name|signature|bank|card_number|account_number|request_body|response_body|payload|bindings/i', $key)
                    ? '[СКРЫТО]'
                    : $this->value($item, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            return $this->text($value);
        }

        return is_object($value) ? '['.$value::class.']' : (is_resource($value) ? '[RESOURCE]' : $value);
    }
}
