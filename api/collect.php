<?php
/* 受信：ご意見・評価（フォーム）と利用ログ（テスター版）。アプリの CONFIG.feedbackEndpoint がここを指す。
   POST の本文は JSON。type が 'log' なら利用ログ（events の配列）、それ以外で rating があればご意見。
   同じものが 2 回届いても（送り直し・sendBeacon の重複）、ログは (did, seq, at) で、ご意見は id で重複を除く。 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST only', 405);
$pdo = db_or_503();
rate_limit($pdo, 'collect', 120, 60);
$o = body_json();

if (($o['type'] ?? '') === 'log') {
    $events = $o['events'] ?? null;
    if (!is_array($events)) fail('no events');
    $events = array_slice(array_values(array_filter($events, 'is_array')), 0, 200);
    $dev = is_array($o['device'] ?? null) ? $o['device'] : [];
    $use = is_array($o['usage'] ?? null) ? $o['usage'] : [];
    $tester = strtoupper(s($o['testerId'] ?? '', 16));
    $did = s($o['did'] ?? '', 24);
    $uid = i($o['uid'] ?? 0, 0, 99999999);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO `usage_batches` (`app_id`, `received_at`, `tester_id`, `uid`, `did`, `edition`, `build`, `app`, `reason`, `platform`, `w`, `h`, `standalone`, `ua`, `days`, `sets`, `streak`, `n_events`, `n_new`, `ip_hash`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([APP_ID, now3(), $tester, $uid, $did, s($o['edition'] ?? '', 20), s($o['build'] ?? '', 32), s($o['app'] ?? '', 64), s($o['reason'] ?? '', 16),
                s($dev['platform'] ?? '', 16), i($dev['w'] ?? 0, 0, 32000), i($dev['h'] ?? 0, 0, 32000), !empty($dev['standalone']) ? 1 : 0, s($dev['ua'] ?? '', 200),
                i($use['days'] ?? 0, 0, 100000), i($use['sets'] ?? 0, 0, 1000000), i($use['streak'] ?? 0, 0, 100000), count($events), ip_hash()]);
        $batchId = (int)$pdo->lastInsertId();
        $insE = $pdo->prepare('INSERT IGNORE INTO `usage_events` (`app_id`, `batch_id`, `tester_id`, `uid`, `did`, `seq`, `at`, `at_raw`, `type`, `what`, `kind`, `n`, `done`, `ok`, `ms`, `score`, `data`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insI = $pdo->prepare('INSERT INTO `usage_items` (`app_id`, `event_id`, `tester_id`, `did`, `at`, `mode`, `text`, `qkind`, `ok`, `secs`, `genre`, `lv`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $new = 0;
        foreach ($events as $e) {
            $type = s($e['type'] ?? '', 12);
            if ($type === '') continue;
            $atRaw = s($e['at'] ?? '', 32);
            $at = iso_to_jst($atRaw) ?? now3();
            $what = $type === 'set' ? s($e['mode'] ?? '', 40) : ($type === 'act' ? s($e['what'] ?? '', 40) : ($type === 'session' ? s($e['screen'] ?? '', 40) : s($e['msg'] ?? '', 40)));
            $kind = $type === 'set' ? s($e['kind'] ?? '', 16) : '';
            $data = $e; unset($data['seq'], $data['at'], $data['type']);
            $insE->execute([APP_ID, $batchId, $tester, $uid, $did, i($e['seq'] ?? 0, 0, 2000000000), $at, $atRaw, $type, $what, $kind,
                i($e['n'] ?? 0, 0, 100000), i($e['done'] ?? 0, 0, 100000), i($e['ok'] ?? 0, 0, 100000), i($e['ms'] ?? 0, 0, 2000000000),
                isset($e['score']) && is_numeric($e['score']) ? i($e['score'], -100000000, 100000000) : null, json_or_null($data)]);
            if ($insE->rowCount() < 1) continue; /* 受信済み */
            $new++;
            $eid = (int)$pdo->lastInsertId();
            if ($type === 'set' && is_array($e['qs'] ?? null)) {
                foreach (array_slice($e['qs'], 0, 60) as $q) {
                    if (!is_array($q)) continue;
                    $insI->execute([APP_ID, $eid, $tester, $did, $at, $what, s($q[0] ?? '', 40), s($q[1] ?? '', 12), !empty($q[2]) ? 1 : 0,
                        max(0, min(99999.9, is_numeric($q[3] ?? null) ? (float)$q[3] : 0.0)), s($q[4] ?? '', 24), s($q[5] ?? '', 16)]);
                }
            }
        }
        $pdo->prepare('UPDATE `usage_batches` SET `n_new` = ? WHERE `id` = ?')->execute([$new, $batchId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        out(['ok' => false, 'error' => 'store', 'why' => err_kind($ex)], 500);
    }
    out(['ok' => true, 'type' => 'log', 'received' => count($events), 'new' => $new]);
}

if (isset($o['rating'])) {
    $items = is_array($o['items'] ?? null) ? $o['items'] : [];
    $dev = is_array($o['device'] ?? null) ? $o['device'] : [];
    $cx = is_array($o['context'] ?? null) ? $o['context'] : [];
    $fbId = s($o['id'] ?? '', 48);
    if ($fbId === '') $fbId = 'fb-' . substr(hash('sha256', json_encode($o)), 0, 24);
    try {
        $st = $pdo->prepare('INSERT IGNORE INTO `feedback` (`app_id`, `fb_id`, `received_at`, `at`, `uid`, `tester_id`, `edition`, `app`, `rating`, `fun`, `difficulty`, `quality`, `usability`, `nps`, `price`, `good`, `improve`, `name`, `contact`, `quote_ok`, `trig`, `platform`, `context_json`, `device_json`, `usage_json`, `ip_hash`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([APP_ID, $fbId, now3(), iso_to_jst($o['at'] ?? ''), i($o['uid'] ?? 0, 0, 99999999), strtoupper(s($o['testerId'] ?? '', 16)), s($o['edition'] ?? '', 20), s($o['app'] ?? '', 64),
            i($o['rating'], 0, 5), i($items['fun'] ?? 0, 0, 5), i($items['difficulty'] ?? 0, 0, 5), i($items['quality'] ?? 0, 0, 5), i($items['usability'] ?? 0, 0, 5),
            isset($o['nps']) && is_numeric($o['nps']) ? i($o['nps'], 0, 10) : null, s($o['price'] ?? '', 10), s($o['good'] ?? '', 2000), s($o['improve'] ?? '', 2000),
            s($o['name'] ?? '', 80), s($o['contact'] ?? '', 120), !empty($o['quoteOk']) ? 1 : 0, s($cx['trigger'] ?? '', 24), s($dev['platform'] ?? '', 16),
            json_or_null($cx), json_or_null($dev), json_or_null($o['usage'] ?? null), ip_hash()]);
    } catch (Throwable $ex) {
        out(['ok' => false, 'error' => 'store', 'why' => err_kind($ex)], 500);
    }
    out(['ok' => true, 'type' => 'feedback', 'id' => $fbId, 'new' => $st->rowCount() > 0]);
}

fail('unknown type');
