<?php
// ver1.0 初期バージョン
// ver2.0 JSON取得してキャッシュする。widgets.jsはローカルに置くようにした。
// ver2.1 データ保管方式を .datから.txtに変更、ブラウザのnative loading="lazy"などに対応
// ver2.2 lazysizes.jsなしでの遅延読み込みに対応
// ver2.3 第2引数以降の引数にnoimgまたはnoconvとつけると、それぞれ画像を非表示にしたりリプライツイートのスレッドを非表示にできます。両方を併用することもできます。
// ver2.4 oEmbed APIからJSON取得してキャッシュ、widgets.js遅延読み込みを圧縮（288バイト）
// ver3.0 Twitter API v2対応：画像・引用ツイート（子ツイート）をJSONキャッシュに保存し静的HTMLで表示
// ver3.1 ツイート画像ファイルをサーバーローカルに保存する機能を追加

define('PLUGIN_TWEET_LAZYLOAD', TRUE); // 初回スクロールに反応しての遅延読み込みを有効にするにはTRUEに、使っていないならFALSEに
define('PLUGIN_TWEET_JSURL', 'https://platform.twitter.com/widgets.js'); // デフォルトは https://platform.twitter.com/widgets.js
define('PLUGIN_TWEET_BEARER_TOKEN', ''); // Twitter API v2 Bearer Token（未設定時はoEmbed APIにフォールバック）

// --- 画像ローカル保存の設定 ---
// 画像ファイルを保存するサーバー上の絶対パス。末尾スラッシュなし。
// 例: '/var/www/html/wiki/cache/tweet_img'
// 空文字列の場合は画像をローカル保存せずTwitter CDNのURLを使用する。
define('PLUGIN_TWEET_LOCAL_IMAGE_DIR', '');

// 上記ディレクトリへのWebアクセス用URLプレフィックス。末尾スラッシュなし。
// 例: 'https://example.com/wiki/cache/tweet_img'
define('PLUGIN_TWEET_LOCAL_IMAGE_URL', '');

/**
 * Twitter API v2 からツイートの詳細データ（画像・引用ツイートを含む）を取得する。
 * PLUGIN_TWEET_BEARER_TOKEN が未設定の場合は false を返す。
 *
 * キャッシュJSON ('v2' キー) に保存される主なデータ構造:
 * {
 *   "data": {
 *     "id": "1234567890",
 *     "text": "ツイート本文",
 *     "author_id": "...",
 *     "attachments": { "media_keys": ["3_xxx"] },
 *     "referenced_tweets": [ {"type": "quoted", "id": "..."} ]
 *   },
 *   "includes": {
 *     "media": [
 *       { "media_key": "3_xxx", "type": "photo",
 *         "url": "https://pbs.twimg.com/media/XXX?format=jpg&name=orig",
 *         "alt_text": "..." }
 *     ],
 *     "tweets": [ {"id": "...", "text": "引用元ツイート本文"} ],
 *     "users":  [ {"id": "...", "name": "表示名", "username": "screen_name"} ]
 *   }
 * }
 */
