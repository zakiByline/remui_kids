# 📂 Complete Code Editor Plugin - All Code Files

## 🎯 Your Plugin Code Location

**Base Directory:** `iomad/mod/codeeditor/`

---

## 📋 Core Moodle Plugin Files

### **1. Plugin Definition**
```
iomad/mod/codeeditor/version.php
```
- Plugin version number
- Moodle requirements
- Component name

### **2. Core Functions**
```
iomad/mod/codeeditor/lib.php
```
- Plugin initialization
- Helper functions
- Moodle hooks

### **3. Main View Page**
```
iomad/mod/codeeditor/view.php
```
- Displays the code editor interface
- Renders the template
- Handles course context

### **4. Activity Form**
```
iomad/mod/codeeditor/mod_form.php
```
- Form for creating/editing code editor activities
- Settings and configuration

### **5. Database**
```
iomad/mod/codeeditor/db/access.php
```
- Capability definitions

```
iomad/mod/codeeditor/db/install.xml
```
- Database schema

### **6. Language**
```
iomad/mod/codeeditor/lang/en/codeeditor.php
```
- All language strings

---

## 🎨 React Frontend Source Code

### **Main Components:**
```
iomad/mod/codeeditor/online-ide-main/Frontend/src/
├── App.jsx                           - Main React app
├── main.jsx                          - Entry point
├── index.css                         - Global styles
├── components/
│   ├── AIAssistant.jsx              ✅ AI Assistant (196 lines)
│   ├── CodeEditor.jsx               - Main editor (33KB)
│   ├── Editor.jsx                   - Editor logic (37KB)
│   ├── Header.jsx                   - Header component (9KB)
│   ├── MainBody.jsx                 - Layout wrapper
│   ├── Footer.jsx                   - Footer
│   ├── NavigationLinks.jsx          - Navigation
│   ├── SharedLinks.jsx              - Share links
│   └── ShareEditor.jsx              - Share editor
├── styles/
│   └── AIAssistant.css             ✅ AI Assistant styles
├── pages/
│   ├── Login.jsx
│   ├── Register.jsx
│   ├── Accounts.jsx
│   ├── ForgotPassword.jsx
│   └── NotFound.jsx
├── routes/
│   └── EditorRoutes.jsx
└── utils/
    ├── apifetch.js
    ├── blocker.js
    ├── constants.js
    ├── InputField.jsx
    ├── OtpInputForm.jsx
    └── ShareLinkModal.js
```

---

## 🤖 AI Assistant Code Files

### **1. React Component (Frontend)**
**File:** `online-ide-main/Frontend/src/components/AIAssistant.jsx`
- **Lines:** 196
- **Features:**
  - Chat interface
  - Quick actions
  - Code insertion
  - Message history
  - Loading states

### **2. CSS Styling**
**File:** `online-ide-main/Frontend/src/styles/AIAssistant.css`
- Purple gradient button
- Panel styling
- Animations
- Dark theme support
- Responsive design

### **3. Backend API**
**File:** `online-ide-main/Backend/Genai/ai_assistant_endpoint.py`
- Flask API endpoint
- Gemini AI integration
- Code analysis
- Error handling

---

## 🎨 Template Integration

### **Main Template**
**File:** `iomad/theme/remui_kids/templates/code_editor_page.mustache`
- **Lines:** 751
- **Contains:**
  - IDE iframe integration
  - AI Assistant button injection script
  - Title update script
  - All JavaScript for button and panel

---

## 📊 File Sizes

| File | Size | Description |
|------|------|-------------|
| CodeEditor.jsx | 33KB | Main editor component |
| Editor.jsx | 37KB | Editor logic |
| Header.jsx | 9KB | Header component |
| AIAssistant.jsx | 6.8KB | AI Assistant component |
| code_editor_page.mustache | 751 lines | Template with injection scripts |

---

## 🔍 How to View Code

### **Option 1: In Your IDE**
Open these files directly:
- `AIAssistant.jsx` - Already shown above
- `CodeEditor.jsx` - Main editor
- `Header.jsx` - Header with buttons
- `code_editor_page.mustache` - Template

### **Option 2: Command Line**
```bash
# View AI Assistant component
Get-Content "C:\wamp64\www\kodeit\iomad\mod\codeeditor\online-ide-main\Frontend\src\components\AIAssistant.jsx"

# View template
Get-Content "C:\wamp64\www\kodeit\iomad\theme\remui_kids\templates\code_editor_page.mustache"
```

### **Option 3: Search for Specific Code**
```bash
# Find all AI Assistant related code
Select-String -Path "C:\wamp64\www\kodeit\iomad\mod\codeeditor" -Pattern "AI|Assistant" -Recurse
```

---

## 📝 Key Code Sections

### **AI Assistant Button (Template Injection)**
**Location:** `templates/code_editor_page.mustache` (lines 114-233)

**What it does:**
- Finds the button container
- Creates AI Assistant button
- Adds purple gradient styling
- Creates chat panel
- Handles click events

### **Title Update Script**
**Location:** `templates/code_editor_page.mustache` (lines 66-121)

**What it does:**
- Removes rocket emoji
- Changes "Online Code Editor" to "Code Editor"
- Updates iframe content
- Watches for dynamic changes

---

## 🎯 Complete Code Structure

```
iomad/mod/codeeditor/
│
├── Core Moodle Plugin
│   ├── version.php
│   ├── lib.php
│   ├── view.php
│   ├── mod_form.php
│   ├── grading.php
│   ├── db/
│   └── lang/
│
├── React Source (online-ide-main/Frontend/src/)
│   ├── components/
│   │   ├── AIAssistant.jsx      ✅ YOUR AI CODE
│   │   ├── CodeEditor.jsx
│   │   ├── Editor.jsx
│   │   └── Header.jsx
│   ├── styles/
│   │   └── AIAssistant.css      ✅ YOUR AI STYLES
│   └── App.jsx
│
├── Backend API (online-ide-main/Backend/)
│   └── Genai/
│       └── ai_assistant_endpoint.py  ✅ YOUR AI API
│
└── Template Integration
    └── theme/remui_kids/templates/
        └── code_editor_page.mustache  ✅ INJECTION SCRIPTS
```

---

## 🔑 Most Important Files

### **For AI Assistant:**
1. ✅ `AIAssistant.jsx` - React component (shown above)
2. ✅ `AIAssistant.css` - Styling
3. ✅ `ai_assistant_endpoint.py` - Backend API
4. ✅ `code_editor_page.mustache` - Template injection

### **For Plugin Core:**
1. `view.php` - Main display page
2. `lib.php` - Core functions
3. `mod_form.php` - Activity form
4. `version.php` - Plugin metadata

---

## 📖 View Complete Code

### **AI Assistant Component:**
Already displayed above ✅ (196 lines)

### **To See Other Files:**
Open in your IDE:
- `online-ide-main/Frontend/src/components/CodeEditor.jsx`
- `online-ide-main/Frontend/src/components/Header.jsx`
- `theme/remui_kids/templates/code_editor_page.mustache`

---

## 🎯 Quick Access

**All your plugin code is in:**
```
C:\wamp64\www\kodeit\iomad\mod\codeeditor\
```

**AI Assistant code is in:**
```
C:\wamp64\www\kodeit\iomad\mod\codeeditor\online-ide-main\Frontend\src\components\AIAssistant.jsx
```

**Template with injection is in:**
```
C:\wamp64\www\kodeit\iomad\theme\remui_kids\templates\code_editor_page.mustache
```

---

**All code files are ready and documented!** 🎉





