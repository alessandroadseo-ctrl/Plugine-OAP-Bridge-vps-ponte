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
        if (!in_array($command, ['status','start','pause','resume','retry'], true)) { return new WP_Error('command', 'Comando non valido.', ['status'=>400]); }
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
        $size = isset($input['batch_size']) && is_string($input['batch_size']) ? $input['batch_size'] : '25';
        if ($command === 'start' && !in_array($size, ['10','25','50','100'], true)) {
            wp_send_json_error(['message'=>'Dimensione lotto non valida.'], 400);
        }
        $result = self::control($command, (int)$size);
        if (is_wp_error($result)) { wp_send_json_error(['message'=>$result->get_error_message()], 409); }
        wp_send_json_success($result);
    }

    /** Connection-scoped DB mutex covers status + command + cache across PHP workers. */
    public static function control(string $command, int $size = 25) {
        global $wpdb;
        $lock = 'oap_regen_' . substr(hash('sha256', DB_NAME . $wpdb->prefix), 0, 40);
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            return new WP_Error('busy', 'Controllo già in corso. Riprovare tra pochi secondi.');
        }
        try {
            return self::locked_control($command, $size);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function locked_control(string $command, int $size) {
        $old = get_option(self::CACHE, []);
        $current = OAP_Regeneration_Client::request();
        if (is_wp_error($current)) {
            return ['stale'=>true, 'campaign'=>$old['campaign'] ?? null, 'received_at'=>$old['received_at'] ?? null,
                'message'=>$current->get_error_message(), 'confirmed'=>false];
        }
        update_option(self::CACHE, ['campaign'=>$current, 'received_at'=>time()], false);
        // Resolve a lost acknowledgment by replaying the persisted idempotent command.
        // VPS2 must return its original receipt without executing it again.
        $pending_ack = get_option(self::PENDING, []);
        if ($command === 'status' && $pending_ack) {
            return ['campaign'=>$current, 'received_at'=>time(), 'stale'=>true, 'pending'=>true, 'confirmed'=>false,
                'message'=>'Comando in attesa di conferma. Verifica automatica in corso.'];
        }
        if ($command === 'retry' && $pending_ack) {
            $ack = OAP_Regeneration_Client::request($pending_ack['command'], $pending_ack['payload'], $pending_ack['key']);
            if (is_wp_error($ack) || ($pending_ack['command'] !== 'start' && $ack['id'] !== ($pending_ack['payload']['campaign_id'] ?? null))) {
                return ['campaign'=>$current, 'received_at'=>time(), 'stale'=>true, 'confirmed'=>false,
                    'message'=>'Esito comando ancora incerto. Verificare VPS2; nessun nuovo comando abilitato.'];
            }
            delete_option(self::PENDING);
            $current = OAP_Regeneration_Client::request();
            if (is_wp_error($current)) {
                return ['campaign'=>$old['campaign'] ?? null, 'received_at'=>$old['received_at'] ?? null,
                    'stale'=>true, 'confirmed'=>false, 'message'=>'Conferma ricevuta; stato corrente non disponibile.'];
            }
        }
        $snapshot = ['campaign'=>$current, 'received_at'=>time()];
        update_option(self::CACHE, $snapshot, false);
        if ($command !== 'status' && $command !== 'retry') {
            if (!in_array($command, ['start','pause','resume'], true) ||
                ($command === 'start' && !in_array($size, [10,25,50,100], true))) {
                return new WP_Error('command', 'Comando non valido.');
            }
            $allowed = ['start'=>['idle','completed','completed_with_errors'], 'pause'=>['running'], 'resume'=>['paused']];
            if (!in_array($current['state'], $allowed[$command], true)) {
                return new WP_Error('state', 'Comando non disponibile nello stato corrente. Aggiornare i dati.');
            }
            $payload = $command === 'start' ? ['batch_size'=>$size] : ['campaign_id'=>$current['id']];
            // Persist before sending. Lost ACKs must reuse the same key and exact payload.
            $pending = get_option(self::PENDING, []);
            if ($pending && (($pending['command'] ?? '') !== $command || ($pending['payload'] ?? []) !== $payload)) {
                return new WP_Error('pending', 'Un comando precedente ha esito incerto. Ripetere lo stesso comando con gli stessi parametri o verificare VPS2.');
            }
            if (!$pending) {
                $pending = ['command'=>$command, 'payload'=>$payload, 'key'=>wp_generate_uuid4()];
                if (!add_option(self::PENDING, $pending, '', false)) {
                    return new WP_Error('pending', 'Impossibile registrare il comando. Nessuna richiesta inviata.');
                }
            }
            $result = OAP_Regeneration_Client::request($command, $payload, $pending['key']);
            if (is_wp_error($result)) {
                return array_merge($snapshot, ['stale'=>true, 'confirmed'=>false,
                    'message'=>'Comando non confermato. ' . $result->get_error_message()]);
            }
            if ($command !== 'start' && $result['id'] !== $current['id']) {
                return array_merge($snapshot, ['stale'=>true, 'confirmed'=>false, 'message'=>'Campagna nella conferma non coerente.']);
            }
            delete_option(self::PENDING);
            $snapshot = ['campaign'=>$result, 'received_at'=>time()];
            update_option(self::CACHE, $snapshot, false);
        }
        return array_merge($snapshot, ['stale'=>false, 'confirmed'=>$command !== 'status' && $command !== 'retry', 'message'=>'']);
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('Accesso non consentito.', '', ['response'=>403]); }
        nocache_headers();
        wp_enqueue_script('oap-regeneration', plugins_url('../assets/regeneration.js', __FILE__), [], '1.4.0', true);
        wp_localize_script('oap-regeneration', 'oapRegeneration', [
            'url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('oap_regeneration'),
        ]);
        echo '<div class="wrap" id="oap-regeneration"><h1>Rigenerazione schede</h1><p>La rigenerazione viene eseguita esclusivamente da VPS2. La pausa ferma la programmazione di nuovi lotti; quelli già avviati possono terminare.</p>';
        echo '<p id="oap-regen-message" role="status" aria-live="polite">Caricamento stato VPS2…</p><p>Stato: <strong id="oap-regen-state">Non raggiungibile</strong></p>';
        echo '<div id="oap-regen-last"></div><table class="widefat striped"><tbody>';
        foreach (['total'=>'Totale schede','examined'=>'Schede esaminate','updated'=>'Schede aggiornate','unchanged'=>'Schede invariate','errors'=>'Errori','remaining'=>'Ancora da elaborare','started_at'=>'Avvio','updated_at'=>'Ultimo aggiornamento VPS2'] as $key=>$label) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td id="oap-regen-' . esc_attr($key) . '">—</td></tr>';
        }
        echo '</tbody></table><p><progress id="oap-regen-progress" max="100" value="0" aria-label="Avanzamento"></progress> <span id="oap-regen-percent">—</span></p>';
        echo '<label for="oap-regen-size">Prodotti per lotto </label><select id="oap-regen-size">';
        foreach ([10,25,50,100] as $size) { echo '<option value="' . esc_attr($size) . '"' . selected($size, 25, false) . '>' . esc_html($size) . '</option>'; }
        echo '</select><p><button type="button" class="button button-primary" data-command="start" disabled>Avvia rigenerazione</button> <button type="button" class="button" data-command="pause" disabled>Metti in pausa</button> <button type="button" class="button" data-command="resume" disabled>Riprendi</button></p><noscript>Attivare JavaScript per controllare VPS2.</noscript></div>';
    }
}
OAP_Regeneration_Admin::boot();
