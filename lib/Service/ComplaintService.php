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
	 * @param WorkingDayCalculator $workingDays Weekend and Dutch-holiday
	 *                                          arithmetic for the Awb deadlines
	 * @param CaseDateNormaliser $dates The one date write path.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly WorkingDayCalculator $workingDays,
		private readonly CaseDateNormaliser $dates,
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

		// The klachtnummer is NOT set here. `complaintNumber` declares
		// `x-openregister-generated` (sequence `complaint`, KL-{year}-{seq:4}),
		// so OpenRegister issues it under a lock inside the create transaction
		// and refuses any later change to it.
		$data['status'] = 'received';
		$data['postponementPossible'] = true;

		// Compute Awb deadlines.
		$data['acknowledgementOfReceiptDeadline'] = $this->addWorkingDays(startDate: $receiptDate, days: self::AWB_ACK_WORKING_DAYS);
		$data['afhandelDeadline'] = $this->addCalendarWeeks(startDate: $receiptDate, weeks: self::AWB_RESOLUTION_WEEKS);

		$complaint = $objectService->saveObject(object: $data, register: $register, schema: $schema);

		$saved = $complaint;
		if (is_array($complaint) === false) {
			$saved = array_merge($data, (array)$complaint->getObject(), ['id' => $complaint->getUuid()]);
		}

		// The number is read off the SAVED complaint, never off `$data`: `$data`
		// never held one, and a log line that invented its own would name a
		// number no complaint carries.
		$this->logger->info(
			'Complaint created: ' . (string)($saved['complaintNumber'] ?? 'number pending'),
			['app' => Application::APP_ID],
		);

		return $saved;
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

		$currentDeadline = ($this->dates->toCalendarDateOrNull($complaint['afhandelDeadline'] ?? null)
			?? $this->dates->todayAsCalendarDate());
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
	 * The window is counted in CALENDAR days, which is what the body does. The
	 * docblock used to say working days and the code never did; this corrects
	 * the comment rather than the behaviour, because an advisory alert window
	 * is not a statutory term.
	 *
	 * @param int $warningDays Warn when the deadline is within this many
	 *                         calendar days
	 *
	 * @return array<string, array<int, array<string, mixed>>> Grouped overdue/warning complaints
	 *
	 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function getDeadlineAlerts(int $warningDays = 3): array {
		$activeStatuses = ['received', 'receipt_confirmed', 'in_handling', 'hoorgesprek_planned', 'hoorgesprek_completed'];
		$all = $this->listComplaints(filters: ['status' => $activeStatuses]);
		$today = $this->dates->today();
		$overdue = [];
		$warning = [];

		foreach ($all as $complaint) {
			$deadlineDate = $this->dates->tryParse($complaint['afhandelDeadline'] ?? null);
			if ($deadlineDate === null) {
				continue;
			}

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
		$start = $this->dates->parse($startDate, 'receiptDate');
		return $this->dates->formatCalendarDate($this->workingDays->addWorkingDays(start: $start, days: $days));
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
		$date = $this->dates->parse($startDate, 'receiptDate')->modify('+' . $weeks . ' weeks');
		return $this->dates->formatCalendarDate($date);
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
		return $this->workingDays->isWorkingDay(date: $date);
	}//end isWorkingDay()

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
