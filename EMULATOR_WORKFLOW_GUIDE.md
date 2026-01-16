# Emulator Access Control - Complete Workflow Guide

## Navigation Flow

This guide shows the complete workflow for managing emulator access across the system.

---

## 🎯 Global Admin Workflow

### **Starting Point: Emulator Access Control Page**

Navigate to: **Site Administration → IOMAD Dashboard → Emulator Access Control**

You'll see **two tabs:**
1. **School Grants** - Control which emulators are available to which schools
2. **Access Control** - Configure detailed teacher/student/cohort permissions

---

### **Workflow A: Quick School Grants**

```
1. Click "School Grants" tab
   ↓
2. Select a school from dropdown
   ↓
3. Toggle switches ON/OFF for each emulator
   ↓
4. ✅ Grants save automatically
   ↓
5. Repeat for other schools
```

**Use Case:** Quick bulk granting of emulators to schools

---

### **Workflow B: Grant + Configure Access**

```
1. Click "School Grants" tab
   ↓
2. Select a school from dropdown
   ↓
3. Find the emulator card you want to configure
   ↓
4. Toggle switch ON to grant the emulator
   ↓
5. Click "Access Control" button on that card
   ↓
6. [Auto-switches to Access Control tab]
   ↓
7. Configure detailed settings:
   - School-wide teacher access
   - School-wide student access
   - Cohort-specific overrides
   - Individual teacher selection
   ↓
8. Click "Back to School Grants" to return
```

**Use Case:** Grant emulator AND configure detailed permissions in one flow

---

## 🏫 School Manager Workflow

### **Starting Point: School Manager Dashboard**

Navigate to: **School Manager → Emulator Access**

**What You See:**
- Only emulators **granted to your school** by the global admin
- If no emulators: "No Emulators Available" message

---

### **Managing Access:**

```
1. Select an emulator from left sidebar
   ↓
2. Configure in right panel:
   ├─ SCHOOL-WIDE ACCESS
   │  ├─ Teachers: Enable/Disable
   │  └─ Students: Enable/Disable
   │
   ├─ INDIVIDUAL TEACHERS (NEW!)
   │  ├─ Toggle for each teacher
   │  └─ Teacher name + email shown
   │
   └─ COHORT OVERRIDES
      ├─ Override for specific cohorts
      └─ Separate controls for teachers/students
   ↓
3. Changes save automatically
   ↓
4. Select another emulator to configure
```

---

## 📊 Complete Access Flow Diagram

```
┌─────────────────────────────────────────────────────┐
│ LEVEL 1: Global Admin (School Grants)              │
│                                                      │
│ School Grants Tab                                   │
│ ├─ Select School                                    │
│ ├─ Toggle: Grant/Deny Emulator                     │
│ └─ Click "Access Control" button (optional)        │
│    ↓                                                 │
│    [Navigates to Access Control tab]                │
├─────────────────────────────────────────────────────┤
│ LEVEL 2: Global Admin (Access Control)             │
│                                                      │
│ Access Control Tab                                  │
│ ├─ Select School                                    │
│ ├─ Select Emulator (auto-selected if from grants)  │
│ ├─ School-Wide: Teachers ON/OFF                    │
│ ├─ School-Wide: Students ON/OFF                    │
│ ├─ Individual Teachers: Select specific teachers   │
│ └─ Cohort Overrides: Per-cohort permissions        │
├─────────────────────────────────────────────────────┤
│ LEVEL 3: School Manager (Emulator Access)          │
│                                                      │
│ School Manager Dashboard → Emulator Access          │
│ ├─ View ONLY granted emulators                     │
│ ├─ Select emulator from sidebar                    │
│ ├─ Configure school-wide access                    │
│ ├─ Select individual teachers                      │
│ └─ Configure cohort overrides                      │
└─────────────────────────────────────────────────────┘
```

---

## 🔄 Navigation Paths

### **Path 1: School Grants → Access Control**

```
School Grants Tab
└─ Click "Access Control" button on a card
   └─ Access Control Tab opens
      ├─ Emulator pre-selected
      ├─ School pre-selected
      ├─ "Back to School Grants" button appears
      └─ Context banner shows which emulator
```

### **Path 2: Access Control → School Grants**

```
Access Control Tab
└─ Click "Back to School Grants" button
   └─ School Grants Tab opens
      ├─ School remains selected
      └─ All emulator cards shown
```

### **Path 3: Direct Access Control**

```
Access Control Tab (default view)
├─ Select school from dropdown
├─ Select emulator from sidebar
└─ Configure permissions
```

---

## 🎨 UI Components

### **School Grants Tab:**

```
┌─────────────────────────────────────────┐
│ SELECT SCHOOL                           │
│ [Al-Faisaliah Islamic School     ▼]    │
└─────────────────────────────────────────┘

┌──────────┐  ┌──────────┐  ┌──────────┐
│ [</>]    │  │ [🧩]     │  │ [⚡]     │
│          │  │          │  │          │
│ Code     │  │ Scratch  │  │ SQL Lab  │
│ Editor   │  │ Emulator │  │          │
│          │  │          │  │          │
│ ✓Granted │  │ ✗Denied  │  │ ✓Granted │
│ [●──] ON │  │ [──○] OFF│  │ [●──] ON │
│          │  │          │  │          │
│ [Access  │  │ [Access  │  │ [Access  │
│  Control]│  │  Control]│  │  Control]│
└──────────┘  └──────────┘  └──────────┘
```

### **Access Control Tab (from grants):**

