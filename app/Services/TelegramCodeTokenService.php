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

        /**
         * 只要內部再次出現這些 marker（pos > 0），就代表可能是「黏在一起」需要拆
         * 注意：這裡保留短 marker（p_/d_/v_/P_/D_/V_）是為了支援黏連拆分
         * 但真正避免誤切的關鍵是下面「更嚴格的 token 合法性判斷」
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
            '/[vV]_/u',
            '/[P]_/u',
            '/[D]_/u',
            '/[V]_/u',
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

        /**
         * 更嚴格的 token 判斷，避免 showfilesbot_1 / D_52 這種碎片被當成合法 token
         */
        private function isValidToken(string $s): bool
        {
            $s = trim($s);
            if ($s === '') {
                return false;
            }

            // showfilesbot_ 必須長成 showfilesbot_1D_.... 或 showfilesbot_3V_....
            // 並要求尾段至少 8 字元，避免 showfilesbot_1D_52 這種誤判
            if (preg_match('/^showfilesbot_\d+[VPD]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // filestoebot_ 至少 8 字元
            if (preg_match('/^filestoebot_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // ntmjmqbot_ 至少 8 字元（允許底線）
            if (preg_match('/^ntmjmqbot_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // datapanbot 類
            if (preg_match('/^[vVpPdD]_?datapanbot_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // vi_ / iv_ 至少 8 字元
            if (preg_match('/^[vV]i_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }
            if (preg_match('/^[iI]v_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // pk_ 至少 6 字元
            if (preg_match('/^pk_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // p_/d_/v_/P_/D_/V_ 這些短前綴：要求內容至少 8 字元，避免 D_52 這種碎片
            if (preg_match('/^[pP]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }
            if (preg_match('/^[dD]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }
            if (preg_match('/^[vV]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }
            if (preg_match('/^[P]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }
            if (preg_match('/^[D]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }
            if (preg_match('/^[V]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }
            if (preg_match('/^[vVpPdD]_[A-Za-z0-9_\+\-]{8,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // link: token（允許空白），內容至少 6 字元
            if (preg_match('/^link:\s*[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // filepan_bot:
            if (preg_match('/^@?filepan_bot:[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1) {
                return true;
            }

            // LH_
            if (preg_match('/^LH_[A-Za-z0-9]{4,}$/u', $s) === 1) {
                return true;
            }

            // 純 token（一定要 =_grp 或 =_mda）
            if (preg_match('/^[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)$/u', $s) === 1) {
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

            // 關鍵：就算整串是合法 token，只要內部還有 marker（pos > 0）就要先嘗試拆
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

                // 只接受「所有片段都是合法 token」
                $allValid = true;
                foreach ($candidate as $t) {
                    if (!$this->isValidToken((string)$t)) {
                        $allValid = false;
                        break;
                    }
                }
                if (!$allValid) {
                    continue;
                }

                // 取能拆出最多 token 的那組
                if (count($candidate) > count($best)) {
                    $best = $candidate;
                }
            }

            // 拆分完全失敗但本體合法：回退成整串
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
