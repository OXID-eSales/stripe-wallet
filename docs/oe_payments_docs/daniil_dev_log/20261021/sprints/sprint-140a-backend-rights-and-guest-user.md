# Subsprint 140a — payment-base: anonymous rights and the guest user

**Repo:** payment-base · **Rules:** [`_engineering_requirements.md`](../../_engineering_requirements.md)

## Finding (report #4)

`PermissionProvider` grants `oxidanonymous` only `PAYMENT_CLIENT_CONFIG`; the storefront grants
basket rights to `oxidcustomer`, `oxidnotyetordered`, `oxidadmin` only; `HeadlessCheckoutService::start()`
throws `user_not_found` when the token's user does not exist.

## Stories (test first)

| #  | Story                                                                                                                                                                                                                                                                                 |
| -- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A1 | **Spike with proof (half a day, time-boxed):** grant `oxidanonymous` the storefront basket rights in a throwaway branch; with an anonymous `token` run `basketCreate`, `basketAddItem`, `basket`. Record what works and what the basket's owner id is. Decide (a) or (b) in the sprint index; delete the branch. |
| A2 | `testPermissions_Anonymous_HasBasketAndCheckoutRights`: `oxidanonymous` → `CREATE_BASKET, VIEW_BASKET, ADD_PRODUCT_TO_BASKET, REMOVE_BASKET_PRODUCT, ADD_VOUCHER, REMOVE_VOUCHER, PAYMENT_CHECKOUT, PAYMENT_CLIENT_CONFIG` (names as string constants mirrored from the storefront, as today). |
| A3 | `HeadlessStartRequest` gains an optional readonly `GuestDetails` value object (e-mail, invoice address, optional delivery address; validated, no nulls leaking). `testStart_AnonymousTokenWithGuestDetails_CreatesGuestUser`, `…_ExistingEmailWithoutPassword_ReusesGuestUser`, `…_ExistingRegisteredEmail_RefusesWithAccountExists`, `…_AnonymousWithoutGuestDetails_RefusesWithGuestDetailsRequired`. The user creation lives in a new `GuestUserService` (SRP), behind an interface (DIP), using the shop's `User` model only inside `Adapter/`. |
| A4 | Basket ownership: per the decision, either re-own the anonymous basket to the created user (a) or build the basket from the request's line items (b); `UserBasketProvider` refuses nothing it accepted before (LSP).                                                               |
| A5 | New error codes in `HeadlessCheckoutException` (`guest_details_required`, `account_exists`); `CheckoutStartResult` unchanged; docs `graphql-headless-checkout.md` "Guest checkout" section; CHANGELOG; release 1.5.0.                                                   |

## Acceptance

An anonymous token with guest details starts a contract and reaches `READY_TO_COMMIT` in the
integration test; a registered e-mail is refused with a code the UI can act on; no `Registry::` or
container lookups in the new domain classes; PHPStan max without new baseline entries.

## Out of scope

Password-less account creation ("create account after checkout"); newsletter opt-in; B2B fields.
