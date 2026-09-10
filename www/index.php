<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$api = $_GET['api'] ?? null;
if ($api !== null) {

    if ($api === 'cron') {
        // LAN içi, tek kullanıcı — auth gerekmez.
        $live   = fetch_live_rates();
        $crypto = fetch_crypto_rates();
        $st = read_state(); // tekrarlayanları da işler

        if (!empty($live['ok'])) {
            if ($live['usd'])  $st['usdRate']  = $live['usd'];
            if ($live['eur'])  $st['eurRate']  = $live['eur'];
            if ($live['gold']) $st['goldRate'] = $live['gold'];
        }
        if (!empty($crypto['ok'])) {
            if ($crypto['btc'])  $st['btcRate']  = $crypto['btc'];
            if ($crypto['eth'])  $st['ethRate']  = $crypto['eth'];
            if ($crypto['xaut']) $st['xautRate'] = $crypto['xaut'];
        }
        if (!empty($st['stocks'])) {
            $sp = fetch_stock_prices(array_map(function ($x) { return $x['symbol']; }, $st['stocks']));
            if (!empty($sp['ok'])) {
                foreach ($st['stocks'] as &$sx) {
                    if (isset($sp['prices'][$sx['symbol']])) $sx['curPrice'] = $sp['prices'][$sx['symbol']];
                }
                unset($sx);
            }
        }
        $total = compute_total_usd($st);
        add_snapshot($st, $total);
        write_state($st);
        json_out(['ok' => true, 'date' => date('Y-m-d'), 'total' => round($total, 2),
                   'rates_ok' => (bool)$live['ok'], 'crypto_ok' => (bool)$crypto['ok']]);
    }

    switch ($api) {

        case 'state':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                json_out(['ok' => true, 'state' => read_state()]);
            }
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $body = json_decode((string)file_get_contents('php://input'), true);
                if (!is_array($body)) json_fail('Geçersiz JSON gövdesi.');
                // Geçmişi istemciye ezdirme
                $current = read_state();
                $body['history'] = (array)$current['history'];
                $state = normalize_state($body);
                $generated = apply_recurring($state);
                if (!write_state($state)) json_fail('Kaydedilemedi. data/ klasörü yazılabilir mi?', 500);
                json_out(['ok' => true, 'generated' => $generated, 'state' => $generated ? $state : null]);
            }
            json_fail('Yöntem desteklenmiyor.', 405);
            break;

        case 'snapshot':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('POST gerekli.', 405);
            $body = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($body) || !is_numeric($body['usd'] ?? null)) json_fail('usd alanı gerekli.');
            $state = read_state();
            add_snapshot($state, (float)$body['usd']);
            write_state($state);
            json_out(['ok' => true, 'history' => $state['history']]);
            break;

        case 'rates':
            $live   = fetch_live_rates();
            $crypto = fetch_crypto_rates();
            json_out([
                'ok'     => (bool)($live['ok'] || $crypto['ok']),
                'usd'    => $live['usd'] ?? null,
                'eur'    => $live['eur'] ?? null,
                'gold'   => $live['gold'] ?? null,
                'btc'    => $crypto['btc'] ?? null,
                'eth'    => $crypto['eth'] ?? null,
                'xaut'   => $crypto['xaut'] ?? null,
                'ts'     => max($live['ts'] ?? 0, $crypto['ts'] ?? 0),
                'cached' => !empty($live['cached']) && !empty($crypto['cached']),
            ]);
            break;

        case 'stockprices': {
            $state = read_state();
            $syms = array_map(function ($s) { return $s['symbol']; }, $state['stocks'] ?? []);
            if (!$syms) json_fail('Kayıtlı hisse yok.');
            $res = fetch_stock_prices($syms);
            if ($res['ok']) {
                foreach ($state['stocks'] as &$st) {
                    if (isset($res['prices'][$st['symbol']])) {
                        $st['curPrice'] = $res['prices'][$st['symbol']];
                    }
                }
                unset($st);
                write_state($state);
            }
            json_out($res + ['stocks' => $state['stocks']]);
            break;
        }

        case 'fillrates':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('POST gerekli.', 405);
            $state = read_state();
            $filled = fill_history_rates($state);
            if ($filled > 0) write_state($state);
            json_out(['ok' => true, 'filled' => $filled, 'history_rates' => $state['history_rates']]);
            break;

        case 'simulate': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('POST gerekli.', 405);
            $body = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($body)) json_fail('Geçersiz JSON gövdesi.');
            $state = read_state();
            $goalId = (string)($body['goalId'] ?? '');
            $monthly = is_numeric($body['monthly'] ?? null) ? (float)$body['monthly'] : 0.0;
            $returnPct = is_numeric($body['returnPct'] ?? null) ? (float)$body['returnPct'] : 0.0;

            $goal = null;
            if ($goalId !== '') {
                foreach (($state['goals'] ?? []) as $g) if ($g['id'] === $goalId) { $goal = $g; break; }
            }
            if ($goal === null) $goal = ['id' => '', 'name' => 'Genel Hedef', 'target' => (float)($state['goal'] ?? 0), 'asset' => ''];

            $current = goal_current_usd($state, $goal);
            $result = simulate_goal($current, (float)($goal['target'] ?? 0), $monthly, $returnPct);
            json_out(['ok' => true, 'goal' => $goal, 'current' => $current, 'result' => $result]);
            break;
        }

        case 'export':
            $state = read_state();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="networth-' . date('Y-m-d') . '.json"');
            echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;

        case 'import':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('POST gerekli.', 405);
            if (empty($_FILES['file']['tmp_name'])) json_fail('Dosya alınamadı.');
            if (($_FILES['file']['size'] ?? 0) > 1024 * 1024) json_fail('Dosya çok büyük (en fazla 1 MB).');
            $raw = file_get_contents($_FILES['file']['tmp_name']);
            $j = json_decode((string)$raw, true);
            if (!is_array($j) || !isset($j['assets'])) json_fail('Geçersiz yedek dosyası: assets alanı bulunamadı.');
            $state = normalize_state($j);
            if (!write_state($state)) json_fail('Kaydedilemedi.', 500);
            json_out(['ok' => true, 'state' => $state]);
            break;

        default:
            json_fail('Bilinmeyen uç nokta.', 404);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#120A0F">
<title>Net Worth Tracker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,500&family=Outfit:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{
  --bg:#120A0F; --card:#1C1117; --card-2:#241620; --line:#3A2530;
  --rose:#E8AFC0; --rose-deep:#C4798F; --gold:#D9B98A; --ivory:#F4ECEA;
  --muted:#9A7E88; --green:#9BD4A8; --red:#E08A8A; --radius:14px;
}
*{margin:0;padding:0;box-sizing:border-box}
body{
  background:var(--bg); color:var(--ivory);
  font-family:'Outfit',sans-serif; font-weight:300; min-height:100vh;
  background-image:
    radial-gradient(ellipse 80% 50% at 50% -10%, rgba(196,121,143,.14), transparent),
    radial-gradient(ellipse 40% 30% at 90% 10%, rgba(217,185,138,.06), transparent);
  -webkit-tap-highlight-color:transparent;
}
.wrap{max-width:940px;margin:0 auto;padding:0 18px calc(70px + env(safe-area-inset-bottom))}

/* ---------- header ---------- */
header{padding:28px 0 20px;border-bottom:1px solid var(--line)}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.brand h1{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:clamp(34px,7vw,46px);line-height:1}
.brand h1 em{font-style:italic;color:var(--rose)}
.brand p{color:var(--muted);font-size:12px;margin-top:5px;letter-spacing:.14em;text-transform:uppercase}
.rates{margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(108px,1fr));gap:8px}
.rate{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:9px 12px;min-width:0}
.rate label{display:block;font-size:10.5px;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;margin-bottom:3px;white-space:nowrap}
.rate input{background:none;border:none;color:var(--gold);outline:none;width:100%;
  font-family:'JetBrains Mono',monospace;font-size:15px;font-weight:500}
.rate input:focus{color:var(--ivory)}
.live-btn{background:var(--card-2);border:1px solid var(--line);border-radius:var(--radius);
  color:var(--rose);cursor:pointer;padding:9px 10px;font-family:'Outfit',sans-serif;
  font-size:12.5px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;transition:all .2s;min-width:0}
.live-btn:hover{border-color:var(--rose-deep)}
.live-btn small{color:var(--muted);font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.live-btn.loading{opacity:.6;pointer-events:none}

/* ---------- hero ---------- */
.hero{padding:36px 0 30px;text-align:center}
.hero .label{font-size:11.5px;letter-spacing:.22em;text-transform:uppercase;color:var(--muted)}
.hero .big{font-family:'Cormorant Garamond',serif;font-weight:500;
  font-size:clamp(46px,12vw,84px);line-height:1.05;margin:8px 0 4px;font-variant-numeric:tabular-nums;
  overflow-wrap:anywhere}
.hero .big .cur{color:var(--rose);font-size:.55em;vertical-align:.28em;margin-right:.1em}
.hero .sub{font-family:'JetBrains Mono',monospace;font-size:13.5px;color:var(--muted)}
.hero .sub b{color:var(--gold);font-weight:500}
.chips{margin-top:14px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;align-items:center}
.chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--card);
  border-radius:99px;padding:6px 14px;font-family:'JetBrains Mono',monospace;font-size:12.5px;color:var(--muted)}
.chip.up{color:var(--green);border-color:rgba(155,212,168,.3)}
.chip.down{color:var(--red);border-color:rgba(224,138,138,.3)}
.chip svg{display:block}
.toggle{display:inline-flex;border:1px solid var(--line);border-radius:99px;overflow:hidden}
.toggle button{background:none;border:none;color:var(--muted);padding:7px 20px;cursor:pointer;
  font-family:'Outfit',sans-serif;font-size:13px;letter-spacing:.06em;transition:all .2s;min-height:34px}
.toggle button.on{background:var(--rose);color:#2A0F1A;font-weight:500}
.toggle.small button{padding:5px 14px;font-size:12px;min-height:28px}

/* ---------- hedef ---------- */
.goal{max-width:520px;margin:26px auto 0;text-align:left}
.goal-top{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:8px;flex-wrap:wrap}
.goal-top .t{font-size:11.5px;letter-spacing:.18em;text-transform:uppercase;color:var(--muted)}
.goal-top .v{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--ivory)}
.goal-top .v b{color:var(--rose);font-weight:500}
.goal-bar{height:10px;background:#2A1A22;border-radius:99px;overflow:hidden;position:relative}
.goal-bar i{display:block;height:100%;border-radius:99px;
  background:linear-gradient(90deg,var(--rose-deep),var(--rose),var(--gold));
  transition:width .6s cubic-bezier(.4,0,.2,1)}
.goal-eta{margin-top:9px;font-size:12.5px;color:var(--muted);line-height:1.5}
.goal-eta b{color:var(--rose);font-weight:500}
.goal-eta .date{font-family:'JetBrains Mono',monospace;color:var(--gold)}
.goal-eta.slow b{color:var(--red)}
.goal-edit{margin-top:8px;display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--muted)}
.goal-edit input{background:var(--card);border:1px solid var(--line);border-radius:8px;color:var(--gold);
  font-family:'JetBrains Mono',monospace;font-size:14px;padding:6px 10px;width:130px;outline:none;text-align:right}
.goal-edit input:focus{border-color:var(--rose-deep)}

/* ---------- çoklu hedefler + simülasyon ---------- */
.goals-section{max-width:680px;margin:32px auto 0;text-align:left}
.goal-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin-bottom:12px}
.goal-card .top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.goal-card .top input.name{background:none;border:none;border-bottom:1px dashed transparent;color:var(--ivory);
  font-family:'Outfit',sans-serif;font-size:17px;outline:none;flex:1;min-width:140px}
.goal-card .top input.name:hover,.goal-card .top input.name:focus{border-bottom-color:var(--rose-deep)}
.goal-card .top .target{display:flex;align-items:center;gap:6px;color:var(--gold);font-family:'JetBrains Mono',monospace;font-size:14px}
.goal-card .top .target input{width:110px;background:var(--card-2);border:1px solid var(--line);border-radius:8px;color:var(--gold);
  font-family:'JetBrains Mono',monospace;font-size:14px;padding:5px 8px;text-align:right;outline:none}
.goal-card .top .target input:focus{border-color:var(--rose-deep)}
.goal-card .bar{height:10px;background:#2A1A22;border-radius:99px;overflow:hidden;position:relative;margin-bottom:10px}
.goal-card .bar i{display:block;height:100%;border-radius:99px;
  background:linear-gradient(90deg,var(--rose-deep),var(--rose),var(--gold));
  transition:width .6s cubic-bezier(.4,0,.2,1)}
.goal-card .meta{font-size:12.5px;color:var(--muted);line-height:1.6}
.goal-card .meta b{color:var(--ivory);font-weight:500}
.goal-card .meta .pos{color:var(--green)} .goal-card .meta .neg{color:var(--red)}
.goal-card .sim{background:rgba(217,185,138,.06);border:1px solid var(--line);border-radius:12px;padding:14px;margin-top:12px}
.goal-card .sim-title{font-size:12px;color:var(--gold);text-transform:uppercase;letter-spacing:.12em;margin-bottom:10px}
.goal-card .sim-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:10px}
.goal-card .sim-grid label{display:block;font-size:11px;color:var(--muted);margin-bottom:4px}
.goal-card .sim-grid input{width:100%;background:var(--card);border:1px solid var(--line);border-radius:8px;color:var(--ivory);
  font-family:'JetBrains Mono',monospace;font-size:14px;padding:6px 8px;outline:none}
