<?php
// ver1.0 初期バージョン
// ver2.0 JSON取得してキャッシュする。widgets.jsはローカルに置くようにした。
// ver2.1 データ保管方式を .datから.txtに変更、ブラウザのnative loading="lazy"などに対応
// ver2.2 lazysizes.jsなしでの遅延読み込みに対応
// ver2.3 第2引数以降の引数にnoimgまたはnoconvとつけると、それぞれ画像を非表示にしたりリプライツイートのスレッドを非表示にできます。両方を併用することもできます。
// ver2.4 oEmbed APIからJSON取得してキャッシュ、widgets.js遅延読み込みを圧縮（288バイト）

define('PLUGIN_TWEET_LAZYLOAD', TRUE); // 初回スクロールに反応しての遅延読み込みを有効にするにはTRUEに、使っていないならFALSEに
define('PLUGIN_TWEET_JSURL', 'https://platform.twitter.com/widgets.js'); //デフォルトは https://platform.twitter.com/widgets.js

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
		// ユーザー名が取得できない場合は汎用URL
		$tweeturl = 'https://twitter.com/user/status/' . $tweetid;
	}

	// キャッシュディレクトリの確認と作成
	$cache_dir = CACHE_DIR . 'tweet/';
	if (!file_exists($cache_dir)) {
		@mkdir($cache_dir, 0777, true);
	}
	$cachefile = $cache_dir . $tweetid . '.txt';

	// キャッシュ確認とoEmbed API取得
	if (file_exists($cachefile)) {
		// キャッシュから読み込み
		$json = file_get_contents($cachefile);
		$data = json_decode($json, true);
		if ($data && isset($data['html'])) {
			$html = html_entity_decode($data['html']);
		} else {
			// キャッシュ破損時はフォールバック
			$fallback_text = isset($tw[1]) ? htmlspecialchars($tw[1], ENT_QUOTES, 'UTF-8') : '';
			$html = '<blockquote class="twitter-tweet"><a href="' . htmlspecialchars($tweeturl, ENT_QUOTES, 'UTF-8') . '">' . $fallback_text . '</a></blockquote>';
		}
	} else {
		// oEmbed APIからJSON取得
		$oembed_url = 'https://publish.twitter.com/oembed?maxwidth=550&dnt=true&url=' . urlencode($tweeturl);
		$json = @file_get_contents($oembed_url);

		if ($json !== false) {
			$data = json_decode($json, true);
			if ($data && isset($data['html'])) {
				$html = html_entity_decode($data['html']);
				// キャッシュに保存
				@file_put_contents($cachefile, $json);
			} else {
				// JSON取得失敗時はフォールバック
				$fallback_text = isset($tw[1]) ? htmlspecialchars($tw[1], ENT_QUOTES, 'UTF-8') : '';
				$html = '<blockquote class="twitter-tweet"><a href="' . htmlspecialchars($tweeturl, ENT_QUOTES, 'UTF-8') . '">' . $fallback_text . '</a></blockquote>';
			}
		} else {
			// 通信エラー時はフォールバック
			$fallback_text = isset($tw[1]) ? htmlspecialchars($tw[1], ENT_QUOTES, 'UTF-8') : '';
			$html = '<blockquote class="twitter-tweet"><a href="' . htmlspecialchars($tweeturl, ENT_QUOTES, 'UTF-8') . '">' . $fallback_text . '</a></blockquote>';
		}
	}

	// widgets.jsの読み込み処理
	if (PLUGIN_TWEET_LAZYLOAD) {
		$script = '<script>!function(s){var f=0,l=function(){f||(f=1,document.body.appendChild(Object.assign(document.createElement("script"),{src:s})),window.removeEventListener("scroll",l),window.removeEventListener("mousemove",l))};window.addEventListener("scroll",l),window.addEventListener("mousemove",l)}("' . PLUGIN_TWEET_JSURL . '");</script>';
	} else {
		$script = '<script async src="' . PLUGIN_TWEET_JSURL . '" charset="utf-8"></script>';
	}
	if (!in_array($script, $head_tags, true)) {
		$head_tags[] = $script;
	}

	if( in_array('noimg', $tw)){
		$twjs4 = 'class="twitter-tweet"';
		$twjs5 = 'class="twitter-tweet" data-cards="hidden"'; //画像非表示
		$html = str_replace($twjs4, $twjs5, $html);
	}

	if( in_array('noconv', $tw)){
		$twjs6 = 'class="twitter-tweet"';
		$twjs7 = 'class="twitter-tweet" data-conversation="none"'; //リプライを非表示
		$html = str_replace($twjs6, $twjs7, $html);
	}
	return <<<EOD
	$html
	EOD;
}
?>
