# Merchant Documentation

Documentation for merchants, shop administrators, and integration developers.

## Contents

### For Merchants & Shop Administrators

| Document | Description |
|----------|-------------|
| [01-installation-configuration.md](./01-installation-configuration.md) | Complete guide for installing, configuring, and using the Stripe payment module |

**Topics covered:**
- System requirements
- Installation via Composer
- Module activation
- Admin panel configuration
- Stripe Dashboard setup
- Payment methods
- Order management
- Refunds
- Troubleshooting
- Going live checklist

### For Integration Developers

| Document | Description |
|----------|-------------|
| [02-integration-guide.md](./02-integration-guide.md) | How to integrate shipping, CRM, ERP, and other modules with the payment-component |

**Topics covered:**
- Event-driven architecture overview
- Available payment and contract events
- Code examples for:
  - ERP order export
  - CRM customer sync
  - Shipping fulfillment
  - Inventory management
  - Notification services
- Handler registration
- Best practices
- Database schema reference

## Quick Links

- **Install the module**: See [Installation](./01-installation-configuration.md#installation)
- **Configure API keys**: See [Configuration](./01-installation-configuration.md#configuration)
- **Setup webhooks**: See [Stripe Dashboard Setup](./01-installation-configuration.md#stripe-dashboard-setup)
- **Subscribe to payment events**: See [Integration Examples](./02-integration-guide.md#integration-examples)

## Related Documentation

- [Architecture Overview](../README.md) - Technical architecture documentation
- [Payment Component](https://github.com/OXID-eSales/payment-component) - Core event-driven component

## Support

- **OXID Support**: support@oxid-esales.com
- **GitHub Issues**: https://github.com/OXID-eSales/stripe-wallet/issues
- **Stripe Support**: https://support.stripe.com
