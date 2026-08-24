# Equivalent mutants — documented survivors

Mutants that Infection reports as *escaped* but which **cannot be killed**,
because the mutated code is semantically identical to the original. Writing a
test for one of these would be writing a test that asserts an implementation
detail, not a behaviour — which is the defect this sprint exists to remove.

**Rule:** an entry here needs an argument, not an assertion. If you cannot show
that the mutated program produces identical observable behaviour for every
input, it is not equivalent — kill it instead.

Introduced by Sprint 135 (mutation-hardening). Source report:
`docs/oe_payments_docs/daniil_dev_log/20260824/reports/01-mutation-testing-baseline.md`.

---

## `src/Stripe/Service/CustomerDataSanitizer.php:28` — `ReturnRemoval`

```php
if ($value === '') {
    return '';        // <- mutant deletes this return
}
```

**Why it is equivalent.** The early return is a short-circuit, not a behaviour.
With it deleted, an empty string falls through the remaining pipeline and every
step is a no-op on `''`:

| Step | Result on `''` |
|---|---|
| `mb_convert_encoding('', 'UTF-8', 'UTF-8')` | `''` |
| `preg_replace('/[\x00-\x08…]/u', '', '')` | `''` |
| `preg_replace('/\s+/u', ' ', '')` | `''` |
| `trim('')` | `''` |
| `mb_strlen('') > $maxLength` | `0 > n` is false for any `n >= 0` |
| `return $value` | `''` |

Both paths return `''` for the only input that reaches the branch, so no test
can distinguish them. The guard is retained because it documents intent and
avoids four pointless calls, not because it changes the result.

**Negative `$maxLength` caveat.** For `$maxLength < 0` the truncation branch
would be taken, but `mb_substr('', 0, -1)` still yields `''`. The equivalence
holds across the whole input domain.
