# External API — Integrator Guide

**Audience:** developers at an integrating organization who want to push to or read from a TicketsCAD NewUI v4 instance over HTTPS, without browser sessions and without shell access to the server.
**TicketsCAD-side admin:** Settings → External API Tokens (Phase 94 Stage 6) OR the CLI helper `tools/mint_external_api_token.php`.
**Code reference:** [`api/external/v1/_auth.php`](../api/external/v1/_auth.php), [`api/external/v1/_dispatch.php`](../api/external/v1/_dispatch.php), [`inc/external-auth.php`](../inc/external-auth.php), [`inc/webhooks.php`](../inc/webhooks.php).
**Companion doc:** [WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md) covers the outbound (TicketsCAD → you) side in depth; this document covers the inbound (you → TicketsCAD) side and cross-links the subscription wiring.

---

## 1. Overview

The external API is the **first-class bidirectional integration surface** for TicketsCAD. It is intentionally separate from the browser-facing `/api/*.php` endpoints — those depend on session cookies and CSRF tokens and are hostile to remote callers. The external surface at `/api/external/v1/*` is bearer-token authenticated, JSON-over-HTTPS, and reuses every internal write path so business logic stays in one place.

**What the external API covers:**

- **Inbound:** create, read, update, soft-delete incidents, members (personnel), responders (units), facilities, teams, incident types, action notes, assignments, attachments. One peer endpoint per resource your dispatcher would touch in the UI.
- **Outbound:** every write that lands in the `newui_audit_log` AND whose `(category, activity, target_type)` is in the explicit allowlist fires a webhook to every active subscription whose filters match. Hardened, retry-with-backoff, dead-letter queue, admin replay.

**What the external API does NOT cover:**

- **Real-time audio.** DMR / Zello / mesh voice have their own protocols. The external API is JSON only.
- **Real-time streaming.** No external WebSocket / SSE. Poll `GET /incidents?since=<timestamp>` or subscribe to webhooks.
- **Bulk export.** Use the Import/Export page (see `docs/USER-GUIDE.md`) for "give me every incident from the last year."
- **Admin operations.** RBAC editing, settings management, role grants, password resets, key rotation, audit purge. Operator/admin actions only — too large a blast radius for a token.
- **OAuth / OIDC / JWT.** Bearer tokens are opaque random strings, minted by an admin. No flows.

For the outbound side details (HMAC verification, replay protection, event payload structure), see [WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md). For bulk historical extracts, see the Import/Export page in the dispatcher UI.

---

## 2. Quickstart

Five minutes from "admin minted me a token" to a live POST that shows up in dispatch.

### Step 1 — admin mints your token

Your TicketsCAD admin runs the CLI helper (or uses the Settings UI panel when it ships in Stage 6):

```bash
sudo -u www-data php tools/mint_external_api_token.php \
     --user=integrator-svc \
     --name="Acme Mobile App v1.2" \
     --scopes="incidents:read,incidents:write"
```

The admin sees output ending with a raw token that is shown **once** and never stored in plaintext on the server:

```
 RAW TOKEN (copy now — will never be shown again):

    tcad_p_7HxKqJ4mN9pVbW2yR8sLfTjGdC5eAuZ1
```

The admin sends you that string out-of-band (encrypted email, password manager share, in person — never plaintext chat).

### Step 2 — confirm the token works

```bash
curl -i https://dispatch.example.org/api/external/v1/incidents \
     -H "Authorization: Bearer tcad_p_7HxKqJ4mN9pVbW2yR8sLfTjGdC5eAuZ1"
```

Expected response:

```http
HTTP/1.1 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "api_version": "v1",
  "request_id": "a4f8c2e1b7d63a90",
  "data": {
    "incidents": [],
    "limit": 50,
    "offset": 0
  }
}
```

A `200` with `"ok": true` means token resolved, IP allowed, rate limit OK, scope checked, RBAC checked. You're in.

### Step 3 — create an incident

```bash
curl -i -X POST https://dispatch.example.org/api/external/v1/incidents \
     -H "Authorization: Bearer tcad_p_7HxKqJ4mN9pVbW2yR8sLfTjGdC5eAuZ1" \
     -H "Content-Type: application/json" \
     -d '{
       "in_types_id": 7,
       "scope": "Tree down across Elm St blocking both lanes",
       "severity": 2,
       "street": "412 Elm St",
       "city": "Saint Paul",
       "state": "MN",
       "lat": 44.9537,
       "lng": -93.0900
     }'
```

Expected response:

```http
HTTP/1.1 201 Created
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "api_version": "v1",
  "request_id": "fa19c2e16b407310",
  "data": {
    "id": 1342,
    "incident_number": "2026-001342",
    "patient_count": 0
  }
}
```

### Step 4 — confirm in the UI

Open the dispatcher dashboard. The Active Incidents widget shows your new incident within ~2 seconds (SSE push). Search the audit log (Settings → System Health → Audit Log) for category `external_api` — your token's name appears as the actor.

That's it. You're integrated. The rest of this document is the reference material for everything else you might want to do.

---

## 3. Authentication

### Token format

```
tcad_<env>_<32_random_chars>
```

- `tcad_` — literal prefix; identifies a TicketsCAD token in any log or dump.
- `<env>` — single lowercase letter (`p` for production, `s` for staging, `d` for dev) derived from the `external_api_env_letter` setting on the server. Makes it visually obvious when an integrator copies the wrong token between environments.
- `<32_random_chars>` — base62 encoding of `random_bytes(24)` (~190 bits of entropy).

Example: `tcad_p_7HxKqJ4mN9pVbW2yR8sLfTjGdC5eAuZ1`.

### Sending the token

ALWAYS in the `Authorization` header, NEVER in a query string or POST body. The server explicitly refuses to read tokens from anywhere except the header — this keeps tokens out of Apache access logs.

```http
GET /api/external/v1/incidents HTTP/1.1
Host: dispatch.example.org
Authorization: Bearer tcad_p_7HxKqJ4mN9pVbW2yR8sLfTjGdC5eAuZ1
```

Per RFC 6750 the literal word `Bearer ` (with a trailing space) precedes the token. Case-insensitive on the header name (`Authorization`, `authorization`); case-sensitive on the token value.

### Minting a token (operator-side)

There are two supported ways:

**CLI** (works today):

```bash
sudo -u www-data php tools/mint_external_api_token.php \
     --user=<user_id_or_username> \
     --name="Short label, e.g. Acme iOS v1.4" \
     --scopes="incidents:read,incidents:write" \
     [--description="optional notes"] \
     [--ip-allowlist="10.0.0.0/8,192.168.0.0/16"] \
     [--expires="2027-12-31 23:59:59"] \
     [--rate-limit=1000]
```

**Settings UI** (shipped 2026-06-28): **Settings → External API Tokens → + Mint new token**. Same fields as the CLI form. The raw token appears in a one-time modal with a copy button and a "this will never be shown again" warning; the modal can't be dismissed by clicking the backdrop or pressing Escape, so an inadvertent click can't lose the token.

