<?php
/**
 * Helper utilities for AI-powered quiz metadata suggestions.
 *
 * @package   theme_remui_kids
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Generate quiz name/description suggestions using Gemini.
 *
 * @param array $contextdata
 * @param int $count
 * @return array
 * @throws moodle_exception
 */
function remui_kids_generate_quiz_meta_suggestions(array $contextdata, int $count = 3): array {
    $count = max(1, min(5, (int)$count));

    [$apikey, $model] = remui_kids_resolve_quiz_ai_credentials();
    if (empty($apikey) || empty($model)) {
        throw new moodle_exception('error_noapikey', 'local_quizai');
    }

    $prompt = remui_kids_build_quiz_meta_prompt($contextdata, $count);
    $response = remui_kids_call_gemini_api($apikey, $model, $prompt);
    $json = remui_kids_extract_first_json($response);

    if (!$json) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    $payload = json_decode($json, true);
    $suggestionsRaw = [];

    if (isset($payload['suggestions']) && is_array($payload['suggestions'])) {
        $suggestionsRaw = $payload['suggestions'];
    } else if (is_array($payload) && remui_kids_is_list_array($payload)) {
        $suggestionsRaw = $payload;
    }

    $suggestions = [];
    foreach ($suggestionsRaw as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $title = trim((string)($entry['title'] ?? ''));
        $description = trim((string)($entry['description'] ?? ''));
        if ($title === '' || $description === '') {
            continue;
        }
        $suggestions[] = [
            'title' => clean_param($title, PARAM_TEXT),
            'description' => clean_param($description, PARAM_RAW_TRIMMED),
            'tone' => clean_param($entry['tone'] ?? '', PARAM_TEXT),
            'focus' => clean_param($entry['focus'] ?? '', PARAM_TEXT),
        ];
        if (count($suggestions) >= $count) {
            break;
        }
    }

    if (empty($suggestions)) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    return $suggestions;
}

/**
 * Resolve API credentials, preferring dedicated quiz AI settings but falling back to site defaults.
 *
 * @return array
 */
function remui_kids_resolve_quiz_ai_credentials(): array {
    $apikey = trim((string)get_config('local_quizai', 'apikey'));
    if ($apikey === '') {
        $apikey = trim((string)get_config('local_aiassistant', 'apikey'));
    }

    $model = trim((string)get_config('local_quizai', 'model'));
    if ($model === '') {
        $model = trim((string)get_config('local_aiassistant', 'model'));
    }

    return [$apikey, $model];
}

/**
 * Call Gemini API.
 *
 * @param string $apikey
 * @param string $model
 * @param string $prompt
 * @return string
 * @throws moodle_exception
 */
function remui_kids_call_gemini_api(string $apikey, string $model, string $prompt): string {
    $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apikey}";

    $payload = [
        'contents' => [[
            'parts' => [[
                'text' => $prompt,
            ]],
        ]],
    ];

    $curl = new curl([
        'CURLOPT_TIMEOUT' => 30,
        'CURLOPT_CONNECTTIMEOUT' => 10,
    ]);

    $curl->setHeader(['Content-Type: application/json']);
    $response = $curl->post($url, json_encode($payload));

    if ($curl->get_errno()) {
        throw new moodle_exception('curlerror', 'error', '', null, $curl->error);
    }

    $result = json_decode($response, true);
    if (isset($result['error'])) {
        $message = $result['error']['message'] ?? 'Unknown API error';
        throw new moodle_exception('error', 'local_quizai', '', null, $message);
    }

    if (!isset($result['candidates'][0]['content']['parts'])) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    $segments = $result['candidates'][0]['content']['parts'];
    $text = '';
    foreach ($segments as $segment) {
        if (!empty($segment['text'])) {
            $text .= $segment['text'];
        }
    }

    return trim($text);
}

/**
 * Build the AI prompt.
 *
 * @param array $contextdata
 * @param int $count
 * @return string
 */
