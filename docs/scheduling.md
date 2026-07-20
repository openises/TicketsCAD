# Scheduling — User Guide

## Overview

The Scheduling page (Personnel > Scheduling) manages two things:
1. **Shifts** — recurring duty rotations (e.g., nightly on-call, weekly net control)
2. **Events** — one-time activities (drills, exercises, deployments, meetings, training)

---

## Shifts

Shifts are built from three layers:

| Layer | What it is | Example |
|-------|-----------|---------|
| **Template** | A named rotation pattern | "Skywarn Weekly On-Call" |
| **Roles** | Positions to fill within the template | "Net Control", "Backup", "Observer" |
| **Slots** | Time blocks defining when shifts occur | Mon 9pm–8am, Tue 9pm–8am, etc. |

Members are then **assigned** to specific slots + roles on specific calendar dates.

### Step-by-Step: Creating a Shift Schedule

#### 1. Create a Template

1. Click the **+** button next to "Shift Templates"
2. Enter a name (e.g., "Nightly On-Call")
3. The template appears in the left panel — click it to select it
4. Fill in the **Template Settings**:
   - **Name**: Display name
   - **Description**: Optional notes about this rotation
   - **Rotation Weeks**: How many weeks before the pattern repeats (1 = weekly, 4 = monthly)
   - **Timezone**: Your local timezone (e.g., America/Chicago)
   - **Active**: Check to make this template live
5. Click **Save Template**

#### 2. Add Roles

Roles define the positions that need to be filled each shift.

1. In the Template Settings panel, find the **Roles** section
2. Click the **+** button next to "Roles"
3. Enter a role name (e.g., "Net Control")
4. Set **Min Slots** (minimum required per shift) and **Max Slots** (maximum allowed)
5. Optionally require a certification or ICS qualification for this role
6. Click **Save**

Examples:
- "Net Control" — min: 1, max: 1 (exactly one person)
- "Support" — min: 0, max: 3 (up to three people, optional)

#### 3. Define Time Slots

**This is the step that defines WHEN shifts happen.** Slots are time blocks on specific days of the week.

1. With a template selected, look for the **Slots** section below Roles
2. Click **+ Add Slot**
3. Fill in:
   - **Day**: Which day of the week (Monday–Sunday)
   - **Start Time**: When the shift starts (e.g., 21:00 for 9pm)
   - **End Time**: When the shift ends (e.g., 08:00 for 8am)
   - **Label**: Optional display name (e.g., "Night Shift", "Day Shift")
   - **Week**: Which week in the rotation (Week 1, Week 2, etc.)
4. Click **Save**

**Overnight shifts**: If start time is after end time (e.g., 21:00–08:00), the system understands this spans midnight.

**Example for 24/7 coverage with two shifts:**

| Slot | Day | Start | End | Label |
|------|-----|-------|-----|-------|
| 1 | Monday | 08:00 | 21:00 | Day Shift |
| 2 | Monday | 21:00 | 08:00 | Night Shift |
| 3 | Tuesday | 08:00 | 21:00 | Day Shift |
| 4 | Tuesday | 21:00 | 08:00 | Night Shift |
| ... | ... | ... | ... | ... |

For a **1-week rotation**, create 14 slots (2 per day x 7 days). For a **4-week rotation**, you can vary the pattern across weeks using the Week number.

#### 4. Assign Members

Once slots and roles are defined, the weekly calendar grid appears on the right side.

1. Navigate to the desired week using the **< >** arrows or click **Today**
2. Each cell in the grid shows the shift slot with role badges
3. Click the **+** button next to a role to add a member
4. Select the member from the list
5. The assignment appears as a badge in the cell

**Assignment statuses:**
- **Assigned** — admin placed them on the schedule
- **Confirmed** — member acknowledged
- **Completed** — shift was worked
- **No-Show** — member didn't show up
- **Cancelled** — assignment was cancelled
- **Swapped** — another member took over

#### 5. Self-Signup

Members can sign up for open slots themselves:
1. Member views the schedule
2. Clicks an open slot where they meet the role prerequisites
3. System checks certifications and capacity
4. If approved, they appear on the schedule with "self-signup" noted

Admins can override prerequisites using the **Force** option.

---

## Reading the Week Grid

The weekly calendar shows:

```
SHIFT        | MON 3/16  | TUE 3/17  | WED 3/18  | ...
─────────────┼───────────┼───────────┼───────────┼────
Night         | [N0NKI]  | [open]    | [KB9GHI]  |
9:00p-8:00a   |  Net Ctrl |  + add    |  Net Ctrl |
              | [WA9XYZ] |           |           |
              |  Support  |           |           |
─────────────┼───────────┼───────────┼───────────┼────
Day           | [open]   | [N0NKI]   | [open]    |
8:00a-9:00p   |  + add   |  Net Ctrl |  + add    |
```

- **Left column**: Shift label + time range
- **Badges**: Member callsign/name with role underneath
- **+ add**: Open slot available for assignment
- **Today's column** is highlighted

---

## Multi-Week Rotations

For a 4-week rotation:
- Set **Rotation Weeks** to 4
- Create slots for each week (Week 1, Week 2, Week 3, Week 4)
- Different weeks can have different shift patterns
- The system cycles through weeks automatically

Example: Week 1 might have extra coverage, Week 4 might be lighter.

---

## Events

Events are one-time scheduled activities.

### Creating an Event

1. Click the **Events** tab
2. Click **+ New Event**
3. Fill in:
   - **Name**: Event title (e.g., "Spring Tornado Drill")
   - **Type**: Drill, Exercise, Deployment, Meeting, Training, Other
   - **Start/End Date**: When it occurs
   - **Location**: Where
   - **Max Participants**: Capacity limit (0 = unlimited)
   - **Required Certifications**: Optional prerequisites
   - **Status**: Planned, Active, Completed, Cancelled
4. Click **Save**

### Managing Participants

1. Click an event to view details
2. Click **+ Add Participant** to register members
3. On the event day:
   - Click **Check In** when a member arrives (records arrival time)
   - Click **Check Out** when they leave (records departure, auto-calculates hours)
4. Hours worked are tracked per participant

### Event Types

| Type | Use For |
|------|---------|
| Drill | Practice scenarios (e.g., tornado drill) |
| Exercise | Full-scale exercises (e.g., FEMA exercise) |
| Deployment | Real-world activations |
| Meeting | Planning meetings, briefings |
| Training | Classes, workshops, certifications |
| Other | Anything else |

---

## Certification Prerequisites

Roles can require certifications before a member can be assigned:

1. When creating/editing a Role, select required certifications
2. When assigning a member, the system checks their certification records
3. If they lack a required cert, assignment is blocked with an error message
4. Admins can force-assign by checking the **Force** option (bypasses prerequisites)

Similarly, roles can require a specific **ICS Position** qualification.

---

## Tips

- **Keyboard**: Use the week navigation arrows to quickly browse the schedule
- **Timezone**: Set the template timezone to match your operating area — all times display in that zone
- **Active/Inactive**: Deactivate a template to hide it from the schedule without deleting it
- **Audit Trail**: All assignment changes are logged in the system audit log
