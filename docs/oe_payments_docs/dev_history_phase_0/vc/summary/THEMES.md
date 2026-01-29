# Available Presentation Themes

## 🎨 Theme Selection Guide

Choose a theme that matches your audience and presentation style:

### 1. **Professional (Default)** - `index.md`
**Colors:** Blue (#0d47a1), Purple gradients, Clean white
**Best for:** Investors, OXID AG, formal presentations
**Vibe:** Corporate, trustworthy, professional

### 2. **Futurama** - `index-futurama.md`
**Colors:** Neon green (#00ff00), Cyan (#00d4ff), Orange (#ff6b00), Magenta (#ff00ff)
**Best for:** Tech-savvy developers, hackathons, informal team meetings
**Vibe:** Sci-fi, futuristic, cyberpunk

### 3. **Simpsons** - `index-simpsons.md`
**Colors:** Yellow (#FFD90F), Blue (#55ACEE), Red (#E32636), White
**Best for:** Fun team presentations, agency pitches, creative sessions
**Vibe:** Playful, friendly, approachable, colorful

### 4. **South Park** - `index-southpark.md`
**Colors:** Bright primary colors, construction paper texture
**Best for:** Informal team meetings, brainstorming, startup pitches
**Vibe:** Bold, edgy, no-nonsense, startup culture

### 5. **Breaking Bad** - `index-breakingbad.md`
**Colors:** Green chemical (#00ff41), Desert tan (#e3c985), Dark grays
**Best for:** Serious strategy discussions, board meetings, high-stakes pitches
**Vibe:** Serious, dramatic, high-stakes, transformation

### 6. **Rick and Morty** - `index-rickmorty.md`
**Colors:** Portal green (#00d4aa), Neon cyan (#00ffff), Purple (#9d00ff), Yellow science
**Best for:** Innovation discussions, R&D teams, disruptive startups
**Vibe:** Chaotic smart, interdimensional, scientific, edgy humor

---

## 🚀 Quick Build Commands

### Build All Themes

```bash
cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/vc/summary

# Professional (default)
make pptx              # Uses index.md

# Futurama theme
marp index-futurama.md --pptx -o OSC-Futurama.pptx

# Simpsons theme
marp index-simpsons.md --pptx -o OSC-Simpsons.pptx

# South Park theme
marp index-southpark.md --pptx -o OSC-SouthPark.pptx

# Breaking Bad theme
marp index-breakingbad.md --pptx -o OSC-BreakingBad.pptx

# Rick and Morty theme
marp index-rickmorty.md --pptx -o OSC-RickMorty.pptx
```

### Build All at Once

```bash
make all-themes
```

---

## 📊 Theme Comparison

| Theme | Background | Primary | Accent | Best For |
|-------|------------|---------|---------|----------|
| **Professional** | White | Blue | Purple | Investors, formal |
| **Futurama** | Dark (#0a0a0a) | Green neon | Cyan/Orange | Developers, tech |
| **Simpsons** | Yellow | Blue sky | Red/White | Agencies, fun |
| **South Park** | Bright | Primary colors | Red/Green | Startups, bold |
| **Breaking Bad** | Dark gray | Chemical green | Tan/Orange | Strategy, serious |
| **Rick & Morty** | Dark purple | Portal green | Neon cyan | Innovation, R&D |

---

## 🎯 Recommendation by Audience

### For OSC Team Meeting
- **First choice:** Futurama (tech vibe, developers love it)
- **Second choice:** Rick & Morty (innovation focus)
- **Safe choice:** Professional (always works)

### For OXID AG Partnership
- **First choice:** Professional (formal, trustworthy)
- **Second choice:** Breaking Bad (transformation narrative)

### For Agency Pitches
- **First choice:** Simpsons (friendly, approachable)
- **Second choice:** Professional (establishes credibility)

### For Investor Presentations
- **First choice:** Professional (corporate standard)
- **Second choice:** Breaking Bad (dramatic transformation story)

### For Developer Recruiting
- **First choice:** Rick & Morty (smart chaos, innovation)
- **Second choice:** Futurama (sci-fi tech appeal)

---

## 💡 Customization Tips

### Modify Colors

Edit the `style:` section in the YAML frontmatter:

```css
h1 {
  color: #YOUR_COLOR;  /* Change h1 color */
}
.metric {
  background: linear-gradient(135deg, #COLOR1 0%, #COLOR2 100%);
}
```

### Font Changes

```css
section {
  font-family: 'Your Font', fallback;
}
```

### Animation Effects

Marp supports some CSS animations in HTML output.

---

## 🛠️ Extended Makefile

The `Makefile` has been updated with:

```makefile
all-themes: pptx-futurama pptx-simpsons pptx-southpark pptx-breakingbad pptx-rickmorty

pptx-futurama:
	marp index-futurama.md --pptx -o OSC-Futurama.pptx

# ... etc for each theme
```

---

## 📝 Content is Identical

All themes have the **same content** - only colors and styling differ:

- ✅ Same 10 slides
- ✅ Same data and numbers
- ✅ Same charts and tables
- ✅ Only visual appearance changes

This allows you to:
1. Develop content in one file
2. Generate multiple themed versions
3. Choose theme based on audience
4. Switch themes instantly

---

**Created:** 2025-10-27
**Themes:** 6 total (1 professional + 5 TV show inspired)
