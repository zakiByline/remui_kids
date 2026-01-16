/**
 * ⚡ COPY THIS CODE TO ADD AI ASSISTANT BUTTON
 * 
 * LOCATION: Find where your "Run Code", "Clear Output", and "Dark" buttons are
 * FILE: Probably in Frontend/src/components/CodeEditor.jsx or Editor.jsx or MainBody.jsx
 */

// ========================================
// STEP 1: ADD THESE IMPORTS AT THE TOP
// ========================================
import { useState } from 'react';
import AIAssistant from './AIAssistant';
import '../styles/AIAssistant.css';

// ========================================
// STEP 2: FIND YOUR BUTTON SECTION
// ========================================
// Look for something like this in your component:

/* YOUR CURRENT CODE PROBABLY LOOKS LIKE THIS: */
<div className="editor-controls" or className="toolbar" or className="header-actions">
  
  {/* Language Dropdown */}
  <select 
    value={language} 
    onChange={(e) => setLanguage(e.target.value)}
    className="language-selector"
  >
    <option value="javascript">JavaScript (Node.js)</option>
    <option value="python">Python</option>
    {/* ... other languages */}
  </select>

  {/* Run Code Button */}
  <button 
    onClick={handleRunCode} 
    className="run-button"
  >
    <PlayIcon /> {/* or any icon */}
    Run Code
  </button>

  {/* Clear Output Button */}
  <button 
    onClick={handleClearOutput}
    className="clear-button"
  >
    Clear Output
  </button>

  {/* Dark Theme Button */}
  <button 
    onClick={handleToggleTheme}
    className="theme-button"
  >
    <MoonIcon />
    Dark
  </button>

</div>


// ========================================
// STEP 3: ADD AI ASSISTANT BEFORE RUN CODE
// ========================================
/* CHANGE IT TO THIS: */

<div className="editor-controls">
  
  {/* Language Dropdown */}
  <select 
    value={language} 
    onChange={(e) => setLanguage(e.target.value)}
    className="language-selector"
  >
    <option value="javascript">JavaScript (Node.js)</option>
    <option value="python">Python</option>
  </select>

  {/* ✨✨✨ ADD THIS - AI ASSISTANT BUTTON ✨✨✨ */}
  <AIAssistant 
    code={code}  {/* Your current code state */}
    language={language}  {/* Your selected language */}
    onInsertCode={(newCode) => {
      setCode(newCode);  {/* Your code setter function */}
      // Or however you update code in your editor
    }}
  />
  {/* ✨✨✨ END OF AI ASSISTANT ✨✨✨ */}

  {/* Run Code Button */}
  <button 
    onClick={handleRunCode} 
    className="run-button"
  >
    Run Code
  </button>

  {/* Clear Output Button */}
  <button 
    onClick={handleClearOutput}
    className="clear-button"
  >
    Clear Output
  </button>

  {/* Dark Theme Button */}
  <button 
    onClick={handleToggleTheme}
    className="theme-button"
  >
    Dark
  </button>

</div>


// ========================================
// STEP 4: COMPLETE COMPONENT EXAMPLE
// ========================================

const CodeEditor = () => {
  const [code, setCode] = useState('// Your code here');
  const [language, setLanguage] = useState('javascript');
  const [output, setOutput] = useState('');

  const handleRunCode = () => {
    // Your run code logic
  };

  const handleClearOutput = () => {
    setOutput('');
  };

  const handleToggleTheme = () => {
    // Your theme toggle logic
  };

  return (
    <div className="code-editor-container">
      
      {/* Header/Toolbar */}
      <div className="editor-header">
        <div className="editor-title">
          🚀 Online Code Editor
        </div>
        
        {/* THIS IS WHERE YOUR BUTTONS ARE */}
        <div className="editor-controls">
          
          {/* Language Selector */}
          <select 
            value={language} 
            onChange={(e) => setLanguage(e.target.value)}
          >
            <option value="javascript">JavaScript (Node.js)</option>
            <option value="python">Python</option>
            <option value="java">Java</option>
          </select>

          {/* ⭐ AI ASSISTANT - ADD THIS ⭐ */}
          <AIAssistant 
            code={code}
            language={language}
            onInsertCode={setCode}
          />

          {/* Run Code */}
          <button onClick={handleRunCode} className="btn-run">
            ▶ Run Code
          </button>

          {/* Clear Output */}
          <button onClick={handleClearOutput} className="btn-clear">
            Clear Output
          </button>

          {/* Toggle Theme */}
          <button onClick={handleToggleTheme} className="btn-theme">
            🌙 Dark
          </button>

        </div>
      </div>

      {/* Editor and Output */}
      <div className="editor-body">
        {/* Your code editor here */}
      </div>

    </div>
  );
};

export default CodeEditor;


// ========================================
// COMMON FILE LOCATIONS TO CHECK:
// ========================================
/*
1. Frontend/src/components/CodeEditor.jsx  ← Most likely
2. Frontend/src/components/Editor.jsx
3. Frontend/src/components/MainBody.jsx
4. Frontend/src/components/Header.jsx
5. Frontend/src/App.jsx

Search for: "Run Code" or "Clear Output" or className="toolbar"
*/


// ========================================
// IF YOU USE MONACO EDITOR OR CODEMIRROR:
// ========================================

// For Monaco Editor:
const handleInsertCode = (newCode) => {
  editorRef.current.setValue(newCode);
};

// For CodeMirror:
const handleInsertCode = (newCode) => {
  codeMirrorRef.current.setValue(newCode);
};

// Pass it to AIAssistant:
<AIAssistant 
  code={code}
  language={language}
  onInsertCode={handleInsertCode}
/>


// ========================================
// AFTER ADDING, YOU MUST REBUILD!
// ========================================
/*
1. Save your file
2. Open terminal
3. cd iomad/mod/codeeditor/online-ide-main/Frontend
4. npm run build
5. Copy dist/ folder contents to your ide/ folder
6. Refresh browser
7. See the AI Assistant button! 🎉
*/


// ========================================
// BUTTON WILL APPEAR LIKE THIS:
// ========================================
/*
┌─────────────────────────────────────────────────────────┐
│ 🚀 Online Code Editor    [JavaScript ▼]                │
│                                                          │
│  [💡 AI Assistant] [▶ Run] [Clear] [🌙 Dark]          │
│  └────┬────────┘                                        │
│    NEW BUTTON!                                          │
└─────────────────────────────────────────────────────────┘
*/

