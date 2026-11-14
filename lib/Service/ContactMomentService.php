<?php

/**
 * Dossiq Contactmoment Service.
 *
 * Logs every inbound/outbound KCC contact as a contactmoment object, records
 * immutable activity entries on the related case, and links a contactmoment to
 * an identified burger reference. Wraps the OpenRegister ObjectService through
 * SettingsService following the established Dossiq service convention.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ContactMomentServiceInterface;
use OCA\Dossiq\Service\Support\CastArray;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for logging KCC contactmomenten and case activity.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
 */
class ContactMomentService implements ContactMomentServiceInterface {
	use SearchesObjects;

	/**
	 * Valid contactmoment channels.
	 */
	private const VALID_KANALEN = ['phone', 'email', 'webformulier', 'chat', 'social_media', 'balie', 'brief'];

	/**
	 * Valid contactmoment natures.
	 */
	private const VALID_AARD = ['informatieverzoek', 'statusverzoek', 'complaint', 'report', 'new_request', 'doorverbinding'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param IUserSession $userSession The session, for the handling employee default.
	 * @param LoggerInterface $logger The logger.
	 * @param CaseDateNormaliser $dates The one date write path.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseDateNormaliser $dates,
		// The timeline entry and the pipelinq append are NOT written here. Both
		// moved to listeners on the create event (ContactMomentTimelineListener,
		// ContactMomentPipelinqListener), because the case page's Log contact
		// form saves straight to OpenRegister and never runs this service.
	) {
	}//end __construct()

	/**
	 * Create a contactmoment.
	 *
	 * A contact logged from the case page arrives with the channel, the
	 * direction, the summary and `case` only: the KCC fields the schema
	 * requires are defaulted here, and `relatedCases` is seeded from `case`
	 * so the KCC voorblad lists the contact as before.
	 *
	 * @param array<string, mixed> $data The contactmoment fields.
	 *
	 * @return array<string, mixed> The created contactmoment record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable, schema unconfigured, or input invalid.
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
	 */
	public function createContactMoment(array $data): array {
		$data = array_merge($data, $this->defaultsFor(record: $data));
		$this->validateInput(data: $data);

		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'contactmoment_schema');

		$record = $this->recordFrom(data: $data);
		$record = $this->withOptionalFields(record: $record, data: $data);

