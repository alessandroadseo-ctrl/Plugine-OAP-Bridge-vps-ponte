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
        foreach ([home_url('/'), site_url('/')] as $internal) {
            $internal_host = strtolower(rtrim((string)wp_parse_url($internal, PHP_URL_HOST), '.'));
            if ($internal_host !== '' && ($host === $internal_host || str_ends_with($host, '.' . $internal_host))) { return null; }
        }
        foreach (['comparaiprezzi.it', 'occhioaiprezzi.com', 'occhioaiprezzi.it'] as $internal) {
            if (str_ends_with($host, '.' . $internal)) { return null; }
        }
        if (in_array($host, ['localhost', 'comparaiprezzi.it', 'www.comparaiprezzi.it',
            'occhioaiprezzi.com', 'www.occhioaiprezzi.com', 'occhioaiprezzi.it', 'www.occhioaiprezzi.it'], true)
            || preg_match('/\.(local|localhost|internal|test|invalid|example|onion|home|lan)$/', $host)) { return null; }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with($host, '::ffff:') || str_starts_with($host, 'ff')
                || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int)explode('.', $host)[0] >= 224)) { return null; }
        } else {
            if (!preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host)
                || strlen($host) > 253 || !str_contains($host, '.') || preg_match('/\A[0-9]+\z/', substr($host, strrpos($host, '.') + 1))) { return null; }
            foreach (explode('.', $host) as $label) {
                if ($label === '' || strlen($label) > 63 || $label[0] === '-' || str_ends_with($label, '-')) { return null; }
            }
        }
        return $value; // Preserve tracking bytes, duplicate parameters and query order.
    }

    /** Resolve only on authenticated ingestion; never on a visitor click. Fail closed. */
    public static function public_host(string $url): bool {
        $host = trim((string)wp_parse_url($url, PHP_URL_HOST), '[]');
        if (filter_var($host, FILTER_VALIDATE_IP)) { return true; } // Already checked by destination().
        $addresses = @gethostbynamel($host);
        if (!$addresses) { return false; }
        foreach ($addresses as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || (int)explode('.', $ip)[0] >= 224) { return false; }
        }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if ($v6 === false) { return false; }
        foreach ($v6 as $record) {
            $ip = $record['ipv6'] ?? '';
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                || str_starts_with(strtolower($ip), '::ffff:') || str_starts_with(strtolower($ip), 'ff')) { return false; }
        }
        return true;
    }

    /** Return a response description so validation/transport can be tested without exit(). */
    public static function response(string $uri, string $method): ?array {
        $path = wp_parse_url($uri, PHP_URL_PATH);
        $base = rtrim((string)wp_parse_url(home_url('/compara'), PHP_URL_PATH), '/');
        if ($path === $base || $path === $base . '/') {
            $query = wp_parse_url($uri, PHP_URL_QUERY) ?? '';
            if (!preg_match('/\Aid=([A-Za-z0-9_-]{43})\z/', $query, $match)) { return ['status' => 404]; }
            $code = $match[1];
        } elseif (is_string($path) && str_starts_with($path, $base . '/')) {
            return ['status' => 404];
        } else { return null; }
        if (!in_array($method, ['GET', 'HEAD'], true)) { return ['status' => 405]; }
        try {
            global $wpdb;
            $db = new OAP_Storage();
            $row = $db->one($wpdb->prepare("SELECT destination_url FROM `{$db->affiliates}` WHERE code=%s AND active=1", $code));
            $destination = self::destination($row['destination_url'] ?? null);
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
