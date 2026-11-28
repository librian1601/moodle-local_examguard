# ExamGuard (`local_examguard`)

**Version:** 1.2.0 | **Compatibility:** Moodle 4.4 – 4.5 | **License:** GNU GPL v3.0 or later

ExamGuard is a server-enforced anti-cheating and proctoring monitor for Moodle quiz attempts. It activates strictly on `/mod/quiz/attempt.php` for quizzes where ExamGuard is enabled, and ships as plain PHP, CSS, and vanilla ES5 JavaScript — no Node/Grunt build step is required.

---

## Key Features

### 1. Focus, Tab-Switch & Browser AI Detection
- **Instant Tab-Switch & Minimize Detection:** Authoritatively catches tab switches and window minimization (even fast switches `< 120ms`) and locks the page immediately.
- **Browser AI Side-Panel Watchdog (e.g. Chrome "Ask Gemini"):** A 200ms `document.hasFocus()` watchdog detects focus leaving the quiz document to browser chrome/side panels while ignoring legitimate internal focus inside Essay editors (TinyMCE / Atto).
- **DOM Question Swapping:** Replaces `.qtext` content with a localized security placeholder the instant focus or visibility is lost, preventing AI sidebars from scraping question text, and restores it automatically once focus returns and the page is unlocked.
- **Navigation-Safe Pagination:** Narrowed Moodle quiz navigation selectors and short-lived cross-page markers ensure normal `Multiple Choice ↔ Essay` pagination produces zero false positives.

### 2. Server-Enforced Penalty Engine (Anti-Snowball)
- **Authoritative Server Deadlines:** All penalty deadlines are calculated and stored server-side (`local_examguard_penalty`). Refreshing the page, clearing browser storage, or changing the device clock cannot bypass an active lock.
- **Anti-Flash Head Lock (`headlock`):** Hides quiz content before first paint when a locked attempt page is reloaded, backed by a 10-second failsafe.
- **Lock-Pause Rule & Edge-Triggered Watchdog:** Normal `windowblur` / `tabswitch` events are ignored while the screen is already locked, preventing idle or background windows from snowballing penalties indefinitely.
- **Hard Cap (`maxpenaltyseconds`):** Caps the maximum active white-screen countdown (default `600s`) even under repeated DevTools overlay tampering (`overlayremoved`).
- **Synced Quiz Timer Freeze:** For timed quizzes, shifts `quiz_attempts.timestart` by the actual added penalty duration (capped by `freezetimermax`) so students do not lose exam time while locked, and automatically resumes the Moodle countdown upon unlock.

### 3. Single-Tab Guard
- Prevents a student from keeping the same quiz attempt open across multiple browser tabs simultaneously using `BroadcastChannel` + `localStorage` lease coordination.
- Secondary tabs enter a non-penalty duplicate mode (questions hidden, zero violation spam) with a **"Continue in This Tab"** takeover button that cleanly transfers ownership and demotes the previous tab.
- Normal in-tab pagination releases the lease on `beforeunload`/`pagehide` so page transitions never trigger false duplicate warnings.

### 4. Content Protection, AI Traps & Watermark
- **Hidden AI Prompt Traps & ZWSP Obfuscation:** Injects hidden adversarial instructions (`clip-path: inset(50%)`) and optional zero-width spaces into question text (automatically skipping math markup, drag-and-drop placeholders, code blocks, and form inputs).
- **Clipboard, Print & DevTools Deterrence:** Blocks copy, cut, paste, drag, right-click, text selection, printing, and common clipboard/DevTools shortcuts.
- **Per-Quiz Traceable Watermark:** Optional diagonal overlay displaying the student's full name, username, IP address, and live server timestamp.

### 5. Independent Per-Quiz Language
- Configure student-facing warnings, lock overlays, countdown labels, DOM swap placeholders, and duplicate-tab prompts independently per quiz: **Bahasa Indonesia (`id`)**, **English (`en`)**, or **Follow Moodle language (`auto`)** — works even when the Indonesian language pack is not installed in `moodledata/lang/`.

### 6. Proctor Dashboard & Quiz Secondary Navigation
- **Direct Quiz Tab:** Automatically adds an **ExamGuard** tab in Moodle 4.5 quiz secondary navigation (`Quiz | Settings | Questions | Results | Question bank | ExamGuard`) whenever ExamGuard is enabled on the quiz or historical logs exist (hidden from students).
- **Active Locks Panel ("Currently Locked Students"):** Displays a live count badge, real-time remaining countdowns, fast search (name, username, email, ID number), and one-click **Reset Penalty** (per-student or bulk).
- **Auto-Unlock with Full Audit Trail:** A 6-second heartbeat automatically unlocks the student's screen and restores questions after a proctor reset while preserving `violationcount`, `frozenseconds`, and logging a `manualreset` audit entry.
- **Exportable Violation History:** Filterable, sortable log table supporting CSV/Excel downloads.

