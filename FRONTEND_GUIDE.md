# MaintenancePizza — Frontend Workflow Guide

This guide tells a frontend developer everything needed to build the UI for the ticket system: which endpoint to call at each step, the exact request/response shapes, and what each page should look like and contain.

The full machine-readable contract lives in [`public/openapi.json`](public/openapi.json) (load it into Swagger UI / Redoc / Insomnia / Postman). This document is the narrative companion.

---

## 🆕 What's New (v2.0 — current version)

This section documents every change from the original API. If you already know the v1 API, read this first.

### 1. Warranty requires `expiry_date` (BREAKING)

- **Old:** `POST .../warranties` with `{ ticket_issue_ids[], body, files[] }`
- **New:** `{ ticket_issue_ids[], body, expiry_date, files[] }` — `expiry_date` is **required** (`YYYY-MM-DD`)
- **Response:** all `Warranty` objects now include an `expiry_date` field
- **UI:** the warranty creation form must include a required date picker for the expiry date

### 2. New `cancelled` status for issues and tickets (ADDITIVE)

- Added `cancelled` to the `IssueStatus` enum: `pending | assigned | in_progress | complete | deferred | **cancelled**`
- `cancelled` is a **terminal/finished** status (like `complete` and `deferred`): cancelled issues do not hold a ticket in Pending
- Derived `TicketStatus` adds `**cancelled**`: when **every** issue on a ticket is cancelled, the ticket derives to `cancelled`
- **New cancel endpoint** (requires a reason): `POST .../issues/{ticketIssue}/cancel` with `{ "reason": "..." }`
  - Records the reason in `status_changes`
  - Does NOT spawn a child (unlike deferral)
  - Returns the cancelled issue
- **Bulk status-set** (`POST .../issues/status`) now explicitly excludes both `deferred` AND `cancelled` — each has its own endpoint
- **Ticket status filter** `?status=` now accepts `cancelled`
- **Issue status filter** `?issue_status=` now accepts `cancelled`

### 3. Ticket final notes replaced with typed multi-notes (BREAKING)

- **Old:** `POST .../final-note` with `{ "final_note": "..." }` — set or cleared a single string on the ticket; the `Ticket` object had `final_note: string | null`
- **New:** `POST .../final-note` as `multipart/form-data` with `{ body, type, files[] }` — **appends** one typed note; call repeatedly to add more. Returns the refreshed ticket.
  - `type` is required and must be one of: `final_notes` | `what_we_learned`
  - Notes can have file attachments (photos, documents)
- **Breaking change on the Ticket response:** `final_note` field is **gone**. Closing notes are now in the `notes[]` array. Filter by `note.type !== null` to get only closing notes. Each note has `type`, `type_label` (`"Final Notes"` / `"What we learned"`), `body`, `attachments[]`, `created_by`, `creator`.
- **What to show in the UI:** a "Closing notes" panel on the ticket with one card per note (showing its type badge, author, body, and any attached files), plus "Add Final Note" and "Add What We Learned" buttons.

### 4. Polymorphic notes and attachments on every entity (ADDITIVE)

Every entity in the system now accepts **any number** of free-text notes and file attachments via dedicated POST endpoints. This applies to:

| Entity | Notes endpoint | Attachments endpoint |
|---|---|---|
| Ticket | `POST .../tickets/{ticket}/notes` | `POST .../tickets/{ticket}/attachments` |
| Ticket Issue | `POST .../issues/{ticketIssue}/notes` | `POST .../issues/{ticketIssue}/attachments` |
| Diagnosis | `POST .../diagnoses/{diagnosis}/notes` | `POST .../diagnoses/{diagnosis}/attachments` |
| Attendance Entry | `POST .../attendance-entries/{entry}/notes` | `.../attachments` |
| Part Usage | `POST .../part-usages/{usage}/notes` | `.../attachments` |
| Pay Entry | `POST .../pay-entries/{entry}/notes` | `.../attachments` |
| Warranty | `POST .../warranties/{warranty}/notes` | `.../attachments` |
| Assignment | `POST .../assignments/{assignment}/notes` | `.../attachments` |
| Store | `POST /stores/{store}/notes` | `/stores/{store}/attachments` |
| Catalog Issue | `POST /issues/{issue}/notes` | `/issues/{issue}/attachments` |
| Technician | `POST /technicians/{technician}/notes` | `/technicians/{technician}/attachments` |
| Category | `POST /categories/{category}/notes` | `/categories/{category}/attachments` |
| Part | `POST /parts/{part}/notes` | `/parts/{part}/attachments` |

