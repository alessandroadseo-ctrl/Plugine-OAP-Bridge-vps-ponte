<?php
defined('ABSPATH') || exit;

/** Outbound control only. Never receives, renders or publishes product documents. */
final class OAP_Regeneration_Client {
    public static function request(string $command = 'status', array $payload = [], string $key = '') {
        if (!in_array($command, ['status', 'start', 'pause', 'resume'], true)) {
            return new WP_Error('invalid_command', 'Comando non valido.');
        }
        $base = defined('OAP_REGENERATION_URL') ? OAP_REGENERATION_URL : '';
        $token = defined('OAP_REGENERATION_TOKEN') ? OAP_REGENERATION_TOKEN : '';
        $url = is_string($base) ? wp_parse_url($base) : false;
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) ||
            isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            !is_string($token) || strlen($token) < 32 || strlen($token) > 4096 || preg_match('/\s/', $token)) {
            return new WP_Error('configuration', 'Configurazione VPS2 mancante o non valida.');
        }
        $headers = ['Authorization'=>'Bearer ' . $token, 'Accept'=>'application/json'];
        if ($command !== 'status') {
            if (!preg_match('/^[a-f0-9-]{36}$/D', $key)) { return new WP_Error('invalid_key', 'Identificativo comando non valido.'); }
            $headers['Idempotency-Key'] = $key;
            $headers['Content-Type'] = 'application/json';
        }
        $args = [
            'method'=>$command === 'status' ? 'GET' : 'POST', 'headers'=>$headers,
            'timeout'=>12, 'redirection'=>0, 'sslverify'=>true,
            'limit_response_size'=>65536, 'reject_unsafe_urls'=>true,
        ];
        if ($command !== 'status') { $args['body'] = wp_json_encode((object)$payload); }
        $response = wp_safe_remote_request(rtrim($base, '/') . '/api/v1/regeneration/' . $command, $args);
        // Never expose response bodies, URLs, WP transport errors or credentials.
        if (is_wp_error($response)) { return new WP_Error('unreachable', 'VPS2 non raggiungibile.'); }
        if (wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
            return new WP_Error('http', 'VPS2 non ha confermato la richiesta.');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || ($body['ok'] ?? null) !== true || !isset($body['campaign']) ||
            ($command !== 'status' && ($body['request_id'] ?? '') !== $key)) {
            return new WP_Error('invalid_response', 'Conferma VPS2 non valida.');
        }
        $campaign = self::validate($body['campaign']);
        if (is_wp_error($campaign)) { return $campaign; }
        $expected = ['start'=>['running'], 'pause'=>['paused'], 'resume'=>['running']];
        if (isset($expected[$command]) && !in_array($campaign['state'], $expected[$command], true)) {
            return new WP_Error('invalid_response', 'Stato VPS2 non coerente con il comando.');
        }
        return $campaign;
    }

    /** Strict, allowlisted snapshot: no arbitrary upstream fields reach WP/browser. */
    public static function validate($data) {
        $error = new WP_Error('invalid_response', 'Stato VPS2 non valido.');
        if (!is_array($data) || !in_array($data['state'] ?? '', ['idle','running','paused','completed','completed_with_errors'], true)) { return $error; }
        $out = ['state'=>$data['state']];
        foreach (['total','examined','updated','unchanged','errors','remaining'] as $field) {
            if (!isset($data[$field]) || !is_int($data[$field]) || $data[$field] < 0 || $data[$field] > 2147483647) { return $error; }
            $out[$field] = $data[$field];
        }
        if ($out['examined'] !== $out['updated'] + $out['unchanged'] + $out['errors'] ||
            $out['total'] !== $out['examined'] + $out['remaining']) { return $error; }
        foreach (['started_at','updated_at'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/D', $value) || strtotime($value) === false)) { return $error; }
            $out[$field] = $value;
        }
        if ($out['state'] !== 'idle' && (!$out['started_at'] || !$out['updated_at'])) { return $error; }
        if (!isset($data['id']) || !is_string($data['id']) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $data['id'])) { return $error; }
        $out['id'] = $data['id'];
        if ($out['state'] === 'idle' && ($out['total'] !== 0 || $out['started_at'] !== null)) { return $error; }
        if (in_array($out['state'], ['completed','completed_with_errors'], true) && $out['remaining'] !== 0) { return $error; }
        if ($out['state'] === 'completed' && $out['errors'] !== 0) { return $error; }
        if ($out['state'] === 'completed_with_errors' && $out['errors'] === 0) { return $error; }
        return $out;
    }
}
