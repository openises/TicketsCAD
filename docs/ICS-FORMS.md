# ICS Forms

TicketsCAD has every form in the FEMA **NIMS ICS Forms Booklet** (FEMA 502-2,
September 2010) built in, laid out the way FEMA lays them out: the same
blocks, block numbers, titles, order and table columns. Each block's FEMA
instruction is shown under it in the editor, and the printout carries FEMA's
heading ("INCIDENT RADIO COMMUNICATIONS PLAN (ICS 205)"), block numbers,
signature lines and footer ("ICS 205 · IAP Page ___").

Source: the FEMA booklet as published at
`training.fema.gov/emiweb/is/icsresource/assets/ics forms/nims ics forms booklet (v3).pdf`.

## Using the forms

Open **ICS Forms** from the menu (or type `/ics` in the command bar).

- **Find a form** — type a number or a word ("205", "medical", "check-in");
  the list narrows as you type and **Enter** opens the first match. **Esc**
  clears it.
- **Link to Incident** — search for the incident and pick it. Each form fills
  in what it can: the incident name and number, today's date and time in the
  Prepared by block, the operational period start, and form-specific fields
  (for example the ICS 201's Date/Time Initiated, or the ICS 209's start time,
  incident definition, State, city and location). Nothing you have already
  typed is overwritten.
- **Save Draft / Finalize / Print / Delete** work the same for every form.
  Printing produces a letter-size page (landscape for the forms FEMA prints
  landscape) with your agency's letterhead if branding is on.
- **Signature lines** are not typed in: they print as a line to sign by hand.
- **Tables** start with a few empty rows; **Add Row** adds more. Forms whose
  rows are fixed by FEMA (for example the ICS 209 casualty counts or the ICS 220
  frequencies) show exactly those rows.
- A security label on the linked incident still watermarks and (if set to)
  blanks every value on the printout, table cells included.

## The forms

| Form | Title | Notes |
|------|-------|-------|
| ICS 201 | Incident Briefing | Blocks 1–10 (all four FEMA pages) on one form. |
| ICS 202 | Incident Objectives | Includes the Incident Action Plan checklist (block 6). |
| ICS 203 | Organization Assignment List | Branches/Divisions/Groups as a table under block 7. |
| ICS 204 | Assignment List | |
| ICS 205 | Incident Radio Communications Plan | N or W and Mode are drop-downs; prints "RX Freq N or W" as FEMA does. |
| ICS 205A | Communications List | |
| ICS 206 | Medical Plan | |
| ICS 207 | Incident Organization Chart | Prints as a chart. |
| ICS 208 | Safety Message/Plan | |
| ICS 208 HM | Site Safety and Control Plan | Sections I–XII, items 1–36. |
| ICS 209 | Incident Status Summary | All 53 blocks; FEMA's required-when-applicable blocks are marked with a red asterisk. |
| ICS 210 | Resource Status Change | |
| ICS 211 | Incident Check-In List | Blocks 5–16 are the columns of the check-in table. |
| ICS 213 | General Message | Winlink XML export (unchanged). |
| ICS 213 RR | Resource Request Message | Requestor, Logistics and Finance sections. |
| ICS 214 | Activity Log | For one person or a whole unit: list the people with you in block 6. |
| ICS 214a | Individual Log (pre-2010) | FEMA merged it into ICS 214 in 2010. Kept so saved logs keep working; use ICS 214 for new logs. |
| ICS 215 | Operational Planning Worksheet | One row per kind of resource an assignment needs; totals in blocks 11–13. |
| ICS 215A | Incident Action Plan Safety Analysis | |
| ICS 217A | Communications Resource Availability Worksheet | |
| ICS 218 | Support Vehicle/Equipment Inventory | |
| ICS 219-1 … 219-10 | Resource Status Cards (T-Cards) | One form per card (Header, Crew/Team, Engine, Helicopter, Personnel, Fixed-Wing, Equipment, Miscellaneous Equipment/Task Force, Generic); prints with a band in the card's colour. |
| ICS 220 | Air Operations Summary | |
| ICS 221 | Demobilization Check-Out | A new form starts with FEMA's check-out units. |
| ICS 225 | Incident Personnel Performance Rating | |
| ICS 230-CG | Daily Meeting Schedule | A new form starts with FEMA's standard meetings. |
| ICS 233-CG | Incident Open Action Tracker | |

Your agency can still build its own forms under **Settings → Custom ICS Form
Types**. A custom form you made before TicketsCAD had a built-in version of it
keeps working and stays editable; you can archive it once you have moved to
the built-in one.

## Forms saved before October 2026

The nine forms TicketsCAD already had (213, 213 RR, 202, 205, 205A, 206, 214,
214a, 221) were corrected to match FEMA. **Nothing you saved was changed or
lost.** When you open or print a form saved before the change, TicketsCAD
shows its values in the new FEMA fields; it is saved in the new shape only
when you press Save.

| Form | What you will see |
|------|-------------------|
| ICS 205 | The old single Tone/NAC appears in both RX Tone/NAC and TX Tone/NAC — check whether your TX tone differs. |
| ICS 214 | Team Name/Number → 3. Name (FEMA: the unit or resource designator); Team Leader name and position → 4. ICS Position, joined as "J. Smith, Engine Boss" (FEMA: the person in charge). 5. Home Agency (and Unit) — the agency of the person completing the form — stays empty for you to fill in. Old log entries have a time but no date. |
| ICS 205A | Phone, Radio and Email are combined into Method(s) of Contact. |
| ICS 206 | Y/N becomes Yes/No; an ambulance's "Paramedics Y" becomes Level of Service ALS (N becomes BLS); a hospital's single travel time is shown under Ground. |
| ICS 221 | The old check-out boxes become rows of the block 6 check-out list (any value except N/No is ticked; other text becomes the row's Remarks). Room Reservations → Room Overnight; Reassignment Destination → 9. Location; Reassignment ETA → Estimated Time of Arrival; Area/Agency/Region → Area/Agency/Region Notified. |
| ICS 202, 213 RR, 221 | Fields FEMA's form does not have (202 Summary of Current Actions; 213 RR To/From and per-row Priority; 221 Leader Name and the Approved By fields) stay on the form, marked **earlier version**, and print under "From an earlier version of this form" — only when they hold something. |

If an old value does not fit a new drop-down (for example "Maybe" in a Yes/No
column), the drop-down offers that value as its own choice, so saving never
discards it.

## For administrators

- No new permissions: creating and printing forms needs the same rights as
  before (Incident Create or ICS Forms Management), deleting needs the ICS form
  delete permission for a finalized form.
- Saving a form (create or update) now writes an entry to the audit log, as
  deleting already did.
- No database change: the new forms use the existing `ics_forms` table.
- The form definitions live in `inc/ics-forms-builtin.php`; the comparison with
  FEMA is a test (`tests/test_gh154_ics_forms_fema_reference.php`) against a
  table typed from the booklet (`tests/_gh154_fema_reference.php`).
