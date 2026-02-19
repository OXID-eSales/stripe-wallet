# Sprint 4: Mutation Integration Tests

**Goal:** Validate that our unit tests are correct by using mutation testing to prove they catch bugs.

**Branch:** b-7.4.x-code-review-STRP-75

---

## What is Mutation Testing?

Mutation testing introduces small changes (mutations) to your code and runs your tests against each mutation. If your tests are good, they should fail when the code is mutated. If tests still pass with mutated code, it means you have a gap in test coverage.

**Example mutations:**
- Change `===` to `!==`
- Change `true` to `false`
- Remove method calls
- Change `>` to `>=`

---

## Target Handlers for Mutation Testing

1. **StripeCaptureRequestHandler**
   - Tests: `StripeCaptureRequestHandlerTest.php`
   - Critical paths: direct capture, contract-based capture, error handling

2. **StripeCancelAuthorizationRequestHandler**
   - Tests: `StripeCancelAuthorizationRequestHandlerTest.php`
   - Critical paths: cancel via Stripe API, error handling, context updates

---

## Implementation Plan

### Step 1: Install Infection PHP
```bash
composer require --dev infection/infection
```

### Step 2: Create infection.json5 configuration
Configure which files to mutate and which tests to run.

### Step 3: Run mutation tests
```bash
vendor/bin/infection --threads=4 --filter=StripeCaptureRequestHandler
vendor/bin/infection --threads=4 --filter=StripeCancelAuthorizationRequestHandler
```

### Step 4: Analyze MSI (Mutation Score Indicator)
- **MSI > 80%**: Good test coverage
- **MSI 60-80%**: Acceptable but could improve
- **MSI < 60%**: Tests need improvement

---

## Expected Outcomes

1. Identify any gaps in test coverage for handlers
2. Verify that critical code paths are properly tested
3. Improve confidence in our test suite

---

## Files to Create/Modify

- `composer.json` - Add infection/infection dependency
- `infection.json5` - Mutation testing configuration
- Tests may need updates based on mutation test results

---

## Success Criteria

- [ ] Infection PHP installed and configured
- [ ] Mutation tests run successfully
- [ ] MSI > 70% for both handlers
- [ ] Any test gaps identified and documented

