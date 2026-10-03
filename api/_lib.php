<?php
/* まじめに速読トレ（旧称 論理的読解タイムアタック）：サーバー側 API の共通部分（さくらのレンタルサーバ・PHP 8.2・MySQL 8.0）
   - 接続先などの秘密は公開領域の外 /home/tsunashiman/secrets/db.json にある（GitHub Actions の deploy.yml が Secrets から書き出す）
   - テーブルは最初のアクセス時に自動で作る（migrate）。全テーブルに app_id を持たせ、ほかのアプリと同じ DB を共用できるようにしてある
   - 返事はすべて JSON。file:// や別ホストから開いた版（単体 HTML・アーティファクト・旧住所）からも送れるよう CORS は * */
declare(strict_types=1);

const APP_ID = 'yomikai';
const SCHEMA_VERSION = 3;
const SECRETS_FILE = '/home/tsunashiman/secrets/db.json';

date_default_timezone_set('Asia/Tokyo');
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function out(array $o, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    out(['ok' => false, 'error' => $msg], $code);
}

/* 本文（JSON）。sendBeacon は text/plain で届くので Content-Type は見ない */
function body_json(int $max = 400000): array
{
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) fail('too large', 413);
    $o = json_decode($raw, true);
    if (!is_array($o)) fail('bad json');
    return $o;
}

function cfg(): array
{
    static $c = null;
    if ($c !== null) return $c;
    if (!is_readable(SECRETS_FILE)) throw new RuntimeException('no-config');
    $c = json_decode((string)file_get_contents(SECRETS_FILE), true);
    if (!is_array($c) || empty($c['host']) || empty($c['db'])) throw new RuntimeException('bad-config');
    return $c;
}

/* 秘密から派生させる塩（IP の匿名化・管理ページのクッキー署名に使う。DB パスワードそのものは使わない） */
function secret_salt(string $purpose): string
{
    return hash('sha256', $purpose . '|' . (cfg()['pass'] ?? '') . '|' . (cfg()['db'] ?? ''));
}

function ip_hash(): string
{
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . secret_salt('ip')), 0, 16);
}

/* DB 接続。hosts（別名と実ホスト名）× users（さくらの MySQL 8.0 は DB 名＝ユーザー名。アカウント名の管理用ユーザーも予備で）を順に試す */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg();
    $users = [];
    foreach (($c['users'] ?? [$c['user'] ?? $c['db']]) as $u) if (is_string($u) && $u !== '' && !in_array($u, $users, true)) $users[] = $u;
    $hosts = [];
    foreach (($c['hosts'] ?? [$c['host']]) as $h) if (is_string($h) && $h !== '' && !in_array($h, $hosts, true)) $hosts[] = $h;
    $last = null;
    foreach ($hosts as $h) {
        foreach ($users as $u) {
            try {
                $p = new PDO('mysql:host=' . $h . (!empty($c['port']) ? ';port=' . (int)$c['port'] : '') . ';dbname=' . $c['db'] . ';charset=utf8mb4', $u, (string)($c['pass'] ?? ''),
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
                $p->exec("SET time_zone = '+09:00'");
                $GLOBALS['__db_user'] = $u; $GLOBALS['__db_host'] = $h;
                migrate($p);
                $pdo = $p;
                return $pdo;
            } catch (PDOException $e) {
                $last = $e;
                if (strpos($e->getMessage(), '1045') === false) break; /* パスワード違い（1045）以外は、このホストで次のユーザーを試しても無駄 → 次のホストへ */
            }
        }
    }
    throw $last ?? new RuntimeException('db');
}

function db_user_label(): string { return (string)($GLOBALS['__db_user'] ?? ''); }
function db_host_label(): string { return (string)($GLOBALS['__db_host'] ?? ''); }

