/*--------------------------------
フッターを最下部に
-------------------------------*/

new function(){
    'use strict';
    
     var footerId = "footer";
     var fixedClass = 'fixed-footer';
     //メイン
     function footerFixed(){
          //ドキュメントの高さ
          var dh = document.getElementsByTagName("body")[0].clientHeight;
          //フッターのtopからの位置
          document.getElementById(footerId).style.top = "0px";
          var ft = document.getElementById(footerId).offsetTop;
          //フッターの高さ
          var fh = document.getElementById(footerId).offsetHeight;
          //ウィンドウの高さ
          var wh;
          if (window.innerHeight){
               wh = window.innerHeight;
          }else if(document.documentElement && document.documentElement.clientHeight !== 0){
               wh = document.documentElement.clientHeight;
          }
          if(ft+fh<wh){
               document.getElementById(footerId).style.position = "relative";
               document.getElementById(footerId).style.top = (wh-fh-ft-1)+"px";

               if (document.body.classList) {
                   document.body.classList.add(fixedClass);
               } else {
                   document.body.className += ' ' + fixedClass;
               }
          } else {
              if (document.body.classList) {
                  document.body.classList.remove(fixedClass);
              } else {
                  document.body.className = document.body.className.replace(
                      new RegExp('(^|\\b)' + fixedClass + '(\\b|$)', 'gi'),
                      ' '
                  );
              }
          }
     }
    
     //文字サイズ
     function checkFontSize(func){
    
          //判定要素の追加    
          var e = document.createElement("div");
          var s = document.createTextNode("S");
          e.appendChild(s);
          e.style.visibility="hidden"
          e.style.position="absolute"
          e.style.top="0"
          document.body.appendChild(e);
          var defHeight = e.offsetHeight;
         
          //判定関数
          function checkBoxSize(){
               if(defHeight !== e.offsetHeight){
                    func();
                    defHeight= e.offsetHeight;
               }
          }
          setInterval(checkBoxSize,1000)
     }
    
     //イベントリスナー
     function addEvent(elm,listener,fn){
          try{
               elm.addEventListener(listener,fn,false);
          }catch(e){
               elm.attachEvent("on"+listener,fn);
          }
     }

     addEvent(window,"load",footerFixed);
     addEvent(window,"load",function(){
          checkFontSize(footerFixed);
     });
     addEvent(window,"resize",footerFixed);
    
}

/*-------------
ページスクロール
-----------*/

(function (window, document, $, undefined) {
    'use strict';

    $(function() {
        var pageTop = $('#page-top');
        pageTop.hide();
        //スクロールが400に達したら表示
        $(window).on('scroll', function () {
            if($(this).scrollTop() > 400) {
                pageTop.fadeIn();
            } else {
                pageTop.fadeOut();
            }
        });
        //スクロールしてトップ
            pageTop.on('click', function () {
            $('body,html').animate({
                scrollTop: 0
            }, 800);
            return false;
            });
      });
}(window, window.document, jQuery));

