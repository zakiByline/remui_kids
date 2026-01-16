# Submit Button Debugging Guide

## 🔍 Current Issue

**Error:** `editor.getValue is not a function`
**Cause:** submit_bridge.js trying to access editor before it's ready

## ✅ Fixes Applied

### 1. **Wait for Editor to Load**
- Bridge script now waits for `editor` to be defined
- Retries every 1 second until ready
- Only starts monitoring after editor is ready

### 2. **Multiple Extraction Methods**
- Tries 6 different ways to get code
- Falls back if one method fails
- Logs which method succeeded

### 3. **Enhanced Console Logging**
- Every step logged to console
- Easy to see where it's failing

## 🚀 How to Debug

### Step 1: Clear ALL Caches

**Browser Cache:**
```
Ctrl + Shift + R (Windows)
Cmd + Shift + R (Mac)
```

**Moodle Cache:**
```
Site Administration > Development > Purge all caches
```

### Step 2: Open Code Editor Activity

### Step 3: Open Browser Console
```
Press F12
Go to Console tab
```

### Step 4: Check Console Messages

You should see:
```
Code Editor Submit Bridge v2.0 loaded ✅
Editor available: true ✅
Monaco available: true ✅
Editor is ready, starting monitoring ✅
```

**If you see:**
```
Editor available: false ❌
```
→ **Wait longer** - Editor still loading

### Step 5: Write Code in IDE

```javascript
console.log("Hello World");
```

### Step 6: Run Code

Click "Run Code" button in IDE

### Step 7: Check Console Again

After running, you should see:
```
Got code from editor.getValue(): 28 characters ✅
Got output from selector #terminal-output: 11 characters ✅
Code/Output changed - sending update to parent ✅
```

### Step 8: Click "Submit Code" Button

Watch console for:
```
Received code data from IDE: {code: "console.log...", output: "Hello World"} ✅
Current codeEditorData: {code: "...", output: "...", language: "javascript"} ✅
Code length: 28 ✅
Output length: 11 ✅
Sending submission to server... ✅
Response received. Status: 200 ✅
✅ CODE SUBMITTED SUCCESSFULLY! ✅
```

## 🐛 Common Issues

### Issue 1: "editor.getValue is not a function"

**Fixed!** The new code waits for editor to load.

**To verify fix worked:**
- Open Console (F12)
- Look for: "Editor is ready, starting monitoring"
- Should appear after a few seconds

### Issue 2: "No code detected"

**Possible causes:**
- Code not extracted from editor
- postMessage not working

**Debug:**
1. Open Console in **IDE iframe**:
   - Right-click inside IDE
   - Select "Inspect"
   - Go to Console tab
2. Type: `editor.getValue()`
3. Should show your code

**If code shows:** Bridge can access it
**If error:** Editor not initialized properly

### Issue 3: "Cannot access iframe"

**Cause:** Same-origin restrictions

**Fix:** Already applied - removed sandbox restriction

### Issue 4: Code submitted but shows as empty

**Cause:** Code extracted but empty

**Debug:**
- Check: `window.codeEditorData` in main page console
- Should show your code
- If empty, postMessage not working

## 📊 Detailed Debugging Steps

### Test 1: Is Editor Ready?

**In IDE iframe console:**
```javascript
typeof editor
// Should return: "object"

editor.getValue()
// Should return your code

editor.getModel().getLanguageId()
// Should return: "javascript" or "python"
```

### Test 2: Is Bridge Script Loaded?

**In IDE iframe console:**
```javascript
typeof getEditorCode
// Should return: "function"

getEditorCode()
// Should return your code

getTerminalOutput()
// Should return terminal output
```

### Test 3: Is postMessage Working?

**In main page console:**
```javascript
window.codeEditorData
// Should show: {code: "...", output: "...", language: "..."}
```

### Test 4: Manual Submission Test

**In main page console:**
```javascript
// Manually trigger submission
var xhr = new XMLHttpRequest();
xhr.open('POST', '/mod/codeeditor/submit_code.php', true);
xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
xhr.onload = function() { console.log(xhr.responseText); };
xhr.send('cmid=YOUR_CM_ID&code=console.log("test")&language=javascript&output=test&sesskey=' + M.cfg.sesskey);
```

Replace `YOUR_CM_ID` with actual CM ID from URL

## ✅ Expected Console Output

### When Page Loads:
```
[Iframe] Code Editor Submit Bridge v2.0 loaded
[Iframe] Editor available: false
[Iframe] Monaco available: true
[Iframe] Editor not ready yet, retrying in 1 second...
[Iframe] Editor is ready, starting monitoring ✅
[Parent] Received code data from IDE: {code: "", output: "", language: "javascript"}
```

### After Writing Code:
```
[Iframe] Got code from editor.getValue(): 28 characters ✅
[Iframe] Got output from selector #terminal-output: 0 characters
[Iframe] Code/Output changed - sending update to parent
[Parent] Received code data from IDE: {code: "console.log...", output: "", ...}
```

### After Running Code:
```
[Iframe] Got code from editor.getValue(): 28 characters ✅
[Iframe] Got output from selector #terminal-output: 11 characters ✅
[Iframe] Code/Output changed - sending update to parent
[Parent] Received code data from IDE: {code: "console.log...", output: "Hello World", ...}
```

### When Clicking Submit:
```
[Parent] Current codeEditorData: {code: "console.log...", output: "Hello World", language: "javascript"}
[Parent] Code length: 28
[Parent] Output length: 11
[Parent] Sending submission to server...
[Parent] Response received. Status: 200
[Parent] Parsed response: {success: true, submissionid: 123, ...}
✅ CODE SUBMITTED SUCCESSFULLY!
```

## 🔧 Quick Fixes

### If "editor not ready" persists:

**Edit submit_bridge.js line 247:**
```javascript
// OLD
setTimeout(startMonitoring, 1000);

// NEW (wait longer)
setTimeout(startMonitoring, 2000);
```

### If code still not extracting:

**In IDE iframe, find where editor is created and add:**
```javascript
// After: editor = monaco.editor.create(...)
window.editor = editor; // Make it globally accessible
console.log('Editor created and exposed globally');
```

## 📝 Testing Checklist

- [ ] Clear browser cache (Ctrl+Shift+R)
- [ ] Clear Moodle cache
- [ ] Open Code Editor activity
- [ ] Open browser console (F12)
- [ ] Check for "Submit Bridge v2.0 loaded"
- [ ] Check for "Editor is ready"
- [ ] Write code in editor
- [ ] Run code
- [ ] Check console shows code/output detected
- [ ] Click "Submit Code" button
- [ ] Check console for submission messages
- [ ] Should see success popup
- [ ] Page should reload
- [ ] Submission should appear in status

## 🆘 If Still Not Working

### Share Console Output:

1. Open Console (F12)
2. Click "Submit Code"
3. Copy ALL console messages
4. Share them

This will show exactly where it's failing!

---

**Files Updated:**
- ✅ view.php - Enhanced debugging
- ✅ submit_bridge.js - Wait for editor, multiple methods
- ✅ test_submit.html - Diagnostic page

**Try again with console open!** The detailed logs will show exactly what's happening.




