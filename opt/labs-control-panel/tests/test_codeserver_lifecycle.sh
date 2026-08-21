#!/usr/bin/env bash
#
# test_codeserver_lifecycle.sh — stress-test the on-demand code-server lifecycle.
#
# Verifies (against a REAL running lab container on the live stack):
#   1. code-server starts OFF after a clean stop (on-demand, not auto-started)
#   2. `labsctl lab ensure-codeserver` (the UI "Launch Code IDE" path) starts it
#   3. a persistent connection to the code-server port keeps it alive PAST the
#      idle window (proves the monitor's idle timer is reset by real usage)
#   4. with no connection for the idle window, the idle monitor stops it
#
# Usage (from the host; needs docker + the lab container running):
#   ./test_codeserver_lifecycle.sh <USER> <HASH> [IDLE_SECS]
#
# Example:
#   ./test_codeserver_lifecycle.sh sathish47 3fb0fe5d53738d9ccf5170b0f89f0458 30
#
set -u

USER="${1:?usage: test_codeserver_lifecycle.sh <USER> <HASH> [IDLE_SECS]}"
HASH="${2:?usage: test_codeserver_lifecycle.sh <USER> <HASH> [IDLE_SECS]}"
IDLE="${3:-30}"
PORT="${CODE_SERVER_PORT:-8080}"
ORCH="${ORCH_CTRL:-Dev_lab}"

# The monitor samples every 30s. Without a connection it would kill the server
# within ~IDLE+30s, so we assert "alive" past that point (IDLE+45) to be meaningful.
GAP=$((IDLE + 45))

LABCTL="docker exec -e CODE_SERVER_IDLE_SECS=$IDLE $ORCH python3 /opt/labs-control-panel/labsctl.py"

pass=0; fail=0
check() { # $1 = label, $2 = eval-able condition
  if eval "$2"; then echo "  PASS: $1"; pass=$((pass+1)); else echo "  FAIL: $1"; fail=$((fail+1)); fi
}

alive() { docker exec "$HASH" bash -lc "pgrep -u '$USER' -f code-server >/dev/null 2>&1"; }

# Wait until code-server is actually listening (avoids connecting before bind).
wait_listening() {
  local i
  for i in $(seq 1 20); do
    docker exec "$HASH" bash -lc "ss -tln 2>/dev/null | grep -q ':${PORT} '" && return 0
    sleep 1
  done
  return 1
}

echo "== 1) Clean stop (code-server OFF by default — on-demand) =="
docker exec "$HASH" bash -lc "pkill -u '$USER' -f code-server 2>/dev/null; pkill -f monitor_codeserver 2>/dev/null; rm -f /tmp/cs_holder.pid; true"
sleep 2
check "code-server is OFF after clean stop" "! alive"

echo "== 2) On-demand start (UI path: labsctl lab ensure-codeserver) =="
$LABCTL lab ensure-codeserver --user="$USER" --hash="$HASH" >/dev/null 2>&1
wait_listening || true
check "code-server launched by ensure-codeserver" "alive"

echo "== 3) Active connection keeps it alive past idle ($IDLE s) =="
# A real browser session holds an ESTABLISHED connection to the code-server port.
# The monitor samples every 30s and resets its idle timer whenever it sees one.
docker exec -d "$HASH" bash -c "exec 3<>/dev/tcp/127.0.0.1/$PORT; echo \$\$ >/tmp/cs_holder.pid; sleep 300"
sleep $GAP
check "code-server STILL alive at T+${GAP}s (past no-connection kill point, so a connection held it up)" "alive"

echo "== 4) Close connection -> idle monitor stops code-server =="
docker exec "$HASH" bash -lc "kill \$(cat /tmp/cs_holder.pid) 2>/dev/null; rm -f /tmp/cs_holder.pid; true"
sleep $GAP
check "idle monitor stopped code-server after no connection" "! alive"

echo
echo "PASS=$pass FAIL=$fail"
[ "$fail" -eq 0 ]
