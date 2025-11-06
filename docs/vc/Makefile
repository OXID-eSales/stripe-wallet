# Wealth Opportunity Presentations Makefile
# Generates HTML presentations with embedded JavaScript (single-file, offline-ready)
# All presentations use Futurama theme

.PHONY: all build clean watch preview help open

# Marp CLI command (use npx if marp not installed globally)
MARP := $(shell which marp 2>/dev/null || echo "npx -y @marp-team/marp-cli")

# Source files
PRES_01 := 01-developer-royalties-lifetime-wealth
PRES_02 := 02-solo-entrepreneur-path
PRES_03 := 03-mini-team-millions
PRES_04 := 04-platform-empire-builders
PRES_05 := 05-choose-your-wealth-path
PRES_06 := 06-industrial-platforms
PRES_07 := 07-automotive-parts-saas

# Output files
HTML_01 := $(PRES_01).html
HTML_02 := $(PRES_02).html
HTML_03 := $(PRES_03).html
HTML_04 := $(PRES_04).html
HTML_05 := $(PRES_05).html
HTML_06 := $(PRES_06).html
HTML_07 := $(PRES_07).html

ALL_HTML := $(HTML_01) $(HTML_02) $(HTML_03) $(HTML_04) $(HTML_05) $(HTML_06) $(HTML_07)

# Default: Build all presentations
all: build
	@echo ""
	@echo "✅ All presentations built successfully!"
	@echo ""
	@echo "📁 Presentations available:"
	@echo "   ./$(HTML_01)"
	@echo "   ./$(HTML_02)"
	@echo "   ./$(HTML_03)"
	@echo "   ./$(HTML_04)"
	@echo "   ./$(HTML_05)"
	@echo "   ./$(HTML_06)"
	@echo "   ./$(HTML_07)"
	@echo ""
	@echo "💡 Open any HTML file in your browser (works offline!)"
	@echo "💡 Press F11 for fullscreen, arrow keys to navigate"

# Build all presentations
build: $(ALL_HTML)

# Build individual presentations
$(HTML_01): $(PRES_01).md
	@echo "💎 Building Developer Royalties presentation..."
	@marp $< --html --allow-local-files -o $@

$(HTML_02): $(PRES_02).md
	@echo "🚀 Building Solo Entrepreneur presentation..."
	@marp $< --html --allow-local-files -o $@

$(HTML_03): $(PRES_03).md
	@echo "👥 Building Mini-Team Millions presentation..."
	@marp $< --html --allow-local-files -o $@

$(HTML_04): $(PRES_04).md
	@echo "🏰 Building Platform Empire Builders presentation..."
	@marp $< --html --allow-local-files -o $@

$(HTML_05): $(PRES_05).md
	@echo "🎯 Building Choose Your Wealth Path presentation..."
	@marp $< --html --allow-local-files -o $@

$(HTML_06): $(PRES_06).md
	@echo "🏭 Building Industrial Platforms presentation..."
	@marp $< --html --allow-local-files -o $@

$(HTML_07): $(PRES_07).md
	@echo "🚗 Building Automotive Parts SaaS presentation..."
	@marp $< --html --allow-local-files -o $@

# Quick build commands for each presentation
p1: $(HTML_01)
	@echo "✅ Presentation 1 ready: $(HTML_01)"

p2: $(HTML_02)
	@echo "✅ Presentation 2 ready: $(HTML_02)"

p3: $(HTML_03)
	@echo "✅ Presentation 3 ready: $(HTML_03)"

p4: $(HTML_04)
	@echo "✅ Presentation 4 ready: $(HTML_04)"

p5: $(HTML_05)
	@echo "✅ Presentation 5 ready: $(HTML_05)"

p6: $(HTML_06)
	@echo "✅ Presentation 6 ready: $(HTML_06)"

p7: $(HTML_07)
	@echo "✅ Presentation 7 ready: $(HTML_07)"

# Clean all generated files
clean:
	@rm -f $(ALL_HTML)
	@echo "🧹 Cleaned all generated HTML files"

# Watch mode (auto-rebuild on save) - watches all .md files
watch:
	@echo "👁️  Watching all presentations for changes... (Ctrl+C to stop)"
	@echo "📝 Edit any .md file and it will auto-rebuild"
	@while true; do \
		inotifywait -q -e modify,create *.md 2>/dev/null && \
		$(MAKE) -s build; \
	done || ( \
		echo "⚠️  inotifywait not found. Install: sudo apt-get install inotify-tools"; \
		echo "⚠️  Falling back to watch mode for presentation 1 only..."; \
		marp $(PRES_01).md --watch --html --allow-local-files -o $(HTML_01); \
	)

