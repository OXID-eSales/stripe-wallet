# Blockchain Inventory Management Diagrams

## Overview

This directory contains PlantUML diagrams for the blockchain-inspired inventory management architecture.

## PlantUML Files

1. **12-blockchain-inventory-architecture.puml**
   - Overall system architecture
   - Components: Payment, Inventory, Event Sourcing, Consensus, Caching, Warehouses
   - Shows integration between Payment Component v3.0 and Inventory Blockchain

2. **12-smart-contract-inventory-lifecycle.puml**
   - Complete smart contract lifecycle with inventory integration
   - Phases: DRAFT → PENDING → COMMITTED → FULFILLED
   - Includes rollback scenarios (payment failure, stock unavailable, auto-expiry)

3. **12-consensus-protocol-raft.puml**
   - Raft consensus protocol for stock allocation
   - Leader election process
   - SKU-based sharding (100 Raft clusters)
   - Prevents overselling in multi-warehouse scenarios

4. **12-distributed-ledger-structure.puml**
   - Ledger event structure with hash chains
   - Multi-level caching strategy (L1-L4)
   - CQRS pattern (separate read/write paths)
   - Audit trail queries and integrity verification

5. **12-multi-warehouse-coordination.puml**
   - Warehouse selection algorithm
   - Split shipment vs. consolidated shipment decision
   - Stock transfer between warehouses
   - Real-time synchronization and load balancing

## Generating SVG Diagrams

### Option 1: Using PlantUML Command Line

If you have PlantUML installed:

```bash
cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/diagrams
plantuml -tsvg 12-*.puml
```

This will generate `.svg` files for each `.puml` file.

### Option 2: Using Java JAR

Download PlantUML JAR from https://plantuml.com/download and run:

```bash
cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/diagrams
java -jar plantuml.jar -tsvg 12-*.puml
```

### Option 3: Online PlantUML Editor

Visit https://www.plantuml.com/plantuml/uml/ and paste the content of any `.puml` file to generate the diagram online.

### Option 4: VS Code Extension

Install the "PlantUML" extension in VS Code:
1. Open VS Code
2. Install extension: `jebbs.plantuml`
3. Open any `.puml` file
4. Press `Alt+D` to preview
5. Right-click → "Export Current Diagram" → Choose SVG

### Option 5: Docker

```bash
cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/diagrams
docker run --rm -v $(pwd):/data plantuml/plantuml -tsvg 12-*.puml
```

## Integration with Documentation

Once SVG files are generated, they can be referenced in the main documentation:

```markdown
![Blockchain Inventory Architecture](diagrams/12-blockchain-inventory-architecture.svg)
![Smart Contract Lifecycle](diagrams/12-smart-contract-inventory-lifecycle.svg)
![Raft Consensus Protocol](diagrams/12-consensus-protocol-raft.svg)
![Distributed Ledger Structure](diagrams/12-distributed-ledger-structure.svg)
![Multi-Warehouse Coordination](diagrams/12-multi-warehouse-coordination.svg)
```

## Diagram Formats

PlantUML supports multiple output formats:
- **SVG** (recommended): Scalable vector graphics, best for web
- **PNG**: Raster graphics, good for presentations
- **PDF**: Best for printing
- **TXT**: ASCII art (console output)

To generate PNG instead of SVG:
```bash
plantuml -tpng 12-*.puml
```

## Updating Diagrams

To update any diagram:
1. Edit the `.puml` file
2. Regenerate the SVG using one of the methods above
3. The updated SVG will automatically reflect in the documentation

## Notes

- PlantUML files are plain text and version-control friendly
- SVG files can be viewed in any modern browser
- Diagrams automatically scale to fit container width
- Dark mode compatible (diagrams have white background)
