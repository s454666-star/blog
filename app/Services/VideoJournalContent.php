<?php

namespace App\Services;

class VideoJournalContent
{
    public function sanitize(string $html): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
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
                    $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                    if ($info && $info['mime'] === 'image/'.$match[1] && strlen($bytes) <= 2 * 1024 * 1024) {
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

    public function subtitles(string $srt): string
    {
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt);
        $srt = str_replace(["\r\n", "\r"], "\n", $srt);
        return "WEBVTT\n\n".preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', trim($srt))."\n";
    }
}
