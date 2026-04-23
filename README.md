# REDCapAgentRexiTools

A REDCap External Module that exposes **RExI escalation ticket operations as callable agent tools** for the [SecureChatAI](https://github.com/susom/secureChatAI) orchestration system.

Three tools for creating, listing, and fetching escalation tickets — no UI, no orchestration logic, just a data layer.

---

## How It Works

```
User → Cappy (or other UX) → SecureChatAI (Agent Orchestrator) → THIS MODULE → REDCap
```

1. User describes an issue in natural language ("I need help with my intake survey")
2. The calling EM (Cappy, MSPA, or any EM) calls `$secureChatAI->callAI()` with `agent_mode => true`
3. SecureChatAI's LLM decides to create/list/fetch an escalation ticket
4. SecureChatAI invokes this module's `redcap_module_api()` via EM-to-EM direct PHP (no API token needed)
5. This module executes the operation against the configured escalation project and returns structured JSON
6. The LLM uses the result to compose a human-readable response

---

## Installation & Setup

1. Place this module in your REDCap `modules/` directory (or `modules-local/` for development)
2. Enable the module **system-wide** in REDCap's External Module Manager (project-level enablement is not required)
3. Set the **Escalation Project ID** system setting to point to the REDCap project that stores escalation tickets
4. In **SecureChatAI** settings, add this module's prefix to **Agent Tool EM Prefixes**:
   - System-wide: `agent_tool_em_prefixes` setting
   - Or per-project: `project_agent_tool_em_prefixes` setting

That's it. SecureChatAI auto-discovers the tools from this module's config.json and invokes them via direct PHP calls (EM-to-EM). No API token, no HTTP requests — just one EM calling another's `redcap_module_api()` method in the same process.

### Auto-Discovery

SecureChatAI discovers tool EMs by matching their prefix against the **Agent Tool EM Prefixes** list. The `redcap_agent_` prefix is a **convention**, not a hard requirement — any prefix works as long as it's in SecureChatAI's list.

---

## System Setting

| Setting | Description |
|---------|-------------|
| **Escalation Project ID** (`escalation-project-id`) | The REDCap project that stores escalation tickets. Required. |

---

## Placeholder Field Names (TODO)

These are the current defaults in `REDCapAgentRexiTools.php`. Update them to match your escalation project data dictionary:

- `escalation_subject`
- `escalation_summary`
- `escalation_username`
- `escalation_priority`
- `escalation_status`
- `escalation_created_at`
- `escalation_updated_at`
- `escalation_resolution`
- `escalation_assigned_to`
- `escalation_conversation_summary`

---

## Tool Reference

Every tool is called through `redcap_module_api($action, $payload)`. In production, SecureChatAI handles this via EM-to-EM PHP calls. For direct testing, you can also call via the REDCap API.

All tools return JSON. On success, you get the result object. On failure:
```json
{"error": true, "message": "What went wrong"}
```

### escalation.create

**Action:** `escalation_create`

Create a ticket in the escalation project. Auto-generates a numeric record ID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `subject` | string | ✅ | Brief subject line for the escalation |
| `summary` | string | ✅ | Detailed description of the issue |
| `priority` | string | | `"low"`, `"normal"` (default), or `"high"` |
| `status` | string | | `"open"` (default), `"in_progress"`, `"resolved"`, `"closed"` |
| `username` | string | | Auto-resolved from session if omitted |

```json
// Request
{"subject": "Need help with intake", "summary": "Survey error on submit", "priority": "high"}

// Response
{
  "pid": 99,
  "record_id": "42",
  "subject": "Need help with intake",
  "summary": "Survey error on submit",
  "priority": "1",
  "status": "1",
  "username": "irvins",
  "success": true
}
```

> **Note:** Confirmation UX (agent summarizes, user approves) is handled by the agent prompt, not this EM.

---

### escalation.list

**Action:** `escalation_list`

List tickets for the current user with optional status filter.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `status` | string | | Filter: `"open"`, `"in_progress"`, `"resolved"`, `"all"` (default: `"open"`) |
| `username` | string | | Auto-resolved from session if omitted |

```json
// Request
{"status": "open"}

// Response
{
  "pid": 99,
  "username": "irvins",
  "status": "1",
  "record_count": 2,
  "records": { ... }
}
```

---

### escalation.get

**Action:** `escalation_get`

Fetch details for a specific ticket by record ID.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `record_id` | string | ✅ | The escalation ticket record ID |

```json
// Request
{"record_id": "42"}

// Response
{
  "pid": 99,
  "record_id": "42",
  "record": { ... }
}
```

---

## Testing

### End-to-End via SecureChatAI (Recommended)

```bash
curl -X POST https://your-redcap/api/ \
  -d "token=YOUR_SECURECHAT_PROJECT_TOKEN" \
  -d "content=externalModule" \
  -d "prefix=secure_chat_ai" \
  -d "action=callAI" \
  -d 'payload={"message":"Create an escalation ticket about a broken survey","agent_mode":true}'
```

### Direct API Call (Isolation Testing)

Enable the tool EM on a project, get an API token, and call directly:

```bash
curl -X POST https://your-redcap/api/ \
  -d "token=YOUR_PROJECT_TOKEN" \
  -d "content=externalModule" \
  -d "prefix=redcap_agent_rexi_tools" \
  -d "action=escalation_create" \
  -d 'payload={"subject":"Test ticket","summary":"Testing escalation creation"}'
```

---

## Architecture Notes

- **Choice normalization:** Priority and status strings are mapped to coded values (`low→3`, `normal→2`, `high→1`; `open→1`, `in progress→2`, etc.)
- **Record ID auto-numbering:** Checks `redcap_record_list` first (newer REDCap), falls back to querying the data table
- **Username resolution:** Tries `$userid` global → `USERID` constant → `$_SESSION['username']` → explicit payload parameter

---

## Related Modules

- [`secure_chat_ai`](https://github.com/susom/secureChatAI) — Agent orchestration and LLM routing
- [`redcap_agent_record_tools`](https://github.com/susom/REDCapAgentRecordTools) — Project and record operations
- [`redcap_agent_tool_template`](https://github.com/susom/REDCapAgentToolTemplate) — Starter template for building new tool EMs
