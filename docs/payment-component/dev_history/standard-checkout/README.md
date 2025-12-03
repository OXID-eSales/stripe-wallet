# Standard Multi-Step Checkout Implementation

**Complete Guide for Traditional OXID Checkout Flow with Stripe**
**Version:** 1.0.0
**Date:** 2025-11-13
**Target Platform:** OXID eShop 7.0+
**Payment Provider:** Stripe (adaptable to other providers)

---

## Overview

This documentation covers implementing a **traditional multi-step checkout** flow in OXID eShop with Stripe payment integration. Unlike single-page checkout, this approach uses OXID's standard checkout process with multiple page loads.

### What is Standard Checkout?

Standard checkout is the traditional e-commerce flow where customers progress through multiple pages:

```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   Basket    │ -> │   Login/    │ -> │   Payment   │ -> │   Review    │ -> │   Thank You │
│   Page      │    │   Address   │    │   Method    │    │   & Submit  │    │   Page      │
└─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘    └─────────────┘
  /basket            /user              /payment           /order             /thankyou

  Page Reload        Page Reload        Page Reload        Page Reload        Page Reload
```

### Key Characteristics

- ✅ **Multiple Page Loads** - Each step is a separate HTTP request
- ✅ **Session-Based State** - Basket and user data stored in session
- ✅ **Traditional Forms** - Standard HTML form submissions
- ✅ **SEO-Friendly** - Each page has unique URL
- ✅ **Theme Compatible** - Works with existing OXID themes
- ✅ **Progressive Enhancement** - JavaScript optional
- ✅ **Familiar UX** - Users understand the flow

### Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     OXID Standard Checkout Flow                 │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Stripe Module Integration                     │
│                                                                   │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐            │
│  │  Payment    │  │   Order     │  │  Webhook    │            │
│  │  Controller │  │  Controller │  │  Controller │            │
│  └─────────────┘  └─────────────┘  └─────────────┘            │
│         │                 │                 │                    │
│         ▼                 ▼                 ▼                    │
│  ┌──────────────────────────────────────────────────┐          │
│  │           Stripe Payment Service                  │          │
│  │  - Payment Intent Creation                        │          │
│  │  - Payment Confirmation                           │          │
│  │  - 3D Secure Handling                            │          │
│  │  - Transaction Storage                            │          │
│  └──────────────────────────────────────────────────┘          │
│                              │                                   │
│                              ▼                                   │
│  ┌──────────────────────────────────────────────────┐          │
│  │           Component Database Tables               │          │
│  │  - osc_payment_transaction                        │          │
│  │  - osc_payment_order_state                       │          │
│  │  - osc_payment_customer                          │          │
│  └──────────────────────────────────────────────────┘          │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                         Stripe API                               │
│  - PaymentIntent API                                             │
│  - Webhooks (payment_intent.succeeded, etc.)                    │
└─────────────────────────────────────────────────────────────────┘
```

---

## Documentation Structure

### Core Documentation (Read in Order)

1. **[README.md](README.md)** (this file) - Overview and architecture
2. **[IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)** - Complete step-by-step implementation
3. **[CONTROLLER_INTEGRATION.md](CONTROLLER_INTEGRATION.md)** - Controller extension details
4. **[SERVICE_LAYER.md](SERVICE_LAYER.md)** - Payment service implementation
5. **[TEMPLATE_GUIDE.md](TEMPLATE_GUIDE.md)** - Frontend template integration
6. **[DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)** - Database tables and structure
7. **[CONFIGURATION.md](CONFIGURATION.md)** - Module configuration guide
8. **[WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md)** - Webhook processing
9. **[ERROR_HANDLING.md](ERROR_HANDLING.md)** - Error handling strategies
10. **[SECURITY_GUIDE.md](SECURITY_GUIDE.md)** - Security best practices
11. **[TESTING_GUIDE.md](TESTING_GUIDE.md)** - Testing strategies
12. **[MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)** - Migration from other systems

### Quick Start Guides

- **[QUICK_START.md](QUICK_START.md)** - Get up and running in 30 minutes
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** - Common issues and solutions
- **[FAQ.md](FAQ.md)** - Frequently asked questions

---

## Target Audience

### For Project Managers
- **Read**: README.md, IMPLEMENTATION_GUIDE.md
- **Estimate**: 40-60 hours for complete implementation
- **Benefits**: Standard, maintainable checkout flow

### For Backend Developers
- **Read**: CONTROLLER_INTEGRATION.md, SERVICE_LAYER.md, DATABASE_SCHEMA.md
- **Implement**: Controller extensions, payment service, database integration
- **Focus**: Payment processing, order creation, transaction tracking

### For Frontend Developers
- **Read**: TEMPLATE_GUIDE.md, ERROR_HANDLING.md
- **Implement**: Payment forms, Stripe.js integration, error display
- **Focus**: User experience, form validation, 3D Secure handling

### For DevOps Engineers
- **Read**: CONFIGURATION.md, WEBHOOK_HANDLING.md, SECURITY_GUIDE.md
- **Setup**: API keys, webhook endpoints, SSL certificates
- **Monitor**: Payment processing, webhook delivery, error logs

### For QA Engineers
- **Read**: TESTING_GUIDE.md, TROUBLESHOOTING.md
- **Test**: Complete checkout flow, error scenarios, edge cases
- **Focus**: Payment processing, 3D Secure, webhook handling

---

## Prerequisites

### System Requirements

- **OXID eShop**: 7.0+ (compatible with 7.1, 7.2, 7.3, 7.4+)
- **PHP**: 8.0+ (8.1+ recommended)
- **MySQL**: 5.7+ or 8.0+
- **Composer**: 2.0+
- **SSL Certificate**: Required for production (Stripe requires HTTPS)

### Stripe Account

- **Stripe Account**: Create at https://stripe.com
- **API Keys**: Test and live keys
- **Webhook Secret**: For webhook signature verification
- **3D Secure**: Enabled for SCA compliance (EU)

### Development Environment

- **Local OXID Installation**: Working OXID shop
- **Module Directory**: `source/modules/` or `source/extensions/stripe/`
- **Database Access**: For table creation
- **Web Server**: Apache/Nginx with HTTPS support

### Knowledge Requirements

- **PHP**: OOP, namespaces, exceptions
- **OXID Development**: Controllers, models, templates
- **Stripe API**: Basic understanding of PaymentIntents
- **JavaScript**: ES6+, async/await, Fetch API
- **SQL**: Database queries and table design

---

## Implementation Approach

### Phase 1: Foundation (8-12 hours)
- Install module structure
- Create database tables
- Configure Stripe API keys
- Set up basic payment method

### Phase 2: Controller Integration (12-16 hours)
- Extend PaymentController
- Extend OrderController
- Implement payment processing
- Handle order creation

### Phase 3: Frontend Integration (8-12 hours)
- Create payment templates
- Integrate Stripe.js
- Implement card element
- Add form validation

### Phase 4: Advanced Features (8-12 hours)
- Implement 3D Secure
- Add webhook handling
- Implement error handling
- Add transaction logging

### Phase 5: Testing & Deployment (8-12 hours)
- Unit testing
- Integration testing
- User acceptance testing
- Production deployment

**Total Estimated Time**: 44-64 hours

---

## Key Features

### Payment Processing

- ✅ **Credit Card Payments** - Visa, Mastercard, Amex, Discover
- ✅ **Debit Cards** - All major debit card networks
- ✅ **3D Secure (SCA)** - Strong Customer Authentication for EU
- ✅ **Dynamic Payment Methods** - Stripe Payment Element
- ✅ **Multi-Currency** - Support for 135+ currencies
- ✅ **Test Mode** - Separate test and live keys

### Order Management

- ✅ **Order Creation** - Create OXID order after payment success
- ✅ **Transaction Tracking** - Store payment details in database
- ✅ **Order Status Sync** - Update order status from webhooks
- ✅ **Payment State Management** - Track payment lifecycle
- ✅ **Idempotency** - Prevent duplicate charges
- ✅ **Order History** - View payment history in admin

### Security

- ✅ **PCI Compliance** - Stripe handles card data (PCI-DSS Level 1)
- ✅ **Tokenization** - Card data never touches your server
- ✅ **Webhook Verification** - Signature-based authentication
- ✅ **HTTPS Required** - SSL/TLS encryption
- ✅ **CSRF Protection** - Token-based form protection
- ✅ **Input Validation** - Sanitize all user input

### Developer Experience

- ✅ **Event-Driven** - Extensible via events
- ✅ **Service Layer** - Clean business logic separation
- ✅ **Repository Pattern** - Data access abstraction
- ✅ **Dependency Injection** - Easy testing and mocking
- ✅ **PSR Standards** - PSR-3 logging, PSR-4 autoloading
- ✅ **Comprehensive Docs** - Complete implementation guide

---

## Standard Checkout Flow

### Step 1: Basket Page (`/basket`)

**Customer Actions:**
- Views basket contents
- Updates quantities
- Clicks "Proceed to Checkout"

**System Actions:**
- Display basket items
- Calculate totals (subtotal, tax, shipping)
- Validate basket (min order amount, stock availability)
- Redirect to login/registration if not logged in

---

### Step 2: User/Address Page (`/user`)

**Customer Actions:**
- Logs in or registers
- Enters billing address
- Enters shipping address (if different)
- Clicks "Continue"

**System Actions:**
- Authenticate user
- Validate addresses
- Store addresses in session
- Calculate shipping costs
- Redirect to payment page

---

### Step 3: Payment Method Page (`/payment`)

**Customer Actions:**
- Selects payment method (Stripe)
- Enters card details in Stripe Element
- Clicks "Continue to Review"

**System Actions:**
- Display available payment methods
- Load Stripe.js SDK
- Create Stripe Card Element
- Validate card client-side
- Store selected payment method in session
- Redirect to order review page

**Technical Details:**
```javascript
// Stripe.js creates payment method
const {paymentMethod, error} = await stripe.createPaymentMethod({
    type: 'card',
    card: cardElement,
    billing_details: {
        name: customerName,
        email: customerEmail,
        address: billingAddress
    }
});
```

---

### Step 4: Order Review Page (`/order`)

**Customer Actions:**
- Reviews order details
- Accepts terms and conditions
- Clicks "Place Order"

**System Actions:**
- Display order summary
- Show selected payment method
- Create Stripe PaymentIntent
- Confirm payment with Stripe
- Handle 3D Secure if required
- Create OXID order
- Store transaction data
- Redirect to thank you page

**Technical Details:**
```php
// Server-side: Create PaymentIntent
$paymentIntent = $stripe->paymentIntents->create([
    'amount' => $amount * 100,
    'currency' => $currency,
    'payment_method' => $paymentMethodId,
    'confirmation_method' => 'manual',
    'confirm' => true,
]);