		try {
			$created = $objectService->saveObject(object: $record, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to create contactmoment: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID],
			);
			throw new RuntimeException('Could not create contactmoment');
		}

		$record = $this->normalize(result: $created);

		return $record;
	}//end createContactMoment()

	/**
	 * The contactmoment record built from the defaulted fields.
	 *
	 * @param array<string, mixed> $data The contactmoment fields, defaults applied.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
	 */
	private function recordFrom(array $data): array {
		$now = $this->dates->nowAsMoment();

		return [
			'notificationChannel' => (string)$data['notificationChannel'],
			'direction' => (string)($data['direction'] ?? 'inbound'),
			'startTime' => (string)($data['startTime'] ?? $now),
			'endTime' => ($data['endTime'] ?? null),
			'callerIdentification' => (string)($data['callerIdentification'] ?? ''),
			'geidentificeerdeBurgerId' => ($data['geidentificeerdeBurgerId'] ?? null),
			'identificationMethod' => (string)$data['identificationMethod'],
			'identificationScore' => ($data['identificationScore'] ?? null),
			'kccEmployeeId' => trim((string)$data['kccEmployeeId']),
			'case' => (string)($data['case'] ?? ''),
			'relatedCases' => array_values((array)($data['relatedCases'] ?? [])),
			'newCaseIds' => array_values((array)($data['newCaseIds'] ?? [])),
			'nature' => (string)$data['nature'],
			'summary' => (string)($data['summary'] ?? ''),
			'accordingToIntent' => (string)($data['accordingToIntent'] ?? ''),
			'firstTimeFix' => (bool)($data['firstTimeFix'] ?? false),
			// Internal unless the handler ticked the box. The flag travels on
			// the record rather than only on the entry, because the entry is
			// written by ContactMomentTimelineListener after this save and has
			// nothing but the record to read the answer from.
			'visibleToApplicant' => (bool)($data['visibleToApplicant'] ?? false),
			'transcript' => (string)($data['transcript'] ?? ''),
			'transferTo' => (string)($data['transferTo'] ?? ''),
		];
	}//end recordFrom()

	/**
	 * The record without an empty case, and with its duration when start and end give one.
	 *
	 * @param array<string, mixed> $record The record to save.
	 * @param array<string, mixed> $data   The contactmoment fields.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
	 */
	private function withOptionalFields(array $record, array $data): array {
		if ($record['case'] === '') {
			unset($record['case']);
		}

		$duration = $this->calculateDuration(data: $data);
		if ($duration !== null) {
			$record['durationSeconds'] = $duration;
		}

		return $record;
	}//end withOptionalFields()

	/**
	 * The fields a contact logged from a case leaves out, with their defaults.
	 *
	 * Returns only the keys that need setting, so a caller can merge them
	 * into a payload it is about to write, or hand them to an OpenRegister
	 * pre-persist hook as modified data. A filled field is never touched:
	 * a KCC write that carries its own `relatedCases` keeps that list.
	 *
	 * - `relatedCases`: seeded with `case` when `case` is set and the list
	 *   is empty, so the KCC voorblad sees the contact.
	 * - `kccEmployeeId`: the signed-in user when absent.
	 * - `identificationMethod`: `non_geidentificeerd` when absent.
	 * - `nature`: `informatieverzoek` when absent.
	 *
	 * @param array<string, mixed> $record The contactmoment fields as supplied.
	 *
	 * @return array<string, mixed> The keys to set, possibly empty.
	 *
	 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md
	 */
	public function defaultsFor(array $record): array {
		$defaults = [];

		$case = trim((string)($record['case'] ?? ''));
		$relatedCases = array_values(array_filter((array)($record['relatedCases'] ?? [])));
		if ($case !== '' && $relatedCases === []) {
			$defaults['relatedCases'] = [$case];
		}

		if (trim((string)($record['kccEmployeeId'] ?? '')) === '') {
			$uid = $this->userSession->getUser()?->getUID() ?? '';
			if ($uid !== '') {
				$defaults['kccEmployeeId'] = $uid;
			}
		}

		if (trim((string)($record['identificationMethod'] ?? '')) === '') {
			$defaults['identificationMethod'] = 'non_geidentificeerd';
		}

		if (trim((string)($record['nature'] ?? '')) === '') {
			$defaults['nature'] = 'informatieverzoek';
		}

		return $defaults;
	}//end defaultsFor()

	/**
	 * Validate the required input fields for a new contactmoment.
	 *
	 * @param array<string, mixed> $data The contactmoment fields.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a required field is missing or invalid.
	 */
	private function validateInput(array $data): void {
		$channel = (string)($data['notificationChannel'] ?? '');
		if (in_array($channel, self::VALID_KANALEN, true) === false) {
			throw new RuntimeException('Invalid kanaal');
		}

		$nature = (string)($data['nature'] ?? 'informatieverzoek');
		if (in_array($nature, self::VALID_AARD, true) === false) {
			throw new RuntimeException('Invalid aard');
		}

		if (trim((string)($data['kccEmployeeId'] ?? '')) === '') {
			throw new RuntimeException('kccMedewerkerId is required');
		}
	}//end validateInput()

	/**
	 * Calculate the contact duration in seconds from start/end timestamps.
	 *
	 * @param array<string, mixed> $data The contactmoment fields.
	 *
	 * @return int|null The duration in seconds, or null when not calculable.
	 */
	private function calculateDuration(array $data): ?int {
		if (isset($data['startTime']) === false || isset($data['endTime']) === false) {
			return null;
		}

		$start = strtotime((string)$data['startTime']);
		$end = strtotime((string)$data['endTime']);
		if ($start === false || $end === false || $end < $start) {
			return null;
		}

		return ($end - $start);
	}//end calculateDuration()

	/**
	 * List contactmomenten for an identified burger, most recent first.
	 *
	 * @param string $burgerId The identified burger reference.
	 * @param int $limit Maximum number of records.
	 *
	 * @return array<int, array<string, mixed>> The contactmoment records.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function listForBurger(string $burgerId, int $limit = 50): array {
		$target = $this->burgerSearchTarget(burgerId: $burgerId);
		if ($target === null) {
			return [];
		}

		try {
			$results = $this->searchForBurger(target: $target, burgerId: $burgerId, limit: $limit);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: failed to list contactmomenten: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
			return [];
		}

		return $this->newestFirst(results: $results);
	}//end listForBurger()

	/**
	 * The ObjectService, register and schema to search a burger's contactmomenten in.
	 *
	 * @param string $burgerId The identified burger reference.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The search target, or null for no burger or no schema.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	private function burgerSearchTarget(string $burgerId): ?array {
		if ($burgerId === '') {
			return null;
		}

		try {
			return $this->resolve(schemaConfigKey: 'contactmoment_schema');
		} catch (RuntimeException $e) {
			return null;
		}
	}//end burgerSearchTarget()

	/**
	 * Search the contactmomenten of an identified burger.
	 *
	 * @param array{0: object, 1: string, 2: string} $target   The ObjectService, register and schema.
	 * @param string                                 $burgerId The identified burger reference.
	 * @param int                                    $limit    Maximum number of records.
	 *
	 * @return array<int, array<string, mixed>> The raw rows.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	private function searchForBurger(array $target, string $burgerId, int $limit): array {
		[$objectService, $register, $schema] = $target;

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['geidentificeerdeBurgerId' => $burgerId, '_limit' => max(1, $limit)],
		);
	}//end searchForBurger()

	/**
	 * Normalise the rows and sort them most recent first.
	 *
	 * @param mixed $results The raw rows.
	 *
	 * @return array<int, array<string, mixed>> The contactmoment records.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	private function newestFirst(mixed $results): array {
		$records = [];
		foreach ((array)$results as $result) {
			$records[] = $this->normalize(result: $result);
		}

		usort(
			$records,
			static function (array $a, array $b): int {
				return strcmp((string)($b['startTime'] ?? ''), (string)($a['startTime'] ?? ''));
			}
		);

		return $records;
	}//end newestFirst()

	/**
	 * Append an immutable activity entry to a case's activity array.
	 *
	 * Activity entries are append-only: this reads the case's current activity
	 * list, appends a timestamped entry, and writes the merged list back. Prior
	 * entries are never edited or removed.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $interactionId The contactmoment UUID.
	 * @param string $type The activity type.
	 * @param string $employeeName The handling medewerker.
	 * @param string $summary A short summary of the activity.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function recordActivity(
		string $caseId,
		string $interactionId,
		string $type,
		string $employeeName,
		string $summary,
	): bool {
		if ($caseId === '') {
			return false;
		}

		try {
			return $this->appendActivity(
				caseId: $caseId,
				interactionId: $interactionId,
				type: $type,
				employeeName: $employeeName,
				summary: $summary,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to record case activity: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID],
			);
			return false;
		}//end try
	}//end recordActivity()

	/**
	 * Read the case's activity list, append the entry and patch it back.
	 *
	 * @param string $caseId        The case UUID.
	 * @param string $interactionId The contactmoment UUID.
	 * @param string $type          The activity type.
	 * @param string $employeeName  The handling medewerker.
	 * @param string $summary       A short summary of the activity.
	 *
	 * @return bool True on success, false when the register is not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	private function appendActivity(
		string $caseId,
		string $interactionId,
		string $type,
		string $employeeName,
		string $summary,
	): bool {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $caseSchema === '') {
			return false;
		}

		$case = $this->normalize(result: $objectService->find($caseId, register: $register, schema: $caseSchema));

		$activity = $this->activityWith(
			case: $case,
			type: $type,
			interactionId: $interactionId,
			employeeName: $employeeName,
			summary: $summary,
		);

		// A patch, not a save: a uuid save of `['activity' => ...]` alone
		// replaces the case and wipes every other field on it.
		$this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId,
			changes: ['activity' => $activity]
		);
		return true;
	}//end appendActivity()

	/**
	 * The case's activity list with a timestamped entry appended.
	 *
	 * @param array<string, mixed> $case          The case.
	 * @param string               $type          The activity type.
	 * @param string               $interactionId The contactmoment UUID.
	 * @param string               $employeeName  The handling medewerker.
	 * @param string               $summary       A short summary of the activity.
	 *
	 * @return array<int, mixed> The activity list.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	private function activityWith(
		array $case,
		string $type,
		string $interactionId,
		string $employeeName,
		string $summary,
	): array {
		$activity = array_values((array)($case['activity'] ?? []));
		$activity[] = [
			'type' => $type,
			'interactionId' => $interactionId,
			'employee' => $employeeName,
			'summary' => $summary,
			'timestamp' => $this->dates->nowAsMoment(),
		];

		return $activity;
	}//end activityWith()

	/**
	 * Link an unidentified contactmoment to an identified burger.
	 *
	 * @param string $interactionId The contactmoment UUID.
	 * @param string $burgerId The resolved burger reference.
	 * @param string $method The identification method.
	 * @param float $score The identification confidence score.
	 *
	 * @return array<string, mixed> The updated contactmoment record.
	 *
	 * @throws RuntimeException When the schema is unconfigured or the update fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	public function linkUnlinkedContactmoment(
		string $interactionId,
		string $burgerId,
		string $method,
		float $score,
	): array {
		$target = $this->resolve(schemaConfigKey: 'contactmoment_schema');
		$changes = [
			'geidentificeerdeBurgerId' => $burgerId,
			'identificationMethod' => $method,
			'identificationScore' => round($score, 2),
		];

		$updated = $this->patchContactmoment(target: $target, interactionId: $interactionId, changes: $changes);

		return $this->normalize(result: $updated);
	}//end linkUnlinkedContactmoment()

	/**
	 * Patch a contactmoment, turning any failure into one RuntimeException.
	 *
	 * @param array{0: object, 1: string, 2: string} $target        The ObjectService, register and schema.
	 * @param string                                 $interactionId The contactmoment UUID.
	 * @param array<string, mixed>                   $changes       The fields to set.
	 *
	 * @return array<string, mixed>|null The updated row.
	 *
	 * @throws RuntimeException When the update fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T04
	 */
	private function patchContactmoment(array $target, string $interactionId, array $changes): ?array {
		[$objectService, $register, $schema] = $target;

		try {
			return $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $interactionId,
				changes: $changes,
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: failed to link contactmoment: ' . $e->getMessage(), ['app' => AppIdentity::APP_ID]);
			throw new RuntimeException('Could not link contactmoment');
		}
	}//end patchContactmoment()

	/**
	 * Resolve the ObjectService, register id and schema id for a config key.
	 *
	 * @param string $schemaConfigKey The schema config key.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RuntimeException When OpenRegister or the schema is unavailable.
	 */
	private function resolve(string $schemaConfigKey): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('KCC schema is not configured');
		}

		return [$objectService, $register, $schema];
	}//end resolve()

	/**
	 * Normalise an ObjectService result into a plain array.
	 *
	 * @param mixed $result The ObjectService result (entity or array).
	 *
	 * @return array<string, mixed> The normalised record.
	 */
	private function normalize($result): array {
		return CastArray::of(value: $result);
	}//end normalize()
}//end class
