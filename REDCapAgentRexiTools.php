<?php
namespace Stanford\REDCapAgentRexiTools;

require_once "emLoggerTrait.php";

class REDCapAgentRexiTools extends \ExternalModules\AbstractExternalModule {

    use emLoggerTrait;

    // TODO: Update these field names to match the escalation project data dictionary.
    private const FIELD_SUBJECT = 'escalation_subject';
    private const FIELD_SUMMARY = 'escalation_summary';
    private const FIELD_USERNAME = 'escalation_username';
    private const FIELD_PRIORITY = 'escalation_priority';
    private const FIELD_STATUS = 'escalation_status';
    private const FIELD_CREATED_AT = 'escalation_created_at';
    private const FIELD_UPDATED_AT = 'escalation_updated_at';
    private const FIELD_RESOLUTION = 'escalation_resolution';
    private const FIELD_ASSIGNED_TO = 'escalation_assigned_to';
    private const FIELD_CONVERSATION_SUMMARY = 'escalation_conversation_summary';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * API Router — redcap_module_api()
     *
     * Single entry point for all escalation tool calls. Called two ways:
     *   - EM-to-EM (primary): SecureChatAI calls getModuleInstance()->redcap_module_api()
     *   - HTTP API (testing/external): curl with content=externalModule&prefix=...
     */
    public function redcap_module_api($action = null, $payload = [])
    {
        $payload = $this->normalizePayload($payload);

        $this->emDebug("AgentRexiTools API call", [
            'action' => $action,
            'payload' => $payload,
            'raw_POST' => $_POST,
            'payload_type' => gettype($payload)
        ]);

        if ($action === 'debug') {
            return $this->wrapResponse([
                "debug" => true,
                "action" => $action,
                "payload" => $payload,
                "payload_type" => gettype($payload),
                "POST" => $_POST
            ]);
        }

        switch ($action) {
            case "escalation_create":
                return $this->wrapResponse(
                    $this->toolEscalationCreate($payload)
                );

            case "escalation_list":
                return $this->wrapResponse(
                    $this->toolEscalationList($payload)
                );

            case "escalation_get":
                return $this->wrapResponse(
                    $this->toolEscalationGet($payload)
                );

            default:
                return $this->wrapResponse([
                    "error" => true,
                    "message" => "Unknown action: $action"
                ], 400);
        }
    }

    private function wrapResponse(array $result, int $defaultStatus = 200)
    {
        return [
            "status" => isset($result['error']) ? 400 : $defaultStatus,
            "body" => json_encode($result),
            "headers" => ["Content-Type" => "application/json"]
        ];
    }

