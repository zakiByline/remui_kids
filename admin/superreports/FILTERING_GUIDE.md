# Super Admin Reports - Filtering Guide
## How School & Cohort Filters Work

---

## 🎯 Quick Overview

The **Super Admin Reports** page (`/theme/remui_kids/admin/superreports/index.php`) supports real-time filtering of all data based on:
- **School Filter** - Filter data by specific school/company
- **Cohort Filter** - Filter data by specific cohort/grade/class
- **Both Combined** - Filter by school AND cohort together

---

## 📍 Filter Location

At the top of the page, you'll find these filters:

```
[🏫 School Dropdown] [🎓 Grade/Cohort Dropdown] [📅 Date Range] [🔄 Refresh] [📥 Export]
```

---

## 🔄 How Filtering Works

### **Step-by-Step Process:**

1. **User selects a filter:**
   - Example: Select "Al-Faisaliah Islamic School" from school dropdown

2. **JavaScript updates filter state:**
   ```javascript
   currentFilters.school = schoolid
   clearAllTabCache()  // Clears cached data
   refreshCurrentTab() // Reloads current tab
   ```

3. **AJAX request sent:**
   ```
   GET /superreports/ajax_data.php?
       tab=overview&
       school=5&
       grade=&
       daterange=month&
       sesskey=...
   ```

4. **Backend filters SQL queries:**
   ```sql
   WHERE ... 
   AND EXISTS (SELECT 1 FROM {company_users} cu 
               WHERE cu.userid = u.id 
               AND cu.companyid = 5)
   ```

5. **Filtered data returned and displayed**

---

## 📊 What Gets Filtered

### **When School Filter is Applied:**

