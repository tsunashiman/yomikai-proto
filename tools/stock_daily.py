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
  python3 tools/stock_daily.py plan [status|advance]           … 文の整備：次の実行で作るコマ（分野×長さ）と数／進み具合／進める
  python3 tools/stock_daily.py review <日付>                    … 他社AI確認用ファイル（stock/review/<日付>_他社AI確認用.md と latest.md）を作る
  python3 tools/stock_daily.py control min <版番号|->             … その版より古い版をすべて止める（- で解除）。テスターに配ったファイル版も止まるので注意
  python3 tools/stock_daily.py control tester-revoke <ID>         … そのテスターコード（ID）を無効にする（漏れたコードだけ止める。アプリは通常版に戻る）
  python3 tools/stock_daily.py control test-end <正式版のURL> [android=<URL>] [ios=<URL>] [message=<一言>]
                                                                  … 正式版の公開後に実行。試作版・テスト版はすべて「テスト版アプリは終了しました。正式版はこちらになります。」と
                                                                    止まり、正式版の入手先へのリンクだけを出す（test-end off で取り消し）
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
TOOLS = os.path.join(ROOT, 'tools')
BASE_TEXTS = os.path.join(TOOLS, 'base_texts.json')  # アプリに最初から入っている文（重複チェック用）

GENRES = ['daily', 'novel', 'news', 'essay', 'culture', 'hist', 'sci', 'civ']   # hist/sci/civ は「学習」（入試・試験のような事実の文。ふりがな付き。v48）
STUDY_GENRES = ['hist', 'sci', 'civ']   # 学習の分野：ランキング用（daily）と文の整備の計画（plan）には入れない
# ふりがなの印：漢字《よみ》 または ｜語《よみ》（アプリは印を外した文と、ふりがな付きの文の両方を持つ）。検査は印を外した形で行う
RUBY_RE = re.compile(r'(?:｜([^《》｜]+)|([\u4e00-\u9fff\u3400-\u4dbf々〆ヵヶ]+))《([^《》]+)》')


def plain(v):
    """ふりがなの印を外す（文字列以外はそのまま）"""
    if not isinstance(v, str) or '《' not in v:
        return v
    return RUBY_RE.sub(lambda m: m.group(1) or m.group(2), v)


def has_ruby(v):
    return isinstance(v, str) and bool(RUBY_RE.search(v))

