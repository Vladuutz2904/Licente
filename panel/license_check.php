<?php
declare(strict_types=1);

/**
 * Client de licenta GENERIC (pentru orice panou PHP) - un singur fisier.
 *  Pune-l in radacina panoului si adauga, primul lucru din fisierul principal:
 *      require_once __DIR__ . '/license_check.php';
 *
 *  - La prima intrare pe site nu exista licenta => esti trimis la licenta.php ca sa introduci cheia.
 *  - Panoul intreaba serverul de licente la fiecare LICENSE_CHECK_INTERVAL secunde.
 *    Ce faci in panoul de licente (dezactivezi / activezi / stergi / expira / prelungesti)
 *    se vede singur aici, fara sa umbli la fisiere.
 *  - Nu sterge nimic din panou/baza de date: doar blocheaza accesul.
 */

const LICENSE_API_URL        = 'https://licente-six.vercel.app/api/check';
const LICENSE_STORE_FILE     = __DIR__ . '/.license_store.php';
const LICENSE_CHECK_INTERVAL = 45;            // secunde intre verificari (schimbarile se vad in <= ~1 minut)
const LICENSE_RETRY_INTERVAL = 20;            // daca serverul nu raspunde, reincearca dupa atatea secunde
const LICENSE_GRACE_PERIOD   = 3 * 24 * 3600; // cat tolereaza panoul daca serverul de licente e cazut

// Acelasi secret ca LICENSE_SIGN_SECRET din Vercel -> Environment Variables.
// Lasa-l gol ('') daca nu ai activat semnarea pe server.
const LICENSE_SIGN_SECRET    = '';

function license_domain(): string
{
    $h = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'unknown'));
    $h = preg_replace('/:\d+$/', '', $h) ?? $h;
    return preg_replace('/^www\./', '', $h) ?? $h;
}

/** Stare salvata: [key, valid, reason, expires(ms|null), last_checked, last_success] */
function license_load(): array
{
    if (!is_file(LICENSE_STORE_FILE)) return [];
    $raw = (string) @file_get_contents(LICENSE_STORE_FILE);
    $pos = strpos($raw, "\n");
    $json = $pos === false ? '' : substr($raw, $pos + 1); // prima linie e un "exit" PHP, ca fisierul sa nu poata fi citit din browser
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function license_save(array $state): bool
{
    // prima linie (exit) blocheaza citirea fisierului din browser
    $ok = @file_put_contents(LICENSE_STORE_FILE, "<?php exit; ?>\n" . json_encode($state), LOCK_EX);
    return $ok !== false;
}

/** Verifica semnatura HMAC a raspunsului (daca LICENSE_SIGN_SECRET e setat). */
function license_verify_sig(array $json, string $key, string $domain): bool
{
    if (LICENSE_SIGN_SECRET === '') return true;
    if (!isset($json['sig']) || !is_string($json['sig']) || $json['sig'] === '') return false;

    $respKey    = (string) ($json['key']    ?? $key);
    $respDomain = (string) ($json['domain'] ?? $domain);
    if ($respKey !== $key || $respDomain !== $domain) return false;

    $expires = $json['expires'] ?? null;
    $payload = implode('|', [
        $respKey,
        $respDomain,
        !empty($json['valid']) ? '1' : '0',
        (string) ($json['reason'] ?? ''),
        $expires === null ? '' : (string) $expires,
    ]);
    $expected = hash_hmac('sha256', $payload, LICENSE_SIGN_SECRET);
    return hash_equals($expected, $json['sig']);
}

/** Intreaba serverul. Returneaza array (raspunsul JSON) sau null daca serverul nu raspunde. */
function license_remote(string $key, string $domain): ?array
{
    $url = LICENSE_API_URL . '?key=' . rawurlencode($key) . '&domain=' . rawurlencode($domain);
    $raw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "Accept: application/json\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
    }

    if (!is_string($raw) || $raw === '') return null;
    $json = json_decode($raw, true);
    // 500 / pagini de eroare de la Vercel => tratate ca "server indisponibil"
    if (!is_array($json) || !array_key_exists('valid', $json) || ($json['reason'] ?? '') === 'server_error' || ($json['reason'] ?? '') === 'kv_not_configured') {
        return null;
    }
    if (!license_verify_sig($json, $key, $domain)) {
        return null;
    }
    return $json;
}

/** Mesaje pentru utilizator. */
function license_reason_text(string $reason): string
{
    switch ($reason) {
        case 'revoked':           return 'Licenta este dezactivata.';
        case 'expired':           return 'Licenta a expirat.';
        case 'not_found':         return 'Cheia de licenta nu exista (sau a fost stearsa).';
        case 'domain_mismatch':   return 'Aceasta cheie este legata de alt domeniu.';
        case 'missing_key':       return 'Introdu cheia de licenta.';
        case 'no_server':         return 'Nu pot contacta serverul de licente. Incearca din nou peste putin timp.';
        case 'grace_over':        return 'Nu am putut verifica licenta de prea mult timp (serverul de licente nu raspunde).';
        case 'no_license':        return 'Panoul nu are inca o licenta. Introdu cheia ca sa il activezi.';
        default:                  return 'Licenta nu este valida.';
    }
}

