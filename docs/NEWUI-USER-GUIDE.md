# TicketsCAD NewUI v4.0 --- User Guide

Welcome to TicketsCAD NewUI, a Computer Aided Dispatch system built for volunteer fire departments, ARES/RACES amateur radio groups, CERT teams, search and rescue organizations, small EMS agencies, event medical services, and campus security.

This guide is written for dispatchers, volunteers, and team leaders who use the system day-to-day. It covers everything you need to know to log in, dispatch incidents, manage units, and get the most out of the system.

---

## Table of Contents

- [Part 1: Getting Started](#part-1-getting-started)
- [Part 2: Dispatch Operations](#part-2-dispatch-operations)
- [Part 3: Working with Units](#part-3-working-with-units)
- [Part 4: Facilities](#part-4-facilities)
- [Part 5: Personnel and Teams](#part-5-personnel-and-teams)
- [Part 6: Search and Reports](#part-6-search-and-reports)
- [Part 7: Communications](#part-7-communications)
- [Part 8: Maps](#part-8-maps)
- [Part 9: Account and Security](#part-9-account-and-security)
- [Part 10: Configuration (Admin Only)](#part-10-configuration-admin-only)
- [Part 11: Call Board](#part-11-call-board)
- [Part 12: ICS Forms](#part-12-ics-forms)
- [Part 13: Mobile Unit Interface](#part-13-mobile-unit-interface)
- [Part 13b: Logging Volunteer Hours](#part-13b-logging-volunteer-hours)
- [Part 14: External Links](#part-14-external-links)
- [Part 15: Mesh Bridges (LoRa Radio Backhaul)](#part-15-mesh-bridges-lora-radio-backhaul)
- [Appendix A: Keyboard Shortcuts](#appendix-a-keyboard-shortcuts)
- [Appendix B: Troubleshooting](#appendix-b-troubleshooting)

---

# Part 1: Getting Started

## Logging In

1. Open your web browser and go to the address your administrator gave you (for example, `http://your-server/newui/`).
2. You will see the login screen with the Tickets NewUI logo.
3. Enter your **Username** and **Password**.
4. Choose your preferred theme --- **Day** (light background) or **Night** (dark background). You can change this at any time after logging in.
5. Click **Log In**.

### First-Time Login

If this is your very first time logging in and your administrator has not given you personal credentials, the default login may be:

| Field    | Value   |
|----------|---------|
| Username | `admin` |
| Password | `admin` |

**Change your password immediately** after your first login. See [Changing Your Password](#changing-your-password) below.

### If Your Account Is Locked

After several incorrect password attempts, your account will be temporarily locked. When this happens, the login screen shows a countdown timer. Wait for the timer to expire, then try again with the correct password. If you cannot remember your password, contact your administrator.

### When You Must Change Your Password Before Continuing

Some accounts are configured so you cannot use the system until you choose your own password. This typically happens when:

- An administrator just created your account with a temporary password and they want you to pick your own before doing anything else.
- An administrator just reset your password (because you forgot it, or for security reasons) and they want you to choose a new one immediately.

If your account is in this state, after you log in (and complete 2FA if required) you will land on a screen with a yellow banner that reads **"Password change required."** You will see:

- A **Change Password** form right below the banner. This is the only thing on the page you can do.
- A red **Log Out** button in the upper-right corner (if you want to abandon and try later).
- The Profile and Security tabs are hidden until you complete the change.

To get out of this state:

1. Type your **current** password (the temporary one your administrator gave you) in the first field.
2. Type your **new** password in the second field. Choose something only you know.
3. Type the **same new password** in the confirm field.
4. Click **Change Password**.

The form will say "Password changed successfully." The yellow banner disappears, the Profile and Security tabs come back, and you can navigate freely. Use the **Dashboard** link in the user menu to go to the main view.

If you click on any other link or try to navigate elsewhere before completing this step, you will be sent back to the Change Password screen.

### Two-Factor Authentication (2FA) at Login

If your administrator has enabled two-factor authentication, you will see an additional screen after entering your username and password:

1. Open your authenticator app (Google Authenticator, Authy, Microsoft Authenticator, or similar).
2. Find the entry for TicketsCAD and read the 6-digit code.
3. Type the code into the field on screen. The form submits automatically once you enter all 6 digits.
4. If you are on a trusted device, you can check **"Remember this device"** to skip this step for the next 30 days.
5. If you have lost access to your authenticator app, click **"Use a backup code instead"** and enter one of the 8-digit recovery codes you saved during setup.

## The Dashboard

After logging in, you land on the **Dashboard**. This is your main workspace --- a live overview of everything happening in your dispatch operation.

The dashboard is divided into movable, resizable panels called **widgets**. Each widget shows different information:

| Widget | What It Shows |
|--------|---------------|
| **Statistics** | Quick counts at a glance: open incidents, unassigned calls, units on scene, available responders, dispatched units, responding units, incidents closed today, and average dispatch time. |
| **Active Incidents** | A table listing every open incident with its ID, name, type, location, severity, number of assigned units, patient count, action count, and when it was last updated. Click any row to open the full incident details. |
| **Responders** | A table of all responder units showing their name, radio handle, type, current status, and how many incidents they are assigned to. |
| **Facilities** | A table of hospitals, shelters, stations, and other facilities with their name, type, status, and operating hours. |
| **Map** | A live map showing markers for active incidents, unit positions, and facilities. You can pan, zoom, and click markers for details. |
| **Activity Log** | A scrolling list of recent events --- dispatches, status changes, notes, and other system activity, showing the time, event type, who did it, and a short description. |
| **Controls** | Quick-action buttons: Show Assigned units, Road Conditions, Contact Units, Contact Facilities, File Manager, Mail, Print, and Settings. |
| **Communications** | Buttons to open Chat, SMS, Radio, Zello push-to-talk, and Alerts. |
| **Recent activity** | A live feed of the last audit-log events (logins, config changes, incident updates) for administrators and users with the `widget.audit_log` permission. Click any row to open a detail modal with the full event including IP address and structured details. The "View all audit events" link opens the full audit-log browser under Settings. |

### Moving and Resizing Widgets

- **Move a widget:** Click and drag its title bar to a new position on the grid.
- **Resize a widget:** Drag the bottom-right corner of any widget to make it larger or smaller.
- **Show or hide widgets:** Look at the **Widget Toggles** bar just below the main navigation. Each small button represents a widget. Click a button to turn that widget on or off.

### Saving Your Layout

Your widget arrangement saves automatically whenever you move or resize a widget. Each user has their own personal layout.

The Widget Toggles bar also has these layout tools:

- **Reset** (circular arrow icon) --- Puts all widgets back to the default positions.
- **Undo** (left arrow icon) --- Reverses your last layout change.
- **Snapshots** (bookmark icon) --- Save your current layout with a name (for example, "Normal Ops" or "Major Event") so you can switch between different arrangements. Click a saved snapshot name to restore it, or delete snapshots you no longer need.

## Day/Night Theme Toggle

In the upper-right area of the screen, you will see two small buttons with a sun icon and a moon icon:

- **Sun** --- Day mode. Light backgrounds, best for well-lit rooms.
- **Night** --- Night mode. Dark backgrounds, easier on the eyes in low-light environments and prevents screen glare.

Your theme choice is saved to your account and stays the same every time you log in.

## Navigating the Menu Bar

The navigation bar runs across the top of every page. Here is what each button does:

| Button | Where It Takes You |
|--------|--------------------|
| **Situation** | The main dashboard (home page). |
| **Full Screen** (expand icon) | Opens the full-screen Situation View in a new browser tab --- ideal for a wall-mounted display. |
| **New** | Opens the New Incident form to create a new call. |
| **Units** | Shows all responder units and their current status. |
| **Fac's** | Shows all facilities (hospitals, shelters, stations). |
| **Search** | Opens the incident search page to find past and current calls. |
| **Personnel** (dropdown) | A menu with links to: Roster, Teams, Scheduling, Vehicles, Equipment, and various personnel configuration pages. |
| **Reports** | Opens the reporting page for generating incident summaries and statistics. |
| **Config** | Opens the Settings page (administrators only). |
| **SOP** | Opens the Standard Operating Procedures viewer. |
| **Contacts** | Opens the Constituents (contacts) page for managing external contacts. |

On the right side of the navigation bar you will find:

- **Connection indicator** (small colored dot) --- Green means real-time updates are flowing. Yellow means reconnecting. Red means disconnected.
- **24-hour clock** --- Shows your local time, updated every second.
- **Audio mute button** (speaker icon) --- Click to mute or unmute alert sounds.
- **Day/Night toggle** --- Switch between light and dark themes.
- **Organization switcher** --- If you belong to more than one organization, a dropdown lets you switch between them.
- **Your username** --- Click to see a dropdown menu with links to My Profile, Change Password, Two-Factor Auth, and Log Out.

---

# Part 2: Dispatch Operations

## Creating a New Incident

When a call comes in, here is the step-by-step workflow:

1. Click **New** in the navigation bar. The New Incident form opens.
2. The form has two columns:
   - **Left column** --- The form fields, organized into collapsible sections.
   - **Right column** --- A map for pinpointing the location, and a panel for assigning responder units.
3. Fill in the required fields (marked with a red asterisk).
4. Assign responder units from the right-side panel.
5. Click **Submit Incident** (green button at the top) or press **Ctrl+Enter** from any field.

At the top of the form you also have:
- **Cancel** --- Returns to the dashboard without saving.
- **Reset** --- Clears all fields so you can start over.

### The Incident Form Sections

The form is organized into 8 collapsible sections. Click any section header to expand or collapse it.

#### Section 1: Classification (Required)

This section identifies what kind of call you are handling.

- **Incident Type** --- Select from the dropdown list. Types might include "Structure Fire," "Medical Emergency," "Traffic Accident," "Welfare Check," and so on. Your administrator sets up these types.
  - When you select a type that has a **response protocol**, the protocol text appears in a panel above the map. Read this to the caller if appropriate.
  - The **severity** level auto-fills based on the type you choose, but you can change it.
- **Severity** --- Indicates urgency. Options are typically Normal, Elevated, and Critical.
- **Incident Name / Scope** --- A brief summary title for the incident (for example, "Vehicle accident on Highway 5" or "Chest pain at 123 Main St").
- **Description** --- A detailed description of what is happening.
- **Signal** --- Optional. Select a signal/dispatch code if your organization uses them.
- **Major Incident** --- Optional. Link this call to an existing major incident, or click **New** to create a new major incident grouping.

#### Section 2: Location

This section records where the incident is happening.

- **Street Address** --- Type the street address. Then click the **Lookup** button (or press Tab to reach it, then press Enter) to find it on the map.
  - If the address is found, the map zooms to that spot and drops a marker.
  - The **City**, **State**, and **Cross Street** fields fill in automatically from the lookup results.
  - If you need to correct the city, just type over it. The state field will clear so you can re-geocode.
  - After a lookup, the city text is selected so you can instantly overwrite it if needed.
- **City** --- Auto-filled by the address lookup, but you can type it manually.
- **State** --- Auto-filled by the address lookup. Select from the dropdown if entering manually.
- **Zip Code** --- Hidden by default. Toggle the "Show Zip Code" switch to reveal it.
- **Area / Cross Street** --- Auto-populated from the address lookup when neighborhood or cross-street data is available. You can also type it manually.
- **Coordinates** --- Latitude and longitude fill in automatically from the lookup or from clicking on the map. These are read-only; use the X button to clear them.
- **Destination Address** --- If a patient needs transport, enter the destination (hospital, shelter, etc.) here.

**Tip:** You can also click directly on the map to place a marker. The address fields will auto-fill based on where you clicked (reverse geocoding).

#### Section 3: Contact

Information about the person who reported the call.

- **Caller Name** --- Name of the person reporting.
- **Phone** --- Their phone number.
- **Contact Notes** --- Any additional contact details.

#### Section 4: Facilities

If the call involves a specific facility (for example, transporting a patient to a hospital), select the receiving facility here.

#### Section 5: Time and Status

- **Reported Time** --- Defaults to the current time. You can edit this for delayed reports.
- **Status** --- The initial status of the call (usually "Open" or "Dispatched").

#### Section 6: Call History

Search for previous calls from the same phone number or address. This is helpful for identifying repeat callers or locations with a history of incidents.

#### Section 7: Patients

For medical incidents, track patient information here.

- Click **Add Patient** for each patient on scene.
- Fill in the available details: name, age, gender, and chief complaint.
- A badge on the section header shows the current patient count.
- Click the remove button on any patient row to delete it.

#### Section 8: Additional Details

- **Notes** --- Internal notes that are not shared with field units.
- Any other supplementary information.

### Using Keyboard Shortcuts on the Form

TicketsCAD is designed for speed. You should be able to handle a call without ever touching the mouse:

1. The cursor starts in the **Description** field. Type the call narrative as the caller relates it.
2. Press **Tab**. The system reads your description and applies the first matching regex pattern from the incident type catalog (see the next section). If a pattern matches, **Incident Type** auto-fills, **Severity** auto-fills from the type, and the **Protocol** panel appears on the right with instructions to read to the caller.
3. From Description, **Tab** moves forward through: Street Address → Lookup button → City → State → Contact (caller name) → Phone → Responder search.
4. **Shift+Tab** from Description moves backward through: Scope → Severity → Incident Type. Use this when you want to set a Scope name, override Severity, or pick a Type manually because the description didn't match a pattern.
5. The **Signal** and **Major Incident** fields are skipped in the tab order (`tabindex="-1"`) since they are rarely needed --- click them with the mouse if you need them.
6. Press **Ctrl+Enter** from any field to submit the incident immediately.

### Description Auto-Match (Pattern Recognition)

When the operator Tabs out of the Description field, NewUI walks the incident type list in order and tests each type's **`match_pattern`** regex against the description text. The first hit wins.

What this looks like in practice:

| You type into Description | NewUI picks |
|---|---|
| "Caller reports chest pain, conscious" | EMS — Medical (regex matches `chest pain`) |
| "Smoke coming from the second floor" | Structure Fire (matches `smoke ... floor`) |
| "Auto accident Highway 7, injuries" | MVA with Injuries (matches `auto accident`) |
| "Welfare check on my elderly neighbor" | Welfare Check (matches `welfare check`) |
| "Brush fire near the river" | Wildland Fire (matches `brush fire`) |
| "Lockout on Main Street" | Public Service (matches `lockout`) |

**Configuring patterns:** Each incident type has a `match_pattern` column (`tools/seed_training_match_patterns.php` seeds the demo install with realistic examples). Patterns use PHP `preg_match` syntax with case-insensitive matching. To add or edit patterns, an admin opens **Settings → Incident Types** and edits the Pattern field on the type.

**Match order:** Types are evaluated in their sort order. To make a more specific type win over a more general one, give it a lower sort number.

**No match found:** Incident Type stays empty. Operator Shift+Tabs to Type and picks manually.

**Manual override:** Picking a type manually (mouse-click or Shift+Tab + keyboard) skips the auto-match — the system never overwrites a type the operator chose.

### Geocoding an Address

**Forward geocoding** (address to map location):
1. Type the street address in the Address field.
2. Press Tab to reach the **Lookup** button, then press Enter. (Or click the Lookup button.)
3. The system searches for the address and, if found, drops a marker on the map and fills in City, State, and Cross Street.

**Reverse geocoding** (map click to address):
1. Click anywhere on the map on the right side of the form.
2. A marker appears at that spot and the address fields fill in automatically.

**Smart correction:** If the geocoder returns the wrong city, simply edit the City field. The State field clears automatically, and pressing Tab routes you back to the Lookup button so you can re-search.

The geocoder prioritizes addresses near your organization's configured location, so local results appear first.

### Assigning Responders to a New Incident

Below the map on the right side of the form, you will see a list of available responder units.

1. Use the **search filter** at the top of the panel to find a specific unit by name or callsign.
2. Click the **checkbox** next to each unit you want to assign.
3. When you submit the incident, all checked units will be dispatched.

### Submitting the Incident

Click the green **Submit Incident** button at the top of the page, or press **Ctrl+Enter** from any field.

The system will:
- Save the incident to the database.
- Notify all assigned responder units.
- Update the dashboard in real time for everyone who is logged in.
- Show you a success message.

## Viewing the Incident List

Click **Situation** in the navigation bar to return to the dashboard, where the **Active Incidents** widget shows all currently open calls.

For a dedicated list view, navigate to the **Incident List** page (accessible from the dashboard or through the command bar). This page shows a filterable, sortable table of all incidents.

- **Filter** by status (Open, Closed, All), date range, incident type, or severity.
- **Sort** by clicking any column header.
- **Click** any row to open the full Incident Detail view.

## Incident Detail View

Click any incident in the dashboard's Active Incidents widget (or in the Incident List) to open its detail view. Here you will find:

- **Header** --- The incident number, type, severity (color-coded), and current status.
- **Location** --- The address, city, state, and a map with the incident marker.
- **Timeline** --- A chronological list of everything that has happened: notes added, status changes, unit assignments, and other activity.
- **Assignments** --- Which responder units are currently assigned.
- **Patients** --- Patient information (if any).
- **Action buttons:**
  - **Add a note** --- Append a text note to the incident timeline.
  - **Change status** --- Update the incident status (Open, Dispatched, Closed, etc.).
  - **Assign or remove units** --- Add or remove responders.
  - **Navigate** --- Opens turn-by-turn directions in an external map application.
  - **ICS-213 Export** --- Generates a Winlink-compatible ICS-213 form from the incident data. Download the XML file and import it into Winlink Express or Pat for radio transmission.

### Closing an Incident

1. Open the incident detail view.
2. Change the status to "Closed" (or your organization's equivalent).
3. Add any closing notes.
4. Save.

To reopen a closed incident, change the status back to "Open" and save.

### Major Incidents

A major incident groups several related calls together under one umbrella. For example, a wildfire might generate separate calls for structure fires, evacuations, and medical emergencies --- all linked to one major incident.

- **Create** a major incident from the incident detail view.
- **Link** existing incidents to it.
- **Command structure** fields let you record Gold, Silver, and Bronze command positions with names and locations.
- **Navigate** between linked incidents from any call in the group.

## Full-Screen Situation View

Click the **Full Screen** button (the expand icon next to "Situation" in the navigation bar) to open a dedicated display in a new browser tab.

This view is designed for a wall-mounted monitor in a dispatch center or Emergency Operations Center (EOC). It shows:

- A **full-screen map** covering the entire browser window, with incident markers color-coded by severity.
- A **semi-transparent overlay panel** in the upper-left corner listing active incidents with their type, address, severity, and assigned units.
- A **summary bar** showing total open incident and unit counts.

### Time-Range Filters

Use the dropdown in the overlay panel to switch what is displayed:

| Filter | Shows |
|--------|-------|
| Current Situation | All currently open incidents |
| Closed Today | Incidents closed today |
| Yesterday | All incidents from yesterday |
| This Week | All incidents this week |
| Last Week | All incidents from last week |
| This Month | All incidents this month |
| Last Month | All incidents from last month |
| This Year | All incidents this year |

Click any incident row in the overlay to zoom the map to that location. The view updates automatically in real time --- you do not need to refresh the page.

On mobile devices, the overlay panel moves to the bottom of the screen for easier touch access.

---

# Part 3: Working with Units

## Viewing Responder Status on the Dashboard

The **Responders** widget on the dashboard shows a live table of all responder units. Each row displays:

- **Name** --- The unit's name (for example, "Engine 1" or "Medic 3").
- **Handle** --- The unit's radio callsign or identifier.
- **Type** --- The kind of unit (Engine, Ambulance, Patrol, etc.).
- **Status** --- The unit's current status (Available, Dispatched, En Route, On Scene, etc.).
- **Assigned** --- How many active incidents the unit is currently assigned to.

You can click any column header to sort the table. Click a unit row to open its detail page.

## Zone Coverage

The **Zone Coverage** board (top navigation → **Zone Coverage**) is built for events where volunteers roam between areas --- a festival, parade, or fair. It answers one question at a glance: **how many units are in each zone right now?** With that, anyone on the team can decide whether to **meet up** with another unit or **spread out** for better coverage.

### How it differs from Net Control

- **Net Control** is the *dispatcher's* command board: a dense grid where net control moves units between zones, runs PAR checks, and logs to the ICS-214. It is limited to dispatch-level roles.
- **Zone Coverage** is the *volunteer's* companion view: big, phone-friendly cards, visible to **every** role including Field Unit. Both read the same zones and the same live unit positions, so a change in one appears in the other within seconds.

### Reading the board

- Each **zone** is a card showing its colour, a large **unit count**, and the units currently in it (callsign plus the lead member's name).
- A **No zone reported yet** section lists units assigned to the event that have not been placed in a zone yet, so no one is invisible.
- The board **updates in real time** (a small *live* indicator sits top-right) and also re-polls every 15 seconds as a safety net.

### Reporting your own zone

If you are the person on a unit assigned to the event, an **"I'm in:"** strip appears with a button for each zone. Tap one and your unit moves there instantly --- no radio call needed --- and it shows up for everyone. It also writes an ICS-214 activity note (for example, *"Alpha → North Gate (self-reported)"*), so the after-action log stays complete. You can only ever move **your own** unit this way; net control still moves any unit from the Net Control board.

### Choosing the event

The board automatically shows the active event that has zones. If more than one event with zones is running at once, an event picker appears top-right; your choice is remembered on that device.

### Setting up zones

Zones are defined on the **Net Control** board (draw them on the map, or name them). Once zones exist and units are assigned to the event, Zone Coverage fills in on its own. If the board reports that no zones are set up yet, the Net Control board is where to start.

### Permissions

- **See the board** --- every role, including Field Unit (`screen.zone_coverage`).
- **Report own zone** --- Super Admin, Org Admin, Dispatcher, Operator, and Field Unit (`action.set_own_zone`). Read-Only can watch but not change.

## Assigning Units to Incidents

There are several ways to assign units:

- **When creating a new incident** --- Check the boxes next to the units you want in the responder panel on the right side of the new incident form.
- **From the Incident Detail page** --- Open an existing incident, then use the assignment controls to add or remove units.

## Changing Unit Status

Unit statuses reflect where a unit is in the dispatch lifecycle. Common statuses include:

| Status | Meaning |
|--------|---------|
| Available | Ready to be dispatched |
| Dispatched | Assigned to a call but not yet en route |
| En Route | Traveling to the scene |
| On Scene | At the incident location |
| Returning | Heading back to the station |
| Out of Service | Not available for dispatch |

Status changes can be made from the Unit Detail page or from the Incident Detail page.

## Unit Detail View

Click a unit's name in the dashboard Responders widget or on the Units page to open its detail view. This page shows:

- The unit's name, handle, type, and current status.
- Current and recent incident assignments.
- Location information (if GPS tracking is configured).
- Contact information and notes.

## Unit Edit View

Administrators and dispatchers with the right permissions can edit a unit's properties: name, handle, type, description, home station, and other fields.

---

# Part 4: Facilities

## Viewing Facilities

Click **Fac's** in the navigation bar (or look at the **Facilities** widget on the dashboard) to see all facilities. The list shows each facility's name, type, status, and operating hours.

Facilities include hospitals, fire stations, emergency shelters, staging areas, command posts, and any other fixed locations your organization needs to track.

## Facility Status and Capacity

Each facility has a status indicator:
- **Open** --- Accepting patients or available for use.
- **Closed** --- Not currently in operation.
- **Full** --- At capacity and not accepting more patients or resources.

For hospitals and shelters, TicketsCAD supports **capacity tracking**:
- View the number of available beds or cots by category (ICU, ER, shelter cots, etc.).
- See total capacity, current occupancy, and remaining availability at a glance.
- Color-coded status badges help you quickly identify which facilities can accept patients.

## Facility Detail View

Click any facility name to see its full details: address, coordinates, type, status, description, capabilities, access instructions, and a map showing its location.

## Adding and Editing Facilities

1. Navigate to the Facilities page.
2. Click **Add Facility** to create a new one, or click an existing facility to view and edit it.
3. Fill in the details:
   - **Name** --- The facility's name (for example, "Memorial Hospital" or "Station 5").
   - **Type** --- The category (Hospital, Fire Station, Shelter, Command Post, etc.).
   - **Address** --- Street address. Use the Lookup button to geocode it.
   - **Description** --- Notes about capabilities, contact numbers, access instructions.
   - **Status** --- Current operational status.
4. Click **Save**.

All facilities with coordinates appear as markers on the dashboard map, the new incident map, and the full-screen situation view.

## Facility Board

The **Facility Board** (accessible at `facility-board.php`) provides a real-time overview of all facilities and their current operational status. This is useful during large events or multi-agency operations where you need to quickly see which hospitals are accepting patients, which shelters have capacity, and which staging areas are active.

The facility board displays each facility as a card with:
- Name, type, and current status (Open, Closed, Full).
- For hospitals and shelters with capacity tracking enabled: a visual bar showing current occupancy versus total capacity, broken down by bed category (ICU, ER, shelter cots, etc.).
- Color-coded status badges so you can spot full or closed facilities at a glance.

The board refreshes automatically via real-time updates, so changes made by other dispatchers appear immediately.

---

# Part 5: Personnel and Teams

## The Roster

Click **Personnel > Roster** in the navigation bar to open the personnel management page. This is where you view and manage all members of your organization.

The roster page has a two-column layout:
- **Left side** --- A searchable, filterable list of all members with a count badge.
- **Right side** --- A detail panel that shows the selected member's full information.

### Searching and Filtering

At the top of the roster, you will find:
- A **search box** --- Type a name, callsign, phone number, or email to find a specific person.
- **Status filters** --- Show only Active, Inactive, or all members.
- **Team filters** --- Show only members of a specific team.
- **Type filters** --- Show only members of a specific member type.

You can also click the **Print** button to generate a printable version of the roster.

## Adding a New Member

1. Click **New Member** at the top of the Roster page.
2. Fill in the member's information: name, contact details, member type, and status.
3. Click **Save**.

## Removing Members

### One member at a time

Open the member on the roster and use the **Delete** button on the detail
panel. This is a soft delete --- the member moves to the Wastebasket and can
be restored. Anyone with the **Manage Members** permission can do this.

### Removing several members at once (bulk removal)

If your account has the **Bulk Delete Members** permission, a small checkbox
column appears at the right edge of the roster table, along with a
**select-all** checkbox in the header.

1. Tick the checkbox on each member you want to remove (or use the header
   checkbox to select every member currently shown).
2. A bulk-actions bar appears showing the count selected. Click **Clear** to
   deselect all.
3. Click **Delete Selected**. A confirmation dialog spells out how many
   members will be removed and that they go to the Wastebasket (soft delete).
4. Confirm. The roster refreshes and reports how many were removed (and how
   many, if any, failed).

Notes:

- Bulk removal is a **soft delete**, just like single delete --- the members
  go to the Wastebasket and can be restored.
- A single request is capped at **500 members**.
- The checkbox column only appears for accounts that hold the **Bulk Delete
  Members** permission. If you don't see it, you don't have that permission ---
  ask an administrator (see the Administration section on granting it).
- **The selection accumulates as you filter.** If you select members, then
  change the search/filter, then select more, the delete applies to *every*
  member you selected across all filters --- not only the rows currently
  visible. Use **Clear** if you want to start over.

## FCC Callsign Lookup

When adding a member who has an amateur radio or GMRS license:

1. Enter the callsign in the lookup field.
2. Click **Lookup** to query the FCC database.
3. If a matching license is found, click **Apply to Form** to auto-fill the member's name and license details (call sign, license class, issue date, expiration date).

This works for both amateur radio callsigns and GMRS callsigns registered with the FCC.

## Certifications and Training Records

Each member can have certifications and training records attached to their profile:

- **Certifications** --- Formal credentials like EMT, Paramedic, HAZMAT Technician, CPR, First Aid, etc. Each certification has an issue date and an expiration date so your organization can track who needs to renew.
- **Training** --- Completed courses like ICS-100, ICS-200, ICS-300, ICS-400, FEMA IS-700, IS-800, etc.

Certification and training types are configured by your administrator under Config > Personnel.

## Communication Identifiers

Each member can have multiple communication identifiers for different radio and messaging modes:

| Mode | Example |
|------|---------|
| Amateur Radio | N0NKI |
| GMRS | WSKZ850 |
| DMR Radio ID | 3120001 |
| APRS | N0NKI-9 |
| Meshtastic | !a1b2c3d4 |
| Zello | eric.dispatch |

These identifiers are managed from the member's detail page on the roster.

## Team Management

Click **Personnel > Teams** in the navigation bar to manage your teams.

Each team has:
- **Name** --- For example, "Engine 1 Crew," "CERT Alpha," or "Net Control."
- **NIMS Resource Type** --- Resource typing per the National Incident Management System (for example, "Type 1 Engine" or "Type 2 Medical Team").
- **Members** --- The people assigned to this team.
- **Description** --- Notes about the team's capabilities and purpose.

## Scheduling

Click **Personnel > Scheduling** in the navigation bar.

The scheduling page supports two types of time management:

### Shifts
- View and manage recurring shift patterns (for example, 24-on/48-off or day/night rotation).
- See who is on duty at any given time.
- Assign members to specific shifts.

### Events
- Create one-time or recurring events such as training sessions, meetings, or community events.
- Define time slots with specific roles needed for each slot.
- Members can self-sign up for available slots.
- View upcoming events and who is signed up.

## Vehicles and Equipment

Under the **Personnel** dropdown you will also find:

- **Vehicles** --- Track your organization's vehicles with details like type, license plate, mileage, maintenance status, and assignment.
- **Equipment** --- Track equipment items with type, serial number, condition, location, and assignment history.

---

# Part 6: Search and Reports

## Searching Past Incidents

Click **Search** in the navigation bar to open the Incident Search page.

The search form lets you look for incidents using multiple criteria at once:

- **Text search** --- Search across incident scope, description, address, and caller information.
- **Type filter** --- Narrow results to a specific incident type.
- **Status filter** --- Show only Open, Closed, or Scheduled incidents (or All).
- **Severity filter** --- Show only incidents of a particular severity level.
- **Date range** --- Search within a specific time period.

Results appear in a sortable table below the search form. Click any column header to sort. Click any row to open the incident detail view.

## Generating Reports

Click **Reports** in the navigation bar.

The reports page provides pre-built reports that help you analyze your operations:

- **Incident Summary** --- Count of incidents broken down by type, severity, or time period.
- **Response Time Analysis** --- Average time from dispatch to on-scene, by unit or incident type.
- **Unit Activity** --- Hours worked, incidents responded to, and mileage for each unit.
- **Daily/Weekly/Monthly Activity** --- Incident volume over time shown as charts or tables.

All reports can be filtered by date range, incident type, unit, and other criteria.

## ICS-213 Export

TicketsCAD can export incident data as ICS-213 (General Message) forms compatible with Winlink, the amateur radio email system:

1. Open the Incident Detail view for any incident.
2. Click the **ICS-213** export button.
3. Download the generated XML file.
4. Import it into Winlink Express or Pat for radio transmission.

## Import and Export

The Import/Export page (accessible from Config > System > Import / Export) allows:

- **Exporting** incidents, members, facilities, or other data to CSV or JSON format.
- **Importing** data from CSV files, with column mapping and a preview step before committing.
- **Legacy migration** from Tickets v3.x installations.

---

# Part 7: Communications

## Chat

TicketsCAD has a built-in chat system for real-time messaging between dispatchers and operators.

To open chat, click the **Chat** button in the Communications widget on the dashboard. A floating chat panel appears.

### Channels

Use the channel dropdown at the top of the chat panel to choose where to send your message:

- **General** --- Visible to everyone in the organization.
- **Dispatch** --- Dispatch operators only.
- **Admin** --- Administrators only.

Additional channels may be available depending on your organization's configuration.

### Sending Messages

1. Select a channel from the dropdown.
2. Type your message in the text field at the bottom.
3. Click the **Send** button or press **Enter**.

### Signal Codes

Click the **Codes** button in the chat panel to see a list of predefined signal codes. Clicking a code sends it as a message instantly. Signal codes are short, standardized messages like "Return to station ASAP" or "Switch to TAC channel."

### Chat Controls

- **Minimize** (dash icon) --- Collapse the chat panel to a small bar.
- **Close** (X icon) --- Hide the chat panel entirely.
- **Resize** --- Drag the edge of the chat panel to make it larger or smaller.

## Audio Alerts

TicketsCAD can play sounds in your browser when important events occur, such as:

- A new incident is created.
- A responder's status changes.
- A new chat message arrives.
- An assignment notification is received.

### Muting and Unmuting

Click the **speaker icon** in the navigation bar to mute or unmute all audio alerts. The icon changes to show the current state (speaker with sound waves = unmuted, speaker with an X = muted).

Sound configuration (which events trigger sounds and what sounds to play) is managed by your administrator in Config > App Preferences > Sound / Alerts.

## Zello Push-to-Talk

If your organization uses Zello, click the **Zello** button in the Communications widget to open the Zello panel. This provides:

- Real-time text messaging over Zello channels.
- A **Push to Talk** button --- hold the Spacebar or click the button to transmit voice (if configured).
- A message feed showing recent Zello activity.

## Other Communication Integrations

Depending on how your administrator has configured the system, you may also have access to:

- **SMS messaging** --- Send text messages to responders or contacts.
- **Radio messaging** --- Send text messages over DMR digital radio.
- **Meshtastic** --- Send messages over LoRa mesh networks.

These features appear as buttons in the Communications widget and are configured in the Settings page.

## Communications Console

The **Console** page (navbar > Console) brings every configured communications channel onto one operational surface, styled like a commercial dispatch console: a bank of vertical **channel strips**, one per channel — Zello channels, DMR talkgroups, Meshtastic, local chat, weather alerts, the event bus, and more as integrations are added.

Each strip shows:

- A **status light** — green (connected), amber (degraded), red (down), grey (unknown/quiet).
- The **last caller** heard on the channel and how long ago.
- An **AMATEUR — ID required** badge on amateur-radio channels as a licensing reminder.
- The controls that channel supports. Voice channels (Zello, DMR) get a button that opens the matching radio widget for listening and push-to-talk. Text channels get a **Messages** drawer with recent traffic and a send box. Feed channels (weather alerts, event bus) get a read-only activity drawer.

### Console views (tabs)

Administrators with the *Design Shared Console Views* permission can author named layouts in the **Console Designer** (the **Design Views** button on the console). The designer works like a diagramming tool — a grid layout within a grid layout:

1. Create a view (for example "Day Shift" or "EOC Activation") and give it a tab icon.
2. Click channels on the right to add strips. **Drag a strip by its title bar** to place it anywhere on the view canvas, and **resize it from its corner** — both width and height are yours.
3. Inside each strip, every component — the label block, status light, last-caller line, PTT button, messages/feed box — is its own draggable, resizable element on a fine grid. Move them anywhere, make the PTT as wide or tall as you want, stack small buttons side by side. Components may overlap, exactly like a drawing canvas.
4. Click a component to edit its properties in the inspector (label text, background color, PTT color and momentary/latch mode). Click the strip title bar to edit strip-level settings (label override, accent color). The component palette only offers what the channel is actually capable of — a published view can never contain a dead button. Components tagged *future* (monitor, mute, volume, Say) can be placed now for layout planning and light up when the audio matrix backend arrives.
5. Click **Publish View**. The view immediately appears as a tab on every dispatcher's console, rendered exactly as designed.

The built-in **All Channels** tab always lists everything enabled and cannot be removed. Your last-used tab is remembered per browser.

Access requires the Communications Console screen permission; transmitting requires Console Transmit; authoring shared views requires Design Shared Console Views. All view publishing and edits are captured in the audit log.

## Internal Messaging

TicketsCAD includes a built-in internal messaging system for sending messages between users within the application. Access it from the **Messaging** page in the navigation bar.

### Inbox

Your inbox shows all messages sent to you. Each message displays:
- Subject line and sender name.
- Priority indicator (normal, high, or urgent).
- Whether you have read it (unread messages appear bold).
- Date and time received.

Click a message to read its full content.

### Composing a Message

1. Click **New Message** on the messaging page.
2. Select one or more recipients from the user list.
3. Enter a subject and message body.
4. Choose a priority level if needed (Normal, High, or Urgent).
5. Optionally link the message to an existing incident.
6. Click **Send**.

### Deleting Messages

When you delete a message from your inbox, it is soft-deleted (hidden from your view but preserved in the system). Other recipients of the same message are not affected.

### HAS Broadcast Alerts

Administrators and dispatchers can send **broadcast alerts** that go to every user in the system simultaneously. Broadcast messages are flagged with the urgent priority and appear prominently in every user's inbox.

Broadcasts are useful for:
- Emergency notifications that affect all personnel.
- System-wide announcements (weather alerts, facility closures, schedule changes).
- Alerting all users about a major incident activation.

---

# Part 8: Maps

## Map Controls

Maps appear in several places throughout TicketsCAD: the dashboard widget, the new incident form, incident detail views, and the full-screen situation view.

### Basic Controls

- **Pan** --- Click and drag to move the map.
- **Zoom in/out** --- Use your mouse scroll wheel, or click the **+** and **-** buttons on the map.
- **Click a marker** --- Click any marker (incident, unit, or facility) to see details about it.

### Map Markers

Different types of items appear as different markers on the map:

- **Incidents** --- Markers color-coded by severity level. Click to see the incident type, address, and status.
- **Responder units** --- Show unit positions (when GPS tracking is configured).
- **Facilities** --- Hospitals, stations, shelters, and other fixed locations.

## Weather Overlay

If your administrator has configured a weather API key, the dashboard map can display weather overlays:

- Temperature
- Precipitation
- Cloud cover
- Wind

Weather tiles are cached to reduce load. The overlay updates periodically.

## Road Conditions

The road conditions feature displays hazardous road conditions on the map as overlay markers:

- **Types** --- Slippery, flooding, closed road, construction, debris, and other conditions.
- **Visual indicators** --- Condition markers appear directly on the map so dispatchers can warn responders about hazards along their route.
- **Automatic expiration** --- Road conditions can be set to clear automatically after a certain time.

Road conditions can be managed through the Controls widget on the dashboard (click the **Roads** button) or by administrators in Config > Locations > Road Conditions.

## Map Markups

Map markups let you draw shapes and annotations on the map for operational planning:

- **Polygons** --- Outline search zones, event perimeters, or staging areas.
- **Circles** --- Mark radius zones like hazmat zones or evacuation rings.
- **Lines** --- Draw routes, boundaries, or barriers.
- **Rectangles** --- Mark rectangular areas.
- **Markers** --- Place labeled points of interest.

Each markup can have a name, description, color, and category. Categories (Region Boundary, Exclusion Zone, etc.) can be toggled on or off so you can show certain markups only when they are relevant.

Markups persist in the database and are visible to all users.

### Toggling Categories from the Map

On the dashboard map and the full-screen situation map, open the layer control (the layers icon in the upper right). Each map overlay category is listed as its own checkbox so dispatchers can show or hide an entire group of markups in one click --- for example, showing the Parade Routes layer only during a planned event, or hiding Precincts to declutter the map during a working incident.

The initial visibility for each category is set by the administrator on the **Map Overlay Categories** settings panel. Your personal choices are remembered in your browser so the map opens with the same layers visible the next time you log in.

## Address Search on Map

On the new incident form, type an address and click **Lookup** to search. The map zooms to the result and drops a marker. You can also click anywhere on the map to get the address for that location (reverse geocoding).

On the full-screen situation view, click any incident row in the overlay panel to zoom the map to that incident's location.

---

# Part 9: Account and Security

## Your Profile

Click your **username** in the upper-right corner of any page, then select **My Profile** from the dropdown menu. The profile page is where you manage your personal security settings.

## Changing Your Password

1. Click your username in the upper-right corner.
2. Select **Change Password** from the dropdown (or go to My Profile and scroll to the password section).
3. Enter your current password.
4. Enter your new password.
5. Confirm the new password.
6. Click **Save**.

Choose a strong password that is at least 8 characters and includes a mix of letters, numbers, and symbols.

## Setting Up Two-Factor Authentication (2FA)

Two-factor authentication adds an extra layer of security by requiring a code from your phone in addition to your password.

### What You Need

An authenticator app on your phone. Free options include:
- Google Authenticator
- Microsoft Authenticator
- Authy

### Setup Steps

1. Click your username in the upper-right corner, then select **Two-Factor Auth** from the dropdown.
2. On the Security Settings page, click **Set Up 2FA**.
3. **Step 1: Confirm your password** --- Enter your current password and click Continue.
4. **Step 2: Scan the QR code** --- Open your authenticator app and scan the QR code shown on screen. If your phone cannot scan the code, click "Can't scan?" to reveal a secret key you can type into your app manually. Click "I've scanned it" to continue.
5. **Step 3: Verify** --- Enter the 6-digit code that your authenticator app is now showing. Click Verify.
6. **Step 4: Save backup codes** --- You will be shown a set of one-time-use recovery codes. **Save these codes in a safe place** (print them, write them down, or save them in a secure password manager). Each backup code can only be used once. If you lose your phone and these codes, you will be locked out.
   - Click **Copy All** to copy the codes to your clipboard.
   - Click **Download as Text** to save them as a text file.
7. Click **Done**.

From now on, each time you log in you will need to enter a 6-digit code from your authenticator app after your password.

### If You Lose Your Authenticator

If your phone is lost or replaced:
1. At the login 2FA screen, click **"Use a backup code instead."**
2. Enter one of your 8-digit backup codes.
3. After logging in, go to your profile and set up 2FA again with your new device.

## Remembered Devices

If your administrator has enabled the "Remember this device" option, you can check the box during 2FA login to skip the code prompt for a set number of days (typically 30) on that specific device.

You can view and revoke your remembered devices from the Security Settings page.

## Disabling 2FA

If your administrator allows it, you can disable 2FA from the Security Settings page. You will need to confirm your password and enter a current TOTP code to disable it.

---

# Part 10: Configuration (Admin Only)

The Settings page is only accessible to administrators (Super Admin and Org Admin roles). If you see "Access Denied" when clicking Config, your account does not have administrator privileges.

## Overview of the Settings Page

The Settings page has a sidebar on the left with 9 sections. Click a section header to expand it and see its sub-panels. Click a sub-panel to load its settings on the right.

| Section | What It Contains |
|---------|-----------------|
| **System** | System Health dashboard, Audit Log viewer, Import/Export tools. |
| **Installation** | System Settings (default location, timezone, date format), API Keys, Lookup Services, Database Info, Backup/Maintenance. |
| **App Preferences** | Incident Types, Severity Levels, Field Help Text (signals/codes), Unit Statuses, Facility Types, Display Settings, Sound/Alerts, Incident Numbers. |
| **Users** | User Accounts (create, edit, disable users), Roles and Levels, Login Settings (lockout thresholds, session timeout), Two-Factor Auth system settings, Field Encryption toggle. |
| **Personnel** | Organizations, Members/Personnel config, Teams, Certifications, ICS Positions, Equipment Types, Vehicle Types, Training, Member Statuses, Member Types. |
| **Communications** | Notification Rules, Email Configuration, Email Lists, SMS Configuration, Telegram, Slack, Radio Messaging, Comm/Location Modes, Zello, Meshtastic, Webhooks, Standard Messages, Chat Settings. |
| **Locations** | Facilities, Regions, Places, Warn Locations, Constituents, Road Conditions. |
| **Maps** | Map Defaults (center point, zoom level), Tile Providers (map sources). |
| **Location (Tracking)** | Location Providers (APRS, Meshtastic, OwnTracks, DMR, Zello), Provider Settings, Geofencing. |

> **List pagination.** Under **App Preferences → Display Settings**, the **Rows per page** value controls how many rows long lists show at a time — enter **any positive integer** (default 50). This keeps big lists responsive on large organizations. The Units screen uses it now (more list screens to follow); there you can also change the row count for your current view with the **Rows** selector in the table footer, including **All**.

## Common Settings to Change First

When setting up a new installation, these are the most important settings to configure:

1. **Change the default admin password** --- Config > Users > User Accounts, edit the admin account.
2. **Set your default location** --- Config > Installation > System Settings. Enter your city, state, latitude, longitude, and default zoom level so the map centers on your jurisdiction.
3. **Configure map defaults** --- Config > Maps > Map Defaults. Set the center point and default area code.
4. **Add your incident types** --- Config > App Preferences > Incident Types. Add the call types your organization handles, along with response protocols.
5. **Define unit statuses** --- Config > App Preferences > Unit Statuses. Set up the status progression your units use.
6. **Create user accounts** --- Config > Users > User Accounts. Add accounts for your dispatchers and operators.
7. **Add responder units** --- Use the Units page to add your engines, ambulances, patrols, etc.
8. **Add facilities** --- Use the Facilities page to add hospitals, stations, shelters, etc.
9. **Set up incident numbering** --- Config > App Preferences > Incident Numbers. Choose a numbering style (sequential, year-prefix, custom prefix).
10. **Configure severity levels** --- Config > App Preferences > Severity Levels. Customize the names and colors.

## Managing Incident Types

Go to Config > App Preferences > Incident Types.

Each incident type has:
- **Name** --- Short description (for example, "Structure Fire," "Chest Pain," "Lost Hiker").
- **Group** --- Category (Fire, EMS, Law, Admin, etc.).
- **Protocol** --- Response instructions shown to the dispatcher when this type is selected.
- **Severity** --- Default severity level.
- **Color** --- Display color for visual identification on maps and lists.

## Managing Users and Roles

Go to Config > Users > User Accounts.

**To create a user:**
1. Click **Add User**.
2. Enter the username, display name, email, and password.
3. Select a role: Super Admin, Org Admin, Dispatcher, Operator, Read-Only, or Field Unit.
4. Click **Save**.

**To disable a user:**
1. Edit the user account.
2. Uncheck the "Active" checkbox.
3. Click **Save**.

### Default Roles

| Role | What They Can Do |
|------|-----------------|
| **Super Admin** | Full access to everything, including all settings and data. |
| **Org Admin** | Full access within their organization. |
| **Dispatcher** | Create and manage incidents, assign units, view all operational data. |
| **Operator** | View incidents and units, update statuses, add notes. |
| **Read-Only** | View all data but cannot create or change anything. |
| **Field Unit** | Mobile-optimized view: see assigned incidents, update their own status. |

## Managing Places

Go to Config > Locations > **Places**.

Places are named locations --- common buildings, landmarks, intersections, or staging areas --- that you want to be able to drop on an incident with one click instead of typing the address. Think of them as a curated address book for your jurisdiction.

Each place has:
- **Name** --- Short label dispatchers will search for ("Lincoln Elementary," "Tower 12," "Staging Area Alpha").
- **Apply To** --- Whether the place behaves as a city-wide reference (`city`) or a specific building (`bldg`). Used by the incident form's lookup.
- **Street / City / State** --- Resolved address.
- **Lat / Lon / Zoom** --- Coordinates and the default zoom level the map should land on when the place is selected.
- **Information** --- Free-form notes about the place (gate codes, contact info, special access requirements).

The Places panel has a filter box at the top --- type any part of the name or street to narrow the list. Click **New Place** to add one; the inline form prompts for each field. Editing or deleting a place uses the row controls.

Places are also exposed to the new-incident form via the search endpoint, so a dispatcher typing in the location field gets matching places as suggestions.

## Managing Map Overlay Categories

Go to Config > Locations > **Map Overlay Categories**.

Map overlay categories are the groups dispatchers see in the map layer control. Each category gets:

- **Name** --- Label shown in the layer control checkbox.
- **Color** --- The swatch shown next to the name; also used as a default for new markups assigned to this category.
- **Icon** --- Optional Bootstrap icon name for future use in the settings list.
- **Sort Order** --- Lower numbers appear higher in the layer control.
- **Default Visible** --- Whether the category is on by default when a fresh browser opens the map. Set this off for categories that should stay hidden until needed (Parade Routes, Drone Operations Zones, etc.).
- **Description** --- Free-form note describing what belongs in this category.

The seed categories are **Precincts**, **Zones**, **Parade Routes** (off by default), **Hazards**, and **Other**. Each category also shows a **markup_count** badge so you can see at a glance how many drawings are assigned to it.

**To assign a markup to a category**, edit the markup on the map (Alert Zones panel) and pick a category from the dropdown. Markups without a category land in the catch-all **Map Markups** layer.

**Archiving a category** soft-deletes it (the historical reference stays in the audit log) and automatically un-assigns every markup that belonged to it --- so the markups don't disappear, they just go back to the catch-all group until you re-categorise them.

## Managing Email Distribution Lists

Go to Config > Communications > **Email Lists**.

Email lists let you address a single email message to a curated group of recipients --- staff, volunteers, mutual-aid partners --- without typing each address every time. They're used by the notification engine, the standard messages broadcast, and any future feature that needs a "send to this group" hook.

Each list has a name and a description, plus a roster of members. A member can be any of:

- **Member** (a personnel record from the Roster), addressed using the email on file.
- **Constituent** (a contact from the Constituents page), addressed using the email on file.
- **Inline** address --- a free-form email address you type in (`sheriff@county.gov`). Use this for one-off contacts that don't belong in the Roster.
- **List** --- another email list, by reference. This is how you build groups of groups ("All Cities" = Bloomington Staff + Edina Staff + Minnetonka Staff). The resolver detects circular references and refuses to fan out a list that loops back on itself.

**Importing from a CSV** --- click the **Import** button and paste a CSV with `email,name,note` columns (header row required). Inline addresses are created for each row that doesn't already match a member by email.

**Resolving a list** --- the backend provides a `resolve` action that walks the list, follows nested list references, deduplicates, and returns the flat array of addresses. This is what the notification engine calls when it needs to send the actual emails.

**Archiving a list** soft-deletes it; existing references to the list remain valid in the audit log but the list won't be offered as a recipient in new messages.

## Managing OwnTracks Tracking Tokens

OwnTracks is the recommended way to push a member's phone location into TicketsCAD over a secure, low-bandwidth channel. Each phone gets its own per-device token --- no more shared password, and revoking one phone doesn't kick everyone else off the mesh. The full provisioning + rotation lifecycle is built into the Roster page.

### Where to find it

1. Open **Roster**.
2. Click any member in the left list.
3. On the right detail panel, scroll down to find the collapsible card titled **OwnTracks Tracking Tokens** (it carries a blue badge showing the current token count).
4. Click the card header to expand. You'll see five buttons across the top and a table of any existing tokens below.

### Picking a delivery method — the short version

The OwnTracks Android app and the OwnTracks iOS app behave differently. The buttons are labelled with the platform that gets the best experience from each one:

| Button | Best for | Why |
|---|---|---|
| **New: File (Android)** | Android phones | OwnTracks Android does NOT include a QR scanner. The supported import paths are *open a .otrc file* or *tap an `owntracks:///config?inline=…` URL*. The file is the easiest. |
| **New: QR (iOS)** | iPhones / iPads | OwnTracks iOS has a built-in QR scanner under Settings → Configuration. Show the QR on your screen, they scan it inside the OwnTracks app, done. |
| **New: URL** | Either platform if you're sitting next to the member | Plain `owntracks:///config?inline=…` URL you can paste anywhere — Messages, email, browser bar. Android's intent system routes it to OwnTracks; on iOS it does the same. |
| **New: Email** | Remote provisioning | Emails the URL to the member's address on file so they can tap it on the phone directly. |
| **Rotate** | Existing member | Mints a new token, keeps the old one valid for the configured overlap window (default 7 days) so the phone migrates with zero downtime. See [Rotating a key](#rotating-a-key-the-easy-way-with-overlap) below. |

### First-time setup for an Android member — step by step

This is the path Eric and most of the volunteer pool will use.

**Admin side (under a minute):**

1. Make sure the member has a TicketsCAD user account (Settings → Users → User Accounts) — the OwnTracks setup uses their login as the device username.
2. On the Roster, click the member, expand **OwnTracks Tracking Tokens**.
3. Click **New: File (Android)**.
4. Two things happen:
    - Your browser downloads a file named `owntracks-<username>-<YYYYMMDD>.otrc` to your Downloads folder.
    - A second tab opens with a copy of these instructions for the member.
5. The new token appears in the table below with status **active**.

**Get the .otrc file onto the phone — easiest path is downloading it directly on the phone:**

1. Open this admin page on the phone's browser (log in to TicketsCAD on the phone). If the phone doesn't have your admin login, message yourself the page URL — `https://your-server/roster.php?member=<id>` — then sign in on the phone with an admin account.
2. Open the same member, expand **OwnTracks Tracking Tokens**, click **New: File (Android)** on the phone itself.
3. The phone saves `<owntracks-…>.otrc` into its Downloads folder.

If you downloaded the .otrc on a desktop, send it to the phone any way you like — Gmail/Outlook attachment, Google Drive, Signal, USB cable. The file is a normal JSON document; nothing fancy.

**Import on the phone:**

The .otrc file contains the member's bearer token in clear text. Treat it like a password. Don't email it to a shared list, don't post it in a group chat. Once OwnTracks has imported it, delete the file from Downloads.

1. Install the **OwnTracks** app from the Play Store if not already installed.
2. Open the phone's **Files** app (also called *My Files* on Samsung).
3. Navigate to **Downloads**.
4. Tap the `.otrc` file.
5. Android shows an *Open With* dialog. Pick **OwnTracks**. (If OwnTracks is the only app registered for `.otrc` files, Android opens it directly.)
6. OwnTracks opens its **Load preferences** screen showing the new settings — server URL, username, etc. Tap **Apply** (the checkmark button in the top right).
7. OwnTracks immediately starts posting position reports. First fix usually shows up on the dispatcher map within a minute.

**If the Open-With dialog doesn't list OwnTracks** (rare, only happens if you have multiple file-management apps that handle JSON):

1. Open the **OwnTracks** app on the phone.
2. Tap the **☰** menu (top left).
3. **Preferences** → **Configuration management**.
4. Tap **Import**.
5. The phone's file picker opens — navigate to **Downloads**, tap the `.otrc` file.
6. Same Load preferences screen as above — tap **Apply**.

**Verification (back on the admin side):**

- Refresh the Roster member detail after a minute. The token row's **Last Used** column should show a recent timestamp.
- On the dispatcher dashboard map, the member appears as a tracked unit (assuming you also configured a unit binding — see *Setting Up Two-Factor Authentication* → *Linking units to position providers* for that piece).

### First-time setup for an iOS member — step by step

iOS has a working in-app QR scanner. Use the QR path.

**Admin side:** Click **New: QR (iOS)**. A new tab opens with a QR code on it.

**Member side:**

1. Install the **OwnTracks** app from the App Store.
2. Open OwnTracks → tap the **ⓘ Info** tab at the bottom.
3. Tap the **⚙ Settings** gear icon.
4. Tap **Configuration**.
5. Tap the **⋮** menu (top right) → **Scan**.
6. Aim the phone at the QR on the admin's screen.
7. OwnTracks shows the imported settings → tap **Save** / **Accept**.

The URL inside the QR uses the official `owntracks:///config?inline=<base64>` scheme that OwnTracks documents — verified against the OwnTracks Android source (`LoadViewModel.kt`).

**Verification (back on the admin side):**

- After a minute, refresh the Roster member detail. The new token row's **Last Used** column will populate with a recent timestamp.
- On the dispatcher dashboard map, the member will appear as a tracked unit (assuming you also configured a unit binding --- see *Setting Up Two-Factor Authentication* → *Linking units to position providers* for that piece).

### Rotating a key (the easy way, with overlap)

Use this when the secret should change but the phone needs to keep working --- e.g. before a major public event when you want fresh credentials in everyone's pocket, or quarterly as a general hygiene cadence.

1. On the member's record, expand **OwnTracks Tracking Tokens** and click **Rotate**.
2. A confirmation dialog asks "Rotate this member's OwnTracks token? The previous token will keep working for the configured dual-window before expiring." Click OK.
3. A second popup shows the **new secret** in plain text, along with a note like *"Window: 7 days"*. Copy this if you want to deliver it out-of-band (the database only stores a hash, so this is your only chance to see it). Click OK.

What happens behind the scenes:

| Time | What's happening |
|---|---|
| `T+0` | New token minted with a fresh secret. Old token gets `valid_until` set to NOW + 7 days. Both work. |
| `T+(seconds to minutes)` | Phone posts its routine position update. The response body carries a queued `setConfiguration` payload with the new secret. OwnTracks app applies it automatically — the member doesn't need to touch anything. |
| `T+7 days` | Old token's `valid_until` passes. Any further posts using the old secret get rejected. Phone now uses the new secret. |

**Zero downtime for the member.** They never see a "your config has changed" prompt. They just keep using the app.

The 7-day window is configurable via the `owntracks_token_dual_window_days` setting in the database. For a major event, you might tighten it to 24 hours so an attacker who captured the old secret only has one day to use it.

### Revoking a key (the immediate way, no overlap)

Use this when a phone is lost, stolen, or the member has left and you want their credentials dead **right now**.

1. On the member's record, expand **OwnTracks Tracking Tokens**.
2. Find the row for the token you want to kill. Click the red **X** icon at the right end of the row.
3. Confirm "Revoke token #N immediately? The phone will be locked out on its next post."

After revocation, that specific token immediately returns 403 on its next ingest attempt. The row in the table moves to status **revoked**. If the member still legitimately needs to track, click **Rotate** afterwards to give them a fresh credential.

### Stale Token Report

If you suspect some volunteer phones haven't been phoning in (member left the team without telling you, app got uninstalled, device died), the stale-token report shows you which ones.

- Direct URL: `GET /api/owntracks-config.php?action=stale&days=30`
- Returns every un-revoked token whose `created_at` is older than 30 days, sorted by `last_used_at`.

In practice you'd point a browser at that URL, copy the list, and walk through each one with the affected member to decide: revoke and re-issue, or just revoke if they've left.

### Locking down to token auth only

By default, the OwnTracks ingest accepts BOTH the legacy shared-secret method and the per-device tokens, so existing setups keep working through the rollout. Once everyone is on tokens, lock it down:

```sql
INSERT INTO settings (name, value) VALUES ('owntracks_require_token', '1')
ON DUPLICATE KEY UPDATE value = '1';
```

After this, any OwnTracks POST that doesn't authenticate via a valid per-device token returns 403 immediately. This is the recommended posture for any public-event deployment — closes the door on a stolen-shared-secret scenario completely.

### Common questions

**Q: What's the difference between Rotate and Revoke?**
A: Rotate keeps the old key working for 7 days (configurable) while the phone receives the new one — zero downtime. Revoke kills the key instantly with no overlap — for lost/stolen phones.

**Q: Can I provision OwnTracks for someone who isn't a member yet?**
A: No — the username on the phone is the member's TicketsCAD login, so they have to be in the Roster + User Accounts first.

**Q: A member rotated their phone (got a new one). What do I do?**
A: Revoke the old token (so the phone in their drawer can't pretend to be them), then provision a fresh one with **New: URL / QR / Email** on the new device.

**Q: The QR code page won't open / "popup blocked"?**
A: The popup that shows the QR is a new browser tab — make sure popups are allowed for this site, or use **New: URL** and copy/paste the URL manually into a QR generator if you need to display it on a TV/projector.

**Q: I scanned the QR with my Android phone's Camera / Google Lens and it said "The app was not found on your device" even though OwnTracks is installed. What's wrong?**
A: OwnTracks Android does not include a QR scanner, and Android's system Camera / Google Lens cannot route a custom `owntracks://` URL to a third-party app — that's why you got the unhelpful "not found" error. For Android, use **New: File (Android)** instead — that downloads a `.otrc` file you open from the phone's Files app. Android's intent system routes `.otrc` directly to OwnTracks via the app's registered intent filter. (Verified against the OwnTracks Android AndroidManifest: scheme `owntracks` + path `/config` is the only URL handler, and there is no Camera/QR activity declared.)

**Q: Where is the Import option inside the OwnTracks Android app?**
A: ☰ menu → **Preferences** → **Configuration management** → **Import** button. That opens the system file picker. Navigate to **Downloads**, pick the `.otrc` file you downloaded earlier.

**Q: Can I just paste the URL into OwnTracks Android instead of using a file?**
A: Yes — use **New: URL** in TicketsCAD, get the `owntracks:///config?inline=…` URL onto the phone (paste in any app — Messages, Notes, browser bar), then tap the link. Android's intent system hands it to OwnTracks. The file approach is recommended because it works even if the phone doesn't recognise the custom URL scheme (some launchers strip it).

**Q: What if a member loses their phone mid-event?**
A: From any laptop or other phone, log into TicketsCAD admin → Roster → their record → expand OwnTracks Tracking Tokens → click the red **X** on their active row. Their phone is locked out on its next post (within seconds). Their position will stop updating on the dispatcher map.

## Backup Procedures

Go to Config > Installation > Backup / Maintenance.

This panel provides tools for:
- **Database backup** --- Create a backup of your entire database.
- **Table optimization** --- Improve database performance.
- **Cache clearing** --- Clear cached weather tiles and other temporary data.

It is recommended to back up your database regularly, especially before making configuration changes or updating the software.

---

# Part 11: Call Board

The **Call Board** provides a focused dispatch view of all active and recently closed incidents. It is designed for wall-mounted displays or a dedicated dispatch monitor.

## Accessing the Call Board

Navigate to the Call Board page from the navigation menu, or go directly to `callboard.php`.

## What the Call Board Shows

Each incident on the call board displays:
- **Incident ID and type** with a color-coded badge matching the incident type's configured color.
- **Location** (street address, city, state).
- **Severity level** with visual indicator.
- **Status** (Open, Dispatched, Scheduled, or Recently Closed).
- **Assigned unit names** listed alongside each incident so dispatchers can see at a glance which units are responding.
- **Elapsed time** since the incident was created, helping supervisors monitor response times.

## Filtering

Use the filter dropdown at the top to narrow the display by incident type. This is helpful during events where only certain types of calls are relevant.

## Recently Closed Incidents

The call board also shows incidents that were closed within the last 30 minutes (configurable by the administrator). This gives dispatchers awareness of recent activity without cluttering the active view.

---

# Part 12: ICS Forms

TicketsCAD supports creating, editing, and exporting standard ICS (Incident Command System) forms used in emergency management.

## Supported Form Types

| Form | Name | Purpose |
|------|------|---------|
| ICS 202 | Incident Objectives | Documents incident objectives and strategy |
| ICS 205 | Incident Radio Communications Plan | Lists radio frequencies and assignments |
| ICS 205a | Communications List | Contact information for incident personnel |
| ICS 206 | Medical Plan | Medical aid stations, transport, and hospitals |
| ICS 213 | General Message | Standard message form for written communications |
| ICS 213rr | Resource Request Message | Request and track resource orders |
| ICS 214 | Activity Log | Unit or personnel activity log |
| ICS 214a | Individual Activity Log | Individual personnel task tracking |
| ICS 221 | Demobilization Check-Out | Tracks resource demobilization process |

## Creating an ICS Form

1. Navigate to the **ICS Forms** page.
2. Click **New Form** and select the form type.
3. Fill in the form fields. Each form type has its own set of fields matching the official ICS form layout.
4. Optionally link the form to an existing incident.
5. Click **Save as Draft** to save your work, or **Finalize** to mark it as complete.

## Form Statuses

- **Draft** --- The form is being prepared and can still be edited.
- **Final** --- The form has been finalized and is ready for distribution.
- **Sent** --- The form has been transmitted (for example, via Winlink).

## Winlink XML Export

For ICS 213 forms, TicketsCAD can export the form as a Winlink-compatible XML file:

1. Open a finalized ICS 213 form.
2. Click the **Export to Winlink** button.
3. Download the XML file.
4. Import it into Winlink Express or Pat for transmission over amateur radio.

This allows organizations to send formal ICS messages over HF/VHF radio when internet connectivity is unavailable.

---

# Part 13: Mobile Unit Interface

The **Mobile Unit Interface** is a simplified view designed for field personnel using phones or tablets. It provides quick access to essential information without the full desktop layout.

## Accessing the Mobile Interface

Navigate to `mobile.php` in your browser, or install TicketsCAD as a Progressive Web App (PWA) on your mobile device for an app-like experience.

## Mobile Features

The mobile interface provides:
- **Current assignments** --- See incidents you are assigned to with address, type, and status.
- **Status updates** --- Change your unit status (Available, En Route, On Scene, etc.) with a single tap.
- **Navigation** --- Tap an incident address to open it in your device's map application for turn-by-turn directions.
- **Mileage tracking** --- Log odometer readings at the start and end of a response for mileage reports.
- **Quick notes** --- Add notes to your assigned incidents from the field.

## Mileage Logging

When responding to a call:
1. Enter your starting odometer reading.
2. After completing the response, enter your ending odometer reading.
3. The system calculates and logs the mileage automatically.

Mileage data is available in reports for reimbursement and fleet management.

---

# Part 13b: Logging Volunteer Hours

Many TicketsCAD users are volunteer agencies (CERT, ARES, RACES, volunteer
fire, campus security) where members need to log hours that aren't tied to
an active incident --- radio nets, drills, training classes, public-education
events, station maintenance, monthly meetings, and so on. The **My Time**
page collects those hours for monthly and annual reporting.

This is the looser counterpart to the on-incident PAR check and the personal
unit clock-in (Phase 54): both of those track on-duty time during a call,
while **My Time** is the "I spent 2 hours on the Tuesday radio net" bucket.

## Where to find it

- **Personnel menu -> My Time** (navbar dropdown) opens the dedicated page.
- The **My time** widget on the dashboard shows your week / month / year
  totals at a glance, with a quick-add row for logging an entry without
  leaving the dashboard.
- Approvers (Super Admin / Org Admin / Dispatcher roles by default) also
  see **Personnel -> Pending Time Approvals** for the org-wide queue.

## Quick-add from the dashboard

1. Pick a **category** from the dropdown (training, drill, event,
   radio_net, meeting, admin, public_education, deployment, response,
   other).
2. Enter the **minutes** (15-minute increments; the spinner clamps to
   sensible bounds).
3. Click **Log**. The entry's end-time defaults to "now"; the start
   time is computed backward.

The widget refreshes after every save. Click any of the big-number
totals to open a modal with your recent entries.

## Detailed entry from the My Time page

The page is split into two columns:

- **Left panel:** filterable, month-grouped list of your entries. Filter
  by month picker, category dropdown, or status (pending / approved /
  rejected). Click any entry to load it into the right-hand form.
- **Right panel:** the detail / edit form. Edit start, end, category,
  activity type, and notes. Save creates a new entry (when the form is
  blank) or updates the currently selected entry.

The bottom of the page shows monthly and annual rollup totals plus an
"awaiting approval" counter.

### Status meanings

- **pending** (`self_reported`): submitted by the member; the agency
  approver hasn't reviewed it yet.
- **approved**: signed off by an approver. The entry is locked --- only
  an admin can re-open or delete it.
- **rejected**: an approver declined the entry. A rejection reason
  appears below the status badge so the member knows what to fix. The
  member can resubmit by creating a corrected entry.

## Approval workflow

Approvers click the **Approval queue** tab (or the "Pending Time
Approvals" navbar link, which routes to the dedicated single-purpose
queue page) to see every `self_reported` entry across the org.

- Check the boxes next to the entries to review.
- Click **Approve selected** or **Reject selected**. When rejecting,
  a prompt asks for an optional reason (max 255 characters) that gets
  saved to the entry and shown back to the member.

Auto-approval can be configured per-activity in **Settings -> Roles &
Permissions** if certain activities (e.g., monthly meetings with a fixed
duration) shouldn't go through manual review.

## Exporting for tax and reporting

There's no full export tool in this phase --- the data is queryable
via the API:

- **Per-member raw entries:**
  `GET /api/time-entries.php?member_id=N&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`
- **Per-member rollup by activity type:**
  `GET /api/time-entries.php?summary=1&member_id=N` (add the same date
  range to scope it).
- **Org-wide listing (approvers only):**
  `GET /api/time-entries.php?all=1`

Pull the JSON, transform to CSV or PDF as your agency requires. A full
export tool with on-screen download is on the backlog.

## Permission summary

| What | Permission code |
|------|-----------------|
| Log my own hours | `time_entry.edit` (all roles by default) |
| Edit / delete my own entry while pending | `time_entry.edit` (self scope) |
| Approve / reject any entry in the org | `time_entry.approve` (Super, Org Admin, Dispatcher) |
| See the dashboard widget | `widget.time_entries` (all roles by default) |
| Permanently delete a time entry | `time_entry.delete` (Super, Org Admin, Dispatcher) |

---

# Part 14: External Links

The **External Links** page provides a configurable panel of useful links relevant to your organization's operations. Access it from the navigation menu or go to `links.php`.

## What External Links Are For

Administrators can add links to resources that dispatchers and operators frequently need, such as:
- Weather radar and forecast sites.
- State or county emergency management portals.
- Hospital status boards.
- FEMA resource pages.
- Training materials and reference documents.
- Partner agency websites.

## How Links Are Organized

Links are grouped by **category** (for example, "Weather," "Reference," "Partner Agencies"). Each link displays:
- A title and description.
- An icon for visual identification.
- Links open in a new browser tab when clicked.

## Managing Links (Admin)

Administrators can add, edit, reorder, and deactivate links from the Settings page or directly from the External Links page. Deactivated links are hidden from non-admin users but can be re-enabled later.

---

# Part 15: Mesh Bridges (LoRa Radio Backhaul)

TicketsCAD can ingest and send text messages over LoRa-based mesh radios — Meshtastic and MeshCore — through any number of bridge hosts you deploy in the field. The **Mesh Console** at **Settings -> System -> Mesh Bridges** is where administrators see what's connected, watch traffic, send messages out, and configure attached radios remotely.

## What is a "bridge"?

A bridge is a small Linux host (a Proxmox VM, a Raspberry Pi, a NUC, anything that boots Debian and has a USB port) with one or two Heltec V3 (or similar) LoRa radios plugged in. A Python daemon — `bridge_v2.py` — runs on it and:

- listens on each radio for inbound packets,
- forwards every packet to TicketsCAD via authenticated HTTPS,
- polls TicketsCAD for outbound messages and configuration commands, and
- applies them to the attached radio.

Each bridge holds a unique bearer token that authenticates it to TicketsCAD. Tokens are minted from the Mesh Console; the secret is shown ONCE and copied into `/etc/ticketscad/meshbridge.env` on the bridge host.

## The Mesh Console tabs

**Overview.** A card for each registered bridge with online / warning / offline state, last-seen time, packet count, and active-token count. Click **Mint Bridge Token** to create a new bridge entry or rotate an existing bridge's token.

**Live Feed.** The last 80 packets across all bridges (or one selected bridge), refreshing every 5 seconds. Each row shows the receive time, protocol (Meshtastic or MeshCore), which bridge heard it, the source node ID, port kind (TEXT, POSITION, etc.), the payload, and signal metrics (SNR / RSSI / hop count).

**Send / Compose.** Type a message, choose a target bridge (or leave on Any) and protocol, and click **Send**. The message is queued in `mesh_outbox`; the next bridge to poll picks it up within ~5 seconds and transmits.

**Coverage & Latency.** Choose a window (1, 6, 24, or 72 hours). The matrix shows, for each source node heard in that window, how many packets each bridge received plus average SNR/RSSI. If two bridges heard the same packet ID, the latency table shows them as a coverage overlap with the millisecond spread between bridges — useful for confirming geographic coverage and judging propagation delay.

**Device Config.** Pick a bridge, set the firmware-level **long name** + **short name** of its primary radio (visible to every node on the mesh), or queue a reboot. The bridge applies the change at its next poll cycle.

## RBAC

Access to the Mesh Console requires the `action.manage_mesh_bridges` permission. By default this is granted to Super Admin, Org Admin, and Dispatcher roles.

## Setting up a new bridge — quick reference

1. From the Mesh Console click **Mint Bridge Token**, fill in a label (e.g. `eoc-radio-room`), copy the token shown.
2. On the bridge host install Python + the `bridge_v2.py` daemon (see `services/meshtastic/` in the repo).
3. Create `/etc/ticketscad/meshbridge.env` with `CAD_URL=https://<your-host>` and `CAD_TOKEN=<the-token>`.
4. Enable the systemd unit (`meshbridge.service.example` in the repo is a working template).
5. Within ~10 seconds the bridge card on the Overview tab flips to green and packets start appearing in the Live Feed.

---

# Appendix A: Keyboard Shortcuts

TicketsCAD is designed for keyboard-first operation. Here is a complete list of keyboard shortcuts.

## Global Shortcuts (Available on Any Page)

| Shortcut | Action |
|----------|--------|
| **/** | Open the command bar (when not typing in a text field). |
| **Esc** | Close the command bar, clear a selection, or dismiss a dialog. |

## Command Bar Commands

Press **/** to open the command bar, then type one of these commands:

| Command | Action |
|---------|--------|
| `/new` | Open the New Incident form. |
| `/inc` or `/incidents` | Jump to the Active Incidents widget and select the first row. |
| `/res`, `/resp`, or `/responders` | Jump to the Responders widget. |
| `/uni` or `/units` | Jump to the Responders widget. |
| `/fac` or `/facilities` | Jump to the Facilities widget. |
| `/log` | Jump to the Activity Log widget. |
| `/detail` | Open the detail view for the currently selected incident. |
| `/zel` or `/zello` | Toggle the Zello radio panel. |

Press **Enter** to execute the command. Press **Esc** to close the command bar.

## Dashboard Keyboard Navigation

When a widget has focus (click on a widget to give it focus):

| Shortcut | Action |
|----------|--------|
| **Up Arrow** | Move selection to the previous row. |
| **Down Arrow** | Move selection to the next row. |
| **Enter** | Open the detail page for the selected item. |
| **Esc** | Clear the selection and leave the widget. |

### Incidents Widget Only

When the Incidents widget has focus and a row is selected:

| Shortcut | Action |
|----------|--------|
| **D** | Dispatch --- open the assignment panel. |
| **V** | View --- open the incident detail page. |
| **E** | Edit the incident. |
| **P** | Pop out --- open the incident in a new window. |
| **X** | Close the incident (with confirmation). |
| **U** | Units --- open the unit assignment panel. |

## New Incident Form

| Shortcut | Action |
|----------|--------|
| **Tab** | Move to the next field in the tab order. |
| **Shift+Tab** | Move to the previous field. |
| **Ctrl+Enter** | Submit the incident from any field. |
| **Enter** (on Lookup button) | Geocode the address. |

## Two-Factor Authentication

| Shortcut | Action |
|----------|--------|
| Type 6 digits | Auto-submits the 2FA form when all 6 digits are entered. |

---

# Appendix B: Troubleshooting

## I Cannot Log In

**Check these things first:**
1. Make sure Caps Lock is off.
2. If this is a brand-new installation, try the default credentials: username `admin`, password `admin`.
3. If you see a "locked" message with a countdown timer, your account has been temporarily locked due to too many failed attempts. Wait for the timer to expire (usually 15 minutes) and try again.
4. Clear your browser's cookies for the TicketsCAD site and try again.
5. If none of the above works, ask your administrator to check your account or reset your password.

## I Am Locked Out of 2FA

If you have lost your authenticator app and do not have backup codes:
- Contact your administrator. They can disable 2FA for your account from Config > Users > Two-Factor Auth.

## Dashboard Widgets Are Not Loading (Spinning or Showing Errors)

1. Look at the **SSE indicator** (small dot in the navigation bar). If it is red, your connection to the server has dropped. Try refreshing the page.
2. Press **F12** to open your browser's developer tools and check the Console tab for error messages.
3. Try navigating directly to the health check page in your browser: add `/api/health.php` to your server address. If it does not return a response, the server may be down.
4. Contact your administrator if the problem persists.

## The Map Is Blank or Grey

1. Check your internet connection. The default map tiles load from the internet (OpenStreetMap).
2. If your organization uses a custom map provider, the API key may be invalid or expired. Contact your administrator.
3. If you are behind a strict firewall, outbound access to tile servers may be blocked. Contact your network administrator.

## Address Lookup Returns No Results or Wrong Results

1. Try a more specific address. Include the city and state (for example, "123 Main St, Springfield, IL" instead of just "123 Main St").
2. The geocoder works best when your organization's default location is configured correctly (this biases results toward your area). Ask your administrator to verify Config > Maps > Map Defaults.
3. The default geocoding service (Nominatim) has usage limits. If lookups fail repeatedly, wait a few seconds between attempts.

## Real-Time Updates Have Stopped

If the dashboard data is stale and the SSE indicator is red:
1. Refresh the page (press F5 or Ctrl+R).
2. If refreshing does not help, the server's real-time event stream may be down. Contact your administrator.

## Audio Alerts Are Not Playing

1. Check that the speaker icon in the navigation bar is not muted (it should show sound waves, not an X).
2. Your browser may be blocking audio. Most browsers require at least one user interaction (like a click) on the page before they allow sounds to play. Click anywhere on the dashboard and try again.
3. Check that your computer's volume is turned up and not muted.

## The Interface Looks Broken After an Update

1. **Hard refresh** your browser: press **Ctrl+Shift+R** (Windows/Linux) or **Cmd+Shift+R** (Mac). This forces the browser to reload all files from the server instead of using its cache.
2. If that does not fix it, clear your browser's cache for the TicketsCAD site.
3. If you installed TicketsCAD as a mobile app (PWA), you may need to uninstall and reinstall it to pick up the updated files.

## Printing Issues

1. Press **Ctrl+P** (Windows/Linux) or **Cmd+P** (Mac) to print any page.
2. The navigation bar, toolbars, and interactive elements are automatically hidden when printing.
3. For the dashboard and map views, use **Landscape** orientation for best results.

## Something Else Is Not Working

1. Try refreshing the page.
2. Try logging out and logging back in.
3. Try a different browser (Chrome, Firefox, and Edge all work well).
4. If the problem persists, note the exact error message (if any) and what you were doing when it happened, then contact your administrator.

---

# Part N — Features added in 2026-05

This section documents features added in the May 2026 development push. Existing features earlier in this guide are unchanged. Each subsection below is also covered by a video module — see `docs/TRAINING-CURRICULUM.md` for the matching script.

## Time tracking

### Logging your own time

Go to **Personnel → Roster**, find yourself in the list, click your row to open your detail. The **Time Log** card shows recent entries; click **Log Time** to add one.

- **Activity type** is required. Choose from the lookup: Net, Drill, Public Service Event, Training, Meeting, EOC Watch, Field Operations, Equipment Maintenance, Other.
- **Started / Ended** are required. Use the datetime picker. The system enforces a 30-day cap on back-dating — entries older than 30 days are rejected.
- **Incident** is optional. If you log time for a specific incident response, link it.
- **Notes** are optional but encouraged.

After saving, the entry shows in your Time Log card with status `self_reported`. It stays editable to you until an admin approves or rejects it.

### Approving someone else's time (admins)

If you have the `time_entry.approve` permission (Dispatcher / Org Admin / Super Admin by default):

- The **Personnel → Pending Time Approvals** menu item is visible to you.
- Open it to see every entry awaiting review across the org. Click **Approve** or **Reject** per row.
- You can also approve/reject from the member's roster Time Log card directly.

### Auto-approve

Go to **Settings → Roles & Permissions → RBAC Settings** (or directly to `/roles.php` and the Settings tab):

- **Off** (default) — every new entry stays `self_reported` until admin approval.
- **On** — every new entry is created in `approved` state. Use this for low-overhead volunteer ops.
- **By activity type** — entries auto-approve only if their activity type is flagged with `auto_approve = 1`. Set the per-type flag on each row in `time_activity_types`.

### Time reports

Go to **Reports** and use the Personnel button group:

- **Roster snapshot** — a directory dump.
- **Time summary** — totals by member over a date range.
- **License expirations** — FCC + FEMA + custom certs expiring within N days.
- **Membership dues due** — members whose dues fall within a window.
- **Inactive members** — members with no time entries in 90 days, or status flagged Inactive.
- **DMR ID inventory** — pulled from `member.notes` regex.

All reports support CSV export.

## Roles and permissions

The full admin guide lives in `docs/RBAC-GUIDE.md`. The short version:

- Permissions are atoms (`incident.create`, `time_entry.approve`).
- Roles bundle permissions.
- Grants assign a role to a user with a scope (where it applies) and an optional expiry (when it stops).
- The Privilege-Escalation Guard prevents an admin from granting a role they don't already hold.

Open the dedicated **Roles & Permissions** page from the Personnel dropdown (or `Settings → Config → Roles & Permissions`).

### Time-bound role grants

When you grant Sam the Dispatcher role for "covers Pat June 1-8 23:59", set the **Expires** field on the Grant Role modal. The grant disappears from `rbac_can()` checks the moment the deadline passes — Sam reverts to whatever other roles they hold.

The expire-grants cron sweeps physically-expired rows nightly. Schedule it once:

```
# Linux cron (nightly at 3am)
0 3 * * *  ejosterberg  /usr/bin/php /var/www/newui/tools/expire_grants.php

# Windows Task Scheduler (XAMPP)
schtasks /create /tn "TicketsCAD expire grants" /tr ^
  "C:\xampp\8.2.4\php\php.exe C:\xampp\8.2.4\htdocs\newui\tools\expire_grants.php" ^
  /sc daily /st 03:00
```

The cron is bookkeeping, not security — expired grants are already invisible to `rbac_can()`. The cron just keeps the table tidy and writes matching `expire` audit-log entries.

### Self-approval setting

`Settings → Roles & Permissions → Require separate approver`:

- **Off** (default) — anyone with `time_entry.approve` can approve any entry, including their own.
- **On** — the system blocks self-approval at the RBAC layer regardless of role.

Used by ops where separation of duties matters (paid staff, regulatory audit). Volunteer ops typically leave this off.

### Audit trail

The **Audit Trail** tab on `/roles.php` shows every RBAC event: grants, revokes, expirations. Filter by user, activity, date range. CSV export for compliance.

## Upgrading from legacy v3.44

See `docs/UPGRADING-FROM-V3.md`. The TL;DR:

```
php tools/upgrade/preflight.php          # green/red signal
php tools/upgrade/postcheck.php --snapshot pre.json
php tools/upgrade/run.php                # the upgrade
php tools/upgrade/postcheck.php --compare pre.json
```

If anything fails, the orchestrator stops and points you at the rollback procedure in `tools/upgrade/ROLLBACK.md`. Plan on 15 minutes of dispatch downtime for a small org; longer for very large ones.

## Training videos

24 video modules cover the curriculum end-to-end:

- Modules 1–8: dispatching
- Modules 9–16: administration
- Modules 17–24: tools (roster, time keeping, scheduling, reports, mobile)

See `docs/TRAINING-CURRICULUM.md` for the full module list with outlines.

---

*TicketsCAD NewUI v4.0 --- Free software. Real dispatch. Zero cost.*
