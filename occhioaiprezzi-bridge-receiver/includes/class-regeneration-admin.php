<?php
defined('ABSPATH') || exit;

final class OAP_Regeneration_Admin {
    const CACHE = 'oap_regeneration_snapshot';
    const PENDING = 'oap_regeneration_pending';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu'], 20);
        add_action('wp_ajax_oap_regeneration', [self::class, 'ajax']);
    }

    public static function menu(): void {
        add_submenu_page(OAP_Admin::PAGE, 'Rigenerazione schede', 'Rigenerazione schede',
            'manage_options', 'oap-regeneration', [self::class, 'render']);
    }

    public static function authorize(string $method, string $command, string $nonce) {
        if (!current_user_can('manage_options')) { return new WP_Error('forbidden', 'Accesso non consentito.', ['status'=>403]); }
        if (!in_array($command, ['status','start','pause','resume'], true)) { return new WP_Error('command', 'Comando non valido.', ['status'=>400]); }
        if ($method !== ($command === 'status' ? 'GET' : 'POST')) { return new WP_Error('method', 'Metodo non consentito.', ['status'=>405]); }
        if (!wp_verify_nonce($nonce, 'oap_regeneration')) { return new WP_Error('nonce', 'Sessione scaduta. Ricaricare la pagina.', ['status'=>403]); }
        return true;
    }

    public static function ajax(): void {
        nocache_headers();
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $input = $method === 'POST' ? $_POST : $_GET;
        $command = isset($input['command']) && is_string($input['command']) ? wp_unslash($input['command']) : '';
        $nonce = isset($input['nonce']) && is_string($input['nonce']) ? wp_unslash($input['nonce']) : '';
        $auth = self::authorize($method, $command, $nonce);
        if (is_wp_error($auth)) { wp_send_json_error(['message'=>$auth->get_error_message()], $auth->get_error_data()['status']); }
        $key = isset($input['idempotency_key']) && is_string($input['idempotency_key']) ? wp_unslash($input['idempotency_key']) : '';
        $target = isset($input['campaign_id']) && is_string($input['campaign_id']) ? wp_unslash($input['campaign_id']) : '';
        $seq = $input['sequence'] ?? '0';
        if (!is_string($seq) || !preg_match('/\A[0-9]{1,18}\z/', $seq)) { wp_send_json_error(['message'=>'Sequenza non valida.'], 400); }
        $result = self::control($command, $key, $target === '' ? null : $target, (int)$seq);
        if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()], $result->get_error_data()['status'] ?? 503); }
        wp_send_json_success($result);
    }

    /** Admin only calls this after capability/nonce validation; no network I/O. */
    public static function control(string $action, string $key = '', $campaign_id = null, int $sequence = 0) {
        try {
            $worker = OAP_Regeneration_Client::worker();
            // Preserve uncertain 1.4.0 commands for manual reconciliation; never convert them.
            if (get_option(self::PENDING, [])) { throw new OAP_Failure('oap_regeneration_legacy_pending', 409); }
            return (new OAP_Regeneration_Store())->locked($worker, static function ($db, $row) use ($worker,$action,$key,$campaign_id,$sequence) {
                global $wpdb;
                $snapshot = json_decode($row['snapshot'], true);
                $pending = $db->pending($worker);
                $ttl = defined('OAP_REGENERATION_STALE_SECONDS') ? OAP_REGENERATION_STALE_SECONDS : 90;
                $ttl = is_int($ttl) ? max(30, min(3600, $ttl)) : 90;
                $stale = empty($snapshot['received_at']) || empty($snapshot['observed_at']) ||
                    time() - $snapshot['received_at'] > $ttl || time() - $snapshot['observed_at'] > $ttl;
                $last = $db->one($wpdb->prepare("SELECT receipt FROM `{$db->commands}` WHERE worker_id=%s ORDER BY sequence DESC LIMIT 1", $worker));
                $receipt = $last && $last['receipt'] ? json_decode($last['receipt'], true) : null;
                $result = $snapshot + ['campaign'=>null,'received_at'=>null];
                $result += ['stale'=>$stale,'pending'=>(bool)$pending,'confirmed'=>false,'message'=>'',
                    'synchronized'=>(int)$row['issued'] === ($snapshot['last_sequence'] ?? 0),
                    'receipt'=>$receipt ? array_intersect_key($receipt, array_flip(['outcome','error_code','sequence'])) : null];
                if ($action === 'status') { return $result; }
                if (!in_array($action, ['start','pause','resume'], true) || !OAP_Regeneration_Client::uuid($key) ||
                    ($campaign_id !== null && !OAP_Regeneration_Client::uuid($campaign_id))) { throw new OAP_Failure('oap_regeneration_command'); }
                $previous = $db->one($wpdb->prepare("SELECT * FROM `{$db->commands}` WHERE idempotency_key=%s", $key));
                if ($previous) {
                    $payload = json_decode($previous['payload'], true);
                    if ($previous['worker_id'] !== $worker || $payload['action'] !== $action ||
                        $payload['campaign_id'] !== $campaign_id || $payload['sequence'] !== $sequence + 1) {
                        throw new OAP_Failure('oap_regeneration_command_conflict', 409);
                    }
                    $result['message'] = 'Comando già registrato. In attesa dello stato VPS2.';
                    return $result;
                }
                $current = $snapshot['campaign'] ?? null;
                $allowed = ['start'=>['idle','completed'],'pause'=>['running'],'resume'=>['paused']];
                if ($stale || $pending || !$result['synchronized'] || !$current ||
                    $sequence !== ($snapshot['last_sequence'] ?? 0) || $campaign_id !== $current['campaign_id'] ||
                    !in_array($current['state'], $allowed[$action], true)) { throw new OAP_Failure('oap_regeneration_state', 409); }
                if ((int)$row['issued'] >= PHP_INT_MAX) { throw new OAP_Failure('oap_regeneration_cursor', 409); }
                $next = (int)$row['issued'] + 1;
                $command = ['command_id'=>wp_generate_uuid4(),'idempotency_key'=>$key,'sequence'=>$next,'action'=>$action,
                    'campaign_id'=>$campaign_id,'issued_at'=>time(),'expires_at'=>time()+600];
                $db->query($wpdb->prepare("INSERT INTO `{$db->commands}` (worker_id,sequence,command_id,idempotency_key,payload) VALUES (%s,%d,%s,%s,%s)",
                    $worker, $next, $command['command_id'], $key, wp_json_encode($command)));
                $db->query($wpdb->prepare("UPDATE `{$db->workers}` SET issued=%d WHERE worker_id=%s", $next, $worker));
                $result['pending'] = true; $result['synchronized'] = false;
                $result['message'] = 'Comando registrato. In attesa di conferma da VPS2.';
                return $result;
            });
        } catch (Throwable $e) {
            $messages = [
                'oap_regeneration_legacy_pending'=>'Comando 1.4.0 con esito incerto: riconciliare la ricevuta su VPS2 prima di usare il pannello.',
                'oap_regeneration_configuration'=>'Configurare token dedicato e identità worker per il polling VPS2.',
                'oap_regeneration_state'=>'Comando non disponibile: attendere uno stato VPS2 recente e la conferma del comando precedente.',
                'oap_regeneration_busy'=>'Controllo già in corso. Riprovare tra pochi secondi.',
            ];
            $error = OAP_Support::error($e);
            return new WP_Error($error->get_error_code(), $messages[$error->get_error_code()] ?? $error->get_error_message(), $error->get_error_data());
        }
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('Accesso non consentito.', '', ['response'=>403]); }
        nocache_headers();
        wp_enqueue_script('oap-regeneration', plugins_url('../assets/regeneration.js', __FILE__), [], '1.4.1', true);
        wp_localize_script('oap-regeneration', 'oapRegeneration', [
            'url'=>admin_url('admin-ajax.php'), 'storageKey'=>'oap-regeneration-' . get_current_blog_id() . '-' . get_current_user_id(), 'nonce'=>wp_create_nonce('oap_regeneration'),
        ]);
        echo '<div class="wrap" id="oap-regeneration"><h1>Rigenerazione schede</h1><p>La rigenerazione viene eseguita esclusivamente da VPS2. La pausa ferma la programmazione di nuovi lotti; quelli già avviati possono terminare.</p>';
        echo '<p id="oap-regen-message" role="status" aria-live="polite">Caricamento stato VPS2…</p><p>Stato: <strong id="oap-regen-state">Non raggiungibile</strong></p>';
        echo '<div id="oap-regen-last"></div><table class="widefat striped"><tbody>';
        foreach (['total'=>'Totale prodotti','examined'=>'Prodotti esaminati','updated'=>'Prodotti aggiornati','unchanged'=>'Prodotti invariati','errors'=>'Errori correnti (inclusi transitori)','terminal_errors'=>'Errori terminali','superseded'=>'Superati da aggiornamenti ordinari','remaining'=>'Prodotti rimanenti','created_at'=>'Avvio','observed_at'=>'Ultimo stato osservato da VPS2'] as $key=>$label) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td id="oap-regen-' . esc_attr($key) . '">—</td></tr>';
        }
        echo '</tbody></table><p><progress id="oap-regen-progress" max="100" value="0" aria-label="Avanzamento"></progress> <span id="oap-regen-percent">—</span></p>';
        echo '<p>I lotti sono configurati su VPS2. Gli errori correnti possono diminuire; l’avanzamento misura i prodotti con esito terminale.</p>';
        echo '<p><button type="button" class="button button-primary" data-command="start" disabled>Avvia rigenerazione</button> <button type="button" class="button" data-command="pause" disabled>Metti in pausa</button> <button type="button" class="button" data-command="resume" disabled>Riprendi</button></p><noscript>Attivare JavaScript per controllare VPS2.</noscript></div>';
    }
}
OAP_Regeneration_Admin::boot();
