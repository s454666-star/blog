/*--------------------------------
 広告のフロート
 -------------------------------*/
;(function ($) {
	'use strict';

	$(function () {
		var $main = $('main'); // メインカラムのID
		var $side = $('#side aside'); // サイドバーのID
		var $sticky = $('#scrollad'); // 広告を包む要素のID

		if ($main.length === 0 || $side.length === 0 || $sticky.length === 0) {
			return;
		}

		var $window = $(window);

		var measureTrigger = function () {
			var $measure;
			var top;
			var measurement;

			$measure = $('<div></div>').css({
				visibility  : 'hidden'
			});

			$sticky.before($measure);

			top = $measure.offset().top;

			measurement = {
				top   : top,
				bottom: top + $sticky.outerHeight()
			};

			$measure.remove();

			return measurement;
		};

		var isStickable = function () {
			// 960px以上は固定
			if (window.matchMedia && window.matchMedia('screen and (min-width: 960px)').matches) {
				return true;
			}

			return false;
		};

		var stick = function (top) {
			var sideWidth = $side.outerWidth();
			var sideLeft = $side.offset().left;

			top = top || 0;

			$sticky.css({
				position: 'fixed',
				width: sideWidth + 'px',
				top: top + 'px',
				left: sideLeft + 'px',
				margin: '0'
			});

			$side.addClass('is-fixed');
		};

		var unstick = function () {
			$sticky.css({
				position: 'static',
				width: '',
				top: '',
				left: '',
				margin: ''
			});

			$side.removeClass('is-fixed');
		};

		var adjust = function () {
			var currentTop = $window.scrollTop();
			var trigger = measureTrigger();
			var mainBottom= $main.offset().top + $main.outerHeight();
			var isStuck = (currentTop > trigger.top) && (trigger.bottom < mainBottom);
			var bottomTrigger;
			var stickyTop;

			if (isStickable() && isStuck) {
				bottomTrigger = mainBottom - $sticky.outerHeight();
				stickyTop = (currentTop < bottomTrigger) ? 0 : (bottomTrigger - currentTop);

				stick(stickyTop);
			} else {
				unstick();
			}
		};

		$window.on('load', function() {
			adjust();

			setTimeout(function() {
				adjust();
			}, 1000);    // 読み込み完了後の調整
		});

		$window.on('scroll', function () {
			adjust();
		});

		$window.on('resize', function() {
			adjust();
		});
	});
})(jQuery);
