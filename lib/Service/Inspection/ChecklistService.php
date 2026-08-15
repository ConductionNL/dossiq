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
 * @author    Conduction Development Team <info@conduction.nl>
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
class ChecklistService {
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
	public const FOLLOWUP_HERINSPECTIE = 'herinspectie';
	public const FOLLOWUP_HANDHAVINGSTAAK = 'handhavingstaak';
	public const FOLLOWUP_DOCUMENT_VERZOEK = 'documentVerzoek';
	public const FOLLOWUP_GEEN = 'geen';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The Procest settings/config bridge to OpenRegister
	 * @param IUserSession $userSession The current Nextcloud user session
	 * @param LoggerInterface $logger The logger instance
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
	 * @param string $templateId Template UUID
	 * @param string $caseId Parent case UUID
	 * @param string|null $inspectionId Optional parent mobiel-inspectie session id
	 *
	 * @return array<string, mixed> The persisted run
	 *
	 * @throws RuntimeException When configuration is missing, the template
	 *                          cannot be loaded, or persistence fails.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function createRun(string $templateId, string $caseId, ?string $inspectionId = null): array {
		[$objectService, $register] = $this->bootstrap();
		$templateSchema = $this->requireConfig(key: 'inspection_checklist_template_schema');
		$runSchema = $this->requireConfig(key: 'inspection_checklist_run_schema');

		$template = $this->toArray(value: $objectService->find($templateId, register: $register, schema: $templateSchema));
		if ($template === []) {
			throw new RuntimeException('Inspection checklist template not found');
		}

		$version = (int)($template['version'] ?? 1);
		$snapshot = [
			'name' => (string)($template['name'] ?? ''),
			'version' => $version,
			'sections' => $template['sections'] ?? [],
		];

		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		$run = [
			'case' => $caseId,
			'template' => $templateId,
			'templateVersion' => $version,
			'templateSnapshot' => $snapshot,
			'inspector' => $this->requireUserId(),
			'startedAt' => $now,
			'status' => self::STATUS_CONCEPT,
			'responses' => [],
			'syncState' => 'synced',
		];

		if ($inspectionId !== null && $inspectionId !== '') {
			$run['inspection'] = $inspectionId;
		}

		$persisted = $this->toArray(value: $objectService->saveObject(object: $run, register: $register, schema: $runSchema));

		$this->logger->info(
			'Procest: created checklist run {runId} for template {templateId} v{version}',
			[
				'runId' => (string)($persisted['id'] ?? ''),
				'templateId' => $templateId,
				'version' => $version,
			]
		);

		return $persisted;
	}//end createRun()

