# -*- coding: utf-8 -*-
"""案内サイト（tsunashiman.com）のページを生成する。
   site/root/ … www/ に置く一式（トップ・アプリ紹介・特商法（雛形）・プライバシー・利用規約・404）
   site/jp/   … www/jp/ に置く一式（tsunashiman.jp → tsunashiman.com への転送だけ）
   文面を直すときはこのファイルを編集して python3 site/build_site.py を実行する（自動反映は GitHub に置いてから）。"""
import os, datetime

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.join(HERE, 'root')
JP = os.path.join(HERE, 'jp')
BRAND = 'Tsunashiman（ツナシマン）'
MAIL = 'info@tsunashiman.com'
SITE = 'https://tsunashiman.com/'
APP_URL = 'https://yomikai.tsunashiman.com/'
APP_NAME = 'まじめに速読トレ'
UPDATED = datetime.date(2026, 10, 3)

CSS = r"""
:root{--ink:#1d2433;--mut:#5f6b7a;--line:#e3e8f0;--bg:#f6f8fc;--card:#ffffff;--blue:#3B7DDD;--blue2:#2a5fb0;--coral:#FF6F8A;--ok:#1a9a5b;--maxw:1040px}
@media (prefers-color-scheme:dark){:root{--ink:#e8edf5;--mut:#9aa6b8;--line:#2a3443;--bg:#0f141c;--card:#171e29;--blue:#6ea0f0;--blue2:#9cbcf5}}
*{box-sizing:border-box}html{scroll-behavior:smooth}
body{margin:0;font:16px/1.8 -apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP","Yu Gothic UI",sans-serif;color:var(--ink);background:var(--bg)}
a{color:var(--blue2);text-decoration:none}a:hover{text-decoration:underline}
header.top{position:sticky;top:0;z-index:5;background:color-mix(in srgb,var(--card) 88%,transparent);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
header.top .in{max-width:var(--maxw);margin:0 auto;padding:10px 20px;display:flex;align-items:center;gap:16px}
.logo{display:flex;align-items:center;gap:10px;font-weight:800;letter-spacing:.02em;color:var(--ink)}
.logo .mark{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,var(--blue),var(--coral));display:inline-grid;place-items:center;color:#fff;font-weight:900;font-size:16px}
nav.menu{margin-left:auto;display:flex;gap:4px;flex-wrap:wrap}nav.menu a{padding:6px 10px;border-radius:8px;color:var(--ink);font-size:14px;white-space:nowrap}nav.menu a:hover{background:var(--bg);text-decoration:none}
@media(max-width:720px){header.top .in{flex-wrap:wrap;padding:8px 16px 6px}nav.menu{margin-left:0;width:100%;flex-wrap:nowrap;overflow-x:auto;gap:0;scrollbar-width:none}nav.menu::-webkit-scrollbar{display:none}nav.menu a{font-size:13px;padding:4px 8px}main{padding:18px 16px 48px}.hero{padding:22px 0 12px}br.pc{display:none}}
main{max-width:var(--maxw);margin:0 auto;padding:28px 20px 60px}
.hero{padding:36px 0 18px}.hero h1{font-size:clamp(26px,4.2vw,40px);line-height:1.3;margin:0 0 12px;letter-spacing:.01em}.hero p{font-size:17px;color:var(--mut);margin:0 0 18px;max-width:40em}
.eyebrow{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.14em;color:var(--blue2);text-transform:uppercase;margin-bottom:8px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin:18px 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px 22px}
.card h3{margin:0 0 6px;font-size:18px}.card p{margin:0;color:var(--mut);font-size:15px}
.app{display:grid;grid-template-columns:96px 1fr;gap:20px;align-items:start}
.app .icon{width:96px;height:96px;border-radius:22px;background:linear-gradient(135deg,#3B7DDD,#FF6F8A);display:grid;place-items:center;color:#fff;font-weight:900;font-size:30px;letter-spacing:.05em;box-shadow:0 10px 24px rgba(59,125,221,.25)}
@media(max-width:560px){.app{grid-template-columns:1fr}.app .icon{width:72px;height:72px;font-size:24px;border-radius:18px}}
.btn{display:inline-block;padding:11px 20px;border-radius:12px;background:var(--blue);color:#fff;font-weight:700;border:0;cursor:pointer}.btn:hover{text-decoration:none;filter:brightness(1.06)}
.btn.ghost{background:transparent;color:var(--blue2);border:1.5px solid var(--line)}
.tag{display:inline-block;font-size:12px;padding:2px 10px;border-radius:999px;background:#e8efff;color:#2a5fb0;font-weight:700;margin-left:6px;vertical-align:middle}
@media (prefers-color-scheme:dark){.tag{background:#24344f;color:#9cbcf5}}
.small{font-size:13px}.mut{color:var(--mut)}
h2{font-size:22px;margin:38px 0 10px}h2:first-child{margin-top:0}
.doc h2{font-size:19px;margin-top:30px}.doc h3{font-size:16px;margin:20px 0 6px}
.doc p,.doc li{font-size:15px}.doc ol,.doc ul{padding-left:1.4em}
table.tbl{border-collapse:collapse;width:100%;font-size:15px;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
table.tbl th,table.tbl td{padding:10px 14px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}table.tbl th{width:32%;background:color-mix(in srgb,var(--bg) 60%,var(--card));font-weight:700}
table.tbl tr:last-child td,table.tbl tr:last-child th{border-bottom:0}
.todo{background:#fff7e0;color:#6b4e00;border:1px dashed #e4b84a;padding:2px 8px;border-radius:6px;font-size:13px}
@media (prefers-color-scheme:dark){.todo{background:#3a2f0f;color:#ffd77a;border-color:#8a6d1f}}
.notice{background:color-mix(in srgb,var(--blue) 10%,var(--card));border:1px solid color-mix(in srgb,var(--blue) 30%,var(--line));border-radius:12px;padding:12px 16px;font-size:14px}
footer{border-top:1px solid var(--line);margin-top:30px}
footer .in{max-width:var(--maxw);margin:0 auto;padding:22px 20px;display:flex;flex-wrap:wrap;gap:8px 18px;font-size:13px;color:var(--mut)}
footer a{color:var(--mut)}footer .copy{margin-left:auto}
.price{display:flex;gap:14px;flex-wrap:wrap}.price .card{flex:1 1 220px}.price b.big{font-size:26px}
.tblwrap{overflow-x:auto;margin:14px 0}table.cmp{border-collapse:collapse;width:100%;min-width:560px;font-size:14px;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
table.cmp th,table.cmp td{padding:9px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}table.cmp thead th{background:color-mix(in srgb,var(--bg) 60%,var(--card));font-weight:700}table.cmp tbody th{width:27%;font-weight:600}
table.cmp .pro{background:color-mix(in srgb,var(--blue) 8%,var(--card))}table.cmp tr:last-child td,table.cmp tr:last-child th{border-bottom:0}
"""

