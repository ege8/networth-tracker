<?php
/**
 * Net Worth Tracker — backend kütüphanesi (tek kullanıcı, LAN)
 * index.php bu dosyayı require eder.
 *
 * Sekiz Vault'tan türetildi: çok kullanıcılı/auth sistemi, admin paneli ve
 * kâr dağıtımı kaldırıldı. Auth YOK — sadece LAN içinde erişilebilir olduğu
 * varsayılır (bkz. README.md).
 */

declare(strict_types=1);

/* =========================================================
 *  AYARLAR
 * ========================================================= */
const RATES_TTL   = 300;   // canlı kur önbelleği: 5 dk
const STOCKS_TTL  = 300;   // hisse fiyatı önbelleği: 5 dk
const CRYPTO_TTL  = 300;   // kripto fiyatı önbelleği: 5 dk
const HISTORY_MAX = 730;   // en fazla 2 yıllık günlük kayıt

const DATA_DIR     = __DIR__ . '/data';
const STATE_FILE   = DATA_DIR . '/state.json';
const RATES_FILE   = DATA_DIR . '/rates_cache.json';
const STOCKS_FILE  = DATA_DIR . '/stocks_cache.json';
const CRYPTO_FILE  = DATA_DIR . '/crypto_cache.json';

/* Sekiz Vault'tan kalan eski dosyalar — varsa ilk açılışta içe aktarılır. */
const LEGACY_FILES = [
    DATA_DIR . '/vault.json',
    DATA_DIR . '/vault_utku.json',
    DATA_DIR . '/vault_keith.json',
    DATA_DIR . '/vault_ege.json',
    DATA_DIR . '/vault_rosea.json',
];

/* ----------------------------------------------------------
 * JSON yanıt
 * -------------------------------------------------------- */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_fail(string $msg, int $code = 400): void {
    json_out(['ok' => false, 'error' => $msg], $code);
}

/* ----------------------------------------------------------
 * Varsayılan durum
 * -------------------------------------------------------- */
function default_state(): array {
    return [
        'usdRate'  => 46.1519,
        'eurRate'  => 49.80,
        'goldRate' => 6122,
        'xautRate' => 2650,   // 1 XAUT (~1 ons altın) USD fiyatı
        'btcRate'  => 0,      // 1 BTC USD fiyatı (canlı çekilir)
        'ethRate'  => 0,      // 1 ETH USD fiyatı (canlı çekilir)
        'goal'      => 100000,
        'goals'     => [],
        'assets'    => [],
        'gider'     => [],
        'gelir'     => [],
        'recurring' => [],
        'subscriptions' => [],
        'stocks'    => [],
        'history'   => new stdClass(),
        'history_rates' => new stdClass(),
        'items'     => [],
    ];
}

/* ----------------------------------------------------------
 * Depolama (tek dosya)
 * -------------------------------------------------------- */
function ensure_data_dir(): void {
    if (!is_dir(DATA_DIR)) {
        if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
            json_fail('data/ klasörü oluşturulamadı. Klasör izinlerini kontrol edin (chown 33:33 veya chmod 775).', 500);
        }
    }
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
}

function read_state(): array {
    ensure_data_dir();
    if (!file_exists(STATE_FILE)) {
        $s = null;
        // Sekiz Vault'tan kalan eski dosya varsa onu içe aktar (ilk bulunanı kullan)
        foreach (LEGACY_FILES as $legacyFile) {
            if (file_exists($legacyFile)) {
                $legacy = json_decode((string)file_get_contents($legacyFile), true);
                if (is_array($legacy)) {
                    $s = normalize_state($legacy);
                    break;
                }
            }
        }
        if ($s === null) $s = normalize_state([]);
        write_state($s);
    } else {
        $raw = file_get_contents(STATE_FILE);
        $j = json_decode((string)$raw, true);
        $s = normalize_state(is_array($j) ? $j : []);
    }
    if (apply_recurring($s)) {
        write_state($s);
    }
    return $s;
}

function write_state(array $s): bool {
    ensure_data_dir();
    if (empty($s['history'])) $s['history'] = new stdClass();
    $json = json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(STATE_FILE, $json, LOCK_EX) !== false;
}