/**
 * Starea curenta a licentei (cu reimprospatare automata de pe server).
 * Returneaza ['valid' => bool, 'reason' => string, 'expires' => ?int(ms), 'key' => string]
 */
function license_status(bool $forceRefresh = false): array
{
    $state = license_load();
    $key   = (string) ($state['key'] ?? '');
    if ($key === '') return ['valid' => false, 'reason' => 'no_license', 'expires' => null, 'key' => ''];

    $now = time();
    $last = (int) ($state['last_checked'] ?? 0);
    $needs = $forceRefresh || ($now - $last) >= LICENSE_CHECK_INTERVAL;

    if ($needs) {
        $res = license_remote($key, license_domain());
        if ($res !== null) {
            $state['valid']        = (bool) $res['valid'];
            $state['reason']       = (string) ($res['reason'] ?? ($res['valid'] ? 'ok' : 'invalid'));
            $state['expires']      = isset($res['expires']) ? (int) $res['expires'] : null;
            $state['last_checked'] = $now;
            $state['last_success'] = $now;
        } else {
            // server indisponibil: pastram ultima stare, reincercam peste putin
            $state['last_checked'] = $now - (LICENSE_CHECK_INTERVAL - LICENSE_RETRY_INTERVAL);
        }
        license_save($state);
    }

    $valid   = (bool) ($state['valid'] ?? false);
    $reason  = (string) ($state['reason'] ?? 'invalid');
    $expires = isset($state['expires']) && $state['expires'] ? (int) $state['expires'] : null;

    // expirare temporara verificata si local (merge chiar daca serverul e cazut)
    if ($valid && $expires !== null && $now * 1000 > $expires) {
        $valid = false;
        $reason = 'expired';
    }
    // perioada de gratie: daca serverul nu a mai raspuns bine de prea mult timp
    if ($valid && ($now - (int) ($state['last_success'] ?? 0)) > LICENSE_GRACE_PERIOD) {
        $valid = false;
        $reason = 'grace_over';
    }

    return ['valid' => $valid, 'reason' => $reason, 'expires' => $expires, 'key' => $key];
}

/** Activeaza o cheie noua (apelata din licenta.php). Returneaza [ok, mesaj]. */
function license_activate(string $key): array
{
    $key = strtoupper(trim($key));
    if (!preg_match('/^[A-Z0-9-]{8,40}$/', $key)) {
        return [false, 'Cheia nu are formatul corect (ex: CFTE-XXXX-XXXX-XXXX).'];
    }
    $res = license_remote($key, license_domain());
    if ($res === null) return [false, license_reason_text('no_server')];
    if (empty($res['valid'])) return [false, license_reason_text((string) ($res['reason'] ?? 'invalid'))];

    $saved = license_save([
        'key'          => $key,
        'valid'        => true,
        'reason'       => 'ok',
        'expires'      => isset($res['expires']) ? (int) $res['expires'] : null,
        'last_checked' => time(),
        'last_success' => time(),
    ]);
    if (!$saved) {
        return [false, 'Cheia e valida, dar nu pot salva in folderul panoului. Da permisiuni de scriere (chmod 755/775) folderului.'];
    }
    return [true, 'Licenta a fost activata.'];
}

/** Pagina de activare / blocare, autonoma (fara tema panoului). */
function license_page(string $message, string $error = ''): void
{
    http_response_code($error === '' ? 402 : 200);
    $h = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="ro"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Licenta</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0e0a08;color:#eee;font-family:system-ui,sans-serif}';
    echo 'form{background:#1a1310;border:1px solid #2c211c;border-radius:14px;padding:28px;width:min(92vw,380px)}h1{margin:0 0 6px;font-size:20px}';
    echo 'p{color:#b9a9a0;font-size:14px}input{width:100%;box-sizing:border-box;padding:11px;border-radius:9px;border:1px solid #3a2b25;background:#0d0908;color:#fff;font-size:15px;text-transform:uppercase;letter-spacing:.05em}';
    echo 'button{margin-top:14px;width:100%;padding:11px;border:0;border-radius:9px;background:#e6402c;color:#fff;font-weight:700;font-size:15px;cursor:pointer}';
    echo '.err{background:rgba(230,64,44,.15);color:#ff8a7a;padding:10px;border-radius:8px;margin:10px 0;font-size:13.5px}</style></head><body>';
    echo '<form method="post"><h1>Licenta panou</h1><p>' . $h($message) . '</p>';
    if ($error !== '') echo '<div class="err">' . $h($error) . '</div>';
    echo '<input name="license_key" placeholder="CFTE-XXXX-XXXX-XXXX" maxlength="40" required autofocus>';
    echo '<button type="submit">Activeaza licenta</button><p style="font-size:12px">Domeniu: ' . $h(license_domain()) . '</p></form></body></html>';
    exit;
}

/** Ruleaza la fiecare cerere. */
function license_gate(): void
{
    if (PHP_SAPI === 'cli') return;

    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['license_key'])) {
        [$ok, $msg] = license_activate((string) $_POST['license_key']);
        if ($ok) {
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'));
            exit;
        }
        $error = $msg;
    }

    $st = license_status();
    if ($st['valid'] && $error === '') return;

    license_page($st['reason'] === 'no_license' ? 'Introdu cheia de licenta ca sa activezi panoul.' : license_reason_text($st['reason']), $error);
}

license_gate();
