<?php
/**
 * External function to analyze code using AI
 *
 * @package    mod_codeeditor
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_codeeditor\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/aiassistant/classes/gemini_api.php');

/**
 * Code analysis external function
 */
class analyze_code extends \external_api {

    /**
     * Returns description of method parameters
     * @return \external_function_parameters
     */
    public static function analyze_code_parameters() {
        return new \external_function_parameters([
            'code' => new \external_value(PARAM_RAW, 'The code to analyze'),
            'language' => new \external_value(PARAM_TEXT, 'Programming language'),
            'output' => new \external_value(PARAM_RAW, 'Code output', VALUE_DEFAULT, ''),
            'context' => new \external_value(PARAM_TEXT, 'Additional context', VALUE_DEFAULT, ''),
            'assignment_question' => new \external_value(PARAM_RAW, 'Assignment question/instructions', VALUE_DEFAULT, '')
        ]);
    }

    /**
     * Analyze code using AI
     * @param string $code
     * @param string $language
     * @param string $output
     * @param string $context
     * @param string $assignment_question
     * @return array
     */
    public static function analyze_code($code, $language, $output = '', $context = '', $assignment_question = '') {
        global $USER, $DB;

        // Validate parameters
        $params = self::validate_parameters(self::analyze_code_parameters(), [
            'code' => $code,
            'language' => $language,
            'output' => $output,
            'context' => $context,
            'assignment_question' => $assignment_question
        ]);

        // Check if AI Assistant is configured
        $apikey = get_config('local_aiassistant', 'apikey');
        if (empty($apikey)) {
            return [
                'success' => false,
                'analysis' => 'AI Assistant is not configured. Please contact your administrator.',
                'error' => 'no_api_key',
                'suggested_grade' => 0,
                'brief_feedback' => '',
                'plagiarism_risk' => 'UNKNOWN',
                'ai_generated_probability' => 'UNKNOWN'
            ];
        }

        // Get max grade from context if available (default 100)
        $maxgrade = 100;
        if (strpos($context, 'maxgrade:') !== false) {
            preg_match('/maxgrade:(\d+)/', $context, $matches);
            if (!empty($matches[1])) {
                $maxgrade = intval($matches[1]);
            }
        }

        // Build analysis prompt with grading and assignment question
        $analysis_prompt = self::build_analysis_prompt($params['code'], $params['language'], $params['output'], $params['context'], $maxgrade, $params['assignment_question']);

        // Call Gemini API
        try {
            $model = get_config('local_aiassistant', 'model') ?: 'gemini-2.0-flash-exp';
            $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apikey}";
            
            $data = [
                'contents' => [[
                    'parts' => [[
                        'text' => $analysis_prompt
                    ]]
                ]],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 2048
                ]
            ];

            $payload = json_encode($data);

            $curl = new \curl([
                'CURLOPT_TIMEOUT' => 30,
                'CURLOPT_CONNECTTIMEOUT' => 10
            ]);
            
            $curl->setHeader(['Content-Type: application/json']);
            $response = $curl->post($url, $payload);
            
            if ($curl->get_errno()) {
                throw new \moodle_exception('curlerror', 'error', '', null, $curl->error);
            }

            $result = json_decode($response, true);

            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                $analysis = $result['candidates'][0]['content']['parts'][0]['text'];
                
                // Extract suggested grade, plagiarism info, and brief feedback
                $suggested_grade = 0;
                $brief_feedback = '';
                $plagiarism_risk = 'LOW';
                $ai_generated_probability = 'LOW';
                
                // Parse SUGGESTED_GRADE
                if (preg_match('/SUGGESTED_GRADE:\s*(\d+(?:\.\d+)?)/i', $analysis, $matches)) {
                    $suggested_grade = floatval($matches[1]);
                    // Ensure grade doesn't exceed max
                    $suggested_grade = min($suggested_grade, $maxgrade);
                }
                
                // Parse PLAGIARISM_RISK
                if (preg_match('/PLAGIARISM_RISK:\s*(LOW|MODERATE|HIGH)/i', $analysis, $matches)) {
                    $plagiarism_risk = strtoupper($matches[1]);
                }
                
                // Parse AI_GENERATED_PROBABILITY
                if (preg_match('/AI_GENERATED_PROBABILITY:\s*(LOW|MODERATE|HIGH)/i', $analysis, $matches)) {
                    $ai_generated_probability = strtoupper($matches[1]);
                }
                
                // Parse BRIEF_FEEDBACK
                if (preg_match('/BRIEF_FEEDBACK:\s*(.+?)(?=\n---|\n##|\n\n|$)/s', $analysis, $matches)) {
                    $brief_feedback = trim($matches[1]);
                }
                
