<?php
defined('ABSPATH') || exit;

/** Public lookups only. Destinations can only be changed by the existing VPS ingestion flow. */
final class OAP_Affiliate_Redirects {
    public static function destination($value): ?string {
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) { return null; }
        $parts = wp_parse_url($value);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) { return null; }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (strtolower($parts['scheme'] ?? '') !== 'https' || (isset($parts['port']) && $parts['port'] !== 443)) { return null; }
        $host = trim($host, '[]');
        if (in_array($host, ['localhost', 'comparaiprezzi.it', 'www.comparaiprezzi.it',
            'occhioaiprezzi.com', 'www.occhioaiprezzi.com', 'occhioaiprezzi.it', 'www.occhioaiprezzi.it'], true)
            || preg_match('/\.(local|localhost|internal)$/', $host)) { return null; }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with($host, '::ffff:') || str_starts_with($host, 'ff')
                || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int)explode('.', $host)[0] >= 224)) { return null; }
        } else {
            if (!preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host)
                || !str_contains($host, '.') || preg_match('/\A[0-9]+\z/', substr($host, strrpos($host, '.') + 1))) { return null; }
            foreach (explode('.', $host) as $label) {
                if ($label === '' || strlen($label) > 63) { return null; }
            }
        }
        return $value; // Preserve tracking bytes, duplicate parameters and query order.
    }

    /** Return a response description so validation/transport can be tested without exit(). */
    public static function response(string $uri, string $method): ?array {
        $path = wp_parse_url($uri, PHP_URL_PATH);
        $base = rtrim((string)wp_parse_url(home_url('/compara'), PHP_URL_PATH), '/');
        if ($path === $base || $path === $base . '/') {
            $query = wp_parse_url($uri, PHP_URL_QUERY) ?? '';
            if (!preg_match('/\Aid=([A-Za-z0-9_-]{43})\z/', $query, $match)) { return ['status' => 404]; }
            $endpoint = '/api/v1/affiliate-redirects/' . $match[1];
        } elseif (is_string($path) && str_starts_with($path, $base . '/')) {
            $id = substr($path, strlen($base) + 1);
            if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id)
                || wp_parse_url($uri, PHP_URL_QUERY) !== null) { return ['status' => 404]; }
            $endpoint = '/api/v1/affiliate-legacy/' . $id;
        } else { return null; }
        if (!in_array($method, ['GET', 'HEAD'], true)) { return ['status' => 405]; }
        try {
            $backend = OAP_Support::cfg('OAP_BRIDGE_REDIRECT_BASE', '');
            $token = OAP_Support::cfg('OAP_BRIDGE_REDIRECT_TOKEN', '');
            OAP_Support::url($backend);
            $parts = wp_parse_url($backend);
            if ($parts['scheme'] !== 'https' || isset($parts['query']) || isset($parts['fragment'])
                || (isset($parts['path']) && !in_array($parts['path'], ['', '/'], true))
                || !is_string($token) || strlen($token) < 32 || preg_match('/[\x00-\x20\x7f]/', $token)) {
                return ['status' => 503];
            }
            $result = wp_remote_get(rtrim($backend, '/') . $endpoint, [
                'timeout' => 3, 'redirection' => 0, 'sslverify' => true,
                'limit_response_size' => 8192,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            ]);
            if (is_wp_error($result)) { return ['status' => 503]; }
            $status = wp_remote_retrieve_response_code($result);
            if ($status === 404) { return ['status' => 404]; }
            if ($status !== 200) { return ['status' => 503]; }
            $payload = json_decode(wp_remote_retrieve_body($result), true, 8, JSON_THROW_ON_ERROR);
            $destination = self::destination(is_array($payload) ? ($payload['destination_url'] ?? null) : null);
            if ($destination === null) { return ['status' => 404]; }
            return ['status' => 302, 'location' => $destination];
        } catch (Throwable $e) { return ['status' => 503]; }
    }

    public static function serve(): void {
        $response = self::response($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($response === null) { return; }
        nocache_headers();
        header('Cache-Control: no-store, private, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex');
        status_header($response['status']);
        if ($response['status'] === 405) { header('Allow: GET, HEAD'); }
        // The validated value is emitted without URL normalization or wp_safe_redirect's local-host restriction.
        if (isset($response['location'])) { header('Location: ' . $response['location'], true, 302); }
        exit;
    }
}
