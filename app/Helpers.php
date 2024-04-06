<?php

if (!function_exists('isPreservedView')) {
    function isPreservedView() {
        return request()->is('blog/show-preserved');
    }
}
