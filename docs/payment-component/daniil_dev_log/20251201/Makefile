svg:
	docker run --rm -v $$(pwd):/workspace plantuml/plantuml -tsvg /workspace/puml/*.puml -o /workspace/_generated/

.PHONY: svg clean rebuild

clean:
	sudo rm -f _generated/*.svg

rebuild: clean svg
	@echo "✅ SVG diagrams regenerated!"

help:
	@echo "Available targets:"
	@echo "  svg      - Generate SVG files from all PUML diagrams"
	@echo "  rebuild  - Clean and regenerate all SVG files (recommended)"
	@echo "  clean    - Remove all generated SVG files"
	@echo "  help     - Show this help message"