DRILL_LEN = {'A': (8, 18), 'B': (15, 35), 'C': (18, 53), 'D': (36, 75)}
READ_LEN = {'A': (8, 18), 'B': (15, 35), 'C': (18, 53), 'D': (36, 80)}  # 読解は速読の文に付けるので、長さの範囲は速読と同じ（D は少し長めも可）
READ_KINDS = ['entail', 'contra']
TARGET = {'drills': 100, 'reads': 200}   # 1 回の実行の目安：速読 100 文（10 文 × 10 コマ）、読解は文ごとに付ける（二節以上は 2 問以上）
MIN_OK = {'drills': 10, 'reads': 0}
PLAN = os.path.join(STOCK, 'plan.json')          # 文の整備の進み具合（次に作るコマ）
BASE_CELLS = os.path.join(TOOLS, 'base_cells.json') # 最初から入っている文の 分野×長さ ごとの数
CELL_TARGET = 100                                 # 各分野×各長さの目標の文の数（基本の文を含む）
CELLS_PER_RUN = 10                                # 1 回の実行で作るコマの数（10 文 × 10 コマ＝100 文）
MAINT_PER_CELL = 3                                # 全コマが目標に達したあと、毎日各コマに足す文の数（サブスク向けの新作）
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
        texts.update(plain(t) for t in load_json(BASE_TEXTS))
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
                    texts.add(plain(it['text']).strip())
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
    drill_texts_here = set(plain(it.get('text', '')).strip() for it in drills if isinstance(it, dict) and isinstance(it.get('text'), str))
    read_qs_here = set()

    def check_text(it, where, ranges, attached_ok=False):
        t = it.get('text')
        if not isinstance(t, str) or not t.strip():
            errors.append('%s: text がありません' % where); return None
        if has_ruby(t):
            # ふりがなの印の形を確かめる（《 》の対応、印の中身が空でない、ひらがな以外の読み）
            if t.count('《') != t.count('》'):
                errors.append('%s: ふりがなの《 》の数が合いません「%s」' % (where, t))
            for m in RUBY_RE.finditer(t):
                if not re.fullmatch(r'[ぁ-ゖー]+', m.group(3)):
                    warnings.append('%s: ふりがな「%s」にひらがな以外が入っています' % (where, m.group(3)))
            if it.get('g') in STUDY_GENRES and re.search(r'[\u4e00-\u9fff]', plain(t)) and not re.search(r'[\u4e00-\u9fff]《', t):
                warnings.append('%s: 学習の文ですが、ふりがなの印が付いていない漢字があります' % where)
        elif it.get('g') in STUDY_GENRES and re.search(r'[\u4e00-\u9fff]', t):
            warnings.append('%s: 学習（%s）の文は、すべての漢字に 漢字《よみ》 の形でふりがなを付けてください「%s」' % (where, it.get('g'), t))
        t = plain(t).strip()
        attached = attached_ok and t in drill_texts_here  # 同じファイルの速読の文に付けた読解（本文の重複は問題ない）
        if not attached:
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
        if not isinstance(qs, list) or not (2 <= len(qs) <= 12):
            errors.append(where + ': qs（問い）は 2〜12 個（文中のあらゆる語を問う。単文 3〜6・二節 5〜8・三節と四節以上 7〜12 が目安）'); return
        if len(qs) < {'A': 3, 'B': 5, 'C': 7, 'D': 7}.get(it.get('lv'), 2):
            warnings.append(where + ': 問いが %d 個と少なめです（目安は 単文 3〜6・二節 5〜8・三節と四節以上 7〜12）' % len(qs))
        if len([q for q in qs if isinstance(q, dict) and q.get('t') == 'twist']) > 1:
            warnings.append(where + ': ひねり（t:twist）は 1 文に 1 つまで')
        answers = set()
        for j, q in enumerate(qs):
            w = '%s.qs[%d]' % (where, j)
            if not isinstance(q, dict):
                errors.append(w + ': オブジェクトではありません'); continue
            qq, a, d, h = plain(q.get('q')), plain(q.get('a')), q.get('d'), plain(q.get('h'))
            if isinstance(d, list):
                d = [plain(x) for x in d]
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
        t = check_text(it, where, READ_LEN, attached_ok=True)
        kind = it.get('kind')
        if kind not in READ_KINDS:
            errors.append(where + ': kind は entail / contra'); return
        q, a, d, why = plain(it.get('q')), plain(it.get('a', '')), it.get('d'), plain(it.get('why'))
        if isinstance(d, list):
            d = [plain(x) for x in d]
        if not isinstance(q, str) or not q.strip():
            errors.append(where + ': q（質問文）がありません')
        elif t:
            if (t, q) in read_qs_here:
                errors.append(where + ': 同じ本文に同じ質問文の読解が重複「%s」' % q)
            read_qs_here.add((t, q))
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
        if t and t in drill_texts_here:
            dl = next((x for x in drills if isinstance(x, dict) and plain(x.get('text', '')).strip() == t), None)
            if dl and dl.get('lv') != it.get('lv'):
                errors.append(where + ': lv が同じ本文の速読の文と違います')

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
        if gd & set(STUDY_GENRES):
            errors.append('daily（ランキング用）に学習の分野（%s）の文は入れません。一般の 5 分野だけにしてください' % '/'.join(sorted(gd & set(STUDY_GENRES))))
        if len(gd) < 3:
            warnings.append('daily の分野が %d 種類しかありません（3 種類以上を推奨）' % len(gd))

    # 本数：速読は最低 10 文。読解は文ごとに付ける（二節以上は 2 問以上、単文は成立するものだけ）
    if len(drills) < MIN_OK['drills']:
        errors.append('速読の文が %d 本しかありません（最低 %d、目安 %d）' % (len(drills), MIN_OK['drills'], TARGET['drills']))
    rc = {}
    for it in reads:
        if isinstance(it, dict):
            k = plain(it.get('text', '')).strip()
            rc[k] = rc.get(k, 0) + 1
    missing = [plain(it.get('text')) for it in drills if isinstance(it, dict) and it.get('lv') in ('B', 'C', 'D') and rc.get(plain(it.get('text', '')).strip(), 0) < 2]
    if missing:
        warnings.append('二節以上なのに読解が 2 問未満の文が %d 本あります（例「%s」）' % (len(missing), missing[0]))
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
    if os.path.exists(os.path.join(APPROVED, data['date'] + '.json')):
        print('NG: %s の分はすでに配信済み（approved）です' % data['date']); return 1
    if os.path.exists(dest):
        # 同じ日付の 2 回目の実行：既存の pending に足す（本文の重複は検査で弾かれる）
        cur = load_json(dest)
        cur['drills'] = cur.get('drills', []) + data.get('drills', [])
        cur['reads'] = cur.get('reads', []) + data.get('reads', [])
        if data.get('daily') and not cur.get('daily'):
            cur['daily'] = data['daily']
        cur['runs'] = int(cur.get('runs', 1)) + 1
        errors, _ = validate(cur, dest)
        if errors:
            print('NG: 既存の pending と合わせると検査に通りません: ' + errors[0]); return 1
        cur['added'] = datetime.datetime.now().isoformat(timespec='seconds')
        save_json(dest, cur)
        print('pending の %s に足しました（この日 %d 回目。速読 %d 文・読解 %d 問）: %s' % (data['date'], cur['runs'], len(cur['drills']), len(cur['reads']), os.path.relpath(dest, ROOT)))
        return 0
    data['status'] = 'pending'
    data['runs'] = 1
    data['added'] = datetime.datetime.now().isoformat(timespec='seconds')
    save_json(dest, data)
    print('pending に入れました: ' + os.path.relpath(dest, ROOT))
    return 0


