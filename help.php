<?php
/**
 * NewUI v4.0 - Built-in Help System
 *
 * Searchable help topics organized by category.
 * All content stored as PHP arrays — no database needed.
 * Client-side search filtering with print support.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/inc/i18n.php';
require_once __DIR__ . '/inc/rbac.php';

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


$user     = e($_SESSION['user']);
$level = current_role_name();
$theme    = $_SESSION['day_night'] ?? 'Day';
$bs_theme = ($theme === 'Night') ? 'dark' : 'light';
$csrf     = csrf_token();
$active_page = 'help';

// ── Help Content ────────────────────────────────────────────────
// Each category has an icon, a label, and an array of topics.
// Each topic has a slug (used for anchoring), a title, and HTML body.

$help_categories = [

    'getting-started' => [
        'icon'  => 'bi-rocket-takeoff',
        'label' => 'Getting Started',
        'topics' => [
            [
                'slug'  => 'logging-in',
                'title' => 'Logging In',
                'body'  => '
<p>Open your web browser and navigate to the address your administrator provided (for example, <code>http://your-server/newui/</code>).</p>
<ol>
    <li>Enter your <strong>Username</strong> and <strong>Password</strong>.</li>
    <li>Choose your preferred theme &mdash; <strong>Day</strong> (light) or <strong>Night</strong> (dark).</li>
    <li>Click <strong>Log In</strong>.</li>
</ol>
<p>If your administrator has enabled Two-Factor Authentication (2FA), you will see an additional screen asking for a 6-digit code from your authenticator app. Enter the code and it will auto-submit.</p>
<p>If your account is locked after too many failed attempts, a countdown timer will appear. Wait for the timer to expire, then try again.</p>
'
            ],
            [
                'slug'  => 'dashboard-overview',
                'title' => 'The Dashboard',
                'body'  => '
<p>After logging in you land on the <strong>Dashboard</strong>. This is your main workspace &mdash; a live overview of your dispatch operation.</p>
<p>The dashboard uses movable, resizable panels called <strong>widgets</strong>:</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Widget</th><th>What It Shows</th></tr></thead>
    <tbody>
        <tr><td>Statistics</td><td>Quick counts: open incidents, unassigned calls, units on scene, available responders, and more.</td></tr>
        <tr><td>Active Incidents</td><td>A table of every open incident. Click a row to see details.</td></tr>
        <tr><td>Responders</td><td>All responder units with name, handle, type, status, and assignment count.</td></tr>
        <tr><td>Facilities</td><td>Hospitals, shelters, stations with name, type, status, and hours.</td></tr>
        <tr><td>Map</td><td>Live map with markers for incidents, units, and facilities.</td></tr>
        <tr><td>Activity Log</td><td>Recent events: dispatches, status changes, notes.</td></tr>
        <tr><td>Controls</td><td>Quick-action buttons for common tasks.</td></tr>
        <tr><td>Communications</td><td>Buttons for Chat, SMS, Radio, Zello, and Alerts.</td></tr>
    </tbody>
</table>
<h6>Moving and Resizing</h6>
<ul>
    <li><strong>Move:</strong> Click and drag a widget&apos;s title bar.</li>
    <li><strong>Resize:</strong> Drag the bottom-right corner of any widget.</li>
    <li><strong>Toggle:</strong> Use the Widget Toggles bar to show or hide individual widgets.</li>
</ul>
<h6>Layout Tools</h6>
<ul>
    <li><strong>Reset</strong> &mdash; Restore the default widget positions.</li>
    <li><strong>Undo</strong> &mdash; Reverse the last layout change.</li>
    <li><strong>Snapshots</strong> &mdash; Save named layouts (e.g., &quot;Normal Ops&quot; or &quot;Major Event&quot;).</li>
</ul>
'
            ],
            [
                'slug'  => 'navigation',
                'title' => 'Navigating the Menu Bar',
                'body'  => '
<p>The navigation bar runs across the top of every page:</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Button</th><th>Destination</th></tr></thead>
    <tbody>
        <tr><td>Situation</td><td>Main dashboard (home page).</td></tr>
        <tr><td>Full Screen</td><td>Full-screen Situation View in a new tab (wall display).</td></tr>
        <tr><td>New</td><td>New Incident form.</td></tr>
        <tr><td>Units</td><td>All responder units and their status.</td></tr>
        <tr><td>Fac&apos;s</td><td>Facilities (hospitals, shelters, stations).</td></tr>
        <tr><td>Search</td><td>Incident search page.</td></tr>
        <tr><td>Personnel</td><td>Dropdown: Roster, Teams, Scheduling, Vehicles, Equipment.</td></tr>
        <tr><td>Reports</td><td>Reporting and statistics.</td></tr>
        <tr><td>Config</td><td>Settings page (administrators only).</td></tr>
        <tr><td>SOP</td><td>Standard Operating Procedures viewer.</td></tr>
        <tr><td>Contacts</td><td>Constituents (external contacts).</td></tr>
        <tr><td>Messages</td><td>Internal messaging system.</td></tr>
    </tbody>
</table>
<p>On the right side: SSE connection indicator, 24-hour clock, message badge, audio mute, theme toggle, org switcher (if applicable), and your user menu.</p>
'
            ],
            [
                'slug'  => 'theme-toggle',
                'title' => 'Day / Night Theme',
                'body'  => '
<p>In the upper-right corner you will see two buttons with a sun and moon icon:</p>
<ul>
    <li><i class="bi bi-sun-fill text-warning"></i> <strong>Day</strong> &mdash; Light backgrounds. Best for well-lit rooms.</li>
    <li><i class="bi bi-moon-fill text-primary"></i> <strong>Night</strong> &mdash; Dark backgrounds. Easier on the eyes in low-light environments.</li>
</ul>
<p>Your theme choice is saved to your account and persists across sessions.</p>
'
            ],
        ],
    ],

    'dispatch' => [
        'icon'  => 'bi-telephone-inbound',
        'label' => 'Dispatch Operations',
        'topics' => [
            [
                'slug'  => 'new-incident',
                'title' => 'Creating a New Incident',
                'body'  => '
<p>Click <strong>New</strong> in the navigation bar. The form has two columns:</p>
<ul>
    <li><strong>Left:</strong> Form fields in 8 collapsible sections.</li>
    <li><strong>Right:</strong> Map for location and responder assignment panel.</li>
</ul>
<h6>The 8 Sections</h6>
<ol>
    <li><strong>Classification</strong> &mdash; Incident type, severity, scope, description, signal, major incident link.</li>
    <li><strong>Location</strong> &mdash; Street address, city, state, zip, cross street, coordinates, destination.</li>
    <li><strong>Contact</strong> &mdash; Caller name, phone, notes.</li>
    <li><strong>Facilities</strong> &mdash; Receiving facility selection.</li>
    <li><strong>Time &amp; Status</strong> &mdash; Reported time, initial status.</li>
    <li><strong>Call History</strong> &mdash; Search previous calls by phone or address.</li>
    <li><strong>Patients</strong> &mdash; Add/remove patients with name, age, gender, chief complaint.</li>
    <li><strong>Additional Details</strong> &mdash; Internal notes.</li>
</ol>
<p>When you select an incident type that has a <strong>response protocol</strong>, the protocol text appears above the map. Read it to the caller as appropriate.</p>
<p>Click <strong>Submit Incident</strong> (green button) or press <strong>Ctrl+Enter</strong> from any field.</p>
'
            ],
            [
                'slug'  => 'assign-units',
                'title' => 'Assigning Responder Units',
                'body'  => '
<p>Below the map on the New Incident form is the responder assignment panel.</p>
<ol>
    <li>Use the <strong>search filter</strong> to find a unit by name or callsign.</li>
    <li>Check the box next to each unit you want to dispatch.</li>
    <li>When you submit the incident, all checked units are assigned automatically.</li>
</ol>
<p>You can also assign units from the <strong>Incident Detail</strong> page after the incident is created.</p>
'
            ],
            [
                'slug'  => 'close-incident',
                'title' => 'Closing an Incident',
                'body'  => '
<ol>
    <li>Open the incident detail view (click the incident row on the dashboard).</li>
    <li>Change the status to <strong>Closed</strong>.</li>
    <li>Add any closing notes.</li>
    <li>Click <strong>Save</strong>.</li>
</ol>
<p>To reopen a closed incident, change the status back to <strong>Open</strong> and save.</p>
'
            ],
            [
                'slug'  => 'major-incidents',
                'title' => 'Major Incidents',
                'body'  => '
<p>A major incident groups related calls under one umbrella. For example, a wildfire might generate separate calls for structure fires, evacuations, and medical emergencies.</p>
<ul>
    <li><strong>Create</strong> a major incident from the incident detail view.</li>
    <li><strong>Link</strong> existing incidents to it.</li>
    <li><strong>Command structure</strong> fields record Gold, Silver, and Bronze command positions.</li>
    <li><strong>Navigate</strong> between linked incidents from any call in the group.</li>
</ul>
'
            ],
            [
                'slug'  => 'incident-detail',
                'title' => 'The Incident Detail View',
                'body'  => '
<p>Click any incident on the dashboard to open its detail view. This page shows:</p>
<ul>
    <li><strong>Header</strong> &mdash; Incident number, type, severity (color-coded), status.</li>
    <li><strong>Location</strong> &mdash; Address, city, state, and a map with the incident marker.</li>
    <li><strong>Timeline</strong> &mdash; Chronological log of notes, status changes, and assignments.</li>
    <li><strong>Assignments</strong> &mdash; Currently assigned responder units.</li>
    <li><strong>Patients</strong> &mdash; Patient information (if any).</li>
</ul>
<h6>Action Buttons</h6>
<ul>
    <li><strong>Add a note</strong> &mdash; Append text to the timeline.</li>
    <li><strong>Change status</strong> &mdash; Update the incident status.</li>
    <li><strong>Assign/remove units</strong> &mdash; Manage responders.</li>
    <li><strong>Navigate</strong> &mdash; Open turn-by-turn directions externally.</li>
    <li><strong>ICS-213 Export</strong> &mdash; Generate a Winlink-compatible XML form.</li>
</ul>
'
            ],
        ],
    ],

    'maps' => [
        'icon'  => 'bi-map',
        'label' => 'Maps',
        'topics' => [
            [
                'slug'  => 'map-controls',
                'title' => 'Basic Map Controls',
                'body'  => '
<ul>
    <li><strong>Pan:</strong> Click and drag to move the map.</li>
    <li><strong>Zoom:</strong> Use the scroll wheel, or click the + and &minus; buttons.</li>
    <li><strong>Click a marker:</strong> See details about the incident, unit, or facility.</li>
</ul>
<p>Maps appear on the dashboard, new incident form, incident detail views, and the full-screen situation view.</p>
'
            ],
            [
                'slug'  => 'address-search',
                'title' => 'Address Search / Geocoding',
                'body'  => '
<h6>Forward Geocoding (address to map)</h6>
<ol>
    <li>Type the street address in the Address field.</li>
    <li>Click <strong>Lookup</strong> or Tab to it and press Enter.</li>
    <li>The map zooms to the result and drops a marker. City, State, and Cross Street auto-fill.</li>
</ol>
<h6>Reverse Geocoding (map click to address)</h6>
<ol>
    <li>Click anywhere on the map.</li>
    <li>A marker appears and address fields fill in automatically.</li>
</ol>
<p><strong>Tip:</strong> The geocoder prioritizes addresses near your configured location. If results are wrong, try including the city and state in your search.</p>
'
            ],
            [
                'slug'  => 'map-markups',
                'title' => 'Map Markups (Drawing)',
                'body'  => '
<p>Markups let you draw shapes and annotations on the map for operational planning:</p>
<ul>
    <li><strong>Polygons</strong> &mdash; Outline search zones, perimeters, staging areas.</li>
    <li><strong>Circles</strong> &mdash; Mark radius zones (hazmat, evacuation rings).</li>
    <li><strong>Lines</strong> &mdash; Draw routes, boundaries, or barriers.</li>
    <li><strong>Rectangles</strong> &mdash; Mark rectangular areas.</li>
    <li><strong>Markers</strong> &mdash; Place labeled points of interest.</li>
</ul>
<p>Each markup has a name, description, color, and category. Categories can be toggled on/off. Markups persist in the database and are visible to all users.</p>
'
            ],
            [
                'slug'  => 'weather-overlay',
                'title' => 'Weather Overlay',
                'body'  => '
<p>If your administrator has configured a weather API key, the dashboard map can display weather overlays for temperature, precipitation, cloud cover, and wind.</p>
<p>Weather tiles are cached to reduce load and update periodically.</p>
'
            ],
            [
                'slug'  => 'situation-view',
                'title' => 'Situation View (Full-Screen EOC Display)',
                'body'  => '
<p>The <strong>Full Screen</strong> link in the top nav opens the Situation
View &mdash; a wall-display map showing every active incident, unit, and
facility, with a live side panel. It is built to run unattended for hours.</p>

<h6>The view-lock (why the map stops re-centering)</h6>
<ul>
    <li>The map auto-fits to show all active incidents, and re-fits only when
        the set of incidents changes &mdash; never on an idle refresh.</li>
    <li><strong>Any manual pan or zoom &mdash; including the on-screen +/&minus;
        buttons &mdash; locks the view.</strong> After that, automatic refreshes
        stop moving the map, so you can watch one area without being snapped
        back.</li>
    <li>A <strong>&#9733; (star)</strong> button appears top-right once the view
        is locked. Click it to resume auto-fit and jump back to the incidents.</li>
    <li>The +/&minus; tightness buttons bias how tight the auto-fit is (fill the
        screen vs. leave margin to watch approaching weather).</li>
</ul>

<h6>Click-to-zoom with restore</h6>
<p>Click any incident, unit, or facility row in the side panel to zoom to it.
Click the <strong>same</strong> row again to restore the exact view you had
before you zoomed in.</p>

<h6>Radar &mdash; two layers</h6>
<p>The layer control (top-right) offers two radar layers:</p>
<ul>
    <li><strong>Radar &mdash; US (NWS)</strong> &mdash; NOAA/NWS high-resolution
        radar (1&nbsp;km, ~2-minute updates). Stays sharp at any zoom. <em>US
        coverage only.</em> Best for watching a cell approach a specific
        site.</li>
    <li><strong>Radar &mdash; Global</strong> &mdash; RainViewer worldwide
        mosaic. Use it outside the US or for a country-wide glance. Looks coarse
        when zoomed in (that is expected &mdash; switch to the NWS layer for a
        sharp US close-up).</li>
</ul>
<p class="small text-body-secondary">Both radar layers, plus your chosen
weather/road/markup overlays and the selected side-panel tab, are remembered
per browser across reloads. Radar and weather require internet access to the
providers; an air-gapped display will not show them.</p>
'
            ],
            [
                'slug'  => 'zone-coverage',
                'title' => 'Zone Coverage — Who is where',
                'body'  => '
<p>The <strong>Zone Coverage</strong> board answers one question at a glance:
<em>how many units are in each zone right now?</em> It is built for events where
volunteers roam between areas &mdash; a festival, parade, or fair &mdash; so
anyone can decide whether to <strong>meet up</strong> with another unit or
<strong>spread out</strong> for better coverage.</p>

<h6>How it relates to Net Control</h6>
<p><strong>Net Control</strong> is the dispatcher&#39;s command board &mdash; a
dense grid where net control moves units between zones, runs PAR checks, and
logs everything to the ICS-214. <strong>Zone Coverage</strong> is the
volunteer&#39;s companion view: big, phone-friendly cards, visible to
<em>every</em> role (including Field Unit). Both read the same zones and the same
live unit positions &mdash; changing a unit&#39;s zone in one shows up in the
other within seconds.</p>

<h6>Reading the board</h6>
<ul>
    <li>Each <strong>zone</strong> is a card with its colour, a big
        <strong>unit count</strong>, and the units currently in it (callsign +
        the lead&#39;s name).</li>
    <li>A <strong>No zone reported yet</strong> bucket at the bottom lists units
        that are assigned to the event but have not been placed in a zone, so
        nobody is invisible.</li>
    <li>The board <strong>updates in real time</strong> &mdash; a small
        <em>live</em> indicator top-right shows the connection. It also re-polls
        every 15 seconds as a safety net.</li>
</ul>

<h6>Reporting your own zone (one tap)</h6>
<p>If you are the person on a unit assigned to the event, an <strong>&#8220;I&#39;m
in:&#8221;</strong> strip appears with a button for each zone. Tap one and your
unit moves there instantly &mdash; no radio call needed &mdash; and it shows up
for everyone on the board. It also writes an ICS-214 activity note
(<em>&#8220;Alpha &#8594; North Gate (self-reported)&#8221;</em>) so the
after-action log is complete. You can only ever move <strong>your own</strong>
unit this way; net control still moves any unit from the Net Control board.</p>

<h6>Choosing the event</h6>
<p>The board automatically shows the active event that has zones. If you run more
than one event with zones at once, an event picker appears top-right; your choice
is remembered on that device.</p>

<h6>Setting up zones</h6>
<p>Zones are defined on the <strong>Net Control</strong> board (draw them on the
map, or name them). Once zones exist and units are assigned to the event, Zone
Coverage fills in on its own. If the board says no zones are set up yet, that is
the place to start.</p>

<h6>Who can see and use it</h6>
<ul>
    <li><strong>See the board</strong> &mdash; every role, including Field Unit
        (permission <code>screen.zone_coverage</code>).</li>
    <li><strong>Report own zone</strong> &mdash; Super Admin, Org Admin,
        Dispatcher, Operator, and Field Unit (permission
        <code>action.set_own_zone</code>); Read-Only can watch but not change.</li>
</ul>
'
            ],
            [
                'slug'  => 'tile-providers',
                'title' => 'Tile Providers — Map Basemap Reference',
                'body'  => '
<p>TicketsCAD ships with a registry of tile (basemap) providers. An admin
picks the default in <strong>Settings &raquo; Tile Providers</strong>; individual
dispatchers can also switch between any of the configured providers from
each map&#39;s layer-control widget. This reference covers what every
built-in option is, whether it needs a key, and what attribution it
requires.</p>

<h6>Free &mdash; no key required (recommended)</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Name</th><th>Coverage</th><th>Best for</th><th>Attribution</th></tr></thead>
    <tbody>
        <tr><td><strong>OpenStreetMap &mdash; Standard</strong></td>
            <td>Worldwide</td>
            <td>General-purpose street map. Best default.</td>
            <td>&copy; OpenStreetMap contributors</td></tr>
        <tr><td><strong>OpenStreetMap &mdash; Humanitarian (HOT)</strong></td>
            <td>Worldwide</td>
            <td>Emergency-focused styling. Hospitals, schools, evacuation features highlighted. Great for dispatch.</td>
            <td>&copy; OpenStreetMap contributors, Humanitarian OSM Team</td></tr>
        <tr><td><strong>USGS &mdash; Topographic</strong></td>
            <td>United States only</td>
            <td>Public-domain US topo map. Contour lines, hydrography, shaded relief.</td>
            <td>US Geological Survey (public domain)</td></tr>
        <tr><td><strong>USGS &mdash; Imagery</strong></td>
            <td>United States only</td>
            <td>US satellite imagery, no labels.</td>
            <td>US Geological Survey</td></tr>
        <tr><td><strong>USGS &mdash; Imagery + Topo</strong></td>
            <td>United States only</td>
            <td>Satellite imagery with topographic overlay.</td>
            <td>US Geological Survey</td></tr>
        <tr><td><strong>CartoDB &mdash; Positron</strong></td>
            <td>Worldwide</td>
            <td>Light grey base, low-distraction. Good when overlays carry the visual weight (incidents, units).</td>
            <td>&copy; OpenStreetMap contributors, &copy; CARTO</td></tr>
        <tr><td><strong>CartoDB &mdash; Dark Matter</strong></td>
            <td>Worldwide</td>
            <td>Dark variant. Pairs with the NewUI dark theme.</td>
            <td>&copy; OpenStreetMap contributors, &copy; CARTO</td></tr>
        <tr><td><strong>Esri &mdash; Street</strong></td>
            <td>Worldwide</td>
            <td>Esri-styled street map. Useful when matching agency&#39;s existing ArcGIS visuals.</td>
            <td>Esri, HERE, Garmin, FAO, NOAA, USGS</td></tr>
        <tr><td><strong>Esri &mdash; World Imagery</strong></td>
            <td>Worldwide</td>
            <td>High-resolution satellite imagery from Esri. Excellent zoom-in detail.</td>
            <td>Esri, Maxar, Earthstar Geographics</td></tr>
        <tr><td><strong>Esri &mdash; Topographic</strong></td>
            <td>Worldwide</td>
            <td>Esri-styled topo. Good worldwide alternative to USGS Topo.</td>
            <td>Esri, USGS, NOAA</td></tr>
    </tbody>
</table>
<p class="small text-body-secondary">
    These are usable without any account or key. They will continue to
    work over time as long as the tile servers themselves stay online.
    Heavy production traffic should be considerate of the public
    OSM/USGS infrastructure &mdash; the providers ask that you not hammer
    them with sustained high-volume requests. For a 24/7 EOC display
    with dozens of dispatchers, a CDN-backed paid plan (Mapbox,
    MapTiler, Stadia, Azure Maps) is more appropriate.
</p>

<h6>Requires an API key</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Name</th><th>How to get a key</th><th>Free tier (approx.)</th></tr></thead>
    <tbody>
        <tr><td><strong>Mapbox</strong></td>
            <td>account.mapbox.com &mdash; sign up for a free account, copy the default public token.</td>
            <td>50,000 free map loads/month, then $0.60/1,000.</td></tr>
        <tr><td><strong>Custom URL</strong> (Azure Maps, MapTiler, Stadia, Thunderforest, your own tileserver, etc.)</td>
            <td>Sign up with the provider, get their tile URL template, paste it into the <strong>Custom URL</strong> field. Substitute the key in the template (e.g. <code>?key={key}</code> with the key value in <strong>API Key</strong>).</td>
            <td>Varies by provider.</td></tr>
    </tbody>
</table>

<h6>Azure Maps (Microsoft&#39;s Bing replacement)</h6>
<p>If you want Microsoft tiles, the path forward is Azure Maps:</p>
<ol>
    <li>Sign up at <code>portal.azure.com</code>, create an Azure Maps account (free Azure subscription works).</li>
    <li>Get a subscription key from the Authentication page of your Azure Maps account.</li>
    <li>In TicketsCAD: Settings &raquo; Tile Providers, choose <strong>Custom URL</strong>, and paste:</li>
</ol>
<pre><code>https://atlas.microsoft.com/map/tile?subscription-key={key}&amp;api-version=2024-04-01&amp;tilesetId=microsoft.imagery&amp;zoom={z}&amp;x={x}&amp;y={y}</code></pre>
<p>Put your subscription key in the <strong>API Key</strong> field. Other tilesets to try by swapping <code>microsoft.imagery</code>: <code>microsoft.base.road</code>, <code>microsoft.base.darkgrey</code>, <code>microsoft.weather.infrared.main</code>.</p>
<p>Azure Maps tile pricing is roughly $0.50 per 1,000 transactions on the Gen2 pay-as-you-go tier (tiles are <strong>not</strong> included in the 250k/month free transactions on Gen2). For a small fire department / ARES group typical usage runs under $1/month; for a busy 24/7 EOC, model the cost before committing.</p>

<h6>Backward compatibility &mdash; not recommended for new use</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Name</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
        <tr><td><strong>Google Maps</strong> (Streets / Satellite / Hybrid)</td>
            <td>Unofficial</td>
            <td>Google does not publish a public tile URL or a free Maps Static / Tile API for this kind of consumption. The URLs in our dropdown hit Google&#39;s tile servers directly &mdash; they work today but are not licensed and can be blocked at any time. For supported Google tiles, use the Maps JavaScript API (different integration, not a drop-in tile URL).</td></tr>
        <tr><td><strong>Bing Maps &mdash; Road / Aerial</strong></td>
            <td><span class="badge bg-danger">Retired by Microsoft</span></td>
            <td>Microsoft discontinued Bing Maps for Enterprise. Basic (free) accounts were shut down on <strong>June 30, 2025</strong>; existing paid Enterprise accounts run through <strong>June 30, 2028</strong> but renewal terms tighten after July 2026 and new accounts are no longer issued. The dropdown options are kept so existing configs don&#39;t break; new deployments should pick Azure Maps (see above) or one of the free no-key options.</td></tr>
    </tbody>
</table>

<h6>Troubleshooting</h6>
<ul>
    <li><strong>Tiles don&#39;t load:</strong> open browser DevTools &raquo; Network and look for the tile URL. If you see 401/403, the provider needs a key. If you see 404, the URL template is malformed. If you see CORS errors, the provider may not allow direct browser access &mdash; use the tile-proxy mode under Settings.</li>
    <li><strong>USGS only shows blank tiles outside the US:</strong> USGS basemaps are US-only. Switch to OpenStreetMap or Esri for international coverage.</li>
    <li><strong>Bing tiles stopped working:</strong> if your install was using Bing before mid-2025, the Basic account was shut down. Switch to any free option or migrate to Azure Maps.</li>
</ul>
'
            ],
            [
                'slug'  => 'road-conditions',
                'title' => 'Road Conditions',
                'body'  => '
<p>Road condition markers show hazards on the map: slippery roads, flooding, closures, construction, and debris.</p>
<ul>
    <li>Manage conditions from the Controls widget on the dashboard (click <strong>Roads</strong>).</li>
    <li>Conditions can auto-expire after a set time.</li>
    <li>Visual markers appear directly on the map so dispatchers can warn responders.</li>
</ul>
'
            ],
        ],
    ],

    'communications' => [
        'icon'  => 'bi-chat-dots',
        'label' => 'Communications',
        'topics' => [
            [
                'slug'  => 'chat',
                'title' => 'Chat',
                'body'  => '
<p>TicketsCAD has a built-in chat system. Open it from the Communications widget on the dashboard.</p>
<h6>Channels</h6>
<ul>
    <li><strong>General</strong> &mdash; Visible to everyone.</li>
    <li><strong>Dispatch</strong> &mdash; Dispatch operators only.</li>
    <li><strong>Admin</strong> &mdash; Administrators only.</li>
</ul>
<p>Select a channel, type your message, and press <strong>Enter</strong> or click <strong>Send</strong>.</p>
<h6>Signal Codes</h6>
<p>Click the <strong>Codes</strong> button to see predefined signal codes. Clicking a code sends it instantly.</p>
'
            ],
            [
                'slug'  => 'audio-alerts',
                'title' => 'Audio Alerts',
                'body'  => '
<p>TicketsCAD can play sounds when important events occur (new incidents, status changes, chat messages).</p>
<p>Click the <strong>speaker icon</strong> in the navigation bar to mute/unmute. Sound configuration is managed by your administrator in Config &gt; App Preferences &gt; Sound / Alerts.</p>
<p><strong>Note:</strong> Browsers require at least one click on the page before they allow audio playback.</p>
'
            ],
            [
                'slug'  => 'has-broadcast',
                'title' => 'HAS Broadcast',
                'body'  => '
<p>Dispatchers and administrators can send a <strong>HAS (Hearing All Stations) Broadcast</strong> &mdash; a message that goes to all connected users immediately.</p>
<p>Click the <i class="bi bi-megaphone-fill"></i> megaphone icon in the navigation bar. Enter your message and send. All logged-in users will see the broadcast.</p>
'
            ],
            [
                'slug'  => 'other-integrations',
                'title' => 'SMS, Radio & Meshtastic',
                'body'  => '
<p>Depending on your configuration, you may also have access to:</p>
<ul>
    <li><strong>SMS messaging</strong> &mdash; Send text messages to responders or contacts.</li>
    <li><strong>DMR radio messaging</strong> &mdash; Send text messages over digital radio.</li>
    <li><strong>Meshtastic</strong> &mdash; Send messages over LoRa mesh networks.</li>
    <li><strong>Zello push-to-talk</strong> &mdash; Voice and text messaging over Zello channels.</li>
    <li><strong>Slack</strong> &mdash; Forward notifications to Slack channels.</li>
</ul>
<p>These are configured in Settings &gt; Communications.</p>
'
            ],
            [
                'slug'  => 'comms-console',
                'title' => 'Communications Console',
                'body'  => '
<p>The <strong>Console</strong> page (navbar &gt; Console) shows every configured communications channel as a vertical <strong>channel strip</strong> &mdash; Zello channels, DMR talkgroups, Meshtastic, local chat, weather alerts, and more. Each strip has a status light (green connected / amber degraded / red down / grey unknown), the last caller and how long ago they were heard, and the controls that channel supports.</p>
<ul>
    <li><strong>Voice channels</strong> (Zello, DMR) &mdash; a button opens the matching radio widget for listening and push-to-talk.</li>
    <li><strong>Text channels</strong> &mdash; a Messages drawer shows recent traffic and lets you send directly onto the channel.</li>
    <li><strong>Feeds</strong> (weather alerts, event bus) &mdash; read-only activity drawers.</li>
</ul>
<p><strong>Views:</strong> administrators can author named layouts in the <strong>Console Designer</strong> (Design Views button) &mdash; pick which channels appear, their order, colors, labels, and controls. Published views appear as tabs across the top of the console for every dispatcher; the built-in <em>All Channels</em> tab always shows everything enabled. Your last-used tab is remembered per browser.</p>
<p>Access requires the <code>screen.console</code> permission; transmitting requires <code>action.console_tx</code>; authoring shared views requires <code>console.design</code>.</p>
'
            ],
            [
                'slug'  => 'weather-alerts',
                'title' => 'Weather Alerts (NWS)',
                'body'  => '
<p>If your administrator has enabled it, TicketsCAD can surface <strong>National Weather Service</strong> watches and warnings for your area. When a matching alert is issued you will see a <i class="bi bi-cloud-lightning-rain-fill text-danger"></i> card in the notification tray (bell icon) with an audible chime, and a banner on the Situation view.</p>
<p>Weather alerts are <strong>off by default</strong> and configured per-install. Administrators set coverage areas (a state, forecast zones, or a point + radius) and routing rules (which severity of alert goes where) at <strong>Settings &gt; Communications &amp; Integrations &gt; Weather Alerts</strong>. Later phases can route alerts to chat/SMS/email and read severe warnings out over DMR/Zello radio.</p>
<p>See the <a href="docs/WEATHER-ALERTS-GUIDE.md" target="_blank">Weather Alerts administrator guide</a> for setup.</p>
'
            ],
        ],
    ],

    'personnel' => [
        'icon'  => 'bi-person-badge',
        'label' => 'Personnel',
        'topics' => [
            [
                'slug'  => 'roster',
                'title' => 'The Roster',
                'body'  => '
<p>Click <strong>Personnel &gt; Roster</strong> to manage all members. The page has a two-column layout:</p>
<ul>
    <li><strong>Left:</strong> Searchable, filterable member list with count badge.</li>
    <li><strong>Right:</strong> Detail panel for the selected member.</li>
</ul>
<h6>Searching and Filtering</h6>
<ul>
    <li>Search by name, callsign, phone, or email.</li>
    <li>Filter by status (Active/Inactive), team, or member type.</li>
</ul>
<h6>Adding a Member</h6>
<ol>
    <li>Click <strong>New Member</strong>.</li>
    <li>Fill in name, contact details, member type, status.</li>
    <li>Click <strong>Save</strong>.</li>
</ol>
<h6>Removing Members</h6>
<p>Delete one member from the detail panel&#39;s <strong>Delete</strong>
button (soft delete &mdash; the member moves to the Wastebasket and can be
restored).</p>
<p>If your account holds the <strong>Bulk Delete Members</strong> permission,
a checkbox column appears at the right edge of the roster table with a
select-all box in the header:</p>
<ol>
    <li>Tick the members to remove (or use the header box to select every
        member currently shown).</li>
    <li>In the bulk-actions bar, click <strong>Delete Selected</strong>.</li>
    <li>Confirm in the dialog. Removed members go to the Wastebasket (soft
        delete); up to 500 per request.</li>
</ol>
<p class="small text-body-secondary">The selection persists as you change the
search/filter, so a bulk delete can span members from more than one filter
view &mdash; use <strong>Clear</strong> to reset. The checkbox column only
appears for accounts with the Bulk Delete Members permission (Super Admin by
default; an admin can grant it to other roles under Config &raquo; Roles).</p>
'
            ],
            [
                'slug'  => 'teams',
                'title' => 'Teams',
                'body'  => '
<p>Click <strong>Personnel &gt; Teams</strong>. Each team has a name, NIMS resource type, members, and description.</p>
<p>Use teams to organize members into functional groups (e.g., &quot;Engine 1 Crew,&quot; &quot;CERT Alpha,&quot; &quot;Net Control&quot;).</p>
'
            ],
            [
                'slug'  => 'certifications',
                'title' => 'Certifications & Training',
                'body'  => '
<p>Each member can have certifications (EMT, HAZMAT, CPR, etc.) and training records (ICS-100, FEMA IS-700, etc.) attached to their profile.</p>
<ul>
    <li>Certifications have issue and expiration dates for renewal tracking.</li>
    <li>Types are configured by your administrator under Config &gt; Personnel.</li>
</ul>
'
            ],
            [
                'slug'  => 'scheduling',
                'title' => 'Scheduling',
                'body'  => '
<p>Click <strong>Personnel &gt; Scheduling</strong>. Two types of time management are supported:</p>
<h6>Shifts</h6>
<ul>
    <li>View and manage recurring shift patterns.</li>
    <li>See who is on duty at any given time.</li>
    <li>Assign members to specific shifts.</li>
</ul>
<h6>Events</h6>
<ul>
    <li>Create one-time or recurring events (training, meetings, community events).</li>
    <li>Define time slots with specific roles needed.</li>
    <li>Members can self-sign up for available slots.</li>
</ul>
'
            ],
            [
                'slug'  => 'fcc-lookup',
                'title' => 'FCC Callsign Lookup',
                'body'  => '
<p>When adding a member with an amateur radio or GMRS license:</p>
<ol>
    <li>Enter the callsign in the lookup field.</li>
    <li>Click <strong>Lookup</strong> to query the FCC database.</li>
    <li>If found, click <strong>Apply to Form</strong> to auto-fill name and license details.</li>
</ol>
<p>Works for both amateur radio and GMRS callsigns.</p>
<p>The lookup source is set under <em>Settings &rarr; FCC Lookup</em>. The default,
<strong>OpenCallbook</strong>, is a free public service that covers both the amateur
and GMRS databases. Other choices are a local offline copy of the FCC data, the
amateur-only callook.info, or a self-hosted FCC-ULS-API. You can also choose how much
detail an internet lookup service is told about who is querying (the User-Agent).</p>
'
            ],
        ],
    ],

    'reports' => [
        'icon'  => 'bi-bar-chart-line',
        'label' => 'Reports',
        'topics' => [
            [
                'slug'  => 'search-incidents',
                'title' => 'Searching Past Incidents',
                'body'  => '
<p>Click <strong>Search</strong> in the navigation bar. Filter by:</p>
<ul>
    <li>Text search (scope, description, address, caller info)</li>
    <li>Incident type, status, severity</li>
    <li>Date range</li>
</ul>
<p>Results appear in a sortable table. Click any row to view the incident.</p>
'
            ],
            [
                'slug'  => 'generating-reports',
                'title' => 'Generating Reports',
                'body'  => '
<p>Click <strong>Reports</strong> in the navigation bar. Available reports include:</p>
<ul>
    <li><strong>Incident Summary</strong> &mdash; Counts by type, severity, or time period.</li>
    <li><strong>Response Time Analysis</strong> &mdash; Dispatch-to-on-scene times.</li>
    <li><strong>Unit Activity</strong> &mdash; Hours, incidents, mileage per unit.</li>
    <li><strong>Daily/Weekly/Monthly Activity</strong> &mdash; Volume over time.</li>
</ul>
<p>All reports can be filtered by date range, type, unit, and other criteria.</p>
'
            ],
            [
                'slug'  => 'ics-213-export',
                'title' => 'ICS-213 Export',
                'body'  => '
<p>Export incident data as ICS-213 (General Message) forms compatible with Winlink:</p>
<ol>
    <li>Open the Incident Detail view.</li>
    <li>Click the <strong>ICS-213</strong> export button.</li>
    <li>Download the generated XML file.</li>
    <li>Import it into Winlink Express or Pat for radio transmission.</li>
</ol>
'
            ],
            [
                'slug'  => 'import-export',
                'title' => 'Import & Export',
                'body'  => '
<p>The Import/Export page (Config &gt; System &gt; Import / Export) allows:</p>
<ul>
    <li><strong>Exporting</strong> incidents, members, facilities to CSV or JSON.</li>
    <li><strong>Importing</strong> data from CSV with column mapping and preview.</li>
    <li><strong>Legacy migration</strong> from Tickets v3.x.</li>
</ul>
'
            ],
        ],
    ],

    'configuration' => [
        'icon'  => 'bi-gear',
        'label' => 'Configuration',
        'topics' => [
            [
                'slug'  => 'settings-overview',
                'title' => 'Settings Overview',
                'body'  => '
<p>The Settings page (admin only) has a sidebar with 9 sections:</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Section</th><th>Contains</th></tr></thead>
    <tbody>
        <tr><td>System</td><td>Health dashboard, Audit Log, Import/Export.</td></tr>
        <tr><td>Installation</td><td>System Settings, API Keys, Lookup Services, Database Info, Backup.</td></tr>
        <tr><td>App Preferences</td><td>Incident Types, Severity, Signals, Unit Statuses, Facility Types, Display, Sound, Numbering.</td></tr>
        <tr><td>Users</td><td>Accounts, Roles, Login Settings, 2FA, Encryption.</td></tr>
        <tr><td>Personnel</td><td>Organizations, Members, Teams, Certs, ICS Positions, Equipment, Vehicles, Training.</td></tr>
        <tr><td>Communications</td><td>Notifications, Email, SMS, Slack, Radio, Zello, Meshtastic, Webhooks, Chat.</td></tr>
        <tr><td>Locations</td><td>Facilities, Regions, Places, Road Conditions.</td></tr>
        <tr><td>Maps</td><td>Map Defaults, Tile Providers.</td></tr>
        <tr><td>Location Tracking</td><td>APRS, Meshtastic, OwnTracks, DMR, Zello providers, Geofencing.</td></tr>
    </tbody>
</table>
'
            ],
            [
                'slug'  => 'incident-types-config',
                'title' => 'Managing Incident Types',
                'body'  => '
<p>Go to Config &gt; App Preferences &gt; Incident Types. Each type has:</p>
<ul>
    <li><strong>Name</strong> &mdash; Short description (e.g., &quot;Structure Fire&quot;).</li>
    <li><strong>Group</strong> &mdash; Category (Fire, EMS, Law, Admin).</li>
    <li><strong>Protocol</strong> &mdash; Response instructions shown to the dispatcher.</li>
    <li><strong>Severity</strong> &mdash; Default severity level.</li>
    <li><strong>Color</strong> &mdash; Display color on maps and lists.</li>
</ul>
'
            ],
            [
                'slug'  => 'unit-history',
                'title' => 'Unit History & Notes',
                'body'  => '
<p>Every unit / responder has a dedicated timeline showing what happened to it in one place — dispatches, status changes, action-log entries, and free-form notes. Reach it from <strong>Personnel &gt; Units &gt; (pick a unit) &gt; History</strong>, or directly at <code>unit-history.php?responder_id=N</code>.</p>

<h6>What ends up on the timeline</h6>
<ul>
    <li><strong>Dispatches + clears</strong> — every <code>assigns</code> row (dispatch and clear timestamps) for this unit.</li>
    <li><strong>Status changes</strong> — every transition through <code>responder_set_status_internal()</code>, including any <code>extra_data</code> that came with the change.</li>
    <li><strong>Action-log notes</strong> — every <code>action</code> row tagged with <code>responder = this_id</code>.</li>
    <li><strong>Free-form notes</strong> — anything you type in via the <em>Add note</em> card.</li>
</ul>

<h6>Notes are ICS-214-friendly</h6>
<p>Type what the unit actually did in plain English — "advanced hoseline to attic, opened truss space", "transported patient from 812 Main to St Joe\'s ER", "held safety officer for CERT Alpha through the training exercise". Add a <em>category</em> (default <code>general</code>) so the person building the ICS-214 later can filter by "incident-XYZ" or "ics-214" or anything else you find useful. The <strong>Copy notes to clipboard</strong> button dumps a chronological plain-text stream you can paste straight into an ICS-214 activity log.</p>

<h6>Answering "where does extra_data go?"</h6>
<p>This page is also the canonical answer to a beta tester\'s GH #15 question. When you configure a unit status with <code>extra_data_target = unit</code>, the value lands on the responder row (mileage, note, etc.). Every write shows up here as a status-change event with the value in the description. If you\'re not sure a specific status is behaving the way you configured it, come here and eyeball the timeline — the answer is one glance away.</p>

<h6>Permissions</h6>
<ul>
    <li>View: anyone who can view the responder page.</li>
    <li>Add / delete notes: <code>action.change_unit_status</code> or admin.</li>
</ul>
'
            ],
            [
                'slug'  => '2fa-remember',
                'title' => 'Two-Factor "Remember Device" & Fingerprinting',
                'body'  => '
<p>When 2FA is enabled and a user checks <strong>Remember this device</strong> on the 2FA form, the browser gets a signed cookie so subsequent logins from that same device skip the code prompt for the configured number of days. Two knobs control who can use this feature and how strictly the "same device" check verifies it.</p>

<h6>Who sees the "Remember device" checkbox</h6>
<ul>
    <li>By default, the checkbox only shows to clients coming from a <strong>trusted CIDR</strong> — the ranges in <code>settings.tfa_trusted_cidrs</code>. The default set is the four RFC1918 private ranges (127/8, 10/8, 172.16/12, 192.168/16), which fits a station-networked install.</li>
    <li>Field responders on public networks (LTE, home Wi-Fi, hotel Wi-Fi) fail that check and never see the checkbox — so they get the code prompt on every login. That surprised beta users.</li>
    <li>Fix: flip <code>tfa_trusted_cidrs_any_network</code> to <code>1</code> in Settings. The checkbox then shows to everyone, and the anti-replay defence comes from the fingerprint bindings below instead of from the network address.</li>
</ul>

<h6>How "same device" is verified (the fingerprint)</h6>
<p>The cookie is bound to a SHA-256 of a bag of attributes. If the attributes reproduce, the cookie is honored — otherwise it\'s rejected and a fresh 2FA code is required. Each attribute is admin-toggleable via <code>tfa_fingerprint_include_*</code> settings so you can trade friction for strictness:</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Setting</th><th>Default</th><th>What it binds to</th></tr></thead>
    <tbody>
        <tr><td><code>tfa_fingerprint_include_ua</code></td>              <td>on</td>  <td>User-Agent header.</td></tr>
        <tr><td><code>tfa_fingerprint_include_accept_lang</code></td>     <td>on</td>  <td>Accept-Language header.</td></tr>
        <tr><td><code>tfa_fingerprint_include_sec_ch_ua</code></td>       <td>on</td>  <td>Sec-CH-UA (Chromium client hints).</td></tr>
        <tr><td><code>tfa_fingerprint_include_timezone</code></td>        <td>on</td>  <td><code>Intl.DateTimeFormat().resolvedOptions().timeZone</code> — client-side.</td></tr>
        <tr><td><code>tfa_fingerprint_include_screen</code></td>          <td>on</td>  <td><code>screen.availWidth × availHeight</code> — client-side.</td></tr>
        <tr><td><code>tfa_fingerprint_include_platform</code></td>        <td>on</td>  <td><code>navigator.platform</code> — client-side.</td></tr>
        <tr><td><code>tfa_fingerprint_include_ip_prefix</code></td>       <td>OFF</td> <td>Client IP\'s /24 (IPv4) or /48 (IPv6). Was on until Phase 104e; caused false rejections on roaming mobile clients where the /24 rotates with the carrier.</td></tr>
    </tbody>
</table>

<h6>The tradeoff — read this before flipping settings</h6>
<ul>
    <li>Loose fingerprint (just UA + Accept-Language) = a stolen <code>tfa_remember</code> cookie is replay-safe for anyone who sets the same UA + language for the cookie\'s whole 30-day life. Low friction, weak defence.</li>
    <li>Default (UA + language + Sec-CH-UA + timezone + screen + platform) = a thief needs to reproduce all six on their machine before the cookie honours. Timezone + screen size especially are non-trivial to fake without knowing the victim\'s device. Reasonable friction, strong defence.</li>
    <li>Add <code>tfa_fingerprint_include_ip_prefix</code> back on if your install is station-networked and roaming isn\'t a concern. That adds a network-locality bind, at the cost of one round of re-2FA whenever a user\'s IP /24 changes.</li>
</ul>

<h6>Cookie lifetime</h6>
<ul>
    <li><code>settings.tfa_remember_days</code> — how long the cookie is valid. Default 30. Range 1&ndash;365.</li>
    <li>Every successful verify EXTENDS the remaining time to a full <code>tfa_remember_days</code> (rolling window).</li>
</ul>

<h6>Revoking</h6>
<ul>
    <li>Users: <em>Profile &gt; Security &gt; Forget all remembered devices</em>.</li>
    <li>Admins: <em>Settings &gt; Users &gt; Accounts &gt; Edit &gt; Revoke remembered devices</em>.</li>
    <li>Deleting rows from <code>tfa_remember_tokens</code> also works and takes effect on the next request.</li>
</ul>
'
            ],
            [
                'slug'  => 'auto-close',
                'title' => 'Auto-close on all-clear',
                'body'  => '
<p>When the last active unit clears from an incident, TicketsCAD can close the incident automatically after a configurable grace period. Two settings drive this — both live at <strong>Config &gt; Configuration &gt; Incident Lifecycle</strong>.</p>

<h6>Setting 1 — Auto-close on all-clear (on / off)</h6>
<ul>
    <li><strong>On (default):</strong> when the last active assignment on an incident stamps <code>clear</code>, the incident is scheduled to close after the grace period.</li>
    <li><strong>Off:</strong> nothing fires automatically; an incident stays open until a dispatcher closes it via the standard Close button.</li>
</ul>

<h6>Setting 2 — Grace period</h6>
<ul>
    <li>Enter a number (1&ndash;999) plus a unit: <em>seconds</em>, <em>minutes</em>, or <em>hours</em>. Default is 90 seconds.</li>
    <li>Range is 1 second to 999 hours (~41 days). Values are stored as raw seconds in the <code>settings.auto_close_grace_seconds</code> row.</li>
</ul>

<h6>Cancellation on re-dispatch</h6>
<p>If a unit is assigned to the incident inside the grace window, the pending auto-close is cancelled and the incident stays open. This handles the "we thought we were done but need someone back" case without a dispatcher having to reopen a closed incident.</p>

<h6>How the close actually fires</h6>
<ul>
    <li>The scheduled time lives on <code>ticket.auto_close_scheduled_at</code> until it fires or is cancelled.</li>
    <li>A lightweight sweeper runs on every fetch of <code>/api/incidents.php</code> — no cron required. Bounded to 20 tickets per call so a huge backlog can\'t stall the request.</li>
    <li>The close itself goes through the same <code>incident_update_status_internal()</code> path a dispatcher-driven Close uses — audit log, SSE, webhook, and any auto-clear-on-close behaviour all run normally.</li>
    <li>Every auto-close writes an audit-log entry with reason "Phase 104d — grace period expired" so it\'s traceable.</li>
</ul>

<h6>Off-by-safety</h6>
<ul>
    <li>Fails soft: if the auto-close helper errors, the underlying status change still commits. A misconfigured grace window can never block a legitimate close.</li>
    <li>Safety re-check: even if the sweeper hits a ticket whose scheduled time passed, it re-verifies "no active assigns remain" before closing. A re-dispatch that skipped the cancel path is still respected.</li>
</ul>
'
            ],
            [
                'slug'  => 'pwa-limitations',
                'title' => 'PWA Sound & Critical Alerts (Limitations)',
                'body'  => '
<p>The TicketsCAD mobile experience runs as a <strong>Progressive Web App</strong> (PWA) — the same code that renders in a desktop browser, wrapped in an installable icon on iOS and Android. That gives us fast iteration and one codebase, but two known limitations affect audio behavior and iOS "Critical Alerts".</p>

<h6>Sound on push notifications</h6>
<ul>
    <li>When the PWA is <strong>open</strong>, TicketsCAD plays the configured tones through <code>assets/js/audio-alerts.js</code>. This works — the audio settings in Config &gt; App Preferences &gt; Sound apply as expected.</li>
    <li>When the PWA is <strong>backgrounded or closed</strong>, a push notification wakes the service worker (<code>sw.js</code>). The Web Push spec allows a <code>sound</code> property on <code>showNotification()</code>, but <strong>iOS Safari ignores it</strong>, and Android Chrome only partially honors it. You get the OS default notification chime, not the TicketsCAD tone. This is a browser-platform constraint, not a bug.</li>
</ul>

<h6>iOS Critical Alerts</h6>
<ul>
    <li>Critical Alerts is an <strong>Apple entitlement</strong> restricted to registered native apps in specific "public safety, health, home security" categories. A PWA — no matter how it is installed — cannot request it. Do-Not-Disturb and Silent Mode will keep suppressing push notifications on iOS.</li>
</ul>

<h6>Roadmap</h6>
<p>Both limits go away when TicketsCAD ships a <strong>native mobile shell</strong> — currently estimated for <strong>Q4 2026</strong>. The native wrapper will register local notifications tied directly to your configured tones and let us apply for the Critical Alerts entitlement so New Incident dispatches, PAR checks, and priority messages bypass silent mode on iOS. Until then, dispatchers relying on audio should keep the PWA in the foreground during their shift.</p>

<h6>Interim workarounds</h6>
<ul>
    <li>Leave the PWA foregrounded during a shift — foreground audio plays configured tones.</li>
    <li>Set the iOS device to a ringtone-loud profile with Do-Not-Disturb OFF for the duration of the shift.</li>
    <li>Use the SMS route (Config &gt; Communications) with the phone\'s own SMS ringtone as a secondary audible cue.</li>
</ul>
'
            ],
            [
                'slug'  => 'bed-counts',
                'title' => 'How Facility Bed Counts Update',
                'body'  => '
<p>Each facility has two simple counters on its detail page: <strong>Beds Available</strong> and <strong>Beds Occupied</strong>. These come from the <code>facilities.beds_a</code> and <code>facilities.beds_o</code> columns. How they change depends on the facility\'s <strong>Bed Count Updates</strong> setting on the Edit Facility page.</p>

<h6>Manual mode <em>(default)</em></h6>
<ul>
    <li>The counters only change when a facility admin edits them on <strong>Edit Facility &gt; Capacity &amp; Status</strong>.</li>
    <li>Nothing about the dispatch workflow adjusts the numbers &mdash; unit statuses, patient assignments, and incident closings all leave the counters alone.</li>
    <li>Best for agencies where the receiving facility publishes its own bed availability out-of-band (phone call, email, dedicated hospital diversion system) and the dispatcher just mirrors it here.</li>
</ul>

<h6>Automatic on unit delivery</h6>
<ul>
    <li>When a unit assigned to this facility as its <strong>Receiving Facility</strong> transitions into a delivery status &mdash; <code>At Facility</code>, <code>At Hospital</code>, <code>Delivered</code>, <code>Patient Delivered</code>, <code>Arrived</code>, or <code>Transfer of Care</code> &mdash; the system drops <code>beds_a</code> by 1 and raises <code>beds_o</code> by 1.</li>
    <li>Fires <strong>once per assignment</strong>. Toggling the unit&apos;s status back and forth doesn&apos;t re-fire; a fresh assignment on a new incident does.</li>
    <li>Automatic mode <strong>does not release beds</strong> on incident close. When the patient is discharged / transferred out, the facility admin adjusts <code>beds_o</code> back down manually. Dispatch doesn&apos;t know when the hospital finishes its work with the patient.</li>
    <li>Fixed delta of 1 bed per delivery. Multi-patient runs still work &mdash; bump <code>beds_o</code> once for each additional patient after the automatic decrement fires. A future release will read patient count from the incident.</li>
    <li>Fails soft: any automation error is logged and the status change still commits. The counters staying stuck is always fixable by editing the facility.</li>
</ul>

<h6>Configuring a facility</h6>
<ol>
    <li>Go to <strong>Fac\'s</strong> &gt; select the facility &gt; <strong>Edit</strong>.</li>
    <li>Under <strong>Capacity &amp; Status</strong>, set <strong>Bed Count Updates</strong> to <em>Manual</em> or <em>Automatic on unit delivery</em>.</li>
    <li>Save.</li>
    <li>The mode shows as a badge next to <strong>Bed Capacity</strong> on the facility detail page so every dispatcher can see it at a glance.</li>
</ol>

<h6>Trail</h6>
<ul>
    <li>Every automatic adjustment writes an entry to the <code>facility_bed_auto_log</code> table (linked to the assignment id, responder id, and status) and to the standard audit log. You can trace exactly why the counter moved.</li>
</ul>
'
            ],
            [
                'slug'  => 'user-management',
                'title' => 'Managing Users',
                'body'  => '
<p>Go to Config &gt; Users &gt; User Accounts.</p>
<h6>Default Roles</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Role</th><th>Access</th></tr></thead>
    <tbody>
        <tr><td>Super Admin</td><td>Full access to everything.</td></tr>
        <tr><td>Org Admin</td><td>Full access within their organization.</td></tr>
        <tr><td>Dispatcher</td><td>Create/manage incidents, assign units.</td></tr>
        <tr><td>Operator</td><td>View data, update statuses, add notes.</td></tr>
        <tr><td>Read-Only</td><td>View only.</td></tr>
        <tr><td>Field Unit</td><td>Mobile view: assigned incidents, own status.</td></tr>
    </tbody>
</table>
'
            ],
            [
                'slug'  => 'message-routing',
                'title' => 'Message Routing &mdash; Phase 99v',
                'body'  => '
<p>The <strong>message routing engine</strong> decides where each event TicketsCAD generates ends up &mdash; which channels carry it, and which users get it. Configure routes at <em>Settings &gt; Message Routing</em>.</p>

<h6>Two axes: channels and recipients</h6>
<p>A route has two dimensions:</p>
<ul>
    <li><strong>Channel</strong> &mdash; where the message goes. Push, Slack, SMS, Meshtastic, DMR, Zello, email, local chat, etc.</li>
    <li><strong>Recipients</strong> &mdash; who receives it. <em>Channel broadcast</em> (default) sends to everyone on the destination channel. Or pick <em>Specific users</em> and choose a predicate that resolves to a user set.</li>
</ul>

<h6>The six recipient predicates</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Predicate</th><th>What it resolves to</th></tr></thead>
    <tbody>
        <tr><td><code>assigned_to_incident</code></td><td>Users whose responder has an active assignment for the incident</td></tr>
        <tr><td><code>responder_status_in</code></td><td>Users whose responder is currently in one of the named statuses (Available, On Scene, etc.)</td></tr>
        <tr><td><code>member_of_team</code></td><td>Users in any of the listed teams</td></tr>
        <tr><td><code>user_id_in</code></td><td>Literal list of user IDs</td></tr>
        <tr><td><code>org_member</code></td><td>Users whose home org or role-scoped org matches one of the listed orgs</td></tr>
        <tr><td><code>rbac_can</code></td><td>Users whose roles grant a named permission code (e.g. <code>screen.situation</code>)</td></tr>
    </tbody>
</table>

<h6>Default seed routes</h6>
<p>Two routes ship pre-configured:</p>
<ul>
    <li><strong>Mobile / field responders</strong> &mdash; push to users assigned to the incident</li>
    <li><strong>Dispatchers</strong> &mdash; push to anyone with <code>screen.situation</code> or <code>widget.incidents</code> access (the desktop firehose)</li>
</ul>
<p>Together these give the operational baseline: field units get only their assignments; dispatchers get the full feed. Disable, edit, or delete them freely &mdash; the system never re-creates a route you removed.</p>

<h6>Using the Preview button</h6>
<p>When you pick a predicate in the form, click <strong>Preview recipients</strong>. The engine resolves the predicate against the live database and shows you how many users it would notify, plus the first 25 names. This is the trust-builder: verify the rule does what you think before saving.</p>

<h6>Composition (advanced)</h6>
<p>For routes that combine multiple predicates (e.g. "Skywarn team members who are currently Available"), use the <strong>Advanced: edit JSON directly</strong> toggle. Compose with <code>{"type":"any_of"|"all_of"|"none_of","conditions":[...]}</code>. Full examples in the routing guide.</p>

<h6>Where to find more</h6>
<p>See <code>docs/MESSAGE-ROUTING-GUIDE.md</code> in the repo for the full configuration guide, ten common configuration templates, and troubleshooting steps.</p>
'
            ],
            [
                'slug'  => 'permissions-matrix',
                'title' => 'Permissions Matrix &mdash; Phase 99u',
                'body'  => '
<p>The <strong>Permissions Matrix</strong> is the dense grid view of every permission against every non-system role. Reach it from <em>Settings &gt; Roles &amp; Permissions</em> &rarr; <strong>Open matrix to review</strong>, or directly at <code>/roles-matrix.php</code>.</p>

<h6>What the page shows</h6>
<ul>
    <li><strong>Rows</strong> = every permission (filterable by category, by name, or by review status).</li>
    <li><strong>Columns</strong> = every non-system role (Super Admin, Dispatcher, Operator, etc. are hidden; they are managed via the seed migration, not editable from this page).</li>
    <li><strong>Cells</strong> = a check means that role grants the permission. Click any cell to flip it. The change saves immediately and is logged to the audit log.</li>
</ul>

<h6>The audit banner</h6>
<p>The yellow banner on the Roles &amp; Permissions page tells you how many permissions are <em>un-reviewed</em>. A permission is <strong>un-reviewed</strong> when:</p>
<ul>
    <li>No non-system role grants it, AND</li>
    <li>No administrator has dismissed it.</li>
</ul>
<p>The banner exists so newly-added capabilities (introduced by a feature ship) don\'t silently end up granted to nobody. You close the gap on each one by EITHER granting it to a role OR dismissing it as intentionally admin-only.</p>

<h6>Dismissing a permission</h6>
<p>If a permission is correctly admin-only on your install &mdash; meaning the only role that should hold it is Super Admin &mdash; click <strong>Dismiss</strong> in the row. The permission drops out of the banner count. No reason field; the audit log records who dismissed it and when, and that is enough to find the reviewer later if questions come up. Re-open the review any time by clicking <strong>Re-open</strong> on a previously-dismissed row.</p>

<h6>System roles are hidden on purpose</h6>
<p>Super Admin, Org Admin, Dispatcher, Operator, Read-Only and Field Unit are <em>system roles</em> &mdash; their grants ship with the application and migration runner. They\'re not editable from the matrix to prevent accidental ops-time changes. If you need to alter what a system role grants, do it via the seed migration (constitution-level change). Your own configurable roles (e.g. "Limited Dispatcher", "Driver", "Internal Auditor") are the ones you tune here.</p>
'
            ],
        ],
    ],

    'integrations' => [
        'icon'  => 'bi-plug',
        'label' => 'Integrations (Webhooks & API)',
        'topics' => [
            [
                'slug'  => 'integrations-overview',
                'title' => 'Overview — Webhooks vs External API',
                'body'  => '
<p>TicketsCAD ships with two bidirectional integration surfaces. Pick the one that matches the direction of data flow:</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Surface</th><th>Direction</th><th>When to use</th></tr></thead>
    <tbody>
        <tr><td><strong>External API</strong></td><td>External system → TicketsCAD</td><td>Your external system creates an incident in TicketsCAD, attaches a file, updates a unit\'s status, etc. RESTful, bearer-token auth.</td></tr>
        <tr><td><strong>Webhooks</strong></td><td>TicketsCAD → External system</td><td>An incident gets created in TicketsCAD and your external system needs to know about it. HTTP POST with HMAC-signed body.</td></tr>
    </tbody>
</table>
<p>Both are managed from <strong>Settings → Integrations</strong>. Both share the same RBAC permission backbone — every API call runs under a real user\'s permissions; the bearer token is bound to that user at mint time.</p>
<p>For detailed reference + code samples, see:</p>
<ul>
    <li><code>docs/EXTERNAL-API.md</code> — full integrator guide for the inbound API (1100+ lines, every endpoint, every error code, scope reference, security model, settings appendix)</li>
    <li><code>docs/WEBHOOKS-INTEGRATOR-GUIDE.md</code> — receiver-side guide for outbound webhooks (signature verification, retry behavior, event catalogue, troubleshooting)</li>
</ul>
'
            ],
            [
                'slug'  => 'external-api-tokens',
                'title' => 'Minting an External API Token',
                'body'  => '
<p>Go to <strong>Settings → External API Tokens</strong> (requires <code>action.manage_external_api_tokens</code>). Click <strong>+ Mint new token</strong> and fill in:</p>
<ul>
    <li><strong>Name</strong> — short label for the integration (e.g., "Acme iOS v1.4"). Visible in audit logs.</li>
    <li><strong>Bind to user</strong> — the token runs UNDER this user\'s permissions. Non-admins can only pick themselves; admins can pick any user.</li>
    <li><strong>Scopes</strong> — limits which resources the token can hit. Examples: <code>incidents:read,incidents:write</code>, <code>members:read</code>. Wildcard <code>*</code> is admin-only.</li>
    <li><strong>Description</strong> (optional) — for the audit trail.</li>
    <li><strong>IP allowlist</strong> (optional) — CIDR ranges this token may originate from.</li>
    <li><strong>Expires at</strong> (optional) — auto-expiry; omit for non-expiring tokens.</li>
    <li><strong>Rate limit / hour</strong> (optional) — overrides the default 1000 req/hr.</li>
</ul>
<p>After clicking Mint, the raw token value appears in a <strong>capture-once</strong> modal. Copy it immediately into your integration\'s secret store — TicketsCAD never displays the full token again (only the first 14-character prefix in the admin UI). If you lose the token, revoke it and mint a new one.</p>
<p><strong>CLI alternative:</strong> <code>sudo -u www-data php tools/mint_external_api_token.php --user=&lt;id&gt; --name="..." --scopes="..."</code> for ops scripts.</p>
'
            ],
            [
                'slug'  => 'external-api-revoke',
                'title' => 'Revoking a Token',
                'body'  => '
<p>If a token leaks, is no longer needed, or you suspect it\'s compromised:</p>
<ol>
    <li><strong>UI:</strong> Settings → External API Tokens → find the row → <strong>Revoke</strong>. Confirm with a reason for the audit log.</li>
    <li><strong>CLI:</strong> <code>sudo -u www-data php tools/revoke_external_api_token.php --id=&lt;N&gt; --reason="..."</code> or <code>--prefix=tcad_p_xxxxxxx</code> if you only have the visible prefix.</li>
</ol>
<p>Revocation is immediate. The next request with the revoked token returns <code>401 token_revoked</code> with no caching delay.</p>
<p>Revoked rows are kept indefinitely so the audit trail stays intact — past activity remains linked to the row by <code>token_id</code>.</p>
'
            ],
            [
                'slug'  => 'webhooks-subscribe',
                'title' => 'Subscribing to Webhooks',
                'body'  => '
<p>Go to <strong>Settings → Webhooks</strong> (requires <code>action.manage_webhooks</code> or admin). Click <strong>Add Webhook</strong> and fill in:</p>
<ul>
    <li><strong>Name</strong> — short label (e.g., "Slack alerts").</li>
    <li><strong>Target URL</strong> — your HTTPS endpoint that will receive the POST.</li>
    <li><strong>Events</strong> — comma-separated list of event types (e.g., <code>incident.created, incident.closed</code>), or wildcards like <code>incident.*</code> or just <code>*</code>.</li>
    <li><strong>HMAC Secret</strong> — leave blank to auto-generate a strong 32-byte secret. The full secret appears in the save response ONE TIME — copy it then.</li>
    <li><strong>Max retry attempts</strong> — defaults to 5 with exponential backoff (30s → 60s → 120s → 240s → 480s).</li>
    <li><strong>Active</strong> — uncheck to pause deliveries without deleting the subscription.</li>
</ul>
<p>After save, the receiver needs to:</p>
<ol>
    <li>Verify the <code>X-Webhook-Signature: sha256=&lt;hex&gt;</code> header against the request body using your HMAC secret.</li>
    <li>Respond with HTTP 2xx within 5 seconds. Anything else triggers retry.</li>
    <li>Treat <code>delivery_id</code> from the body as the dedup key — you WILL occasionally get duplicates.</li>
</ol>
<p>Watch deliveries in the <strong>Recent Deliveries</strong> widget below the webhook list. Failed deliveries that exhaust retries land in <strong>dead-letter</strong> for manual replay.</p>
'
            ],
            [
                'slug'  => 'webhooks-secret-rotation',
                'title' => 'Rotating a Webhook Secret',
                'body'  => '
<p>If your webhook secret leaks (committed to a repo, posted in chat, etc.):</p>
<ol>
    <li>Settings → Webhooks → click the row → enter a NEW secret in the HMAC field → Save.</li>
    <li>Update your receiver to use the new secret BEFORE the next delivery fires.</li>
</ol>
<p><strong>Important — secret-edit behavior (since 2026-06-28):</strong> when you open an existing webhook for editing, the HMAC secret field is BLANK and the placeholder shows the first 8 characters of the current secret (e.g. "leave blank to keep (current starts: abc12345…)"). Submitting with the field blank means "keep the current secret unchanged"; entering a new value rotates it.</p>
<p>This prevents the old "open webhook, see full secret, accidentally re-paste" UX hazard. The full secret is never re-disclosed via the admin UI after initial creation. Capture it at create time or use the CLI for emergency reads.</p>
'
            ],
            [
                'slug'  => 'webhooks-ssrf',
                'title' => 'Webhook Target URL Restrictions (SSRF guard)',
                'body'  => '
<p>TicketsCAD\'s outbound webhook delivery refuses to POST to URLs that resolve to internal addresses. Specifically <strong>blocked</strong>:</p>
<ul>
    <li><strong>Loopback</strong>: <code>127.0.0.0/8</code>, <code>::1</code></li>
    <li><strong>Link-local</strong>: <code>169.254.0.0/16</code> (includes AWS/GCP/Azure cloud metadata endpoints)</li>
    <li><strong>RFC1918 private</strong>: <code>10.0.0.0/8</code>, <code>172.16.0.0/12</code>, <code>192.168.0.0/16</code></li>
    <li><strong>IPv6 ULA / link-local</strong>: <code>fc00::/7</code>, <code>fe80::/10</code></li>
    <li><strong>Non-http(s) schemes</strong>: <code>file://</code>, <code>gopher://</code>, <code>dict://</code>, <code>ftp://</code></li>
    <li><strong>Unresolvable hostnames</strong> (DNS failure)</li>
</ul>
<p>Failed deliveries show <code>target URL rejected by SSRF guard</code> in the Recent Deliveries widget. This protects against admin-level mistakes / compromised admin accounts pointing webhooks at internal services (Redis on localhost, the cloud metadata endpoint to harvest IAM credentials, etc.).</p>
<p><strong>Opt-in for legitimate internal destinations:</strong> if your install genuinely needs webhooks pointing at an internal hostname (e.g., a mid-tier service on your LAN), add the hostname suffix to the <code>webhook_url_allowlist</code> setting (Settings → System Settings → search for "webhook_url_allowlist"). Newline-separated list:</p>
<pre><code>internal.example.com
mid-tier.lan</code></pre>
<p>A URL is allowed if its hostname exactly equals OR is a subdomain of any listed suffix.</p>
'
            ],
            [
                'slug'  => 'webhooks-events',
                'title' => 'Webhook Event Catalogue',
                'body'  => '
<p>The complete list of event types TicketsCAD can fire (canonical allowlist in <code>inc/webhooks.php</code> :: <code>_audit_to_webhook_event()</code>). Audit rows that do NOT match a tuple in this map fire NO webhook — even if a future feature adds them. By design.</p>
<ul>
    <li><strong>Incidents:</strong> <code>incident.created</code>, <code>incident.updated</code>, <code>incident.closed</code>, <code>incident.reopened</code>, <code>incident.deleted</code>, <code>incident.note_added</code></li>
    <li><strong>Assignments:</strong> <code>assign.created</code>, <code>assign.removed</code></li>
    <li><strong>Responders:</strong> <code>responder.created</code>, <code>responder.updated</code>, <code>responder.deleted</code>, <code>responder.status_changed</code></li>
    <li><strong>Members:</strong> <code>member.created</code>, <code>member.updated</code>, <code>member.deleted</code>, <code>member.status_changed</code>, <code>member.location_updated</code></li>
    <li><strong>Facilities:</strong> <code>facility.created</code>, <code>facility.updated</code>, <code>facility.deleted</code></li>
    <li><strong>Teams:</strong> <code>team.created</code>, <code>team.updated</code>, <code>team.deleted</code></li>
    <li><strong>Incident types:</strong> <code>incident_type.created</code>, <code>incident_type.updated</code>, <code>incident_type.deleted</code></li>
    <li><strong>Attachments:</strong> <code>attachment.created</code>, <code>attachment.deleted</code></li>
</ul>
<p>26 events total. Subscribe to specific ones, to a category (<code>incident.*</code>), or to all (<code>*</code>). Filtering is server-side — no wasted round-trips.</p>
'
            ],
        ],
    ],

    'keyboard' => [
        'icon'  => 'bi-keyboard',
        'label' => 'Keyboard Shortcuts',
        'topics' => [
            [
                'slug'  => 'global-shortcuts',
                'title' => 'Global Shortcuts',
                'body'  => '
<table class="table table-sm table-bordered">
    <thead><tr><th>Shortcut</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><kbd>/</kbd></td><td>Open the command bar (when not in a text field).</td></tr>
        <tr><td><kbd>Esc</kbd></td><td>Close the command bar, clear selection, or dismiss a dialog.</td></tr>
    </tbody>
</table>
'
            ],
            [
                'slug'  => 'command-bar',
                'title' => 'Command Bar Commands',
                'body'  => '
<p>Press <kbd>/</kbd> on any page to open the command bar, then start typing.
The bar matches on a <strong>unique prefix</strong>, so <code>/in</code> is
enough to identify <code>/incidents</code>. When more than one command shares
the prefix (e.g. <code>/r</code> matches reports, responders, roster, roles,
road), a dropdown of completions appears &mdash; use <kbd>&uarr;</kbd> /
<kbd>&darr;</kbd> + <kbd>Enter</kbd>, click, or <kbd>Tab</kbd>-complete to
pick one.</p>

<h6>Dispatch &amp; workflow</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Command</th><th>Aliases</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><code>/new</code></td><td>&mdash;</td><td>Create a new incident.</td></tr>
        <tr><td><code>/incidents</code></td><td><code>/inc</code></td><td>Focus the Active Incidents widget.</td></tr>
        <tr><td><code>/responders</code></td><td><code>/res</code>, <code>/resp</code></td><td>Focus the Responders widget.</td></tr>
        <tr><td><code>/units</code></td><td><code>/uni</code></td><td>Focus the Responders widget (units view).</td></tr>
        <tr><td><code>/facilities</code></td><td><code>/fac</code></td><td>Focus the Facilities widget.</td></tr>
        <tr><td><code>/log</code></td><td><code>/logs</code></td><td>Focus the Activity Log widget.</td></tr>
        <tr><td><code>/detail</code></td><td>&mdash;</td><td>Open detail view for the selected incident.</td></tr>
        <tr><td><code>/zello</code></td><td><code>/zel</code></td><td>Toggle the Zello radio panel.</td></tr>
    </tbody>
</table>

<h6>Unit status &mdash; Phase 99r</h6>
<p>Change a unit&#39;s status without opening any modal:</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Command</th><th>Example</th><th>Effect</th></tr></thead>
    <tbody>
        <tr><td><code>/s &lt;handle&gt; &lt;status&gt;</code></td><td><code>/s M21 av</code></td><td>Medic 21 &rarr; Available.</td></tr>
        <tr><td><code>/status &lt;handle&gt; &lt;status&gt;</code></td><td><code>/status E2 disp</code></td><td>Engine 2 &rarr; Dispatched. <code>/st</code> is also accepted.</td></tr>
    </tbody>
</table>
<p>Recognized status shortcuts (case-insensitive):</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Status</th><th>Shortcuts</th></tr></thead>
    <tbody>
        <tr><td>Available</td><td><code>av</code>, <code>avail</code>, <code>available</code></td></tr>
        <tr><td>Busy</td><td><code>busy</code></td></tr>
        <tr><td>Unavailable</td><td><code>unav</code>, <code>unavail</code>, <code>unavailable</code></td></tr>
        <tr><td>Dispatched</td><td><code>disp</code>, <code>dispatched</code></td></tr>
        <tr><td>Responding</td><td><code>resp</code>, <code>responding</code></td></tr>
        <tr><td>On Scene</td><td><code>os</code>, <code>onscene</code>, <code>on-scene</code>, <code>on scene</code></td></tr>
        <tr><td>Transporting</td><td><code>tx</code>, <code>transp</code>, <code>transport</code>, <code>transporting</code></td></tr>
        <tr><td>At Facility</td><td><code>af</code>, <code>atfacility</code>, <code>at facility</code></td></tr>
        <tr><td>In Quarters</td><td><code>iq</code>, <code>inquarters</code>, <code>in quarters</code></td></tr>
        <tr><td>Out of Service</td><td><code>oos</code>, <code>out of service</code></td></tr>
    </tbody>
</table>
<p>Multi-word unit names work: <code>/s Engine 2 dispatched</code> sets Engine 2 to Dispatched.
The status keyword is matched from the end of the line, so everything before it is the unit handle.</p>
<p>Statuses that need extra info (e.g. <em>Transporting</em> needs a destination facility,
<em>Out of Service</em> may need a reason note) are refused from the command bar in v1 &mdash;
use the unit&#39;s <strong>S</strong> hotkey instead, which opens a modal with facility autocomplete /
note input. Inline collection from the command bar is a planned v2.</p>

<h6>Navigation</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Command</th><th>Aliases</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><code>/dashboard</code></td><td><code>/sit</code>, <code>/situation</code></td><td>Open the dashboard / situation view.</td></tr>
        <tr><td><code>/search</code></td><td>&mdash;</td><td>Open the search page.</td></tr>
        <tr><td><code>/reports</code></td><td>&mdash;</td><td>Open the reports page.</td></tr>
        <tr><td><code>/settings</code></td><td>&mdash;</td><td>Open the settings page.</td></tr>
        <tr><td><code>/sop</code></td><td>&mdash;</td><td>Open the SOP viewer.</td></tr>
        <tr><td><code>/help</code></td><td>&mdash;</td><td>Open the help page (this one).</td></tr>
        <tr><td><code>/roster</code></td><td>&mdash;</td><td>Open the personnel roster.</td></tr>
        <tr><td><code>/teams</code></td><td><code>/team</code></td><td>Open the teams page.</td></tr>
        <tr><td><code>/schedule</code></td><td>&mdash;</td><td>Open the scheduling page.</td></tr>
        <tr><td><code>/vehicles</code></td><td>&mdash;</td><td>Open the vehicles page.</td></tr>
        <tr><td><code>/equipment</code></td><td>&mdash;</td><td>Open the equipment page.</td></tr>
        <tr><td><code>/roles</code></td><td>&mdash;</td><td>Open the roles &amp; permissions admin page.</td></tr>
        <tr><td><code>/profile</code></td><td>&mdash;</td><td>Open your user profile.</td></tr>
        <tr><td><code>/contacts</code></td><td><code>/constituents</code></td><td>Open the contacts / constituents page.</td></tr>
        <tr><td><code>/messages</code></td><td><code>/messaging</code></td><td>Open internal messaging.</td></tr>
        <tr><td><code>/links</code></td><td>&mdash;</td><td>Open the external links page.</td></tr>
        <tr><td><code>/ics</code></td><td><code>/forms</code></td><td>Open the ICS forms page.</td></tr>
    </tbody>
</table>

<p><kbd>Enter</kbd> executes &middot; <kbd>Tab</kbd> completes to the
highlighted suggestion &middot; <kbd>Esc</kbd> closes the bar.</p>
'
            ],
            [
                'slug'  => 'dashboard-keys',
                'title' => 'Dashboard Navigation',
                'body'  => '
<p>When a widget has focus:</p>
<table class="table table-sm table-bordered">
    <thead><tr><th>Shortcut</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><kbd>&uarr;</kbd> / <kbd>&darr;</kbd></td><td>Move selection up/down.</td></tr>
        <tr><td><kbd>Enter</kbd></td><td>Open the detail page for the selected item.</td></tr>
        <tr><td><kbd>Esc</kbd></td><td>Clear selection and leave the widget.</td></tr>
    </tbody>
</table>
<h6>Incidents Widget Only</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Shortcut</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><kbd>D</kbd></td><td>Dispatch &mdash; open assignment panel.</td></tr>
        <tr><td><kbd>V</kbd></td><td>View &mdash; open incident detail.</td></tr>
        <tr><td><kbd>E</kbd></td><td>Edit the incident.</td></tr>
        <tr><td><kbd>P</kbd></td><td>Pop out &mdash; open in new window.</td></tr>
        <tr><td><kbd>X</kbd></td><td>Close the incident (with confirmation).</td></tr>
        <tr><td><kbd>U</kbd></td><td>Units &mdash; open unit assignment panel.</td></tr>
    </tbody>
</table>

<h6>Responders Widget Only</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Shortcut</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><kbd>V</kbd></td><td>View &mdash; open unit detail.</td></tr>
        <tr><td><kbd>E</kbd></td><td>Edit the unit.</td></tr>
        <tr><td><kbd>D</kbd></td><td>Dispatch &mdash; pick an open incident to assign this unit to.</td></tr>
        <tr><td><kbd>S</kbd></td><td>Status &mdash; change the unit&#39;s status (modal).</td></tr>
        <tr><td><kbd>N</kbd></td><td>Note &mdash; record a note on the unit.</td></tr>
    </tbody>
</table>

<h6>Facilities Widget Only</h6>
<table class="table table-sm table-bordered">
    <thead><tr><th>Shortcut</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><kbd>V</kbd></td><td>View &mdash; open facility detail.</td></tr>
        <tr><td><kbd>E</kbd></td><td>Edit the facility.</td></tr>
        <tr><td><kbd>I</kbd></td><td>Incident@ &mdash; start a new incident at this facility.</td></tr>
        <tr><td><kbd>S</kbd></td><td>Status &mdash; set the facility&#39;s status (modal).</td></tr>
        <tr><td><kbd>N</kbd></td><td>Note &mdash; add a note to the facility log.</td></tr>
        <tr><td><kbd>B</kbd></td><td>Beds &mdash; update bed counts + optional note (modal).</td></tr>
    </tbody>
</table>
'
            ],
            [
                'slug'  => 'new-incident-keys',
                'title' => 'New Incident Form',
                'body'  => '
<table class="table table-sm table-bordered">
    <thead><tr><th>Shortcut</th><th>Action</th></tr></thead>
    <tbody>
        <tr><td><kbd>Tab</kbd></td><td>Move to the next field.</td></tr>
        <tr><td><kbd>Shift+Tab</kbd></td><td>Move to the previous field.</td></tr>
        <tr><td><kbd>Ctrl+Enter</kbd></td><td>Submit the incident from any field.</td></tr>
        <tr><td><kbd>Enter</kbd> (on Lookup)</td><td>Geocode the address.</td></tr>
    </tbody>
</table>
'
            ],
        ],
    ],

    'troubleshooting' => [
        'icon'  => 'bi-wrench-adjustable',
        'label' => 'Troubleshooting',
        'topics' => [
            [
                'slug'  => 'cannot-login',
                'title' => 'I Cannot Log In',
                'body'  => '
<ol>
    <li>Make sure Caps Lock is off.</li>
    <li>For new installations, try the default credentials: <code>admin</code> / <code>admin</code>.</li>
    <li>If you see a &quot;locked&quot; message with a countdown, wait for it to expire (usually 15 minutes).</li>
    <li>Clear your browser&apos;s cookies for the TicketsCAD site and try again.</li>
    <li>Contact your administrator to check or reset your account.</li>
</ol>
'
            ],
            [
                'slug'  => 'locked-out-2fa',
                'title' => 'Locked Out of 2FA',
                'body'  => '
<p>If you lost your authenticator app and do not have backup codes, contact your administrator. They can disable 2FA for your account from Config &gt; Users &gt; Two-Factor Auth.</p>
'
            ],
            [
                'slug'  => 'widgets-not-loading',
                'title' => 'Dashboard Widgets Not Loading',
                'body'  => '
<ol>
    <li>Check the <strong>SSE indicator</strong> (colored dot in the nav bar). Red = disconnected. Refresh the page.</li>
    <li>Press <kbd>F12</kbd> to open browser developer tools and check the Console for errors.</li>
    <li>Navigate to <code>/api/health.php</code> to check if the server is responding.</li>
    <li>Contact your administrator if the problem persists.</li>
</ol>
'
            ],
            [
                'slug'  => 'map-blank',
                'title' => 'The Map Is Blank or Grey',
                'body'  => '
<ol>
    <li>Check your internet connection. Default map tiles load from OpenStreetMap.</li>
    <li>If using a custom tile provider, the API key may be invalid or expired.</li>
    <li>A strict firewall may be blocking outbound access to tile servers.</li>
</ol>
'
            ],
            [
                'slug'  => 'geocoding-issues',
                'title' => 'Address Lookup Returns Wrong Results',
                'body'  => '
<ol>
    <li>Try a more specific address including city and state.</li>
    <li>Verify your default location is configured correctly (Config &gt; Maps &gt; Map Defaults).</li>
    <li>The default geocoder (Nominatim) has usage limits. Wait a few seconds between attempts.</li>
</ol>
'
            ],
            [
                'slug'  => 'realtime-stopped',
                'title' => 'Real-Time Updates Have Stopped',
                'body'  => '
<p>If the dashboard is stale and the SSE indicator is red:</p>
<ol>
    <li>Refresh the page (F5 or Ctrl+R).</li>
    <li>If that does not help, the server&apos;s event stream may be down. Contact your administrator.</li>
</ol>
'
            ],
            [
                'slug'  => 'audio-not-playing',
                'title' => 'Audio Alerts Not Playing',
                'body'  => '
<ol>
    <li>Check that the speaker icon is not muted (should show sound waves, not an X).</li>
    <li>Browsers require at least one user interaction before allowing audio. Click anywhere on the page.</li>
    <li>Check your computer&apos;s volume and mute settings.</li>
</ol>
'
            ],
            [
                'slug'  => 'broken-after-update',
                'title' => 'Interface Looks Broken After Update',
                'body'  => '
<ol>
    <li><strong>Hard refresh:</strong> Press <kbd>Ctrl+Shift+R</kbd> (Windows/Linux) or <kbd>Cmd+Shift+R</kbd> (Mac).</li>
    <li>Clear your browser cache for the TicketsCAD site.</li>
    <li>If installed as a PWA, uninstall and reinstall the app.</li>
</ol>
'
            ],
            [
                'slug'  => 'general-troubleshooting',
                'title' => 'Something Else Not Working',
                'body'  => '
<ol>
    <li>Try refreshing the page.</li>
    <li>Try logging out and back in.</li>
    <li>Try a different browser (Chrome, Firefox, Edge).</li>
    <li>Note the exact error message and what you were doing, then contact your administrator.</li>
</ol>
'
            ],
        ],
    ],

]; // end $help_categories

// Build flat index for search (used by JS)
$flat_topics = [];
foreach ($help_categories as $catKey => $cat) {
    foreach ($cat['topics'] as $topic) {
        $flat_topics[] = [
            'cat'   => $catKey,
            'slug'  => $topic['slug'],
            'title' => $topic['title'],
            'text'  => strip_tags($topic['body']),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(i18n_lang()); ?>" data-bs-theme="<?php echo $bs_theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e($csrf); ?>">
    <title><?php echo e(t('page.help', 'Help')); ?> &mdash; <?php echo e(t('login.title', 'Tickets NewUI')); ?> <?php echo NEWUI_VERSION; ?></title>

    <!-- Vendor CSS -->
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="assets/vendor/bootstrap/bootstrap-icons.min.css">

    <!-- App CSS -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?php echo asset_v('assets/css/dashboard.css'); ?>">
    <link rel="stylesheet" href="assets/css/help.css?v=<?php echo asset_v('assets/css/help.css'); ?>">
</head>
<body>

<?php include_once NEWUI_ROOT . '/inc/navbar.php'; ?>
</header>

<!-- Help Layout: Sidebar + Content -->
<div class="help-layout">

    <!-- Sidebar -->
    <nav class="help-sidebar" id="helpSidebar">
        <!-- Search box -->
        <div class="p-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control form-control-sm" id="helpSearch"
                       placeholder="Search help topics..." autocomplete="off">
            </div>
        </div>

        <!-- Search results area (hidden until searching) -->
        <div class="help-search-results d-none" id="helpSearchResults"></div>

        <!-- Category accordion -->
        <div id="helpCategories">
<?php foreach ($help_categories as $catKey => $cat): ?>
            <button class="help-section-header" data-category="<?php echo $catKey; ?>">
                <i class="bi <?php echo $cat['icon']; ?> section-icon"></i>
                <?php echo e($cat['label']); ?>
                <i class="bi bi-chevron-down chevron"></i>
            </button>
            <ul class="help-topic-list" data-category="<?php echo $catKey; ?>">
<?php foreach ($cat['topics'] as $topic): ?>
                <li>
                    <button class="help-topic-link" data-slug="<?php echo $topic['slug']; ?>" data-category="<?php echo $catKey; ?>">
                        <?php echo e($topic['title']); ?>
                    </button>
                </li>
<?php endforeach; ?>
            </ul>
<?php endforeach; ?>
        </div>
    </nav>

    <!-- Main content area -->
    <div class="help-content" id="helpContent">
        <!-- Welcome panel (shown by default) -->
        <div id="helpWelcome">
            <div class="text-center py-5">
                <i class="bi bi-question-circle" style="font-size:3rem;opacity:0.3"></i>
                <h4 class="mt-3 text-body-secondary">TicketsCAD Help</h4>
                <p class="text-body-tertiary">Select a topic from the sidebar, or use the search box to find what you need.</p>
            </div>
        </div>

        <!-- Topic panels (all rendered, toggled by JS) -->
<?php foreach ($help_categories as $catKey => $cat): ?>
    <?php foreach ($cat['topics'] as $topic): ?>
        <div class="help-topic-panel d-none" id="topic-<?php echo $topic['slug']; ?>" data-category="<?php echo $catKey; ?>">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <small class="text-body-tertiary"><?php echo e($cat['label']); ?></small>
                    <h5 class="mb-0"><?php echo e($topic['title']); ?></h5>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()" title="Print this topic">
                    <i class="bi bi-printer me-1"></i>Print
                </button>
            </div>
            <hr>
            <?php echo $topic['body']; ?>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
    </div>
</div>

<!-- Search index for JS -->
<script>
var HELP_TOPICS = <?php echo json_encode($flat_topics, JSON_UNESCAPED_UNICODE); ?>;
</script>

<!-- Vendor JS -->
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<!-- App JS -->
<script src="assets/js/toolbar.js?v=<?php echo NEWUI_VERSION; ?>"></script>
<script src="assets/js/theme-manager.js"></script>
<script src="assets/js/help.js?v=<?php echo asset_v('assets/js/help.js'); ?>"></script>

</body>
</html>
