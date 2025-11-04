#!/bin/bash
# Setup Helper for OXID Payment Module
# Handles foreign key constraints during demodata installation

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"

DB_HOST="${DB_HOST:-mysql}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"
DB_NAME="${DB_NAME:-example}"

echo "=== OXID Payment Module - Setup Helper ==="
echo ""

# Function to execute SQL
execute_sql() {
    local sql="$1"
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$sql"
}

# Function to drop FK constraints
drop_fk_constraints() {
    echo "Dropping foreign key constraints to allow demodata installation..."

    execute_sql "
        ALTER TABLE osc_payment_contract DROP FOREIGN KEY IF EXISTS FK_CONTRACT_ORDER;
        ALTER TABLE osc_payment_order_state DROP FOREIGN KEY IF EXISTS FK_ORDER_STATE;
        ALTER TABLE osc_payment_order_state DROP FOREIGN KEY IF EXISTS FK_ORDER_STATE_CONTRACT;
        ALTER TABLE osc_payment_transaction DROP FOREIGN KEY IF EXISTS FK_ORDER;
        ALTER TABLE osc_payment_transaction DROP FOREIGN KEY IF EXISTS FK_CONTRACT;
    " 2>/dev/null || echo "Some constraints already dropped or don't exist yet"

    echo "✓ FK constraints dropped"
}

# Function to add FK constraints back
add_fk_constraints() {
    echo "Adding foreign key constraints back..."

    # NOTE: FK_CONTRACT_ORDER is intentionally NOT added back
    # Reason: It blocks TRUNCATE operations during testing
    # Referential integrity is maintained at application level
    # See: docs/payment-component/architecture/04-database-design.md

    execute_sql "
        ALTER TABLE osc_payment_order_state
            ADD CONSTRAINT FK_ORDER_STATE
            FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE;

        ALTER TABLE osc_payment_order_state
            ADD CONSTRAINT FK_ORDER_STATE_CONTRACT
            FOREIGN KEY (OXCONTRACTID) REFERENCES osc_payment_contract(OXID) ON DELETE SET NULL;

        ALTER TABLE osc_payment_transaction
            ADD CONSTRAINT FK_ORDER
            FOREIGN KEY (OXORDERID) REFERENCES oxorder(OXID) ON DELETE CASCADE;

        ALTER TABLE osc_payment_transaction
            ADD CONSTRAINT FK_CONTRACT
            FOREIGN KEY (OXCONTRACTID) REFERENCES osc_payment_contract(OXID) ON DELETE SET NULL;
    " 2>/dev/null || echo "Some constraints already exist"

    echo "✓ FK constraints added (FK_CONTRACT_ORDER intentionally omitted)"
}

# Main script
case "${1:-}" in
    "drop-fk")
        drop_fk_constraints
        ;;
    "add-fk")
        add_fk_constraints
        ;;
    "help"|"--help"|"-h")
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  drop-fk    Drop FK constraints (run BEFORE demodata installation)"
        echo "  add-fk     Add FK constraints back (run AFTER demodata installation)"
        echo ""
        echo "Environment variables:"
        echo "  DB_HOST    Database host (default: mysql)"
        echo "  DB_USER    Database user (default: root)"
        echo "  DB_PASS    Database password (default: root)"
        echo "  DB_NAME    Database name (default: example)"
        echo ""
        echo "Example workflow:"
        echo "  ./setup-helper.sh drop-fk"
        echo "  vendor/bin/oe-console oe:setup:demodata"
        echo "  ./setup-helper.sh add-fk"
        ;;
    *)
        echo "Error: Unknown command '${1:-}'"
        echo "Run '$0 help' for usage information"
        exit 1
        ;;
esac

echo ""
echo "=== Done ==="
