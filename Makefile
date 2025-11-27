# Makefile for OXID Stripe Payment Module
# Provides convenient shortcuts for common development tasks

.PHONY: help install build build-dev build-prod watch clean test test-unit test-integration style style-commit phpstan phpstan-commit phpcs phpmd

# Default target - show help
help:
	@echo "╔════════════════════════════════════════════════════════════════╗"
	@echo "║  OXID Stripe Payment Module - Makefile                        ║"
	@echo "╚════════════════════════════════════════════════════════════════╝"
	@echo ""
	@echo "Available targets:"
	@echo ""
	@echo "  📦 Installation & Setup:"
	@echo "    make install         Install composer and npm dependencies"
	@echo "    make install-composer Install only composer dependencies"
	@echo "    make install-npm     Install only npm dependencies"
	@echo ""
	@echo "  🔨 Build (JavaScript):"
	@echo "    make build           Build JavaScript (production)"
	@echo "    make build-dev       Build JavaScript (development with source maps)"
	@echo "    make build-prod      Build JavaScript (production, minified)"
	@echo "    make watch           Watch JavaScript files and rebuild on change"
	@echo ""
	@echo "  🧪 Testing:"
	@echo "    make test            Run all tests (unit + integration)"
	@echo "    make test-unit       Run unit tests only"
	@echo "    make test-integration Run integration tests only"
	@echo ""
	@echo "  ✨ Code Quality:"
	@echo "    make style           Run all code style checks (phpstan, phpcs, phpmd)"
	@echo "    make style-commit    Run code style checks (pre-commit)"
	@echo "    make phpstan         Run PHPStan static analysis"
	@echo "    make phpstan-commit  Run PHPStan (pre-commit)"
	@echo "    make phpcs           Run PHP CodeSniffer"
	@echo "    make phpmd           Run PHP Mess Detector"
	@echo ""
	@echo "  🧹 Cleanup:"
	@echo "    make clean           Remove build artifacts and caches"
	@echo "    make clean-js        Remove JavaScript build artifacts"
	@echo "    make clean-vendor    Remove vendor directories"
	@echo ""

# ==========================================
# Installation & Setup
# ==========================================

install: install-composer install-npm
	@echo "✓ All dependencies installed"

install-composer:
	@echo "📦 Installing Composer dependencies..."
	composer install

install-npm:
	@echo "📦 Installing NPM dependencies..."
	npm install

# ==========================================
# Build (JavaScript)
# ==========================================

build: build-prod

build-dev:
	@echo "🔨 Building JavaScript (development)..."
	npm run build:dev

build-prod:
	@echo "🔨 Building JavaScript (production)..."
	npm run build:prod

watch:
	@echo "👀 Watching JavaScript files..."
	npm run watch

# ==========================================
# Testing
# ==========================================

test:
	@echo "🧪 Running all tests..."
	composer phpunit

test-unit:
	@echo "🧪 Running unit tests..."
	composer phpunit -- --testsuite Unit

test-integration:
	@echo "🧪 Running integration tests..."
	composer phpunit -- --testsuite Integration

# ==========================================
# Code Quality
# ==========================================

style: phpstan phpcs phpmd
	@echo "✓ All code style checks passed"

style-commit:
	@echo "✨ Running code style checks (pre-commit)..."
	composer style-commit

phpstan:
	@echo "🔍 Running PHPStan..."
	composer phpstan

phpstan-commit:
	@echo "🔍 Running PHPStan (pre-commit)..."
	composer phpstan-commit

phpcs:
	@echo "🔍 Running PHP CodeSniffer..."
	composer phpcs

phpmd:
	@echo "🔍 Running PHP Mess Detector..."
	composer phpmd

# ==========================================
# Cleanup
# ==========================================

clean: clean-js
	@echo "🧹 Cleaning caches..."
	rm -rf var/cache/*
	rm -rf var/log/*
	@echo "✓ Cleanup complete"

clean-js:
	@echo "🧹 Cleaning JavaScript build artifacts..."
	rm -rf assets/js/*.js
	rm -rf assets/js/*.map
	rm -rf node_modules/.cache

clean-vendor:
	@echo "🧹 Removing vendor directories..."
	rm -rf vendor
	rm -rf node_modules
	@echo "✓ Vendor directories removed (run 'make install' to reinstall)"

# ==========================================
# Development Shortcuts
# ==========================================

# Quick dev workflow: install deps + build + test
dev-setup: install build-dev test
	@echo "✓ Development setup complete"

# Quick check before commit
pre-commit: style-commit test-unit build-dev
	@echo "✓ Pre-commit checks passed"