/*---------------------------
アコーディオン
------------------------------*/
;(function (window, $, ST, undefined) {
    'use strict';

    /**
     * transition event
     */
    var transitionEvent = (function detectTransitionEvent() {
        var element     = document.createElement('div');
        var transitions = {
            'transition'      : 'transitionend',
            'OTransition'     : 'oTransitionEnd',
            'MozTransition'   : 'transitionend',
            'WebkitTransition': 'webkitTransitionEnd'
        };

        for (var property in transitions) {
            if (!Object.prototype.hasOwnProperty.call(transitions, property)) {
                continue;
            }

            if (element.style[property] !== undefined) {
                return transitions[property];
            }
        }

        return 'transitionend';
    }());

    /**
     * スマホかどうかを返す
     */
    function isMobile() {
        return (ST.is_mobile === '1');
    }

    /**
     * メニュー項目 (ホバーで開閉) のイベントリスナーファクトリ
     */
    function createHoverableMenuItemListener() {
        return {
            /**
             * メニュークリック時
             *
             * @this {MenuItem}
             */
            click     : function (event) {
                event.preventDefault();
                event.stopPropagation();

                if (this.isClickable()) {
                    if (isMobile()) {
                        this.getRoot().data('st-menu').collapseChildren(false);
                    }

                    window.location.href = this.getLink().attr('href');

                    return;
                }

                this.toggle();
            },
            /**
             * マウスエンター時
             *
             * @this {MenuItem}
             */
            mouseenter: function () {
                if (isMobile()) {
                    return;
                }

                this.expand();
            },
            /**
             * マウスリーブ時
             *
             * @this {MenuItem}
             */
            mouseleave: function () {
                if (isMobile()) {
                    return;
                }

                this.collapse();
            }
        };
    }

    /**
     * メニュー項目 (クリックでリンク) のイベントリスナーファクトリ
     */
    function createClickableMenuItemListener() {
        return {
            /**
             * メニュークリック時
             *
             * @this {MenuItem}
             */
            click     : function (event) {
                event.preventDefault();
                event.stopPropagation();

                if (this.isClickable()) {
                    window.location.href = this.getLink().attr('href');

                    return;
                }

                this.toggle();
            },
            /**
             * マウスエンター時
             *
             * @this {MenuItem}
             */
            mouseenter: function () {
                // Do nothing.
            },
            /**
             * マウスリーブ時
             *
             * @this {MenuItem}
             */
            mouseleave: function () {
                // Do nothing.
            }
        };
    }

    var MenuItem = (function () {
        /**
         * メニュー項目オブジェクト
         */
        function MenuItem($element, options) {
            if (!(this instanceof MenuItem)) {
                return new MenuItem($element);
            }

            var defaults = {
                listener : {
                    click     : function () {
                    },
                    mouseenter: function () {
                    },
                    mouseleave: function () {
                    }
                },
                expanded : false,
                hoverable: false,
                link     : null,
                children : null,
                icon     : false
            };

            this.$element = $element;
            this.options  = $.extend({}, defaults, options);

            if (!this.options.link) {
                this.options.link = function () {
                    return this.$element.find('> a');
                };
            }

            if (!this.options.children) {
                this.options.children = function () {
                    return this.$element.find('> ul');
                };
            }

            this.expanded = this.options.expanded;
            this.$icon    = null;
        }

        /**
         * メニュー項目要素を取得
         */
        MenuItem.prototype.getElement = function getElement() {
            return this.$element;
        };

        /**
         * 親メニューがあるかどうかを返す
         */
        MenuItem.prototype.hasParent = function hasParent() {
            var hasParent = false;

            this.$element.parents().each(function () {    // eslint-disable-line consistent-return
                if (typeof $(this).data('st-menu-item') !== 'undefined') {
                    return false;
                }
            });

            return hasParent;
        };

        /**
         * ルートメニューを取得
         */
        MenuItem.prototype.getRoot = function getRoot() {
            var $root = this.$element;

            this.$element.parents().each(function () {
                var $this = $(this);

                if (typeof $this.data('st-menu-item') !== 'undefined') {
                    $root = $this;
                }
            });

            return $root;
        };

        /**
         * アイコン要素を取得
         */
        MenuItem.prototype.getIcon = function getIcon() {
            this.$icon = this.$icon || $('<i></i>').appendTo(this.getLink());

            return this.$icon;
        };

        /**
         * リンク要素を取得
         */
        MenuItem.prototype.getLink = function getLink() {
            if (typeof this.options.link === 'function') {
                return this.options.link.bind(this)();
            } else {
                return $(this.options.link).eq(0);
            }
        };

        /**
         * 子メニューがあるかどうかを返す
         */
        MenuItem.prototype.hasChildren = function hasChildren() {
            if (typeof this.options.children === 'function') {
                return this.options.children.bind(this)().length;
            } else {
                return $(this.options.children).length;
            }
        };

        /**
         * 展開済みかどうかを返す
         */
        MenuItem.prototype.isExpanded = function isExpanded() {
            return this.expanded;
        };

        /**
         * クリック可能かどうかを返す
         *
         * 子メニューがある場合は、展開済みの場合のみクリック可
         */
        MenuItem.prototype.isClickable = function isClckable() {
            if (!this.getLink().length) {
                return false;
            }

            return (!this.hasChildren() || (this.hasChildren() && this.isExpanded()));
        };

        /**
         * 初期化
         */
        MenuItem.prototype.initialize = function initialzie() {
            if (this.options.icon) {
                this.$icon = $('<i></i>');

                this.getLink().append(this.$icon);
            }

            this.refresh(false);
            this.addEventListener();
        };

        /**
         * 表示を更新
         */
        MenuItem.prototype.refresh = function refresh(animation) {
            animation = (typeof animation !== 'undefined') ? animation : true;

            if (this.expanded || !this.hasChildren()) {
                this.expand(animation);
            } else {
                this.collapse(animation);
            }
        };

        /**
         * メニューを展開
         */
        MenuItem.prototype.expand = function expand(animation) {
            var self   = this;
            var $child = this.$element.find('> ul');

            this.expanded = true;

            animation = (typeof animation !== 'undefined') ? animation : true;

            if (animation && $child.length) {
                $child.animate({
                        marginTop    : 'show',
                        marginBottom : 'show',
                        paddingTop   : 'show',
                        paddingBottom: 'show',
                        height       : 'show'
                    },
                    'fast',
                    function () {
                        if (self.options.icon) {
                            self.getIcon().attr({'class': 'menu-item-icon st-fa st-svg-angle-right'});
                        }

                        self.$element.removeClass('menu-item-collapsed').addClass('menu-item-expanded');
                    }
                );
            } else {
                $child.show();

                if (self.options.icon) {
                    this.getIcon().attr({'class': 'menu-item-icon st-fa st-svg-angle-right'});
                }

                this.$element.removeClass('menu-item-collapsed').addClass('menu-item-expanded');
            }
        };

        /**
         * メニューを折り畳む
         */
        MenuItem.prototype.collapse = function collapse(animation) {
            var self   = this;
            var $child = this.$element.find('> ul');

            this.expanded = false;

            animation = (typeof animation !== 'undefined') ? animation : true;

            if (animation && $child.length) {
                $child.slideUp('fast', function () {
                    if (self.options.icon) {
                        self.getIcon().attr({'class': 'menu-item-icon st-fa st-svg-angle-down'});
                    }

                    self.$element.removeClass('menu-item-expanded').addClass('menu-item-collapsed');
                });
            } else {
                $child.hide();

                if ($child.length && self.options.icon) {
                    this.getIcon().attr({'class': 'menu-item-icon st-fa st-svg-angle-down'});
                }

                this.$element.removeClass('menu-item-expanded').addClass('menu-item-collapsed');
            }
        };

        /**
         * メニューをトグル
         */
        MenuItem.prototype.toggle = function toggle() {
            if (!this.hasChildren()) {
                return;
            }

            this.expanded ? this.collapse() : this.expand();
        };

        /**
         * イベントリスナーを登録
         */
        MenuItem.prototype.addEventListener = function addEventListener() {
            this.getLink().on('click', $.proxy(this.options.listener.click, this));

            if (this.options.hoverable) {
                this.$element.on({
                    mouseenter: $.proxy(this.options.listener.mouseenter, this),
                    mouseleave: $.proxy(this.options.listener.mouseleave, this)
                });
            }
        };

        return MenuItem;
    }());

    var Menu = (function () {
        /**
         * メニューオブジェクト
         */
        function Menu($element, options) {
            if (!(this instanceof Menu)) {
                return new Menu();
            }

            var defaults = {
                expanded : false,
                hoverable: false,
                link     : null,
                children : null,
                icon     : false
            };

            this.$element = $element;
            this.options  = $.extend({}, defaults, options);
        }

        /**
         * 初期化
         */
        Menu.prototype.initialize = function initialize() {
            var self = this;

            this.getMenuItems().each(function () {
                var $this    = $(this);
                var listener = self.options.hoverable ? createHoverableMenuItemListener() : createClickableMenuItemListener();

                var options = {
                    listener : listener,
                    expanded : self.options.expanded,
                    hoverable: self.options.hoverable,
                    link     : self.options.link,
                    children : self.options.children,
                    icon     : self.options.icon
                };

                var menuItem = new MenuItem($this, options);

                $this.data('st-menu', self);
                $this.data('st-menu-item', menuItem);

                menuItem.initialize();
            });
        };

        /**
         * メニュー項目要素を取得
         */
        Menu.prototype.getMenuItems = function getMenuItems() {
            return this.$element.find('li');
        };

        /**
         * 全てのメニュー項目を折り畳む
         */
        Menu.prototype.collapseChildren = function collapseChildren(animation) {
            this.getMenuItems().each(function () {
                $(this).data('st-menu-item').collapse(animation);
            })
        };

        /**
         * 全てのメニュー項目を開く
         */
        Menu.prototype.expandChildren = function collapseChildren(animation) {
            this.getMenuItems().each(function () {
                $(this).data('st-menu-item').expand(animation);
            })
        };

        return Menu;
    }());

    var Navigation = (function () {
        /**
         * ナビゲーション
         */
        function Navigation($navigation, $toggle, $container, options) {
            var defaults = {
                $navigationContent      : $navigation,
                activeClass             : 's-navi-active',
                activatedClass          : 's-navi-activated',
                waitTransitionendOnOpen : false,
                waitTransitionendOnClose: false
            };

            this.$navigation = $navigation;
            this.$toggle     = $toggle;
            this.$container  = $container;
            this.$overlay    = null;
            this.options     = $.extend({}, defaults, options);
            this.opened      = false;
            this.scrollTop   = 0;
        }

        /**
         * 開いているかどうかを返す
         */
        Navigation.prototype.isOpened = function isOpened() {
            return this.opened;
        };

        /**
         * 開く
         */
        Navigation.prototype.open = function open() {
            var self = this;
            var event;
            var activationCallback;

            if (self.opened) {
                return;
            }

            self.scrollTop = $(window).scrollTop();

            event = $.Event('open.stnavigation');

            self.$navigation.trigger(event);

            activationCallback = function () {
                var height = (window.innerHeight === document.body.offsetHeight) ? window.innerHeight : document.body.offsetHeight;

                height -= self.$navigation.outerHeight() - self.$navigation.height();

                setTimeout(function () {
                    self.options.$navigationContent.css({height: height + 'px'});
                    $('html').addClass(self.options.activatedClass);

                    self.opened = true;
                }, 250);
            };

            if (window.innerHeight) {
                if (self.options.waitTransitionendOnOpen) {
                    self.$navigation.one(transitionEvent, activationCallback);
                } else {
                    activationCallback();
                }
            }

            self.$toggle.addClass('active');
            $('html').addClass(self.options.activeClass);

            self.$container.css({top: '-' + self.scrollTop + 'px'});

            self.options.$navigationContent.scrollTop(0);
        };

        /**
         * 閉じる
         */
        Navigation.prototype.close = function close() {
            var self = this;
            var deactivationCallback;

            if (!self.opened) {
                return;
            }

            deactivationCallback = function () {
                var $html = $('html');

                $html.removeClass(self.options.activatedClass);
                $html.removeClass(self.options.activeClass);
                self.$toggle.removeClass('active');

                self.options.$navigationContent.css({height: ''});
                self.$container.css({top: ''});

                $(window).scrollTop(self.scrollTop);

                self.opened = false;
            };

            if (self.options.waitTransitionendOnClose) {
                self.$navigation.one(transitionEvent, deactivationCallback);
            } else {
                deactivationCallback();
            }

            $('html').removeClass(self.options.activatedClass);
        };

        /**
         * 更新
         */
        Navigation.prototype.refresh = function refresh() {
            var self = this;
            var height;

            if (!self.opened) {
                return;
            }

            if (window.matchMedia && window.matchMedia('screen and (min-width: 960px)').matches) {
                self.close();

                return;
            }

            height = (window.innerHeight === document.body.offsetHeight) ? window.innerHeight : document.body.offsetHeight;

            height -= self.$navigation.outerHeight() - self.$navigation.height();

            self.options.$navigationContent.css({height: height + 'px'});
        };

        /**
         * イベントリスナーを登録
         */
        Navigation.prototype.addEventListeners = function addEventListeners() {
            var self = this;
            var resizeTimer;
            var scrollTimer;

            this.$toggle.on('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                if (self.opened) {
                    self.close();
                } else {
                    self.open();
                }
            });

            if (self.options.overlay) {
                this.$overlay.on('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    if (!self.opened) {
                        return;
                    }

                    self.close();
                });
            }

            $(window).on('orientationchange resize', function (event) {
                if (resizeTimer) {
                    clearTimeout(resizeTimer);
                }

                resizeTimer = setTimeout(function () {
                    self.refresh();
                }, 100);
            });

            self.options.$navigationContent.on('touchstart', function (event) {
                if (scrollTimer) {
                    clearTimeout(scrollTimer);
                }

                scrollTimer = setTimeout(function () {
                    self.refresh();
                }, 100);
            });
        };

        /**
         * 初期化
         */
        Navigation.prototype.initialize = function initialize() {
            var self = this;
            var event;

            if (self.options.overlay && self.$overlay === null) {
                var $overlay = $('#' + self.options.overlayId);

                if ($overlay.length) {
                    self.$overlay = $overlay;
                } else {
                    self.$overlay = $('<div></div>', {id: self.options.overlayId});

                    self.$container.append(self.$overlay);
                }
            }

            event = $.Event('initialize.stnavigation');

            self.$navigation.trigger(event, {'navigation': self});

            this.addEventListeners();
        };

        return Navigation;
    }());

    var SideMenu = (function () {
        /**
         * サイドメニュー
         */
        function SideMenu($menu, $container, options) {
            var defaults = {
                expanded : false,
                hoverable: true,
                link     : null,
                children : null,
                icon     : false
            };

            this.$menu   = $menu;
            this.options = $.extend({}, defaults, options);
        }

        /**
         * 初期化
         */
        SideMenu.prototype.initialize = function initialize() {
            var self = this;

            this.$menu.each(function () {
                var $this = $(this);

                var options = {
                    expanded : self.options.expanded,
                    hoverable: self.options.hoverable,
                    link     : self.options.link,
                    children : self.options.children,
                    icon     : self.options.icon
                };

                var menu = new Menu($this, options);

                $this.data('st-menu', menu);

                menu.initialize();
            });
        };

        return SideMenu;
    }());

    /**
     * ナビゲーションを初期化
     */
    function initializeNavigation() {
        var $navigation        = $('.acordion_tree'); // ナビゲーション要素
        var $navigationContent = $('.acordion_tree .acordion_tree_content'); // ナビゲーションコンテンツ要素
        var $trigger           = $('.op-menu'); // メニューボタン要素
        var $container         = $('#st-ami'); // コンテナー要素
        var $menu              = $('.acordion_tree ul.menu'); // メニュー要素

        var navigationOptions = {
            $navigationContent      : $navigationContent,
            waitTransitionendOnClose: false,
            overlay                 : true,
            overlayId               : 's-navi-overlay'
        };

        var menuOptions = {
            expanded          : (ST.expand_accordion_menu === '1'),
            $navigationContent: $navigation,
            hoverable         : false,
            link              : null,
            children          : null,
            icon              : true
        };

        $navigation.on('initialize.stnavigation', function (event, data) {
            var navigation = data.navigation;

            $menu.each(function () {
                var $this = $(this);

                var menu = new Menu($this, menuOptions);

                $this.data('st-menu', menu);

                menu.initialize();
            });
        });

        $navigation.on('open.stnavigation', function (event, data) {
            if (menuOptions.expanded) {
                $menu.each(function () {
                    var menu = $(this).data('st-menu');

                    menu.expandChildren(false);
                });
            } else {
                $menu.each(function () {
                    var menu = $(this).data('st-menu');

                    menu.collapseChildren(false);
                });
            }
        });

        var navigation = new Navigation($navigation, $trigger, $container, navigationOptions);

        navigation.initialize();
    }

    /**
     * サイドメニューを初期化
     */
    function initializeSideMenu() {
        var $menu = $('#side aside .st-pagelists ul'); // メニュー要素

        var options = {
            hoverable: isMobile(),
        };

        var sideMenu = new SideMenu($menu, options);

        sideMenu.initialize();
    }

    /**
     * 検索ナビゲーションを初期化
     */
    function initializeSearchNavigation() {
        var $navigation        = $('.acordion_search'); // ナビゲーション要素
        var $navigationContent = $('.acordion_search .acordion_search_content'); // ナビゲーションコンテンツ要素
        var $trigger           = $('.op-search'); // メニューボタン要素
        var $container         = $('#st-ami'); // コンテナー要素
        var isOverlay          = $('html').hasClass('s-navi-search-overlay');
        var overlayContainer   = !isOverlay;

        var options = {
            $navigationContent      : $navigationContent,
            activeClass             : 's-navi-search-active',
            activatedClass          : 's-navi-search-activated',
            waitTransitionendOnClose: isOverlay,
            overlay                 : overlayContainer,
            overlayId               : 's-navi-overlay'
        };

        var navigation = new Navigation($navigation, $trigger, $container, options);

        navigation.initialize();
    }

    /**
     * DOM Ready 時
     */
    function onReady() {
        initializeNavigation();

        if (ST.sidemenu_accordion === '1') {
            initializeSideMenu();
        }

        initializeSearchNavigation();
    }

    $(onReady);
}(window, jQuery, ST));