	/**
	 * Submit a run: validate, derive overallResult, dispatch follow-ups, lock to append-only.
	 *
	 * @param string $runId Run UUID
	 * @param array<string, mixed> $payload {responses?: array, followUpType?: string}
	 *
	 * @return array<string, mixed> The submitted, immutable run
	 *
	 * @throws RuntimeException When the run is already submitted (REQ-IC-8)
	 *                          or persistence fails.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) — branches cover validation + follow-up dispatch
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function submitRun(string $runId, array $payload): array {
		[$objectService, $register] = $this->bootstrap();
		$runSchema = $this->requireConfig(key: 'inspection_checklist_run_schema');

		$run = $this->toArray(value: $objectService->find($runId, register: $register, schema: $runSchema));
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

			$itemId = (string)($response['itemId'] ?? '');
			$item = $itemsByOrder[$itemId] ?? null;
			if ($item !== null) {
				$this->validateResponse(item: $item, payload: $response);
			}

			// Photos live in the OR photos leaf (ADR-022); persist only the
			// leaf references, never an inline photo blob.
			$validResponses[] = $this->stripInlinePhotoBlobs(response: $response);
		}

		$aggregate = $this->aggregateResult(responses: $validResponses, snapshot: $snapshot);

		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

		$run['responses'] = $validResponses;
		$run['status'] = self::STATUS_INGEDIEND;
		$run['submittedAt'] = $now;
		$run['completedAt'] = $now;
		$run['overallResult'] = $aggregate;
		$run['inspector'] = $this->requireUserId();
		$run['syncState'] = 'synced';

		$followUp = $this->resolvePrimaryFollowUp(responses: $validResponses, snapshot: $snapshot);
		if ($followUp !== null) {
			$run['followUpType'] = $followUp;
		}

		$persisted = $this->toArray(value: $objectService->saveObject(object: $run, register: $register, schema: $runSchema));

		try {
			$this->dispatchFollowUps(run: $persisted);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Procest: checklist follow-up dispatch failed for run {runId}: {msg}',
				[
					'runId' => (string)($persisted['id'] ?? ''),
					'msg' => $e->getMessage(),
				]
			);
		}

		return $persisted;
	}//end submitRun()

	/**
	 * Derive overallResult from responses + frozen template snapshot.
	 *
	 * @param array<int, array<string, mixed>> $responses Submitted responses
	 * @param array<string, mixed> $snapshot Frozen template snapshot
	 *
	 * @return string conform | niet_conform | deels_conform
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function aggregateResult(array $responses, array $snapshot): string {
		$itemsByOrder = $this->indexItemsBySnapshot(snapshot: $snapshot);
		$fails = 0;
		$skipped = 0;

		foreach ($responses as $response) {
			$itemId = (string)($response['itemId'] ?? '');
			$item = $itemsByOrder[$itemId] ?? null;
			$verdict = $this->classifyResponse(response: $response, item: $item);

			if ($verdict === 'fail') {
				$fails++;
			} elseif ($verdict === 'skip') {
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
	 * @param array<string, mixed> $item Frozen item definition
	 * @param array<string, mixed> $payload Submitted response payload
	 *
	 * @return void
	 *
	 * @throws RuntimeException On validation failure with the spec error codes.
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function validateResponse(array $item, array $payload): void {
		$type = (string)($item['responseType'] ?? '');

		$this->assertValueMatchesType(type: $type, item: $item, payload: $payload);
		$this->assertPhotoRules(type: $type, item: $item, payload: $payload);
	}//end validateResponse()

	/**
	 * Assert the submitted value satisfies the constraints its response type declares (REQ-IC-3).
	 *
	 * @param string $type Frozen item response type
	 * @param array<string, mixed> $item Frozen item definition
	 * @param array<string, mixed> $payload Submitted response payload
	 *
	 * @return void
	 *
	 * @throws RuntimeException On validation failure with the spec error codes.
	 */
	private function assertValueMatchesType(string $type, array $item, array $payload): void {
		if ($type === 'ja_nee_nvt') {
			$value = (string)($payload['value'] ?? '');
			if (in_array($value, ['ja', 'nee', 'nvt'], true) === false) {
				throw new RuntimeException('INVALID_VALUE');
			}
		}

		if (in_array($type, ['getal', 'meting'], true) === true) {
			if ($this->isNumericOutOfRange(item: $item, data: $payload) === true) {
				throw new RuntimeException('OUT_OF_RANGE');
			}
		}

		if ($type === 'meerkeuze') {
			$choice = (string)($payload['choice'] ?? ($payload['value'] ?? ''));
			if ($this->hasInvalidChoice(item: $item, choice: $choice) === true) {
				throw new RuntimeException('INVALID_CHOICE');
			}
		}

		if ($type === 'tekst') {
			$comment = (string)($payload['value'] ?? ($payload['comment'] ?? ''));
			if (strlen($comment) > 2000) {
				throw new RuntimeException('TEXT_TOO_LONG');
			}
		}
	}//end assertValueMatchesType()

