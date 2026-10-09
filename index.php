<?php
/*****************************************
  *----------------------------------
  |  ThisScriptVersion: 1.0.0     |
  |  © 2026 By ISAMI ABE          |
  |  LastUpdate: 2026-10-08       |
  |  License: MIT License         |
  |  pusyuu_ips (データ中継API) |
----------------------------------*
******************************************/
/**
 * =====================================================================
 * PusyuuIPS - 完全ローカル完結型 データ中継API
 * 配置場所: /var/page/main/pusyuusystem/apis/pusyuu_ips/index.php
 *
 * メインシステム(/var/www/page/pips/)からの GET / POST を中継し、
 * 設定された保存モードに応じて裏側のストレージへ透過的に保存・復元する。
 * 外部API・外部ネットワークへの通信は一切行わない。
 * =====================================================================
 */

declare(strict_types=1);

// =====================================================================
// ▼▼▼ 設定エリア（ここだけ書き換えれば挙動が変わる） ▼▼▼
// =====================================================================

// 保存モード: 'jsonl' | 'sqlite' | 'raw_json'
// モードを切り替えた際の過去データ移行は行わない（切替後は新規データとして開始）。
const STORAGE_MODE = 'jsonl';

// 各モードの実ファイル配置先（このAPI配下に自己完結させる）
const STORAGE_DIR   = __DIR__ . '/storage';
const JSONL_FILE     = STORAGE_DIR . '/posts.jsonl';
const SQLITE_FILE    = STORAGE_DIR . '/posts.db';
const RAW_JSON_FILE  = STORAGE_DIR . '/data.json';

// GET の ids=… で1回に受け付けるidの数。投稿idは約30文字なので、100件でURLが3KB程度です
// (これより大きくすると、途中のサーバがURLの長さで断り始めます)。
const IDS_MAX = 100;

// 巨大なBase64画像を扱うため、PHP側のメモリ上限に注意（php.ini側で調整すること）
// memory_limit / post_max_size / upload_max_filesize を十分に確保すること

// =====================================================================
// ▲▲▲ 設定エリアここまで ▲▲▲
// =====================================================================

header('Content-Type: application/json; charset=utf-8');

if (!is_dir(STORAGE_DIR)) {
  mkdir(STORAGE_DIR, 0775, true);
}

// ---------------------------------------------------------------------
// ストレージ・ドライバー共通インターフェース
//
// どんなKeyが増減してもAPI側のコードを書き換えずに透過保存・復元できるよう、
// 各ドライバーは「トップレベルitemオブジェクトを丸ごと」読み書きする。
// 個別のKey（text/media等）には一切触れない。
// ---------------------------------------------------------------------
interface StorageDriverInterface {
  /** 保存されている全item（トップレベル投稿）を配列で返す */
  public function getAllItems(): array;

  /** 新しいitem（トップレベル投稿）を1件追加する */
  public function appendItem(array $item): void;

  /**
   * $parentId で指す投稿のスレッドへ、返信を1件追加する。見つからなければfalse。
   *
   * $parentId には「トップレベルitemのid」だけでなく「いずれかのitemのreplies内にある
   * 返信のid」も渡せる。後者の場合、その返信を含む**親itemのreplies配列の末尾**へ追加する
   * （返信への返信も、同じスレッドの続きとして時系列に並ぶ）。探索順はupdateItem()・
   * deleteItem()と同じくトップレベル→各itemのreplies内。
   *
   * 【この仕様を外さないこと】pips側の返信ボタンは、押した投稿のidをそのまま
   * parent_idとして送る。返信に付いている返信ボタンを押せば、当然そこには返信のidが入る。
   * トップレベルidしか受け付けない実装に戻すと、返信への返信だけが404で静かに落ちる
   * （画面上はボタンがあるのに投稿できない、という分かりにくい壊れ方をする）。
   * 実際、2026-06にファイル全体を1つのJSONで扱う方式からこのAPI方式へ移行した際、
   * pips側にあった解決処理(findPostById())が移植されず、この探索もこちらに無かったため、
   * 返信への返信が丸ごと機能しなくなっていた。読み出し側(?id=)は最初から
   * 「返信のidならそのスレッドを返す」を行っているので、書き込み側だけが
   * 取り残されていた形になる。読み書きで解決規則を揃えておくこと。
   */
  public function appendReply(string $parentId, array $reply): bool;

  /**
   * 既存item（トップレベルitem、またはいずれかのitemのreplies内）をid一致で探し、
   * $data に含まれるキーだけを上書き（マージ）する。$data に無い既存キーはそのまま保持する。
   * 管理画面での編集や、閲覧数のように後から追加されるキーの更新など、
   * ドライバー側のスキーマを変えずに任意のキーを追加・変更できるようにするための汎用エントリーポイント。
   * "id" キーが$dataに含まれていても、対象の入れ替えは行わない（idは不変）。
   * 見つからなければfalse。
   */
  public function updateItem(string $id, array $data): bool;

  /**
   * 既存item（トップレベルitem、またはいずれかのitemのreplies内）をid一致で探し、削除する。
   * updateItem()と同じ探索順（トップレベル→各itemのreplies内）。見つからなければfalse。
   */
  public function deleteItem(string $id): bool;

  /**
   * 既存item(トップレベル・replies内のどちらでも可。探索順はupdateItem()と同じ)の
   * 数値のキーへ、$deltas の差分を足す。例: ['views' => 1] / ['bookmarks' => -1]。
   * 無いキーは0から数え、結果が0未満になるときは0で止める。
   * 戻り値は足した後の値(['views' => 12] の形)。対象が見つからなければnull。
   * 足す先が数でない値だったときは InvalidArgumentException(何も書き換えない)。
   *
   * 【読んでから足すまでを、必ず1つのロック(sqliteは1つのトランザクション)の中で行うこと】
   * これが無いと、2人がほぼ同時に足したときに片方の分が消える。以前の閲覧数は、
   * 呼び出し側が「読んだ値+1」を updateItem() で上書きしていたので、この取りこぼしが起きていた。
   * このAPIは中身の意味を知らない立場なので、キーが閲覧数か何かは関知しない。
   */
  public function incrementItem(string $id, array $deltas): ?array;
}

// ---------------------------------------------------------------------
// モード①: jsonl（デフォルト・推奨）
// 1投稿 = 1行のJSON。末尾追記のみで完結するため高速。
// ---------------------------------------------------------------------
class JsonlStorageDriver implements StorageDriverInterface {
  public function __construct(private string $filePath) {
    if (!file_exists($this->filePath)) {
      touch($this->filePath);
    }
  }

