<?php

/**
 * The `intakeObligations` entries on a case: read them, and write one's state.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Obligations
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Obligations;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads a case's intake entries and writes one entry's state, nothing else.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */
class IntakeObligationCase {

	use SearchesObjects;

	/**
	 * The case field the submit writes.
	 *
	 * @var string
	 */
	public const FIELD = 'intakeObligations';

	public const STATE_AWAITING = 'awaiting';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Bridge to OpenRegister.
	 * @param CaseDateNormaliser $dates           The moment a state changes.
	 * @param LoggerInterface    $logger          Records what could not be read or written.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDateNormaliser $dates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The case, or null when it cannot be read.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function read(string $caseId): ?array {
		[$objectService, $register, $schema] = $this->store();
		if ($objectService === null || trim($caseId) === '') {
			return null;
		}

		$case = null;
		try {
			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: trim($caseId));
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq intake obligations: the case could not be read', ['case' => $caseId, 'error' => $e->getMessage()]);
		}

		return $case;
	}//end read()

	/**
	 * The entries the submit wrote, as a list.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public function entries(array $case): array {
		$entries = ($case[self::FIELD] ?? null);
		if (is_array($entries) === false) {
			return [];
		}

		return array_values(array_filter($entries, 'is_array'));
	}//end entries()

	/**
	 * The state of the entry for a kind, or null when the case has none.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string               $kind The kind.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function stateOf(array $case, string $kind): ?string {
		$index = $this->indexOf(case: $case, kind: $kind);
		if ($index === null) {
			return null;
		}

		return (string)($this->entries(case: $case)[$index]['state'] ?? self::STATE_AWAITING);
	}//end stateOf()

	/**
	 * Write the new state of the entry for a kind on the case, and nothing else.
	 *
	 * @param array<string, mixed> $case  The case.
	 * @param string               $kind  The kind.
	 * @param string               $state The new state.
	 *
	 * @return bool True when the write landed.
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function writeState(array $case, string $kind, string $state): bool {
		$index = $this->indexOf(case: $case, kind: $kind);
		[$objectService, $register, $schema] = $this->store();
		if ($index === null || $objectService === null) {
			return false;
		}

		$entries = $this->entries(case: $case);
		$entries[$index]['state']     = $state;
		$entries[$index]['settledAt'] = $this->dates->nowAsMoment();

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $this->idOf(row: $case),
				changes: [self::FIELD => $entries],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq intake obligations: the state could not be written on the case',
				['case' => $this->idOf(row: $case), 'error' => $e->getMessage()],
			);
			return false;
		}

		return true;
	}//end writeState()

	/**
	 * Run an operation as the system.
	 *
	 * A portal submit and a portal settlement carry no Nextcloud user, and
	 * OpenRegister refuses every read and write by nobody. The operations run
	 * this way touch only the case and obligation rows named by id.
	 *
	 * @param callable $operation The operation.
	 * @param mixed    $fallback  What to answer when OpenRegister is not there.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-the-holder-settles-an-intake-obligation-from-the-portal
	 */
	public function asSystem(callable $operation, mixed $fallback): mixed {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $fallback;
		}

		return $this->runAsSystemIfAvailable(objectService: $objectService, operation: $operation);
	}//end asSystem()

	/**
	 * The id of a stored row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public function idOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($row['@self']['id'] ?? ''))));
	}//end idOf()

	/**
	 * Where the entry for a kind sits, or null.
	 *
	 * @param array<string, mixed> $case The case.
	 * @param string               $kind The kind.
	 *
	 * @return int|null
	 */
	private function indexOf(array $case, string $kind): ?int {
		if (trim($kind) === '') {
			return null;
		}

		foreach ($this->entries(case: $case) as $index => $entry) {
			if (trim((string)($entry['kind'] ?? '')) === trim($kind)) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * The object service and the register and schema cases live in.
	 *
	 * @return array{0: object|null, 1: string, 2: string}
	 */
	private function store(): array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string)$this->settingsService->getConfigValue(key: 'register');
		$schema        = (string)$this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [null, '', ''];
		}

		return [$objectService, $register, $schema];
	}//end store()
}//end class
