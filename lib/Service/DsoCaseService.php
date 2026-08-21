<?php

/**
 * DSO Case Service
 *
 * Core service for the DSO Omgevingsloket integration. Handles vergunningaanvraag
 * intake (zaak creation), status transitions, deadline computation, and per-object
 * authorization for DSO-related cases. All workflow side-effects (notifications,
 * event dispatch) are routed through this service to keep the controller layer thin.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Exception;
use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Dso\DsoStatusChangeNotifier;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for DSO Omgevingsloket case management.
 *
 * Creates Procest zaken from DSO vergunningaanvragen, transitions statuses,
 * and computes statutory deadlines in working days (excluding weekends and
 * Dutch national holidays).
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) ADR-083 replaced a lazy
 * container lookup with a typed ObjectServiceInterface dependency, which is
 * the point of the ADR — the dependency is now visible to readers and tools.
 * That pushed this class to 13 collaborators, one over the threshold. The
 * container stays because IGroupManager is still resolved through it.
 * 29 classes in this app already carry this suppression.
 */
class DsoCaseService {

	use SearchesObjects;

	/**
	 * Fixed Dutch national holidays as [month, day] pairs.
	 * Variable Easter-based holidays are computed dynamically in isWorkingDay().
	 *
	 * @var array<int, array{0: int, 1: int}>
	 */
	private const FIXED_HOLIDAYS = [
		[1, 1],
		// New Year.
		[4, 27],
		// King's Day.
		[5, 5],
		// Liberation Day.
		[12, 25],
		// Christmas Day 1.
		[12, 26],
		// Christmas Day 2.
	];

	/**
	 * Day-offsets from Easter Sunday for variable Dutch national holidays.
	 *
	 * 0=Eerste Paasdag, 1=Tweede Paasdag, 39=Hemelvaartsdag,
	 * 49=Eerste Pinksterdag, 50=Tweede Pinksterdag.
	 *
	 * @var array<int, int>
	 */
	private const EASTER_OFFSETS = [0, 1, 39, 49, 50];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The application config service
	 * @param ContainerInterface $container The DI container (ObjectService resolved lazily)
	 * @param DsoStatusChangeNotifier $notifier Emits the VergunningStatusChanged domain event
	 * @param LoggerInterface $logger The logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service (ADR-084)
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly DsoStatusChangeNotifier $notifier,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Create a Procest zaak from a DSO vergunningaanvraag.
	 *
	 * Looks up the vergunningaanvraag object, determines the procedure type
	 * from the activiteiten list, computes the statutory deadline, and
	 * persists a new zaak in the Procest register.
	 *
	 * @param string $permitApplicationId The UUID of the vergunningaanvraag object
	 *
	 * @return array<string,mixed> The created zaak object
	 *
	 * @throws \RuntimeException When OpenRegister is unavailable or config is missing
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function createZaakFromVergunningaanvraag(string $permitApplicationId): array {
		$objectService = $this->getObjectService();

		$requestSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'dso_vergunningaanvraag_schema',
			default: ''
		);

		$permitApplication = $this->findObjectAsArray(
			objectService: $objectService,
			register: 'dso',
			schema: $requestSchema,
			id: $permitApplicationId
		);

		if ($permitApplication === null) {
			throw new RuntimeException('Vergunningaanvraag not found: ' . $permitApplicationId);
		}

		$activiteiten = $permitApplication['activiteiten'] ?? [];
		$procedureType = $this->determineProcedureType(activiteiten: $activiteiten);

		$submissionDate = (string)($permitApplication['indieningsdatum'] ?? date('Y-m-d'));
		$deadlineDate = $this->computeDeadline(
			submissionDate: $submissionDate,
			procedureType: $procedureType
		);

