# Code Editor Module - Grading & Assessment Features Upgrade

**Version:** 2.0 (2025102900)  
**Date:** October 29, 2025  
**Status:** ✅ COMPLETE

---

## 🎯 Overview

The Code Editor module has been significantly upgraded to include comprehensive grading, rubrics, and competencies support - matching the functionality of the Assignment module.

---

## ✅ Features Added

### 1. **Grading System**
- ✅ Full gradebook integration
- ✅ Maximum grade configuration
- ✅ Individual student grading
- ✅ Feedback comments for students
- ✅ Grading history tracking

### 2. **Rubric Support**
- ✅ Advanced grading with rubrics
- ✅ Criteria-based assessment
- ✅ Automatic grade calculation from rubric scores
- ✅ Rubric builder in activity settings
- ✅ Visual rubric grading interface

### 3. **Competency Mapping**
- ✅ Link activities to course competencies
- ✅ Track competency achievement
- ✅ Automatic competency completion on activity completion
- ✅ Integration with Moodle's competency framework

### 4. **Availability & Due Dates**
- ✅ Allow submissions from date
- ✅ Due date configuration
- ✅ Cut-off date (no late submissions)
- ✅ Visual indicators for overdue submissions
- ✅ Automatic timezone handling

### 5. **Submission Management**
- ✅ Draft vs Submitted status
- ✅ Multiple attempt tracking
- ✅ Latest submission flagging
- ✅ Submission history
- ✅ Optional "submit button" requirement

---

## 📁 Files Modified/Created

### **Database Schema**
- ✅ `db/install.xml` - Added grading fields to tables
- ✅ `db/upgrade.php` - Upgrade script for existing installations
- ✅ `db/access.php` - Added grading capabilities
- ✅ `version.php` - Updated to v2.0 (2025102900)

### **Core Files**
- ✅ `lib.php` - Added grading functions and feature support
- ✅ `mod_form.php` - Added grading section, dates, and settings
- ✅ `view.php` - Added submission status and grading interface

### **New Grading Pages**
- ✅ `grading.php` - Main grading interface for teachers
- ✅ `grade_submission.php` - Individual submission grading form

---

## 🗄️ Database Changes

### **codeeditor Table - New Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `grade` | INT(10) | Maximum grade (default: 100) |
| `duedate` | INT(10) | Unix timestamp for due date |
| `cutoffdate` | INT(10) | Unix timestamp for cut-off date |
| `allowsubmissionsfromdate` | INT(10) | Unix timestamp when submissions open |
| `requiresubmit` | INT(2) | Require explicit submit (1=yes, 0=auto-save) |

### **codeeditor_submissions Table - New Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `status` | CHAR(20) | 'draft' or 'submitted' |
| `grade` | NUMBER(10,5) | Grade received |
| `grader` | INT(10) | User ID of grader |
| `feedbacktext` | TEXT | Teacher feedback |
| `feedbackformat` | INT(4) | Format of feedback |
| `attemptnumber` | INT(10) | Attempt number |
| `latest` | INT(2) | Is latest submission (1=yes) |
| `timemodified` | INT(10) | Last modification time |
| `timegraded` | INT(10) | When graded |

---

## 🔧 Installation Steps

### **For New Installations:**
1. The schema will be automatically created
2. All features will be available immediately

### **For Existing Installations:**
1. Go to: **Site Administration → Notifications**
2. Click **"Upgrade Moodle database now"**
3. The upgrade script will add all new fields automatically
4. All existing code editor activities will get default values

---

## 👥 New Capabilities

| Capability | Description | Roles |
|------------|-------------|-------|
| `mod/codeeditor:grade` | Grade student submissions | Teacher, Editing Teacher, Manager |
| `mod/codeeditor:viewgrades` | View all grades | Teacher, Editing Teacher, Manager |

---

## 🎨 Teacher Features

### **Activity Creation:**
When creating/editing a code editor activity, teachers can now:

1. **Set Maximum Grade** (e.g., 100 points)
2. **Configure Due Dates:**
   - Allow submissions from (start date)
   - Due date
   - Cut-off date (hard deadline)
3. **Enable Rubric Grading:**
   - Create custom rubric criteria
   - Define performance levels
   - Auto-calculate grades
4. **Map Competencies:**
   - Link to course competencies
   - Track student achievement
   - Auto-complete on submission/grading

### **Grading Interface:**
Teachers see:
- List of all submitted code
- Student information
- Submission date and language
- Current grade status
- Quick access to grade each submission
- Rubric grading (if enabled)
- Bulk grading options

### **Individual Grading Page:**
- View student's submitted code with syntax highlighting
- Enter numeric grade
- Provide text feedback
- Save to gradebook automatically
- View grading history

---

## 👨‍🎓 Student Features

### **Activity View Page:**
Students see:
- **Submission Status Card:**
  - STATUS: Draft / Submitted
  - GRADE: Points earned or "Not graded yet"
  - SUBMITTED: Date of submission
- **Teacher Feedback:** Highlighted feedback section (if graded)
- **Due Date Alert:** Visual warning if overdue
- **Code Editor:** Embedded IDE for writing code

