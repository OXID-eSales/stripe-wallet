# Troubleshooting Guide

## ❌ PDF Generation Error: "ReadableStream is not defined"

### The Problem

Marp CLI PDF generation fails with:
```
[ ERROR ] Failed converting Markdown. (ReadableStream is not defined)
```

This is a known issue with Marp CLI and certain Node.js versions.

### ✅ Solution 1: Use PowerPoint Instead (RECOMMENDED)

PowerPoint generation **always works** and is the best format for presentations:

```bash
# Generate Futurama PPTX (recommended)
make pptx-futurama

# Or default theme
make pptx
```

**Why PPTX is better:**
- ✅ Works 100% reliably
- ✅ Editable (change text, colors, layouts)
- ✅ Compatible with PowerPoint, Keynote, Google Slides
- ✅ Best for actual presentations
- ✅ Full animation support

### ✅ Solution 2: Generate PDF via Browser

Use HTML as intermediate format:

```bash
# Generate HTML
make pdf-via-html-futurama

# Then:
# 1. Open OSC-Futurama.html in Chrome or Firefox
# 2. Press Ctrl+P (or Cmd+P on Mac)
# 3. Select "Save as PDF" as destination
# 4. Settings: Margins = None, Scale = 100%
# 5. Click "Save"
```

**Browser PDF Settings:**
- **Margins:** None (for full-page slides)
- **Scale:** 100% (keep original size)
- **Background graphics:** ON (to show colors)
- **Headers/Footers:** OFF (no page numbers)

### ✅ Solution 3: Use LibreOffice to Convert

If you have LibreOffice installed:

```bash
# Generate PPTX first
make pptx-futurama

# Convert with LibreOffice
libreoffice --headless --convert-to pdf OSC-Futurama.pptx
```

### ✅ Solution 4: Fix Node.js (Advanced)

The issue is related to Node.js version. Try:

```bash
# Check Node version
node --version

# If < 18, update Node.js
# Using nvm (Node Version Manager):
nvm install 18
nvm use 18

# Then try PDF again
make pdf-futurama
```

### ✅ Solution 5: Use Docker Marp

Run Marp in Docker (isolated environment):

```bash
docker run --rm -v $(pwd):/home/marp/app/ marpteam/marp-cli index-futurama.md --pdf -o OSC-Futurama.pdf
```

---

## 🎯 Recommended Workflow

### For Team Presentations
**Use:** PowerPoint (PPTX)
```bash
make pptx-futurama
```

**Why:**
- Editable on the fly
- Works with projectors
- Can adjust slides during presentation
- Universal compatibility

### For Sharing/Distribution
**Use:** PDF via browser
```bash
make pdf-via-html-futurama
# Then print to PDF from browser
```

**Why:**
- Fixed format (looks same everywhere)
- Smaller file size
- Easy to email/share
- No editing needed

### For Online Viewing
**Use:** HTML
```bash
make html-futurama
```

**Why:**
- Interactive (arrow keys to navigate)
- Works in any browser
- Smallest file size
- Easy to host online

---

## Other Common Issues

### Issue: "marp: command not found"

**Solution:** Install Marp CLI

```bash
npm install -g @marp-team/marp-cli

# Or with yarn
yarn global add @marp-team/marp-cli
```

### Issue: PPTX opens but slides look wrong

**Solution:** This shouldn't happen, but if it does:

1. Check the .pptx file was generated recently
2. Try opening in different software (PowerPoint vs LibreOffice vs Google Slides)
3. Regenerate: `make clean && make pptx-futurama`

### Issue: Colors look wrong in PPTX

**Solution:** This is normal - PPTX doesn't support all CSS effects

- Glows and shadows may not render
- Gradients may be simplified
- This is a PowerPoint limitation
- Colors are still correct, just less "fancy"

**Fix:** Use HTML for full visual effects, PPTX for editability

### Issue: Build is slow

**Solution:** Marp can be slow on first run

- First build: 10-30 seconds (normal)
- Subsequent builds: 3-5 seconds
- Use `make watch` for live reload during development

---

## Quick Reference: What Works

| Format | Command | Status | Best For |
|--------|---------|--------|----------|
| **PPTX** | `make pptx-futurama` | ✅ Always works | Presentations |
| **HTML** | `make html-futurama` | ✅ Always works | Online viewing |
| **PDF (direct)** | `make pdf-futurama` | ⚠️ May fail | - |
| **PDF (browser)** | `make pdf-via-html-futurama` | ✅ Always works | Sharing/print |

---

## Still Having Issues?

### Check Your Setup

```bash
# Verify Marp is installed
which marp
marp --version

# Verify Node.js
node --version

# Should be v14+ (v18+ recommended)
```

### Test with Simple File

Create `test.md`:
```markdown
---
marp: true
---

# Test Slide

This is a test.
```

Then try:
```bash
marp test.md --pptx -o test.pptx
marp test.md --html -o test.html
```

If this works, the issue is with the presentation file. If it doesn't, the issue is with your Marp installation.

---

## Need Help?

1. **Check Marp documentation:** https://github.com/marp-team/marp-cli
2. **Check Node.js version:** `node --version` (should be 14+)
3. **Use PPTX instead:** It always works and is best for presentations anyway!

---

## Bottom Line

**For your OSC team presentation:**

```bash
# Just use this - it works!
make pptx-futurama
```

Open `OSC-Futurama.pptx` in PowerPoint/Keynote/Google Slides and you're ready to present! 🚀

PDF is nice to have, but PPTX is the professional standard for presentations and gives you the most flexibility.