---

## Requirements

- Moodle **4.4** or **4.5** (`$plugin->requires = 2024042200`)
- Standard Moodle `mod_quiz` module

---

## Installation & Upgrade

1. Upload the release ZIP via **Site administration > Plugins > Install plugins**, or copy the `examguard/` directory to `<moodle>/local/examguard/`.
2. Run the database upgrade via **Site administration > Notifications** or CLI:
   ```bash
   php admin/cli/upgrade.php --non-interactive
   ```
3. Purge Moodle caches so `styles.css`, hooks, and language strings are registered:
   ```bash
   php admin/cli/purge_caches.php
   ```
4. Review global defaults at **Site administration > Plugins > Local plugins > ExamGuard**.
5. Enable protection per quiz under **Quiz > Settings > ExamGuard Security**.

---

## Configuration

### Global Settings (`Site administration > Plugins > Local plugins > ExamGuard`)

| Setting | Default | Description |
|---|---|---|
| `enabled` | On (`1`) | Master switch for the entire site. |
| `defaultlang` | `id` | Default student UI language (`id`, `en`, or `auto`). |
| `applytoteachers` | Off (`0`) | Also monitor teacher preview attempts. |
| `penaltyseconds` | `120` | Default lock duration (seconds) per violation. |
| `maxpenaltyseconds` | `600` | Default hard cap (seconds) on active lock countdown. |
| `dedupewindow` | `3` | Minimum seconds between duplicate events. |
| `freezetimer` | On (`1`) | Pause/extend timed quiz countdown during penalties. |
| `freezetimermax` | `600` | Default max cumulative frozen seconds per attempt (`0` = unlimited). |
| `headlock` | On (`1`) | Hide quiz content before JS loads when reloading a locked attempt. |
| `trapenabled` / `traptext` | On | Inject hidden adversarial AI prompt traps inside `.qtext`. |
| `zwspenabled` | On (`1`) | Obfuscate question text with zero-width spaces. |
| `blockcopy` | On (`1`) | Block copy, cut, paste, drag, selection, and right-click. |
| `blockdevtools` | Off (`0`) | Deter F12 and DevTools keyboard shortcuts. |
| `watermarkenabled` / `watermarkopacity` | On / `0.12` | Default watermark toggle and opacity (`0.02`–`0.60`). |
| `retentiondays` | `90` | Days before old logs/states are purged by the daily scheduled task. |
| `reportperpage` | `50` | Number of rows per page in the violation history table. |

### Per-Quiz Settings (`Quiz > Settings > ExamGuard Security`)

| Field | Default | Description |
|---|---|---|
| `examguard_enabled` | No (`0`) | Enables ExamGuard for this specific quiz. |
| `examguard_penaltyseconds` | `120` | Lock duration (seconds) per violation in this quiz. |
| `examguard_maxpenaltyseconds` | Global (`600`) | Hard cap (seconds) on active penalty countdown (`>= penaltyseconds`). |
| `examguard_freezetimermax` | `600` | Max frozen seconds per attempt (`0` = unlimited). |
| `examguard_watermark` | Global (`1`) | Show or hide the traceable watermark for this quiz. |
| `examguard_lang` | Global (`id`) | Display language for student alerts/overlays (`id`, `en`, `auto`). |

---

## Proctor Dashboard & Capabilities

Proctors and administrators can open the dashboard from three scopes:
- **Quiz level:** **ExamGuard** tab inside the quiz's secondary navigation (`/local/examguard/index.php?cmid=<id>`).
- **Course level:** Course administration > **ExamGuard violation report** (`/local/examguard/index.php?courseid=<id>`).
- **Site level:** Site administration > Plugins > Local plugins > **ExamGuard violation report** (`/local/examguard/index.php`).

| Capability | Context | Default Roles | Purpose |
|---|---|---|---|
| `local/examguard:viewreport` | Course | Teacher, Editing teacher, Manager | View quiz/course violation reports and active locks. |
| `local/examguard:manage` | Course | Editing teacher, Manager | Reset/unlock active penalties (individual and bulk). |
| `local/examguard:viewall` | System | Manager | View site-wide violation reports across all courses. |

---

## Architecture

