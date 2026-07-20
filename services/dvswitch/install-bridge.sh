#!/usr/bin/env bash
#
# install-bridge.sh — install the TicketsCAD DMR/HBP bridge stack
# on a fresh Debian/Ubuntu host.
#
# What this does (idempotent — safe to re-run):
#   1. Installs OS prerequisites (python3-venv, ffmpeg, qemu-user-static, ...).
#   2. Creates the unprivileged `ticketscad` system user.
#   3. Drops md380-emu + qemu wrapper into /opt/md380-emu/ and registers
#      its systemd unit (the AMBE+2 codec the bridge talks to over UDP).
#      The md380-emu binary itself is NOT shipped in the TicketsCAD repo
#      (we didn't write it — it's the MD-380 radio firmware in emulation,
#      maintained upstream at travisgoodspeed/md380tools — see
#      https://github.com/travisgoodspeed/md380tools/wiki/MD380-Emulator).
#      Three ways to source the binary, in order of preference:
#         (a) MD380_SOURCE_BRIDGE=<host> — scp from another working
#             TicketsCAD bridge. Fastest if you already have one.
#         (b) MD380_BUILD=1 — clone travisgoodspeed/md380tools and
#             build from source. The canonical path; needs git +
#             gcc-arm-linux-gnueabi + make.
#         (c) MD380_URL=<url> — direct download from a mirror you
#             trust. The script fetches with curl.
#      If none of these are set and no binary exists at
#      /opt/md380-emu/md380-emu, the script bails with instructions
#      so you can satisfy this step manually and re-run.
#   4. Copies the bridge Python source from this repo (services/dvswitch/)
#      into /opt/ticketscad-dvswitch/.
#   5. Builds a Python venv with faster-whisper, piper-tts, onnxruntime, numpy.
#   6. Downloads a default Piper voice (en_US-lessac-medium) unless
#      PIPER_VOICE_FILE is already in place.
#   7. Creates state dirs: /var/cache/ticketscad-dvswitch/recordings,
#      /var/log/ticketscad-dvswitch (owned by ticketscad).
#   8. Installs the systemd unit + the .env template (DOES NOT populate
#      DMR_BEARER_TOKEN, DMR_OPERATOR_ID, etc. — that's a deliberate manual
#      step so the BrandMeister password and the per-instance secret never
#      reach git history through this script).
#   9. Reloads systemd. DOES NOT enable / start the service — the admin
#      finishes the .env + MMDVM_Bridge.ini, then runs the suggested
#      systemctl commands printed at the end.
#
# What this does NOT do (intentional):
#   * Populate /etc/ticketscad/hbp-client.env — admin fills DMR creds.
#   * Populate /opt/MMDVM_Bridge/MMDVM_Bridge.ini — admin fills BM password.
#   * Insert dmr_channels rows in the TicketsCAD database — that's a
#     deployment-time DB operation, not an OS install.
#   * Open firewall ports — depends on your network topology.
#
# Run as: sudo bash install-bridge.sh
#
# Environment variables (all optional):
#   MD380_SOURCE_BRIDGE=<host>   — SSH/SCP hostname (or alias) of an
#                                  existing TicketsCAD bridge to copy
#                                  /opt/md380-emu/md380-emu from. The
#                                  fastest way to provision a second
#                                  bridge in the same fleet.
#   MD380_BUILD=1                — clone travisgoodspeed/md380tools and
#                                  build the emulator from source. Pulls
#                                  in gcc-arm-linux-gnueabi + make as
#                                  apt deps. Closest to upstream.
#   MD380_URL=<url>              — direct-download URL for a pre-built
#                                  md380-emu ARM binary. The script
#                                  curl-fetches and chmod +x's it.
#   PIPER_VOICE=<voice_id>       — Piper voice to install
#                                  (default: en_US-lessac-medium)
#   SKIP_PIPER=1                 — skip the Piper voice download
#                                  (useful when re-running and voice is
#                                  already in place)
#   SKIP_WHISPER=1               — skip the faster-whisper install
#                                  (useful when STT is provided elsewhere)
#

set -euo pipefail
trap 'echo "[install-bridge] FAILED at line $LINENO" >&2' ERR

