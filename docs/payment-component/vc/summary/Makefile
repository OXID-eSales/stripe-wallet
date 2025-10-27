# OSC Portfolio Presentation Makefile - All 5 Themes
# Generates HTML presentations with JavaScript (view in browser)

.PHONY: all build clean watch preview help build-all themes

# Default: Build all themes
all: build-all

# Build all 5 themes into separate subdirectories
build-all: themes
	@echo "🎨 Building all 5 themes..."
	@echo ""
	@$(MAKE) -s build-futurama
	@$(MAKE) -s build-simpsons
	@$(MAKE) -s build-southpark
	@$(MAKE) -s build-breakingbad
	@$(MAKE) -s build-rickmorty
	@echo ""
	@echo "✅ All themes built successfully!"
	@echo ""
	@echo "📁 Presentations available in:"
	@echo "   ./futurama/index.html"
	@echo "   ./simpsons/index.html"
	@echo "   ./southpark/index.html"
	@echo "   ./breakingbad/index.html"
	@echo "   ./rickmorty/index.html"

# Create theme directories
themes:
	@mkdir -p futurama simpsons southpark breakingbad rickmorty

# Build individual themes
build-futurama:
	@echo "🚀 Building Futurama (Cyberpunk/Neon Green)..."
	@marp index.md --html --allow-local-files -o futurama/index.html

build-simpsons:
	@echo "🟡 Building Simpsons (Yellow/Cartoon)..."
	@marp index-simpsons.md --html --allow-local-files -o simpsons/index.html

build-southpark:
	@echo "🎨 Building South Park (Primary Colors/Bold)..."
	@marp index-southpark.md --html --allow-local-files -o southpark/index.html

build-breakingbad:
	@echo "🧪 Building Breaking Bad (Desert/Chemical Green)..."
	@marp index-breakingbad.md --html --allow-local-files -o breakingbad/index.html

build-rickmorty:
	@echo "🌀 Building Rick & Morty (Portal Green/Purple)..."
	@marp index-rickmorty.md --html --allow-local-files -o rickmorty/index.html

# Build just the default Futurama theme (legacy)
build:
	@echo "🚀 Building Futurama HTML presentation..."
	@marp index.md --html --allow-local-files -o index.html
	@echo "✅ Done! Open index.html in your browser"

# Clean all generated files
clean:
	@rm -f index.html
	@rm -f futurama/index.html simpsons/index.html southpark/index.html breakingbad/index.html rickmorty/index.html
	@echo "🧹 Cleaned all generated HTML files"

# Watch mode (auto-rebuild on save) - Futurama only
watch:
	@echo "👁️  Watching for changes... (Ctrl+C to stop)"
	@marp index.md --watch --html --allow-local-files -o index.html

# Preview in browser with live reload - Futurama only
preview:
	@echo "🌐 Opening preview in browser..."
	@marp index.md --preview

# Open presentations in browser
open-futurama:
	@xdg-open futurama/index.html 2>/dev/null || open futurama/index.html 2>/dev/null || start futurama/index.html 2>/dev/null || echo "Please open futurama/index.html manually"

open-simpsons:
	@xdg-open simpsons/index.html 2>/dev/null || open simpsons/index.html 2>/dev/null || start simpsons/index.html 2>/dev/null || echo "Please open simpsons/index.html manually"

open-southpark:
	@xdg-open southpark/index.html 2>/dev/null || open southpark/index.html 2>/dev/null || start southpark/index.html 2>/dev/null || echo "Please open southpark/index.html manually"

open-breakingbad:
	@xdg-open breakingbad/index.html 2>/dev/null || open breakingbad/index.html 2>/dev/null || start breakingbad/index.html 2>/dev/null || echo "Please open breakingbad/index.html manually"

open-rickmorty:
	@xdg-open rickmorty/index.html 2>/dev/null || open rickmorty/index.html 2>/dev/null || start rickmorty/index.html 2>/dev/null || echo "Please open rickmorty/index.html manually"

# Build and open
present-futurama: build-futurama open-futurama
present-simpsons: build-simpsons open-simpsons
present-southpark: build-southpark open-southpark
present-breakingbad: build-breakingbad open-breakingbad
present-rickmorty: build-rickmorty open-rickmorty

# Help
help:
	@echo "OSC Portfolio Presentation - 5 TV Show Themes! 🎨"
	@echo ""
	@echo "📊 Main Commands:"
	@echo "  make all           - Build ALL 5 themes (DEFAULT) ✅"
	@echo "  make build-all     - Same as 'make all'"
	@echo ""
	@echo "🎨 Individual Theme Builds:"
	@echo "  make build-futurama     - Cyberpunk/Neon Green (Futurama)"
	@echo "  make build-simpsons     - Yellow/Cartoon (The Simpsons)"
	@echo "  make build-southpark    - Primary Colors/Bold (South Park)"
	@echo "  make build-breakingbad  - Desert/Chemical Green (Breaking Bad)"
	@echo "  make build-rickmorty    - Portal Green/Purple (Rick & Morty)"
	@echo ""
	@echo "🚀 Build & Open:"
	@echo "  make present-futurama"
	@echo "  make present-simpsons"
	@echo "  make present-southpark"
	@echo "  make present-breakingbad"
	@echo "  make present-rickmorty"
	@echo ""
	@echo "🛠️  Development:"
	@echo "  make watch    - Auto-rebuild Futurama theme on file changes"
	@echo "  make preview  - Preview Futurama with live reload"
	@echo "  make clean    - Remove all generated files"
	@echo ""
	@echo "💡 Usage:"
	@echo "  1. make all           (generates all 5 themes)"
	@echo "  2. Open any theme in Chrome/Firefox"
	@echo "  3. Press F11 for fullscreen"
	@echo "  4. Use arrow keys to navigate"
	@echo ""
	@echo "✨ Themes: Futurama | Simpsons | South Park | Breaking Bad | Rick & Morty"
	@echo "📁 Output: Each theme in its own subdirectory (works offline)"
