# AI Assistant Integration for Code Editor Module

This guide shows you how to add the AI Assistant chatbot button to your Code Editor module.

## Quick Integration Steps

### Step 1: Add Function to lib.php

Add the AI assistant function to your `lib.php` file. You can either:

**Option A: Copy the function directly**
1. Open `iomad/mod/codeeditor/lib.php`
2. Add this function at the end (before the closing `?>` or at the end of the file):

```php
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
```

**Option B: Include the separate file**
1. In your `lib.php`, add at the end:
```php
require_once(__DIR__ . '/lib_ai_integration.php');
```

### Step 2: Add Call in view.php

Open `iomad/mod/codeeditor/view.php` and find the line that outputs the footer:

```php
echo $OUTPUT->footer();
```

**Add this line RIGHT BEFORE the footer:**

```php
// Add AI Assistant chatbot
codeeditor_add_ai_assistant();

echo $OUTPUT->footer();
```

### Step 3: Clear Caches

After making these changes:

1. **Purge all caches:**
   - Go to: **Site administration → Development → Purge all caches**
   - Or run: `php admin/cli/purge_caches.php`

2. **Test the integration:**
   - Visit any Code Editor activity in your course
   - You should see a floating AI Assistant button (chat icon) in the bottom-right corner
   - Click it to open the chatbot!

## What You'll See

- **Floating Button:** A blue circular button with a chat icon appears in the bottom-right corner of the code editor page
- **Chat Window:** Click the button to open a chat window where you can ask coding questions
- **Coding-Focused:** The AI assistant will have a coding-focused welcome message tailored to your role

## Troubleshooting

### AI Assistant button doesn't appear:
1. ✅ Check that `local_aiassistant` plugin is installed and enabled
2. ✅ Check that user has `local/aiassistant:use` capability
3. ✅ Check that AI Assistant is enabled in plugin settings
4. ✅ Clear all caches

### Function not found error:
- Make sure you added the function to `lib.php` correctly
- Check that there are no PHP syntax errors

### Chatbot doesn't respond:
- Check that the AI Assistant API key is configured in plugin settings
- Check browser console for JavaScript errors

## Files Modified

- `iomad/mod/codeeditor/lib.php` - Added `codeeditor_add_ai_assistant()` function
- `iomad/mod/codeeditor/view.php` - Added call to `codeeditor_add_ai_assistant()` before footer

## That's It!

Your Code Editor module now has an AI Assistant integrated! Students and teachers can get coding help directly from the code editor page.

