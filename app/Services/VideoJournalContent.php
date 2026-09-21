<?php

namespace App\Services;

class VideoJournalContent
{
    public const IMAGE_BYTES = 20 * 1024 * 1024;
    public const BODY_BYTES = 64 * 1024 * 1024;

    public function sanitize(string $html): string
    {
        if (strlen($html) > self::BODY_BYTES) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => '文章含圖片最多 64 MB。']);
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Base64 for a 20 MB image exceeds libxml's default attribute limit.
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $dom->getElementsByTagName('body')->item(0);
        return $body ? $this->children($body) : '';
    }

    private function children(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'svg', 'math', 'template'], true)) {
                continue;
            }
            if ($tag === 'img') {
                $src = $child->getAttribute('src');
                if (preg_match('~^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=\r\n]+)$~D', $src, $match)) {
                    $bytes = base64_decode($match[2], true);
                    if ($bytes !== false && strlen($bytes) > self::IMAGE_BYTES) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['body' => '每張圖片最多 20 MB。']);
                    }
                    $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                    if ($info && $info['mime'] === 'image/'.$match[1]) {
                        $src = $this->fitImage($src, $bytes, $info[0], $info[1]);
                        $out .= '<img src="'.htmlspecialchars($src, ENT_QUOTES).'" alt="貼上的圖片">';
                    }
                }
                continue;
            }
            $inner = $this->children($child);
            $allowed = ['p', 'div', 'br', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code'];
            $out .= in_array($tag, $allowed, true) ? '<'.$tag.'>'.$inner.($tag === 'br' ? '' : '</'.$tag.'>') : $inner;
        }
        return $out;
    }

    private function fitImage(string $original, string $bytes, int $width, int $height): string
    {
        $scale = min(1, 1920 / $width, 1080 / $height);
        if ($scale >= 1) return $original;

        $limit = ini_parse_quantity(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $width * $height * 8 + 64 * 1024 * 1024 > $limit) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => '圖片像素過大，請先縮小圖片後再儲存。']);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => '無法解碼圖片，請重新選擇圖片後再儲存。']);
        }
        $target = imagecreatetruecolor(max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);
        ob_start();
        try {
            $encoded = imagewebp($target, null, 85);
            $compressed = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($target, $image);
        }
        if (!$encoded || $compressed === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['body' => '圖片壓縮失敗，請重試。']);
        }
        return 'data:image/webp;base64,'.base64_encode($compressed);
    }

    public function subtitles(string $srt): string
    {
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt);
        $srt = str_replace(["\r\n", "\r"], "\n", $srt);
        return "WEBVTT\n\n".preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', trim($srt))."\n";
    }
}