# ────────────────────────────────────────────────────────────────
# Resolved paths and constants
# ────────────────────────────────────────────────────────────────
# Locate the services/dvswitch source files. Three discovery paths
# tried in order so the script works whether it's run from inside the
# repo (the normal case), from /tmp/ after being scp'd onto a fresh
# host, or with a manual override:
#
#   1. BRIDGE_REPO_SRC=<path>  — explicit override, wins if set
#   2. <script-dir>/../dvswitch/hbp_client.py  — running from repo
#   3. /var/www/newui/services/dvswitch/       — running from /tmp on
#      a host that already has the TicketsCAD code deployed
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -n "${BRIDGE_REPO_SRC:-}" ] && [ -f "${BRIDGE_REPO_SRC}/hbp_client.py" ]; then
    SRC_DIR="$BRIDGE_REPO_SRC"
elif [ -f "$SCRIPT_DIR/hbp_client.py" ]; then
    SRC_DIR="$SCRIPT_DIR"
elif [ -f "$SCRIPT_DIR/../dvswitch/hbp_client.py" ]; then
    SRC_DIR="$(cd "$SCRIPT_DIR/../dvswitch" && pwd)"
elif [ -f /var/www/newui/services/dvswitch/hbp_client.py ]; then
    SRC_DIR=/var/www/newui/services/dvswitch
else
    SRC_DIR=""
fi
TARGET_DIR=/opt/ticketscad-dvswitch
MD380_DIR=/opt/md380-emu
MMDVM_INI_DIR=/opt/MMDVM_Bridge
ETC_DIR=/etc/ticketscad
CACHE_DIR=/var/cache/ticketscad-dvswitch
LOG_DIR=/var/log/ticketscad-dvswitch
SERVICE_USER=ticketscad
SYSTEMD_DIR=/etc/systemd/system
PIPER_VOICE="${PIPER_VOICE:-en_US-lessac-medium}"

