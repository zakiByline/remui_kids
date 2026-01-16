# AI Assistant Integration Guide

## 🎯 Overview

This guide will help you integrate the AI Assistant button into your code editor.

---

## 📋 Step 1: Add AI Assistant Component to Header

Update your `Header.jsx` file to include the AI Assistant button.

### Location: `Frontend/src/components/Header.jsx`

Add this import at the top:
```jsx
import AIAssistant from './AIAssistant';
```

Then add the AI Assistant button in your header JSX (alongside "Run Code", "Clear Output", "Dark" buttons):

```jsx
{/* Add this next to your other buttons */}
<AIAssistant 
  code={code}
  language={selectedLanguage}
  onInsertCode={handleInsertCode}
/>
```

### Example Integration:

```jsx
// In your Header.jsx or CodeEditor.jsx
<div className="editor-header-buttons">
  {/* Existing buttons */}
  <button onClick={handleRunCode} className="run-btn">
    <PlayIcon /> Run Code
  </button>
  
  <button onClick={handleClearOutput} className="clear-btn">
    Clear Output
  </button>
  
  <button onClick={toggleTheme} className="theme-btn">
    <MoonIcon /> Dark
  </button>
  
  {/* NEW: AI Assistant Button */}
  <AIAssistant 
    code={code}
    language={selectedLanguage}
    onInsertCode={handleInsertCode}
  />
</div>
```

---

## 📋 Step 2: Add Insert Code Handler

Add this function to handle code insertion from AI Assistant:

```jsx
const handleInsertCode = (suggestedCode) => {
  // Insert code at cursor position or replace all
  setCode(suggestedCode);
  // Or use your editor's insert method
  // editorRef.current.insert(suggestedCode);
};
```

---

## 📋 Step 3: Import CSS

In your main component or `App.jsx`, import the AI Assistant CSS:

```jsx
import './styles/AIAssistant.css';
```

---

## 📋 Step 4: Create Backend API Endpoint

Create a new file or add to existing backend:

### Location: `Backend/Genai/ai_assistant.py`

```python
from flask import Flask, request, jsonify
from flask_cors import CORS
import google.generativeai as genai
import os

app = Flask(__name__)
CORS(app)

# Configure Gemini API
GEMINI_API_KEY = os.getenv('GEMINI_API_KEY', 'your-api-key-here')
genai.configure(api_key=GEMINI_API_KEY)

@app.route('/api/ai-assistant', methods=['POST'])
def ai_assistant():
    try:
        data = request.json
        user_message = data.get('message', '')
        code = data.get('code', '')
        language = data.get('language', 'javascript')
        
        # Build context
        context = f"""
You are an expert programming assistant specializing in {language}.

Current Code:
```{language}
{code}
```

User Question: {user_message}

Please provide helpful, clear, and concise responses. If suggesting code improvements, 
provide the complete updated code in your response.
"""
        
        # Call Gemini AI
        model = genai.GenerativeModel('gemini-pro')
        response = model.generate_content(context)
        
        # Extract code if present
        suggested_code = None
        response_text = response.text
        
        # Check if response contains code block
        if '```' in response_text:
            # Extract code from markdown code block
            parts = response_text.split('```')
            if len(parts) >= 3:
                suggested_code = parts[1]
                # Remove language identifier if present
                if '\n' in suggested_code:
                    lines = suggested_code.split('\n')
                    if lines[0].strip() in ['javascript', 'python', 'java', 'cpp', 'c']:
                        suggested_code = '\n'.join(lines[1:])
        
        return jsonify({
            'success': True,
            'response': response_text,
            'suggestedCode': suggested_code
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e),
            'response': 'Sorry, I encountered an error. Please try again.'
        }), 500

if __name__ == '__main__':
    app.run(debug=True, port=5001)
```

---

## 📋 Step 5: Update Backend Dependencies

Add to `Backend/Genai/requirements.txt`:

```txt
flask
flask-cors
google-generativeai
python-dotenv
```

Install:
```bash
cd Backend/Genai
pip install -r requirements.txt
```

---

## 📋 Step 6: Configure Environment Variables

Create `.env` file in `Backend/Genai/`:

```env
GEMINI_API_KEY=your-actual-gemini-api-key-here
PORT=5001
```

---

## 📋 Step 7: Update Frontend API Configuration

Update your API fetch configuration:

### Location: `Frontend/src/utils/apifetch.js` or API config

```javascript
export const AI_ASSISTANT_API = 'http://localhost:5001/api/ai-assistant';

