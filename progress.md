# AAP (Alpro Approval Protocol) — Project Progress Log

**Project start:** 25/8/2026
**Presentation to Mr Hong:** 10/9/2026
**Development completed:** 15/9/2026
**Presentation to Miss Wong (upcoming):** 22/9/2026

---

## Phase 0 — Requirement Study & Flowchart Design (25/8 – 28/8)
- Gathered requirements from stakeholders on what the approval system needed to do — who raises a case, who verifies it, who approves it, who executes it, and how a case gets closed.
- Studied the existing manual approval process end-to-end (Investigation, Verification, Approval, Execution, Closing) to understand current pain points, handoffs between departments, and where cases could get stuck or lost.
- Clarified rules around rejection and sending a case back for more evidence (the basis for the later Suspend Case feature).
- Mapped out the full case-lifecycle flowchart, including the Rejected and Suspended side-paths, as the blueprint for the system build.
- Defined the multi-level structure (Level 1 Investigation, Level 2 Verification/Approval, Level 3 Execution) and department/approval-unit/tier concepts used throughout the module.
- Design and process flow finalized as the basis for Phase 1 development.

## Phase 1 — Core Build (29/8 – 9/9)

### Case lifecycle & workflow
- Built the full case lifecycle: **Investigation → Verification → Approval → Execute → Closing → Closed**, with **Rejected** and **Suspended** side-paths.
- `aapCaseDisplayStatus()` set up as the single source of truth for the status label everywhere (case list, case page header, CSV export).
- Multi-level approval flow (Level 1 Investigation, Level 2 Verification/Approval, Level 3 Execution) with department-based approval units and tier-based exclusion assignment.

### Case Queue dashboard (`index.php`)
- Case list with filters: Case Status, Case Type, Outlet, Verification Reference, Approval, Date Raised, Date Closed, Search (Ref/Transaction/Membership ID).
- Stat tiles for Open Cases, Verification, Approval, Execute, Closing in Progress, Closed — tiles respect every filter except Case Status itself (so they always show the breakdown across statuses).
- Two-tab layout: **Case Queue** and **Incoming from Fixit** (linked Fixit tickets), each with its own self-contained filter + list.
- CSV export of the case list, shared builder logic (`aapCaseExportCsvHeader`/`Row`).

### Bank Master
- New `aap_bank_master` lookup table with real Malaysian bank names, replacing the old free-text bank name field.
- `bank_id` dropdown wired into both Add Case and Edit Case forms.
- SuperAdmin "Bank Master" management section added to Admin Settings (add / rename / retire banks).

### Attachments & evidence
- General **Evidence Attachments** on every case (visible to all relevant parties).
- Separate **Execution Attachments** — visible only to Level 3 / Execution staff, hidden from requester and approvers.
- Evidence-freeze rule: once a phase ends, its notes/attachments become read-only — applied uniformly across **all** phases, with **no admin bypass** (even an admin who is also the acting user on a later phase cannot edit/delete a prior phase's evidence).

### Suspend Case
- Execution can send a case back to Verification/Approval for more evidence via **Suspend Case**.
- Suspend reason + suspended-by tracked and shown in a persistent banner on the case page.
- Cases can be suspended **more than once** (no one-time limit) — each suspend cycle still keeps prior evidence frozen.
- Once a case has ever been suspended, **Reject is permanently retired** for that case.

### Execute and Close
- Combined **Execute and Close** action added to the Execution step — executes and closes the case (and notifies the requester / completes the linked Fixit ticket) in one step, alongside the existing separate Execute action.

### Notifications
- In-app notification bell with unread badge, polling every 8s, mark-as-read / mark-all-read.
- Notification types: case approved, rejected, closed, suspended, pending approval.

### Admin area
- **Add Case Type** — case type configuration.
- **Approval Units** (grouping master) — department approval-unit / tier setup.
- **Staff Assignments** — assign staff to approval tiers.
- **Department Managers** — grant department-scoped manager access (Approval Units + Staff Assignments) without full admin rights.
- **Admin Settings** — SuperAdmin-only: Bank Master management + module settings.
- Admin audit log (`aap_admin_audit_logs`) for staff-assignment changes.

---

## Presentation to Mr Hong — 10/9/2026
First stakeholder walkthrough of the working module (case lifecycle, dashboard, admin setup).

---

## Phase 2 — Hardening, Bug Fixes & Polish (10/9 – 15/9)

### Bug fixes
- **Page-freeze bug** (whole page hangs, needs refresh): root-caused to PHP's default session file locking holding the session file for a request's entire duration, combined with missing double-submit guards on admin action buttons. Fixed with `session_write_close()` on identity-only pages and a page-wide `guarded()` click-lock wrapper in `aap_grouping_master.js`.
- **Silent NAS upload failures**: uploads that failed to reach the NAS were reporting success anyway — fixed to surface real success/failure counts and a warning message to the user.
- **CSV/formula-injection vulnerability**: values starting with `=`, `+`, `-`, `@` in exported CSV fields (Fixit report/remark, notes, attachment lists) could execute as spreadsheet formulas — fixed by prefixing with a safe character.
- **Broken notification icon on Admin pages**: a relative-path bug in the shared outer-app header only broke on `admin/*.php` (one folder deeper); fixed client-side via JS without touching the shared file.
- Reduced height of the blue page-title banner and nav button bar to reclaim screen space.

### New feature: department self-assignment on Add Case
- Feedback from the Mr Hong presentation: other departments needed a way to assign their **own** group/approval unit when raising a case, instead of it being fixed to one department.
- Added the ability, on the Add Case form, for the requester's department to assign its own group for the case — extending the grouping/approval-unit model built in Phase 1 to work across departments, not just the original one.

### Naming & label cleanup
- "Physical Confirm" renamed to **"Verification Reference"** throughout.
- Status labels shortened (Investigation, Verification, Approval, Execute) while **"Closing in Progress"** was kept as-is per review.
- Removed the "(Suspended)" suffix from Execute/Closed status labels (kept the underlying suspend data and detail line, just simplified the label).

### UI standardization
- Built a reusable button-pair CSS system (`.aap-actions-row`, `.aap-btn-action` + tone modifiers) and rolled it out consistently across Approve/Reject, Execute/Suspend Case, Confirm Suspend/Cancel, Confirm Reject/Cancel, and Add Evidence.
- Execute section reworked into a reveal/confirm/cancel flow: Execute reveals **Execute / Execute and Close / Cancel**, all in one row.
- Case Queue dashboard restructured to match the group's standard pattern (filter card → stat tiles → case list on one tab), and stat tiles' visual style matched to the standard card look (white card, underlined title, bold coloured number).

### Pre-production review
- Full readiness check before go-live: PHP lint pass, live-schema vs `sql/aap_master.sql` diff (no drift found), and a security spot-check — which is what caught the CSV-injection issue above.

---

## Development completed — 15/9/2026
Core build, hardening, and UI polish finished and considered production-ready.

---

## Upcoming — Presentation to Miss Wong — 22/9/2026
Second stakeholder walkthrough, covering the hardened/polished version of the module.