function clean_id($v): string {
    return (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$v);
}

/* ----------------------------------------------------------
 * Durumu normalize et (kalıcılaştırmadan önce her zaman çalışır)
 * -------------------------------------------------------- */
function normalize_state(array $in): array {
    $def = default_state();
    $out = [];
    $out['usdRate']  = is_numeric($in['usdRate']  ?? null) ? (float)$in['usdRate']  : $def['usdRate'];
    $out['eurRate']  = is_numeric($in['eurRate']  ?? null) ? (float)$in['eurRate']  : $def['eurRate'];
    $out['goldRate'] = is_numeric($in['goldRate'] ?? null) ? (float)$in['goldRate'] : $def['goldRate'];
    $out['xautRate'] = is_numeric($in['xautRate'] ?? null) ? (float)$in['xautRate'] : $def['xautRate'];
    $out['btcRate']  = is_numeric($in['btcRate']  ?? null) ? (float)$in['btcRate']  : $def['btcRate'];
    $out['ethRate']  = is_numeric($in['ethRate']  ?? null) ? (float)$in['ethRate']  : $def['ethRate'];
    $out['goal']     = is_numeric($in['goal']     ?? null) ? max(0, (float)$in['goal']) : $def['goal'];

    /* Çoklu hedefler: her hedef için ad, hedef tutar (USD), varlık atanabilir. */
    $out['goals'] = [];
    foreach (($in['goals'] ?? []) as $g) {
        if (!is_array($g)) continue;
        $out['goals'][] = [
            'id'     => clean_id($g['id'] ?? '') ?: uniqid('g'),
            'name'   => mb_substr(trim((string)($g['name'] ?? '')), 0, 80),
            'target' => is_numeric($g['target'] ?? null) ? max(0, (float)$g['target']) : 0,
            'asset'  => clean_id($g['asset'] ?? ''),
        ];
    }
    // Eski tek hedef otomatik çoklu hedef listesine de yansıt (ilk geçiş için)
    if ($out['goal'] > 0 && count($out['goals']) === 0) {
        $out['goals'][] = ['id' => 'legacy', 'name' => 'Genel Hedef', 'target' => $out['goal'], 'asset' => ''];
    }

    $validCur = ['USD', 'TL', 'EUR', 'GOLD', 'XAUT', 'BTC', 'ETH'];

    /* Varlıklar: artık kilit yok — kullanıcı istediğini ekler/siler/adını değiştirir.
       Taban tutar (base) negatif olabilir → borç olarak yorumlanır (net worth'tan düşer).
       Eski Sekiz Vault verisindeki 'fixed' alanı yoksayılır (geriye dönük uyumluluk). */
    $out['assets'] = [];
    foreach (($in['assets'] ?? []) as $a) {
        if (!is_array($a)) continue;
        $out['assets'][] = [
            'id'   => clean_id($a['id'] ?? '') ?: uniqid('a'),
            'name' => mb_substr(trim((string)($a['name'] ?? '')), 0, 80),
            'base' => is_numeric($a['base'] ?? null) ? (float)$a['base'] : 0.0,
            'cur'  => in_array(($a['cur'] ?? 'USD'), $validCur, true) ? $a['cur'] : 'USD',
        ];
    }

    foreach (['gider', 'gelir'] as $k) {
        $out[$k] = [];
        foreach (($in[$k] ?? []) as $t) {
            if (!is_array($t)) continue;
            $date = (string)($t['date'] ?? '');
            $out[$k][] = [
                'date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d'),
                'desc'  => mb_substr(trim((string)($t['desc'] ?? '')), 0, 120),
                'amt'   => is_numeric($t['amt'] ?? null) ? (float)$t['amt'] : 0.0,
                'cur'   => (($t['cur'] ?? 'TL') === 'USD') ? 'USD' : 'TL',
                'asset' => clean_id($t['asset'] ?? ''),
                'log'   => !empty($t['log']),
            ];
        }
    }

    $out['recurring'] = [];
    foreach (($in['recurring'] ?? []) as $r) {
        if (!is_array($r)) continue;
        $start = (string)($r['start'] ?? '');
        $last  = (string)($r['last'] ?? '');
        $out['recurring'][] = [
            'id'    => clean_id($r['id'] ?? '') ?: uniqid('r'),
            'kind'  => (($r['kind'] ?? 'gider') === 'gelir') ? 'gelir' : 'gider',
            'desc'  => mb_substr(trim((string)($r['desc'] ?? '')), 0, 120),
            'amt'   => is_numeric($r['amt'] ?? null) ? (float)$r['amt'] : 0.0,
            'cur'   => (($r['cur'] ?? 'TL') === 'USD') ? 'USD' : 'TL',
            'asset' => clean_id($r['asset'] ?? ''),
            'day'   => min(31, max(1, (int)($r['day'] ?? 1))),
            'start' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : date('Y-m-d'),
            'last'  => preg_match('/^\d{4}-\d{2}$/', $last) ? $last : '',
        ];
    }

    /* Abonelikler: tracker'a düşmez, sadece takip + hatırlatma */
    $out['subscriptions'] = [];
    $validSubPeriod = ['monthly', 'yearly', 'weekly'];
    $validSubCat = ['müzik', 'video', 'depolama', 'üretkenlik', 'oyun', 'diğer'];
    foreach (($in['subscriptions'] ?? []) as $sub) {
        if (!is_array($sub)) continue;
        $period = (string)($sub['period'] ?? '');
        $cat = trim((string)($sub['category'] ?? ''));
        $out['subscriptions'][] = [
            'id'       => clean_id($sub['id'] ?? '') ?: uniqid('sub'),
            'name'     => mb_substr(trim((string)($sub['name'] ?? '')), 0, 80),
            'amt'      => is_numeric($sub['amt'] ?? null) ? (float)$sub['amt'] : 0.0,
            'cur'      => in_array(($sub['cur'] ?? 'TL'), $validCur, true) ? $sub['cur'] : 'TL',
            'period'   => in_array($period, $validSubPeriod, true) ? $period : 'monthly',
            'day'      => min(31, max(1, (int)($sub['day'] ?? 1))),
            'month'    => min(12, max(1, (int)($sub['month'] ?? 1))),
            'weekday'  => min(6, max(0, (int)($sub['weekday'] ?? 0))),
            'category' => in_array($cat, $validSubCat, true) ? $cat : 'diğer',
            'note'     => mb_substr(trim((string)($sub['note'] ?? '')), 0, 200),
            'active'   => !isset($sub['active']) || (bool)$sub['active'],
        ];
    }

    /* BIST hisseleri: sembol, lot, alış ₺, güncel ₺ */
    $out['stocks'] = [];
    foreach (($in['stocks'] ?? []) as $st) {
        if (!is_array($st)) continue;
        $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($st['symbol'] ?? '')));
        if ($sym === '' || strlen($sym) > 10) continue;
        $out['stocks'][] = [
            'id'       => clean_id($st['id'] ?? '') ?: uniqid('s'),
            'symbol'   => $sym,
            'lots'     => is_numeric($st['lots']     ?? null) ? max(0.0, (float)$st['lots'])     : 0.0,
            'buyPrice' => is_numeric($st['buyPrice'] ?? null) ? max(0.0, (float)$st['buyPrice']) : 0.0,
            'curPrice' => is_numeric($st['curPrice'] ?? null) ? max(0.0, (float)$st['curPrice']) : 0.0,
        ];
    }

    $hist = [];
    foreach ((array)($in['history'] ?? []) as $d => $v) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && is_numeric($v)) {
            $hist[(string)$d] = round((float)$v, 2);
        }
    }
    ksort($hist);
    if (count($hist) > HISTORY_MAX) {
        $hist = array_slice($hist, -HISTORY_MAX, null, true);
    }
    $out['history'] = $hist ?: new stdClass();

    /* Günlük snapshot'ların çekildiği kur değerleri (grafikte TL geçmişini
       doğru göstermek için). Aynı günün history girişiyle eşleştirilir. */
    $hr = [];
    foreach ((array)($in['history_rates'] ?? []) as $d => $v) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && is_numeric($v)) {
            $hr[(string)$d] = round((float)$v, 4);
        }
    }
    ksort($hr);
    $out['history_rates'] = $hr ?: new stdClass();

    /* Fiziksel eşyalar: bilgisayar, çevre birimleri, hatıra paralar, telefon... */
    $out['items'] = [];
    $validCat = ['Bilgisayar', 'Çevre Birimleri', 'Hatıra Paralar', 'Telefon', 'Diğer'];
    foreach (($in['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $cat = trim((string)($it['category'] ?? ''));
        $date = (string)($it['date'] ?? '');
        $out['items'][] = [
            'id'      => clean_id($it['id'] ?? '') ?: uniqid('i'),
            'name'    => mb_substr(trim((string)($it['name'] ?? '')), 0, 100),
            'category'=> in_array($cat, $validCat, true) ? $cat : 'Diğer',
            'current' => is_numeric($it['current'] ?? null) ? max(0.0, (float)$it['current']) : 0.0,
            'initial' => is_numeric($it['initial'] ?? null) ? max(0.0, (float)$it['initial']) : 0.0,
            'date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
            'parts'   => [],
        ];
    }

    /* Bilgisayar eşyalarının parçaları (mouse, klavye, monitör, ekran kartı...) */
    foreach ($out['items'] as $idx => &$it) {
        $parts = [];
        foreach ((($in['items'][$idx]['parts'] ?? $it['parts'] ?? []) ?: []) as $p) {
            if (!is_array($p)) continue;
            $parts[] = [
                'id'      => clean_id($p['id'] ?? '') ?: uniqid('p'),
                'name'    => mb_substr(trim((string)($p['name'] ?? '')), 0, 80),
                'current' => is_numeric($p['current'] ?? null) ? max(0.0, (float)$p['current']) : 0.0,
                'initial' => is_numeric($p['initial'] ?? null) ? max(0.0, (float)$p['initial']) : 0.0,
            ];
        }
        $it['parts'] = $parts;
    }
    unset($it);

    /* Bilgisayar kategorisinde parça toplamı, elle girilen değeri ezmez (fallback toplam) */
    foreach ($out['items'] as &$it) {
        if ($it['category'] === 'Bilgisayar' && count($it['parts']) > 0) {
            $sumCur = array_sum(array_map(fn($p) => (float)($p['current'] ?? 0), $it['parts']));
            $sumInit = array_sum(array_map(fn($p) => (float)($p['initial'] ?? 0), $it['parts']));
            if ($sumCur > 0 || $sumInit > 0) {
                $it['current'] = $sumCur;
                $it['initial'] = $sumInit;
            }
        }
    }
    unset($it);

    return $out;
}

/* ----------------------------------------------------------
 * Tekrarlayan işlemler
 * -------------------------------------------------------- */
function next_month(string $ym): string {
    return date('Y-m', (int)strtotime($ym . '-01 +1 month'));
}

function apply_recurring(array &$state): bool {
    $changed = false;
    $today = date('Y-m-d');
    $curMonth = date('Y-m');

    foreach ($state['recurring'] as &$r) {
        if ($r['amt'] == 0.0) continue;
        $startMonth = substr($r['start'], 0, 7);
        $m = ($r['last'] !== '') ? next_month($r['last']) : $startMonth;

        $guard = 0;
        while ($m <= $curMonth && $guard++ < 60) {
            $daysInMonth = (int)date('t', (int)strtotime($m . '-01'));
            $d = min((int)$r['day'], $daysInMonth);
            $due = $m . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);

            if ($due < $r['start']) {
                $r['last'] = $m; $changed = true;
                $m = next_month($m);
                continue;
            }
            if ($due > $today) break;

            $state[$r['kind']][] = [
                'date' => $due,
                'desc' => $r['desc'] !== '' ? $r['desc'] : 'Tekrarlayan',
                'amt'  => $r['amt'],
                'cur'  => $r['cur'],
                'asset'=> $r['asset'],
            ];
            $r['last'] = $m; $changed = true;
            $m = next_month($m);
        }
    }
    unset($r);
    return $changed;
}