function remui_kids_build_quiz_meta_prompt(array $contextdata, int $count): string {
    $coursename = trim((string)($contextdata['coursename'] ?? ''));
    $placement = trim((string)($contextdata['placementpath'] ?? $contextdata['placementname'] ?? ''));
    $sectionsummary = trim((string)($contextdata['sectionsummary'] ?? ''));
    $moduleintro = trim((string)($contextdata['moduleintro'] ?? ''));
    $existingname = trim((string)($contextdata['existingname'] ?? ''));
    $existingdescription = trim((string)($contextdata['existingdescription'] ?? ''));

    $audience = trim((string)($contextdata['audience'] ?? 'students'));
    $count = max(1, min(5, $count));

    $existingblock = '';
    if ($existingname !== '' || $existingdescription !== '') {
        $existingblock = "Existing title: {$existingname}\nExisting summary: {$existingdescription}\n";
    }

    $contextblock = <<<CONTEXT
Course: {$coursename}
Placement: {$placement}
Lesson summary: {$sectionsummary}
Module focus: {$moduleintro}
Audience: {$audience}
{$existingblock}
CONTEXT;

    $instructions = <<<PROMPT
You are an instructional designer helping teachers create engaging Moodle quizzes.

Use the context below to propose {$count} compelling quiz names and descriptions. Focus on clarity, learning outcomes, and motivational tone. Titles must be <= 80 characters. Descriptions <= 600 characters and should highlight skills assessed plus a short call-to-action for learners.

{$contextblock}

Return ONLY JSON in this schema:
{
  "suggestions": [
    {
      "title": "string",
      "description": "string",
      "tone": "short adjective",
      "focus": "skills or concepts assessed"
    }
  ]
}
Ensure the array contains exactly {$count} suggestions.
PROMPT;

    return $instructions;
}

/**
 * Extract JSON from AI response.
 *
 * @param string $response
 * @return string|null
 */
function remui_kids_extract_first_json(string $response): ?string {
    $response = preg_replace('/```[a-zA-Z]*|```/', '', $response);
    $response = trim($response);

    $attempt = remui_kids_try_decode_json($response);
    if ($attempt !== null) {
        return $attempt;
    }

    $length = strlen($response);
    for ($i = 0; $i < $length; $i++) {
        $char = $response[$i];
        if ($char === '{' || $char === '[') {
            $segment = remui_kids_extract_balanced_json($response, $i);
            if ($segment !== null) {
                return $segment;
            }
        }
    }

    return null;
}

/**
 * Try to decode JSON directly.
 *
 * @param string $candidate
 * @return string|null
 */
function remui_kids_try_decode_json(string $candidate): ?string {
    $candidate = remui_kids_sanitize_json_string($candidate);
    $decoded = json_decode($candidate, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return json_encode($decoded);
    }
    return null;
}

/**
 * Extract balanced JSON starting at $start.
 *
 * @param string $response
 * @param int $start
 * @return string|null
 */
function remui_kids_extract_balanced_json(string $response, int $start): ?string {
    $length = strlen($response);
    $stack = [];
    $instring = false;
    $escape = false;

    for ($i = $start; $i < $length; $i++) {
        $char = $response[$i];

        if ($instring) {
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($char === '\\') {
                $escape = true;
                continue;
            }
            if ($char === '"') {
                $instring = false;
            }
            continue;
        }

        if ($char === '"') {
            $instring = true;
            continue;
        }

        if ($char === '{' || $char === '[') {
            $stack[] = $char;
        } else if ($char === '}' || $char === ']') {
            if (empty($stack)) {
                return null;
            }
            $open = array_pop($stack);
            if (($open === '{' && $char !== '}') || ($open === '[' && $char !== ']')) {
                return null;
            }
            if (empty($stack)) {
                $snippet = substr($response, $start, $i - $start + 1);
                $snippet = remui_kids_sanitize_json_string($snippet);
                $decoded = json_decode($snippet, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return json_encode($decoded);
                }
            }
        }
    }

    return null;
}

/**
 * Replace smart quotes/dashes for JSON decoding.
 *
 * @param string $value
 * @return string
 */
function remui_kids_sanitize_json_string(string $value): string {
    $map = [
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{00A0}" => ' ',
    ];
    return strtr($value, $map);
}

/**
 * Determine if array has numeric keys.
 *
 * @param array $value
 * @return bool
 */
function remui_kids_is_list_array(array $value): bool {
    $expected = 0;
    foreach ($value as $key => $_) {
        if ((string)$key !== (string)$expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

