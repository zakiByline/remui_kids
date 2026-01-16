<?php
/**
 * QUICK INTEGRATION SCRIPT
 * 
 * This file contains the code you need to add to integrate AI Assistant
 * into your Code Editor module.
 * 
 * DO NOT RUN THIS FILE DIRECTLY!
 * Copy the function below into your lib.php file.
 */

// ============================================================================
// COPY THIS FUNCTION TO: iomad/mod/codeeditor/lib.php
// Add it at the END of the file
// ============================================================================

/**
 * Add AI Assistant chatbot to code editor pages
 * This integrates the local_aiassistant plugin into the code editor module
 *
 * @return void
 */
function codeeditor_add_ai_assistant() {
    global $PAGE, $OUTPUT, $USER, $CFG;

    // Check if user is logged in
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Check if AI Assistant plugin exists and is enabled
    if (!file_exists($CFG->dirroot . '/local/aiassistant/lib.php')) {
        return;
    }

    require_once($CFG->dirroot . '/local/aiassistant/lib.php');

    // Check if user has capability
    $context = context_system::instance();
    if (!has_capability('local/aiassistant:use', $context)) {
        return;
    }

    // Check if AI Assistant is enabled
    $enabled = get_config('local_aiassistant', 'enabled');
    if (!$enabled) {
        return;
    }

    // Load JavaScript module for floating chat
    $PAGE->requires->js_call_amd('local_aiassistant/chatbot', 'init');

    // Get user's first name for personalized greeting
    $firstname = !empty($USER->firstname) ? $USER->firstname : $USER->username;
    
    // Default coding-focused welcome message
    $welcomemessage = "<strong>Hello {$firstname}!</strong><br><br>I'm your AI coding assistant. I can help you with:<br>• Code debugging and error fixing<br>• Algorithm explanations<br>• Best practices and code optimization<br>• Language-specific guidance<br><br>What would you like help with?";

    // Try to get personalized greeting from AI assistant if available
    try {
        require_once($CFG->dirroot . '/local/aiassistant/classes/role_helper.php');
        $userrole = \local_aiassistant\role_helper::get_primary_role($USER->id);
        
        // Coding-focused role greetings
        $rolegreetings = [
            'admin' => 'I can help you with system configuration, debugging, and technical support for your code editor module.',
            'teacher' => 'As your coding assistant, I can help you create assignments, debug code, explain concepts, and provide code examples for your students.',
            'student' => 'I\'m here to help you with your coding assignments, debugging errors, understanding algorithms, and improving your code quality.',
            'companymanager' => 'I can assist you with training programs, code review, and technical documentation.',
            'guest' => 'I\'m here to help you learn coding and answer your programming questions.'
        ];
        
        $rolename = ucfirst($userrole);
        $greeting = isset($rolegreetings[$userrole]) ? $rolegreetings[$userrole] : $rolegreetings['guest'];
        
        // Create personalized welcome message with coding focus
        $welcomemessage = "<strong>Hello {$firstname}!</strong><br><br>{$greeting}<br><br>Ask me anything about coding, debugging, or programming!";
    } catch (\Exception $e) {
        // If role detection fails, use default coding-focused message
        debugging('Error detecting user role for AI assistant welcome message: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    // Render floating chat template
    echo $OUTPUT->render_from_template('local_aiassistant/floating_chatbot', [
        'username' => fullname($USER),
        'firstname' => $firstname,
        'userrole' => $rolename ?? 'User',
        'welcomemessage' => $welcomemessage
    ]);
}


// ============================================================================
// COPY THIS TO: iomad/mod/codeeditor/view.php
// Add it RIGHT BEFORE: echo $OUTPUT->footer();
// ============================================================================

/*
// Add AI Assistant chatbot
codeeditor_add_ai_assistant();

echo $OUTPUT->footer();
*/