def page(title, body, rel='', desc='', path_label=None):
    """共通の枠。rel は css へ戻る相対パス（'' or '../' or '../../'）"""
    home = rel or './'
    nav = ''.join('<a href="%s">%s</a>' % ((home if href == '' else rel + href), name) for href, name in [
        ('', 'ホーム'), ('apps/yomikai/', 'アプリ'), ('legal/privacy.html', 'プライバシー'), ('legal/terms.html', '利用規約'), ('legal/tokushoho.html', '特定商取引法'), ('#contact', 'お問い合わせ')])
    return ('<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<title>%(title)s</title><meta name="description" content="%(desc)s"><link rel="icon" href="%(rel)sfavicon.svg" type="image/svg+xml">'
            '<link rel="stylesheet" href="%(rel)ssite.css"><meta name="theme-color" content="#3B7DDD"></head><body>'
            '<header class="top"><div class="in"><a class="logo" href="%(home)s"><span class="mark">T</span><span>Tsunashiman</span></a><nav class="menu">%(nav)s</nav></div></header>'
            '<main>%(body)s</main>'
            '<footer><div class="in"><span>%(brand)s</span><a href="%(rel)slegal/privacy.html">プライバシーポリシー</a><a href="%(rel)slegal/terms.html">利用規約</a><a href="%(rel)slegal/tokushoho.html">特定商取引法に基づく表記</a><a href="mailto:%(mail)s">%(mail)s</a><span class="copy">© 2026 Tsunashiman</span></div></footer>'
            '</body></html>') % {'title': title, 'desc': desc or 'Tsunashiman（ツナシマン）— 考える力を鍛えるアプリをつくる個人開発ブランド。', 'rel': rel, 'home': home, 'nav': nav, 'body': body, 'brand': BRAND, 'mail': MAIL}

