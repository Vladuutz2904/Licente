<?php
/**
 * license_check.php
 *
 * Pune acest fisier in radacina panoului si fa require_once la el
 * in fisierul principal (ex. index.php / bootstrap), inainte de orice
 * alta logica.
 *
 * NU sterge fisiere. Daca licenta e invalida, afiseaza o pagina de blocare.
 */

// ---- CONFIGURARE ----
define('LICENSE_API_URL', 'https://NUME-PROIECT.vercel.app/api/check');
define('LICENSE_CACHE_FILE', __DIR__ . '/.license_cache.json');
define('LICENSE_CHECK_INTERVAL', 6 * 3600);      // verifica din 6 in 6 ore
define('LICENSE_GRACE_PERIOD', 4 * 24 * 3600);   // 4 zile toleranta daca serverul e jos
// ----------------------

function license_get_domain() {
    return $_SERVER['HTTP_HOST'] ?? 'unknown';
}

function license_load_cache() {
    if (!file_exists(LICENSE_CACHE_FILE)) return null;
    $data = json_decode(@file_get_contents(LICENSE_CACHE_FILE), true);
    return is_array($data) ? $data : null;
}

function license_save_cache($data) {
    @file_put_contents(LICENSE_CACHE_FILE, json_encode($data));
}

function license_remote_check($domain) {
    $url = LICENSE_API_URL . '?domain=' . urlencode($domain);
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null; // server indisponibil

    $json = json_decode($raw, true);
    if (!is_array($json)) return null;

    return $json; // { valid: bool, reason: ..., expires: ... }
}

function license_block_page($reason) {
    http_response_code(402);
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8">';
    echo '<title>Licenta inactiva</title></head><body style="font-family:sans-serif;text-align:center;padding:80px;">';
    echo '<h1>Acces suspendat</h1>';
    echo '<p>Panoul este temporar indisponibil din cauza unei licente inactive (' . htmlspecialchars($reason) . ').</p>';
    echo '<p>Contacteaza administratorul pentru reactivare. Datele tale nu au fost afectate.</p>';
    echo '</body></html>';
    exit;
}

function license_check_and_enforce() {
    $domain = license_get_domain();
    $cache  = license_load_cache();
    $now    = time();

    $needsRefresh = true;
    if ($cache && isset($cache['last_checked'])) {
        $needsRefresh = ($now - $cache['last_checked']) > LICENSE_CHECK_INTERVAL;
    }

    if ($needsRefresh) {
        $result = license_remote_check($domain);

        if ($result !== null) {
            // Am primit raspuns de la server - e sursa de adevar
            $cache = [
                'last_checked'   => $now,
                'last_success'   => $now,
                'valid'          => (bool) $result['valid'],
                'reason'         => $result['reason'] ?? null,
            ];
            license_save_cache($cache);
        } else {
            // Server indisponibil: pastram ultima stare cunoscuta, actualizam doar last_checked
            if ($cache) {
                $cache['last_checked'] = $now;
                license_save_cache($cache);
            } else {
                // Prima rulare si nu avem conexiune - permitem temporar (grace),
                // dar marcam ca "necunoscut" ca sa nu ramana permanent deblocat.
                $cache = [
                    'last_checked' => $now,
                    'last_success' => 0,
                    'valid'        => true,
                    'reason'       => 'no_connection_yet',
                ];
                license_save_cache($cache);
            }
        }
    }

    if (!$cache['valid']) {
        license_block_page($cache['reason'] ?? 'invalid');
    }

    // Grace period: daca de prea mult timp nu am mai avut raspuns bun de la server, blocam
    $lastSuccess = $cache['last_success'] ?? 0;
    if ($lastSuccess === 0 || ($now - $lastSuccess) > LICENSE_GRACE_PERIOD) {
        license_block_page('no_recent_server_contact');
    }
}

license_check_and_enforce();