.goal-card .sim-grid input:focus{border-color:var(--rose-deep)}
.goal-card .sim-result{font-size:12.5px;color:var(--muted);line-height:1.7;margin-top:8px}
.goal-card .sim-result b{color:var(--rose);font-weight:500}
.goal-card .sim-result .date{color:var(--gold);font-family:'JetBrains Mono',monospace}
.goal-card .del-goal{background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px;padding:6px;border-radius:8px}
.goal-card .del-goal:hover{color:var(--red);background:#2C1A25}
.goal-card .asset-sel{background:var(--card-2);border:1px solid var(--line);border-radius:8px;color:var(--gold);
  font-family:'Outfit',sans-serif;font-size:12px;padding:5px 8px;outline:none;cursor:pointer;margin-left:8px}
.goal-actions{display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap}
.goal-actions .mini-btn{font-size:12px;padding:7px 12px}
.fillrates-note{font-size:12px;color:var(--muted);margin-top:8px;font-style:italic}

/* ---------- sections ---------- */
section{margin-top:38px}
.sec-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:14px;gap:10px;flex-wrap:wrap}
.sec-head h2{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:25px}
.sec-head h2::after{content:"";display:block;width:34px;height:2px;background:var(--rose-deep);margin-top:6px;border-radius:2px}
.btn{background:var(--card-2);border:1px solid var(--line);color:var(--rose);border-radius:99px;
  padding:9px 18px;font-size:13.5px;cursor:pointer;font-family:'Outfit',sans-serif;transition:all .2s;
  min-height:40px;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.btn:hover{border-color:var(--rose-deep);background:#2C1A25}
.btn:focus-visible,.toggle button:focus-visible,input:focus-visible,select:focus-visible{outline:2px solid var(--rose);outline-offset:2px}

/* ---------- grafik ---------- */
.chart-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px}
.chart-wrap{position:relative;height:240px;margin-top:4px}
.chart-empty{color:var(--muted);font-size:13px;text-align:center;padding:60px 0;font-style:italic}

/* ---------- varlıklar ---------- */
.assets{display:flex;flex-direction:column;gap:10px}
.asset{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  padding:13px 16px;display:grid;grid-template-columns:1fr auto;gap:5px 12px;
  align-items:center;transition:border-color .2s;position:relative;overflow:hidden}
.asset:hover{border-color:#4A2F3D}
.asset.is-debt{border-color:rgba(224,138,138,.35)}
.asset .name{display:flex;align-items:center;gap:7px;min-width:0}
.asset .name input{background:none;border:none;color:var(--ivory);font-family:'Outfit',sans-serif;
  font-size:16px;outline:none;width:100%;min-width:0}
.asset .debt-tag{font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:var(--red);
  border:1px solid rgba(224,138,138,.4);border-radius:99px;padding:1px 7px;flex:none}
.asset .eff{justify-self:end;font-family:'JetBrains Mono',monospace;font-size:14.5px;color:var(--ivory);white-space:nowrap}
.asset .eff.neg{color:var(--red)}
.asset .row2{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:wrap}
.asset .val{display:flex;align-items:center;gap:5px}
.asset .val input{background:none;border:none;border-bottom:1px dashed transparent;color:var(--gold);
  outline:none;font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:500;text-align:right;width:118px}
.asset .val input:hover,.asset .val input:focus{border-bottom-color:var(--rose-deep)}
.asset select.cur{background:var(--card-2);border:1px solid var(--line);border-radius:8px;color:var(--gold);
  font-family:'JetBrains Mono',monospace;font-size:13px;padding:5px 6px;outline:none;cursor:pointer}
.asset select.cur:focus{border-color:var(--rose-deep)}
.asset .meta{font-size:11px;color:var(--muted);font-family:'JetBrains Mono',monospace;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.asset .meta .pos{color:var(--green)} .asset .meta .neg{color:var(--red)}
.asset .actions{justify-self:end;display:flex;align-items:center;gap:4px}
.asset .del{background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px;
  padding:8px;border-radius:8px;transition:all .2s;line-height:1}
.asset .del:hover{color:var(--red);background:#2C1A25}
.asset .bar{grid-column:1 / -1;height:4px;background:#2A1A22;border-radius:99px;overflow:hidden;margin-top:3px}
.asset .bar i{display:block;height:100%;border-radius:99px;
  background:linear-gradient(90deg,var(--rose-deep),var(--rose));transition:width .5s cubic-bezier(.4,0,.2,1)}
.asset-totals{margin-top:12px;padding:12px 14px;background:var(--card);border:1px solid var(--line);
  border-radius:var(--radius);display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px 18px;font-size:13px}
.asset-totals .t{color:var(--muted)}
.asset-totals .v{font-family:'JetBrains Mono',monospace;color:var(--ivory)}
.asset-totals .v.pos{color:var(--green)} .asset-totals .v.neg{color:var(--red)}
.asset-totals .net{border-left:1px solid var(--line);padding-left:18px}
.asset-totals .net .v{color:var(--rose);font-weight:500}
@media(max-width:520px){.asset-totals .net{border-left:none;padding-left:0}}

/* ---------- özet ---------- */
.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
.sum-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px}
.sum-title{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:14px}
.month-label{color:var(--rose);text-transform:none;letter-spacing:0}
.donut-wrap{display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.donut-wrap svg{flex:0 0 auto}
.legend{display:flex;flex-direction:column;gap:7px;flex:1;min-width:120px}
.legend .li{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ivory)}
.legend .dot{width:10px;height:10px;border-radius:3px;flex:0 0 auto}
.legend .li .nm{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.legend .li .pc{font-family:'JetBrains Mono',monospace;color:var(--muted);font-size:12px}
.mon-grid{display:flex;flex-direction:column;gap:11px}
.mon-item{display:flex;justify-content:space-between;align-items:baseline;
  padding-bottom:10px;border-bottom:1px solid var(--line)}
.mon-item.net{border-bottom:none;padding-top:3px}
.mon-k{font-size:14px;color:var(--muted)}
.mon-v{font-family:'JetBrains Mono',monospace;font-size:16px;color:var(--ivory)}
.mon-item.net .mon-v{font-size:19px;font-weight:500}
.mon-v.pos{color:var(--green)} .mon-v.neg{color:var(--red)}
.mon-note{color:var(--muted);font-size:12px;margin-top:12px;line-height:1.5}
.growth-big{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:36px;line-height:1.1}
.growth-big.pos{color:var(--green)} .growth-big.neg{color:var(--red)}
.growth-sub{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--muted);margin-top:6px}
.growth-note{color:var(--muted);font-size:12px;margin-top:12px;line-height:1.5}

/* ---------- borsa istanbul ---------- */
.sec-actions{display:flex;gap:8px;align-items:center}
.mini-btn{background:var(--card);border:1px solid var(--line);color:var(--rose);border-radius:10px;
  padding:8px 13px;font-size:13px;cursor:pointer;font-family:inherit;transition:border-color .2s}
.mini-btn:hover{border-color:var(--rose-deep)}
.mini-btn:disabled{opacity:.5;cursor:wait}
.stock-add{display:grid;grid-template-columns:1.4fr 1fr 1fr auto;gap:8px;margin-bottom:10px}
@media(max-width:560px){.stock-add{grid-template-columns:1fr 1fr;} .stock-add input:first-child{grid-column:1 / -1}}
.stock-add input{background:var(--card);border:1px solid var(--line);border-radius:10px;color:var(--ivory);
  padding:10px 12px;font-size:14px;font-family:'JetBrains Mono',monospace;min-width:0}
.stock-add input:focus{border-color:var(--rose-deep);outline:none}
.stock-note{color:var(--muted);font-size:12px;margin:2px 0 10px;min-height:15px}
.stocks{display:flex;flex-direction:column;gap:9px}
.stock-row{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);
  padding:12px 14px;display:grid;grid-template-columns:auto 1fr auto;gap:4px 12px;align-items:center}
.stock-row .sym{font-family:'JetBrains Mono',monospace;font-size:15px;color:var(--gold);font-weight:500}
.stock-row .meta{grid-column:1 / 3;font-size:12.5px;color:var(--muted)}
.stock-row .meta b{color:var(--ivory);font-weight:500;font-family:'JetBrains Mono',monospace}
.stock-row .curp{display:flex;align-items:center;gap:6px;font-size:12.5px;color:var(--muted)}
.stock-row .curp input{width:92px;background:var(--bg);border:1px solid var(--line);border-radius:8px;
  color:var(--gold);padding:6px 8px;font-size:13px;font-family:'JetBrains Mono',monospace;text-align:right}
.stock-row .curp input:focus{border-color:var(--rose-deep);outline:none}
.stock-row .pl{grid-column:1 / -1;display:flex;justify-content:space-between;align-items:baseline;
  border-top:1px solid var(--line);margin-top:8px;padding-top:8px;font-size:13px}
.stock-row .pl .val{font-family:'JetBrains Mono',monospace;color:var(--ivory)}
.stock-row .pl .kz{font-family:'JetBrains Mono',monospace}
.stock-row .pl .kz.pos{color:var(--green)} .stock-row .pl .kz.neg{color:var(--red)}
.stock-row .del{background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px;
  padding:4px 6px;justify-self:end}
.stock-row .del:hover{color:var(--red)}
.stock-total{margin-top:12px;padding:12px 14px;background:var(--card);border:1px solid var(--rose-deep);
  border-radius:var(--radius);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;font-size:13.5px}
.stock-total .t{color:var(--muted)}
.stock-total .v{font-family:'JetBrains Mono',monospace;color:var(--ivory)}
.stock-total .v b{color:var(--rose)}

/* ---------- işlemler ---------- */
.tx-note{color:var(--muted);font-size:13px;margin:-6px 0 14px;line-height:1.5}
.tx-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:780px){.tx-grid{grid-template-columns:1fr}}
.tx-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px}
.tx-card h3{font-family:'Cormorant Garamond',serif;font-weight:500;font-size:21px;margin-bottom:2px}
.tx-card.gider h3{color:var(--red)} .tx-card.gelir h3{color:var(--green)}
.tx-card .tot{font-family:'JetBrains Mono',monospace;font-size:12.5px;color:var(--muted);margin-bottom:12px}
.tx-row{display:grid;gap:6px;align-items:center;padding:8px 0;border-bottom:1px solid #281822;
  grid-template-columns:92px 1fr 80px 50px 96px 30px;
  grid-template-areas:"dt ds amt cur asset x"}
.tx-row .dt{grid-area:dt} .tx-row .ds{grid-area:ds} .tx-row .amt{grid-area:amt}
.tx-row .cur-sel{grid-area:cur} .tx-row .asset-sel{grid-area:asset} .tx-row .x{grid-area:x}
.tx-row input,.tx-row select{background:none;border:none;color:var(--ivory);outline:none;
  font-family:'Outfit',sans-serif;font-size:13.5px;width:100%;min-height:30px}
.tx-row select{color:var(--muted);cursor:pointer}
.tx-row select option{background:var(--card-2);color:var(--ivory)}
.tx-row input.amt{font-family:'JetBrains Mono',monospace;text-align:right;color:var(--gold)}
.tx-row input.dt{color:var(--muted);font-size:12px}
.tx-row .x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px;border-radius:8px;
  min-height:36px;min-width:30px}
.tx-row .x:hover{color:var(--red)}
.tx-row.logrow{grid-template-columns:92px 1fr auto auto 30px;
  grid-template-areas:"dt ds amt mark x";align-items:center;background:rgba(155,212,168,.05)}
.tx-row.logrow .dt{grid-area:dt;color:var(--muted);font-size:12px;font-family:'JetBrains Mono',monospace}
.tx-row.logrow .ds{grid-area:ds;color:var(--ivory);font-size:13.5px;display:flex;flex-direction:column;gap:1px}
.tx-row.logrow .ds .tag{font-size:10.5px;color:var(--green);letter-spacing:.02em}
.tx-row.logrow .amt{grid-area:amt;color:var(--green);font-family:'JetBrains Mono',monospace;font-size:13.5px;text-align:right}
.tx-row.logrow .logmark{grid-area:mark;font-size:9.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--green);
  border:1px solid rgba(155,212,168,.3);border-radius:99px;padding:2px 7px}
.tx-row.logrow .x{grid-area:x}
@media(max-width:640px){
  .tx-row.logrow{grid-template-columns:1fr auto 30px;
    grid-template-areas:"ds amt x" "dt mark mark"}
}
.tx-head{font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid var(--line);padding:4px 0}
.tx-add{margin-top:12px;width:100%;justify-content:center}
.empty{color:var(--muted);font-size:13px;padding:14px 0;text-align:center;font-style:italic}

/* ---------- tekrarlayan ---------- */
.rec-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px}
.rec-row{display:grid;gap:6px;align-items:center;padding:8px 0;border-bottom:1px solid #281822;
  grid-template-columns:76px 1fr 80px 50px 70px 96px 30px;
  grid-template-areas:"kind ds amt cur day asset x"}
.rec-row .kind{grid-area:kind} .rec-row .ds{grid-area:ds} .rec-row .amt{grid-area:amt}
.rec-row .cur-sel{grid-area:cur} .rec-row .day{grid-area:day} .rec-row .asset-sel{grid-area:asset} .rec-row .x{grid-area:x}
.rec-row input,.rec-row select{background:none;border:none;color:var(--ivory);outline:none;
  font-family:'Outfit',sans-serif;font-size:13.5px;width:100%;min-height:30px}
.rec-row select{color:var(--muted);cursor:pointer}
.rec-row select.kind{font-weight:500}
.rec-row select option{background:var(--card-2);color:var(--ivory)}
.rec-row input.amt{font-family:'JetBrains Mono',monospace;text-align:right;color:var(--gold)}
.rec-row .day-wrap{grid-area:day;display:flex;align-items:center;gap:4px;font-size:11.5px;color:var(--muted);white-space:nowrap}
.rec-row .day-wrap input{width:42px;text-align:center;font-family:'JetBrains Mono',monospace;color:var(--ivory)}
.rec-row .x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px;border-radius:8px;min-height:36px;min-width:30px}
.rec-row .x:hover{color:var(--red)}
.rec-head{font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;border-bottom:1px solid var(--line);padding:4px 0}

@media(max-width:640px){
  .tx-head,.rec-head{display:none}
  .tx-row{grid-template-columns:96px 1fr 56px 30px;
    grid-template-areas:
      "dt   amt amt x"
      "ds   ds  cur cur"
      "asset asset asset asset";
    border-bottom:1px solid var(--line);padding:10px 0}
  .tx-row .asset-sel{background:var(--card-2);border:1px solid var(--line);border-radius:8px;padding:4px 8px}
  .rec-row{grid-template-columns:90px 1fr 56px 30px;
    grid-template-areas:
      "kind  amt  amt  x"
      "ds    ds   cur  cur"
      "day   day  asset asset";
    border-bottom:1px solid var(--line);padding:10px 0}
  .rec-row .asset-sel{background:var(--card-2);border:1px solid var(--line);border-radius:8px;padding:4px 8px}
  .tx-row input,.tx-row select,.rec-row input,.rec-row select{font-size:16px}
  .tx-row input.dt{font-size:13px}
  .rate input{font-size:16px}
  .asset .val input{font-size:16px}
  .goal-edit input{font-size:16px}
}

/* ---------- veri ---------- */
.data-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.status{font-size:12px;color:var(--muted);min-height:18px;margin-top:10px;font-family:'JetBrains Mono',monospace;overflow-wrap:anywhere}
.status.err{color:var(--red)} .status.okk{color:var(--green)}