**Note creation request** (one note per call, `multipart/form-data`):
```
body=...      (required, text)
type=...      (optional, free-form string; unconstrained on all entities except /final-note)
files[]=...   (optional, one or many files up to 10 MB each)
```

**Attachment upload request** (one or many files, `multipart/form-data`):
```
files[]=...   (required, one or many files up to 10 MB each)
```

**What shows in responses:** every entity now returns `notes[]` and `attachments[]` arrays. Each note includes `id`, `type`, `type_label`, `body`, `attachments[]` (files on the note itself), `created_by`, `creator` (user object), `created_at`, `updated_at`. Each attachment includes `id`, `path`, `url` (full public URL — use this in `<img>` / download links), `original_name`, `mime_type`, `size`, `created_by`, `created_at`.

### 5. OpenAPI / response shape additions (additive, no breaking changes)

All schemas now include the fields the API actually returns:

- Every entity exposes `created_by` (integer user id, nullable) and `created_at` / `updated_at`
- `Attachment` now includes `created_by` and `created_at`
- `IssueStatusChange` exposes `created_by`
- `AttendanceEntry` includes `technician` (nested object) and `ticket_issue_ids[]`
- `PayEntry` includes `technician` (nested object), `attachments[]`, `notes[]`, `ticket_issue_ids[]`
- `PartUsage`, `Diagnosis`, `Warranty`, `Assignment`, `AssignmentDelay` all include `notes[]` and `ticket_issue_ids[]`
- `Technician`, `Category`, `Part`, `Issue`, `Store` all include `notes[]`, `attachments[]`, `created_by`, full timestamps
- `Ticket` now has `creator` (nested User object) in addition to `created_by`
- `TicketIssue` now has `notes[]`, `attachments[]`
- Missing resource/collection wrappers added: `TechnicianResource`, `TechnicianCollection`, `CategoryResource`, `CategoryCollection`, `PartResource`, `PartCollection`, `StoreResource`, `AttendanceEntryResource`

---

## 1. Conventions you must know first

| Topic | Rule |
|---|---|
| **Base URL** | `http://localhost:8000/api` (dev). All paths below are relative to this. |
| **Auth** | Every request needs `Authorization: Bearer <token>`. There is **no** login/registration endpoint in this service — your auth layer supplies the token. For local dev, run `php artisan db:seed` and copy the printed **DEV API TOKEN**. |
| **Headers** | Always send `Accept: application/json`. (The API forces JSON anyway, but be explicit.) |
| **Store id** | The `{store}` path segment is the **store number string**, e.g. `03795-00001` — never a numeric id. |
| **Responses** | Single records: `{ "data": { ... } }`. Lists: `{ "data": [ ... ], "links": {...}, "meta": {...} }` (Laravel pagination). |
| **No edits** | There are **no** PUT/PATCH endpoints. To "fix" a leaf record (diagnosis, attendance, part, pay) call its `/mistaken` endpoint and create a new one. All lifecycle changes are explicit POST actions. |
| **Ticket status** | Never set by you. Computed from issues and returned as `data.status`. |
| **Enums on response** | Priority and status fields come back as `{ "value": "in_progress", "label": "In Progress" }`. When you POST, send only the raw `value` string. |
| **Multipart vs JSON** | Endpoints that accept files use `multipart/form-data` (array fields have the `[]` suffix). All other endpoints use `application/json`. |
| **Errors** | `401 Unauthenticated`, `422 {"message":"...","errors":{"field":["..."]}}`, `404` for unknown route parameters. |
| **File URLs** | Every `Attachment` object carries a `url` field — a fully-qualified public URL. Use it directly in `<img src>` or download links. |
| **Append-only notes** | Notes and attachments are append-only. You cannot edit or delete them. |

### Enum values

- **Priority:** `urgent` | `high` | `medium` | `low`
- **Issue status:** `pending` | `assigned` | `in_progress` | `complete` | `deferred` | `cancelled`
- **Ticket status (derived):** `pending` | `assigned` | `in_progress` | `complete` | `cancelled`
- **Final-note type:** `final_notes` | `what_we_learned`

### How ticket status is derived (so your status badges are correct)

| Rule (evaluated top to bottom) | Ticket status |
|---|---|
| Any issue is `in_progress` | **In Progress** |
| No `in_progress`, but any issue is `assigned` | **Assigned** |
| Every issue is `cancelled` (no other statuses) | **Cancelled** |
| Every issue is terminal (`complete` / `deferred` / `cancelled`) — at least one is not `cancelled` | **Complete** |
| Anything else (incl. no issues) | **Pending** |

---

## 2. The lifecycle at a glance

