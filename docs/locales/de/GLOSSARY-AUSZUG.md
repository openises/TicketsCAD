# Glossar — Auszug (deutsch)

> **Übersetzungsstatus:** Erste Übersetzung der wichtigsten Begriffe, 2026-06-15. Das vollständige Glossar ist [auf Englisch verfügbar](../../GLOSSARY.md) und enthält 90+ Begriffe; hier sind nur die ~30 wichtigsten übersetzt.

Begriffe in alphabetischer Reihenfolge nach dem englischen Original. Die deutsche Bezeichnung steht jeweils direkt darunter, gefolgt von der Definition.

---

### Account
**Deutsch:** Benutzerkonto
Ein Benutzerdatensatz in der `user`-Tabelle — Zugangsdaten, Rollenzuweisung, Profileinstellungen. Im Unterschied zu einem [Member](#member) (Personaleintrag); ein Account ist *einem Member zugeordnet* über `user.member_id`.

### Allocates
**Deutsch:** Sichtbarkeitstabelle "allocates"
Die Legacy-Tabelle aus v3, die festlegt, welche [Gruppen](../../GLOSSARY.md#group) welche Ressourcen (Einsatz, Einheit, Einrichtung) sehen dürfen. Wird in v4 weiterhin als Fallback verwendet, wenn RBAC keine explizite Erlaubnis erteilt.

### Assignment
**Deutsch:** Zuweisung
Eine Zeile in der `assigns`-Tabelle, die eine [Einheit](#responder) mit einem [Einsatz](#incident) verknüpft.

### Audit log
**Deutsch:** Audit-Protokoll
Die `audit_log`-Tabelle — jede zustandsändernde Aktion erzeugt eine Zeile. Kategorien: `auth` (Authentifizierung), `data` (Daten), `admin` (Administration), `comms` (Kommunikation), `security` (Sicherheit).

### Broker
**Deutsch:** Nachrichten-Broker / Vermittlungsschicht
Die einheitliche Nachrichten-Fanout-Ebene, die Nachrichten an passende Kanal-Adapter (Chat, SMTP, Slack, Twilio, Meshtastic, DMR, Zello) verteilt.

### Channel
**Deutsch:** Kanal
Eine logische Nachrichten-Bus-Kennzeichnung (`general`, `dispatch`, `incident-NN`, usw.), über die der [Broker](#broker) routet.

### CJIS
**Deutsch:** CJIS-Sicherheitsrichtlinie (US-amerikanische FBI-Polizeidatenrichtlinie)
Im deutschsprachigen Raum nicht direkt relevant, aber als Sicherheits-Bezugsrahmen nutzbar.

### Clock-in
**Deutsch:** Anmelden zum Dienst / Einrücken
Eine Aktion, mit der ein Mitglied sich als "im Dienst" markiert. Erzeugt automatisch eine [Personal Resource](#personal-resource).

### Dispatch
**Deutsch:** Disposition / Alarmierung
Das Senden einer Einheit zu einem Einsatz.

### DMR
**Deutsch:** Digital Mobile Radio (digitaler Sprechfunkstandard nach ETSI)
TicketsCAD bindet DMR über die [DVSwitch](#dvswitch)-Suite an.

### DVSwitch
**Deutsch:** DVSwitch-Brücken-Stack
Eine Software-Suite (Analog_Bridge, MMDVM_Bridge, md380-emu), die digitale Funkprotokolle anbindet.

### Geofence
**Deutsch:** Geo-Zaun / Alarmbereich
Ein Polygon oder Kreis auf der Karte mit einer zugeordneten Alarmrichtlinie. Wenn eine Einheit die Grenze überquert, wird eine Benachrichtigung ausgelöst.

### Grant
**Deutsch:** Berechtigungszuweisung
Ein Eintrag in `user_roles`, der einem Benutzer eine [Rolle](#role) zuweist. Kann ein `expires_at`-Datum für zeitlich begrenzte Delegation haben.

### Incident
**Deutsch:** Einsatz
Der kanonische Name für das, was disponiert wird. In der Datenbank in der Tabelle `ticket` gespeichert (historisch bedingt). **Im Benutzertext "Einsatz" verwenden.**

### Mayday
**Deutsch:** Mayday / Notfall
Notstatus. Eine Einheit, die Mayday meldet, löst einen globalen Alarm + Broadcast-Nachricht aus.

### Member
**Deutsch:** Mitglied (Person)
Ein Datensatz in der `member`-Tabelle — eine Person auf der Personalliste. Kann ein verknüpftes [Benutzerkonto](#account) haben, muss aber nicht. Unterschieden von einer [Einheit](#responder) (das disponierbare Objekt).

### PAR (Personnel Accountability Report)
**Deutsch:** Personalkontroll-Bericht / Sicherheitscheck
Ein periodischer Check-in-Zyklus für zugewiesene Einheiten. Disponent (oder ein automatischer Scheduler) initiiert; jede zugewiesene Einheit muss innerhalb des Fensters bestätigen.

### Permission
**Deutsch:** Berechtigung
Ein Berechtigungscode (z. B. `screen.dashboard`, `action.create_incident`). Insgesamt 65. An [Rollen](#role) vergeben, die wiederum Benutzern zugewiesen werden.

### Personal resource
**Deutsch:** Persönliche Ressource
Eine automatisch erzeugte [Einheit](#responder), wenn sich ein [Mitglied](#member) zum Dienst anmeldet ([clock-in](#clock-in)).

### RBAC
**Deutsch:** Rollenbasierte Zugriffssteuerung
Die Phase-11/12-Neugestaltung, die das alte `user.level` (Ganzzahl) durch eine Rollen + Berechtigungs-Matrix ersetzt hat. 6 Standardrollen, 65 Berechtigungen.

### Responder
**Deutsch:** Einheit (disponierbar)
Ein Datensatz in der `responder`-Tabelle — eine disponierbare Ressource. Löschfahrzeuge, Rettungswagen, Streifenwagen, persönliche Ressourcen.

### Role
**Deutsch:** Rolle
Eine benannte Bündelung von [Berechtigungen](#permission). Sechs sind vorinstalliert: Super-Admin, Org-Admin, Disponent, Operator, Read-Only, Field Unit.

### Routing engine
**Deutsch:** Routing-Engine / Vermittlungs-Engine
Wertet [Routes](../../GLOSSARY.md#route) gegen ein- und ausgehende Nachrichten aus und leitet Treffer an Ziel-Kanäle weiter. Schleifenschutz über `_is_routed_forward` + `_route_depth`.

### Signal
**Deutsch:** Funksignal / Code
Kurzer Code (z. B. "10-4", "Signal 7") mit fester Bedeutung. Im legacy v3 "Hint" genannt.

### SSE (Server-Sent Events)
**Deutsch:** Server-Sent Events (Server-gesendete Ereignisse)
Unidirektionales Server→Client-Streaming-Protokoll für Echtzeit-Aktualisierungen.

### Status
**Deutsch:** Status
Der operative Zustand einer [Einheit](#responder). Konfigurierbar pro Installation. Beispiele: Verfügbar, Anfahrt, Vor Ort, Frei, Außer Dienst.

### TFA / 2FA / TOTP
**Deutsch:** Zwei-Faktor-Authentifizierung
TicketsCAD verwendet RFC-6238 TOTP (30-Sekunden-Fenster, 6-stelliger Code) plus 8 einmalig nutzbare [Backup-Codes](../../GLOSSARY.md#backup-code).

### Ticket
**Deutsch:** Ticket (Legacy-Begriff für [Einsatz](#incident))
Die Tabelle heißt weiterhin `ticket`; im Benutzertext "Einsatz" verwenden.

### Unit
**Deutsch:** Einheit (synonym mit [Responder](#responder))
Gleiche Tabelle, gleiche Zeile.

### User
**Deutsch:** Benutzer
Ein Datensatz in der `user`-Tabelle — Zugangsdaten, Rollenzuweisung, Profileinstellungen. Verknüpft mit einem [Mitglied](#member) über `user.member_id`.

---

## Weitere Begriffe

Für die vollständigen 90+ Begriffe (Webhooks, Meshtastic, OwnTracks, APRS, Vosk, Piper, etc.) bitte das [englische Original-Glossar](../../GLOSSARY.md) konsultieren.

Mitwirkende: bitte erweitern Sie diese Datei, wenn Sie einen häufig nachgefragten Begriff übersetzen können. Konsistenz mit den anderen deutschen Dokumenten beibehalten — zum Beispiel "Einsatz" überall, nicht abwechselnd "Vorfall" / "Einsatz" / "Tickets".