INDEX = page('Tsunashiman（ツナシマン）｜考える力を鍛えるアプリ', '''
<section class="hero">
  <span class="eyebrow">Tsunashiman / ツナシマン</span>
  <h1>毎日 5 分、「読んで考える力」を<br class="pc">鍛えるアプリをつくっています。</h1>
  <p>Tsunashiman は、個人で企画・開発しているアプリのブランドです。最初のアプリ「%(app)s」は、速く読んで、正しく読めたかを毎回チェックする、理解チェック付きの速読トレーニング。毎日開催の全国ランキング戦で、伸びが順位でわかります。いまは試作版を公開し、正式版に向けて改善を続けています。</p>
  <a class="btn" href="%(appurl)s">%(app)s を開く</a>
  <a class="btn ghost" href="apps/yomikai/">くわしく見る</a>
</section>

<h2>アプリ</h2>
<div class="card app">
  <div class="icon">速読</div>
  <div>
    <span class="eyebrow">毎日開催｜速読｜全国ランキング戦</span>
    <h3 style="margin:0 0 6px;font-size:20px">%(app)s <span class="tag">試作版</span></h3>
    <p><b>基礎の反復｜毎日開催・全国速読ランキング戦｜1日5分。</b>速く読んで、正しく読めたかを毎回チェック。「読んだつもり」を許さない、理解チェック付きの速読トレーニングです。超集中の 1 日 5 分。短い反復を毎日積み重ねるのがカギ。毎日開催の全国ランキング戦では、全員が同じ 20 問に挑戦。読み取り速度（字/分）の記録と順位で、伸びが目に見える。お子様にも安心して使える文章だけを収録しています。</p>
    <p class="small mut" style="margin-top:8px">Web アプリ（PC・スマホのブラウザで動作。ホーム画面に追加してアプリとしても使えます）。基本無料。正式版では新作の追加やランキング機能を広げるサブスクを予定。</p>
    <p style="margin-top:12px"><a class="btn" href="%(appurl)s">アプリを開く</a> <a class="btn ghost" href="apps/yomikai/">機能と料金</a></p>
  </div>
</div>

<h2>Tsunashiman について</h2>
<div class="grid">
  <div class="card"><h3>小さく作って、使う人と一緒に育てる</h3><p>最初から完成品を目指さず、試作版を実際に使ってもらい、ご意見をもとに毎週のように改善します。アプリ内の「ご意見・評価を送る」から届いた声は、すべて目を通しています。</p></div>
  <div class="card"><h3>続けられる設計</h3><p>1 回 5 分。毎日の新しい問題、順位、レベルの上下、復習の仕組みで、「今日もやろう」と思える流れを大切にしています。</p></div>
  <div class="card"><h3>個人情報を集めすぎない</h3><p>アプリは氏名や連絡先なしで使えます。記録は端末に保存し、サーバーに送るのは順位の計算に必要な最小限の情報だけです。<a href="legal/privacy.html">プライバシーポリシー</a></p></div>
</div>

<h2 id="contact">お問い合わせ</h2>
<div class="card">
  <p>アプリへのご意見・不具合のご報告は、アプリ内の「ご意見・評価を送る」からお送りいただくのがいちばん確実です。そのほかのご連絡は、メールでお願いします。</p>
  <p style="margin-top:8px"><a class="btn ghost" href="mailto:%(mail)s">%(mail)s</a></p>
  <p class="small mut" style="margin-top:8px">個人で運営しているため、返信に数日いただくことがあります。</p>
</div>
''' % {'app': APP_NAME, 'appurl': APP_URL, 'mail': MAIL}, rel='', desc='Tsunashiman（ツナシマン）は、個人で企画・開発するアプリのブランド。最初のアプリ「まじめに速読トレ」は、毎日開催の全国ランキング戦で伸びがわかる、理解チェック付きの速読トレーニングです。')