	/**
	 * Assert the photo obligations hold: a `foto` item needs at least one photo, and the item's
	 * `fotoRequired` gate ('altijd' / 'bij_nee') is honoured (REQ-IC-3).
	 *
	 * @param string $type Frozen item response type
	 * @param array<string, mixed> $item Frozen item definition
	 * @param array<string, mixed> $payload Submitted response payload
	 *
	 * @return void
	 *
	 * @throws RuntimeException PHOTO_REQUIRED when a mandated photo is missing.
	 */
	private function assertPhotoRules(string $type, array $item, array $payload): void {
		if ($type === 'foto') {
			if ($this->photoCount(response: $payload) < 1) {
				throw new RuntimeException('PHOTO_REQUIRED');
			}
		}

		if ($this->photoCount(response: $payload) >= 1) {
			return;
		}

		$fotoGate = (string)($item['photoRequired'] ?? 'nooit');
		$value = (string)($payload['value'] ?? '');

		if ($fotoGate === 'altijd') {
			throw new RuntimeException('PHOTO_REQUIRED');
		}

		if ($fotoGate === 'bij_nee' && $value === 'nee') {
			throw new RuntimeException('PHOTO_REQUIRED');
		}
	}//end assertPhotoRules()

	/**
	 * Test whether a numeric response falls outside the item's declared `numericRange`.
	 *
	 * Returns false when the item declares no usable range or the payload carries no numeric
	 * value — an absent range is not a violation.
	 *
	 * @param array<string, mixed> $item Frozen item definition
	 * @param array<string, mixed> $data Submitted response payload
	 *
	 * @return bool True when the numeric value is out of range.
	 */
	private function isNumericOutOfRange(array $item, array $data): bool {
		$range = $item['numericRange'] ?? null;
		if (is_array($range) === false || array_key_exists('numericValue', $data) === false) {
			return false;
		}

		$val = (float)$data['numericValue'];
		$min = null;
		if (array_key_exists('min', $range) === true) {
			$min = (float)$range['min'];
		}

		$max = null;
		if (array_key_exists('max', $range) === true) {
			$max = (float)$range['max'];
		}

		return (($min !== null && $val < $min) || ($max !== null && $val > $max));
	}//end isNumericOutOfRange()

	/**
	 * Test whether a multiple-choice answer is absent from the item's declared `choices`.
	 *
	 * Returns false when the item declares no usable choice list.
	 *
	 * @param array<string, mixed> $item Frozen item definition
	 * @param string $choice The submitted choice
	 *
	 * @return bool True when the choice is not one of the declared options.
	 */
	private function hasInvalidChoice(array $item, string $choice): bool {
		$choices = $item['choices'] ?? [];
		return (is_array($choices) === true && in_array($choice, $choices, true) === false);
	}//end hasInvalidChoice()

	/**
	 * Count the photos attached to a checklist response.
	 *
	 * Inspection photos are stored through OpenRegister's `photos` integration
	 * leaf (files attached to the run/case object) per ADR-022 — the leaf owns
	 * storage, procest owns the photo-gate rule. The gate therefore counts the
	 * leaf-provided photo references (`photoRefs` — file ids / album entries
	 * surfaced by the photos leaf) rather than an inline `photos[]` blob
	 * payload. A legacy inline `photos[]` array is still counted as a
	 * backwards-compat fallback for runs captured before the migration, but
	 * `stripInlinePhotoBlobs()` ensures new submissions never persist one.
	 *
	 * @param array<string, mixed> $response A single checklist response payload.
	 *
	 * @return int Number of photos attached via the photos leaf (or legacy inline).
	 *
	 * @spec openspec/specs/inspection-forms-via-forms-leaf/spec.md
	 */
	private function photoCount(array $response): int {
		$refs = $response['photoRefs'] ?? [];
		if (is_array($refs) === true && count($refs) > 0) {
			return count($refs);
		}

		// Backwards-compat: legacy runs captured an inline `photos[]` blob.
		$inline = $response['photos'] ?? [];
		if (is_array($inline) === true) {
			return count($inline);
		}

		return 0;
	}//end photoCount()

