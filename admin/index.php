<?php
/* 開発者用の管理ページ：https://yomikai.tsunashiman.com/admin/
   テスター別のまとめ・利用ログ・ご意見・ランキング・文別の成績を見る。CSV でも取り出せる。
   入るには合言葉（GitHub の Secret ADMIN_KEY → サーバーの secrets/db.json の admin_key）が必要。合っていれば 30 日有効のクッキーを置く。
   アプリからはどこにもリンクしていない。検索エンジンにも載せない（noindex）。 */
declare(strict_types=1);
require __DIR__ . '/../api/_lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

const COOKIE = 'lrta_adm';
function h(mixed $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function admin_key(): string { try { return (string)(cfg()['admin_key'] ?? ''); } catch (Throwable $e) { return ''; } }
function token(int $exp): string { return $exp . '.' . hash_hmac('sha256', (string)$exp, secret_salt('admin|' . admin_key())); }
function token_ok(string $t): bool
{
    if (admin_key() === '' || !preg_match('/^(\d+)\.([0-9a-f]{64})$/', $t, $m)) return false;
    return (int)$m[1] > time() && hash_equals(token((int)$m[1]), $t);
}
function logged_in(): bool { return token_ok((string)($_COOKIE[COOKIE] ?? '')); }
function set_login_cookie(bool $on): void
{
    $exp = $on ? time() + 30 * 86400 : time() - 3600;
    setcookie(COOKIE, $on ? token($exp) : '', ['expires' => $exp, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}
function page(string $title, string $body, bool $nav = true): never
{
    $views = ['summary' => 'テスター別まとめ', 'log' => '利用ログ', 'feedback' => 'ご意見・評価', 'ranking' => 'ランキング', 'items' => '文別の成績', 'accounts' => 'アカウント', 'sales' => '売上'];
    $cur = (string)($_GET['v'] ?? 'summary');
    $links = '';
    if ($nav) foreach ($views as $k => $name) $links .= '<a href="?v=' . $k . '"' . ($cur === $k ? ' class="on"' : '') . '>' . $name . '</a>';
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . h($title) . ' | 速読トレ 管理</title>
<style>
:root{--ink:#1d2433;--mut:#6b7280;--line:#e5e7eb;--bg:#f6f7fb;--card:#fff;--acc:#2d6cdf;--ok:#1a9a5b;--ng:#d04c4c}
*{box-sizing:border-box}body{margin:0;font:14px/1.6 -apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;color:var(--ink);background:var(--bg)}
header{background:#fff;border-bottom:1px solid var(--line);padding:10px 16px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}
header b{font-size:15px}nav a{display:inline-block;padding:6px 10px;border-radius:8px;color:var(--ink);text-decoration:none;margin-right:2px}nav a.on{background:#e8efff;color:var(--acc);font-weight:600}
main{padding:16px;max-width:1400px;margin:0 auto}h1{font-size:18px;margin:4px 0 10px}h2{font-size:15px;margin:18px 0 6px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:14px;overflow:auto}
table{border-collapse:collapse;width:100%;font-size:13px}th,td{border-bottom:1px solid var(--line);padding:6px 8px;text-align:left;vertical-align:top;white-space:nowrap}th{background:#fafbfe;position:sticky;top:0}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}td.wrap{white-space:pre-wrap;min-width:220px;max-width:520px}
.mut{color:var(--mut)}.small{font-size:12px}.ok{color:var(--ok)}.ng{color:var(--ng)}.pill{display:inline-block;padding:1px 8px;border-radius:999px;background:#eef1f7;font-size:12px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}input,select,button{font:inherit}input[type=text],input[type=password],input[type=date],select{padding:7px 10px;border:1px solid #cfd4dd;border-radius:8px}
button,.btn{padding:7px 14px;border:0;border-radius:8px;background:var(--acc);color:#fff;cursor:pointer;text-decoration:none;display:inline-block}.btn.ghost{background:#eef1f7;color:var(--ink)}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.kpi div{background:#fafbfe;border:1px solid var(--line);border-radius:10px;padding:8px 10px}.kpi b{font-size:20px;display:block}
.login{max-width:420px;margin:60px auto}
</style></head><body><header><b>まじめに速読トレ　管理</b>' . ($nav ? '<nav>' . $links . '</nav><span style="margin-left:auto" class="small"><a href="../api/health.php" target="_blank" rel="noopener">状態</a>　<a href="?logout=1">ログアウト</a></span>' : '') . '</header><main>' . $body . '</main></body></html>';
    exit;
}

/* ---- ログイン ---- */
if (isset($_GET['logout'])) { set_login_cookie(false); header('Location: index.php'); exit; }
if (!logged_in()) {
    $msg = '';
    if (admin_key() === '') {
        page('設定待ち', '<div class="card login"><h1>管理ページの準備ができていません</h1><p>合言葉（GitHub の Secret <b>ADMIN_KEY</b>）がまだサーバーに届いていません。Secret を登録して、GitHub Actions の「さくらへ反映」を一度動かしてください。</p></div>', false);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try { rate_limit(db(), 'admin-login', 8, 300); } catch (Throwable $e) { /* DB 不調でも合言葉の照合はできる */ }
        $k = (string)($_POST['key'] ?? '');
        if ($k !== '' && hash_equals(admin_key(), $k)) { set_login_cookie(true); header('Location: index.php'); exit; }
        usleep(400000);
        $msg = '<p class="ng">合言葉が違います。</p>';
    }
    page('ログイン', '<form class="card login" method="post" autocomplete="off"><h1>管理ページ</h1><p class="mut small">開発者だけが入る画面です。GitHub の Secret「ADMIN_KEY」に登録した合言葉を入れてください。</p>' . $msg .
        '<p><input type="password" name="key" placeholder="合言葉" style="width:100%" autofocus required></p><p><button type="submit">入る</button></p></form>', false);
}

/* ---- ここから中身（ログイン済み） ---- */
try { $pdo = db(); } catch (Throwable $e) { page('DB エラー', '<div class="card"><h1>データベースにつながりません</h1><p>原因の種類：' . h(err_kind($e)) . '（<a href="../api/health.php">health.php</a> も参照）</p></div>'); }
$v = (string)($_GET['v'] ?? 'summary');
$csv = isset($_GET['csv']);
$q = function (string $sql, array $p = []) use ($pdo): array { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); };

function csv_out(string $name, array $head, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '_' . date('Ymd-Hi') . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, $head);
    foreach ($rows as $r) fputcsv($f, array_map(fn($x) => is_scalar($x) || $x === null ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE), array_values($r)));
    exit;
}
function fmt_min(int $ms): string { $m = $ms / 60000; return $m >= 100 ? round($m) . '分' : ($m >= 10 ? round($m, 1) . '分' : round($m, 1) . '分'); }
function pct(int $ok, int $n): string { return $n > 0 ? round($ok / $n * 100) . '%' : '—'; }
function dt(?string $s): string { return $s ? substr($s, 0, 16) : '—'; }
$tester = strtoupper(s($_GET['tester'] ?? '', 16));
$w = $tester !== '' ? ' AND `tester_id` = ' . $pdo->quote($tester) : '';
/* ---- 削除（自分の試し打ちや、おかしな記録を消す）。フォームの印（ログインのクッキーと同じ値）が合うときだけ ---- */
$csrf = (string)($_COOKIE[COOKIE] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['del'])) {
    if (!hash_equals($csrf, (string)($_POST['t'] ?? ''))) page('エラー', '<div class="card"><h1>やり直してください</h1><p>画面を開き直してから、もう一度削除してください。</p></div>');
    $del = (string)$_POST['del']; $n = 0;
    if ($del === 'tester') {
        $tid = strtoupper(s($_POST['id'] ?? '', 16));
        if ($tid !== '') {
            foreach (['usage_items', 'usage_events', 'usage_batches', 'feedback', 'daily_results'] as $t) { $st = $pdo->prepare('DELETE FROM `' . $t . '` WHERE `app_id` = ? AND `tester_id` = ?'); $st->execute([APP_ID, $tid]); $n += $st->rowCount(); }
        }
        header('Location: index.php?v=summary&deleted=' . $n); exit;
    }
    if ($del === 'rank') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $st = $pdo->prepare('DELETE FROM `daily_results` WHERE `app_id` = ? AND `id` = ?'); $st->execute([APP_ID, $id]); $n = $st->rowCount(); }
        header('Location: index.php?v=ranking&date=' . h(s($_POST['date'] ?? '', 10)) . '&deleted=' . $n); exit;
    }
    if ($del === 'feedback') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $st = $pdo->prepare('DELETE FROM `feedback` WHERE `app_id` = ? AND `id` = ?'); $st->execute([APP_ID, $id]); $n = $st->rowCount(); }
        header('Location: index.php?v=feedback&deleted=' . $n); exit;
    }
    if ($del === 'account') { /* アカウントとそのセッション・プランを削除（利用者からの削除依頼に使う） */
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { foreach (['sessions' => 'account_id', 'entitlements' => 'account_id', 'accounts' => 'id'] as $t => $col) { $st = $pdo->prepare('DELETE FROM `' . $t . '` WHERE `' . $col . '` = ?'); $st->execute([$id]); $n += $st->rowCount(); } }
        header('Location: index.php?v=accounts&deleted=' . $n); exit;
    }
    if ($del === 'sessions') { /* ログイン状態を全端末で解除（本人からの依頼・不正時） */
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $st = $pdo->prepare('UPDATE `sessions` SET `revoked_at` = NOW() WHERE `account_id` = ? AND `revoked_at` IS NULL'); $st->execute([$id]); $n = $st->rowCount(); }
        header('Location: index.php?v=accounts&q=' . urlencode((string)($_POST['q'] ?? '')) . '&revoked=' . $n); exit;
    }
}
/* ---- プランの手動設定（ギフト・テスター・返金対応など）。決済連携ができるまでの運用と、連携後の例外対応に使う ---- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['setplan'])) {
    if (!hash_equals($csrf, (string)($_POST['t'] ?? ''))) page('エラー', '<div class="card"><h1>やり直してください</h1><p>画面を開き直してから、もう一度操作してください。</p></div>');
    $aid = (int)($_POST['id'] ?? 0); $plan = s($_POST['plan'] ?? 'free', 24); $until = s($_POST['until'] ?? '', 10); $note = s($_POST['note'] ?? '', 200);
    if (!in_array($plan, ['free', 'month', 'year', 'gift', 'tester'], true)) $plan = 'free';
    if ($until !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) $until = '';
    if ($aid > 0) {
        $pdo->prepare('INSERT INTO `entitlements` (`account_id`, `app_id`, `plan`, `until`, `source`, `ref`, `status`, `updated_at`, `note`) VALUES (?, ?, ?, ?, \'manual\', \'\', \'active\', NOW(), ?)
            ON DUPLICATE KEY UPDATE `plan` = ?, `until` = ?, `source` = \'manual\', `status` = \'active\', `updated_at` = NOW(), `note` = ?')
            ->execute([$aid, APP_ID, $plan, $until !== '' ? $until : null, $note, $plan, $until !== '' ? $until : null, $note]);
    }
    header('Location: index.php?v=accounts&q=' . urlencode((string)($_POST['q'] ?? '')) . '&saved=1'); exit;
}
/* PAY.JP との照合（アカウントの行の「PAY.JP と照合」）：定期課金の状態を問い合わせ直して反映する */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['paysync'])) {
    if (!hash_equals($csrf, (string)($_POST['t'] ?? ''))) page('エラー', '<div class="card"><h1>やり直してください</h1><p>画面を開き直してから、もう一度操作してください。</p></div>');
    $aid = (int)($_POST['id'] ?? 0); $msg = 'synced';
    if ($aid > 0) { try { $r = payjp_sync($pdo, $aid, true); $msg = $r ? 'synced' : 'nosub'; } catch (Throwable $e) { $msg = 'error'; } }
    header('Location: index.php?v=accounts&q=' . urlencode((string)($_POST['q'] ?? '')) . '&paysync=' . $msg); exit;
}
function del_form(string $kind, string $id, string $label, string $confirm, string $extra = ''): string
{
    global $csrf;
    return '<form method="post" style="display:inline" onsubmit="return confirm(' . h(json_encode($confirm, JSON_UNESCAPED_UNICODE)) . ')"><input type="hidden" name="del" value="' . h($kind) . '"><input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="t" value="' . h($csrf) . '">' . $extra . '<button type="submit" class="btn ghost" style="padding:2px 8px;font-size:12px;color:var(--ng)">' . h($label) . '</button></form>';
}
$deletedNote = isset($_GET['deleted']) ? '<p class="small ok">削除しました（' . (int)$_GET['deleted'] . ' 件）。</p>' : '';

