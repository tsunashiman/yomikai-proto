<?php
/* アカウント（メールのリンクでログイン）と有料プランの照合。パスワードは持たない。
   POST JSON { action, ... }
     request { email, did }      → ログイン用のリンクをメールで送る（20 分有効・1 回限り）。結果は常に ok（アドレスの有無は明かさない）
     verify  { token, did }      → リンクのトークンを照合し、セッション（180 日）を発行。アカウントが無ければ作る
     me      { session }         → セッションの照合とアカウント情報（plan・until・pro）
     logout  { session }         → セッションを無効化
   セッションの生の値はアプリの端末にだけ保存し、サーバーには sha256 だけを置く。 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST only', 405);
$pdo = db_or_503();
$o = body_json(20000);
$action = s($o['action'] ?? '', 16);
$did = s($o['did'] ?? '', 24);

function norm_email(string $e): string { return mb_strtolower(trim($e)); }
function valid_email(string $e): bool { return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
function new_token(): string { return bin2hex(random_bytes(32)); }

if ($action === 'request') {
    rate_limit($pdo, 'auth-req', 10, 600);
    $email = s($o['email'] ?? '', 190);
    if (!valid_email($email)) fail('bad email');
    $en = norm_email($email);
    /* 同じアドレスへの送りすぎ防止：10 分に 3 通まで */
    $st = $pdo->prepare('SELECT COUNT(*) FROM `login_tokens` WHERE `app_id` = ? AND `email_norm` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 10 MINUTE)'); $st->execute([APP_ID, $en]);
    if ((int)$st->fetchColumn() >= 3) fail('too many requests', 429);
    $tok = new_token();
    $pdo->prepare('INSERT INTO `login_tokens` (`app_id`, `email_norm`, `token_hash`, `did`, `created_at`, `expires_at`, `ip_hash`) VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 20 MINUTE), ?)')
        ->execute([APP_ID, $en, hash('sha256', $tok), $did, ip_hash()]);
    $link = app_url() . '?login=' . $tok;
    $body = "まじめに速読トレ（Tsunashiman）です。\n\n"
        . "次のリンクを開くと、このメールアドレスでログインします（20 分以内・1 回だけ有効）。\n\n"
        . $link . "\n\n"
        . "・ログインしたい端末（スマホ／PC）でこのリンクを開いてください。別の端末で開くと、その端末がログインします。\n"
        . "・心当たりがない場合は、このメールを無視してください（何も起こりません）。\n\n"
        . "— Tsunashiman（ツナシマン）\n" . mail_reply() . "\n";
    $sent = send_mail($email, '【まじめに速読トレ】ログイン用のリンク', $body);
    if (!$sent) out(['ok' => false, 'error' => 'mail'], 500);
    out(['ok' => true, 'sent' => true]);
}

if ($action === 'verify') {
    rate_limit($pdo, 'auth-verify', 30, 600);
    $tok = s($o['token'] ?? '', 80);
    if (!preg_match('/^[0-9a-f]{64}$/', $tok)) fail('bad token');
    $st = $pdo->prepare('SELECT `id`, `email_norm`, `did` FROM `login_tokens` WHERE `app_id` = ? AND `token_hash` = ? AND `used_at` IS NULL AND `expires_at` > NOW()'); $st->execute([APP_ID, hash('sha256', $tok)]);
    $t = $st->fetch();
    if (!$t) out(['ok' => false, 'error' => 'expired'], 410);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE `login_tokens` SET `used_at` = NOW() WHERE `id` = ?')->execute([(int)$t['id']]);
        $st = $pdo->prepare('SELECT `id` FROM `accounts` WHERE `email_norm` = ?'); $st->execute([$t['email_norm']]);
        $aid = (int)($st->fetchColumn() ?: 0);
        if (!$aid) {
            $pdo->prepare('INSERT INTO `accounts` (`email`, `email_norm`, `created_at`, `last_login_at`) VALUES (?, ?, NOW(), NOW())')->execute([$t['email_norm'], $t['email_norm']]);
            $aid = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT IGNORE INTO `entitlements` (`account_id`, `app_id`, `plan`, `until`, `source`, `status`, `updated_at`) VALUES (?, ?, \'free\', NULL, \'manual\', \'active\', NOW())')->execute([$aid, APP_ID]);
        } else {
            $pdo->prepare('UPDATE `accounts` SET `last_login_at` = NOW() WHERE `id` = ?')->execute([$aid]);
        }
        $sess = new_token();
        $pdo->prepare('INSERT INTO `sessions` (`app_id`, `account_id`, `token_hash`, `did`, `created_at`, `expires_at`, `last_seen_at`, `ua`) VALUES (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 180 DAY), NOW(), ?)')
            ->execute([APP_ID, $aid, hash('sha256', $sess), $did, s($_SERVER['HTTP_USER_AGENT'] ?? '', 200)]);
        $pdo->commit();
    } catch (Throwable $ex) { $pdo->rollBack(); out(['ok' => false, 'error' => 'store', 'why' => err_kind($ex)], 500); }
    out(['ok' => true, 'session' => $sess, 'account' => account_info($pdo, $aid)]);
}

if ($action === 'me') {
    rate_limit($pdo, 'auth-me', 120, 600);
    $aid = session_account($pdo, s($o['session'] ?? '', 80));
    if (!$aid) out(['ok' => false, 'error' => 'nosession'], 401);
    try { payjp_sync($pdo, $aid, false); } catch (Throwable $e) { /* 決済側につながらなくても、手元の記録で答える */ }
    out(['ok' => true, 'account' => account_info($pdo, $aid)]);
}

if ($action === 'logout') {
    $raw = s($o['session'] ?? '', 80);
    if (preg_match('/^[0-9a-f]{64}$/', $raw)) $pdo->prepare('UPDATE `sessions` SET `revoked_at` = NOW() WHERE `app_id` = ? AND `token_hash` = ? AND `revoked_at` IS NULL')->execute([APP_ID, hash('sha256', $raw)]);
    out(['ok' => true]);
}

fail('unknown action');
