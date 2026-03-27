<?php

    namespace App\Services;

    class TelegramCodeTokenService
    {
        private const EXTRACT_PATTERN = '/
    (?:
        \b(?:@?filepan_bot:|link:\s*|[vV]i_|[iI]v_|pk_|p_|d_|
        showfilesbot_|showfiles3bot_|Save2BoxBot|
        ntmjmqbot_|newjmqbot_|filestoebot_|mtfxqbot_|
        Messengercode_|QQfile_bot:|yzfile_bot:|atfileslinksbot_|lddeebot_|
        [vVpPdD]_?datapanbot_)
        [A-Za-z0-9_\+\-]+(?:=_grp|=_mda)?\b
    )
    |
    (?:\b[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)\b)
    |
    (?:\bLH_[A-Za-z0-9]+\b)
/xu';

        private const STRONG_PREFIXES = [
            'showfilesbot_',
            'showfiles3bot_',
            'Save2BoxBot',
            'filestoebot_',
            'mtfxqbot_',
            'ntmjmqbot_',
            'newjmqbot_',
            'Messengercode_',
            'QQfile_bot:',
            'yzfile_bot:',
            'atfileslinksbot_',
            'lddeebot_',
            'datapanbot_',
            'vi_',
            'iv_',
            'pk_',
            'LH_',
            'link:',
            '@filepan_bot:',
            'filepan_bot:',
        ];

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
            $isMessengerCode = strpos($s, 'Messengercode_') === 0;
            $isNewJmqbot = strpos($s, 'newjmqbot_') === 0;
            $isMtfxqbot = stripos($s, 'mtfxqbot_') === 0;
            $isQqFileBot = stripos($s, 'QQfile_bot:') === 0;
            $isYzFileBot = stripos($s, 'yzfile_bot:') === 0;
            $isAtfileslinksbot = stripos($s, 'atfileslinksbot_') === 0;
            $isLddeebot = stripos($s, 'lddeebot_') === 0;

            if (
                !$isShowfilesbot &&
                !$isShowfiles3bot &&
                !$isSave2BoxBot &&
                !$isMessengerCode &&
                !$isNewJmqbot &&
                !$isMtfxqbot &&
                !$isQqFileBot &&
                !$isYzFileBot &&
                !$isAtfileslinksbot &&
                !$isLddeebot &&
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
                return preg_match('/^showfilesbot_\d+[VPD]_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if ($isShowfiles3bot) {
                return preg_match('/^showfiles3bot_\d+[VPD]_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if ($isSave2BoxBot) {
                return preg_match('/^Save2BoxBot[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if ($isMessengerCode) {
                return preg_match('/^Messengercode_[A-Za-z0-9_\+\-]{6,}$/u', $s) === 1;
            }

            if ($isNewJmqbot) {
                return preg_match('/^newjmqbot_[A-Za-z0-9_\+\-]{6,}$/u', $s) === 1;
            }

            if ($isMtfxqbot) {
                return preg_match('/^mtfxqbot_\d+[VPD]_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/iu', $s) === 1;
            }

            if ($isQqFileBot) {
                return preg_match('/^QQfile_bot:[A-Za-z0-9_\+\-]{6,}$/iu', $s) === 1;
            }

            if ($isYzFileBot) {
                return preg_match('/^yzfile_bot:[A-Za-z0-9_\+\-]{6,}$/iu', $s) === 1;
            }

            if ($isAtfileslinksbot) {
                return preg_match('/^atfileslinksbot_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/iu', $s) === 1;
            }

            if ($isLddeebot) {
                return preg_match('/^lddeebot_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/iu', $s) === 1;
            }

            if (strpos($s, 'filestoebot_') === 0) {
                return preg_match('/^filestoebot_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (strpos($s, 'ntmjmqbot_') === 0) {
                return preg_match('/^ntmjmqbot_[A-Za-z0-9_\+\-]{6,}$/u', $s) === 1;
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
                return preg_match('/^[vVpPdD]_?datapanbot_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (preg_match('/^[vV]i_/u', $s) === 1) {
                return preg_match('/^[vV]i_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
            }

            if (preg_match('/^[iI]v_/u', $s) === 1) {
                return preg_match('/^[iI]v_[A-Za-z0-9_\+\-]{6,}(?:=_grp|=_mda)?$/u', $s) === 1;
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

            $seen = [];
            $result = [];

            foreach ($raw as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }

                if (!$this->isValidToken($token)) {
                    continue;
                }

                if (!isset($seen[$token])) {
                    $seen[$token] = true;
                    $result[] = $token;
                }
            }

            return $result;
        }
    }
