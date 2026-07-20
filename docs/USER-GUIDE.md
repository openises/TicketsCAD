# TicketsCAD NewUI v4.0 --- User and Administrator Guide

**TicketsCAD** is a free, open-source Computer Aided Dispatch system for volunteer fire departments, ARES/RACES amateur radio groups, CERT teams, search and rescue organizations, small EMS agencies, event medical services, and campus security.

This guide covers every feature in the NewUI v4.0 interface.

---

## Table of Contents

1. [Getting Started](#1-getting-started)
2. [Dashboard](#2-dashboard)
3. [Incident Management](#3-incident-management)
4. [Personnel and Teams](#4-personnel-and-teams)
5. [Facilities](#5-facilities)
6. [Mapping](#6-mapping)
7. [Communications](#7-communications)
8. [Search and Reports](#8-search-and-reports)
9. [Security](#9-security)
10. [Administration](#10-administration)
11. [SOP (Standard Operating Procedures)](#11-sop-standard-operating-procedures)
12. [Full-Screen Situation View](#12-full-screen-situation-view)
13. [Keyboard Shortcuts](#13-keyboard-shortcuts)
14. [Mobile Access](#14-mobile-access)
15. [Troubleshooting](#15-troubleshooting)

---

## 1. Getting Started

### 1.1 System Requirements

| Component | Requirement |
|-----------|-------------|
| Web Server | Apache 2.4+ with `mod_rewrite` enabled |
| PHP | 8.0 or newer (8.2 recommended) |
| Database | MySQL 5.7+ or MariaDB 10.3+ |
| Browser | Any modern browser (Chrome, Firefox, Edge, Safari) |
| Operating System | Windows (XAMPP/WAMP), Linux (LAMP), or macOS (MAMP) |

TicketsCAD can run on hardware as modest as a Raspberry Pi or a $5/month VPS.

### 1.2 Installation Steps

1. **Download** the TicketsCAD NewUI files and place them in your web server's document root (for example, `htdocs/newui/` on XAMPP).

2. **Create the database.** Open phpMyAdmin or a MySQL client and create a new database:
   ```sql
   CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

3. **Create a database user** with full privileges on that database:
   ```sql
   CREATE USER 'newui'@'localhost' IDENTIFIED BY 'your_password';
   GRANT ALL PRIVILEGES ON newui.* TO 'newui'@'localhost';
   FLUSH PRIVILEGES;
   ```

4. **Edit `config.php`** in the NewUI root directory. Set your database host, name, username, and password:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'newui');
   define('DB_USER', 'newui');
   define('DB_PASS', 'your_password');
   ```

5. **Run the seed SQL.** Import the schema and seed data from the `sql/` directory using phpMyAdmin or the command line:
   ```bash
   mysql -u newui -p newui < sql/schema.sql
   mysql -u newui -p newui < sql/seed.sql
   ```

6. **Open the application** in your browser at `http://localhost/newui/` (or wherever you installed it).

### 1.3 First Login

The default administrator credentials are:

| Field | Value |
|-------|-------|
| Username | `admin` |
| Password | `admin` |

**Change the default password immediately** after your first login by going to **Config > User Accounts** and editing the admin account.

### 1.4 Initial Configuration Checklist

After your first login, work through these configuration tasks:

- [ ] Change the default admin password
- [ ] Set your organization's default location (Config > System Settings)
- [ ] Configure map defaults (Config > Maps > Map Defaults)
- [ ] Add your incident types (Config > App Preferences > Incident Types)
- [ ] Define unit statuses (Config > App Preferences > Unit Statuses)
- [ ] Create user accounts for your dispatchers (Config > Users > User Accounts)
- [ ] Add your responder units (Units page)
- [ ] Add your facilities (Facilities page)
- [ ] Set up incident numbering (Config > App Preferences > Incident Numbers)
- [ ] Configure severity levels (Config > App Preferences > Severity Levels)
- [ ] Set your preferred theme (Day/Night toggle in the toolbar)

---

## 2. Dashboard

The dashboard is the main screen you see after logging in. It provides a real-time overview of your dispatch operations through customizable widgets.

### 2.1 Widget Layout (GridStack)

The dashboard uses a drag-and-drop grid layout powered by GridStack. Each panel on the dashboard is a "widget" that you can:

- **Move** --- Click and drag any widget's title bar to reposition it.
- **Resize** --- Drag the bottom-right corner of any widget to make it larger or smaller.
- **Show/Hide** --- Use the Widget Toggles bar below the main navigation to turn individual widgets on or off.

### 2.2 Available Widgets

| Widget | Description |
|--------|-------------|
| **Active Incidents** | Lists all open incidents with type, address, severity, and assigned units. |
| **Responders** | Shows all responder units and their current status (Available, En Route, On Scene, etc.). |
| **Facilities** | Displays hospitals, shelters, stations, and other facilities with their current status. |
| **Map** | Interactive Leaflet map showing incident locations, unit positions, and facility markers. |
| **Statistics** | Incident counts and trends --- open, closed today, response times. |
| **Activity Log** | Scrolling feed of recent system activity (dispatches, status changes, notes). |
| **Controls** | Quick-action buttons for common dispatch operations. |
| **Communications** | Chat messages and communication activity. |

### 2.3 Saving and Restoring Layouts

Your widget arrangement is personal to your account. The dashboard provides several layout management tools in the Widget Toggles bar:

- **Save** --- Your layout saves automatically when you move or resize widgets.
- **Undo** --- Click the back-arrow button to undo the last layout change.
- **Snapshots** --- Click the bookmark icon to open the snapshot menu. You can:
  - **Save a snapshot** --- Capture your current layout with a name (for example, "Normal Operations" or "Major Event").
  - **Restore a snapshot** --- Click any saved snapshot name to restore that layout.
  - **Delete a snapshot** --- Remove snapshots you no longer need.
- **Reset** --- Click the circular-arrow button to restore the default widget layout.

### 2.4 Light/Dark Theme Toggle

The toolbar in the upper-right corner has a Day/Night toggle:

- **Sun icon** --- Day mode (light theme). Best for well-lit dispatch centers.
- **Moon icon** --- Night mode (dark theme). Reduces eye strain in low-light environments and prevents screen glare from distracting field personnel.

Your theme preference is saved to your account and persists across sessions.

### 2.5 Real-Time Updates (SSE)

The dashboard receives real-time updates from the server using Server-Sent Events (SSE). You do not need to refresh the page to see new incidents, status changes, or messages.

The SSE connection status is shown as a small dot in the toolbar:

- **Green dot** --- Connected. Updates are flowing in real time.
- **Yellow dot** --- Reconnecting. The connection was briefly interrupted and is being restored.
- **Red dot** --- Disconnected. Check your network connection.

### 2.6 Toolbar Clock

A 24-hour clock is displayed in the toolbar. This shows your browser's local time and updates every second. It is useful for logging dispatch times without looking away from the screen.

### 2.7 Audio Mute

Click the speaker icon in the toolbar to mute or unmute audio alerts. When muted, no sounds will play for new incidents or status changes. The icon changes to indicate the current state.

---

## 3. Incident Management

### 3.1 Creating a New Incident

TicketsCAD is designed for keyboard-first dispatch. When a call comes in, the workflow is:

1. Click **New** in the navigation bar (or press the keyboard shortcut).
2. The New Incident form opens with two columns:
   - **Left column** --- Form fields organized into collapsible sections.
   - **Right column** --- Interactive map and responder assignment panel.

#### Form Sections

The form has 8 collapsible sections. You can expand or collapse each section by clicking its header.

**1. Classification**
- **Incident Type** --- Select from the dropdown. Incident types are configured by your administrator and may include categories like "Structure Fire," "Medical Emergency," "Traffic Accident," "Welfare Check," etc.
- **Protocol** --- When you select an incident type that has a response protocol, the protocol text appears in a panel above the map. Read this aloud to the caller if applicable.
- **Severity** --- Auto-populated from the incident type, but you can override it. Levels range from 1 (Critical) to 5 (Informational).

**2. Location**
- **Address** --- Type the street address. Press Tab to move to the Lookup button.
- **Lookup** --- Click or press Enter to geocode the address. The map will center on the location and drop a marker.
- **City / State** --- Auto-filled by the geocoder, but editable. If you change the city, the state field clears automatically so you can correct it.
- **Cross Street** --- Auto-populated from the geocoder's neighborhood/suburb data when available.
- **Zip Code** --- Optional field (hidden by default; enable in display settings).

**3. Contact**
- **Caller Name** --- Name of the person reporting the incident.
- **Phone** --- Caller's phone number.
- **Contact Notes** --- Any additional contact information.

**4. Facilities**
- Select a receiving facility (hospital, shelter, etc.) if applicable.

**5. Time and Status**
- **Reported Time** --- Defaults to the current time. Editable for delayed reports.
- **Status** --- Initial status of the incident (typically "Open" or "Dispatched").

**6. Call History**
- Search for previous calls from the same phone number or address. This helps identify repeat callers or known locations.

**7. Patients**
- Add patient information for medical incidents. Click the "Add Patient" button to add a row.
- A counter badge shows the number of patients entered.
- Each patient can have name, age, gender, and chief complaint fields.
- Click the remove button on any patient row to delete it.

**8. Additional Details**
- **Description** --- Free-text description of the incident.
- **Notes** --- Internal notes not shared with field units.
- **Major Incident** --- Check this box to flag as a major incident.

#### Keyboard Workflow

The form is designed for speed. The tab order flows logically through the most commonly used fields:

1. Incident Type (Tab index 1)
2. Address (Tab index 2)
3. Lookup button (Tab index 3)
4. City (Tab index 4)
5. State (Tab index 5)
6. Caller Name (Tab index 6)
7. Phone (Tab index 7)
8. Description (Tab index 8)
9. Through remaining fields...

Press **Ctrl+Enter** from any field to submit the incident immediately.

#### Assigning Responders

The right column shows a list of available responder units below the map. To assign responders:

1. Use the search filter to find a specific unit by name or callsign.
2. Click the checkbox next to each unit you want to assign.
3. Assigned units will be dispatched when you submit the incident.

#### Submitting the Incident

Click the **Create Incident** button or press **Ctrl+Enter**. The system will:
- Save the incident to the database.
- Notify all assigned responder units.
- Update the dashboard in real time for all logged-in dispatchers.
- Redirect you back to the dashboard (or to the incident detail page).

### 3.2 Incident Types and Protocols

Incident types define the categories of calls your organization handles. Each type can include:

- **Name** --- Short description (for example, "Structure Fire," "Chest Pain," "Lost Hiker").
- **Group** --- Category grouping (Fire, EMS, Law, Admin, etc.).
- **Protocol** --- Response instructions displayed to the dispatcher when this type is selected.
- **Severity** --- Default severity level for this type.
- **Color** --- Display color for visual identification on the map and lists.

Incident types are managed in **Config > App Preferences > Incident Types**.

### 3.3 Severity Levels

Severity levels indicate the urgency of an incident:

| Level | Name | Typical Use |
|-------|------|-------------|
| 1 | Critical | Life-threatening emergencies, structure fires |
| 2 | High | Serious incidents requiring immediate response |
| 3 | Medium | Standard calls requiring timely response |
| 4 | Low | Non-urgent calls |
| 5 | Informational | Logging/documentation only |

Colors and names for severity levels can be customized in **Config > App Preferences > Severity Levels**.

### 3.4 Location and Geocoding

When you enter an address in the New Incident form:

1. Type the street address in the Address field.
2. Press Tab to reach the Lookup button, then press Enter (or click the button).
3. The geocoder queries Nominatim (OpenStreetMap) to find the location.
4. If found, the map zooms to that location and places a marker.
5. The City, State, and Cross Street fields auto-populate from the geocoder results.

**Reverse geocoding:** Click anywhere on the map to place a marker and auto-fill the address fields from that location.

**Smart correction:** If you edit the City field after geocoding, the State field clears automatically. Pressing Tab from the City field routes focus to the Lookup button so you can re-geocode with the corrected city.

The geocoder uses a geographic viewbox bias centered on your organization's configured default location, so local addresses are prioritized.

### 3.5 Assigning Responders

Responders (units) can be assigned to incidents in several ways:

- **During creation** --- Check units in the New Incident form's right-column panel.
- **From Incident Detail** --- Open an existing incident and add or remove unit assignments.
- **From the Dashboard** --- Drag-and-drop assignment (if supported by your widget configuration).

### 3.6 Patient Management

For medical incidents, you can track one or more patients:

1. Open the **Patients** section on the New Incident form.
2. Click **Add Patient** for each patient.
3. Fill in available information (name, age, gender, chief complaint).
4. The patient count badge updates automatically.
5. Click the X button on a patient row to remove it.

### 3.7 Major Incident Linking

A "major incident" groups multiple related calls under one umbrella. This is useful for large-scale events where many individual calls relate to the same situation (for example, a wildfire generating multiple structure fire calls, evacuations, and medical emergencies).

- **Create a major incident** from the incident detail view.
- **Link existing incidents** to a major incident.
- **Command structure** fields allow you to record Gold, Silver, and Bronze command positions with names and locations.
- **Navigate** between linked incidents from any incident in the group.

Major incidents are managed through the API endpoint `api/major-incidents.php`.

### 3.8 Closing and Reopening Incidents

From the **Incident Detail** view:

1. Open the incident you want to close.
2. Change the status to "Closed" (or your organization's equivalent).
3. Add any closing notes.
4. Click Save.

To reopen a closed incident, change its status back to "Open" and save.

### 3.9 Incident Detail View

Click any incident in the dashboard's Active Incidents widget or the Incident List to open its detail view.

The detail view shows:

- **Header** --- Incident number, type, severity, and status with color coding.
- **Location** --- Address, city, state, and map with a marker.
- **Timeline** --- Chronological list of all actions, notes, and status changes.
- **Assignments** --- Currently assigned responder units.
- **Patients** --- Patient information (if applicable).
- **Actions** --- Buttons to add notes, change status, assign units, and export.
- **Navigate** --- Button to open turn-by-turn directions in an external map application.
- **ICS-213 Export** --- Generate a Winlink-compatible ICS-213 form from the incident data.

### 3.10 Incident List

The Incident List page (`incident-list.php`) provides a filterable, sortable table of all incidents.

- **Filter** by status (open, closed, all), date range, incident type, or severity.
- **Sort** by any column header.
- **Click** any row to open its detail view.

---

## 4. Personnel and Teams

### 4.1 Roster Management

The Roster page (`roster.php`) is the central place to manage your organization's members.

Each member record can include:
- Name and contact information
- Callsign(s) --- amateur radio and/or GMRS
- Communications licenses with issue and expiration dates
- Organization memberships
- Communications identifiers (DMR radio ID, APRS callsign, Meshtastic ID, etc.)
- Certifications and training records
- Member status and type

### 4.2 Adding Members

1. Navigate to the **Roster** page from the Personnel dropdown menu.
2. Click **Add Member**.
3. Fill in the member's information.
4. Click **Save**.

#### FCC Callsign Lookup

When adding a member with an amateur radio or GMRS callsign:

1. Enter the callsign in the lookup field.
2. Click **Lookup** to query the FCC database.
3. If found, click **Apply to Form** to auto-fill the member's name and license details.

The lookup supports both amateur radio callsigns (via FCC ULS) and GMRS callsigns.

### 4.3 Certifications and Training

Track member qualifications under two categories:

- **Certifications** --- Formal credentials like EMT, Paramedic, HAZMAT Technician, CPR, etc. Each has an issue date and expiration date.
- **Training** --- Completed courses like ICS-100, ICS-200, FEMA IS-700, etc.

Certification types are managed in **Config > Personnel > Certifications**. Training types are managed in **Config > Personnel > Training**.

### 4.4 Team Management

The Teams page (`teams.php`) lets you organize members into functional teams.

Each team has:
- **Name** --- Team designation (for example, "Engine 1 Crew," "CERT Alpha," "Net Control").
- **NIMS Resource Type** --- Resource typing per the National Incident Management System (for example, "Type 1 Engine," "Type 2 Medical Team").
- **Members** --- List of assigned team members.
- **Description** --- Notes about the team's capabilities and purpose.

Teams are managed in **Config > Personnel > Teams** or directly on the Teams page.

### 4.5 Organization Memberships

Members can belong to one or more organizations. Each membership can have a role (Admin, Dispatcher, Operator, Read-Only, etc.) that determines what that member can do within that organization.

Manage organizations in **Config > Personnel > Organizations**.

### 4.6 Communications Identifiers

Each member can have multiple communication identifiers for different modes:

| Mode | Identifier | Example |
|------|-----------|---------|
| Amateur Radio | Callsign | N0NKI |
| GMRS | Callsign | WSKZ850 |
| DMR | Radio ID | 3120001 |
| APRS | Callsign-SSID | N0NKI-9 |
| Meshtastic | Node ID | !a1b2c3d4 |
| Zello | Username | eric.dispatch |

Identifiers are managed from the member's roster detail page.

### 4.7 Scheduling

The Scheduling page (`scheduling.php`) supports two types of time management:

#### Shifts
- Define recurring shift patterns (for example, 24-on/48-off, day/night rotation).
- Assign members to shifts.
- View who is on duty at any given time.

#### Events
- Create one-time or recurring events (training sessions, meetings, community events).
- Define time slots and roles needed for each event.
- Members can self-sign up for available slots.

### 4.8 ICS Positions

Configure Incident Command System positions used by your organization (Incident Commander, Operations Section Chief, Safety Officer, etc.). These positions are available for assignment during major incidents.

Manage ICS positions in **Config > Personnel > ICS Positions**.

---

## 5. Facilities

### 5.1 Facility List

The Facilities page (`facilities.php`) displays all facilities in a searchable list showing name, type, status, and location.

### 5.2 Adding and Editing Facilities

1. Navigate to **Facilities** from the navigation bar.
2. Click **Add Facility** to create a new one, or click an existing facility to view and edit it.
3. Fill in the facility information:
   - **Name** --- Facility name (for example, "Memorial Hospital," "Station 5," "Community Shelter").
   - **Type** --- Category (Hospital, Fire Station, Shelter, Command Post, etc.). Types are configured in **Config > App Preferences > Facility Types**.
   - **Address** --- Street address with geocoding support.
   - **Latitude/Longitude** --- Auto-filled from geocoding or manually entered.
   - **Description** --- Notes, capabilities, access instructions.
   - **Status** --- Current operational status (Open, Closed, Full, etc.).
4. Click **Save**.

### 5.3 Facility Types

Facility types are configured in **Config > App Preferences > Facility Types**. Examples include:

- Hospital / Emergency Room
- Fire Station
- Emergency Shelter
- Staging Area
- Command Post
- Vehicle Storage (ICC)
- Helipad

### 5.4 Bed and Capacity Tracking

For hospitals and shelters, TicketsCAD supports category-based capacity tracking:

- **Bed categories** --- ICU beds, ER beds, shelter cots, etc.
- **Capacity** --- Total number of beds/cots in each category.
- **Occupancy** --- Current number in use.
- **Availability** --- Automatically calculated (capacity minus occupancy).

Status badges (Open, Closed, Full) with color coding help dispatchers quickly identify which facilities can accept patients.

Capacity is managed through the facility detail and edit pages, with API support via `api/facility-capacity.php`.

### 5.5 Facility Map Markers

All facilities with latitude/longitude coordinates appear as markers on the dashboard map and the new incident map. Markers can be color-coded by facility status.

---

## 6. Mapping

### 6.1 Interactive Map

TicketsCAD uses Leaflet.js with OpenStreetMap tiles for its interactive map. The map appears in several places:

- **Dashboard widget** --- Shows all active incidents, unit positions, and facilities.
- **New Incident form** --- For placing and verifying incident locations.
- **Incident Detail** --- Shows the specific incident location.
- **Situation View** --- Full-screen operational map.

**Basic map controls:**
- **Pan** --- Click and drag to move the map.
- **Zoom** --- Use the scroll wheel, or click the +/- buttons.
- **Click** --- Click on the map to place a marker (on the New Incident form).
- **Markers** --- Click any marker to see details about that incident, unit, or facility.

### 6.2 Map Markups

Map markups let you draw shapes and annotations on the map for operational planning.

**Drawing tools:**
- **Polygons** --- Outline areas (search zones, event perimeters, staging areas).
- **Circles** --- Mark radius zones (hazmat zones, evacuation rings).
- **Lines** --- Draw routes, boundaries, or barriers.
- **Rectangles** --- Mark rectangular areas.
- **Markers** --- Place labeled points of interest.

Each markup can have:
- Name and description
- Color, opacity, and line width
- Fill color and opacity
- Category assignment

**Markup categories:**
- Region Boundary
- Facility Catchment
- Exclusion Zone
- Ring Fence
- Banners / Labels
- Basemap overlays

Categories are managed in the admin settings. Each category can be toggled on or off independently, so you can show event zones during an event and hide them afterward.

Markups are managed through `api/map-markups.php` and persist in the database across sessions.

### 6.3 Road Conditions Overlay

The road conditions feature lets dispatchers log and display hazardous road conditions:

- **Condition types** --- Slippery, flooding, closed, construction, debris, etc.
- **Location** --- Address or map point with lat/lng.
- **Description** --- Details about the condition.
- **Expiration** --- Conditions auto-clear after a set time (for example, after a storm passes).

Active road conditions display as overlay markers on the map so dispatchers can warn responders about hazards en route.

Configure condition types in **Config > Locations > Road Conditions**.

### 6.4 Weather Overlay

The dashboard map supports weather overlays from OpenWeatherMap:

- Temperature
- Precipitation
- Cloud cover
- Wind

Weather tiles are cached locally to reduce API calls. Configure your OpenWeatherMap API key in **Config > Installation > API Keys**.

### 6.5 Geofencing

Geofences are virtual boundaries on the map. When a tracked unit enters or leaves a geofence, the system can generate an alert.

Uses include:
- Alerting when a unit arrives at or departs from a scene.
- Monitoring whether units stay within their assigned area.
- Triggering notifications when units approach a hazard zone.

Configure geofences in **Config > Location > Geofencing**. The API endpoint is `api/geofences.php`.

### 6.6 Location Tracking Providers

TicketsCAD supports multiple location tracking protocols for real-time unit position reporting:

| Provider | Technology | Typical Use |
|----------|-----------|-------------|
| **APRS** | Amateur radio packet | Ham radio operators with GPS-equipped radios |
| **Meshtastic** | LoRa mesh network | SAR/CERT teams using low-cost mesh devices |
| **OwnTracks** | Mobile app (MQTT/HTTP) | Smartphone-based tracking |
| **DMR** | MotoTRBO GPS | Organizations using DMR digital radios |
| **Zello** | Zello Work API | Teams using Zello push-to-talk |
| **Manual** | Dispatcher-entered | Fallback when no automatic tracking |

Each unit can have multiple location sources with configurable priority. If the primary source goes stale (no update within a threshold), the system falls back to the next source.

Configure location providers in **Config > Location > Location Providers** and **Provider Settings**.

### 6.7 Warn Locations (Proximity Alerts)

Warn locations are flagged addresses or GPS coordinates that trigger a warning when a new incident is created nearby. Examples:

- Known aggressive animals at an address
- Hazardous materials storage
- Structural hazards (condemned buildings)
- Threats to responder safety

Configure warn locations in **Config > Locations > Warn Locations**. Each entry has a lat/lng, radius, alert text, severity level, and optional expiration date.

### 6.8 Map Configuration

Configure map behavior in **Config > Maps**:

- **Map Defaults** --- Default center point (lat/lng), zoom level, and area code for your jurisdiction.
- **Tile Providers** --- Choose and configure map tile sources. The default is OpenStreetMap. Additional providers may require API keys (Bing Maps, Google Maps, Esri, etc.).

---

## 7. Communications

### 7.1 Chat System

TicketsCAD includes a built-in chat system for real-time communication between dispatch positions.

**Channel types:**
- **All** --- Organization-wide messages visible to everyone.
- **By Role** --- Messages to a specific role (for example, all dispatchers).
- **By Incident** --- Messages tied to a specific active incident.
- **Direct Message** --- Private messages between two users.

The chat API is at `api/chat.php`.

### 7.2 Signals and Codes

Signals (also called dispatch codes or "hints") are predefined short messages that can be sent with a single click. Examples:

| Code | Message |
|------|---------|
| EX-1 | Return to station ASAP |
| EX-2 | Switch to TAC channel |
| EX-3 | Request additional units |

Signals are managed in **Config > App Preferences > Field Help Text** and **Config > Communications > Standard Messages**.

### 7.3 SMS Configuration

TicketsCAD supports outbound and inbound SMS messaging through multiple providers:

| Provider | Notes |
|----------|-------|
| **Twilio** | Most widely used; supports 2-way SMS |
| **BulkVS** | Budget option for bulk messaging |
| **Pushbullet** | Sends SMS through a connected Android phone |
| **Generic REST** | Custom HTTP integration with any provider |

Configure your SMS provider in **Config > Communications > SMS Configuration**. Enter your provider's API credentials, phone number, and endpoint settings.

### 7.4 Email Configuration

Configure outbound email for notifications and alerts:

- **Method** --- Choose between PHP `mail()` (sendmail) or SMTP relay.
- **SMTP settings** --- Host, port, encryption (TLS/SSL), username, and password.
- **From address** --- The email address that notifications come from.
- **Email lists** --- Create distribution groups for batch notifications.

Configure email in **Config > Communications > Email Configuration** and **Email Lists**.

### 7.5 Slack Integration

Connect TicketsCAD to a Slack workspace to send incident notifications and status updates to a Slack channel.

Configure Slack in **Config > Communications > Slack**. You will need a Slack webhook URL from your Slack workspace's app settings.

### 7.6 Telegram Integration

Send notifications to a Telegram chat or group. Configure your Telegram bot token and chat ID in **Config > Communications > Telegram**.

### 7.7 Zello Network Radio

Zello is a push-to-talk (PTT) application used by many volunteer organizations. TicketsCAD integrates with the Zello Work API for:

- User management
- Text messaging
- Location sharing

Configure Zello in **Config > Communications > Zello Network Radio**. API endpoints include `api/zello-config.php`, `api/zello-messages.php`, `api/zello-token.php`, and `api/zello-user.php`.

### 7.8 Meshtastic Mesh Networking

Meshtastic is a LoRa-based mesh networking platform used by SAR and CERT teams. TicketsCAD supports:

- Text messaging over the mesh network
- GPS location sharing from mesh nodes

Configure Meshtastic in **Config > Communications > Mesh (Meshtastic)**.

### 7.9 Radio Messaging (DMR)

For organizations using MotoTRBO DMR digital radios, TicketsCAD can send and receive text messages over the DMR network.

Configure in **Config > Communications > Radio Messaging**.

### 7.10 Webhooks

Webhooks let TicketsCAD push event notifications to external systems in real time. When an event occurs (new incident, status change, unit assignment, etc.), TicketsCAD sends an HTTP POST request to your configured URL with the event data in JSON format.

Configure webhooks in **Config > Communications > Webhooks / Events**. The API endpoint is `api/webhooks.php`.

### 7.11 Standard Messages

Standard messages are pre-written text templates that can be sent through any communication channel. They support variable substitution --- placeholders like `{ticket_number}`, `{address}`, `{time}`, and `{date}` are replaced with actual values at send time.

Each standard message can be enabled or disabled per channel (email, SMS, radio).

Configure in **Config > Communications > Standard Messages**.

### 7.12 Sound and Audio Alerts

TicketsCAD can play audio alerts in your browser for events like:

- New incident created
- Responder status change
- New chat message
- Assignment notification

Configure which events trigger sounds and select audio files in **Config > App Preferences > Sound / Alerts**. The system uses the browser's Web Audio API, so no plugins are required.

You can mute all audio with the speaker button in the toolbar.

---

## 8. Search and Reports

### 8.1 Search

The Search page (`search.php`) provides a unified search across all data in the system.

**Search across:**
- Incidents (by number, address, type, description, or date range)
- Responders/Units (by name, callsign, or status)
- Facilities (by name, type, or location)
- Members (by name, callsign, or certification)
- Activity log entries

The search API endpoint is `api/incident-search.php`.

### 8.2 Reports

The Reports page (`reports.php`) provides pre-built reports and the ability to generate custom queries.

**Available report types:**
- **Incident Summary** --- Count of incidents by type, severity, or time period.
- **Response Time Analysis** --- Average time from dispatch to on-scene by unit or type.
- **Unit Activity** --- Hours worked, incidents responded to, mileage logged.
- **Daily/Weekly/Monthly Activity** --- Incident volume over time.

Reports can be filtered by date range, incident type, unit, and other criteria. The API endpoint is `api/reports.php`.

### 8.3 ICS Forms and Winlink Export

TicketsCAD can export incident data as ICS-213 (General Message) forms compatible with the Winlink amateur radio email system.

**To export an ICS-213 form:**
1. Open the Incident Detail view.
2. Click the **ICS-213** export button.
3. The system generates an XML file in the Winlink standard format.
4. Download the XML file and import it into Winlink Express or Pat for radio transmission.

The export API is at `api/winlink-export.php`.

### 8.4 Import and Export

The Import/Export page (`import-export.php`) allows bulk data management:

**Export:**
- Export incidents, members, facilities, or other data to CSV or JSON format.
- Filter exports by date range or other criteria.
- PHI (Protected Health Information) fields are excluded from exports unless you have the appropriate permission.

**Import:**
- Import data from CSV files.
- Map CSV columns to database fields.
- Preview data before committing the import.
- Supports importing constituents (contacts) with `api/constituents-import.php`.

**Legacy Migration:**
- Import data from legacy Tickets v3.x installations using `api/legacy-import.php`.

---

## 9. Security

### 9.1 Two-Factor Authentication (TOTP)

TicketsCAD supports time-based one-time passwords (TOTP) for an additional layer of login security. This works with authenticator apps like Google Authenticator, Authy, Microsoft Authenticator, or any TOTP-compatible app.

#### Enabling 2FA (Administrator)

1. Go to **Config > Users > Two-Factor Auth**.
2. Enable 2FA for the system.
3. Optionally, make 2FA mandatory for specific roles (for example, required for Super Admin and Org Admin, optional for Dispatcher).

#### Enrolling in 2FA (User)

1. After an administrator enables 2FA, you will see a 2FA enrollment prompt on your next login (if mandatory) or in your profile settings (if optional).
2. Open your authenticator app and scan the QR code displayed on screen.
3. Enter the 6-digit code from your authenticator app to verify enrollment.
4. **Save your backup codes.** You will be shown a set of one-time-use recovery codes. Write these down or save them in a secure location. Each backup code can only be used once.

#### Using 2FA

After enrollment, each login will require:
1. Your username and password (as usual).
2. A 6-digit code from your authenticator app.

#### Backup Codes

If you lose access to your authenticator app (phone lost or replaced):
1. Enter one of your backup codes instead of the 6-digit code.
2. Each backup code can only be used once.
3. After logging in, re-enroll in 2FA with your new device.
4. Generate new backup codes from your profile or the admin panel.

#### Remembered Devices

If enabled by your administrator, you can check "Remember this device" during login. This skips the 2FA prompt for 30 days on that specific device. Administrators can restrict this feature to trusted networks (private IP ranges by default).

#### Disabling 2FA

- **User:** Go to your profile settings and disable 2FA (if your administrator allows it).
- **Administrator:** Go to **Config > Users > Two-Factor Auth** to disable 2FA for specific users or system-wide.

The 2FA API is at `api/tfa.php`, with helper modules in `inc/tfa.php` and `inc/totp.php`.

### 9.2 Role-Based Access Control (RBAC)

RBAC controls what each user can see and do in the system.

#### Default Roles

| Role | Description |
|------|-------------|
| **Super Admin** | Full access to all features, settings, and data |
| **Org Admin** | Full access within their organization |
| **Dispatcher** | Create and manage incidents, assign units, view all operational data |
| **Operator** | View incidents and units, update statuses, add notes |
| **Read-Only** | View all data but cannot create or modify anything |
| **Field Unit** | Mobile-optimized view: see assigned incidents, update own status |

#### Permission Categories

Permissions are organized into four categories:

- **Screen access** --- Which pages/screens a role can open (Dashboard, New Incident, Search, Reports, Config, etc.).
- **Widget access** --- Which dashboard widgets a role can see and interact with.
- **Action permissions** --- What actions a role can perform (create incidents, assign units, close incidents, delete records, manage users, etc.).
- **Field visibility** --- Which data fields a role can see (patient information, phone numbers, addresses may be hidden or masked for lower-privilege roles).

#### Creating Custom Roles

1. Go to **Config > Users > Roles and Levels**.
2. Click **Add Role**.
3. Name the role and set its permissions using the checkbox matrix.
4. Click **Save**.

#### Assigning Roles to Users

1. Go to **Config > Users > User Accounts**.
2. Edit a user account.
3. Select the role from the Role dropdown.
4. Click **Save**.

#### Migrating from Legacy Levels

If you are upgrading from Tickets v3.x, the legacy numeric access levels (0-6) are automatically mapped to the new role system:

| Legacy Level | New Role |
|-------------|----------|
| 0 | Super Admin |
| 1 | Org Admin |
| 2 | Dispatcher |
| 3 | Operator |
| 4 | Read-Only |
| 5 | Field Unit |
| 6 | Guest |

The RBAC system is managed through `api/rbac.php` with helper functions in `inc/rbac.php`.

### 9.3 Login Security

#### Account Lockout

After a configurable number of failed login attempts (default: 5), the account is locked for a configurable duration (default: 15 minutes). This prevents brute-force attacks.

Configure lockout settings in **Config > Users > Login Settings**.

#### Session Management

- **Active sessions** --- View all currently active sessions for any user.
- **Force logout** --- Administrators can force-logout any session (for example, if a workstation is left unattended).
- **Session timeout** --- Sessions expire after a configurable period of inactivity (default: varies by installation).

Session management is handled by `inc/session-manager.php` with the API at `api/login-security.php`.

#### Security Headers

When accessed over HTTPS, TicketsCAD automatically sets security headers:

- **HSTS** (HTTP Strict Transport Security) --- Tells browsers to always use HTTPS.
- **CSP** (Content Security Policy) --- Prevents loading of untrusted scripts or styles.
- **Secure cookie flags** --- Session cookies are marked `Secure` and `SameSite=Strict`.

Security headers are configured in `inc/security-headers.php`.

### 9.4 Field Encryption (HTTP Protection)

For installations that do not use HTTPS (for example, a dispatch center on a local network without SSL), TicketsCAD offers client-side field encryption:

**How it works:**
1. The server generates an RSA key pair during installation.
2. The public key is embedded in forms that contain sensitive data.
3. JavaScript encrypts fields (username, password, patient information) with the public key before submitting the form.
4. The server decrypts the data with the private key.

**Important:** This is not a replacement for HTTPS. A persistent warning banner is displayed when the system detects it is running over HTTP. Use HTTPS in production whenever possible.

**Administrator controls:**
- Enable/disable field encryption in **Config > Users > Field Encryption**.
- The encryption toggle is useful for debugging form submissions.

The field encryption module is at `inc/field-encrypt.php`.

### 9.5 Audit Logging

TicketsCAD maintains a detailed audit log of all significant actions.

**What is logged:**
- Login attempts (successful and failed), including IP address and browser
- Incident creation, updates, and status changes
- User account changes (creation, modification, role changes)
- Configuration changes
- Data access to sensitive fields (patient information, medical records)
- Data exports

**Viewing the audit log:**
1. Go to **Config > System > Audit Log**.
2. Filter by user, action type, date range, IP address, or severity.
3. Review entries. Each entry shows the timestamp, user, action, details, and IP address.

Audit logs are append-only --- they cannot be deleted through the application. The log API is at `api/audit-log.php` with the helper module at `inc/audit.php`.

---

## 10. Administration

### 10.1 Accessing Settings

Click **Config** in the navigation bar to open the Settings page. The settings page has a sidebar with 8 sections:

| Section | Icon | What It Contains |
|---------|------|-----------------|
| **System** | Heart Pulse | System Health, Audit Log, Import/Export |
| **Installation** | Server Rack | System Settings, API Keys, Lookup Services, Database Info, Backup/Maintenance |
| **App Preferences** | Sliders | Incident Types, Severity Levels, Field Help Text, Unit Statuses, Facility Types, Display Settings, Sound/Alerts, Incident Numbers |
| **Users** | Shield Lock | User Accounts, Roles and Levels, Login Settings, Two-Factor Auth, Field Encryption |
| **Personnel** | Person Badge | Organizations, Members/Personnel, Teams, Certifications, ICS Positions, Equipment Types, Vehicle Types, Training, Member Statuses, Member Types |
| **Communications** | Chat Dots | Notification Rules, Email Config, Email Lists, SMS Config, Telegram, Slack, Radio Messaging, Comm/Location Modes, Zello, Meshtastic, Webhooks, Standard Messages, Chat Settings |
| **Locations** | Pin | Facilities, Regions, Places, Warn Locations, Constituents, Road Conditions |
| **Maps** | Map | Map Defaults, Tile Providers |
| **Location** | Broadcast | Location Providers, Provider Settings, Geofencing |

Only administrators (Super Admin and Org Admin) can access the Settings page.

### 10.2 User Account Management

Go to **Config > Users > User Accounts** to manage user accounts.

**To create a new user:**
1. Click **Add User**.
2. Enter the username, display name, email, and password.
3. Select a role (Super Admin, Org Admin, Dispatcher, Operator, Read-Only, Field Unit).
4. Click **Save**.

**To edit a user:**
1. Click the user's name in the list.
2. Modify any fields.
3. Click **Save**.

**To disable a user:**
1. Edit the user account.
2. Uncheck the "Active" checkbox.
3. Click **Save**. The user will no longer be able to log in.

### 10.3 Incident Numbering

Configure how incident numbers are generated in **Config > App Preferences > Incident Numbers**.

**Numbering styles:**

| Style | Example | Description |
|-------|---------|-------------|
| None | (blank) | No auto-numbering |
| Sequential | 12345 | Simple incrementing number |
| Prefix + Sequential | INC-00001 | Custom label prefix with padded number |
| Year + Sequential | 26-00001 | Two-digit year prefix with padded number |

**Settings:**
- **Label** --- Custom prefix text (for example, "INC", "CAD", your agency abbreviation).
- **Separator** --- Character between prefix and number (for example, "-", "/", ".").
- **Next number** --- Manually set the next number in the sequence.
- **Year reset** --- Automatically restart the sequence at the beginning of each year.

### 10.4 Standard Messages and Signals

Standard messages are pre-written templates for common dispatch communications.

**Config > App Preferences > Field Help Text** --- Manage signal codes (short codes with associated text).

**Config > Communications > Standard Messages** --- Manage full message templates with variable substitution.

Template variables include:
- `{ticket_number}` --- Incident number
- `{address}` --- Incident address
- `{type}` --- Incident type
- `{time}` --- Current time
- `{date}` --- Current date
- `{user}` --- Dispatcher name

### 10.5 Regions Management

Regions define geographic territories for your organization. Each region can have:

- A name and type (EMS, Fire, Security, Civil)
- A default center point and zoom level
- A default area code, city, and state
- A boundary polygon (linked to map markups)

Regions can be used to filter data so dispatchers only see incidents in their assigned region.

Configure regions in **Config > Locations > Regions**.

### 10.6 System Settings

**Config > Installation > System Settings** contains global configuration:

- **Default location** --- City, state, latitude, longitude, and zoom level for your jurisdiction.
- **Timezone** --- System timezone for timestamp display.
- **Date format** --- Date display preference.
- **Military time** --- Toggle between 12-hour and 24-hour time display.
- **Session timeout** --- How long an idle session remains active.
- **Login banner** --- Custom text shown on the login page.
- **Phone formatting** --- Toggle phone number formatting.

### 10.7 API Keys

External service integrations require API keys. Configure them in **Config > Installation > API Keys**:

- **OpenWeatherMap** --- Weather overlay on maps.
- **Bing Maps** --- Alternative map tiles.
- **Google Maps** --- Alternative map tiles and geocoding.
- **Other services** --- As configured by your organization.

### 10.8 Lookup Services

Configure external lookup services in **Config > Installation > Lookup Services**:

- **FCC ULS** --- Amateur radio license lookups.
- **Nominatim** --- OpenStreetMap geocoding (address to coordinates).
- **Zip code** --- Zip code to city/state lookups via `api/zipcode-lookup.php`.

### 10.9 Database Info and Maintenance

**Config > Installation > Database Info** shows:
- Database server version
- Database name and size
- Table count and row counts
- Connection status

**Config > Installation > Backup / Maintenance** provides:
- Database backup tools
- Table optimization
- Cache clearing

### 10.10 Captions and Internationalization (i18n)

TicketsCAD supports label overrides so you can rename any term in the interface to match your organization's terminology. For example:

- "Incident" can become "Call" or "Event"
- "Responder" can become "Unit" or "Apparatus"
- "Facility" can become "Hospital" or "Location"

The captions API is at `api/captions.php` with the i18n helper at `inc/i18n.php`.

### 10.11 Webhooks (Outbound Events)

Webhooks push real-time event notifications to external systems. Configure endpoints that receive JSON payloads when events occur:

- New incident created
- Incident status changed
- Unit assigned/unassigned
- Responder status changed
- And other configurable event types

Configure webhooks in **Config > Communications > Webhooks / Events**. The webhook module is at `inc/webhooks.php`.

### 10.12 Constituents (Contacts)

The Constituents page (`constituents.php`) manages a database of external contacts --- community members, partner agencies, vendors, media contacts, etc.

- **Import** contacts from CSV files via `api/constituents-import.php`.
- **Export** contacts to CSV via `api/constituents-export.php`.
- **Search** and filter contacts.

Configure constituent settings in **Config > Locations > Constituents**.

### 10.13 Display Settings

Configure display preferences in **Config > App Preferences > Display Settings**:

- **Zip code field** --- Show or hide the zip code field on forms (default: hidden).
- **Dispatch status codes** --- Customize the status progression (for example, D/R/O/FE/FA/Clear).
- **Other display toggles** as configured.

### 10.14 Legacy Data Migration

If you are upgrading from Tickets v3.x to NewUI v4.0:

1. Go to **Config > System > Import / Export**.
2. Use the Legacy Import tool to connect to your existing Tickets v3.x database.
3. The import maps legacy tables and fields to the NewUI schema.
4. Review the import preview before committing.

The legacy import API is at `api/legacy-import.php`.

---

## 11. SOP (Standard Operating Procedures)

The SOP page (`sop.php`) provides a built-in document viewer and editor for your organization's standard operating procedures.

### 11.1 Viewing Procedures

1. Click **SOP** in the navigation bar.
2. Browse the list of available procedures.
3. Click any procedure to read it.

### 11.2 Creating and Editing SOPs

1. Click **New SOP** to create a new procedure.
2. Enter a title and write the content. The editor supports formatted text.
3. Click **Save**.

To edit an existing SOP:
1. Open the procedure.
2. Click **Edit**.
3. Make your changes.
4. Click **Save**. Previous versions are retained for revision history.

SOP management uses the following API endpoints:
- `api/sop-pages.php` --- List and retrieve SOPs.
- `api/sop-save.php` --- Create or update an SOP.
- `api/sop-delete.php` --- Delete an SOP.
- `api/sop-revisions.php` --- View revision history.

---

## 12. Full-Screen Situation View

The Situation View (`situation.php`) opens a full-screen display designed for wall-mounted monitors in a dispatch center or EOC.

### 12.1 Opening the Situation Display

Click the **Full Screen** button in the navigation bar (the expand icon next to "Situation"). The view opens in a new browser tab or window.

### 12.2 What It Shows

- **Full-screen map** with incident markers color-coded by severity.
- **Incident list overlay** showing active calls with status, type, address, and assigned units.
- **Auto-refresh** --- The display updates in real time via SSE.

### 12.3 Time-Range Filters

Use the "Change display" dropdown to switch between:

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
| Last Year | All incidents from last year |

---

## 13. Keyboard Shortcuts

TicketsCAD is designed for keyboard-first operation. Here are the available shortcuts:

### Global Shortcuts

| Shortcut | Action |
|----------|--------|
| **/** | Open the command bar (type a command after the slash) |

### New Incident Form

| Shortcut | Action |
|----------|--------|
| **Ctrl+Enter** | Submit the incident form |
| **Tab** | Move to the next field in tab order |
| **Shift+Tab** | Move to the previous field |
| **Enter** (on Lookup button) | Geocode the entered address |

### Command Bar

The command bar activates when you press `/` from most pages. Available commands:

| Command | Action |
|---------|--------|
| `/new` | Open the New Incident form |
| `/find [query]` | Search for incidents, units, or facilities |
| `/status [unit] [status]` | Change a unit's status |
| `/note [incident] [text]` | Add a note to an incident |

The command bar supports:
- **Autocomplete** with fuzzy matching
- **Command history** with up/down arrow keys
- **Context-aware suggestions** (unit IDs, incident numbers)

---

## 14. Mobile Access

### 14.1 PWA Installation

TicketsCAD NewUI is a Progressive Web App (PWA). You can install it on your phone or tablet for an app-like experience:

**On Android (Chrome):**
1. Open `http://your-server/newui/` in Chrome.
2. Tap the three-dot menu.
3. Tap "Add to Home screen" or "Install app."
4. Tap "Install" when prompted.

**On iOS (Safari):**
1. Open `http://your-server/newui/` in Safari.
2. Tap the Share button (box with up arrow).
3. Scroll down and tap "Add to Home Screen."
4. Tap "Add."

The installed app opens without browser chrome (no address bar or tabs) for a cleaner experience.

### 14.2 Responsive Layout

The NewUI automatically adapts its layout to your screen size:

- **Desktop** (1280px+) --- Full layout with sidebar navigation and multi-column views.
- **Tablet** (768px-1279px) --- Condensed layout with collapsible sidebar.
- **Phone** (below 768px) --- Single-column layout with hamburger menu navigation.

Widgets on the dashboard stack vertically on narrow screens. Forms use full-width inputs on mobile.

---

## 15. Troubleshooting

### 15.1 Cannot Log In

**Symptoms:** Login page rejects valid credentials.

**Solutions:**
1. Verify Caps Lock is off.
2. Try the default credentials (`admin` / `admin`) if this is a fresh installation.
3. Check if your account has been locked due to too many failed attempts. Wait for the lockout period to expire (default: 15 minutes) or ask an administrator to unlock it.
4. Clear your browser cookies for the site and try again.
5. Check the PHP error log for database connection issues.

### 15.2 Dashboard Widgets Not Loading

**Symptoms:** Widgets show spinners or error messages.

**Solutions:**
1. Check the browser developer console (F12) for JavaScript errors.
2. Verify the SSE connection indicator in the toolbar is green.
3. Check that the API endpoints are accessible by navigating to `http://your-server/newui/api/health.php` directly. It should return a JSON response.
4. Check the PHP error log at your server's log directory.

### 15.3 Map Not Displaying

**Symptoms:** Map area is blank or grey.

**Solutions:**
1. Check your internet connection --- the default OpenStreetMap tiles load from the internet.
2. If using a custom tile provider, verify the API key is entered correctly in **Config > Maps > Tile Providers**.
3. Check the browser console for tile loading errors.
4. If behind a strict firewall, your organization may need to allow outbound access to `tile.openstreetmap.org` on port 443.

### 15.4 Geocoding Not Working

**Symptoms:** Address lookup returns no results or incorrect results.

**Solutions:**
1. Try a more specific address (include city and state).
2. Check that your configured geocoding provider is reachable.
3. Nominatim (the default) has usage limits --- if you see rate-limit errors, wait a few seconds between lookups.
4. Verify the map defaults (Config > Maps > Map Defaults) have the correct center point for your jurisdiction. The geocoder uses this as a geographic bias.

### 15.5 Real-Time Updates Stopped

**Symptoms:** Dashboard data is stale, SSE indicator shows red.

**Solutions:**
1. Refresh the browser page.
2. Check that the `api/stream.php` endpoint is accessible.
3. Verify your web server supports long-running PHP scripts (Apache should have `mod_php` or PHP-FPM configured with an adequate timeout).
4. Check for server resource issues (memory, CPU, max connections).

### 15.6 JSON Error on API Endpoints

**Symptoms:** API calls return HTML error text instead of JSON, or the browser console shows "Unexpected token" errors.

**Cause:** PHP warnings or notices are being output before the JSON response.

**Solutions:**
1. Open `config.php` and ensure `display_errors` is set to `0` for production.
2. Check the PHP error log for the specific warning or notice.
3. If a specific API endpoint fails, check that all required database tables and columns exist. TicketsCAD includes self-healing patterns that attempt to fix missing columns, but some issues may require manual intervention.

### 15.7 Database Connection Failed

**Symptoms:** Blank pages or database error messages.

**Solutions:**
1. Verify the database credentials in `config.php`.
2. Confirm the MySQL/MariaDB service is running.
3. Test the connection manually: open phpMyAdmin and try to log in with the same credentials.
4. Check that the database and all expected tables exist.

### 15.8 Slow Performance

**Solutions:**
1. Enable PHP OPcache if not already enabled.
2. Check MySQL slow query log for expensive queries.
3. Ensure the weather tile cache directory (`cache/`) is writable --- if the cache cannot be written, every map view triggers a fresh API call.
4. On older hardware, reduce the number of dashboard widgets.

### 15.9 Clearing Browser Cache

If the interface looks broken after an update:

1. **Hard refresh:** Press Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac).
2. **Clear cache:** Open browser settings and clear cached images and files for your TicketsCAD URL.
3. **Service worker:** If using the PWA, you may need to unregister the service worker. In Chrome, go to Developer Tools > Application > Service Workers and click "Unregister."

### 15.10 Print Issues

TicketsCAD includes a print stylesheet. To print the dashboard, incident detail, or any page:

1. Press Ctrl+P (or Cmd+P on Mac).
2. The print stylesheet automatically hides navigation, toolbars, and interactive elements.
3. Use "Landscape" orientation for the dashboard and map views.

---

## Appendix A: Complete Page Reference

| Page | File | Description |
|------|------|-------------|
| Dashboard | `index.php` | Main operational display with widgets |
| Login | `login.php` | Authentication page |
| New Incident | `new-incident.php` | Create a new incident |
| Incident List | `incident-list.php` | Browse and filter all incidents |
| Incident Detail | `incident-detail.php` | View/edit a specific incident |
| Units | `units.php` | Responder unit list |
| Unit Detail | `unit-detail.php` | View a specific unit |
| Unit Edit | `unit-edit.php` | Edit a unit's properties |
| Roster | `roster.php` | Personnel management |
| Teams | `teams.php` | Team management |
| Scheduling | `scheduling.php` | Shift and event scheduling |
| Facilities | `facilities.php` | Facility list |
| Facility Detail | `facility-detail.php` | View a specific facility |
| Facility Edit | `facility-edit.php` | Edit a facility |
| Search | `search.php` | Global search |
| Reports | `reports.php` | Reporting and analytics |
| Settings | `settings.php` | System configuration (admin only) |
| SOP | `sop.php` | Standard Operating Procedures |
| Situation | `situation.php` | Full-screen situation display |
| Status Board | `status.php` | System health and status |
| Equipment | `equipment.php` | Equipment tracking |
| Vehicles | `vehicles.php` | Vehicle management |
| Constituents | `constituents.php` | Contact management |
| Import/Export | `import-export.php` | Data import and export |
| Profile | `profile.php` | User profile settings |

## Appendix B: Complete API Reference

| Endpoint | Purpose |
|----------|---------|
| `api/audit-log.php` | Audit log entries |
| `api/auth.php` | Authentication |
| `api/call-history.php` | Call history search |
| `api/callsign-lookup.php` | FCC callsign lookup |
| `api/captions.php` | UI label overrides (i18n) |
| `api/chat.php` | Chat messaging |
| `api/comm-identifiers.php` | Communication identifiers |
| `api/compliance.php` | Compliance and privacy |
| `api/config-admin.php` | Admin configuration |
| `api/constituents.php` | Contacts CRUD |
| `api/constituents-export.php` | Export contacts to CSV |
| `api/constituents-import.php` | Import contacts from CSV |
| `api/dashboard-data.php` | Dashboard widget data |
| `api/equipment.php` | Equipment tracking |
| `api/events.php` | Event management |
| `api/facilities.php` | Facility list |
| `api/facility-capacity.php` | Bed/capacity tracking |
| `api/facility-detail.php` | Single facility details |
| `api/facility-save.php` | Create/update facility |
| `api/file-upload.php` | File attachments |
| `api/geofences.php` | Geofence management |
| `api/health.php` | System health check |
| `api/ics-positions.php` | ICS position management |
| `api/import-export.php` | Bulk data import/export |
| `api/incident-assign.php` | Unit assignment to incidents |
| `api/incident-create.php` | Create new incident |
| `api/incident-detail.php` | Single incident details |
| `api/incident-list.php` | Incident list with filters |
| `api/incident-search.php` | Search incidents |
| `api/incident-types.php` | Incident type management |
| `api/incident-update.php` | Update existing incident |
| `api/incidents.php` | Active incidents |
| `api/layout.php` | Dashboard layout save/restore |
| `api/legacy-import.php` | Import from Tickets v3.x |
| `api/location.php` | Location tracking |
| `api/log.php` | Activity log |
| `api/login-security.php` | Login security settings |
| `api/major-incidents.php` | Major incident management |
| `api/map-config.php` | Map configuration |
| `api/map-markups.php` | Map drawing/annotations |
| `api/members.php` | Member/personnel CRUD |
| `api/organizations.php` | Organization management |
| `api/personnel-config.php` | Personnel configuration |
| `api/proximity-warnings.php` | Warn location alerts |
| `api/rbac.php` | Role-based access control |
| `api/reports.php` | Report generation |
| `api/responder-delete.php` | Delete a responder unit |
| `api/responder-detail.php` | Single responder details |
| `api/responder-save.php` | Create/update responder |
| `api/responder-status.php` | Update responder status |
| `api/responders.php` | Responder unit list |
| `api/road-conditions.php` | Road condition management |
| `api/scheduled.php` | Scheduled tasks |
| `api/service-uptime.php` | Service uptime monitoring |
| `api/shift-assignments.php` | Shift assignment management |
| `api/shifts.php` | Shift definitions |
| `api/sop-delete.php` | Delete an SOP |
| `api/sop-pages.php` | List/retrieve SOPs |
| `api/sop-revisions.php` | SOP revision history |
| `api/sop-save.php` | Create/update SOP |
| `api/statistics.php` | Incident statistics |
| `api/stream.php` | Server-Sent Events stream |
| `api/teams.php` | Team management |
| `api/tfa.php` | Two-factor authentication |
| `api/theme.php` | Theme toggle |
| `api/training.php` | Training records |
| `api/unit-status-manage.php` | Unit status type management |
| `api/unit-statuses.php` | Unit status definitions |
| `api/unit-types.php` | Unit type definitions |
| `api/upload.php` | General file upload |
| `api/vehicles.php` | Vehicle management |
| `api/weather-proxy.php` | Weather tile caching proxy |
| `api/webhooks.php` | Webhook management |
| `api/winlink-export.php` | ICS-213 Winlink XML export |
| `api/zello-config.php` | Zello configuration |
| `api/zello-messages.php` | Zello messaging |
| `api/zello-token.php` | Zello authentication |
| `api/zello-user.php` | Zello user management |
| `api/zipcode-lookup.php` | Zip code to city/state |

## Appendix C: Settings Panel Reference

Below is every configuration panel accessible from the Settings page, organized by sidebar section.

### System
- System Health (links to `status.php`)
- Audit Log
- Import / Export (links to `import-export.php`)

### Installation
- System Settings
- API Keys
- Lookup Services
- Database Info
- Backup / Maintenance

### App Preferences
- Incident Types
- Severity Levels
- Field Help Text (Signals)
- Unit Statuses
- Facility Types
- Display Settings
- Sound / Alerts
- Incident Numbers

### Users
- User Accounts
- Roles and Levels
- Login Settings
- Two-Factor Auth
- Field Encryption

### Personnel
- Organizations
- Members / Personnel
- Teams
- Certifications
- ICS Positions
- Equipment Types
- Vehicle Types
- Training
- Member Statuses
- Member Types

### Communications
- Notification Rules
- Email Configuration
- Email Lists
- SMS Configuration
- Telegram
- Slack
- Radio Messaging
- Comm / Location Modes
- Zello Network Radio
- Mesh (Meshtastic)
- Webhooks / Events
- Standard Messages
- Chat Settings

### Locations
- Facilities
- Regions
- Places
- Warn Locations
- Constituents
- Road Conditions

### Maps
- Map Defaults
- Tile Providers

### Location (Tracking)
- Location Providers
- Provider Settings
- Geofencing

---

*TicketsCAD NewUI v4.0 --- Free software. Real dispatch. Zero cost.*
