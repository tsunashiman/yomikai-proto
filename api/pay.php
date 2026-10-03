<?php
/* 決済（PAY.JP・v60）。カード番号はアプリの画面で PAY.JP の部品（payjp.js）がトークン化し、当方のサーバーには番号が届かない。
   GET  ?action=config                       → 決済が使えるか・公開鍵・価格（アプリが起動時に取る。秘密は含まない）
   POST { action: 'subscribe', session, plan: 'month'|'year', token? }  → 顧客（カード）を登録して定期課金を開始（即時に初回課金）
   POST { action: 'cancel', session }        → 定期課金をキャンセル（期間末まで使える。次回の請求が止まる）
   POST { action: 'resume', session }        → キャンセルの取り消し（期間末より前なら）／支払い失敗で停止した定期課金の再開（カードの更新後）
   POST { action: 'ticket', session, pack: 't5'|'t10', token? } → チケットの都度払い（登録済みのカードか、新しいトークン）
   POST { action: 'sync', session }          → PAY.JP に問い合わせて状態を更新（アプリの「再確認」）
   価格はサーバー側の定義（PAY_PLANS・PAY_TICKETS）だけを使う。 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
cors();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $pc = payjp_cfg();
    out(['ok' => true, 'enabled' => $pc['enabled'], 'mode' => $pc['mode'], 'publicKey' => $pc['enabled'] ? $pc['public'] : '',
        'plans' => array_map(fn($p) => ['amount' => $p['amount'], 'interval' => $p['interval']], PAY_PLANS),
        'tickets' => array_map(fn($t) => ['amount' => $t['amount'], 'n' => $t['n']], PAY_TICKETS), 'tds' => false, 'priceRev' => PAY_PRICE_REV]);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('GET or POST only', 405);
$pdo = db_or_503();
$o = body_json(20000);
$action = s($o['action'] ?? '', 16);
if (!payjp_enabled()) out(['ok' => false, 'error' => 'disabled'], 503);
rate_limit($pdo, 'pay', 40, 600);
$aid = session_account($pdo, s($o['session'] ?? '', 80));
if (!$aid) out(['ok' => false, 'error' => 'nosession'], 401);
$st = $pdo->prepare('SELECT `id`, `email`, `payjp_customer` FROM `accounts` WHERE `id` = ?'); $st->execute([$aid]); $acc = $st->fetch();
if (!$acc) out(['ok' => false, 'error' => 'nosession'], 401);
$token = s($o['token'] ?? '', 80);
if ($token !== '' && !preg_match('/^tok_[0-9a-zA-Z_]+$/', $token)) fail('bad token');
$ent = function () use ($pdo, $aid): ?array { $st = $pdo->prepare('SELECT * FROM `entitlements` WHERE `account_id` = ? AND `app_id` = ?'); $st->execute([$aid, APP_ID]); return $st->fetch() ?: null; };
$done = function (array $extra = []) use ($pdo, $aid): never { out(array_merge(['ok' => true, 'account' => account_info($pdo, $aid)], $extra)); };
$payErr = function (array $r, string $where) use ($aid): never {
    error_log('[pay] ' . $where . ' account=' . $aid . ' status=' . $r['status'] . ' ' . json_encode($r['data'], JSON_UNESCAPED_UNICODE));
    $isCard = (($r['data']['type'] ?? '') === 'card_error') || $r['status'] === 402;
    out(['ok' => false, 'error' => $isCard ? 'card' : 'pay', 'code' => (string)($r['data']['code'] ?? ''), 'message' => payjp_error_text($r['data'])], $isCard ? 402 : 502);
};

/* 顧客（PAY.JP の customer）を用意する。トークンがあればカードを登録（既存の顧客なら新しいカードを追加してメインにする） */
$ensureCustomer = function (bool $needCard) use ($pdo, $aid, $acc, $token, $payErr): array {
    $cid = (string)$acc['payjp_customer']; $cardInfo = null;
    if ($cid === '') {
        if ($token === '') fail('card required');
        $r = payjp_request('POST', 'customers', ['email' => $acc['email'], 'card' => $token, 'description' => 'yomikai account #' . $aid, 'metadata' => ['account_id' => (string)$aid, 'app' => APP_ID]]);
        if (!$r['ok']) $payErr($r, 'customers.create');
        $cid = (string)$r['data']['id'];
        $pdo->prepare('UPDATE `accounts` SET `payjp_customer` = ? WHERE `id` = ?')->execute([$cid, $aid]);
        $cards = $r['data']['cards']['data'] ?? []; $cardInfo = $cards[0] ?? null;
    } elseif ($token !== '') {
        $r = payjp_request('POST', 'customers/' . rawurlencode($cid) . '/cards', ['card' => $token, 'default' => 'true']);
        if ($r['ok']) $cardInfo = $r['data'];
        elseif (($r['data']['code'] ?? '') !== 'already_have_the_same_card') $payErr($r, 'cards.create');
    } elseif ($needCard) {
        $r = payjp_request('GET', 'customers/' . rawurlencode($cid));
        if (!$r['ok']) $payErr($r, 'customers.get');
        if (empty($r['data']['default_card'])) fail('card required');
        foreach (($r['data']['cards']['data'] ?? []) as $c) if ($c['id'] === $r['data']['default_card']) $cardInfo = $c;
    }
    if ($cardInfo) {
        $pdo->prepare('INSERT INTO `entitlements` (`account_id`, `app_id`, `plan`, `until`, `source`, `ref`, `status`, `updated_at`, `card_brand`, `card_last4`) VALUES (?, ?, \'free\', NULL, \'manual\', \'\', \'active\', NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE `card_brand` = VALUES(`card_brand`), `card_last4` = VALUES(`card_last4`)')->execute([$aid, APP_ID, s($cardInfo['brand'] ?? '', 24), s($cardInfo['last4'] ?? '', 4)]);
    }
    return [$cid, $cardInfo];
};

