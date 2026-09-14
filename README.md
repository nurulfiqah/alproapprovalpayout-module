# AAP — Alpro Approval Protocol

A payout/refund/adjustment approval workflow module. It plugs into the main
Alpro intranet app as a sub-module — it does not run standalone.

## What it does

A **case** in AAP tracks a compensation/refund/adjustment payout from request
to payout, through three parallel checks:

- **Physical confirmation** — for case types that require a physical item to
  be returned (Operations tags it, then confirms receipt).
- **Approval** — an eligible approver signs off on the payout, within their
  personal RM approval ceiling.
- **Execution** — Operations pays out and closes the case.

### Case lifecycle

Cases are only created from a **Fixit ticket handoff** ("Continue to Raise
Approval Case", or picked up from the "Incoming from Fixit" queue) — there is
no way to open a case from scratch.

```
draft → open → (confirm physical / approve / execute) → executed → closed
                                  |
                                  └─→ rejected / voided (terminal exits)
```

- **draft** — case created, evidence being gathered (attachments/notes).
  Can still be edited or voided by the issuer or an admin.
- **open** — required fields filled in (value, value type, recommended
  outcome). From here the case moves through physical confirmation (if
  required), approval, and execution.
- **executed** — approved and paid out, awaiting close.
- **closed** — final state; requester is notified.
- **rejected** — approver declined the case.
- **voided** — cancelled by the issuer/admin before reaching the approval
  gate.

Execution can also **suspend** a case, kicking it back to Verification/
Approval (with a reason logged); a case closed after a suspension shows as
"Closed (Suspended)".

Every state change is written to an audit log, and affected staff get an
in-app notification.

## Access control

Access is driven by the shared `staff` table (`grade`, `department`), plus a
per-module SuperAdmin flag and an explicit Department Manager grant:

| Role | Who | Can do |
|---|---|---|
| **Admin** | grade ≥ 4, dept 16 (Digital Innovation), or SuperAdmin | Everything, bypasses approval pool/ceiling checks |
| **Department Manager** | staff granted a department via `aap_department_managers` | Manage that department's Case Types, Approval Unit Groups, and Staff Assignments — additive only, never removes existing grade/department access |
| **Operations** | dept 13 | Tag/confirm physical returns, execute, close (and suspend) cases |
| **Customer Support** | dept 27 | Approve `cs_tier` case types (alongside Operations) |
| **Approver** | staff assigned a tier slot in `aap_case_type_staff_tiers`, resolved against `aap_approval_unit_tier_groups` | Approve/reject up to the RM ceiling of their assigned tier (Unlimited/Tier 4–0) |
| **Everyone else** | any staff | Sees only cases they or their department raised |

Approval authority is resolved through a tier system rather than a flat
per-staff ceiling:

- **Approval Unit Tiers** (`admin/aap_grouping_master.php`) — each department
  has one or more named **Groups**, each holding a fixed 6-tier ladder
  (Unlimited, Tier 4–0) with an RM value per tier. A department with no
  Group of its own falls back to the shared "Universal" tier values.
  Individual staff can be manually overridden to a different tier within a
  Group.
- **Case Type Registry** (`admin/aap_admin.php`) — each Case Type assigns
  specific staff to specific tiers (`aap_case_type_staff_tiers`), which is
  what actually grants approval rights for that Case Type.
- **Staff Assignments** (`admin/aap_staff_assignments.php`) — lets a
  superadmin (or a Department Manager, scoped to their department) look up a
  staff member and reassign/remove every tier slot they hold at once — built
  for offboarding.

The SuperAdmin roster and Department Manager grants are managed in
`admin/aap_settings.php` / `admin/aap_department_managers.php`.
`admin/aap_access.php` is a read-only page documenting this access model.

## File overview

| File | Purpose |
|---|---|
| `index.php` | Case Queue dashboard — stats, filters, pagination, Draft tab, Incoming-from-Fixit tab |
| `aap_add.php` | Raise a new case (only reachable via a Fixit handoff) |
| `aap_update.php` | Case detail page — open/tag/approve/execute/edit/close/void, notes, evidence upload/download |
| `aap_delete.php` | Confirmation page to void a case |
| `aap_export_cases.php` | CSV export of selected cases from the Case Queue (re-scoped to what the user can see) |
| `aap_export_close_cases.php` | Bulk "Export & Close" — closes each selected `executed` case, then streams a CSV of the outcome |
| `aap_notifications.php` | AJAX endpoint for the notification bell |
| `aap_search_customer.php` | AJAX typeahead for customer membership ID |
| `aap_sidebar.php` | Top nav + notification bell |
| `aap_footer.php` / `aap_modern_head.php` | Shared layout include/close |
| `aap_lib.php` | Core business logic — scoping, permissions, tier/approval resolution, case queries, NAS uploads, audit logging |
| `admin/aap_admin.php` | Case Type registry + per-Case-Type staff tier assignment |
| `admin/aap_grouping_master.php` | Approval Unit Groups/Tiers per department |
| `admin/aap_department_managers.php` | Grant/revoke Department Manager access |
| `admin/aap_staff_assignments.php` | Look up a staff member and reassign/remove all their tier slots |
| `admin/aap_settings.php` | SuperAdmin roster |
| `admin/aap_access.php` | Read-only access-model documentation page |
| `lib/synologynas.php` | Synology FileStation API client (not committed — see below) |
| `sql/aap_master.sql` | Schema for all `aap_*` tables |

## Evidence storage (NAS)

Case evidence attachments are staged locally in `uploads/tmp/`, pushed to a
Synology NAS over the FileStation REST API, then removed locally — only the
NAS path is kept in the database. Downloads are streamed back on demand from
the NAS, nothing is kept locally long-term.

This requires two files that are **not committed to this repo** (see
`.gitignore`) because they contain live credentials / are environment-
specific:

- `nas_config.php` — NAS host, port, credentials, and remote folder path.
- `lib/synologynas.php` — the NAS API client class.

Both must already exist on any environment (dev/production) before the
module will work. Copy them in manually; they are never touched by
`git pull`.

## Dependencies on the parent app

This module is included by the main Alpro app and expects it to already have
provided, before any AAP file is loaded:

- `../lock_adv.php` — auth/session gate.
- `../common/index_adv.php` — provides `$conn` (mysqli), `$department`,
  `$grade`, `$id_user`, and shared layout CSS.
- Shared tables it reads but doesn't own: `staff`, `staff_department`,
  `customer`, `fixit_record`, `fixit_category`, `fixit_attachment`, `outlet`.

`aap_footer.php` re-includes `common/index_adv.php` with `$connect = 0` to
close out the session/layout consistently with the rest of the app.

## Deployment

Production pulls this repo directly via `git pull` — see `.gitignore` for
what's intentionally excluded (`nas_config.php`, `lib/synologynas.php`,
`uploads/`). Set those up once per environment before the first pull.
