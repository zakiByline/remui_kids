<?php
// Theme-level endpoint for AI-powered matching question generation.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    remui_kids_match_ai_send_response(false, 'Invalid request method.');
}

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/quizai:generate', $context);

if (!get_config('local_quizai', 'enabled')) {
    remui_kids_match_ai_send_response(false, get_string('error_disabled', 'local_quizai'));
}

$topic = trim(required_param('topic', PARAM_TEXT));
$difficulty = trim(optional_param('difficulty', 'balanced', PARAM_ALPHANUMEXT));
$count = max(1, min(10, (int)optional_param('count', 1, PARAM_INT)));
$pairs = max(3, min(8, (int)optional_param('pairs', 5, PARAM_INT)));

[$apikey, $model] = remui_kids_match_ai_resolve_credentials();
if (empty($apikey) || empty($model)) {
    remui_kids_match_ai_send_response(false, get_string('error_noapikey', 'local_quizai'));
}

try {
    $prompt = remui_kids_match_ai_build_prompt($topic, $difficulty, $count, $pairs, $USER);
    $response = remui_kids_match_ai_call_gemini($apikey, $model, $prompt);
    $jsonpayload = remui_kids_match_ai_extract_json($response);
    if ($jsonpayload === null) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    $decoded = json_decode($jsonpayload, true);
    if (!is_array($decoded)) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    $normalized = remui_kids_match_ai_normalise_payload($decoded, $count);

    remui_kids_match_ai_send_response(true, get_string('generate_success', 'local_quizai'), [
        'questions' => $normalized,
    ]);
} catch (moodle_exception $ex) {
    remui_kids_match_ai_send_response(false, $ex->getMessage());
} catch (Throwable $ex) {
    debugging('Matching AI generation failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
    remui_kids_match_ai_send_response(false, get_string('error_invalid_response', 'local_quizai'));
}

/**
 * Send JSON response and exit.
 *
 * @param bool $success
 * @param string $message
 * @param array $extra
 * @return void
 */
function remui_kids_match_ai_send_response(bool $success, string $message, array $extra = []): void {
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

/**
 * Resolve API credentials, respecting AI Assistant fallbacks.
 *
 * @return array
 */
function remui_kids_match_ai_resolve_credentials(): array {
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
 * Build Gemini prompt for matching questions.
 *
 * @param string $topic
 * @param string $difficulty
 * @param int $count
 * @param int $pairs
 * @param stdClass $user
 * @return string
 */
function remui_kids_match_ai_build_prompt(string $topic, string $difficulty, int $count, int $pairs, stdClass $user): string {
    $teacher = fullname($user);
    $roleassignments = get_user_roles(context_system::instance(), $user->id, false) ?: [];
    $roles = array_filter(array_map(function($assignment) {
        return $assignment->shortname ?? $assignment->name ?? '';
    }, $roleassignments));
    $rolelabel = empty($roles) ? 'teacher' : implode(', ', $roles);

    $plural = $count > 1 ? 'questions' : 'question';

    return <<<PROMPT
You are an instructional designer for Moodle. Generate {$count} "{$topic}" matching {$plural} aimed at {$difficulty} difficulty for {$teacher} ({$rolelabel}).

Rules:
- Respond ONLY with JSON, no prose or code fences.
- Top-level structure: {"questions": [ /* {$count} items */ ]}
- Each item must follow this schema exactly:
{
  "name": "short title (<=70 characters)",
  "text": "question stem in Markdown (no tables)",
  "defaultmark": number,
  "generalfeedback": "feedback shown after grading",
  "pairs": [
     {"question": "prompt text", "answer": "matching answer"},
     ...
  ]
}
- Provide between {$pairs} and {$pairs} distinct pairs per question.
- Pairs must be concise (<=120 chars) and unambiguous.
- Avoid mentioning that you are an AI.
- Use plain text/Markdown only.
PROMPT;
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
function remui_kids_match_ai_call_gemini(string $apikey, string $model, string $prompt): string {
    $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apikey}";
    $curl = new curl([
        'CURLOPT_TIMEOUT' => 30,
        'CURLOPT_CONNECTTIMEOUT' => 10,
    ]);
    $curl->setHeader(['Content-Type: application/json']);

    $payload = json_encode([
        'contents' => [[
            'parts' => [[
                'text' => $prompt,
            ]],
        ]],
    ]);

    $response = $curl->post($url, $payload);
    if ($curl->get_errno()) {
        throw new moodle_exception('curlerror', 'error', '', null, $curl->error);
    }

    $decoded = json_decode($response, true);
    if (isset($decoded['error'])) {
        $message = $decoded['error']['message'] ?? 'Unknown API error';
        throw new moodle_exception('error', 'local_quizai', '', null, $message);
    }

    if (empty($decoded['candidates'][0]['content']['parts'])) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    $text = '';
    foreach ($decoded['candidates'][0]['content']['parts'] as $part) {
        if (!empty($part['text'])) {
            $text .= $part['text'];
        }
    }

    return trim($text);
}

/**
 * Extract JSON payload from AI response.
 *
 * @param string $response
 * @return string|null
 */
function remui_kids_match_ai_extract_json(string $response): ?string {
    $response = preg_replace('/```[a-zA-Z]*|```/', '', trim($response));
    if ($response === '') {
        return null;
    }

    $decoded = remui_kids_match_ai_attempt_json($response);
    if ($decoded !== null) {
        return $decoded;
    }

    $length = strlen($response);
    for ($i = 0; $i < $length; $i++) {
        $char = $response[$i];
        if ($char === '{' || $char === '[') {
            $segment = remui_kids_match_ai_extract_segment($response, $i);
            if ($segment !== null) {
                return $segment;
            }
        }
    }

    return null;
}

/**
 * Attempt to validate JSON quickly.
 *
 * @param string $payload
 * @return string|null
 */
function remui_kids_match_ai_attempt_json(string $payload): ?string {
    json_decode($payload);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $payload;
    }
    return null;
}

/**
 * Extract a balanced JSON segment from text.
 *
 * @param string $text
 * @param int $start
 * @return string|null
 */
function remui_kids_match_ai_extract_segment(string $text, int $start): ?string {
    $stack = [];
    $length = strlen($text);
    for ($i = $start; $i < $length; $i++) {
        $char = $text[$i];
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
                $segment = substr($text, $start, $i - $start + 1);
                if (remui_kids_match_ai_attempt_json($segment) !== null) {
                    return $segment;
                }
                return null;
            }
        }
    }
    return null;
}

