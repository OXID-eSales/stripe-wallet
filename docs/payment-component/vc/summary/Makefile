# OSC Portfolio Presentation Makefile
# Generates HTML presentations with JavaScript (view in browser)

.PHONY: all build clean watch preview help

# Default: Build HTML presentation (Futurama theme)
all: build

# Build HTML presentation (main command)
build:
	@echo "🚀 Building HTML presentation..."
	marp index.md --html --allow-local-files -o index.html
	@echo "✅ Done! Open index.html in your browser"
	@echo ""
	@echo "Navigate slides:"
	@echo "  → / Space    Next slide"
	@echo "  ← / Backspace Previous slide"
	@echo "  F11          Fullscreen"

# Alternative: Build Futurama variant
build-futurama:
	@echo "🚀 Building Futurama HTML presentation..."
	marp index-futurama.md --html --allow-local-files -o presentation.html
	@echo "✅ Done! Open presentation.html in your browser"

# Clean generated files
clean:
	@rm -f index.html presentation.html
	@echo "🧹 Cleaned all generated HTML files"

# Watch mode (auto-rebuild on save)
watch:
	@echo "👁️  Watching for changes... (Ctrl+C to stop)"
	marp index.md --watch --html --allow-local-files -o index.html

# Preview in browser with live reload
preview:
	@echo "🌐 Opening preview in browser..."
	marp index.md --preview

# Open the presentation in default browser
open:
	@if [ -f index.html ]; then \
		xdg-open index.html 2>/dev/null || open index.html 2>/dev/null || start index.html 2>/dev/null || \
		echo "Please open index.html manually in your browser"; \
	else \
		echo "❌ index.html not found. Run 'make build' first"; \
	fi

# Build and open in one command
present: build open

# Help
help:
	@echo "OSC Portfolio Presentation - HTML Only (Simple & Reliable)"
	@echo ""
	@echo "📊 Main Commands:"
	@echo "  make build    - Build HTML presentation (DEFAULT) ✅"
	@echo "  make present  - Build and open in browser 🚀"
	@echo "  make open     - Open existing presentation"
	@echo ""
	@echo "🛠️  Development:"
	@echo "  make watch    - Auto-rebuild on file changes"
	@echo "  make preview  - Preview with live reload"
	@echo "  make clean    - Remove generated files"
	@echo ""
	@echo "💡 Usage:"
	@echo "  1. make build          (generates index.html)"
	@echo "  2. Open index.html in Chrome/Firefox"
	@echo "  3. Press F11 for fullscreen"
	@echo "  4. Use arrow keys to navigate"
	@echo ""
	@echo "✨ Theme: Futurama (Neon green cyberpunk)"
	@echo "📁 Output: index.html (standalone, works offline)"