```
File ticket (all issues = pending)
        │
        ▼
Assign issues to technicians + date  → issues: assigned   → ticket: assigned
        │  Delay / Change technicians as needed
        ▼
Set issue in_progress                → ticket: in_progress
        │  Add diagnoses, parts, attendance, pay, warranty; add notes & attachments anywhere
        ▼
   Complete ──── or ──── Defer (reason → spawns child) ──── or ──── Cancel (reason, no child)
        │
        ▼
All issues terminal (complete/deferred/cancelled) → ticket: complete
        │  (all cancelled → ticket: cancelled)
        ▼
Add typed closing notes (Final Notes / What we learned) via /final-note
```

**Notes & attachments:** any entity at any stage can receive notes or file attachments — before, during, or after the lifecycle. Call `POST <entity>/notes` or `POST <entity>/attachments` whenever you want.

---

## 3. Reference / catalog data

Load once, cache for dropdowns. All catalog lists support `?per_page=N`.

| Data | List endpoint | Create | Delete | Restore |
|---|---|---|---|---|
| Issues | `GET /issues` | `POST /issues` `{ title, description? }` | `DELETE /issues/{id}` (soft) | `POST /issues/{id}/restore` |
| Technicians | `GET /technicians` | `POST /technicians` `{ name, category_id? }` | `DELETE /technicians/{id}` (soft) | `POST /technicians/{id}/restore` |
| Categories | `GET /categories` | `POST /categories` `{ name }` | `DELETE /categories/{id}` (**hard**, technicians kept) | — |
| Parts | `GET /parts` | `POST /parts` `{ name }` | `DELETE /parts/{id}` (soft) | `POST /parts/{id}/restore` |

Add `?trashed=with` to include soft-deleted, `?trashed=only` for just deleted (restore screen). Soft-deleted items must not appear in selection dropdowns.

---

## 4. Workflow endpoints in detail

### 4.1 File a ticket

`POST /stores/{store}/tickets` — JSON body

```json
{
  "issues": [
    { "issue_id": 1, "priority": "high", "description": "Oven not heating" },
    { "other_title": "Strange smell near vents", "priority": "low", "description": "Investigate" }
  ]
}
```

Each line is **either** `issue_id` (catalog) **or** `other_title` (free text) — not both. Mix freely. Returns `TicketResource` with `status.value = "pending"`.

### 4.2 Fetch full ticket detail ("one look")

`GET /stores/{store}/tickets/{ticket}/issues` — returns all issues with **complete history**:

Each issue includes: `diagnoses[]`, `attendance_entries[]`, `part_usages[]`, `pay_entries[]`, `warranties[]`, `assignments[]` (with `delays[]`), `technicians[]`, `status_changes[]`, `children[]` (deferral chain), `notes[]`, `attachments[]`.

`GET .../issues/{ticketIssue}` returns a single issue the same way.

### 4.3 Assign issues to technicians

`POST /stores/{store}/tickets/{ticket}/assignments` — JSON body

```json
{ "ticket_issue_ids": [1, 2], "technician_ids": [3, 5], "assigned_date": "2026-06-10", "assigned_hour": "09:30" }
```

`assigned_hour` is optional (`HH:MM`). Moves the specified issues to `assigned`.

**Delay an assignment** (snapshots old date into history):
```json
POST .../assignments/{assignment}/delays
{ "new_date": "2026-06-12", "new_hour": "14:00", "reason": "Part on backorder" }
```

**Change technicians without rescheduling** (NOT a delay):
```json
POST .../assignments/{assignment}/change-technicians
{ "technician_ids": [2, 4] }
```

### 4.4 Move an issue through statuses

`POST /stores/{store}/tickets/{ticket}/issues/status` — JSON body

```json
{ "ticket_issue_ids": [1, 2], "status": "in_progress" }
```

Allowed values: `pending`, `assigned`, `in_progress`, `complete`. **Not** `deferred` (use §4.5) or `cancelled` (use §4.5b) — both have their own reason-bearing endpoints.

### 4.5 Defer an issue (reason required → spawns a pending child)

`POST /stores/{store}/tickets/{ticket}/issues/{ticketIssue}/defer`

```json
{ "reason": "Needs HVAC specialist — coming back next week" }
```

- Marks the issue `deferred` and returns a **new pending child issue** with the same `issue_id`, `priority`, `description`, and `parent_id` set.
- Reason stored in the parent's `status_changes`.

### 4.5b Cancel an issue (reason required → no child spawned)

`POST /stores/{store}/tickets/{ticket}/issues/{ticketIssue}/cancel`

```json
{ "reason": "Store withdrew the request" }
```