1. **`db/hooks.php` & `classes/hook_listener.php`** — Registers Moodle 4.4+ hooks (`before_http_headers` to promote the quiz secondary navigation tab; `before_standard_head_html_generation` and `before_footer_html_generation` to inject `headlock` CSS, localized JSON config, and `js/guard.js`). Legacy callbacks in `lib.php` remain for compatibility.
2. **`js/guard.js`** — Self-contained vanilla ES5 client monitor managing focus/visibility detection, DOM Question Swapping, Single-Tab Guard, anti-tamper overlay enforcement, 6-second heartbeat sync, and watermark rendering.
3. **`ajax.php` & `classes/ajax_handler.php`** — Authenticated JSON endpoint validating `POST`, `sesskey`, attempt ownership, and capabilities before delegating to `penalty_manager`.
4. **`classes/penalty_manager.php`** — Transactional server-side engine enforcing Lock-Pause, `maxpenaltyseconds` hard cap, freeze-timer shifts (`quiz_attempts.timestart`), active lock queries, and audit-preserving resets (`manualreset`).
5. **`classes/lang_helper.php`** — Direct string loader enabling per-quiz `id`/`en`/`auto` switching without requiring Moodle language packs.
6. **`index.php` & `classes/table/logs_table.php`** — Multi-scope proctor dashboard with live Active Locks panel and downloadable history table.
7. **`classes/task/cleanup_logs.php`** — Daily scheduled task removing expired logs, stale penalty states, and `user_preferences` mirrors.

---

## Testing Checklist

1. **Tab Switch & Ask Gemini:** Switch tabs or click Chrome's AI side panel during an attempt — question text swaps out immediately, white lock overlay appears, and countdown begins.
2. **Reload Persistence:** Reload the page while locked — `headlock` hides questions before paint and the countdown resumes from server time.
3. **DevTools Tamper & Hard Cap:** Remove `#examguard-penalty-overlay` via DevTools — overlay returns immediately, `overlayremoved` is logged, and the countdown never exceeds `maxpenaltyseconds`.
4. **Anti-Snowball (Idle):** Leave the browser unfocused while locked — no additional violations or lock extensions accumulate.
5. **Single-Tab Guard:** Open the same attempt in a second tab — Tab B shows the duplicate-tab screen with hidden questions and sends 0 violations; clicking **Continue in This Tab** transfers ownership and demotes Tab A.
6. **Normal Pagination:** Navigate `Multiple Choice ↔ Essay` within one tab — 0 violations and no duplicate-tab prompt.
7. **Proctor Reset Penalty:** Click **Reset Penalty** in the quiz's **ExamGuard** tab — student unlocks automatically within ~6 seconds, questions and timer resume, and `violationcount` remains intact with a `manualreset` audit row.
8. **Scheduled Cleanup:** Run manually via CLI:
   ```bash
   php admin/cli/scheduled_task.php --execute='\local_examguard\task\cleanup_logs'
   ```

---

## Known Limitations

- Operating-system screenshots and external cameras cannot be blocked by a browser; the diagonal watermark makes leaks traceable and disrupts OCR.
- Browser AI assistants may choose to ignore hidden adversarial prompts; prompt traps act as an additional deterrent alongside DOM Question Swapping and focus detection.
- With JavaScript disabled, client-side monitoring is inactive, though any already-recorded penalties remain enforced server-side.
- The official Moodle mobile app does not execute web page JavaScript plugins.
- Timer freezing shifts `quiz_attempts.timestart` forward on the server; every shift is recorded in `local_examguard_logs.extra` and accumulated in `local_examguard_penalty.frozenseconds` for auditing.

---

## Changelog

### v1.2.0 (Build: 20261126)
- Added anti-snowball penalty protection (Lock-Pause rule, edge-triggered focus watchdog, and `maxpenaltyseconds` hard cap synced with freeze timer).
- Added Single-Tab Guard (`BroadcastChannel` + `localStorage` lease) with non-penalty duplicate overlay and safe tab takeover.
- Added direct **ExamGuard** tab in Moodle 4.5 quiz secondary navigation.
- Upgraded proctor dashboard (`index.php`) with a live "Currently Locked Students" panel, quick search, 6-second auto-unlock heartbeat, and per-student/bulk **Reset Penalty** preserving audit history.

### v1.1.x
- Added independent per-quiz language selector (`id` / `en` / `auto`).
- Added per-quiz watermark On/Off toggle.
- Hardened Chrome Ask Gemini detection, DOM Question Swapping, and `Multiple Choice ↔ Essay` pagination grace handling.

---

## Author & License

- **Author:** Librian Yudhista Pambudi
- **Website:** https://librian.my.id
- **License:** [GNU General Public License v3.0 or later](https://www.gnu.org/copyleft/gpl.html)
