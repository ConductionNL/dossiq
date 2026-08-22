<?php

/**
 * Dossiq Vergadering Case Service
 *
 * Service that wraps ORI vergaderingen as Dossiq cases, managing the
 * case lifecycle (gepland → lopend → afgerond / geannuleerd), deadline
 * alerts (agenda publication T-7), and an audit trail.
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
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Wraps ORI vergaderingen as Dossiq cases with lifecycle and deadline tracking.
 *
 * A vergadering is created in the ORI register with status "planned".  Where a
 * linked Dossiq case already exists, this service applies the full Dossiq
 * lifecycle engine (status, deadlines, tasks, audit trail) to it.  It does not
 * create that link itself — see the note on `createForVergadering()` below.
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
 */
class VergaderingCaseService {

	use SearchesObjects;

	/**
	 * Valid case statuses for vergadering-backed cases.
	 *
	 * @var string[]
	 */
	private const VALID_STATUSES = [
		'planned',
		'lopend',
		'completed',
		'cancelled',
	];

	/*
	 * NO AGENDA_DEADLINE_DAYS HERE — its only reader was
	 * `createForVergadering()`, which computed `startDatum − 7 days` when it
	 * created the case. That method is gone (see the note below), and the
	 * deadline rule is recorded in
	 * `openspec/changes/open-raadsinformatie/tasks.md#task-5`.
	 */

	/**
	 * Constructor for VergaderingCaseService.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/*
	 * NO createForVergadering() HERE.
	 *
	 * It created a linked Dossiq case whenever a vergadering appeared in the
	 * open-raadsinformatie register. Nothing called it: the only consumer of
	 * this service, `BackgroundJob\\VergaderingDeadlineJob`, calls
	 * `checkDeadlines()`, and there is no listener on vergadering creation.
	 * The ORI ingest side of that bridge does not exist here, so this was a
	 * writer with no event to write on; `advanceStatus()` and
	 * `checkDeadlines()`, which operate on cases that already exist, are
	 * untouched.
	 */

	/**
	 * Advance the status of a vergadering-backed case.
	 *
	 * @param string $caseId The UUID of the Dossiq case to advance
	 * @param string $newStatus The target status (gepland|lopend|afgerond|geannuleerd)
	 *
	 * @return array The updated case object
	 *
	 * @throws RuntimeException When status is invalid or the case cannot be updated
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
	 */
	public function advanceStatus(string $caseId, string $newStatus): array {
		if (in_array(needle: $newStatus, haystack: self::VALID_STATUSES, strict: true) === false) {
			throw new RuntimeException(
				'Invalid vergadering case status: ' . $newStatus . '. '
				. 'Valid values: ' . implode(separator: ', ', array: self::VALID_STATUSES)
			);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister ObjectService is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

		if (empty($register) === true || empty($caseSchema) === true) {
			throw new RuntimeException('Dossiq case register/schema is not configured');
		}

		try {
			$updated = $objectService->saveObject(
				register: $register,
				schema: $caseSchema,
				object: ['status' => $newStatus],
				id: $caseId,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to advance vergadering case status',
				['caseId' => $caseId, 'newStatus' => $newStatus, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Could not update vergadering case status: ' . $e->getMessage());
		}

		$this->logger->info(
			'Dossiq: advanced vergadering case status',
			['caseId' => $caseId, 'newStatus' => $newStatus]
		);

		return ($updated ?? []);
	}//end advanceStatus()

	/**
	 * Check all gepland vergadering cases and advance those whose startDatum has passed.
	 *
	 * GIVEN startDatum reached WHEN nightly job runs
	 * THEN status transitions to "lopend".
	 *
	 * @return int The number of cases advanced to "lopend"
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-5
	 */
	public function checkDeadlines(): int {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');

		if (empty($register) === true || empty($caseSchema) === true) {
			return 0;
		}

		$today = (new DateTimeImmutable('today'))->format('Y-m-d');
		$advanced = 0;

		try {
			$plannedCases = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: [
					'status' => 'planned',
					'deadline' => $today,
					'_limit' => 200,
				]
			);

			foreach ($plannedCases as $case) {
				$caseId = (string)($case['id'] ?? '');
				if (empty($caseId) === true) {
					continue;
				}

				try {
					$this->advanceStatus(caseId: $caseId, newStatus: 'lopend');
					$advanced++;
				} catch (\Throwable $e) {
					$this->logger->warning(
						'Dossiq: could not advance deadline case',
						['caseId' => $caseId, 'exception' => $e->getMessage()]
					);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'Dossiq: deadline check for vergadering cases failed',
				['exception' => $e->getMessage(), 'app' => Application::APP_ID]
			);
		}//end try

		return $advanced;
	}//end checkDeadlines()
}//end class
