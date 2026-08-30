<?php

/**
 * Dossiq consultation repository.
 *
 * Every OpenRegister read, delete and query the consultation surface performs:
 * listing a case's consultations, fetching one, resolving the public secure
 * token, finding overdue ones, allocating the next ADV-{year}-{seq} number,
 * and deleting.
 *
 * Split out of ConsultationService so that service keeps only the Awb 3:5-3:9
 * lifecycle — the status graph, the advice-response rules, the deadline
 * extension rules and the fail-closed decidesk delegation — while the
 * register/schema resolution and query shapes live here.
 *
 * The two return conventions are deliberate and differ by caller expectation:
 * a *query* degrades to an empty array / null when OpenRegister or the schema
 * is unconfigured, while {@see deleteConsultation()} throws — a delete that
 * silently did nothing would read as success to its caller.
 *
 * {@see findBySecureToken()} additionally refuses terminal consultations
 * (afgesloten / ingetrokken): the token is a public, unauthenticated surface,
 * so a closed consultation must stop resolving rather than stay readable.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * OpenRegister persistence and lookup for consultations (adviesaanvragen).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 */
class ConsultationRepository {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['parentCase' => $caseId, '_limit' => 100],
		);
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
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $consultationId,
		);
	}//end getConsultation()

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
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Consultation schema not configured');
		}

		$objectService->deleteObject(uuid: (string)$consultationId, register: $register, schema: $schema);

		$this->logger->info(
			'Consultation deleted: ' . $consultationId,
			['app' => Application::APP_ID],
		);

		return true;
	}//end deleteConsultation()

	/**
	 * Get overdue consultations (past deadline with open/in_behandeling status).
	 *
	 * @return array<int, array<string, mixed>> List of overdue consultations
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function getOverdueConsultations(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		$openList = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['status' => 'open', '_limit' => 200],
		);

		$inProgressList = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['status' => 'in_handling', '_limit' => 200],
		);

		$all = array_merge($openList, $inProgressList);
		$today = date('Y-m-d');
		$overdue = [];

		foreach ($all as $consultation) {
			$deadline = $consultation['latestResponseDate'] ?? '';
			if ($deadline !== '' && $deadline < $today) {
				$overdue[] = $consultation;
			}
		}

		return $overdue;
	}//end getOverdueConsultations()

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
		if (strlen($token) < 32) {
			return null;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('consultation_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$results = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['secureToken' => $token, '_limit' => 1],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to find consultation by token: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return null;
		}

		if (empty($results) === true) {
			return null;
		}

		$consultation = $results[0];
		$status = $consultation['status'] ?? '';

		if ($status === 'closed' || $status === 'withdrawn') {
			return null;
		}

		return $consultation;
	}//end findBySecureToken()

	/**
	 * Generate a unique consultation number in ADV-{year}-{seq} format.
	 *
	 * Queries existing consultations to find the maximum sequence number for
	 * the current year, then increments by one.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register The register slug
	 * @param string $schema The schema slug
	 *
	 * @return string Generated consultation number (e.g. ADV-2026-0001)
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
	 */
	public function nextConsultationNumber(
		object $objectService,
		string $register,
		string $schema,
	): string {
		$year = (int)date('Y');
		$prefix = 'ADV-' . $year . '-';

		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: [
				'consultationNumber' => $prefix . '%',
				'_order' => ['consultationNumber' => 'DESC'],
				'_limit' => 1,
			],
		);

		$maxSeq = 0;
		if (empty($existing) === false) {
			$latest = $existing[0];
			$number = $latest['consultationNumber'] ?? '';
			if (str_starts_with($number, $prefix) === true) {
				$seqPart = substr($number, strlen($prefix));
				$seq = (int)$seqPart;
				if ($seq > $maxSeq) {
					$maxSeq = $seq;
				}
			}
		}

		return $prefix . str_pad((string)($maxSeq + 1), 4, '0', STR_PAD_LEFT);
	}//end nextConsultationNumber()
}//end class