# Preview specific presentation with live reload
preview-1:
	@echo "🌐 Opening Presentation 1 preview..."
	@marp $(PRES_01).md --preview

preview-2:
	@echo "🌐 Opening Presentation 2 preview..."
	@marp $(PRES_02).md --preview

preview-3:
	@echo "🌐 Opening Presentation 3 preview..."
	@marp $(PRES_03).md --preview

preview-4:
	@echo "🌐 Opening Presentation 4 preview..."
	@marp $(PRES_04).md --preview

preview-5:
	@echo "🌐 Opening Presentation 5 preview..."
	@marp $(PRES_05).md --preview

preview-6:
	@echo "🌐 Opening Presentation 6 preview..."
	@marp $(PRES_06).md --preview

preview-7:
	@echo "🌐 Opening Presentation 7 preview..."
	@marp $(PRES_07).md --preview

# Open presentations in browser (cross-platform)
open-1:
	@xdg-open $(HTML_01) 2>/dev/null || open $(HTML_01) 2>/dev/null || start $(HTML_01) 2>/dev/null || echo "Please open $(HTML_01) manually"

open-2:
	@xdg-open $(HTML_02) 2>/dev/null || open $(HTML_02) 2>/dev/null || start $(HTML_02) 2>/dev/null || echo "Please open $(HTML_02) manually"

open-3:
	@xdg-open $(HTML_03) 2>/dev/null || open $(HTML_03) 2>/dev/null || start $(HTML_03) 2>/dev/null || echo "Please open $(HTML_03) manually"

open-4:
	@xdg-open $(HTML_04) 2>/dev/null || open $(HTML_04) 2>/dev/null || start $(HTML_04) 2>/dev/null || echo "Please open $(HTML_04) manually"

open-5:
	@xdg-open $(HTML_05) 2>/dev/null || open $(HTML_05) 2>/dev/null || start $(HTML_05) 2>/dev/null || echo "Please open $(HTML_05) manually"

open-6:
	@xdg-open $(HTML_06) 2>/dev/null || open $(HTML_06) 2>/dev/null || start $(HTML_06) 2>/dev/null || echo "Please open $(HTML_06) manually"

open-7:
	@xdg-open $(HTML_07) 2>/dev/null || open $(HTML_07) 2>/dev/null || start $(HTML_07) 2>/dev/null || echo "Please open $(HTML_07) manually"

# Open all presentations at once (useful for reviewing)
open-all: $(ALL_HTML)
	@echo "🌐 Opening all presentations..."
	@$(MAKE) -s open-1
	@sleep 1
	@$(MAKE) -s open-2
	@sleep 1
	@$(MAKE) -s open-3
	@sleep 1
	@$(MAKE) -s open-4
	@sleep 1
	@$(MAKE) -s open-5
	@sleep 1
	@$(MAKE) -s open-6
	@sleep 1
	@$(MAKE) -s open-7

# Build and open specific presentation
present-1: p1 open-1
present-2: p2 open-2
present-3: p3 open-3
present-4: p4 open-4
present-5: p5 open-5
present-6: p6 open-6
present-7: p7 open-7

# Export to PowerPoint (requires marp-cli)
pptx: $(ALL_HTML)
	@echo "📊 Exporting to PowerPoint format..."
	@marp $(PRES_01).md --pptx -o $(PRES_01).pptx
	@marp $(PRES_02).md --pptx -o $(PRES_02).pptx
	@marp $(PRES_03).md --pptx -o $(PRES_03).pptx
	@marp $(PRES_04).md --pptx -o $(PRES_04).pptx
	@marp $(PRES_05).md --pptx -o $(PRES_05).pptx
	@marp $(PRES_06).md --pptx -o $(PRES_06).pptx
	@marp $(PRES_07).md --pptx -o $(PRES_07).pptx
	@echo "✅ PowerPoint files created!"

# Export to PDF (requires Chrome/Chromium)
pdf: $(ALL_HTML)
	@echo "📄 Exporting to PDF format..."
	@marp $(PRES_01).md --pdf -o $(PRES_01).pdf
	@marp $(PRES_02).md --pdf -o $(PRES_02).pdf
	@marp $(PRES_03).md --pdf -o $(PRES_03).pdf
	@marp $(PRES_04).md --pdf -o $(PRES_04).pdf
	@marp $(PRES_05).md --pdf -o $(PRES_05).pdf
	@marp $(PRES_06).md --pdf -o $(PRES_06).pdf
	@marp $(PRES_07).md --pdf -o $(PRES_07).pdf
	@echo "✅ PDF files created!"

# Clean all exports (PDF, PPTX, HTML)
clean-all: clean
	@rm -f *.pdf *.pptx
	@echo "🧹 Cleaned all generated files (HTML, PDF, PPTX)"

