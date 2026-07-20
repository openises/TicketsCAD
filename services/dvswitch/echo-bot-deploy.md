# Deploying the DMR echo bot on `dvswitch-01`

The bot listens for inbound DMRD voice on the local hbp_client socket
(via passive tcpdump), decodes AMBE → PCM, transcribes with
faster-whisper, and replies through `hbp_client`'s `/tx/text` HTTP
endpoint.

## Files

| Source-controlled | Deployed to |
|---|---|
| `services/dvswitch/echo_bot.py` | `/opt/ticketscad-dvswitch/echo_bot.py` |
| `services/dvswitch/ticketscad-echo-bot.service` | `/etc/systemd/system/ticketscad-echo-bot.service` |

The bot imports `services.dvswitch.ambe_codec`, `ambe_fec`, and
`_prng_data` — those must already be installed under
`/opt/ticketscad-dvswitch/services/dvswitch/` (they were as of phase 84).

## Install steps

```bash
# 1. Push the bot + service file to the host.
scp services/dvswitch/echo_bot.py dvswitch-01:/tmp/
scp services/dvswitch/ticketscad-echo-bot.service dvswitch-01:/tmp/

ssh dvswitch-01
sudo install -m 0644 -o ticketscad -g ticketscad \
    /tmp/echo_bot.py /opt/ticketscad-dvswitch/echo_bot.py
sudo install -m 0644 /tmp/ticketscad-echo-bot.service \
    /etc/systemd/system/ticketscad-echo-bot.service

# 2. Environment file. Reuses hbp_client's bearer token; rotate together.
sudo mkdir -p /etc/ticketscad
sudo tee /etc/ticketscad/echo-bot.env > /dev/null <<'EOF'
DMR_BEARER_TOKEN=<same token hbp_client uses>
DMR_HTTP_PORT=18091
DMR_HBP_LOCAL_PORT=62032
DMR_BM_MASTER_IP=74.91.114.19
WHISPER_MODEL=base.en
WHISPER_COMPUTE=int8
PYTHONPATH=/opt/ticketscad-dvswitch
EOF
sudo chmod 0640 /etc/ticketscad/echo-bot.env
sudo chown root:ticketscad /etc/ticketscad/echo-bot.env

# 3. Sudoers — bot needs to invoke tcpdump without password.
sudo tee /etc/sudoers.d/ticketscad-echo-bot > /dev/null <<'EOF'
ticketscad ALL=(root) NOPASSWD: /usr/bin/tcpdump
EOF
sudo chmod 0440 /etc/sudoers.d/ticketscad-echo-bot
sudo visudo -c -f /etc/sudoers.d/ticketscad-echo-bot   # validate

# 4. Whisper model cache — pre-warm to avoid 150 MB download on first
#    start while the systemd timeout is ticking.
sudo -u ticketscad bash -c '
    cd /opt/ticketscad-dvswitch
    ./venv/bin/python3 -c "
from faster_whisper import WhisperModel
WhisperModel(\"base.en\", device=\"cpu\", compute_type=\"int8\")
print(\"model warmed\")
    "
'

# 5. Enable + start.
sudo systemctl daemon-reload
sudo systemctl enable --now ticketscad-echo-bot
sudo systemctl status ticketscad-echo-bot
sudo journalctl -u ticketscad-echo-bot -f
```

## Verifying

With the bot running, key your radio briefly on TG 3127 with a clear
sentence. You should see, in `journalctl -u ticketscad-echo-bot`:

```
INFO call <sid> ended — N packets, decoding
INFO voice payloads: N
INFO wrote /tmp/rx-<sid>.wav (X.XX sec)
INFO STT (X.XXs): 'the sentence you said'
INFO replying: "I heard you say. <text>. End of reply."
INFO TX response: {"ok": true, "packets_sent": ...}
```

Then the radio will speak the reply ~2-3 seconds after your call
ends. (Whisper decode time + Piper TTS + AMBE encode + 60ms-paced TX
to BrandMeister all add up.)

## Choosing a Whisper model

The default is `base.en` because it balances quality and CPU. For
better quality at the cost of latency, edit `/etc/ticketscad/echo-bot.env`:

| WHISPER_MODEL  | Disk   | Latency on a 5s utterance | Quality on AMBE |
|----------------|--------|---------------------------|-----------------|
| `tiny.en`      | 75 MB  | ~0.4 s                    | poor            |
| `base.en`      | 150 MB | ~1.0 s                    | good            |
| `small.en`     | 500 MB | ~2.5 s                    | very good       |
| `medium.en`    | 1.5 GB | ~7 s                      | excellent       |

`int8` quantization is fine for all of these. If the VM has AVX2,
`int8_float16` is slightly faster.

## Troubleshooting

- **Bot starts but never reacts to calls**: confirm hbp_client is
  authenticated (`journalctl -u ticketscad-hbp-client | tail`) and
  that TG 3127 traffic is reaching us (`grep DMRD /tmp/hbp.log |
  tail`). If hbp_client sees voice but the bot doesn't, the tcpdump
  filter or the sudoers grant is the problem.
- **STT is empty**: confirm the WAV is correct by playing it (`aplay
  /tmp/rx-<sid>.wav`). If audio is silent, the AMBE decode is the
  problem — see the encoder modules in `services/dvswitch/`. If audio
  is intelligible but Whisper returns nothing, try a larger model.
- **Reply transmits but never reaches the radio**: confirm the bot's
  `DMR_BEARER_TOKEN` matches what hbp_client expects — they share the
  same secret.
- **AMBE decode failures spam the log**: usually means a few wire bits
  were dropped/corrupted upstream. Calls with isolated bad frames
  still transcribe fine; if you see hundreds in a row, hbp_client's
  RX socket is probably dropping packets (check `ss -unp | grep 62032`
  for receive buffer overruns).
