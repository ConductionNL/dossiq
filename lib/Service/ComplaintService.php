<?php

/**
 * Dossiq Complaint Service
 *
 * Service for managing complaints (klachten) per Awb chapter 9.
 * Handles CRUD, status-machine transitions, Awb working-day deadline
 * computation, verdaging (extension) logic, and escalation linking.
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
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for complaint (klacht) management per Awb chapter 9.
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
 */
class ComplaintService {

	use SearchesObjects;

	/**
	 * Valid complaint statuses in lifecycle order.
	 */
	private const VALID_STATUSES = [
		'received',
		'receipt_confirmed',
		'in_handling',
		'hoorgesprek_planned',
		'hoorgesprek_completed',
		'handled',
		'withdrawn',
	];

	/**
	 * Allowed status transitions (from => [to, ...]).
	 */
	private const TRANSITIONS = [
		'received' => ['receipt_confirmed', 'withdrawn'],
		'receipt_confirmed' => ['in_handling', 'withdrawn'],
		'in_handling' => ['hoorgesprek_planned', 'handled', 'withdrawn'],
		'hoorgesprek_planned' => ['hoorgesprek_completed', 'withdrawn'],
		'hoorgesprek_completed' => ['handled', 'withdrawn'],
		'handled' => [],
		'withdrawn' => [],
	];

	/**
	 * Dutch public holidays (fixed dates) for working-day calculation.
	 * Format: 'MM-DD'.
	 */
	private const FIXED_HOLIDAYS_NL = [
		'01-01',
		'04-27',
		'05-05',
		'12-25',
		'12-26',
	];

	/**
	 * Awb chapter 9 acknowledgment deadline in working days.
	 */
	private const AWB_ACK_WORKING_DAYS = 5;

	/**
	 * Awb chapter 9 resolution deadline in calendar weeks.
	 */
	private const AWB_RESOLUTION_WEEKS = 6;

	/**
	 * Awb chapter 9 verdaging (extension) in calendar weeks.
	 */
	private const AWB_VERDAGING_WEEKS = 4;

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
	 * Create a new complaint.
	 *
	 * @param array<string, mixed> $data Complaint data
	 *
	 * @return array<string, mixed> Created complaint
	 *
	 * @throws \RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function createComplaint(array $data): array {
		$this->validateRequired(data: $data, required: ['subject', 'description', 'receiptDate']);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_schema');

		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Complaint schema not configured');
		}

		$receiptDate = $data['receiptDate'];

		// Generate klachtnummer.
		$data['complaintNumber'] = $this->generateComplaintNumber();
		$data['status'] = 'received';
		$data['priority'] = $data['priority'] ?? 'normal';
		$data['postponementPossible'] = true;

		// Compute Awb deadlines.
		$data['acknowledgementOfReceiptDeadline'] = $this->addWorkingDays(startDate: $receiptDate, days: self::AWB_ACK_WORKING_DAYS);
		$data['afhandelDeadline'] = $this->addCalendarWeeks(startDate: $receiptDate, weeks: self::AWB_RESOLUTION_WEEKS);

		$complaint = $objectService->saveObject(object: $data, register: $register, schema: $schema);

		$this->logger->info(
			'Complaint created: ' . $data['complaintNumber'],
			['app' => Application::APP_ID],
		);

		if (is_array($complaint) === true) {
			return $complaint;
		}