	/**
	 * Strip inline photo blob payloads from a response, retaining only the
	 * photos-leaf references.
	 *
	 * Per `inspection-forms-via-forms-leaf`, new submissions SHALL NOT persist
	 * an inline `photos[]` payload into the checklist item — photos live in the
	 * photos leaf and the response carries only their `photoRefs`. Any inline
	 * `photos[]` is dropped on write while the leaf reference list is kept.
	 *
	 * @param array<string, mixed> $response A single checklist response payload.
	 *
	 * @return array<string, mixed> The response without an inline photo blob.
	 *
	 * @spec openspec/specs/inspection-forms-via-forms-leaf/spec.md
	 */
	private function stripInlinePhotoBlobs(array $response): array {
		unset($response['photos']);
		return $response;
	}//end stripInlinePhotoBlobs()

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
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function dispatchFollowUps(array $run): array {
		$responses = $run['responses'] ?? [];
		$snapshot = $run['templateSnapshot'] ?? [];
		if (is_array($responses) === false || is_array($snapshot) === false) {
			return [];
		}

		$items = $this->indexItemsBySnapshot(snapshot: $snapshot);
		[$objectService, $register] = $this->bootstrap();
		$taskSchema = (string)$this->settingsService->getConfigValue('task_schema');

		$created = [];
		$runId = (string)($run['id'] ?? '');
		$caseId = (string)($run['case'] ?? '');
		$submittedAt = (string)($run['submittedAt'] ?? (new DateTimeImmutable())->format(DateTimeInterface::ATOM));

		foreach ($responses as $response) {
			if (is_array($response) === false) {
				continue;
			}

			$itemId = (string)($response['itemId'] ?? '');
			$item = $items[$itemId] ?? null;
			$actionType = $this->resolveFollowUpType(response: $response, item: $item);
			if ($actionType === null || $item === null) {
				continue;
			}

			$task = $this->buildFollowUpTask(
				item: $item,
				itemId: $itemId,
				actionType: $actionType,
				caseId: $caseId,
				runId: $runId,
				submittedAt: $submittedAt,
			);

			if ($actionType === self::FOLLOWUP_HANDHAVINGSTAAK) {
				$this->createHandhavingsactie(
					objectService: $objectService,
					register: $register,
					caseId: $caseId,
					runId: $runId,
					itemId: $itemId
				);
			}

			$persisted = $this->persistFollowUpTask(
				objectService: $objectService,
				register: $register,
				schema: $taskSchema,
				task: $task,
			);
			if ($persisted !== null) {
				$created[] = $persisted;
			}
		}//end foreach

		return $created;
	}//end dispatchFollowUps()

	/**
	 * Resolve the follow-up action type a failed response demands, or null when the response does
	 * not fail, carries no item, or declares no actionable failureAction (REQ-IC-7).
	 *
	 * @param array<string, mixed> $response Submitted response
	 * @param array<string, mixed>|null $item Frozen item definition
	 *
	 * @return string|null The follow-up type, or null when nothing is due.
	 */
	private function resolveFollowUpType(array $response, ?array $item): ?string {
		$verdict = $this->classifyResponse(response: $response, item: $item);
		if ($verdict !== 'fail' || $item === null) {
			return null;
		}

		$action = $item['failureAction'] ?? null;
		if (is_array($action) === false) {
			return null;
		}

		$actionType = (string)($action['type'] ?? self::FOLLOWUP_GEEN);
		if ($actionType === self::FOLLOWUP_GEEN || $actionType === '') {
			return null;
		}

		return $actionType;
	}//end resolveFollowUpType()