if ($action === 'subscribe') {
    $plan = s($o['plan'] ?? '', 8);
    if (!isset(PAY_PLANS[$plan])) fail('bad plan');
    $e = $ent();
    if ($e && $e['source'] === 'payjp' && $e['ref'] !== '') {
        $e = payjp_sync($pdo, $aid, true) ?: $e;
        if ($e['status'] === 'active' && empty($e['canceled_at']) && plan_is_pro($e)) out(['ok' => false, 'error' => 'already', 'message' => 'すでにサブスクに登録済みです。', 'account' => account_info($pdo, $aid)], 409);
        if ($e['status'] === 'active' && !empty($e['canceled_at']) && $e['plan'] === $plan) {
            /* キャンセル後・期間内に同じプランへ戻る：再開 */
            $r = payjp_request('POST', 'subscriptions/' . rawurlencode((string)$e['ref']) . '/resume');
            if (!$r['ok']) $payErr($r, 'subscriptions.resume');
            payjp_apply_subscription($pdo, $aid, $r['data'], $plan);
            $done(['resumed' => true]);
        }
        if ($e['status'] === 'past_due' || ($e['status'] === 'active' && !empty($e['canceled_at']))) {
            /* 停止中（支払い失敗）か、キャンセル済みで別プランへ：古い定期課金を削除してから作り直す */
            if ($e['status'] === 'past_due' && $token === '') fail('card required');
            payjp_request('DELETE', 'subscriptions/' . rawurlencode((string)$e['ref']));
        }
    }
    if ($e && in_array((string)$e['plan'], ['gift', 'tester'], true) && plan_is_pro($e)) {
        /* ギフト・テスターが有効なあいだは買わせない（二重払いを防ぐ） */
        out(['ok' => false, 'error' => 'already', 'message' => 'いまは' . plan_label((string)$e['plan']) . 'が有効です（' . ($e['until'] ? fmt_until((string)$e['until']) . ' まで' : '期限なし') . '）。終わってからご登録ください。', 'account' => account_info($pdo, $aid)], 409);
    }
    [$cid] = $ensureCustomer(true);
    $pr = payjp_ensure_plan($plan);
    if (!$pr['ok']) $payErr($pr, 'plans.ensure');
    $r = payjp_request('POST', 'subscriptions', ['customer' => $cid, 'plan' => payjp_plan_id($plan), 'metadata' => ['account_id' => (string)$aid, 'app' => APP_ID]]);
    if (!$r['ok'] && (($r['data']['code'] ?? '') === 'already_subscribed')) {
        /* PAY.JP 側に同じプランの定期課金が残っている（当方の記録と食い違い）：顧客の定期課金を探して引き取る */
        $l = payjp_request('GET', 'customers/' . rawurlencode($cid) . '/subscriptions', ['plan' => payjp_plan_id($plan), 'limit' => 5]);
        $found = null; foreach (($l['data']['data'] ?? []) as $sx) if (in_array((string)($sx['status'] ?? ''), ['active', 'trial', 'canceled', 'paused'], true)) { $found = $sx; break; }
        if ($found && $found['status'] === 'canceled') { $rr = payjp_request('POST', 'subscriptions/' . rawurlencode((string)$found['id']) . '/resume'); if ($rr['ok']) $found = $rr['data']; }
        if (!$found) $payErr($r, 'subscriptions.create');
        payjp_apply_subscription($pdo, $aid, $found, $plan);
        $done(['adopted' => true]);
    }
    if (!$r['ok']) $payErr($r, 'subscriptions.create');
    $sub = $r['data'];
    payjp_apply_subscription($pdo, $aid, $sub, $plan);
    payjp_record_payment($pdo, $aid, 'subscribe', $plan, PAY_PLANS[$plan]['amount'], '', (string)$sub['id'], 'paid', '初回');
    $periodEnd = ts_to_jst(isset($sub['current_period_end']) ? (int)$sub['current_period_end'] : null);
    $mode = payjp_cfg()['mode'] === 'live' ? '' : "【テスト環境のため実際の請求はありません】\n\n";
    send_mail((string)$acc['email'], '【まじめに速読トレ】サブスクの登録を受け付けました', $mode . "まじめに速読トレ（Tsunashiman）です。\n\n"
        . plan_label($plan) . "・" . number_format(PAY_PLANS[$plan]['amount']) . " 円（税込）の登録を受け付けました。ありがとうございます。\n"
        . "次回の更新日：" . ($periodEnd ? substr($periodEnd, 0, 10) : '—') . "（この日に自動で更新・請求されます）\n\n"
        . "料金を改定することがあっても、継続中のあいだはご加入時の料金（" . number_format(PAY_PLANS[$plan]['amount']) . " 円）のままです（ご自身で解約した後に再加入する場合は、そのときの料金になります。値下げのときは継続中の方にも新しい料金を適用します）。\n"
        . "解約はいつでも、アプリの「チケット・サブスク」または設定の「アカウント」からできます。解約後は次回更新日以降の請求が止まり、期間の終わりまで引き続きお使いいただけます。\n"
        . "カード明細には PAY.JP 経由の請求として表示されます。\n\n— Tsunashiman（ツナシマン）\n" . mail_reply() . "\n");
    $done(['subscribed' => true]);
}