/*---------------------------
その他
------------------------------*/
(function (window, document, $, undefined) {
    'use strict';

    $(function () {
        /* 固定ページのメニュー */
        $("ul.menu li").filter(function () {
            return !$(this).closest('.acordion').length;
        }).on({
            mouseenter: function () {
                $(">ul:not(:animated)", this).slideDown("fast");
            },
            mouseleave: function () {
                $(">ul", this).slideUp("fast");
            }
        });

        /* マイボタンから空のaタグを削除 */
        $(".st-mybtn a:empty").remove();
    });
}(window, window.document, jQuery));

/*---------------------------
 スライドショー
 ------------------------------*/
;(function (window, document, $, undefined) {
    'use strict';

    if (typeof $.fn.slick === 'undefined') {
        return;
    }

    function update(slick) {
        var $slider = $(slick.$slider);

        $slider.removeClass(function (index, className) {
            return (className.match(/\bslick-slides-to-show-\S+/g) || []).join(' ');
        })
            .addClass('slick-slides-to-show-' + slick.options.slidesToShow);

        if (Math.ceil(slick.slideCount / slick.options.slidesToScroll) > 10 ||
            slick.slideCount <= slick.options.slidesToShow) {
            $slider.removeClass('slick-dotted');
        }
    }

    $(function () {
        $('[data-slick]')
            .on('init', function (event, slick) {
                update(slick);
            })
            .on('breakpoint', function (event, slick, breakpoint) {
                update(slick);
            })
            .slick();
    });
}(window, window.document, jQuery));

