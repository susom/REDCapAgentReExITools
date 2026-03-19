# REDCapAgentRexiTools

REDCapAgentRexiTools is a lightweight External Module scaffold that exposes
RExI escalation tools as callable agent actions for SecureChatAI.

This module is intentionally minimal and focused on three API actions. The
escalation project data dictionary and tool registry schema are out of scope
for now.

---

## System Setting

- **Escalation Project ID** (`escalation-project-id`): Points to the REDCap
  project that stores escalation tickets.

---

## Placeholder Field Names (TODO)

These are the current defaults in `REDCapAgentRexiTools.php`. Update them to
match your escalation project data dictionary:

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

## Actions (Scaffold)

### escalation_create

Create a ticket in the escalation project.

Notes:
- The tool will auto-generate a numeric record ID by querying the next available
  auto-numbered ID for the project (record list/data table).
- Priority/status labels are normalized to their coded values.

**Payload**
```json
{
  "subject": "Need help with intake",
  "summary": "User reports survey error when submitting",
  "priority": "high",
  "status": "open"
}
```

Notes:
- Username is auto-resolved from session/API context.
- Confirmation UX (agent summarizes, user approves) is handled by the agent
  prompt, not this EM.

### escalation_list

List tickets for the current user.

**Payload**
```json
{
  "status": "open"
}
```

### escalation_get

Fetch a specific ticket by `record_id`.

**Payload**
```json
{
  "record_id": "esc_irvins_ab12cd34"
}
```

---

## Out of Scope (Intentional)

- Tool registry schema entry for SecureChatAI
- Escalation project data dictionary / field definitions
- Teams integration
- UI-side styling overrides in Cappy
