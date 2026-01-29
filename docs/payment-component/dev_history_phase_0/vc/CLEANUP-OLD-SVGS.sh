#!/bin/bash
# Cleanup script to remove old problematic SVG files

echo "🧹 Cleaning up old SVG files with errors..."

cd /home/dtkachev/osc/strpwt7-oct21/stripe-wallet/docs/payment-component/vc/_generated

# Remove old complex diagrams that have errors
sudo rm -f m1-marketplace-structure.svg
sudo rm -f m3-payment-component-structure.svg
sudo rm -f p1-cloud-structure.svg
sudo rm -f revenue-flow-diagram.svg
sudo rm -f wealth-creation-model.svg
sudo rm -f 05-revenue-flow.svg

echo "✅ Cleaned up old SVG files"
echo ""
echo "✨ Keeping these working SVG files:"
ls -lh *.svg

echo ""
echo "These 4 diagrams are working perfectly:"
echo "  01-wealth-creation-flow.svg (19K)"
echo "  02-m3-payment-component.svg (21K)"
echo "  03-m1-marketplace-network.svg (22K)"
echo "  04-p1-cloud-paas.svg (26K)"