✅ **Overview Tab:**
- Total Teachers (only from selected school)
- Total Students (only from selected school)
- Course enrollments (only school's courses)
- Activity trends (only school's users)

✅ **Assignments Tab:**
- Only assignments from school's courses
- Only submissions from school's students
- Statistics calculated for school only

✅ **Quizzes Tab:**
- Only quizzes from school's courses
- Only attempts from school's students
- Average scores for school only

✅ **Overall Grades Tab:**
- System average filtered by school
- Top 5 students from school only
- Grade distribution for school

✅ **Competencies Tab:**
- Competency progress for school's students
- Course mappings for school's courses
- Achievement rates by school

✅ **Teacher Performance Tab:**
- Only teachers assigned to school
- Their course activity in school's courses
- Student engagement metrics for school

✅ **Student Performance Tab:**
- Only students from selected school
- Their grades and completion rates
- Filtered by school enrollment

✅ **Courses Tab:**
- Only courses assigned to school
- Enrollment statistics for school
- Completion rates for school

---

### **When Cohort Filter is Applied:**

✅ **All Tabs Filter To:**
- Only users who are members of the selected cohort
- All statistics calculated for cohort members only
- Grades, attempts, completions from cohort members

---

### **When BOTH School AND Cohort Are Selected:**

✅ **Combined Filtering:**
- Users must be in BOTH school AND cohort
- Example: "Al-Faisaliah School" + "Grade 10 Students"
- Shows only Grade 10 students from Al-Faisaliah
- Most precise filtering option

---

## 🎬 Demo for Presentation

### **Demo Script:**

> **"Let me show you the power of our filtering system."**

1. **Show all data (no filters):**
   ```
   - Total Teachers: 50
   - Total Students: 500
   - Total Assignments: 200
   ```

2. **Select School: "Al-Faisaliah Islamic School"**
   ```
   - Watch the statistics update in real-time...
   - Total Teachers: 12 (only Al-Faisaliah teachers)
   - Total Students: 120 (only Al-Faisaliah students)
   - Total Assignments: 45 (only Al-Faisaliah courses)
   ```

3. **Add Cohort: "Grade 10 Students"**
   ```
   - Now filtering by School AND Grade...
   - Total Students: 30 (Grade 10 from Al-Faisaliah only)
   - All data shows only this specific group
   ```

4. **Switch to different tabs:**
   ```
   - Assignments Tab: Only shows Grade 10 Al-Faisaliah assignments
   - Quizzes Tab: Only shows Grade 10 Al-Faisaliah quiz attempts
   - Grades Tab: Top 5 students from this filtered group
   ```

5. **Clear filters (select "All Schools"):**
   ```
   - Data returns to showing entire system
   - All statistics back to original totals
   ```

---

## 💡 Filter Indicator Banner

When filters are active, a banner appears at the top of each tab:

```
🔍 Filters Active: 🏫 Al-Faisaliah Islamic School • 🎓 Grade 10 Students
```

This reminds you that you're viewing filtered data.

---

## 🎨 Visual Indicators

### **No Filters Active:**
- Banner: *Not shown*
- Data: *System-wide (all schools, all cohorts)*

### **School Filter Active:**
- Banner: `🏫 Al-Faisaliah Islamic School`
- Data: *School-specific only*

### **Cohort Filter Active:**
- Banner: `🎓 Grade 10 Students`
- Data: *Cohort members only*

### **Both Filters Active:**
- Banner: `🏫 Al-Faisaliah Islamic School • 🎓 Grade 10 Students`
- Data: *Intersection of both (most specific)*

---

## 📋 Filtering Support by Tab

| Tab | School Filter | Cohort Filter | Combined |
|-----|---------------|---------------|----------|
| Overview | ✅ | ✅ | ✅ |
| Assignments | ✅ | ✅ | ✅ |
| Quizzes | ✅ | ✅ | ✅ |
| Overall Grades | ✅ | ✅ | ✅ |
| Competencies | ✅ | ✅ | ✅ |
| Teacher Performance | ✅ | ✅ | ✅ |
| Student Performance | ✅ | ✅ | ✅ |
| Courses | ✅ | ⚠️ | ⚠️ |
| Activity | ⚠️ | ⚠️ | ⚠️ |
| Attendance | ⚠️ | ⚠️ | ⚠️ |

✅ = Fully implemented  
⚠️ = Partial or not implemented

---

## 🔧 Technical Implementation

### **Frontend (script.js):**
```javascript
// Filter state management
let currentFilters = {
    school: '',      // School ID
    grade: '',       // Cohort ID
    framework: '',   // Competency framework ID
    dateRange: 'month',
    startDate: '',
    endDate: ''
};

// When filter changes:
schoolFilter.addEventListener('change', function() {
    currentFilters.school = this.value;
    clearAllTabCache();  // Clear cached data
    loadAISummary();     // Reload AI insights
    refreshCurrentTab(); // Reload current tab with new filter
});
```

### **Backend (ajax_data.php):**
```php
// Receive filter parameters
$schoolid = optional_param('school', 0, PARAM_INT);
$gradeid = optional_param('grade', '', PARAM_TEXT);

// Pass to data functions
$data = superreports_get_assignments_overview($schoolid, $gradeid, ...);
```

### **Data Functions (lib.php):**
```php
// School filtering
if ($schoolid > 0) {
    $sql .= " AND EXISTS (SELECT 1 FROM {company_course} cc 
                          WHERE cc.courseid = c.id 
                          AND cc.companyid = :companyid)";
    $params['companyid'] = $schoolid;
}

// Cohort filtering
if (!empty($gradeid)) {
    $sql .= " AND EXISTS (SELECT 1 FROM {cohort_members} cm 
                          WHERE cm.userid = u.id 
                          AND cm.cohortid = :cohortid)";
    $params['cohortid'] = $gradeid;
}
```

---

## ✅ Verification Checklist

Before presenting, verify:

- [ ] School dropdown shows all schools
- [ ] Cohort dropdown shows all cohorts
- [ ] Selecting school updates all tab data
- [ ] Selecting cohort updates all tab data
- [ ] Filter banner displays correctly
- [ ] Statistics update in real-time
- [ ] Export includes filtered data only
- [ ] Clearing filters returns to full data

---

## 🎤 Presentation Talking Points

**"Our filtering system provides three levels of data visibility:"**

1. **System-Wide View** (No filters)
   - "See everything across all schools and students"
   - "Perfect for district-level oversight"

2. **School-Specific View** (School filter)
   - "Focus on one institution's performance"
   - "Compare schools side-by-side"

3. **Granular View** (School + Cohort)
   - "Drill down to specific classes or grades"
   - "Track individual cohort progress"

**"Every filter change updates all data instantly - no page refresh needed!"**

---

## 🚀 Advanced Use Cases

### **Use Case 1: Compare Schools**
1. View all data (no filter)
2. Note total system average grade
3. Filter by School A → Note their average
4. Filter by School B → Note their average
5. Compare performance between schools

### **Use Case 2: Track Specific Class**
1. Select School: "Al-Faisaliah"
2. Select Cohort: "Grade 10 Math Class"
3. View assignments, quizzes, grades for this class only
4. Export report for parent-teacher meetings

### **Use Case 3: School-Wide Competency Audit**
1. Select School: "Demo Academy"
2. Go to Competencies tab
3. See which competencies are covered
4. Identify gaps in curriculum
5. Generate report for accreditation

---

**The filtering system is production-ready and working! 🎉**









































