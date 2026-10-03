PWA公開用 一式（まじめに速読トレ 試作版）
==================================================

このフォルダの中身をそのまま Web サーバー（https）に置くと、
Android の Chrome では「アプリをインストール」、PC の Chrome／Edge では
アドレスバーの「インストール」から、アプリとして入れられます。

■ この試作版の公開先（設定済み・v57 で さくらのレンタルサーバ へ引っ越し）
 ・アプリの URL（公式の住所）：https://yomikai.tsunashiman.com/
 ・置き場所（サーバー）      ：さくらのレンタルサーバ（tsunashiman.sakura.ne.jp）の /home/tsunashiman/www/yomikai/
 ・ソースの保管庫（GitHub）  ：https://github.com/tsunashiman/yomikai-proto
   GitHub の main に置く（＝毎日の新作生成ルーティンが置く）と、GitHub Actions（.github/workflows/deploy.yml）が
   自動でサーバーの www/yomikai/ に同期します（反映まで 1〜2 分）。手動で置き直すときも GitHub に上げれば十分です。
 ・旧住所 https://tsunashiman.github.io/yomikai-proto/ は引っ越し期間中だけ動き、その後は「公式の住所で開いてください」の案内になります。
 ・.htaccess は さくら（Apache）用の設定（文字コード・MIME・キャッシュ）です。GitHub Pages では無視されます。

■ サーバー側（v58・さくらでだけ動く PHP）
 ・api/collect.php  ：ご意見・評価と利用ログ（テスター版）の受信。アプリの feedbackEndpoint がここを指す（Google スプレッドシートは不要になった）
 ・api/ranking.php  ：ランキング。その日の「今日のランキング戦」の初回の結果を集め、同じ20問を解いた人同士の順位表を返す
 ・api/health.php   ：動作確認（https://yomikai.tsunashiman.com/api/health.php で DB につながっているか分かる）
 ・admin/           ：開発者用の管理ページ https://yomikai.tsunashiman.com/admin/（テスター別まとめ・利用ログ・ご意見・ランキング・文別の成績・CSV）
                     合言葉は GitHub の Secret ADMIN_KEY。アプリからはどこにもリンクしていない
 ・データベース     ：さくらの MySQL 8.0（tsunashiman_app）。接続情報は GitHub の Secret SAKURA_DB_PASSWORD から、自動反映のたびに
                     サーバーの公開領域の外（/home/tsunashiman/secrets/db.json）へ書き出される。テーブルは最初のアクセスで自動作成
 ・GitHub Pages など PHP の無い場所に置いた場合、api/ は動かないので、順位表はサンプルプレイヤーとの比較だけになり、
   ご意見は「共有／メール／コピー」で送る画面になる（アプリ自体は動く）

■ 別の場所に置くとき（GitHub Pages・無料・約10分）
 1. https://github.com にログイン（アカウントがなければ作成）
 2. 右上「＋」→「New repository」。名前は例：yomikai-proto、Public を選び Create
 3. 「uploading an existing file」を押し、このフォルダの中の全ファイル
    （index.html / manifest.webmanifest / sw.js / png 4枚）をドラッグ＆ドロップ → Commit changes
 4. リポジトリの Settings → Pages → Branch を「main」「/(root)」にして Save
 5. 1〜2分後に表示される URL（https://ユーザー名.github.io/yomikai-proto/）を開く

■ Android での確認手順
 1. Chrome で上の URL を開く → ストア風の画面が出る
 2. 「インストール」を押す（ボタンが効かない場合は Chrome メニュー →「ホーム画面に追加」）
 3. ホーム画面のアイコンから起動 → ブラウザの枠なしで全画面で開けば成功
 4. 機内モードにしても起動できることを確認（オフライン対応）

■ PC（Windows）での確認手順
 1. Chrome または Edge で URL を開く
 2. アドレスバー右端の「インストール」アイコン → インストール
 3. デスクトップ／スタートメニューのアイコンから、専用ウィンドウで起動すれば成功

■ 注意
 ・sw.js（オフライン用）は https で公開したときだけ動きます。ファイルをダブルクリックで開いた場合は動きません。
 ・アプリ内の記録（スコア・チケット）はブラウザ内に保存されます。URL が変わると記録は引き継がれません。
 ・更新版はこのフォルダの中身をそのまま置き直せば切り替わります（sw.js のキャッシュ名は版ごとに自動で変わります）。
 ・版の保護：この一式（Web 版）は公式の住所以外では動かず、有効期限（作成から 60 日）があり、毎日 stock/control.json を見て
   止める版が指定されていれば止まります。公式の住所では点呼ができている限り期限は自動で延びます。
   止め方・仕組みは「コピー対策の仕組みと止め方.txt」を参照。
 ・data/bank.json（基本の問題データ）は必ず一緒に置いてください。アプリ本体には問題が入っておらず、起動時にここから取ります（2回目からは端末に保存した分で起動）。
 ・stock フォルダ（毎日の新作）と tools フォルダ（作成・検査のスクリプト）も一緒に置いてください。
   使い方は tools/スケジュール実行の設定手順.md と stock/README_問題ストック.txt を参照。
 ・.nojekyll は「置いたファイルをそのまま配信する」ための空の目印ファイルです。消さないでください。
