<?php
defined('ABSPATH') || exit;

/** Read-only views of the receiver's existing index and verified artifacts. */
final class OAP_Admin {
    const PAGE = 'oap-bridge-products';
    const PER_PAGE = 20;

    public static function register(): void {
        add_menu_page('OcchioAiPrezzi Bridge', 'OcchioAiPrezzi Bridge', 'manage_options', self::PAGE, [self::class, 'render'], 'dashicons-products');
        add_submenu_page(self::PAGE, 'Prodotti', 'Prodotti', 'manage_options', self::PAGE, [self::class, 'render']);
    }

    private static function document(array $row): ?array {
        try { return OAP_Storage::read($row['artifact']); }
        catch (Throwable $e) { return null; }
    }

    private static function title(array $row, ?array $document): string {
        return (string)($document['seo']['h1'] ?? $document['seo']['title'] ?? $row['gtin']);
    }

    /** Title searches scan verified artifacts in bounded batches; no duplicate index. */
    public static function listing(string $search, int $page): array {
        $db = new OAP_Storage();
        $counts = ['active'=>0, 'inactive'=>0];
        foreach ($db->rows("SELECT state, COUNT(*) AS n FROM `{$db->pages}` WHERE owner='oap-wp-1' GROUP BY state") as $row) {
            if (isset($counts[$row['state']])) { $counts[$row['state']] = (int)$row['n']; }
        }
        $total = array_sum($counts);
        $page = max(1, min($page, max(1, (int)ceil($total / self::PER_PAGE))));
        $items = []; $matched = 0; $unavailable = 0;
        if ($search === '') {
            $matched = $total;
            $items = $db->rows("SELECT * FROM `{$db->pages}` WHERE owner='oap-wp-1' ORDER BY gtin LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE));
            foreach ($items as &$item) {
                $document = self::document($item);
                if ($document === null) { ++$unavailable; }
                $item['title'] = self::title($item, $document);
            }
            unset($item);
            return compact('items', 'counts', 'matched', 'page', 'unavailable');
        }
        for ($offset = 0; $offset < $total; $offset += 100) {
            $rows = $db->rows("SELECT * FROM `{$db->pages}` WHERE owner='oap-wp-1' ORDER BY gtin LIMIT 100 OFFSET " . $offset);
            foreach ($rows as $row) {
                $document = self::document($row);
                if ($document === null) { ++$unavailable; }
                $row['title'] = self::title($row, $document);
                if ($search !== '' && stripos($row['title'], $search) === false && stripos($row['gtin'], $search) === false) { continue; }
                if ($matched >= ($page - 1) * self::PER_PAGE && count($items) < self::PER_PAGE) { $items[] = $row; }
                ++$matched;
            }
        }
        $last = max(1, (int)ceil($matched / self::PER_PAGE));
        if ($page > $last) { return self::listing($search, $last); }
        return compact('items', 'counts', 'matched', 'page', 'unavailable');
    }

    private static function url(array $args = []): string {
        return add_query_arg(array_merge(['page'=>self::PAGE], $args), admin_url('admin.php'));
    }

    private static function public_link(array $row): void {
        try { $url = OAP_Support::canonical(OAP_Support::slug($row['slug'])); }
        catch (Throwable $e) { echo 'URL non disponibile'; return; }
        echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a><br>';
        echo '<a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Visualizza sul sito</a>';
    }

    private static function date(array $row): string {
        return (int)$row['modified_at'] > 0 ? wp_date('d/m/Y H:i:s T', (int)$row['modified_at']) : 'Non disponibile';
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) { wp_die('Accesso non consentito.', '', ['response'=>403]); }
        echo '<div class="wrap"><h1>Prodotti — OcchioAiPrezzi Bridge</h1><p>Pagine ricevute dalla VPS Ponte. Consultazione in sola lettura.</p>';
        try {
            $gtin = isset($_GET['gtin']) && is_string($_GET['gtin']) ? wp_unslash($_GET['gtin']) : '';
            if ($gtin !== '') { self::detail(OAP_Support::gtin($gtin)); }
            else { self::table(); }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>Impossibile leggere i dati del Bridge.</p></div>';
        }
        echo '</div>';
    }