# Development workflow
dev: watch

# Help
help:
	@echo "💎 Wealth Opportunity Presentations - Makefile 🚀"
	@echo ""
	@echo "📊 Main Commands:"
	@echo "  make all (or just 'make')  - Build ALL 5 HTML presentations ✅"
	@echo "  make clean                 - Remove all HTML files"
	@echo "  make clean-all             - Remove HTML, PDF, and PPTX files"
	@echo ""
	@echo "🎯 Individual Presentations:"
	@echo "  make p1  - Build: Developer Royalties (Lifetime Wealth)"
	@echo "  make p2  - Build: Solo Entrepreneur Path"
	@echo "  make p3  - Build: Mini-Team Millions"
	@echo "  make p4  - Build: Platform Empire Builders"
	@echo "  make p5  - Build: Choose Your Wealth Path"
	@echo "  make p6  - Build: Industrial Platforms"
	@echo "  make p7  - Build: Automotive Parts SaaS"
	@echo ""
	@echo "🌐 Open in Browser:"
	@echo "  make open-1       - Open Presentation 1"
	@echo "  make open-2       - Open Presentation 2"
	@echo "  make open-3       - Open Presentation 3"
	@echo "  make open-4       - Open Presentation 4"
	@echo "  make open-5       - Open Presentation 5"
	@echo "  make open-6       - Open Presentation 6"
	@echo "  make open-7       - Open Presentation 7"
	@echo "  make open-all     - Open all presentations"
	@echo ""
	@echo "🚀 Build & Present:"
	@echo "  make present-1    - Build p1 and open in browser"
	@echo "  make present-2    - Build p2 and open in browser"
	@echo "  make present-3    - Build p3 and open in browser"
	@echo "  make present-4    - Build p4 and open in browser"
	@echo "  make present-5    - Build p5 and open in browser"
	@echo "  make present-6    - Build p6 and open in browser"
	@echo "  make present-7    - Build p7 and open in browser"
	@echo ""
	@echo "📤 Export Formats:"
	@echo "  make pptx         - Export all to PowerPoint (.pptx)"
	@echo "  make pdf          - Export all to PDF (.pdf)"
	@echo ""
	@echo "🛠️  Development:"
	@echo "  make watch        - Auto-rebuild on file changes (all presentations)"
	@echo "  make preview-1    - Preview p1 with live reload"
	@echo "  make preview-2    - Preview p2 with live reload"
	@echo "  make preview-3    - Preview p3 with live reload"
	@echo "  make preview-4    - Preview p4 with live reload"
	@echo "  make preview-5    - Preview p5 with live reload"
	@echo "  make preview-6    - Preview p6 with live reload"
	@echo "  make preview-7    - Preview p7 with live reload"
	@echo "  make dev          - Same as 'make watch'"
	@echo ""
	@echo "💡 Quick Start:"
	@echo "  1. make               (builds all 5 HTML presentations)"
	@echo "  2. make present-5     (build & open the decision guide)"
	@echo "  3. Press F11          (fullscreen mode)"
	@echo "  4. Arrow keys         (navigate slides)"
	@echo ""
	@echo "🎨 Theme: All presentations use Futurama (neon green, cyan, orange, magenta)"
	@echo "📁 Output: Self-contained HTML files (work offline, embed all CSS/JS)"
	@echo ""
	@echo "📚 Presentations:"
	@echo "  1. Developer Royalties - The OSC 60% royalty model"
	@echo "  2. Solo Entrepreneur  - €500K-1M ARR, 100% ownership"
	@echo "  3. Mini-Team Millions - €1-3M/dev, best risk/reward"
	@echo "  4. Platform Builders  - €5-25M/dev, generational wealth"
	@echo "  5. Choose Your Path   - Complete comparison & decision guide ⭐"
	@echo "  6. Industrial Platforms - 6 opportunities in automotive, pharma & SaaS"
	@echo "  7. Automotive Parts SaaS - Startup pitch & business plan (3 devs + 3 mgrs)"

# Validate that marp is installed
check-marp:
	@command -v marp >/dev/null 2>&1 || { \
		echo "❌ Error: marp-cli is not installed"; \
		echo ""; \
		echo "Install with:"; \
		echo "  npm install -g @marp-team/marp-cli"; \
		echo ""; \
		echo "Or with yarn:"; \
		echo "  yarn global add @marp-team/marp-cli"; \
		echo ""; \
		exit 1; \
	}
	@echo "✅ marp-cli is installed"
	@marp --version

# Install marp if not present (requires npm)
install-marp:
	@echo "📦 Installing marp-cli..."
	@npm install -g @marp-team/marp-cli
	@echo "✅ marp-cli installed successfully!"

.DEFAULT_GOAL := all