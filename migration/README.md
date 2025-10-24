# Stripe Payment Module - Doctrine Migrations (OXID 7.3)

This directory contains Doctrine migrations for the Stripe payment module database schema, following OXID 7.3 conventions.

## Migration Files

All migrations follow the telecash-install pattern for OXID 7.3 compatibility.

### Version20251024140000.php
**Creates: `osc_stripe_payment_transaction`**

Table for storing Stripe payment transactions.

**Columns:**
- `OXID` - Primary key (char(32) latin1_general_ci)
- `TRANSACTION_ID` - Stripe transaction ID (varchar(255), unique index)
- `OXORDERID` - Order reference (char(32), indexed)
- `AMOUNT` - Transaction amount (DOUBLE, default 0)
- `CURRENCY` - Currency code (char(3), ISO 4217)
- `STATUS` - Transaction status (varchar(50))
- `OXCREATED` - Creation timestamp
- `OXTIMESTAMP` - Update timestamp (auto-updated)

**Indexes:**
- Primary key on `OXID`
- Unique index on `TRANSACTION_ID`
- Index on `OXORDERID`

### Version20251024140100.php
**Creates: `osc_stripe_payment_order_state`**

Table for tracking payment order states.

**Columns:**
- `OXID` - Primary key (char(32))
- `OXORDERID` - Order reference (char(32), indexed)
- `STATE` - Payment state (varchar(50))
- `METADATA` - Additional metadata as JSON (text)
- `OXCREATED` - Creation timestamp
- `OXTIMESTAMP` - Update timestamp (auto-updated)

**Indexes:**
- Primary key on `OXID`
- Index on `OXORDERID`

### Version20251024140200.php
**Creates: `osc_stripe_payment_customer`**

Table for storing Stripe customer information.

**Columns:**
- `OXID` - Primary key (char(32))
- `CUSTOMER_ID` - Internal customer ID (varchar(255), unique index)
- `OXUSERID` - OXID user reference (char(32), indexed)
- `STRIPE_CUSTOMER_ID` - Stripe customer ID (varchar(255), indexed)
- `DEFAULT_PAYMENT_METHOD` - Default payment method ID (varchar(255))
- `OXCREATED` - Creation timestamp
- `OXTIMESTAMP` - Update timestamp (auto-updated)

**Indexes:**
- Primary key on `OXID`
- Unique index on `CUSTOMER_ID`
- Index on `OXUSERID`
- Index on `STRIPE_CUSTOMER_ID`

### Version20251024140300.php
**Creates: `osc_stripe_payment_basket_snapshot`**

Table for storing basket snapshots during payment process.

**Columns:**
- `OXID` - Primary key (char(32))
- `BASKET_ID` - Basket identifier (varchar(255), unique index)
- `OXUSERID` - User reference (char(32), indexed)
- `BASKET_DATA` - Serialized basket data (text)
- `OXCREATED` - Creation timestamp

**Indexes:**
- Primary key on `OXID`
- Unique index on `BASKET_ID`
- Index on `OXUSERID`

## Migration Pattern

All migrations follow these OXID 7.3 conventions:

1. **Namespace**: `OxidSolutionCatalysts\Payments\Migrations`
2. **Idempotent**: Safe to run multiple times (checks for existing tables/columns)
3. **Enum mapping**: Registers doctrine type mapping for enum fields
4. **Column definitions**: Uses explicit `columnDefinition` for MySQL-specific types
5. **Collation**: All string fields use `latin1_general_ci` collation
6. **Timestamps**:
   - `OXCREATED`: `timestamp default current_timestamp`
   - `OXTIMESTAMP`: `timestamp default current_timestamp on update current_timestamp`
7. **Comments**: All columns include descriptive comments

## Running Migrations

Migrations are executed automatically during module activation:

```bash
# Activate module (runs migrations)
vendor/bin/oe-console oe:module:activate osc/stripe

# Or manually install configuration
vendor/bin/oe-console oe:module:install-configuration osc/stripe
```

## Development Notes

- Based on telecash-install migration pattern for OXID 7.3
- Each migration includes `SchemaException` in docblock
- Uses `Types::FLOAT` with `columnDefinition` for DOUBLE fields
- All migrations are final classes extending `AbstractMigration`
- Empty `down()` methods (rollback handled externally if needed)