APP_PAGE = page('まじめに速読トレ（試作版）｜毎日開催・全国速読ランキング戦｜Tsunashiman', '''
<section class="hero">
  <span class="eyebrow">毎日開催｜速読｜全国ランキング戦</span>
  <h1>%(app)s <span class="tag">試作版</span></h1>
  <p style="font-weight:700;color:var(--ink)">基礎の反復｜毎日開催・全国速読ランキング戦｜1日5分</p>
  <p>速く読んで、正しく読めたかを毎回チェック。「読んだつもり」を許さない、理解チェック付きの速読トレーニングです。超集中の 1 日 5 分。短い反復を毎日積み重ねるのがカギ。</p>
  <p>毎日開催の全国ランキング戦では、全員が同じ 20 問に挑戦。読み取り速度（字/分）の記録と順位で、伸びが目に見える。全国の速読者はどのくらい速いのか。自分と比べて体感してみよう。</p>
  <a class="btn" href="%(appurl)s">アプリを開く（無料）</a>
  <p class="small mut" style="margin-top:10px">PC・スマホのブラウザで動きます。Android の Chrome では「インストール」、iPhone の Safari では「ホーム画面に追加」で、アプリとして起動できます。</p>
</section>

<h2>できること</h2>
<div class="grid">
  <div class="card"><h3>今日のランキング戦（全員同じ 20 問）</h3><p>毎日同じ 20 問を全員が解き、文が表示されてから「判定する」までの時間と正誤で順位が決まります。ランキングに記録されるのは当日の初回の受験だけで（当日 2 回目以降は練習扱いでランキング対象外）、順位は翌日に確定。順位でレベルが上下します。</p></div>
  <div class="card"><h3>練習モード</h3><p>分野（日常会話・小説・ニュース・エッセイ・教養・学習）と文の長さを選んで 10 文。毎日新作が追加され、自分の読み取り速度（字/分）が記録されます。</p></div>
  <div class="card"><h3>復習モード</h3><p>間違えた文・時間のかかった文から順に出題。同じ文でも問いが変わるので、答えを覚えるのではなく読み取る力がつきます。</p></div>
  <div class="card"><h3>学習の分野・自作問題</h3><p>小学生のお子様の学習にも役立つ「ふりがな・ひらがな表示」に対応した学習向けの分野（歴史・理科・公民）と、自分で文と問いを作れる自作問題（Excel からの取り込みも可）。</p></div>
  <div class="card"><h3>記録と弱点分析</h3><p>分野別・長さ別の正答率、読み取り速度の推移、過去のランキング戦への再挑戦。</p></div>
  <div class="card"><h3>お子様にも安心</h3><p>文章は、性的・暴力的・差別的な表現や、犯罪・自傷などを助長する内容を含まないことを基準に作成・点検しています。ニュース・評論の分野でも、事件や事故を生々しく描く文は入れません。小さなお子様や未成年の方にも安心してお使いいただけます。万一気になる文があれば、アプリの判定画面の「通報・指摘」からお知らせください（確認のうえ取り下げます）。</p></div>
  <div class="card"><h3>プライバシー</h3><p>氏名・連絡先なしで使えます。記録は端末内に保存し、サーバーに送るのは順位の計算に必要な最小限の情報だけ。<a href="../../legal/privacy.html">くわしく</a></p></div>
</div>

<h2>料金（予定）</h2>
<div class="notice">試作版のあいだは、すべての機能を無料で試せます（アプリ内の「サブスク登録」は模擬で、請求は発生しません）。正式版の料金は以下を予定しており、変更する場合はこのページとアプリ内でお知らせします。</div>
<div class="price" style="margin-top:14px">
  <div class="card"><span class="eyebrow">無料</span><br><b class="big">0 円</b><p>ランキング戦（毎日 20 問の新作）・練習の新作 10 文/日・復習は無制限・ランキング参加（読者#番号で掲載）・直近 7 日の記録</p></div>
  <div class="card"><span class="eyebrow">サブスク 月額</span><br><b class="big">500 円</b><span class="mut">/月（税込）</span><p>練習の新作 30 文/日・分野の自由選択・時事と教養の毎日枠・過去のランキング戦・全期間の記録と弱点分析・ペンネーム掲載・週間/月間ランキング。1 日あたり約 17 円。</p><p style="margin-top:8px"><a href="#compare">無料との違いを一覧表で見る ›</a></p></div>
  <div class="card"><span class="eyebrow">サブスク 年額</span><br><b class="big">5,000 円</b><span class="mut">/年（税込・2 か月分お得）</span><p>月額と同じ内容。月額換算 417 円、1 日あたり約 14 円。</p><p style="margin-top:8px"><a href="#compare">無料との違いを一覧表で見る ›</a></p></div>
</div>
<p class="small mut">サブスクの最小単位は 1 か月（または 1 年）です。解約はいつでもでき、次の更新日から請求が止まります（期間の途中で解約しても日割りの返金はありません）。チケット（5 枚 500 円＝1 枚 100 円で新作 10 文を追加。10 枚なら 900 円）も検討中です。</p>

<h2 id="compare">無料とサブスクの違い（一覧表）</h2>
<div class="tblwrap"><table class="cmp"><thead><tr><th></th><th>無料</th><th class="pro">サブスク</th></tr></thead><tbody>
<tr><th>今日のランキング戦</th><td>毎日 20 問</td><td class="pro">同じ</td></tr>
<tr><th>練習モードで解ける<b>初めて見る文</b></th><td><b>1 日 10 文</b>（分野・節数の合計）＋チケット 1 枚で +10 文</td><td class="pro"><b>1 日 30 文</b>（合計）</td></tr>
<tr><th>練習で選べる分野</th><td>ミックス・今日の分野・学習・自作</td><td class="pro"><b>すべての分野</b></td></tr>
<tr><th>練習で選べる節数</th><td>すべて</td><td class="pro">同じ</td></tr>
<tr><th>復習モード</th><td>無制限</td><td class="pro">無制限＋<b>ランキング戦の文も</b></td></tr>
<tr><th>自作問題・自作問題モード</th><td>あり（画面から 1 文ずつ）</td><td class="pro">同じ＋<b>Excel でまとめて取り込み・書き出し</b></td></tr>
<tr><th>過去のランキング戦</th><td>なし</td><td class="pro"><b>前日までの全て</b>（2026 年 9 月 29 日の公開以降）</td></tr>
<tr><th>記録</th><td>直近 7 日</td><td class="pro"><b>全期間</b>＋弱点分析</td></tr>
<tr><th>ランキングの掲載</th><td>読者#番号</td><td class="pro"><b>ペンネーム＋★</b>・週間／月間</td></tr>
<tr><th>料金（予定）</th><td>0 円</td><td class="pro">月 500 円／年 5,000 円（税込・2 か月分無料）<br><span class="small">1 日あたり 月契約 約 17 円／年契約 約 14 円。7 日間無料トライアル</span></td></tr>
</tbody></table></div>
<p class="small mut">新作の文そのものは、無料・サブスクとも毎日すべての分野・節数が端末に届きます。違いは「初めて見る文を 1 日に何文まで練習できるか」と「分野を自由に選べるか」です。1 日の枠は分野・節数の合計で、どの分野・どの節数に使ってもかまいません。「今日のランキング戦」の文は枠に数えません。枠を使い切った日も、見たことのある文の練習と復習モードは無制限です。</p>

<h2>動作環境</h2>
<p class="small">Chrome・Edge・Safari の最新版（PC・Android・iPhone/iPad）。初回はインターネット接続が必要です。2 回目からは、問題を受け取る以外はオフラインでも動きます。</p>

<h2>お問い合わせ</h2>
<p class="small">不具合・ご意見はアプリ内の「ご意見・評価を送る」から。メール：<a href="mailto:%(mail)s">%(mail)s</a></p>
''' % {'app': APP_NAME, 'appurl': APP_URL, 'mail': MAIL}, rel='../../', desc='まじめに速読トレ（試作版）の機能・料金・動作環境。基礎の反復｜毎日開催・全国速読ランキング戦｜1日5分。理解チェック付きの速読トレーニング。')