/* ----------------------------------------------------------
 * Hesaplamalar (USD)
 * -------------------------------------------------------- */
function rate_to_usd(array $s, string $cur): float {
    $usd = (float)$s['usdRate'];
    if ($cur === 'USD') return 1.0;
    if ($usd <= 0) return 0.0;
    if ($cur === 'TL')   return 1.0 / $usd;
    if ($cur === 'EUR')  return ((float)$s['eurRate']) / $usd;
    if ($cur === 'GOLD') return ((float)$s['goldRate']) / $usd;
    if ($cur === 'XAUT') return (float)($s['xautRate'] ?? 0);
    if ($cur === 'BTC')  return (float)($s['btcRate'] ?? 0);
    if ($cur === 'ETH')  return (float)($s['ethRate'] ?? 0);
    return 0.0;
}

function asset_effective_usd(array $s, string $assetId): float {
    foreach ($s['assets'] as $a) {
        if ($a['id'] !== $assetId) continue;
        $v = ((float)$a['base']) * rate_to_usd($s, $a['cur']);
        foreach ($s['gelir'] as $t) if ($t['asset'] === $assetId && empty($t['log'])) $v += ((float)$t['amt']) * rate_to_usd($s, $t['cur']);
        foreach ($s['gider'] as $t) if ($t['asset'] === $assetId && empty($t['log'])) $v -= ((float)$t['amt']) * rate_to_usd($s, $t['cur']);
        return $v;
    }
    return 0.0;
}

