# Installation & Build Guide

## ✅ What's Been Created

**18-Slide Presentation** with 5 TV show themes:
- 🚀 **Futurama** - Cyberpunk/Neon Green (Dark background, high tech)
- 🟡 **Simpsons** - Yellow/Cartoon (Bright, fun, energetic)
- 🎨 **South Park** - Primary Colors/Bold (Simple, in-your-face)
- 🧪 **Breaking Bad** - Desert/Chemical Green (Gritty, serious)
- 🌀 **Rick & Morty** - Portal Green/Purple (Sci-fi, colorful)

**Supporting Materials:**
- 5 PlantUML business diagrams (converted to SVG)
- Comprehensive presenter notes (brave solution-style)
- All with proper contrast (WCAG compliant)

---

## 🚀 Quick Start (3 Steps)

### Step 1: Install Marp CLI

You need Marp CLI to build the presentations. Install it with npm:

```bash
npm install -g @marp-team/marp-cli
```

Or with yarn:
```bash
yarn global add @marp-team/marp-cli
```

**Verify installation:**
```bash
marp --version
```

### Step 2: Build All Themes

```bash
cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/vc/summary
make all
```

This will generate:
- `./futurama/index.html`
- `./simpsons/index.html`
- `./southpark/index.html`
- `./breakingbad/index.html`
- `./rickmorty/index.html`

### Step 3: Open in Browser

```bash
# Open any theme (or all of them!)
make present-futurama
make present-simpsons
make present-southpark
make present-breakingbad
make present-rickmorty
```

Or just open the HTML files directly in Chrome/Firefox.

---

## 📁 File Structure

```
summary/
├── index.md                    # Futurama theme (main)
├── index-simpsons.md           # Simpsons theme
├── index-southpark.md          # South Park theme
├── index-breakingbad.md        # Breaking Bad theme
├── index-rickmorty.md          # Rick & Morty theme
├── Makefile                    # Build automation
├── PRESENTER-NOTES.md          # Brave solution-style notes for all 18 slides
├── README.md                   # Full documentation
├── INSTALLATION.md             # This file
├── futurama/                   # Generated HTML (after build)
│   └── index.html
├── simpsons/                   # Generated HTML (after build)
│   └── index.html
├── southpark/                  # Generated HTML (after build)
│   └── index.html
├── breakingbad/                # Generated HTML (after build)
│   └── index.html
└── rickmorty/                  # Generated HTML (after build)
    └── index.html
```

---

## 🛠️ Make Commands

### Build Commands

```bash
make all              # Build ALL 5 themes (default)
make build-futurama   # Build only Futurama
make build-simpsons   # Build only Simpsons
make build-southpark  # Build only South Park
make build-breakingbad # Build only Breaking Bad
make build-rickmorty  # Build only Rick & Morty
```

### Build & Open

```bash
make present-futurama     # Build and open Futurama
make present-simpsons     # Build and open Simpsons
make present-southpark    # Build and open South Park
make present-breakingbad  # Build and open Breaking Bad
make present-rickmorty    # Build and open Rick & Morty
```

### Development

```bash
make watch    # Auto-rebuild Futurama on file changes
make preview  # Live preview with hot-reload
make clean    # Remove all generated HTML files
make help     # Show all available commands
```

---

## 🎨 Theme Comparison

| Theme | Best For | Vibe | Colors |
|-------|----------|------|--------|
| **Futurama** | Tech audiences, developers | Cyberpunk, futuristic | Neon green, cyan, dark |
| **Simpsons** | Fun, energetic pitches | Cartoon, bright, approachable | Yellow, blue, red |
| **South Park** | Bold, in-your-face | Simple, direct, loud | Primary colors, black borders |
| **Breaking Bad** | Serious, dramatic | Gritty, professional | Desert tan, chemical green |
| **Rick & Morty** | Sci-fi, creative | Colorful, portal vibes | Portal green, purple, cyan |

---

## 📊 The 18 Slides

1. **Title** - The OSC €2 Billion Opportunity
2. **Vision** - Build OXID + Vertical Ecosystems
3. **Problem** - What we're solving
4. **Portfolio** - 40 Products overview
5. **Numbers** - €2.09B, 40,449% ROI
6. **Mini-Team Deep Dive** - M1-M20 products
7. **Platform Deep Dive** - P1-P20 products
8. **Revenue Sharing** - Everyone wins model
9. **Developer Royalties** - €8.6M/year (KEY SLIDE)
10. **Team Dynamics** - How 2-3 colleagues work together
11. **Timeline** - 18 months to first €1M
12. **Regional Strategy** - 6 markets, phased approach
13. **Top 10 Opportunities** - Highest ROI products
14. **Technology Stack** - PHP 8.2, React/Vue, K8s
15. **Phase 1** - Start with 5 products (€600K)
16. **Risk Mitigation** - Low risk, high reward
17. **Call to Action** - Join the journey
18. **Closing** - Remember the numbers

---

## 💡 Usage Tips

### For Presentations

1. **Pick your theme** based on your audience:
   - Developers/Tech: Futurama or Rick & Morty
   - Business/Fun: Simpsons
   - Bold/Direct: South Park
   - Serious/Professional: Breaking Bad

2. **Open in browser** (Chrome or Firefox recommended)

3. **Press F11** for fullscreen

4. **Navigate** with arrow keys or Space/Backspace

5. **Use PRESENTER-NOTES.md** for talking points (20-25 min total)

### For Sharing

- **Email:** Just send the HTML file (2-3MB, works offline)
- **USB Drive:** Copy HTML file to USB, works on any computer
- **Online:** Upload to any web server

---

## 🎯 PlantUML Diagrams (in ../puml/)

5 business diagrams created:
1. **01-wealth-creation-flow.puml** - Overall model (€80K → €8.6M/year)
2. **02-m3-payment-component.puml** - Payment library quick win
3. **03-m1-marketplace-network.puml** - Marketplace network effects
4. **04-p1-cloud-paas.puml** - OXID Cloud PaaS model
5. **05-revenue-flow.puml** - Revenue distribution (P16 example)

**Already generated as SVG** in `../generated/`

View diagrams:
```bash
cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/vc/puml
ls ../_generated/*.svg
```

---

## ❓ Troubleshooting

### "marp: command not found"

Install Marp CLI:
```bash
npm install -g @marp-team/marp-cli
```

If you don't have npm, install Node.js first:
- **Ubuntu/Debian:** `sudo apt install nodejs npm`
- **Mac:** `brew install node`
- **Windows:** Download from nodejs.org

### Build errors

1. Make sure you're in the right directory:
   ```bash
   cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/vc/summary
   ```

2. Check that theme files exist:
   ```bash
   ls -l index*.md
   ```

3. Try building one theme at a time:
   ```bash
   make build-futurama
   ```

### Presentations look wrong

- Use **Chrome** or **Firefox** (not Edge/Safari)
- Enable **JavaScript** in browser
- Rebuild: `make clean && make all`

---

## ✅ What's Next?

1. **Install Marp CLI** (if not already installed)
2. **Run `make all`** to build all 5 themes
3. **Open presentations** in browser
4. **Practice with PRESENTER-NOTES.md** (20-25 min timing)
5. **Choose your theme** based on audience
6. **Present!** 🚀

---

**Created:** 2025-10-27
**Status:** Ready to build & present
**Portfolio:** 40 products, €2.09B (3Y), 40,449% ROI
**Themes:** 5 (Futurama, Simpsons, South Park, Breaking Bad, Rick & Morty)

**Good luck with your €2 billion pitch!** 💰🚀