TOKUSHOHO = page('特定商取引法に基づく表記｜Tsunashiman', '''
<div class="doc">
<span class="eyebrow">Legal</span>
<h1 style="font-size:24px;margin:0 0 10px">特定商取引法に基づく表記</h1>
<p class="notice"><b>この表記は雛形です（準備中）。</b>有料サービスの販売開始までに、<span class="todo">黄色の部分</span>を確定して差し替えます。販売開始前のため、現時点でお支払いが発生するサービスはありません。</p>
<table class="tbl" style="margin-top:14px">
<tr><th>販売業者（屋号）</th><td>Tsunashiman（ツナシマン）</td></tr>
<tr><th>運営責任者</th><td><span class="todo">氏名（個人事業主の本名）</span></td></tr>
<tr><th>所在地</th><td><span class="todo">郵便番号・住所</span><br><span class="small mut">※個人事業のため、Web 上では省略し「請求があれば遅滞なく開示します」とする方法も選べます（その場合はこの欄に「請求があれば遅滞なく開示いたします。下記メールアドレスまでご連絡ください。」と記載）。</span></td></tr>
<tr><th>電話番号</th><td><span class="todo">電話番号</span><br><span class="small mut">※所在地と同様に、「請求があれば遅滞なく開示いたします」とする方法も選べます。</span></td></tr>
<tr><th>メールアドレス</th><td>%(mail)s</td></tr>
<tr><th>販売 URL</th><td>%(appurl)s（アプリ内）</td></tr>
<tr><th>販売価格</th><td>サブスクリプション 月額 500 円（税込）／年額 5,000 円（税込）、チケット 5 枚 500 円／10 枚 900 円（税込）<span class="todo">（予定・確定後に更新）</span>。各サービスの価格は、お申し込み画面に表示します。</td></tr>
<tr><th>商品代金以外の必要料金</th><td>インターネット接続にかかる通信料（お客様のご負担）</td></tr>
<tr><th>お支払い方法</th><td>クレジットカード（決済代行：<span class="todo">PAY.JP</span>）</td></tr>
<tr><th>お支払い時期</th><td>お申し込み時に初回分を決済し、以後は更新日に自動で決済します。</td></tr>
<tr><th>サービスの提供時期</th><td>決済の完了後、ただちにご利用いただけます。</td></tr>
<tr><th>返品・キャンセル</th><td>デジタルコンテンツの性質上、決済後の返金・キャンセルはお受けしていません。サブスクリプションは、アプリ内の設定からいつでも解約でき、解約後は次の更新日以降の請求が停止します（期間途中の解約による日割り返金はありません）。</td></tr>
<tr><th>動作環境</th><td>Chrome・Edge・Safari の最新版（PC・Android・iPhone/iPad）。インターネット接続が必要です。</td></tr>
<tr><th>特別な販売条件</th><td>未成年の方は、保護者の同意を得たうえでお申し込みください。</td></tr>
</table>
<p class="small mut" style="margin-top:12px">最終更新：%(date)s</p>
</div>
''' % {'mail': MAIL, 'appurl': APP_URL, 'date': UPDATED.strftime('%%Y年%%-m月%%-d日') if False else '2026年10月3日'}, rel='../', desc='Tsunashiman の特定商取引法に基づく表記。')