/*---------------------------
 アイコン追加
 ------------------------------*/
(function (window, document, $, undefined) {
    'use strict';

    $(function(){
        'use strict';

        $('p.hatenamark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-question-circle"></i>');
        });
        $('p.checkmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-check-circle"></i>');
        });
        $('.check-ul li').each(function(){
            $(this).prepend('<i class="st-fa st-svg-check-circle"></i>');
        });
        $('p.attentionmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-exclamation-triangle"></i>');
        });
        $('p.memomark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-pencil-square-o"></i>');
        });
        $('p.usermark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-user" aria-hidden="true"></i>');
        });


        $('h2.hatenamark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-question-circle"></i>');
        });
        $('h2.checkmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-check-circle"></i>');
        });
        $('h2.attentionmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-exclamation-triangle"></i>');
        });
        $('h2.memomark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-pencil-square-o"></i>');
        });
        $('h2.usermark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-user" aria-hidden="true"></i>');
        });


        $('h3.hatenamark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-question-circle"></i>');
        });
        $('h3.checkmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-check-circle"></i>');
        });
        $('h3.attentionmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-exclamation-triangle"></i>');
        });
        $('h3.memomark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-pencil-square-o"></i>');
        });
        $('h3.usermark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-user" aria-hidden="true"></i>');
        });


        $('h4.hatenamark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-question-circle"></i>');
        });
        $('h4.checkmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-check-circle"></i>');
        });
        $('h4.attentionmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-exclamation-triangle"></i>');
        });
        $('h4.memomark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-pencil-square-o"></i>');
        });
        $('h4.usermark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-user" aria-hidden="true"></i>');
        });


        $('h5.hatenamark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-question-circle"></i>');
        });
        $('h5.checkmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-check-circle"></i>');
        });
        $('h5.attentionmark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-exclamation-triangle"></i>');
        });
        $('h5.memomark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-pencil-square-o"></i>');
        });
        $('h5.usermark').each(function(){
            $(this).prepend('<i class="st-fa st-svg-user" aria-hidden="true"></i>');
        });

    });
}(window, window.document, jQuery));