### **Submission Workflow:**
1. Write code in the embedded editor
2. Test and debug
3. Click "Submit" (if required)
4. View submission status
5. Wait for teacher feedback
6. See grade and comments

---

## 🎯 Rubric Grading

### **How It Works:**
1. Teacher creates rubric in activity settings:
   - Define criteria (e.g., "Code Quality", "Functionality", "Comments")
   - Set performance levels (e.g., "Excellent", "Good", "Needs Work")
   - Assign points to each level
2. When grading, teacher clicks criteria levels
3. Grade is automatically calculated
4. Student sees detailed rubric feedback

### **Integration:**
- Uses Moodle's built-in advanced grading system
- Rubrics can be shared across activities
- Supports rubric templates
- Works with gradebook

---

## 🏆 Competency Mapping

### **Features:**
- Link code editor activities to course competencies
- Automatic competency completion on:
  - Activity completion
  - Submission
  - Passing grade
- Track student progress toward competency mastery
- Integrate with learning plans

### **Teacher Benefits:**
- Map coding skills to competency frameworks
- Track longitudinal student development
- Generate competency reports
- Align with curriculum standards

---

## 📊 Gradebook Integration

All grades from code editor activities automatically:
- ✅ Appear in course gradebook
- ✅ Contribute to final course grade
- ✅ Support grade scales
- ✅ Show grading history
- ✅ Support grade overrides
- ✅ Work with grade categories

---

## 🚀 Usage Examples

### **Example 1: Python Programming Assignment**
```
Activity: "Python Functions Quiz"
Grade: 100 points
Rubric Criteria:
  - Code Correctness (40 points)
  - Code Efficiency (30 points)
  - Comments & Documentation (20 points)
  - Code Style (10 points)
Competencies:
  - "Write functions in Python"
  - "Use conditional statements"
Due: 7 days from creation
```

### **Example 2: JavaScript Project**
```
Activity: "Interactive Web App"
Grade: 150 points
Rubric: Advanced (5 criteria, 4 levels each)
Competencies:
  - "Create interactive web applications"
  - "Use JavaScript DOM manipulation"
  - "Debug client-side code"
Allow from: Lesson 3 start date
Due: End of Lesson 3
```

---

## 🔄 Upgrade Process

The upgrade is **automatic** when you visit:
**Site Administration → Notifications**

### **What Happens:**
1. ✅ New fields added to `mdl_codeeditor` table
2. ✅ New fields added to `mdl_codeeditor_submissions` table
3. ✅ Indexes created for performance
4. ✅ Foreign keys established
5. ✅ Default values set for existing activities
6. ✅ No data loss - all existing submissions preserved

### **Safe Rollback:**
If needed, fields can be removed, but grades will be lost. **Backup recommended before upgrade.**

---

## 📝 Notes for Administrators

1. **Gradebook Visibility:** Grades appear in gradebook automatically
2. **Permissions:** Default permissions are appropriate for most schools
3. **Rubrics:** Must be enabled per-activity in settings
4. **Competencies:** Require competency framework to be set up in course
5. **Performance:** Indexes added for fast queries even with thousands of submissions

---

## 🎓 Benefits for Schools

### **For Teachers:**
- ✅ More detailed assessment of coding skills
- ✅ Consistent grading with rubrics
- ✅ Track competency development
- ✅ Less time grading (rubrics speed it up)
- ✅ Better feedback to students

### **For Students:**
- ✅ Clear expectations (rubrics)
- ✅ Detailed feedback on code quality
- ✅ Track own competency progress
- ✅ Understand grading criteria
- ✅ Motivation to improve

### **For Administrators:**
- ✅ Standards alignment (competencies)
- ✅ Data-driven insights
- ✅ Curriculum mapping
- ✅ Learning analytics integration

---

## 🐛 Troubleshooting

### **Grades Not Appearing in Gradebook?**
1. Check activity has grade > 0 set
2. Verify student has submitted (status = 'submitted')
3. Purge all caches
4. Re-grade the submission

### **Rubric Not Showing?**
1. Edit activity settings
2. Go to "Grade" section
3. Select "Rubric" from grading method dropdown
4. Define rubric criteria
5. Save activity

### **Competencies Not Available?**
1. Go to Course → Competencies
2. Link course to competency framework
3. Add competencies to course
4. Then edit activity to map competencies

---

## 📚 Related Moodle Documentation

- [Advanced Grading](https://docs.moodle.org/en/Advanced_grading)
- [Rubrics](https://docs.moodle.org/en/Rubric)
- [Competencies](https://docs.moodle.org/en/Competencies)
- [Gradebook](https://docs.moodle.org/en/Gradebook)

---

## ✨ Future Enhancements (Optional)

Potential additions for future versions:
- Automated code testing (unit tests)
- Plagiarism detection integration
- Peer review functionality
- Code quality metrics (complexity, style)
- GitHub integration for submissions
- Live code collaboration

---

**Upgrade completed successfully! The Code Editor module now has full assessment capabilities.** 🎊



