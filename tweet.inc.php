<?php
// ver1.0 初期バージョン
// ver2.0 JSON取得してキャッシュする。widgets.jsはローカルに置くようにした。
// ver2.1 データ保管方式を .datから.txtに変更、ブラウザのnative loading="lazy"などに対応
// ver2.2 lazysizes.jsなしでの遅延読み込みに対応
// ver2.3 第2引数以降の引数にnoimgまたはnoconvとつけると、それぞれ画像を非表示にしたりリプライツイートのスレッドを非表示にできます。両方を併用することもできます。
// ver2.4 バグの微修正

define('PLUGIN_TWEET_LAZYLOAD', FALSE); // 初回スクロールに反応しての遅延読み込みを有効にするにはTRUEに、使っていないならFALSEに
define('PLUGIN_TWEET_JSURL', 'https://platform.twitter.com/widgets.js'); //デフォルトは https://platform.twitter.com/widgets.js

function plugin_tweet_convert()
{
	global $head_tags;
	$tw = func_get_args();
	preg_match('/[0-9]{9,30}/', $tw[0], $tweetids); //URLでもツイートIDでも投稿できるように
	$tweetid = end($tweetids);
	$tweeturl = 'https://twitter.com/user/status/' . $tweetid ;
	$datcache = CACHE_DIR . 'tweet/' . $tweetid . '.txt';

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
		$json_url = 'https://publish.twitter.com/oembed?maxwidth=550&dnt=true&url='. urlencode($tweeturl);
		$ch = curl_init($json_url);
		curl_setopt_array($ch, $options);
		$json = curl_exec($ch);
		$arr = json_decode($json, true);
		curl_close($ch);

		if ($arr === NULL) {
			//json取得失敗
			$html = '<blockquote class="twitter-tweet"><a href="https://twitter.com/user/status/' . $tweetid . '">' . $tw[1] . '</a></blockquote>';
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