/*---------------------------
 スマホのフッターメニュー
 ------------------------------*/
(function (window, document, $, undefined) {
    'use strict';

    $(function() {
        'use strict';

        var topBtn = $('#st-footermenubox');
        topBtn.css('bottom', '-100px');
        var showFlag = false;
        $(window).on('scroll', function () {
            if ($(this).scrollTop() > 100) {
                if (!showFlag) {
                    showFlag = true;
                    topBtn.stop().animate({'bottom' : '0px'}, 200);
                }
            } else {
                if (showFlag) {
                    showFlag = false;
                    topBtn.stop().animate({'bottom' : '-100px'}, 200);
                }
            }
        });

    });
}(window, window.document, jQuery));

/*---------------------------
 [st-back-btn]
 ------------------------------*/
(function (window, document, $, undefined) {
    'use strict';

    var ST_BACK_BTN_KEY = 'stBackBtnHistory';

    function history_get() {
        var history = window.sessionStorage.getItem(ST_BACK_BTN_KEY);

        if (history) {
            return JSON.parse(history);
        }

        return [];
    }

    function history_push(url) {
        var history = history_get();

        if (history.length >= 1 && history[history.length - 1] === url) {
            return;
        }

        history.push(url);
        history.slice(0, 2);

        window.sessionStorage.setItem(ST_BACK_BTN_KEY, JSON.stringify(history));
    }

    function history_back() {
        var history = history_get();
        var url;

        if (history.length <= 1) {
            return;
        }

        url = history[history.length - 2];

        window.history.pushState(null, null, url);

        window.location.href = url;
    }

    function on_ready() {
        var history = window.sessionStorage.getItem(ST_BACK_BTN_KEY) || [];

        history_push(window.location.href);

        if (history.length <= 1) {
            return;
        }

        $('[data-st-back-btn]').each(function (index, element) {
            var $element = $(element);
            var html     = $element.html();
            var $button  = $(html);

            $element.replaceWith($button);
        });
    }

    $(on_ready);

    window.st_back_btn_back = history_back;
}(window, window.document, jQuery));