function stocks_total_tl(array $s): float {
    $tl = 0.0;
    foreach (($s['stocks'] ?? []) as $st) {
        $tl += ((float)($st['lots'] ?? 0)) * ((float)($st['curPrice'] ?? 0));
    }
    return $tl;
}

/* Bir hedef için mevcut değeri hesapla. Atanmış varlık varsa onun değeri,
   yoksa toplam net worth kullanılır. */
function goal_current_usd(array $s, array $goal): float {
    if (!empty($goal['asset'])) {
        foreach (($s['assets'] ?? []) as $a) {
            if ($a['id'] === $goal['asset']) return asset_effective_usd($s, $a['id']);
        }
        return 0.0;
    }
    return compute_total_usd($s);
}

/* Basit hedef simülasyonu: aylık sabit katkı + yıllık getiri oranı ile
   hedefe kaç ayda ulaşılacağı ve toplam yatırım/getiri dağılımı. */
function simulate_goal(float $current, float $target, float $monthlyContribution, float $annualReturnPct): array {
    $target = max(0.0, $target);
    $current = (float)$current;
    if ($target <= 0) return ['months' => 0, 'totalContributed' => 0.0, 'totalReturn' => 0.0, 'final' => $current];
    if ($current >= $target) return ['months' => 0, 'totalContributed' => 0.0, 'totalReturn' => 0.0, 'final' => $current];
    if ($monthlyContribution < 0) $monthlyContribution = 0;

    $r = $annualReturnPct / 100.0 / 12.0; // aylık oran
    $balance = $current;
    $contributed = 0.0;
    $months = 0;
    $maxMonths = 50 * 12; // 50 yıl sınır

    while ($balance < $target && $months < $maxMonths) {
        $balance += $monthlyContribution;
        $contributed += $monthlyContribution;
        $balance *= (1.0 + $r);
        $months++;
    }

    $final = $balance;
    $totalReturn = max(0.0, $final - $current - $contributed);

    return [
        'months'           => $months,
        'years'            => round($months / 12.0, 1),
        'totalContributed' => round($contributed, 2),
        'totalReturn'      => round($totalReturn, 2),
        'final'            => round($final, 2),
        'reached'          => $balance >= $target,
    ];
}