/* ---- テーブル（初回に自動作成。版を上げるときは SCHEMA_VERSION を上げ、下に ALTER を足す） ---- */
function ddl_v1(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `rate_limits` (`k` VARCHAR(96) NOT NULL PRIMARY KEY, `n` INT NOT NULL, `exp` INT NOT NULL, KEY `ix_exp` (`exp`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `usage_batches` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `received_at` DATETIME(3) NOT NULL,
            `tester_id` VARCHAR(16) NOT NULL DEFAULT '',
            `uid` INT NOT NULL DEFAULT 0,
            `did` VARCHAR(24) NOT NULL DEFAULT '',
            `edition` VARCHAR(20) NOT NULL DEFAULT '',
            `build` VARCHAR(32) NOT NULL DEFAULT '',
            `app` VARCHAR(64) NOT NULL DEFAULT '',
            `reason` VARCHAR(16) NOT NULL DEFAULT '',
            `platform` VARCHAR(16) NOT NULL DEFAULT '',
            `w` SMALLINT NOT NULL DEFAULT 0, `h` SMALLINT NOT NULL DEFAULT 0,
            `standalone` TINYINT NOT NULL DEFAULT 0,
            `ua` VARCHAR(200) NOT NULL DEFAULT '',
            `days` INT NOT NULL DEFAULT 0, `sets` INT NOT NULL DEFAULT 0, `streak` INT NOT NULL DEFAULT 0,
            `n_events` INT NOT NULL DEFAULT 0, `n_new` INT NOT NULL DEFAULT 0,
            `ip_hash` CHAR(16) NOT NULL DEFAULT '',
            KEY `ix_tester` (`app_id`, `tester_id`, `received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `usage_events` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `batch_id` BIGINT UNSIGNED NOT NULL,
            `tester_id` VARCHAR(16) NOT NULL DEFAULT '',
            `uid` INT NOT NULL DEFAULT 0,
            `did` VARCHAR(24) NOT NULL DEFAULT '',
            `seq` INT NOT NULL DEFAULT 0,
            `at` DATETIME(3) NOT NULL,
            `at_raw` VARCHAR(32) NOT NULL DEFAULT '',
            `type` VARCHAR(12) NOT NULL,
            `what` VARCHAR(40) NOT NULL DEFAULT '',
            `kind` VARCHAR(16) NOT NULL DEFAULT '',
            `n` INT NOT NULL DEFAULT 0, `done` INT NOT NULL DEFAULT 0, `ok` INT NOT NULL DEFAULT 0,
            `ms` INT NOT NULL DEFAULT 0,
            `score` INT NULL,
            `data` JSON NULL,
            UNIQUE KEY `uq_evt` (`app_id`, `did`, `seq`, `at_raw`),
            KEY `ix_tester` (`app_id`, `tester_id`, `at`),
            KEY `ix_type` (`app_id`, `type`, `at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `usage_items` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `event_id` BIGINT UNSIGNED NOT NULL,
            `tester_id` VARCHAR(16) NOT NULL DEFAULT '',
            `did` VARCHAR(24) NOT NULL DEFAULT '',
            `at` DATETIME(3) NOT NULL,
            `mode` VARCHAR(40) NOT NULL DEFAULT '',
            `text` VARCHAR(40) NOT NULL DEFAULT '',
            `qkind` VARCHAR(12) NOT NULL DEFAULT '',
            `ok` TINYINT NOT NULL DEFAULT 0,
            `secs` DECIMAL(7,1) NOT NULL DEFAULT 0,
            `genre` VARCHAR(24) NOT NULL DEFAULT '',
            `lv` VARCHAR(16) NOT NULL DEFAULT '',
            KEY `ix_text` (`app_id`, `text`(20)),
            KEY `ix_tester` (`app_id`, `tester_id`, `at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `feedback` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `fb_id` VARCHAR(48) NOT NULL,
            `received_at` DATETIME(3) NOT NULL,
            `at` DATETIME(3) NULL,
            `uid` INT NOT NULL DEFAULT 0,
            `tester_id` VARCHAR(16) NOT NULL DEFAULT '',
            `edition` VARCHAR(20) NOT NULL DEFAULT '',
            `app` VARCHAR(64) NOT NULL DEFAULT '',
            `rating` TINYINT NOT NULL DEFAULT 0,
            `fun` TINYINT NOT NULL DEFAULT 0, `difficulty` TINYINT NOT NULL DEFAULT 0, `quality` TINYINT NOT NULL DEFAULT 0, `usability` TINYINT NOT NULL DEFAULT 0,
            `nps` TINYINT NULL,
            `price` VARCHAR(10) NOT NULL DEFAULT '',
            `good` TEXT NULL, `improve` TEXT NULL,
            `name` VARCHAR(80) NOT NULL DEFAULT '', `contact` VARCHAR(120) NOT NULL DEFAULT '',
            `quote_ok` TINYINT NOT NULL DEFAULT 0,
            `trig` VARCHAR(24) NOT NULL DEFAULT '',
            `platform` VARCHAR(16) NOT NULL DEFAULT '',
            `context_json` JSON NULL, `device_json` JSON NULL, `usage_json` JSON NULL,
            `ip_hash` CHAR(16) NOT NULL DEFAULT '',
            UNIQUE KEY `uq_fb` (`app_id`, `fb_id`),
            KEY `ix_tester` (`app_id`, `tester_id`, `received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `daily_results` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `day` DATE NOT NULL,
            `did` VARCHAR(24) NOT NULL,
            `uid` INT NOT NULL DEFAULT 0,
            `tester_id` VARCHAR(16) NOT NULL DEFAULT '',
            `handle` VARCHAR(40) NOT NULL DEFAULT '',
            `pro` TINYINT NOT NULL DEFAULT 0,
            `score` INT NOT NULL,
            `correct` TINYINT NOT NULL, `total` TINYINT NOT NULL,
            `rate` INT NOT NULL DEFAULT 0,
            `secs` DECIMAL(7,1) NOT NULL DEFAULT 0,
            `build` VARCHAR(32) NOT NULL DEFAULT '', `edition` VARCHAR(20) NOT NULL DEFAULT '',
            `received_at` DATETIME(3) NOT NULL,
            `ip_hash` CHAR(16) NOT NULL DEFAULT '',
            UNIQUE KEY `uq_day` (`app_id`, `day`, `did`),
            KEY `ix_board` (`app_id`, `day`, `score`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/* v2（v59）：アカウント（メールのリンクでログイン）・セッション・有料プランの照合。accounts は屋号の全アプリ共通、entitlements は app_id ごと */
function ddl_v2(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `accounts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(190) NOT NULL,
            `email_norm` VARCHAR(190) NOT NULL,
            `created_at` DATETIME NOT NULL,
            `last_login_at` DATETIME NULL,
            `note` VARCHAR(200) NOT NULL DEFAULT '',
            UNIQUE KEY `uq_email` (`email_norm`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `login_tokens` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `email_norm` VARCHAR(190) NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `did` VARCHAR(24) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL,
            `ip_hash` CHAR(16) NOT NULL DEFAULT '',
            UNIQUE KEY `uq_tok` (`token_hash`),
            KEY `ix_email` (`email_norm`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `sessions` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `account_id` BIGINT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `did` VARCHAR(24) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `last_seen_at` DATETIME NULL,
            `revoked_at` DATETIME NULL,
            `ua` VARCHAR(200) NOT NULL DEFAULT '',
            UNIQUE KEY `uq_sess` (`token_hash`),
            KEY `ix_acc` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `entitlements` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `account_id` BIGINT UNSIGNED NOT NULL,
            `app_id` VARCHAR(32) NOT NULL,
            `plan` VARCHAR(24) NOT NULL DEFAULT 'free',
            `until` DATE NULL,
            `source` VARCHAR(24) NOT NULL DEFAULT 'manual',
            `ref` VARCHAR(64) NOT NULL DEFAULT '',
            `status` VARCHAR(16) NOT NULL DEFAULT 'active',
            `updated_at` DATETIME NOT NULL,
            `note` VARCHAR(200) NOT NULL DEFAULT '',
            UNIQUE KEY `uq_acc_app` (`account_id`, `app_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/* v3（v60）：決済（PAY.JP）。accounts に顧客 ID、entitlements に定期課金の状態とチケットの累計、payments（売上）と webhook_log */
function ddl_v3(): array
{
    return [
        "ALTER TABLE `accounts` ADD COLUMN `payjp_customer` VARCHAR(100) NOT NULL DEFAULT ''",
        "ALTER TABLE `entitlements` ADD COLUMN `period_end` DATETIME NULL",
        "ALTER TABLE `entitlements` ADD COLUMN `canceled_at` DATETIME NULL",
        "ALTER TABLE `entitlements` ADD COLUMN `synced_at` DATETIME NULL",
        "ALTER TABLE `entitlements` ADD COLUMN `tickets_granted` INT NOT NULL DEFAULT 0",
        "ALTER TABLE `entitlements` ADD COLUMN `card_brand` VARCHAR(24) NOT NULL DEFAULT ''",
        "ALTER TABLE `entitlements` ADD COLUMN `card_last4` VARCHAR(4) NOT NULL DEFAULT ''",
        "CREATE TABLE IF NOT EXISTS `payments` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `app_id` VARCHAR(32) NOT NULL,
            `account_id` BIGINT UNSIGNED NOT NULL,
            `kind` VARCHAR(16) NOT NULL,
            `plan` VARCHAR(24) NOT NULL DEFAULT '',
            `amount` INT NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT 'paid',
            `payjp_charge` VARCHAR(64) NOT NULL DEFAULT '',
            `payjp_sub` VARCHAR(64) NOT NULL DEFAULT '',
            `livemode` TINYINT NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `note` VARCHAR(200) NOT NULL DEFAULT '',
            KEY `ix_acc` (`app_id`, `account_id`, `created_at`),
            KEY `ix_charge` (`payjp_charge`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS `webhook_log` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `received_at` DATETIME(3) NOT NULL,
            `event_id` VARCHAR(64) NOT NULL DEFAULT '',
            `type` VARCHAR(48) NOT NULL DEFAULT '',
            `object_id` VARCHAR(64) NOT NULL DEFAULT '',
            `verified` TINYINT NOT NULL DEFAULT 0,
            `handled` VARCHAR(40) NOT NULL DEFAULT '',
            `body` MEDIUMTEXT NULL,
            KEY `ix_evt` (`event_id`),
            KEY `ix_at` (`received_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}
function exec_ignore(PDO $pdo, string $sql, array $ignoreCodes): void
{
    try { $pdo->exec($sql); }
    catch (PDOException $e) { $m = $e->getMessage(); foreach ($ignoreCodes as $c) if (strpos($m, $c) !== false) return; throw $e; }
}

function migrate(PDO $pdo): void
{
    $v = 0;
    try { $v = (int)$pdo->query('SELECT `v` FROM `schema_version` WHERE `id` = 1')->fetchColumn(); }
    catch (PDOException $e) { $pdo->exec('CREATE TABLE IF NOT EXISTS `schema_version` (`id` TINYINT NOT NULL PRIMARY KEY, `v` INT NOT NULL, `at` DATETIME NOT NULL) ENGINE=InnoDB'); }
    if ($v >= SCHEMA_VERSION) return;
    if ($v < 1) foreach (ddl_v1() as $sql) $pdo->exec($sql);
    if ($v < 2) foreach (ddl_v2() as $sql) $pdo->exec($sql);
    if ($v < 3) foreach (ddl_v3() as $sql) exec_ignore($pdo, $sql, ['1060', '1061', '1050']); /* 列・索引・表が既にある（1060／1061／1050）は無視 */
    $pdo->prepare('INSERT INTO `schema_version` (`id`, `v`, `at`) VALUES (1, ?, NOW()) ON DUPLICATE KEY UPDATE `v` = ?, `at` = NOW()')->execute([SCHEMA_VERSION, SCHEMA_VERSION]);
}

/* ---- アカウント・プラン（共通の判定） ---- */
const PAID_PLANS = ['month', 'year', 'gift', 'tester'];
function plan_is_pro(?array $ent): bool
{
    if (!$ent || ($ent['status'] ?? 'active') !== 'active') return false; /* past_due（支払い失敗で停止）・ended（削除済み）は無効 */
    if (!in_array((string)($ent['plan'] ?? 'free'), PAID_PLANS, true)) return false;
    $until = $ent['until'] ?? null;
    return $until === null || $until === '' || (string)$until >= today();
}
function plan_label(string $plan): string
{
    return ['free' => '無料', 'month' => 'サブスク（月額）', 'year' => 'サブスク（年額）', 'gift' => 'ギフト（無料で付与）', 'tester' => 'テスター'][$plan] ?? $plan;
}
/* アカウントの要約（アプリに返す形）：email・plan・until・pro */
function account_info(PDO $pdo, int $accountId): ?array
{
    $st = $pdo->prepare('SELECT `id`, `email`, `created_at`, `last_login_at`, `payjp_customer` FROM `accounts` WHERE `id` = ?'); $st->execute([$accountId]);
    $a = $st->fetch(); if (!$a) return null;
    $st = $pdo->prepare('SELECT `plan`, `until`, `source`, `ref`, `status`, `updated_at`, `period_end`, `canceled_at`, `tickets_granted`, `card_brand`, `card_last4` FROM `entitlements` WHERE `account_id` = ? AND `app_id` = ?'); $st->execute([$accountId, APP_ID]);
    $e = $st->fetch() ?: null;
    $sub = null;
    if ($e && $e['source'] === 'payjp' && $e['ref'] !== '') {
        $sub = ['status' => $e['status'], 'periodEnd' => $e['period_end'] ? substr((string)$e['period_end'], 0, 16) : null, 'canceled' => !empty($e['canceled_at']), 'card' => trim(($e['card_brand'] ?? '') . ' ' . ($e['card_last4'] !== '' ? '****' . $e['card_last4'] : ''))];
    }
    return ['email' => $a['email'], 'since' => substr((string)$a['created_at'], 0, 10), 'plan' => $e ? $e['plan'] : 'free', 'planLabel' => plan_label($e ? $e['plan'] : 'free'), 'until' => $e && $e['until'] ? (string)$e['until'] : null, 'source' => $e ? $e['source'] : '', 'pro' => plan_is_pro($e),
        'sub' => $sub, 'ticketsGranted' => $e ? (int)$e['tickets_granted'] : 0, 'hasCard' => (string)($a['payjp_customer'] ?? '') !== ''];
}
/* セッションの照合：有効なら account_id、無ければ 0 */
function session_account(PDO $pdo, string $rawToken): int
{
    if (!preg_match('/^[0-9a-f]{64}$/', $rawToken)) return 0;
    $st = $pdo->prepare('SELECT `id`, `account_id` FROM `sessions` WHERE `app_id` = ? AND `token_hash` = ? AND `revoked_at` IS NULL AND `expires_at` > NOW()'); $st->execute([APP_ID, hash('sha256', $rawToken)]);
    $r = $st->fetch(); if (!$r) return 0;
    $pdo->prepare('UPDATE `sessions` SET `last_seen_at` = NOW() WHERE `id` = ?')->execute([(int)$r['id']]);
    return (int)$r['account_id'];
}
/* メール送信（さくらの sendmail）。差出人はドメインのアドレス（SPF が通る） */
function mail_from(): string { try { return (string)(cfg()['mail_from'] ?? 'noreply@tsunashiman.com'); } catch (Throwable $e) { return 'noreply@tsunashiman.com'; } }
function mail_reply(): string { try { return (string)(cfg()['mail_reply'] ?? 'info@tsunashiman.com'); } catch (Throwable $e) { return 'info@tsunashiman.com'; } }
function app_url(): string { try { return (string)(cfg()['app_url'] ?? 'https://yomikai.tsunashiman.com/'); } catch (Throwable $e) { return 'https://yomikai.tsunashiman.com/'; } }
function send_mail(string $to, string $subject, string $body): bool
{
    /* 試験用：db.json に "mail_mode": "log" があれば送らずに secrets/mail.log へ書く（本番の db.json には無い） */
    try { if ((cfg()['mail_mode'] ?? '') === 'log') { file_put_contents(dirname(SECRETS_FILE) . '/mail.log', "=== " . now3() . " to: $to\nsubject: $subject\n$body\n", FILE_APPEND); return true; } } catch (Throwable $e) { /* 通常送信へ */ }
    mb_language('ja'); mb_internal_encoding('UTF-8');
    $from = mail_from();
    $headers = 'From: ' . mb_encode_mimeheader('まじめに速読トレ', 'UTF-8') . ' <' . $from . ">\r\n" . 'Reply-To: ' . mail_reply() . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n" . 'Content-Transfer-Encoding: 8bit' . "\r\n" . 'X-Mailer: lrta' . "\r\n";
    try { return @mb_send_mail($to, $subject, $body, $headers, '-f ' . $from); } catch (Throwable $e) { return false; }
}

/* ---- 簡易レート制限（IP ごと・時間窓ごとの回数） ---- */
function rate_limit(PDO $pdo, string $bucket, int $limit, int $window): void
{
    $slot = intdiv(time(), $window);
    $k = $bucket . ':' . ip_hash() . ':' . $slot;
    $exp = ($slot + 2) * $window;
    $pdo->prepare('INSERT INTO `rate_limits` (`k`, `n`, `exp`) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE `n` = `n` + 1')->execute([$k, $exp]);
    $st = $pdo->prepare('SELECT `n` FROM `rate_limits` WHERE `k` = ?'); $st->execute([$k]);
    if ((int)$st->fetchColumn() > $limit) fail('too many requests', 429);
    if (mt_rand(1, 50) === 1) $pdo->prepare('DELETE FROM `rate_limits` WHERE `exp` < ?')->execute([time()]);
}

/* ---- 値の整形 ---- */
function s(mixed $v, int $max): string
{
    if (!is_scalar($v)) return '';
    $t = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) : $t;
}
function i(mixed $v, int $min, int $max, int $def = 0): int
{
    if (!is_numeric($v)) return $def;
    $n = (int)round((float)$v);
    return max($min, min($max, $n));
}
/* ISO 8601（UTC）→ 日本時間の DATETIME(3) 文字列。読めなければ null */
function iso_to_jst(mixed $v): ?string
{
    if (!is_string($v) || $v === '') return null;
    try { $d = new DateTimeImmutable($v); } catch (Throwable $e) { return null; }
    return $d->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s.v');
}
function now3(): string { return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s.v'); }
function today(): string { return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d'); }
function json_or_null(mixed $v, int $max = 20000): ?string
{
    if ($v === null) return null;
    $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($j === false) return null;
    return strlen($j) > $max ? json_encode(['_truncated' => true, 'head' => mb_substr($j, 0, 2000)], JSON_UNESCAPED_UNICODE) : $j;
}

/* DB が使えないとき：503 で「準備中」を返す（アプリ側は次回に再送する） */
function db_or_503(): PDO
{
    try { return db(); }
    catch (Throwable $e) { out(['ok' => false, 'error' => 'not-ready', 'why' => err_kind($e)], 503); }
}
function err_kind(Throwable $e): string
{
    $m = $e->getMessage();
    if ($m === 'no-config') return 'no-config';
    if ($m === 'bad-config') return 'bad-config';
    if (strpos($m, '1045') !== false) return 'auth';
    if (strpos($m, '2002') !== false || stripos($m, 'timed out') !== false) return 'connect';
    if (strpos($m, '1044') !== false || strpos($m, '1049') !== false) return 'database';
    return 'sql:' . (preg_match('/SQLSTATE\[(\w+)\]/', $m, $mm) ? $mm[1] : 'unknown');
}

/* ======================= 決済（PAY.JP・v60） =======================
   秘密鍵・公開鍵は secrets/db.json（payjp_secret／payjp_public／payjp_webhook_token。deploy.yml が GitHub の Secrets から書き出す）。
   秘密鍵が無ければ決済機能は無効（アプリは従来どおり模擬の購入画面のまま）。
   価格はここ（サーバー側）だけで決める。アプリから送られた金額は使わない。
   db.json に "payjp_mode": "mock" があると PAY.JP に通信せず、secrets/payjp_mock.json に状態を持つ偽物で動く（手元のテスト用）。 */
const PAY_PLANS = ['month' => ['amount' => 500, 'interval' => 'month', 'name' => 'まじめに速読トレ サブスク（月額）'], 'year' => ['amount' => 5000, 'interval' => 'year', 'name' => 'まじめに速読トレ サブスク（年額）']];
const PAY_TICKETS = ['t5' => ['amount' => 500, 'n' => 5, 'name' => 'チケット 5 枚'], 't10' => ['amount' => 900, 'n' => 10, 'name' => 'チケット 10 枚']];
const PAY_GRACE_DAYS = 2; /* 更新の遅れ（PAY.JP は current_period_end ちょうどには更新しない）を吸収するための猶予 */

function payjp_cfg(): array
{
    try { $c = cfg(); } catch (Throwable $e) { $c = []; }
    $sk = trim((string)($c['payjp_secret'] ?? '')); $pk = trim((string)($c['payjp_public'] ?? ''));
    $mock = (($c['payjp_mode'] ?? '') === 'mock');
    return ['secret' => $sk, 'public' => $pk, 'webhook_token' => trim((string)($c['payjp_webhook_token'] ?? '')), 'mock' => $mock,
        'enabled' => $mock || ($sk !== '' && $pk !== ''), 'mode' => $mock ? 'test' : (str_starts_with($sk, 'sk_live_') ? 'live' : 'test')];
}
function payjp_enabled(): bool { return payjp_cfg()['enabled']; }

/* PAY.JP への問い合わせ。返事は ['ok' => bool, 'status' => int, 'data' => array（成功ならオブジェクト、失敗なら error の中身）] */
function payjp_request(string $method, string $path, array $params = []): array
{
    $pc = payjp_cfg();
    if ($pc['mock']) return payjp_mock($method, $path, $params);
    if ($pc['secret'] === '') return ['ok' => false, 'status' => 0, 'data' => ['type' => 'config', 'code' => 'no_key', 'message' => 'PAY.JP の鍵が未設定']];
    $url = 'https://api.pay.jp/v1/' . ltrim($path, '/');
    $body = http_build_query($params, '', '&');
    if ($method === 'GET' && $body !== '') { $url .= '?' . $body; $body = ''; }
    $resp = false; $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERPWD => $pc['secret'] . ':', CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: yomikai/1.0'], CURLOPT_SSL_VERIFYPEER => true]);
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($resp === false) { $err = curl_error($ch); curl_close($ch); return ['ok' => false, 'status' => 0, 'data' => ['type' => 'network', 'code' => 'curl', 'message' => $err]]; }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => "Authorization: Basic " . base64_encode($pc['secret'] . ':') . "\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: yomikai/1.0\r\n", 'content' => $body, 'timeout' => 25, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return ['ok' => false, 'status' => 0, 'data' => ['type' => 'network', 'code' => 'stream', 'message' => 'connect failed']];
        foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) return ['ok' => false, 'status' => $code, 'data' => ['type' => 'bad_response', 'code' => 'json', 'message' => 'HTTP ' . $code]];
    if (isset($j['error'])) return ['ok' => false, 'status' => $code ?: (int)($j['error']['status'] ?? 400), 'data' => $j['error']];
    return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'data' => $j];
}
/* 利用者向けの短い説明（PAY.JP の error.code → 日本語） */
function payjp_error_text(array $err): string
{
    $code = (string)($err['code'] ?? ''); $type = (string)($err['type'] ?? '');
    $map = ['card_declined' => 'カード会社に支払いを断られました。別のカードをお試しください。', 'expired_card' => 'カードの有効期限が切れています。', 'incorrect_card_data' => 'カード情報のいずれかが誤っています。',
        'invalid_number' => 'カード番号が正しくありません。', 'invalid_cvc' => 'セキュリティコードが正しくありません。', 'invalid_expiry_month' => '有効期限（月）が正しくありません。', 'invalid_expiry_year' => '有効期限（年）が正しくありません。',
        'card_flagged' => 'このカードは一時的に使えません。しばらくしてからお試しください。', 'processing_error' => '決済ネットワークでエラーが起きました。しばらくしてからお試しください。', 'token_already_used' => 'カード情報を入れ直してください（入力が古くなりました）。',
        'already_subscribed' => 'このアカウントはすでに登録済みです。', 'test_card_on_livemode' => 'テスト用のカード番号は使えません。', 'over_capacity' => '混み合っています。少し待ってからお試しください。', 'no_key' => '決済の設定がまだです。'];
    if (isset($map[$code])) return $map[$code];
    if ($type === 'card_error') return 'カードでの支払いができませんでした。カード情報をご確認ください。';
    if ($type === 'network') return '決済サービスにつながりませんでした。しばらくしてからお試しください。';
    return '決済でエラーが起きました（' . ($code !== '' ? $code : $type) . '）。';
}
/* プランは PAY.JP 側にも作る（ID に金額を含めるので、価格を変えたら新しいプランができる） */
function payjp_plan_id(string $plan): string { return 'yomikai_' . $plan . '_' . PAY_PLANS[$plan]['amount']; }
function payjp_ensure_plan(string $plan): array
{
    $id = payjp_plan_id($plan); $def = PAY_PLANS[$plan];
    $r = payjp_request('GET', 'plans/' . $id);
    if ($r['ok']) return $r;
    $r = payjp_request('POST', 'plans', ['id' => $id, 'amount' => $def['amount'], 'currency' => 'jpy', 'interval' => $def['interval'], 'name' => $def['name']]);
    if (!$r['ok'] && (($r['data']['code'] ?? '') === 'already_exist_id')) return payjp_request('GET', 'plans/' . $id);
    return $r;
}
function ts_to_jst(?int $ts): ?string { return $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s') : null; }
function date_add_days(string $ymd, int $days): string { return (new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone('Asia/Tokyo')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d'); }

/* PAY.JP の定期課金オブジェクト → entitlements の行に反映（作成・照合・Webhook のすべてがここを通る） */
function payjp_apply_subscription(PDO $pdo, int $aid, array $sub, ?string $planKey = null): void
{
    $status = (string)($sub['status'] ?? '');
    $periodEnd = ts_to_jst(isset($sub['current_period_end']) ? (int)$sub['current_period_end'] : null);
    $endDate = $periodEnd ? substr($periodEnd, 0, 10) : today();
    $canceled = !empty($sub['canceled_at']) || $status === 'canceled';
    if ($planKey === null) { $pid = (string)($sub['plan']['id'] ?? ''); foreach (PAY_PLANS as $k => $d) if (str_starts_with($pid, 'yomikai_' . $k . '_')) $planKey = $k; }
    $planKey = $planKey ?: 'month';
    if ($status === 'active' || $status === 'trial') { $st = 'active'; $until = date_add_days($endDate, PAY_GRACE_DAYS); }
    elseif ($status === 'canceled') { $st = 'active'; $until = $endDate; } /* 期間末まで使える */
    elseif ($status === 'paused') { $st = 'past_due'; $until = $endDate; } /* 支払い失敗：止める（カードを更新して再開） */
    else { $st = 'ended'; $until = date_add_days(today(), -1); }
    $pdo->prepare('INSERT INTO `entitlements` (`account_id`, `app_id`, `plan`, `until`, `source`, `ref`, `status`, `updated_at`, `period_end`, `canceled_at`, `synced_at`, `note`)
        VALUES (?, ?, ?, ?, \'payjp\', ?, ?, NOW(), ?, ?, NOW(), \'\')
        ON DUPLICATE KEY UPDATE `plan` = VALUES(`plan`), `until` = VALUES(`until`), `source` = \'payjp\', `ref` = VALUES(`ref`), `status` = VALUES(`status`), `updated_at` = NOW(), `period_end` = VALUES(`period_end`), `canceled_at` = VALUES(`canceled_at`), `synced_at` = NOW()')
        ->execute([$aid, APP_ID, $planKey, $until, (string)($sub['id'] ?? ''), $st, $periodEnd, $canceled ? (ts_to_jst(isset($sub['canceled_at']) ? (int)$sub['canceled_at'] : null) ?? now3()) : null]);
}
function payjp_record_payment(PDO $pdo, int $aid, string $kind, string $plan, int $amount, string $charge, string $sub, string $status = 'paid', string $note = ''): void
{
    if ($charge !== '') { $st = $pdo->prepare('SELECT COUNT(*) FROM `payments` WHERE `payjp_charge` = ?'); $st->execute([$charge]); if ((int)$st->fetchColumn() > 0) return; }
    $pdo->prepare('INSERT INTO `payments` (`app_id`, `account_id`, `kind`, `plan`, `amount`, `status`, `payjp_charge`, `payjp_sub`, `livemode`, `created_at`, `note`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
        ->execute([APP_ID, $aid, $kind, $plan, $amount, $status, $charge, $sub, payjp_cfg()['mode'] === 'live' ? 1 : 0, $note]);
}
/* 定期課金の状態を PAY.JP に問い合わせて反映する。force でなければ 6 時間に 1 回、または期限が近いときだけ */
function payjp_sync(PDO $pdo, int $aid, bool $force = false): ?array
{
    if (!payjp_enabled()) return null;
    $st = $pdo->prepare('SELECT * FROM `entitlements` WHERE `account_id` = ? AND `app_id` = ?'); $st->execute([$aid, APP_ID]);
    $e = $st->fetch(); if (!$e || $e['source'] !== 'payjp' || $e['ref'] === '') return null;
    if (!$force) {
        $fresh = $e['synced_at'] && (time() - strtotime((string)$e['synced_at'])) < 6 * 3600;
        $near = $e['until'] === null || (string)$e['until'] <= date_add_days(today(), 1);
        if ($fresh && !$near) return $e;
        if ($fresh && $near && $e['synced_at'] && (time() - strtotime((string)$e['synced_at'])) < 20 * 60) return $e; /* 期限が近くても 20 分に 1 回まで */
    }
    $r = payjp_request('GET', 'subscriptions/' . rawurlencode((string)$e['ref']));
    if ($r['ok']) {
        $sub = $r['data'];
        /* 期間が進んでいれば更新（renew）として売上に記録 */
        $newEnd = ts_to_jst(isset($sub['current_period_end']) ? (int)$sub['current_period_end'] : null);
        if ($newEnd && $e['period_end'] && $newEnd > (string)$e['period_end'] && in_array((string)($sub['status'] ?? ''), ['active', 'trial'], true)) {
            $pk = (string)$e['plan']; $amt = PAY_PLANS[$pk]['amount'] ?? 0;
            payjp_record_payment($pdo, $aid, 'renew', $pk, $amt, '', (string)$e['ref'], 'paid', '期間更新 ' . substr($newEnd, 0, 10));
        }
        payjp_apply_subscription($pdo, $aid, $sub);
    } elseif ($r['status'] === 404) {
        /* 削除済み（キャンセル後に期間が終わった／管理画面で削除）：終了にする */
        $pdo->prepare('UPDATE `entitlements` SET `status` = \'ended\', `until` = ?, `updated_at` = NOW(), `synced_at` = NOW() WHERE `account_id` = ? AND `app_id` = ?')->execute([date_add_days(today(), -1), $aid, APP_ID]);
    } else {
        $pdo->prepare('UPDATE `entitlements` SET `synced_at` = NOW() WHERE `account_id` = ? AND `app_id` = ?')->execute([$aid, APP_ID]); /* つながらない：次回に */
    }
    $st->execute([$aid, APP_ID]); return $st->fetch() ?: null;
}

/* ---- 手元テスト用の偽 PAY.JP（db.json に "payjp_mode": "mock"）。secrets/payjp_mock.json に状態を持つ ---- */
function payjp_mock(string $method, string $path, array $p): array
{
    $file = dirname(SECRETS_FILE) . '/payjp_mock.json';
    $db = is_readable($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    foreach (['customers', 'plans', 'subscriptions', 'charges'] as $k) $db[$k] = $db[$k] ?? [];
    $save = function () use (&$db, $file) { file_put_contents($file, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); };
    $err = fn(string $code, string $type, int $status, string $msg = '') => ['ok' => false, 'status' => $status, 'data' => ['code' => $code, 'type' => $type, 'status' => $status, 'message' => $msg ?: $code]];
    $now = time(); $id = fn(string $pfx) => $pfx . '_' . bin2hex(random_bytes(12));
    $card = function (string $tok) { return ['object' => 'card', 'id' => 'car_' . substr(md5($tok), 0, 24), 'brand' => 'Visa', 'last4' => str_ends_with($tok, '_mc') ? '4444' : '4242', 'exp_month' => 12, 'exp_year' => 2030]; };
    $tokOk = fn(string $tok) => str_starts_with($tok, 'tok_') && !str_contains($tok, 'fail');
    $seg = explode('/', trim($path, '/'));
    if ($seg[0] === 'plans') {
        if ($method === 'GET' && isset($seg[1])) return isset($db['plans'][$seg[1]]) ? ['ok' => true, 'status' => 200, 'data' => $db['plans'][$seg[1]]] : $err('invalid_id', 'client_error', 404, 'no plan');
        if ($method === 'POST' && !isset($seg[1])) { $pid = (string)($p['id'] ?? $id('pln')); if (isset($db['plans'][$pid])) return $err('already_exist_id', 'client_error', 400); $db['plans'][$pid] = ['object' => 'plan', 'id' => $pid, 'amount' => (int)$p['amount'], 'currency' => 'jpy', 'interval' => $p['interval'], 'name' => $p['name'] ?? null, 'created' => $now, 'livemode' => false]; $save(); return ['ok' => true, 'status' => 200, 'data' => $db['plans'][$pid]]; }
    }
    if ($seg[0] === 'customers') {
        if ($method === 'POST' && !isset($seg[1])) { $tok = (string)($p['card'] ?? ''); if ($tok !== '' && !$tokOk($tok)) return $err('card_declined', 'card_error', 402, 'declined'); $cid = (string)($p['id'] ?? $id('cus')); $c = ['object' => 'customer', 'id' => $cid, 'email' => $p['email'] ?? null, 'description' => $p['description'] ?? null, 'created' => $now, 'livemode' => false, 'default_card' => null, 'cards' => ['object' => 'list', 'data' => []]]; if ($tok !== '') { $cd = $card($tok); $c['cards']['data'][] = $cd; $c['default_card'] = $cd['id']; } $db['customers'][$cid] = $c; $save(); return ['ok' => true, 'status' => 200, 'data' => $c]; }
        if (isset($seg[1]) && !isset($db['customers'][$seg[1]])) return $err('invalid_id', 'client_error', 404, 'no customer');
        $c = &$db['customers'][$seg[1]];
        if ($method === 'GET' && !isset($seg[2])) return ['ok' => true, 'status' => 200, 'data' => $c];
        if ($method === 'POST' && isset($seg[2]) && $seg[2] === 'cards') { $tok = (string)($p['card'] ?? ''); if (!$tokOk($tok)) return $err('card_declined', 'card_error', 402, 'declined'); $cd = $card($tok); foreach ($c['cards']['data'] as $x) if ($x['id'] === $cd['id']) return $err('already_have_the_same_card', 'client_error', 400); $c['cards']['data'][] = $cd; if (!empty($p['default']) && $p['default'] !== 'false') $c['default_card'] = $cd['id']; $save(); return ['ok' => true, 'status' => 200, 'data' => $cd]; }
        if ($method === 'POST' && !isset($seg[2])) { if (isset($p['default_card'])) $c['default_card'] = $p['default_card']; if (isset($p['email'])) $c['email'] = $p['email']; $save(); return ['ok' => true, 'status' => 200, 'data' => $c]; }
    }
    if ($seg[0] === 'subscriptions') {
        if ($method === 'POST' && !isset($seg[1])) {
            $cid = (string)($p['customer'] ?? ''); $pid = (string)($p['plan'] ?? '');
            if (!isset($db['customers'][$cid])) return $err('invalid_customer', 'client_error', 400); if (!isset($db['plans'][$pid])) return $err('invalid_plan', 'client_error', 400);
            if (empty($db['customers'][$cid]['default_card'])) return $err('doesnt_have_card', 'client_error', 400);
            foreach ($db['subscriptions'] as $x) if ($x['customer'] === $cid && $x['plan']['id'] === $pid && $x['status'] !== 'deleted') return $err('already_subscribed', 'client_error', 400);
            $plan = $db['plans'][$pid]; $end = $plan['interval'] === 'year' ? strtotime('+1 year', $now) : strtotime('+1 month', $now);
            $sid = $id('sub'); $s = ['object' => 'subscription', 'id' => $sid, 'customer' => $cid, 'plan' => $plan, 'status' => 'active', 'created' => $now, 'start' => $now, 'current_period_start' => $now, 'current_period_end' => $end, 'canceled_at' => null, 'paused_at' => null, 'resumed_at' => null, 'trial_end' => null, 'livemode' => false, 'metadata' => $p['metadata'] ?? null];
            $db['subscriptions'][$sid] = $s; $db['charges'][] = ['object' => 'charge', 'id' => $id('ch'), 'amount' => $plan['amount'], 'customer' => $cid, 'subscription' => $sid, 'paid' => true, 'created' => $now]; $save(); return ['ok' => true, 'status' => 200, 'data' => $s];
        }
        if (!isset($seg[1]) || !isset($db['subscriptions'][$seg[1]]) || $db['subscriptions'][$seg[1]]['status'] === 'deleted') return $err('invalid_id', 'client_error', 404, 'no subscription');
        $s = &$db['subscriptions'][$seg[1]];
        if ($method === 'GET') return ['ok' => true, 'status' => 200, 'data' => $s];
        if ($method === 'POST' && isset($seg[2]) && $seg[2] === 'cancel') { if ($s['status'] === 'canceled') return $err('already_canceled', 'client_error', 400); $s['status'] = 'canceled'; $s['canceled_at'] = $now; $save(); return ['ok' => true, 'status' => 200, 'data' => $s]; }
        if ($method === 'POST' && isset($seg[2]) && $seg[2] === 'resume') { if ($s['status'] === 'active') return $err('subscription_worked', 'client_error', 400); $s['status'] = 'active'; $s['canceled_at'] = null; $s['paused_at'] = null; $s['resumed_at'] = $now; $save(); return ['ok' => true, 'status' => 200, 'data' => $s]; }
        if ($method === 'POST' && isset($seg[2]) && $seg[2] === 'pause') { $s['status'] = 'paused'; $s['paused_at'] = $now; $save(); return ['ok' => true, 'status' => 200, 'data' => $s]; }
        if ($method === 'POST' && isset($seg[2]) && $seg[2] === '_renew') { $s['current_period_start'] = $s['current_period_end']; $s['current_period_end'] = $s['plan']['interval'] === 'year' ? strtotime('+1 year', $s['current_period_end']) : strtotime('+1 month', $s['current_period_end']); $save(); return ['ok' => true, 'status' => 200, 'data' => $s]; } /* テスト専用：期間更新を起こす */
        if ($method === 'DELETE') { $s['status'] = 'deleted'; $save(); return ['ok' => true, 'status' => 200, 'data' => ['deleted' => true, 'id' => $s['id']]]; }
    }
    if ($seg[0] === 'charges' && $method === 'POST' && !isset($seg[1])) {
        $tok = (string)($p['card'] ?? ''); $cid = (string)($p['customer'] ?? '');
        if ($cid !== '' && !isset($db['customers'][$cid])) return $err('invalid_customer', 'client_error', 400);
        if ($cid === '' && !$tokOk($tok)) return $err('card_declined', 'card_error', 402, 'declined');
        $ch = ['object' => 'charge', 'id' => $id('ch'), 'amount' => (int)$p['amount'], 'currency' => 'jpy', 'customer' => $cid ?: null, 'paid' => true, 'captured' => true, 'created' => $now, 'description' => $p['description'] ?? null, 'card' => $card($tok ?: 'tok_saved'), 'livemode' => false];
        $db['charges'][] = $ch; $save(); return ['ok' => true, 'status' => 200, 'data' => $ch];
    }
    return $err('not_found', 'client_error', 404, 'mock: ' . $method . ' ' . $path);
}