- Marks the issue `cancelled` (terminal) and returns it. No child is created.
- Reason stored in `status_changes`.
- If **every** issue on the ticket ends up `cancelled`, the ticket derives to `cancelled`.

### 4.6 Add workflow records (all target one-or-many issues)

| Record | Endpoint | Key body fields | Files? | Returns |
|---|---|---|---|---|
| Diagnosis | `POST .../diagnoses` | `ticket_issue_ids[]`, `body?` | `files[]` optional | `DiagnosisResource` |
| Attendance | `POST .../attendance-entries` | `ticket_issue_ids[]`, `technician_id`, clock fields | `files[]` optional | `AttendanceEntryResource` |
| Part usage | `POST .../part-usages` | `ticket_issue_ids[]`, `part_id`, `cost` | `files[]` optional | `PartUsageResource` |
| Pay/driving | `POST .../pay-entries` | `ticket_issue_ids[]`, `technician_id`, money fields | none | `PayEntryResource` |
| Warranty | `POST .../warranties` | `ticket_issue_ids[]`, `body`, **`expiry_date`** | `files[]` optional | `WarrantyResource` |
| Attach techs | `POST .../technicians` | `ticket_issue_ids[]`, `technician_ids[]` | none | issues array |

All multipart requests send array fields as `ticket_issue_ids[]` / `files[]` (with the `[]` suffix).

**Attendance clock fields** (all optional datetimes): `start_clock`, `end_clock`, `start_break`, `end_break`, `start_parts_run`, `end_parts_run`. The technician must already be attached to at least one target issue (assign or use §4.6 "Attach techs" first).

**Pay entry fields** (all optional decimals):
- Attendance group: `base_pay`, `performance_pay` (per hour)
- Driving group: `driving_time` (hours), `miles_driven`, `per_mile_rate`, `driving_base_pay`, `driving_performance_pay`

**Mark a leaf record mistaken** (append-only "undo"):
```
POST .../diagnoses/{id}/mistaken
POST .../attendance-entries/{id}/mistaken
POST .../part-usages/{id}/mistaken
POST .../pay-entries/{id}/mistaken
```
The record stays in history with `mistaken: true`; render it struck-through/greyed, exclude from cost totals.

### 4.7 Add typed closing notes to a ticket

`POST /stores/{store}/tickets/{ticket}/final-note` — `multipart/form-data`

```
body=We replaced the heating element and tested at 375°F for 30 minutes.
type=final_notes
files[]=signed-checklist.pdf
```

Or a "What we learned" note:
```
body=The temperature probe had a hairline crack — always check probe connections first.
type=what_we_learned
```

- `type` is required: `final_notes` or `what_we_learned`
- Post once per note — call repeatedly to add as many as you want
- Returns the refreshed `TicketResource` (with all notes)
- Closing notes appear in `data.notes[]` — filter `note.type !== null` to show only closing notes

**There is no longer a `final_note` string on the ticket object.** All closing notes are in `data.notes[]`.

### 4.8 Delete / restore a ticket

```
DELETE /stores/{store}/tickets/{ticket}          (soft-delete)
POST   /stores/{store}/tickets/{ticket}/restore  (restore)
```

### 4.9 Generic notes and attachments (any entity, any time)

Every entity in the system can receive any number of free-text notes and file attachments. One call = one note or one batch of files.

#### Add a note

`POST <entity-path>/notes` — `multipart/form-data`

| Field | Required | Description |
|---|---|---|
| `body` | ✅ | Note text |
| `type` | ❌ | Free-form tag string (unconstrained; leave blank for general notes) |
| `files[]` | ❌ | Files to attach to this note (max 10 MB each) |

Returns `NoteResource`:
```json
{
  "data": {
    "id": 42,
    "type": null,
    "type_label": null,
    "body": "Called vendor — part ships Tuesday.",
    "attachments": [],
    "created_by": 7,
    "creator": { "id": 7, "name": "Jane Dispatcher", "email": "jane@example.com" },
    "created_at": "2026-06-07T14:30:00.000000Z",
    "updated_at": "2026-06-07T14:30:00.000000Z"
  }
}
```

#### Add attachments

`POST <entity-path>/attachments` — `multipart/form-data`

| Field | Required | Description |
|---|---|---|
| `files[]` | ✅ | One or more files (max 10 MB each) |

Returns `{ "data": [ <Attachment>, ... ] }`:
```json
{
  "data": [
    {
      "id": 55,
      "path": "attachments/abc123.jpg",
      "url": "http://localhost:8000/storage/attachments/abc123.jpg",
      "original_name": "photo.jpg",
      "mime_type": "image/jpeg",
      "size": 204800,
      "created_by": 7,
      "created_at": "2026-06-07T14:32:00.000000Z"
    }
  ]
}
```

