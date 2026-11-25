# local_examguard

Anti-cheating monitor for Moodle quiz attempts. The plugin only activates on
`/mod/quiz/attempt.php` and is delivered as plain PHP/CSS/JavaScript, so it can
be installed by copying the folder to `local/examguard` or by zipping it and
using the Moodle plugin installer. No Node/Grunt build step is required.

## Installation

1. Copy this folder to `local/examguard` on the server.
2. Visit `Site administration > Notifications` or run:
   `php admin/cli/upgrade.php --non-interactive`
3. Purge caches so the plugin `styles.css` is included in the theme CSS.
4. Review `Site administration > Plugins > Local plugins > ExamGuard`.

## What it does

| Layer | Behaviour |
|---|---|
| Module 1 | Injects invisible adversarial prompt traps into every `.qtext` container and obfuscates question text with zero width spaces (skipped for drag-and-drop placeholders, math and form fields). |
| Module 2 | Blocks copy, cut, paste, drag, right click, selection and the common keyboard shortcuts, and renders a fixed diagonal watermark with the student name, username, IP address and a live timestamp. |
| Module 3 | Detects tab switches, window blur and page hide, covers the page with a white overlay and locks it for the configured penalty duration (default 120 seconds). The state is enforced by the server and survives refresh, cache clearing and DevTools tampering. |
| Freeze timer | When the quiz has a time limit, the attempt start time is shifted on the server by the penalty duration so the quiz countdown does not run while the screen is locked (capped by the `freezetimermax` setting). |
| Module 4 | `local/examguard/index.php` shows a filterable, downloadable recap for teachers and administrators, with an unlock action for active penalties. |

## How the pieces talk to each other

1. `classes/hook_listener.php` (registered through `db/hooks.php` on Moodle 4.4+
   and through the legacy callbacks in `lib.php` on older versions) validates the
   attempt and injects the lock CSS plus a JSON configuration and `js/guard.js`.
2. `js/guard.js` reports events to `ajax.php`.
3. `ajax.php` delegates to `classes/ajax_handler.php`, which validates the
   attempt, the session key and the capability, then calls
   `classes/penalty_manager.php` to write the log row, update the authoritative
   penalty deadline and optionally freeze the quiz timer.
4. On every page load the deadline is read from the database again, so the
   browser never decides when a penalty ends.

## Settings

| Setting | Default | Purpose |
|---|---|---|
| `enabled` | On | Master switch. |
| `applytoteachers` | Off | Also monitor teacher preview attempts. |
| `penaltyseconds` | 120 | Penalty duration. |
| `dedupewindow` | 3 | Ignore duplicate blur/visibility events. |
| `freezetimer` | On | Pause the quiz timer while locked. |
| `freezetimermax` | 600 | Maximum frozen seconds per attempt (0 = unlimited). |
| `headlock` | On | Hide the quiz before JavaScript loads on a locked attempt. |
| `trapenabled` / `traptext` | On | Hidden AI prompt trap. |
| `zwspenabled` | On | Zero width space obfuscation. |
| `blockcopy` | On | Copy, paste and context menu protection. |
| `blockdevtools` | Off | Deter DevTools shortcuts. |
| `watermarkenabled` / `watermarkopacity` | On / 0.12 | Traceable watermark. |
| `retentiondays` | 90 | Log retention, applied by the daily scheduled task. |
| `reportperpage` | 50 | Rows per report page. |

## Capabilities

* `local/examguard:viewreport` - course level report.
* `local/examguard:manage` - unlock a penalty from the report.
* `local/examguard:viewall` - system level report for administrators.

## Testing checklist

1. Start a quiz attempt as a student and switch to another tab: the white screen
   must appear immediately and the countdown must start at the configured value.
2. Reload the page while the penalty is active: the quiz content must stay hidden
   and the countdown must resume with the correct remaining time.
3. Delete the overlay with DevTools: it must come back instantly and a new
   violation must be logged.
4. For a timed quiz, verify `quiz_attempts.timestart` increased by the penalty
   duration and that the quiz timer resumes with the extra time.
5. Open the report page as a teacher and check the row, then use Unlock.
6. Run the scheduled task manually:
   `php admin/cli/scheduled_task.php --execute='\local_examguard\task\cleanup_logs'`

## Known limitations

* Screenshots taken by the operating system cannot be blocked; the watermark
  makes them traceable and disrupts OCR.
* Browser AI assistants can still ignore adversarial prompts; the trap is a
  deterrent, not a guarantee.
* With JavaScript disabled the client side protection is inactive. Penalties are
  still enforced server side once they have been recorded.
* The official Moodle mobile app does not run this JavaScript.
* Shifting `quiz_attempts.timestart` makes the recorded attempt start time move
  forward. Every shift is written to `local_examguard_logs.extra` and counted in
  `local_examguard_penalty.frozenseconds` so teachers can audit it.

## Author / Credits

- **Author:** Librian Yudhista Pambudi
- **Website:** https://librian.my.id
- **License:** GNU General Public License v3.0 or later
