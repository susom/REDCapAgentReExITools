# Copilot Instructions

## What This Module Does

`REDCapAgentRexiTools` is a REDCap External Module that exposes escalation ticket
management as callable agent tools for the SecureChatAI orchestration system.
It has no UI — all interaction is via `handleToolCall()`.

Tools are called via EM-to-EM direct PHP:
- SecureChatAI calls `getModuleInstance()->handleToolCall($action, $payload)` — no HTTP, no API tokens

Three actions are exposed:
- `escalation_create` — create a ticket in the configured escalation project
- `escalation_list` — list tickets for the current user (with optional status filter)
- `escalation_get` — fetch a single ticket by `record_id`

## Architecture

### Auto-Discovery

SecureChatAI discovers this EM by matching its prefix against the **Agent Tool EM Prefixes**
list in SecureChatAI settings. The `redcap_agent_` prefix is a convention, not a hard
requirement. The module only needs to be enabled **system-wide** (no project-level enablement required).

### Entry Point

`handleToolCall(string $action, array $payload): array` in `REDCapAgentRexiTools.php` is the
single entry point. It dispatches to the appropriate `tool*` method and returns raw result arrays.

### Tool Manifest

Tool definitions live in `tools.json` (not config.json). Each tool has an `action` field
that links to the corresponding switch case in `handleToolCall()`.

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
- **Tool registration**: Tools are declared in `tools.json` with JSON Schema definitions.
  The `action` field links each tool to its PHP switch case.
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
