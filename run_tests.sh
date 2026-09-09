#!/bin/bash
# ============================================================================
# run_tests.sh — Test runner for Dev_lab
# 
# Runs all PHP and Python tests, reports results, exits non-zero on failure.
#
# Usage:
#   ./run_tests.sh              # Run all tests
#   ./run_tests.sh php          # Run only PHP tests
#   ./run_tests.sh python       # Run only Python tests
#   ./run_tests.sh <file.php>   # Run specific test file
#
# Environment:
#   TEST_BASE_URL    — URL for HTTP tests (default: http://localhost:8083)
#   TEST_HOST_HEADER — Host header for Apache vhost (default: dev.tomweb.in)
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$SCRIPT_DIR/labs/workspace/tests"
RESULTS_DIR="${CI_ARTIFACTS_DIR:-$SCRIPT_DIR/test-results}"
PASSED=0
FAILED=0
SKIPPED=0
FAILURES=()

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Create results directory
mkdir -p "$RESULTS_DIR"

header() {
    echo ""
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════${NC}"
}

run_php_test() {
    local test_file="$1"
    local test_name=$(basename "$test_file" .php)
    
    echo -e "\n${YELLOW}▶ Running: $test_name${NC}"
    
    # Run the test and capture output + exit code
    local output
    local exit_code=0
    output=$(php "$test_file" 2>&1) || exit_code=$?
    
    # Parse results from output
    local test_passed=$(echo "$output" | grep -oP 'Results: \K\d+(?= passed)' || echo "0")
    local test_failed=$(echo "$output" | grep -oP '\d+(?= failed)' | tail -1 || echo "0")
    local test_skipped=$(echo "$output" | grep -oP '\d+(?= skipped)' || echo "0")
    
    # Print output
    echo "$output" | sed 's/^/    /'
    
    # Track totals
    PASSED=$((PASSED + test_passed))
    FAILED=$((FAILED + test_failed))
    SKIPPED=$((SKIPPED + test_skipped))
    
    # Write JUnit XML for this test
    local junit_file="$RESULTS_DIR/${test_name}.xml"
    local total=$((test_passed + test_failed))
    local failures=$test_failed
    
    cat > "$junit_file" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="$test_name" tests="$total" failures="$failures" time="0">
$(echo "$output" | grep -E "^\s+(PASS|FAIL):" | sed 's/.*PASS: /    <testcase name="/' | sed 's/.*FAIL: /    <testcase name="/' | sed 's/$/">/' | sed 's/$/    <\/testcase>/' | head -100)
  </testsuite>
</testsuites>
EOF
    
    if [ $exit_code -ne 0 ]; then
        FAILURES+=("$test_name")
    fi
}

run_python_test() {
    local test_file="$1"
    local test_name=$(basename "$test_file" .py)
    
    echo -e "\n${YELLOW}▶ Running: $test_name${NC}"
    
    local output
    local exit_code=0
    output=$(python3 "$test_file" 2>&1) || exit_code=$?
    
    echo "$output" | sed 's/^/    /'
    
    if [ $exit_code -eq 0 ]; then
        PASSED=$((PASSED + 1))
    else
        FAILED=$((FAILED + 1))
        FAILURES+=("$test_name")
    fi
}

# ── Main ──

MODE="${1:-all}"

header "Dev_lab Test Suite"

# Set default env vars if not provided
export TEST_BASE_URL="${TEST_BASE_URL:-http://localhost:8083}"
export TEST_HOST_HEADER="${TEST_HOST_HEADER:-dev.tomweb.in}"

echo "Configuration:"
echo "  TEST_BASE_URL=$TEST_BASE_URL"
echo "  TEST_HOST_HEADER=$TEST_HOST_HEADER"
echo "  Mode=$MODE"

# ── PHP Tests ──
if [ "$MODE" = "all" ] || [ "$MODE" = "php" ]; then
    header "PHP Tests"
    
    for test_file in "$TESTS_DIR"/test_*.php; do
        [ -f "$test_file" ] || continue
        # Skip bootstrap.php
        [[ "$(basename "$test_file")" == "bootstrap.php" ]] && continue
        run_php_test "$test_file"
    done
fi

# ── Python Tests ──
if [ "$MODE" = "all" ] || [ "$MODE" = "python" ]; then
    header "Python Tests"
    
    PYTHON_TESTS=(
        "$SCRIPT_DIR/opt/labs-control-panel/scripts/test_mcp_setup.py"
    )
    
    for test_file in "${PYTHON_TESTS[@]}"; do
        [ -f "$test_file" ] && run_python_test "$test_file"
    done
fi

# ── Specific file ──
if [ -f "$MODE" ]; then
    if [[ "$MODE" == *.php ]]; then
        run_php_test "$MODE"
    elif [[ "$MODE" == *.py ]]; then
        run_python_test "$MODE"
    fi
fi

# ── Summary ──
header "Test Summary"

TOTAL=$((PASSED + FAILED + SKIPPED))
echo -e "  ${GREEN}Passed:${NC}  $PASSED"
echo -e "  ${RED}Failed:${NC}  $FAILED"
echo -e "  ${YELLOW}Skipped:${NC} $SKIPPED"
echo "  Total:   $TOTAL"

if [ ${#FAILURES[@]} -gt 0 ]; then
    echo -e "\n${RED}Failed tests:${NC}"
    for f in "${FAILURES[@]}"; do
        echo -e "  ${RED}✗ $f${NC}"
    done
fi

echo ""

if [ $FAILED -gt 0 ]; then
    echo -e "${RED}❌ CI FAILED${NC}"
    exit 1
else
    echo -e "${GREEN}✅ CI PASSED${NC}"
    exit 0
fi
