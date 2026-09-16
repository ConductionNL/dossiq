<?php

/**
 * Dossiq Consultation Service
 *
 * Service for managing inter-departmental consultations (adviesaanvragen).
 * Consultations are first-class entities linked to parent cases with their
 * own lifecycle, document exchange, and structured responses per Awb 3:5-3:9.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph;
use OCA\Dossiq\Service\Consultation\ConsultationRepository;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for consultation (adviesaanvraag) management.
 *
 * Handles the full consultation lifecycle: creation with auto-generated numbers,
 * status transitions, advice responses, deadline extensions, and dependency
 * cycle detection per Awb 3:5-3:9.
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 */
class ConsultationService {

	use SearchesObjects;

	/**
	 * Valid consultation statuses.
	 */
	private const VALID_STATUSES = [
		'open',
		'received',
		'in_handling',
		'advice_uitgebracht',
		'closed',
		'withdrawn',
	];

	/**
	 * Valid advice response types.
	 */
	private const VALID_RESPONSES = [
		'positive',
		'positief_with_terms',
		'negative',
		'non_from_application',
	];

	/**
	 * Allowed status transitions (from => [allowed-to, ...]).
	 */
	private const STATUS_TRANSITIONS = [
		'open' => ['received', 'withdrawn'],
		'received' => ['in_handling', 'withdrawn'],
		'in_handling' => ['advice_uitgebracht', 'withdrawn'],
		'advice_uitgebracht' => ['closed'],
		'closed' => [],
		'withdrawn' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 * @param AdviceDelegationService $adviceDelegation Advice delegation to decidesk (ADR-019)
	 * @param ConsultationRepository $repository OpenRegister reads/writes for consultations
	 * @param ConsultationDependencyGraph $dependencyGraph `dependsOn` cycle detection
	 * @param CaseDateNormaliser $dates The one date write path.
	 * @param ObligationService $obligations The one mechanism a case waits on.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly AdviceDelegationService $adviceDelegation,
		private readonly ConsultationRepository $repository,
		private readonly ConsultationDependencyGraph $dependencyGraph,
		private readonly CaseDateNormaliser $dates,
		private readonly ObligationService $obligations,
	) {
	}//end __construct()

