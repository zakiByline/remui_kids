# 🚀 Build and Deploy Instructions

## ⚠️ IMPORTANT: Your AI Assistant button won't show until you rebuild!

You created the AIAssistant component, but your browser is showing the **old built version**.

---

## 📋 Step-by-Step Process

### Step 1: Edit Source Files (DO THIS FIRST!)

1. **Open** `Frontend/src/components/CodeEditor.jsx` (or wherever your buttons are)
2. **Add import** at the top:
   ```jsx
   import AIAssistant from './AIAssistant';
   ```
3. **Find** where your "Run Code" button is
4. **Add** AI Assistant component BEFORE it:
   ```jsx
   <AIAssistant 
     code={code}
     language={language}
     onInsertCode={setCode}
   />
   ```
5. **Save** the file

### Step 2: Rebuild the React App

```bash
# Navigate to Frontend folder
cd C:\wamp64\www\kodeit\iomad\mod\codeeditor\online-ide-main\Frontend

# Install dependencies (if not already done)
npm install

# Build for production
npm run build
```

This creates new files in `Frontend/dist/`

### Step 3: Deploy Built Files

```bash
# Copy built files to your ide folder
# From: Frontend/dist/
# To: iomad/mod/codeeditor/ide/

# Windows PowerShell:
Copy-Item -Path "dist\*" -Destination "..\..\ide\" -Recurse -Force
```

### Step 4: Clear Browser Cache

- Press `Ctrl + Shift + Delete`
- Or `Ctrl + F5` (hard refresh)

### Step 5: Refresh and See AI Assistant!

Open your code editor page and you should see:
```
[💡 AI Assistant] [▶ Run Code] [Clear Output] [🌙 Dark]
└────┬────────┘
  NEW BUTTON!
```

---

## 🔍 Troubleshooting

### Button Still Not Showing?

**Check 1:** Did you edit the SOURCE files?
- ❌ Wrong: Editing `ide/index.html` (built file)
- ✅ Correct: Editing `Frontend/src/components/*.jsx` (source)

**Check 2:** Did you rebuild?
```bash
cd Frontend
npm run build
```

**Check 3:** Did you copy built files?
```bash
# Built files should be in ide/ folder
ls iomad/mod/codeeditor/ide/
# Should show: index.html, assets/, etc.
```

**Check 4:** Did you clear browser cache?
- `Ctrl + F5` (hard refresh)

---

## 📁 File Locations

### Source Files (EDIT THESE):
```
iomad/mod/codeeditor/online-ide-main/Frontend/src/
├── components/
│   ├── AIAssistant.jsx        ✅ Created
│   ├── CodeEditor.jsx         ⚠️  YOU NEED TO EDIT THIS
│   ├── Editor.jsx             ⚠️  OR THIS
│   └── Header.jsx             ⚠️  OR THIS
└── styles/
    └── AIAssistant.css        ✅ Created
```

### Built Files (VIEW THESE):
```
iomad/mod/codeeditor/ide/
├── index.html                 👀 This is what browser shows
└── assets/
    ├── index-*.js             👀 Compiled JavaScript
    └── index-*.css            👀 Compiled CSS
```

---

## 🎯 Quick Commands

### One-line build and deploy:
```bash
cd C:\wamp64\www\kodeit\iomad\mod\codeeditor\online-ide-main\Frontend && npm run build && Copy-Item -Path "dist\*" -Destination "..\..\ide\" -Recurse -Force
```

### Check if build was successful:
```bash
Get-ChildItem C:\wamp64\www\kodeit\iomad\mod\codeeditor\ide\
# Should show new/updated files with recent timestamps
```

---

## ✅ Success Checklist

After completing all steps, you should have:
- [  ] Edited source JSX file with AI Assistant component
- [  ] Run `npm run build` successfully
- [  ] Copied dist files to ide folder
- [  ] Cleared browser cache
- [  ] Refreshed page
- [  ] See AI Assistant button to the left of Run Code button
- [  ] Button has purple gradient background
- [  ] Clicking opens AI chat panel
- [  ] Chat interface is functional

---

## 🎨 Expected Result

### Before (Current):
```
[JavaScript ▼] [▶ Run Code] [Clear Output] [🌙 Dark]
```

### After (With AI Assistant):
```
[JavaScript ▼] [💡 AI Assistant] [▶ Run Code] [Clear Output] [🌙 Dark]
```

---

## 💡 Tips

1. **Always edit SOURCE files** (`Frontend/src/`), never built files (`ide/`)
2. **Always rebuild** after editing source files
3. **Always clear cache** after deploying new build
4. **Check browser console** for any errors
5. **Use hard refresh** (`Ctrl + F5`) to bypass cache

---

## 📞 If Still Not Working

1. Check browser console (F12) for errors
2. Verify AIAssistant.jsx and AIAssistant.css exist
3. Verify you edited the correct component file
4. Make sure import path is correct
5. Ensure you're viewing the correct URL (not cached version)

---

**Follow these steps carefully and you'll see the AI Assistant button!** 🚀





