# TicketsCAD NewUI — Dokumentation

**Version:** 4.0-dev &nbsp;·&nbsp; **Lizenz:** GPL-2.0 &nbsp;·&nbsp; **In-App-Hilfe:** [`help.php`](../../../help.php)

> **Übersetzungsstatus:** Erste Übersetzung 2026-06-15. Wenn ein Link auf ein englisches Dokument verweist, wurde dieses noch nicht übersetzt — bitte das englische Original lesen.

> **Neu hier?** Wählen Sie eine Zeile unten. Jede Seite sagt oben, für wen sie gedacht ist.
>
> | Wenn Sie… sind | Beginnen Sie mit |
> |---|---|
> | **Disponent / Einsatzkraft** (Sie nutzen das System) | [Benutzerhandbuch (en)](../../NEWUI-USER-GUIDE.md) · [FAQ (en)](../../FAQ.md) · [Glossar (Auszug)](GLOSSARY-AUSZUG.md) |
> | **Systemadministrator** (Sie installieren oder warten das System) | [Installationscheckliste (en)](../../INSTALLATION-CHECKLIST.md) · [Wartungshandbuch (en)](../../MAINTENANCE-RUNBOOK.md) · [Fehlerbehebung (en)](../../TROUBLESHOOTING.md) |
> | **Entwickler / Integrator** (Sie schreiben Code, der mit TicketsCAD kommuniziert) | [Architekturübersicht (en)](../../INDEX.md#explanation--how-and-why) · [Routing-Engine (en)](../../ROUTING-ENGINE-REFERENCE.md) · [Webhooks (en)](../../WEBHOOKS-INTEGRATOR-GUIDE.md) |
> | **Migration von v3.44** (Sie haben eine Legacy-Installation) | [Upgrade von V3 (en)](../../UPGRADING-FROM-V3.md) · [Begriffs-Übersetzungstabelle (en)](../../LEGACY-TO-NEWUI-TERMS.md) |
> | **Sicherheits- / Compliance-Prüfer** | [Sicherheitsrichtlinie (en)](../../SECURITY-POLICY.md) · [CJIS-Status (en)](../../CJIS-POSTURE.md) · [Audit-Protokoll-Referenz (en)](../../AUDIT-LOG-REFERENCE.md) |

---

## Aufbau dieser Dokumentation

Wir folgen dem [Diátaxis](https://diataxis.fr)-Rahmenwerk: vier Arten von Dokumentation, je mit unterschiedlicher Funktion.

| Art | Zweck | Beispiele |
|---|---|---|
| **Tutorials** | Praktisch lernen | Schnellstart, Schulungssequenz |
| **Anleitungen** | Konkretes Problem lösen | "Installation auf einem frischen Debian-System", "DMR einrichten" |
| **Referenz** | Faktum nachschlagen | Glossar, RBAC-Berechtigungsliste |
| **Erklärung** | Hintergründe verstehen | Architekturübersicht, Konzepte |

---

## Tutorials — das System lernen

(Die Lerninhalte sind derzeit nur auf Englisch verfügbar. Eine Übersetzung der Schulungsmodule ist in Planung.)

- [Schnellstart (en)](../../../quick-start.php) — Anmeldung, Profil, erster Einsatz
- [Schulungslehrplan (en)](../../TRAINING-CURRICULUM.md) — 24 Module
- Modul 1: Willkommen (en)
- Modul 2: Anmeldung + 2FA (en)
- Modul 3: Einsatz erstellen (en)
- Modul 4: Einheiten disponieren (en)

---

## Anleitungen — ein Problem lösen

### Installation und Konfiguration

- **[Installationscheckliste (en)](../../INSTALLATION-CHECKLIST.md)** — leere Debian/Ubuntu-VM → funktionierender Disponentenarbeitsplatz in 60 Minuten
- [Upgrade von v3.44 (en)](../../UPGRADING-FROM-V3.md) — Migrationsprozedur Legacy → v4
- [Installations- + Admin-Handbuch (en)](../../INSTALL.md)

### Integrationen und Feldhardware

- [DVSwitch DMR-Brücke (en)](../../DVSWITCH-ADMIN-GUIDE.md) — Analog_Bridge + MMDVM_Bridge + md380-emu + Piper + Vosk
- [Meshtastic-Brücke (en)](../../MESH-BRIDGE-GUIDE.md) — LoRa-Mesh-Dienst
- [APRS-IS Listener (en)](../../APRS-LISTENER-SETUP.md) — Python-Dienst für APRS-IS
- [OwnTracks-Einrichtung (en)](../../OWNTRACKS-CONFIG-PUSH.md) — HTTP-direkt mit Member-Tokens

### Laufender Betrieb

- [Wartungshandbuch (en)](../../MAINTENANCE-RUNBOOK.md) — täglich / wöchentlich / monatlich
- [Backup + Wiederherstellung (en)](../../BACKUP-RECOVERY-RUNBOOK.md)
- [Fehlerbehebung (en)](../../TROUBLESHOOTING.md) — Symptom → Ursache → Lösung

### Personal, Berechtigungen, Dienstplan

- [RBAC-Übersicht (en)](../../RBAC-GUIDE.md) — Rollen, Berechtigungen
- [Dienstplan (en)](../../scheduling.md) — Schichten und Veranstaltungen

---

## Referenz — etwas nachschlagen

### Konzepte und Terminologie

- **[Glossar (Auszug, deutsch)](GLOSSARY-AUSZUG.md)** — wichtigste Begriffe
- [Vollständiges Glossar (en)](../../GLOSSARY.md)
- [Begriffs-Übersetzungstabelle v3.44 → v4.0 (en)](../../LEGACY-TO-NEWUI-TERMS.md)
- [FAQ (en)](../../FAQ.md) — häufige Fragen

### Systemverhalten

- [Routing-Engine (en)](../../ROUTING-ENGINE-REFERENCE.md) — Regel-Schema, Filter, Schleifen-Vermeidung
- [Webhooks (en)](../../WEBHOOKS-INTEGRATOR-GUIDE.md) — HMAC-Signatur, Wiederversuch, Event-Typen
- [Audit-Protokoll-Referenz (en)](../../AUDIT-LOG-REFERENCE.md) — OCSF-konformes Schema

### Sicherheit und Compliance

- [Sicherheitsrichtlinie (en)](../../SECURITY-POLICY.md)
- [CJIS-Status (en)](../../CJIS-POSTURE.md) — Compliance-Übersicht für Prüfer

---

## Mitwirken

Wir freuen uns über jede Erweiterung der deutschen Dokumentation. Auch Teilbeiträge sind willkommen.

Lesen Sie [CONTRIBUTING-TRANSLATIONS.md](../CONTRIBUTING-TRANSLATIONS.md) (englisch) für den Beitragsleitfaden.

Wenn Sie eine Übersetzung verbessern möchten, öffnen Sie einen Pull Request gegen die Datei unter `docs/locales/de/`.

---

*Diese Seite wurde im Rahmen der Dokumentationsüberarbeitung 2026-06-15 erstellt. Die englische Originalversion ist [`docs/INDEX.md`](../../INDEX.md).*
