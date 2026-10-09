# pusyuu_ips

投稿データの保存と読み出しを中継する、外部と一切通信しない完全ローカル完結型の API です。
[PusyuuIPS](https://github.com/PusyuuWanko/PusyuuIPS) などのメインシステムからの GET / POST を受け、
設定した保存方式で裏側のストレージへ保存・復元します。

保存されたデータ(`storage/`)はこのリポジトリには含まれていません。

## 設定

`index.php` 冒頭の「設定エリア」だけを書き換えます。

| 定数 | 既定値 | 内容 |
| --- | --- | --- |
| `STORAGE_MODE` | `'jsonl'` | 保存方式。`'jsonl'` / `'sqlite'` / `'raw_json'` |
| `STORAGE_DIR` | `__DIR__ . '/storage'` | 保存先フォルダ |
| `IDS_MAX` | `100` | `ids=` で一度に受け付ける ID の数 |

保存方式を切り替えても、以前のデータは移行されません。
大きな Base64 画像を扱うので、`php.ini` の `memory_limit` / `post_max_size` / `upload_max_filesize` を十分に確保してください。

## 主な機能

- 投稿の追加、スレッドへの返信(返信への返信も同じスレッドに時系列で並びます)
- `page` / `limit` による一覧の取得、`id` / `id_one_post` / `ids` による個別の取得
- 投稿の更新・削除

各ドライバーは投稿のオブジェクトを丸ごと保存するので、項目が増えても API のコードを直す必要はありません。

## ライセンス

[MIT License](LICENSE) © 2026 ISAMI ABE
