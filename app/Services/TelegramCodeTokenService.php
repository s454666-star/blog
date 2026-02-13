<?php

    namespace App\Services;

    class TelegramCodeTokenService
    {
        private const EXTRACT_PATTERN = '/
        (?:\b(?:@?filepan_bot:|link:\s*|[vV]i_|[iI]v_|pk_|p_|d_|showfilesbot_|showfiles3bot_|Save2BoxBot|[vVpPdD]_?datapanbot_|[vVpPdD]_|ntmjmqbot_|filestoebot_)
            [A-Za-z0-9_\+\-]+(?:=_grp|=_mda)?\b
        )
        |
        (?:\b[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)\b)
        |
        (?:\bLH_[A-Za-z0-9]+\b)
    /xu';

        /**
         * 強前綴：只要同一串出現多次，就先硬切（最可靠）
         * 這些前綴在你的 token 系統裡辨識度高，不會誤切在 token 內容中間
         */
        private const STRONG_PREFIXES = [
            'showfilesbot_',
            'showfiles3bot_',
            'Save2BoxBot',
            'filestoebot_',
            'ntmjmqbot_',
            'datapanbot_',
            'vi_',
            'iv_',
            'pk_',
            'LH_',
            'link:',
            '@filepan_bot:',
            'filepan_bot:',
        ];

        /**
         * 更嚴格的 token 判斷，避免 showfilesbot_1 / D_52 這種碎片被當成合法 token
         */
        private function isValidToken(string $s): bool
        {
            $s = trim($s);
            if ($s === '') {
                return false;
            }

            $len = strlen($s);
            if ($len < 6) {
                return false;
            }

            $hasSuffix = (strpos($s, '=_grp') !== false) || (strpos($s, '=_mda') !== false);

            $isShowfilesbot = strpos($s, 'showfilesbot_') === 0;
            $isShowfiles3bot = strpos($s, 'showfiles3bot_') === 0;
            $isSave2BoxBot = stripos($s, 'Save2BoxBot') === 0;

            if (
                !$isShowfilesbot &&
                !$isShowfiles3bot &&
                !$isSave2BoxBot &&
                strpos($s, 'filestoebot_') !== 0 &&
                strpos($s, 'ntmjmqbot_') !== 0 &&
                strpos($s, 'datapanbot_') === false &&
                (stripos($s, 'vi_') !== 0) &&
                (stripos($s, 'iv_') !== 0) &&
                strpos($s, 'pk_') !== 0 &&
                strpos($s, 'LH_') !== 0 &&
                stripos($s, 'link:') !== 0 &&
                stripos($s, '@filepan_bot:') !== 0 &&
                stripos($s, 'filepan_bot:') !== 0
            ) {
                if (!$hasSuffix) {
                    return false;
                }
            }

            if ($isShowfilesbot) {
                return preg_match('/^showfilesbot_\d+[VPD]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if ($isShowfiles3bot) {
                return preg_match('/^showfiles3bot_\d+[VPD]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if ($isSave2BoxBot) {
                return preg_match('/^Save2BoxBot[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (strpos($s, 'filestoebot_') === 0) {
                return preg_match('/^filestoebot_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (strpos($s, 'ntmjmqbot_') === 0) {
                return preg_match('/^ntmjmqbot_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (strpos($s, 'pk_') === 0) {
                return preg_match('/^pk_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (strpos($s, 'LH_') === 0) {
                return preg_match('/^LH_[A-Za-z0-9]{4,}$/u', $s) === 1;
            }

            if (stripos($s, 'link:') === 0) {
                return preg_match('/^link:\s*[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (stripos($s, '@filepan_bot:') === 0 || stripos($s, 'filepan_bot:') === 0) {
                return preg_match('/^@?filepan_bot:[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (preg_match('/^[vVpPdD]_?datapanbot_/u', $s) === 1) {
                return preg_match('/^[vVpPdD]_?datapanbot_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (preg_match('/^[vV]i_/u', $s) === 1) {
                return preg_match('/^[vV]i_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (preg_match('/^[iI]v_/u', $s) === 1) {
                return preg_match('/^[iI]v_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if ($hasSuffix) {
                return preg_match('/^[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)$/u', $s) === 1;
            }

            return false;
        }

        public function extractTokens(string $text): array
        {
            $text = trim($text);
            if ($text === '') {
                return [];
            }

            $clean = preg_replace('/[\p{Han}]+/u', '', $text);
            if ($clean === null) {
                $clean = $text;
            }

            preg_match_all(self::EXTRACT_PATTERN, $clean, $m);
            $raw = $m[0] ?? [];
            if (empty($raw)) {
                return [];
            }

            $expanded = [];
            foreach ($raw as $token) {
                $token = (string)$token;
                if ($token === '') {
                    continue;
                }

                $parts = $this->splitToValidTokens($token);
                foreach ($parts as $p) {
                    $p = trim((string)$p);
                    if ($p !== '') {
                        $expanded[] = $p;
                    }
                }
            }

            $seen = [];
            $result = [];
            foreach ($expanded as $token) {
                if (isset($seen[$token])) {
                    continue;
                }
                $seen[$token] = true;
                $result[] = $token;
            }

            return $result;
        }

        private function splitToValidTokens(string $s): array
        {
            $s = trim($s);
            if ($s === '') {
                return [];
            }

            $memo = [];
            $out = $this->splitToValidTokensInternal($s, $memo);
            if (!empty($out)) {
                return $out;
            }

            return [$s];
        }

        private function splitToValidTokensInternal(string $s, array &$memo): array
        {
            $s = trim($s);
            if ($s === '') {
                return [];
            }

            if (isset($memo[$s])) {
                return $memo[$s];
            }

            /**
             * 第一步：強前綴硬切（解決黏連，保證不漏最後一段）
             */
            $strongPieces = $this->splitByStrongPrefixesIfNeeded($s);
            if (count($strongPieces) > 1) {
                $all = [];
                foreach ($strongPieces as $piece) {
                    $piece = trim((string)$piece);
                    if ($piece === '') {
                        continue;
                    }

                    $sub = $this->splitToValidTokensInternal($piece, $memo);

                    if (!empty($sub)) {
                        $all = array_merge($all, $sub);
                        continue;
                    }

                    if ($this->isValidToken($piece)) {
                        $all[] = $piece;
                    } else {
                        $memo[$s] = [];
                        return $memo[$s];
                    }
                }

                if (!empty($all)) {
                    $memo[$s] = $all;
                    return $memo[$s];
                }
            }

            /**
             * 第二步：如果本身就是合法 token，且沒有需要硬切，直接回傳
             */
            if ($this->isValidToken($s)) {
                $memo[$s] = [$s];
                return $memo[$s];
            }

            $memo[$s] = [];
            return $memo[$s];
        }

        /**
         * 若同一串內某個強前綴出現多次，直接以所有出現位置切段
         */
        private function splitByStrongPrefixesIfNeeded(string $s): array
        {
            $s = (string)$s;
            $positions = [];

            foreach (self::STRONG_PREFIXES as $prefix) {
                $prefixLen = strlen($prefix);
                if ($prefixLen === 0) {
                    continue;
                }

                $offset = 0;
                $found = [];

                while (true) {
                    $pos = strpos($s, $prefix, $offset);
                    if ($pos === false) {
                        break;
                    }

                    $found[] = (int)$pos;
                    $offset = (int)$pos + 1;

                    if ($offset >= strlen($s)) {
                        break;
                    }
                }

                if (count($found) >= 2) {
                    foreach ($found as $p) {
                        if ($p > 0) {
                            $positions[$p] = true;
                        }
                    }
                }
            }

            if (empty($positions)) {
                return [$s];
            }

            $cuts = array_map('intval', array_keys($positions));
            sort($cuts);

            $parts = [];
            $start = 0;

            foreach ($cuts as $cut) {
                $len = $cut - $start;
                if ($len > 0) {
                    $parts[] = substr($s, $start, $len);
                }
                $start = $cut;
            }

            $tail = substr($s, $start);
            if ($tail !== '') {
                $parts[] = $tail;
            }

            $cleanParts = [];
            foreach ($parts as $p) {
                $p = trim((string)$p);
                if ($p !== '') {
                    $cleanParts[] = $p;
                }
            }

            if (empty($cleanParts)) {
                return [$s];
            }

            return $cleanParts;
        }
    }
