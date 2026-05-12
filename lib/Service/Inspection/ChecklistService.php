<?php

/**
 * Procest Inspection Checklist Service
 *
 * Orchestrates the lifecycle of inspectionChecklistTemplate + inspectionChecklistRun
 * objects. Generic CRUD is delegated to the OpenRegister manifest renderer; this
 * service owns only the operations that the manifest path cannot perform:
 *
 *  - createRun(inspection, template): freeze templateSnapshot + templateVersion,
 *    server-derive inspector identity from IUserSession.
 *  - submitRun(run, payload): validate responses, derive overallResult,
 *    dispatch follow-up actions, mark run append-only.
 *  - aggregateResult(responses, snapshot): pass/fail aggregation per REQ-IC-6.
 *
 * Append-only enforcement (REQ-IC-8) lives in ChecklistRunImmutabilityListener
 * on OpenRegister ObjectUpdatedEvent — this service layers a service-level
 * check on top so direct callers see the same contract.
 *
 * @category Service
 * @package  OCA\Procest\Service\Inspection
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Inspection;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Procest\Service\SettingsService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Inspection checklist orchestration service.
 *
 * @spec openspec/changes/inspection-checklists/tasks.md#T03
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   — orchestrates ObjectService + IUserSession + follow-up dispatch
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — guarded by validateResponse branches per response type
 */
class ChecklistService
{
    /**
     * Run status when initially created.
     */
    public const STATUS_CONCEPT = 'concept';

    /**
     * Run status while answers are being entered.
     */
    public const STATUS_IN_UITVOERING = 'in_uitvoering';

    /**
     * Run status once the inspector submits — append-only from here.
     */
    public const STATUS_INGEDIEND = 'ingediend';

    /**
     * Run status when retention has archived the run.
     */
    public const STATUS_GEARCHIVEERD = 'gearchiveerd';

    /**
     * Overall result: every item passes or is N/A.
     */
    public const RESULT_CONFORM = 'conform';

    /**
     * Overall result: at least one failure and no skipped items.
     */
    public const RESULT_NIET_CONFORM = 'niet_conform';

    /**
     * Overall result: at least one failure AND at least one skipped item.
     */
    public const RESULT_DEELS_CONFORM = 'deels_conform';

    /**
     * Follow-up types matching the failureAction.type enum.
     */
    public const FOLLOWUP_HERINSPECTIE     = 'herinspectie';
    public const FOLLOWUP_HANDHAVINGSTAAK  = 'handhavingstaak';
    public const FOLLOWUP_DOCUMENT_VERZOEK = 'documentVerzoek';
    public const FOLLOWUP_GEEN = 'geen';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The Procest settings/config bridge to OpenRegister
     * @param IUserSession    $userSession     The current Nextcloud user session
     * @param LoggerInterface $logger          The logger instance
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Start a new run against a template, freezing the snapshot and inspector identity.
     *
     * @param string      $templateId   Template UUID
     * @param string      $caseId       Parent case UUID
     * @param string|null $inspectionId Optional parent mobiel-inspectie session id
     *
     * @return array<string, mixed> The persisted run
     *
     * @throws RuntimeException When configuration is missing, the template
     *                          cannot be loaded, or persistence fails.
     */
    public function createRun(string $templateId, string $caseId, ?string $inspectionId=null): array
    {
        [$objectService, $register] = $this->bootstrap();
        $templateSchema = $this->requireConfig(key: 'inspection_checklist_template_schema');
        $runSchema      = $this->requireConfig(key: 'inspection_checklist_run_schema');

        $template = $this->toArray(value: $objectService->findObject($register, $templateSchema, $templateId));
        if ($template === []) {
            throw new RuntimeException('Inspection checklist template not found');
        }

        $version  = (int) ($template['version'] ?? 1);
        $snapshot = [
            'name'     => (string) ($template['name'] ?? ''),
            'version'  => $version,
            'sections' => $template['sections'] ?? [],
        ];

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $run = [
            'case'             => $caseId,
            'template'         => $templateId,
            'templateVersion'  => $version,
            'templateSnapshot' => $snapshot,
            'inspector'        => $this->requireUserId(),
            'startedAt'        => $now,
            'status'           => self::STATUS_CONCEPT,
            'responses'        => [],
            'syncState'        => 'synced',
        ];

        if ($inspectionId !== null && $inspectionId !== '') {
            $run['inspection'] = $inspectionId;
        }

        $persisted = $this->toArray(value: $objectService->saveObject($register, $runSchema, $run));

        $this->logger->info(
            'Procest: created checklist run {runId} for template {templateId} v{version}',
            [
                'runId'      => (string) ($persisted['id'] ?? ''),
                'templateId' => $templateId,
                'version'    => $version,
            ]
        );

        return $persisted;
    }//end createRun()

