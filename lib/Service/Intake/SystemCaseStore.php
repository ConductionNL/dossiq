<?php

/**
 * Dossiq system case store.
 *
 * Reads a case type and a case by id, and writes a case, as the system and
 * without RBAC or multitenancy, on the schemas dossiq configures. An intake
 * that arrives without a Nextcloud user (a portal forward, a form another app
 * took in) has nobody to act as, so it writes as the system; the intake itself
 * decides beforehand whether the request may become a case.
 *
 * Every failure is logged and answered as an absent value, so the caller turns
 * it into its own refusal: no case type, no read-back, no written case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and writes cases as the system, on dossiq's configured case schemas.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
 */
class SystemCaseStore {

	use SearchesObjects;

	/**
	 * Reads one object by id as the system, unscoped.
	 *
	 * @var UnscopedObjectReader
	 */
	private readonly UnscopedObjectReader $reader;

	/**
	 * Fills the opening fields a staff create form would have filled.
	 *
	 * @var CaseOpeningDefaults
	 */
	private readonly CaseOpeningDefaults $openingDefaults;

	/**
	 * Constructor.
	 *
	 * @param SettingsService             $settingsService The configured case and case type schemas.
	 * @param LoggerInterface             $logger          Logger.
	 * @param UnscopedObjectReader|null   $reader          Reads unscoped; a fresh one when null.
	 * @param CaseCreationAnnouncer|null  $announcer       Announces the written case; null where
	 *                                                     no event dispatcher is at hand, and then
	 *                                                     the case is written unannounced and that
	 *                                                     is logged.
	 * @param CaseOpeningDefaults|null    $openingDefaults The case type's opening fields; a fresh
	 *                                                     one when null.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		?UnscopedObjectReader $reader = null,
		private readonly ?CaseCreationAnnouncer $announcer = null,
		?CaseOpeningDefaults $openingDefaults = null,
	) {
		$this->reader          = ($reader ?? new UnscopedObjectReader());
		$this->openingDefaults = ($openingDefaults ?? new CaseOpeningDefaults());
	}//end __construct()

	/**
	 * A case type by id, or null when it is absent or cannot be read.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseTypeId    The case type uuid.
	 *
	 * @return array<string, mixed>|null The case type.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function caseType(object $objectService, string $register, string $caseTypeId): ?array {
		$caseType = null;
		try {
			$caseType = $this->reader->find(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_type_schema'),
				id: $caseTypeId,
			);
		} catch (Throwable $e) {
			$this->logger->error('SystemCaseStore: the case type could not be read', ['caseType' => $caseTypeId, 'error' => $e->getMessage()]);
		}

		return $caseType;
	}//end caseType()

	/**
	 * The version of a case type that new cases get: the row the id names,
	 * followed along `supersededBy` to the version nothing has superseded.
	 * The answer carries its own `id`, so a caller files on that version.
	 *
	 * A link that cannot be read ends the walk on the last readable version,
	 * and a chain that loops back ends where it would repeat: a broken chain
	 * files on a version that exists rather than on none.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseTypeId    Any version of the case type.
	 *
	 * @return array<string, mixed>|null The current version, or null when the id names nothing.
	 *
	 * @spec openspec/changes/woo-five-steps/specs/woo-request-intake/spec.md#requirement-a-request-is-filed-on-the-current-version-of-the-case-type
	 */
	public function currentCaseType(object $objectService, string $register, string $caseTypeId): ?array {
		$current = $this->caseType(objectService: $objectService, register: $register, caseTypeId: $caseTypeId);
		if ($current === null) {
			return null;
		}

		$currentId = $caseTypeId;
		$seen      = [$caseTypeId => true];
		$next      = trim((string)($current['supersededBy'] ?? ''));
		while ($next !== '' && isset($seen[$next]) === false) {
			$seen[$next] = true;
			$row = $this->caseType(objectService: $objectService, register: $register, caseTypeId: $next);
			if ($row === null) {
				break;
			}

			$current   = $row;
			$currentId = $next;
			$next      = trim((string)($row['supersededBy'] ?? ''));
		}

		$current['id'] = $currentId;

		return $current;
	}//end currentCaseType()

	/**
	 * A case by id, or an empty array when it is absent or cannot be read.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseId        The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function readCase(object $objectService, string $register, string $caseId): array {
		$case = [];
		try {
			$case = (array)$this->reader->find(
				objectService: $objectService,
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				id: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->warning('SystemCaseStore: the case could not be read back', ['case' => $caseId, 'error' => $e->getMessage()]);
		}

		return $case;
	}//end readCase()

	/**
	 * Write a case, and read its uuid, number and deadline from what was written.
	 *
	 * A NEW CASE OPENS AS A STAFF-CREATED ONE DOES. Before the write the case
	 * takes the opening fields it lacks from its case type (handler, handling
	 * group, confidentiality, first status and the moment it entered it).
	 * After it, the created event OpenRegister withholds for a system write is
	 * sent, so every listener that answers a new case runs: the
	 * acknowledgement of receipt above all.
	 *
	 * @param object                    $objectService The OpenRegister ObjectService.
	 * @param string                    $register      The dossiq register.
	 * @param array<string, mixed>      $case          The case to write.
	 * @param array<string, mixed>|null $caseType      Its case type when the caller already read it;
	 *                                                 read from `caseType` when null.
	 *
	 * @return array{id: string, answer: array<string, string>}|null The written case, or null when the write failed.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 * @spec openspec/changes/a-portal-filed-case-opens-like-a-staff-created-one/specs/woo-request-intake/spec.md#requirement-a-case-the-system-writes-opens-as-a-staff-created-case-does-req-wri-005
	 */
	public function write(object $objectService, string $register, array $case, ?array $caseType = null): ?array {
		$caseTypeId = trim((string)($case['caseType'] ?? ''));
		if ($caseType === null && $caseTypeId !== '') {
			$caseType = $this->caseType(objectService: $objectService, register: $register, caseTypeId: $caseTypeId);
		}

		if ($caseType !== null) {
			$case = $this->openingDefaults->apply(case: $case, caseType: $caseType);
		}

		$saved  = null;
		$failed = false;
		try {
			$saved = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): mixed => $objectService->saveObject(
					object: $case,
					register: $register,
					schema: $this->settingsService->getConfigValue('case_schema'),
					_rbac: false,
					_multitenancy: false,
				)
			);
		} catch (Throwable $e) {
			$this->logger->error('SystemCaseStore: the case could not be written', ['error' => $e->getMessage()]);
			$failed = true;
		}

		if ($failed === true) {
			return null;
		}

		$this->announce(objectService: $objectService, saved: $saved);

		return (new WrittenCase())->read(saved: $saved);
	}//end write()

	/**
	 * Send the created event OpenRegister withheld, when it withheld one.
	 *
	 * Without `runAsSystem()` the write ran as an ordinary save, which
	 * announced itself; sending the event again would answer the case twice.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param mixed  $saved         What the write returned.
	 *
	 * @return void
	 */
	private function announce(object $objectService, mixed $saved): void {
		if (method_exists($objectService, 'runAsSystem') === false) {
			return;
		}

		if ($this->announcer === null) {
			$this->logger->warning('SystemCaseStore: no announcer, so the new case was written unannounced');

			return;
		}

		$this->announcer->announce(objectService: $objectService, saved: $saved);
	}//end announce()
}//end class