    private static function table(): void {
        $search = isset($_GET['s']) && is_string($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $data = self::listing($search, $page);
        echo '<p>Attive: ' . esc_html($data['counts']['active']) . ' · Disattivate: ' . esc_html($data['counts']['inactive']) . '</p>';
        if ($data['unavailable']) { echo '<div class="notice notice-warning"><p>Alcuni artefatti non sono leggibili: il titolo viene sostituito dal GTIN e la ricerca per titolo può essere incompleta.</p></div>'; }
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><label for="oap-search">Titolo o GTIN </label><input id="oap-search" name="s" type="search" value="' . esc_attr($search) . '"> <button class="button">Cerca</button></form>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Prodotto', 'GTIN', 'Stato', 'URL pubblico', 'Versione VPS', 'Ultimo aggiornamento (VPS)'] as $label) { echo '<th scope="col">' . esc_html($label) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($data['items'] as $row) {
            echo '<tr><td><a href="' . esc_url(self::url(['gtin'=>$row['gtin']])) . '">' . esc_html($row['title']) . '</a></td><td>' . esc_html($row['gtin']) . '</td><td>' . esc_html($row['state'] === 'active' ? 'Attivo' : 'Disattivato') . '</td><td>';
            self::public_link($row);
            echo '</td><td>' . esc_html($row['version']) . '</td><td>' . esc_html(self::date($row)) . '</td></tr>';
        }
        if (!$data['items']) { echo '<tr><td colspan="6">Nessuna pagina trovata.</td></tr>'; }
        echo '</tbody></table><p>' . esc_html($data['matched']) . ' risultati</p><nav aria-label="Paginazione prodotti">';
        $pages = max(1, (int)ceil($data['matched'] / self::PER_PAGE));
        foreach (['Prima'=>1, 'Precedente'=>$data['page']-1, 'Successiva'=>$data['page']+1, 'Ultima'=>$pages] as $label=>$number) {
            if ($number >= 1 && $number <= $pages && $number !== $data['page']) {
                echo '<a class="button" href="' . esc_url(self::url(['s'=>$search, 'paged'=>$number])) . '">' . esc_html($label) . '</a> ';
            }
        }
        echo '<span>Pagina ' . esc_html($data['page']) . ' di ' . esc_html($pages) . '</span></nav>';
    }

    private static function detail(string $gtin): void {
        $row = (new OAP_Storage())->page($gtin);
        echo '<p><a href="' . esc_url(self::url()) . '">Torna ai prodotti</a></p>';
        if (!$row || $row['owner'] !== 'oap-wp-1') { echo '<p>Pagina non trovata.</p>'; return; }
        $document = self::document($row);
        echo '<h2>' . esc_html(self::title($row, $document)) . '</h2>';
        self::public_link($row);
        echo '<h2>Metadati archivio</h2><pre>' . esc_html(wp_json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<p>Ultimo aggiornamento dichiarato dalla VPS: ' . esc_html(self::date($row)) . '</p>';
        if ($document === null) { echo '<p>Artefatto non disponibile o non integro.</p>'; return; }
        $metadata = $document; unset($metadata['html']);
        echo '<h2>Metadati ricevuti</h2><pre>' . esc_html(wp_json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        // No scripts, navigation, forms, same-origin privileges or external resource loads.
        $preview = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;"></head><body>' . wp_kses_post((string)($document['html'] ?? '')) . '</body></html>';
        echo '<h2>Anteprima HTML</h2><p>Anteprima isolata: risorse esterne disabilitate.</p><iframe title="Anteprima prodotto" sandbox="" referrerpolicy="no-referrer" style="width:100%;height:500px;border:1px solid #ccc" srcdoc="' . esc_attr($preview) . '"></iframe>';
    }
}