function plugin_tweet_fetch_v2($tweetid)
{
    $bearer_token = PLUGIN_TWEET_BEARER_TOKEN;
    if (empty($bearer_token)) return false;

    $url = 'https://api.twitter.com/2/tweets/' . urlencode($tweetid)
        . '?tweet.fields=' . urlencode('text,attachments,referenced_tweets,created_at,author_id')
        . '&expansions='   . urlencode('attachments.media_keys,referenced_tweets.id,author_id')
        . '&media.fields=' . urlencode('url,preview_image_url,type,alt_text,width,height')
        . '&user.fields='  . urlencode('name,username,profile_image_url');

    $context = stream_context_create([
        'http' => [
            'header'        => 'Authorization: Bearer ' . $bearer_token . "\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        ]
    ]);

    $json = @file_get_contents($url, false, $context);
    if ($json === false) return false;

    $data = json_decode($json, true);
    if (!isset($data['data']['id'])) return false;

    return $data;
}

/**
 * ツイートの画像1枚をローカルに保存し、ローカルURLを返す。
 * PLUGIN_TWEET_LOCAL_IMAGE_DIR / URL が未設定の場合や保存失敗時は null を返す。
 *
 * 保存ファイル名: {tweetid}_{連番}.{拡張子}
 * 例: 1234567890_0.jpg
 *
 * @param string $img_url    Twitter CDN上の画像URL
 * @param string $tweetid    ツイートID（ファイル名プレフィックスに使用）
 * @param int    $idx        このツイートの何枚目か（0始まり）
 * @return string|null       ローカルURL、または null
 */
function plugin_tweet_save_local_image($img_url, $tweetid, $idx)
{
    $local_dir = PLUGIN_TWEET_LOCAL_IMAGE_DIR;
    $local_url = PLUGIN_TWEET_LOCAL_IMAGE_URL;
    if (empty($local_dir) || empty($local_url)) return null;

    // 拡張子の決定（?format=jpg や .jpg 形式に対応）
    $ext = 'jpg';
    if (preg_match('/[?&]format=(\w+)/', $img_url, $m)) {
        $ext = $m[1];
    } elseif (preg_match('/\.(\w+)(?:\?|$)/', basename(parse_url($img_url, PHP_URL_PATH)), $m)) {
        $ext = $m[1];
    }

    $filename  = $tweetid . '_' . $idx . '.' . $ext;
    $localpath = rtrim($local_dir, '/') . '/' . $filename;
    $localurl  = rtrim($local_url, '/') . '/' . $filename;

    if (file_exists($localpath)) {
        return $localurl;
    }

    // 保存ディレクトリを作成
    if (!file_exists($local_dir)) {
        if (!@mkdir($local_dir, 0777, true)) return null;
    }

    // 最高解像度（orig）で取得
    $fetch_url = preg_replace('/name=\w+/', 'name=orig', $img_url);
    $img_data  = @file_get_contents($fetch_url);
    if ($img_data === false) return null;

    if (@file_put_contents($localpath, $img_data) === false) return null;

    return $localurl;
}

/**
 * v2データから blockquote HTML を構築する。
 * widgets.js が動作すれば公式ウィジェットとして表示され、
 * 動作しない場合でも画像・引用ツイートを含む静的 HTML としてフォールバック表示される。
 *
 * @param array  $v2data      plugin_tweet_fetch_v2() の戻り値
 * @param string $tweeturl    ツイートURL
 * @param array  $opts        プラグイン引数（'noimg', 'noconv' を含む可能性あり）
 * @param array  $local_imgs  media_key => ローカルURL の連想配列（省略時は空）
 * @return string             HTML文字列
 */
function plugin_tweet_build_html_v2($v2data, $tweeturl, $opts = [], $local_imgs = [])
{
    $tweet    = $v2data['data'];
    $includes = isset($v2data['includes']) ? $v2data['includes'] : [];
    $noimg    = in_array('noimg',  $opts);
    $noconv   = in_array('noconv', $opts);

    // 著者情報
    $author_name     = '';
    $author_username = '';
    if (!empty($includes['users'])) {
        foreach ($includes['users'] as $user) {
            if (isset($tweet['author_id']) && $user['id'] === $tweet['author_id']) {
                $author_name     = htmlspecialchars($user['name'],     ENT_QUOTES, 'UTF-8');
                $author_username = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
                break;
            }
        }
    }

    // ツイート本文
    $text = htmlspecialchars($tweet['text'], ENT_QUOTES, 'UTF-8');

    // 画像HTML（noimgオプション時はスキップ）
    $media_html = '';
    if (!$noimg && !empty($includes['media'])) {
        $imgs = '';
        foreach ($includes['media'] as $media) {
            $media_key = isset($media['media_key']) ? $media['media_key'] : '';
            if ($media['type'] === 'photo' && !empty($media['url'])) {
                // ローカル保存済みURLを優先、なければTwitter CDN URL
                $img_url = isset($local_imgs[$media_key]) && $local_imgs[$media_key]
                    ? $local_imgs[$media_key]
                    : $media['url'];
                $img_url = htmlspecialchars($img_url, ENT_QUOTES, 'UTF-8');
                $alt     = htmlspecialchars(isset($media['alt_text']) ? $media['alt_text'] : '', ENT_QUOTES, 'UTF-8');
                $imgs   .= '<img src="' . $img_url . '" alt="' . $alt . '" loading="lazy" style="max-width:100%;display:block;margin:4px 0;">';
            } elseif (in_array($media['type'], ['video', 'animated_gif']) && !empty($media['preview_image_url'])) {
                $thumb_url = isset($local_imgs[$media_key . '_thumb']) && $local_imgs[$media_key . '_thumb']
                    ? $local_imgs[$media_key . '_thumb']
                    : $media['preview_image_url'];
                $thumb_url = htmlspecialchars($thumb_url, ENT_QUOTES, 'UTF-8');
                $imgs     .= '<img src="' . $thumb_url . '" alt="video thumbnail" loading="lazy" style="max-width:100%;display:block;margin:4px 0;">';
            }
        }
        if ($imgs !== '') {
            $media_html = '<div class="tweet-media">' . $imgs . '</div>';
        }
    }

    // 引用ツイートHTML（noconvオプション時はスキップ）
    $quoted_html = '';
    if (!$noconv && !empty($tweet['referenced_tweets']) && !empty($includes['tweets'])) {
        foreach ($tweet['referenced_tweets'] as $ref) {
            if ($ref['type'] !== 'quoted') continue;
            foreach ($includes['tweets'] as $qt) {
                if ($qt['id'] !== $ref['id']) continue;
                $qt_text = htmlspecialchars($qt['text'], ENT_QUOTES, 'UTF-8');
                $qt_url  = htmlspecialchars('https://twitter.com/i/status/' . $qt['id'], ENT_QUOTES, 'UTF-8');
                $quoted_html = '<blockquote class="tweet-quoted" style="border-left:3px solid #ccc;margin:8px 0;padding:4px 8px;font-size:.9em;">'
                    . '<p>' . $qt_text . '</p>'
                    . '<a href="' . $qt_url . '">' . $qt_url . '</a>'
                    . '</blockquote>';
                break;
            }
        }
    }

    // blockquote 属性
    $bq_attrs = 'class="twitter-tweet"';
    if ($noimg)  $bq_attrs .= ' data-cards="hidden"';
    if ($noconv) $bq_attrs .= ' data-conversation="none"';

    $safe_url = htmlspecialchars($tweeturl, ENT_QUOTES, 'UTF-8');

    return '<blockquote ' . $bq_attrs . '>'
        . '<p lang="ja">' . $text . '</p>'
        . ($author_name !== '' ? '<p>— ' . $author_name . ' (@' . $author_username . ')</p>' : '')
        . $media_html
        . $quoted_html
        . '<a href="' . $safe_url . '">' . $safe_url . '</a>'
        . '</blockquote>';
}

/**
 * v2データ内の画像をローカルに保存し、media_key => ローカルURL の配列を返す。
 * PLUGIN_TWEET_LOCAL_IMAGE_DIR が未設定の場合は空配列を返す。
 *
 * @param array  $v2data   plugin_tweet_fetch_v2() の戻り値
 * @param string $tweetid  ツイートID
 * @return array           media_key => ローカルURL の連想配列
 */
function plugin_tweet_download_images($v2data, $tweetid)
{
    if (empty(PLUGIN_TWEET_LOCAL_IMAGE_DIR)) return [];

    $includes   = isset($v2data['includes']) ? $v2data['includes'] : [];
    $local_imgs = [];
    $idx        = 0;

    if (empty($includes['media'])) return [];

    foreach ($includes['media'] as $media) {
        $media_key = isset($media['media_key']) ? $media['media_key'] : $idx;
        if ($media['type'] === 'photo' && !empty($media['url'])) {
            $local = plugin_tweet_save_local_image($media['url'], $tweetid, $idx);
            if ($local !== null) {
                $local_imgs[$media_key] = $local;
            }
            $idx++;
        } elseif (in_array($media['type'], ['video', 'animated_gif']) && !empty($media['preview_image_url'])) {
            $local = plugin_tweet_save_local_image($media['preview_image_url'], $tweetid, $idx);
            if ($local !== null) {
                $local_imgs[$media_key . '_thumb'] = $local;
            }
            $idx++;
        }
    }

    return $local_imgs;
}

function plugin_tweet_convert()
{
    global $head_tags;
    $tw = func_get_args();

    // ツイートIDまたはURLからIDを抽出
    preg_match('/[0-9]{9,30}/', $tw[0], $tweetids);
    $tweetid = end($tweetids);

    // URLからユーザー名を抽出（可能な場合）
    if (preg_match('/(?:x\.com|twitter\.com)\/([^\/]+)\/status/', $tw[0], $matches)) {
        $username = $matches[1];
        $tweeturl = 'https://twitter.com/' . $username . '/status/' . $tweetid;
    } else {
        $tweeturl = 'https://twitter.com/user/status/' . $tweetid;
    }

    // キャッシュディレクトリの確認と作成
    $cache_dir = CACHE_DIR . 'tweet/';
    if (!file_exists($cache_dir)) {
        @mkdir($cache_dir, 0777, true);
    }
    $cachefile = $cache_dir . $tweetid . '.txt';

    $html      = '';
    $used_v2   = false;

    // --- キャッシュから読み込み ---
    if (file_exists($cachefile)) {
        $cache_json = file_get_contents($cachefile);
        $cache_data = json_decode($cache_json, true);
        if ($cache_data) {
            if (isset($cache_data['v2'])) {
                // v2データ付きキャッシュ → ローカル画像URLを含めて静的HTMLを構築
                $local_imgs = isset($cache_data['local_images']) ? $cache_data['local_images'] : [];
                $html       = plugin_tweet_build_html_v2($cache_data['v2'], $tweeturl, $tw, $local_imgs);
                $used_v2    = true;
            } elseif (isset($cache_data['html'])) {
                // oEmbedのみのキャッシュ（旧形式との互換）
                $html = html_entity_decode($cache_data['html']);
            }
        }
    }

    // --- キャッシュなし or 破損 → APIから取得 ---
    if (empty($html)) {
        $v2data = plugin_tweet_fetch_v2($tweetid);

        if ($v2data) {
            // 画像をローカルに保存
            $local_imgs = plugin_tweet_download_images($v2data, $tweetid);

            // 静的HTMLを構築
            $html    = plugin_tweet_build_html_v2($v2data, $tweeturl, $tw, $local_imgs);
            $used_v2 = true;

            // oEmbed も取得してキャッシュに同梱（widgets.js 用）
            $oembed_url  = 'https://publish.twitter.com/oembed?maxwidth=550&dnt=true&url=' . urlencode($tweeturl);
            $oembed_json = @file_get_contents($oembed_url);
            $oembed_data = ($oembed_json !== false) ? json_decode($oembed_json, true) : null;

            // キャッシュ保存（v2 JSON + ローカル画像パス + oEmbed HTML）
            $save = ['v2' => $v2data];
            if (!empty($local_imgs)) {
                $save['local_images'] = $local_imgs;
            }
            if ($oembed_data && isset($oembed_data['html'])) {
                $save['html'] = $oembed_data['html'];
            }
            @file_put_contents($cachefile, json_encode($save, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            // v2 API 未設定またはエラー → oEmbed にフォールバック
            $oembed_url = 'https://publish.twitter.com/oembed?maxwidth=550&dnt=true&url=' . urlencode($tweeturl);
            $json       = @file_get_contents($oembed_url);
            if ($json !== false) {
                $data = json_decode($json, true);
                if ($data && isset($data['html'])) {
                    $html = html_entity_decode($data['html']);
                    @file_put_contents($cachefile, $json);
                }
            }
        }
    }

    // --- すべて失敗時のフォールバック ---
    if (empty($html)) {
        $fallback_text = isset($tw[1]) ? htmlspecialchars($tw[1], ENT_QUOTES, 'UTF-8') : '';
        $html = '<blockquote class="twitter-tweet"><a href="' . htmlspecialchars($tweeturl, ENT_QUOTES, 'UTF-8') . '">' . $fallback_text . '</a></blockquote>';
    }

    // --- noimg / noconv（oEmbed HTML の場合のみ文字列置換で対応）---
    if (!$used_v2) {
        if (in_array('noimg', $tw)) {
            $html = str_replace('class="twitter-tweet"', 'class="twitter-tweet" data-cards="hidden"', $html);
        }
        if (in_array('noconv', $tw)) {
            $html = str_replace('class="twitter-tweet"', 'class="twitter-tweet" data-conversation="none"', $html);
        }
    }

    // --- widgets.js の読み込み処理 ---
    if (PLUGIN_TWEET_LAZYLOAD) {
        $script = '<script>!function(s){var f=0,l=function(){f||(f=1,document.body.appendChild(Object.assign(document.createElement("script"),{src:s})),window.removeEventListener("scroll",l),window.removeEventListener("mousemove",l))};window.addEventListener("scroll",l),window.addEventListener("mousemove",l)}("' . PLUGIN_TWEET_JSURL . '");</script>';
    } else {
        $script = '<script async src="' . PLUGIN_TWEET_JSURL . '" charset="utf-8"></script>';
    }
    if (!in_array($script, $head_tags, true)) {
        $head_tags[] = $script;
    }

    return "\t$html\n";
}
?>
