<?php
// Theme-level endpoint for AI-powered drag-drop words (ddwtos) generation.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    remui_kids_ddwtos_ai_send_response(false, 'Invalid request method.');
}

require_login();
require_sesskey();

$context = context_system::instance();
require_capability('local/quizai:generate', $context);

if (!get_config('local_quizai', 'enabled')) {
    remui_kids_ddwtos_ai_send_response(false, get_string('error_disabled', 'local_quizai'));
}

$topic = trim(required_param('topic', PARAM_TEXT));
$difficulty = trim(optional_param('difficulty', 'balanced', PARAM_ALPHANUMEXT));
$count = max(1, min(5, (int)optional_param('count', 1, PARAM_INT)));
$blanks = max(2, min(6, (int)optional_param('blanks', 3, PARAM_INT)));
$choicespergap = max(3, min(6, $blanks + 2));

[$apikey, $model] = remui_kids_ddwtos_ai_resolve_credentials();
if (empty($apikey) || empty($model)) {
    remui_kids_ddwtos_ai_send_response(false, get_string('error_noapikey', 'local_quizai'));
}

try {
    $prompt = remui_kids_ddwtos_ai_build_prompt($topic, $difficulty, $count, $blanks, $choicespergap, $USER);
    $response = remui_kids_ddwtos_ai_call_gemini($apikey, $model, $prompt);
    $jsonpayload = remui_kids_ddwtos_ai_extract_json($response);
    if ($jsonpayload === null) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    $decoded = json_decode($jsonpayload, true);
    if (!is_array($decoded)) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    $normalized = remui_kids_ddwtos_ai_normalise_payload($decoded, $count, $blanks, $choicespergap);

    remui_kids_ddwtos_ai_send_response(true, get_string('generate_success', 'local_quizai'), [
        'questions' => $normalized,
    ]);
} catch (moodle_exception $ex) {
    remui_kids_ddwtos_ai_send_response(false, $ex->getMessage());
} catch (Throwable $ex) {
    debugging('DDWTOS AI generation failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
    remui_kids_ddwtos_ai_send_response(false, get_string('error_invalid_response', 'local_quizai'));
}

function remui_kids_ddwtos_ai_send_response(bool $success, string $message, array $extra = []): void {
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

function remui_kids_ddwtos_ai_resolve_credentials(): array {
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

function remui_kids_ddwtos_ai_build_prompt(string $topic, string $difficulty, int $count, int $blanks, int $choicespergap, stdClass $user): string {
    $teacher = fullname($user);
    $roleassignments = get_user_roles(context_system::instance(), $user->id, false) ?: [];
    $roles = array_filter(array_map(function($assignment) {
        return $assignment->shortname ?? ($assignment->name ?? '');
    }, $roleassignments));
    $rolelabel = empty($roles) ? 'teacher' : implode(', ', $roles);

    $plural = $count > 1 ? 'questions' : 'question';
    $distractorcount = max(1, $choicespergap - 1);
    $instructions = <<<PROMPT
You are an instructional designer for Moodle. Generate {$count} "{$topic}" drag-and-drop words into text {$plural} aimed at {$difficulty} difficulty for {$teacher} ({$rolelabel}).

Rules:
- Respond ONLY with JSON (no prose or code fences).
- Top-level structure: {"questions": [ ... ]}
- Each question must follow this schema:
{
  "name": "short title (<=70 characters)",
  "text": "question stem in Markdown using placeholders like [[1]], [[2]], etc.",
  "defaultmark": number,
  "generalfeedback": "string",
  "shuffleanswers": boolean,
  "gaps": [
     {"answer": "correct word or phrase", "distractors": ["wrong option 1", "wrong option 2", "..."]},
     ...
  ]
}
- Provide exactly {$blanks} placeholders ([[1]] ... [[{$blanks}]]) in the question text.
- Each gap must have at least {$choicespergap} total choices (1 correct + {$distractorcount} distractors).
- Distractors must be plausible and distinct.
- Avoid mentioning that you are an AI.
PROMPT;

    return $instructions;
}

function remui_kids_ddwtos_ai_call_gemini(string $apikey, string $model, string $prompt): string {
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

function remui_kids_ddwtos_ai_extract_json(string $response): ?string {
    $response = preg_replace('/```[a-zA-Z]*|```/', '', trim($response));
    if ($response === '') {
        return null;
    }

    $decoded = json_decode($response, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $response;
    }

    $length = strlen($response);
    for ($i = 0; $i < $length; $i++) {
        $char = $response[$i];
        if ($char === '{' || $char === '[') {
            $segment = remui_kids_ddwtos_ai_extract_segment($response, $i);
            if ($segment !== null) {
                return $segment;
            }
        }
    }

    return null;
}

function remui_kids_ddwtos_ai_extract_segment(string $text, int $start): ?string {
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
                json_decode($segment, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $segment;
                }
                return null;
            }
        }
    }
    return null;
}

function remui_kids_ddwtos_ai_normalise_payload(array $decoded, int $requestedcount, int $blanks, int $choicespergap): array {
    if (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $items = $decoded['questions'];
    } else if (remui_kids_ddwtos_ai_is_list($decoded)) {
        $items = $decoded;
    } else {
        $items = [$decoded];
    }

    $normalized = [];
    foreach ($items as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $text = trim((string)($entry['text'] ?? ''));
        $gaps = [];
        if (isset($entry['gaps']) && is_array($entry['gaps'])) {
            foreach ($entry['gaps'] as $gap) {
                if (!is_array($gap)) {
                    continue;
                }
                $answer = trim((string)($gap['answer'] ?? ''));
                if ($answer === '') {
                    continue;
                }
                $distractors = isset($gap['distractors']) && is_array($gap['distractors'])
                    ? array_filter(array_map('trim', $gap['distractors']), static function($value) {
                        return $value !== '';
                    })
                    : [];
                if (count($distractors) < $choicespergap - 1) {
                    $distractors = array_merge(
                        $distractors,
                        remui_kids_ddwtos_ai_generate_distractors($answer, ($choicespergap - 1) - count($distractors))
                    );
                }
                $gaps[] = [
                    'answer' => $answer,
                    'distractors' => array_values(array_unique($distractors))
                ];
            }
        }

        if (count($gaps) < $blanks) {
            for ($i = count($gaps); $i < $blanks; $i++) {
                $fallback = 'Example ' . ($i + 1);
                $gaps[] = [
                    'answer' => $fallback,
                    'distractors' => remui_kids_ddwtos_ai_generate_distractors($fallback, $choicespergap - 1)
                ];
            }
        } else if (count($gaps) > $blanks) {
            $gaps = array_slice($gaps, 0, $blanks);
        }

        if ($text === '') {
            $pieces = array_map(function($index) {
                return "[[{$index}]]";
            }, range(1, $blanks));
            $text = 'Complete the sentence: ' . implode(' ... ', $pieces);
        }

        for ($i = 1; $i <= $blanks; $i++) {
            if (strpos($text, '[[' . $i . ']]') === false) {
                $text .= " [[{$i}]]";
            }
        }

        $normalized[] = [
            'qtype' => 'ddwtos',
            'name' => clean_param($entry['name'] ?? 'Drag and drop words', PARAM_TEXT),
            'text' => clean_param($text, PARAM_RAW_TRIMMED),
            'defaultmark' => isset($entry['defaultmark']) ? (float)$entry['defaultmark'] : 1.0,
            'generalfeedback' => clean_param($entry['generalfeedback'] ?? '', PARAM_RAW_TRIMMED),
            'shuffleanswers' => !empty($entry['shuffleanswers']),
            'gaps' => $gaps
        ];
        if (count($normalized) >= $requestedcount) {
            break;
        }
    }

    if (empty($normalized)) {
        throw new moodle_exception('error_invalid_response', 'local_quizai');
    }

    return $normalized;
}

function remui_kids_ddwtos_ai_generate_distractors(string $answer, int $needed): array {
    $distractors = [];
    $base = trim($answer);
    if ($base === '') {
        $base = 'option';
    }
    $templates = [
        'Not ' . $base,
        strtoupper($base),
        strtolower($base),
        $base . 'ly',
        $base . 'er',
        'Another ' . $base,
        'Different ' . $base
    ];

    foreach ($templates as $template) {
        if (count($distractors) >= $needed) {
            break;
        }
        if (strcasecmp($template, $answer) !== 0) {
            $distractors[] = $template;
        }
    }

    while (count($distractors) < $needed) {
        $distractors[] = 'Choice ' . chr(65 + count($distractors));
    }

    return array_slice($distractors, 0, $needed);
}

function remui_kids_ddwtos_ai_is_list(array $value): bool {
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