    /**
     * Submit a run: validate, derive overallResult, dispatch follow-ups, lock to append-only.
     *
     * @param string               $runId   Run UUID
     * @param array<string, mixed> $payload {responses?: array, followUpType?: string}
     *
     * @return array<string, mixed> The submitted, immutable run
     *
     * @throws RuntimeException When the run is already submitted (REQ-IC-8)
     *                          or persistence fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — branches cover validation + follow-up dispatch
     */
    public function submitRun(string $runId, array $payload): array
    {
        [$objectService, $register] = $this->bootstrap();
        $runSchema = $this->requireConfig(key: 'inspection_checklist_run_schema');

        $run = $this->toArray(value: $objectService->findObject($register, $runSchema, $runId));
        if ($run === []) {
            throw new RuntimeException('Inspection checklist run not found');
        }

        $this->assertRunMutable(run: $run);

        // User-supplied inspector / overallResult / submittedAt are ignored on submit.
        $responses = $payload['responses'] ?? ($run['responses'] ?? []);
        if (is_array($responses) === false) {
            $responses = [];
        }

        $snapshot = $run['templateSnapshot'] ?? [];
        if (is_array($snapshot) === false) {
            $snapshot = [];
        }

        $itemsByOrder = $this->indexItemsBySnapshot(snapshot: $snapshot);

        $validResponses = [];
        foreach ($responses as $response) {
            if (is_array($response) === false) {
                continue;
            }

            $itemId = (string) ($response['itemId'] ?? '');
            $item   = $itemsByOrder[$itemId] ?? null;
            if ($item !== null) {
                $this->validateResponse(item: $item, payload: $response);
            }

            $validResponses[] = $response;
        }

        $aggregate = $this->aggregateResult(responses: $validResponses, snapshot: $snapshot);

        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $run['responses']     = $validResponses;
        $run['status']        = self::STATUS_INGEDIEND;
        $run['submittedAt']   = $now;
        $run['completedAt']   = $now;
        $run['overallResult'] = $aggregate;
        $run['inspector']     = $this->requireUserId();
        $run['syncState']     = 'synced';

        $followUp = $this->resolvePrimaryFollowUp(responses: $validResponses, snapshot: $snapshot);
        if ($followUp !== null) {
            $run['followUpType'] = $followUp;
        }

        $persisted = $this->toArray(value: $objectService->saveObject($register, $runSchema, $run));

        try {
            $this->dispatchFollowUps(run: $persisted);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: checklist follow-up dispatch failed for run {runId}: {msg}',
                [
                    'runId' => (string) ($persisted['id'] ?? ''),
                    'msg'   => $e->getMessage(),
                ]
            );
        }

