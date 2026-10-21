# Engineering requirements (default for every PayPal sprint)

Every sprint and subsprint file links here instead of repeating these rules. A story is not done
until each row below is honoured and the DevOps gate is green.

| Principle | What it means in this module |
|-----------|------------------------------|
| **TDD-first** | Write the failing test before the production code. Unit tests live in `tests/Unit` (standalone, `tests/phpunit-unit.xml`, no shop, no DB); shop-bound behaviour gets an Integration test; browser behaviour gets a Playwright spec in `tests/e2e`. Red → green → refactor, in that order, per story. |
| **SOLID / SRP** | One class, one reason to change. New behaviour goes into its own service or value object, never inlined into a controller, widget or handler. |
| **SOLID / OCP** | Add the next payment method / funding source by adding a definition or a mapping row, not by editing `if` chains. |
| **SOLID / LSP** | Implementations honour the interface contract: same return types, no surprise exceptions, safe defaults (an unknown method is "not eligible", never a crash). |
| **SOLID / ISP** | Narrow interfaces (`…AdapterInterface` per concern). Consumers depend on the slice they use. |
| **SOLID / DIP** | Constructor injection through `services.yaml`. No `Registry::…` / `ContainerFacade::get()` inside domain code; OXID access is confined to adapters and widget/controller seams. The PayPal SDK is confined to `Adapter/`. |
| **DRY** | One source of truth per fact (payment definitions, SDK config, funding-source mapping, Twig partials shared by OPC and standard checkout). Reuse payment-base before writing a second version. |
| **Clean Code** | Early returns, no `else`, methods ≤ 25 lines, intention-revealing names, readonly value objects, integer cents at boundaries, comments say *why* not *what*. Test names: `testMethod_Condition_ExpectedOutcome`. |
| **No overengineering** | Build what the current story needs. No abstractions for a third consumer that does not exist, no feature flags without a second value in use, no new JS test framework — JS stays thin and is proven by Playwright. Write the "not now" list in each sprint's *Out of scope*. |
| **Pre-commit guard** | `./bin/pre-commit-check.sh` (PHPCS PSR-12, PHPStan at the level configured in `tests/PhpStan/phpstan.neon` with no new baseline entries, PHPMD, PHPUnit Unit) must be green before every commit; `./bin/pre-commit-check.sh --full` (Unit + Integration, needs MySQL) before a subsprint is declared done. A red gate is a blocker, never "fix later". |
| **Proof** | Every subsprint ends with evidence: test counts, gate output, and for checkout behaviour a sandbox run (order id, screenshots or Playwright trace) linked from a report in `reports/`. |
| **Git flow** | Feature branch per sprint, never merge a mainline into it (rebase only, by the user), mainlines get no-ff merges, no PRs, no worktrees. |