```
┌─────────────────────────────────────────┐
│ [← Back to School Grants]               │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│ [</>] Configuring: Code Editor          │
│ Judge0-based IDE with multi-language... │
└─────────────────────────────────────────┘

SCHOOL-WIDE ACCESS
├─ Teachers: [ENABLED] ☑ [Reset]
└─ Students: [DISABLED] ☐ [Reset]

INDIVIDUAL TEACHERS
├─ John Doe: [ENABLED] ☑
├─ Jane Smith: [DISABLED] ☐
└─ Bob Teacher: [ENABLED] ☑

COHORT OVERRIDES
├─ Grade 5: Teachers ☑ | Students ☐
└─ Grade 6: Teachers ☑ | Students ☑
```

---

## 💡 Use Case Examples

### **Example 1: Quick Grant to Multiple Schools**

**Goal:** Grant "Code Editor" to 10 schools quickly

**Steps:**
1. School Grants tab
2. Select School 1 → Toggle Code Editor ON
3. Select School 2 → Toggle Code Editor ON
4. Repeat for all 10 schools
5. ✅ Done - All schools have access

---

### **Example 2: Grant + Fine-Tune Access**

**Goal:** Grant "Scratch" to School A, but only for Grade 1-3 students

**Steps:**
1. School Grants tab
2. Select School A
3. Toggle "Scratch Emulator" ON
4. Click "Access Control" button on Scratch card
5. [Navigates to Access Control]
6. School-Wide Students: Keep DISABLED
7. Cohort Overrides:
   - Grade 1: Students ON
   - Grade 2: Students ON
   - Grade 3: Students ON
   - Other grades: Leave OFF
8. Click "Back to School Grants"
9. ✅ Done - Only Grade 1-3 students have access

---

### **Example 3: Teacher-Specific Access**

**Goal:** Grant "SQL Lab" to School B, but only for specific teachers

**Steps:**
1. School Grants tab
2. Select School B
3. Toggle "SQL Lab" ON
4. Click "Access Control" button on SQL Lab card
5. [Navigates to Access Control]
6. School-Wide Teachers: Keep DISABLED
7. Individual Teachers:
   - John (Database Expert): Enable ☑
   - Jane (IT Teacher): Enable ☑
   - Bob (Math Teacher): Leave disabled ☐
8. School-Wide Students: Enable ☑ (all students can access)
9. ✅ Done - Only John and Jane (teachers) can access, all students can

---

## 🎯 Benefits of This Workflow

✅ **Fast Grants** - Toggle switches for quick school grants  
✅ **Deep Configuration** - Access Control button for detailed setup  
✅ **Context Preservation** - School and emulator selection maintained  
✅ **Easy Navigation** - Back button to return to grants view  
✅ **Visual Feedback** - Context banner shows which emulator being configured  
✅ **Flexible** - Choose between quick grants or detailed configuration  

---

## 🔑 Key Features

### **On Emulator Cards:**
- **Toggle Switch** - Quick grant/deny
- **Access Control Button** - Deep configuration
- **Status Badge** - Visual feedback (Granted/Denied/Default)
- **Gradient Icons** - Beautiful, distinctive colors

### **On Access Control View:**
- **Back Button** - Return to grants view
- **Context Banner** - Shows which emulator (if from grants)
- **Badge on Tab** - Shows emulator name
- **Pre-selection** - Emulator auto-selected

### **Automatic Features:**
- **Auto-save** - All changes save instantly
- **URL Parameters** - Maintains state in URL
- **Responsive** - Works on all screen sizes

---

## 📋 Admin Checklist

### **Initial Setup:**
1. ☐ Run Moodle upgrade to create database tables
2. ☐ Verify tables exist in phpMyAdmin
3. ☐ Review default security settings (DENY ALL)
4. ☐ Plan which emulators each school needs

### **Per School Setup:**
1. ☐ Go to School Grants tab
2. ☐ Select the school
3. ☐ Grant necessary emulators (toggle ON)
4. ☐ For each emulator:
   - ☐ Click "Access Control" button
   - ☐ Configure school-wide access
   - ☐ Set teacher selections
   - ☐ Set cohort permissions
   - ☐ Click "Back to School Grants"
5. ☐ Repeat for next school

### **Testing:**
1. ☐ Login as school manager
2. ☐ Verify only granted emulators visible
3. ☐ Test teacher access
4. ☐ Test student access
5. ☐ Verify cohort restrictions work

---

## 🆘 Troubleshooting

### **"Access Control button not working"**
- Check browser console for JavaScript errors
- Verify you're on the latest version
- Clear browser cache and refresh

### **"Emulator not pre-selected in Access Control"**
- Check URL parameters include `?view=access&emulator=code_editor&companyid=123`
- Verify emulator slug matches catalog definition

### **"Back button doesn't preserve school selection"**
- URL should include `companyid` parameter
- Check if school ID is valid

---

## 📞 Support

For issues or questions, see:
- `EMULATOR_SCHOOL_GRANTS_SETUP.md` - Complete setup guide
- `TEACHER_EMULATOR_ACCESS_SETUP.md` - Teacher selection guide
- `SECURITY_MODEL_DENY_ALL.md` - Security model documentation

---

## 🎉 Summary

The new workflow provides:
- **Quick Access** - Toggle switches for fast grants
- **Deep Control** - Access Control button for fine-tuning
- **Seamless Navigation** - Easy movement between views
- **Context Awareness** - System remembers your selections
- **Professional UX** - Smooth, intuitive interface

Perfect for managing emulator access across many schools! 🚀