        return $persisted;
    }//end submitRun()

    /**
     * Derive overallResult from responses + frozen template snapshot.
     *
     * @param array<int, array<string, mixed>> $responses Submitted responses
     * @param array<string, mixed>             $snapshot  Frozen template snapshot
     *
     * @return string conform | niet_conform | deels_conform
     */
    public function aggregateResult(array $responses, array $snapshot): string
    {
        $itemsByOrder = $this->indexItemsBySnapshot(snapshot: $snapshot);
        $fails        = 0;
        $skipped      = 0;

        foreach ($responses as $response) {
            if (is_array($response) === false) {
                continue;
            }

            $itemId  = (string) ($response['itemId'] ?? '');
            $item    = $itemsByOrder[$itemId] ?? null;
            $verdict = $this->classifyResponse(response: $response, item: $item);

            if ($verdict === 'fail') {
                $fails++;
            } else if ($verdict === 'skip') {
                $skipped++;
            }
        }

        if ($fails === 0) {
            return self::RESULT_CONFORM;
        }

        if ($skipped > 0) {
            return self::RESULT_DEELS_CONFORM;
        }

        return self::RESULT_NIET_CONFORM;
    }//end aggregateResult()

    /**
     * Validate a single response against its item definition (REQ-IC-3).
     *
     * @param array<string, mixed> $item    Frozen item definition
     * @param array<string, mixed> $payload Submitted response payload
     *
     * @return void
     *
     * @throws RuntimeException On validation failure with the spec error codes.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — branches cover all response types
     */
    public function validateResponse(array $item, array $payload): void
    {
        $type = (string) ($item['responseType'] ?? '');

        if ($type === 'ja_nee_nvt') {
            $value = (string) ($payload['value'] ?? '');
            if (in_array($value, ['ja', 'nee', 'nvt'], true) === false) {
                throw new RuntimeException('INVALID_VALUE');
            }
        }

        if ($type === 'getal' || $type === 'meting') {
            $range = $item['numericRange'] ?? null;
            if (is_array($range) === true && array_key_exists('numericValue', $payload) === true) {
                $val = (float) $payload['numericValue'];
                $min = array_key_exists('min', $range) === true ? (float) $range['min'] : null;
                $max = array_key_exists('max', $range) === true ? (float) $range['max'] : null;
                if (($min !== null && $val < $min) || ($max !== null && $val > $max)) {
                    throw new RuntimeException('OUT_OF_RANGE');
                }
            }
        }

        if ($type === 'meerkeuze') {
            $choices = $item['choices'] ?? [];
            $choice  = (string) ($payload['choice'] ?? ($payload['value'] ?? ''));
            if (is_array($choices) === true && in_array($choice, $choices, true) === false) {
                throw new RuntimeException('INVALID_CHOICE');
            }
        }

        if ($type === 'tekst') {
            $comment = (string) ($payload['value'] ?? ($payload['comment'] ?? ''));
            if (strlen($comment) > 2000) {
                throw new RuntimeException('TEXT_TOO_LONG');
            }
        }

        if ($type === 'foto') {
            $photos = $payload['photos'] ?? [];
            if (is_array($photos) === false || count($photos) < 1) {
                throw new RuntimeException('PHOTO_REQUIRED');
            }
        }

        $fotoGate = (string) ($item['fotoRequired'] ?? 'nooit');
        $photos   = $payload['photos'] ?? [];
        $hasPhoto = is_array($photos) === true && count($photos) >= 1;
        $value    = (string) ($payload['value'] ?? '');

        if ($fotoGate === 'altijd' && $hasPhoto === false) {
            throw new RuntimeException('PHOTO_REQUIRED');
        }

        if ($fotoGate === 'bij_nee' && $value === 'nee' && $hasPhoto === false) {
            throw new RuntimeException('PHOTO_REQUIRED');
        }
    }//end validateResponse()

    /**
     * Dispatch follow-up actions for failed items per REQ-IC-7.
     *
     * Creates one task per failed item whose failureAction.type !== geen.
     * handhavingstaak hands off to the enforcement-lhs handhavingsactie schema
     * (currently invoked inline; a dedicated EnforcementRecommendationService
     * is the long-term target).
     *
     * @param array<string, mixed> $run The submitted run
     *
     * @return array<int, array<string, mixed>> Tasks created
     */
    public function dispatchFollowUps(array $run): array
    {
        $responses = $run['responses'] ?? [];
        $snapshot  = $run['templateSnapshot'] ?? [];
        if (is_array($responses) === false || is_array($snapshot) === false) {
            return [];
        }

        $items = $this->indexItemsBySnapshot(snapshot: $snapshot);
        [$objectService, $register] = $this->bootstrap();
        $taskSchema = (string) $this->settingsService->getConfigValue('task_schema');

        $created     = [];
        $runId       = (string) ($run['id'] ?? '');
        $caseId      = (string) ($run['case'] ?? '');
        $submittedAt = (string) ($run['submittedAt'] ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM));

        foreach ($responses as $response) {
            if (is_array($response) === false) {
                continue;
            }

            $itemId  = (string) ($response['itemId'] ?? '');
            $item    = $items[$itemId] ?? null;
            $verdict = $this->classifyResponse(response: $response, item: $item);
            if ($verdict !== 'fail' || $item === null) {
                continue;
            }

            $action = $item['failureAction'] ?? null;
            if (is_array($action) === false) {
                continue;
            }

            $actionType = (string) ($action['type'] ?? self::FOLLOWUP_GEEN);
            if ($actionType === self::FOLLOWUP_GEEN || $actionType === '') {
                continue;
            }

            $deadlineDays = (int) ($action['deadlineDays'] ?? 0);
            $deadline     = null;
            if ($deadlineDays > 0) {
                $deadline = (new DateTimeImmutable($submittedAt))
                    ->modify('+'.$deadlineDays.' days')
                    ->format(DateTimeInterface::ATOM);
            }

            $task = [
                'case'         => $caseId,
                'title'        => $this->describeFollowUp(type: $actionType, item: $item),
                'description'  => 'Follow-up automatically created from inspection checklist run',
                'sourceRun'    => $runId,
                'sourceItem'   => $itemId,
                'followUpType' => $actionType,
            ];

            if ($deadline !== null) {
                $task['deadline'] = $deadline;
            }

            if ($actionType === self::FOLLOWUP_HANDHAVINGSTAAK) {
                $this->createHandhavingsactie(
                    objectService: $objectService,
                    register: $register,
                    caseId: $caseId,
                    runId: $runId,
                    itemId: $itemId
                );
            }

            if ($taskSchema !== '') {
                try {
                    $persisted = $this->toArray(value: $objectService->saveObject($register, $taskSchema, $task));
                    $created[] = $persisted;
                } catch (Throwable $e) {
                    $this->logger->debug(
                        'Procest: follow-up task save failed: '.$e->getMessage(),
                    );
                }
            } else {
                $created[] = $task;
            }
        }//end foreach

        return $created;
    }//end dispatchFollowUps()

    /**
     * Hand off to the enforcement-lhs recommendation surface.
     *
     * Until a dedicated EnforcementRecommendationService is built, this
     * creates a handhavingsactie object with neutral defaults; the LHS
     * matrix is filled in by the handhaving handler once the case is
     * picked up.
     *
     * @param object $objectService OpenRegister object service handle
     * @param string $register      Procest register slug
     * @param string $caseId        Parent case UUID
     * @param string $runId         Source run UUID
     * @param string $itemId        Source item id
     *
     * @return void
     */
    private function createHandhavingsactie(
        object $objectService,
        string $register,
        string $caseId,
        string $runId,
        string $itemId,
    ): void {
        $schema = (string) $this->settingsService->getConfigValue('handhavingsactie_schema');
        if ($schema === '') {
            return;
        }

        $payload = [
            'case'        => $caseId,
            'type'        => 'waarschuwing',
            'ernst'       => 'aanzienlijk',
            'gedrag'      => 'onverschillig',
            'interventie' => 'Suggested by inspection checklist run '.$runId.' item '.$itemId,
        ];

        try {
            $objectService->saveObject($register, $schema, $payload);
        } catch (Throwable $e) {
            $this->logger->debug(
                'Procest: handhavingsactie save failed for run '.$runId.': '.$e->getMessage(),
            );
        }
    }//end createHandhavingsactie()

    /**
     * Reject any write to a run that has already been submitted (REQ-IC-8).
     *
     * @param array<string, mixed> $run The current run
     *
     * @return void
     *
     * @throws RuntimeException With message "Checklist run is append-only".
     */
    public function assertRunMutable(array $run): void
    {
        $status = (string) ($run['status'] ?? '');
        if ($status === self::STATUS_INGEDIEND || $status === self::STATUS_GEARCHIVEERD) {
            throw new RuntimeException('Checklist run is append-only');
        }
    }//end assertRunMutable()

    /**
     * Classify a single response: pass | fail | skip.
     *
     * @param array<string, mixed>      $response Submitted response
     * @param array<string, mixed>|null $item     Frozen item definition
     *
     * @return string
     */
    private function classifyResponse(array $response, ?array $item): string
    {
        if ($item === null) {
            return 'pass';
        }

        $type  = (string) ($item['responseType'] ?? '');
        $value = (string) ($response['value'] ?? '');

        if ($type === 'ja_nee_nvt') {
            if ($value === 'nvt') {
                return 'skip';
            }

            if ($value === 'nee') {
                return 'fail';
            }

            return 'pass';
        }

        if ($type === 'getal' || $type === 'meting') {
            $range = $item['numericRange'] ?? null;
            if (is_array($range) === false || array_key_exists('numericValue', $response) === false) {
                return 'pass';
            }

            $val = (float) $response['numericValue'];
            $min = array_key_exists('min', $range) === true ? (float) $range['min'] : null;
            $max = array_key_exists('max', $range) === true ? (float) $range['max'] : null;
            if (($min !== null && $val < $min) || ($max !== null && $val > $max)) {
                return 'fail';
            }

            return 'pass';
        }

        if ($type === 'meerkeuze') {
            $choices = $item['choices'] ?? [];
            $choice  = (string) ($response['choice'] ?? $value);
            if (is_array($choices) === true && in_array($choice, $choices, true) === false) {
                return 'fail';
            }

            return 'pass';
        }

        if ($type === 'foto') {
            $photos = $response['photos'] ?? [];
            if (is_array($photos) === false || count($photos) < 1) {
                return 'fail';
            }

            return 'pass';
        }

        return 'pass';
    }//end classifyResponse()

    /**
     * Pick the highest-priority follow-up type across failed items.
     *
     * @param array<int, array<string, mixed>> $responses Responses
     * @param array<string, mixed>             $snapshot  Frozen template snapshot
     *
     * @return string|null
     */
    private function resolvePrimaryFollowUp(array $responses, array $snapshot): ?string
    {
        $priority = [
            self::FOLLOWUP_HANDHAVINGSTAAK  => 3,
            self::FOLLOWUP_HERINSPECTIE     => 2,
            self::FOLLOWUP_DOCUMENT_VERZOEK => 1,
            self::FOLLOWUP_GEEN             => 0,
        ];

        $items  = $this->indexItemsBySnapshot(snapshot: $snapshot);
        $winner = null;
        $best   = -1;

        foreach ($responses as $response) {
            if (is_array($response) === false) {
                continue;
            }

            $itemId = (string) ($response['itemId'] ?? '');
            $item   = $items[$itemId] ?? null;
            if ($item === null) {
                continue;
            }

            if ($this->classifyResponse(response: $response, item: $item) !== 'fail') {
                continue;
            }

            $action = $item['failureAction'] ?? [];
            if (is_array($action) === false) {
                continue;
            }

            $type = (string) ($action['type'] ?? self::FOLLOWUP_GEEN);
            $rank = $priority[$type] ?? 0;
            if ($rank > $best) {
                $best   = $rank;
                $winner = $type;
            }
        }//end foreach

        return $winner;
    }//end resolvePrimaryFollowUp()

    /**
     * Map snapshot.items[]/sections[].items[] into an itemId-keyed lookup.
     *
     * The template can express items[] either flat (legacy) or nested under
     * sections[] — accept both.
     *
     * @param array<string, mixed> $snapshot Frozen template snapshot
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexItemsBySnapshot(array $snapshot): array
    {
        $out = [];

        $collect = static function (array $items) use (&$out): void {
            foreach ($items as $idx => $item) {
                if (is_array($item) === false) {
                    continue;
                }

                $id       = (string) ($item['id'] ?? ($item['order'] ?? (string) $idx));
                $out[$id] = $item;
            }
        };

        if (is_array($snapshot['items'] ?? null) === true) {
            $collect($snapshot['items']);
        }

        $sections = $snapshot['sections'] ?? [];
        if (is_array($sections) === true) {
            foreach ($sections as $section) {
                if (is_array($section) === false) {
                    continue;
                }

                $items = $section['items'] ?? [];
                if (is_array($items) === true) {
                    $collect($items);
                }
            }
        }

        return $out;
    }//end indexItemsBySnapshot()

    /**
     * Compose a follow-up task title.
     *
     * @param string               $type Follow-up type
     * @param array<string, mixed> $item Source item
     *
     * @return string
     */
    private function describeFollowUp(string $type, array $item): string
    {
        $label = (string) ($item['label'] ?? 'inspection finding');

        return match ($type) {
            self::FOLLOWUP_HERINSPECTIE => 'Herinspectie: '.$label,
            self::FOLLOWUP_HANDHAVINGSTAAK => 'Handhavingstaak: '.$label,
            self::FOLLOWUP_DOCUMENT_VERZOEK => 'Documentverzoek: '.$label,
            default => 'Follow-up: '.$label,
        };
    }//end describeFollowUp()

    /**
     * Bootstrap ObjectService + the register slug.
     *
     * @return array{0: object, 1: string}
     *
     * @throws RuntimeException When OpenRegister is unavailable.
     */
    private function bootstrap(): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is niet beschikbaar');
        }

        $register = $this->requireConfig(key: 'register');

        return [$objectService, $register];
    }//end bootstrap()

    /**
     * Resolve a required configuration value.
     *
     * @param string $key The config key
     *
     * @return string
     *
     * @throws RuntimeException When the value is empty.
     */
    private function requireConfig(string $key): string
    {
        $value = $this->settingsService->getConfigValue($key);
        if ($value === '') {
            throw new RuntimeException(sprintf('Procest configuration key %s is not set', $key));
        }

        return $value;
    }//end requireConfig()

    /**
     * Resolve the current user UID, refusing anonymous sessions.
     *
     * @return string
     *
     * @throws RuntimeException When no user is authenticated.
     */
    private function requireUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new RuntimeException('Geen geauthenticeerde gebruiker');
        }

        return $user->getUID();
    }//end requireUserId()

    /**
     * Coerce an ObjectService return value to a plain array.
     *
     * @param mixed $value The raw return
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true) {
            if (method_exists($value, 'jsonSerialize') === true) {
                $serialised = $value->jsonSerialize();
                if (is_array($serialised) === true) {
                    return $serialised;
                }
            }

            if (method_exists($value, 'toArray') === true) {
                $arr = $value->toArray();
                if (is_array($arr) === true) {
                    return $arr;
                }
            }
        }

        return [];
    }//end toArray()
}//end class