footer{margin-top:52px;padding-top:16px;border-top:1px solid var(--line);
  display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px;color:var(--muted);font-size:11.5px}
.saved{color:var(--rose);transition:opacity .4s;opacity:0}
.saved.show{opacity:1}

/* ---------- sekmeler ---------- */
.tabs{display:flex;gap:6px;margin:18px 0 6px;border-bottom:1px solid var(--line);padding-bottom:8px}
.tab{background:transparent;border:1px solid transparent;color:var(--muted);font-family:'Outfit',sans-serif;font-size:13px;font-weight:500;padding:8px 14px;border-radius:8px;cursor:pointer}
.tab:hover{color:var(--text);background:rgba(196,121,143,.08)}
.tab.on{background:rgba(196,121,143,.18);color:var(--rose);border-color:rgba(196,121,143,.35)}
.tabview{display:none}
.tabview.on{display:block}

/* ---------- eşyalar sekmesi ---------- */
.items-hero{display:flex;flex-direction:column;align-items:center;gap:4px;margin:12px 0 22px}
.items-hero .it-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.items-hero .it-total{font-size:clamp(36px,9vw,64px);line-height:1;color:var(--text);font-variant-numeric:tabular-nums}
.items-hero .it-delta{font-size:13px;color:var(--muted)}
.items-add{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
.items-add input,.items-add select{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:8px 10px;color:var(--text);font-family:inherit}
.items-add input[type="number"]{max-width:110px}
.items-add input[type="date"]{max-width:140px;color-scheme:dark}
.items-add select{min-width:130px}
.item-cat{margin:22px 0 12px;padding-bottom:6px;border-bottom:1px solid var(--line);font-size:14px;font-weight:600;color:var(--rose);text-transform:uppercase;letter-spacing:.04em}
.item-list{display:flex;flex-direction:column;gap:10px}
.item-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
.item-card .it-left{display:flex;flex-direction:column;gap:3px}
.item-card .it-name{font-weight:500}
.item-card .it-meta{font-size:11px;color:var(--muted)}
.item-card .it-right{text-align:right}
.item-card .it-cur{font-size:16px;font-weight:600;font-variant-numeric:tabular-nums}
.item-card .it-init{font-size:11px;color:var(--muted)}
.item-card .it-delta-sm{font-size:12px;margin-top:2px}
.item-card .it-actions{display:flex;gap:4px;justify-content:flex-end;margin-top:4px}
.item-card .it-actions button{font-size:11px;padding:4px 8px;border-radius:6px}
.item-card .pos{color:var(--green)} .item-card .neg{color:var(--red)}
.it-empty{color:var(--muted);font-size:13px;margin:8px 0 24px}
.items-hero-card{display:inline-flex;align-items:center;gap:8px;background:rgba(196,121,143,.12);border:1px solid rgba(196,121,143,.25);border-radius:999px;padding:6px 12px;font-size:12px;color:var(--rose);margin-top:8px}
.items-hero-card .it-h-total{font-weight:600;font-variant-numeric:tabular-nums}

/* ---------- abonelikler sekmesi ---------- */
.sub-hero{display:flex;flex-direction:column;align-items:center;gap:4px;margin:12px 0 22px}
.sub-hero .sub-label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.sub-hero .sub-total{font-size:clamp(36px,9vw,64px);line-height:1;color:var(--text);font-variant-numeric:tabular-nums}
.sub-hero .sub-row{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:6px}
.sub-hero .sub-pill{font-size:12px;background:rgba(196,121,143,.12);border:1px solid rgba(196,121,143,.25);border-radius:999px;padding:5px 11px;color:var(--rose)}
.sub-add{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
.sub-add input,.sub-add select{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:8px 10px;color:var(--text);font-family:inherit}
.sub-add input[type="number"]{max-width:110px}
.sub-add input#subName{min-width:140px}
.sub-add input#subNote{min-width:160px;flex:1}
.sub-add select{min-width:110px}
.sub-upcoming{background:rgba(196,121,143,.08);border:1px dashed rgba(196,121,143,.35);border-radius:12px;padding:14px;margin-bottom:18px}
.sub-upcoming h4{margin:0 0 10px;font-size:13px;color:var(--rose)}
.sub-upcoming ul{margin:0;padding-left:18px;font-size:13px;color:var(--text)}
.sub-upcoming li{margin-bottom:5px}
.sub-list{display:flex;flex-direction:column;gap:10px}
.sub-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
.sub-card .sub-left{display:flex;flex-direction:column;gap:3px}
.sub-card .sub-name{font-weight:500;display:flex;align-items:center;gap:6px}
.sub-card .sub-meta{font-size:11px;color:var(--muted)}
.sub-card .sub-note{font-size:11px;color:var(--muted);font-style:italic;margin-top:2px}
.sub-card .sub-right{text-align:right}
.sub-card .sub-cur{font-size:16px;font-weight:600;font-variant-numeric:tabular-nums}
.sub-card .sub-period{font-size:11px;color:var(--muted)}
.sub-card .sub-actions{display:flex;gap:4px;justify-content:flex-end;margin-top:4px}
.sub-card .sub-actions button{font-size:11px;padding:4px 8px;border-radius:6px}
.sub-card.paused{opacity:.55}
.sub-empty{color:var(--muted);font-size:13px;margin:8px 0 24px}
.sub-cat{display:inline-block;font-size:10px;background:rgba(196,121,143,.14);border:1px solid rgba(196,121,143,.28);border-radius:999px;padding:2px 8px;margin-left:6px;color:var(--rose);text-transform:uppercase;letter-spacing:.03em}

/* ---------- parça listesi (bilgisayar setup'ları) ---------- */
.parts-list{margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);display:flex;flex-direction:column;gap:8px;grid-column:1 / -1}
.part-row{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;padding:8px 10px;background:rgba(18,10,15,.4);border-radius:8px;font-size:12px}
.part-row .part-name{flex:1;min-width:120px;font-weight:500}
.part-row .part-cur{font-weight:600;font-variant-numeric:tabular-nums}
.part-row .part-init,.part-row .part-delta{color:var(--muted)}
.part-row .part-delta{margin-left:auto}
.part-row .part-actions{display:flex;gap:4px}
.part-row .part-actions button{font-size:10px;padding:3px 6px}
.part-add-box{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;padding:10px;margin-top:10px;background:rgba(196,121,143,.08);border:1px dashed rgba(196,121,143,.35);border-radius:10px;grid-column:1 / -1}
.part-add-box input,.part-row.editing input{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:6px 8px;color:var(--text);font-family:inherit;font-size:12px}

@media (prefers-reduced-motion: reduce){*{transition:none!important}}
</style>
</head>
<body>
<div class="wrap">

<header>
  <div class="top">
    <div class="brand">
      <h1>Net <em>Worth</em></h1>
      <p>Kişisel Varlık Takibi</p>
    </div>
  </div>
  <div class="rates">
    <div class="rate">
      <label for="usdRate">Dolar (₺)</label>
      <input id="usdRate" type="number" step="0.0001" inputmode="decimal">
    </div>
    <div class="rate">
      <label for="eurRate">Euro (₺)</label>
      <input id="eurRate" type="number" step="0.0001" inputmode="decimal">
    </div>
    <div class="rate">
      <label for="goldRate">Gr. Altın (₺)</label>
      <input id="goldRate" type="number" step="1" inputmode="decimal">
    </div>
    <div class="rate">
      <label for="xautRate">XAUT ($)</label>
      <input id="xautRate" type="number" step="0.01" inputmode="decimal">
    </div>
    <div class="rate">
      <label for="btcRate">BTC ($)</label>
      <input id="btcRate" type="number" step="0.01" inputmode="decimal">
    </div>
    <div class="rate">
      <label for="ethRate">ETH ($)</label>
      <input id="ethRate" type="number" step="0.01" inputmode="decimal">
    </div>
    <button class="live-btn" id="liveBtn" title="Güncel kurları internetten çek">
      ⟳ Canlı Kur
      <small id="liveInfo">çekmek için tıkla</small>
    </button>
  </div>
</header>

<nav class="tabs" id="tabs" role="tablist">
  <button class="tab on" data-tab="networth" role="tab" aria-selected="true">Net Worth</button>
  <button class="tab" data-tab="items" role="tab" aria-selected="false">Eşyalar</button>
  <button class="tab" data-tab="subscriptions" role="tab" aria-selected="false">Abonelikler</button>
  <button class="tab" data-tab="transactions" role="tab" aria-selected="false">İşlemler</button>
</nav>
<div class="tabview on" id="view-networth">

<div class="hero">
  <div class="label">Net Worth</div>
  <div class="big" id="bigTotal">—</div>
  <div class="sub" id="subTotal"></div>
  <div class="chips">
    <span class="chip" id="growthChip" hidden></span>
    <span class="chip" id="sparkChip" hidden></span>
    <span class="toggle" role="group" aria-label="Para birimi">
      <button id="btnTL" class="on">₺ TL</button>
      <button id="btnUSD">$ USD</button>
    </span>
  </div>

  <div class="goals-section" id="goalsSection">
    <div class="sec-head" style="margin-top:0">
      <h2>Hedefler</h2>
      <button class="btn" id="addGoal">+ Hedef Ekle</button>
    </div>
    <div class="goals-list" id="goalsList"></div>
  </div>
  <div class="items-hero-card" id="itemsHeroCard" style="display:none" title="Fiziksel eşyalar toplamı">
    <span>🎒 Eşyalar:</span>
    <span class="it-h-total" id="itemsHeroTotal">—</span>
    <span id="itemsHeroDelta">—</span>
  </div>
</div>

<section>
  <div class="sec-head">
    <h2>Net Worth Grafiği</h2>
    <span class="toggle small" role="group" aria-label="Grafik aralığı">
      <button id="rng7">7 Gün</button>
      <button id="rng30" class="on">30 Gün</button>
      <button id="rng90">90 Gün</button>
      <button id="rngAll">Tümü</button>
    </span>
  </div>
  <div class="chart-card">
    <div class="chart-wrap"><canvas id="networthChart"></canvas></div>
    <div class="chart-empty" id="chartEmpty" hidden>Grafik için en az birkaç günlük geçmiş kaydı gerekiyor.</div>
  </div>
</section>

<section>
  <div class="sec-head">
    <h2>Varlıklar</h2>
    <button class="btn" id="addAsset">+ Varlık Ekle</button>
  </div>
  <p class="tx-note">Taban tutarı negatif girilen bir varlık borç olarak sayılır ve net worth'tan düşülür.</p>
  <div class="assets" id="assetList"></div>
  <div class="asset-totals" id="assetTotals"></div>
</section>

<section>
  <div class="sec-head">
    <h2>Borsa İstanbul</h2>
    <div class="sec-actions">
      <button class="mini-btn" id="stockRefreshBtn" title="Güncel fiyatları çek">⟳ Fiyatları Güncelle</button>
    </div>
  </div>
  <div class="stock-add">
    <input id="stSym" placeholder="Sembol (THYAO)" maxlength="10" autocapitalize="characters" aria-label="Hisse sembolü">
    <input id="stLots" type="number" step="any" min="0" inputmode="decimal" placeholder="Lot" aria-label="Lot sayısı">
    <input id="stBuy" type="number" step="any" min="0" inputmode="decimal" placeholder="Alış ₺" aria-label="Alış fiyatı">
    <button class="mini-btn" id="stAddBtn">+ Ekle</button>
  </div>
  <div class="stock-note" id="stockInfo"></div>
  <div class="stocks" id="stockList"></div>
  <div class="stock-total" id="stockTotal" hidden></div>
</section>

<section>
  <div class="sec-head"><h2>Özet</h2></div>
  <div class="summary" id="summaryBox">
    <div class="sum-card">
      <div class="sum-title">Varlık Dağılımı</div>
      <div class="donut-wrap">
        <div id="donut"></div>
        <div class="legend" id="donutLegend"></div>
      </div>
    </div>
    <div class="sum-card">
      <div class="sum-title">Bu Ay <span id="monthLabel" class="month-label"></span></div>
      <div class="mon-grid">
        <div class="mon-item"><span class="mon-k">Gelir</span><span class="mon-v pos" id="monIncome">$0</span></div>
        <div class="mon-item"><span class="mon-k">Gider</span><span class="mon-v neg" id="monExpense">$0</span></div>
        <div class="mon-item net"><span class="mon-k">Net</span><span class="mon-v" id="monNet">$0</span></div>
      </div>
      <div class="mon-note" id="monNote"></div>
    </div>
    <div class="sum-card">
      <div class="sum-title">Aylık Büyüme</div>
      <div class="growth-big" id="growthBig">—</div>
      <div class="growth-sub" id="growthSub"></div>
      <div class="growth-note" id="growthNote"></div>
    </div>
  </div>
</section>


<section>
  <div class="sec-head"><h2>Veri</h2></div>
  <div class="data-bar">
    <a class="btn" href="?api=export" download>⤓ Dışa Aktar</a>
    <button class="btn" id="importBtn">⤒ İçe Aktar</button>
    <input type="file" id="importFile" accept="application/json,.json" hidden>
  </div>
  <div class="status" id="dataStatus">Veriler sunucuda tek bir dosyada (data/state.json) saklanır. Cron kuruluysa geçmiş her gün otomatik kaydedilir.</div>
</section>

</div>
<div class="tabview" id="view-transactions">
<section>
  <div class="sec-head"><h2>İşlemler</h2></div>
  <p class="tx-note">
    Bir işlemi varlığa bağlarsan tutarı o varlığın bakiyesine otomatik yansır
    (gelir ekler, gider düşer). ₺ tutarlar güncel kurla dolara çevrilir.
  </p>
  <div class="tx-grid">
    <div class="tx-card gider">
      <h3>Gider</h3>
      <div class="tot" id="giderTot"></div>
      <div class="tx-row tx-head"><span>Tarih</span><span>Açıklama</span><span style="text-align:right">Tutar</span><span>Birim</span><span>Varlık</span><span></span></div>
      <div id="giderList"></div>
      <button class="btn tx-add" data-add="gider">+ Gider Ekle</button>
    </div>
    <div class="tx-card gelir">
      <h3>Gelir</h3>
      <div class="tot" id="gelirTot"></div>
      <div class="tx-row tx-head"><span>Tarih</span><span>Açıklama</span><span style="text-align:right">Tutar</span><span>Birim</span><span>Varlık</span><span></span></div>
      <div id="gelirList"></div>
      <button class="btn tx-add" data-add="gelir">+ Gelir Ekle</button>
    </div>
  </div>
</section>

<section>
  <div class="sec-head">
    <h2>Tekrarlayan İşlemler</h2>
    <button class="btn" id="addRec">+ Kural Ekle</button>
  </div>
  <p class="tx-note">
    Her ay belirlediğin günde otomatik gelir/gider kaydı oluşturulur (ör. her ayın 1'i maaş, 5'i kira).
    Kayıtlar siteyi açtığında veya günlük cron çalıştığında üretilir.
  </p>
  <div class="rec-card">
    <div class="rec-row rec-head"><span>Tür</span><span>Açıklama</span><span style="text-align:right">Tutar</span><span>Birim</span><span>Gün</span><span>Varlık</span><span></span></div>
    <div id="recList"></div>
  </div>
</section>

</div>
<div class="tabview" id="view-items">
<section>
  <div class="items-hero">
    <span class="it-label">Fiziksel Eşyalar</span>
    <div class="it-total" id="itemsTotalTL">—</div>
    <div class="it-delta" id="itemsDelta"></div>
  </div>

  <div class="items-add">
    <input id="itName" placeholder="Eşya adı (örn. iPhone 16 Pro)">
    <select id="itCat">
      <option>Bilgisayar</option>
      <option>Çevre Birimleri</option>
      <option>Hatıra Paralar</option>
      <option>Telefon</option>
      <option>Diğer</option>
    </select>
    <input id="itCurrent" type="number" step="any" min="0" inputmode="decimal" placeholder="Güncel ₺">
    <input id="itInitial" type="number" step="any" min="0" inputmode="decimal" placeholder="Alış ₺">
    <input id="itDate" type="date">
    <button class="mini-btn" id="itAddBtn">+ Ekle</button>
  </div>

  <div id="itemList"></div>
</section>
</div>

<div class="tabview" id="view-subscriptions">
<section>
  <div class="sub-hero">
    <span class="sub-label">Aylık Abonelik Maliyeti</span>
    <div class="sub-total" id="subMonthlyTotal">—</div>
    <div class="sub-row">
      <span class="sub-pill">Yıllık: <strong id="subYearlyTotal">—</strong></span>
      <span class="sub-pill" id="subUpcomingCount">Yaklaşan: —</span>
    </div>
  </div>

  <div class="sub-add">
    <input id="subName" placeholder="Abonelik adı (örn. Spotify)" aria-label="Ad">
    <input id="subAmt" type="number" step="any" min="0" inputmode="decimal" placeholder="Tutar" aria-label="Tutar">
    <select id="subCur" aria-label="Para birimi">
      <option value="TL">₺ TL</option>
      <option value="USD">$ USD</option>
      <option value="EUR">€ EUR</option>
    </select>
    <select id="subPeriod" aria-label="Periyot">
      <option value="monthly">Aylık</option>
      <option value="yearly">Yıllık</option>
      <option value="weekly">Haftalık</option>
    </select>
    <input id="subDay" type="number" min="1" max="31" inputmode="numeric" placeholder="Gün" aria-label="Ödeme günü">
    <select id="subCat" aria-label="Kategori">
      <option value="müzik">🎵 Müzik</option>
      <option value="video">🎬 Video</option>
      <option value="depolama">☁️ Depolama</option>
      <option value="üretkenlik">🛠️ Üretkenlik</option>
      <option value="oyun">🎮 Oyun</option>
      <option value="diğer">📦 Diğer</option>
    </select>
    <input id="subNote" placeholder="Not (iptal linki vb.)" aria-label="Not">
    <button class="mini-btn" id="subAddBtn">+ Ekle</button>
  </div>

  <div id="subUpcomingBox"></div>
  <div id="subList"></div>
</section>
</div>
<footer>
  <span>Net Worth Tracker</span>
  <span class="saved" id="savedNote">✓ sunucuya kaydedildi</span>
</footer>

</div>

<script>
"use strict";
let S = null;
let currency = "TL";
let chartRange = 30;
let chartInstance = null;
let activeTab = 'networth';
const uid = () => 'a' + Math.random().toString(36).slice(2, 9);
const fmt = (n, dec=2) => (n||0).toLocaleString("tr-TR",{minimumFractionDigits:dec,maximumFractionDigits:dec});
const $ = id => document.getElementById(id);

const CURS = {
  USD:  {sym:'$',  label:'$ USD'},
  TL:   {sym:'₺',  label:'₺ TL'},
  EUR:  {sym:'€',  label:'€ EUR'},
  GOLD: {sym:'gr', label:'gr Altın'},
  XAUT: {sym:'XAUT', label:'XAUT (ons)'},
  BTC:  {sym:'BTC', label:'₿ BTC'},
  ETH:  {sym:'ETH', label:'Ξ ETH'},
};

/* ---------- sunucu ---------- */
async function api(path, opts){
  const r = await fetch('?api=' + path, opts);
  const j = await r.json().catch(()=>null);
  if(!r.ok || (j && j.ok === false)) throw new Error((j && j.error) || ('Sunucu hatası: ' + r.status));
  return j;
}

let saveTimer = null;
function save(){
  clearTimeout(saveTimer);
  saveTimer = setTimeout(async ()=>{
    try{
      const r = await api('state', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(S)});
      flashSaved();
      // Tekrarlayan kural yeni işlem ürettiyse sunucudan gelen durumu al
      if(r.generated && r.state){
        S = r.state;
        if(!S.history || Array.isArray(S.history)) S.history = {};
        refresh(true);
      }
      snapshot();
    }catch(e){ setStatus('Kaydedilemedi: ' + e.message, 'err'); }
  }, 600);
}
let snapTimer = null;
function snapshot(){
  clearTimeout(snapTimer);
  snapTimer = setTimeout(async ()=>{
    try{
      const r = await api('snapshot', {method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({usd: totalUSD()})});
      if(r.history){ S.history = r.history; renderGrowth(); renderChart(); renderMonthlyGrowth(); }
    }catch(e){ /* sessiz */ }
  }, 800);
}
function flashSaved(){
  const n = $('savedNote');
  n.classList.add('show');
  setTimeout(()=>n.classList.remove('show'), 1200);
}
function setStatus(msg, cls){
  const el = $('dataStatus');
  el.textContent = msg;
  el.className = 'status' + (cls ? ' ' + cls : '');
}