	/**
	 * Create a consultation linked to a parent case.
	 *
	 * Generates a unique consultation number in the format ADV-{year}-{seq}.
	 *
	 * @param array<string, mixed> $data Consultation data
	 *
	 * @return array<string, mixed> Created consultation with ID and number
	 *
	 * @throws \RuntimeException If OpenRegister unavailable, required fields missing, or decidesk fails closed
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function createConsultation(array $data): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Consultation schema not configured');
		}

		// Validate required fields.
		$this->assertRequiredConsultationFields(data: $data);

		// Generate unique consultation number.
		$data['consultationNumber'] = $this->repository->nextConsultationNumber(
			objectService: $objectService,
			register: $register,
			schema: $schema,
		);

		// Set defaults.
		$data['status'] = 'open';
		$data['createdAt'] = $this->dates->nowAsMoment();

		$consultation = $objectService->saveObject(object: $data, register: $register, schema: $schema);

		$consultationId = ($data['id'] ?? '');
		if (is_object($consultation) === true) {
			$consultationId = $consultation->getUuid();
		}

		// REQ-PDRD-001 / REQ-PDRD-002: a consultatie is an advice request that
		// is *decided* in decidesk. Raise a decidesk `advice` Decision and
		// persist its ref. Fail CLOSED — never author the consultation advice
		// outcome locally as a fallback.
		$decisionRef = $this->raiseAndPersistAdviceDecision(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			consultationId: (string)$consultationId,
			data: $data,
		);

		// 🔑 THE ADVICE REQUEST IS THE FIRST OBLIGATION, NOT A SPECIAL CASE.
		// Everything this service knew about blocking a case lived in
		// `getBlockingConsultations()`: the right behaviour written inside the
		// one thing that happened to need it. Placing an obligation here is
		// what makes the second kind, a fee, an inspection, an external
		// approval, a line of configuration rather than a class. Only a
		// MANDATORY consultation places one, because only a mandatory one ever
		// blocked.
		$this->placeAdviceObligation(consultationId: (string)$consultationId, data: $data);

		$this->logger->info(
			'Consultation created: ' . $consultationId
			. ' (' . $data['consultationNumber'] . ') for case ' . $data['parentCase'],
			['app' => Application::APP_ID],
		);

		return [
			'id' => $consultationId,
			'consultationNumber' => $data['consultationNumber'],
			'status' => 'open',
			'decisionRef' => $decisionRef,
		];
	}//end createConsultation()

	/**
	 * Get all consultations for a case.
	 *
	 * @param string $caseId The parent case UUID
	 *
	 * @return array<int, array<string, mixed>> List of consultations
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function getConsultationsForCase(string $caseId): array {
		return $this->repository->getConsultationsForCase(caseId: $caseId);
	}//end getConsultationsForCase()

	/**
	 * Get a single consultation by ID.
	 *
	 * @param string $consultationId The consultation UUID
	 *
	 * @return array<string, mixed>|null The consultation data or null if not found
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function getConsultation(string $consultationId): ?array {
		return $this->repository->getConsultation(consultationId: $consultationId);
	}//end getConsultation()

	/**
	 * Update consultation status with transition validation.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param string $newStatus The new status
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If invalid status, invalid transition, or OpenRegister unavailable
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function updateStatus(string $consultationId, string $newStatus): array {
		if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
			throw new RuntimeException('Invalid status: ' . $newStatus);
		}

		// Transition validation against the declared status graph. Only a
		// recognised current status constrains the move; a consultation whose
		// stored status is absent or unknown may be set to any valid status so
		// the graph never wedges an object that predates it.
		$consultation = $this->getConsultation(consultationId: $consultationId);
		$current = (string)($consultation['status'] ?? '');
		if (array_key_exists($current, self::STATUS_TRANSITIONS) === true
			&& in_array($newStatus, self::STATUS_TRANSITIONS[$current], true) === false
		) {
			throw new RuntimeException('Invalid status transition: ' . $current . ' -> ' . $newStatus);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		$updateData = ['status' => $newStatus];
		if ($newStatus === 'closed') {
			$updateData['closedAt'] = $this->dates->nowAsMoment();
		}

		$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: (string)$consultationId, changes: $updateData);

		// Meeting the obligation is what RELEASES the case, and it happens on
		// the same act that answers the advice request rather than on a sweep
		// that would notice later. A status this service treats as still
		// blocking leaves the obligation exactly as it is.
		$this->settleAdviceObligation(consultationId: (string)$consultationId, newStatus: $newStatus);

		$this->logger->info(
			'Consultation ' . $consultationId . ' status updated to ' . $newStatus,
			['app' => Application::APP_ID],
		);

		return [
			'id' => $consultationId,
			'status' => $newStatus,
		];
	}//end updateStatus()

	/**
	 * Submit advice response to a consultation.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param array<string, mixed> $response Response data (advies, toelichting, voorwaarden)
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If invalid response type or OpenRegister unavailable
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function submitResponse(string $consultationId, array $response): array {
		$advies = $response['advice'] ?? '';
		if (in_array($advies, self::VALID_RESPONSES, true) === false) {
			throw new RuntimeException('Invalid advice type: ' . $advies);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		$updateData = [
			'advice' => $advies,
			'notes' => $response['notes'] ?? '',
			'adviesDatum' => $this->dates->todayAsCalendarDate(),
			'status' => 'advice_uitgebracht',
		];

		if (isset($response['terms']) === true) {
			$updateData['terms'] = $response['terms'];
		}

		$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: (string)$consultationId, changes: $updateData);

		$this->logger->info(
			'Consultation ' . $consultationId . ' advice submitted: ' . $advies,
			['app' => Application::APP_ID],
		);

		return [
			'id' => $consultationId,
			'advice' => $advies,
			'status' => 'advice_uitgebracht',
		];
	}//end submitResponse()

	/**
	 * Delete a consultation by ID.
	 *
	 * @param string $consultationId The consultation UUID
	 *
	 * @return bool True on success
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function deleteConsultation(string $consultationId): bool {
		return $this->repository->deleteConsultation(consultationId: $consultationId);
	}//end deleteConsultation()

	/**
	 * Get overdue consultations (past deadline with open/in_behandeling status).
	 *
	 * @return array<int, array<string, mixed>> List of overdue consultations
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function getOverdueConsultations(): array {
		return $this->repository->getOverdueConsultations();
	}//end getOverdueConsultations()

	/**
	 * Get mandatory consultations that are blocking case progression.
	 *
	 * Returns consultations where mandatory=true and status is neither
	 * advies_uitgebracht nor afgesloten.
	 *
	 * @param string $caseId The parent case UUID
	 *
	 * @return array<int, array<string, mixed>> Blocking consultations
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function getBlockingConsultations(string $caseId): array {
		$all = $this->getConsultationsForCase(caseId: $caseId);
		$blocking = [];

		foreach ($all as $consultation) {
			if ($this->consultationBlocks(consultation: $consultation) === true) {
				$blocking[] = $consultation;
			}
		}

		return $blocking;
	}//end getBlockingConsultations()

	/**
	 * Whether one consultation is holding its case.
	 *
	 * Unchanged from the day this service owned the whole rule: a MANDATORY
	 * request that has neither been answered nor closed. It is a method rather
	 * than a loop body because the obligation mirror has to ask exactly the
	 * same question, and two spellings of "is this still blocking" is how the
	 * two answers start disagreeing about the same advice request.
	 *
	 * @param array<string, mixed> $consultation The consultation row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	public function consultationBlocks(array $consultation): bool {
		if (($consultation['mandatory'] ?? false) !== true) {
			return false;
		}

		$status = (string)($consultation['status'] ?? '');

		return in_array($status, ['advice_uitgebracht', 'closed', 'withdrawn'], true) === false;
	}//end consultationBlocks()

	/**
	 * Settle the obligation behind an advice request that has been answered.
	 *
	 * `withdrawn` is a withdrawal and everything else terminal is a
	 * settlement, and the two are never recorded as each other: an advice
	 * request nobody answered is not advice that was given. Six months later
	 * that difference is the whole record.
	 *
	 * @param string $consultationId The consultation.
	 * @param string $newStatus      The status it just moved to.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function settleAdviceObligation(string $consultationId, string $newStatus): void {
		if ($this->consultationBlocks(consultation: ['mandatory' => true, 'status' => $newStatus]) === true) {
			return;
		}

		$obligationId = $this->obligations->idForSource(source: $consultationId);
		if ($obligationId === '') {
			return;
		}

		if ($newStatus === 'withdrawn') {
			$this->obligations->withdraw(
				obligationId: $obligationId,
				reason: 'The advice request was withdrawn.',
			);

			return;
		}

		$this->obligations->meet(
			obligationId: $obligationId,
			note: 'The advice request reached ' . $newStatus . '.',
		);
	}//end settleAdviceObligation()

	/**
	 * Place the obligation that mirrors a mandatory advice request.
	 *
	 * The consultation stays the surface a handler works with, and the
	 * obligation is what the transition engine reads. One of them has to be
	 * the record of "is this case still waiting", and it is the obligation,
	 * because a fee and an inspection will never be consultations.
	 *
	 * A failure to place it does NOT fail the consultation. The advice request
	 * exists and the department has been asked; a case that could not be
	 * created because its bookkeeping row refused would be the worse outcome,
	 * and `ObligationService` logs what went wrong.
	 *
	 * @param string               $consultationId The consultation just created.
	 * @param array<string, mixed> $data           The consultation payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/consultation-management/spec.md
	 */
	private function placeAdviceObligation(string $consultationId, array $data): void {
		if (($data['mandatory'] ?? false) !== true) {
			return;
		}

		$this->obligations->place(
			obligation: [
				'case' => (string)($data['parentCase'] ?? ''),
				'kind' => ObligationService::KIND_ADVICE,
				'title' => (string)($data['subject'] ?? 'Advice request'),
				'placedOn' => (string)($data['assignee'] ?? ''),
				'placedOnGroup' => (string)($data['adviceAuthority'] ?? ''),
				'dueAt' => substr((string)($data['latestResponseDate'] ?? ''), 0, 10),
				'source' => $consultationId,
			],
		);
	}//end placeAdviceObligation()

