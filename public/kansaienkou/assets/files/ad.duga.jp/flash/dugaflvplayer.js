
/**************************************************************
 *  ドメイン設定
 **************************************************************/
var strClickDomain = 'click.duga.jp';
var strAdDomain    = 'ad.duga.jp';
var strPicDomain   = 'pic.duga.jp';
var strFlvDomain   = 'flv.duga.jp';
var GA_MEASUREMENT_ID = "UA-33035204-4";

// var strClickDomain = 'click.dev.jse';
// var strAdDomain    = 'ad.dev.jse';
// var strPicDomain   = 'vtl-pic.dev.jse';
// var strFlvDomain   = 'vtl-flv.dev.jse';
// var GA_MEASUREMENT_ID = "UA-134544554-1"; // 開発用

/**************************************************************
 *  Cookie関連
 **************************************************************/
Cookie = function () {
	this.Cookies = new Object();
	var objData = document.cookie.split(/;[ ]*/);
	for (i in objData) {
		if (typeof(objData[i]) != 'string') { continue; }
		var strTemp = objData[i].split('=');
		this.Cookies[unescape(strTemp[0])] = unescape(strTemp[1]);
	}
}
Cookie.prototype.GetCookie = function (strName) {
	return this.Cookies[strName];
}
Cookie.prototype.SetCookie = function (strName, strValue, intExpSec, strPath) {
	if (!strName) { return; }
	var strCookieVar = escape(strName) + '=' + escape(strValue) + ';';
	if (intExpSec) {
		objDate = new Date();
		objDate.setTime(objDate.getTime() + (intExpSec * 1000));
		strCookieVar += "expires=" + objDate.toGMTString() + ';';
	}
	if (strPath) {
		strCookieVar += 'path=' + strPath + ';';
	}
	document.cookie = strCookieVar;
	return;
}

// 開発モード判定
var strDevMode = 0;
var objCookie = new Cookie();
var strBrowserMode = objCookie.GetCookie('BROWSERMODE');
if (strBrowserMode) {
	var strTemp = strBrowserMode.split(',');
	strDevMode = strTemp[0];
}

/**************************************************************
 *  その他の関数
 **************************************************************/
function loadScript(src, callback, charset) {
    var done = false;
    var head = document.getElementsByTagName('head')[0];
    var script = document.createElement('script');
	script.type = 'text/javascript';
	if (charset) { script.charset = charset; }
    script.src = src;
    head.appendChild(script);
    // Attach handlers for all browsers
    script.onload = script.onreadystatechange = function() {
        if ( !done && (!this.readyState ||
                this.readyState === "loaded" || this.readyState === "complete") ) {
            done = true;
            if (callback) { callback(); }
            // Handle memory leak in IE
            script.onload = script.onreadystatechange = null;
            if ( head && script.parentNode ) {
                head.removeChild( script );
            }
        }
    };
}

function getClientCareer() {
	var strClientCareer = 'pc';
	if (navigator.userAgent.match(/(iPhone|iPad|iPod|iPod touch);/i)) {
		if (RegExp.$1 == 'iPad') {
			strClientCareer = 'ipad';
		} else {
			strClientCareer = 'iphone';
		}
	}
	if (navigator.userAgent.match(/(Android) [0-9\.]+;|(Android)DownloadManager| (Silk)\/|(googlebot-mobile)/i)) {
		if (RegExp.$1 == 'Silk' || navigator.userAgent.match(/ SC-01C /) || !navigator.userAgent.match(/ Mobile /)) {
			strClientCareer = 'android-tablet';
		} else {
			strClientCareer = 'android';
		}
	}
	return strClientCareer;
}

function addDOMContentLoadedEvent(func) {
	// フレームが存在するページの対処
	if (parent.frmbtm) {
		window.addEventListener("load", func);
		return;
	}

	if (navigator.userAgent.indexOf("MSIE") != -1) {
		IEContentLoaded(window, func);
	} else {
		document.addEventListener("DOMContentLoaded", func, false);
	}
}

function IEContentLoaded (w, fn) {
	var d = w.document, done = false,
	// only fire once
	init = function () {
		if (!done) {
			done = true;
			fn();
		}
	};
	// polling for no errors
	(function () {
		try {
			// throws errors until after ondocumentready
			d.documentElement.doScroll('left');
		} catch (e) {
			setTimeout(arguments.callee, 50);
			return;
		}
		// no errors, fire
		init();
	})();
	// trying to always fire before onload
	d.onreadystatechange = function() {
		if (d.readyState == 'complete') {
			d.onreadystatechange = null;
			init();
		}
	};
}

/**************************************************************
 *  処理関数
 **************************************************************/
var proto = (("https:" == document.location.protocol) ? "https" : "http");
var AdmovieScript = proto + '://' + strAdDomain + '/js/admovie/admovie-2.2.js';
// if (strDevMode == "1") { AdmovieScript = proto + '://' + strAdDomain + '/js/admovie/admovie-2.0.js'; }