                return [
                    'success' => true,
                    'analysis' => $analysis,
                    'suggested_grade' => $suggested_grade,
                    'brief_feedback' => $brief_feedback,
                    'plagiarism_risk' => $plagiarism_risk,
                    'ai_generated_probability' => $ai_generated_probability,
                    'error' => null
                ];
            } else {
                throw new \moodle_exception('no_response', 'mod_codeeditor', '', null, 'No response from AI');
            }

        } catch (\Exception $e) {
            debugging('AI Analysis Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [
                'success' => false,
                'analysis' => 'Error analyzing code: ' . $e->getMessage(),
                'error' => 'api_error',
                'suggested_grade' => 0,
                'brief_feedback' => '',
                'plagiarism_risk' => 'UNKNOWN',
                'ai_generated_probability' => 'UNKNOWN'
            ];
        }
    }

    /**
     * Build analysis prompt with grade calculation
     * @param string $code
     * @param string $language
     * @param string $output
     * @param string $context
     * @param int $maxgrade
     * @param string $assignment_question
     * @return string
     */
    private static function build_analysis_prompt($code, $language, $output, $context, $maxgrade = 100, $assignment_question = '') {
        $prompt = "You are an expert code reviewer, educator, and academic integrity specialist. Analyze the following student code submission and provide a comprehensive evaluation with a suggested grade AND plagiarism/AI-generation detection.\n\n";
        
        // Add assignment question if provided
        if (!empty($assignment_question)) {
            $prompt .= "=== ASSIGNMENT QUESTION/REQUIREMENTS ===\n";
            $prompt .= strip_tags($assignment_question) . "\n\n";
            $prompt .= "IMPORTANT: Evaluate if the submitted code correctly addresses the assignment requirements above.\n\n";
        }
        
        $prompt .= "=== CODE SUBMISSION ===\n";
        $prompt .= "Programming Language: {$language}\n\n";
        $prompt .= "Code:\n```{$language}\n{$code}\n```\n\n";
        
        if (!empty($output)) {
            $prompt .= "Program Output:\n```\n{$output}\n```\n\n";
        } else {
            $prompt .= "Note: No output was captured for this submission.\n\n";
        }
        
        if (!empty($context)) {
            $prompt .= "Additional Context: {$context}\n\n";
        }
        
        $prompt .= "=== PLAGIARISM & AI DETECTION ===\n";
        $prompt .= "Analyze the code for signs of AI generation or copying:\n\n";
        $prompt .= "**AI-Generated Code Indicators:**\n";
        $prompt .= "- Overly perfect/polished code structure for a beginner\n";
        $prompt .= "- Excessive or AI-style comments (e.g., 'This function does X')\n";
        $prompt .= "- Sophisticated error handling beyond student level\n";
        $prompt .= "- Complex patterns not taught in course\n";
        $prompt .= "- Consistent naming conventions typical of AI (e.g., camelCase perfection)\n";
        $prompt .= "- Advanced features or libraries not covered in curriculum\n";
        $prompt .= "- Lack of typical student mistakes or learning patterns\n\n";
        
        $prompt .= "**Copying/Plagiarism Indicators:**\n";
        $prompt .= "- Code structure matches common online tutorials exactly\n";
        $prompt .= "- Comments or variable names in different style than student's usual work\n";
        $prompt .= "- Inconsistent coding style within submission\n";
        $prompt .= "- Advanced techniques not typical for the assignment level\n\n";
        
        $prompt .= "**Authenticity Indicators (Good Signs):**\n";
        $prompt .= "- Minor syntax errors or typical student mistakes\n";
        $prompt .= "- Unique problem-solving approach\n";
        $prompt .= "- Simple but functional solutions\n";
        $prompt .= "- Personal variable naming or comments\n";
        $prompt .= "- Appropriate complexity for assignment level\n\n";
        
        $prompt .= "=== GRADING CRITERIA (Maximum Grade: {$maxgrade}) ===\n";
        $prompt .= "Evaluate based on:\n";
        if (!empty($assignment_question)) {
            $prompt .= "- Requirement Fulfillment (30%): Does code solve the assigned task correctly?\n";
            $prompt .= "- Code Correctness (25%): Syntax, logic, functionality\n";
            $prompt .= "- Code Quality (20%): Structure, best practices, efficiency\n";
            $prompt .= "- Code Style (10%): Readability, naming, formatting\n";
            $prompt .= "- Originality (15%): Authenticity and personal approach\n\n";
        } else {
            $prompt .= "- Code Correctness (35%): Syntax, logic, functionality\n";
            $prompt .= "- Code Quality (25%): Structure, best practices, efficiency\n";
            $prompt .= "- Code Style (15%): Readability, naming, formatting\n";
            $prompt .= "- Output/Results (10%): Expected vs actual output\n";
            $prompt .= "- Originality (15%): Authenticity and personal approach\n\n";
        }
        
        $prompt .= "=== REQUIRED OUTPUT FORMAT ===\n";
        $prompt .= "You MUST start your response with exactly this format:\n\n";
        $prompt .= "SUGGESTED_GRADE: [number between 0 and {$maxgrade}]\n";
        $prompt .= "PLAGIARISM_RISK: [LOW/MODERATE/HIGH]\n";
        $prompt .= "AI_GENERATED_PROBABILITY: [LOW/MODERATE/HIGH]\n";
        $prompt .= "BRIEF_FEEDBACK: [2-3 sentence summary for student]\n";
        $prompt .= "---\n\n";
        $prompt .= "Then provide detailed analysis:\n\n";
        
        $prompt .= "IMPORTANT: Do NOT use any emojis in your response. Use clear, professional language only.\n\n";
        
        $prompt .= "## 0. Academic Integrity Assessment\n";
        $prompt .= "[Analyze plagiarism risk and AI-generation probability with specific evidence]\n\n";
        
        $prompt .= "## 1. Assignment Requirements Analysis\n";
        if (!empty($assignment_question)) {
            $prompt .= "[Does the code fulfill the assignment requirements? What was asked vs what was delivered?]\n\n";
        } else {
            $prompt .= "[General functionality assessment]\n\n";
        }
        
        $prompt .= "## 2. Code Quality & Correctness\n";
        $prompt .= "[Your analysis of syntax, logic, best practices]\n\n";
        
        $prompt .= "## 3. Functionality Assessment\n";
        $prompt .= "[Analysis of output and functionality]\n\n";
        
        $prompt .= "## 4. Code Style & Readability\n";
        $prompt .= "[Analysis of naming, formatting, clarity]\n\n";
        
        $prompt .= "## 5. Strengths\n";
        $prompt .= "[What the student did well]\n\n";
        
        $prompt .= "## 6. Areas for Improvement\n";
        $prompt .= "[Specific suggestions for learning]\n\n";
        
        $prompt .= "## 7. Grade Breakdown\n";
        if (!empty($assignment_question)) {
            $prompt .= "[Explain how you calculated the suggested grade:\n";
            $prompt .= "- Requirement Fulfillment (30%): X/30\n";
            $prompt .= "- Code Correctness (25%): X/25\n";
            $prompt .= "- Code Quality (20%): X/20\n";
            $prompt .= "- Code Style (10%): X/10\n";
            $prompt .= "- Originality (15%): X/15\n";
            $prompt .= "Total: X/{$maxgrade}]\n\n";
        } else {
            $prompt .= "[Explain how you calculated the suggested grade including originality score]\n\n";
        }
        
        $prompt .= "## 8. Recommendations\n";
        $prompt .= "[If plagiarism/AI risk is moderate or high, recommend follow-up actions]\n\n";
        
        $prompt .= "CRITICAL INSTRUCTIONS:\n";
        $prompt .= "- Always start with SUGGESTED_GRADE, PLAGIARISM_RISK, AI_GENERATED_PROBABILITY, and BRIEF_FEEDBACK\n";
        $prompt .= "- Be specific about evidence for plagiarism/AI detection\n";
        $prompt .= "- If risk is HIGH, deduct points from originality score\n";
        $prompt .= "- Be fair but vigilant about academic integrity\n";
        $prompt .= "- Consider that some 'perfect' code might just be from a good student\n";
        $prompt .= "- Do NOT use emojis - use professional language and Font Awesome icon names if visual indicators are needed\n\n";
        
        $prompt .= "Begin your analysis:";
        
        return $prompt;
    }

    /**
     * Returns description of method result value
     * @return \external_single_structure
     */
    public static function analyze_code_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Whether the analysis was successful'),
            'analysis' => new \external_value(PARAM_RAW, 'The AI analysis result'),
            'suggested_grade' => new \external_value(PARAM_FLOAT, 'AI suggested grade', VALUE_DEFAULT, 0),
            'brief_feedback' => new \external_value(PARAM_RAW, 'Brief feedback summary', VALUE_DEFAULT, ''),
            'plagiarism_risk' => new \external_value(PARAM_TEXT, 'Plagiarism risk level (LOW/MODERATE/HIGH)', VALUE_DEFAULT, 'LOW'),
            'ai_generated_probability' => new \external_value(PARAM_TEXT, 'AI-generated code probability (LOW/MODERATE/HIGH)', VALUE_DEFAULT, 'LOW'),
            'error' => new \external_value(PARAM_TEXT, 'Error message if any', VALUE_DEFAULT, null)
        ]);
    }
}