/**
 * Normalise decoded payload into front-end friendly format.
 *
 * @param array $decoded
 * @param int $requestedcount
 * @return array
 * @throws moodle_exception
 */
function remui_kids_match_ai_normalise_payload(array $decoded, int $requestedcount): array {
    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $items = $decoded['questions'];
    } else if (remui_kids_match_ai_is_list($decoded)) {
        $items = $decoded;
    } else {
        $items = [$decoded];
    }

    $normalized = [];
    foreach ($items as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $pairs = remui_kids_match_ai_extract_pairs($entry);
        if (count($pairs) < 2) {
            continue;
        }
        $name = trim((string)($entry['name'] ?? ''));
        if ($name === '') {
            $name = get_string('pluginname', 'local_quizai') . ' Matching';
        }

        $normalized[] = [
            'qtype' => 'match',
            'name' => clean_param($name, PARAM_TEXT),
            'text' => clean_param($entry['text'] ?? '', PARAM_RAW_TRIMMED),
            'defaultmark' => isset($entry['defaultmark']) ? (float)$entry['defaultmark'] : 1,
            'generalfeedback' => clean_param($entry['generalfeedback'] ?? '', PARAM_RAW_TRIMMED),
            'pairs' => $pairs,
        ];
    }

    if (empty($normalized)) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    if (count($normalized) > $requestedcount) {
        $normalized = array_slice($normalized, 0, $requestedcount);
    }

    return $normalized;
}

/**
 * Extract match pairs from entry.
 *
 * @param array $entry
 * @return array
 */
function remui_kids_match_ai_extract_pairs(array $entry): array {
    $candidates = [];
    foreach (['pairs', 'matches', 'options'] as $key) {
        if (!empty($entry[$key]) && is_array($entry[$key])) {
            $candidates = $entry[$key];
            break;
        }
    }

    $pairs = [];
    foreach ($candidates as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $question = trim((string)($pair['question'] ?? $pair['prompt'] ?? $pair['left'] ?? ''));
        $answer = trim((string)($pair['answer'] ?? $pair['match'] ?? $pair['right'] ?? ''));
        if ($question === '' || $answer === '') {
            continue;
        }
        $pairs[] = [
            'question' => clean_param($question, PARAM_TEXT),
            'answer' => clean_param($answer, PARAM_TEXT),
        ];
        if (count($pairs) >= 8) {
            break;
        }
    }

    return $pairs;
}

/**
 * Determine if array is list.
 *
 * @param array $value
 * @return bool
 */
function remui_kids_match_ai_is_list(array $value): bool {
    if ($value === []) {
        return true;
    }
    $expected = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