/* ---------- hesaplamalar ---------- */
function rate(cur){
  const usd = parseFloat(S.usdRate)||0;
  if(cur==='USD') return 1;
  if(usd<=0) return 0;
  if(cur==='TL')   return 1/usd;
  if(cur==='EUR')  return (parseFloat(S.eurRate)||0)/usd;
  if(cur==='GOLD') return (parseFloat(S.goldRate)||0)/usd;
  if(cur==='XAUT') return (parseFloat(S.xautRate)||0);
  if(cur==='BTC')  return (parseFloat(S.btcRate)||0);
  if(cur==='ETH')  return (parseFloat(S.ethRate)||0);
  return 0;
}
const toUSD = (amt, cur) => (amt||0) * rate(cur||'USD');

function assetEffective(a){
  let v = toUSD(parseFloat(a.base)||0, a.cur);
  for(const t of S.gelir) if(t.asset === a.id && !t.log) v += toUSD(t.amt, t.cur);
  for(const t of S.gider) if(t.asset === a.id && !t.log) v -= toUSD(t.amt, t.cur);
  return v;
}
const stocksTotalTL = () => (S.stocks||[]).reduce((s,x)=>s + (parseFloat(x.lots)||0)*(parseFloat(x.curPrice)||0), 0);
const stocksCostTL  = () => (S.stocks||[]).reduce((s,x)=>s + (parseFloat(x.lots)||0)*(parseFloat(x.buyPrice)||0), 0);
const totalUSD = () => S.assets.reduce((sum,a)=>sum+assetEffective(a),0) + stocksTotalTL()*rate('TL');

/* ---------- hero + hedef + büyüme ---------- */
function renderHero(){
  const usd = totalUSD();
  const tl  = usd * (parseFloat(S.usdRate)||0);
  const gold = (parseFloat(S.goldRate)||0) > 0 ? tl/S.goldRate : 0;
  if(currency === "TL"){
    $('bigTotal').innerHTML = `<span class="cur">₺</span>${fmt(tl)}`;
    $('subTotal').innerHTML = `≈ <b>$${fmt(usd)}</b> · ≈ <b>${fmt(gold,1)} gr</b> altın`;
  }else{
    $('bigTotal').innerHTML = `<span class="cur">$</span>${fmt(usd)}`;
    $('subTotal').innerHTML = `≈ <b>₺${fmt(tl)}</b> · ≈ <b>${fmt(gold,1)} gr</b> altın`;
  }
  renderGoal(usd);
}

function renderGoal(usd){} // legacy placeholder (goals section handles this)

function renderGoals(){
  const list = $('goalsList');
  if(!list) return;
  list.innerHTML = '';
  S.goals = S.goals || [];
  if(S.goals.length === 0){
    list.innerHTML = `<div class="empty">Henüz hedef yok — "+ Hedef Ekle" ile başla</div>`;
    return;
  }
  S.goals.forEach((g,i)=>{
    const current = goalCurrent(g);
    const target = parseFloat(g.target)||0;
    const pct = target > 0 ? Math.min(100, Math.max(0, current/target*100)) : 0;
    const remaining = target - current;
    const el = document.createElement('div');
    el.className = 'goal-card';
    el.innerHTML = `
      <div class="top">
        <input class="name" placeholder="Hedef adı" aria-label="Hedef adı">
        <div class="target">
          <span>$ /</span>
          <input type="number" step="any" inputmode="decimal" aria-label="Hedef tutarı">
          <select class="asset-sel" aria-label="Bağlı varlık">${assetOptions(g.asset)}</select>
          <button class="del-goal" title="Sil" aria-label="Hedefi sil">✕</button>
        </div>
      </div>
      <div class="bar"><i style="width:${pct.toFixed(1)}%"></i></div>
      <div class="meta" id="gmeta-${i}"></div>
      <div class="sim">
        <div class="sim-title">Senaryo Planlayıcısı</div>
        <div class="sim-grid">
          <label>Aylık ek yatırım ($)<input type="number" step="any" min="0" class="sim-monthly" value="500" inputmode="decimal"></label>
          <label>Yıllık getiri (%)<input type="number" step="0.1" min="0" class="sim-return" value="8" inputmode="decimal"></label>
        </div>
        <button class="mini-btn sim-run">Simüle Et</button>
        <div class="sim-result" id="simres-${i}">Simülasyon çalıştırmak için "Simüle Et"e tıkla.</div>
      </div>`;

    const nameIn = el.querySelector('.name');
    nameIn.value = g.name || '';
    nameIn.addEventListener('input', e=>{ g.name = e.target.value; save(); });

    const targetIn = el.querySelector('.target input');
    targetIn.value = g.target || '';
    targetIn.addEventListener('input', e=>{
      g.target = parseFloat(e.target.value)||0;
      updateGoalCard(el, g); // odak kaybolmasın, sadece bu kartı güncelle
      save();
    });
    targetIn.addEventListener('change', ()=> renderGoals()); // blur/enter'da tam render

    const assetSel = el.querySelector('.asset-sel');
    assetSel.addEventListener('change', e=>{ g.asset = e.target.value; updateGoalCard(el, g); save(); });

    el.querySelector('.del-goal').addEventListener('click', ()=>{
      if(!confirm(`"${g.name || '(adsız)'}" hedefi silinsin mi?`)) return;
      S.goals.splice(i,1);
      renderGoals(); save();
    });

    el.querySelector('.sim-run').addEventListener('click', ()=> runSim(i));

    updateGoalCard(el, g); // ilk metni doldur
    list.appendChild(el);
  });
}

function updateGoalCard(el, g){
  const current = goalCurrent(g);
  const target = parseFloat(g.target)||0;
  const pct = target > 0 ? Math.min(100, Math.max(0, current/target*100)) : 0;
  const remaining = target - current;
  const barI = el.querySelector('.bar i');
  if(barI) barI.style.width = pct.toFixed(1) + '%';
  const meta = el.querySelector('.meta');
  if(!meta) return;
  if(target <= 0){
    meta.innerHTML = 'Hedef tutarı girildiğinde ilerleme görünecek.';
  } else {
    meta.innerHTML = `<b>%${fmt(pct,1)}</b> · mevcut $${fmt(current,0)} / hedef $${fmt(target,0)} · ` +
      (remaining > 0 ? `kalan $${fmt(remaining,0)}` : '<b class="pos">🎉 hedefe ulaşıldı</b>');
  }
}

function goalCurrent(g){
  if(!g || !g.asset) return totalUSD();
  const a = S.assets.find(x => x.id === g.asset);
  return a ? assetEffective(a) : 0;
}

async function runSim(idx){
  const card = document.querySelectorAll('#goalsList .goal-card')[idx];
  if(!card) return;
  const g = S.goals[idx];
  const monthly = parseFloat(card.querySelector('.sim-monthly').value)||0;
  const returnPct = parseFloat(card.querySelector('.sim-return').value)||0;
  const resEl = card.querySelector('.sim-result');
  resEl.textContent = 'hesaplanıyor…';
  try{
    const r = await api('simulate', {method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({goalId: g.id, monthly, returnPct})});
    if(!r.ok) throw new Error(r.error || 'hata');
    const {months, years, totalContributed, totalReturn, reached} = r.result;
    const targetDate = new Date();
    targetDate.setMonth(targetDate.getMonth() + months);
    const dateStr = targetDate.toLocaleDateString('tr-TR', {month:'long', year:'numeric'});
    if(!reached){
      resEl.innerHTML = `Bu senaryo (${monthly}$/ay + %${fmt(returnPct,1)} yıllık) ile hedefe ulaşılamıyor. Lütfen aylık katkıyı/getiriyi artırın.`;
    } else {
      resEl.innerHTML = `<b>${years} yıl</b> (${months} ay) sonra hedefe ulaşılır · tahmini <span class="date">${dateStr}</span><br>
        Toplam katkı: <b>$${fmt(totalContributed)}</b> · getiri: <b>$${fmt(totalReturn)}</b> · toplam $${fmt(r.result.final)}`;
    }
  }catch(e){ resEl.textContent = 'Simülasyon hatası: ' + e.message; }
}

function humanizeDays(d){
  if(d <= 0) return 'bugün';
  if(d < 14) return `${d} gün`;
  if(d < 60){ const w = Math.round(d/7); return `~${w} hafta`; }
  if(d < 365){ const m = Math.round(d/30); return `~${m} ay`; }
  const y = Math.floor(d/365), rem = Math.round((d%365)/30);
  return rem > 0 ? `~${y} yıl ${rem} ay` : `~${y} yıl`;
}