# ---------- 文の整備の計画（各分野×各長さ 100 文）：次に作るコマと数を出す ----------
PLAN_GENRES = [g for g in GENRES if g not in STUDY_GENRES]   # 文の整備の計画は一般の 5 分野だけ（学習の分野は別立てで、出典の確認を伴うため人手の指示で足す）
CELL_ORDER = [(g, lv) for g in PLAN_GENRES for lv in 'ABCD']  # 分野1の各長さ → 分野2の各長さ → …


def cell_counts():
    """分野×長さ ごとの、いまある文の数（基本の文＋approved＋pending）"""
    counts = {}
    if os.path.exists(BASE_CELLS):
        for k, v in load_json(BASE_CELLS).items():
            counts[k] = int(v)
    for d in (APPROVED, PENDING):
        if not os.path.isdir(d):
            continue
        for fn in os.listdir(d):
            if not fn.endswith('.json'):
                continue
            try:
                data = load_json(os.path.join(d, fn))
            except Exception:
                continue
            for it in data.get('drills', []):
                if isinstance(it, dict):
                    k = '%s_%s' % (it.get('g'), it.get('lv'))
                    counts[k] = counts.get(k, 0) + 1
    return counts


def load_plan():
    base = {'next': 0, 'updated': ''}
    if os.path.exists(PLAN):
        try:
            base.update(load_json(PLAN))
        except Exception:
            pass
    return base