	/**
	 * Validate that adding the given dependsOn list would not create a dependency cycle.
	 *
	 * Uses depth-first traversal to detect cycles. Returns true if a cycle is
	 * detected, false if the dependency graph remains acyclic.
	 *
	 * @param string $consultationId The consultation being updated
	 * @param string[] $dependsOn The proposed dependency IDs
	 *
	 * @return bool True if a cycle would be created
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function validateDependencyCycle(string $consultationId, array $dependsOn): bool {
		return $this->dependencyGraph->wouldCreateCycle(
			consultationId: $consultationId,
			dependsOn: $dependsOn
		);
	}//end validateDependencyCycle()

	/**
	 * Request a deadline extension for a consultation.
	 *
	 * Records the extension request timestamp and justification.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param string $justification The justification for the extension request
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function requestExtension(string $consultationId, string $justification): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		$updateData = [
			'extensionRequestedAt' => $this->dates->nowAsMoment(),
			'extensionJustification' => $justification,
			'extensionApproved' => false,
		];

		$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: (string)$consultationId, changes: $updateData);

		$this->logger->info(
			'Extension requested for consultation ' . $consultationId,
			['app' => Application::APP_ID],
		);

		return [
			'id' => $consultationId,
			'extensionRequestedAt' => $updateData['extensionRequestedAt'],
			'extensionJustification' => $justification,
		];
	}//end requestExtension()

	/**
	 * Approve a deadline extension and update the deadline.
	 *
	 * @param string $consultationId The consultation UUID
	 * @param string $newDeadline The new deadline date (Y-m-d format)
	 *
	 * @return array<string, mixed> Updated consultation summary
	 *
	 * @throws \RuntimeException If OpenRegister is unavailable or date format invalid
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function approveExtension(string $consultationId, string $newDeadline): array {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDeadline) !== 1) {
			throw new RuntimeException('Invalid date format; expected Y-m-d');
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		$updateData = [
			'latestResponseDate' => $newDeadline,
			'extensionApproved' => true,
		];

		$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: (string)$consultationId, changes: $updateData);

		$this->logger->info(
			'Extension approved for consultation ' . $consultationId . ', new deadline: ' . $newDeadline,
			['app' => Application::APP_ID],
		);

		return [
			'id' => $consultationId,
			'latestResponseDate' => $newDeadline,
			'extensionApproved' => true,
		];
	}//end approveExtension()

	/**
	 * Assert that every field required to create a consultation is present.
	 *
	 * @param array<string, mixed> $data Consultation data to validate
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If any required field is missing or empty
	 */
	private function assertRequiredConsultationFields(array $data): void {
		if (empty($data['parentCase']) === true) {
			throw new RuntimeException('parentZaak is required');
		}

		if (empty($data['adviceAuthority']) === true) {
			throw new RuntimeException('adviesInstantie is required');
		}

		if (empty($data['questionFormulation']) === true) {
			throw new RuntimeException('vraagstelling is required');
		}

		if (empty($data['latestResponseDate']) === true) {
			throw new RuntimeException('uiterlijkeReactiedatum is required');
		}
	}//end assertRequiredConsultationFields()