#### Entity paths (complete list)

| Entity | `<entity-path>` |
|---|---|
| Ticket | `/stores/{store}/tickets/{ticket}` |
| Ticket Issue | `/stores/{store}/tickets/{ticket}/issues/{ticketIssue}` |
| Diagnosis | `/stores/{store}/tickets/{ticket}/diagnoses/{diagnosis}` |
| Attendance Entry | `/stores/{store}/tickets/{ticket}/attendance-entries/{attendanceEntry}` |
| Part Usage | `/stores/{store}/tickets/{ticket}/part-usages/{partUsage}` |
| Pay Entry | `/stores/{store}/tickets/{ticket}/pay-entries/{payEntry}` |
| Warranty | `/stores/{store}/tickets/{ticket}/warranties/{warranty}` |
| Assignment | `/stores/{store}/tickets/{ticket}/assignments/{assignment}` |
| Store | `/stores/{store}` |
| Catalog Issue | `/issues/{issue}` |
| Technician | `/technicians/{technician}` |
| Category | `/categories/{category}` |
| Part | `/parts/{part}` |

---

## 5. Listing & filtering tickets

`GET /tickets` (global, all stores) — `GET /stores/{store}/tickets` (store-scoped)

| Filter param | Type | Meaning |
|---|---|---|
| `store` | string | (global only) limit to this store number |
| `status` | string | derived ticket status: `pending\|assigned\|in_progress\|complete\|cancelled` |
| `issue_id` | integer | tickets that contain this catalog issue |
| `issue_status` | string | tickets with ≥1 issue in this status (includes `deferred`, `cancelled`) |
| `priority` | string | tickets with ≥1 issue of this priority |
| `created_from` / `created_to` | date | ticket creation date range |
| `assigned_from` / `assigned_to` | date | scheduled assignment date range |
| `part_cost_single_gt` | number | ≥1 issue whose summed (non-mistaken) part cost exceeds N |
| `part_cost_total_gt` | number | whole-ticket (non-mistaken) part cost exceeds N |
| `technician_id` | integer | tickets with an issue assigned to this technician |
| `creator_id` | integer | tickets filed by this user |
| `trashed` | `with\|only` | include / limit to soft-deleted tickets |
| `sort` | `created_at\|updated_at\|id` | sort field (default `created_at`) |
| `dir` | `asc\|desc` | sort direction (default `desc`) |
| `per_page` | integer | page size (default 15) |

---

## 6. Export

`GET /export/excel` → downloads `maintenancepizza-export.xlsx`

Multi-sheet workbook covering every entity: stores, tickets, ticket issues, status changes, assignments, delays, diagnoses, attendance entries, part usages, pay entries, warranties, notes, attachments, and all four catalog types. Uses a separate secret-key auth (not the bearer token) — render as a simple "Export to Excel" button.

---

## 7. Complete endpoint reference

### Catalog (not store-scoped)

| Method | Path | Description |
|---|---|---|
| `GET` | `/issues` | List catalog issues (paginated) |
| `POST` | `/issues` | Create catalog issue |
| `DELETE` | `/issues/{issue}` | Soft-delete issue |
| `POST` | `/issues/{issue}/restore` | Restore issue |
| `POST` | `/issues/{issue}/notes` | Add note to catalog issue |
| `POST` | `/issues/{issue}/attachments` | Upload files to catalog issue |
| `GET` | `/technicians` | List technicians (paginated) |
| `POST` | `/technicians` | Create technician |
| `DELETE` | `/technicians/{technician}` | Soft-delete technician |
| `POST` | `/technicians/{technician}/restore` | Restore technician |
| `POST` | `/technicians/{technician}/notes` | Add note to technician |
| `POST` | `/technicians/{technician}/attachments` | Upload files to technician |
| `GET` | `/categories` | List categories (paginated) |
| `POST` | `/categories` | Create category |
| `DELETE` | `/categories/{category}` | Hard-delete category |
| `POST` | `/categories/{category}/notes` | Add note to category |
| `POST` | `/categories/{category}/attachments` | Upload files to category |
| `GET` | `/parts` | List parts (paginated) |
| `POST` | `/parts` | Create part |
| `DELETE` | `/parts/{part}` | Soft-delete part |
| `POST` | `/parts/{part}/restore` | Restore part |
| `POST` | `/parts/{part}/notes` | Add note to part |
| `POST` | `/parts/{part}/attachments` | Upload files to part |