function renderGrowth(){
  const chip = $('growthChip'), spark = $('sparkChip');
  const hist = S.history || {};
  const dates = Object.keys(hist).sort();
  if(dates.length < 2){
    chip.hidden = true; spark.hidden = true;
    return;
  }
  const now = totalUSD();
  const today = new Date();
  const target = new Date(today.getTime() - 30*86400000).toISOString().slice(0,10);
  let refDate = null;
  for(const d of dates){ if(d <= target) refDate = d; else break; }
  let label;
  if(refDate){ label = 'son 30 gün'; }
  else { refDate = dates[0];
    const days = Math.max(1, Math.round((today - new Date(refDate))/86400000));
    label = `son ${days} gün`;
  }
  const ref = hist[refDate];
  if(!(ref > 0)){ chip.hidden = true; }
  else{
    const diff = now - ref;
    const pct = diff/ref*100;
    chip.className = 'chip ' + (diff >= 0 ? 'up' : 'down');
    chip.textContent = `${diff >= 0 ? '▲' : '▼'} ${diff>=0?'+':''}${fmt(pct,1)}% · ${diff>=0?'+':''}$${fmt(diff,0)} ${label}`;
    chip.hidden = false;
  }
  const vals = dates.slice(-90).map(d=>hist[d]);
  if(vals.length >= 3){
    const w=86, h=22, min=Math.min(...vals), max=Math.max(...vals), span=(max-min)||1;
    const pts = vals.map((v,i)=>`${(i/(vals.length-1)*w).toFixed(1)},${(h-2-(v-min)/span*(h-4)).toFixed(1)}`).join(' ');
    spark.innerHTML = `<svg width="${w}" height="${h}" viewBox="0 0 ${w} ${h}" aria-label="Toplam değer grafiği">
      <polyline points="${pts}" fill="none" stroke="#C4798F" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></svg>`;
    spark.hidden = false;
  } else spark.hidden = true;
}

/* ---------- net worth grafiği (Chart.js) ---------- */
function renderChart(){
  const hist = S.history || {};
  let dates = Object.keys(hist).sort();
  if(chartRange > 0) dates = dates.slice(-chartRange);
  const empty = $('chartEmpty'), wrap = document.querySelector('.chart-wrap');
  if(dates.length < 2){
    wrap.style.display = 'none'; empty.hidden = false;
    if(chartInstance){ chartInstance.destroy(); chartInstance = null; }
    return;
  }
  wrap.style.display = ''; empty.hidden = true;

  const usdVals = dates.map(d=>hist[d]);
  // TL geçmişini doğru göstermek için: her günün değeri O günün kuruyla çarpılır.
  // Kur kaydı yoksa bugünün kurunu kullan (eski davranış).
  const hr = S.history_rates || {};
  const nowUsdRate = parseFloat(S.usdRate)||0;
  const vals = currency === 'TL'
    ? usdVals.map((v,d)=>{ const k = hr[dates[d]]||nowUsdRate; return k>0 ? v*k : v; })
    : usdVals;
  const labels = dates.map(d=>{
    const parts = d.split('-');
    return parts[2] + '.' + parts[1];
  });
  const lineColor = '#C4798F';

  const cfg = {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: currency === 'TL' ? 'Net Worth (₺)' : 'Net Worth ($)',
        data: vals,
        borderColor: lineColor,
        backgroundColor: 'rgba(196,121,143,.14)',
        pointRadius: 0,
        pointHoverRadius: 4,
        borderWidth: 2,
        tension: .25,
        fill: true,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {mode:'index', intersect:false},
      plugins: {
        legend: {display:false},
        tooltip: {
          callbacks: {
            label: ctx => (currency==='TL' ? '₺' : '$') + Number(ctx.parsed.y).toLocaleString('tr-TR',{maximumFractionDigits:0})
          }
        }
      },
      scales: {
        x: {ticks:{color:'#9A7E88', maxRotation:0, autoSkip:true, maxTicksLimit:8}, grid:{color:'#281822'}},
        y: {ticks:{color:'#9A7E88', callback:v=>(currency==='TL'?'₺':'$')+Number(v).toLocaleString('tr-TR',{notation:'compact'})}, grid:{color:'#281822'}},
      }
    }
  };

  if(chartInstance){
    chartInstance.data = cfg.data;
    chartInstance.options = cfg.options;
    chartInstance.update();
  } else {
    chartInstance = new Chart($('networthChart').getContext('2d'), cfg);
  }
}

function setChartRange(r){
  chartRange = r;
  $('rng7').classList.toggle('on', r === 7);
  $('rng30').classList.toggle('on', r === 30);
  $('rng90').classList.toggle('on', r === 90);
  $('rngAll').classList.toggle('on', r === 0);
  renderChart();
}

/* ---------- aylık büyüme kartı ---------- */
function renderMonthlyGrowth(){
  const big = $('growthBig'), sub = $('growthSub'), note = $('growthNote');
  const hist = S.history || {};
  const dates = Object.keys(hist).sort();
  const now = totalUSD();

  // Bu ayın ilk gününden önceki (veya ona en yakın) kayıt = "geçen ay sonu" referansı
  const firstOfMonth = new Date().toISOString().slice(0,7) + '-01';
  let refDate = null;
  for(const d of dates){ if(d < firstOfMonth) refDate = d; else break; }

  if(!refDate){
    big.textContent = '—'; big.className = 'growth-big';
    sub.textContent = '';
    note.textContent = 'Karşılaştırma için önceki aydan bir kayıt gerekiyor. Veri biriktikçe burada görünecek.';
    return;
  }
  const ref = hist[refDate];
  if(!(ref > 0)){
    big.textContent = '—'; big.className = 'growth-big'; sub.textContent = ''; note.textContent = '';
    return;
  }
  const diff = now - ref;
  const pct = diff/ref*100;
  const cls = diff >= 0 ? 'pos' : 'neg';
  big.className = 'growth-big ' + cls;
  big.textContent = `${diff>=0?'+':''}%${fmt(pct,1)}`;
  sub.textContent = `${diff>=0?'+':'−'}$${fmt(Math.abs(diff))} · ${refDate} tarihine göre`;
  note.textContent = diff >= 0
    ? 'Geçen aya göre net worth artıyor.'
    : 'Geçen aya göre net worth azaldı.';
}

/* ---------- özet: dağılım + bu ay ---------- */
const DONUT_COLORS = ['#E8AFC0','#D9B98A','#C4798F','#9BD4A8','#9A7E88','#E08A8A','#B98AD9','#8AC4D9','#D9C98A','#C0E8AF'];

function renderSummary(){
  renderDonut();
  renderMonth();
  renderMonthlyGrowth();
}

function renderDonut(){
  const host = $('donut'), legend = $('donutLegend');
  if(!host) return;
  const parts = S.assets
    .map(a => ({ name:a.name, val:assetEffective(a) }))
    .filter(p => p.val > 0)
    .sort((x,y)=>y.val-x.val);
  const bistUsd = stocksTotalTL() * rate('TL');
  if(bistUsd > 0){
    parts.push({name:'BIST Hisseleri', val:bistUsd});
    parts.sort((x,y)=>y.val-x.val);
  }
  const total = parts.reduce((s,p)=>s+p.val,0);
  if(total <= 0 || parts.length === 0){
    host.innerHTML = '';
    legend.innerHTML = '<div class="li"><span class="nm" style="color:var(--muted)">Gösterilecek pozitif varlık yok.</span></div>';
    return;
  }
  // en fazla 9 dilim + "Diğer"
  let slices = parts;
  if(parts.length > 9){
    const top = parts.slice(0,8);
    const rest = parts.slice(8).reduce((s,p)=>s+p.val,0);
    top.push({name:'Diğer', val:rest});
    slices = top;
  }
  const cx=58, cy=58, r=44, sw=20, C=2*Math.PI*r;
  let offset=0, segs='';
  slices.forEach((p,i)=>{
    const frac = p.val/total;
    const len = frac*C;
    const col = DONUT_COLORS[i % DONUT_COLORS.length];
    segs += `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${col}" stroke-width="${sw}"
      stroke-dasharray="${len.toFixed(2)} ${(C-len).toFixed(2)}"
      stroke-dashoffset="${(-offset).toFixed(2)}" transform="rotate(-90 ${cx} ${cy})"
      stroke-linecap="butt"><title>${escapeHtml(p.name)}: $${fmt(p.val)}</title></circle>`;
    offset += len;
  });
  host.innerHTML = `<svg width="116" height="116" viewBox="0 0 116 116" aria-label="Varlık dağılımı">
    ${segs}
    <text x="${cx}" y="${cy-3}" text-anchor="middle" fill="var(--muted)" font-size="9" font-family="Outfit,sans-serif">TOPLAM</text>
    <text x="${cx}" y="${cy+11}" text-anchor="middle" fill="var(--ivory)" font-size="13" font-family="JetBrains Mono,monospace">$${fmt(total,0)}</text>
  </svg>`;
  legend.innerHTML = slices.map((p,i)=>{
    const col = DONUT_COLORS[i % DONUT_COLORS.length];
    const pc = (p.val/total*100);
    return `<div class="li"><span class="dot" style="background:${col}"></span>
      <span class="nm">${escapeHtml(p.name)}</span>
      <span class="pc">${fmt(pc,1)}%</span></div>`;
  }).join('');
}

function renderMonth(){
  const now = new Date();
  const ym = now.toISOString().slice(0,7); // YYYY-MM
  $('monthLabel').textContent = now.toLocaleDateString('tr-TR',{month:'long', year:'numeric'});
  let inc=0, exp=0;
  for(const t of (S.gelir||[])){ if(!t.log && (t.date||'').slice(0,7) === ym) inc += toUSD(t.amt, t.cur); }
  for(const t of (S.gider||[])){ if(!t.log && (t.date||'').slice(0,7) === ym) exp += toUSD(t.amt, t.cur); }
  const net = inc - exp;
  $('monIncome').textContent  = '$'+fmt(inc);
  $('monExpense').textContent = '$'+fmt(exp);
  const netEl = $('monNet');
  netEl.textContent = (net>=0?'+':'−')+'$'+fmt(Math.abs(net));
  netEl.className = 'mon-v ' + (net>=0?'pos':'neg');
  const note = $('monNote');
  if(inc===0 && exp===0){
    note.textContent = 'Bu ay tarihli gelir/gider işlemi yok.';
  } else {
    note.textContent = net>=0
      ? `Bu ay ${fmt(net)} $ fazla verdin.`
      : `Bu ay ${fmt(Math.abs(net))} $ açık verdin.`;
  }
}

/* ---------- borsa istanbul ---------- */
function stockPL(st){
  const lots = parseFloat(st.lots)||0;
  const cost = lots * (parseFloat(st.buyPrice)||0);
  const val  = lots * (parseFloat(st.curPrice)||0);
  const kz   = val - cost;
  const pct  = cost > 0 ? kz/cost*100 : 0;
  return {cost, val, kz, pct};
}

function stockPlHtml(st){
  const p = stockPL(st);
  const cls = p.kz >= 0 ? 'pos' : 'neg';
  const sign = p.kz >= 0 ? '+' : '−';
  return `<span class="val">Değer: <b>₺${fmt(p.val)}</b> · Maliyet: ₺${fmt(p.cost)}</span>
    <span class="kz ${cls}">${sign}₺${fmt(Math.abs(p.kz))} (${sign}%${fmt(Math.abs(p.pct),1)})</span>`;
}

function renderStocks(){
  const list = $('stockList');
  if(!list) return;
  S.stocks = S.stocks || [];
  list.innerHTML = '';
  if(S.stocks.length === 0){
    list.innerHTML = `<div class="empty">Henüz hisse yok — sembol, lot ve alış fiyatı girip ekle</div>`;
    updateStockTotals();
    return;
  }
  S.stocks.forEach((st, i)=>{
    const el = document.createElement('div');
    el.className = 'stock-row';
    el.dataset.idx = i;
    el.innerHTML = `
      <span class="sym">${escapeHtml(st.symbol)}</span>
      <span class="curp">güncel ₺ <input type="number" step="any" min="0" inputmode="decimal" value="${st.curPrice||''}" aria-label="${escapeHtml(st.symbol)} güncel fiyat"></span>
      <button class="del" title="Hisseyi sil" aria-label="Sil">✕</button>
      <span class="meta"><b>${fmt(parseFloat(st.lots)||0,0)}</b> lot · alış <b>₺${fmt(parseFloat(st.buyPrice)||0)}</b></span>
      <div class="pl">${stockPlHtml(st)}</div>`;
    el.querySelector('.curp input').addEventListener('input', e=>{
      S.stocks[i].curPrice = parseFloat(e.target.value)||0;
      el.querySelector('.pl').innerHTML = stockPlHtml(S.stocks[i]);
      updateStockTotals();
      renderHero(); renderGrowth(); renderSummary();
      save();
    });
    el.querySelector('.del').addEventListener('click', ()=>{
      if(!confirm(`${st.symbol} silinsin mi?`)) return;
      S.stocks.splice(i,1);
      renderStocks(); refresh(false); save();
    });
    list.appendChild(el);
  });
  updateStockTotals();
}

function updateStockTotals(){
  const box = $('stockTotal');
  if(!box) return;
  const valTL = stocksTotalTL(), costTL = stocksCostTL();
  if(valTL <= 0 && costTL <= 0){ box.hidden = true; return; }
  const kz = valTL - costTL;
  const pct = costTL > 0 ? kz/costTL*100 : 0;
  const usd = valTL * rate('TL');
  const cls = kz >= 0 ? 'pos' : 'neg';
  const sign = kz >= 0 ? '+' : '−';
  box.hidden = false;
  box.innerHTML = `<span class="t">Portföy toplamı</span>
    <span class="v"><b>₺${fmt(valTL)}</b> ≈ $${fmt(usd)} ·
      <span class="kz ${cls}" style="color:var(--${kz>=0?'green':'red'})">${sign}₺${fmt(Math.abs(kz))} (${sign}%${fmt(Math.abs(pct),1)})</span></span>`;
}

function addStock(){
  const symEl = $('stSym'), lotEl = $('stLots'), buyEl = $('stBuy');
  const sym = symEl.value.trim().toUpperCase();
  if(!sym) { symEl.focus(); return; }
  S.stocks = S.stocks || [];
  S.stocks.push({id: uid(), symbol: sym, lots: parseFloat(lotEl.value)||0, buyPrice: parseFloat(buyEl.value)||0, curPrice: 0});
  symEl.value = lotEl.value = buyEl.value = '';
  renderStocks(); refresh(false); save();
  fetchStockPrices(); // yeni sembolün güncel fiyatını dene
}