  public function getAllItems(): array {
    $items = [];
    $fp = fopen($this->filePath, 'r');

    if ($fp === false) {
      // 開けなかった場合は「まだ1件も無い」と同じ扱いにします。
      // ここで例外を投げると、保存先が一時的に読めないだけで全体が止まります。
      $items = [];
    } else {
      flock($fp, LOCK_SH);
      while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line !== '') {
          $decoded = json_decode($line, true);
          if (is_array($decoded)) {
            $items[] = $decoded;
          }
        }
      }
      flock($fp, LOCK_UN);
      fclose($fp);
    }

    return $items;
  }

  /**
   * $offset バイト目から、予算いっぱいまで読んで返します。
   * 戻り値は ['items' => 配列, 'next_offset' => 続きの位置 or null]。
   *
   * 【なぜバイト位置で区切るか】JSONLは1行1件なので、途中から読み始められます。
   * 「何件目から」で区切ると、そこへ辿り着くまでに毎回先頭から読み直すことになり、
   * 分割した回数だけ全件読みが走って、かえって重くなります(以前この作りで、
   * 投稿数が増えるほど二乗で遅くなっていました)。バイト位置なら fseek 一発で
   * 続きから読めるので、分割しても総コストは1回ぶんのままです。
   *
   * $budgetBytes は「今回の呼び出しで読み込んでよい生バイト数」の目安です。
   * 超えた時点でその行までを返し、続きの位置を next_offset で知らせます。
   */
  public function readChunk(int $offset, int $budgetBytes): array {
    $items = [];
    $nextOffset = null;
    $fp = fopen($this->filePath, 'r');

    if ($fp !== false) {
      flock($fp, LOCK_SH);

      // 指定の位置へ飛べたときだけ読みます。飛べなかったのを無視して先頭から読むと、
      // 呼び出し側は「続き」のつもりで同じ範囲をもう一度受け取ります(重複の原因)。
      // next_offset を null のままにしておけば、呼び出し側はそこで walk を打ち切ります。
      if ($offset > 0 && fseek($fp, $offset) !== 0) {
        $items = [];
        $nextOffset = null;
      } else {
        $consumed = 0;
        while (($line = fgets($fp)) !== false) {
          $consumed += strlen($line);
          $trimmed = trim($line);
          if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
              $items[] = $decoded;
            }
          }
          // 予算を使い切ったら、次の位置を知らせてここで止めます。
          // 判定を読み込みの「後」に置いているのは、1行も返さずに位置だけ進むと
          // 呼び出し側が無限に往復してしまうためです。
          if ($consumed >= $budgetBytes) {
            $here = ftell($fp);
            if ($here !== false && !feof($fp)) {
              $nextOffset = $here;
            }
            break;
          }
        }
      }

      flock($fp, LOCK_UN);
      fclose($fp);
    }

    return ['items' => $items, 'next_offset' => $nextOffset];
  }

  // -------------------------------------------------------------------
  // 索引(1件を探す・一覧の1ページを、全件を解かずに済ませるための物)
  //
  // 【なぜ要るか】投稿は画像を base64 で抱えていて、ファイルは数十MBあります(2026-10-08 時点で 48MB)。
  // 以前は id での検索も一覧の1ページも getAllItems() で全件を解いていたので、1回の要求で
  // 約0.65秒・約140MB を使っていました。pips は1回の表示で2回ほど呼ぶので、表示が重なると
  // サーバ全体を食いつぶしました(2026-10-08)。
  // 索引には、行ごとに「ファイル内の位置・長さ・id・返信のid・並べ替えに使う時刻」だけを持ちます。
  // 読むときは索引を引き、要る行だけを fseek して解きます。
  //
  // 【索引は、データのファイルが変わるたびに作り直します】書き込む処理は、書いた後に dropIndex() を
  // 呼んで索引を消します。消し忘れても、索引に控えた「ファイルの大きさ・更新時刻・iノード」が
  // 今のファイルと違えば作り直します(二重の守り)。**位置がずれた索引で読むと、別の投稿や壊れた行を
  // 返します。**書き込む処理を足すときは、必ず dropIndex() も足してください。
  // 【並びは getAllItems() + usort と同じ】行は元のファイルの順に並べ、時刻で並べ替えます(PHP 8 の
  // 並べ替えは安定なので、同じ時刻の投稿は元の順のまま)。表示順は以前と変わりません。
  // -------------------------------------------------------------------

  private function indexPath(): string {
    return $this->filePath . '.index.json';
  }

  /** 索引を消します。データのファイルを書き換えた後に、必ず呼ぶこと(上の説明)。 */
  public function dropIndex(): void {
    @unlink($this->indexPath());
  }

  /** 開いているデータのファイルの「版」。大きさ・更新時刻・iノードのどれかが違えば、索引は使えません。 */
  private static function stampOf($fp): string {
    $st = fstat($fp);
    return is_array($st) ? ($st['size'] . ':' . $st['mtime'] . ':' . $st['ino']) : '';
  }

  /**
   * データのファイルを共有の鍵で開き、索引と一緒に $reader($fp, $rows) へ渡します。戻り値は $reader の戻り値。
   * 鍵を握っている間は書き込みが入らないので、索引の位置と中身が食い違いません。
   * 索引が無い・古いときは、ここで作り直します(作り直しは同時に1本だけ。ほかは出来上がるのを待って使います)。
   */
  private function withIndex(callable $reader) {
    $fp = fopen($this->filePath, 'r');
    $result = null;

    if ($fp === false) {
      throw new RuntimeException('jsonlファイルを開けませんでした');
    }
    flock($fp, LOCK_SH);

    try {
      $stamp = self::stampOf($fp);
      $rows = $this->loadIndex($stamp);

      if ($rows === null) {
        // 作り直すのは1本だけにします。鍵を取れたら、待っている間にほかが作っていないか見直してから作ります。
        $lock = fopen($this->indexPath() . '.lock', 'c');
        if ($lock !== false) {
          flock($lock, LOCK_EX);
        }
        $rows = $this->loadIndex($stamp) ?? $this->buildIndex($fp, $stamp);
        if ($lock !== false) {
          flock($lock, LOCK_UN);
          fclose($lock);
        }
      }

      $result = $reader($fp, $rows);
    } finally {
      flock($fp, LOCK_UN);
      fclose($fp);
    }

    return $result;
  }

  /** 保存してある索引が今の版の物なら、その行の一覧。無い・壊れている・版が違うなら null。 */
  private function loadIndex(string $stamp): ?array {
    $raw = is_file($this->indexPath()) ? @file_get_contents($this->indexPath()) : false;
    $decoded = ($raw === false) ? null : json_decode($raw, true);
    $usable = is_array($decoded) && ($decoded['stamp'] ?? '') === $stamp && $stamp !== '' && is_array($decoded['rows'] ?? null);

    return $usable ? $decoded['rows'] : null;
  }

  /** 全行を1回だけ読んで索引を作り、保存します(データのファイルは呼び出し側が共有の鍵で開いています)。 */
  private function buildIndex($fp, string $stamp): array {
    $rows = [];
    rewind($fp);
    $offset = 0;

    while (($line = fgets($fp)) !== false) {
      $length = strlen($line);
      $decoded = (trim($line) === '') ? null : json_decode(trim($line), true);

      // getAllItems() と同じく、解けない行は飛ばします(索引にも載せません)。
      if (is_array($decoded)) {
        $replyIds = [];
        foreach ($decoded['replies'] ?? [] as $reply) {
          $replyIds[] = is_array($reply) ? (string)($reply['id'] ?? '') : '';
        }
        $rows[] = [
          'o' => $offset,
          'n' => $length,
          'id' => (string)($decoded['id'] ?? ''),
          'r' => $replyIds,
          't' => parseDatetimeForSort((string)($decoded['datetime'] ?? '')),
        ];
      }
      $offset += $length;
    }

    $json = json_encode(['stamp' => $stamp, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $this->indexPath() . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $this->indexPath())) {
      // 保存できなくても、今回の読み出しには作った索引をそのまま使えます(次も作り直しになるだけ)。
      @unlink($tmp);
      error_log('[pusyuu_ips] 索引を保存できませんでした: ' . $this->indexPath());
    }

    return $rows;
  }

  /** 索引の1行が指す投稿を、データのファイルから読んで解きます。読めなければ null。 */
  private static function readRow($fp, array $row): ?array {
    $raw = (fseek($fp, (int)$row['o']) === 0) ? fread($fp, max(1, (int)$row['n'])) : false;
    $decoded = ($raw === false) ? null : json_decode(trim($raw), true);

    return is_array($decoded) ? $decoded : null;
  }

  /**
   * id で探します(以前の GET ?id= / ?id_one_post= と同じ結果)。見つからなければ null。
   * $wholeThread が true なら、返信の id に当たったときもその親(スレッド全体)を返します(?id=)。
   * false なら、返信の id に当たったときはその返信だけを返します(?id_one_post=)。
   * 探す順はファイルの順で、各行は「親の id → その返信の id」の順に見ます(以前のループと同じ)。
   */
  public function findById(string $id, bool $wholeThread): ?array {
    return $this->withIndex(static function ($fp, array $rows) use ($id, $wholeThread): ?array {
      $found = null;

      foreach ($rows as $row) {
        $topHit = ($row['id'] === $id);
        $replyPos = $topHit ? false : array_search($id, $row['r'], true);

        if ($topHit || $replyPos !== false) {
          $item = self::readRow($fp, $row);
          if ($item === null) {
            $found = null;
          } else if ($topHit || $wholeThread) {
            $found = $item;
          } else {
            // 返信そのもの。索引と同じ位置の返信を、念のため id で確かめてから返します。
            $reply = $item['replies'][$replyPos] ?? null;
            $found = (is_array($reply) && (string)($reply['id'] ?? '') === $id) ? $reply : null;
          }
          break;
        }
      }

      return $found;
    });
  }

  /**
   * 決まった何件かを、id → 投稿(親でも返信でも、当たった本人)の対応で返します(以前の GET ?ids= と同じ)。
   * 同じ id が複数あれば、ファイルの後ろの方が勝ちます(以前の $byId への代入の順と同じ)。
   */
  public function findByIds(array $ids): array {
    $wanted = array_fill_keys($ids, true);

    return $this->withIndex(static function ($fp, array $rows) use ($wanted): array {
      $byId = [];

      foreach ($rows as $row) {
        $touches = isset($wanted[$row['id']]);
        foreach ($row['r'] as $replyId) {
          $touches = $touches || isset($wanted[$replyId]);
        }
        $item = $touches ? self::readRow($fp, $row) : null;

        if ($item !== null) {
          $byId[(string)($item['id'] ?? '')] = $item;
          foreach ($item['replies'] ?? [] as $reply) {
            $byId[(string)($reply['id'] ?? '')] = $reply;
          }
        }
      }

      return $byId;
    });
  }

  /** 新しい順の一覧の1ページと、全体の件数を返します(以前の GET ?page=&limit= と同じ並び)。 */
  public function newestPage(int $page, int $limit): array {
    return $this->withIndex(static function ($fp, array $rows) use ($page, $limit): array {
      usort($rows, static fn($a, $b) => $b['t'] - $a['t']);
      $items = [];
      foreach (array_slice($rows, ($page - 1) * $limit, $limit) as $row) {
        $item = self::readRow($fp, $row);
        if ($item !== null) {
          $items[] = $item;
        }
      }

      return ['items' => $items, 'total' => count($rows)];
    });
  }

  public function appendItem(array $item): void {
    $fp = fopen($this->filePath, 'a');
    if ($fp === false) {
      throw new RuntimeException('jsonlファイルを開けませんでした');
    }
    flock($fp, LOCK_EX);
    fwrite($fp, json_encode($item, JSON_UNESCAPED_UNICODE) . "\n");
    $this->dropIndex();
    flock($fp, LOCK_UN);
    fclose($fp);
  }

  public function appendReply(string $parentId, array $reply): bool {
    // 追記専用のjsonlではin-place更新ができないため、
    // 該当行を探して書き換えるときだけ全体を読み直して再構築する。
    $fp = fopen($this->filePath, 'c+');
    if ($fp === false) {
      throw new RuntimeException('jsonlファイルを開けませんでした');
    }
    flock($fp, LOCK_EX);

    // 【1回の走査で済ませる理由】updateItem()は「まずトップレベルを探し、無ければ
    // もう一度repliesを探す」という二段構えだが、jsonlでそれをやると数十MBの
    // ファイルを2回読むことになる。ここでは1回読む間に「トップレベルが一致した行」と
    // 「repliesの中が一致した行」の両方を控えておき、走査後にトップレベルを優先して
    // 選ぶ。結果の優先順位はupdateItem()と同じで、読む回数だけが1回で済む。
    $lines = [];
    $topLevelIndex = null;
    $insideReplyIndex = null;
    $index = 0;
    while (($line = fgets($fp)) !== false) {
      $trimmed = trim($line);
      if ($trimmed === '') {
        continue;
      }
      $decoded = json_decode($trimmed, true);
      if (is_array($decoded)) {
        if ($topLevelIndex === null && (string)($decoded['id'] ?? '') === $parentId) {
          $topLevelIndex = $index;
        } elseif ($insideReplyIndex === null && isset($decoded['replies']) && is_array($decoded['replies'])) {
          foreach ($decoded['replies'] as $existingReply) {
            if (is_array($existingReply) && (string)($existingReply['id'] ?? '') === $parentId) {
              $insideReplyIndex = $index;
              break;
            }
          }
        }
      }
      // 行は読んだ文字列のまま保持し、書き換える1行だけを後でデコードし直す。
      // 目的はメモリではなく（実測では文字列51.3MB / 連想配列43.1MBとほぼ同じ。
      // 中身の大半がbase64の長い文字列なので、デコードしても膨らまない）、
      // **触っていない行を1バイトも変えないこと**。全行をjson_encode()で書き直すと、
      // 何かの理由でデコードできない行があった場合に "null" で上書きしてしまい、
      // その投稿が丸ごと消える。追記のたびに全投稿を書き直す処理なので、
      // ここでの取りこぼしは被害が大きい。
      $lines[] = $trimmed;
      $index++;
    }

    // トップレベル優先。見つからなければ、その返信を含む親の行へ追記する。
    $targetIndex = $topLevelIndex !== null ? $topLevelIndex : $insideReplyIndex;
    $found = $targetIndex !== null;

    if ($found) {
      $decoded = json_decode($lines[$targetIndex], true);
      if (!isset($decoded['replies']) || !is_array($decoded['replies'])) {
        $decoded['replies'] = [];
      }
      $decoded['replies'][] = $reply;
      $lines[$targetIndex] = json_encode($decoded, JSON_UNESCAPED_UNICODE);

      ftruncate($fp, 0);
      rewind($fp);
      foreach ($lines as $l) {
        fwrite($fp, $l . "\n");
      }
      $this->dropIndex();
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $found;
  }

  public function updateItem(string $id, array $data): bool {
    unset($data['id']);

    $fp = fopen($this->filePath, 'c+');
    if ($fp === false) {
      throw new RuntimeException('jsonlファイルを開けませんでした');
    }
    flock($fp, LOCK_EX);

    $lines = [];
    $found = false;
    while (($line = fgets($fp)) !== false) {
      $trimmed = trim($line);
      if ($trimmed === '') {
        continue;
      }
      $decoded = json_decode($trimmed, true);
      if (is_array($decoded) && !$found) {
        if ((string)($decoded['id'] ?? '') === $id) {
          $decoded = array_merge($decoded, $data);
          $decoded['id'] = $id;
          $found = true;
        } elseif (isset($decoded['replies']) && is_array($decoded['replies'])) {
          foreach ($decoded['replies'] as &$reply) {
            if (is_array($reply) && (string)($reply['id'] ?? '') === $id) {
              $reply = array_merge($reply, $data);
              $reply['id'] = $id;
              $found = true;
              break;
            }
          }
          unset($reply);
        }
      }
      $lines[] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    if ($found) {
      ftruncate($fp, 0);
      rewind($fp);
      foreach ($lines as $l) {
        fwrite($fp, $l . "\n");
      }
      $this->dropIndex();
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $found;
  }

  public function deleteItem(string $id): bool {
    $fp = fopen($this->filePath, 'c+');
    if ($fp === false) {
      throw new RuntimeException('jsonlファイルを開けませんでした');
    }
    flock($fp, LOCK_EX);

    $lines = [];
    $found = false;
    while (($line = fgets($fp)) !== false) {
      $trimmed = trim($line);
      if ($trimmed === '') {
        continue;
      }
      $decoded = json_decode($trimmed, true);
      if (is_array($decoded) && !$found) {
        if ((string)($decoded['id'] ?? '') === $id) {
          // トップレベルitemごと除外する（この行は書き戻さない）
          $found = true;
          continue;
        }
        if (isset($decoded['replies']) && is_array($decoded['replies'])) {
          $before = count($decoded['replies']);
          $decoded['replies'] = array_values(array_filter(
            $decoded['replies'],
            fn ($reply) => !(is_array($reply) && (string)($reply['id'] ?? '') === $id)
          ));
          if (count($decoded['replies']) !== $before) {
            $found = true;
          }
        }
      }
      $lines[] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    }

    if ($found) {
      ftruncate($fp, 0);
      rewind($fp);
      foreach ($lines as $l) {
        fwrite($fp, $l . "\n");
      }
      $this->dropIndex();
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $found;
  }

  public function incrementItem(string $id, array $deltas): ?array {
    $fp = fopen($this->filePath, 'c+');
    if ($fp === false) {
      throw new RuntimeException('jsonlファイルを開けませんでした');
    }
    flock($fp, LOCK_EX);

    // 後始末(ロック解除とclose)を必ず通すため、例外は finally の後で投げ直します。
    // 数でない値に当たったときの例外がここを素通りすると、ロックを握ったまま帰ります。
    $values = null;
    try {
      // appendReply() と同じく、行は読んだ文字列のまま持ち、書き換える1行だけをデコードし直す
      // (触っていない行を1バイトも変えないため。理由は appendReply() のコメント参照)。
      // 対象を見つけるまでは各行をデコードして探し、見つけた後はデコードしない。
      $lines = [];
      $targetIndex = null;
      $index = 0;
      while (($line = fgets($fp)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '') {
          continue;
        }
        if ($targetIndex === null) {
          $decoded = json_decode($trimmed, true);
          if (is_array($decoded) && itemContainsId($decoded, $id)) {
            $targetIndex = $index;
          }
        }
        $lines[] = $trimmed;
        $index++;
      }

      if ($targetIndex !== null) {
        $decoded = json_decode($lines[$targetIndex], true);
        [$decoded, $values] = applyIncrementsInItem($decoded, $id, $deltas);
        $lines[$targetIndex] = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        ftruncate($fp, 0);
        rewind($fp);
        foreach ($lines as $l) {
          fwrite($fp, $l . "\n");
        }
        $this->dropIndex();
      }
    } finally {
      flock($fp, LOCK_UN);
      fclose($fp);
    }

    return $values;
  }
}

// ---------------------------------------------------------------------
// モード②: sqlite（単一ファイル型DB）
// テーブルは id(TEXT) / data(TEXT=JSON文字列丸ごと) の2カラムのみ。
// ---------------------------------------------------------------------
class SqliteStorageDriver implements StorageDriverInterface {
  private PDO $pdo;

  public function __construct(string $filePath) {
    // 【ドライバの有無を先に確かめること(CODING_STYLE 12-4)】素のPHPの PDO にはドライバが1つも無く、
    // このモードは使えません。止める理由をはっきり残すため、ここで投げます(下のメイン処理が受けて止めます)。
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
      throw new RuntimeException('保存モード sqlite に要る PDO のドライバ(pdo_sqlite)がありません。入れるか、STORAGE_MODE を jsonl にしてください。');
    }
    $this->pdo = new PDO('sqlite:' . $filePath);
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->pdo->exec('PRAGMA busy_timeout = 5000');
    $this->pdo->exec(
      'CREATE TABLE IF NOT EXISTS posts (
                id TEXT PRIMARY KEY,
                data TEXT NOT NULL,
                created_at INTEGER
            )'
    );
  }

  public function getAllItems(): array {
    $stmt = $this->pdo->query('SELECT data FROM posts ORDER BY created_at ASC, id ASC');
    $items = [];
    foreach ($stmt as $row) {
      $decoded = json_decode($row['data'], true);
      if (is_array($decoded)) {
        $items[] = $decoded;
      }
    }
    return $items;
  }

  public function appendItem(array $item): void {
    $id = (string)($item['id'] ?? '');
    $stmt = $this->pdo->prepare(
      'INSERT INTO posts (id, data, created_at) VALUES (:id, :data, :created_at)'
    );
    $stmt->execute([
      ':id' => $id,
      ':data' => json_encode($item, JSON_UNESCAPED_UNICODE),
      ':created_at' => time(),
    ]);
  }

  public function appendReply(string $parentId, array $reply): bool {
    $result = false;

    // まずトップレベルitemとして直接一致するか試します（idが主キーなので高速）
    $select = $this->pdo->prepare('SELECT data FROM posts WHERE id = :id');
    $select->execute([':id' => $parentId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    $topLevel = ($row === false) ? null : json_decode($row['data'], true);

    if (is_array($topLevel)) {
      if (!isset($topLevel['replies']) || !is_array($topLevel['replies'])) {
        $topLevel['replies'] = [];
      }
      $topLevel['replies'][] = $reply;

      $update = $this->pdo->prepare('UPDATE posts SET data = :data WHERE id = :id');
      $update->execute([
        ':data' => json_encode($topLevel, JSON_UNESCAPED_UNICODE),
        ':id' => $parentId,
      ]);
      $result = true;
    } else if ($row !== false) {
      // 行はあるのに中身が壊れている場合。idは主キーなので同じidが他に無く、
      // replies側を探し直しても見つかりません。ここで失敗にします。
      $result = false;
    } else {
      // トップレベルに無ければ、各itemのreplies配列の中を探します（返信への返信）。
      // 見つかった場合、追加先はその返信ではなく、その返信を含む親itemのreplies末尾です。
      $all = $this->pdo->query('SELECT id, data FROM posts');
      foreach ($all as $parentRow) {
        $decoded = json_decode($parentRow['data'], true);
        if (is_array($decoded) && isset($decoded['replies']) && is_array($decoded['replies'])) {
          $found = false;
          foreach ($decoded['replies'] as $existingReply) {
            if (is_array($existingReply) && (string)($existingReply['id'] ?? '') === $parentId) {
              $found = true;
              break;
            }
          }

          if ($found) {
            $decoded['replies'][] = $reply;
            $update = $this->pdo->prepare('UPDATE posts SET data = :data WHERE id = :id');
            $update->execute([
              ':data' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
              ':id' => $parentRow['id'],
            ]);
            $result = true;
            break;
          }
        }
      }
    }

    return $result;
  }

  public function updateItem(string $id, array $data): bool {
    unset($data['id']);
    $result = false;

    // まずトップレベルitemとして直接一致するか試します（idが主キーなので高速）
    $select = $this->pdo->prepare('SELECT data FROM posts WHERE id = :id');
    $select->execute([':id' => $id]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    $topLevel = ($row === false) ? null : json_decode($row['data'], true);

    if (is_array($topLevel)) {
      $topLevel = array_merge($topLevel, $data);
      $topLevel['id'] = $id;

      $update = $this->pdo->prepare('UPDATE posts SET data = :data WHERE id = :id');
      $update->execute([
        ':data' => json_encode($topLevel, JSON_UNESCAPED_UNICODE),
        ':id' => $id,
      ]);
      $result = true;
    } else if ($row !== false) {
      // 行はあるのに中身が壊れている場合。idは主キーなので同じidが他に無く、
      // replies側を探し直しても見つかりません。ここで失敗にします。
      $result = false;
    } else {
      // トップレベルに無ければ、各itemのreplies配列の中を探します
      $all = $this->pdo->query('SELECT id, data FROM posts');
      foreach ($all as $parentRow) {
        $decoded = json_decode($parentRow['data'], true);
        if (is_array($decoded) && isset($decoded['replies']) && is_array($decoded['replies'])) {
          $found = false;
          foreach ($decoded['replies'] as &$reply) {
            if (is_array($reply) && (string)($reply['id'] ?? '') === $id) {
              $reply = array_merge($reply, $data);
              $reply['id'] = $id;
              $found = true;
              break;
            }
          }
          unset($reply);

          if ($found) {
            $update = $this->pdo->prepare('UPDATE posts SET data = :data WHERE id = :id');
            $update->execute([
              ':data' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
              ':id' => $parentRow['id'],
            ]);
            $result = true;
            break;
          }
        }
      }
    }

    return $result;
  }

  public function deleteItem(string $id): bool {
    $result = false;

    $delete = $this->pdo->prepare('DELETE FROM posts WHERE id = :id');
    $delete->execute([':id' => $id]);

    if ($delete->rowCount() > 0) {
      $result = true;
    } else {
      // トップレベルに無ければ、各itemのreplies配列の中を探して削除します
      $all = $this->pdo->query('SELECT id, data FROM posts');
      foreach ($all as $parentRow) {
        $decoded = json_decode($parentRow['data'], true);
        if (is_array($decoded) && isset($decoded['replies']) && is_array($decoded['replies'])) {
          $before = count($decoded['replies']);
          $decoded['replies'] = array_values(array_filter(
            $decoded['replies'],
            fn ($reply) => !(is_array($reply) && (string)($reply['id'] ?? '') === $id)
          ));

          if (count($decoded['replies']) !== $before) {
            $update = $this->pdo->prepare('UPDATE posts SET data = :data WHERE id = :id');
            $update->execute([
              ':data' => json_encode($decoded, JSON_UNESCAPED_UNICODE),
              ':id' => $parentRow['id'],
            ]);
            $result = true;
            break;
          }
        }
      }
    }

    return $result;
  }

  public function incrementItem(string $id, array $deltas): ?array {
    $values = null;

    // 【BEGIN IMMEDIATE にすること】既定の BEGIN(DEFERRED)は、読んだ時点ではまだ書き込みの
    // 鍵を取らないので、2つの要求が同じ値を読んでから順に書く、という取りこぼしが起きます。
    // IMMEDIATE は始めた時点で書き込みの鍵を取るので、読んでから書くまでを1人ずつにできます。
    $this->pdo->exec('BEGIN IMMEDIATE');
    try {
      // まずトップレベルitemとして直接一致するか試します（idが主キーなので高速）
      $select = $this->pdo->prepare('SELECT data FROM posts WHERE id = :id');
      $select->execute([':id' => $id]);
      $row = $select->fetch(PDO::FETCH_ASSOC);
      $topLevel = ($row === false) ? null : json_decode($row['data'], true);
      $parentId = null;
      $parent = null;

      if (is_array($topLevel)) {
        $parentId = $id;
        $parent = $topLevel;
      } else if ($row !== false) {
        // 行はあるのに中身が壊れている場合。updateItem() と同じく失敗にします。
        $parentId = null;
      } else {
        // トップレベルに無ければ、各itemのreplies配列の中を探します
        $all = $this->pdo->query('SELECT id, data FROM posts');
        foreach ($all as $parentRow) {
          $decoded = json_decode($parentRow['data'], true);
          if (is_array($decoded) && itemContainsId($decoded, $id)) {
            $parentId = (string)$parentRow['id'];
            $parent = $decoded;
            break;
          }
        }
      }

      if ($parentId !== null) {
        [$parent, $values] = applyIncrementsInItem($parent, $id, $deltas);
        $update = $this->pdo->prepare('UPDATE posts SET data = :data WHERE id = :id');
        $update->execute([
          ':data' => json_encode($parent, JSON_UNESCAPED_UNICODE),
          ':id' => $parentId,
        ]);
      }

      $this->pdo->exec('COMMIT');
    } catch (Throwable $e) {
      $this->pdo->exec('ROLLBACK');
      throw $e;
    }

    return $values;
  }
}

// ---------------------------------------------------------------------
// モード③: raw_json（現状維持・保険モード）
// 全体を1つのdata.jsonとして塊で読み書きする。
// ---------------------------------------------------------------------
class RawJsonStorageDriver implements StorageDriverInterface {
  public function __construct(private string $filePath) {
    if (!file_exists($this->filePath)) {
      file_put_contents($this->filePath, json_encode(['item' => []], JSON_UNESCAPED_UNICODE));
    }
  }

  public function getAllItems(): array {
    $raw = $this->readWithLock();
    return is_array($raw['item'] ?? null) ? $raw['item'] : null;//[];
  }

  public function appendItem(array $item): void {
    $this->writeWithLock(function (array $raw) use ($item) {
      $raw['item'][] = $item;
      return $raw;
    });
  }

  public function appendReply(string $parentId, array $reply): bool {
    $found = false;
    $this->writeWithLock(function (array $raw) use ($parentId, $reply, &$found) {
      // まずトップレベルitemとして直接一致するか試します
      foreach ($raw['item'] as &$existing) {
        if ((string)($existing['id'] ?? '') === $parentId) {
          if (!isset($existing['replies']) || !is_array($existing['replies'])) {
            $existing['replies'] = [];
          }
          $existing['replies'][] = $reply;
          $found = true;
          break;
        }
      }
      unset($existing);
      if ($found) {
        return $raw;
      }

      // トップレベルに無ければ、各itemのreplies配列の中を探します（返信への返信）。
      // 見つかった場合、追加先はその返信ではなく、その返信を含む親itemのreplies末尾です。
      foreach ($raw['item'] as &$existing) {
        if (!isset($existing['replies']) || !is_array($existing['replies'])) {
          continue;
        }
        foreach ($existing['replies'] as $existingReply) {
          if (is_array($existingReply) && (string)($existingReply['id'] ?? '') === $parentId) {
            $found = true;
            break;
          }
        }
        if ($found) {
          $existing['replies'][] = $reply;
          break;
        }
      }
      unset($existing);
      return $raw;
    });
    return $found;
  }

  public function updateItem(string $id, array $data): bool {
    unset($data['id']);

    $found = false;
    $this->writeWithLock(function (array $raw) use ($id, $data, &$found) {
      foreach ($raw['item'] as &$existing) {
        if ((string)($existing['id'] ?? '') === $id) {
          $existing = array_merge($existing, $data);
          $existing['id'] = $id;
          $found = true;
          break;
        }
        if (isset($existing['replies']) && is_array($existing['replies'])) {
          foreach ($existing['replies'] as &$reply) {
            if (is_array($reply) && (string)($reply['id'] ?? '') === $id) {
              $reply = array_merge($reply, $data);
              $reply['id'] = $id;
              $found = true;
              break;
            }
          }
          unset($reply);
          if ($found) {
            break;
          }
        }
      }
      unset($existing);
      return $raw;
    });
    return $found;
  }

  public function deleteItem(string $id): bool {
    $found = false;
    $this->writeWithLock(function (array $raw) use ($id, &$found) {
      $before = count($raw['item']);
      $raw['item'] = array_values(array_filter(
        $raw['item'],
        fn ($existing) => (string)($existing['id'] ?? '') !== $id
      ));
      if (count($raw['item']) !== $before) {
        $found = true;
        return $raw;
      }

      foreach ($raw['item'] as &$existing) {
        if (isset($existing['replies']) && is_array($existing['replies'])) {
          $repliesBefore = count($existing['replies']);
          $existing['replies'] = array_values(array_filter(
            $existing['replies'],
            fn ($reply) => !(is_array($reply) && (string)($reply['id'] ?? '') === $id)
          ));
          if (count($existing['replies']) !== $repliesBefore) {
            $found = true;
            break;
          }
        }
      }
      unset($existing);
      return $raw;
    });
    return $found;
  }

  public function incrementItem(string $id, array $deltas): ?array {
    $values = null;
    // writeWithLock() は読んでから書くまでを1つのロックの中で行うので、取りこぼしは起きません。
    $this->writeWithLock(function (array $raw) use ($id, $deltas, &$values) {
      foreach ($raw['item'] as &$existing) {
        if ($values === null && is_array($existing) && itemContainsId($existing, $id)) {
          [$existing, $values] = applyIncrementsInItem($existing, $id, $deltas);
        }
      }
      unset($existing);
      return $raw;
    });
    return $values;
  }

  private function readWithLock(): array {
    $fp = fopen($this->filePath, 'r');
    $decoded = null;

    if ($fp !== false) {
      flock($fp, LOCK_SH);
      $contents = stream_get_contents($fp);
      flock($fp, LOCK_UN);
      fclose($fp);

      $decoded = json_decode((string)$contents, true);
    }

    if (!is_array($decoded) || !isset($decoded['item']) || !is_array($decoded['item'])) {
      // 【触る前に読むこと】ここは null を返しますが、この関数の戻り値の型は array です。
      // このファイルは strict_types が有効なので、この行を通ると TypeError で落ちます。
      // 以前は ['item' => []] を返していました(行末のコメントがその名残)。
      // 直すなら「戻り値の型を ?array にして呼び出し側で null を受ける」か
      // 「元の空配列へ戻す」かのどちらかです。**片方だけ直さないでください** —
      // getAllItems() も同じ形(null を返すのに : array)なので、両方を揃える必要があります。
      // 現状 STORAGE_MODE は 'jsonl' なので、このクラス自体が動いていません。
      $result = null;//['item' => []];
    } else {
      $result = $decoded;
    }

    return $result;
  }

  private function writeWithLock(callable $mutator): void {
    $fp = fopen($this->filePath, 'c+');
    if ($fp === false) {
      throw new RuntimeException('data.jsonを開けませんでした');
    }
    flock($fp, LOCK_EX);

    $contents = stream_get_contents($fp);
    $decoded = json_decode((string)$contents, true);
    if (!is_array($decoded) || !isset($decoded['item']) || !is_array($decoded['item'])) {
      $decoded = null;//['item' => []];
    }

    $decoded = $mutator($decoded);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($decoded, JSON_UNESCAPED_UNICODE));

    flock($fp, LOCK_UN);
    fclose($fp);
  }
}

// ---------------------------------------------------------------------
// ドライバー・ファクトリー
// ---------------------------------------------------------------------
function createStorageDriver(string $mode): StorageDriverInterface {
  $driver = match ($mode) {
    'jsonl' => new JsonlStorageDriver(JSONL_FILE),
    'sqlite' => new SqliteStorageDriver(SQLITE_FILE),
    'raw_json' => new RawJsonStorageDriver(RAW_JSON_FILE),
    default => throw new RuntimeException("未知の保存モードです: {$mode}"),
  };

  return $driver;
}

// ---------------------------------------------------------------------
// ユーティリティ
// ---------------------------------------------------------------------

// "datetime"フィールドは過去の経緯でフォーマットが統一されていません。
// 保存されている投稿の多くは「2025年11月18日 21:01:41」のような全角の年月日区切り形式（JS側で
// 組み立てていた頃の名残）ですが、PHPのstrtotime()はこの区切り文字を解釈できず常にfalseを返し、
// 日時での比較・ソートが実質的に効かなくなります。この関数はその形式（と、strtotime()がそのまま
// 解釈できるその他の形式）に対応し、どうしても解釈できない場合は0（比較上は最も古い扱い）を返します。
function parseDatetimeForSort(string $str): int {
  $result = 0;

  if ($str === '') {
    $result = 0;
  } else {
    // 全角の「年」「月」「日」区切りを、strtotime()が解釈できるハイフン区切りに変換します
    // 例: "2025年11月18日 21:01:41" -> "2025-11-18 21:01:41"
    $normalized = preg_replace('/^(\d{1,4})年(\d{1,2})月(\d{1,2})日\s*/u', '$1-$2-$3 ', trim($str));
    $timestamp = strtotime($normalized);

    if ($timestamp !== false) {
      $result = $timestamp;
    } else {
      // 変換後でも読めなければ、元の文字列でもう一度だけ試します。
      // 全角区切りではない形式で保存された投稿が混ざっているためです。
      $retry = strtotime($str);
      $result = ($retry !== false) ? $retry : 0;
    }
  }

  return $result;
}

/**
 * $item(トップレベルitem)自身、またはその replies のどれかの id が $id か。
 * 探索順は updateItem() と同じ「そのitem自身 → replies内」です。
 */
function itemContainsId(array $item, string $id): bool {
  $result = false;

  if ((string)($item['id'] ?? '') === $id) {
    $result = true;
  } else if (isset($item['replies']) && is_array($item['replies'])) {
    foreach ($item['replies'] as $reply) {
      if (is_array($reply) && (string)($reply['id'] ?? '') === $id) {
        $result = true;
      }
    }
  } else {
    $result = false;
  }

  return $result;
}

/**
 * itemContainsId() が真になった $item(トップレベルitem)の中で、id が $id のもの
 * (自身か、replies内の1件)に差分を足します。戻り値は [足した後の $item, 足した後の値]。
 */
function applyIncrementsInItem(array $item, string $id, array $deltas): array {
  $values = [];

  if ((string)($item['id'] ?? '') === $id) {
    [$item, $values] = applyIncrements($item, $deltas);
  } else {
    foreach ($item['replies'] as &$reply) {
      if (is_array($reply) && (string)($reply['id'] ?? '') === $id) {
        [$reply, $values] = applyIncrements($reply, $deltas);
        break;
      }
    }
    unset($reply);
  }

  return [$item, $values];
}

/**
 * $target の各キーへ $deltas の差分を足します。戻り値は [足した後の $target, 足した後の値]。
 *
 * - 無いキー(null)は0から数えます(閲覧数のように、後から足されたキーのため)。
 * - 結果が0未満になるときは0で止めます。数を減らす操作(ブックマークを外す等)が、
 *   何かの食い違いで足した回数より多く届いても、マイナスの数を表に出さないためです。
 * - 足す先が数でなければ、何も変えずに InvalidArgumentException を投げます。
 *   【0として上書きしないこと】キー名を取り違えた呼び出しが、本文などの値を黙って
 *   0に潰してしまいます。
 */
function applyIncrements(array $target, array $deltas): array {
  $values = [];

  // 先に全部のキーを確かめてから書きます。途中で投げると、前半のキーだけ足された
  // 中途半端な $target が残るためです(呼び出し側は書き戻さないので実害はありませんが、
  // 「確かめてから変える」の順を崩さないでください)。
  foreach ($deltas as $key => $delta) {
    $current = $target[$key] ?? null;
    if ($current === null) {
      $values[$key] = 0;
    } else if (is_int($current)) {
      $values[$key] = $current;
    } else if (is_string($current) && preg_match('/^\d+$/', $current)) {
      // 古い書き込みで数が文字列として入っていることがあるため、数字だけの文字列は数として読みます。
      $values[$key] = (int)$current;
    } else {
      throw new InvalidArgumentException('"' . $key . '" は数ではないため、足せません');
    }
  }

  foreach ($deltas as $key => $delta) {
    $values[$key] = max(0, $values[$key] + $delta);
    $target[$key] = $values[$key];
  }

  return [$target, $values];
}

function generateId(): string {
  return bin2hex(pack('N', time())) . bin2hex(random_bytes(10));
}

// ---------------------------------------------------------------------
// メモリ予算
//
// このAPIは投稿本文にBase64の画像を抱えるため、1件あたりが大きくなります
// (2026-09-06時点の実測で478件 42.7MB、1件平均91KB)。全件をPHP配列へ展開すると
// 36MB、それをjson_encodeすると更に約35MBが必要で、合計のピークは108MBに達します。
// 本番のmemory_limitが128MBなので、少し増えただけで即座に致命的エラーになります。
// 実際 error.log には line 640 (respond()のjson_encode) での枯渇が1853回並んでいました。
//
// そこで「全件が要る」と言われたときも、まず**今の上限を実際に読んで**、その半分を
// 超えそうなら一度に返さず分割して返します。半分にしているのは、応答をjson_encodeする
// ぶんで概ね同量がもう一度要るためです(配列とJSON文字列が同時に載る瞬間がある)。
// ---------------------------------------------------------------------

/** php.iniのmemory_limitを実際に読んでバイト数で返します。無制限(-1)なら null。 */
function memoryLimitBytes(): ?int {
  $raw = trim((string)ini_get('memory_limit'));

  if ($raw === '' || $raw === '-1') {
    // -1 は「無制限」の意味。上限が無いので、バイト数では答えられません。
    $result = null;
  } else {
    $unit = strtolower(substr($raw, -1));
    $value = (int)$raw;
    $result = match ($unit) {
      'g' => $value * 1024 * 1024 * 1024,
      'm' => $value * 1024 * 1024,
      'k' => $value * 1024,
      default => $value,
    };
  }

  return $result;
}

/**
 * 生のJSONLを1バイト読むと、PHPのメモリを実際には何バイト使うか。
 *
 * 【この数字は実測です。当て推量で下げないこと】
 * 2026-09-06に本番データ(42.7MB / 478件)で測ったところ、
 *   PHP配列へ展開した時点 …… 36MB
 *   それをjson_encodeした後 … ピーク108MB
 * となりました。108 / 42.7 ≒ 2.5 です。配列そのものと、応答用のJSON文字列が
 * 同時にメモリへ載る瞬間があるため、生バイト数の倍以上を見ておく必要があります。
 * ここを1.0のまま(=生バイト数だけで判断)にすると、予算内のつもりで上限を突き抜けます。
 * 実際、最初に書いたときそうなっていて、42.7MBを「予算62MBに収まる」と判断した結果
 * ピークが108MBまで伸びました。余裕を持たせて3倍で見積もります。
 */
const READ_EXPANSION_FACTOR = 3;

/**
 * 一度に読み込んでよい「生のJSONLのバイト数」。
 *
 * memory_limit の半分を使ってよい上限とし、そこから今すでに使っている分を引き、
 * さらに上の倍率で割って生バイト数に戻します。半分にしているのは、応答を組み立てる
 * ぶんの余地を残すためです。
 * 無制限の環境では、際限なく積まないための現実的な上限として16MiBを使います。
 */
function readBudgetBytes(): int {
  $limit = memoryLimitBytes();

  if ($limit === null) {
    // 無制限の環境。上限が無くても際限なく積むのは危ないので、現実的な値で頭打ちにします。
    $result = 16 * 1024 * 1024;
  } else {
    $usable = (int)($limit / 2) - memory_get_usage(true);
    $budget = (int)($usable / READ_EXPANSION_FACTOR);
    // どんなに切羽詰まっていても、1回で1件も読めないと先へ進めません。
    // 画像入りの投稿1件が大きい(実測で平均91KB、最大はその数倍)ので、
    // 最低でも1MiBは確保します。
    $result = max(1024 * 1024, $budget);
  }

  return $result;
}

/** $items の各投稿から、トップレベルのキー $omit を外します(GET の omit=… 参照)。 */
function omitKeys(array $items, array $omit): array {
  $result = $items;

  if ($omit !== []) {
    $drop = array_flip($omit);
    $result = array_map(static function ($item) use ($drop) {
      return is_array($item) ? array_diff_key($item, $drop) : $item;
    }, $items);
  }

  return $result;
}

function respond(int $statusCode, array $body): void {
  http_response_code($statusCode);
  echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// =====================================================================
// メイン処理
// =====================================================================
try {
  $driver = createStorageDriver(STORAGE_MODE);
} catch (Throwable $e) {
  // 理由はログにだけ残します。例外の文にはファイルの場所などが入るので、応答には出しません。
  error_log('[pusyuu_ips] ストレージの初期化に失敗しました: ' . $e->getMessage());
  respond(500, ['status' => 'error', 'message' => 'ストレージ初期化に失敗しました。']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// =====================================================================
// 読み出しの本数の上限と、締め切り
//
// 【なぜ要るか】読み出しは、索引を作り直すときや全件(all=1)のときに重くなります。重い読み出しが
// 押し寄せたとき、全部を同時に走らせるとメモリとCPUを食いつぶし、サーバ全体が止まります(2026-10-08)。
// そこで、同時に読むのは IPS_READ_SLOTS 本までにします。空きが無ければ待ち、締め切りまでに
// 空かなければ 503(混んでいます)を返します。止まるのはこのAPIの読み出しだけで、サーバは守られます。
//
// 【締め切りは呼ぶ側が決めます】呼ぶ側は「いつまで待つか」(Unix時刻・秒)を X-Pusyuu-Deadline の見出しで
// 伝えてください。呼ぶ側が待つのをやめた後に読み始めても、その結果は誰にも届かず捨てられるだけです。
// 見出しが無いときは、今から IPS_DEFAULT_WAIT 秒を締め切りにします。
// 【枠の数を増やすときは memory_limit とCPUの数も見ること】全件の読み出しは、1本で memory_limit の
// 半分まで使います(readBudgetBytes())。枠を増やすと、その分だけ同時に使うメモリが増えます。
// =====================================================================
const IPS_READ_SLOTS   = 3;
const IPS_DEFAULT_WAIT = 5;

/** 呼ぶ側が伝えた締め切り(Unix時刻・秒)。無い・おかしい値なら、今から IPS_DEFAULT_WAIT 秒後。 */
function readDeadline(): float {
  $given = (float)($_SERVER['HTTP_X_PUSYUU_DEADLINE'] ?? 0);
  $now = microtime(true);

  // 過去の時刻や、1分より先の時刻は受け取りません(おかしな値で長く居座らせないため)。
  return ($given > $now && $given <= $now + 60) ? $given : $now + IPS_DEFAULT_WAIT;
}

/**
 * 読み出しの枠を1つ取ります。取れたら、その鍵のファイル(この要求が終わるまで握り続けます)。
 * 締め切りまでに取れなければ null。
 */
function acquireReadSlot(float $deadline) {
  $held = null;

  while ($held === null && microtime(true) < $deadline) {
    for ($i = 0; $i < IPS_READ_SLOTS && $held === null; $i++) {
      $fp = @fopen(STORAGE_DIR . '/read_slot_' . $i . '.lock', 'c');
      if ($fp !== false && flock($fp, LOCK_EX | LOCK_NB)) {
        $held = $fp;
      } else if ($fp !== false) {
        fclose($fp);
      }
    }
    if ($held === null) {
      usleep(50000);
    }
  }

  return $held;
}

if ($method === 'GET') {
  $readDeadline = readDeadline();
  $readSlot = acquireReadSlot($readDeadline);
  if ($readSlot === null) {
    header('Retry-After: 2');
    respond(503, ['status' => 'error', 'error' => 'busy', 'message' => '混み合っています。しばらくしてからもう一度お試しください。']);
  }

  try {
    $page        = max(1, (int)($_GET['page']  ?? 1));
    $limit       = max(1, min(100, (int)($_GET['limit'] ?? 15)));
    $id          = $_GET['id']          ?? '';
    $id_one_post = $_GET['id_one_post'] ?? '';
    // 【ids=… は「決まった何件かを、一度にまとめて」読む口です】カンマ区切りのidに当たった
    // 投稿(親でも返信でも、当たった本人)を返します。見つからないidは黙って抜け落ちます
    // (1件ずつ404にしないのは、ブックマークのように「消えた投稿が混じっている並び」を
    // 呼び出し側がそのまま渡してくるためです)。
    // getAllItems() は1回呼ぶたびに全件を読むので、id_one_post を件数ぶん繰り返すより
    // 1回で済むこちらを使ってください。1回に受け付けるのは IDS_MAX 件までで、超えた分は
    // 400 で断ります(黙って切り捨てると、呼び出し側は「無い投稿」と見分けられません)。
    //
    // omit=… は、返す投稿から外す**トップレベルのキー名**です(例: omit=media,replies)。
    // このAPIは個々のキーの意味を知らない約束なので、何を外すかは呼び出し側が名指しします。
    // 一覧の見出しのように本文だけが要る場面で、Base64の画像を運ばずに済ませるためのものです。
    // ids と all=1(全件)のときに効きます。
    $ids  = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['ids'] ?? ''))), 'strlen'));
    $omit = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['omit'] ?? ''))), 'strlen'));

    // 【全件が要るのは検索・並び替えのときだけです】
    // 一覧をただ表示するだけなら page/limit で足ります。表示のたびに all=1 を
    // 投げると、JSONLを1行ずつ読める形式にしている意味が丸ごと失われます。
    // 実際、pips側がサイトマップ生成のために毎リクエスト all=1 を投げており、
    // それが error.log に並んでいたメモリ枯渇の主因でした。
    //
    // 全件が要る場合も、一度に返すとは限りません。今のmemory_limitを実際に読み、
    // その半分に収まらない量なら分割して返し、続きの位置を next_offset で知らせます。
    // 呼び出し側は next_offset が null になるまで繰り返してください。
    if (!empty($_GET['all']) && $id === '' && $id_one_post === '' && $ids === [] && $driver instanceof JsonlStorageDriver) {
      $offset = max(0, (int)($_GET['offset'] ?? 0));
      $chunk = $driver->readChunk($offset, readBudgetBytes());
      respond(200, [
        'item'        => omitKeys($chunk['items'], $omit),
        'total'       => count($chunk['items']),
        'next_offset' => $chunk['next_offset'],
        'chunked'     => true,
      ]);
    }

    // 【JSONL なら、全件を解かずに索引から読みます】(JsonlStorageDriver の「索引」の説明)
    // 返す形と中身は、下の getAllItems() を使う道と同じです。ほかの保存方式は今まで通り下の道を通ります。
    if ($driver instanceof JsonlStorageDriver && ($id !== '' || $id_one_post !== '')) {
      $target = ($id !== '') ? $driver->findById($id, true) : $driver->findById($id_one_post, false);
      if ($target === null) {
        respond(404, ['status' => 'error', 'message' => '投稿が見つかりません']);
      }
      if ($id_one_post !== '') {
        respond(200, ['item' => [$target], 'total' => 1]);
      }
      $replies       = $target['replies'] ?? [];
      $total_replies = count($replies);
      if (!empty($_GET['all'])) {
        $target['replies'] = $replies;
        respond(200, ['item' => [$target], 'total_replies' => $total_replies]);
      }
      $target['replies'] = array_slice($replies, ($page - 1) * $limit, $limit);
      respond(200, ['item' => [$target], 'total_replies' => $total_replies, 'page' => $page, 'limit' => $limit]);
    }
    if ($driver instanceof JsonlStorageDriver && $ids !== []) {
      if (count($ids) > IDS_MAX) {
        respond(400, ['status' => 'error', 'error' => 'too_many_ids', 'message' => 'ids は1回に' . IDS_MAX . '件までです']);
      }
      $byId = $driver->findByIds($ids);
      $found = [];
      foreach ($ids as $wanted) {
        if (isset($byId[$wanted])) {
          $found[] = omitKeys([$byId[$wanted]], $omit)[0];
        }
      }
      respond(200, ['item' => $found, 'total' => count($found)]);
    }
    if ($driver instanceof JsonlStorageDriver && empty($_GET['all'])) {
      $pageResult = $driver->newestPage($page, $limit);
      respond(200, ['item' => $pageResult['items'], 'total' => $pageResult['total'], 'page' => $page, 'limit' => $limit]);
    }

    $all_items = $driver->getAllItems();

    if ($id !== '') {
      $target = null;
      foreach ($all_items as $item) {
        if ((string)($item['id'] ?? '') === $id) { $target = $item; break; }
        foreach ($item['replies'] ?? [] as $reply) {// 改良時ここ消さない、リプライIDの時にそのスレッド内を案内できるようにするために必要
          if ((string)($reply['id'] ?? '') === $id) { $target = $item; break 2; }
        }
      }
      if ($target === null) {
        respond(404, ['status' => 'error', 'message' => '投稿が見つかりません']);
      }
      $replies       = $target['replies'] ?? [];
      $total_replies = count($replies);
      // 呼び出し側(pips/index.phpのpipsFetchAllForSearchSort())が検索・並び替えのために
      // 全件を必要とする場合向けの逃げ道。無指定時は従来通りpage/limitでスライスする。
      if (!empty($_GET['all'])) {
        $target['replies'] = $replies;
        respond(200, ['item' => [$target], 'total_replies' => $total_replies]);
      }
      $target['replies'] = array_slice($replies, ($page - 1) * $limit, $limit);
      respond(200, ['item' => [$target], 'total_replies' => $total_replies, 'page' => $page, 'limit' => $limit]);

    } elseif ($id_one_post !== '') {
      $found = null;
      foreach ($all_items as $item) {
        if ((string)($item['id'] ?? '') === $id_one_post) { $found = $item; break; }
        foreach ($item['replies'] ?? [] as $reply) {// ここは本名、先ほどのと違い返信のこのポストと明示的な表示に必要
          if ((string)($reply['id'] ?? '') === $id_one_post) { $found = $reply; break 2; }
        }
      }
      if ($found === null) {
        respond(404, ['status' => 'error', 'message' => '投稿が見つかりません']);
      }
      respond(200, ['item' => [$found], 'total' => 1]);

    } elseif ($ids !== []) {
      if (count($ids) > IDS_MAX) {
        respond(400, ['status' => 'error', 'error' => 'too_many_ids', 'message' => 'ids は1回に' . IDS_MAX . '件までです']);
      }
      // 返す順は、求められたidの順です(呼び出し側の並び=保存した順をそのまま使えるように)。
      $byId = [];
      foreach ($all_items as $item) {
        $byId[(string)($item['id'] ?? '')] = $item;
        foreach ($item['replies'] ?? [] as $reply) {
          $byId[(string)($reply['id'] ?? '')] = $reply;
        }
      }
      $found = [];
      foreach ($ids as $wanted) {
        if (isset($byId[$wanted])) {
          $found[] = omitKeys([$byId[$wanted]], $omit)[0];
        }
      }
      respond(200, ['item' => $found, 'total' => count($found)]);

    } else {
      usort($all_items, function ($a, $b) {
        return parseDatetimeForSort((string)($b['datetime'] ?? '')) - parseDatetimeForSort((string)($a['datetime'] ?? ''));
      });
      $total = count($all_items);
      // pips/index.phpの検索・並び替え機能(pipsFetchAllForSearchSort())は、絞り込み・
      // 並び替えの対象を全件にする必要があるため、以前はpage/limit(最大100件)を
      // 繰り返し呼んで全件を集めていた。しかしgetAllItems()は毎回ファイル全体を
      // 読み直すため、呼ぶたびに全件読み込みが走り、投稿数が増えるほど
      // (総ページ数)×(全件読み込みコスト)というほぼ二乗のコストになっていた。
      // $all=1はそれを1回の呼び出しで済ませるための専用の逃げ道。
      if (!empty($_GET['all'])) {
        respond(200, ['item' => omitKeys($all_items, $omit), 'total' => $total]);
      }
      $paged = array_slice($all_items, ($page - 1) * $limit, $limit);
      respond(200, ['item' => $paged, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }
  } catch (Throwable $e) {
    respond(500, ['status' => 'error', 'message' => '読み込みに失敗しました: ' . $e->getMessage()]);
  }
}

