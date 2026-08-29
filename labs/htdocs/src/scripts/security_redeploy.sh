#!/bin/bash
#
# Security Redeploy Script (labsctl integration)
# 
# Uses labsctl as deploy engine with queue-based processing.
#
# Usage:
#   ./security_redeploy.sh deploy              # Deploy queued labs
#   ./security_redeploy.sh deploy --user u1    # Deploy specific user
#   ./security_redeploy.sh deploy --dry-run    # Preview only
#   ./security_redeploy.sh health              # Check DB vs container
#   ./security_redeploy.sh reconcile           # Fix mismatched states
#   ./security_redeploy.sh queue               # Show queue status
#   ./security_redeploy.sh cancel              # Cancel pending jobs
#

set -e

CONTAINER="Dev_lab"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

show_help() {
    echo -e "${GREEN}Security Redeploy (labsctl)${NC}"
    echo ""
    echo "Usage: $0 <command> [options]"
    echo ""
    echo "Commands:"
    echo "  deploy              Bulk deploy with throttling"
    echo "  health              DB vs actual container state"
    echo "  reconcile           Fix mismatched states"
    echo "  queue               Show queue status"
    echo "  cancel              Cancel pending jobs"
    echo "  cleanup             Remove old queue entries"
    echo ""
}

COMMAND=""
EXTRA_ARGS=""

while [[ $# -gt 0 ]]; do
    case $1 in
        deploy|health|reconcile|queue|cancel|cleanup|help)
            COMMAND="$1"
            shift
            ;;
        *)
            EXTRA_ARGS="$EXTRA_ARGS $1"
            shift
            ;;
    esac
done

if [ -z "$COMMAND" ]; then
    show_help
    exit 0
fi

# Check container
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
    echo -e "${RED}Error: Container ${CONTAINER} not running${NC}"
    exit 1
fi

case "$COMMAND" in
    deploy)
        echo -e "${GREEN}════════════════════════════════════════${NC}"
        echo -e "${GREEN}  SECURITY REDEPLOY${NC}"
        echo -e "${GREEN}════════════════════════════════════════${NC}"
        echo ""
        docker exec "$CONTAINER" labsctl instance bulk $EXTRA_ARGS
        ;;
        
    health)
        echo -e "${GREEN}════════════════════════════════════════${NC}"
        echo -e "${GREEN}  HEALTH CHECK${NC}"
        echo -e "${GREEN}════════════════════════════════════════${NC}"
        echo ""
        docker exec "$CONTAINER" labsctl instance health
        ;;
        
    reconcile)
        echo -e "${GREEN}════════════════════════════════════════${NC}"
        echo -e "${GREEN}  RECONCILE${NC}"
        echo -e "${GREEN}════════════════════════════════════════${NC}"
        echo ""
        docker exec "$CONTAINER" labsctl instance reconcile $EXTRA_ARGS
        ;;
        
    queue)
        docker exec "$CONTAINER" labsctl instance queue
        ;;
        
    cancel)
        docker exec "$CONTAINER" labsctl instance cancel
        ;;
        
    cleanup)
        docker exec "$CONTAINER" labsctl instance cleanup $EXTRA_ARGS
        ;;
        
    help)
        show_help
        ;;
esac
