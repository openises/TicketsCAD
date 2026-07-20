# Map Configuration Guide

This guide covers setting up the map features in NewUI: weather overlays,
default coordinates, and the area title display.

The fastest path is the **Settings → Map Settings** admin panel, which has a
live preview map you pan and zoom to capture your desired view. The SQL fallback
paths in this guide are kept for unattended installs and disaster recovery; the
GUI is the recommended way for day-to-day use.

---

## Table of Contents

1. [OpenWeatherMap API Key](#openweathermap-api-key)
2. [Default Map Position](#default-map-position)
3. [Area Title](#area-title)
4. [Available Map Layers](#available-map-layers)
5. [Troubleshooting](#troubleshooting)
6. [Settings Key Reference](#settings-key-reference)

---

## OpenWeatherMap API Key

Weather overlays on the map (clouds, precipitation, radar, temperature, wind,
snow, and city weather) require a free API key from OpenWeatherMap (OWM).

### Step 1: Create an OpenWeatherMap account

1. Go to https://openweathermap.org/
2. Click **Sign Up** in the top-right corner
3. Fill in the registration form (username, email, password)
4. Check your email and confirm your account

### Step 2: Get your API key

1. Log in at https://openweathermap.org/
2. Click your username in the top-right, then select **My API keys**
   (direct link: https://home.openweathermap.org/api_keys)
3. Copy the **Default** key (long string of letters/numbers, e.g.
   `a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4`)

> **Important:** New API keys can take up to 2 hours to activate. If weather
> layers don't appear immediately, wait and try again.

### Step 3: Save the key in NewUI

**Recommended — via the Settings panel:**

1. Log in as an administrator
2. Click your name (top-right) → **Settings**
3. In the left sidebar choose **System** → **API Keys**
4. Paste the key into the **OpenWeatherMap API Key** field
5. Click **Save API Keys**

That's it. The eye icon next to the field lets you reveal what you typed;
the clipboard icon copies it.

**Fallback — direct database update**  
Use this only when you cannot reach the admin UI (recovery, scripted install,
container bootstrap). Run against your NewUI database:

```sql
-- Newer installs (and anything written by the GUI) use this key:
UPDATE settings SET value = 'YOUR_API_KEY_HERE' WHERE name = 'owm_api_key';
INSERT INTO settings (name, value)
  SELECT 'owm_api_key', 'YOUR_API_KEY_HERE'
  WHERE NOT EXISTS (SELECT 1 FROM settings WHERE name = 'owm_api_key');
```

The weather proxy also reads the legacy key `openweathermaps_api` if
`owm_api_key` is empty, so a value in either row will work.

### Step 4: Verify

1. Refresh the NewUI dashboard
2. Click the layers icon (stacked diamonds) in the top-right of the map
3. You should now see weather overlay options: Clouds, Precipitation, Rain,
   Pressure, Temperature, Wind, Snow, and City Weather
4. Check any overlay to enable it

### API key security

The API key is **never sent to the browser**. All weather requests are routed
through a server-side caching proxy (`api/weather-proxy.php`). The browser
only knows whether weather is "enabled" or not.

### API key plans and rate limits

The **free tier** of OpenWeatherMap includes:

- 1,000 API calls per day (data API)
- 60 requests per minute
- Weather map tiles and weather layers

Higher limits available at https://openweathermap.org/price.

### Server-side caching proxy

NewUI's built-in caching proxy protects against exceeding API limits even
with many simultaneous users:

- Weather tile and city weather requests route through `api/weather-proxy.php`
- Tile images are cached on disk for **30 minutes** (OWM's update cycle)
- City weather JSON is cached for **15 minutes**
- Multiple browser clients share the same server-side cache
- A rate limiter caps outbound OWM requests to **55 per minute**
- If the rate limit is hit, stale cached data is served as a fallback

**Cache location:** `newui/cache/weather/` (auto-created, excluded from git)

**Estimated API usage with the proxy:**

| Scenario                  | Daily API Calls (approx) |
|---------------------------|--------------------------|
| 1 dispatcher, all layers  | 50–80                    |
| 5 dispatchers, all layers | 80–120                   |
| 10 dispatchers            | 100–150                  |
| 20 dispatchers            | 120–200                  |

Without the proxy, 10 dispatchers could use 400–600+ calls/day. The proxy
reduces this dramatically because cached responses serve all clients.

**Cache maintenance:** Self-managing — old tiles and JSON files are
overwritten when they expire. To clear manually (e.g., after changing the
API key), delete the contents of `newui/cache/weather/`.

---

## Default Map Position

The map centers on default coordinates when the dashboard first loads or
when the view is reset.

### Recommended — Settings panel (with live preview map)

1. Log in as an administrator
2. Click your name (top-right) → **Settings**
3. In the left sidebar choose **Maps & Places** → **Map Settings**
4. **Pan and zoom the preview map** in the panel to your desired centre and
   zoom level — the latitude, longitude, and zoom fields update automatically
   as you do
5. Optionally pick a **Default Layer** (Street, Satellite, or Terrain)
6. Click **Save Map Defaults**

The preview map is 16:9 to match what dispatchers see on the situation
screen, so what you set up here is what they'll see.

You can also type values directly into the fields if you already know the
exact coordinates and zoom level you want.

**Finding coordinates without the preview map:**

1. Go to https://www.google.com/maps
2. Right-click on your desired centre point
3. Copy the latitude and longitude that appear at the top of the menu
4. Paste each value into the matching field in the Map Settings panel

### Fallback — direct database update

Only when the admin UI is unreachable:

```sql
-- GUI-canonical keys (used by anything written through Settings → Map Settings):
UPDATE settings SET value = '44.9778'  WHERE name = 'default_lat';
UPDATE settings SET value = '-93.2650' WHERE name = 'default_lng';
UPDATE settings SET value = '12'       WHERE name = 'default_zoom';
```

If the rows don't exist:

```sql
INSERT INTO settings (name, value) VALUES ('default_lat',  '44.9778');
INSERT INTO settings (name, value) VALUES ('default_lng',  '-93.2650');
INSERT INTO settings (name, value) VALUES ('default_zoom', '12');
```

`api/map-config.php` reads each `default_*` key first and falls back to the
legacy short names (`def_lat`, `def_lng`, `def_zoom`) if the new key is
empty. Either name works; new writes should use `default_*` so the Map
Settings GUI shows the right value when you re-open it.

### Zoom level guide

| Zoom  | View         |
|-------|--------------|
| 3–4   | Country      |
| 5–6   | State/Region |
| 7–9   | County       |
| 10–12 | City         |
| 13–15 | Neighborhood |
| 16–18 | Street       |

For most CAD operations, zoom levels **10–13** work well (city to neighborhood).

---

## Area Title

A small label in the bottom-right of the map showing the name of your
coverage area (e.g. "Metro Dispatch" or "County Fire").

### Recommended — Settings panel

1. Log in as an administrator
2. Click your name → **Settings**
3. In the left sidebar choose **System** → **System Settings**
4. In the **General** section, set the **Area Title** field
5. Click **Save** at the bottom of the System Settings form

Leave it blank to hide the title.

### Fallback — direct database update

```sql
UPDATE settings SET value = 'Metro Dispatch' WHERE name = 'area_title';
INSERT INTO settings (name, value)
  SELECT 'area_title', 'Metro Dispatch'
  WHERE NOT EXISTS (SELECT 1 FROM settings WHERE name = 'area_title');
```

`api/map-config.php` reads `area_title` first and falls back to the legacy
key `map_area_title` for older installs. Either works.

---

## Available Map Layers

### Base layers (choose one)

| Layer            | Description                       | API key required |
|------------------|-----------------------------------|------------------|
| Open Streetmaps  | Standard OSM street map           | No               |
| USGS Topo        | US Geological Survey topographic  | No               |
| Dark             | CartoDB dark theme for night use  | No               |

### Overlay layers (toggle independently)

| Layer         | Description                         | API key required |
|---------------|-------------------------------------|------------------|
| Incidents     | Red markers for active incidents    | No               |
| Responders    | Blue markers for responder units    | No               |
| Facilities    | Green markers for facilities        | No               |
| Grid          | Latitude/longitude grid lines       | No               |
| Radar         | NEXRAD weather radar (US coverage)  | No               |
| Clouds        | Cloud cover overlay                 | OWM key          |
| Precipitation | Precipitation intensity             | OWM key          |
| Rain          | Rain intensity                      | OWM key          |
| Pressure      | Atmospheric pressure contours       | OWM key          |
| Temperature   | Temperature overlay                 | OWM key          |
| Wind          | Wind speed and direction            | OWM key          |
| Snow          | Snow coverage                       | OWM key          |
| City Weather  | Weather icons over cities (zoom 8+) | OWM key          |

Layer selections are saved automatically and persist across page reloads.

---

## Troubleshooting

### Weather layers don't appear in the layer control

- **Cause:** No OWM API key configured, or the key is empty
- **Fix:** Follow the [OpenWeatherMap API Key](#openweathermap-api-key) steps
  above — easiest via Settings → System → API Keys

### Weather layers appear but show no data

- **Cause:** New API key not yet activated (up to 2 hours)
- **Fix:** Wait 2 hours after key creation, then refresh
- **Cause:** API key is invalid or expired
- **Fix:** Log in to https://home.openweathermap.org/api_keys and verify
  the key is active

### Map shows wrong default location

- **Cause:** Default coordinates not set, or set in a key the consumer
  doesn't read
- **Fix:** Open **Settings → Maps & Places → Map Settings**, pan/zoom the
  preview to the correct view, and save — the GUI writes the canonical
  keys

### Map Settings panel saves successfully but the dashboard still shows the old centre

- **Cause:** Browser cache or a service worker holding the previous tiles
- **Fix:** Hard-refresh (Ctrl+Shift+R) the dashboard, or log out and back
  in. If it persists, check the `settings` table directly:
  ```sql
  SELECT name, value FROM settings
  WHERE name IN ('default_lat','default_lng','default_zoom',
                 'def_lat','def_lng','def_zoom');
  ```
  Both name pairs are read; if both contain values, the `default_*` row
  wins.

### City Weather icons don't appear

- **Cause:** Zoom level is below 8
- **Fix:** Zoom in closer; city weather icons only display at zoom level 8
  and above

### NEXRAD Radar shows no data

- **Cause:** No active precipitation in the viewed area, or the NEXRAD
  service is temporarily unavailable
- **Note:** NEXRAD only covers the continental United States

---

## Settings Key Reference

For administrators auditing the `settings` table or writing migration
scripts, here is the full picture of which keys back each Map Settings
field.

| What it controls   | GUI panel                       | GUI key (canonical) | Legacy key also read |
|--------------------|---------------------------------|---------------------|----------------------|
| Default latitude   | Maps & Places → Map Settings    | `default_lat`       | `def_lat`            |
| Default longitude  | Maps & Places → Map Settings    | `default_lng`       | `def_lng`            |
| Default zoom level | Maps & Places → Map Settings    | `default_zoom`      | `def_zoom`           |
| Default base layer | Maps & Places → Map Settings    | `default_map_layer` | —                    |
| Area title         | System → System Settings        | `area_title`        | `map_area_title`     |
| OpenWeatherMap key | System → API Keys               | `owm_api_key`       | `openweathermaps_api`|

`api/map-config.php` and `api/weather-proxy.php` read the GUI key first and
fall back to the legacy key if the GUI key is empty. New writes should use
the GUI-canonical name; legacy installs continue to work without migration.