def cmd_plan(args):
    """plan            … 次の実行で作るコマ（分野×長さ）と文の数を JSON で出す
       plan advance    … 次の実行分（CELLS_PER_RUN コマ）を進めたことにする
       plan status     … コマごとの進み具合"""
    counts = cell_counts()
    plan = load_plan()
    remaining = {('%s_%s' % c): max(0, CELL_TARGET - counts.get('%s_%s' % c, 0)) for c in CELL_ORDER}
    done_all = all(v == 0 for v in remaining.values())
    if args and args[0] == 'status':
        for g in PLAN_GENRES:
            print(g.ljust(8) + '  '.join('%s:%3d/%d' % (lv, counts.get('%s_%s' % (g, lv), 0), CELL_TARGET) for lv in 'ABCD'))
        st = {g: sum(counts.get('%s_%s' % (g, lv), 0) for lv in 'ABCD') for g in STUDY_GENRES}
        print('学習（計画の対象外・人手の指示で足す）: ' + '  '.join('%s:%d 文' % (g, n) for g, n in st.items()))
        print('残り合計 %d 文。次のコマ: %s%s' % (sum(remaining.values()), CELL_ORDER[plan['next'] % len(CELL_ORDER)], '（全コマ達成 → 毎日各コマ %d 文の維持モード）' % MAINT_PER_CELL if done_all else ''))
        return 0
    if done_all:
        assign = [{'g': g, 'lv': lv, 'n': MAINT_PER_CELL} for (g, lv) in CELL_ORDER]
        out = {'mode': 'maintenance', 'cells': assign, 'total': sum(a['n'] for a in assign), 'note': '全コマが %d 文に達したので、毎日各コマに %d 文ずつ足す（朝の実行だけ）' % (CELL_TARGET, MAINT_PER_CELL)}
    else:
        assign = []; i = plan['next'] % len(CELL_ORDER); steps = 0
        while len(assign) < CELLS_PER_RUN and steps < len(CELL_ORDER):
            g, lv = CELL_ORDER[i]; r = remaining['%s_%s' % (g, lv)]
            if r > 0:
                assign.append({'g': g, 'lv': lv, 'n': min(10, r)})
            i = (i + 1) % len(CELL_ORDER); steps += 1
        out = {'mode': 'build', 'cells': assign, 'total': sum(a['n'] for a in assign), 'next_after': i, 'remaining_total': sum(remaining.values())}
    if args and args[0] == 'advance':
        if not done_all:
            plan['next'] = out['next_after']
        plan['updated'] = datetime.datetime.now().isoformat(timespec='seconds')
        save_json(PLAN, plan)
        print('進めました。次のコマ: %s' % (CELL_ORDER[plan['next'] % len(CELL_ORDER)],))
        return 0
    print(json.dumps(out, ensure_ascii=False, indent=1))
    return 0


