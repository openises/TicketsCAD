# Primary / Responsible Unit (Phase 151, GH#138)

Follow-on to GH#16, built for rjonesbsink's TicketsRMS integration, which
needs to know which unit owns an incident's reporting. Full design history:
`specs/phase-151-primary-responsible-unit/{spec.md,plan.md,tasks.md}`.

## What it is

An incident can have one designated **primary unit** — the unit accountable
for the call and its after-action reporting. It is stored on the incident
itself (`ticket.primary_responder_id`), not on the assignment record, so it
stays visible even after that unit clears or the incident closes.

**Off by default on every install, fresh or upgraded.** If your agency
doesn't use this concept, nothing changes for you.

## Enabling it

Settings → Incident Lifecycle → Primary / Responsible Unit:

- **Off** (default) — the feature is completely inert. No UI, no webhook.
- **Manual** — a dispatcher can star a unit on the incident's Assign tab,
  or use the "Primary: [change]" picker above the assigned-units table.
- **Automatic** — everything Manual does, plus: when an incident has
  exactly one assigned unit and no primary is yet set, that unit is
  automatically marked primary. Adding a second unit afterward does not
  change it. This only ever fires once per incident, from zero to one.

## What persists, and what doesn't

- The primary designation **survives a normal clear**. A unit finishing its
  call and going back in service does not lose the designation — the whole
  point is that it still shows who was responsible after the fact.
- **Unassigning a unit outright** (the "added in error" Remove action, not a
  normal status-driven clear) DOES clear the designation if that unit was
  the primary — a unit no longer associated with the incident at all can't
  remain its accountable unit.
- A unit can be marked primary even after it has cleared (a dispatcher
  correcting the record after the fact) — the picker offers every unit that
  has ever been assigned to the incident, active or cleared.

## Permissions

One permission, `action.set_primary_unit`, granted by default to Super
Admin, Org Admin, and Dispatcher. There is no separate "supervisor only"
tier — an install that wants this restricted can revoke it from Dispatcher
in Settings → Roles & Permissions like any other permission.

## Integrations

- **Webhook**: `incident.primary_changed` — see
  `WEBHOOKS-INTEGRATOR-GUIDE.md`'s Incidents table for the payload shape.
  Has its own subscription checkbox in Settings → Webhooks / Events; it is
  not bundled under "All Events" implicitly-only — subscribe to it explicitly.
- **External API**: `GET /api/external/v1/incidents/<id>` returns
  `primary_responder_id`/`primary_responder_name`/`primary_set_at`/
  `primary_set_by` on every incident. `PATCH` accepts `primary_responder_id`
  as a dedicated write — see `EXTERNAL-API.md`'s Update section.
