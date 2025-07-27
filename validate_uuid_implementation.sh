#!/bin/bash

echo "=== Final UUID Implementation Validation ==="
echo ""

# Check for any remaining temp- references in JavaScript
echo "1. Checking for remaining temp- references in JavaScript..."
TEMP_REFS=$(grep -r "temp-" assets/js/ --include="*.js" | grep -v test | grep -v uuid-utils | wc -l)
if [ "$TEMP_REFS" -eq 0 ]; then
    echo "   ✅ No unwanted temp- references found"
else
    echo "   ❌ Found $TEMP_REFS temp- references:"
    grep -r "temp-" assets/js/ --include="*.js" | grep -v test | grep -v uuid-utils
fi

# Check for removed function references
echo ""
echo "2. Checking for removed function usage..."
REMOVED_FUNCS=$(grep -r "generateTempId\|uuidv4" assets/js/ --include="*.js" | grep -v "module.exports" | grep -v "export.*{" | wc -l)
if [ "$REMOVED_FUNCS" -eq 0 ]; then
    echo "   ✅ No references to removed functions found"
else
    echo "   ❌ Found $REMOVED_FUNCS references to removed functions:"
    grep -r "generateTempId\|uuidv4" assets/js/ --include="*.js" | grep -v "module.exports" | grep -v "export.*{"
fi

# Check that UUID utils are being imported where needed
echo ""
echo "3. Checking UUID utils imports..."
UUID_IMPORTS=$(grep -r "uuid-utils" assets/js/ --include="*.js" | wc -l)
echo "   Found $UUID_IMPORTS UUID utils imports"

# Run the comprehensive test
echo ""
echo "4. Running comprehensive UUID test..."
php test_comprehensive_uuid.php | tail -1

echo ""
echo "5. Running UUID generation tests..."
php test_uuid.php | tail -1

echo ""
echo "6. Running API function tests..."
php test_api_functions_uuid.php | tail -1

echo ""
echo "=== Validation Complete ==="