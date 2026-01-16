# Security Model: DENY ALL (Default)

## Overview

The emulator access control system uses a **"DENY ALL" security model** by default. This means all access is DISABLED until explicitly granted.

---

## Why "DENY ALL"?

### Security Benefits:
✅ **Whitelist Approach** - Only explicitly permitted users get access  
✅ **Principle of Least Privilege** - Users only get what they need  
✅ **Prevents Accidents** - No accidental over-permissioning  
✅ **Audit Trail** - Every permission is explicitly recorded  
✅ **Compliance Ready** - Meets security audit requirements  

### Risk Mitigation:
❌ **Prevents:** Unauthorized access to tools  
❌ **Prevents:** Students accessing teacher-only emulators  
❌ **Prevents:** Schools accessing emulators not granted to them  
❌ **Prevents:** Data breaches from over-permissioning  

---

## How It Works

### Three-Level Hierarchy:

```
┌─────────────────────────────────────────────────┐
│ Level 1: Global Admin (School Grants)          │
│ Default: DENY ALL schools                      │
│ Action: Must explicitly grant to each school   │
└────────────────┬────────────────────────────────┘
                 │ IF GRANTED ↓
┌─────────────────────────────────────────────────┐
│ Level 2: School Manager (School-Wide Access)   │
│ Default: DENY teachers & students              │
│ Action: Must explicitly enable school-wide     │
└────────────────┬────────────────────────────────┘
                 │ IF ENABLED ↓
┌─────────────────────────────────────────────────┐
│ Level 3: Individual Overrides                  │
│ Teachers: Can grant/deny individually          │
│ Students: Can grant/deny per cohort            │
└─────────────────────────────────────────────────┘
```

---

## Access Grant Flow

### For Schools:

**Default State:**
```
❌ School A: DENIED
❌ School B: DENIED
❌ School C: DENIED
```

**After Global Admin Grants:**
```
✅ School A: GRANTED (explicitly)
❌ School B: DENIED (default)
❌ School C: DENIED (default)
```

### For Teachers:

**Default State (even if school has access):**
```
School A - Code Editor:
├─ School-Wide: ❌ DENIED
├─ Teacher John: ❌ DENIED (default)
├─ Teacher Jane: ❌ DENIED (default)
└─ Teacher Bob: ❌ DENIED (default)
```

**Option 1: School-Wide Grant:**
```
School A - Code Editor:
├─ School-Wide: ✅ ENABLED
├─ Teacher John: ✅ GRANTED (inherited)
├─ Teacher Jane: ✅ GRANTED (inherited)
└─ Teacher Bob: ✅ GRANTED (inherited)
```

**Option 2: Individual Grants:**
```
School A - Code Editor:
├─ School-Wide: ❌ DISABLED
├─ Teacher John: ✅ GRANTED (explicitly)
├─ Teacher Jane: ✅ GRANTED (explicitly)
└─ Teacher Bob: ❌ DENIED (default)
```

**Option 3: Mixed (School-Wide + Individual Deny):**
```
School A - Code Editor:
├─ School-Wide: ✅ ENABLED
├─ Teacher John: ✅ GRANTED (inherited)
├─ Teacher Jane: ✅ GRANTED (inherited)
└─ Teacher Bob: ❌ DENIED (explicitly blocked)
```

### For Students:

**Default State:**
```
School A - Scratch Emulator:
├─ School-Wide: ❌ DENIED
├─ Grade 5 Cohort: ❌ DENIED (default)
├─ Grade 6 Cohort: ❌ DENIED (default)
└─ Grade 7 Cohort: ❌ DENIED (default)
```

**After School Manager Enables:**
```
School A - Scratch Emulator:
├─ School-Wide: ✅ ENABLED
├─ Grade 5 Cohort: ✅ GRANTED (inherited)
├─ Grade 6 Cohort: ❌ DENIED (explicitly blocked)
└─ Grade 7 Cohort: ✅ GRANTED (inherited)
```

---

## Configuration

### Current Settings:

```php
// File: iomad/theme/remui_kids/lib/emulator_manager.php

const THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW = false;
// ↑ Teachers & Students: DENIED by default

const THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = false;
// ↑ Schools: DENIED by default
```

### Alternative: ALLOW ALL (Not Recommended)

```php
const THEME_REMUI_KIDS_EMULATOR_DEFAULT_ALLOW = true;
// ⚠️ Teachers & Students: ALLOWED by default (less secure)

const THEME_REMUI_KIDS_EMULATOR_DEFAULT_GRANT_ALL = true;
// ⚠️ Schools: ALL GRANTED by default (less secure)
```

**Warning:** Setting to `true` means:
- All schools automatically get all emulators
- All teachers automatically get access
- All students automatically get access
- You must explicitly DENY to restrict (blacklist approach)

---

## Database Behavior

### When No Record Exists:

**With DENY ALL (current):**
```sql
-- No record in theme_remui_kids_emulator_school_grants
-- Result: School does NOT have access

-- No record in theme_remui_kids_emulator_access
-- Result: Teachers/Students do NOT have access

-- No record in theme_remui_kids_teacher_emulator
-- Result: Individual teacher does NOT have access
```

**With ALLOW ALL (if changed to true):**
```sql
-- No record in theme_remui_kids_emulator_school_grants
-- Result: School DOES have access

-- No record in theme_remui_kids_emulator_access
-- Result: Teachers/Students DO have access

-- No record in theme_remui_kids_teacher_emulator
-- Result: Individual teacher DOES have access
```

---

## Admin Workflow

### Global Admin:
1. Review emulator catalog
2. **Explicitly grant** each emulator to each school
3. Use "School Grants" tab → Select school → Toggle ON

### School Manager:
1. Review granted emulators (only see those granted by admin)
2. **Explicitly enable** school-wide for teachers/students
3. **OR** selectively grant to individual teachers
4. **OR** selectively grant to specific cohorts

### Result:
- Clear audit trail of who granted what
- No accidental over-permissioning
- Secure by design

---

## Troubleshooting

### "Teachers/Students can't see emulators"

**Expected Behavior with DENY ALL:**
1. Check: Is emulator granted to the school? (Global Admin)
2. Check: Is school-wide access enabled? (School Manager)
3. Check: Is individual teacher/cohort granted? (School Manager)

**All three levels must be granted for access.**

### "Everything is denied!"

**This is correct behavior!**
- DENY ALL is the default
- You must explicitly grant at each level
- Work top-down: School → School-Wide → Individual

---

## Migration from ALLOW ALL

If you previously used ALLOW ALL (`true`) and switch to DENY ALL (`false`):

**Before (ALLOW ALL):**
- Everyone had access by default
- Records existed only for denials

**After (DENY ALL):**
- Everyone is denied by default
- You must re-grant permissions

**Migration Steps:**
1. Document current access patterns
2. Change constants to `false`
3. Grant emulators to schools (Global Admin)
4. Enable school-wide access (School Managers)
5. Test with sample users
6. Roll out to all schools

---

## Security Checklist

✅ Default constants set to `false`  
✅ Global admin explicitly grants schools  
✅ School managers explicitly enable access  
✅ Regular audit of permissions  
✅ Document access decisions  
✅ Monitor for access issues  
✅ Training for admins on security model  

---

## Support

For questions about the security model, contact your system administrator.

**Remember:** DENY ALL is more secure but requires more initial setup!