if ($method === 'POST') {
  $rawBody = file_get_contents('php://input');
  $payload = json_decode((string)$rawBody, true);

  if (!is_array($payload)) {
    respond(400, ['status' => 'error', 'message' => '不正なJSONです']);
  }

  // 封筒（エンベロープ）部分だけをAPIが解釈する。
  // type / parent_id はAPI側の構造制御用キーであり、data の中身（text/media等）には一切関与しない。
  //
  // 【type は必ず名指しで受け取ること。知らない type を新規投稿として扱わないこと】
  // 以前は type が無い・知らない値のときに、最後の else で新規投稿として追記していました。
  // そのため、type の綴りを間違えた書き込みや、このAPIがまだ知らない type(新しく足した
  // 種類を、古いままのAPIへ送った場合など)が、中身の無い投稿として1件増えていました。
  // 今は type が無いか知らない値なら 400 で断り、error に 'unknown_type' を入れて返します。
  // pips はこれを「届いた上で断られた」と受け取り、ローカルの控えへは書きません。
  $knownTypes = ['post', 'reply', 'update', 'delete', 'increment'];
  $type = $payload['type'] ?? null;
  $data = $payload['data'] ?? null;

  if (!is_string($type) || !in_array($type, $knownTypes, true)) {
    respond(400, ['status' => 'error', 'error' => 'unknown_type', 'message' => '"type" が無いか、知らない値です(' . implode(' / ', $knownTypes) . ' のどれかを指定してください)']);
  }

  if (!is_array($data)) {
    respond(400, ['status' => 'error', 'message' => '"data" は必須かつオブジェクトである必要があります']);
  }

  try {
    if ($type === 'reply') {
      $parentId = (string)($payload['parent_id'] ?? '');
      if ($parentId === '') {
        respond(400, ['status' => 'error', 'message' => 'reply投稿には "parent_id" が必要です']);
      }
      if (!isset($data['id'])) {
        $data['id'] = generateId();
      }
      $found = $driver->appendReply($parentId, $data);
      if (!$found) {
        respond(404, ['status' => 'error', 'message' => '指定されたparent_idのitemが見つかりません']);
      }
      respond(200, ['status' => 'ok', 'id' => $data['id']]);
    } elseif ($type === 'update') {
      // 既存item（トップレベル投稿・返信いずれも可）の部分更新（編集）。
      // 管理画面での編集や、閲覧数のように後から追加されるキーの更新に使う汎用エンドポイント。
      // 更新対象の指定は parent_id と同様に envelope 側の "id" で行い、data には
      // 上書きしたいキーだけを渡す（data に無い既存キーはそのまま保持される）。
      $targetId = (string)($payload['id'] ?? '');
      if ($targetId === '') {
        respond(400, ['status' => 'error', 'message' => 'update には "id" が必要です']);
      }
      $found = $driver->updateItem($targetId, $data);
      if (!$found) {
        respond(404, ['status' => 'error', 'message' => '指定されたidのitemが見つかりません']);
      }
      respond(200, ['status' => 'ok', 'id' => $targetId]);
    } elseif ($type === 'delete') {
      // 既存item（トップレベル投稿・返信いずれも可）の削除。
      // 対象の指定は update と同様に envelope 側の "id" で行う（data は使わない）。
      $targetId = (string)($payload['id'] ?? '');
      if ($targetId === '') {
        respond(400, ['status' => 'error', 'message' => 'delete には "id" が必要です']);
      }
      $found = $driver->deleteItem($targetId);
      if (!$found) {
        respond(404, ['status' => 'error', 'message' => '指定されたidのitemが見つかりません']);
      }
      respond(200, ['status' => 'ok', 'id' => $targetId]);
    } elseif ($type === 'increment') {
      // 既存item（トップレベル投稿・返信いずれも可）の数値のキーへ差分を足す。
      // 対象の指定は update と同様に envelope 側の "id"、data は {キー: 足す整数} です。
      //   例: {"type":"increment","id":"...","data":{"views":1}}
      // 読んでから足すまでは保存先のロックの中で行われます(incrementItem() のコメント参照)。
      // 応答の values は足した後の値です。
      $targetId = (string)($payload['id'] ?? '');
      if ($targetId === '') {
        respond(400, ['status' => 'error', 'message' => 'increment には "id" が必要です']);
      }
      if (empty($data)) {
        respond(400, ['status' => 'error', 'message' => 'increment の "data" には、足すキーを1つ以上入れてください']);
      }
      foreach ($data as $key => $delta) {
        // id と replies は投稿の構造そのものなので、数として足させません。
        if (!is_string($key) || $key === 'id' || $key === 'replies') {
          respond(400, ['status' => 'error', 'message' => '"' . $key . '" には足せません']);
        }
        if (!is_int($delta)) {
          respond(400, ['status' => 'error', 'message' => '"' . $key . '" に足す値は整数で指定してください']);
        }
      }
      try {
        $values = $driver->incrementItem($targetId, $data);
      } catch (InvalidArgumentException $e) {
        // 足す先が数ではなかった(キーの取り違え等)。何も書き換えていません。
        respond(409, ['status' => 'error', 'error' => 'not_a_number', 'message' => $e->getMessage()]);
      }
      if ($values === null) {
        respond(404, ['status' => 'error', 'message' => '指定されたidのitemが見つかりません']);
      }
      respond(200, ['status' => 'ok', 'id' => $targetId, 'values' => $values]);
    } elseif ($type === 'post') {
      if (!isset($data['id'])) {
        $data['id'] = generateId();
      }
      $driver->appendItem($data);
      respond(200, ['status' => 'ok', 'id' => $data['id']]);
    } else {
      // 上の $knownTypes に足したのに、ここへ枝を足し忘れた場合だけ来ます。
      // 黙って何かをせず、必ず失敗として返します(書き忘れに最初の1回で気づくため)。
      respond(500, ['status' => 'error', 'error' => 'unhandled_type', 'message' => '開発者の設定ミス: type "' . $type . '" の処理がありません']);
    }
  } catch (Throwable $e) {
    respond(500, ['status' => 'error', 'message' => '保存に失敗しました: ' . $e->getMessage()]);
  }
}

respond(405, ['status' => 'error', 'message' => '許可されていないHTTPメソッドです']);