	/**
	 * Build the follow-up task payload for one failed item, stamping the deadline derived from the
	 * item's `failureAction.deadlineDays` when it declares one.
	 *
	 * @param array<string, mixed> $item Frozen item definition
	 * @param string $itemId Source item id
	 * @param string $actionType Resolved follow-up type
	 * @param string $caseId Parent case UUID
	 * @param string $runId Source run UUID
	 * @param string $submittedAt Run submission timestamp (ATOM)
	 *
	 * @return array<string, mixed> The task payload.
	 */
	private function buildFollowUpTask(array $item, string $itemId, string $actionType, string $caseId, string $runId, string $submittedAt): array {
		$task = [
			'case' => $caseId,
			'title' => $this->describeFollowUp(type: $actionType, item: $item),
			'description' => 'Follow-up automatically created from inspection checklist run',
			'sourceRun' => $runId,
			'sourceItem' => $itemId,
			'followUpType' => $actionType,
		];

		$deadlineDays = (int)(($item['failureAction']['deadlineDays']) ?? 0);
		if ($deadlineDays > 0) {
			$task['deadline'] = (new DateTimeImmutable($submittedAt))
				->modify('+' . $deadlineDays . ' days')
				->format(DateTimeInterface::ATOM);
		}

		return $task;
	}//end buildFollowUpTask()

	/**
	 * Persist a follow-up task, returning the row to record in the created list. Returns the
	 * unsaved payload when no task schema is configured, and null when the save failed.
	 *
	 * @param object $objectService OpenRegister object service handle
	 * @param string $register Procest register slug
	 * @param string $schema Task schema slug ('' when unconfigured)
	 * @param array<string, mixed> $task The task payload
	 *
	 * @return array<string, mixed>|null The row to record, or null when the save failed.
	 */
	private function persistFollowUpTask(object $objectService, string $register, string $schema, array $task): ?array {
		if ($schema === '') {
			return $task;
		}

		try {
			return $this->toArray(value: $objectService->saveObject(object: $task, register: $register, schema: $schema));
		} catch (Throwable $e) {
			$this->logger->debug(
				'Procest: follow-up task save failed: ' . $e->getMessage(),
			);
			return null;
		}
	}//end persistFollowUpTask()

	/**
	 * Hand off to the enforcement-lhs recommendation surface.
	 *
	 * Until a dedicated EnforcementRecommendationService is built, this
	 * creates a handhavingsactie object with neutral defaults; the LHS
	 * matrix is filled in by the handhaving handler once the case is
	 * picked up.
	 *
	 * @param object $objectService OpenRegister object service handle
	 * @param string $register Procest register slug
	 * @param string $caseId Parent case UUID
	 * @param string $runId Source run UUID
	 * @param string $itemId Source item id
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
		$schema = (string)$this->settingsService->getConfigValue('handhavingsactie_schema');
		if ($schema === '') {
			return;
		}

		$payload = [
			'case' => $caseId,
			'type' => 'waarschuwing',
			'severity' => 'aanzienlijk',
			'behaviour' => 'onverschillig',
			'intervention' => 'Suggested by inspection checklist run ' . $runId . ' item ' . $itemId,
		];

		try {
			$objectService->saveObject(object: $payload, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Procest: handhavingsactie save failed for run ' . $runId . ': ' . $e->getMessage(),
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
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function assertRunMutable(array $run): void {
		$status = (string)($run['status'] ?? '');
		if ($status === self::STATUS_INGEDIEND || $status === self::STATUS_GEARCHIVEERD) {
			throw new RuntimeException('Checklist run is append-only');
		}
	}//end assertRunMutable()

	/**
	 * Classify a single response: pass | fail | skip.
	 *
	 * @param array<string, mixed> $response Submitted response
	 * @param array<string, mixed>|null $item Frozen item definition
	 *
	 * @return string
	 */
	private function classifyResponse(array $response, ?array $item): string {
		if ($item === null) {
			return 'pass';
		}

		$type = (string)($item['responseType'] ?? '');
		$value = (string)($response['value'] ?? '');

		if ($type === 'ja_nee_nvt') {
			return $this->classifyJaNeeNvt(value: $value);
		}

		if ($this->hasFailingValue(type: $type, item: $item, response: $response, value: $value) === true) {
			return 'fail';
		}

		return 'pass';
	}//end classifyResponse()