/* Tarihsel USD/TRY kuru (Frankfurter ücretsiz API). */
function fetch_historical_usd_try(string $date): ?float {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $j = http_get_json("https://api.frankfurter.app/{$date}?from=USD&to=TRY", 10);
    $rate = $j['rates']['TRY'] ?? null;
    return is_numeric($rate) ? (float)$rate : null;
}

/* history_rates eksik tarihlerini Frankfurter'den doldur. */
function fill_history_rates(array &$state): int {
    $filled = 0;
    $rates = (array)$state['history_rates'];
    $history = (array)$state['history'];
    foreach (array_keys($history) as $d) {
        if (isset($rates[$d])) continue;
        $r = fetch_historical_usd_try($d);
        if ($r !== null && $r > 0) {
            $rates[$d] = round($r, 4);
            $filled++;
        }
    }
    ksort($rates);
    $state['history_rates'] = $rates;
    return $filled;
}

/* Net Worth = Varlıklar (pozitif efektif bakiyeler) − Borçlar (negatif efektif bakiyeler, mutlak değer). */
function compute_total_usd(array $s): float {
    $total = 0.0;
    foreach ($s['assets'] as $a) $total += asset_effective_usd($s, $a['id']);
    $total += stocks_total_tl($s) * rate_to_usd($s, 'TL');
    return $total;
}

