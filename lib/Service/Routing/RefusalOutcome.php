<?php

/**
 * Dossiq refusal as an outcome of routing.
 *
 * `lib/Service/Routing/Strategy/` holds five strategies and every one of them
 * answers with a destination. Refusal is the missing sixth outcome: the case is
 * not for us, and it still has to go somewhere.
 *
 * AWB 2:3 IS THE REQUIREMENT, NOT A NICETY. A bestuursorgaan that receives a
 * document meant for another body sends it on. So a refused case is assigned to
 * the department and role its case type declares, the reason and the refuser are
 * recorded, and the case stays in the register and in search. A refusal that
 * deletes or hides is not a refusal, it is a loss, and the person waiting for an
 * answer never learns which.
 *
 * A case type declaring no destination cannot refuse at all. The refusal is
 * itself refused, saying the destination is not configured, because "refused to
 * nowhere" is exactly the lost case xxllnc configures its way out of.
 *
 * Not a {@see RoutingStrategyInterface}. A strategy answers with participant
 * references and this answers with a destination and a record, so making it one
 * would have every existing caller handle a return shape that is not a list of
 * assignees.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Routing
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Routing;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\RefusesWhenIndeterminate;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refusing a case at intake, to a declared department and role.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) — `RefusedException::indeterminate()` is
 *  a named constructor, not a service call. It holds no state and exists so a
 *  caller cannot build a refusal with the wrong status on it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */
class RefusalOutcome {

	use RefusesWhenIndeterminate;
	use SearchesObjects;

	/**
	 * The case-type property declaring where a refused case goes.
	 */
	public const PROPERTY = 'refusalDestination';

	/**
	 * The case property carrying the refusal.
	 */
	public const CASE_FIELD = 'intakeRefusal';

	/**
	 * The rule a refusal with nowhere to go names.
	 */
	public const RULE_NO_DESTINATION = 'refusal-destination-not-configured';

	/**
	 * The rule a refusal with no reason names.
	 */
	public const RULE_NO_REASON = 'refusal-carries-no-reason';

	/**
	 * The rule a refusal of a case that cannot be read names.
	 */
	public const RULE_CASE_UNREADABLE = 'refusal-case-unreadable';

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService  Register and schema resolution.
	 * @param CaseTypeResolver $caseTypeResolver The effective case type.
	 * @param CaseFieldWriter  $writer           Partial writes to the stored case.
	 * @param ITimeFactory     $time             Clock.
	 * @param LoggerInterface  $logger           Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly CaseFieldWriter $writer,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Where a case of this type goes when it is refused.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array{department: string, role: string} The destination, blank when undeclared.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function destinationFor(array $caseType): array {
		$declared = ($caseType[self::PROPERTY] ?? null);
		if (is_array($declared) === false) {
			$declared = [];
		}

		return [
			'department' => trim((string)($declared['department'] ?? '')),
			'role' => trim((string)($declared['role'] ?? '')),
		];
	}//end destinationFor()