/*---------------------------
 .entry-contentにコンテンツがない場合
 ------------------------------*/
(function (window, document, $, undefined) {
    'use strict';

    $(function () {
        var $content = $('.entry-content');

        if ($content.text().trim() === '') {
            $('body').addClass('is-content-empty');
        }
    });
}(window, window.document, jQuery));

/*---------------------------
サイドメニューの枠線調整
 ------------------------------*/
(function (window, document, $, undefined) {
    'use strict';

    $(function () {
        $('.st-pagelists a').last().css({
            'border-bottom': 'none'
        });
        $('.st-pagelists li li').last().css({
            'border-bottom': 'none'
        });
    });
}(window, window.document, jQuery));

/*---------------------------
カラムブロック横スクロール用のdivクラス
 ------------------------------*/
(function (window, document, $, undefined) {
    'use strict';

    $(function () {
        $('.wp-block-columns.scroll-x-80').each(function(){
            $(this).wrap('<div class="scroll-x-80-wrapper"></div>');
        });
    });
}(window, window.document, jQuery));

/*---------------------------
FAQブロックのおりたたみ
 ------------------------------*/
 document.addEventListener('DOMContentLoaded', function() {
    // 'st-toggle-visibility' クラスを含む dl 要素内のすべての dt 要素を選択
    const faqContainers = document.querySelectorAll('.st-toggle-visibility');

    faqContainers.forEach(container => {
        const faqQuestions = container.querySelectorAll('.wp-block-st-blocks-faq-question');

        faqQuestions.forEach(function(question) {
            question.addEventListener('click', function() {
                const answer = this.nextElementSibling; // dt の直後の dd を取得
                if (answer.style.display === 'none' || answer.style.display === '') {
                    answer.style.display = 'flex';
                    this.classList.add('active'); // active クラスを追加
                } else {
                    answer.style.display = 'none';
                    this.classList.remove('active'); // active クラスを削除
                }
            });
        });
    });
});

/*---------------------------
リストスタイル（タイムライン）の調整
 ------------------------------*/
document.addEventListener("DOMContentLoaded", function () {
  const elements = document.querySelectorAll(
    '.wp-block-list.has-st-border, .wp-block-list.has-background'
  );

  elements.forEach(el => {
    const style = window.getComputedStyle(el);
    const paddingLeft = style.getPropertyValue('padding-left');
    el.style.setProperty('--timeline-left', paddingLeft);
  });
});
