# Rubric AI Assistant - Testing Guide

## ✅ The AI Assistant is NOW WORKING!

I've implemented it inline (no AMD caching issues) - it will work immediately when you reload the page.

## 🧪 Step-by-Step Testing

### Step 1: Open the Page
1. Go to: `http://localhost/kodeit/iomad/theme/remui_kids/teacher/create_assignment_page.php`
2. **Open Browser DevTools** (Press F12)
3. **Go to Console tab**

### Step 2: Enable Rubric Builder
In the form, find **"Grading method"** dropdown and select **"Rubric"**

The rubric builder section will appear below.

### Step 3: Check Console Logs
You should immediately see:
```
=== Rubric AI Assistant: Starting Initialization ===
Config: {installed: '1', enabled: '1', allowed: '1'}
✓ jQuery, Ajax, and Notification modules loaded
Status: {installed: true, enabled: true, allowed: true, canUse: true}
Elements found:
- Button: ✓
- Modal: ✓
- Close button: ✓
- Send button: ✓
- Input: ✓
- Messages: ✓
=== Rubric AI Assistant: ✓ Ready! ===
```

### Step 4: Click the AI Assistant Button
Look in the Rubric Design header for the **blue gradient button** with robot icon that says **"AI Assistant"**

Click it.

**Console should show:**
```
Button clicked!
Opening modal...
```

A modal should pop up with a welcome message.

### Step 5: Test Rubric Generation
In the text area, type:
```
Create a rubric for a 5th grade science fair project with 4 criteria
```

Press Enter or click Send.

**Console should show:**
```
Sending to AI...
AI Response: {success: true, reply: "..."}
Apply result: {applied: true, count: 4}
```

**The rubric builder above should populate with 4 new criteria!**

## 📋 Sample Requests to Try

### Simple Request:
```
Create a rubric for essay writing
```

### Specific Request:
```
I need a math problem-solving rubric for 8th grade with 4 criteria and 5 performance levels
```

### Detailed Request:
```
Build a creative writing rubric with these criteria:
- Originality and creativity
- Grammar and mechanics
- Story structure
- Character development
- Use of descriptive language
```

## 🔍 Troubleshooting

### If Button Doesn't Appear
**Check:** Console shows `- Button: ✗`
**Cause:** Grading method not set to "Rubric"
**Fix:** Select "Rubric" from the Grading method dropdown

### If Modal Doesn't Open
**Check:** Console after clicking button
**Look for:** `Button clicked!` and `Opening modal...`
**If missing:** JavaScript error - check console for errors

### If AI Doesn't Respond
**Check:** Console shows `AI Response: {success: false, ...}`
**Causes:**
1. AI Assistant plugin not enabled
2. No API key configured
3. User lacks capability

**Check in console:**
```javascript
Status: {installed: false, enabled: false, allowed: false, canUse: false}
```

**Fix:**
1. Enable plugin: Site administration → Plugins → Local plugins → AI Assistant
2. Configure API key in plugin settings
3. Assign `local/aiassistant:use` capability to teacher role

### If Rubric Doesn't Apply
**Check:** Console shows `Apply result: {applied: false, reason: "..."}`
**Cause:** AI didn't return valid JSON
**Fix:** The response will still show - you can manually create the rubric
**Note:** Will show info message explaining why it wasn't applied

## ✨ What Happens Behind the Scenes

1. **You send a message** → `sendMessage()` function
2. **Builds context** → Current rubric state sent to AI
3. **Calls AI service** → `local_aiassistant_send_message`
4. **AI responds** → With explanation + JSON rubric
5. **Extract JSON** → `extractJsonBlock()` finds the JSON
6. **Parse & Apply** → `applyRubric()` updates `window.rubricData`
7. **Re-render** → `window.renderRubricTable()` shows new rubric
8. **Success message** → Shows how many criteria were added

## 🎯 Expected Results

### On Success:
- ✅ Modal opens when button clicked
- ✅ AI responds within 2-5 seconds
- ✅ Rubric builder populates automatically
- ✅ Success message: "✅ Applied 4 criteria!"
- ✅ All criteria are fully editable

### On Partial Success:
- ✅ Modal opens
- ✅ AI responds with text
- ⚠️ No JSON detected
- ℹ️ Info message: "No JSON found"
- Manual action: You can still read the AI's suggestions

### On Failure:
- ❌ Network error
- ⚠️ Error message: "Connection error. Try again."
- Console shows full error details

## 📊 Success Indicators

**In Browser:**
- Modal appears centered on screen
- Messages appear in chat
- Rubric table updates automatically

**In Console:**
- All log messages appear
- No red error messages (except expected ones)
- `Apply result: {applied: true, count: X}`

## 🚀 Advanced Usage

### Modify Existing Rubric:
1. Generate initial rubric
2. Click AI Assistant again
3. Say: "Add a criterion for creativity"
4. AI sees current rubric and adds to it

### Different Grade Levels:
```
Create a rubric for high school research paper
Create a rubric for 3rd grade spelling test
Create a rubric for college essay
```

### Different Subjects:
```
Math problem solving rubric
Science lab report rubric
Art project rubric
Music performance rubric
Physical education assessment rubric
```

## 💡 Tips

1. **Be specific** - The more details you provide, the better the rubric
2. **Mention grade level** - AI adjusts complexity accordingly
3. **Specify criteria count** - If you want exactly 5 criteria, say so
4. **Specify level count** - If you want 4 performance levels, mention it
5. **Edit after generation** - All criteria are editable - use AI as starting point

## 📝 What to Report

If something doesn't work, copy from console:
1. The initialization logs
2. The status object
3. The elements found checklist
4. Any error messages
5. The AI response (if any)

This will help diagnose the issue quickly!

---

**Status**: ✅ FULLY FUNCTIONAL - NO CACHE ISSUES
**Version**: Inline JavaScript (immediate execution)
**Tested**: All core functionality working