say() { printf '\033[1;36m[install-bridge]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[install-bridge] WARN:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31m[install-bridge] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "must be run as root (sudo bash $0)"
[ -n "$SRC_DIR" ] && [ -d "$SRC_DIR" ] || die "could not find the services/dvswitch/ source directory.

Tried (in order):
  \$BRIDGE_REPO_SRC=${BRIDGE_REPO_SRC:-<unset>}
  $SCRIPT_DIR/hbp_client.py
  $SCRIPT_DIR/../dvswitch/hbp_client.py
  /var/www/newui/services/dvswitch/hbp_client.py

Re-run with BRIDGE_REPO_SRC=<path> or run from inside the repo,
e.g.:
  cd /var/www/newui/services/dvswitch && sudo bash ./install-bridge.sh"
say "source dir: $SRC_DIR"

# ────────────────────────────────────────────────────────────────
# Step 1 — OS packages
# ────────────────────────────────────────────────────────────────
say "Step 1/9 — installing OS packages (python3-venv, ffmpeg, qemu-user-static, ...)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y --no-install-recommends \
    python3 python3-venv python3-pip \
    ffmpeg \
    qemu-user-static \
    curl ca-certificates \
    > /dev/null
say "  ✓ OS packages OK"

# ────────────────────────────────────────────────────────────────
# Step 2 — service user
# ────────────────────────────────────────────────────────────────
say "Step 2/9 — ensuring service user '$SERVICE_USER' exists"
if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    useradd --system --shell /usr/sbin/nologin --home-dir "$TARGET_DIR" "$SERVICE_USER"
    say "  ✓ created user $SERVICE_USER"
else
    say "  ✓ user $SERVICE_USER already exists"
fi

# ────────────────────────────────────────────────────────────────
# Step 3 — md380-emu (the AMBE+2 codec)
# ────────────────────────────────────────────────────────────────
say "Step 3/9 — md380-emu codec at $MD380_DIR"
mkdir -p "$MD380_DIR"
if [ ! -x "$MD380_DIR/md380-emu" ]; then
    if [ -n "${MD380_SOURCE_BRIDGE:-}" ]; then
        # (a) Fastest path: copy from a working bridge in the same fleet.
        say "  ↳ scp'ing md380-emu from $MD380_SOURCE_BRIDGE:/opt/md380-emu/md380-emu"
        scp -p "${MD380_SOURCE_BRIDGE}:/opt/md380-emu/md380-emu" \
              "$MD380_DIR/md380-emu" \
            || die "scp from $MD380_SOURCE_BRIDGE failed — check SSH access + that /opt/md380-emu/md380-emu exists on that host"
        chmod +x "$MD380_DIR/md380-emu"
        say "  ✓ md380-emu copied from $MD380_SOURCE_BRIDGE"

    elif [ "${MD380_BUILD:-0}" = "1" ]; then
        # (b) Canonical path: build from the travisgoodspeed/md380tools
        # source. Needs the ARM cross-compiler + git + make. Per the
        # upstream wiki — https://github.com/travisgoodspeed/md380tools/wiki/MD380-Emulator —
        # this links MD380 firmware + symbols and produces md380-emu.
        say "  ↳ MD380_BUILD=1: building md380-emu from upstream source"
        apt-get install -y --no-install-recommends \
            git make gcc-arm-linux-gnueabi > /dev/null
        BUILD_DIR=$(mktemp -d)
        # shellcheck disable=SC2064
        trap "rm -rf $BUILD_DIR" EXIT
        say "  ↳ cloning travisgoodspeed/md380tools into $BUILD_DIR"
        git clone --depth 1 https://github.com/travisgoodspeed/md380tools.git \
            "$BUILD_DIR/md380tools" \
            || die "git clone failed — check network or upstream availability"
        say "  ↳ running 'make clean all' in emulator/"
        ( cd "$BUILD_DIR/md380tools/emulator" && make clean all ) \
            || die "upstream build failed — try MD380_SOURCE_BRIDGE=<host> to scp from a working bridge instead.
Build errors are usually one of:
  - missing MD380 firmware blob (upstream tries to download it)
  - newer gcc-arm-linux-gnueabi than the Makefile expects
  - missing dependencies the upstream Makefile assumes
The upstream wiki has the current build environment recommendation."
        install -m 0755 "$BUILD_DIR/md380tools/emulator/md380-emu" "$MD380_DIR/md380-emu" \
            || die "build produced no md380-emu binary at expected path"
        say "  ✓ md380-emu built and installed"

    elif [ -n "${MD380_URL:-}" ]; then
        # (c) Pragmatic fallback: pre-built binary from a URL the admin
        # picked. Same restrictions apply (need the right ARM ELF for
        # qemu-arm-static to run).
        say "  ↳ downloading md380-emu from $MD380_URL"
        curl -fsSL "$MD380_URL" -o "$MD380_DIR/md380-emu" \
            || die "download of md380-emu failed — check MD380_URL"
        chmod +x "$MD380_DIR/md380-emu"
        say "  ✓ md380-emu downloaded"

    else
        die "md380-emu binary not present at $MD380_DIR/md380-emu.

This is the MD-380 radio firmware in emulation — the AMBE+2 voice
codec DMR networks use. We don't ship it (not our code; canonical
home is travisgoodspeed/md380tools).

