# Frontend Guide — v2.1 Update Notes

This document covers **only** the changes introduced in the current update.
See `public/openapi.json` (65 paths, 46+ schemas) for the complete API reference.

---

## What Changed in This Update

### 1. Notes & Attachments at Ticket Creation Time

`POST /stores/{store}/tickets` now accepts optional notes and file attachments
**at creation time**, in addition to the already-existing generic
`POST .../notes` and `POST .../attachments` endpoints for adding them afterward.

**Content-Type:** `multipart/form-data` when uploading files; `application/json`
is still accepted for field-only payloads (no files).

#### New optional fields

| Field | Where | Type | Description |
|---|---|---|---|
| `notes[]` | Ticket level | array | Notes to attach to the ticket |
| `notes[].body` | | string (required within notes) | Note text |
| `notes[].type` | | string \| null | e.g. `final_notes`, `what_we_learned` |
| `files[]` | Ticket level | file uploads | Direct attachments to the ticket |
| `issues[N][notes][]` | Per issue | array | Notes to attach to that issue |
| `issues[N][notes][M][body]` | | string | Note text |
| `issues[N][notes][M][type]` | | string \| null | Optional note type |
| `issues[N][files][]` | Per issue | file uploads | Direct attachments to that issue |

#### Example — multipart with ticket note and file

```
POST /stores/03795-00001/tickets
Content-Type: multipart/form-data

issues[0][priority]           = high
issues[0][description]        = Oven not heating
issues[0][issue_id]           = 4
issues[0][notes][0][body]     = First noticed 2 days ago
notes[0][body]                = This ticket was escalated
notes[0][type]                =
files[]                       = @photo.jpg
```

#### Example — plain JSON (no files)

```json
POST /stores/03795-00001/tickets
Content-Type: application/json

{
  "issues": [
    {
      "issue_id": 4,
      "priority": "high",
      "description": "Oven not heating",
      "notes": [
        { "body": "First noticed 2 days ago" }
      ]
    }
  ],
  "notes": [
    { "body": "Escalated ticket", "type": null }
  ]
}
```

> **Note:** Notes created at ticket/issue creation time are **text-only**.
> To attach files to a note, use the separate `POST .../notes` endpoint
> (which supports `files[]` inline with the note body).

#### Response

The response is the same `Ticket` shape as before. Ticket-level `notes` and
`attachments` arrays will be populated if any were provided. Issue-level notes
are **not** embedded in the ticket creation response (the ticket index does not
load per-issue notes for performance); retrieve full issue detail via
`GET /stores/{store}/tickets/{ticket}/issues/{issue}`.

---

### 2. `mistaken` Flag Added to Warranties, Assignments, and Assignment Delays

Three workflow record types that were missing the `mistaken` boolean now have it.
Each gets a dedicated `POST .../mistaken` endpoint — no request body required.

**Semantics:** `mistaken: true` means the record was entered in error. The record
is **not deleted** (append-only design); the frontend should visually strike it
out or hide it from cost/time summaries.

Updated schemas (the `mistaken` field is now present in all responses):

| Schema | New field |
|---|---|
| `Warranty` | `mistaken: boolean` |
| `Assignment` | `mistaken: boolean` |
| `AssignmentDelay` | `mistaken: boolean` |

---

## All Mistaken Endpoints — Complete Reference

All seven endpoints follow the same pattern:

- **Method:** `POST`
- **Auth:** Bearer token (same as every other endpoint)
- **Request body:** none
- **Success:** `200 OK` with the updated record wrapped in `{ "data": { … } }`

### 1. Diagnoses

```
POST /stores/{store}/tickets/{ticket}/diagnoses/{diagnosis}/mistaken
```

**Path params:**

| Param | Type | Description |
|---|---|---|
| `store` | string | store_number (e.g. `03795-00001`) |
| `ticket` | integer | Ticket id |
| `diagnosis` | integer | Diagnosis id |

**Response:**
```json
{
  "data": {
    "id": 12,
    "body": "Checked thermostat — OK",
    "mistaken": true,
    "attachments": [],
    "notes": [],
    "ticket_issue_ids": [3, 7],
    "created_by": 2,
    "created_at": "2026-06-07T10:00:00.000000Z",
    "updated_at": "2026-06-07T10:05:00.000000Z"
  }
}
```

---

### 2. Attendance Entries

```
POST /stores/{store}/tickets/{ticket}/attendance-entries/{attendanceEntry}/mistaken
```

**Path params:**

| Param | Type | Description |
|---|---|---|
| `store` | string | store_number |
| `ticket` | integer | Ticket id |
| `attendanceEntry` | integer | AttendanceEntry id |

**Response:**
```json
{
  "data": {
    "id": 5,
    "technician_id": 3,
    "technician": { "id": 3, "name": "John Doe" },
    "start_clock": "08:00:00",
    "end_clock": "16:00:00",
    "start_break": null,
    "end_break": null,
    "start_parts_run": null,
    "end_parts_run": null,
    "mistaken": true,
    "attachments": [],
    "notes": [],
    "ticket_issue_ids": [3],
    "created_by": 1,
    "created_at": "2026-06-07T08:00:00.000000Z",
    "updated_at": "2026-06-07T10:10:00.000000Z"
  }
}
```

---

### 3. Part Usages

