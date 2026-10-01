#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""新作問題ストックの管理スクリプト（論理的読解タイムアタック）

使い方（リポジトリの直下で実行）：
  python3 tools/stock_daily.py validate  <日付ファイル.json>   … 形式と品質の検査（合格なら exit 0）
  python3 tools/stock_daily.py add       <日付ファイル.json>   … 検査して stock/pending/ に入れる
  python3 tools/stock_daily.py promote                          … pending のうち「日付が今日以前」かつ hold.txt にない分を approved へ
  python3 tools/stock_daily.py index                            … stock/index.json を作り直す
  python3 tools/stock_daily.py status                           … 在庫の一覧
  python3 tools/stock_daily.py push  "<コミットの説明>"          … git add / commit / push（GITHUB_TOKEN があればそれで認証）
  python3 tools/stock_daily.py daily <日付> <daily.json>          … その日付のファイル（pending か approved）にランキング用20問（daily）を入れる／差し替える
  python3 tools/stock_daily.py control                          … 版の保護（stock/control.json）の現在の内容を表示
  python3 tools/stock_daily.py control revoke <版番号>            … その版のアプリを止める（次の点呼で全画面の案内になる）
  python3 tools/stock_daily.py control unrevoke <版番号>          … 止めるのをやめる
  python3 tools/stock_daily.py control min <版番号|->             … その版より古い版をすべて止める（- で解除）。テスターに配ったファイル版も止まるので注意
  python3 tools/stock_daily.py control tester-revoke <ID>         … そのテスターコード（ID）を無効にする（漏れたコードだけ止める。アプリは通常版に戻る）
  python3 tools/stock_daily.py control tester-unrevoke <ID>
  python3 tools/stock_daily.py control extend <版番号> <YYYY-MM-DD> … その版の有効期限を延ばす
  python3 tools/stock_daily.py control notice "<文>"              … アプリのホームに出すお知らせ（"" で消す）
  ※ control を変えたら push で GitHub に上げる。版番号はアプリの「設定」→ バージョン情報の「版 YYYYMMDD-HHMM」