if ($action === 'cancel') {
    $e = $ent();
    if (!$e || $e['source'] !== 'payjp' || $e['ref'] === '') fail('no subscription');
    $r = payjp_request('POST', 'subscriptions/' . rawurlencode((string)$e['ref']) . '/cancel');
    if (!$r['ok'] && (($r['data']['code'] ?? '') !== 'already_canceled')) $payErr($r, 'subscriptions.cancel');
    $sub = $r['ok'] ? $r['data'] : (payjp_request('GET', 'subscriptions/' . rawurlencode((string)$e['ref']))['data'] ?? null);
    if (is_array($sub) && isset($sub['id'])) payjp_apply_subscription($pdo, $aid, $sub);
    $e2 = $ent();
    send_mail((string)$acc['email'], '【まじめに速読トレ】サブスクの解約を受け付けました', "まじめに速読トレ（Tsunashiman）です。\n\nサブスクの解約を受け付けました。" . ($e2 && $e2['until'] ? fmt_until((string)$e2['until']) . " まではこれまでどおりお使いいただけ、以降の請求はありません。" : '') . "\n"
        . "期間が終わる前なら、アプリの「チケット・サブスク」から解約を取り消せます。\n\nご利用ありがとうございました。またのご利用をお待ちしています。\n\n— Tsunashiman（ツナシマン）\n" . mail_reply() . "\n");
    $done(['canceled' => true]);
}

if ($action === 'resume') {
    $e = $ent();
    if (!$e || $e['source'] !== 'payjp' || $e['ref'] === '') fail('no subscription');
    if ($e['status'] === 'past_due' && $token !== '') $ensureCustomer(false); /* 新しいカードを登録してから再開 */
    $r = payjp_request('POST', 'subscriptions/' . rawurlencode((string)$e['ref']) . '/resume');
    if (!$r['ok']) $payErr($r, 'subscriptions.resume');
    payjp_apply_subscription($pdo, $aid, $r['data']);
    if ($e['status'] === 'past_due') payjp_record_payment($pdo, $aid, 'renew', (string)$e['plan'], (int)($r['data']['plan']['amount'] ?? 0) ?: ((int)($e['amount'] ?? 0) ?: (int)(PAY_PLANS[(string)$e['plan']]['amount'] ?? 0)), '', (string)$e['ref'], 'paid', '再開');
    $done(['resumed' => true]);
}

if ($action === 'ticket') {
    $pack = s($o['pack'] ?? '', 8);
    if (!isset(PAY_TICKETS[$pack])) fail('bad pack');
    $t = PAY_TICKETS[$pack];
    [$cid] = $ensureCustomer(true);
    $r = payjp_request('POST', 'charges', ['customer' => $cid, 'amount' => $t['amount'], 'currency' => 'jpy', 'description' => 'まじめに速読トレ ' . $t['name'], 'metadata' => ['account_id' => (string)$aid, 'app' => APP_ID, 'pack' => $pack]]);
    if (!$r['ok']) $payErr($r, 'charges.create');
    $ch = $r['data'];
    $pdo->prepare('INSERT INTO `entitlements` (`account_id`, `app_id`, `plan`, `until`, `source`, `ref`, `status`, `updated_at`, `tickets_granted`) VALUES (?, ?, \'free\', NULL, \'manual\', \'\', \'active\', NOW(), ?)
        ON DUPLICATE KEY UPDATE `tickets_granted` = `tickets_granted` + VALUES(`tickets_granted`)')->execute([$aid, APP_ID, $t['n']]);
    payjp_record_payment($pdo, $aid, 'ticket', $pack, $t['amount'], (string)($ch['id'] ?? ''), '', 'paid', $t['name']);
    $done(['granted' => $t['n']]);
}

if ($action === 'sync') {
    payjp_sync($pdo, $aid, true);
    $done();
}

fail('unknown action');

function fmt_until(string $ymd): string { return (int)substr($ymd, 0, 4) . '年' . (int)substr($ymd, 5, 2) . '月' . (int)substr($ymd, 8, 2) . '日'; }