function dugafpw(w, h, fid, url, thumb, aid, bid) {
	dugafpwc(w, h, fid, url, aid, bid);
}
function dugafpwc(w, h, fid, url, aid, bid) {
	// HTML5版
	var strTemp = url.split('/');
	var LabelType = strTemp[3];
	var ProductID = strTemp[4];
	var AgentVars = strTemp[5];

	loadScript(AdmovieScript, function () {
		var objDugaAdMovie = new DugaAdMovie(ProductID, url, h, w, fid, strAdDomain, strPicDomain, strFlvDomain);
		objDugaAdMovie.init();
		console.log("[call] legacy DugaAdMovie " + ProductID);
	}, 'UTF-8');
	
	// ログ記録
	var script = document.createElement('script');
	script.src = proto + "://" + strAdDomain + "/rw/dugaflvplayer.php?agentid=" + aid + "&url=" + encodeURI(url) + "&t=" + parseInt(new Date / 1000);
	var head = document.getElementsByTagName('head');
	if (head) {
		head.item(0).appendChild(script);
	}

	// イベント送信
	google_analytics_send_event(aid, decodeURI("%E5%BA%83%E5%91%8A%E7%B4%A0%E6%9D%90"), decodeURI("%E3%83%AA%E3%82%AF%E3%82%A8%E3%82%B9%E3%83%88"), decodeURI("%E5%8B%95%E7%94%BB%E5%BA%83%E5%91%8A"), "1");
}

// 新仕様のタグ対応②
var result = function() {
	if (typeof duga_o == 'undefined') { return; }
	if (!duga_o) { return; }

	// 変数の設定
	var w2     = duga_w;
	var h2     = duga_h;
	var fid2   = duga_o;
	var aid2   = duga_a;
	var bid2   = duga_b;
	var pid2   = duga_p;
	var ltype2 = duga_l;
	var url2   = proto + '://' + strClickDomain + '/' + ltype2 + '/' + pid2 + '/' + aid2 + '-' + bid2;

	loadScript(AdmovieScript, function () {
		var objDugaAdMovie = new DugaAdMovie(pid2, url2, h2, w2, fid2, strAdDomain, strPicDomain, strFlvDomain);
		objDugaAdMovie.initDirect();
		console.log("[call] new DugaAdMovie " + pid2);	
	}, 'UTF-8');

	// ログ記録
	var script = document.createElement('script');
	script.src = proto + "://" + strAdDomain + "/rw/dugaflvplayer.php?agentid=" + aid2 + "&url=" + encodeURI(url2) + "&t=" + parseInt(new Date / 1000);
	var head = document.getElementsByTagName('head');
	if (head) {
		head.item(0).appendChild(script);
	}

	// イベント送信
	google_analytics_send_event(aid2, decodeURI("%E5%BA%83%E5%91%8A%E7%B4%A0%E6%9D%90"), decodeURI("%E3%83%AA%E3%82%AF%E3%82%A8%E3%82%B9%E3%83%88"), decodeURI("%E5%8B%95%E7%94%BB%E5%BA%83%E5%91%8A"), "1");

	duga_w = "";
	duga_h = "";
	duga_o = "";
	duga_l = "";
	duga_p = "";
	duga_a = "";
	duga_b = "";
}();

// 新仕様のタグ対応①
function initDugaAdMovie() {
	// 遅延ロード
	loadScript(AdmovieScript, function () {
		var objDiv = document.getElementsByTagName("div");
		for(var i = 0; i < objDiv.length; ++i) {
			if (!objDiv[i].getAttribute("data-o")) { continue; }
			if (objDiv[i].getAttribute("parsed") == "1") { continue; }

			// 変数の設定
			var w2 = objDiv[i].getAttribute("data-w");
			var h2 = objDiv[i].getAttribute("data-h");
			var fid2 = objDiv[i].getAttribute("data-o");
			var aid2 = objDiv[i].getAttribute("data-a");
			var bid2 = objDiv[i].getAttribute("data-b");
			var pid2 = objDiv[i].getAttribute("data-p");
			var ltype2 = objDiv[i].getAttribute("data-l");
			var url2 = proto + '://' + strClickDomain + '/' + ltype2 + '/' + pid2 + '/' + aid2 + '-' + bid2;

			var objDugaAdMovie = new DugaAdMovie(pid2, url2, h2, w2, fid2, strAdDomain, strPicDomain, strFlvDomain);
			objDugaAdMovie.initDirect();
			console.log("[call] defer DugaAdMovie " + pid2);

			// ログ記録
			var script = document.createElement('script');
			script.src = proto + "://" + strAdDomain + "/rw/dugaflvplayer.php?agentid=" + aid2 + "&url=" + encodeURI(url2) + "&t=" + parseInt(new Date / 1000);
			var head = document.getElementsByTagName('head');
			if (head) {
				head.item(0).appendChild(script);
			}

			// イベント送信
			google_analytics_send_event(aid2, decodeURI("%E5%BA%83%E5%91%8A%E7%B4%A0%E6%9D%90"), decodeURI("%E3%83%AA%E3%82%AF%E3%82%A8%E3%82%B9%E3%83%88"), decodeURI("%E5%8B%95%E7%94%BB%E5%BA%83%E5%91%8A"), "1");
		}
	}, 'UTF-8');
};
addDOMContentLoadedEvent(initDugaAdMovie);

// Google Analytics
function google_analytics_send_event(uid, category, action, label, value) {
	loadScript("https://www.googletagmanager.com/gtag/js?id=" + GA_MEASUREMENT_ID, function () {
		window.dataLayer = window.dataLayer || [];
		function gtag(){ dataLayer.push(arguments); }
		gtag('js', new Date());
	
		gtag('config', GA_MEASUREMENT_ID, {
			'user_id': uid,
			'send_page_view': false
		});
		  
		gtag('event', action, {
			'event_category': category,
			'event_label': label,
			'value': value
		});
		//console.log("send event");
	}, 'UTF-8');
}