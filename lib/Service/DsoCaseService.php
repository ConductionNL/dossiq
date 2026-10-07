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
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Exception;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\Dso\DsoStatusChangeNotifier;
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IAppConfig;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for DSO Omgevingsloket case management.
 *
 * Creates Dossiq zaken from DSO vergunningaanvragen, transitions statuses,
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
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The application config service
	 * @param ContainerInterface $container The DI container (ObjectService resolved lazily)
	 * @param DsoStatusChangeNotifier $notifier Emits the VergunningStatusChanged domain event
	 * @param LoggerInterface $logger The logger
	 * @param ObjectServiceInterface $objectService The OpenRegister object service (ADR-084)
	 * @param WorkingDayCalculator $workingDays Weekend and Dutch-holiday
	 *                                          arithmetic for the statutory deadlines
	 * @param CaseTypeReferenceResolver $caseTypes The mapped case type reference, to its row
	 * @param CaseJournal $journal The case's activity record
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly DsoStatusChangeNotifier $notifier,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly WorkingDayCalculator $workingDays,
		private readonly CaseTypeReferenceResolver $caseTypes,
		private readonly CaseJournal $journal,
	) {
	}//end __construct()

	/**
	 * Create a Dossiq zaak from a DSO intake record.
	 *
	 * The record is either integriq's `dso_verzoek` after mapping (it carries
	 * `mappedCaseTypes`, `mappedTitle`, `submissionDate`, `rawRequest`) or a
	 * legacy vergunningaanvraag (`title`, `indieningsdatum`, `activiteiten`).
	 * The listener hands the record it was given; without one it is read from
	 * the legacy `dso` register.
	 *
	 * The case is written in the shape the case schema accepts: `caseType` is
	 * the uuid of the case type the activity mapping names, `status` is that
	 * type's initial status type, and `dsoStatus` is `submitted`, the DSO-LV
	 * status DsoDeadlineJob selects on. A record that names no case type that
	 * resolves writes nothing and throws: a case on a guessed type is worse
	 * than a verzoek still waiting in integriq's list.
	 *
	 * One record makes one case. A second event for the same record answers
	 * the case the first one made.
	 *
	 * @param string                    $permitApplicationId The UUID of the intake record
	 * @param array<string, mixed>|null $permitApplication   The record itself, when the caller holds it
	 *
	 * @return array<string,mixed> The created (or already existing) zaak object
	 *
	 * @throws \RuntimeException When the record is missing or names no case type that resolves
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function createZaakFromVergunningaanvraag(string $permitApplicationId, ?array $permitApplication = null): array {
		$objectService = $this->getObjectService();

		if ($permitApplication === null) {
			$permitApplication = $this->findObjectAsArray(
				objectService: $objectService,
				register: 'dso',
				schema: $this->config(key: 'dso_vergunningaanvraag_schema'),
				id: $permitApplicationId
			);
		}

		if ($permitApplication === null) {
			throw new RuntimeException('Vergunningaanvraag not found: ' . $permitApplicationId);
		}

		$register = $this->config(key: 'register');
		$caseSchema = $this->config(key: 'case_schema');

		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			filters: ['permitApplicationRef' => $permitApplicationId, '_limit' => 1]
		);
		if ($existing !== []) {
			return $existing[0];
		}

		$case = $this->intakeCase(permitApplicationId: $permitApplicationId, permitApplication: $permitApplication);

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
			'Dossiq DsoCaseService: zaak created',
			[
				'app' => Application::APP_ID,
				'vergunningaanvraagId' => $permitApplicationId,
				'caseType' => $case['caseType'],
				'procedureType' => $case['procedureType'],
				'deadlineDate' => $case['deadlineDate'],
			]
		);

		return $created;
	}//end createZaakFromVergunningaanvraag()

	/**
	 * Transition the DSO status of a DSO zaak.
	 *
	 * `newStatus` is a DSO-LV value (submitted, in_handling, granted, refused,
	 * withdrawn), and it lands on `dsoStatus`, the field the schema declares
	 * for it. The case's own `status` is the uuid of a status type and moves
	 * through StatusTransitionService, never through a DSO value. The write is
	 * a patch of the fields this act owns, so the case's computed fields are
	 * not sent back. The linked vergunningaanvraag is synced and a
	 * VergunningStatusChangedEvent is dispatched for downstream listeners.
	 *
	 * @param string $caseId The UUID of the zaak
	 * @param string $newStatus The target DSO status value
	 * @param string|null $besluitdatum Optional ISO 8601 decision date
	 * @param string|null $notes Optional explanation text
	 * @param string $userId The Nextcloud user UID performing the action
	 *
	 * @return array<string,mixed> The updated zaak object
	 *
	 * @throws \RuntimeException When the zaak cannot be found
	 *
	 * @spec openspec/specs/vth-dso-integration/spec.md
	 */
	public function transitionStatus(
		string $caseId,
		string $newStatus,
		?string $besluitdatum,
		?string $notes,
		string $userId,
	): array {
		$objectService = $this->getObjectService();

		$register = $this->config(key: 'register');
		$caseSchema = $this->config(key: 'case_schema');

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

		$oldStatus = (string)($case['dsoStatus'] ?? '');
		$requestRef = (string)($case['permitApplicationRef'] ?? '');

		$changes = ['dsoStatus' => $newStatus];
		if ($besluitdatum !== null) {
			$changes['besluitdatum'] = $besluitdatum;
		}

		if ($notes !== null) {
			$changes['dsoNotes'] = $notes;
		}

		$entry = [
			'type' => 'dsoStatusChanged',
			'userId' => $userId,
			'oldStatus' => $oldStatus,
			'newStatus' => $newStatus,
		];
		if ($notes !== null) {
			$entry['note'] = $notes;
		}

		$changes[CaseJournal::FIELD] = $this->journal->append(case: $case, entry: $entry)[CaseJournal::FIELD];

		// Same as above: this method returns an array to its caller.
		$updatedCase = $this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId,
			changes: $changes
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
	 * The case an intake record becomes, in the shape the case schema accepts.
	 *
	 * @param string               $permitApplicationId The record's UUID
	 * @param array<string, mixed> $permitApplication   The record
	 *
	 * @return array<string, mixed> The case payload
	 *
	 * @throws \RuntimeException When the record names no case type that resolves
	 */
	private function intakeCase(string $permitApplicationId, array $permitApplication): array {
		$caseType = $this->caseTypeFor(permitApplication: $permitApplication);
		if ($caseType === []) {
			throw new RuntimeException(
				'No dossiq case type answers to the activity mapping of vergunningaanvraag ' . $permitApplicationId
				. ' (mappedCaseTypes: ' . implode(', ', $this->caseTypeReferences(permitApplication: $permitApplication)) . ')'
			);
		}

		$activiteiten = $this->listAt(record: $permitApplication, key: 'activiteiten');
		if ($activiteiten === []) {
			$activiteiten = $this->listAt(record: $this->listAt(record: $permitApplication, key: 'rawRequest'), key: 'activiteiten');
		}

		$procedureType = $this->determineProcedureType(activiteiten: $activiteiten);

		$submitted = (string)($permitApplication['submissionDate'] ?? ($permitApplication['indieningsdatum'] ?? ''));
		$submissionDate = substr($submitted, 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $submissionDate) !== 1) {
			$submissionDate = date('Y-m-d');
		}

		$name = trim((string)($permitApplication['mappedTitle'] ?? ''));
		if ($name === '') {
			$name = trim((string)($permitApplication['title'] ?? ''));
		}

		if ($name === '') {
			$name = $permitApplicationId;
		}

		$case = [
			'title' => mb_substr('Omgevingsvergunning: ' . $name, 0, 255),
			'caseType' => $this->caseTypes->idOf(caseType: $caseType),
			'dsoStatus' => 'submitted',
			'procedureType' => $procedureType,
			'permitApplicationRef' => $permitApplicationId,
			'startDate' => $submissionDate,
			'deadlineDate' => $this->computeDeadline(submissionDate: $submissionDate, procedureType: $procedureType),
		];

		$summary = trim((string)($permitApplication['mappedSummary'] ?? ''));
		if ($summary !== '') {
			$case['description'] = $summary;
		}

		$initial = $this->caseTypes->initialStatusOf(caseType: $caseType);
		if ($initial !== '') {
			$case['status'] = $initial;
		}

		return $this->journal->append(
			case: $case,
			entry: ['type' => 'dsoIntake', 'note' => 'Zaak aangemaakt vanuit DSO vergunningaanvraag ' . $permitApplicationId . '.']
		);
	}//end intakeCase()

	/**
	 * The first case type the record's activity mapping names that resolves.
	 *
	 * @param array<string, mixed> $permitApplication The record
	 *
	 * @return array<string, mixed> The case type row, or an empty array
	 */
	private function caseTypeFor(array $permitApplication): array {
		foreach ($this->caseTypeReferences(permitApplication: $permitApplication) as $reference) {
			$caseType = $this->caseTypes->resolve(reference: $reference);
			if ($caseType !== []) {
				return $caseType;
			}
		}

		return [];
	}//end caseTypeFor()

	/**
	 * The case type references a record carries.
	 *
	 * The activity mapping in integriq writes `mappedCaseTypes` (a ZGW zaaktype URL
	 * or a catalogue identifier per entry). A legacy record may name one in
	 * `caseType`.
	 *
	 * @param array<string, mixed> $permitApplication The record
	 *
	 * @return list<string> The references, in the order the record gives them
	 */
	private function caseTypeReferences(array $permitApplication): array {
		$references = [];
		foreach ($this->listAt(record: $permitApplication, key: 'mappedCaseTypes') as $reference) {
			if (is_array($reference) === true) {
				$reference = ($reference['reference'] ?? '');
			}

			if (is_string($reference) === true && trim($reference) !== '') {
				$references[] = trim($reference);
			}
		}

		$legacy = $permitApplication['caseType'] ?? '';
		if (is_string($legacy) === true && trim($legacy) !== '') {
			$references[] = trim($legacy);
		}

		return $references;
	}//end caseTypeReferences()

	/**
	 * The array a record holds under a key, or an empty array.
	 *
	 * @param array<string, mixed> $record The record
	 * @param string               $key    The key
	 *
	 * @return array<array-key, mixed> The value when it is an array
	 */
	private function listAt(array $record, string $key): array {
		$value = ($record[$key] ?? []);
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listAt()

	/**
	 * One of this app's config values.
	 *
	 * @param string $key The key
	 *
	 * @return string The value, or '' when unset
	 */
	private function config(string $key): string {
		return $this->appConfig->getValueString(app: Application::APP_ID, key: $key, default: '');
	}//end config()

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
		// The case schema declares `assignee`; the other two are older shapes.
		$assignee = (string)($case['assignee'] ?? ($case['assigneeUserId'] ?? ($case['handler'] ?? '')));

		if ($uid === $assignee) {
			return;
		}

		try {
			$groupManager = $this->container->get('OCP\IGroupManager');
			if ($groupManager->isAdmin(userId: $uid) === true) {
				return;
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Dossiq DsoCaseService: could not resolve IGroupManager for auth check: ' . $e->getMessage(),
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
	 * Delegates to WorkingDayCalculator, the app's single implementation of
	 * the weekend and Dutch-holiday rules.
	 *
	 * @param \DateTimeImmutable $date The date to check
	 *
	 * @return bool True when the date is a working day
	 */
	private function isWorkingDay(\DateTimeImmutable $date): bool {
		return $this->workingDays->isWorkingDay(date: $date);
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
				'Dossiq DsoCaseService: could not sync vergunningaanvraag status: ' . $e->getMessage(),
				[
					'app' => Application::APP_ID,
					'permitApplicationRef' => $requestRef,
				]
			);
		}//end try
	}//end syncVergunningaanvraagStatus()
}//end class
