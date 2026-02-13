# Sprint 48 Completion Report — Product Feed: Catalog Sync to AI Agents

**Sprint:** 48
**Priority:** High
**Status:** DONE
**Date:** 2026-02-13
**Branch:** `b-7.4.x-mcp-STRP-88`

---

## Summary

Implemented product feed generation for AI agent catalog sync. Created provider-agnostic interfaces in `payment-component` and Stripe-specific implementations in the stripe module. Supports CSV (Stripe format) and JSONL (OpenAI format) feed generation.

## Files Created

### payment-component (interfaces)
- `src/Mcp/Acp/ProductFeedGeneratorInterface.php` — `generate()`, `getContentType()`, `getFileExtension()`
- `src/Mcp/Acp/ProductFieldMapperInterface.php` — `mapProduct()`, `getFieldNames()`

### stripe (implementations)
- `src/Stripe/Mcp/Service/OxidArticleQueryServiceInterface.php` — OXID article query abstraction
- `src/Stripe/Mcp/Service/OxidProductService.php` — `AcpProductServiceInterface` impl, delegates to field mapper + article query
- `src/Stripe/Mcp/Service/OxidProductFieldMapper.php` — Maps OXID articles to ACP fields (13 fields: id, title, description, url, brand, price, currency, availability, image_url, gtin, mpn, weight, group_id)
- `src/Stripe/Mcp/ProductFeed/CsvFeedGenerator.php` — Stripe CSV format with field name mapping
- `src/Stripe/Mcp/ProductFeed/JsonlFeedGenerator.php` — OpenAI JSONL format with country config and eligibility flags
- `src/Stripe/Mcp/Event/ProductFeedRequestEvent.php` — Event wrapping EventContext
- `src/Stripe/Mcp/Handler/ProductFeedRequestHandler.php` — HandlerInterface impl, generates feed from products
- `src/Stripe/Mcp/Controller/ProductFeedController.php` — Event-only controller: auth > context > dispatch > response
- `src/Stripe/Mcp/Command/ProductFeedCommand.php` — CLI `stripe:product-feed:generate` with --format, --output, --limit

### Tests (5 files, 127 tests, 280 assertions)
- `tests/Unit/Stripe/Mcp/Service/OxidProductFieldMapperTest.php` — 43 tests
- `tests/Unit/Stripe/Mcp/ProductFeed/CsvFeedGeneratorTest.php` — 16 tests
- `tests/Unit/Stripe/Mcp/ProductFeed/JsonlFeedGeneratorTest.php` — 32 tests
- `tests/Unit/Stripe/Mcp/Service/OxidProductServiceTest.php` — 21 tests
- `tests/Unit/Stripe/Mcp/Handler/ProductFeedRequestHandlerTest.php` — 15 tests

### services.yaml additions
- `ProductFieldMapperInterface`, `ProductFeedGeneratorInterface`, `OxidArticleQueryServiceInterface`
- `OxidProductService` (replaces stub), `ProductFeedRequestHandler`, `ProductFeedCommand`
- Parameters: `stripe.feed.store_country`, `stripe.feed.target_countries`

## Key Design Decisions
- Title truncation at 150 chars, description at 5000 chars with HTML stripping
- Availability mapping: stock > 0 = `in_stock`, stock=0+flag=1 = `out_of_stock`, stock=0+flag=4 = `backorder`
- CSV excludes weight/currency (Stripe format); JSONL includes country eligibility flags
- Limit capped at 100 in `OxidProductService` to prevent memory issues
