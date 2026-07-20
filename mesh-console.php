<?php
/**
 * NewUI v4.0 — Mesh Console (Phase 35C, 2026-06-12).
 *
 * Admin-only page for observing + controlling all connected mesh
 * bridges. Shows live packet feed, bridge status, send-text box,
 * coverage matrix, and remote device-config affordances.
 *
 * Requires action.manage_mesh_bridges RBAC permission.
 */
require_once __DIR__ . '/config.php';

// 2026-07-04 (GH #13) — pick the session profile matching the
// client's cookie (TCADMOBILE vs PHPSESSID). Without this, a
// browser holding a mobile cookie opens an empty desktop session
// here and bounces to login -> redirect loop.
require_once __DIR__ . '/inc/session-bootstrap.php';
sess_bootstrap_auto();
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/inc/force-pw-change.php';
force_pw_change_redirect();
require_once __DIR__ . '/inc/rbac.php';

if (!rbac_can('action.manage_mesh_bridges')) {
    header('Location: index.php');
    exit;
}

$user     = e($_SESSION['user']);
$level    = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title>Mesh Console — Tickets NewUI <?php echo NEWUI_VERSION; ?></title>
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo NEWUI_VERSION; ?>">
    <link rel="stylesheet" href="assets/css/config.css?v=<?php echo NEWUI_VERSION; ?>">
    <!-- Phase 39E: Leaflet for the node map tab -->
    <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
    <style>
        .mesh-bridge-card {
            border: 1px solid var(--bs-border-color);
            border-radius: 6px;
            padding: 12px 14px;
            background: var(--bs-body-bg);
        }
        .mesh-bridge-card.online  { border-left: 4px solid var(--bs-success); }
        .mesh-bridge-card.warn    { border-left: 4px solid var(--bs-warning); }
        .mesh-bridge-card.offline { border-left: 4px solid var(--bs-danger); }
        .mesh-feed-row { font-size: 0.82rem; padding: 4px 8px; border-bottom: 1px solid var(--bs-border-color); }
        .mesh-feed-row:hover { background: var(--bs-tertiary-bg); }
        .mesh-token-box { font-family: monospace; font-size: 0.75rem; word-break: break-all; }
        .mesh-coverage-table th, .mesh-coverage-table td { font-size: 0.78rem; padding: 4px 8px; }
        .mesh-rssi-strong { color: var(--bs-success); }
        .mesh-rssi-fair   { color: var(--bs-warning); }
        .mesh-rssi-weak   { color: var(--bs-danger); }
        .mesh-coverage-table th.mesh-sortable { user-select: none; }
        .mesh-coverage-table th.mesh-sortable:hover { background: var(--bs-tertiary-bg); }
        .mesh-sort-ind { font-size: 0.7em; opacity: 0.85; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/inc/navbar.php'; ?>
</header>

<div class="config-layout">
    <?php $configActivePage = 'mesh-console'; include_once __DIR__ . '/inc/config-sidebar.php'; ?>

    <main class="config-content" id="configContent" style="padding: 1rem 1.5rem;">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0">
            <i class="bi bi-broadcast-pin text-primary me-2"></i>Mesh Console
            <small class="text-body-secondary">Live LoRa-mesh bridge view</small>
        </h5>
        <div class="d-flex gap-2 align-items-center">
            <span class="small text-body-secondary">
                <span id="meshConnIndicator" class="badge bg-secondary">idle</span>
                last refresh <span id="meshLastRefresh">—</span>
            </span>
            <button class="btn btn-sm btn-outline-secondary" id="btnMeshRefresh">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
            <button class="btn btn-sm btn-success" id="btnMeshNewToken">
                <i class="bi bi-key me-1"></i>Mint Bridge Token
            </button>
        </div>
    </div>

    <div id="alertArea"></div>

    <ul class="nav nav-tabs" id="meshTabs">
        <li class="nav-item"><button class="nav-link active" data-tab="overview">Overview</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="inbox">Inbox <span class="badge bg-danger d-none" id="inboxBadge">0</span></button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="feed">Live Feed</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="nodes">Nodes</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="channels">Channels</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="map">Map</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="send">Send / Compose</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="coverage">Coverage &amp; Latency</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="config">Device Config</button></li>
        <li class="nav-item"><button class="nav-link"        data-tab="setup">Setup</button></li>
    </ul>

    <div class="tab-content mt-3">
        <!-- ── Overview ───────────────────────────────────────────── -->
        <div class="tab-pane fade show active" data-tab-content="overview">
            <div class="row g-3" id="bridgesGrid">
                <div class="text-body-secondary p-3">Loading bridges...</div>
            </div>
        </div>

        <!-- ── Inbox (Phase C) ────────────────────────────────────── -->
        <!-- Reply-able inbound TEXT messages. Each row tags transport +
             origin (src_node / channel slot) + friendly name when known,
             and offers a Reply action that threads back to the origin. -->
        <div class="tab-pane fade" data-tab-content="inbox">
            <div class="d-flex gap-2 mb-2 align-items-center">
                <div class="small text-body-secondary">
                    Inbound text from the mesh. <strong>Reply</strong> sends back to the originating node (direct) or channel slot. Direct replies over MeshCore surface an end-to-end delivery ACK.
                </div>
                <div class="form-check form-switch ms-auto">
                    <input class="form-check-input" type="checkbox" id="inboxAutoRefresh" checked>
                    <label class="form-check-label small" for="inboxAutoRefresh">Auto-refresh (5s)</label>
                </div>
                <button class="btn btn-sm btn-outline-secondary" id="btnInboxRefresh"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
            <div class="card mb-3">
                <div class="card-header py-1 small fw-semibold">
                    <i class="bi bi-broadcast-pin me-1"></i>Mesh
                </div>
                <div class="card-body p-0">
                    <div id="meshInboxBody" style="max-height:560px; overflow-y:auto;">
                        <div class="text-body-secondary p-3 small">Loading inbound messages…</div>
                    </div>
                </div>
            </div>

            <!-- ── Zello (Phase E) ───────────────────────────────────
                 Inbound Zello TEXT, reply-ably. Each row is tagged
                 transport=zello with its origin (channel, or sender user
                 for a DM). Reply sends to the channel, or DMs the sender
                 back — queued via api/zello-inbox.php → zello_outbox, which
                 the Zello proxy drains. -->
            <div class="card">
                <div class="card-header py-1 small fw-semibold d-flex align-items-center">
                    <i class="bi bi-broadcast me-1 text-warning"></i>Zello
                    <span class="badge bg-warning text-dark ms-2 d-none" id="zelloInboxBadge">0</span>
                    <span class="text-body-secondary fw-normal ms-2">Network radio text</span>
                </div>
                <div class="card-body p-0">
                    <div id="zelloInboxBody" style="max-height:480px; overflow-y:auto;">
                        <div class="text-body-secondary p-3 small">Loading inbound Zello text…</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Live Feed ──────────────────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="feed">
            <div class="d-flex gap-2 mb-2 align-items-center">
                <label class="form-label form-label-sm mb-0">Bridge filter:</label>
                <select class="form-select form-select-sm" id="feedBridgeFilter" style="max-width:240px;">
                    <option value="0">All bridges</option>
                </select>
                <div class="form-check form-switch ms-auto">
                    <input class="form-check-input" type="checkbox" id="feedAutoRefresh" checked>
                    <label class="form-check-label small" for="feedAutoRefresh">Auto-refresh (5s)</label>
                </div>
            </div>
            <div class="card">
                <div class="card-body p-0">
                    <div id="meshFeedBody" style="max-height:540px; overflow-y:auto;">
                        <div class="text-body-secondary p-3 small">No packets yet.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Nodes ──────────────────────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="nodes">
            <div class="d-flex gap-2 mb-2 align-items-center">
                <label class="form-label form-label-sm mb-0">Last heard within:</label>
                <select class="form-select form-select-sm" id="nodesHours" style="max-width:160px;">
                    <option value="1">1 hour</option>
                    <option value="24">24 hours</option>
                    <option value="168" selected>7 days</option>
                    <option value="720">30 days</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" id="btnNodesRefresh"><i class="bi bi-arrow-clockwise"></i></button>
                <span class="ms-auto small text-body-secondary" id="nodesCount"></span>
            </div>
            <div class="card">
                <div class="card-body p-0" id="nodesBody">
                    <div class="text-body-secondary p-3 small">Loading nodes…</div>
                </div>
            </div>
        </div>

        <!-- ── Channels (Phase 39B) ───────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="channels">
            <div class="d-flex gap-2 mb-2 align-items-center">
                <div class="small text-body-secondary">
                    Each channel has a unique encryption key. Bridges only relay traffic for the channels they're assigned.
                    Share a channel key by QR code or by emailing the share URL — the receiving radio gets it via Meshtastic <code>set_channel</code>.
                </div>
                <button class="btn btn-sm btn-success ms-auto" id="btnNewChannel"><i class="bi bi-plus-lg me-1"></i>New Channel</button>
                <button class="btn btn-sm btn-outline-secondary" id="btnChannelsRefresh"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
            <div class="card">
                <div class="card-body p-0" id="channelsBody">
                    <div class="text-body-secondary p-3 small">Loading channels…</div>
                </div>
            </div>
            <div class="card mt-2">
                <div class="card-header py-2 small fw-semibold">Assign a channel to a bridge slot</div>
                <div class="card-body small">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Bridge</label>
                            <select class="form-select form-select-sm" id="assignBridge"><option value="">— choose —</option></select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label form-label-sm">Channel</label>
                            <select class="form-select form-select-sm" id="assignChannel"><option value="">— choose —</option></select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Slot</label>
                            <select class="form-select form-select-sm" id="assignSlot">
                                <option value="0">0 (Primary)</option>
                                <option value="1">1</option><option value="2">2</option><option value="3">3</option>
                                <option value="4">4</option><option value="5">5</option><option value="6">6</option><option value="7">7</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary w-100" id="btnAssignChannel">Apply</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Map (Phase 39E) ────────────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="map">
            <div class="d-flex gap-2 mb-2 align-items-center">
                <label class="form-label form-label-sm mb-0">Window:</label>
                <select class="form-select form-select-sm" id="mapHours" style="max-width:140px;">
                    <option value="1">last 1 hour</option>
                    <option value="24" selected>last 24 hours</option>
                    <option value="168">last 7 days</option>
                    <option value="720">last 30 days</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" id="btnMapRefresh"><i class="bi bi-arrow-clockwise"></i></button>
                <span class="ms-auto small text-body-secondary" id="mapNodeCount"></span>
            </div>
            <div id="meshMap" style="width:100%; height:65vh; border-radius:6px; border:1px solid var(--bs-border-color);"></div>
        </div>

        <!-- ── Setup (Phase 39F + 40) ─────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="setup">
            <div class="card mb-3">
                <div class="card-header py-2 small fw-semibold"><i class="bi bi-1-circle me-1"></i>Connect a new workstation as a bridge</div>
                <div class="card-body small">
                    <p>A "bridge" is any Linux host with one or more LoRa radios plugged in. The bridge daemon (<code>bridge_v2.py</code>) speaks <strong>Meshtastic</strong> and <strong>MeshCore</strong> — pick the firmware your radio runs (or run both if you have two radios attached).</p>

                    <div class="alert alert-info py-2 small">
                        <strong>Which firmware?</strong> Open the radio's USB serial in <code>screen</code> at 115200 baud — Meshtastic logs lines like <code>(I) (modem) ...</code> at startup; MeshCore logs <code>MeshCore vN.NN.N - ...</code>. If you flashed the radio recently, you already know — Heltec V3s ship empty, you have to flash one or the other.
                    </div>

                    <ol class="mb-3">
                        <li>If you don't have a bridge row yet, click <strong>Mint a new bridge</strong> below. Give it a label like <code>eoc-desk</code> and copy the token shown — paste it where the script tells you to.</li>
                        <li>SSH into the bridge host (or run locally if it's this machine).</li>
                        <li>Pick the bridge + protocol + transport below, click <strong>Generate setup script</strong>, copy the output, run it on the bridge host.</li>
                        <li>Edit <code>/etc/ticketscad/meshbridge.env</code> on the bridge host and paste your token where the script flagged it. Then <code>sudo systemctl enable --now meshbridge</code>.</li>
                        <li>The bridge card on <strong>Overview</strong> flips green within ~10s. Assign it a channel under <strong>Channels</strong>.</li>
                    </ol>

                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Bridge</label>
                            <select class="form-select form-select-sm" id="setupBridgeSelect"><option value="">— pick existing —</option></select>
                            <button type="button" class="btn btn-sm btn-link p-0 mt-1" id="btnSetupMintNew"><i class="bi bi-plus-circle me-1"></i>Mint a new bridge</button>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Radio firmware</label>
                            <select class="form-select form-select-sm" id="setupProtocol">
                                <option value="meshtastic">Meshtastic</option>
                                <option value="meshcore">MeshCore</option>
                                <option value="both">Both (two radios on same host)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Transport</label>
                            <select class="form-select form-select-sm" id="setupTransport">
                                <option value="serial">USB Serial (default)</option>
                                <option value="tcp">TCP/IP (Wi-Fi node)</option>
                                <option value="ble">Bluetooth LE (paired)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Transport target</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="setupTarget" placeholder="/dev/ttyUSB0">
                            <div class="form-text small" id="setupTargetHint">Linux: <code>dmesg | grep tty</code> to find it.</div>
                        </div>
                    </div>

                    <div class="row g-2 mt-2 d-none" id="setupSecondPortRow">
                        <div class="col-md-3 offset-md-3">
                            <label class="form-label form-label-sm">Second radio firmware</label>
                            <input type="text" class="form-control form-control-sm" id="setupProtocol2" value="meshcore" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Second transport</label>
                            <select class="form-select form-select-sm" id="setupTransport2">
                                <option value="serial">USB Serial</option>
                                <option value="tcp">TCP/IP</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Second target</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="setupTarget2" placeholder="/dev/ttyUSB1">
                        </div>
                    </div>

                    <div class="mt-3">
                        <button class="btn btn-sm btn-primary" id="btnGenerateSetup"><i class="bi bi-file-earmark-code me-1"></i>Generate setup script</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header py-2 small fw-semibold"><i class="bi bi-info-circle me-1"></i>Connection cheat sheet</div>
                <div class="card-body small">
                    <table class="table table-sm">
                        <thead><tr><th>Transport</th><th>How to configure</th><th>Bridge port spec</th></tr></thead>
                        <tbody>
                            <tr><td><strong>USB Serial</strong></td><td>Plug Heltec V3 into bridge host's USB. Linux: <code>dmesg \| grep ttyUSB</code> for the device path.</td><td><code>/dev/ttyUSB0</code></td></tr>
                            <tr><td><strong>TCP/IP</strong></td><td>Configure the Meshtastic node's Wi-Fi via the phone app or web client. Note the IP it picks up.</td><td><code>tcp:192.168.1.50</code> (default port 4403)</td></tr>
                            <tr><td><strong>Bluetooth LE</strong></td><td>Pair the Heltec to the bridge host: <code>bluetoothctl scan on</code> → pair → trust → connect. Use the friendly name.</td><td><code>ble:Meshtastic_a1b2</code></td></tr>
                            <tr><td><strong>MQTT</strong></td><td>(planned) Subscribe to the public Meshtastic MQTT broker or a private one. Requires a configured Meshtastic node uplinking to the broker.</td><td><em>coming soon</em></td></tr>
                            <tr><td><strong>MeshCore</strong></td><td>USB serial only today. Repeater/Companion modes both speak the same v2 packet protocol the bridge handles.</td><td><code>/dev/ttyUSB1</code></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="setupScriptOut" class="mt-3 d-none">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <strong class="small">Generated setup script</strong>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="btnCopySetup"><i class="bi bi-clipboard me-1"></i>Copy</button>
                </div>
                <pre class="bg-body-tertiary border rounded p-3 small" id="setupScriptBody" style="max-height:400px; overflow:auto;"></pre>
            </div>
        </div>

        <!-- ── Send / Compose ─────────────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="send">
            <div class="card">
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label form-label-sm">Target bridge</label>
                            <select class="form-select form-select-sm" id="sendBridge">
                                <option value="0">Any (first available)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Protocol</label>
                            <select class="form-select form-select-sm" id="sendProtocol">
                                <option value="any">Any</option>
                                <option value="meshtastic">Meshtastic</option>
                                <option value="meshcore">MeshCore</option>
                                <option value="zello">Zello</option>
                            </select>
                        </div>
                        <!-- Phase B: choose the addressing mode for this send. -->
                        <div class="col-md-2">
                            <label class="form-label form-label-sm">Send to</label>
                            <select class="form-select form-select-sm" id="sendMode">
                                <option value="channel">Channel (broadcast)</option>
                                <option value="node">Direct — node ID</option>
                                <option value="unit">Direct — unit / person</option>
                            </select>
                        </div>
                        <!-- Phase 39C + 40: direct-message target node. Free text + datalist autocomplete. -->
                        <div class="col-md-3" id="sendToNodeWrap" style="display:none;">
                            <label class="form-label form-label-sm">To node (DM)
                                <i class="bi bi-info-circle text-body-secondary" title="Type a node ID (!abc12345) or a MeshCore pubkey-prefix, pick from suggestions, or switch to Channel to broadcast."></i>
                            </label>
                            <input type="text" class="form-control form-control-sm font-monospace"
                                   id="sendToNode" list="sendToNodeOptions" placeholder="!abc12345 / pubkey prefix" autocomplete="off">
                            <datalist id="sendToNodeOptions"></datalist>
                            <div class="form-text small" id="sendToNodeHint"></div>
                        </div>
                        <!-- Phase B: direct-message a unit / person, resolved to the
                             transport address via the comm-identifier resolver. -->
                        <div class="col-md-3" id="sendToUnitWrap" style="display:none;">
                            <label class="form-label form-label-sm">To unit / person (DM)
                                <i class="bi bi-info-circle text-body-secondary" title="Resolves the chosen unit/person to their Meshtastic node / MeshCore pubkey-prefix from the roster. Requires a concrete protocol (not Any)."></i>
                            </label>
                            <select class="form-select form-select-sm" id="sendToUnit">
                                <option value="">Loading…</option>
                            </select>
                            <div class="form-text small" id="sendToUnitHint">Pick a unit or person. Set Protocol to Meshtastic or MeshCore.</div>
                        </div>
                        <div class="col-md-2" id="sendChannelSlotWrap">
                            <label class="form-label form-label-sm">Channel</label>
                            <select class="form-select form-select-sm" id="sendChannelSlot">
                                <option value="0">Slot 0 (primary)</option>
                                <option value="1">Slot 1</option><option value="2">Slot 2</option>
                                <option value="3">Slot 3</option><option value="4">Slot 4</option>
                                <option value="5">Slot 5</option><option value="6">Slot 6</option><option value="7">Slot 7</option>
                            </select>
                        </div>
                        <!-- Phase F: Zello uses a NAMED channel string, not a mesh slot.
                             Shown only for a Zello channel broadcast; blank = the
                             configured dispatch channel (the proxy fills it in). -->
                        <div class="col-md-3" id="sendZelloChannelWrap" style="display:none;">
                            <label class="form-label form-label-sm">Zello channel
                                <i class="bi bi-info-circle text-body-secondary" title="The Zello channel name to broadcast on. Leave blank to use the configured dispatch channel."></i>
                            </label>
                            <input type="text" class="form-control form-control-sm" id="sendZelloChannel"
                                   maxlength="100" placeholder="(dispatch channel)" autocomplete="off">
                        </div>
                        <!-- Gap 1 (zello-config-video-brief.md): Speak on channel —
                             synthesise the message to speech (Piper) and key it onto
                             the Zello channel as audio, instead of sending text.
                             Channel-only: Zello voice has no per-user addressing, so
                             this is shown only for a Zello channel broadcast. -->
                        <div class="col-md-3 d-flex align-items-end" id="sendZelloTtsWrap" style="display:none;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="sendZelloTts">
                                <label class="form-check-label small" for="sendZelloTts">
                                    <i class="bi bi-megaphone me-1"></i>Speak on channel (TTS audio)
                                    <i class="bi bi-info-circle text-body-secondary" title="Synthesise this message to speech and key it onto the Zello channel as audio. Requires Piper TTS configured on the proxy host. Channel broadcast only — voice has no per-user addressing."></i>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-sm btn-primary w-100" id="btnSendText"><i class="bi bi-send me-1"></i>Send</button>
                        </div>
                        <div class="col-12">
                            <label class="form-label form-label-sm">Message</label>
                            <input type="text" class="form-control form-control-sm" id="sendText" maxlength="200" placeholder="Type a message to the mesh...">
                            <div class="form-text small">Direct messages use the transport's direct addressing (Meshtastic node / MeshCore pubkey-prefix; Zello user); channel sends go to the chosen mesh slot or Zello channel. A Zello send queues to <code>zello_outbox</code> and the Zello proxy relays it.</div>
                        </div>
                    </div>
                    <hr>
                    <div class="small text-body-secondary">
                        <strong>Outbox queue:</strong> messages wait in <code>mesh_outbox</code> with status <code>queued</code>. Bridges claim them via Bearer-authed <code>poll_outbox</code>; the first bridge that matches (target_bridge_id or target_protocol) wins. Acked status: <code>sent</code> or <code>failed</code> with error text.
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Coverage / Latency ─────────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="coverage">
            <div class="d-flex gap-2 mb-2">
                <label class="form-label form-label-sm mb-0">Window:</label>
                <select class="form-select form-select-sm" id="coverageHours" style="max-width:140px;">
                    <option value="1">last 1 hour</option>
                    <option value="6">last 6 hours</option>
                    <option value="24" selected>last 24 hours</option>
                    <option value="72">last 72 hours</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" id="btnCoverageRefresh">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header py-2 small fw-semibold">Coverage matrix — which bridge heard which source</div>
                        <div class="card-body p-0" id="coverageMatrix">
                            <div class="text-body-secondary p-3 small">Loading...</div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header py-2 small fw-semibold">Latency spread — when multiple bridges heard the same packet</div>
                        <div class="card-body p-0" id="latencyTable">
                            <div class="text-body-secondary p-3 small">Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="small text-body-secondary mt-3 ps-1">
                <strong>How to read it:</strong> Each source node appears as a row. Columns show how many packets each bridge heard from that source plus signal averages. Two bridges hearing the same packet ID = both are within radio range — useful for confirming geographic coverage.
            </div>
        </div>

        <!-- ── Device Config ──────────────────────────────────────── -->
        <div class="tab-pane fade" data-tab-content="config">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header py-2 small fw-semibold">Configure a remote device</div>
                        <div class="card-body">
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Bridge (which radio to configure)</label>
                                <select class="form-select form-select-sm" id="cfgBridge">
                                    <option value="0">— Choose a bridge —</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Set owner / long name</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" id="cfgLongName" maxlength="39" placeholder="e.g. CAD-Dispatch">
                                    <input type="text" class="form-control" id="cfgShortName" maxlength="4" placeholder="4-char">
                                    <button class="btn btn-outline-primary" id="btnCfgSetOwner">Apply</button>
                                </div>
                                <div class="form-text small">Sets the firmware-level node name. Visible to all mesh chat clients.</div>
                            </div>
                            <hr>
                            <div class="mb-2">
                                <label class="form-label form-label-sm">Reboot device</label>
                                <button class="btn btn-sm btn-outline-warning" id="btnCfgReboot">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Queue reboot
                                </button>
                                <div class="form-text small">Reboots the attached radio. Bridge stays running; reconnects within ~30s.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header py-2 small fw-semibold">Notes &amp; limits</div>
                        <div class="card-body small">
                            <ul class="mb-0">
                                <li><strong>Set owner</strong> works on Meshtastic firmware right now. MeshCore companion-mode equivalent is queued for a follow-on.</li>
                                <li><strong>Region / channel</strong> set is planned but needs careful protobuf handling — coming next.</li>
                                <li>All commands flow through <code>mesh_outbox</code>; the bridge polls every 5s. Result rows track success / error per command.</li>
                                <li>Newly-flashed Heltec V3s default to the public LongFast channel and "Meshtastic XXXX" name — use this page to rename in place before deploying.</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </main>
</div>

<!-- Phase 39B: New Channel modal -->
<div class="modal fade" id="newChannelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Create a mesh channel</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label form-label-sm">Name *</label>
                    <input type="text" class="form-control form-control-sm" id="newChName" maxlength="32" placeholder="e.g. EOC-Ops">
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">PSK (base64, optional)</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="newChPsk" placeholder="(leave blank to generate a fresh 32-byte AES-256 key)">
                    <div class="form-text">Paste an existing channel's key to join, or leave blank to mint a new private channel.</div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label form-label-sm">Region</label>
                        <select class="form-select form-select-sm" id="newChRegion">
                            <option>US</option><option>EU_433</option><option>EU_868</option>
                            <option>CN</option><option>JP</option><option>ANZ</option>
                            <option>KR</option><option>TW</option><option>RU</option>
                            <option>IN</option><option>NZ_865</option><option>TH</option>
                            <option>LORA_24</option><option>UA_433</option><option>UA_868</option><option>MY_433</option>
                            <option>MY_919</option><option>SG_923</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label form-label-sm">Modem preset</label>
                        <select class="form-select form-select-sm" id="newChModem">
                            <option>LONG_FAST</option><option>LONG_SLOW</option>
                            <option>VERY_LONG_SLOW</option><option>MEDIUM_SLOW</option>
                            <option>MEDIUM_FAST</option><option>SHORT_SLOW</option>
                            <option>SHORT_FAST</option><option>SHORT_TURBO</option>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label form-label-sm">Notes</label>
                    <input type="text" class="form-control form-control-sm" id="newChNotes" maxlength="200">
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="newChUplink" checked>
                    <label class="form-check-label small" for="newChUplink">Uplink (mesh → us)</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" id="newChDownlink" checked>
                    <label class="form-check-label small" for="newChDownlink">Downlink (us → mesh)</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-success" id="btnCreateChannelSubmit">Create</button>
            </div>
        </div>
    </div>
</div>

<!-- Phase 39B: Share Channel (QR + URL) modal -->
<div class="modal fade" id="shareChannelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Share channel: <span id="shareChName"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 align-items-center">
                    <div class="col-md-5 text-center">
                        <div id="shareQrBox" class="bg-white d-inline-block p-3 rounded"></div>
                        <div class="small text-body-secondary mt-1">Scan with a Meshtastic app (Android/iOS)</div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label form-label-sm">Share URL</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control font-monospace" id="shareUrl" readonly>
                            <button class="btn btn-outline-secondary" id="btnCopyShareUrl"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <label class="form-label form-label-sm">PSK (base64)</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control font-monospace" id="sharePsk" readonly>
                            <button class="btn btn-outline-secondary" id="btnCopySharePsk"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <div class="alert alert-warning small py-2 mb-0">
                            <i class="bi bi-shield-exclamation me-1"></i>
                            Anyone with this PSK can read AND send messages on the channel. Treat it like a password — send only over secure channels (email to a verified contact, in-person QR scan, etc.).
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Phase 39A: Packet detail modal -->
<div class="modal fade" id="packetDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Packet detail <small class="text-body-secondary" id="packetIdLabel"></small></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="packetDetailBody">
                <div class="text-body-secondary small">Loading…</div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Phase C: Reply modal -->
<div class="modal fade" id="replyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Reply to mesh message</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="border rounded p-2 mb-2 small bg-body-tertiary" id="replyContext">
                    <!-- filled by JS: who/where the inbound came from -->
                </div>
                <label class="form-label form-label-sm">Reply</label>
                <input type="text" class="form-control form-control-sm" id="replyText" maxlength="200"
                       placeholder="Type your reply…" autocomplete="off">
                <div class="form-text small" id="replyHint"></div>
                <div class="mt-2 small d-none" id="replyStatusLine"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-primary" id="btnReplySubmit"><i class="bi bi-reply me-1"></i>Send reply</button>
            </div>
        </div>
    </div>
</div>

<!-- Zello reply modal (Phase E) -->
<div class="modal fade" id="zelloReplyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Reply to Zello message</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="border rounded p-2 mb-2 small bg-body-tertiary" id="zelloReplyContext">
                    <!-- filled by JS: who/where the inbound came from -->
                </div>
                <div class="mb-2" id="zelloReplyModeWrap">
                    <label class="form-label form-label-sm">Send to</label>
                    <div class="btn-group btn-group-sm w-100" role="group" id="zelloReplyMode">
                        <input type="radio" class="btn-check" name="zReplyMode" id="zReplyChannel" value="channel" checked>
                        <label class="btn btn-outline-secondary" for="zReplyChannel">Channel</label>
                        <input type="radio" class="btn-check" name="zReplyMode" id="zReplyUser" value="user">
                        <label class="btn btn-outline-secondary" for="zReplyUser">DM sender</label>
                    </div>
                </div>
                <label class="form-label form-label-sm">Reply</label>
                <input type="text" class="form-control form-control-sm" id="zelloReplyText" maxlength="1000"
                       placeholder="Type your reply…" autocomplete="off">
                <div class="form-text small" id="zelloReplyHint"></div>
                <div class="mt-2 small d-none" id="zelloReplyStatusLine"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-warning" id="btnZelloReplySubmit"><i class="bi bi-reply me-1"></i>Send reply</button>
            </div>
        </div>
    </div>
</div>

<!-- Mint token modal -->
<div class="modal fade" id="mintTokenModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">Mint a new bridge token</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label form-label-sm">Bridge</label>
                    <select class="form-select form-select-sm" id="mintBridgeSelect">
                        <option value="0">— New bridge —</option>
                    </select>
                </div>
                <div id="mintNewFields">
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Label *</label>
                        <input type="text" class="form-control form-control-sm" id="mintLabel" placeholder="e.g. eoc-radio-room">
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Host hint (optional)</label>
                        <input type="text" class="form-control form-control-sm" id="mintHost" placeholder="hostname + radio model + location">
                    </div>
                </div>
                <div id="mintResult" class="d-none">
                    <div class="alert alert-info small">
                        <strong>Token (shown ONCE — copy now):</strong>
                        <div class="mesh-token-box mt-1" id="mintTokenOut"></div>
                        <hr>
                        <p class="small mb-0">Put it in <code>/etc/ticketscad/meshbridge.env</code>:</p>
                        <pre class="small mb-0 mt-1">CAD_URL=https://&lt;cad-host&gt;
CAD_TOKEN=&lt;the-token-above&gt;</pre>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-sm btn-success" id="btnMintTokenSubmit">
                    <i class="bi bi-key me-1"></i>Mint
                </button>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?php echo e($csrf); ?>">

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/js/theme-manager.js"></script>
<!-- Phase 39B: QR generator for channel sharing.
     Self-hosted (no CDN dependency for stability + no info leak to a third party).
     Phase 43d fix: the previous URL (qrcode@1.5.3/build/qrcode.min.js) was a 404
     since that path doesn't exist in the published package — the QR render was
     silently broken. Now using qrcode-generator (different API, see mesh-console.js). -->
<script src="assets/vendor/qrcode/qrcode-generator.min.js"></script>
<!-- Phase 39E: Leaflet for node map -->
<script src="assets/vendor/leaflet/leaflet.js"></script>
<script src="assets/js/leaflet-mobile-fit.js?v=<?php echo function_exists("asset_v")?asset_v("assets/js/leaflet-mobile-fit.js"):NEWUI_VERSION; ?>"></script>
<script src="assets/js/mesh-console.js?v=<?php echo NEWUI_VERSION . '-' . @filemtime(__DIR__ . '/assets/js/mesh-console.js'); ?>"></script>
</body>
</html>