# ---------- 他社AI確認用ファイル：その日の文・問い・選択肢・解説を、他社の AI にそのまま見せて点検を頼める形にまとめる ----------
REVIEW_DIR = os.path.join(STOCK, 'review')
REVIEW_INTRO = """# 速読・読解ドリルの問題チェックのお願い（{date} 分）

あなたは日本語の国語教材の編集者です。下の「問題一覧」は、速読・読解の訓練アプリで出題する問題です。
次の観点で 1 問ずつ点検し、問題があるものだけを、最後の「報告の形式」どおりに書き出してください。問題のないものは書かなくて結構です。

## 点検の観点
1. 【速読】正答の「中身」が本文の中にそのままの形で存在しているか。判断のルールは次のとおり。
   - 正答は質問に自然に答える形にしてあるため、本文の語句に「つなぎの語」が付いていることがある（例：「〜とき」「〜こと」「〜の」「〜ため」「〜から」「〜まで」「〜ところ」、助詞、「〜した」→「〜したとき」のような活用語尾の違い）。**つなぎの語が本文に無いことは不備ではない。**
   - 正答が本文の語句と一字一句同じでない問いには「本文中の該当部分」を添えてある。見るのは 2 点：(a) 該当部分が本文に一字一句同じ形で含まれているか、(b) 正答から該当部分を除いた残りが「つなぎの語」だけか（本文に無い内容語＝名詞・動詞・形容詞・数量・固有名詞や、言い換え・類義語・要約・推測が混じっていないか）。
   - 該当部分が添えられていない正答は、正答そのものが本文にそのまま含まれている（プログラムで検査済み）。その場合は「質問の答えとして正しい箇所か」だけを見る。
   - 例：本文「私が丘に駆け上がったら、海が見えた。」質問「海が見えたのはいつ？」正答「丘に駆け上がったとき」（本文中の該当部分「丘に駆け上がった」）→ 合格（「とき」はつなぎの語）。正答が「丘の上に着いたとき」なら、「着いた」が本文に無い言い換えなので不可。
2. 【読解】本文だけを見て、質問に対する正解を一つに導き出せるか。本文に書かれていない知識・前提・推測が無いと解けない問いは不可。逆に、本文の丸写しが正答になっている問い（読解になっていない）も不可
3. 日本語として不自然・誤字脱字・意味が取りにくい（本文・質問・選択肢・解説のすべて）
4. 選択肢のうち正しいものがちょうど 1 つになっていない。正答は 1 つだけ存在し、2 つ以上あってはいけない：示された正答以外の選択肢（誤答）のどれかが本文に照らして正しい・正しいとも読める場合は不可。本文の読み方によって答えが変わる場合も不可（アプリは正答を抜いて「上記以外」を正解にする回も作るので、誤答 3 個はどれも明確に誤りでなければならない）
5. 正答が誤り（本文を正確に読むと、示された正答が成り立たない。読解は特に注意）
6. 誤答が明らかに不自然で、消去法ですぐ分かってしまう（「紛らわしいが、正解を見れば納得できる」誤答になっていない）
7. 速読の問いなのに本文を読まなくても常識で答えられる
8. 解説（why）が答えの根拠を本文の言葉で説明できていない、または解説自体が誤っている
9. 事実関係の誤り（教養・ニュースの文で、本文の内容が明らかな事実誤認を含む）
10. 差別的・暴力的・性的・政治的など、不適切な表現
11. 改善案（より良い問い・選択肢・解説にできる具体案があれば）

## 問題一覧の読み方
- 「速読」：本文を読んだあとに本文が消え、質問に答える。**正解の中身は必ず本文の中にそのまま存在する語句**で、質問に合わせた「つなぎの語」だけが付くことがある（観点 1 のルール）。正答が本文と一字一句同じでない問いには「本文中の該当部分」を添えてある。選択肢は正答＋誤答 3 個（アプリが「上記以外」を 4 つ目として自動で足し、正答を抜いて「上記以外」が正解になる回もある）。
- 速読・読解とも、**選択肢のうち正しいものは 1 つだけ**（観点 4）。誤答のどれかが正しいと、正答が 2 つある問題になってしまう。
- 「読解」：本文を見ながら答える。**正解は本文だけから導けること**（観点 2）。正答は本文の言い換え・含意で、本文にそのままは書かれていない。
- 〔ひねり〕と付いた問いは、数や順序の計算が要る意図的な変化球です（意外性のために入れています。計算が正しいかを重点的に確認してください）。
- 「学習・歴史」「学習・理科」「学習・公民」の文は、入試・試験のような事実の文です。漢字の直後の《 》はふりがなで（例：鎌倉《かまくら》）、アプリでは印を外した文か、ふりがな付きの文として表示されます。**文の内容の点検は《 》を外した文で行い**、観点 9（事実の誤り）は年号・人名・数値・用語・しくみまで厳密に確かめてください（誤りの疑いがあれば、根拠となる出典を添えてください）。ふりがなの読みが誤っている・その文脈では別の読みが普通、という場合も観点 3 として指摘してください（幼稚園・小学校低学年の子が読むため）。

## 報告の形式（この形式で、問題のある問いだけを書いてください）
```
番号: <問題番号（例 S12 や R7）>
観点: <上の 1〜11 の番号>
指摘: <何がどう問題か、1〜2 文>
改善案: <直した本文／質問／選択肢／解説。直すものだけ>
```
最後に、点検した問題数、指摘した問題数、全体として気づいた傾向（もしあれば）を 2〜3 行で書いてください。
あなたの報告は、そのまま作成者（別の AI）に渡して直してもらいます。

---

# 問題一覧（{date} 分：速読 {nd} 文・問い {nq} 個／読解 {nr} 問）

"""
LEVEL_NAME = {'A': '単文', 'B': '二節', 'C': '三節', 'D': '四節以上'}
GENRE_NAME = {'daily': '日常会話', 'novel': '小説・エッセイ', 'news': 'ニュース', 'essay': '新聞・評論', 'culture': '教養', 'hist': '学習・歴史', 'sci': '学習・理科', 'civ': '学習・公民'}


