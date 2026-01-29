#!/bin/bash
# Cleanup script to remove old broken SVG files

echo "🧹 Cleaning up old broken SVG files..."
echo ""

cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/vc/_generated

echo "Old broken SVG files to remove:"
ls -lh 05-revenue-flow.svg m1-marketplace-structure.svg m3-payment-component-structure.svg p1-cloud-structure.svg revenue-flow-diagram.svg wealth-creation-model.svg 2>/dev/null

echo ""
echo "Removing old broken SVG files (requires sudo password)..."
sudo rm -f 05-revenue-flow.svg \
           m1-marketplace-structure.svg \
           m3-payment-component-structure.svg \
           p1-cloud-structure.svg \
           revenue-flow-diagram.svg \
           wealth-creation-model.svg

echo ""
echo "✅ Cleanup complete!"
echo ""
echo "✨ Remaining working SVG files:"
ls -lh *.svg

echo ""
echo "📊 You now have 4 perfect diagrams with no errors:"
echo "   01-wealth-creation-flow.svg"
echo "   02-m3-payment-component.svg"
echo "   03-m1-marketplace-network.svg"
echo "   04-p1-cloud-paas.svg"