PRIVACY = page('プライバシーポリシー｜Tsunashiman', '''
<div class="doc">
<span class="eyebrow">Legal</span>
<h1 style="font-size:24px;margin:0 0 10px">プライバシーポリシー</h1>
<p>Tsunashiman（ツナシマン・以下「当方」）は、当方が提供するアプリ・Web サイト（以下「本サービス」）における利用者の情報の取り扱いについて、次のとおり定めます。</p>

<h2>1. 取得する情報</h2>
<h3>（1）アプリの利用に伴い自動的に生成・送信される情報</h3>
<ul>
<li><b>利用者番号・端末 ID</b>：アプリが端末ごとに自動で割り当てる番号（氏名などとは結びつきません）。ランキングの集計と、同じ端末からの重複送信を除くために使います。</li>
<li><b>ランキングの結果</b>：「今日のランキング戦」の初回の結果（スコア・正解数・読み取り速度・所要時間・表示名）。順位表の作成に使い、表示名（読者#番号またはペンネーム）とスコアは他の利用者にも表示されます。</li>
<li><b>利用状況（テスター版のみ）</b>：テスターコードを有効にした端末では、いつ・どの機能を・どのくらい使ったか、成績、エラーの記録を送信します。改善の目的にだけ使い、文章の自作問題の本文など、利用者が書いた内容は送信しません。</li>
<li><b>端末情報</b>：OS の種類、画面サイズ、ブラウザの種類（不具合の調査に使います）。IP アドレスは保存せず、送りすぎ防止のために短時間だけ符号化した形で扱います。</li>
</ul>
<h3>（2）利用者が入力して送信する情報</h3>
<ul>
<li><b>ご意見・評価</b>：評価、自由記述、任意で入力されたお名前・連絡先。改善と、同意いただいた場合の紹介文への引用に使います。</li>
<li><b>メールアドレス</b>：アカウント機能（ログイン用のリンクの送付）と、有料プランの管理・ご連絡に使います。</li>
<li><b>決済情報</b>：クレジットカード番号などの決済情報は決済代行会社（PAY.JP を予定）が取り扱い、当方のサーバーには保存されません。当方が受け取るのは、決済の成否・プラン・期間などの情報です。</li>
</ul>
<h3>（3）端末内に保存される情報</h3>
<p>学習の記録、設定、自作問題などはブラウザの保存領域（localStorage・IndexedDB）に保存されます。これらは利用者の端末内にあり、当方のサーバーには送信されません。</p>
<p>次の操作をすると、この記録は消え、元に戻せません。</p>
<ol>
<li>ブラウザの「閲覧履歴データの削除」などで Cookie やサイトのデータを消したとき（Chrome：設定 →「プライバシーとセキュリティ」→「閲覧履歴データの削除」で「Cookie と他のサイトデータ」にチェックを入れて削除した場合。iPhone の Safari：設定 →「Safari」→「履歴と Web サイトデータを消去」）。</li>
<li>本アプリのサイト（yomikai.tsunashiman.com）のデータだけを個別に削除したとき。</li>
<li>ブラウザや、ホーム画面に追加したアプリをアンインストール・再インストールしたとき。</li>
<li>端末を初期化したとき、または機種変更で別の端末に移ったとき（記録は端末ごとに保存され、自動では引き継がれません）。</li>
<li>シークレットモード／プライベートブラウズで使ったとき（ウィンドウを閉じると消えます）。</li>
<li>iPhone・iPad の Safari でブラウザのまま使っていて、7 日以上開かなかったとき（Safari の仕様で、使われていないサイトのデータが自動で削除されることがあります。ホーム画面に追加して使うと、この対象になりません）。</li>
</ol>
<p>また、端末の空き容量が少なくなると、ブラウザが古いサイトのデータを自動で削除することがあります。本アプリは起動時にブラウザへデータの保持を要求しますが（対応ブラウザのみ）、確実ではありません。ホーム画面に追加（インストール）して使うと、消えにくくなります。</p>

<h2>2. 利用目的</h2>
<ul>
<li>本サービスの提供・運営（ランキング・アカウント・有料プランの管理）</li>
<li>品質の改善、不具合の調査、新機能の検討</li>
<li>お問い合わせへの対応、重要なお知らせの送付</li>
<li>利用規約に反する利用（不正なスコアの送信など）への対応</li>
</ul>

<h2>3. 第三者への提供・委託</h2>
<p>法令に基づく場合を除き、利用者の情報を第三者に提供しません。サーバーの運用（さくらインターネット株式会社）および決済（決済代行会社）については、業務に必要な範囲で委託します。</p>

<h2>4. Cookie 等について</h2>
<p>本サービスは、ログイン状態の維持や設定の保存のために Cookie やブラウザの保存領域を使います。広告目的の Cookie や外部の解析ツールは使っていません。</p>

<h2>5. 保存期間と削除</h2>
<p>取得した情報は、利用目的に必要な期間保存します。アカウントの削除やデータの削除を希望される場合は、下記までご連絡ください。合理的な期間内に対応します。</p>

<h2>6. 安全管理</h2>
<p>情報は暗号化された通信（HTTPS）で送受信し、アクセスを制限したサーバーで管理します。</p>

<h2>7. 改定</h2>
<p>本ポリシーを改定する場合は、本ページでお知らせします。重要な変更はアプリ内でもお知らせします。</p>

<h2>8. お問い合わせ</h2>
<p>Tsunashiman（ツナシマン）　メール：<a href="mailto:%(mail)s">%(mail)s</a></p>
<p class="small mut">制定：2026年10月3日</p>
</div>
''' % {'mail': MAIL}, rel='../', desc='Tsunashiman のプライバシーポリシー。')