	/**
	 * Classify a ja/nee/nvt answer: 'nvt' skips, 'nee' fails, anything else passes.
	 *
	 * @param string $value The submitted value
	 *
	 * @return string
	 */
	private function classifyJaNeeNvt(string $value): string {
		if ($value === 'nvt') {
			return 'skip';
		}

		if ($value === 'nee') {
			return 'fail';
		}

		return 'pass';
	}//end classifyJaNeeNvt()

	/**
	 * Test whether a response violates the constraint its response type declares. Response types
	 * without a constraint (and `ja_nee_nvt`, which the caller classifies separately) never fail.
	 *
	 * @param string $type Frozen item response type
	 * @param array<string, mixed> $item Frozen item definition
	 * @param array<string, mixed> $response Submitted response
	 * @param string $value The submitted plain value
	 *
	 * @return bool True when the response fails its item constraint.
	 */
	private function hasFailingValue(string $type, array $item, array $response, string $value): bool {
		if (in_array($type, ['getal', 'meting'], true) === true) {
			return $this->isNumericOutOfRange(item: $item, data: $response);
		}

		if ($type === 'meerkeuze') {
			return $this->hasInvalidChoice(item: $item, choice: (string)($response['choice'] ?? $value));
		}

		if ($type === 'foto') {
			return ($this->photoCount(response: $response) < 1);
		}

		return false;
	}//end hasFailingValue()

	/**
	 * Pick the highest-priority follow-up type across failed items.
	 *
	 * @param array<int, array<string, mixed>> $responses Responses
	 * @param array<string, mixed> $snapshot Frozen template snapshot
	 *
	 * @return string|null
	 */
	private function resolvePrimaryFollowUp(array $responses, array $snapshot): ?string {
		$priority = [
			self::FOLLOWUP_HANDHAVINGSTAAK => 3,
			self::FOLLOWUP_HERINSPECTIE => 2,
			self::FOLLOWUP_DOCUMENT_VERZOEK => 1,
			self::FOLLOWUP_GEEN => 0,
		];

		$items = $this->indexItemsBySnapshot(snapshot: $snapshot);
		$winner = null;
		$best = -1;

		foreach ($responses as $response) {
			$itemId = (string)($response['itemId'] ?? '');
			$item = $items[$itemId] ?? null;
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

			$type = (string)($action['type'] ?? self::FOLLOWUP_GEEN);
			$rank = $priority[$type] ?? 0;
			if ($rank > $best) {
				$best = $rank;
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
	private function indexItemsBySnapshot(array $snapshot): array {
		$out = [];

		$collect = static function (array $items) use (&$out): void {
			foreach ($items as $idx => $item) {
				if (is_array($item) === false) {
					continue;
				}

				$id = (string)($item['id'] ?? ($item['order'] ?? (string)$idx));
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
	 * @param string $type Follow-up type
	 * @param array<string, mixed> $item Source item
	 *
	 * @return string
	 */
	private function describeFollowUp(string $type, array $item): string {
		$label = (string)($item['label'] ?? 'inspection finding');

		return match ($type) {
			self::FOLLOWUP_HERINSPECTIE => 'Herinspectie: ' . $label,
			self::FOLLOWUP_HANDHAVINGSTAAK => 'Handhavingstaak: ' . $label,
			self::FOLLOWUP_DOCUMENT_VERZOEK => 'Documentverzoek: ' . $label,
			default => 'Follow-up: ' . $label,
		};
	}//end describeFollowUp()

	/**
	 * Bootstrap ObjectService + the register slug.
	 *
	 * @return array{0: object, 1: string}
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	private function bootstrap(): array {
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
	private function requireConfig(string $key): string {
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
	private function requireUserId(): string {
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
	private function toArray(mixed $value): array {
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