### Tickets (global)

| Method | Path | Description |
|---|---|---|
| `GET` | `/tickets` | Global ticket index (all stores, full filter set) |

### Store-level

| Method | Path | Description |
|---|---|---|
| `POST` | `/stores/{store}/notes` | Add note to a store |
| `POST` | `/stores/{store}/attachments` | Upload files to a store |

### Tickets (store-scoped)

| Method | Path | Description |
|---|---|---|
| `GET` | `/stores/{store}/tickets` | Store-scoped ticket list |
| `POST` | `/stores/{store}/tickets` | File a new ticket |
| `DELETE` | `/stores/{store}/tickets/{ticket}` | Soft-delete ticket |
| `POST` | `/stores/{store}/tickets/{ticket}/restore` | Restore ticket |
| `POST` | `/stores/{store}/tickets/{ticket}/final-note` | Append typed closing note |
| `POST` | `/stores/{store}/tickets/{ticket}/notes` | Add generic note to ticket |
| `POST` | `/stores/{store}/tickets/{ticket}/attachments` | Upload files to ticket |

### Issue workflow

| Method | Path | Description |
|---|---|---|
| `GET` | `/stores/{store}/tickets/{ticket}/issues` | All issues with full history |
| `GET` | `/stores/{store}/tickets/{ticket}/issues/{ticketIssue}` | Single issue with full history |
| `POST` | `/stores/{store}/tickets/{ticket}/issues/status` | Bulk set status (`pending\|assigned\|in_progress\|complete`) |
| `POST` | `/stores/{store}/tickets/{ticket}/issues/{ticketIssue}/defer` | Defer (reason, spawns child) |
| `POST` | `/stores/{store}/tickets/{ticket}/issues/{ticketIssue}/cancel` | Cancel (reason, no child) |
| `POST` | `/stores/{store}/tickets/{ticket}/issues/{ticketIssue}/notes` | Add note to issue |
| `POST` | `/stores/{store}/tickets/{ticket}/issues/{ticketIssue}/attachments` | Upload files to issue |
| `POST` | `/stores/{store}/tickets/{ticket}/assignments` | Create assignment + schedule |
| `POST` | `/stores/{store}/tickets/{ticket}/assignments/{assignment}/delays` | Delay assignment |
| `POST` | `/stores/{store}/tickets/{ticket}/assignments/{assignment}/change-technicians` | Swap technicians (no delay) |
| `POST` | `/stores/{store}/tickets/{ticket}/assignments/{assignment}/notes` | Add note to assignment |
| `POST` | `/stores/{store}/tickets/{ticket}/assignments/{assignment}/attachments` | Upload files to assignment |
| `POST` | `/stores/{store}/tickets/{ticket}/diagnoses` | Add diagnosis |
| `POST` | `/stores/{store}/tickets/{ticket}/diagnoses/{diagnosis}/mistaken` | Mark diagnosis mistaken |
| `POST` | `/stores/{store}/tickets/{ticket}/diagnoses/{diagnosis}/notes` | Add note to diagnosis |
| `POST` | `/stores/{store}/tickets/{ticket}/diagnoses/{diagnosis}/attachments` | Upload files to diagnosis |
| `POST` | `/stores/{store}/tickets/{ticket}/attendance-entries` | Add attendance entry |
| `POST` | `/stores/{store}/tickets/{ticket}/attendance-entries/{attendanceEntry}/mistaken` | Mark attendance mistaken |
| `POST` | `/stores/{store}/tickets/{ticket}/attendance-entries/{attendanceEntry}/notes` | Add note to attendance entry |
| `POST` | `/stores/{store}/tickets/{ticket}/attendance-entries/{attendanceEntry}/attachments` | Upload files to attendance entry |
| `POST` | `/stores/{store}/tickets/{ticket}/part-usages` | Add part usage |
| `POST` | `/stores/{store}/tickets/{ticket}/part-usages/{partUsage}/mistaken` | Mark part usage mistaken |
| `POST` | `/stores/{store}/tickets/{ticket}/part-usages/{partUsage}/notes` | Add note to part usage |
| `POST` | `/stores/{store}/tickets/{ticket}/part-usages/{partUsage}/attachments` | Upload files to part usage |
| `POST` | `/stores/{store}/tickets/{ticket}/pay-entries` | Add pay/driving entry |
| `POST` | `/stores/{store}/tickets/{ticket}/pay-entries/{payEntry}/mistaken` | Mark pay entry mistaken |
| `POST` | `/stores/{store}/tickets/{ticket}/pay-entries/{payEntry}/notes` | Add note to pay entry |
| `POST` | `/stores/{store}/tickets/{ticket}/pay-entries/{payEntry}/attachments` | Upload files to pay entry |
| `POST` | `/stores/{store}/tickets/{ticket}/warranties` | Add warranty |
| `POST` | `/stores/{store}/tickets/{ticket}/warranties/{warranty}/notes` | Add note to warranty |
| `POST` | `/stores/{store}/tickets/{ticket}/warranties/{warranty}/attachments` | Upload files to warranty |
| `POST` | `/stores/{store}/tickets/{ticket}/technicians` | Attach technicians to issues (no schedule) |

