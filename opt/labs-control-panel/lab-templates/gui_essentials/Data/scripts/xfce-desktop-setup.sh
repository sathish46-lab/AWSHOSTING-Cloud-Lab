#!/bin/bash
# xfce-desktop-setup.sh — Configure XFCE desktop layout
# Called from linkuser.sh after user creation
# Usage: xfce-desktop-setup.sh <username>

USER_NAME="${1:-sathish46}"
USER_HOME=$(eval echo "~$USER_NAME")
DISPLAY_NUM=":1"
export DISPLAY="$DISPLAY_NUM"

echo "[*] Configuring XFCE desktop layout for $USER_NAME..."

# ── Helper ────────────────────────────────────────────────────
xfce_set() {
    local channel="$1" prop="$2" val="$3"
    xfconf-query -c "$channel" -p "$prop" -s "$val" 2>/dev/null || \
    xfconf-query -c "$channel" -p "$prop" -n -t "$(echo "$val" | grep -qE '^[0-9]+$' && echo int || echo string)" -s "$val" 2>/dev/null || true
}

# ── 1. Wallpaper ─────────────────────────────────────────────
WALLPAPER=$(find "$USER_HOME/.config/wallpaper" -type f \( -name "*.png" -o -name "*.jpg" -o -name "*.jpeg" \) 2>/dev/null | head -1)
if [ -n "$WALLPAPER" ]; then
    xfce_set "xfce4-desktop" "/backdrop/screen0/monitorVirtual-1/workspace0/last-image" "$WALLPAPER"
    xfce_set "xfce4-desktop" "/backdrop/screen0/monitorVirtual-1/workspace0/image-style" "5"
    xfce_set "xfce4-desktop" "/backdrop/screen0/monitorVirtual-1/workspace0/color-style" "0"
    echo "[✓] Wallpaper: $(basename "$WALLPAPER")"
fi

# ── 2. Desktop Icons ─────────────────────────────────────────
xfce_set "xfce4-desktop" "/desktop-icons/style" "0"
xfce_set "xfce4-desktop" "/desktop-icons/icon-size" "48"
xfce_set "xfce4-desktop" "/desktop-icons/single-click" "false"
xfce_set "xfce4-desktop" "/desktop-icons/show-thumbnails" "false"

# Create common directories if missing
mkdir -p "$USER_HOME/Desktop" "$USER_HOME/Downloads" "$USER_HOME/Documents" "$USER_HOME/Uploads"
chown -R "$USER_NAME:$USER_NAME" "$USER_HOME/Desktop" "$USER_HOME/Downloads" "$USER_HOME/Documents" "$USER_HOME/Uploads"
echo "[✓] Desktop icons configured"

# ── 3. Panel 1 — Top Bar (clock, systray, notification, clipman) ──
# Reset existing panels
xfconf-query -c xfce4-panel -r -p /panels 2>/dev/null || true
xfconf-query -c xfce4-panel -r -p /plugins 2>/dev/null || true

# Panel 1 — Top bar
xfconf-query -c xfce4-panel -p /panels/panel-1/position -s "p=8;x=0;y=0" 2>/dev/null || true
xfconf-query -c xfce4-panel -p /panels/panel-1/length -s "100" 2>/dev/null || true
xfconf-query -c xfce4-panel -p /panels/panel-1/position-locked -s "true" 2>/dev/null || true
xfconf-query -c xfce4-panel -p /panels/panel-1/background-style -s "0" 2>/dev/null || true
xfconf-query -c xfce4-panel -p /panels/panel-1/size -s "28" 2>/dev/null || true
xfconf-query -c xfce4-panel -p /panels/panel-1/num-rows -s "1" 2>/dev/null || true
xfconf-query -c xfce4-panel -p /panels/panel-1/monitor -s "0" 2>/dev/null || true
xfconf-query -c xfce4-panel -p /panels/panel-1/screen -s "0" 2>/dev/null || true

# Plugin IDs for panel 1 (top)
P1_CLIPBOARD="clipman-1"
P1_SYSTRAY="systray-1"
P1_SEPARATOR1="sep-t1"
P1_CLOCK="clock-1"
P1_SEPARATOR2="sep-t2"

# Set panel 1 plugin ids
xfconf-query -c xfce4-panel -p /panels/panel-1/plugin-ids -n -t int -s 1 -s 2 -s 3 -s 4 -s 5 2>/dev/null || true

# Create plugins
mkdir -p "$USER_HOME/.config/xfce4/panel"
cat <<PLUGINCONF > "$USER_HOME/.config/xfce4/panel/xfce4-panel.xml"
<?xml version="1.0" encoding="UTF-8"?>
<channel name="xfce4-panel" version="1.0">
  <property name="panels" type="array">
    <value type="int" value="1"/>
    <property name="panel-1" type="empty">
      <property name="position" type="string" value="p=8;x=0;y=0"/>
      <property name="length" type="uint" value="100"/>
      <property name="position-locked" type="bool" value="true"/>
      <property name="size" type="uint" value="28"/>
      <property name="background-style" type="uint" value="0"/>
      <property name="plugin-ids" type="array">
        <value type="int" value="1"/>
        <value type="int" value="2"/>
        <value type="int" value="3"/>
        <value type="int" value="4"/>
        <value type="int" value="5"/>
      </property>
    </property>
    <property name="panel-2" type="empty">
      <property name="position" type="string" value="p=12;x=0;y=0"/>
      <property name="length" type="uint" value="30"/>
      <property name="position-locked" type="bool" value="true"/>
      <property name="size" type="uint" value="48"/>
      <property name="background-style" type="uint" value="0"/>
      <property name="plugin-ids" type="array">
        <value type="int" value="10"/>
        <value type="int" value="11"/>
        <value type="int" value="12"/>
        <value type="int" value="13"/>
        <value type="int" value="14"/>
      </property>
    </property>
  </property>
</channel>
PLUGINCONF

echo "[✓] Panel layout configured"

# ── 4. Appearance ─────────────────────────────────────────────
xfce_set "xsettings" "/Net/ThemeName" "Greybird"
xfce_set "xsettings" "/Net/IconThemeName" "elementary-xfce-dark"
xfce_set "xsettings" "/Gtk/FontName" "Sans 10"
xfce_set "xsettings" "/Gtk/MonospaceFontName" "Monospace 10"
echo "[✓] Appearance configured"

# ── 5. Window Manager ────────────────────────────────────────
xfce_set "xfwm4" "/general/theme" "Greybird"
xfce_set "xfwm4" "/general/title_font" "Sans Bold 10"
xfce_set "xfwm4" "/general/button_layout" "CHM|"
xfce_set "xfwm4" "/general/snap_to_border" "true"
xfce_set "xfwm4" "/general/snap_to_windows" "true"
echo "[✓] Window manager configured"

# ── 6. Default Apps ──────────────────────────────────────────
mkdir -p "$USER_HOME/.config/mimeapps.list"
cat <<MIME > "$USER_HOME/.config/mimeapps.list"
[Default Applications]
x-scheme-handler/http=firefox.desktop
x-scheme-handler/https=firefox.desktop
text/html=firefox.desktop
inode/directory=thunar.desktop
application/x-terminal=xfce4-terminal.desktop
MIME
chown "$USER_NAME:$USER_NAME" "$USER_HOME/.config/mimeapps.list"
echo "[✓] Default apps configured"

chown -R "$USER_NAME:$USER_NAME" "$USER_HOME/.config/xfce4" 2>/dev/null || true
echo "[✓] XFCE desktop setup complete"
