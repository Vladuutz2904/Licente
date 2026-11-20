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
/** Pagina de activare / blocare, in stilul VladuutzWeb (autonoma, fara dependinte externe de fisiere). */
function license_page(string $message, string $error = ''): void
{
    http_response_code($error === '' ? 402 : 200);
    $h = fn($t) => htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<title>Licenta panou · VladuutzWeb</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#04070c">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  @property --ang { syntax: '<angle>'; initial-value: 0deg; inherits: false; }
  :root {
    color-scheme: dark;
    --bg: #04070c; --card: rgba(16,22,34,.78); --border: rgba(120,160,210,.14);
    --border-strong: rgba(120,170,230,.3); --fg: #eaf2fb; --muted: #8a98ad;
    --cyan: #22e0f5; --blue: #1f8fe6; --orange: #ff8a1f; --ok: #2ee6b0; --danger: #ff5d6c;
    --brand: linear-gradient(135deg,#22e0f5,#1f8fe6);
    --font: 'Inter',-apple-system,'Segoe UI',sans-serif;
    --font-d: 'Montserrat','Inter',sans-serif;
  }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html, body { margin: 0; min-height: 100%; }
  body {
    min-height: 100vh; min-height: 100dvh; display: flex; align-items: center; justify-content: center;
    padding: 28px 20px; position: relative; overflow-x: hidden;
    background: radial-gradient(ellipse 80% 60% at 50% 0%, #0b1a2c 0%, transparent 70%), var(--bg);
    color: var(--fg); font-family: var(--font); -webkit-font-smoothing: antialiased;
  }
  .aurora { position: fixed; inset: 0; z-index: 0; pointer-events: none; overflow: hidden; }
  .aurora i { position: absolute; border-radius: 50%; filter: blur(80px); opacity: .5; }
  .aurora .a1 { width: 480px; height: 480px; background: #1f8fe6; top: -160px; left: -140px; }
  .aurora .a2 { width: 420px; height: 420px; background: #22e0f5; bottom: -180px; right: -120px; opacity: .3; }
  .wrap { position: relative; z-index: 1; width: 100%; max-width: 400px; }
  .tagline { display: inline-flex; align-items: center; gap: 7px; font-size: 10.5px; font-weight: 700; letter-spacing: .14em; color: var(--cyan); background: rgba(34,224,245,.08); border: 1px solid rgba(34,224,245,.2); padding: 5px 10px; border-radius: 99px; margin-bottom: 16px; }
  .tagline::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: var(--ok); box-shadow: 0 0 8px var(--ok); animation: blink 2s infinite; }
  @keyframes blink { 50% { opacity: .35; } }
  .card {
    position: relative; background: linear-gradient(180deg, rgba(20,28,43,.82), rgba(12,18,29,.9));
    backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px); border-radius: 22px;
    padding: 32px 30px 28px; box-shadow: 0 30px 90px rgba(0,0,0,.6), inset 0 1px 0 rgba(255,255,255,.05);
  }
  .card::before {
    content: ''; position: absolute; inset: 0; border-radius: inherit; padding: 1px; pointer-events: none;
    background: conic-gradient(from var(--ang), rgba(34,224,245,0), rgba(34,224,245,.75), rgba(31,143,230,0) 30%, rgba(255,138,31,.7) 55%, rgba(255,138,31,0) 70%, rgba(34,224,245,0));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor; mask-composite: exclude; animation: rotate-border 7s linear infinite;
  }
  @keyframes rotate-border { to { --ang: 360deg; } }
  .lock { width: 50px; height: 50px; margin-bottom: 16px; border-radius: 15px; display: grid; place-items: center; color: var(--cyan); background: rgba(34,224,245,.1); border: 1px solid rgba(34,224,245,.25); }
  .lock svg { width: 23px; height: 23px; stroke: currentColor; fill: none; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
  h1 { font-family: var(--font-d); font-size: 21px; font-weight: 700; letter-spacing: -.2px; margin: 0 0 6px; }
  .hint { color: var(--muted); font-size: 13.5px; margin: 0 0 22px; line-height: 1.5; }
  .field { margin-bottom: 14px; }
  .field label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 7px; font-weight: 500; }
  input {
    width: 100%; background: rgba(5,9,15,.7); border: 1px solid var(--border); color: var(--fg);
    padding: 12px 14px; border-radius: 12px; font-size: 15px; text-transform: uppercase; letter-spacing: .05em;
    transition: border-color .15s, box-shadow .15s;
  }
  input::placeholder { color: #4b586b; text-transform: none; letter-spacing: normal; }
  input:focus { outline: none; border-color: var(--cyan); box-shadow: 0 0 0 4px rgba(34,224,245,.12); }
  button {
    width: 100%; margin-top: 8px; border: none; border-radius: 12px; background: var(--brand);
    color: #04101a; font-weight: 700; font-size: 15px; padding: 13px; cursor: pointer;
    position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(31,143,230,.38);
    transition: transform .15s, box-shadow .2s; font-family: inherit;
  }
  button::after { content: ''; position: absolute; top: 0; left: -120%; width: 60%; height: 100%; background: linear-gradient(100deg, transparent, rgba(255,255,255,.45), transparent); transform: skewX(-20deg); transition: left .6s; }
  button:hover { transform: translateY(-1px); box-shadow: 0 14px 38px rgba(34,224,245,.42); }
  button:hover::after { left: 140%; }
  button:active { transform: scale(.97); }
  .err { margin-top: 14px; font-size: 13px; color: var(--danger); background: rgba(255,93,108,.09); border: 1px solid rgba(255,93,108,.25); border-radius: 10px; padding: 10px 12px; }
  .foot { margin-top: 18px; font-size: 11.5px; color: #4f5d72; text-align: center; letter-spacing: .02em; }
  .foot b { color: var(--muted); font-weight: 600; }
</style>
</head>
<body>
  <div class="aurora" aria-hidden="true"><i class="a1"></i><i class="a2"></i></div>
  <div class="wrap">
    <span class="tagline">LICENTA PANOU</span>
    <form class="card" method="post">
      <div class="lock"><svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V7a4 4 0 0 1 8 0v4M12 15v2"/></svg></div>
      <h1>Licenta panoului</h1>
      <p class="hint"><?= $h($message) ?></p>
      <?php if ($error !== ''): ?><div class="err"><?= $h($error) ?></div><?php endif; ?>
      <div class="field">
        <label for="license_key">Cheie licenta</label>
        <input id="license_key" name="license_key" placeholder="CFTE-XXXX-XXXX-XXXX" maxlength="40" required autofocus autocapitalize="characters" spellcheck="false">
      </div>
      <button type="submit">Activeaza licenta</button>
      <p class="foot">Domeniu: <b><?= $h(license_domain()) ?></b> · VladuutzWeb</p>
    </form>
  </div>
</body>
</html><?php
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