		$register = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'register',
			default: ''
		);
		$caseSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'case_schema',
			default: ''
		);

		$case = [
			'title' => 'Omgevingsvergunning: ' . ($permitApplication['title'] ?? $permitApplicationId),
			'status' => 'submitted',
			'caseType' => 'omgevingsvergunning',
			'procedureType' => $procedureType,
			'permitApplicationRef' => $permitApplicationId,
			'indieningsdatum' => $submissionDate,
			'deadlineDate' => $deadlineDate,
			'activiteiten' => $activiteiten,
			'activityLog' => [
				[
					'timestamp' => date('c'),
					'action' => 'zaak_created',
					'note' => 'Zaak aangemaakt vanuit DSO vergunningaanvraag.',
				],
			],
		];

		// The saveObject() call returns an ObjectEntityInterface (ADR-084); this
		// method declares `: array`. Normalise, exactly as findObjectAsArray()
		// does on the read side.
		$created = $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			object: $case
		) ?? [];

		$this->logger->info(
			'Procest DsoCaseService: zaak created',
			[
				'app' => Application::APP_ID,
				'vergunningaanvraagId' => $permitApplicationId,
				'procedureType' => $procedureType,
				'deadlineDate' => $deadlineDate,
			]
		);

		return $created;
	}//end createZaakFromVergunningaanvraag()

	/**
	 * Transition the status of a DSO zaak.
	 *
	 * Loads both the zaak and the linked vergunningaanvraag, updates their
	 * statuses, appends to the activity log, and dispatches a
	 * VergunningStatusChangedEvent for downstream listeners.
	 *
	 * @param string $caseId The UUID of the zaak
	 * @param string $newStatus The target status value
	 * @param string|null $besluitdatum Optional ISO 8601 decision date
	 * @param string|null $notes Optional explanation text
	 * @param string $userId The Nextcloud user UID performing the action
	 *
	 * @return array<string,mixed> The updated zaak object
	 *
	 * @throws \RuntimeException When the zaak cannot be found
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function transitionStatus(
		string $caseId,
		string $newStatus,
		?string $besluitdatum,
		?string $notes,
		string $userId,
	): array {
		$objectService = $this->getObjectService();

		$register = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'register',
			default: ''
		);
		$caseSchema = $this->appConfig->getValueString(
			app: Application::APP_ID,
			key: 'case_schema',
			default: ''
		);

		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);

		if ($case === null) {
			throw new RuntimeException('Zaak not found: ' . $caseId);
		}

		$case = $this->normalizeToArray(value: $case);

		$oldStatus = (string)($case['status'] ?? '');
		$requestRef = (string)($case['permitApplicationRef'] ?? '');

		$case['status'] = $newStatus;
		if ($besluitdatum !== null) {
			$case['besluitdatum'] = $besluitdatum;
		}

		if ($notes !== null) {
			$case['notes'] = $notes;
		}

		$logEntry = [
			'timestamp' => date('c'),
			'action' => 'status_transition',
			'userId' => $userId,
			'oldStatus' => $oldStatus,
			'newStatus' => $newStatus,
		];
		if ($notes !== null) {
			$logEntry['note'] = $notes;
		}

		$activityLog = $case['activityLog'] ?? [];
		$activityLog[] = $logEntry;
		$case['activityLog'] = $activityLog;

		// Same as above: this method returns an array to its caller.
		$updatedCase = $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			object: $case
		) ?? [];

		// Update the linked vergunningaanvraag status when possible.
		if ($requestRef !== '') {
			$this->syncPermitApplicationStatus(
				objectService: $objectService,
				requestRef: $requestRef,
				newStatus: $newStatus,
				besluitdatum: $besluitdatum
			);
		}

		$this->notifier->dispatchStatusChanged(
			requestRef: $requestRef,
			oldStatus: $oldStatus,
			newStatus: $newStatus,
			besluitdatum: $besluitdatum,
			notes: $notes,
			userId: $userId,
		);

		return $updatedCase;
	}//end transitionStatus()

	/**
	 * Compute the statutory deadline for a vergunningaanvraag.
	 *
	 * Reguliere procedure: 40 working days (8 weeks).
	 * Uitgebreide procedure: 130 working days (26 weeks).
	 * Working days exclude weekends (Saturday = 6, Sunday = 7 per date('N'))
	 * and a fixed set of Dutch national holidays.
	 *
	 * @param string $submissionDate ISO 8601 date of submission
	 * @param string $procedureType 'reguliere' or 'uitgebreide'
	 *
	 * @return string ISO 8601 date string of the computed deadline
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function computeDeadline(string $submissionDate, string $procedureType): string {
		$workingDaysTarget = 40;
		if ($procedureType === 'uitgebreide') {
			$workingDaysTarget = 130;
		}

		$current = new DateTimeImmutable($submissionDate);
		$workingDays = 0;

		while ($workingDays < $workingDaysTarget) {
			$current = $current->modify('+1 day');
			if ($this->isWorkingDay(date: $current) === true) {
				$workingDays++;
			}
		}

		return $current->format('Y-m-d');
	}//end computeDeadline()

	/**
	 * Authorise a zaak mutation for the given user.
	 *
	 * Checks whether the user is either the assigned user on the zaak or
	 * a Nextcloud administrator. Throws an exception if not authorised so
	 * that the controller can catch and return a 403 response.
	 *
	 * @param array<string,mixed> $case The zaak object array
	 * @param IUser $user The authenticated user
	 *
	 * @return void
	 *
	 * @throws \Exception When the user is not authorised to mutate the zaak
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function authorizeZaakMutation(array $case, IUser $user): void {
		$uid = $user->getUID();
		$assignee = (string)($case['assigneeUserId'] ?? ($case['handler'] ?? ''));

		if ($uid === $assignee) {
			return;
		}

		try {
			$groupManager = $this->container->get('OCP\IGroupManager');
			if ($groupManager->isAdmin(uid: $uid) === true) {
				return;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Procest DsoCaseService: could not resolve IGroupManager for auth check: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
		}

		throw new Exception('Not authorized');
	}//end authorizeZaakMutation()

	/**
	 * Get the ObjectService lazily from the DI container.
	 *
	 * @return object The OpenRegister ObjectService
	 *
	 * No @throws: the service is injected (ADR-083), so this method only reads a
	 * property and cannot throw. The stale `@throws \RuntimeException` described
	 * the old lazy-container lookup that ADR-083 removed; PHPStan 2 reports it as
	 * throws.unusedType.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083), so this cannot fail — a property read throws
		// nothing, and phpstan reports the old try/catch as a dead catch.
		// Absence is now a CONSTRUCTION failure on the route that needed the
		// data, which is what ADR-083 rule 1 asks for.
		return $this->objectService;
	}//end getObjectService()

	/**
	 * Normalise an OpenRegister object (array or entity) to an associative array.
	 *
	 * ObjectService::findObject() returns either an array or an entity object
	 * (which exposes jsonSerialize()); this collapses both into a predictable
	 * array<string, mixed> so callers can use offset access safely.
	 *
	 * @param mixed $value The value returned by the ObjectService.
	 *
	 * @return array<string, mixed> The normalised array (empty when not coercible).
	 */
	private function normalizeToArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end normalizeToArray()

	/**
	 * Determine the procedure type from the activiteiten list.
	 *
	 * Returns 'uitgebreide' when any activiteit has regelkwalificatie set to
	 * 'uitgebreide' or when there are more than 3 activiteiten; 'reguliere'
	 * otherwise.
	 *
	 * @param array<int,mixed> $activiteiten The activiteiten array
	 *
	 * @return string 'reguliere' or 'uitgebreide'
	 */
	private function determineProcedureType(array $activiteiten): string {
		if (count($activiteiten) > 3) {
			return 'uitgebreide';
		}

		foreach ($activiteiten as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$kwalificatie = (string)($activity['regelkwalificatie'] ?? '');
			if ($kwalificatie === 'uitgebreide') {
				return 'uitgebreide';
			}
		}

		return 'reguliere';
	}//end determineProcedureType()

	/**
	 * Check whether a given date is a working day.
	 *
	 * A working day is neither a weekend day nor a Dutch national holiday.
	 * Both fixed holidays (New Year, King's Day, Liberation Day, Christmas)
	 * and Easter-based variable holidays (Eerste/Tweede Paasdag,
	 * Hemelvaartsdag, Eerste/Tweede Pinksterdag) are excluded.
	 *
	 * @param \DateTimeImmutable $date The date to check
	 *
	 * @return bool True when the date is a working day
	 */
	private function isWorkingDay(\DateTimeImmutable $date): bool {
		$dayOfWeek = (int)$date->format('N');
		if ($dayOfWeek >= 6) {
			return false;
		}

		$month = (int)$date->format('n');
		$day = (int)$date->format('j');

		foreach (self::FIXED_HOLIDAYS as $holiday) {
			if ($holiday[0] === $month && $holiday[1] === $day) {
				return false;
			}
		}

		// Check Easter-based variable holidays using PHP's easter_date().
		$year = (int)$date->format('Y');
		$easterTs = easter_date($year);
		$easterDay = (int)date('j', $easterTs);
		$easterMon = (int)date('n', $easterTs);
		$easterDate = (new DateTimeImmutable())->setDate($year, $easterMon, $easterDay);

		foreach (self::EASTER_OFFSETS as $offset) {
			$holiday = $easterDate->modify('+' . $offset . ' days');
			if ((int)$holiday->format('n') === $month && (int)$holiday->format('j') === $day) {
				return false;
			}
		}

		return true;
	}//end isWorkingDay()

	/**
	 * Sync the vergunningaanvraag status to match the zaak's new status.
	 *
	 * Best-effort: errors are logged but do not propagate to the caller.
	 *
	 * @param object $objectService The ObjectService instance
	 * @param string $requestRef The vergunningaanvraag UUID
	 * @param string $newStatus The new status to set
	 * @param string|null $besluitdatum Optional decision date
	 *
	 * @return void
	 */
	private function syncPermitApplicationStatus(
		object $objectService,
		string $requestRef,
		string $newStatus,
		?string $besluitdatum,
	): void {
		try {
			$requestSchema = $this->appConfig->getValueString(
				app: Application::APP_ID,
				key: 'dso_vergunningaanvraag_schema',
				default: ''
			);

			if ($requestSchema === '') {
				return;
			}

			$request = $this->findObjectAsArray(
				objectService: $objectService,
				register: 'dso',
				schema: $requestSchema,
				id: $requestRef
			);

			if ($request === null) {
				return;
			}

			$request['status'] = $newStatus;
			if ($besluitdatum !== null) {
				$request['besluitdatum'] = $besluitdatum;
			}

			$objectService->saveObject(
				register: 'dso',
				schema: $requestSchema,
				object: $request
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Procest DsoCaseService: could not sync vergunningaanvraag status: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'permitApplicationRef' => $requestRef,
				]
			);
		}//end try
	}//end syncVergunningaanvraagStatus()
}//end class
