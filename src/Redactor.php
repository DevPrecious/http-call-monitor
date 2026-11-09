<?php

namespace HttpCallMonitor;

use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Decides what part of a call is safe to store.
 *
 * Query strings and URL credentials are never read. Path segments that look like
 * identifiers or tokens are masked, which also lets similar calls group together.
 */
final class Redactor
{
    private const MASK = '{id}';

    private const MAX_PATH_LENGTH = 1000;

    private const MAX_ERROR_LENGTH = 255;

    /**
     * @param  array<string, string>  $pathReplacements  Regex pattern => replacement.
     */
    public function __construct(private readonly array $pathReplacements = []) {}

    public function host(UriInterface $uri): string
    {
        $port = $uri->getPort();

        return mb_substr(strtolower($uri->getHost()).($port === null ? '' : ":{$port}"), 0, 255);
    }

    public function path(UriInterface $uri): string
    {
        $path = implode('/', array_map($this->maskSegment(...), explode('/', $uri->getPath())));

        if ($this->pathReplacements !== []) {
            $path = preg_replace(array_keys($this->pathReplacements), array_values($this->pathReplacements), $path);
        }

        return mb_substr($path === '' ? '/' : $path, 0, self::MAX_PATH_LENGTH);
    }

    /**
     * cURL error messages end with the full request URL, query string included.
     */
    public function error(mixed $reason): string
    {
        $message = mb_scrub($reason instanceof Throwable ? $reason->getMessage() : 'Request failed', 'UTF-8');
        $message = preg_replace('#\s*\(see https?://[^)]*\)#i', '', $message);
        $message = preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '[url]', $message);

        return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
    }

    private function maskSegment(string $segment): string
    {
        return $this->looksLikeIdentifier($segment) ? self::MASK : $segment;
    }

    private function looksLikeIdentifier(string $segment): bool
    {
        if ($segment === '') {
            return false;
        }

        return ctype_digit($segment)
            || str_contains($segment, '@')
            || stripos($segment, '%40') !== false
            || (strlen($segment) >= 16 && preg_match('/\d|[a-z].*[A-Z]|[A-Z].*[a-z]/', $segment) === 1);
    }
}