**Privilege-escalation clamp (2026-06-28 security fix #2):** An admin minting a token can bind it to any `user_id`. A non-admin (any user who has `action.manage_external_api_tokens` but is not `is_admin()`) is clamped:

- Can only bind the token to **their own** `user_id`. Attempting to bind to another user returns `403 Cannot mint a token bound to another user — admin permission required`.
- Cannot grant the wildcard `*` scope. Attempting to do so returns `403 Wildcard scope (*) requires admin permission`.

This prevents the classic "Org Admin mints a `*`-scope token bound to the Super Admin's `user_id` and then calls any endpoint as Super Admin" escalation path.

### Storage on the server

The TicketsCAD database **never stores the raw token**. At mint time:

1. A random 24-byte secret is generated and base62 encoded.
2. `sha256(raw_token)` is computed and stored in `external_api_tokens.token_hash`.
3. The first 14 characters of the raw token (e.g. `tcad_p_7HxKqJ4`) are stored in `external_api_tokens.token_prefix` so admins can identify a token in the UI without ever seeing the full secret.
4. The raw token is returned to the admin once, then thrown away.

This means: if you lose the token, no one can recover it for you. The admin revokes the old one and mints a new one.

### Revocation procedure

Operator-side. Soft-delete by setting `revoked_at` and `revoked_reason`. Once revoked, the token returns `401 token_revoked` on every subsequent call. Revoked rows are kept indefinitely for the audit trail (the token's prior activity stays linked to its row by `token_id`).

CLI revoke (alternative to the Settings UI — useful for emergency ops without browser access):

```bash
sudo -u www-data php tools/revoke_external_api_token.php --id=<token_id> --reason="Rotated 2026-06-28"
# or by visible 14-char prefix (when --id isn't to hand)
sudo -u www-data php tools/revoke_external_api_token.php --prefix=tcad_p_7HxKqJ4 --reason="Suspected leak"
```

Or directly via SQL during incident response:

```sql
UPDATE external_api_tokens
SET revoked_at = NOW(), revoked_reason = 'Suspected leak — a beta tester G'
WHERE id = <token_id>;
```

The next authenticated request with the revoked token returns `401 token_revoked` immediately — no caching, no propagation delay.

---

## 4. Scopes

Scope codes are short strings that describe what category of action a token may perform. **Scopes LIMIT the token; RBAC GRANTS the capability.** A token with `*` (superuser scope) on a Read-Only user can read everything but cannot write anything — because the user's role doesn't hold the underlying write permissions.

### Scope catalogue

| Scope                       | Allows                                                                      |
|-----------------------------|-----------------------------------------------------------------------------|
| `incidents:read`            | `GET /incidents`, `GET /incidents/<id>`                                     |
| `incidents:write`           | `POST /incidents`, `PATCH /incidents/<id>`, `DELETE /incidents/<id>`, plus nested `/actions`, `/assignments`, `/attachments` |
| `members:read`              | `GET /members`, `GET /members/<id>`                                         |
| `members:write`             | `POST /members`, `PATCH /members/<id>`, `DELETE /members/<id>`, `/status`   |
| `responders:read`           | `GET /responders`, `GET /responders/<id>`                                   |
| `responders:write`          | `POST /responders`, `PATCH /responders/<id>`, `DELETE /responders/<id>`, `/status` |
| `facilities:read`           | `GET /facilities`, `GET /facilities/<id>`                                   |
| `facilities:write`          | `POST /facilities`, `PATCH /facilities/<id>`, `DELETE /facilities/<id>`     |
| `teams:read`                | `GET /teams`, `GET /teams/<id>`                                             |
| `teams:write`               | `POST /teams`, `PATCH /teams/<id>`, `DELETE /teams/<id>`                    |
| `incident_types:read`       | `GET /incident-types`, `GET /incident-types/<id>`                           |
| `incident_types:write`      | `POST /incident-types`, `PATCH /incident-types/<id>`, `DELETE /incident-types/<id>` |
| `attachments:read`          | direct attachment endpoints (also implicitly granted by parent resource's `:write`) |
| `attachments:write`         | direct attachment endpoints (also implicitly granted by parent resource's `:write`) |

### Wildcard semantics

| Wildcard           | Satisfies                                                                |
|--------------------|--------------------------------------------------------------------------|
| `*`                | every scope (superuser — still bounded by the bound user's RBAC)         |
| `*:read`           | every `<resource>:read` scope                                            |
| `<resource>` or `<resource>:*` | both `<resource>:read` and `<resource>:write` (e.g. `incidents` satisfies `incidents:write`) |

Matching is performed in `ext_api_require_scope()` (see [`inc/external-auth.php`](../inc/external-auth.php)). A scope check fails with `403 forbidden_scope` and includes the required scope plus the token's current scope list in the response, so integrators can debug without admin help.

### The "scope LIMITS, RBAC GRANTS" model

Two layers, both must pass:

1. **Scope** — declared by the admin at mint time. A coarse-grained "what kinds of things may this token attempt." Cheap to check, controllable at the token level. A token scoped `incidents:read` cannot make a write attempt regardless of what RBAC would allow.
2. **RBAC** — the bound user's role permissions. The fine-grained "is this user actually allowed to do this." A token scoped `*` (everything) bound to a Read-Only user can read everything but writes fail with `403 forbidden_rbac`.

This is intentional. Scopes give admins a quick way to constrain a token's blast radius without granting or rescinding role permissions on the underlying user. If you need a token to do something its scope blocks, ask the admin to re-mint with the right scopes. If you need it to do something the user's role blocks, that's a role-permissions decision, not a token decision.

---

## 5. URL structure

Two forms are supported. The clean-URL form is the documented preferred path; the direct-file form is the fallback for installs without Apache `mod_rewrite`.

### Clean URL (preferred)

```
/api/external/v1/<resource>[/<id>[/<sub_resource>[/<sub_id>]]]
```

The `.htaccess` at `api/external/v1/.htaccess` rewrites any path under `/api/external/v1/` to `_dispatch.php`, which parses the path segments, populates `$_GET` with the right IDs, and includes the matching handler file.

Examples:

| Method | URL                                              | Handler invoked              |
|--------|--------------------------------------------------|------------------------------|
| GET    | `/api/external/v1/incidents`                     | `incidents.php` (list)       |
| GET    | `/api/external/v1/incidents/42`                  | `incidents.php` (detail)     |
| POST   | `/api/external/v1/incidents`                     | `incidents.php` (create)     |
| PATCH  | `/api/external/v1/incidents/42`                  | `incidents.php`              |
| DELETE | `/api/external/v1/incidents/42`                  | `incidents.php`              |
| POST   | `/api/external/v1/incidents/42/actions`          | `incident-actions.php`       |
| POST   | `/api/external/v1/incidents/42/assignments`      | `assignments.php`            |
| PATCH  | `/api/external/v1/incidents/42/assignments/7`    | `assignments.php`            |
| DELETE | `/api/external/v1/incidents/42/assignments/7`    | `assignments.php`            |
| POST   | `/api/external/v1/incidents/42/attachments`      | `attachments.php`            |
| GET    | `/api/external/v1/members`                       | `members.php`                |
| POST   | `/api/external/v1/members`                       | `members.php`                |
| PATCH  | `/api/external/v1/members/142/status`            | `member-status.php`          |
| GET    | `/api/external/v1/responders`                    | `responders.php`             |
| PATCH  | `/api/external/v1/responders/77/status`          | `responder-status.php`       |
| GET    | `/api/external/v1/facilities`                    | `facilities.php`             |
| GET    | `/api/external/v1/teams`                         | `teams.php`                  |
| GET    | `/api/external/v1/incident-types`                | `incident-types.php`         |

### Direct file (fallback)

If your operator's install does not have `mod_rewrite` enabled (rare but real on some shared-hosting setups), you can hit the handler files directly with `.php` URLs:

| Clean URL                                       | Direct-file equivalent                                                     |
|-------------------------------------------------|----------------------------------------------------------------------------|
| `/api/external/v1/incidents`                    | `/api/external/v1/incidents.php`                                           |
| `/api/external/v1/incidents/42`                 | `/api/external/v1/incidents.php?id=42`                                     |
| `/api/external/v1/incidents/42/actions`         | `/api/external/v1/incident-actions.php` (body: `{"ticket_id":42, ...}`)    |
| `/api/external/v1/incidents/42/assignments`     | `/api/external/v1/assignments.php` (body: `{"ticket_id":42, ...}`)         |
| `/api/external/v1/incidents/42/assignments/7`   | `/api/external/v1/assignments.php?assign_id=7` (body: `{"assign_id":7,...}`) |
| `/api/external/v1/members/142`                  | `/api/external/v1/members.php?id=142`                                      |
| `/api/external/v1/members/142/status`           | `/api/external/v1/member-status.php?member_id=142`                         |
| `/api/external/v1/responders/77/status`         | `/api/external/v1/responder-status.php?responder_id=77`                    |

The handlers don't care how `$_GET` got populated — `_dispatch.php` injects the same key names that the direct-file form expects. Pick the form that works on your operator's install.

### API discovery stub

Hit the root with no path (and no auth):

```bash
curl https://dispatch.example.org/api/external/v1/
```

Returns the contract version + resource list without requiring a token:

```json
{
  "ok": true,
  "api_version": "v1",
  "resources": ["incidents", "members", "responders", "facilities", "teams", "incident-types", "attachments"],
  "docs": "/documentation/?doc=EXTERNAL-API.md"
}
```

Useful as a liveness check from a monitoring system.

---

## 6. Endpoints

Each subsection lists the method, path, scope required, RBAC permission required, request body shape, response shape, and status codes that endpoint can return. Endpoints that aren't yet shipped at the time of this writing are marked `Status: PENDING (Phase 94 Stage 4X)` — the URL and contract are stable per the plan but the handler isn't on disk yet, so calls land on `_dispatch.php`'s `501 endpoint_not_implemented` response.

### 6.1 Incidents

**Status:** SHIPPED (Stage 4a).

#### List incidents

```
GET /api/external/v1/incidents
```

| Param          | Type   | Default | Notes                                              |
|----------------|--------|---------|----------------------------------------------------|
| `limit`        | int    | 50      | 1–200                                              |
| `offset`       | int    | 0       | for pagination                                     |
| `status`       | int    | (all)   | filter by ticket.status                            |
| `since`        | string | (none)  | `YYYY-MM-DD HH:MM:SS` — return rows updated on/after |

- Scope: `incidents:read`
- RBAC: `action.view_incident` OR `action.view_incidents`
- Returns: `{ "incidents": [...], "limit": N, "offset": N }`

#### Detail

```
GET /api/external/v1/incidents/<id>
```

- Scope: `incidents:read`
- RBAC: `action.view_incident` OR `action.view_incidents`
- Returns: full ticket row including `in_type_name`
- Statuses: `200`, `400 invalid_id`, `404 not_found`

#### Create

```
POST /api/external/v1/incidents
Content-Type: application/json

{
  "in_types_id": 7,
  "scope": "Free-text dispatcher summary",
  "severity": 2,
  "street": "412 Elm St",
  "city": "Saint Paul",
  "state": "MN",
  "lat": 44.9537,
  "lng": -93.0900,
  "contact": "Caller name",
  "phone": "+15551234567"
}
```

- Scope: `incidents:write`
- RBAC: `action.create_incident`
- Returns: `201 { "id": 1342, "incident_number": "2026-001342", "patient_count": 0 }`
- Side effects: audit row (`category=incident`, `activity=create`); SSE `incident:new` event; webhook `incident.created` event (via audit-driven fan-out)
- Statuses: `201`, `400 invalid_json_body`, `403 forbidden_scope` / `forbidden_rbac`, `422 validation_failed` (response includes a `details.errors` array)

#### Update

```
PATCH /api/external/v1/incidents/<id>
```

**Status:** PENDING (Phase 94 Stage 4a follow-up). Returns `405 method_not_allowed` today.

#### Soft-delete

```
DELETE /api/external/v1/incidents/<id>
```

**Status:** PENDING (Phase 94 Stage 4a follow-up). Returns `405 method_not_allowed` today. When shipped, will route through `incident_soft_delete()` (wastebasket) and fire `incident.deleted` webhook.

#### Add action note

```
POST /api/external/v1/incidents/<id>/actions
Content-Type: application/json

{ "ticket_id": 42, "note": "Free-text activity log entry" }
```

(Direct-file form is canonical; the clean-URL form populates `ticket_id` from the URL.)

- Scope: `incidents:write`
- RBAC: `action.edit_incident`
- Returns: `201 { "id": <action_id>, "ticket_id": <id> }`
- Side effects: audit row (`incident|note_add|action`); SSE `incident:note`; webhook `incident.note_added`
- Statuses: `201`, `400 invalid_ticket_id`/`invalid_json_body`, `404 not_found`, `422 validation_failed`, `405 method_not_allowed`

### 6.2 Assignments (responder ↔ incident)

**Status:** SHIPPED (Stage 4c).

Single endpoint with three verbs. Scope: `incidents:write`; RBAC: `action.assign_unit` (for all three).

#### Assign

```
POST /api/external/v1/incidents/<ticket_id>/assignments
Content-Type: application/json

{ "ticket_id": 42, "responder_id": 77, "role": "primary" }
```

- Returns: `201 { "id": <assign_id>, "ticket_id": 42, "responder_id": 77 }`
- Side effects: audit `incident|assign|assigns`; webhook `assign.created`; SSE `responder:assign`

#### Update status

```
PATCH /api/external/v1/incidents/<ticket_id>/assignments/<assign_id>
Content-Type: application/json

{ "assign_id": 7, "new_status_id": 3 }
```

OR equivalently:

```
{ "assign_id": 7, "new_status": "on_scene" }
```

Accepted `new_status` strings: `responding`, `on_scene`, `clear`.

- Returns: `200 { "assign_id": 7, "status": "on_scene" }`
- Side effects: audit `incident|update|responder`; webhook `responder.status_changed`
- Statuses: `200`, `400 invalid_assign_id`/`missing_status`, `404 not_found`, `422 validation_failed`

#### Unassign

```
DELETE /api/external/v1/incidents/<ticket_id>/assignments/<assign_id>
```

`assign_id` may also be passed in the JSON body for clients that can't send DELETE bodies.

- Returns: `200 { "assign_id": 7, "unassigned": true }`
- Side effects: audit `incident|unassign|assigns`; webhook `assign.removed`
- Statuses: `200`, `400 invalid_assign_id`, `404 not_found`, `422 validation_failed`

### 6.3 Members (personnel)

**Status:** SHIPPED (Stage 4d), partial — POST + GET + DELETE; PATCH pending.

#### List

```
GET /api/external/v1/members
```

| Param    | Type   | Default | Notes                                          |
|----------|--------|---------|------------------------------------------------|
| `limit`  | int    | 50      | 1–200                                          |
| `offset` | int    | 0       |                                                |
| `search` | string | (none)  | matches first_name/last_name/callsign/email    |

- Scope: `members:read`
- RBAC: `action.view_members` OR `action.manage_members`
- Returns: `{ "members": [...], "limit": N, "offset": N }`
- Soft-deleted members (`deleted_at` non-null) are excluded.

#### Detail

```
GET /api/external/v1/members/<id>
```

Returns the full row joined with `member_types`, `member_status`, `teams`. Statuses: `200`, `400 invalid_id`, `404 not_found`.

#### Create

```
POST /api/external/v1/members
Content-Type: application/json

{
  "first_name": "Jane",
  "last_name": "Doe",
  "callsign": "K0ABC",
  "email": "jane.doe@example.org",
  "phone_cell": "+15552223333",
  "member_type_id": 2
}
```

- Scope: `members:write`
- RBAC: `action.manage_members`
- Returns: `201 { "id": <member_id> }`
- Side effects: audit `personnel|create|member`; webhook `member.created`

#### Update (PATCH)

```
PATCH /api/external/v1/members/<id>
```

**Status:** PENDING (Stage 4d follow-up). The internal members.php's partial-update path is being factored out; not yet in `inc/member-write.php`.

#### Soft-delete

```
DELETE /api/external/v1/members?id=<id>
```

Or via clean URL: `DELETE /api/external/v1/members/<id>`.

- Scope: `members:write`
- RBAC: `action.manage_members`
- Returns: `200 { "deleted": true }`
- Side effects: audit `personnel|delete|member`; webhook `member.deleted`

#### Status change

```
PATCH /api/external/v1/members/<id>/status
```

**Status:** PENDING (Phase 94 Stage 4e). Handler file `member-status.php` not on disk yet — calls return `501 endpoint_not_implemented`.

### 6.4 Responders (units)

**Status:** PENDING (Phase 94 Stage 4b). Handler file `responders.php` not on disk yet — `GET/POST/PATCH/DELETE /api/external/v1/responders[...]` return `501 endpoint_not_implemented`.

Planned shape per `plan.md` §4:

| Method | Path                                       | Scope             | RBAC                    | Internal helper             |
|--------|--------------------------------------------|-------------------|-------------------------|------------------------------|
| GET    | `/api/external/v1/responders`              | `responders:read` | `action.view_responders` | `api/responders.php`         |
| GET    | `/api/external/v1/responders/<id>`         | `responders:read` | `action.view_responders` | `api/responder-detail.php`   |
| POST   | `/api/external/v1/responders`              | `responders:write`| `action.manage_responders` | `inc/responder-write.php` `responder_upsert()` |
| PATCH  | `/api/external/v1/responders/<id>`         | `responders:write`| `action.manage_responders` | same                         |
| DELETE | `/api/external/v1/responders/<id>`         | `responders:write`| `action.manage_responders` | `inc/responder-write.php` `responder_soft_delete()` |
| PATCH  | `/api/external/v1/responders/<id>/status`  | `responders:write`| `action.set_responder_status` | `inc/responder-write.php` `responder_set_status()` |

When shipped, will fire `responder.created` / `responder.updated` / `responder.deleted` / `responder.status_changed` webhooks per the audit map.

### 6.5 Facilities

**Status:** PENDING (Phase 94 Stage 4f). Handler file `facilities.php` not on disk yet.

Planned shape:

| Method | Path                                | Scope              | RBAC                      |
|--------|-------------------------------------|--------------------|---------------------------|
| GET    | `/api/external/v1/facilities`       | `facilities:read`  | `action.view_facilities`  |
| GET    | `/api/external/v1/facilities/<id>`  | `facilities:read`  | `action.view_facilities`  |
| POST   | `/api/external/v1/facilities`       | `facilities:write` | `action.manage_facilities` |
| PATCH  | `/api/external/v1/facilities/<id>`  | `facilities:write` | `action.manage_facilities` |
| DELETE | `/api/external/v1/facilities/<id>`  | `facilities:write` | `action.manage_facilities` |

Webhook events: `facility.created`, `facility.updated`, `facility.deleted`.

### 6.6 Teams

**Status:** PENDING (Phase 94 Stage 4g). Handler file `teams.php` not on disk yet.

Planned shape:

| Method | Path                              | Scope         | RBAC                |
|--------|-----------------------------------|---------------|---------------------|
| GET    | `/api/external/v1/teams`          | `teams:read`  | `action.view_teams` |
| GET    | `/api/external/v1/teams/<id>`     | `teams:read`  | `action.view_teams` |
| POST   | `/api/external/v1/teams`          | `teams:write` | `action.manage_teams` |
| PATCH  | `/api/external/v1/teams/<id>`     | `teams:write` | `action.manage_teams` |
| DELETE | `/api/external/v1/teams/<id>`     | `teams:write` | `action.manage_teams` |

Webhook events: `team.created`, `team.updated`, `team.deleted`.

### 6.7 Incident types

**Status:** PENDING (Phase 94 Stage 4i). Handler file `incident-types.php` not on disk yet.

Planned shape:

| Method | Path                                       | Scope                   | RBAC                  |
|--------|--------------------------------------------|-------------------------|-----------------------|
| GET    | `/api/external/v1/incident-types`          | `incident_types:read`   | `action.view_config`  |
| GET    | `/api/external/v1/incident-types/<id>`     | `incident_types:read`   | `action.view_config`  |
| POST   | `/api/external/v1/incident-types`          | `incident_types:write`  | `action.manage_config` |
| PATCH  | `/api/external/v1/incident-types/<id>`     | `incident_types:write`  | `action.manage_config` |
| DELETE | `/api/external/v1/incident-types/<id>`     | `incident_types:write`  | `action.manage_config` |

Webhook events: `incident_type.created`, `incident_type.updated`, `incident_type.deleted`.

### 6.8 Attachments

**Status:** PENDING (Phase 94 Stage 4h). Handler file `attachments.php` not on disk yet.

Planned shape (multipart for uploads, JSON for list/delete):

| Method | Path                                                     | Scope                                  |
|--------|----------------------------------------------------------|----------------------------------------|
| GET    | `/api/external/v1/incidents/<id>/attachments`            | `incidents:read` (parent perm)         |
| POST   | `/api/external/v1/incidents/<id>/attachments` (multipart)| `incidents:write` (parent perm)        |
| DELETE | `/api/external/v1/attachments/<file_id>`                 | `attachments:write`                    |

Also supported via parent: `/api/external/v1/members/<id>/attachments`, `/api/external/v1/facilities/<id>/attachments`. Upload size capped by the `external_api_max_upload_bytes` setting (default 10 MB; see appendix).

Webhook events: `attachment.created`, `attachment.deleted`.

---

## 7. Response envelope

Every external endpoint returns the same envelope shape — successful and error responses alike. This is contract; integrators can rely on it across resources and across versions of v1.

### Success

```json
{
  "ok": true,
  "api_version": "v1",
  "request_id": "fa19c2e16b407310",
  "data": { /* resource payload */ }
}
```

### Error

```json
{
  "ok": false,
  "api_version": "v1",
  "request_id": "fa19c2e16b407310",
  "error": "forbidden_scope",
  "required": "incidents:write",
  "token_scopes": ["incidents:read"]
}
```

### Envelope fields

| Field          | Always | Type    | Notes                                                      |
|----------------|--------|---------|------------------------------------------------------------|
| `ok`           | yes    | bool    | `true` on 2xx, `false` on every error response             |
| `api_version`  | yes    | string  | Always `"v1"` for this contract                            |
| `request_id`   | yes*   | string  | 16-hex-char identifier (`bin2hex(random_bytes(8))`); correlates with the audit-log row's `details.request_id` and any webhook delivery fired by this request |
| `data`         | on success | object/array | Resource payload                                       |
| `error`        | on error | string  | Machine-readable error code (see §8)                       |
| Extra keys     | on error | varies  | Per-error context, e.g. `required`, `retry_after`, `errors[]` |

\* `request_id` is null only when the request errored before `_auth.php` finished (e.g. dispatcher rejected the URL before auth ran).

### Correlating with the audit log

Every external API request that successfully hydrates a session writes one or more rows to `newui_audit_log` with `category='external_api'` (for reads) or the resource's natural category (for writes, e.g. `category='incident'`). The `details` JSON on each row includes the `token_id` and — if the audit-driven path fires — the `request_id`. When filing a support ticket with your operator, include the `request_id` from your response; the operator can grep their audit log for it and reconstruct exactly what happened.

---

## 8. Errors

Every error code TicketsCAD can return from an external endpoint, with the HTTP status, when it fires, and what to do about it.

### Auth flow errors

| Code               | HTTP | Fires when                                                                | Remediation                                                        |
|--------------------|------|---------------------------------------------------------------------------|--------------------------------------------------------------------|
| `https_required`   | 426  | TLS not negotiated and `external_api_require_tls=1`                       | Use `https://` URL. Default is on; dev environments can opt out.   |
| `missing_token`    | 401  | No `Authorization: Bearer ...` header on the request                      | Add the header. Tokens are never read from query strings or body.  |
| `invalid_token`    | 401  | Bearer didn't match any row in `external_api_tokens` (or wrong format)    | Confirm you copied the token correctly. Ask admin to verify it exists. |
| `token_revoked`    | 401  | Token row has `revoked_at` set                                            | Ask admin to mint a replacement. See §11.                          |
| `token_expired`    | 401  | Token row has `expires_at` in the past (UTC)                              | Ask admin to extend or re-mint.                                    |
| `ip_not_allowed`   | 403  | Client IP doesn't match any CIDR in token's `ip_allowlist_json`           | Ask admin to add your egress IP to the allowlist, or call from an allowlisted IP. |
| `rate_limited`     | 429  | Per-token sliding-window count exceeds `rate_limit_per_hour`              | Wait — response includes `Retry-After` header in seconds. See §9.  |

### Authorization errors

| Code               | HTTP | Fires when                                                                | Remediation                                                        |
|--------------------|------|---------------------------------------------------------------------------|--------------------------------------------------------------------|
| `forbidden_scope`  | 403  | Token's scope list doesn't include the scope required by this endpoint    | Response includes `required` and `token_scopes`. Ask admin to re-mint with broader scopes. |
| `forbidden_rbac`   | 403  | Token's bound user's RBAC role doesn't grant the action                   | Response includes `required` permission code. Ask admin to either change the role or bind a different user to a fresh token. |

### Request-validation errors

| Code                  | HTTP | Fires when                                                              | Remediation                                                |
|-----------------------|------|-------------------------------------------------------------------------|------------------------------------------------------------|
| `method_not_allowed`  | 405  | Endpoint exists but HTTP method isn't supported                         | Response's `allowed` field lists the supported verbs.      |
| `invalid_json_body`   | 400  | `Content-Type: application/json` body couldn't be decoded               | Validate your JSON syntax. Empty body on a verb requiring one. |
| `invalid_id`          | 400  | Path or query `id` is not a positive integer                            | Pass numeric IDs.                                          |
| `invalid_ticket_id`   | 400  | Body's `ticket_id` missing or non-positive                              | Required for nested incident endpoints.                    |
| `invalid_assign_id`   | 400  | Body's `assign_id` missing or non-positive                              | Required for assignment PATCH/DELETE.                      |
| `invalid_responder_id`| 400  | Body's `responder_id` missing or non-positive                           | Required for `POST /assignments`.                          |
| `missing_status`      | 400  | `PATCH /assignments` without `new_status` or `new_status_id`            | Send one or the other.                                     |
| `validation_failed`   | 422  | Body decoded but failed field-level validation                          | Response's `errors` array lists per-field issues from the internal write helper. |
| `not_found`           | 404  | Referenced resource doesn't exist (e.g. `ticket_id` for action note)    | Verify the parent resource exists. Per security rule 27 we don't disclose existence to callers without read access — `not_found` may also mean "exists but you can't see it". |

### Route + server errors

| Code                       | HTTP | Fires when                                                              | Remediation                                                |
|----------------------------|------|-------------------------------------------------------------------------|------------------------------------------------------------|
| `route_not_found`          | 404  | Path doesn't match the dispatcher's marker (`/api/external/v1/`)        | Check your URL.                                            |
| `unknown_resource`         | 404  | Resource name (`/api/external/v1/<X>`) isn't in the route table         | See §5 for the canonical resource list.                    |
| `unknown_subresource`      | 404  | Sub-resource (`/incidents/42/<sub>`) isn't recognized                   | Valid: `actions`, `assignments`, `attachments`.            |
| `endpoint_not_implemented` | 501  | Handler file isn't on disk yet (a PENDING endpoint per §6)              | Wait for the relevant Stage to ship. Response includes the missing handler filename. |
| `auth_not_resolved`        | 500  | Scope check called before `_auth.php` ran (developer bug, shouldn't reach a client) | If you see this, file a bug — implies a handler forgot to `require_once '_auth.php'`. |
| `auth_user_missing`        | 500  | Token resolved but bound user couldn't be loaded from the `user` table  | Operator-side: bound user was deleted. Admin needs to re-mint the token against a live user. |
| `db_error`                 | 500  | Database call threw an exception                                        | Response includes the exception message in `message`. Forward to operator with the `request_id`. |

### Error response shape examples

```json
{
  "ok": false,
  "api_version": "v1",
  "request_id": "a4f8c2e1b7d63a90",
  "error": "forbidden_scope",
  "required": "incidents:write",
  "token_scopes": ["incidents:read", "members:read"]
}
```

```json
{
  "ok": false,
  "api_version": "v1",
  "request_id": "a4f8c2e1b7d63a90",
  "error": "rate_limited",
  "retry_after": 142
}
```

```json
{
  "ok": false,
  "api_version": "v1",
  "request_id": "a4f8c2e1b7d63a90",
  "error": "validation_failed",
  "errors": [
    "in_types_id is required",
    "lat must be a valid decimal"
  ]
}
```

---

## 9. Rate limits

### Defaults

| Limit                              | Setting key                            | Default       |
|------------------------------------|----------------------------------------|---------------|
| Per-token, requests per hour       | `external_api_default_rate_limit`      | 1000          |
| Per-IP, requests per hour          | `external_api_per_ip_rate_limit`       | 5000          |
| Per-token override                 | `external_api_tokens.rate_limit_per_hour` (column) | — (admin sets at mint time) |

The per-token cap is enforced via a sliding-window counter (`external_api_rate_limits` table, one row per token per minute, summed over the last 60 minutes). The per-IP cap acts as a circuit breaker against compromised tokens routing through a single egress IP.

### The 429 response

```http
HTTP/1.1 429 Too Many Requests
Content-Type: application/json; charset=utf-8
Retry-After: 142

{
  "ok": false,
  "api_version": "v1",
  "request_id": "a4f8c2e1b7d63a90",
  "error": "rate_limited",
  "retry_after": 142
}
```

The `Retry-After` header value (in seconds) matches `data.retry_after` in the body. It's an *approximate* hint — the time until the oldest minute-bucket in your current sliding window falls out. Treat it as "back off at least this long," not "after exactly this many seconds you'll succeed."

### Recommended client behavior

- On `429`, sleep for `Retry-After` seconds, then retry the request. Don't retry faster than that — TicketsCAD will keep returning `429` until the window clears.
- Don't burst-then-pause. If you have N events to push per hour, spread them evenly. The sliding window forgives short bursts but penalizes sustained ones near the ceiling.
- For batch loads (importing a backlog), ask your operator to temporarily raise the per-token `rate_limit_per_hour` rather than fighting the limit. Admin can update the column without re-minting:

  ```sql
  UPDATE external_api_tokens SET rate_limit_per_hour = 5000 WHERE id = <id>;
  ```

### Monitoring your usage

Two paths:

1. **In your own code**, count successful responses per minute and warn yourself when you're approaching your cap.
2. **Ask your operator** to read your token's recent activity from the admin UI (Settings → External API Tokens → click your token → Audit, when Stage 6 ships) or via SQL:

   ```sql
   SELECT bucket_min, count
   FROM external_api_rate_limits
   WHERE token_id = <your_token_id>
     AND bucket_min >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
   ORDER BY bucket_min DESC;
   ```

There is no per-request header announcing "you have N remaining this hour." The audit log is the source of truth; the response envelope keeps no rate-limit state.

---

## 10. Webhook subscriptions (cross-link)

This section is a short orientation; for full HMAC-verification reference code, replay protection patterns, and the receiver-side troubleshooting guide, read **[WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md)** end-to-end.

### How the fan-out works

Phase 94 Stage 5 made webhook firing **audit-driven**. Every successful `audit_log()` write inside TicketsCAD checks an explicit allowlist mapping (`_audit_to_webhook_event()` in [`inc/webhooks.php`](../inc/webhooks.php)) of `(category, activity, target_type)` tuples → webhook event types. If the tuple is in the map, `webhook_fire()` is called automatically, which:

1. Loads every active row from `webhook_subscriptions`.
2. For each, checks if its `event_filters_json` matches the fired event type (supports exact match, `*`, and prefix-wildcard `incident.*`).
3. Builds the JSON body, computes `hash_hmac('sha256', $body, $hmac_secret)`, fires HTTP POST with header `X-Webhook-Signature: sha256=<hash>`.
4. Logs the delivery to `webhook_deliveries`.

Failed deliveries are retried by `tools/webhook_retry_tick.php` on a per-minute systemd timer with exponential backoff (`POW(2, attempt) * 30 seconds`) up to the subscription's `max_attempts` (default 5). After that, the delivery transitions to `status='dead_letter'` and surfaces in the admin Recent Deliveries widget for manual replay.

The "audit-driven" choice is the reliability fix for the original "webhooks don't fire reliably" report. Previously, individual endpoints had to remember to call `webhook_fire()` directly and many didn't. Now every write that lands in the audit log AND maps to an event automatically fires — single source of reliability.

### Event-type allowlist

These are the only event types TicketsCAD can fire (the canonical list, from [`inc/webhooks.php`](../inc/webhooks.php) `_audit_to_webhook_event()`):

**Incidents:**

| Event                       | Audit tuple                                   |
|-----------------------------|-----------------------------------------------|
| `incident.created`          | `incident` / `create` / `ticket`              |
| `incident.updated`          | `incident` / `update` / `ticket`              |
| `incident.deleted`          | `incident` / `delete` / `ticket`              |
| `incident.closed`           | `incident` / `close` / `ticket`               |
| `incident.reopened`         | `incident` / `reopen` / `ticket`              |
| `incident.note_added`       | `incident` / `note_add` / `action`            |

**Assignments:**

| Event                       | Audit tuple                                   |
|-----------------------------|-----------------------------------------------|
| `assign.created`            | `incident` / `assign` / `assigns`             |
| `assign.removed`            | `incident` / `unassign` / `assigns`           |
| `responder.status_changed`  | `incident` / `update` / `responder` (via assignments) OR `asset` / `status_change` / `responder` |

**Personnel:**

| Event                          | Audit tuple                                                |
|--------------------------------|------------------------------------------------------------|
| `member.created`               | `personnel` / `create` / `member`                          |
| `member.updated`               | `personnel` / `update` / `member`                          |
| `member.deleted`               | `personnel` / `delete` / `member`                          |
| `member.location_updated`      | `personnel` / `location_update` / `location_reports`       |

**Assets (responders, facilities, teams):**

| Event                       | Audit tuple                                   |
|-----------------------------|-----------------------------------------------|
| `responder.created`         | `asset` / `create` / `responder`              |
| `responder.updated`         | `asset` / `update` / `responder`              |
| `responder.deleted`         | `asset` / `delete` / `responder`              |
| `facility.created`          | `asset` / `create` / `facility`               |
| `facility.updated`          | `asset` / `update` / `facility`               |
| `facility.deleted`          | `asset` / `delete` / `facility`               |
| `team.created`              | `asset` / `create` / `team`                   |
| `team.updated`              | `asset` / `update` / `team`                   |
| `team.deleted`              | `asset` / `delete` / `team`                   |

**Configuration:**

| Event                          | Audit tuple                              |
|--------------------------------|------------------------------------------|
| `incident_type.created`        | `config` / `create` / `incident_type`    |
| `incident_type.updated`        | `config` / `update` / `incident_type`    |
| `incident_type.deleted`        | `config` / `delete` / `incident_type`    |

**Legacy-category aliases (2026-06-28 reliability fix):** Several internal endpoints emit non-canonical audit categories (e.g. `api/responder-save.php` emits `incident|create|responder` instead of canonical `asset|create|responder`). The map in `inc/webhooks.php` includes ALIAS entries so UI-driven state changes fire the same webhook event types as the external API path:

| Internal endpoint emits             | Fires event              |
|-------------------------------------|--------------------------|
| `incident` / `create` / `responder` | `responder.created`      |
| `incident` / `update` / `responder` | `responder.status_changed` (note 1) |
| `incident` / `delete` / `responder` | `responder.deleted`      |
| `config` / `create` / `facility`    | `facility.created`       |
| `config` / `update` / `facility`    | `facility.updated`       |
| `config` / `delete` / `facility`    | `facility.deleted`       |
| `personnel` / `update` / `team`     | `team.updated`           |
| `personnel` / `delete` / `team`     | `team.deleted`           |
| `data` / `upload` / `file`          | `attachment.created`     |
| `incident` / `assign` / `responder` | `assign.created`         |
| `incident` / `unassign` / `responder` | `assign.removed`       |

Note 1: `incident|update|responder` is fired by TWO internal paths (the responder-edit update AND `setResponderStatus()` from the assignments flow). With one map slot per tuple the safer pick is `responder.status_changed`. Subscribers needing distinct `responder.updated` events should use the external API's `PATCH /responders/<id>` path or wait for the internal-endpoint refactor that's queued in `specs/handoff.md`.

**Attachments:**

| Event                       | Audit tuple                       |
|-----------------------------|-----------------------------------|
| `attachment.created`        | `data` / `create` / `file`        |
| `attachment.deleted`        | `data` / `delete` / `file`        |

Any audit row whose tuple is **not** in this map fires no webhook. This is by design (see plan §7 Decision #4): admin / config / security audit rows can't leak to external subscribers even if a future feature adds them, because they require an explicit one-line addition here before fan-out.

### Subscribing

Admins create subscriptions in **Settings → Integrations → Webhooks**. Each gets a name, target URL, HMAC secret, event filters (one or more entries from the table above, plus `*` and `<prefix>.*` wildcards), and an optional retry policy.

**HMAC secret behavior (2026-06-28 security fix #6 — capture-once flow):**

- On **CREATE**: if the admin leaves the secret field blank, the server auto-generates a strong 32-byte hex secret. The full secret appears in the save-response ONCE — admins must copy it then. This is the only time the full secret is disclosed.
- On **EDIT**: the detail GET response returns `secret=null` and `secret_prefix=<first 8 chars>` so admins can visually confirm "this is the same secret I captured at create time" without re-disclosing it. The edit form shows a placeholder `leave blank to keep (current starts: abc12345…)`. Submitting an UPDATE with the secret field blank means "keep current"; submitting with a NEW value rotates it. The UPDATE response NEVER discloses the secret, even when it was just rotated.

This means the legacy "open existing webhook → see full secret in edit form" path no longer works. It was always a confused-deputy risk; the explicit capture-once flow is the same model used by Stripe, GitHub, and other major webhook platforms.

**SSRF guard on target URL (2026-06-28 security fix #4):** Outbound deliveries refuse to POST to URLs that resolve to loopback, link-local (`169.254/16` including AWS/GCP/Azure metadata endpoints), RFC1918 (`10/8`, `172.16/12`, `192.168/16`), IPv6 ULA / link-local, or non-`http(s)` schemes (`file://`, `gopher://`, `dict://`, `ftp://`). Failed deliveries show `target URL rejected by SSRF guard` in the Recent Deliveries widget.

Installs that legitimately need to webhook into a private host (e.g. an internal mid-tier service on the LAN) can opt-in via the `webhook_url_allowlist` setting — newline-separated list of hostname suffixes:

```
internal.example.com
mid-tier.lan
```

A URL is allowed if its hostname exactly equals OR is a subdomain of any listed suffix.

### Signature verification (summary)

TicketsCAD signs each delivery with `hash_hmac('sha256', $body, $hmac_secret)` and sends it in the `X-Webhook-Signature: sha256=<hex>` header. On the receiver side:

```php
$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';   // "sha256=abc123..."
$expected = 'sha256=' . hash_hmac('sha256', $body, $YOUR_HMAC_SECRET);
if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    exit('bad signature');
}
```

Read [WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md) for Python/Node/Go equivalents, dead-letter replay UX, and timestamp-replay-protection patterns.

---

## 11. Token rotation playbook

Plan for periodic token rotation — quarterly at minimum, immediately on suspected leak. The operator-side procedure:

### Step 1 — admin mints the replacement

```bash
sudo -u www-data php tools/mint_external_api_token.php \
     --user=integrator-svc \
     --name="Acme Mobile App v1.2 (rotated 2026-Q3)" \
     --scopes="incidents:read,incidents:write"
```

The new token has a fresh random suffix and its own row in `external_api_tokens`. The old token is **not touched** yet — both are valid in parallel during the rotation window.

### Step 2 — admin sends the new token to the integrator

Out-of-band — encrypted email, password manager share, in person. Never plaintext chat. Include the token name + creation timestamp so the integrator can confirm they're swapping the right one.

### Step 3 — integrator switches

Integrator updates their configuration to use the new token. They confirm by making one call with the new token and verifying the response. They retain the old token in a hot-rollback location (e.g., previous-config file) in case the new one is mis-configured.

### Step 4 — admin revokes the old

After the integrator confirms the new token works AND a reasonable observation window has passed (an hour for low-volume integrators, a day for high-volume), the admin revokes the old:

```sql
UPDATE external_api_tokens
SET revoked_at = NOW(),
    revoked_by = <admin_user_id>,
    revoked_reason = 'Rotated 2026-Q3 — superseded by token #<new_id>'
WHERE id = <old_token_id>;
```

The next request using the old token returns `401 token_revoked`. The integrator's hot-rollback file is now useless (intentionally) — they're committed to the new token.

### Step 5 — the revoked row stays forever

Revoked tokens are not deleted. Their `id` is referenced by every `newui_audit_log` row from their active period. Deleting the row would orphan the audit history. The admin UI's token list filters revoked tokens out of the default view but they remain queryable for forensics.

### Emergency rotation (suspected leak)

Skip the parallel-validity window. Mint the new token, send it to the integrator on the fastest secure channel, revoke the old immediately. The integrator's calls will fail with `401 token_revoked` until they swap. Accept the short outage — the cost of a leaked token in active use is much higher.

---

## 12. Security considerations

### What we DO protect

- **HTTPS-only** in production. `external_api_require_tls=1` (default) rejects non-TLS connections with `426 https_required`. Bearer tokens are credentials and travel only over encrypted transport.
- **Tokens stored as SHA-256 hashes**. The raw token is shown to the admin once at mint time and never persists to disk anywhere on the server.
- **IP allowlist (optional per token)**. CIDR ranges in `external_api_tokens.ip_allowlist_json`. Empty = no restriction.
- **Audit attribution**. Every successful authenticated call writes a row to `newui_audit_log` with the token's name in `user_name`, the source IP in `ip_address`, the `token_id` in `details`. Admin can reconstruct what every token did and when.
- **No tokens in URLs**. `ext_api_extract_bearer()` reads only the `Authorization` header — never query string, never POST body. Keeps tokens out of Apache access logs.
- **No tokens in error messages**. Even on `invalid_token`, the response never echoes the failed bearer back.
- **No DB error leakage** (2026-06-28 security fix #1). `ext_api_db_error()` / `ext_api_internal_error()` log raw exceptions (with `request_id` correlation) to PHP's error log and return a scrubbed `{ok:false, error:'db_error', stage:<tag>, request_id}` envelope. Old code path that returned `$e->getMessage()` (leaking SQLSTATE, table names, column names) is replaced across all 44 catch blocks in `api/external/v1/*.php`.
- **Token mint privilege-escalation clamp** (2026-06-28 fix #2). Non-admin can only bind tokens to their own `user_id` and cannot grant `*` scope. See §3 Authentication for the failure-mode response shape.
- **Rate limiter fails CLOSED** (2026-06-28 fix #3). If the rate-limit lookup throws (DB pool exhausted, lock contention, table missing), the request is treated as if it exceeded the limit — `429`. The previous fail-open meant an attacker who could flood the DB enough could disable the limiter entirely.
- **SSRF guard on webhook target URLs** (2026-06-28 fix #4). See §10 above for the full rule set + the `webhook_url_allowlist` opt-in for legitimate internal destinations.
- **Webhook HMAC secret masked after creation** (2026-06-28 fix #6). Detail GET returns `secret_prefix` only; full secret is never re-disclosed via the admin UI after capture. See §10.
- **Security headers** (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, HSTS in HTTPS deployments) set on every external response via `set_security_headers()`.
- **No CSRF needed because bearer IS the auth**. The external endpoints explicitly ignore session cookies. Even a request bearing a valid TicketsCAD session cookie that lacks an `Authorization` header is rejected with `401 missing_token`. This is correct only because no cookie path can ever authenticate an external request; treat it as immutable.

### What we DON'T protect

- **No JWT signing or claims**. Tokens are opaque random strings. There's no "user_id encoded in the token" — the binding lives in the DB row.
- **No OAuth flows**. No PKCE, no refresh tokens, no user-on-behalf-of. If you need delegated user auth, that's a Phase 95+ topic.
- **No automatic key rotation**. Tokens don't expire unless `expires_at` was set explicitly at mint time. Operator-side policy is to rotate quarterly; the system doesn't enforce.
- **No request signing**. The bearer header is the only credential — request bodies are NOT signed. A man-in-the-middle who has the bearer can replay or modify any request. HTTPS prevents this in transit; that's the only line of defense.
- **No SSRF allowlist enforcement on the SUBSCRIBER side.** TicketsCAD's outbound SSRF guard (fix #4 above) keeps OUR outbound deliveries off internal IPs. If the subscriber's domain or DNS is compromised, the public DNS will resolve to whatever the attacker chose — TicketsCAD has no way to know. Subscribers should pin TLS certs / use HSTS / monitor DNS for their own webhook endpoints. The per-subscription `ip_allowlist_json` column in `webhook_subscriptions` is in the schema but not enforced in the delivery path today; that's a subscriber-side policy (allowlisting which OUR-egress IPs they accept) that's the subscriber's call to enforce.

### Token-leak response procedure

If your token leaks (logged accidentally, committed to a repo, screenshot in a Slack channel):

1. **Tell your operator immediately.** Don't wait to confirm a leak — assume the worst.
2. **Operator revokes the token** (see §11 emergency rotation).
3. **Operator audits recent activity** — `newui_audit_log WHERE details->>'$.token_id' = <leaked_id>` for the relevant time window. Look for IPs, paths, and bodies that don't match your integrator's normal pattern.
4. **Operator mints replacement** and sends to you on a known-clean channel.
5. **You scrub the leaked surface** — rewrite Git history (`git filter-repo`), purge log files, rotate any credentials the leaked token may have indirectly compromised.
6. **Update integrator-side runbooks** so the leak path doesn't reopen.

---

## 13. Versioning

The `v1` in the URL is the API contract version. Within `v1`:

- New endpoints may be added without notice.
- New optional request fields may be added without notice.
- New response fields may be added without notice — your client must tolerate unknown keys.
- Existing endpoint URLs, scope codes, error codes, response envelope shape, and HTTP method semantics are **stable** within `v1`. Removing or changing any of these means cutting `v2`.

When `v2` ships (no concrete plans as of this writing), it will live at `/api/external/v2/*` and `v1` will continue to serve clients indefinitely — versions are additive, never breaking. The `api_version` field in every response envelope tells you which contract version you're talking to.

If you depend on a behavior, exercise it in tests. If it changes within v1, that's a bug; file it.

---

## 14. Appendix — settings reference

The following rows in the `settings` table govern external API behavior. They're seeded by `sql/run_phase94_external_api.php`. Operator can change them via Settings → System Health → Advanced Settings or by `UPDATE settings SET value = '...' WHERE name = '...'`.

| Setting key                       | Default     | Type | Controls                                                                                  |
|-----------------------------------|-------------|------|-------------------------------------------------------------------------------------------|
| `external_api_require_tls`        | `1`         | bool | Reject non-TLS connections to `/api/external/v1/*` with `426 https_required`. Set to `0` only in dev. |
| `external_api_env_letter`         | `p`         | char | Single lowercase letter embedded in minted token strings (`tcad_<letter>_<random>`). Customarily `p`=production, `s`=staging, `d`=dev. |
| `external_api_default_rate_limit` | `1000`      | int  | Default per-token rate ceiling (requests per hour) applied to newly minted tokens whose `rate_limit_per_hour` isn't overridden. Existing tokens use the value stored in their own row. |
| `external_api_per_ip_rate_limit`  | `5000`      | int  | Per-IP rate ceiling (requests per hour) across all tokens. Acts as a circuit breaker against a single compromised egress IP. |
| `external_api_max_upload_bytes`   | `10485760`  | int  | Maximum attachment upload size in bytes (default 10 MB). Apache's `upload_max_filesize` and `post_max_size` still apply on top; the handler hard-caps at 100 MB even if this setting is mis-edited upward. |

Per-token overrides live on the `external_api_tokens` row itself (`rate_limit_per_hour` column, `ip_allowlist_json` column, `expires_at` column) and take precedence over the global defaults where they overlap.

### Related schema

| Table                       | Created by                                  | Purpose                                                    |
|-----------------------------|---------------------------------------------|------------------------------------------------------------|
| `external_api_tokens`       | `sql/run_phase94_external_api.php` §1.1     | Token metadata (hash, scopes, allowlist, binding user)     |
| `external_api_rate_limits`  | `sql/run_phase94_external_api.php` §1.2     | Sliding-window counters per token per minute               |
| `webhook_subscriptions`     | `sql/run_phase94_external_api.php` §1.3     | Outbound subscriptions (replaces legacy `webhooks` table)  |
| `webhook_deliveries`        | extended in §1.5                            | Per-attempt delivery log incl. `dead_letter` status        |

The legacy `webhooks` table is kept in place during the rollout window and read-migrated by `sql/run_phase94_external_api.php`; Stage 6 issues `DROP TABLE webhooks` once the new admin UI is verified.

### Related RBAC permissions

| Permission                          | Granted to                | Gates                                          |
|-------------------------------------|---------------------------|------------------------------------------------|
| `action.manage_external_api_tokens` | Super Admin, Org Admin    | Settings → External API Tokens panel (mint, list, revoke) |
| `action.manage_webhooks`            | Super Admin, Org Admin    | Webhook subscription management + replay action |

End-user RBAC permissions (`action.create_incident`, `action.assign_unit`, `action.manage_members`, etc.) are enforced as the second layer after scope (see §4); they're documented in [RBAC-GUIDE.md](RBAC-GUIDE.md).