// Handle response
if ($paymentIntent->status === 'succeeded') {
    // Create order
    $order = $this->createOrder();
    // Store transaction
    $this->storeTransaction($order, $paymentIntent);
}
```

---

### Step 5: Thank You Page (`/thankyou`)

**Customer Actions:**
- Views order confirmation
- Receives order number
- Downloads invoice (optional)

**System Actions:**
- Display order confirmation
- Show payment status
- Send confirmation email
- Clear basket/session
- Trigger order fulfillment

---

## Webhook Processing (Background)

After order creation, Stripe sends webhooks for payment events:

```
Order Created (status: pending)
       ↓
Webhook: payment_intent.succeeded
       ↓
Update Order Status (status: paid)
       ↓
Trigger Fulfillment
       ↓
Send Confirmation Email
```

**Important Webhooks:**
- `payment_intent.succeeded` - Payment captured successfully
- `payment_intent.payment_failed` - Payment failed
- `payment_intent.requires_action` - 3D Secure required
- `charge.refunded` - Refund processed
- `charge.dispute.created` - Chargeback initiated

---

## Technology Stack

### Backend
- **PHP 8.0+** - Core language
- **OXID eShop 7.0+** - E-commerce platform
- **Stripe PHP SDK 10+** - Payment processing
- **MySQL 5.7+/8.0+** - Database
- **Composer** - Dependency management

### Frontend
- **Stripe.js v3** - Secure card handling
- **Smarty Templates** - OXID templating engine
- **JavaScript ES6+** - Client-side logic
- **CSS3** - Styling

### Testing
- **PHPUnit** - Unit testing
- **Codeception** - Integration testing
- **Stripe Test Cards** - Payment testing

---

## Security Considerations

### PCI Compliance

**Level 1 PCI-DSS** - Stripe handles all card data:
- Card details collected by Stripe.js
- Payment data tokenized before transmission
- No card data stored on your server
- No card data in server logs
- SAQ A compliance (easiest level)

### Data Protection

- **HTTPS Required** - All pages must use SSL/TLS
- **Webhook Signatures** - Verify all webhook requests
- **CSRF Tokens** - Protect form submissions
- **Input Validation** - Sanitize all user input
- **SQL Injection Prevention** - Use prepared statements
- **XSS Prevention** - Escape all output

### GDPR Compliance

- **Data Minimization** - Only store necessary data
- **Right to Erasure** - Delete customer data on request
- **Data Portability** - Export customer data
- **Consent Management** - Track consent for data processing
- **Data Retention** - Delete old transaction data

---

## Performance Optimization

### Page Load Speed

- **Lazy Load Stripe.js** - Only on payment page
- **Async Script Loading** - Non-blocking JavaScript
- **CDN for Assets** - Stripe CDN for libraries
- **Minimal Dependencies** - Keep bundle size small
- **Browser Caching** - Cache static assets

### Database Optimization

- **Indexed Queries** - Index on OXORDERID, OXPROVIDERORDERID
- **Foreign Keys** - Maintain referential integrity
- **Query Optimization** - Use JOINs efficiently
- **Connection Pooling** - Reuse database connections
- **Transaction Batching** - Batch webhook updates

### API Optimization

- **Idempotency Keys** - Prevent duplicate charges
- **Request Deduplication** - Handle retry logic
- **Webhook Retry** - Exponential backoff
- **Connection Reuse** - HTTP keep-alive
- **Timeout Handling** - Set reasonable timeouts

---

## Cost Estimation

### Development Costs

| Phase | Hours | Rate | Cost |
|-------|-------|------|------|
| Foundation | 10 | $100/hr | $1,000 |
| Controllers | 14 | $100/hr | $1,400 |
| Frontend | 10 | $100/hr | $1,000 |
| Advanced | 10 | $100/hr | $1,000 |
| Testing | 10 | $100/hr | $1,000 |
| **Total** | **54** | **$100/hr** | **$5,400** |

### Stripe Processing Fees

- **Online Card Payments**: 2.9% + $0.30 per successful charge (US)
- **European Cards**: 1.4% + €0.25 (within EU)
- **International Cards**: +1.5% additional fee
- **Currency Conversion**: +1% for non-local currency
- **No Setup Fee**: Free to start
- **No Monthly Fee**: Pay only for successful transactions

### Maintenance Costs

- **Stripe SDK Updates**: 2-4 hours/year
- **Security Updates**: 4-8 hours/year
- **Bug Fixes**: Variable
- **Feature Additions**: As needed

---

## Getting Started

### Quick Start (30 Minutes)

1. **Install Module**
   ```bash
   composer require stripe/stripe-php
   ```

2. **Configure Stripe**
   - Add API keys to config
   - Create payment method

3. **Test Payment**
   - Use test card: 4242 4242 4242 4242
   - Complete checkout flow

### Full Implementation

**Follow this order:**

1. Read [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)
2. Set up database tables [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)
3. Configure module [CONFIGURATION.md](CONFIGURATION.md)
4. Implement controllers [CONTROLLER_INTEGRATION.md](CONTROLLER_INTEGRATION.md)
5. Create service layer [SERVICE_LAYER.md](SERVICE_LAYER.md)
6. Build templates [TEMPLATE_GUIDE.md](TEMPLATE_GUIDE.md)
7. Set up webhooks [WEBHOOK_HANDLING.md](WEBHOOK_HANDLING.md)
8. Test thoroughly [TESTING_GUIDE.md](TESTING_GUIDE.md)

---

## Support & Resources

### Documentation
- **Stripe Docs**: https://stripe.com/docs
- **OXID Docs**: https://docs.oxid-esales.com
- **This Guide**: Complete implementation reference

### Community
- **OXID Forum**: https://forum.oxid-esales.com
- **Stripe Support**: https://support.stripe.com
- **GitHub Issues**: For bug reports

### Testing Resources
- **Test Cards**: https://stripe.com/docs/testing
- **Webhook Testing**: Use Stripe CLI for local testing
- **3D Secure Testing**: Test cards with 3DS enabled

---

## Success Criteria

### Technical Success
- ✅ All payments processed through Stripe
- ✅ Orders created in OXID after payment success
- ✅ Transaction data stored correctly
- ✅ Webhooks processed reliably
- ✅ 3D Secure handled properly
- ✅ Error handling comprehensive

### Business Success
- ✅ Payment success rate >95%
- ✅ Checkout abandonment <70%
- ✅ Page load time <3 seconds
- ✅ Zero duplicate charges
- ✅ PCI compliance maintained
- ✅ Customer satisfaction high

---

## Next Steps

1. **Read Implementation Guide**: [IMPLEMENTATION_GUIDE.md](IMPLEMENTATION_GUIDE.md)
2. **Set Up Environment**: Install OXID, create Stripe account
3. **Follow Steps**: Complete each implementation phase
4. **Test Thoroughly**: Use test cards, test webhooks
5. **Deploy to Production**: Move to live mode
6. **Monitor Performance**: Track success rates, errors

---

**Status**: ✅ Ready for Implementation
**Last Updated**: 2025-11-13
**Version**: 1.0.0