### Export

| Method | Path | Description |
|---|---|---|
| `GET` | `/export/excel` | Download full .xlsx export |

---

## 8. Key response shapes

### Ticket (from GET /stores/{store}/tickets or after any ticket-level POST)

```json
{
  "data": {
    "id": 12,
    "store_id": 3,
    "store": { "id": 3, "store_number": "03795-00001", "notes": null, "attachments": null, "created_at": "...", "updated_at": "..." },
    "status": { "value": "in_progress", "label": "In Progress" },
    "notes": [
      {
        "id": 5, "type": "final_notes", "type_label": "Final Notes",
        "body": "Replaced the heating element.",
        "attachments": [{ "id": 8, "url": "http://...", "original_name": "receipt.pdf", ... }],
        "created_by": 1, "creator": { "id": 1, "name": "Jane", "email": "jane@example.com" },
        "created_at": "...", "updated_at": "..."
      }
    ],
    "attachments": [],
    "issues": [ "...(TicketIssue objects)..." ],
    "issues_count": 2,
    "created_by": 1,
    "creator": { "id": 1, "name": "Jane", "email": "jane@example.com" },
    "created_at": "2026-06-01T10:00:00.000000Z",
    "updated_at": "2026-06-07T14:00:00.000000Z",
    "deleted_at": null
  }
}
```

### TicketIssue (from GET .../issues or .../issues/{id})

```json
{
  "id": 7, "ticket_id": 12,
  "issue_id": 3, "issue": { "id": 3, "title": "Oven failure", "description": null, ... },
  "other_title": null,
  "display_title": "Oven failure",
  "priority": { "value": "high", "label": "High" },
  "description": "Oven not heating above 200°F.",
  "status": { "value": "complete", "label": "Complete" },
  "parent_id": null,
  "children": [],
  "diagnoses": [ { "id": 1, "body": "Heating element failed.", "mistaken": false, "attachments": [...], "notes": [...], "ticket_issue_ids": [7], "created_by": 1, "created_at": "...", "updated_at": "..." } ],
  "attendance_entries": [ { "id": 2, "technician_id": 3, "technician": {...}, "start_clock": "2026-06-05T08:00:00Z", "end_clock": "2026-06-05T11:30:00Z", "start_break": null, "end_break": null, "start_parts_run": null, "end_parts_run": null, "mistaken": false, "attachments": [], "notes": [], "ticket_issue_ids": [7], "created_by": 1, "created_at": "...", "updated_at": "..." } ],
  "part_usages": [ { "id": 3, "part_id": 5, "part": { "id": 5, "name": "Heating element", ... }, "cost": "49.99", "mistaken": false, "attachments": [], "notes": [], "ticket_issue_ids": [7], "created_by": 1, "created_at": "...", "updated_at": "..." } ],
  "pay_entries": [],
  "warranties": [ { "id": 1, "body": "90-day parts warranty.", "expiry_date": "2026-09-05", "attachments": [], "notes": [], "ticket_issue_ids": [7], "created_by": 1, "created_at": "...", "updated_at": "..." } ],
  "assignments": [ { "id": 2, "assigned_date": "2026-06-05", "assigned_hour": "08:00", "delays": [], "ticket_issue_ids": [7], "notes": [], "attachments": [], "created_by": 1, "created_at": "...", "updated_at": "..." } ],
  "technicians": [ { "id": 3, "name": "Bob T.", "category_id": 1, "category": { "id": 1, "name": "HVAC", ... }, "notes": null, "attachments": null, "created_by": 1, "created_at": "...", "updated_at": "...", "deleted_at": null } ],
  "status_changes": [
    { "id": 1, "ticket_issue_id": 7, "from_status": null, "to_status": "pending", "reason": null, "created_by": 1, "created_at": "..." },
    { "id": 2, "ticket_issue_id": 7, "from_status": "pending", "to_status": "assigned", "reason": null, "created_by": 1, "created_at": "..." },
    { "id": 3, "ticket_issue_id": 7, "from_status": "assigned", "to_status": "complete", "reason": null, "created_by": 1, "created_at": "..." }
  ],
  "notes": [],
  "attachments": [],
  "created_by": 1,
  "creator": { "id": 1, "name": "Jane", "email": "jane@example.com" },
  "created_at": "2026-06-01T10:00:00.000000Z",
  "updated_at": "2026-06-07T11:30:00.000000Z"
}
```