		return array_merge($data, ['id' => $complaint->getUuid()]);
	}//end createComplaint()

	/**
	 * Get a single complaint by ID.
	 *
	 * @param string $id Complaint UUID
	 *
	 * @return array<string, mixed>|null Complaint or null if not found
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function getComplaint(string $id): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_schema');

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $id
		);
	}//end getComplaint()

	/**
	 * List complaints with optional filters.
	 *
	 * @param array<string, mixed> $filters Filter parameters
	 *
	 * @return array<int, array<string, mixed>> List of complaints
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function listComplaints(array $filters = []): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_schema');

		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		$params = array_merge(['_limit' => 100, '_offset' => 0], $filters);

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: $params
		);
	}//end listComplaints()

	/**
	 * Update a complaint.
	 *
	 * @param string $id Complaint UUID
	 * @param array<string, mixed> $data Updated data
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function updateComplaint(string $id, array $data): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_schema');

		$result = $objectService->saveObject(object: $data, register: $register, schema: $schema, uuid: (string)$id);

		if (is_array($result) === true) {
			return $result;
		}

		return array_merge($data, ['id' => $id]);
	}//end updateComplaint()

	/**
	 * Transition a complaint to a new status.
	 *
	 * @param string $id Complaint UUID
	 * @param string $newStatus Target status
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If transition not allowed
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function transitionStatus(string $id, string $newStatus): array {
		$complaint = $this->getComplaint(id: $id);
		if ($complaint === null) {
			throw new RuntimeException('Complaint not found: ' . $id);
		}

		if (in_array($newStatus, self::VALID_STATUSES, true) === false) {
			throw new RuntimeException('Unknown complaint status: ' . $newStatus);
		}

		$currentStatus = $complaint['status'] ?? 'received';
		$allowed = self::TRANSITIONS[$currentStatus] ?? [];

		if (in_array($newStatus, $allowed, true) === false) {
			throw new RuntimeException(
				'Transition from ' . $currentStatus . ' to ' . $newStatus . ' is not allowed'
			);
		}

		return $this->updateComplaint(id: $id, data: ['status' => $newStatus]);
	}//end transitionStatus()

	/**
	 * Request a verdaging (deadline extension) per Awb chapter 9.
	 *
	 * @param string $id Complaint UUID
	 * @param string $justification Written justification (required by Awb)
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If extension not available or invalid
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function requestVerdaging(string $id, string $justification): array {
		$complaint = $this->getComplaint(id: $id);
		if ($complaint === null) {
			throw new RuntimeException('Complaint not found: ' . $id);
		}

		if (($complaint['postponementPossible'] ?? false) === false) {
			throw new RuntimeException('Verdaging is not available — already used or not applicable');
		}

		if (empty($justification) === true) {
			throw new RuntimeException('Justificatie is required for verdaging per Awb chapter 9');
		}

		$currentDeadline = $complaint['afhandelDeadline'] ?? date('Y-m-d');
		$newDeadline = $this->addCalendarWeeks(startDate: $currentDeadline, weeks: self::AWB_VERDAGING_WEEKS);

		$updateData = [
			'afhandelDeadline' => $newDeadline,
			'postponementPossible' => false,
			'postponementJustification' => $justification,
		];

		$this->logger->info(
			'Verdaging requested for complaint ' . $id . '; new deadline: ' . $newDeadline,
			['app' => Application::APP_ID],
		);

		return $this->updateComplaint(id: $id, data: $updateData);
	}//end requestVerdaging()

	/**
	 * Link a complaint to an escalated formal case.
	 *
	 * @param string $complaintId Complaint UUID
	 * @param string $caseId Case UUID
	 *
	 * @return array<string, mixed> Updated complaint
	 *
	 * @throws \RuntimeException If complaint not found
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function linkEscalatedCase(string $complaintId, string $caseId): array {
		$complaint = $this->getComplaint(id: $complaintId);
		if ($complaint === null) {
			throw new RuntimeException('Complaint not found: ' . $complaintId);
		}

		return $this->updateComplaint(id: $complaintId, data: ['escalatedCase' => $caseId]);
	}//end linkEscalatedCase()

	/**
	 * Get complaints approaching or past their deadlines.
	 *
	 * @param int $warningDays Warn when deadline is within this many working days
	 *
	 * @return array<string, array<int, array<string, mixed>>> Grouped overdue/warning complaints
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function getDeadlineAlerts(int $warningDays = 3): array {
		$activeStatuses = ['received', 'receipt_confirmed', 'in_handling', 'hoorgesprek_planned', 'hoorgesprek_completed'];
		$all = $this->listComplaints(filters: ['status' => $activeStatuses]);
		$today = new DateTimeImmutable('today');
		$overdue = [];
		$warning = [];

		foreach ($all as $complaint) {
			$deadline = $complaint['afhandelDeadline'] ?? null;
			if ($deadline === null) {
				continue;
			}

			$deadlineDate = new DateTimeImmutable($deadline);
			$diff = (int)$today->diff($deadlineDate)->days;
			$isPast = $today > $deadlineDate;

			if ($isPast === true) {
				$overdue[] = $complaint;
			} elseif ($diff <= $warningDays) {
				$warning[] = $complaint;
			}
		}

		return ['overdue' => $overdue, 'warning' => $warning];
	}//end getDeadlineAlerts()

	/**
	 * Add working days to a date, skipping weekends and Dutch public holidays.
	 *
	 * @param string $startDate ISO date string (Y-m-d)
	 * @param int $days Number of working days to add
	 *
	 * @return string Resulting ISO date string (Y-m-d)
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function addWorkingDays(string $startDate, int $days): string {
		$date = new DateTimeImmutable($startDate);
		$added = 0;

		while ($added < $days) {
			$date = $date->modify('+1 day');
			if ($this->isWorkingDay(date: $date) === true) {
				$added++;
			}
		}

		return $date->format('Y-m-d');
	}//end addWorkingDays()

	/**
	 * Add calendar weeks to a date.
	 *
	 * @param string $startDate ISO date string (Y-m-d)
	 * @param int $weeks Number of weeks to add
	 *
	 * @return string Resulting ISO date string (Y-m-d)
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function addCalendarWeeks(string $startDate, int $weeks): string {
		$date = new DateTimeImmutable($startDate);
		$date = $date->modify('+' . $weeks . ' weeks');
		return $date->format('Y-m-d');
	}//end addCalendarWeeks()

	/**
	 * Determine whether a given date is a Dutch working day.
	 *
	 * @param \DateTimeImmutable $date Date to check
	 *
	 * @return bool True if the date is a working day
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function isWorkingDay(\DateTimeImmutable $date): bool {
		$dayOfWeek = (int)$date->format('N');

		// Skip weekends (Saturday=6, Sunday=7).
		if ($dayOfWeek >= 6) {
			return false;
		}

		// Skip fixed Dutch public holidays.
		$monthDay = $date->format('m-d');
		if (in_array($monthDay, self::FIXED_HOLIDAYS_NL, true) === true) {
			return false;
		}

		// Skip Easter-derived holidays (Good Friday, Easter Monday, Ascension, Whit Monday).
		$year = (int)$date->format('Y');
		$easter = new DateTimeImmutable(date('Y-m-d', easter_date($year)));
		$easterDerived = [
			$easter->modify('-2 days')->format('Y-m-d'),
			$easter->modify('+1 day')->format('Y-m-d'),
			$easter->modify('+39 days')->format('Y-m-d'),
			$easter->modify('+50 days')->format('Y-m-d'),
		];

		if (in_array($date->format('Y-m-d'), $easterDerived, true) === true) {
			return false;
		}

		return true;
	}//end isWorkingDay()

	/**
	 * Generate the next sequential klachtnummer for the current year.
	 *
	 * @return string Klachtnummer in format KL-{year}-{sequence}
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	private function generateComplaintNumber(): string {
		$year = date('Y');
		$objectService = $this->settingsService->getObjectService();

		if ($objectService === null) {
			return 'KL-' . $year . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('complaint_schema');

		if (empty($register) === true || empty($schema) === true) {
			return 'KL-' . $year . '-0001';
		}

		// Count existing complaints this year.
		$yearStart = $year . '-01-01';
		$yearEnd = $year . '-12-31';

		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['ontvangstdatum>=' => $yearStart, 'ontvangstdatum<=' => $yearEnd, '_limit' => 10000]
		);

		$count = count($existing);

		return 'KL-' . $year . '-' . str_pad((string)($count + 1), 4, '0', STR_PAD_LEFT);
	}//end generateKlachtnummer()

	/**
	 * Validate that required fields are present and non-empty.
	 *
	 * @param array<string, mixed> $data Input data
	 * @param string[] $required Required field names
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If any required field is missing
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	private function validateRequired(array $data, array $required): void {
		$missing = [];
		foreach ($required as $field) {
			if (empty($data[$field]) === true) {
				$missing[] = $field;
			}
		}

		if (empty($missing) === false) {
			throw new RuntimeException('Required fields missing: ' . implode(', ', $missing));
		}
	}//end validateRequired()
}//end class
