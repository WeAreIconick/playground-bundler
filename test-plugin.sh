#!/bin/bash

# WordPress Plugin Testing Script
# Run this before deploying any plugin changes

echo "🔍 WordPress Plugin Testing Script"
echo "=================================="

# Check if we're in the right directory
if [ ! -f "playground-bundler.php" ]; then
    echo "❌ Error: Not in plugin directory. Run this from the plugin root."
    exit 1
fi

echo "📦 Installing dependencies..."
npm install

echo "🔧 Running JavaScript linting..."
npm run lint:js

echo "🏗️ Building JavaScript..."
npm run build

echo "🐘 Checking PHP syntax..."
php -l playground-bundler.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax error in main plugin file!"
    exit 1
fi

php -l includes/class-asset-detector.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax error in asset detector!"
    exit 1
fi

php -l includes/class-blueprint-generator.php
if [ $? -ne 0 ]; then
    echo "❌ PHP syntax error in blueprint generator!"
    exit 1
fi

echo "✅ All syntax checks passed!"
echo ""
echo "📋 Manual Testing Checklist:"
echo "1. Install plugin on fresh WordPress"
echo "2. Test all functionality"
echo "3. Deactivate plugin (should work cleanly)"
echo "4. Delete plugin (should not cause critical errors)"
echo "5. Check WordPress error logs"
echo ""
echo "🎉 Plugin is ready for deployment!"
