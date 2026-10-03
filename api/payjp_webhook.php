<?php
/* PAY.JP からの Webhook（v60）。PAY.JP の管理画面「Webhook」で送信先に https://yomikai.tsunashiman.com/api/payjp_webhook.php を登録する。
   正当性：ヘッダー X-Payjp-Webhook-Token が secrets/db.json の payjp_webhook_token と一致するか（設定があれば必須）。
   届いた内容は信用せず、対象の定期課金 ID だけを取り出して PAY.JP に問い合わせ直し（payjp_sync）、その結果で entitlements を更新する。
   同じイベントが二度届いても結果が同じになる（べき等）。常に 200 を返す（PAY.JP は 4xx/5xx だと 3 分おきに 3 回まで再送する）。 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input', false, null, 0, 400001) ?: '';
$ev = json_decode($raw, true);
$pc = payjp_cfg();
$hdr = (string)($_SERVER['HTTP_X_PAYJP_WEBHOOK_TOKEN'] ?? '');
$verified = $pc['webhook_token'] !== '' ? hash_equals($pc['webhook_token'], $hdr) : 0;
try { $pdo = db(); } catch (Throwable $e) { http_response_code(503); exit; } /* DB が無ければ再送してもらう */
$type = is_array($ev) ? s($ev['type'] ?? '', 48) : '';
$data = is_array($ev) && is_array($ev['data'] ?? null) ? $ev['data'] : [];
$objId = s($data['id'] ?? '', 64);
$subId = ($data['object'] ?? '') === 'subscription' ? $objId : s($data['subscription'] ?? '', 64);
$evId = is_array($ev) ? s($ev['id'] ?? '', 64) : '';
$handled = 'ignored';
if ($pc['webhook_token'] !== '' && !$verified) { $handled = 'bad-token'; }
elseif (!is_array($ev)) { $handled = 'bad-json'; }
else {
    $st = $pdo->prepare('SELECT COUNT(*) FROM `webhook_log` WHERE `event_id` = ? AND `handled` LIKE \'synced%\''); $st->execute([$evId]);
    if ($evId !== '' && (int)$st->fetchColumn() > 0) $handled = 'duplicate';
    elseif ($subId !== '') {
        $st = $pdo->prepare('SELECT `account_id` FROM `entitlements` WHERE `app_id` = ? AND `ref` = ?'); $st->execute([APP_ID, $subId]);
        $aid = (int)$st->fetchColumn();
        if ($aid > 0) {
            try { payjp_sync($pdo, $aid, true); $handled = 'synced:' . $aid; } catch (Throwable $e) { $handled = 'error'; }
            if (in_array($type, ['charge.failed'], true)) { /* 支払い失敗：状態は sync で反映済み。本人に知らせる */
                $st = $pdo->prepare('SELECT `email` FROM `accounts` WHERE `id` = ?'); $st->execute([$aid]); $em = (string)$st->fetchColumn();
                if ($em !== '') send_mail($em, '【まじめに速読トレ】お支払いができませんでした', "まじめに速読トレ（Tsunashiman）です。\n\nサブスクの更新のお支払いができませんでした（カードの有効期限切れなどが考えられます）。\nアプリの「チケット・サブスク」からカード情報を更新して再開していただくと、引き続きお使いいただけます。\n\n— Tsunashiman（ツナシマン）\n" . mail_reply() . "\n");
            }
        } else $handled = 'unknown-sub';
    } elseif ($type !== '' && str_starts_with($type, 'charge.') && $objId !== '') {
        /* 都度払い（チケット）の返金など：記録の状態だけ合わせる */
        if ($type === 'charge.refunded') { $pdo->prepare('UPDATE `payments` SET `status` = \'refunded\' WHERE `payjp_charge` = ?')->execute([$objId]); $handled = 'refund-marked'; }
        else $handled = 'charge-noted';
    }
}
try {
    $pdo->prepare('INSERT INTO `webhook_log` (`received_at`, `event_id`, `type`, `object_id`, `verified`, `handled`, `body`) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([now3(), $evId, $type, $objId !== '' ? $objId : $subId, $verified ? 1 : 0, $handled, mb_substr($raw, 0, 60000)]);
    if (mt_rand(1, 40) === 1) $pdo->exec('DELETE FROM `webhook_log` WHERE `received_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)');
} catch (Throwable $e) { /* 記録できなくても 200 */ }
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'handled' => $handled]);
