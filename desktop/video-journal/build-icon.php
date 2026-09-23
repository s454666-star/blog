<?php
$image = imagecreatetruecolor(256,256);
$cream = imagecolorallocate($image,246,244,237);
$green = imagecolorallocate($image,43,64,53);
$coral = imagecolorallocate($image,228,105,76);
imagefill($image,0,0,$cream);
imagefilledrectangle($image,30,42,226,214,$green);
imagefilledrectangle($image,42,54,214,182,$cream);
imagefilledellipse($image,128,117,98,98,$coral);
imagefilledpolygon($image,[114,88,114,146,153,117],$cream);
imagefilledrectangle($image,57,194,147,200,$cream);
imagefilledellipse($image,197,198,9,9,$coral);
ob_start(); imagepng($image); $png = ob_get_clean();
file_put_contents(__DIR__.'/assets/icon.png',$png);
file_put_contents(__DIR__.'/assets/icon.ico',pack('vvv',0,1,1).pack('CCCCvvVV',0,0,0,0,1,32,strlen($png),22).$png);