async function fetchStockPrices(){
  const btn = $('stockRefreshBtn'), info = $('stockInfo');
  if(!S.stocks || S.stocks.length === 0){ info.textContent = 'Önce hisse ekle.'; return; }
  btn.disabled = true; info.textContent = 'fiyatlar çekiliyor…';
  try{
    const r = await api('stockprices');
    if(!r.ok) throw new Error('alınamadı');
    if(Array.isArray(r.stocks)) S.stocks = r.stocks;
    const got = Object.keys(r.prices||{});
    const missing = (S.stocks||[]).map(s=>s.symbol).filter(s=>!got.includes(s));
    info.textContent = (r.cached ? 'önbellek · ' : '') +
      new Date((r.ts||Date.now()/1000)*1000).toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'}) +
      (missing.length ? ` · alınamadı: ${missing.join(', ')} (elle gir)` : '');
    renderStocks(); refresh(false);
  }catch(e){
    info.textContent = 'Fiyatlar alınamadı — güncel fiyatı elle girebilirsin.';
  }finally{
    btn.disabled = false;
  }
}

/* ---------- varlıklar ---------- */
function curSelect(selected){
  return Object.entries(CURS).map(([k,c]) =>
    `<option value="${k}" ${k===selected?'selected':''}>${c.label}</option>`).join('');
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function renderAssets(){
  const list = $('assetList');
  list.innerHTML = "";
  const tot = totalUSD();
  S.assets.forEach((a,i)=>{
    const eff = assetEffective(a);
    const baseUSD = toUSD(parseFloat(a.base)||0, a.cur);
    const delta = eff - baseUSD;
    const isDebt = eff < 0;
    const pct = tot > 0 ? (Math.abs(eff)/tot*100) : 0;
    const el = document.createElement('div');
    el.className = 'asset' + (isDebt ? ' is-debt' : '');
    el.innerHTML = `
      <div class="name">
        <input aria-label="Varlık adı">
        ${isDebt ? '<span class="debt-tag">borç</span>' : ''}
      </div>
      <div class="eff ${isDebt?'neg':''}" title="İşlemler dahil efektif bakiye (USD)">$${fmt(eff)}</div>
      <div class="row2">
        <div class="val">
          <input type="number" step="any" inputmode="decimal" aria-label="Taban tutar">
          <select class="cur" aria-label="Para birimi">${curSelect(a.cur)}</select>
        </div>
        <span class="meta">${delta !== 0
          ? `işlemler: <span class="${delta>0?'pos':'neg'}">${delta>0?'+':''}$${fmt(delta)}</span>` : ''}</span>
      </div>
      <div class="actions">
        <button class="del" title="Sil" aria-label="Varlığı sil">✕</button>
      </div>
      <div class="bar"><i style="width:${Math.max(0,Math.min(100,pct)).toFixed(1)}%;${isDebt?'background:linear-gradient(90deg,var(--red),#c46d6d)':''}"></i></div>`;

    const nameIn = el.querySelector('.name input');
    nameIn.value = a.name;
    nameIn.addEventListener('input', e=>{ a.name = e.target.value; save(); });
    const valIn = el.querySelector('.val input');
    valIn.value = a.base;
    // Odak bozulmasın: yazarken listeyi baştan ÇİZME, sadece hesapları güncelle
    valIn.addEventListener('input', e=>{
      a.base = parseFloat(e.target.value)||0;
      refresh(false); save();
    });
    valIn.addEventListener('change', ()=>{ refresh(true); });
    const curSel = el.querySelector('select.cur');
    curSel.addEventListener('change', e=>{
      a.cur = e.target.value;
      refresh(true); save();
    });
    const del = el.querySelector('.del');
    del.addEventListener('click', ()=>{
      if(!confirm(`"${a.name || '(adsız)'}" varlığı silinsin mi?`)) return;
      for(const k of ['gider','gelir']) for(const t of S[k]) if(t.asset === a.id) t.asset = '';
      for(const r of S.recurring) if(r.asset === a.id) r.asset = '';
      S.assets.splice(i,1); refresh(true); save();
    });
    list.appendChild(el);
  });
  renderAssetTotals();
}

function updateAssetsInPlace(){
  const tot = totalUSD();
  document.querySelectorAll('#assetList .asset').forEach((el,i)=>{
    const a = S.assets[i];
    if(!a) return;
    const eff = assetEffective(a);
    const baseUSD = toUSD(parseFloat(a.base)||0, a.cur);
    const delta = eff - baseUSD;
    const isDebt = eff < 0;
    const pct = tot > 0 ? (Math.abs(eff)/tot*100) : 0;
    el.classList.toggle('is-debt', isDebt);
    const effEl = el.querySelector('.eff');
    effEl.textContent = '$' + fmt(eff);
    effEl.classList.toggle('neg', isDebt);
    const tagEl = el.querySelector('.debt-tag');
    if(isDebt && !tagEl){ el.querySelector('.name').insertAdjacentHTML('beforeend', '<span class="debt-tag">borç</span>'); }
    if(!isDebt && tagEl){ tagEl.remove(); }
    el.querySelector('.meta').innerHTML = delta !== 0
      ? `işlemler: <span class="${delta>0?'pos':'neg'}">${delta>0?'+':''}$${fmt(delta)}</span>` : '';
    const barI = el.querySelector('.bar i');
    barI.style.width = Math.max(0,Math.min(100,pct)).toFixed(1) + '%';
    barI.style.background = isDebt ? 'linear-gradient(90deg,var(--red),#c46d6d)' : '';
  });
  renderAssetTotals();
}

function renderAssetTotals(){
  const box = $('assetTotals');
  if(!box) return;
  let assetsSum = 0, debtSum = 0;
  for(const a of S.assets){
    const eff = assetEffective(a);
    if(eff >= 0) assetsSum += eff; else debtSum += -eff;
  }
  const bistUsd = stocksTotalTL() * rate('TL');
  const net = assetsSum - debtSum + bistUsd; // üstteki büyük toplamla tutarlı (hisse dahil)
  box.innerHTML = `
    <span><span class="t">Varlıklar</span> <span class="v pos">$${fmt(assetsSum)}</span></span>
    <span><span class="t">Borçlar</span> <span class="v neg">$${fmt(debtSum)}</span></span>
    ${bistUsd > 0 ? `<span><span class="t">BIST</span> <span class="v pos">$${fmt(bistUsd)}</span></span>` : ''}
    <span class="net"><span class="t">Net</span> <span class="v">$${fmt(net)}</span></span>`;
}

/* ---------- işlemler ---------- */
function assetOptions(selected){
  let html = `<option value="">— yok —</option>`;
  for(const a of S.assets){
    html += `<option value="${a.id}" ${a.id===selected?'selected':''}>${escapeHtml(a.name||'(adsız)')}</option>`;
  }
  return html;
}

function renderTx(kind){
  const list = $(kind + 'List');
  list.innerHTML = "";
  if(S[kind].length === 0){
    list.innerHTML = `<div class="empty">Henüz kayıt yok</div>`;
    return;
  }
  S[kind].forEach((t,i)=>{
    const el = document.createElement('div');

    if(t.log){
      el.className = 'tx-row logrow';
      const aName = (S.assets.find(a=>a.id===t.asset)||{}).name || '';
      el.innerHTML = `
        <span class="dt">${t.date}</span>
        <span class="ds">${escapeHtml(t.desc||'Kayıt')}<span class="tag">bakiyeye işlendi${aName?' · '+escapeHtml(aName):''}</span></span>
        <span class="amt">+$${fmt(t.amt)}</span>
        <span class="logmark">log</span>
        <button class="x" title="Kaydı sil" aria-label="Log kaydını sil">✕</button>`;
      el.querySelector('.x').addEventListener('click', ()=>{
        if(!confirm('Bu log kaydı silinsin mi? (Bakiye değişmez, yalnız kayıt silinir)')) return;
        S[kind].splice(i,1); refresh(true); save();
      });
      list.appendChild(el);
      return;
    }

    el.className = 'tx-row';
    el.innerHTML = `
      <input class="dt" type="date" aria-label="Tarih">
      <input class="ds" placeholder="Açıklama" aria-label="Açıklama">
      <input class="amt" type="number" step="any" inputmode="decimal" aria-label="Tutar">
      <select class="cur-sel" aria-label="Para birimi"><option value="TL">₺</option><option value="USD">$</option></select>
      <select class="asset-sel" aria-label="Bağlı varlık">${assetOptions(t.asset)}</select>
      <button class="x" title="Sil" aria-label="Kaydı sil">✕</button>`;
    el.querySelector('.dt').value = t.date;
    el.querySelector('.ds').value = t.desc;
    el.querySelector('.amt').value = t.amt;
    el.querySelector('.cur-sel').value = t.cur || 'TL';
    el.querySelector('.dt').addEventListener('input', e=>{ t.date = e.target.value; save(); });
    el.querySelector('.ds').addEventListener('input', e=>{ t.desc = e.target.value; save(); });
    el.querySelector('.amt').addEventListener('input', e=>{ t.amt = parseFloat(e.target.value)||0; refresh(false); save(); });
    el.querySelector('.cur-sel').addEventListener('change', e=>{ t.cur = e.target.value; refresh(false); save(); });
    el.querySelector('.asset-sel').addEventListener('change', e=>{ t.asset = e.target.value; refresh(false); save(); });
    el.querySelector('.x').addEventListener('click', ()=>{ S[kind].splice(i,1); refresh(true); save(); });
    list.appendChild(el);
  });
}
function renderTxSelects(){
  ['gider','gelir'].forEach(kind=>{
    const rows = document.querySelectorAll('#'+kind+'List .tx-row');
    let idx = 0;
    S[kind].forEach((t)=>{
      const row = rows[idx++];
      if(!row) return;
      const sel = row.querySelector('.asset-sel');
      if(sel && !t.log) sel.innerHTML = assetOptions(t.asset);
    });
  });
  document.querySelectorAll('#recList .asset-sel').forEach((sel,i)=>{ if(S.recurring[i]) sel.innerHTML = assetOptions(S.recurring[i].asset); });
}
function sumTx(kind){
  let tl = 0, usd = 0;
  for(const t of S[kind]){ if(t.log) continue; if((t.cur||'TL')==='USD') usd += t.amt||0; else tl += t.amt||0; }
  return {tl, usd};
}
function renderTotals(){
  const g = sumTx('gider'), k = sumTx('gelir');
  const part = s => [s.tl ? `₺${fmt(s.tl)}` : null, s.usd ? `$${fmt(s.usd)}` : null].filter(Boolean).join(' + ') || '₺0,00';
  $('giderTot').textContent = `Toplam: ${part(g)}`;
  $('gelirTot').textContent = `Toplam: ${part(k)}`;
}

/* ---------- tekrarlayan işlemler ---------- */
function renderRec(){
  const list = $('recList');
  list.innerHTML = "";
  if(S.recurring.length === 0){
    list.innerHTML = `<div class="empty">Henüz kural yok — "+ Kural Ekle" ile başla</div>`;
    return;
  }
  S.recurring.forEach((r,i)=>{
    const el = document.createElement('div');
    el.className = 'rec-row';
    el.innerHTML = `
      <select class="kind" aria-label="Tür">
        <option value="gider">Gider</option>
        <option value="gelir">Gelir</option>
      </select>
      <input class="ds" placeholder="Açıklama (ör. Maaş, Kira)" aria-label="Açıklama">
      <input class="amt" type="number" step="any" inputmode="decimal" aria-label="Tutar">
      <select class="cur-sel" aria-label="Para birimi"><option value="TL">₺</option><option value="USD">$</option></select>
      <span class="day-wrap">her ayın <input class="day" type="number" min="1" max="31" inputmode="numeric" aria-label="Ayın günü">'i</span>
      <select class="asset-sel" aria-label="Bağlı varlık">${assetOptions(r.asset)}</select>
      <button class="x" title="Sil" aria-label="Kuralı sil">✕</button>`;
    el.querySelector('.kind').value = r.kind;
    el.querySelector('.ds').value = r.desc;
    el.querySelector('.amt').value = r.amt;
    el.querySelector('.cur-sel').value = r.cur || 'TL';
    el.querySelector('.day').value = r.day;
    el.querySelector('.kind').addEventListener('change', e=>{ r.kind = e.target.value; save(); });
    el.querySelector('.ds').addEventListener('input', e=>{ r.desc = e.target.value; save(); });
    el.querySelector('.amt').addEventListener('input', e=>{ r.amt = parseFloat(e.target.value)||0; save(); });
    el.querySelector('.cur-sel').addEventListener('change', e=>{ r.cur = e.target.value; save(); });
    el.querySelector('.day').addEventListener('input', e=>{
      r.day = Math.min(31, Math.max(1, parseInt(e.target.value)||1)); save();
    });
    el.querySelector('.asset-sel').addEventListener('change', e=>{ r.asset = e.target.value; save(); });
    el.querySelector('.x').addEventListener('click', ()=>{
      if(!confirm('Bu kural silinsin mi? (Üretilmiş kayıtlar silinmez)')) return;
      S.recurring.splice(i,1); renderRec(); save();
    });
    list.appendChild(el);
  });
}

/* ---------- canlı kur ---------- */
async function fetchRates(){
  const btn = $('liveBtn'), info = $('liveInfo');
  btn.classList.add('loading'); info.textContent = 'çekiliyor…';
  try{
    const r = await api('rates');
    if(!r.ok) throw new Error('kaynaklara ulaşılamadı');
    if(r.usd){  S.usdRate  = r.usd;  $('usdRate').value  = r.usd; }
    if(r.eur){  S.eurRate  = r.eur;  $('eurRate').value  = r.eur; }
    if(r.gold){ S.goldRate = r.gold; $('goldRate').value = r.gold; }
    if(r.btc){  S.btcRate  = r.btc;  $('btcRate').value  = r.btc; }
    if(r.eth){  S.ethRate  = r.eth;  $('ethRate').value  = r.eth; }
    if(r.xaut){ S.xautRate = r.xaut; $('xautRate').value = r.xaut; }
    else if(r.gold && r.usd){
      // CoinGecko'dan XAUT alınamazsa gram altından türet (~1 ons = 31.1035 gr)
      const x = (r.gold * 31.1035) / r.usd;
      if(x > 0){ S.xautRate = Math.round(x*100)/100; $('xautRate').value = S.xautRate; }
    }
    info.textContent = (r.cached ? 'önbellek · ' : '') +
      new Date(r.ts*1000).toLocaleTimeString('tr-TR',{hour:'2-digit',minute:'2-digit'});
    refresh(false); save();
  }catch(e){
    info.textContent = 'alınamadı — elle girin';
  }finally{
    btn.classList.remove('loading');
  }
}

/* ---------- içe/dışa aktarma ---------- */
function bindData(){
  $('importBtn').addEventListener('click', ()=> $('importFile').click());
  $('importFile').addEventListener('change', async e=>{
    const f = e.target.files[0];
    if(!f) return;
    if(!confirm('İçe aktarma mevcut tüm verilerin üzerine yazar. Devam edilsin mi?')){ e.target.value=''; return; }
    const fd = new FormData();
    fd.append('file', f);
    try{
      const r = await api('import', {method:'POST', body: fd});
      S = r.state;
      if(!S.history || Array.isArray(S.history)) S.history = {};
      bindRates(); refresh(true);
      setStatus('İçe aktarma başarılı.', 'okk');
    }catch(err){
      setStatus('İçe aktarma hatası: ' + err.message, 'err');
    }
    e.target.value = '';
  });
}

/* ---------- genel ---------- */
function refresh(full){
  if(full){ renderAssets(); renderStocks(); renderTx('gider'); renderTx('gelir'); renderRec(); renderGoals(); renderItems(); }
  else{ updateAssetsInPlace(); updateStockTotals(); }
  renderHero();
  renderTotals();
  renderGrowth();
  renderSummary();
  renderGoals();
  renderChart();
  renderItemsTotal();
}

/* ---------- sekme yönetimi ---------- */
function setTab(name){
  activeTab = name;
  document.querySelectorAll('.tab').forEach(b=>{
    b.classList.toggle('on', b.dataset.tab === name);
    b.setAttribute('aria-selected', b.dataset.tab === name ? 'true' : 'false');
  });
  document.querySelectorAll('.tabview').forEach(v=>v.classList.toggle('on', v.id === 'view-'+name));
  const rates = document.querySelector('.rates');
  if(rates) rates.style.display = name === 'networth' ? '' : 'none';
  if(name === 'items') renderItems();
  if(name === 'subscriptions') renderSubscriptions();
}

function itemValues(it){
  if(!Array.isArray(it.parts)) it.parts = [];
  if(it.category === 'Bilgisayar' && it.parts.length > 0){
    let cur=0, init=0;
    for(const p of it.parts){ cur += p.current||0; init += p.initial||0; }
    return {current:cur, initial:init};
  }
  return {current: it.current||0, initial: it.initial||0};
}
function itemsTotal(){
  if(!Array.isArray(S.items)) S.items = [];
  let cur = 0, init = 0;
  for(const it of S.items){ const v=itemValues(it); cur += v.current; init += v.initial; }
  return {current:cur, initial:init, delta:cur-init};
}
function fmtTL(n){ return '₺' + fmt(n,0); }
function fmtPct(cur, init){
  if(!init) return init === 0 && cur === 0 ? '0%' : '—';
  return (((cur-init)/init)*100).toFixed(1).replace('.',',') + '%';
}
function renderItemsTotal(){
  const t = itemsTotal();
  const big = $('itemsTotalTL'), delta = $('itemsDelta');
  if(big) big.textContent = fmtTL(t.current);
  if(delta){
    const cls = t.delta > 0 ? 'pos' : (t.delta < 0 ? 'neg' : '');
    const sign = t.delta > 0 ? '+' : '';
    delta.innerHTML = `<span class="${cls}">${sign}${fmtTL(Math.abs(t.delta))}</span> · Alış ${fmtTL(t.initial)} · ${fmtPct(t.current, t.initial)}`;
  }
  const heroCard = $('itemsHeroCard');
  if(heroCard){
    heroCard.style.display = t.current > 0 ? 'inline-flex' : 'none';
    const hTotal = $('itemsHeroTotal');
    const hDelta = $('itemsHeroDelta');
    if(hTotal) hTotal.textContent = fmtTL(t.current);
    if(hDelta){
      const cls = t.delta >=0 ? 'pos' : 'neg';
      hDelta.textContent = `(${t.delta >=0 ? '+' : ''}${fmtPct(t.current, t.initial)})`;
      hDelta.className = cls;
    }
  }
}
function renderItems(){
  if(!Array.isArray(S.items)) S.items = [];
  const wrap = $('itemList');
  if(!wrap) return;
  if(S.items.length === 0){
    wrap.innerHTML = '<p class="it-empty">Henüz eşya eklenmemiş. Yukarıdaki form ile PC, telefon, hatıra paralarınızı ekleyebilirsiniz.</p>';
    return;
  }
  const cats = {};
  for(const it of S.items){
    const c = it.category || 'Diğer';
    if(!cats[c]) cats[c] = [];
    cats[c].push(it);
  }
  const order = ['Bilgisayar','Çevre Birimleri','Hatıra Paralar','Telefon','Diğer'];
  const present = order.filter(c=>cats[c]).concat(Object.keys(cats).filter(c=>!order.includes(c)));
  let html = '';
  for(const cat of present){
    html += `<div class="item-cat">${esc(cat)}</div><div class="item-list">`;
    for(const it of cats[cat]){
      const v = itemValues(it);
      const d = v.current - v.initial;
      const cls = d > 0 ? 'pos' : (d < 0 ? 'neg' : '');
      const sign = d > 0 ? '+' : '';
      const meta = [it.date ? dateTr(it.date) : '', it.category==='Bilgisayar' ? `${it.parts?.length||0} parça` : ''].filter(Boolean).join(' · ');
      html += `
        <div class="item-card" data-id="${esc(it.id)}">
          <div class="it-left">
            <span class="it-name">${esc(it.name || 'İsimsiz')}</span>
            <span class="it-meta">${meta}</span>
          </div>
          <div class="it-right">
            <div class="it-cur">${fmtTL(v.current)}</div>
            <div class="it-init">Alış: ${fmtTL(v.initial)}</div>
            <div class="it-delta-sm ${cls}">${sign}${fmtTL(Math.abs(d))} · ${fmtPct(v.current, v.initial)}</div>
          </div>
          <div class="it-actions">
            <button class="mini-btn it-edit">Düzenle</button>
            ${it.category==='Bilgisayar' ? `<button class="mini-btn it-parts">Parça Ekle</button>` : ''}
            <button class="mini-btn it-del">Sil</button>
          </div>
          ${it.category==='Bilgisayar' ? renderParts(it) : ''}
        </div>`;
    }
    html += '</div>';
  }
  wrap.innerHTML = html;
  // action listeners
  wrap.querySelectorAll('.it-del').forEach(b=>{
    b.addEventListener('click', ()=>{
      const id = b.closest('.item-card')?.dataset.id;
      if(!id) return;
      S.items = S.items.filter(x=>x.id !== id);
      renderItems(); renderItemsTotal(); save();
    });
  });
  wrap.querySelectorAll('.it-edit').forEach(b=>{
    b.addEventListener('click', ()=>{
      const id = b.closest('.item-card')?.dataset.id;
      const it = S.items.find(x=>x.id === id);
      if(!it) return;
      editItemInline(id, it);
    });
  });
  wrap.querySelectorAll('.it-parts').forEach(b=>{
    b.addEventListener('click', ()=>{
      const id = b.closest('.item-card')?.dataset.id;
      const it = S.items.find(x=>x.id === id);
      if(!it) return;
      showPartAdd(id, it);
    });
  });
  wrap.querySelectorAll('.part-del').forEach(b=>{
    b.addEventListener('click', ()=>{
      const row = b.closest('.part-row');
      const card = b.closest('.item-card');
      const itemId = card?.dataset.id;
      const partId = row?.dataset.part;
      const it = S.items.find(x=>x.id === itemId);
      if(!it || !Array.isArray(it.parts)) return;
      it.parts = it.parts.filter(p=>p.id !== partId);
      renderItems(); renderItemsTotal(); save();
    });
  });
  wrap.querySelectorAll('.part-edit').forEach(b=>{
    b.addEventListener('click', ()=>{
      const row = b.closest('.part-row');
      const card = b.closest('.item-card');
      const itemId = card?.dataset.id;
      const partId = row?.dataset.part;
      const it = S.items.find(x=>x.id === itemId);
      const p = it?.parts?.find(x=>x.id === partId);
      if(!it || !p) return;
      showPartEdit(itemId, partId, it, p);
    });
  });
}
function esc(s){
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function dateTr(d){
  const [y,m,day] = d.split('-');
  return `${day}.${m}.${y}`;
}
function addItem(){
  if(!Array.isArray(S.items)) S.items = [];
  const name = $('itName')?.value.trim();
  if(!name){ setStatus('Eşya adı gerekli.', 'err'); return; }
  const current = parseFloat($('itCurrent')?.value) || 0;
  const initial = parseFloat($('itInitial')?.value) || 0;
  const cat = $('itCat')?.value || 'Diğer';
  const date = $('itDate')?.value || '';
  S.items.push({id: uid(), name, category: cat, current, initial, date});
  $('itName').value = ''; $('itCurrent').value = ''; $('itInitial').value = ''; $('itDate').value = '';
  renderItems(); renderItemsTotal(); save();
  $('itName').focus();
}
function editItemInline(id, it){
  const card = document.querySelector(`.item-card[data-id="${id}"]`);
  if(!card) return;
  card.classList.add('editing');
  const left = card.querySelector('.it-left');
  const right = card.querySelector('.it-right');
  const actions = card.querySelector('.it-actions');
  left.innerHTML = `
    <input class="it-edit-name" value="${esc(it.name)}" style="width:100%">
    <select class="it-edit-cat" style="width:100%">${['Bilgisayar','Çevre Birimleri','Hatıra Paralar','Telefon','Diğer'].map(c=>`<option${c===it.category?' selected':''}>${c}</option>`).join('')}</select>
    <input class="it-edit-date" type="date" value="${esc(it.date)}" style="width:100%">`;
  right.innerHTML = `
    <input class="it-edit-current" type="number" step="any" min="0" value="${it.current||0}" placeholder="Güncel ₺">
    <input class="it-edit-initial" type="number" step="any" min="0" value="${it.initial||0}" placeholder="Alış ₺">`;
  actions.innerHTML = '<button class="mini-btn it-save">Kaydet</button> <button class="mini-btn it-cancel">İptal</button>';
  const updateState = ()=>{
    it.name = left.querySelector('.it-edit-name').value.trim() || it.name;
    it.category = left.querySelector('.it-edit-cat').value;
    it.date = left.querySelector('.it-edit-date').value;
    it.current = parseFloat(right.querySelector('.it-edit-current').value) || 0;
    it.initial = parseFloat(right.querySelector('.it-edit-initial').value) || 0;
  };
  // canlı değer değişince sadece okuma alanlarını güncelle, kartı yeniden çizme (fokus koruma)
  const curIn = right.querySelector('.it-edit-current');
  const initIn = right.querySelector('.it-edit-initial');
  const liveUpdate = ()=>{
    const c = parseFloat(curIn.value)||0, i = parseFloat(initIn.value)||0;
    const d = c - i;
    const ds = card.querySelector('.it-delta-sm') || document.createElement('div');
    ds.className = `it-delta-sm ${d>0?'pos':(d<0?'neg':'')}`;
    ds.textContent = `${d>0?'+':''}${fmtTL(Math.abs(d))} · ${fmtPct(c,i)}`;
  };
  curIn.addEventListener('input', liveUpdate);
  initIn.addEventListener('input', liveUpdate);
  actions.querySelector('.it-save').addEventListener('click', ()=>{
    updateState(); renderItems(); renderItemsTotal(); save();
  });
  actions.querySelector('.it-cancel').addEventListener('click', ()=>{ renderItems(); });
}

function renderParts(it){
  if(!Array.isArray(it.parts) || it.parts.length === 0) return '';
  let rows = it.parts.map(p=>{
    const d = (p.current||0)-(p.initial||0);
    const cls = d>0?'pos':(d<0?'neg':'');
    const sign = d>0?'+':'';
    return `<div class="part-row" data-part="${esc(p.id)}">
      <span class="part-name">${esc(p.name||'İsimsiz')}</span>
      <span class="part-cur">${fmtTL(p.current||0)}</span>
      <span class="part-init">Alış ${fmtTL(p.initial||0)}</span>
      <span class="part-delta ${cls}">${sign}${fmtTL(Math.abs(d))} · ${fmtPct(p.current||0, p.initial||0)}</span>
      <span class="part-actions">
        <button class="mini-btn part-edit">Düzenle</button>
        <button class="mini-btn part-del">Sil</button>
      </span>
    </div>`;
  }).join('');
  return `<div class="parts-list" data-owner="${esc(it.id)}">${rows}</div>`;
}
function showPartAdd(itemId, it){
  const card = document.querySelector(`.item-card[data-id="${itemId}"]`);
  if(!card) return;
  let box = card.querySelector('.part-add-box');
  if(box){ box.remove(); return; }
  box = document.createElement('div');
  box.className = 'part-add-box';
  box.innerHTML = `
    <input class="part-name-in" placeholder="Parça adı (örn. RX 9070 XT)" style="flex:1">
    <input class="part-cur-in" type="number" step="any" min="0" inputmode="decimal" placeholder="Güncel ₺" style="max-width:110px">
    <input class="part-init-in" type="number" step="any" min="0" inputmode="decimal" placeholder="Alış ₺" style="max-width:110px">
    <button class="mini-btn part-save">Kaydet</button>
    <button class="mini-btn part-cancel">İptal</button>`;
  card.appendChild(box);
  box.querySelector('.part-save').addEventListener('click', ()=>{
    const name = box.querySelector('.part-name-in').value.trim();
    if(!name){ setStatus('Parça adı gerekli.', 'err'); return; }
    const current = parseFloat(box.querySelector('.part-cur-in').value) || 0;
    const initial = parseFloat(box.querySelector('.part-init-in').value) || 0;
    if(!Array.isArray(it.parts)) it.parts = [];
    it.parts.push({id: uid(), name, current, initial});
    renderItems(); renderItemsTotal(); save();
  });
  box.querySelector('.part-cancel').addEventListener('click', ()=> box.remove());
}
function showPartEdit(itemId, partId, it, p){
  const row = document.querySelector(`.item-card[data-id="${itemId}"] .part-row[data-part="${partId}"]`);
  if(!row) return;
  row.classList.add('editing');
  row.innerHTML = `
    <input class="part-name-in" value="${esc(p.name)}" style="flex:1">
    <input class="part-cur-in" type="number" step="any" min="0" inputmode="decimal" value="${p.current||0}" placeholder="Güncel ₺" style="max-width:110px">
    <input class="part-init-in" type="number" step="any" min="0" inputmode="decimal" value="${p.initial||0}" placeholder="Alış ₺" style="max-width:110px">
    <button class="mini-btn part-save">Kaydet</button>
    <button class="mini-btn part-cancel">İptal</button>`;
  const save = ()=>{
    p.name = row.querySelector('.part-name-in').value.trim() || p.name;
    p.current = parseFloat(row.querySelector('.part-cur-in').value) || 0;
    p.initial = parseFloat(row.querySelector('.part-init-in').value) || 0;
    renderItems(); renderItemsTotal(); save();
  };
  row.querySelector('.part-save').addEventListener('click', save);
  row.querySelector('.part-cancel').addEventListener('click', ()=> renderItems());
}

function bindRates(){
  $('usdRate').value  = S.usdRate;
  $('eurRate').value  = S.eurRate;
  $('goldRate').value = S.goldRate;
  $('xautRate').value = S.xautRate;
  $('btcRate').value  = S.btcRate;
  $('ethRate').value  = S.ethRate;
  if($('goalInput')) $('goalInput').value = S.goal > 0 ? S.goal : '';
}

/* ---------- abonelikler ---------- */
function subToUsd(amt, cur){
  if(cur==='USD') return amt;
  if(cur==='TL') return amt / S.usdRate;
  if(cur==='EUR') return amt * S.eurRate / S.usdRate;
  return 0;
}
function subMonthlyUsd(sub){
  const v = subToUsd(sub.amt, sub.cur);
  if(sub.period==='yearly') return v / 12;
  if(sub.period==='weekly') return v * 52 / 12;
  return v;
}
function subNextDate(sub){
  const today = new Date();
  const y = today.getFullYear(), m = today.getMonth();
  if(sub.period==='monthly' || sub.period==='yearly'){
    let target = new Date(y, m, Math.min(sub.day || 1, new Date(y,m+1,0).getDate()));
    if(target < today) target = new Date(y, m + (sub.period==='yearly' ? 12 : 1), Math.min(sub.day || 1, new Date(y,m+(sub.period==='yearly'?12:1)+1,0).getDate()));
    return target;
  }
  if(sub.period==='weekly'){
    const d = new Date(today);
    d.setDate(today.getDate() + ((sub.weekday||0) - today.getDay() + 7) % 7);
    if(d < today) d.setDate(d.getDate() + 7);
    return d;
  }
  return today;
}
function fmtSubMoney(n, cur){
  const sym = {USD:'$', TL:'₺', EUR:'€'}[cur] || cur;
  return `${sym}${fmt(n,2)}`;
}
function fmtSubDate(d){
  if(!(d instanceof Date)) return d;
  return d.toLocaleDateString('tr-TR', {day:'numeric', month:'short'});
}
function daysUntil(d){
  const diff = Math.ceil((d - new Date().setHours(0,0,0,0)) / 86400000);
  if(diff===0) return 'bugün';
  if(diff===1) return 'yarın';
  return `${diff} gün sonra`;
}
function renderSubscriptions(){
  if(!Array.isArray(S.subscriptions)) S.subscriptions = [];
  const list = $('subList');
  if(!list) return;

  // totales
  const active = S.subscriptions.filter(s=>s.active !== false);
  const monthly = active.reduce((sum,s)=>sum+subMonthlyUsd(s), 0);
  $('subMonthlyTotal').textContent = '₺' + fmt(monthly * S.usdRate, 0);
  $('subYearlyTotal').textContent = '₺' + fmt(monthly * 12 * S.usdRate, 0);

  // upcoming
  const upcoming = active.map(s=>{ const d=subNextDate(s); return {s, d, days: Math.ceil((d - new Date().setHours(0,0,0,0))/86400000)}; }).filter(x=>x.days <= 7).sort((a,b)=>a.days-b.days);
  $('subUpcomingCount').textContent = `Yaklaşan: ${upcoming.length}`;
  const box = $('subUpcomingBox');
  if(upcoming.length===0) box.innerHTML = '';
  else{
    let html = '<div class="sub-upcoming"><h4>🗓️ Yaklaşan Ödemeler (7 gün)</h4><ul>';
    for(const u of upcoming){
      html += `<li><strong>${esc(u.s.name)}</strong> — ${fmtSubMoney(u.s.amt, u.s.cur)} · ${fmtSubDate(u.d)} (${daysUntil(u.d)})</li>`;
    }
    html += '</ul></div>';
    box.innerHTML = html;
  }

  // listado
  if(S.subscriptions.length===0){
    list.innerHTML = '<p class="sub-empty">Henüz abonelik yok. Spotify, iCloud+, Netflix gibi üyeliklerini yukarıdan ekle.</p>';
    return;
  }
  const cats = {'müzik':'🎵','video':'🎬','depolama':'☁️','üretkenlik':'🛠️','oyun':'🎮','diğer':'📦'};
  let html = '<div class="sub-list">';
  for(const s of S.subscriptions){
    const d = subNextDate(s);
    const period = {monthly:'aylık', yearly:'yıllık', weekly:'haftalık'}[s.period] || s.period;
    const monthlyEq = subMonthlyUsd(s) * S.usdRate;
    html += `
      <div class="sub-card ${s.active===false?'paused':''}" data-id="${esc(s.id)}">
        <div class="sub-left">
          <span class="sub-name">${esc(cats[s.category]||'📦')} ${esc(s.name || 'İsimsiz')} <span class="sub-cat">${esc(s.category || 'diğer')}</span></span>
          <span class="sub-meta">${fmtSubMoney(s.amt, s.cur)} · ${period} · sonraki: ${fmtSubDate(d)} (${daysUntil(d)}) · aylık eşdeğer: ₺${fmt(monthlyEq,0)}</span>
          ${s.note ? `<div class="sub-note">${esc(s.note)}</div>` : ''}
        </div>
        <div class="sub-right">
          <div class="sub-cur">${fmtSubMoney(s.amt, s.cur)}</div>
          <div class="sub-period">/${period}</div>
          <div class="sub-actions">
            <button class="mini-btn sub-toggle">${s.active===false?'Aktif':'Durdur'}</button>
            <button class="mini-btn sub-edit">Düzenle</button>
            <button class="mini-btn sub-del">Sil</button>
          </div>
        </div>
      </div>`;
  }
  html += '</div>';
  list.innerHTML = html;

  // listeners
  list.querySelectorAll('.sub-del').forEach(b=>{
    b.addEventListener('click', ()=>{
      const id = b.closest('.sub-card')?.dataset.id;
      if(!id) return;
      S.subscriptions = S.subscriptions.filter(x=>x.id !== id);
      renderSubscriptions(); save();
    });
  });
  list.querySelectorAll('.sub-toggle').forEach(b=>{
    b.addEventListener('click', ()=>{
      const id = b.closest('.sub-card')?.dataset.id;
      const s = S.subscriptions.find(x=>x.id===id);
      if(s){ s.active = s.active===false; renderSubscriptions(); save(); }
    });
  });
  list.querySelectorAll('.sub-edit').forEach(b=>{
    b.addEventListener('click', ()=>{
      const id = b.closest('.sub-card')?.dataset.id;
      const s = S.subscriptions.find(x=>x.id===id);
      if(s) editSubscriptionInline(s);
    });
  });
}
function addSubscription(){
  if(!Array.isArray(S.subscriptions)) S.subscriptions = [];
  const name = $('subName').value.trim();
  const amt = parseFloat($('subAmt').value) || 0;
  if(!name || amt<=0){ setStatus('Abonelik adı ve pozitif tutar girin.', 'err'); return; }
  S.subscriptions.push({
    id: uid(),
    name,
    amt,
    cur: $('subCur').value,
    period: $('subPeriod').value,
    day: Math.min(31, Math.max(1, parseInt($('subDay').value)||1)),
    month: 1,
    weekday: 0,
    category: $('subCat').value,
    note: $('subNote').value.trim(),
    active: true,
  });
  $('subName').value=''; $('subAmt').value=''; $('subNote').value=''; $('subDay').value='';
  renderSubscriptions(); save();
}
function editSubscriptionInline(s){
  const card = document.querySelector(`.sub-card[data-id="${CSS.escape(s.id)}"]`);
  if(!card) return;
  card.innerHTML = `
    <div style="grid-column:1 / -1;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <input id="editSubName${esc(s.id)}" value="${esc(s.name)}">
      <input id="editSubAmt${esc(s.id)}" type="number" step="any" value="${s.amt}">
      <select id="editSubCur${esc(s.id)}"><option value="TL" ${s.cur==='TL'?'selected':''}>₺ TL</option><option value="USD" ${s.cur==='USD'?'selected':''}>$ USD</option><option value="EUR" ${s.cur==='EUR'?'selected':''}>€ EUR</option></select>
      <select id="editSubPeriod${esc(s.id)}"><option value="monthly" ${s.period==='monthly'?'selected':''}>Aylık</option><option value="yearly" ${s.period==='yearly'?'selected':''}>Yıllık</option><option value="weekly" ${s.period==='weekly'?'selected':''}>Haftalık</option></select>
      <input id="editSubDay${esc(s.id)}" type="number" value="${s.day}">
      <select id="editSubCat${esc(s.id)}"><option value="müzik" ${s.category==='müzik'?'selected':''}>Müzik</option><option value="video" ${s.category==='video'?'selected':''}>Video</option><option value="depolama" ${s.category==='depolama'?'selected':''}>Depolama</option><option value="üretkenlik" ${s.category==='üretkenlik'?'selected':''}>Üretkenlik</option><option value="oyun" ${s.category==='oyun'?'selected':''}>Oyun</option><option value="diğer" ${s.category==='diğer'?'selected':''}>Diğer</option></select>
      <input id="editSubNote${esc(s.id)}" value="${esc(s.note||'')}" placeholder="Not">
      <button class="mini-btn" id="saveSub${esc(s.id)}">Kaydet</button>
      <button class="mini-btn" id="cancelSub${esc(s.id)}">İptal</button>
    </div>`;
  $(`saveSub${s.id}`).addEventListener('click', ()=>{
    s.name = $(`editSubName${s.id}`).value.trim();
    s.amt = parseFloat($(`editSubAmt${s.id}`).value) || 0;
    s.cur = $(`editSubCur${s.id}`).value;
    s.period = $(`editSubPeriod${s.id}`).value;
    s.day = Math.min(31, Math.max(1, parseInt($(`editSubDay${s.id}`).value)||1));
    s.category = $(`editSubCat${s.id}`).value;
    s.note = $(`editSubNote${s.id}`).value.trim();
    renderSubscriptions(); save();
  });
  $(`cancelSub${s.id}`).addEventListener('click', ()=> renderSubscriptions());
}

function bindAll(){
  $('usdRate').addEventListener('input',  e=>{ S.usdRate  = parseFloat(e.target.value)||0; refresh(false); save(); });
  $('eurRate').addEventListener('input',  e=>{ S.eurRate  = parseFloat(e.target.value)||0; refresh(false); save(); });
  $('goldRate').addEventListener('input', e=>{ S.goldRate = parseFloat(e.target.value)||0; refresh(false); save(); });
  $('xautRate').addEventListener('input', e=>{ S.xautRate = parseFloat(e.target.value)||0; refresh(false); save(); });
  $('btcRate').addEventListener('input',  e=>{ S.btcRate  = parseFloat(e.target.value)||0; refresh(false); save(); });
  $('ethRate').addEventListener('input',  e=>{ S.ethRate  = parseFloat(e.target.value)||0; refresh(false); save(); });
  $('goalInput')?.addEventListener('input', e=>{ S.goal = parseFloat(e.target.value)||0; renderHero(); save(); });
  $('addGoal')?.addEventListener('click', ()=>{
    S.goals = S.goals || [];
    S.goals.push({id: uid(), name:'Yeni Hedef', target:0, asset:''});
    renderGoals(); save();
  });
  $('liveBtn').addEventListener('click', fetchRates);
  $('stockRefreshBtn').addEventListener('click', fetchStockPrices);
  $('stAddBtn').addEventListener('click', addStock);
  $('stBuy').addEventListener('keydown', e=>{ if(e.key==='Enter') addStock(); });
  $('btnTL').addEventListener('click', ()=>setCur('TL'));
  $('btnUSD').addEventListener('click', ()=>setCur('USD'));
  $('rng7').addEventListener('click', ()=>setChartRange(7));
  $('rng30').addEventListener('click', ()=>setChartRange(30));
  $('rng90').addEventListener('click', ()=>setChartRange(90));
  $('rngAll').addEventListener('click', ()=>setChartRange(0));
  document.querySelectorAll('.tab').forEach(b=>b.addEventListener('click', ()=>setTab(b.dataset.tab)));
  $('itAddBtn')?.addEventListener('click', addItem);
  $('itName')?.addEventListener('keydown', e=>{ if(e.key==='Enter') addItem(); });
  $('subAddBtn')?.addEventListener('click', addSubscription);
  $('subName')?.addEventListener('keydown', e=>{ if(e.key==='Enter') addSubscription(); });
  $('addAsset').addEventListener('click', ()=>{
    S.assets.push({id: uid(), name:'Yeni Varlık', base:0, cur:'TL'});
    refresh(true); save();
    const ins = document.querySelectorAll('#assetList .name input');
    const last = ins[ins.length-1];
    if(last){ last.focus(); last.select(); }
  });
  $('addRec').addEventListener('click', ()=>{
    S.recurring.push({id: uid(), kind:'gider', desc:'', amt:0, cur:'TL', asset:'',
      day:1, start: new Date().toISOString().slice(0,10), last:''});
    renderRec(); save();
    const ins = document.querySelectorAll('#recList .ds');
    const lastIn = ins[ins.length-1];
    if(lastIn) lastIn.focus();
  });
  document.querySelectorAll('[data-add]').forEach(b=>{
    b.addEventListener('click', ()=>{
      const kind = b.dataset.add;
      S[kind].push({date:new Date().toISOString().slice(0,10), desc:'', amt:0, cur:'TL', asset:''});
      renderTx(kind); renderTotals(); save();
    });
  });
  bindData();
}
function setCur(c){
  currency = c;
  $('btnTL').classList.toggle('on', c==='TL');
  $('btnUSD').classList.toggle('on', c==='USD');
  renderHero();
  renderChart();
}

(async function init(){
  try{
    const r = await api('state');
    S = r.state;
    if(!S.history || Array.isArray(S.history)) S.history = {};
    if(!Array.isArray(S.recurring)) S.recurring = [];
  }catch(e){
    setStatus('Sunucudan veri alınamadı: ' + e.message, 'err');
    return;
  }
  if(!Array.isArray(S.items)) S.items = [];
  bindRates();
  bindAll();
  refresh(true);
  setTab('networth');
  await fetchRates();
  snapshot();
})();
</script>
</body>
</html>
