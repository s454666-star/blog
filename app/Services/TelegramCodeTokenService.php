<?php

    namespace App\Services;

    class TelegramCodeTokenService
    {
        private const EXTRACT_PATTERN = '/
        (?:\b(?:@?filepan_bot:|link:\s*|[vV]i_|[iI]v_|pk_|p_|d_|showfilesbot_|[vVpPdD]_?datapanbot_|[vVpPdD]_|ntmjmqbot_|filestoebot_)
            [A-Za-z0-9_\+\-]+(?:=_grp|=_mda)?\b
        )
        |
        (?:\b[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)\b)
        |
        (?:\bLH_[A-Za-z0-9]+\b)
    /xu';

        private const VALID_TOKEN_PREFIXED = '/^(?:@?filepan_bot:|link:\s*|[vV]i_|[iI]v_|pk_|[pP]_|[dD]_|showfilesbot_|[vVpPdD]_?datapanbot_|[vVpPdD]_|ntmjmqbot_|filestoebot_)[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)?$/u';
        private const VALID_TOKEN_PLAIN    = '/^[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)$/u';
        private const VALID_TOKEN_LH       = '/^LH_[A-Za-z0-9]+$/u';

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
                    $p = (string)$p;
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

            $positions = $this->findSplitPositions($s);

            // 關鍵修正：
            // 就算整串符合 "prefix + allowed chars" 看起來是合法 token，
            // 只要內部還出現 marker（pos > 0），就必須先嘗試拆分。
            if ($this->isValidToken($s) && empty($positions)) {
                $memo[$s] = [$s];
                return $memo[$s];
            }

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

                $left  = trim((string)substr($s, 0, $pos));
                $right = trim((string)substr($s, $pos));

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

            // 拆分如果完全失敗，但整串其實是合法 token，回退成整串
            if (empty($best) && $this->isValidToken($s)) {
                $best = [$s];
            }

            $memo[$s] = $best;
            return $memo[$s];
        }

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