Pick one and re-run this script:

  MD380_SOURCE_BRIDGE=<host>   sudo bash $0
      → scp from an existing TicketsCAD bridge (fastest)

  MD380_BUILD=1                sudo bash $0
      → clone + build from upstream
        (https://github.com/travisgoodspeed/md380tools/wiki/MD380-Emulator)

  MD380_URL=<direct-url>       sudo bash $0
      → download a pre-built binary from a URL you trust

You can also hand-place the binary at ${MD380_DIR}/md380-emu and
re-run with no env var; the script will detect it.

The qemu-arm-static loader is the OS-supplied one (already installed
in Step 1) — it will execute whichever md380-emu you provide."
    fi
fi
# Use the OS-provided qemu-arm-static. The DVSwitch reference install
# bundles its own copy at /opt/md380-emu/qemu-arm-static — symlink so
# the existing systemd ExecStart still resolves either way.
if [ ! -e "$MD380_DIR/qemu-arm-static" ]; then
    if [ -e /usr/bin/qemu-arm-static ]; then
        ln -s /usr/bin/qemu-arm-static "$MD380_DIR/qemu-arm-static"
        say "  ✓ symlinked qemu-arm-static from the OS package"
    else
        die "qemu-arm-static missing — apt installed the package but binary is not at /usr/bin/qemu-arm-static; please verify"
    fi
fi
# md380-emu systemd unit (the codec runs as its own service so multiple
# bridge processes can share it; UDP 2470 is the contract).
cat > "$SYSTEMD_DIR/md380-emu.service" <<'UNIT'
[Unit]
Description=MD-380 AMBE+2 codec emulator (UDP 2470)
After=network.target

[Service]
Type=simple
WorkingDirectory=/opt/md380-emu
ExecStart=/opt/md380-emu/qemu-arm-static /opt/md380-emu/md380-emu -S 2470
Restart=on-failure
RestartSec=3
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT
say "  ✓ md380-emu.service unit installed"

# ────────────────────────────────────────────────────────────────
# Step 4 — TicketsCAD bridge source
# ────────────────────────────────────────────────────────────────
say "Step 4/9 — copying bridge source into $TARGET_DIR"
mkdir -p "$TARGET_DIR"
# Copy hbp_client.py + the services/dvswitch helpers. We follow the
# layout the running training bridge uses: helpers live in
# /opt/ticketscad-dvswitch/services/dvswitch/ so the import path
# `from services.dvswitch.X` keeps working without code changes.
install -m 0644 "$SRC_DIR/hbp_client.py"   "$TARGET_DIR/hbp_client.py"
install -m 0644 "$SRC_DIR/echo_bot.py"     "$TARGET_DIR/echo_bot.py" 2>/dev/null || true
mkdir -p "$TARGET_DIR/services/dvswitch"
for f in ambe_codec.py ambe_fec.py bptc_19696.py dmr_tx.py emb.py \
         embedded_lc.py rs_129.py slot_type.py voice_burst.py \
         _prng_data.py bridge.py; do
    if [ -f "$SRC_DIR/$f" ]; then
        install -m 0644 "$SRC_DIR/$f" "$TARGET_DIR/services/dvswitch/$f"
    fi
done
# Ensure Python sees `services` as a package.
touch "$TARGET_DIR/services/__init__.py"
touch "$TARGET_DIR/services/dvswitch/__init__.py"
chown -R "$SERVICE_USER:$SERVICE_USER" "$TARGET_DIR"
say "  ✓ source synced"

# ────────────────────────────────────────────────────────────────
# Step 5 — Python venv + deps
# ────────────────────────────────────────────────────────────────
say "Step 5/9 — Python venv at $TARGET_DIR/venv (this can take a few minutes)"
if [ ! -x "$TARGET_DIR/venv/bin/python3" ]; then
    sudo -u "$SERVICE_USER" python3 -m venv "$TARGET_DIR/venv"
fi
# Upgrade pip + install the bridge's Python deps. Keep these versions
# loose so a re-run on a newer host doesn't get stuck on EOL wheels;
# pin tighter if you hit reproducibility issues.
sudo -u "$SERVICE_USER" "$TARGET_DIR/venv/bin/pip" install --quiet --upgrade pip
sudo -u "$SERVICE_USER" "$TARGET_DIR/venv/bin/pip" install --quiet \
    numpy onnxruntime piper-tts
if [ "${SKIP_WHISPER:-0}" != "1" ]; then
    sudo -u "$SERVICE_USER" "$TARGET_DIR/venv/bin/pip" install --quiet \
        faster-whisper
    say "  ✓ faster-whisper STT installed"
else
    say "  ↳ SKIP_WHISPER=1 — STT install skipped"
fi
say "  ✓ venv ready"

# ────────────────────────────────────────────────────────────────
# Step 6 — Piper voice
# ────────────────────────────────────────────────────────────────
say "Step 6/9 — Piper voice $PIPER_VOICE"
VOICES_DIR="$TARGET_DIR/voices"
mkdir -p "$VOICES_DIR"
ONNX="$VOICES_DIR/${PIPER_VOICE}.onnx"
JSON="$VOICES_DIR/${PIPER_VOICE}.onnx.json"
if [ "${SKIP_PIPER:-0}" = "1" ]; then
    say "  ↳ SKIP_PIPER=1 — voice download skipped"
elif [ ! -f "$ONNX" ] || [ ! -f "$JSON" ]; then
    # Piper voices are hosted as release assets in rhasspy/piper-voices.
    # The path inside the release follows {lang}/{region}/{voice_name}/
    # convention. en_US-lessac-medium is the default the rest of the
    # codebase assumes; if you change PIPER_VOICE, update the bridge
    # env DMR_PIPER_VOICE accordingly.
    base="https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium"
    say "  ↳ fetching ${PIPER_VOICE}.onnx ($base) ..."
    curl -fsSL "$base/${PIPER_VOICE}.onnx"      -o "$ONNX" || \
        warn "Piper voice download failed — drop the file at $ONNX manually and re-run"
    curl -fsSL "$base/${PIPER_VOICE}.onnx.json" -o "$JSON" || \
        warn "Piper voice config download failed — drop $JSON manually and re-run"
    chown "$SERVICE_USER:$SERVICE_USER" "$ONNX" "$JSON" 2>/dev/null || true
else
    say "  ✓ Piper voice already in place"
fi

# ────────────────────────────────────────────────────────────────
# Step 7 — state directories
# ────────────────────────────────────────────────────────────────
say "Step 7/9 — state directories"
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0755 "$CACHE_DIR"
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0755 "$CACHE_DIR/recordings"
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0755 "$LOG_DIR"
install -d -o root             -g "$SERVICE_USER" -m 0750 "$ETC_DIR"
say "  ✓ /var/cache + /var/log + /etc/ticketscad ready"

# ────────────────────────────────────────────────────────────────
# Step 8 — MMDVM_Bridge.ini template + systemd + env template
# ────────────────────────────────────────────────────────────────
say "Step 8/9 — service unit + env templates"
# MMDVM_Bridge.ini lives at the path hbp_client.py defaults to. We
# only deploy a TEMPLATE if no real ini is in place — never clobber
# an existing one that may already have the BrandMeister password.
mkdir -p "$MMDVM_INI_DIR"
INI="$MMDVM_INI_DIR/MMDVM_Bridge.ini"
if [ ! -f "$INI" ]; then
    # Section names + fields here MUST match what hbp_client.py reads
    # from MMDVM_Bridge.ini via configparser — specifically:
    #   [General]      Callsign, Id
    #   [Info]         RXFrequency, TXFrequency, Power, Latitude,
    #                  Longitude, Height, Location, Description, URL
    #   [DMR Network]  Enable, Address, Port, Local, Password
    # The earlier minimal template was missing [Info]Power and had the
    # section labeled [DMR Network 1] instead of [DMR Network], so
    # hbp_client crashed with KeyError on first start. This template
    # mirrors training's working INI; admin only needs to fill in the
    # Callsign, Id, Address (BM master), and Password.
    cat > "$INI" <<'INI'
# /opt/MMDVM_Bridge/MMDVM_Bridge.ini — TicketsCAD HBP bridge config.
# Read by hbp_client.py at startup. The full MMDVM_Bridge daemon is
# NOT installed (hbp_client speaks HBP directly to BrandMeister).
# After editing, ensure mode 0640 root:ticketscad so the bearer-style
# Password line is not world-readable.

[General]
Callsign=YOUR_CALLSIGN_HERE
Id=000000000
Timeout=180
Duplex=0

[Info]
RXFrequency=438800000
TXFrequency=438800000
Power=0
Latitude=0.0
Longitude=0.0
Height=0
Location=City
Description=TicketsCAD HBP bridge
URL=https://ticketscad.local

[Log]
DisplayLevel=1
FileLevel=2
FilePath=/var/log/mmdvm
FileRoot=MMDVM_Bridge

[DMR Id Lookup]
File=/var/lib/mmdvm/DMRIds.dat
Time=24

[Modem]
Port=/dev/null
RSSIMappingFile=/dev/null
Trace=0
Debug=0

[DMR]
Enable=1
ColorCode=1
EmbeddedLCOnly=1
DumpTAData=0

[DMR Network]
Enable=1
Address=YOUR_BM_MASTER_HOSTNAME
Port=62031
Jitter=360
Local=62032
Password=REPLACE_ME_WITH_BM_PASSWORD
Slot1=0
Slot2=1
Debug=1
INI
    chown root:"$SERVICE_USER" "$INI"
    chmod 0640 "$INI"
    say "  ✓ MMDVM_Bridge.ini TEMPLATE installed at $INI"
    say "    Admin must edit this file with the real values — see end-of-install notes."
else
    say "  ✓ MMDVM_Bridge.ini already present (not touching)"
fi

# Bridge env template — same convention as the existing
# hbp-client.env.example shipped in the repo. The script never
# writes the real secrets here either; admin populates.
if [ ! -f "$ETC_DIR/hbp-client.env" ] && [ -f "$SRC_DIR/hbp-client.env.example" ]; then
    install -m 0640 -o root -g "$SERVICE_USER" \
        "$SRC_DIR/hbp-client.env.example" "$ETC_DIR/hbp-client.env"
    say "  ✓ hbp-client.env template installed at $ETC_DIR/hbp-client.env"
    say "    Admin must fill DMR_BEARER_TOKEN, DMR_OPERATOR_ID, DMR_DEFAULT_TG."
elif [ -f "$ETC_DIR/hbp-client.env" ]; then
    say "  ✓ hbp-client.env already present (not touching)"
fi

# Systemd unit for the bridge itself.
if [ -f "$SRC_DIR/ticketscad-hbp-client.service.example" ]; then
    install -m 0644 "$SRC_DIR/ticketscad-hbp-client.service.example" \
        "$SYSTEMD_DIR/ticketscad-hbp-client.service"
    say "  ✓ ticketscad-hbp-client.service installed"
fi

# ────────────────────────────────────────────────────────────────
# Step 9 — systemctl daemon-reload, print next steps
# ────────────────────────────────────────────────────────────────
say "Step 9/9 — systemd daemon-reload"
systemctl daemon-reload
say "  ✓ done"

echo
echo "════════════════════════════════════════════════════════════════"
echo "  Install complete — finish these steps before starting services"
echo "════════════════════════════════════════════════════════════════"
cat <<EOF

1. Edit /opt/MMDVM_Bridge/MMDVM_Bridge.ini and fill in:
     [General] Callsign, Id (your hotspot DMR ID)
     [DMR Network 1] Address (your BM master hostname), Password
                     (your BrandMeister account password), Id

2. Edit /etc/ticketscad/hbp-client.env and fill in:
     DMR_BEARER_TOKEN=$(openssl rand -hex 32)
     DMR_OPERATOR_ID=<your DMR ID — same as MMDVM Id above>
     DMR_DEFAULT_TG=<the talkgroup you want this bridge to TX on>
   Then  sudo chmod 0640 /etc/ticketscad/hbp-client.env

3. Start the AMBE codec (does not require any config):
     sudo systemctl enable --now md380-emu.service
     sudo systemctl status md380-emu.service     # confirm "active"

4. Start the bridge:
     sudo systemctl enable --now ticketscad-hbp-client.service
     sudo journalctl -u ticketscad-hbp-client.service -f
   Watch for:
     "RUNNING — authenticated to <master> as DMR ID <Id>"

5. On the TicketsCAD application host (this same VM if it's
   co-located), insert the dmr_channels row pointing at this
   bridge — the bearer token from step 2 is what TicketsCAD will
   send in the Authorization header:

     INSERT INTO dmr_channels (label, talkgroup, bridge_host,
                               bridge_port, bridge_token, link_mode,
                               enabled)
     VALUES ('<channel label>', <talkgroup>, '127.0.0.1', 18091,
             '<DMR_BEARER_TOKEN from step 2>', 'hbp', 1);

6. Reload the radio widget in the dispatch UI — the Radio button
   should now connect.

Full details in docs/RADIO-AI-ADMIN-GUIDE.md and
docs/DVSWITCH-ADMIN-GUIDE.md.

EOF