	/**
	 * Raise the decidesk advice Decision for a consultation and persist its ref.
	 *
	 * Fails CLOSED — never authors the consultation advice outcome locally.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 * @param string $consultationId The freshly created consultation UUID
	 * @param array<string, mixed> $data Consultation data used to build the decision payload
	 *
	 * @return string The decidesk decision reference
	 *
	 * @throws \RuntimeException If decidesk is unavailable (REQ-PDRD-002)
	 */
	private function raiseAndPersistAdviceDecision(
		object $objectService,
		string $register,
		string $schema,
		string $consultationId,
		array $data,
	): string {
		try {
			$decisionRef = $this->adviceDelegation->raiseAdviceDecision(
				subjectSchema: 'consultation',
				subjectId: $consultationId,
				payload: [
					'subjectRegister' => $register,
					'externalReference' => (string)$data['parentCase'],
					'subjectLabel' => (string)$data['consultationNumber'],
					'question' => (string)$data['questionFormulation'],
				],
			);

			if ($consultationId !== '') {
				$this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					id: $consultationId,
					changes: ['decisionRef' => $decisionRef],
				);
			}
		} catch (\RuntimeException $e) {
			$this->logger->error(
				'Dossiq: createConsultation: decidesk advice Decision raise failed — failing closed: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			// REQ-PDRD-002: fail closed; surface the error.
			throw new RuntimeException('Decision service unavailable: ' . $e->getMessage(), 0, $e);
		}//end try

		return $decisionRef;
	}//end raiseAndPersistAdviceDecision()

	/**
	 * Find a consultation by its secure token (for external body public access).
	 *
	 * Returns null when the token is invalid, the consultation is not found,
	 * or the consultation is in a terminal status (afgesloten / ingetrokken).
	 *
	 * @param string $token The 64-character hex secure token
	 *
	 * @return array<string, mixed>|null Consultation data or null
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function findBySecureToken(string $token): ?array {
		return $this->repository->findBySecureToken(token: $token);
	}//end findBySecureToken()
}//end class