    private function normalizePayload(array $payload): array
    {
        if (!empty($payload['payload'])) {
            $payloadData = json_decode($payload['payload'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $payloadData;
            }
        } elseif (empty($payload)) {
            if (!empty($_POST['payload'])) {
                $payloadData = json_decode($_POST['payload'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $payloadData;
                }
            } else {
                $raw = file_get_contents("php://input");
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
                return $_POST;
            }
        }

        return $payload;
    }

    private function getEscalationProjectId(): ?int
    {
        $pid = $this->getSystemSetting('escalation-project-id');
        if (empty($pid) || !is_numeric($pid)) {
            return null;
        }

        return (int)$pid;
    }

    private function resolveUsername(): ?string
    {
        global $userid;

        if (!empty($userid)) {
            return $userid;
        }

        if (defined('USERID')) {
            return USERID;
        }

        if (!empty($_SESSION['username'])) {
            return $_SESSION['username'];
        }

        return null;
    }

    private function getEscalationFields(): array
    {
        return [
            self::FIELD_SUBJECT,
            self::FIELD_SUMMARY,
            self::FIELD_USERNAME,
            self::FIELD_PRIORITY,
            self::FIELD_STATUS,
            self::FIELD_CREATED_AT,
            self::FIELD_UPDATED_AT,
            self::FIELD_RESOLUTION,
            self::FIELD_ASSIGNED_TO,
            self::FIELD_CONVERSATION_SUMMARY
        ];
    }

    private function escapeLogicValue(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    private function normalizeChoice(?string $value, array $map): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtolower(trim($value));
        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return $map[$normalized] ?? $value;
    }

    private function getDataTableName(int $pid): string
    {
        $table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($pid) : 'redcap_data';
        return preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    }

    private function getMaxNumericRecordId(string $table, int $pid): ?int
    {
        $pid = (int)$pid;
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($table === '') {
            return null;
        }

        $sql = "SELECT MAX(CAST(record AS UNSIGNED)) AS max_id
                FROM $table
                WHERE project_id = $pid
                  AND record REGEXP '^[0-9]+$'";
        $result = db_query($sql);
        if (!$result) {
            $this->emError("Failed to query max record id from $table for pid $pid");
            return null;
        }

        $row = db_fetch_assoc($result);
        if (!$row || $row['max_id'] === null) {
            return 0;
        }

        return (int)$row['max_id'];
    }

    private function getNextAutoNumberedRecordId(int $pid): ?string
    {
        $maxId = null;
        $hasRecordList = db_query("SHOW TABLES LIKE 'redcap_record_list'");
        if ($hasRecordList && db_num_rows($hasRecordList) > 0) {
            $maxId = $this->getMaxNumericRecordId('redcap_record_list', $pid);
        }

        if ($maxId === null) {
            $maxId = $this->getMaxNumericRecordId($this->getDataTableName($pid), $pid);
        }

        if ($maxId === null) {
            return null;
        }

        return (string)($maxId + 1);
    }

    /**
     * Tool: escalation.create
     * Create an escalation ticket in the configured escalation project.
     *
     * Confirmation UX is handled by the agent prompt/flow, not this EM.
     */
    public function toolEscalationCreate(array $payload)
    {
        $pid = $this->getEscalationProjectId();
        if (empty($pid)) {
            return [
                "error" => true,
                "message" => "Escalation project not configured. Set system setting escalation-project-id."
            ];
        }

        if (empty($payload['subject'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: subject"
            ];
        }

        if (empty($payload['summary'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: summary"
            ];
        }

        $username = $payload['username'] ?? $this->resolveUsername();
        if (empty($username)) {
            return [
                "error" => true,
                "message" => "Unable to determine username for escalation ticket"
            ];
        }

        $recordId = $payload['record_id'] ?? $this->getNextAutoNumberedRecordId($pid);
        if (empty($recordId)) {
            return [
                "error" => true,
                "message" => "Unable to determine next auto-numbered record ID for escalation ticket"
            ];
        }
        $priority = $this->normalizeChoice(
            $payload['priority'] ?? 'normal',
            ['low' => '3', 'normal' => '2', 'high' => '1']
        );
        $status = $this->normalizeChoice(
            $payload['status'] ?? 'open',
            ['open' => '1', 'in progress' => '2', 'resolved' => '3', 'closed' => '4']
        );
        $createdAt = date('Y-m-d H:i:s');
        $updatedAt = $payload['updated_at'] ?? $createdAt;

        $recordData = [
            self::FIELD_SUBJECT => $payload['subject'],
            self::FIELD_SUMMARY => $payload['summary'],
            self::FIELD_USERNAME => $username,
            self::FIELD_PRIORITY => $priority,
            self::FIELD_STATUS => $status,
            self::FIELD_CREATED_AT => $createdAt,
            self::FIELD_UPDATED_AT => $updatedAt
        ];

        if (!empty($recordId)) {
            $recordData['record_id'] = $recordId;
        }

        if (!empty($payload['resolution'])) {
            $recordData[self::FIELD_RESOLUTION] = $payload['resolution'];
        }

        if (!empty($payload['assigned_to'])) {
            $recordData[self::FIELD_ASSIGNED_TO] = $payload['assigned_to'];
        }

        if (!empty($payload['conversation_summary'])) {
            $recordData[self::FIELD_CONVERSATION_SUMMARY] = $payload['conversation_summary'];
        }

        $data = [$recordData];

        try {
            $result = \REDCap::saveData(
                $pid,
                'json',
                json_encode($data),
                'normal'
            );

            if (!empty($result['errors'])) {
                return [
                    "error" => true,
                    "message" => "Failed to create escalation ticket",
                    "errors" => $result['errors'],
                    "warnings" => $result['warnings'] ?? [],
                    "data_submitted" => $data
                ];
            }

            $savedId = $recordId;
            if (empty($savedId) && !empty($result['ids']) && is_array($result['ids'])) {
                $savedId = $result['ids'][0] ?? null;
            }

            return [
                "pid" => $pid,
                "record_id" => $savedId,
                "subject" => $payload['subject'],
                "summary" => $payload['summary'],
                "priority" => $priority,
                "status" => $status,
                "username" => $username,
                "success" => true
            ];
        } catch (\Exception $e) {
            $this->emError("escalation.create error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to create escalation ticket: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool: escalation.list
     * List escalation tickets for the current user with optional status filter.
     */
    public function toolEscalationList(array $payload)
    {
        $pid = $this->getEscalationProjectId();
        if (empty($pid)) {
            return [
                "error" => true,
                "message" => "Escalation project not configured. Set system setting escalation-project-id."
            ];
        }

        $username = $payload['username'] ?? $this->resolveUsername();
        if (empty($username)) {
            return [
                "error" => true,
                "message" => "Unable to determine username for escalation list"
            ];
        }

        $status = $this->normalizeChoice(
            $payload['status'] ?? null,
            ['open' => '1', 'in progress' => '2', 'resolved' => '3', 'closed' => '4']
        );
        $filters = [
            "[" . self::FIELD_USERNAME . "] = '" . $this->escapeLogicValue($username) . "'"
        ];

        if (!empty($status)) {
            $filters[] = "[" . self::FIELD_STATUS . "] = '" . $this->escapeLogicValue($status) . "'";
        }

        $filterLogic = implode(" AND ", $filters);

        try {
            $data = \REDCap::getData(
                $pid,
                'array',
                null,
                $this->getEscalationFields(),
                null,
                null,
                false,
                false,
                false,
                $filterLogic
            );

            return [
                "pid" => $pid,
                "username" => $username,
                "status" => $status,
                "record_count" => is_array($data) ? count($data) : 0,
                "records" => $data
            ];
        } catch (\Exception $e) {
            $this->emError("escalation.list error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to list escalation tickets: " . $e->getMessage()
            ];
        }
    }

    /**
     * Tool: escalation.get
     * Fetch details for a specific escalation ticket by record_id.
     */
    public function toolEscalationGet(array $payload)
    {
        $pid = $this->getEscalationProjectId();
        if (empty($pid)) {
            return [
                "error" => true,
                "message" => "Escalation project not configured. Set system setting escalation-project-id."
            ];
        }

        if (empty($payload['record_id'])) {
            return [
                "error" => true,
                "message" => "Missing required parameter: record_id"
            ];
        }

        $recordId = $payload['record_id'];

        try {
            $data = \REDCap::getData(
                $pid,
                'array',
                [$recordId],
                $this->getEscalationFields()
            );

            if (empty($data)) {
                return [
                    "error" => true,
                    "message" => "No escalation ticket found for record_id '$recordId'"
                ];
            }

            return [
                "pid" => $pid,
                "record_id" => $recordId,
                "record" => $data[$recordId] ?? $data
            ];
        } catch (\Exception $e) {
            $this->emError("escalation.get error for pid $pid: " . $e->getMessage());
            return [
                "error" => true,
                "message" => "Failed to fetch escalation ticket: " . $e->getMessage()
            ];
        }
    }
}
