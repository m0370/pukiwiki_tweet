<?php
// ver1.0 初期バージョン
// ver2.0 JSON取得してキャッシュする。widgets.jsはローカルに置くようにした。
// ver2.1 データ保管方式を .datから.txtに変更、ブラウザのnative loading="lazy"などに対応
// ver2.2 lazysizes.jsなしでの遅延読み込みに対応
// ver2.3 第2引数以降の引数にnoimgまたはnoconvとつけると、それぞれ画像を非表示にしたりリプライツイートのスレッドを非表示にできます。両方を併用することもできます。
// ver2.6 埋め込みURLのドメイン変更とツイートID取得処理を改善
// ver2.7 ツイートURL構築の不具合を修正、キャッシュディレクトリ自動作成、エラーハンドリング改善

define('PLUGIN_TWEET_LAZYLOAD', FALSE); // 初回スクロールに反応しての遅延読み込みを有効にするにはTRUEに、使っていないならFALSEに
define('PLUGIN_TWEET_JSURL', 'https://platform.twitter.com/widgets.js'); //デフォルトは https://platform.twitter.com/widgets.js

// 指定された文字列からツイート情報を取得する
function plugin_tweet_parse_input($str)
{
    // URLの場合、ユーザー名とツイートIDを抽出
    if (preg_match('/(?:https?:\/\/)?(?:x\.com|twitter\.com)\/([^\/]+)\/status(?:es)?\/(\d+)/i', $str, $m)) {
        $username = $m[1];
        $tweetid = $m[2];
        return array(
            'id' => $tweetid,
            'url' => 'https://x.com/' . $username . '/status/' . $tweetid,
            'username' => $username
        );
    }
    // ツイートIDのみの場合
    if (preg_match('/^\d+$/', $str)) {
        return array(
            'id' => $str,
            'url' => null,
            'username' => null
        );
    }
    return null;
}

function plugin_tweet_convert()
{
        global $head_tags;
        $tw = func_get_args();

        // ツイート情報を解析
        $tweet_info = plugin_tweet_parse_input($tw[0]);
        if ($tweet_info === null || $tweet_info['id'] === null) {
            return '<p style="color:red;">エラー: 有効なツイートIDまたはURLを指定してください。</p>';
        }

        $tweetid = $tweet_info['id'];
        $tweeturl = $tweet_info['url'];

        // キャッシュディレクトリの確認と作成
        $cache_dir = CACHE_DIR . 'tweet/';
        if (!file_exists($cache_dir)) {
            mkdir($cache_dir, 0777, true);
        }
        $datcache = $cache_dir . $tweetid . '.txt';

	//オプション設定
	$options = array(
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 3 //タイムアウト(秒)
	);

	if(file_exists($datcache)){
		//キャッシュがある場合
		$json = file_get_contents($datcache);
		$arr = json_decode($json, true);
		$html = html_entity_decode($arr['html']);
	} else {
		//キャッシュがない場合
		// ツイートIDのみでURLがない場合はエラー
		if ($tweeturl === null) {
			return '<p style="color:red;">エラー: ツイートIDのみの指定では新規取得できません。完全なURLを指定するか、既存のキャッシュを使用してください。</p>';
		}

		$json_url = 'https://publish.twitter.com/oembed?maxwidth=550&dnt=true&url='. urlencode($tweeturl);
		$ch = curl_init($json_url);
		curl_setopt_array($ch, $options);
		$json = curl_exec($ch);
		$arr = json_decode($json, true);
		curl_close($ch);

		if ($arr === NULL || !isset($arr['html'])) {
			//json取得失敗
			$fallback_text = isset($tw[1]) ? htmlspecialchars($tw[1], ENT_QUOTES, 'UTF-8') : 'ツイートを読み込めませんでした';
			$html = '<blockquote class="twitter-tweet"><a href="' . htmlspecialchars($tweeturl, ENT_QUOTES, 'UTF-8') . '">' . $fallback_text . '</a></blockquote>';
		} else {
			//json取得成功
			$html = html_entity_decode($arr['html']);
			//キャッシュする
			file_put_contents($datcache, $json);
		}
	}

	if (PLUGIN_TWEET_LAZYLOAD) {
		//scriptをlazyloadに置換
		$twjs1 = '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>';
		$twjs2 = '';
		$twjs3 = '<script>function lazyLoadScript(scriptSrc) {var scrollFirstTime = 1;window.addEventListener("scroll", oneTimeFunction, false);window.addEventListener("mousemove", oneTimeFunction, false);function oneTimeFunction() {if (scrollFirstTime === 1) {scrollFirstTime = 0;var adScript = document.createElement("script");adScript.src = scriptSrc;document.body.appendChild(adScript);window.removeEventListener("scroll", oneTimeFunction, false);window.removeEventListener("mousemove", oneTimeFunction, false);}}}lazyLoadScript("' . PLUGIN_TWEET_JSURL . '");</script>';
		$html = str_replace($twjs1, $twjs2, $html);
		if( !in_array($twjs3, $head_tags, true)) {
			$head_tags[] = $twjs3 ;
		}
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