ファイルの形式は tools/生成の指示文.md を参照。
"""
import json, os, re, subprocess, sys, datetime, shutil

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STOCK = os.path.join(ROOT, 'stock')
PENDING = os.path.join(STOCK, 'pending')
APPROVED = os.path.join(STOCK, 'approved')
HOLD = os.path.join(STOCK, 'hold.txt')
INDEX = os.path.join(STOCK, 'index.json')
CONTROL = os.path.join(STOCK, 'control.json')  # 版の保護：止める版・無効にしたテスターID・期限の延長・お知らせ
BASE_TEXTS = os.path.join(ROOT, 'tools', 'base_texts.json')  # アプリに最初から入っている文（重複チェック用）

GENRES = ['daily', 'novel', 'news', 'essay', 'culture']
DRILL_LEN = {'A': (8, 18), 'B': (15, 35), 'C': (18, 53), 'D': (36, 75)}
READ_LEN = {'B': (15, 35), 'C': (18, 45), 'D': (40, 80)}
READ_KINDS = ['entail', 'contra']
TARGET = {'drills': 30, 'reads': 10}
MIN_OK = {'drills': 20, 'reads': 6}
# ランキング用（daily）：全員が同じ20問。長さの内訳はアプリの DAILY_PLAN と同じ（速読 A8/B3/C2/D1＝14、読解 B3/C2/D1＝6）
DAILY_PLAN = {'drills': {'A': 8, 'B': 3, 'C': 2, 'D': 1}, 'reads': {'B': 3, 'C': 2, 'D': 1}}


def jst_now():
    return datetime.datetime.utcnow() + datetime.timedelta(hours=9)


def load_json(path):
    with open(path, encoding='utf-8') as f:
        return json.load(f)


def save_json(path, data):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as f:
        json.dump(data, f, ensure_ascii=False, indent=1)
        f.write('\n')


def known_texts(exclude_file=None):
    """すでに使われている本文（初期バンク＋approved＋pending）"""
    texts = set()
    if os.path.exists(BASE_TEXTS):
        texts.update(load_json(BASE_TEXTS))
    for d in (APPROVED, PENDING):
        if not os.path.isdir(d):
            continue
        for fn in os.listdir(d):
            p = os.path.join(d, fn)
            if not fn.endswith('.json') or (exclude_file and os.path.abspath(p) == os.path.abspath(exclude_file)):
                continue
            try:
                data = load_json(p)
            except Exception:
                continue
            daily = data.get('daily') if isinstance(data.get('daily'), dict) else {}
            for it in data.get('drills', []) + data.get('reads', []) + daily.get('drills', []) + daily.get('reads', []):
                if isinstance(it, dict) and it.get('text'):
                    texts.add(it['text'])
    return texts


def validate(data, path_for_dup=None):
    """形式と品質の検査。(errors, warnings) を返す。errors が空なら合格"""
    errors, warnings = [], []
    if not isinstance(data, dict):
        return (['ファイル全体が JSON オブジェクトではありません'], [])
    date = data.get('date', '')
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', str(date)):
        errors.append('date は YYYY-MM-DD 形式にしてください: %r' % (date,))
    drills = data.get('drills', [])
    reads = data.get('reads', [])
    if not isinstance(drills, list) or not isinstance(reads, list):
        return (['drills / reads は配列にしてください'], [])
    used = known_texts(path_for_dup)
    seen_here = set()

    def check_text(it, where, ranges):
        t = it.get('text')
        if not isinstance(t, str) or not t.strip():
            errors.append('%s: text がありません' % where); return None
        t = t.strip()
        if t in used:
            errors.append('%s: 本文がすでに使われています「%s」' % (where, t))
        if t in seen_here:
            errors.append('%s: このファイルの中で本文が重複「%s」' % (where, t))
        seen_here.add(t)
        lv = it.get('lv')
        if lv not in ranges:
            errors.append('%s: lv は %s のどれかにしてください: %r' % (where, '/'.join(ranges), lv)); return t
        lo, hi = ranges[lv]
        if not (lo <= len(t) <= hi):
            errors.append('%s: lv %s の本文は %d〜%d 字（いま %d 字）「%s」' % (where, lv, lo, hi, len(t), t))
        if lv == 'A' and '、' in t:
            errors.append('%s: 単文（A）に「、」は入れません「%s」' % (where, t))
        if lv in ('B', 'C', 'D') and t.count('、') < {'B': 1, 'C': 2, 'D': 3}[lv]:
            warnings.append('%s: lv %s の割に「、」が少ないです（%d 個）「%s」' % (where, lv, t.count('、'), t))
        if it.get('g') not in GENRES:
            errors.append('%s: g（分野）は %s のどれかにしてください: %r' % (where, '/'.join(GENRES), it.get('g')))
        if not t.endswith(('。', '！', '？', '」')):
            warnings.append('%s: 文末が「。」で終わっていません「%s」' % (where, t))
        return t

    def check_drill(it, where):
        if not isinstance(it, dict):
            errors.append(where + ': オブジェクトではありません'); return
        t = check_text(it, where, DRILL_LEN)
        qs = it.get('qs')
        if not isinstance(qs, list) or not (2 <= len(qs) <= 4):
            errors.append(where + ': qs（問い）は 2〜4 個'); return
        answers = set()
        for j, q in enumerate(qs):
            w = '%s.qs[%d]' % (where, j)
            if not isinstance(q, dict):
                errors.append(w + ': オブジェクトではありません'); continue
            qq, a, d, h = q.get('q'), q.get('a'), q.get('d'), q.get('h')
            if not isinstance(qq, str) or not qq.strip():
                errors.append(w + ': q（質問文）がありません')
            elif not qq.rstrip().endswith('？'):
                warnings.append(w + ': 質問文は「？」で終えてください「%s」' % qq)
            if not isinstance(a, str) or not a.strip():
                errors.append(w + ': a（正答）がありません'); continue
            if t and a not in t and not (isinstance(h, str) and h and h in t):
                errors.append(w + ': 正答「%s」が本文にそのまま含まれていません（含まれる部分を h に書くこともできます）' % a)
            if isinstance(h, str) and h and t and h not in t:
                errors.append(w + ': h「%s」が本文にありません' % h)
            if a in answers:
                warnings.append(w + ': 同じ正答の問いが重複しています「%s」' % a)
            answers.add(a)
            if not isinstance(d, list) or len(d) < 3:
                errors.append(w + ': d（誤答）は 3 個以上'); continue
            if len(set(d)) != len(d):
                errors.append(w + ': 誤答が重複しています %r' % (d,))
            for x in d:
                if not isinstance(x, str) or not x.strip():
                    errors.append(w + ': 空の誤答があります'); continue
                if x == a:
                    errors.append(w + ': 誤答に正答と同じものがあります「%s」' % x)
                if t and x in t:
                    warnings.append(w + ': 誤答「%s」も本文に出てきます。問いの答えが一つに決まるか確かめてください' % x)
                if len(x) > max(12, len(a) * 3):
                    warnings.append(w + ': 誤答「%s」が正答に比べて長すぎます' % x)

    def check_read(it, where):
        if not isinstance(it, dict):
            errors.append(where + ': オブジェクトではありません'); return
        t = check_text(it, where, READ_LEN)
        kind = it.get('kind')
        if kind not in READ_KINDS:
            errors.append(where + ': kind は entail / contra'); return
        q, a, d, why = it.get('q'), it.get('a', ''), it.get('d'), it.get('why')
        if not isinstance(q, str) or not q.strip():
            errors.append(where + ': q（質問文）がありません')
        need = 2 if kind == 'contra' and not it.get('none') else 3
        if not isinstance(d, list) or len(d) < need:
            errors.append(where + ': d（誤答）は %d 個以上' % need); return
        if len(set(d)) != len(d):
            errors.append(where + ': 誤答が重複しています')
        if kind == 'contra' and it.get('none'):
            if a:
                warnings.append(where + ': none:true の矛盾なし問題では a は空にします')
        else:
            if not isinstance(a, str) or not a.strip():
                errors.append(where + ': a（正答）がありません')
            if a in d:
                errors.append(where + ': 誤答に正答と同じものがあります')
            if t and a and a in t:
                warnings.append(where + ': 読解の正答が本文にそのまま含まれています（言い換えにすると読解問題らしくなります）「%s」' % a)
        if not isinstance(why, str) or len(why.strip()) < 8:
            errors.append(where + ': why（解説）を 8 字以上で書いてください')
        if kind == 'contra' and it.get('lv') != 'D':
            warnings.append(where + ': 矛盾探しは四節以上（D）に置いています')

    for i, it in enumerate(drills):
        check_drill(it, 'drills[%d]' % i)
    for i, it in enumerate(reads):
        check_read(it, 'reads[%d]' % i)

    # ランキング用（daily）：全員が同じ20問。無ければ基本問題で代用されるので注意止まり、あれば内訳は厳密に
    daily = data.get('daily')
    if daily is None:
        warnings.append('ランキング用（daily）がありません。この日は基本問題から選んだ20問で代用されます')
    elif not isinstance(daily, dict) or not isinstance(daily.get('drills'), list) or not isinstance(daily.get('reads'), list):
        errors.append('daily は {"drills": [...14本], "reads": [...6問]} の形にしてください')
    else:
        for i, it in enumerate(daily['drills']):
            check_drill(it, 'daily.drills[%d]' % i)
        for i, it in enumerate(daily['reads']):
            check_read(it, 'daily.reads[%d]' % i)
        for key in ('drills', 'reads'):
            cnt = {}
            for it in daily[key]:
                if isinstance(it, dict):
                    cnt[it.get('lv')] = cnt.get(it.get('lv'), 0) + 1
            want = DAILY_PLAN[key]
            if cnt != want:
                errors.append('daily.%s の長さの内訳は %s にしてください（いま %s）' % (key, want, cnt))
        gd = set(it.get('g') for it in daily['drills'] + daily['reads'] if isinstance(it, dict))
        if len(gd) < 3:
            warnings.append('daily の分野が %d 種類しかありません（3 種類以上を推奨）' % len(gd))

    # 本数・配分
    if len(drills) < MIN_OK['drills']:
        errors.append('速読の文が %d 本しかありません（最低 %d、目安 %d）' % (len(drills), MIN_OK['drills'], TARGET['drills']))
    if len(reads) < MIN_OK['reads']:
        errors.append('読解が %d 問しかありません（最低 %d、目安 %d）' % (len(reads), MIN_OK['reads'], TARGET['reads']))
    lv_count = {}
    for it in drills:
        lv_count[it.get('lv')] = lv_count.get(it.get('lv'), 0) + 1
    for lv in 'ABCD':
        if lv_count.get(lv, 0) < 3:
            warnings.append('速読の長さ %s が %d 本です（各長さ 3 本以上を推奨）' % (lv, lv_count.get(lv, 0)))
    g_count = {}
    for it in drills + reads:
        g_count[it.get('g')] = g_count.get(it.get('g'), 0) + 1
    if len([g for g in GENRES if g_count.get(g, 0) > 0]) < 4:
        warnings.append('分野の種類が少ないです（%s）。5 分野をなるべく全部使ってください' % g_count)
    return errors, warnings


def cmd_validate(path, quiet=False):
    try:
        data = load_json(path)
    except Exception as e:
        print('NG: JSON として読めません: %s' % e); return 1
    errors, warnings = validate(data, path)
    for w in warnings:
        print('注意: ' + w)
    for e in errors:
        print('NG: ' + e)
    if errors:
        print('=> 不合格（%d 件）。直してから再度 validate してください' % len(errors)); return 1
    if not quiet:
        dl = data.get('daily') if isinstance(data.get('daily'), dict) else None
        print('=> 合格：%s 速読 %d 文（問い %d）・読解 %d 問・ランキング用 %s・注意 %d 件' % (data.get('date'), len(data['drills']), sum(len(d['qs']) for d in data['drills']), len(data['reads']), ('速読 %d・読解 %d' % (len(dl.get('drills', [])), len(dl.get('reads', []))) if dl else 'なし'), len(warnings)))
    return 0


def cmd_add(path):
    if cmd_validate(path, quiet=True) != 0:
        return 1
    data = load_json(path)
    dest = os.path.join(PENDING, data['date'] + '.json')
    if os.path.exists(dest) or os.path.exists(os.path.join(APPROVED, data['date'] + '.json')):
        print('NG: %s の分はすでにあります' % data['date']); return 1
    data['status'] = 'pending'
    data['added'] = datetime.datetime.now().isoformat(timespec='seconds')
    save_json(dest, data)
    print('pending に入れました: ' + os.path.relpath(dest, ROOT))
    return 0


def holds():
    if not os.path.exists(HOLD):
        return set()
    with open(HOLD, encoding='utf-8') as f:
        return set(l.strip() for l in f if re.match(r'\d{4}-\d{2}-\d{2}', l.strip()))


def cmd_promote(today=None):
    """pending のうち「日付が明日（日本時間）以前」で hold.txt にない分を approved へ。
    翌日分を前日に配るのは、ランキング用の20問を日付が変わった瞬間から全員に使えるようにするため"""
    today = today or jst_now().date().isoformat()
    limit = (datetime.date.fromisoformat(today) + datetime.timedelta(days=1)).isoformat()
    os.makedirs(APPROVED, exist_ok=True); os.makedirs(PENDING, exist_ok=True)
    held = holds(); moved = []
    for fn in sorted(os.listdir(PENDING)):
        if not fn.endswith('.json'):
            continue
        date = fn[:-5]
        if date in held:
            print('保留中（hold.txt）: ' + date); continue
        if date > limit:
            print('まだ配る日ではありません（前日の朝に配ります）: ' + date); continue
        data = load_json(os.path.join(PENDING, fn))
        errors, _ = validate(data, os.path.join(PENDING, fn))
        if errors:
            print('検査に通らないので昇格しません: %s（%s）' % (date, errors[0])); continue
        data['status'] = 'approved'
        data['approved'] = datetime.datetime.now().isoformat(timespec='seconds')
        save_json(os.path.join(APPROVED, fn), data)
        os.remove(os.path.join(PENDING, fn))
        moved.append(date)
    cmd_index()
    print('approved へ: %s' % (', '.join(moved) if moved else 'なし'))
    return 0


def cmd_index():
    os.makedirs(APPROVED, exist_ok=True)
    days = []
    for fn in sorted(os.listdir(APPROVED)):
        if fn.endswith('.json') and re.fullmatch(r'\d{4}-\d{2}-\d{2}\.json', fn):
            data = load_json(os.path.join(APPROVED, fn))
            dl = data.get('daily') if isinstance(data.get('daily'), dict) else None
            days.append({'date': fn[:-5], 'drills': len(data.get('drills', [])), 'reads': len(data.get('reads', [])), 'daily': bool(dl and len(dl.get('drills', [])) == 14 and len(dl.get('reads', [])) == 6)})
    index = {'updated': datetime.datetime.now().isoformat(timespec='seconds'), 'approved': [d['date'] for d in days], 'days': days}
    save_json(INDEX, index)
    print('index.json を更新: approved %d 日分' % len(days))
    return 0


def cmd_status():
    for name, d in (('approved', APPROVED), ('pending', PENDING)):
        files = sorted(fn for fn in os.listdir(d)) if os.path.isdir(d) else []
        files = [f for f in files if f.endswith('.json')]
        print('%s: %d 日分 %s' % (name, len(files), ', '.join(f[:-5] for f in files)))
    h = holds()
    print('保留（hold.txt）: %s' % (', '.join(sorted(h)) if h else 'なし'))
    return 0


def cmd_daily(date, path):
    """既存の日付ファイルにランキング用20問を入れる（差し替えも可）。検査に通らなければ書き換えない"""
    target = None
    for d in (PENDING, APPROVED):
        p = os.path.join(d, date + '.json')
        if os.path.exists(p):
            target = p; break
    if not target:
        print('NG: %s の日付ファイルが pending にも approved にもありません' % date); return 1
    try:
        daily = load_json(path)
    except Exception as e:
        print('NG: JSON として読めません: %s' % e); return 1
    if isinstance(daily, dict) and 'daily' in daily and isinstance(daily['daily'], dict):
        daily = daily['daily']
    data = load_json(target)
    data['daily'] = daily
    errors, warnings = validate(data, target)
    for w in warnings:
        print('注意: ' + w)
    for e in errors:
        print('NG: ' + e)
    if errors:
        print('=> 不合格（%d 件）。ファイルは書き換えていません' % len(errors)); return 1
    save_json(target, data)
    if target.startswith(APPROVED):
        cmd_index()
    print('ランキング用20問を入れました: ' + os.path.relpath(target, ROOT))
    return 0


def load_control():
    base = {'updated': '', 'minBuild': '', 'revoked': [], 'revokedTesters': [], 'extend': {}, 'notice': ''}
    if os.path.exists(CONTROL):
        try:
            base.update(load_json(CONTROL))
        except Exception as e:
            print('control.json が読めません: %s' % e)
    return base


def save_control(c):
    c['updated'] = datetime.datetime.now().strftime('%Y-%m-%d %H:%M')
    save_json(CONTROL, c)


def cmd_control(args):
    c = load_control()
    if not args:
        print('版の保護（stock/control.json）')
        print('  更新: %s' % (c.get('updated') or '—'))
        print('  minBuild（これより古い版は止まる）: %s' % (c.get('minBuild') or 'なし'))
        print('  revoked（止める版）: %s' % (', '.join(c.get('revoked') or []) or 'なし'))
        print('  revokedTesters（無効にしたテスターID）: %s' % (', '.join(c.get('revokedTesters') or []) or 'なし'))
        print('  extend（期限の延長）: %s' % (', '.join('%s→%s' % kv for kv in sorted((c.get('extend') or {}).items())) or 'なし'))
        print('  notice（お知らせ）: %s' % (c.get('notice') or 'なし'))
        return 0
    sub = args[0]; rest = args[1:]
    if sub == 'revoke' and rest:
        for b in rest:
            if b not in c['revoked']: c['revoked'].append(b)
        save_control(c); print('止める版に追加しました: %s' % ', '.join(rest)); return 0
    if sub == 'unrevoke' and rest:
        c['revoked'] = [b for b in c['revoked'] if b not in rest]
        save_control(c); print('止めるのをやめました: %s' % ', '.join(rest)); return 0
    if sub == 'min' and rest:
        c['minBuild'] = '' if rest[0] == '-' else rest[0]
        save_control(c); print('minBuild を %s にしました' % (c['minBuild'] or '解除')); return 0
    if sub == 'tester-revoke' and rest:
        for t in rest:
            t = t.upper()
            if t not in c['revokedTesters']: c['revokedTesters'].append(t)
        save_control(c); print('無効にしたテスターID: %s' % ', '.join(c['revokedTesters'])); return 0
    if sub == 'tester-unrevoke' and rest:
        up = [t.upper() for t in rest]
        c['revokedTesters'] = [t for t in c['revokedTesters'] if t not in up]
        save_control(c); print('無効を解除しました: %s' % ', '.join(up)); return 0
    if sub == 'extend' and len(rest) >= 2:
        if not re.match(r'^\d{4}-\d{2}-\d{2}$', rest[1]):
            print('日付は YYYY-MM-DD で'); return 2
        c['extend'][rest[0]] = rest[1]
        save_control(c); print('版 %s の期限を %s まで延ばしました' % (rest[0], rest[1])); return 0
    if sub == 'notice':
        c['notice'] = rest[0] if rest else ''
        save_control(c); print('お知らせ: %s' % (c['notice'] or '（消しました）')); return 0
    print(__doc__); return 2


def cmd_push(message):
    """stock/ の変更をコミットし、リモートの main ブランチへ直接 push する。
    どのブランチで作業していても main に入れる（HEAD:main）。まず通常の push（Claude の実行環境が
    認証を付けてくれる）を試し、だめなら GITHUB_TOKEN 入りの URL で再試行する。"""
    def run(*args):
        return subprocess.run(args, cwd=ROOT, capture_output=True, text=True)
    run('git', 'config', 'user.email', 'stock-bot@example.com')
    run('git', 'config', 'user.name', 'stock-bot')
    run('git', 'add', 'stock')
    r = run('git', 'commit', '-m', message)
    if r.returncode != 0 and 'nothing to commit' in (r.stdout + r.stderr):
        print('変更はありません'); return 0
    if r.returncode != 0:
        print('commit に失敗しました: ' + (r.stderr or r.stdout)[-800:]); return 1
    url = run('git', 'remote', 'get-url', 'origin').stdout.strip()
    token = os.environ.get('GITHUB_TOKEN', '')
    urls = ['origin']
    if token and url.startswith('https://') and '@' not in url:
        urls.append(url.replace('https://', 'https://x-access-token:%s@' % token))
    last = ''
    for attempt in range(2):
        for u in urls:
            r = run('git', 'push', u, 'HEAD:main')
            if r.returncode == 0:
                print('push しました（main）: ' + message); return 0
            last = (r.stderr or r.stdout)[-800:]
        # 先に他の更新が入っていた場合は取り込んでからもう一度
        if attempt == 0 and ('rejected' in last or 'fetch first' in last or 'non-fast-forward' in last):
            run('git', 'fetch', 'origin', 'main')
            run('git', 'rebase', 'origin/main')
        else:
            break
    print('push に失敗しました: ' + last)
    return 1


if __name__ == '__main__':
    args = sys.argv[1:]
    if not args:
        print(__doc__); sys.exit(2)
    cmd = args[0]
    if cmd == 'validate' and len(args) >= 2:
        sys.exit(cmd_validate(args[1]))
    if cmd == 'add' and len(args) >= 2:
        sys.exit(cmd_add(args[1]))
    if cmd == 'promote':
        sys.exit(cmd_promote(args[1] if len(args) >= 2 else None))
    if cmd == 'index':
        sys.exit(cmd_index())
    if cmd == 'status':
        sys.exit(cmd_status())
    if cmd == 'push':
        sys.exit(cmd_push(args[1] if len(args) >= 2 else '新作ストックの更新'))
    if cmd == 'control':
        sys.exit(cmd_control(args[1:]))
    if cmd == 'daily' and len(args) >= 3:
        sys.exit(cmd_daily(args[1], args[2]))
    print(__doc__); sys.exit(2)