def hit_part(text, q):
    """速読の正答が本文のどこに当たるか（印を外して比べる）。正答そのものが含まれていれば正答、無ければ h、どちらも無ければ None"""
    t = plain(text) or ''
    a = plain(q.get('a', '')) or ''
    if a and a in t:
        return q.get('a', '')
    h = q.get('h')
    if isinstance(h, str) and h and plain(h) in t:
        return h
    return None


def review_pack_text(data, date, title_note=''):
    drills = [d for d in data.get('drills', []) if isinstance(d, dict)]
    reads = [r for r in data.get('reads', []) if isinstance(r, dict)]
    nq = sum(len(d.get('qs', [])) for d in drills)
    out = [REVIEW_INTRO.format(date=date + title_note, nd=len(drills), nq=nq, nr=len(reads))]
    by_text = {}
    for r in reads:
        by_text.setdefault(r.get('text', '').strip(), []).append(r)
    sn = 0; rn = 0
    for d in drills:
        sn += 1
        out.append('## 文 %d　[%s・%s]　%s' % (sn, GENRE_NAME.get(d.get('g'), d.get('g')), LEVEL_NAME.get(d.get('lv'), d.get('lv')), d.get('text', '')))
        for j, q in enumerate(d.get('qs', [])):
            tw = '〔ひねり〕' if q.get('t') == 'twist' else ''
            core = hit_part(d.get('text', ''), q)
            out.append('- S%d-%d%s 速読　質問「%s」　正答「%s」%s　誤答「%s」' % (sn, j + 1, tw, q.get('q', ''), q.get('a', ''), ('（本文中の該当部分「%s」）' % core) if core is not None and plain(core) != plain(q.get('a', '')) else ('（※本文中に見つかりません）' if core is None else ''), '」「'.join(q.get('d', []))))
        for r in by_text.pop(d.get('text', '').strip(), []):
            rn += 1
            tw = '〔ひねり〕' if r.get('t') == 'twist' else ''
            kind = '矛盾探し' if r.get('kind') == 'contra' else '読解'
            ans = '（矛盾なし）' if r.get('none') else '「%s」' % r.get('a', '')
            out.append('- R%d%s %s　質問「%s」　正答%s　誤答「%s」　解説「%s」' % (rn, tw, kind, r.get('q', ''), ans, '」「'.join(r.get('d', [])), r.get('why', '')))
        out.append('')
    for t, rs in by_text.items():  # 速読の文に付いていない読解（独立の文）
        if not rs:
            continue
        r0 = rs[0]
        out.append('## 読解だけの文　[%s・%s]　%s' % (GENRE_NAME.get(r0.get('g'), r0.get('g')), LEVEL_NAME.get(r0.get('lv'), r0.get('lv')), t))
        for r in rs:
            rn += 1
            tw = '〔ひねり〕' if r.get('t') == 'twist' else ''
            kind = '矛盾探し' if r.get('kind') == 'contra' else '読解'
            ans = '（矛盾なし）' if r.get('none') else '「%s」' % r.get('a', '')
            out.append('- R%d%s %s　質問「%s」　正答%s　誤答「%s」　解説「%s」' % (rn, tw, kind, r.get('q', ''), ans, '」「'.join(r.get('d', [])), r.get('why', '')))
        out.append('')
    dl = data.get('daily') if isinstance(data.get('daily'), dict) else None
    if dl:
        out.append('# ランキング用の20問（%s 分。全員が同じ順で解く）' % date)
        out.append('')
        sub = review_pack_text({'drills': dl.get('drills', []), 'reads': dl.get('reads', [])}, date)
        out.append(sub.split('\n# 問題一覧（', 1)[1].split('\n', 2)[2] if '\n# 問題一覧（' in sub else sub)
    return '\n'.join(out)


