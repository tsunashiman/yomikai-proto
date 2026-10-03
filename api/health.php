<?php
/* 動作確認：https://yomikai.tsunashiman.com/api/health.php
   DB につながるか・テーブルの版・PHP の版を返す（秘密は返さない）。deploy.yml の「反映の確認」でも呼ぶ */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
cors();
$o = ['ok' => true, 'app' => APP_ID, 'time' => now3(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'mailXHeader' => (string)ini_get('mail.add_x_header'), 'schema' => SCHEMA_VERSION];
try {
    $pdo = db();
    $o['db'] = 'ok';
    $o['dbUser'] = db_user_label();
    $o['dbHost'] = db_host_label();
    $o['mysql'] = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $cnt = [];
    foreach (['usage_events', 'feedback', 'daily_results'] as $t) $cnt[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $t . '` WHERE `app_id` = ' . $pdo->quote(APP_ID))->fetchColumn();
    $o['rows'] = $cnt;
    try { $o['adminKey'] = !empty(cfg()['admin_key']); } catch (Throwable $e) { $o['adminKey'] = false; }
    $pc = payjp_cfg(); $o['payjp'] = $pc['enabled'] ? ($pc['mock'] ? 'mock' : $pc['mode']) : 'off'; /* off＝鍵なし（模擬のまま）、test＝テスト環境の鍵、live＝本番の鍵 */
    if (isset($_GET['pay']) && $pc['enabled'] && !$pc['mock']) { /* ?pay=1：秘密鍵が PAY.JP に通るか（プラン一覧を 1 件だけ取る。鍵そのものは返さない） */
        $r = payjp_request('GET', 'plans', ['limit' => 1]);
        $o['payjpKey'] = $r['ok'] ? 'ok' : ('error:' . ($r['data']['code'] ?? $r['data']['type'] ?? $r['status']));
        if ($r['ok']) $o['payjpPlans'] = array_map(fn($x) => ['id' => $x['id'], 'amount' => $x['amount'], 'interval' => $x['interval']], $r['data']['data'] ?? []);
    }
} catch (Throwable $e) {
    $o['ok'] = false;
    $o['db'] = 'not-ready';
    $o['why'] = err_kind($e);
    /* 原因の切り分け用（パスワードは含まれない。MySQL の返事そのもの：どのユーザー名・どのホストで断られたかが分かる） */
    $o['detail'] = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 200);
    $o['tried'] = ['hosts' => array_values(array_filter((function () { try { $c = cfg(); return $c['hosts'] ?? [$c['host'] ?? '']; } catch (Throwable $x) { return []; } })(), 'is_string')), 'users' => (function () { try { $c = cfg(); return $c['users'] ?? [$c['user'] ?? '']; } catch (Throwable $x) { return []; } })()];
}
out($o, $o['ok'] ? 200 : 503);