### Note (response from any /notes endpoint)

```json
{
  "data": {
    "id": 42,
    "type": "what_we_learned",
    "type_label": "What we learned",
    "body": "The temperature probe had a hairline crack — always check probe first.",
    "attachments": [
      { "id": 55, "path": "attachments/abc.jpg", "url": "http://localhost:8000/storage/attachments/abc.jpg", "original_name": "crack-photo.jpg", "mime_type": "image/jpeg", "size": 204800, "created_by": 1, "created_at": "..." }
    ],
    "created_by": 1,
    "creator": { "id": 1, "name": "Jane", "email": "jane@example.com" },
    "created_at": "2026-06-07T15:00:00.000000Z",
    "updated_at": "2026-06-07T15:00:00.000000Z"
  }
}
```

### Warranty (now has expiry_date)

```json
{
  "data": {
    "id": 3,
    "body": "90-day parts and labour warranty.",
    "expiry_date": "2026-09-07",
    "attachments": [...],
    "notes": [],
    "ticket_issue_ids": [7, 9],
    "created_by": 1,
    "created_at": "...", "updated_at": "..."
  }
}
```

---

## 9. Suggested screens

### A. Ticket list (per store and global)

- **Filters bar:** store selector (global), status badge filter (now includes **Cancelled**), issue status filter, priority, date ranges, technician picker, part cost threshold, trashed toggle, sort
- **Table:** ticket id, store number, status badge (5 possible colours including Cancelled), issues count, creator name, created-at, soft-delete action
- **File ticket** button → §4.1 form

### B. File ticket

Repeatable issue rows: **"catalog" toggle** (searchable `/issues` dropdown) vs **"other" toggle** (free-text title input), priority selector, description textarea. "Add row" button. Submit → §4.1.

### C. Ticket detail

**Header:** ticket id · store number · big **status badge** · creator · created-at · **"Add note"** dropdown (Final Notes / What We Learned / Generic) · soft-delete button

**Closing notes panel** (shows notes with non-null `type`): one card per note — type badge, author, timestamp, body, attached files (thumbnails/download links). "Add Final Note" / "Add What We Learned" buttons each open the `/final-note` form.

**Issue cards** (one per issue, from §4.2):
- Header: `display_title`, priority badge, status badge, description, parent/children links
- **Action buttons:** Assign · Add Diagnosis · Add Part · Add Attendance · Add Pay · Add Warranty · Set Status · Defer · **Cancel** · Attach Technicians · **Add Note** · **Add Attachment**
- **History timeline** (ordered by `created_at`):
  - Status changes: `from → to`, reason (for deferrals and cancellations), who/when
  - Assignments: date/hour, technicians list, delay log (old→new + reason)
  - Diagnoses: body + attachment gallery + note cards. Mistaken → greyed/struck
  - Attendance: clock timeline per technician + attachment gallery + note cards. Mistaken → greyed
  - Part usages: part name + cost + attachments + note cards. Mistaken → greyed, excluded from cost total
  - Pay entries: attendance pay vs driving pay breakdown + note cards
  - Warranties: body + **expiry date** badge + attachments + note cards

**Ticket-level notes panel:** shows notes with `type === null` (generic). "Add Note" / "Add Attachment" buttons.

**Cost summary:** sum of non-mistaken `part_usages[].cost` across all issues.

### D. Catalog management (Issues / Technicians / Categories / Parts)

List + "Add" form per §3. Trashed/restore tabs for soft-deletable entities. Technician form includes category dropdown. Each catalog item detail page should show its `notes[]` and `attachments[]`.

---

## 10. Local quickstart

```bash
# 1. Ensure the SQLite database file exists and run migrations:
touch database/database.sqlite
php artisan migrate:fresh --seed   # creates schema + sample data + prints DEV TOKEN

# 2. Start the server:
php artisan serve                  # http://localhost:8000

# 3. Test with the printed token:
curl -H "Authorization: Bearer <TOKEN>" \
     -H "Accept: application/json" \
     http://localhost:8000/api/stores/03795-00001/tickets
```

Seeded reference data: stores `03795-00001`, `03795-00002`, `03795-00003`; a set of catalog issues, technicians, categories, and parts ready to reference.
