# Theme Quick Start Guide

## 🚀 You're All Set with FUTURAMA Theme!

The main presentation (`index.md`) now uses the **Futurama cyberpunk theme** - perfect for your OSC developer team!

### Current Theme Features

**🎨 Futurama Theme (Cyberpunk/Sci-Fi)**
- **Background:** Dark (#0a0a0a) with gradient to (#1a1a2e)
- **Primary Text:** Neon green (#00ff00) with glow effect
- **Secondary:** Cyan (#00d4ff), Orange (#ff6b00), Magenta (#ff00ff)
- **Font:** Courier New (monospace, terminal-like)
- **Vibe:** Futuristic, tech-forward, Matrix-inspired

### Why Futurama for OSC Team?

✅ **Developer Appeal:** Monospace fonts, terminal aesthetic
✅ **Tech Vibe:** Sci-fi colors match innovation narrative
✅ **Energy:** Neon glows create excitement
✅ **Memorable:** Stands out from boring corporate presentations
✅ **On-Brand:** "Future of e-commerce" = futuristic design

---

## 📦 What You Have

```
summary/
├── index.md                  ← MAIN FILE (Futurama theme)
├── index-futurama.md         ← Backup copy (identical)
├── Makefile                  ← Build automation
├── README.md                 ← Full documentation
├── THEMES.md                 ← Theme comparison guide
└── THEMES-QUICKSTART.md      ← This file
```

---

## 🎬 Build Your Presentation NOW

### Option 1: Quick Build (PPTX only)

```bash
cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/vc/summary
make pptx
```

**Creates:** `OSC-Portfolio-Presentation.pptx`

### Option 2: All Formats

```bash
make all
```

**Creates:**
- `OSC-Portfolio-Presentation.pptx` (PowerPoint)
- `OSC-Portfolio-Presentation.pdf` (PDF)
- `OSC-Portfolio-Presentation.html` (HTML)

### Option 3: Preview in Browser

```bash
make preview
```

Opens live preview with hot-reload.

---

## 🎨 Theme Color Reference (Futurama)

### Primary Colors

| Element | Color | Hex | Usage |
|---------|-------|-----|-------|
| **Background** | Black | `#0a0a0a` | Main background |
| **H1 Headings** | Neon Green | `#00ff00` | Main titles (with glow) |
| **H2 Headings** | Cyan | `#00d4ff` | Section titles (with glow) |
| **H3 Headings** | Orange | `#ff6b00` | Sub-headings |
| **Strong Text** | Magenta | `#ff00ff` | Emphasis (with glow) |
| **Body Text** | Light Gray | `#cccccc` | Regular text |
| **Code Blocks** | Green on Black | `#00ff00` / `#0a0a0a` | Terminal look |

### Component Colors

| Component | Colors | Effect |
|-----------|--------|--------|
| **Metrics** | Blue gradient + Green text | Glowing numbers |
| **Success Boxes** | Dark green + Neon green | Success glow |
| **Gold Boxes** | Dark orange + Orange | Warning glow |
| **Feature Boxes** | Dark blue + Cyan | Info glow |
| **Tables** | Dark rows + Green headers | Matrix style |

---

## 🎯 Presentation Checklist

Before your team meeting:

- [ ] Install Marp: `npm install -g @marp-team/marp-cli`
- [ ] Build PPTX: `make pptx`
- [ ] Test opening file: Check if PPTX opens correctly
- [ ] Review slides: Walk through all 10 slides
- [ ] Prepare talking points: Use README timing guide
- [ ] Charge laptop: 10-minute presentation needs battery
- [ ] Backup to USB: Always have backup

---

## 🔄 Quick Theme Customization

### Make Text Brighter

In `index.md`, line ~11, change:
```css
color: #00ff00;  /* Change to #00ff88 for softer green */
```

### Change Background Darkness

Line ~12:
```css
background: linear-gradient(135deg, #0a0a0a 0%, #1a1a2e 100%);
/* Make darker: #000000 to #0a0a0a */
/* Make lighter: #1a1a1a to #2a2a3e */
```

### Remove Glow Effects

Find all `text-shadow` lines and delete them:
```css
text-shadow: 0 0 10px #00ff00;  /* DELETE THIS LINE to remove glow */
```

---

## 💡 Pro Tips

### For Dark Rooms
✅ Futurama theme PERFECT - high contrast, glowing elements

### For Bright Rooms
⚠️ May need to increase brightness in `index.md`:
- Change background to `#1a1a2a` (lighter)
- Change text to `#00ff88` (brighter green)

### For Projectors
✅ Neon colors project well on most screens
⚠️ Old projectors may wash out colors - test beforehand

### For Video Calls
✅ High contrast works great on Zoom/Teams
✅ Glowing effects add visual interest on screen

---

## 🚀 Ready to Present!

Your presentation is **ready to go** with the Futurama theme. Here's what makes it special:

1. **€8.6M developer royalties** - Life-changing wealth message in neon green
2. **40 products** - Comprehensive portfolio in cyberpunk style
3. **€2.09B revenue** - Massive opportunity with sci-fi flair
4. **10 slides, 10 minutes** - Perfect timing, maximum impact

### The Key Message

> "Transform the e-commerce ecosystem while building **generational wealth** for developers"

All wrapped in a futuristic, tech-forward design that your developer team will love! 🚀

---

## 🎬 Next Steps

1. **Build it:** `make pptx`
2. **Review it:** Open the PPTX file
3. **Practice it:** 10-minute dry run
4. **Present it:** Blow minds with €2B opportunity
5. **Recruit developers:** Show them the €8.6M/year royalties (Slide 6!)

---

**Questions?**
- Check `README.md` for full documentation
- Check `THEMES.md` for other theme options
- Check the presentation itself - all answers are in there!

**Good luck with your presentation!** 🚀💚