def cmd_review(args):
    """review <日付> [--base]  … その日付のファイル（pending か approved）から他社AI確認用ファイルを作る。
       stock/review/<日付>_他社AI確認用.md と stock/review/latest.md（いちばん新しい分）に書く"""
    if not args:
        print('使い方: review <YYYY-MM-DD>'); return 1
    date = args[0]
    target = None
    for d in (PENDING, APPROVED):
        p = os.path.join(d, date + '.json')
        if os.path.exists(p):
            target = p; break
    if not target:
        print('NG: %s の日付ファイルがありません' % date); return 1
    data = load_json(target)
    text = review_pack_text(data, date)
    os.makedirs(REVIEW_DIR, exist_ok=True)
    dest = os.path.join(REVIEW_DIR, date + '_他社AI確認用.md')
    with open(dest, 'w', encoding='utf-8') as f:
        f.write(text)
    with open(os.path.join(REVIEW_DIR, 'latest.md'), 'w', encoding='utf-8') as f:
        f.write(text)
    print('他社AI確認用ファイルを書きました: %s（%d 字）と review/latest.md' % (os.path.relpath(dest, ROOT), len(text)))
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
            days.append({'date': fn[:-5], 'drills': len(data.get('drills', [])), 'reads': len(data.get('reads', [])), 'qs': sum(len(x.get('qs', [])) for x in data.get('drills', [])), 'daily': bool(dl and len(dl.get('drills', [])) == 14 and len(dl.get('reads', [])) == 6)})  # qs：問いの総数（増えたらアプリが取り直す）
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
    base = {'updated': '', 'minBuild': '', 'revoked': [], 'revokedTesters': [], 'extend': {}, 'notice': '', 'testEnd': {'ended': False, 'url': '', 'android': '', 'ios': '', 'message': ''}}
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
        te = c.get('testEnd') or {}
        print('  testEnd（テスト版の終了）: %s' % ('終了（正式版: %s%s%s）' % (te.get('url') or '公式の住所', ' / Android: ' + te['android'] if te.get('android') else '', ' / iOS: ' + te['ios'] if te.get('ios') else '') if te.get('ended') else 'まだ（試作版・テスト版は動く）'))
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
    if sub == 'test-end':
        # 正式版の公開後：test-end <正式版のURL> [android=<Play のURL>] [ios=<App Store のURL>] [message=<一言>]。test-end off で取り消し
        if rest and rest[0] == 'off':
            c['testEnd'] = {'ended': False, 'url': '', 'android': '', 'ios': '', 'message': ''}
            save_control(c); print('テスト版の終了を取り消しました（試作版・テスト版はまた動きます）'); return 0
        te = {'ended': True, 'url': rest[0] if rest and '=' not in rest[0] else '', 'android': '', 'ios': '', 'message': ''}
        for a in rest:
            if '=' in a:
                k, v = a.split('=', 1)
                if k in ('android', 'ios', 'message', 'url'): te[k] = v
        c['testEnd'] = te
        save_control(c); print('テスト版を終了にしました。試作版・テスト版は次の点呼で「テスト版アプリは終了しました。正式版はこちらになります。」と止まります（正式版: %s）' % (te['url'] or '公式の住所')); return 0
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
    if cmd == 'plan':
        sys.exit(cmd_plan(args[1:]))
    if cmd == 'review':
        sys.exit(cmd_review(args[1:]))
    print(__doc__); sys.exit(2)
