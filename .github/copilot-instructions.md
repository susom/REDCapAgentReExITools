# Copilot Instructions

## What This Module Does

`REDCapAgentRexiTools` is a REDCap External Module that exposes escalation ticket
management as callable agent tools for the SecureChatAI orchestration system.
It has no UI — all interaction is via `redcap_module_api()`.

Tools are called two ways:
- **EM-to-EM (primary):** SecureChatAI calls `getModuleInstance()->redcap_module_api()` — direct PHP, no HTTP
- **HTTP API (testing/external):** curl with `content=externalModule&prefix=redcap_agent_rexi_tools`

Three API actions are exposed:
- `escalation_create` — create a ticket in the configured escalation project
- `escalation_list` — list tickets for the current user (with optional status filter)
- `escalation_get` — fetch a single ticket by `record_id`

## Architecture

### Auto-Discovery

SecureChatAI discovers this EM by matching its prefix against the **Agent Tool EM Prefixes**
list in SecureChatAI settings. The `redcap_agent_` prefix is a convention, not a hard
requirement. The module only needs to be enabled **system-wide** (no project-level enablement required).

### Entry Point

`redcap_module_api($action, $payload)` in `REDCapAgentRexiTools.php` is the single
entry point. It normalizes the payload, dispatches to the appropriate `tool*` method,
and wraps the result via `wrapResponse()`.

### Payload Normalization

Agents may send payloads in multiple formats. `normalizePayload()` handles:
1. A `payload` key containing a JSON string (nested wrapping from SecureChatAI)
2. `$_POST['payload']` as a JSON string
3. Raw JSON body (`php://input`)
4. Plain `$_POST`

### Response Format

All responses are arrays with `status` (int), `body` (JSON string), and `headers`.
Always use `wrapResponse()` — never return raw arrays from the API entry point.
Error responses must include `"error" => true` to trigger the 400 status in `wrapResponse()`.

### Field Name Constants

All escalation project field names are defined as private constants at the top of
the class (`FIELD_SUBJECT`, `FIELD_SUMMARY`, etc.). These are **placeholder names**
that must be updated to match the actual REDCap project data dictionary before use
(see the TODO comment in the file and the README).

### Choice Normalization

`normalizeChoice()` maps human-readable strings to coded values. Mapping is
case-insensitive, normalizes underscores/hyphens to spaces:
- Priority: `low→3`, `normal→2`, `high→1`
- Status: `open→1`, `in progress→2`, `resolved→3`, `closed→4`

### Record ID Auto-Numbering

`getNextAutoNumberedRecordId()` checks `redcap_record_list` first (newer REDCap
versions), then falls back to querying the data table directly. Table names are
sanitized before interpolation into raw SQL via `preg_replace('/[^a-zA-Z0-9_]/', '', ...)`.

### Logging

Uses `emLoggerTrait` (vendored copy in `emLoggerTrait.php`). Debug logging only fires
when the `em_logger` External Module is installed and either the system or project
debug setting is enabled. Use `$this->emDebug(...)`, `$this->emLog(...)`,
`$this->emError(...)`.

## Key Conventions

- **Namespace**: `Stanford\REDCapAgentRexiTools` — used in both files.
- **EM Framework version**: 14 (`framework-version` in `config.json`).
- **API access control**: All three actions are declared under `api-actions` in
  `config.json` with `"access": ["auth"]`. This is future-proofing for external
  API access — EM-to-EM calls (the primary path) don't require authentication.
- **No confirmation UX in this EM**: Agent confirmation flows (summarize → user
  approves → create) are handled by the agent prompt in SecureChatAI, not here.
- **REDCap API calls**: Use `\REDCap::getData()` and `\REDCap::saveData()` (static
  calls on the global `\REDCap` class). Pass `$pid` explicitly; do not rely on
  project context.
- **System setting key**: `escalation-project-id` (hyphen-delimited, matches
  `config.json`). Retrieve with `$this->getSystemSetting('escalation-project-id')`.
- **Username resolution**: `resolveUsername()` tries `$userid` global, then
  `USERID` constant, then `$_SESSION['username']`. Callers may also pass
  `username` explicitly in the payload.
