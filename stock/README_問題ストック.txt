問題ストック（毎日の新作の置き場所）
==================================================

stock/index.json         … 配信する日付の一覧（approved）。アプリはこれを見に来る。tools/stock_daily.py index で作り直せる
stock/approved/日付.json … 配信中の1日分（速読30文・読解10問）。アプリは未取得の日付だけを取りに来て端末に保存する
stock/pending/日付.json  … 作ったばかりで、まだ配信していない分。日付が来ると翌朝の実行で approved へ
stock/hold.txt           … 配信を止めたい日付を1行ずつ。ここにある日付は approved に上がらない

日付ファイルの形式と作り方 … tools/生成の指示文.md
毎日の自動実行の設定       … tools/スケジュール実行の設定手順.md
検査・昇格・push          … python3 tools/stock_daily.py（使い方はファイル冒頭）

手で1日分を足したいとき：
  1. tools/生成の指示文.md の形式で JSON を作る（見本：stock/approved/2026-10-01.json）
  2. python3 tools/stock_daily.py add その.json      ← 検査して pending へ
  3. python3 tools/stock_daily.py promote            ← 日付が来ていれば approved へ、index.json も更新
  4. GitHub にアップロード（または python3 tools/stock_daily.py push "新作 YYYY-MM-DD"）

置き場所：https://github.com/tsunashiman/yomikai-proto　配信元：https://yomikai.tsunashiman.com/（GitHub の main から GitHub Actions で自動同期）stock/
