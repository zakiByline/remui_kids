# URGENT: Course Name Fix Required

## 🚨 **COURSE CONTEXT ISSUE IDENTIFIED**

The course column is now visible, but it's still showing the wrong course name. This means the course context detection during submission is failing.

---

## 🔍 **IMMEDIATE DEBUGGING STEPS:**

### **Step 1: Test Course Detection Script**
Visit: `http://localhost/kodeit/iomad/mod/codeeditor/test_course_detection.php`

This will show you:
- All Code Editor activities in your system
- Which courses they belong to
- Current URL and referer information

### **Step 2: Test Course Mapping**
Visit: `http://localhost/kodeit/iomad/mod/codeeditor/force_course_fix.php`

This will show you:
- Current submission-course mapping
- Which CodeEditor instances are being used
- Course information for each submission

### **Step 3: Check Browser Console**
1. **Open Developer Tools** (F12)
2. **Go to Console tab**
3. **Submit code from "Test 3" course**
4. **Look for these logs:**
   - `Current URL: ...`
   - `Extracted CMID: ...`
   - `Course detection data: ...`

### **Step 4: Check PHP Error Logs**
Check: `C:\wamp64\logs\php_error.log`

Look for:
- `=== SIMPLE SUBMIT DEBUG ===`
- `=== CMID DETECTION DEBUG ===`
- `CMID from data: ...`
- `CMID from referer: ...`
- `Final CMID: ...`
- `Found CodeEditor instance: ... in course: ...`

---

## 🎯 **LIKELY CAUSES:**

### **Cause 1: CMID Not Being Extracted**
- The URL doesn't contain the correct `id` parameter
- JavaScript isn't extracting the CMID properly
- The iframe is masking the URL context

### **Cause 2: Fallback to First Activity**
- CMID detection fails
- System falls back to first CodeEditor instance
- All submissions get linked to the same (first) activity

### **Cause 3: Multiple CodeEditor Activities**
- Multiple activities exist in different courses
- System is finding the wrong one due to ID conflicts

---

## 🔧 **QUICK FIX OPTIONS:**

### **Option 1: Manual CMID Override**
Add a hidden field in the Code Editor page to store the CMID:

```javascript
// In ide-master/index.html
const cmid = window.parent.location.search.match(/id=(\d+)/)?.[1];
```

### **Option 2: Session-Based Course Context**
Store the course context in the session when the page loads.

### **Option 3: URL Parameter Fix**
Ensure the iframe can access the parent window's URL parameters.

---

## 🚀 **IMMEDIATE ACTION REQUIRED:**

### **Please run these steps and share the results:**

1. **Run the course detection script** and share output
2. **Check browser console** when submitting and share logs
3. **Check PHP error logs** and share relevant entries
4. **Tell me the exact URL** you're using to access the Code Editor in "Test 3" course

### **Example of what I need:**
```
URL: http://localhost/kodeit/iomad/mod/codeeditor/view.php?id=123
Console logs: Current URL: ..., Extracted CMID: ...
PHP logs: Final CMID: 123, Found CodeEditor instance: 5 in course: 3
```

---

## 🎯 **EXPECTED VS ACTUAL:**

### **Expected:**
- CMID should be extracted from URL (e.g., `id=123`)
- CodeEditor instance should be found for that CMID
- Course should be "Test 3" (course ID 3)

### **Actual (Current Issue):**
- CMID is probably NULL or wrong
- System falls back to first CodeEditor instance
- Course shows as "Test 5" (course ID 5)

---

## ⚡ **URGENT NEXT STEPS:**

1. **Run the debug scripts** above
2. **Share the output** with me
3. **I'll provide a targeted fix** based on the results

**The issue is in the course context detection - we need to identify exactly where it's failing!** 🚨