if ($v === 'summary') {
    $rows = $q('SELECT `tester_id`, MIN(`at`) first_at, MAX(`at`) last_at, COUNT(DISTINCT DATE(`at`)) days,
        SUM(`type` = \'open\') opens, SUM(`type` = \'set\') sets, SUM(`type` = \'set\' AND `kind` = \'daily\') dailies,
        SUM(CASE WHEN `type` = \'set\' THEN `ms` ELSE 0 END) play_ms, SUM(CASE WHEN `type` = \'session\' THEN `ms` ELSE 0 END) sess_ms,
        SUM(CASE WHEN `type` = \'set\' THEN `done` ELSE 0 END) done_q, SUM(CASE WHEN `type` = \'set\' THEN `ok` ELSE 0 END) ok_q,
        SUM(`type` = \'act\' AND `what` = \'feedback\') fb_acts, SUM(`type` = \'err\') errs
        FROM `usage_events` WHERE `app_id` = ? GROUP BY `tester_id` ORDER BY last_at DESC', [APP_ID]);
    $modes = [];
    foreach ($q('SELECT `tester_id`, `what`, COUNT(*) c FROM `usage_events` WHERE `app_id` = ? AND `type` = \'set\' GROUP BY `tester_id`, `what`', [APP_ID]) as $r) $modes[$r['tester_id']][] = $r['what'] . ' ' . $r['c'];
    $fbc = [];
    foreach ($q('SELECT `tester_id`, COUNT(*) c, AVG(`rating`) avg_r FROM `feedback` WHERE `app_id` = ? GROUP BY `tester_id`', [APP_ID]) as $r) $fbc[$r['tester_id']] = $r;
    $last = [];
    foreach ($q('SELECT b.`tester_id`, b.`platform`, b.`standalone`, b.`build`, b.`edition`, b.`w`, b.`h` FROM `usage_batches` b JOIN (SELECT `tester_id`, MAX(`id`) mid FROM `usage_batches` WHERE `app_id` = ? GROUP BY `tester_id`) m ON m.mid = b.`id`', [APP_ID]) as $r) $last[$r['tester_id']] = $r;
    $devices = [];
    foreach ($q('SELECT `tester_id`, COUNT(DISTINCT `did`) c FROM `usage_events` WHERE `app_id` = ? GROUP BY `tester_id`', [APP_ID]) as $r) $devices[$r['tester_id']] = (int)$r['c'];
    $fbNoLog = $q('SELECT `tester_id`, COUNT(*) c, AVG(`rating`) avg_r, MAX(`received_at`) last_at FROM `feedback` WHERE `app_id` = ? AND `tester_id` NOT IN (SELECT DISTINCT `tester_id` FROM `usage_events` WHERE `app_id` = ?) GROUP BY `tester_id`', [APP_ID, APP_ID]);
    $head = ['テスターID', '初回', '最終', '利用日数', '起動', 'セット数', 'うちランキング', 'プレイ時間', '滞在時間', '回答数', '正答率', 'モード別', 'ご意見', '端末', '版', 'エラー', '削除'];
    $table = [];
    foreach ($rows as $r) {
        $t = $r['tester_id']; $l = $last[$t] ?? [];
        $table[] = [$t !== '' ? $t : '（IDなし）', dt($r['first_at']), dt($r['last_at']), (int)$r['days'], (int)$r['opens'], (int)$r['sets'], (int)$r['dailies'], fmt_min((int)$r['play_ms']), fmt_min((int)$r['sess_ms']), (int)$r['done_q'], pct((int)$r['ok_q'], (int)$r['done_q']),
            implode('／', $modes[$t] ?? []), isset($fbc[$t]) ? $fbc[$t]['c'] . ' 件（平均★' . round((float)$fbc[$t]['avg_r'], 1) . '）' : '0',
            ($l['platform'] ?? '') . (!empty($l['standalone']) ? '・インストール済' : '') . (($devices[$t] ?? 1) > 1 ? '・' . $devices[$t] . ' 台' : ''), ($l['build'] ?? '') . ' ' . ($l['edition'] ?? ''), (int)$r['errs'], ''];
    }
    if ($csv) csv_out('テスター別まとめ', $head, array_map(fn($r) => array_slice($r, 0, 16), $table));
    $tot = $q('SELECT COUNT(DISTINCT `tester_id`) testers, COUNT(DISTINCT `did`) devices, SUM(`type` = \'set\') sets, SUM(CASE WHEN `type` = \'set\' THEN `ms` ELSE 0 END) play_ms FROM `usage_events` WHERE `app_id` = ?', [APP_ID])[0];
    $fbt = $q('SELECT COUNT(*) c, AVG(`rating`) avg_r FROM `feedback` WHERE `app_id` = ?', [APP_ID])[0];
    $rk = $q('SELECT COUNT(*) c, COUNT(DISTINCT `day`) days FROM `daily_results` WHERE `app_id` = ?', [APP_ID])[0];
    $b = '<h1>テスター別まとめ</h1>' . $deletedNote . '<div class="card kpi"><div><span class="mut small">テスター（ログあり）</span><b>' . (int)$tot['testers'] . '</b></div><div><span class="mut small">端末</span><b>' . (int)$tot['devices'] . '</b></div><div><span class="mut small">セット数</span><b>' . (int)$tot['sets'] . '</b></div><div><span class="mut small">プレイ時間</span><b>' . fmt_min((int)$tot['play_ms']) . '</b></div><div><span class="mut small">ご意見</span><b>' . (int)$fbt['c'] . '</b><span class="small mut">平均★' . ($fbt['c'] ? round((float)$fbt['avg_r'], 1) : '—') . '</span></div><div><span class="mut small">ランキング参加</span><b>' . (int)$rk['c'] . '</b><span class="small mut">' . (int)$rk['days'] . ' 日分</span></div></div>';
    $b .= '<div class="card"><div class="row"><span class="mut small">利用ログはテスターコードが有効な端末からだけ届きます。プレイ時間＝セットの開始から終了まで、滞在時間＝アプリの画面を開いていた時間。</span><a class="btn ghost" style="margin-left:auto" href="?v=summary&csv=1">CSV</a></div><table><tr>' . implode('', array_map(fn($x) => '<th>' . h($x) . '</th>', $head)) . '</tr>';
    foreach ($table as $i => $r) { $t = $rows[$i]['tester_id']; $b .= '<tr>' . implode('', array_map(fn($x, $j) => '<td' . (in_array($j, [3, 4, 5, 6, 9, 15], true) ? ' class="num"' : '') . '>' . ($j === 0 ? '<a href="?v=log&tester=' . h($t) . '">' . h($x) . '</a>' : ($j === 16 ? ($t !== '' ? del_form('tester', $t, '削除', 'テスターID ' . $t . ' の利用ログ・ご意見・ランキングの記録をすべて削除します。元に戻せません。よろしいですか？') : '') : h($x))) . '</td>', $r, array_keys($r))) . '</tr>'; }
    if (!$table) $b .= '<tr><td colspan="17" class="mut">まだ利用ログが届いていません。</td></tr>';
    $b .= '</table><p class="small mut">「削除」は、自分の試し打ちなど、そのテスターIDの記録をまとめて消すためのもの（ご意見・ランキングの記録も消える）。</p></div>';
    if ($fbNoLog) { $b .= '<h2>ご意見だけ届いている（利用ログなし）</h2><div class="card"><table><tr><th>テスターID</th><th class="num">件数</th><th>平均★</th><th>最終</th></tr>'; foreach ($fbNoLog as $r) $b .= '<tr><td>' . h($r['tester_id'] !== '' ? $r['tester_id'] : '（IDなし＝通常版）') . '</td><td class="num">' . (int)$r['c'] . '</td><td>' . round((float)$r['avg_r'], 1) . '</td><td>' . dt($r['last_at']) . '</td></tr>'; $b .= '</table></div>'; }
    page('テスター別まとめ', $b);
}

if ($v === 'log') {
    $type = s($_GET['type'] ?? '', 12);
    $wt = $type !== '' ? ' AND `type` = ' . $pdo->quote($type) : '';
    $rows = $q('SELECT `id`, `at`, `tester_id`, `did`, `type`, `what`, `kind`, `n`, `done`, `ok`, `ms`, `score`, `data` FROM `usage_events` WHERE `app_id` = ?' . $w . $wt . ' ORDER BY `at` DESC, `id` DESC LIMIT 500', [APP_ID]);
    $head = ['日時', 'テスター', '端末', '種類', '内容', '問題数', '回答', '正解', '時間', 'スコア', '詳細'];
    $table = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        unset($d['qs']);
        $detail = [];
        foreach ($d as $k => $x) { if ($x === '' || $x === null) continue; $detail[] = $k . '=' . (is_scalar($x) ? $x : json_encode($x, JSON_UNESCAPED_UNICODE)); }
        $table[] = [substr((string)$r['at'], 0, 19), $r['tester_id'], $r['did'], $r['type'], $r['what'] . ($r['kind'] !== '' ? '（' . $r['kind'] . '）' : ''), $r['type'] === 'set' ? (int)$r['n'] : '', $r['type'] === 'set' ? (int)$r['done'] : '', $r['type'] === 'set' ? (int)$r['ok'] : '', $r['ms'] ? round($r['ms'] / 1000) . '秒' : '', $r['score'] !== null ? (int)$r['score'] : '', implode('　', $detail)];
    }
    if ($csv) csv_out('利用ログ' . ($tester !== '' ? '_' . $tester : ''), $head, $table);
    $testers = $q('SELECT DISTINCT `tester_id` FROM `usage_events` WHERE `app_id` = ? ORDER BY `tester_id`', [APP_ID]);
    $b = '<h1>利用ログ</h1><div class="card"><form class="row" method="get"><input type="hidden" name="v" value="log"><label>テスター <select name="tester"><option value="">すべて</option>';
    foreach ($testers as $t) $b .= '<option value="' . h($t['tester_id']) . '"' . ($t['tester_id'] === $tester ? ' selected' : '') . '>' . h($t['tester_id'] !== '' ? $t['tester_id'] : '（IDなし）') . '</option>';
    $b .= '</select></label><label>種類 <select name="type"><option value="">すべて</option>';
    foreach (['open' => '起動', 'session' => '滞在', 'set' => 'セット', 'act' => '操作', 'err' => 'エラー'] as $k => $nm) $b .= '<option value="' . $k . '"' . ($type === $k ? ' selected' : '') . '>' . $nm . '</option>';
    $b .= '</select></label><button type="submit">表示</button><a class="btn ghost" style="margin-left:auto" href="?v=log&tester=' . h($tester) . '&type=' . h($type) . '&csv=1">CSV</a></form>';
    $b .= '<p class="small mut">新しい順に 500 件まで。種類：open＝起動、session＝画面を離れるまでの滞在、set＝1 セット（モード・問題数・正解数・所要時間）、act＝機能の利用、err＝エラー。</p><table><tr>' . implode('', array_map(fn($x) => '<th>' . h($x) . '</th>', $head)) . '</tr>';
    foreach ($table as $r) $b .= '<tr>' . implode('', array_map(fn($x, $j) => '<td class="' . ($j >= 5 && $j <= 9 ? 'num' : ($j === 10 ? 'wrap small mut' : '')) . '">' . h($x) . '</td>', $r, array_keys($r))) . '</tr>';
    if (!$table) $b .= '<tr><td colspan="11" class="mut">該当するログはありません。</td></tr>';
    page('利用ログ', $b . '</table></div>');
}

if ($v === 'feedback') {
    $rows = $q('SELECT * FROM `feedback` WHERE `app_id` = ?' . $w . ' ORDER BY `received_at` DESC LIMIT 300', [APP_ID]);
    $priceName = ['high' => '高い', 'bit' => 'やや高い', 'ok' => 'ちょうどよい', 'cheap' => '安い', 'unknown' => 'わからない'];
    $head = ['受信', 'テスター', '版', '総合★', '面白さ', '難しさ', '質', '操作', 'すすめ度', '料金', '良かった点', '改善点・不具合', 'お名前', '連絡先', '引用', 'きっかけ', '端末', '利用者番号'];
    $table = []; $fids = [];
    foreach ($rows as $r) {
        $fids[] = (int)$r['id'];
        $cx = json_decode((string)$r['context_json'], true) ?: [];
        $extra = '';
        if (!empty($cx['extra']['problem'])) $extra = "\n（問題：" . $cx['extra']['problem'] . '）';
        $table[] = [substr((string)$r['received_at'], 0, 16), $r['tester_id'], $r['edition'], (int)$r['rating'], $r['fun'] ?: '', $r['difficulty'] ?: '', $r['quality'] ?: '', $r['usability'] ?: '', $r['nps'] !== null ? (int)$r['nps'] : '', $priceName[$r['price']] ?? $r['price'], $r['good'], $r['improve'] . $extra, $r['name'], $r['contact'], $r['quote_ok'] ? '可' : '', $r['trig'], $r['platform'], (int)$r['uid']];
    }
    if ($csv) csv_out('ご意見', $head, $table);
    $b = '<h1>ご意見・評価</h1>' . $deletedNote . '<div class="card"><div class="row"><span class="small mut">新しい順に 300 件。項目別は 1〜5（空欄＝未回答）、すすめ度は 0〜10。</span><a class="btn ghost" style="margin-left:auto" href="?v=feedback&csv=1">CSV</a></div><table><tr>' . implode('', array_map(fn($x) => '<th>' . h($x) . '</th>', $head)) . '<th>削除</th></tr>';
    foreach ($table as $i => $r) $b .= '<tr>' . implode('', array_map(fn($x, $j) => '<td class="' . (in_array($j, [10, 11], true) ? 'wrap' : (in_array($j, [3, 4, 5, 6, 7, 8, 17], true) ? 'num' : '')) . '">' . h($x) . '</td>', $r, array_keys($r))) . '<td>' . del_form('feedback', (string)$fids[$i], '削除', $r[0] . ' のご意見（★' . $r[3] . '）を削除します。よろしいですか？') . '</td></tr>';
    if (!$table) $b .= '<tr><td colspan="19" class="mut">まだご意見は届いていません。</td></tr>';
    page('ご意見・評価', $b . '</table></div>');
}

if ($v === 'ranking') {
    $days = $q('SELECT `day`, COUNT(*) c, MAX(`score`) top FROM `daily_results` WHERE `app_id` = ? GROUP BY `day` ORDER BY `day` DESC LIMIT 120', [APP_ID]);
    $day = s($_GET['date'] ?? ($days[0]['day'] ?? today()), 10);
    $rows = $q('SELECT * FROM `daily_results` WHERE `app_id` = ? AND `day` = ? ORDER BY `score` DESC, `secs` ASC, `id` ASC', [APP_ID, $day]);
    $head = ['順位', '名前', 'スコア', '正解', '字/分', '合計秒', 'テスター', '端末', 'サブスク相当', '版', '受信'];
    $table = []; $pos = 0; $ids = [];
    foreach ($rows as $r) { $pos++; $ids[] = (int)$r['id']; $table[] = [$pos, $r['handle'], (int)$r['score'], $r['correct'] . '/' . $r['total'], (int)$r['rate'], (float)$r['secs'], $r['tester_id'], $r['did'], $r['pro'] ? '○' : '', $r['build'] . ' ' . $r['edition'], substr((string)$r['received_at'], 0, 16)]; }
    if ($csv) csv_out('ランキング_' . $day, $head, $table);
    $b = '<h1>ランキング</h1>' . $deletedNote . '<div class="card"><form class="row" method="get"><input type="hidden" name="v" value="ranking"><label>日付 <select name="date">';
    foreach ($days as $d) $b .= '<option value="' . h($d['day']) . '"' . ($d['day'] === $day ? ' selected' : '') . '>' . h($d['day']) . '（' . (int)$d['c'] . ' 人・最高 ' . number_format((int)$d['top']) . '）</option>';
    if (!$days) $b .= '<option value="' . h($day) . '">' . h($day) . '</option>';
    $b .= '</select></label><button type="submit">表示</button><a class="btn ghost" style="margin-left:auto" href="?v=ranking&date=' . h($day) . '&csv=1">CSV</a></form><p class="small mut">その日の「今日の20問」の初回の結果（端末ごとに 1 件）。順位はスコア → 合計秒 → 先着。アプリの順位表では、参加者が 10 人未満のあいだはサンプルプレイヤーも並べて表示します。</p><table><tr>' . implode('', array_map(fn($x) => '<th>' . h($x) . '</th>', $head)) . '</tr>';
    $b = str_replace('<th>受信</th></tr>', '<th>受信</th><th>削除</th></tr>', $b);
    foreach ($table as $i => $r) $b .= '<tr>' . implode('', array_map(fn($x, $j) => '<td class="' . (in_array($j, [0, 2, 4, 5], true) ? 'num' : '') . '">' . h($x) . '</td>', $r, array_keys($r))) . '<td>' . del_form('rank', (string)$ids[$i], '削除', $day . ' の「' . $r[1] . '」（スコア ' . $r[2] . '）の記録を削除します。よろしいですか？', '<input type="hidden" name="date" value="' . h($day) . '">') . '</td></tr>';
    if (!$table) $b .= '<tr><td colspan="12" class="mut">この日の記録はありません。</td></tr>';
    page('ランキング', $b . '</table><p class="small mut">「削除」は、自分の試し打ちや不正と思われる記録を順位表から外すためのもの（その端末のアプリ側の表示は次に順位表を開いたときに変わる）。</p></div>');
}

if ($v === 'items') {
    $rows = $q('SELECT `text`, `qkind`, `genre`, `lv`, COUNT(*) n, SUM(`ok`) ok, AVG(`secs`) avg_secs, COUNT(DISTINCT `tester_id`) testers FROM `usage_items` WHERE `app_id` = ?' . $w . ' GROUP BY `text`, `qkind`, `genre`, `lv` ORDER BY n DESC, ok ASC LIMIT 500', [APP_ID]);
    $head = ['本文（先頭30字）', '種類', '分野', '長さ', '出題数', '正解', '正答率', '平均秒', '人数'];
    $table = [];
    foreach ($rows as $r) $table[] = [$r['text'], $r['qkind'], $r['genre'], $r['lv'], (int)$r['n'], (int)$r['ok'], pct((int)$r['ok'], (int)$r['n']), round((float)$r['avg_secs'], 1), (int)$r['testers']];
    if ($csv) csv_out('文別の成績', $head, $table);
    $b = '<h1>文別の成績</h1><div class="card"><div class="row"><span class="small mut">テスターの回答から、文ごとの出題数・正答率・平均の判定時間。正答率が低い文や時間がかかる文は、問題や文の見直し候補。自作問題の文は「（自作問題）」とまとめています。</span><a class="btn ghost" style="margin-left:auto" href="?v=items&csv=1">CSV</a></div><table><tr>' . implode('', array_map(fn($x) => '<th>' . h($x) . '</th>', $head)) . '</tr>';
    foreach ($table as $r) $b .= '<tr>' . implode('', array_map(fn($x, $j) => '<td class="' . ($j >= 4 ? 'num' : ($j === 0 ? 'wrap' : '')) . '">' . h($x) . '</td>', $r, array_keys($r))) . '</tr>';
    if (!$table) $b .= '<tr><td colspan="9" class="mut">まだ記録がありません。</td></tr>';
    page('文別の成績', $b . '</table></div>');
}

if ($v === 'accounts') {
    $qs = s($_GET['q'] ?? '', 100);
    $wq = $qs !== '' ? ' WHERE a.`email` LIKE ' . $pdo->quote('%' . $qs . '%') : '';
    $rows = $q('SELECT a.`id`, a.`email`, a.`created_at`, a.`last_login_at`, a.`note` AS anote, a.`payjp_customer`, e.`plan`, e.`until`, e.`source`, e.`ref`, e.`status`, e.`updated_at`, e.`note`, e.`period_end`, e.`canceled_at`, e.`tickets_granted`, e.`card_brand`, e.`card_last4`, e.`amount`, e.`price_rev`,
        (SELECT COUNT(*) FROM `sessions` s WHERE s.`account_id` = a.`id` AND s.`revoked_at` IS NULL AND s.`expires_at` > NOW()) AS live_sessions
        FROM `accounts` a LEFT JOIN `entitlements` e ON e.`account_id` = a.`id` AND e.`app_id` = ' . $pdo->quote(APP_ID) . $wq . ' ORDER BY a.`created_at` DESC LIMIT 300');
    $head = ['メールアドレス', '登録', '最終ログイン', 'プラン', '有効期限', '出どころ', '決済（PAY.JP）', 'チケット累計', 'ログイン中の端末', 'メモ'];
    $table = [];
    foreach ($rows as $r) {
        $pay = '';
        if (($r['source'] ?? '') === 'payjp' && ($r['ref'] ?? '') !== '') {
            $listAmt = (int)(PAY_PLANS[(string)($r['plan'] ?? '')]['amount'] ?? 0); $amt = (int)($r['amount'] ?? 0) ?: $listAmt;
            $pay = (($r['status'] ?? '') === 'active' ? (!empty($r['canceled_at']) ? '解約済み（期間末まで）' : '継続中') : (($r['status'] ?? '') === 'past_due' ? '支払い失敗で停止' : '終了'))
                . ($amt > 0 ? '・' . number_format($amt) . ' 円' . ($listAmt > 0 && $amt < $listAmt ? '（加入時の料金で据え置き。いまの料金表は ' . number_format($listAmt) . ' 円）' : ($listAmt > 0 && $amt > $listAmt ? '（料金表より高い。値下げ後なら PAY.JP で新プランへ移す）' : '')) : '')
                . ($r['period_end'] ? '・次回 ' . substr((string)$r['period_end'], 0, 10) : '') . ($r['card_last4'] !== '' ? '・' . $r['card_brand'] . ' ****' . $r['card_last4'] : '');
        } elseif (($r['payjp_customer'] ?? '') !== '') $pay = 'カード登録あり' . ($r['card_last4'] !== '' ? '（' . $r['card_brand'] . ' ****' . $r['card_last4'] . '）' : '');
        $table[] = [$r['email'], substr((string)$r['created_at'], 0, 10), dt($r['last_login_at']), plan_label((string)($r['plan'] ?? 'free')) . (plan_is_pro($r) ? '' : (($r['plan'] ?? 'free') !== 'free' ? '（期限切れ）' : '')), $r['until'] ?: '—', $r['source'] ?: '', $pay, (int)($r['tickets_granted'] ?? 0), (int)$r['live_sessions'], $r['note'] ?: ''];
    }
    if ($csv) csv_out('アカウント', $head, $table);
    $tot = $q('SELECT COUNT(*) c FROM `accounts`')[0]['c'];
    $pro = $q('SELECT COUNT(*) c FROM `entitlements` WHERE `app_id` = ? AND `status` = \'active\' AND `plan` IN (\'month\',\'year\',\'gift\',\'tester\') AND (`until` IS NULL OR `until` >= CURDATE())', [APP_ID])[0]['c'];
    $b = '<h1>アカウント</h1>' . $deletedNote . (isset($_GET['saved']) ? '<p class="small ok">プランを保存しました。</p>' : '') . (isset($_GET['revoked']) ? '<p class="small ok">ログインを解除しました（' . (int)$_GET['revoked'] . ' 端末）。</p>' : '')
        . (isset($_GET['paysync']) ? '<p class="small ' . ($_GET['paysync'] === 'synced' ? 'ok' : 'ng') . '">' . (['synced' => 'PAY.JP と照合しました。', 'nosub' => 'このアカウントには PAY.JP の定期課金がありません。', 'error' => '照合でエラーが起きました。'][(string)$_GET['paysync']] ?? '') . '</p>' : '');
    $b .= '<div class="card kpi"><div><span class="mut small">アカウント数</span><b>' . (int)$tot . '</b></div><div><span class="mut small">有料相当（有効）</span><b>' . (int)$pro . '</b></div></div>';
    $b .= '<div class="card"><form class="row" method="get"><input type="hidden" name="v" value="accounts"><input type="text" name="q" value="' . h($qs) . '" placeholder="メールアドレスで検索"><button type="submit">検索</button><a class="btn ghost" style="margin-left:auto" href="?v=accounts&csv=1">CSV</a></form>';
    $b .= '<p class="small mut">メールのリンクでログインした人の一覧。「プラン」はこのアプリでの有料相当の扱い（month／year＝サブスク、gift＝無料で付与、tester＝テスター、free＝無料）。「出どころ」が payjp の行は PAY.JP の定期課金に連動している（手で「保存」すると manual に戻り、連動が切れるので注意。返金・特例は PAY.JP の管理画面で行い、必要ならここで日付を直す）。有効期限が空ならずっと有効。決済の状態は ' . h(payjp_cfg()['enabled'] ? ('PAY.JP ' . payjp_cfg()['mode'] . ' 環境') : '未接続（模擬）') . '。</p>';
    $b .= '<table><tr>' . implode('', array_map(fn($x) => '<th>' . h($x) . '</th>', $head)) . '<th>操作</th></tr>';
    foreach ($rows as $i => $r) {
        $aid = (int)$r['id'];
        $form = '<form method="post" class="row" style="gap:6px;flex-wrap:nowrap"><input type="hidden" name="setplan" value="1"><input type="hidden" name="id" value="' . $aid . '"><input type="hidden" name="t" value="' . h($csrf) . '"><input type="hidden" name="q" value="' . h($qs) . '">'
            . '<select name="plan">' . implode('', array_map(fn($p) => '<option value="' . $p . '"' . (($r['plan'] ?? 'free') === $p ? ' selected' : '') . '>' . h(plan_label($p)) . '</option>', ['free', 'month', 'year', 'gift', 'tester'])) . '</select>'
            . '<input type="date" name="until" value="' . h($r['until'] ?: '') . '" title="有効期限（空＝無期限）"><input type="text" name="note" value="' . h($r['note'] ?: '') . '" placeholder="メモ" style="width:120px"><button type="submit">保存</button></form>'
            . ((($r['source'] ?? '') === 'payjp' && ($r['ref'] ?? '') !== '') ? ' <form method="post" style="display:inline"><input type="hidden" name="paysync" value="1"><input type="hidden" name="id" value="' . $aid . '"><input type="hidden" name="t" value="' . h($csrf) . '"><input type="hidden" name="q" value="' . h($qs) . '"><button type="submit" class="btn ghost" style="padding:2px 8px;font-size:12px">PAY.JP と照合</button></form>' : '')
            . ' ' . del_form('sessions', (string)$aid, 'ログイン解除', $r['email'] . ' のログインを全端末で解除します。よろしいですか？', '<input type="hidden" name="q" value="' . h($qs) . '">')
            . ' ' . del_form('account', (string)$aid, '削除', $r['email'] . ' のアカウント・プラン・ログインを削除します（利用ログやランキングの記録は残ります）。元に戻せません。よろしいですか？');
        $b .= '<tr>' . implode('', array_map(fn($x, $j) => '<td class="' . (in_array($j, [7, 8], true) ? 'num' : '') . '">' . h($x) . '</td>', $table[$i], array_keys($table[$i]))) . '<td>' . $form . '</td></tr>';
    }
    if (!$rows) $b .= '<tr><td colspan="11" class="mut">まだアカウントはありません（アプリの設定 →「アカウント」からメールでログインすると、ここに載ります）。</td></tr>';
    page('アカウント', $b . '</table></div>');
}

if ($v === 'sales') {
    $rows = $q('SELECT p.`id`, p.`created_at`, a.`email`, p.`kind`, p.`plan`, p.`amount`, p.`status`, p.`payjp_charge`, p.`payjp_sub`, p.`livemode`, p.`note` FROM `payments` p LEFT JOIN `accounts` a ON a.`id` = p.`account_id` WHERE p.`app_id` = ? ORDER BY p.`created_at` DESC, p.`id` DESC LIMIT 500', [APP_ID]);
    $kindName = ['subscribe' => 'サブスク開始', 'renew' => 'サブスク更新', 'ticket' => 'チケット', 'refund' => '返金'];
    $head = ['日時', 'メールアドレス', '種類', 'プラン／内容', '金額（円）', '状態', '環境', 'PAY.JP の支払い ID', 'PAY.JP の定期課金 ID', 'メモ'];
    $table = [];
    foreach ($rows as $r) $table[] = [dt($r['created_at']), $r['email'] ?? '（削除済み）', $kindName[$r['kind']] ?? $r['kind'], $r['plan'] !== '' ? (plan_label($r['plan']) !== $r['plan'] ? plan_label($r['plan']) : (PAY_TICKETS[$r['plan']]['name'] ?? $r['plan'])) : '', (int)$r['amount'], $r['status'] === 'paid' ? '支払い済み' : ($r['status'] === 'refunded' ? '返金済み' : $r['status']), $r['livemode'] ? '本番' : 'テスト', $r['payjp_charge'], $r['payjp_sub'], $r['note']];
    if ($csv) csv_out('売上', $head, $table);
    $sum = $q("SELECT COUNT(*) c, COALESCE(SUM(CASE WHEN `status` = 'paid' THEN `amount` ELSE 0 END), 0) total, COALESCE(SUM(CASE WHEN `status` = 'paid' AND `created_at` >= DATE_FORMAT(NOW(), '%Y-%m-01') THEN `amount` ELSE 0 END), 0) month_total FROM `payments` WHERE `app_id` = ? AND `livemode` = 1", [APP_ID])[0];
    $subs = $q("SELECT COUNT(*) c FROM `entitlements` WHERE `app_id` = ? AND `source` = 'payjp' AND `status` = 'active' AND `canceled_at` IS NULL AND (`until` IS NULL OR `until` >= CURDATE())", [APP_ID])[0]['c'];
    /* 加入時の料金で据え置き中の人数（いまの料金表より安い金額で継続している定期課金） */
    $gf = 0; foreach ($q("SELECT `plan`, `amount` FROM `entitlements` WHERE `app_id` = ? AND `source` = 'payjp' AND `status` = 'active' AND `canceled_at` IS NULL AND (`until` IS NULL OR `until` >= CURDATE())", [APP_ID]) as $x) { $la = (int)(PAY_PLANS[(string)$x['plan']]['amount'] ?? 0); if ((int)$x['amount'] > 0 && $la > 0 && (int)$x['amount'] < $la) $gf++; }
    $hooks = $q('SELECT `received_at`, `type`, `object_id`, `verified`, `handled` FROM `webhook_log` ORDER BY `id` DESC LIMIT 20');
    $b = '<h1>売上（決済の記録）</h1><div class="card kpi"><div><span class="mut small">継続中のサブスク（PAY.JP）</span><b>' . (int)$subs . '</b></div><div><span class="mut small">うち加入時の料金で据え置き中</span><b>' . (int)$gf . '</b></div><div><span class="mut small">本番の売上 今月（円）</span><b>' . number_format((int)$sum['month_total']) . '</b></div><div><span class="mut small">本番の売上 累計（円）</span><b>' . number_format((int)$sum['total']) . '</b></div><div><span class="mut small">決済の環境</span><b style="font-size:15px">' . h(payjp_cfg()['enabled'] ? payjp_cfg()['mode'] . (payjp_cfg()['mock'] ? '（模擬）' : '') : '未接続') . '</b></div></div>';
    $b .= '<div class="card"><div class="row"><span class="small mut">サブスクの開始・更新・チケットの都度払いの記録（PAY.JP の管理画面の「売上」と突き合わせる。更新は、アプリの照合か Webhook が期間の更新に気づいた時点で記録される）。金額は税込。いまの料金表は第 ' . PAY_PRICE_REV . ' 世代（月額 ' . number_format(PAY_PLANS['month']['amount']) . ' 円／年額 ' . number_format(PAY_PLANS['year']['amount']) . ' 円）。値上げしても継続中の人は加入時の金額のまま更新される（PAY.JP のプランが金額ごとに分かれているため）。</span><a class="btn ghost" style="margin-left:auto" href="?v=sales&csv=1">CSV</a></div>';
    $b .= '<table><tr>' . implode('', array_map(fn($x) => '<th>' . h($x) . '</th>', $head)) . '</tr>';
    foreach ($table as $row) $b .= '<tr>' . implode('', array_map(fn($x, $j) => '<td class="' . ($j === 4 ? 'num' : '') . '">' . h($x) . '</td>', $row, array_keys($row))) . '</tr>';
    if (!$table) $b .= '<tr><td colspan="10" class="mut">まだ決済の記録はありません。</td></tr>';
    $b .= '</table></div>';
    $b .= '<div class="card"><h2 style="margin-top:0">Webhook の受信（直近 20 件）</h2><p class="small mut">PAY.JP の管理画面「Webhook」で送信先 https://yomikai.tsunashiman.com/api/payjp_webhook.php を登録すると、更新・解約・支払い失敗がすぐに反映される（登録しなくても、アプリの照合で 6 時間以内に反映される）。verified が 0 のときは、Secret PAYJP_WEBHOOK_TOKEN が未設定か不一致。</p><table><tr><th>受信</th><th>種類</th><th>対象 ID</th><th>検証</th><th>処理</th></tr>';
    foreach ($hooks as $hk) $b .= '<tr><td>' . h(dt($hk['received_at'])) . '</td><td>' . h($hk['type']) . '</td><td>' . h($hk['object_id']) . '</td><td>' . ((int)$hk['verified'] ? '<span class="ok">済</span>' : '<span class="mut">—</span>') . '</td><td>' . h($hk['handled']) . '</td></tr>';
    if (!$hooks) $b .= '<tr><td colspan="5" class="mut">まだ受信はありません。</td></tr>';
    page('売上', $b . '</table></div>');
}

page('不明', '<div class="card">そのページはありません。</div>');
