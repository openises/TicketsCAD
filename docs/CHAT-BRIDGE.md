# Chat Bridge

Forward local-chat room messages out to Slack, Telegram, Email, or Mesh
Radio, live, as they're sent.

## What it is

Settings → Chat Settings → Cross-platform Bridges has four toggles:

- Bridge → Slack
- Bridge → Telegram
- Bridge → Email
- Bridge → Mesh radio

Each toggle controls whether **public, room-broadcast local chat
messages** are forwarded to that external channel. Turning a toggle on
creates (or re-enables) a routing rule; turning it off disables that
rule without deleting it, so any customization you've made to it (see
"Customizing a bridge" below) survives being switched off and back on.

## Direct messages are never bridged

This is not configurable, and there is no setting that turns it off.

A **direct/private message** — a chat message addressed to a specific
person rather than a room — is never forwarded to Slack, Telegram, Email,
Mesh Radio, or any other destination by the routing engine, regardless of
what filters a routing rule (including one of these four) is configured
to match. This is enforced inside the routing engine itself
(`inc/router.php`'s `router_evaluate()`), not by a filter on the rule, so
it cannot be edited away from the Message Routing admin screen or by
editing the rule's JSON directly.

This applies to **every** local-chat-sourced routing rule on your
install, not just the four managed by these checkboxes — including any
rule you build yourself in Message Routing with "Local Chat" as the
source channel. If you build a custom local-chat rule of your own, it
inherits the same protection automatically.

## What "bridged" actually forwards

Only messages sent to a chat room (the default "general" channel, an
incident channel, a team channel, etc.) are eligible. A message is
identified as a room message, not a direct message, the same way the
chat widget itself decides where to deliver it in real time: if it's
addressed to a specific user, it's a DM and is excluded; anything
else — including the default "all" / room broadcast — is public room
traffic and can be bridged.

## Email is a live forward, not a digest

The "Bridge → Email" toggle sends each chat message as its own email as
soon as it's sent. There is currently no digest/batching mode (no
interval configuration, no recipient list separate from the destination
mailbox, no scheduled summary job) — the label used to say "Bridge →
Email (digest)" but that described behavior that didn't exist. If you
want a periodic digest instead of one email per message, that's a
different feature that hasn't been built yet.

## Customizing a bridge

Each toggle owns exactly one routing rule under **Settings → Message
Routing**, named "Chat Bridge → \<destination\>" with source channel
`local_chat`. You can open that rule and add your own filters (keywords,
incident type, severity, exclude-keywords) or a transform (prefix text,
priority override) the same way you would for any other routing rule —
see the [Routing engine reference](ROUTING-ENGINE-REFERENCE.md). The
direct-message exclusion above still applies underneath whatever filters
you add; there's no filter that can widen it back to include DMs.

If you'd rather build your own rule from scratch instead of using one of
these four (for example, to bridge only messages in one specific
incident's chat room), you can — just leave the corresponding checkbox
here off and create the rule yourself in Message Routing with `local_chat`
as the source. It gets the same DM protection either way.

## Upgrading from an install where a checkbox was already checked

If you'd already turned one of these toggles on before this feature
existed (they saved to the database but did nothing), the next time
migrations run (`php sql/run_migrations.php`, or automatically via your
normal update process) picks up whatever was already checked and creates
the corresponding routing rule, enabled. If you didn't want that behavior
active, uncheck the box in Settings → Chat Settings.

## Related

- [Routing engine reference](ROUTING-ENGINE-REFERENCE.md) — full rule
  schema, filter types, loop prevention
- [Message routing guide](MESSAGE-ROUTING-GUIDE.md) — day-to-day admin
  workflow for the Message Routing screen