TERMS = page('利用規約｜Tsunashiman', '''
<div class="doc">
<span class="eyebrow">Legal</span>
<h1 style="font-size:24px;margin:0 0 10px">利用規約</h1>
<p>この利用規約（以下「本規約」）は、Tsunashiman（ツナシマン・以下「当方」）が提供するアプリおよび Web サイト（以下「本サービス」）の利用条件を定めるものです。利用者は、本サービスを利用することで本規約に同意したものとみなします。</p>

<h2>第1条（適用）</h2>
<p>本規約は、本サービスの利用に関する当方と利用者との間のすべての関係に適用されます。当方がアプリ内や Web サイトで示す個別の案内・注意事項も本規約の一部を構成します。</p>

<h2>第2条（アカウント）</h2>
<ol>
<li>一部の機能は、メールアドレスによるアカウント登録が必要です。利用者は正確な情報を登録し、ログイン用のリンクやコードを第三者に渡してはなりません。</li>
<li>アカウントの管理は利用者の責任で行うものとし、第三者による利用から生じた損害について、当方の故意または重過失による場合を除き、当方は責任を負いません。</li>
</ol>

<h2>第3条（有料サービス）</h2>
<ol>
<li>有料プラン（サブスクリプション等）の内容・価格・支払方法は、アプリ内の申込画面および「特定商取引法に基づく表記」に定めるとおりとします。</li>
<li>サブスクリプションは、解約の手続きがない限り、期間満了時に自動で更新されます。解約はアプリ内の設定からいつでも行え、解約後は次回更新日以降の請求が停止します。期間途中の解約による日割りの返金は行いません。</li>
<li>デジタルコンテンツの性質上、決済後の返金はお受けしません。ただし、当方の責めに帰すべき事由によりサービスが提供できなかった場合はこの限りではありません。</li>
</ol>

<h2>第4条（禁止事項）</h2>
<p>利用者は、次の行為をしてはなりません。</p>
<ul>
<li>不正な方法でスコアやランキングを操作する行為（自動操作、改変したアプリの使用、複数の端末や番号を使い分けた重複参加など）</li>
<li>本サービスの問題文・画像・プログラムを、私的利用の範囲を超えて複製・転載・再配布・販売する行為</li>
<li>テスター版・配布版を、当方の許可なく第三者に渡す行為</li>
<li>他の利用者や第三者の権利を侵害する行為、公序良俗に反するペンネームや投稿</li>
<li>本サービスの運営を妨げる行為、サーバーに過度の負荷をかける行為</li>
<li>その他、当方が不適切と判断する行為</li>
</ul>

<h2>第5条（利用制限・登録抹消）</h2>
<p>当方は、利用者が本規約に違反した場合、事前の通知なく、ランキングの記録の削除、機能の制限、アカウントの停止・削除を行うことができます。</p>

<h2>第6条（知的財産権）</h2>
<p>本サービスに含まれる文章・問題・デザイン・プログラムに関する権利は、当方または正当な権利者に帰属します。利用者が自作問題として作成した文章の権利は、作成した利用者に帰属します。</p>

<h2>第7条（サービスの変更・中断・終了）</h2>
<ol>
<li>当方は、利用者への事前の通知なく、本サービスの内容を変更し、または提供を中断・終了することがあります。試作版・テスト版は、正式版の公開に伴い終了することがあります。</li>
<li>有料プランの提供を終了する場合は、合理的な期間をもって事前にお知らせし、未提供期間分の取り扱いを案内します。</li>
</ol>

<h2>第8条（免責）</h2>
<ol>
<li>当方は、本サービスの内容の正確性・有用性・特定目的への適合性について保証しません。学習効果には個人差があります。</li>
<li>利用者の端末内に保存された記録の消失（ブラウザのデータ消去、端末の故障等）について、当方は責任を負いません。</li>
<li>当方の責めに帰すべき事由により利用者に損害が生じた場合、当方の賠償責任は、当該利用者が過去 12 か月間に当方に支払った利用料金の額を上限とします（当方に故意または重過失がある場合を除く）。</li>
</ol>

<h2>第9条（規約の変更）</h2>
<p>当方は、必要に応じて本規約を変更できます。変更後の規約は、本ページに掲載した時点から効力を生じます。重要な変更はアプリ内でもお知らせします。</p>

<h2>第10条（準拠法・管轄）</h2>
<p>本規約は日本法に準拠します。本サービスに関して紛争が生じた場合、当方の所在地を管轄する裁判所を第一審の専属的合意管轄裁判所とします。</p>

<p class="small mut">制定：2026年10月3日</p>
</div>
''', rel='../', desc='Tsunashiman の利用規約。')

