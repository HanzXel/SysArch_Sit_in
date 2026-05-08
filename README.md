# CCS Sit-in Monitoring System — Enhancement Guide
## Files Included & Where to Place Them

---

## STEP 1 — Run the SQL patch in phpMyAdmin

Open phpMyAdmin → select `ccs_sitin` database → go to the **SQL** tab → paste and run:

```
Database/setup_enhancements.sql
```

This creates:
- `labs` table (laboratory list with rows/cols config)
- `seats` table (individual computer seats per lab)
- `software_categories` table
- `software` table
- Adds `seat_id`, `seat_number` columns to `reservations`
- Adds `seat_number` column to `sit_in`

---

## STEP 2 — Copy files to your project root

| File (from this package) | Copy to |
|---|---|
| `manage_labs.php` | project root |
| `manage_software.php` | project root |
| `software.php` | project root |
| `student_reservation.php` | project root (replaces existing) |
| `userdb.php` | project root (replaces existing) |
| `landing.php` | project root (replaces existing) |
| `reports.php` | project root (replaces existing) |
| `Database/setup_enhancements.sql` | Database/ folder |

---

## STEP 3 — Update your admin navbar

In ALL admin pages (`admin_dashboard.php`, `sitin_logs.php`, `reservations.php`,
`manage_students.php`, `student_feedback.php`), add these two links to
`.dashboard-right` ul — add them after "Sit-in Logs":

```html
<li><a href="manage_labs.php">Manage Labs</a></li>
<li><a href="manage_software.php">Manage Software</a></li>
```

---

## STEP 4 — Update your student navbar

In `userdb.php`, `notifications.php`, `edit_profile.php`, `history.php`,
`feedback.php` — add this link to `.dashboard-right` ul:

```html
<li><a href="software.php">Software</a></li>
```

---

## Features Summary

### 🏛️ Admin Side

#### Manage Labs (`manage_labs.php`)
- View all labs as clickable cards (active/inactive indicator)
- Click any lab to see its full seat layout grid
- Toggle individual seats green ↔ red (available / under maintenance)
- Add new lab with custom rows × columns
- Edit lab description, layout size, active status
- Delete lab (removes all seats)

#### Manage Software (`manage_software.php`)
- Category sidebar (Web Browsers, Programming IDEs, Database, Office, etc.)
- Add/remove software categories with emoji icons
- Add software: name, version, icon, description, category
- Remove individual software items
- Visual card grid per category

#### Reports with PDF Export (`reports.php`)
- All existing charts retained (monthly, daily, per-lab, by-purpose)
- **New: "Generate PDF Reports" section**
  - **Student List PDF**: all students, landscape A4, auto-table
  - **Sit-in Logs PDF**: filterable by date range, landscape A4
  - Both PDFs include: branded header (navy bar), footer with page numbers, alternating row colors

### 🎓 Student Side

#### Software Page (`software.php`)
- Collapsible category sections with emoji icons
- Card grid per category showing icon, name, version
- Live search/filter across all software
- Active lab badges banner at top

#### Reservation with Seat Picker (`student_reservation.php`)
- Select lab → interactive seat grid appears
- Color-coded seats: 🟢 available / 🔴 taken
- Aisle separator in the middle of each row
- Teacher's desk marker at top
- Click seat to select (turns blue)
- Seat number stored with reservation
- Selected seat badge confirmation

#### Dashboard with Sit-in Summary (`userdb.php`)
- Left panel now includes "Sit-in Summary" below student info:
  - **Total Sitting Hours** (sum of all completed sessions)
  - **Sessions Used** (count of sit-in records)
  - **Average Duration** (formatted as Xh Ym)
  - **Longest Session** (formatted as Xh Ym)

#### Landing Page Leaderboard (`landing.php`)
- New section below hero: "Top 10 Students Leaderboard"
- Shows: rank medal 🥇🥈🥉, profile picture, full name, ID, course
- Shows total sessions count + total hours
- Gold/silver/bronze highlight for top 3
- Animated fade-in cards
- Empty state if no data yet

---

## Notes

- All tables are auto-created by PHP if they don't exist (safe fallback)
- Default labs (524, 526, 528, 530, 542, 544) auto-seeded if labs table is empty
- Default software auto-seeded if software table is empty
- PDF generation uses **jsPDF + jsPDF-AutoTable** (loaded from CDN — requires internet)
- Seat availability is checked against today's `approved`/`pending` reservations
