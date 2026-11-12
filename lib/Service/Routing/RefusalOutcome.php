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
 * @spec openspec/specs/kcc-routing/spec.md
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
 * @spec openspec/specs/kcc-routing/spec.md
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
	 * @spec openspec/specs/kcc-routing/spec.md
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
	 * @spec openspec/specs/kcc-routing/spec.md
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
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function refuse(string $caseId, string $reason, string $refusedBy): array {
		$why = $this->requiredReason(reason: $reason);
		$case = $this->readableCase(caseId: $caseId);
		$destination = $this->declaredDestination(case: $case);

		$record = [
			'refused' => true,
			'reason' => $why,
			'refusedBy' => $refusedBy,
			'refusedAt' => $this->time->getDateTime()->format(DATE_ATOM),
			'department' => $destination['department'],
			'role' => $destination['role'],
		];

		$this->write(caseId: $caseId, changes: $this->refusalChanges(record: $record, destination: $destination));

		return $record;
	}//end refuse()

	/**
	 * The trimmed reason; a refusal without one is itself refused.
	 *
	 * @param string $reason The reason given.
	 *
	 * @return string
	 *
	 * @throws RefusedException When the reason is empty.
	 */
	private function requiredReason(string $reason): string {
		$why = trim($reason);
		if ($why === '') {
			throw new RefusedException(
				rule: self::RULE_NO_REASON,
				sentence: 'A refusal carries the reason it was refused for, so the person waiting '
					. 'can be told what happened.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $why;
	}//end requiredReason()

	/**
	 * The case, or a refusal when it cannot be read.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RefusedException When the case cannot be read.
	 */
	private function readableCase(string $caseId): array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: self::RULE_CASE_UNREADABLE,
				sentence: 'We could not read the case, so it was not refused.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $case;
	}//end readableCase()

	/**
	 * Where the case's type sends a refused case, or a refusal when it declares no department and role.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array{department: string, role: string}
	 *
	 * @throws RefusedException When the destination is not declared.
	 */
	private function declaredDestination(array $case): array {
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

		return $destination;
	}//end declaredDestination()

	/**
	 * What the refusal writes on the case: the record, and the team when one resolves.
	 *
	 * 🔴 THE CASE IS NOT HIDDEN AND NOT DELETED. Only the refusal record and
	 * the team are written. `statusHiddenInLists` is deliberately untouched:
	 * a refused case a handler cannot find is the lost case this whole
	 * outcome exists to prevent, and search reads the same rows the list
	 * does.
	 * 🔴 `assignedGroup` IS A NEXTCLOUD GROUP ID, NOT A DEPARTMENT NAME
	 * (one-team-model). `refusalDestination.department` is a plain
	 * department name, and this line once wrote one into the other. The
	 * team is resolved from the declared department and role to the
	 * group that role names (`organisatieRol.ncGroupId`), and when
	 * nothing resolves the refusal is still RECORDED, just unassigned. A
	 * refused case a handler can find beats a refusal that did not happen.
	 *
	 * @param array<string, mixed>                    $record      The refusal record.
	 * @param array{department: string, role: string} $destination The declared destination.
	 *
	 * @return array<string, mixed>
	 */
	private function refusalChanges(array $record, array $destination): array {
		$changes = [self::CASE_FIELD => $record];
		$team = $this->teamFor(destination: $destination);
		if ($team !== '') {
			$changes['assignedGroup'] = $team;
		}

		return $changes;
	}//end refusalChanges()

	/**
	 * The refusal recorded on one case.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed> The refusal record, empty when it was not refused.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
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
	 * The Nextcloud group a declared refusal destination names.
	 *
	 * The destination is a department and a role, in words an administrator
	 * typed on the case type. `case.assignedGroup` is a Nextcloud group id, so
	 * the words are joined to the `organisatieRol` they describe (on
	 * `department` plus `roleName`) and the case gets that role's
	 * `ncGroupId`, the one link from a role to a team.
	 *
	 * Returns an empty string when the register is unconfigured, when nothing
	 * matches, when the matching role names no group, or when the search
	 * throws. Every one of those means the case is refused and left
	 * unassigned, which is the recoverable outcome: an administrator assigns
	 * it, and the refusal record already says where it was meant to go. A
	 * role's `team` or `roleName` is never tried as a group id: a wrong guess
	 * hands the case to a team that never sees it.
	 *
	 * @param array{department: string, role: string} $destination The declared destination.
	 *
	 * @return string The Nextcloud group id, or '' when none resolves.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 * @spec openspec/specs/role-routing-via-or-rbac/spec.md#requirement-an-organisation-role-names-its-nextcloud-group-req-team-02
	 */
	private function teamFor(array $destination): string {
		$rows = $this->roleRowsFor(destination: $destination);
		if ($rows === null) {
			return '';
		}

		if ($rows === []) {
			$this->logger->warning(
				'Dossiq refusal: no organisatieRol answers to {department}/{role}, so the refused case is unassigned',
				['department' => $destination['department'], 'role' => $destination['role']],
			);

			return '';
		}

		$group = trim((string)($rows[0]['ncGroupId'] ?? ''));
		if ($group === '') {
			$this->logger->warning(
				'Dossiq refusal: the organisatieRol for {department}/{role} names no Nextcloud group, so the refused case is unassigned',
				['department' => $destination['department'], 'role' => $destination['role']],
			);
		}

		return $group;
	}//end teamFor()

	/**
	 * The organisatieRol rows of the destination's department and role, or null when they cannot be read.
	 *
	 * @param array{department: string, role: string} $destination The declared destination.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function roleRowsFor(array $destination): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'organisatie_rol_schema');

		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: [
					'department' => $destination['department'],
					'roleName' => $destination['role'],
					'_limit' => 1,
				],
			);
		} catch (Throwable $e) {
			$this->logUnresolvedTeam(destination: $destination, exception: $e);
			$rows = null;
		}

		return $rows;
	}//end roleRowsFor()

	/**
	 * Log that the team of a destination could not be resolved.
	 *
	 * @param array{department: string, role: string} $destination The declared destination.
	 * @param Throwable                               $exception   Why the read failed.
	 *
	 * @return void
	 */
	private function logUnresolvedTeam(array $destination, Throwable $exception): void {
		$this->logger->warning(
			'Dossiq refusal: could not resolve the team for {department}/{role}',
			[
				'department' => $destination['department'],
				'role' => $destination['role'],
				'reason' => $exception->getMessage(),
			],
		);
	}//end logUnresolvedTeam()

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
		[$objectService, $register, $schema] = $this->caseStore(caseId: $caseId);

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

	/**
	 * The object service, the register and the case schema; a refusal when any is missing.
	 *
	 * @param string $caseId The case the refusal is for, for the log.
	 *
	 * @return array{0: object, 1: string, 2: string}
	 *
	 * @throws RefusedException When the register is not configured.
	 */
	private function caseStore(string $caseId): array {
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

		return [$objectService, $register, $schema];
	}//end caseStore()
}//end class