function compute_items_total(array $s): array {
    $current = 0.0;
    $initial = 0.0;
    foreach (($s['items'] ?? []) as $it) {
        // Bilgisayar kategorisinde parça toplamı authoritative'dır
        $cur = (float)($it['current'] ?? 0);
        $ini = (float)($it['initial'] ?? 0);
        if (($it['category'] ?? '') === 'Bilgisayar' && !empty($it['parts'])) {
            $cur = array_sum(array_map(fn($p) => (float)($p['current'] ?? 0), $it['parts']));
            $ini = array_sum(array_map(fn($p) => (float)($p['initial'] ?? 0), $it['parts']));
        }
        $current += $cur;
        $initial += $ini;
    }
    return ['current' => $current, 'initial' => $initial, 'delta' => $current - $initial];
}

function add_snapshot(array &$state, float $usd): void {
    $today = date('Y-m-d');
    $hist = (array)$state['history'];
    $hist[$today] = round($usd, 2);
    ksort($hist);
    if (count($hist) > HISTORY_MAX) $hist = array_slice($hist, -HISTORY_MAX, null, true);
    $state['history'] = $hist;

    // O günün USD/TL kurunu da kaydet (TL geçmişini doğru göstermek için)
    $rates = (array)$state['history_rates'];
    $usdRate = (float)($state['usdRate'] ?? 0);
    if ($usdRate > 0) {
        $rates[$today] = round($usdRate, 4);
        ksort($rates);
        $state['history_rates'] = $rates;
    }
}

/* ----------------------------------------------------------
 * Canlı kur (önbellekli): TL/EUR/Altın
 * -------------------------------------------------------- */
