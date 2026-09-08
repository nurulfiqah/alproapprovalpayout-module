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

Every state change is written to an audit log, and affected staff get an
in-app notification.

## Access control

Access is driven by the shared `staff` table (`grade`, `department`), plus a
per-module SuperAdmin flag:

| Role | Who | Can do |
|---|---|---|
| **Admin** | grade ≥ 4, dept 16 (Digital Innovation), or SuperAdmin | Everything, bypasses approval pool/ceiling checks |
| **Operations** | dept 13 | Tag/confirm physical returns, execute, close cases |
| **Customer Support** | dept 27 | Approve `cs_tier` case types (alongside Operations) |
| **Approver** | staff with a threshold row in `aap_staff_thresholds` matching the case type's approver pool | Approve/reject up to their personal RM ceiling (null = unlimited) |
| **Everyone else** | any staff | Sees only cases they or their department raised |

Case Types (approver pool, physical confirmation requirement, turnaround
days) are managed in `admin/aap_admin.php`. The SuperAdmin roster is managed
in `admin/aap_settings.php`. `admin/aap_access.php` is a read-only page
documenting this access model.

## File overview

| File | Purpose |
|---|---|
| `index.php` | Case Queue dashboard — stats, filters, pagination, Draft tab, Incoming-from-Fixit tab |
| `aap_add.php` | Raise a new case (only reachable via a Fixit handoff) |
| `aap_update.php` | Case detail page — open/tag/approve/execute/edit/close/void, notes, evidence upload/download |
| `aap_delete.php` | Confirmation page to void a case |
| `aap_notifications.php` | AJAX endpoint for the notification bell |
| `aap_search_customer.php` | AJAX typeahead for customer membership ID |
| `aap_sidebar.php` | Top nav + notification bell |
| `aap_footer.php` / `aap_modern_head.php` | Shared layout include/close |
| `aap_lib.php` | Core business logic — scoping, permissions, case queries, NAS uploads, audit logging |
| `admin/` | Case Type registry, SuperAdmin roster, access-model docs |
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