	/**
	 * Whether a case of this type can be refused at all.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return boolean True when a department and a role are declared.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function canRefuse(array $caseType): bool {
		$destination = $this->destinationFor(caseType: $caseType);

		return ($destination['department'] !== '' && $destination['role'] !== '');
	}//end canRefuse()

	/**
	 * Refuse one case at intake and hand it to its declared destination.
	 *
	 * @param string $caseId    The case UUID.
	 * @param string $reason    Why it is refused, in the refuser's own words.
	 * @param string $refusedBy The uid of the person refusing.
	 *
	 * @return array<string, mixed> `{refused: true, department, role, reason, refusedBy, refusedAt}`.
	 *
	 * @throws RefusedException When there is no reason, no destination, or no readable case.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function refuse(string $caseId, string $reason, string $refusedBy): array {
		$why = trim($reason);
		if ($why === '') {
			throw new RefusedException(
				rule: self::RULE_NO_REASON,
				sentence: 'A refusal carries the reason it was refused for, so the person waiting '
					. 'can be told what happened.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: self::RULE_CASE_UNREADABLE,
				sentence: 'We could not read the case, so it was not refused.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		$caseType = $this->caseTypeResolver->effectiveCaseType(
			caseTypeId: $this->caseTypeIdOf(case: $case)
		);
		$destination = $this->destinationFor(caseType: $caseType);
		if ($destination['department'] === '' || $destination['role'] === '') {
			throw new RefusedException(
				rule: self::RULE_NO_DESTINATION,
				sentence: 'This case type does not say where a refused case goes, so the case '
					. 'was not refused. Declare a department and a role on the case type first.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$record = [
			'refused' => true,
			'reason' => $why,
			'refusedBy' => $refusedBy,
			'refusedAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'department' => $destination['department'],
			'role' => $destination['role'],
		];

		// 🔴 THE CASE IS NOT HIDDEN AND NOT DELETED. Only the refusal record and
		// the team are written. `statusHiddenInLists` is deliberately untouched:
		// a refused case a handler cannot find is the lost case this whole
		// outcome exists to prevent, and search reads the same rows the list
		// does.
		$this->write(
			caseId: $caseId,
			changes: [
				self::CASE_FIELD => $record,
				'assignedGroup' => $destination['department'],
			]
		);

		return $record;
	}//end refuse()

	/**
	 * The refusal recorded on one case.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed> The refusal record, empty when it was not refused.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	public function refusalOn(array $case): array {
		$record = ($case[self::CASE_FIELD] ?? null);
		if (is_array($record) === false || ($record['refused'] ?? false) !== true) {
			return [];
		}

		return $record;
	}//end refusalOn()

	/**
	 * The case type this case names.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return string The case type UUID, or ''.
	 */
	private function caseTypeIdOf(array $case): string {
		$value = ($case['caseType'] ?? '');
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['@self']['id'] ?? '')));
		}

		return trim((string)$value);
	}//end caseTypeIdOf()

	/**
	 * Read one case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The case row, or null when the register is not configured.
	 *
	 * @throws RefusedException When the register is configured but the read fails.
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');

		if ($objectService === null || $register === '' || $schema === '' || trim($caseId) === '') {
			return null;
		}

		return $this->readOrRefuse(
			read: fn (): ?array => $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
			),
			what: 'case ' . $caseId . ' for a refusal at intake',
			rule: self::RULE_CASE_UNREADABLE,
			sentence: 'We could not read the case, so it was not refused.',
		);
	}//end readCase()

	/**
	 * Apply changes to the stored case.
	 *
	 * 🔴 A WRITE THAT DID NOT LAND REFUSES, IT DOES NOT LOG AND CARRY ON. An
	 * earlier shape here logged the failure and answered the caller with the
	 * record anyway, so the surface said "refused, handed to Juridische Zaken"
	 * about a case still sitting unrefused in the queue. The 503 says neither
	 * yes nor no, which is the only honest answer when the store did not take
	 * the write.
	 *
	 * @param string               $caseId  The case UUID.
	 * @param array<string, mixed> $changes The fields this service owns.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the register is absent or the write failed.
	 */
	private function write(string $caseId, array $changes): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			$this->logger->error(
				'Dossiq refusal: the register is not configured, so case {case} records nothing',
				['case' => $caseId],
			);

			throw RefusedException::indeterminate(
				rule: self::RULE_CASE_UNREADABLE,
				sentence: 'The register is not configured, so the refusal was not recorded.',
			);
		}

		try {
			$this->writer->write(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				case: ['id' => $caseId],
				changes: $changes,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq refusal: case {case} could not record the refusal',
				['case' => $caseId, 'reason' => $e->getMessage()],
			);

			$this->refuseIndeterminate(
				rule: self::RULE_CASE_UNREADABLE,
				sentence: 'The refusal could not be recorded on the case, so nothing was refused.',
				previous: $e,
			);
		}
	}//end write()
}//end class
