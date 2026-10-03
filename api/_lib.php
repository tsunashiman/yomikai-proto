<?php
/* 論理的読解タイムアタック：サーバー側 API の共通部分（さくらのレンタルサーバ・PHP 8.2・MySQL 8.0）
   - 接続先などの秘密は公開領域の外 /home/tsunashiman/secrets/db.json にある（GitHub Actions の deploy.yml が Secrets から書き出す）
   - テーブルは最初のアクセス時に自動で作る（migrate）。全テーブルに app_id を持たせ、ほかのアプリと同じ DB を共用できるようにしてある
   - 返事はすべて JSON。file:// や別ホストから開いた版（単体 HTML・アーティファクト・旧住所）からも送れるよう CORS は * */
declare(strict_types=1);

const APP_ID = 'yomikai';
const SCHEMA_VERSION = 1;
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

/* DB 接続。users に複数あれば順に試す（さくらは DB 名＝ユーザー名の方式とアカウント名の方式があるため） */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = cfg();
    $users = [];
    foreach (($c['users'] ?? [$c['user'] ?? $c['db']]) as $u) if (is_string($u) && $u !== '' && !in_array($u, $users, true)) $users[] = $u;
    $last = null;
    foreach ($users as $u) {
        try {
            $p = new PDO('mysql:host=' . $c['host'] . (!empty($c['port']) ? ';port=' . (int)$c['port'] : '') . ';dbname=' . $c['db'] . ';charset=utf8mb4', $u, (string)($c['pass'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
            $p->exec("SET time_zone = '+09:00'");
            $GLOBALS['__db_user'] = $u;
            migrate($p);
            $pdo = $p;
            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
            if (strpos($e->getMessage(), '1045') === false) break; /* パスワード違い（1045）以外は次のユーザーを試しても無駄 */
        }
    }
    throw $last ?? new RuntimeException('db');
}

function db_user_label(): string { return (string)($GLOBALS['__db_user'] ?? ''); }

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

function migrate(PDO $pdo): void
{
    $v = 0;
    try { $v = (int)$pdo->query('SELECT `v` FROM `schema_version` WHERE `id` = 1')->fetchColumn(); }
    catch (PDOException $e) { $pdo->exec('CREATE TABLE IF NOT EXISTS `schema_version` (`id` TINYINT NOT NULL PRIMARY KEY, `v` INT NOT NULL, `at` DATETIME NOT NULL) ENGINE=InnoDB'); }
    if ($v >= SCHEMA_VERSION) return;
    if ($v < 1) foreach (ddl_v1() as $sql) $pdo->exec($sql);
    $pdo->prepare('INSERT INTO `schema_version` (`id`, `v`, `at`) VALUES (1, ?, NOW()) ON DUPLICATE KEY UPDATE `v` = ?, `at` = NOW()')->execute([SCHEMA_VERSION, SCHEMA_VERSION]);
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
