# TicketsCAD DMR bridge — container image (Phase 120, 2026-07-25)
#
# Runs BOTH halves of the bridge in one container, because they must share a
# network namespace: the Python client talks to the AMBE vocoder on
# 127.0.0.1:2470 (see services/dvswitch/ambe_codec.py — the host is not
# configurable, matching the bare-metal layout).
#
#   1. md380-emu  — the AMBE+2 vocoder. This is the MD-380 radio firmware in
#      emulation (upstream: travisgoodspeed/md380tools), executed under
#      qemu-arm-static, listening on UDP 2470.
#   2. hbp_client.py — the HomeBrew Protocol client: speaks HBP/UDP to your
#      master (HBLink3, BrandMeister, …) and serves the CAD's HTTP control
#      surface on 18091.
#
# ── IMPORTANT: where md380-emu comes from, and why not from source ─────────
# The vocoder is installed from the DVSwitch project's own apt repository
# (dvswitch.org) — the SAME package a bare-metal DVSwitch/TicketsCAD bridge
# uses. We deliberately do NOT build it from travisgoodspeed/md380tools:
# verified 2026-07-25, that upstream build produces a FILE-mode encoder/decoder
# with no `-S <port>` UDP server mode, which is the mode ambe_codec.py requires.
# An upstream-built binary starts, then rejects `-S` — i.e. no audio.
#
# We therefore do not vendor or redistribute the vocoder: your `docker build`
# fetches it from DVSwitch at build time. It is MD-380 radio firmware in
# emulation; transmitting with it requires an appropriate amateur radio licence.

FROM debian:bookworm-slim

# qemu-user-static runs the ARM vocoder binary on x86_64/arm64 hosts.
# ffmpeg + python3-numpy serve the audio paths; curl is for healthchecks.
RUN apt-get update && apt-get install -y --no-install-recommends \
        qemu-user-static python3 python3-numpy ffmpeg curl gnupg ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# md380-emu from the DVSwitch repository.
ARG DVSWITCH_REPO=http://dvswitch.org/DVSwitch_Repository
ARG DVSWITCH_SUITE=bookworm
# NOTE: we DOWNLOAD and unpack the .deb rather than `apt-get install` it. The
# package's postinst registers a systemd unit and exits 127 in a container
# (no systemctl), which fails the build. We only need the binary — our
# entrypoint supervises it — so `dpkg-deb -x` (no maintainer scripts) is both
# sufficient and more predictable.
RUN mkdir -p /etc/apt/keyrings \
    && curl -fsSL "${DVSWITCH_REPO}/dvswitch-keyring.gpg" -o /etc/apt/keyrings/dvswitch-keyring.gpg \
    && echo "deb [signed-by=/etc/apt/keyrings/dvswitch-keyring.gpg arch=amd64] ${DVSWITCH_REPO} ${DVSWITCH_SUITE} hamradio" \
         > /etc/apt/sources.list.d/dvswitch.list \
    && apt-get update \
    && cd /tmp && apt-get download md380-emu \
    && dpkg-deb -x md380-emu_*.deb /tmp/emupkg \
    && cp -a /tmp/emupkg/opt/md380-emu /opt/ \
    && rm -rf /tmp/emupkg md380-emu_*.deb \
    && rm -rf /var/lib/apt/lists/* \
    && test -x /opt/md380-emu/md380-emu

# Bridge source. The Python modules import as `services.dvswitch.*`, so the
# package path must be preserved and PYTHONPATH must point at its parent
# (mirrors /opt/ticketscad-dvswitch on a bare-metal install).
WORKDIR /opt/ticketscad-dvswitch
COPY . /opt/ticketscad-dvswitch/services/dvswitch/
RUN mkdir -p /opt/ticketscad-dvswitch/services \
    && touch /opt/ticketscad-dvswitch/services/__init__.py \
             /opt/ticketscad-dvswitch/services/dvswitch/__init__.py \
    && cp /opt/ticketscad-dvswitch/services/dvswitch/docker/entrypoint.sh /entrypoint.sh \
    && chmod +x /entrypoint.sh \
    && mkdir -p /var/cache/ticketscad-dvswitch/recordings

ENV PYTHONPATH=/opt/ticketscad-dvswitch \
    PYTHONUNBUFFERED=1 \
    DMR_HTTP_PORT=18091 \
    AMBE_EMU_PORT=2470

# Run as an unprivileged user. Nothing here needs root: both ports (2470,
# 18091) are unprivileged, and the two directories the entrypoint writes to
# at runtime — the rendered MMDVM_Bridge.ini and the recordings cache — are
# created and owned by this user at build time, before ENTRYPOINT drops to it.
RUN useradd -r -M -d /nonexistent -s /usr/sbin/nologin bridge \
    && mkdir -p /etc/ticketscad \
    && chown -R bridge:bridge /etc/ticketscad /var/cache/ticketscad-dvswitch /opt/ticketscad-dvswitch

# HTTP control surface for the CAD. The HBP/UDP socket to your master is
# outbound, so it needs no published port.
EXPOSE 18091

USER bridge
ENTRYPOINT ["/entrypoint.sh"]