NOTFOUND = page('ページが見つかりません｜Tsunashiman', '''
<section class="hero"><h1>ページが見つかりません</h1><p>URL が変わったか、削除された可能性があります。</p><a class="btn" href="/">ホームへ</a></section>
''', rel='/')

FAVICON = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3B7DDD"/><stop offset="1" stop-color="#FF6F8A"/></linearGradient></defs><rect width="64" height="64" rx="16" fill="url(#g)"/><text x="32" y="44" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-weight="900" font-size="36" fill="#fff">T</text></svg>'''

JP_HTACCESS = '''# tsunashiman.jp → tsunashiman.com へ転送（パスはそのまま引き継ぐ）
RewriteEngine On
RewriteRule ^(.*)$ https://tsunashiman.com/$1 [R=301,L]
'''
JP_INDEX = '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta http-equiv="refresh" content="0; url=https://tsunashiman.com/"><title>Tsunashiman</title></head><body><p>移動しました：<a href="https://tsunashiman.com/">https://tsunashiman.com/</a></p></body></html>\n'

def write(path, text):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(text)

for p in (ROOT, JP):
    os.makedirs(p, exist_ok=True)
write(os.path.join(ROOT, 'index.html'), INDEX)
write(os.path.join(ROOT, 'site.css'), CSS.strip() + '\n')
write(os.path.join(ROOT, 'favicon.svg'), FAVICON)
write(os.path.join(ROOT, 'apps', 'yomikai', 'index.html'), APP_PAGE)
write(os.path.join(ROOT, 'legal', 'tokushoho.html'), TOKUSHOHO)
write(os.path.join(ROOT, 'legal', 'privacy.html'), PRIVACY)
write(os.path.join(ROOT, 'legal', 'terms.html'), TERMS)
write(os.path.join(ROOT, '404.html'), NOTFOUND)
write(os.path.join(ROOT, 'robots.txt'), 'User-agent: *\nAllow: /\nSitemap: https://tsunashiman.com/sitemap.xml\n')
write(os.path.join(ROOT, 'sitemap.xml'), '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + ''.join('  <url><loc>%s%s</loc></url>\n' % (SITE, u) for u in ['', 'apps/yomikai/', 'legal/privacy.html', 'legal/terms.html', 'legal/tokushoho.html']) + '</urlset>\n')
write(os.path.join(JP, '.htaccess'), JP_HTACCESS)
write(os.path.join(JP, 'index.html'), JP_INDEX)
print('site generated:', ROOT)
for root_, _, files in os.walk(HERE):
    for fn in sorted(files):
        if fn.endswith('.py'): continue
        full = os.path.join(root_, fn); print('%7d  %s' % (os.path.getsize(full), os.path.relpath(full, HERE)))
