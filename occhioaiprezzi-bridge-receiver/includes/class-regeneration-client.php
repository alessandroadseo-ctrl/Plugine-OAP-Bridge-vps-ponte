<?php
defined('ABSPATH') || exit;

/** Shared oap-regeneration-poll-1 validation. No outbound HTTP transport. */
final class OAP_Regeneration_Client {
    const PROTOCOL = 'oap-regeneration-poll-1';

    public static function worker(): string {
        $id = defined('OAP_REGENERATION_WORKER_ID') ? OAP_REGENERATION_WORKER_ID : '';
        $token = defined('OAP_REGENERATION_POLL_TOKEN') ? OAP_REGENERATION_POLL_TOKEN : '';
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $id) ||
            !is_string($token) || !preg_match('/\A[\x21-\x7e]{32,4096}\z/', $token) ||
            (defined('OAP_BRIDGE_TOKEN') && hash_equals((string)OAP_BRIDGE_TOKEN, $token)) ||
            (defined('OAP_REGENERATION_TOKEN') && hash_equals((string)OAP_REGENERATION_TOKEN, $token)) ||
            hash_equals((string)get_option(OAP_Auth::TOKEN_OPTION, ''), hash('sha256', $token))) {
            throw new OAP_Failure('oap_regeneration_configuration', 503);
        }
        return $id;
    }

    public static function integer($value, int $min = 0): bool {
        return is_int($value) && $value >= $min;
    }

    public static function uuid($value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $value);
    }

    public static function keys(array $data, array $keys): void {
        $actual = array_keys($data); sort($actual); sort($keys);
        if ($actual !== $keys) { throw new OAP_Failure('oap_regeneration_schema'); }
    }

    /** Preserve VPS2 counters; errors overlap pending items and may decrease. */
    public static function campaign($data): array {
        if (!is_array($data)) { throw new OAP_Failure('oap_regeneration_schema'); }
        if (($data['state'] ?? '') === 'idle') {
            self::keys($data, ['state','campaign_id']);
            if ($data['campaign_id'] !== null) { throw new OAP_Failure('oap_regeneration_schema'); }
            return $data;
        }
        self::keys($data, ['campaign_id','state','created_at','completed_at','checkpoint','total','examined','queued',
            'updated','unchanged','superseded','errors','remaining','to_examine','awaiting_publication']);
        if (!self::uuid($data['campaign_id']) || !in_array($data['state'], ['running','paused','completed'], true) ||
            !self::integer($data['created_at']) || ($data['completed_at'] !== null && !self::integer($data['completed_at'])) ||
            !is_string($data['checkpoint']) || !preg_match('/\A(?:[0-9]{14})?\z/', $data['checkpoint'])) {
            throw new OAP_Failure('oap_regeneration_schema');
        }
        foreach (['total','examined','queued','updated','unchanged','superseded','errors','remaining','to_examine','awaiting_publication'] as $field) {
            if (!self::integer($data[$field]) || $data[$field] > $data['total']) { throw new OAP_Failure('oap_regeneration_schema'); }
        }
        // Terminal errors are distinct from current errors (which also include retries).
        $terminal = $data['total'] - $data['remaining'];
        $success = $data['updated'] + $data['unchanged'] + $data['superseded'];
        if ($success > $terminal || $terminal - $success > $data['errors'] ||
            $data['examined'] !== $data['total'] - $data['to_examine'] ||
            $data['remaining'] !== $data['to_examine'] + $data['awaiting_publication'] ||
            ($data['state'] === 'completed' && ($data['remaining'] !== 0 || $data['completed_at'] === null))) {
            throw new OAP_Failure('oap_regeneration_schema');
        }
        return $data;
    }
}
