<?php
/* ランキング（その日の「今日の20問」の初回の結果を集め、同じ問題を解いた人同士の順位表を返す）
   GET  ?date=YYYY-MM-DD&did=端末ID[&limit=100]   → その日の順位表（上位 limit 件）と、did の順位
   POST {did, uid, date, score, correct, total, rate, secs, handle, pro, testerId, build, edition}
        → 記録して順位表を返す。同じ端末・同じ日の 2 回目以降は「記録済み」のまま（名前の変更だけ反映）
   順位は スコアの高い順 → 判定までの合計時間が短い順 → 先着順。日付は端末の日付（日本時間の今日の前後 1 日まで受け付ける） */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
cors();
$pdo = db_or_503();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function valid_day(string $d): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('Asia/Tokyo'));
    return $t !== false && $t->format('Y-m-d') === $d;
}

function board(PDO $pdo, string $day, string $did, int $limit): array
{
    $st = $pdo->prepare('SELECT `did`, `handle`, `pro`, `score`, `correct`, `total`, `rate`, `secs` FROM `daily_results` WHERE `app_id` = ? AND `day` = ? ORDER BY `score` DESC, `secs` ASC, `id` ASC');
    $st->execute([APP_ID, $day]);
    $rows = []; $me = null; $pos = 0;
    while ($r = $st->fetch()) {
        $pos++;
        $row = ['pos' => $pos, 'name' => $r['handle'], 'pro' => (int)$r['pro'], 'score' => (int)$r['score'], 'correct' => (int)$r['correct'], 'total' => (int)$r['total'], 'rate' => (int)$r['rate'], 'secs' => (float)$r['secs'], 'me' => $did !== '' && $r['did'] === $did];
        if ($row['me']) $me = $row;
        if ($pos <= $limit || $row['me']) $rows[] = $row;
    }
    return ['ok' => true, 'date' => $day, 'final' => $day < today(), 'n' => $pos, 'rows' => $rows, 'me' => $me];
}

if ($method === 'GET') {
    $day = s($_GET['date'] ?? today(), 10);
    if (!valid_day($day)) fail('bad date');
    $did = s($_GET['did'] ?? '', 24);
    rate_limit($pdo, 'rank-get', 240, 60);
    out(board($pdo, $day, $did, i($_GET['limit'] ?? 100, 1, 300)));
}

if ($method !== 'POST') fail('GET or POST', 405);
rate_limit($pdo, 'rank-post', 60, 60);
$o = body_json(20000);
$did = s($o['did'] ?? '', 24);
if (!preg_match('/^[a-z0-9]{6,24}$/', $did)) fail('bad did');
$day = s($o['date'] ?? '', 10);
if (!valid_day($day)) fail('bad date');
$t = today();
$lo = (new DateTimeImmutable($t))->modify('-1 day')->format('Y-m-d');
$hi = (new DateTimeImmutable($t))->modify('+1 day')->format('Y-m-d');
if ($day < $lo || $day > $hi) fail('date out of range');
$total = i($o['total'] ?? 0, 1, 60);
$correct = i($o['correct'] ?? 0, 0, $total);
$score = i($o['score'] ?? 0, 0, $total * 6000);
$secs = max(0.0, min(99999.9, is_numeric($o['secs'] ?? null) ? (float)$o['secs'] : 0.0));
if ($secs < $total * 0.3) fail('implausible'); /* 1 問 0.3 秒未満は人の操作ではない */
$handle = s($o['handle'] ?? '', 20);
if ($handle === '') $handle = '読者#' . i($o['uid'] ?? 0, 0, 99999999);
$pro = !empty($o['pro']) ? 1 : 0;
try {
    $pdo->prepare('INSERT INTO `daily_results` (`app_id`, `day`, `did`, `uid`, `tester_id`, `handle`, `pro`, `score`, `correct`, `total`, `rate`, `secs`, `build`, `edition`, `received_at`, `ip_hash`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `handle` = ?, `pro` = ?')
        ->execute([APP_ID, $day, $did, i($o['uid'] ?? 0, 0, 99999999), strtoupper(s($o['testerId'] ?? '', 16)), $handle, $pro, $score, $correct, $total, i($o['rate'] ?? 0, 0, 100000), $secs,
            s($o['build'] ?? '', 32), s($o['edition'] ?? '', 20), now3(), ip_hash(), $handle, $pro]);
} catch (Throwable $ex) {
    out(['ok' => false, 'error' => 'store', 'why' => err_kind($ex)], 500);
}
out(board($pdo, $day, $did, 100));