function http_get_json(string $url, int $timeout = 8): ?array {
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'ignore_errors' => true,
                   'header' => "User-Agent: NetWorthTracker/1.0\r\nAccept: application/json\r\n"],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => 'NetWorthTracker/1.0',
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
        }
    }
    if (!is_string($raw) || $raw === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function tr_num($v): ?float {
    if (is_numeric($v)) return (float)$v;
    if (!is_string($v)) return null;
    $v = str_replace(['.', ','], ['', '.'], trim($v));
    return is_numeric($v) ? (float)$v : null;
}

function fetch_live_rates(): array {
    if (file_exists(RATES_FILE)) {
        $c = json_decode((string)file_get_contents(RATES_FILE), true);
        if (is_array($c) && (time() - ($c['ts'] ?? 0)) < RATES_TTL) {
            $c['cached'] = true;
            return $c;
        }
    }

    $usd = null; $eur = null; $gold = null; $source = [];

    $t = http_get_json('https://finans.truncgil.com/v4/today.json');
    if ($t) {
        $usd  = tr_num($t['USD']['Selling'] ?? $t['USD']['Buying'] ?? null);
        $eur  = tr_num($t['EUR']['Selling'] ?? $t['EUR']['Buying'] ?? null);
        $gold = tr_num($t['GRA']['Selling'] ?? $t['GRA']['Buying']
              ?? $t['gram-altin']['Satış'] ?? $t['gram-altin']['Alış'] ?? null);
        if ($usd)  $source[] = 'truncgil:usd';
        if ($eur)  $source[] = 'truncgil:eur';
        if ($gold) $source[] = 'truncgil:gold';
    }

    if (!$usd || !$eur) {
        $e = http_get_json('https://open.er-api.com/v6/latest/USD');
        $rates = $e['rates'] ?? null;
        if (is_array($rates)) {
            if (!$usd && is_numeric($rates['TRY'] ?? null)) { $usd = (float)$rates['TRY']; $source[] = 'er-api:usd'; }
            if (!$eur && $usd && is_numeric($rates['EUR'] ?? null) && (float)$rates['EUR'] > 0) {
                $eur = $usd / (float)$rates['EUR'];
                $source[] = 'er-api:eur';
            }
        }
    }

    $result = [
        'ok' => (bool)($usd || $eur || $gold),
        'usd' => $usd, 'eur' => $eur, 'gold' => $gold,
        'source' => implode(',', $source), 'ts' => time(), 'cached' => false,
    ];
    if ($result['ok']) {
        ensure_data_dir();
        @file_put_contents(RATES_FILE, json_encode($result), LOCK_EX);
    }
    return $result;
}

/* ----------------------------------------------------------
 * Kripto fiyatları (CoinGecko, önbellekli, API key gerektirmez)
 * BTC, ETH ve XAUT (tether-gold) için USD fiyatı döner.
 * -------------------------------------------------------- */
function fetch_crypto_rates(): array {
    if (file_exists(CRYPTO_FILE)) {
        $c = json_decode((string)file_get_contents(CRYPTO_FILE), true);
        if (is_array($c) && (time() - ($c['ts'] ?? 0)) < CRYPTO_TTL) {
            $c['cached'] = true;
            return $c;
        }
    }

    $url = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin,ethereum,tether-gold&vs_currencies=usd';
    $j = http_get_json($url);

    $btc  = tr_num($j['bitcoin']['usd']      ?? null);
    $eth  = tr_num($j['ethereum']['usd']     ?? null);
    $xaut = tr_num($j['tether-gold']['usd']  ?? null);

    $result = [
        'ok'   => (bool)($btc || $eth || $xaut),
        'btc'  => $btc, 'eth' => $eth, 'xaut' => $xaut,
        'source' => 'coingecko', 'ts' => time(), 'cached' => false,
    ];
    if ($result['ok']) {
        ensure_data_dir();
        @file_put_contents(CRYPTO_FILE, json_encode($result), LOCK_EX);
    }
    return $result;
}

/* ----------------------------------------------------------
 * BIST hisse fiyatları (Yahoo Finance, önbellekli)
 * -------------------------------------------------------- */
function fetch_stock_prices(array $symbols): array {
    $symbols = array_map(function ($s) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s));
    }, $symbols);
    $symbols = array_filter($symbols, function ($s) {
        return $s !== '' && strlen($s) <= 10;
    });
    $symbols = array_values(array_unique($symbols));
    if (!$symbols) return ['ok' => false, 'prices' => [], 'ts' => time(), 'cached' => false];

    $cache = [];
    if (file_exists(STOCKS_FILE)) {
        $c = json_decode((string)file_get_contents(STOCKS_FILE), true);
        if (is_array($c)) $cache = $c;
    }

    $now = time();
    $prices = [];
    $fetchedAny = false;
    $allCached = true;

    foreach ($symbols as $sym) {
        $hit = $cache[$sym] ?? null;
        if (is_array($hit) && ($now - ($hit['ts'] ?? 0)) < STOCKS_TTL && is_numeric($hit['price'] ?? null)) {
            $prices[$sym] = (float)$hit['price'];
            continue;
        }
        $allCached = false;
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
             . rawurlencode($sym) . '.IS?interval=1d&range=1d';
        $j = http_get_json($url);
        $p = $j['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
        if (is_numeric($p) && (float)$p > 0) {
            $prices[$sym] = (float)$p;
            $cache[$sym] = ['price' => (float)$p, 'ts' => $now];
            $fetchedAny = true;
        }
        // bulunamayan sembol sessizce atlanır; istemci "alınamadı" gösterir
    }

    if ($fetchedAny) {
        ensure_data_dir();
        @file_put_contents(STOCKS_FILE, json_encode($cache), LOCK_EX);
    }

    return [
        'ok'     => count($prices) > 0,
        'prices' => $prices,
        'ts'     => $now,
        'cached' => $allCached && count($prices) > 0,
    ];
}
