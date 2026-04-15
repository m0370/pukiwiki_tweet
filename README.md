# pukiwiki自作プラグイン tweet.inc.php

PukiwikiにX(旧Twitter)の単体のツイートを埋め込むだけのプラグインです。

TLの複数のツイートを埋め込んだり検索結果を表示するような高度な機能は備えていません。JavaScriptを利用しているので自分のpukiwikiサーバにはほとんど負荷をかけません。キャッシュとしてツイート内容を保存しておき、2回目の表示からはフォールバックとしてツイートをキャッシュから表示することができます。

自分のpukiwikiを「はてなダイアリー」的なブログのように利用するために自作して自分だけで使っていたプラグインですが、せっかく作ったので公開しておきます。

詳しくはこちらのサイトもご覧ください。
https://oncologynote.jp/?b723fa4260

## 設置方法

1. pluginフォルダにtweet.inc.phpを設置します。
2. キャッシュの保存場所のためにcacheフォルダ内にtweetというサブフォルダを作っておきます。このフォルダには読み書き可能なパーミッション(666)を与えます。

## 使い方

>#tweet(1375069974583074816)  
>// ツイートIDを書く場合

#tweet(https://x.com/m0370/status/1375069974583074816)
>// ツイートURLを書く場合

上記のように記載すればツイートが表示されます。

## Twitter API v2 による画像・引用ツイート取得（ver3.0/3.1）

### Bearer Token の取得

1. [Twitter Developer Portal](https://developer.twitter.com/) でアプリを作成する
2. プロジェクトのダッシュボードから **Bearer Token** をコピーする
3. `tweet.inc.php` の先頭にある定数に設定する

```php
define('PLUGIN_TWEET_BEARER_TOKEN', 'ここにBearer Tokenを貼り付ける');
```

Bearer Token を設定しない場合は従来の oEmbed API にフォールバックします（画像・引用ツイートは widgets.js 依存）。

### キャッシュJSONの構造（v2使用時）

`cache/tweet/{ツイートID}.txt` に以下の JSON が保存されます。

```json
{
  "v2": {
    "data": {
      "id": "1234567890",
      "text": "ツイート本文",
      "author_id": "...",
      "attachments": { "media_keys": ["3_xxx"] },
      "referenced_tweets": [ {"type": "quoted", "id": "..."} ]
    },
    "includes": {
      "media": [
        { "type": "photo", "url": "https://pbs.twimg.com/media/XXX?format=jpg&name=orig",
          "alt_text": "画像の説明" }
      ],
      "tweets": [ {"id": "...", "text": "引用元ツイート本文"} ],
      "users":  [ {"id": "...", "name": "表示名", "username": "screen_name"} ]
    }
  },
  "local_images": {
    "3_xxx": "https://example.com/wiki/cache/tweet_img/1234567890_0.jpg"
  },
  "html": "<blockquote class=\"twitter-tweet\">...</blockquote>"
}
```

### 画像ファイルをサーバーにローカル保存する（ver3.1）

Twitter CDN の画像URLはツイートが削除されたり X のサービスが変化した場合に無効になります。
画像ファイルをサーバーにローカル保存することで、ツイートが消えた後も画像を表示し続けることができます。

`tweet.inc.php` で以下の2つの定数を設定してください。

```php
// 画像を保存するサーバー上の絶対パス（Webから読めるディレクトリ）
define('PLUGIN_TWEET_LOCAL_IMAGE_DIR', '/var/www/html/wiki/cache/tweet_img');

// そのディレクトリへのWebアクセス用URLプレフィックス
define('PLUGIN_TWEET_LOCAL_IMAGE_URL', 'https://example.com/wiki/cache/tweet_img');
```

- 保存ファイル名は `{ツイートID}_{連番}.{拡張子}` 形式（例: `1234567890_0.jpg`）
- 動画・GIFの場合はサムネイル画像（preview_image_url）を保存します
- ローカル保存に成功するとキャッシュJSON の `local_images` にマッピングが記録されます
- 両定数が未設定の場合は従来どおり Twitter CDN の URL を使用します

### フォールバック動作まとめ

| 条件 | 表示方法 |
|------|---------|
| Bearer Token あり | v2 API で取得した本文・画像・引用ツイートを静的 HTML で表示、widgets.js で公式ウィジェットに置換 |
| Bearer Token なし | oEmbed API の HTML を使用（画像・引用ツイートは widgets.js 依存） |
| ネットワーク障害時 | キャッシュから静的 HTML を表示 |

## 変更履歴
### ver2.1までの変更点

ver1.0と比べての変化点は、TwitterのサイトからJSONでツイート内容を取得してキャッシュしておく機能が付いた点です。また、ver2.0からver2.1に変わるときにキャッシュファイルの形式を変更しました（拡張子も.datから.txtに変更し、互換性はありません）。

### ver2.2の変更点

https://platform.twitter.com/widgets.js を遅延読み込みで呼び出す方法を、 lazysizes.js を使わずにこのプラグイン単独で対応しています。

具体的には初回スクロール（scroll）または初回マウス移動（mousemove）で発火するjavascriptを使ってwidget.jsを読み込んでいます。このjavascriptはスキンの$head_tagの中に配置するようにしています。初回スクロールで発火するjavasriptは下記のサイトを参考にさせていただきました。なお、widgets.jsをpreloadで読み込むようにすることでさらに高速化が図れます。

https://q-az.net/lazy-load-script/

### ver2.3の変更点

第2引数以降の引数にnoimgまたはnoconvとつけると、それぞれ画像を非表示にしたりリプライツイートのスレッドを非表示にできます。両方を併用することもできます。

### ver2.4の変更点

widgets.jsの遅延読み込みコードを大幅に圧縮し、475バイトから288バイトへ39%削減しました（IIFE使用による最適化）。また、デフォルトで遅延読み込みを有効化（`PLUGIN_TWEET_LAZYLOAD = TRUE`）し、PageSpeed Insightsのスコア改善に対応しました。

ver2.7/2.8で追加された複雑な実装を削除し、シンプルで動作が安定したver2.3ベースの実装に戻しています。oEmbed APIからJSON取得してキャッシュする基本機能は維持しています。

## しくみ

Twitter(X) Publishで発行されるHTMLタグを出力するだけなので単純な構造です。プラグインの第1引数にツイートIDかツイートのURLのどちらかを記載します。これによって、[旧版ver1.0](https://oncologynote.jp/?f6353f6b5e)ではフォールバックを記載しておくことができましたが、ver2.0以降ではJSONキャッシュから自動でフォールバックが作成されます。

### 昔すぎるツイートは読み込めません

旧版ではツイートIDを9桁以上かどうかで判断していたため、IDが短いごく古いツイートは読み込めないことがありました。現在のバージョンでも9桁以上（9-30桁）の数字パターンでツイートIDを抽出しているため、非常に古いツイート（IDが9桁未満）は読み込めない可能性があります。
