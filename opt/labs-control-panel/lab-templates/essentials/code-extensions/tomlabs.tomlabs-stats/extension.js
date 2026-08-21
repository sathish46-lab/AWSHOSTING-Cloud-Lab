// TomCloud Lab System Stats — shows CPU, memory and network I/O in the
// status bar at the bottom of the code-server editor. Polls /proc every 3s.
'use strict';
const vscode = require('vscode');
const { execFile } = require('child_process');

let cpuItem, memItem, netItem, timer;
let prevCpu = null; // {total, idle}
let prevNet = null; // {rx, tx}
let prevNetT = 0;

function activate(context) {
  // StatusBarAlignment.Right = bottom-right of the editor status bar.
  cpuItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
  memItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 99);
  netItem = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 98);

  cpuItem.text = '$(chip) CPU --';
  memItem.text = '$(pulse) Mem --';
  netItem.text = '$(arrow-down) - $(arrow-up) -';
  [cpuItem, memItem, netItem].forEach(i => i.show());

  context.subscriptions.push(cpuItem, memItem, netItem, {
    dispose() { if (timer) clearInterval(timer); }
  });

  tick();
  timer = setInterval(tick, 3000);
}

function tick() {
  readMem();
  readCpu();
  readNet();
}

function readMem() {
  execFile('free', ['-m'], (err, so) => {
    if (err) return;
    const m = so.match(/Mem:\s+(\d+)\s+(\d+)\s+/);
    if (!m) return;
    const total = parseInt(m[1], 10);
    const used = parseInt(m[2], 10);
    const pct = total ? Math.round((used / total) * 100) : 0;
    memItem.text = `$(pulse) Mem ${pct}% ${used}/${total}MB`;
  });
}

function readCpu() {
  execFile('/bin/bash', ['-c', 'cat /proc/stat'], (err, so) => {
    if (err) return;
    const line = so.split('\n')[0]; // aggregate "cpu" line
    const parts = line.trim().split(/\s+/).slice(1).map(Number);
    if (parts.length < 4) return;
    const idle = parts[3] + (parts[4] || 0); // idle + iowait
    const total = parts.reduce((a, b) => a + b, 0);
    if (prevCpu && (total - prevCpu.total) > 0) {
      const pct = (1 - (idle - prevCpu.idle) / (total - prevCpu.total)) * 100;
      cpuItem.text = `$(chip) CPU ${pct.toFixed(0)}%`;
    }
    prevCpu = { total, idle };
  });
}

function readNet() {
  execFile('/bin/bash', ['-c', "cat /proc/net/dev | awk 'NR>2 {rx+=$2; tx+=$10} END {print rx, tx}'"], (err, so) => {
    if (err) return;
    const p = so.trim().split(/\s+/);
    const rx = parseInt(p[0], 10);
    const tx = parseInt(p[1], 10);
    const now = Date.now();
    if (prevNet && prevNetT) {
      const dt = (now - prevNetT) / 1000;
      const rRate = dt > 0 ? Math.max(0, (rx - prevNet.rx)) / dt : 0;
      const tRate = dt > 0 ? Math.max(0, (tx - prevNet.tx)) / dt : 0;
      const fmt = b => (b >= 1048576 ? (b / 1048576).toFixed(1) + 'MB/s'
                       : b >= 1024 ? (b / 1024).toFixed(0) + 'KB/s'
                       : b.toFixed(0) + 'B/s');
      netItem.text = `$(arrow-down) ${fmt(rRate)} $(arrow-up) ${fmt(tRate)}`;
    }
    prevNet = { rx, tx };
    prevNetT = now;
  });
}

function deactivate() {}
module.exports = { activate, deactivate };
