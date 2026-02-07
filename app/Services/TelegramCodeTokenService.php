<?php

    namespace App\Services;

    class TelegramCodeTokenService
    {
        /**
         * 抽取 token 用的正則（與你原本一致）
         */
        private const EXTRACT_PATTERN = '/
        (?:\b(?:@?filepan_bot:|link:\s*|[vV]i_|[iI]v_|pk_|p_|d_|showfilesbot_|[vVpPdD]_?datapanbot_|[vVpPdD]_|ntmjmqbot_|filestoebot_)
            [A-Za-z0-9_\+\-]+(?:=_grp|=_mda)?\b
        )
        |
        (?:\b[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)\b)
        |
        (?:\bLH_[A-Za-z0-9]+\b)
    /xu';

        /**
         * 驗證一段字串是否為「合法 token」（用來判斷切割是否成立）
         */
        private const VALID_TOKEN_PREFIXED = '/^(?:@?filepan_bot:|link:\s*|[vV]i_|[iI]v_|pk_|[pP]_|[dD]_|showfilesbot_|[vVpPdD]_?datapanbot_|[vVpPdD]_|ntmjmqbot_|filestoebot_)[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)?$/u';
        private const VALID_TOKEN_PLAIN    = '/^[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)$/u';
        private const VALID_TOKEN_LH       = '/^LH_[A-Za-z0-9]+$/u';

        /**
         * 內部分離用：任何「原本支援的起始前綴」都列入切點候選
         * 切點是否採用，會再用 VALID_TOKEN_* 做驗證，避免亂切
         */
        private const SPLIT_MARKER_PATTERNS = [
            '/@?filepan_bot:/u',
            '/link:\s*/u',
            '/showfilesbot_/u',
            '/filestoebot_/u',
            '/ntmjmqbot_/u',
            '/[vVpPdD]_?datapanbot_/u',
            '/datapanbot_/u',
            '/LH_/u',
            '/[vV]i_/u',
            '/[iI]v_/u',
            '/pk_/u',
            '/[pP]_/u',
            '/[dD]_/u',
            '/[vVpPdD]_/u',
        ];

        /**
         * 輸入文字，回傳擷取到的 token 陣列（去重、保留原順序）
         * 並且可以把「黏在一起的 token」遞迴切成多個 token
         */
        public function extractTokens(string $text): array
        {
            $text = trim($text);
            if ($text === '') {
                return [];
            }

            // 去中文（保留英文、日文假名等）
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
                    $p = (string)$p;
                    if ($p !== '') {
                        $expanded[] = $p;
                    }
                }
            }

            // 去重（保留順序）
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

        private function isValidToken(string $s): bool
        {
            $s = trim($s);
            if ($s === '') {
                return false;
            }

            if (preg_match(self::VALID_TOKEN_PREFIXED, $s) === 1) {
                return true;
            }

            if (preg_match(self::VALID_TOKEN_PLAIN, $s) === 1) {
                return true;
            }

            if (preg_match(self::VALID_TOKEN_LH, $s) === 1) {
                return true;
            }

            return false;
        }

        /**
         * 將一段字串切成「全部都是合法 token」的陣列
         * 如果本身就是合法 token，直接回傳單一元素
         * 如果黏在一起，會透過 marker 找切點並遞迴驗證
         */
        private function splitToValidTokens(string $s): array
        {
            $s = trim($s);
            if ($s === '') {
                return [];
            }

            $memo = [];
            return $this->splitToValidTokensInternal($s, $memo);
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

            if ($this->isValidToken($s)) {
                $memo[$s] = [$s];
                return $memo[$s];
            }

            $positions = $this->findSplitPositions($s);
            if (empty($positions)) {
                $memo[$s] = [];
                return $memo[$s];
            }

            sort($positions);

            $best = [];
            foreach ($positions as $pos) {
                $pos = (int)$pos;
                if ($pos <= 0) {
                    continue;
                }

                $left  = substr($s, 0, $pos);
                $right = substr($s, $pos);

                $left  = trim((string)$left);
                $right = trim((string)$right);

                if ($left === '' || $right === '') {
                    continue;
                }

                $leftTokens  = $this->splitToValidTokensInternal($left, $memo);
                if (empty($leftTokens)) {
                    continue;
                }

                $rightTokens = $this->splitToValidTokensInternal($right, $memo);
                if (empty($rightTokens)) {
                    continue;
                }

                $candidate = array_merge($leftTokens, $rightTokens);

                if (count($candidate) > count($best)) {
                    $best = $candidate;
                }
            }

            $memo[$s] = $best;
            return $memo[$s];
        }

        /**
         * 找出字串內部可疑的「新 token 起點」位置（pos > 0）
         */
        private function findSplitPositions(string $s): array
        {
            $positions = [];

            foreach (self::SPLIT_MARKER_PATTERNS as $rx) {
                $matches = [];
                preg_match_all($rx, $s, $matches, PREG_OFFSET_CAPTURE);

                $items = $matches[0] ?? [];
                foreach ($items as $item) {
                    $pos = (int)($item[1] ?? -1);
                    if ($pos > 0) {
                        $positions[$pos] = true;
                    }
                }
            }

            return array_map('intval', array_keys($positions));
        }
    }
