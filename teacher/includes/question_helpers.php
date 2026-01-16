<?php
// Shared helpers for question bank interactions within the RemUI Kids teacher tools.

defined('MOODLE_INTERNAL') || die();

if (!function_exists('theme_remui_kids_get_question_bank_entry')) {
    /**
     * Resolve the question bank entry associated with a question id, with fallbacks for older schemas.
     *
     * @param int $questionid
     * @return stdClass|null
     */
    function theme_remui_kids_get_question_bank_entry($questionid) {
        global $DB;

        if (empty($questionid) || !is_numeric($questionid)) {
            return null;
        }

        $questionid = (int)$questionid;
        if ($questionid <= 0) {
            return null;
        }

        // Try Moodle's native helper first.
        if (function_exists('get_question_bank_entry')) {
            $entry = get_question_bank_entry($questionid);
            if ($entry) {
                return $entry;
            }
        }

        // Manual fallback via question_versions.
        $sql = "SELECT qbe.*
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                 WHERE qv.questionid = ?
              ORDER BY qv.version DESC";
        return $DB->get_record_sql($sql, [$questionid]) ?: null;
    }
}

if (!function_exists('remui_kids_int_to_letter')) {
    function remui_kids_int_to_letter(int $number): string {
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)) . $letters;
            $number = intdiv($number, 26);
        }
        return $letters ?: 'A';
    }
}

if (!function_exists('remui_kids_letter_to_int')) {
    function remui_kids_letter_to_int(string $letters): int {
        $letters = strtoupper(trim($letters));
        if ($letters === '') {
            return 0;
        }
        $value = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $char = ord($letters[$i]);
            if ($char < 65 || $char > 90) {
                continue;
            }
            $value = $value * 26 + ($char - 64);
        }
        return $value ?: 0;
    }
}

if (!function_exists('remui_kids_serialize_ddwtos_feedback')) {
    /**
     * Encode DDWTOS drag group metadata the way Moodle expects.
     *
     * @param int  $groupindex Sequential group index (1-based).
     * @param bool $infinite   Whether the choice is reusable indefinitely.
     * @return string Serialized payload stored in question_answers.feedback.
     */
    function remui_kids_serialize_ddwtos_feedback(int $groupindex, bool $infinite = false): string {
        $payload = (object) [
            'draggroup' => max(1, $groupindex),
            'infinite' => $infinite ? 1 : 0,
        ];
        return serialize($payload);
    }
}

if (!function_exists('remui_kids_decode_ddwtos_feedback')) {
    /**
     * Decode drag-drop words feedback metadata stored in question_answers.feedback.
     *
     * Supports native serialized format as well as legacy group letters/numbers.
     *
     * @param string|null $feedback
     * @return int|null Group index (1-based) or null if it cannot be derived.
     */
    function remui_kids_decode_ddwtos_feedback(?string $feedback): ?int {
        if ($feedback === null) {
            return null;
        }
        $trimmed = trim($feedback);
        if ($trimmed === '') {
            return null;
        }
        $decoded = @unserialize($trimmed, ['allowed_classes' => true]);
        if ($decoded !== false && is_object($decoded) && isset($decoded->draggroup)) {
            return (int)$decoded->draggroup;
        }
        if (ctype_digit($trimmed)) {
            return (int)$trimmed;
        }
        $letterValue = remui_kids_letter_to_int($trimmed);
        return $letterValue > 0 ? $letterValue : null;
    }
}