```
POST /stores/{store}/tickets/{ticket}/part-usages/{partUsage}/mistaken
```

**Path params:**

| Param | Type | Description |
|---|---|---|
| `store` | string | store_number |
| `ticket` | integer | Ticket id |
| `partUsage` | integer | PartUsage id |

**Response:**
```json
{
  "data": {
    "id": 9,
    "part_id": 14,
    "part": { "id": 14, "name": "Igniter" },
    "cost": 45.00,
    "mistaken": true,
    "attachments": [],
    "notes": [],
    "ticket_issue_ids": [3],
    "created_by": 1,
    "created_at": "2026-06-07T09:00:00.000000Z",
    "updated_at": "2026-06-07T10:15:00.000000Z"
  }
}
```

---

### 4. Pay Entries

```
POST /stores/{store}/tickets/{ticket}/pay-entries/{payEntry}/mistaken
```

**Path params:**

| Param | Type | Description |
|---|---|---|
| `store` | string | store_number |
| `ticket` | integer | Ticket id |
| `payEntry` | integer | PayEntry id |

**Response:**
```json
{
  "data": {
    "id": 7,
    "technician_id": 3,
    "technician": { "id": 3, "name": "John Doe" },
    "base_pay": 120.00,
    "performance_pay": 20.00,
    "driving_time": null,
    "miles_driven": null,
    "per_mile_rate": null,
    "driving_base_pay": null,
    "driving_performance_pay": null,
    "mistaken": true,
    "attachments": [],
    "notes": [],
    "ticket_issue_ids": [3],
    "created_by": 1,
    "created_at": "2026-06-07T09:30:00.000000Z",
    "updated_at": "2026-06-07T10:20:00.000000Z"
  }
}
```

---

### 5. Warranties *(new in this update)*

```
POST /stores/{store}/tickets/{ticket}/warranties/{warranty}/mistaken
```

**Path params:**

| Param | Type | Description |
|---|---|---|
| `store` | string | store_number |
| `ticket` | integer | Ticket id |
| `warranty` | integer | Warranty id |

**Response:**
```json
{
  "data": {
    "id": 2,
    "body": "90-day parts warranty",
    "expiry_date": "2026-09-07",
    "mistaken": true,
    "attachments": [],
    "notes": [],
    "ticket_issue_ids": [3],
    "created_by": 1,
    "created_at": "2026-06-07T11:00:00.000000Z",
    "updated_at": "2026-06-07T11:05:00.000000Z"
  }
}
```

---

### 6. Assignments *(new in this update)*

```
POST /stores/{store}/tickets/{ticket}/assignments/{assignment}/mistaken
```

**Path params:**

| Param | Type | Description |
|---|---|---|
| `store` | string | store_number |
| `ticket` | integer | Ticket id |
| `assignment` | integer | Assignment id |

**Response:**
```json
{
  "data": {
    "id": 4,
    "assigned_date": "2026-06-10",
    "assigned_hour": "09:00",
    "mistaken": true,
    "delays": [],
    "ticket_issue_ids": [3, 7],
    "notes": [],
    "attachments": [],
    "created_by": 1,
    "created_at": "2026-06-07T10:00:00.000000Z",
    "updated_at": "2026-06-07T10:30:00.000000Z"
  }
}
```

---

### 7. Assignment Delays *(new in this update)*

```
POST /stores/{store}/tickets/{ticket}/assignments/{assignment}/delays/{delay}/mistaken
```

**Path params:**

| Param | Type | Description |
|---|---|---|
| `store` | string | store_number |
| `ticket` | integer | Ticket id |
| `assignment` | integer | Assignment id (parent) |
| `delay` | integer | AssignmentDelay id |

**Response:**
```json
{
  "data": {
    "id": 1,
    "assignment_id": 4,
    "old_date": "2026-06-10",
    "old_hour": "09:00",
    "new_date": "2026-06-12",
    "new_hour": "10:00",
    "reason": "Technician unavailable",
    "mistaken": true,
    "notes": [],
    "attachments": [],
    "created_by": 1,
    "created_at": "2026-06-07T10:25:00.000000Z"
  }
}
```

---

## Summary — All Record Types and Their Mistaken Support

| Record type | Create endpoint | Mistaken endpoint | `mistaken` since |
|---|---|---|---|
| Diagnosis | `POST .../diagnoses` | `POST .../diagnoses/{diagnosis}/mistaken` | v1.0 |
| AttendanceEntry | `POST .../attendance-entries` | `POST .../attendance-entries/{attendanceEntry}/mistaken` | v1.0 |
| PartUsage | `POST .../part-usages` | `POST .../part-usages/{partUsage}/mistaken` | v1.0 |
| PayEntry | `POST .../pay-entries` | `POST .../pay-entries/{payEntry}/mistaken` | v1.0 |
| Warranty | `POST .../warranties` | `POST .../warranties/{warranty}/mistaken` | **v2.1** |
| Assignment | `POST .../assignments` | `POST .../assignments/{assignment}/mistaken` | **v2.1** |
| AssignmentDelay | `POST .../assignments/{id}/delays` | `POST .../assignments/{assignment}/delays/{delay}/mistaken` | **v2.1** |

> All mistaken endpoints are idempotent — calling them multiple times has no
> additional effect once `mistaken` is already `true`.
