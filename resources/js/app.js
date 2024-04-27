require('./bootstrap');
// resources/js/app.js
window.onscroll = function(ev) {
    if ((window.innerHeight + window.pageYOffset) >= document.body.offsetHeight) {
        // 假設你有一個AJAX函數來加載更多圖片
        loadMoreImages();
    }
};

function loadMoreImages() {
    // 使用AJAX向後端請求更多圖片
}