export const callAIAssistant = async (message, code, language) => {
  const response = await fetch(AI_ASSISTANT_API, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      message,
      code,
      language
    })
  });
  
  return await response.json();
};
```

---

## 📋 Step 8: Run the Backend

```bash
cd Backend/Genai
python ai_assistant.py
```

Backend will run on `http://localhost:5001`

---

## 📋 Step 9: Build and Run Frontend

```bash
cd Frontend
npm install
npm run dev
```

---

## 🎨 Customization

### Change Button Style

Edit `Frontend/src/styles/AIAssistant.css`:

```css
.ai-assistant-toggle {
  background: linear-gradient(135deg, #YOUR_COLOR_1 0%, #YOUR_COLOR_2 100%);
  /* Customize as needed */
}
```

### Change Panel Position

In `AIAssistant.css`:

```css
.ai-assistant-panel {
  right: 20px;  /* Change position */
  bottom: 20px; /* Change position */
  width: 400px; /* Change width */
}
```

### Add More Quick Actions

In `AIAssistant.jsx`, add to `handleQuickAction`:

```jsx
case 'refactor':
  prompt = 'Refactor this code to be more efficient:';
  break;
case 'tests':
  prompt = 'Write unit tests for this code:';
  break;
```

---

## 🔌 Alternative: Use OpenAI Instead of Gemini

Replace Gemini code with OpenAI:

```python
import openai

openai.api_key = os.getenv('OPENAI_API_KEY')

response = openai.ChatCompletion.create(
    model="gpt-4",
    messages=[
        {"role": "system", "content": "You are a programming assistant"},
        {"role": "user", "content": context}
    ]
)

response_text = response.choices[0].message.content
```

---

## 🧪 Testing

### Test the AI Assistant:

1. **Open Code Editor**
2. **Click AI Assistant Button** (purple gradient)
3. **Try Quick Actions:**
   - "Explain Code"
   - "Find Bugs"
   - "Optimize"
   - "Add Docs"
4. **Ask Custom Questions:**
   - "How can I improve this function?"
   - "Add error handling"
   - "Convert this to async/await"

---

## 🐛 Troubleshooting

### Button Not Appearing?
- Check if `AIAssistant.jsx` is imported in Header
- Check if CSS is imported
- Check browser console for errors

### API Not Working?
- Verify backend is running on port 5001
- Check CORS settings
- Verify Gemini API key is correct
- Check browser network tab for API errors

### Panel Not Opening?
- Check browser console for React errors
- Verify state management (useState)
- Check z-index in CSS

---

## 📊 Features Included

✅ **Chat Interface** - Natural conversation with AI
✅ **Quick Actions** - One-click common tasks
✅ **Code Suggestions** - AI can suggest code improvements
✅ **Code Insertion** - Insert AI-suggested code directly
✅ **Context Aware** - AI knows your current code and language
✅ **Dark Theme Support** - Works with dark/light themes
✅ **Responsive Design** - Works on mobile and desktop
✅ **Typing Indicator** - Shows when AI is thinking
✅ **Conversation History** - Maintains context
✅ **Beautiful UI** - Modern gradient design

---

## 🚀 Next Steps

1. **Get Gemini API Key:** https://makersuite.google.com/app/apikey
2. **Follow steps 1-9 above**
3. **Test the integration**
4. **Customize as needed**

---

## 💡 Example Use Cases

### 1. Explain Code
```
User: "Explain this code"
AI: "This function performs a binary search..."
```

### 2. Find Bugs
```
User: "Find bugs"
AI: "I found a potential null pointer issue on line 12..."
```

### 3. Optimize
```
User: "How can I make this faster?"
AI: "You can optimize by using memoization..." + [Code]
```

### 4. Add Documentation
```
User: "Add comments"
AI: [Returns commented version of code]
```

---

## 📞 Support

For issues or questions, check:
- Browser console for errors
- Backend logs for API errors
- Network tab for API calls

---

**Enjoy your new AI Assistant! 🎉**





