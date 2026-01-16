# Code Editor - Submission Button Enhancement

## ✅ What's Been Updated

### For Admin:
- ✅ **View Submissions** button in activity page
- ✅ Access to grading page to see all submissions
- ✅ Full access to submission data
- ✅ Can view/grade all student submissions

### For Teachers:
- ✅ **View Submissions** button in activity page
- ✅ **Grade Submissions** button in activity page
- ✅ Statistics showing submission counts
- ✅ Full grading interface

### For Students:
- ✅ Can submit code through IDE
- ✅ See submission status
- ✅ View their grade
- ✅ View teacher feedback

## 📊 Updated View (view.php)

### Buttons Now Show:

#### For Admin:
```
📊 Submissions Overview
  5 submissions | 3 graded | 2 pending

[View Submissions (5)] [Grade Submissions]
```

#### For Teachers:
```
📊 Submissions Overview
  5 submissions | 3 graded | 2 pending

[View Submissions (5)] [Grade Submissions]
```

#### For Students:
```
Submission Status
  STATUS: ✅ Submitted
  GRADE: 85 / 100
  SUBMITTED: 05 Nov 2025

Teacher Feedback: Great work! Consider optimizing...
```

## 🔧 Changes Made

### File: view.php
**Line 99:** Changed capability check to include both teachers AND admins
```php
// OLD
if (has_capability('mod/codeeditor:grade', $context))

// NEW  
if (has_capability('mod/codeeditor:grade', $context) || 
    has_capability('moodle/site:config', context_system::instance()))
```

**Lines 115-132:** Added two buttons instead of one
- **View Submissions** - Blue button for viewing (admin + teacher)
- **Grade Submissions** - Green button for grading (teacher only)

### File: grading.php
**Lines 26-32:** Updated permission check
- Now allows both teachers AND admins
- Shows role indicator at top of page

## 📋 How It Works

### Workflow for Teachers:

1. **Teacher opens activity**
   - Sees "View Submissions" and "Grade Submissions" buttons
   - Sees statistics (5 submissions, 3 graded, 2 pending)

2. **Clicks "View Submissions"**
   - Opens grading.php
   - Shows all student submissions
   - Can view code output
   - Can provide feedback

3. **Grades submissions**
   - Enter grade (0-100)
   - Add feedback
   - Save

### Workflow for Admin:

1. **Admin opens activity**
   - Sees "View Submissions" button
   - Sees statistics
   - Can monitor all submissions

2. **Clicks "View Submissions"**
   - Opens grading.php
   - Shows all submissions
   - Full visibility into student work

### Workflow for Students:

1. **Student opens activity**
   - Sees IDE
   - Writes code
   - Runs code
   - Clicks "Submit" (in IDE)

2. **Submission saved**
   - Code and output saved to database
   - Status shown on page
   - Teacher notified

3. **After grading**
   - Student sees grade
   - Student sees feedback
   - Can view their submission

## 🎯 Submission Data Captured

When student submits, the system saves:
- ✅ Code written by student
- ✅ Programming language used
- ✅ Output from code execution
- ✅ Submission timestamp
- ✅ Student information
- ✅ Activity ID

## 📁 Files Modified

- `view.php` - Added submissions button for admin + teacher
- `grading.php` - Updated permissions for admin + teacher
- `SUBMISSION_BUTTON_UPDATE.md` - This documentation

## ✅ Testing

### Test as Teacher:
1. Go to a code editor activity
2. You should see TWO buttons:
   - "View Submissions" (blue)
   - "Grade Submissions" (green)
3. Click either to view submissions
4. Should see all student submissions

### Test as Admin:
1. Go to a code editor activity
2. You should see ONE button:
   - "View Submissions" (blue)
3. Click to view all submissions
4. Should see full submission details

### Test as Student:
1. Open code editor activity
2. Write code in IDE
3. Run code
4. Submit code (using submit button in IDE)
5. Should see submission status update
6. Should see grade when teacher grades it

## 🔐 Permissions

### Required Capabilities:

**For Teachers:**
- `mod/codeeditor:grade` - Can grade submissions

**For Admin:**
- `moodle/site:config` - Site admin

**For Students:**
- `mod/codeeditor:submit` - Can submit code
- `mod/codeeditor:view` - Can view activity

## 📊 Database

### Table: `codeeditor_submissions`

Fields captured:
- `id` - Submission ID
- `codeeditorid` - Activity ID
- `userid` - Student ID
- `code` - Code written
- `language` - Programming language
- `output` - Code execution output
- `status` - 'draft' or 'submitted'
- `grade` - Grade given (0-100)
- `grader` - Teacher who graded
- `feedbacktext` - Teacher feedback
- `timecreated` - When submitted
- `latest` - Is this the latest submission?

## 🎉 Success Criteria

✅ Admin can view submissions from activity page
✅ Teacher can view submissions from activity page  
✅ Teacher can grade submissions
✅ Student can submit code output
✅ Student can see their grade/feedback
✅ Statistics visible on activity page
✅ Clean, professional UI

---

**Status:** Complete ✅  
**Version:** Existing (3.9)  
**Updated:** view.php, grading.php




