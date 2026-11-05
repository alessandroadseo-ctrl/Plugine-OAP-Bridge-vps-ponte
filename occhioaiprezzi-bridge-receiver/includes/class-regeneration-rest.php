<?php
defined('ABSPATH') || exit;

final class OAP_Regeneration_REST {
    public static function register(): void {
        foreach (['poll','ack'] as $action) {
            register_rest_route('oap-bridge/v1', '/regeneration/' . $action, [
                'methods'=>'POST', 'permission_callback'=>[self::class,'permission'],
                'callback'=>static function ($request) use ($action) { return self::receive($request, $action); },
            ]);
        }
    }

    public static function permission(WP_REST_Request $r) {
        try {
            if (!is_ssl()) { throw new OAP_Failure('oap_https_required', 403); }
            OAP_Regeneration_Client::worker();
            $auth = $r->get_header('authorization');
            if (!is_string($auth) || !preg_match('/\ABearer ([\x21-\x7e]{32,4096})\z/i', $auth, $m) ||
                !hash_equals(OAP_REGENERATION_POLL_TOKEN, $m[1])) { throw new OAP_Failure('oap_unauthorized', 401); }
            if (strlen($r->get_body()) > 65536 || (int)$r->get_header('content-length') > 65536) { throw new OAP_Failure('oap_request_too_large', 413); }
            return true;
        } catch (Throwable $e) { return OAP_Support::error($e); }
    }

    public static function receive(WP_REST_Request $r, string $action) {
        try {
            if (strtolower(trim(explode(';', (string)$r->get_header('content-type'))[0])) !== 'application/json') {
                throw new OAP_Failure('oap_json_required', 415);
            }
            $data = json_decode($r->get_body(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['protocol'] ?? '') !== OAP_Regeneration_Client::PROTOCOL ||
                ($data['worker_id'] ?? '') !== OAP_Regeneration_Client::worker()) { throw new OAP_Failure('oap_regeneration_identity', 403); }
            $campaign = OAP_Regeneration_Client::campaign($data['campaign'] ?? null);
            if ($action === 'poll') {
                OAP_Regeneration_Client::keys($data, ['protocol','worker_id','last_sequence','observed_at','campaign']);
                if (!OAP_Regeneration_Client::integer($data['last_sequence']) || !OAP_Regeneration_Client::integer($data['observed_at']) ||
                    $data['observed_at'] > time() + 60) { throw new OAP_Failure('oap_regeneration_schema'); }
            } else {
                OAP_Regeneration_Client::keys($data, ['protocol','worker_id','command_id','idempotency_key','sequence','outcome','error_code','processed_at','campaign','receipt_hash']);
                if (!OAP_Regeneration_Client::uuid($data['command_id']) || !OAP_Regeneration_Client::uuid($data['idempotency_key']) ||
                    !OAP_Regeneration_Client::integer($data['sequence'], 1) || !OAP_Regeneration_Client::integer($data['processed_at']) ||
                    !is_string($data['receipt_hash']) || !preg_match('/\A[a-f0-9]{64}\z/', $data['receipt_hash']) ||
                    !in_array($data['outcome'], ['applied','rejected'], true) ||
                    ($data['outcome'] === 'applied' && $data['error_code'] !== null) ||
                    ($data['outcome'] === 'rejected' && !in_array($data['error_code'], ['command_expired','command_from_future','campaign_already_active','stale_campaign','invalid_transition'], true))) {
                    throw new OAP_Failure('oap_regeneration_schema');
                }
            }
            $result = (new OAP_Regeneration_Store())->locked($data['worker_id'], static function ($db, $row) use ($action,$data,$campaign) {
                global $wpdb;
                $base = ['protocol'=>OAP_Regeneration_Client::PROTOCOL,'worker_id'=>$data['worker_id']];
                if ($action === 'poll') {
                    $snapshot = json_decode($row['snapshot'], true);
                    // A lost ACK is recovered by redelivery, never inferred from the cursor.
                    if ($data['last_sequence'] > (int)$row['issued'] ||
                        $data['last_sequence'] < ($snapshot['last_sequence'] ?? 0) ||
                        $data['observed_at'] < ($snapshot['observed_at'] ?? 0)) { throw new OAP_Failure('oap_regeneration_cursor', 409); }
                    $db->save_snapshot($data['worker_id'], ['campaign'=>$campaign,'observed_at'=>$data['observed_at'],
                        'received_at'=>time(),'last_sequence'=>$data['last_sequence']]);
                    $pending = $db->pending($data['worker_id']);
                    return $base + ['command'=>$pending ? json_decode($pending['payload'], true) : null];
                }
                $command = $db->one($wpdb->prepare("SELECT * FROM `{$db->commands}` WHERE worker_id=%s AND sequence=%d", $data['worker_id'], $data['sequence']));
                if (!$command || $command['command_id'] !== $data['command_id'] || $command['idempotency_key'] !== $data['idempotency_key']) {
                    throw new OAP_Failure('oap_regeneration_command_conflict', 409);
                }
                $payload = json_decode($command['payload'], true);
                if ($data['outcome'] === 'applied' &&
                    (($campaign['state'] ?? '') !== ($payload['action'] === 'pause' ? 'paused' : 'running') ||
                    ($payload['action'] !== 'start' && $campaign['campaign_id'] !== $payload['campaign_id']) ||
                    ($payload['action'] === 'start' && $campaign['campaign_id'] === $payload['campaign_id']))) {
                    throw new OAP_Failure('oap_regeneration_command_conflict', 409);
                }
                if ($command['receipt'] !== null) {
                    // JSON object key order is immaterial, but no field can change.
                    if (json_decode($command['receipt'], true) != $data) { throw new OAP_Failure('oap_regeneration_receipt_conflict', 409); }
                } else {
                    $first = $db->pending($data['worker_id']);
                    if (!$first || $first['sequence'] !== $command['sequence']) { throw new OAP_Failure('oap_regeneration_cursor', 409); }
                    $db->query($wpdb->prepare("UPDATE `{$db->commands}` SET receipt=%s WHERE worker_id=%s AND sequence=%d", wp_json_encode($data), $data['worker_id'], $data['sequence']));
                }
                // Receipt campaign is historical: never replace the poll snapshot.
                return $base + array_intersect_key($data, array_flip(['command_id','idempotency_key','sequence','receipt_hash']));
            });
            $response = new WP_REST_Response($result, 200);
            $response->header('Cache-Control','no-store, private'); return $response;
        } catch (JsonException $e) { return OAP_Support::error(new OAP_Failure('oap_invalid_json')); }
        catch (Throwable $e) { return OAP_Support::error($e); }
    }
}
add_action('rest_api_init', [OAP_Regeneration_REST::class,'register']);
