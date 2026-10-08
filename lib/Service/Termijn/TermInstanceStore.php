<?php

/**
 * Dossiq term instance store.
 *
 * Where a term instance lives: the register and the schema it is written to,
 * the one row behind an id, the latest clock on a case, every clock on a case,
 * and the write itself. A read that fails and a row that is not there are the
 * same answer everywhere except on the whole-case read, which refuses rather
 * than answering an empty list, because a case with no clocks and a case whose
 * clocks could not be read are opposite facts.
 *
 * Split out of {@see \OCA\Dossiq\Service\TermijnService}, which was over its
 * complexity ceiling. What is left there is the lifecycle of a term: creating
 * one, completing one, recording what happened to it. What moved here is where
 * all of that is kept.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Where a term instance is read and written.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class TermInstanceStore {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema ids, and the object service.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get TermijnInstance by id.
	 *
	 * @param string $termInstanceId Instance id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function read(string $termInstanceId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $termInstanceId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'TermInstanceStore.read failed',
				['id' => $termInstanceId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end read()

	/**
	 * Fetch the active TermijnInstance bound to a zaak (latest by start).
	 *
	 * @param string $caseId Case id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function latestForCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['case' => $caseId]);
		} catch (\Throwable $e) {
			return null;
		}

		if (count($rows) === 0) {
			return null;
		}

		usort(
			$rows,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['startDate'] ?? ''), (string)($a['startDate'] ?? ''))
		);

		return $rows[0];
	}//end latestForCase()

	/**
	 * Every TermijnInstance bound to a case, newest first.
	 *
	 * {@see self::latestForCase()} answers the ONE latest instance, which
	 * was the right answer while a case had one clock. A case now carries a
	 * statutory term, a planned end, an internal target and a phase term, and a
	 * caller that wants all four cannot get them by asking for the latest four
	 * times. This is the same query without the `[0]`.
	 *
	 * @param string $caseId Case id.
	 *
	 * @return array<int, array<string, mixed>> The instances, newest start first.
	 *
	 * @throws RefusedException When the store could not be asked.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function allForCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null || $caseId === '') {
			return [];
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['case' => $caseId]
			);
		} catch (\Throwable $e) {
			// NOT an empty list. A case with no clocks and a case whose clocks
			// could not be read are opposite facts, and the second rendered as
			// the first tells a handler there is no deadline.
			$this->logger->warning(
				'TermInstanceStore.allForCase lookup failed, so the read is refused',
				['case' => $caseId, 'error' => $e->getMessage()]
			);

			throw new RefusedException(
				rule: 'term-instances-unreadable',
				sentence: 'The terms on this case could not be read.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}//end try

		usort(
			$rows,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['startDate'] ?? ''), (string)($a['startDate'] ?? ''))
		);

		return $rows;
	}//end allForCase()

	/**
	 * Persist an object to a configured schema.
	 *
	 * @param string $schemaConfigKey The schema config key (e.g. 'termijn_instance_schema').
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return array<string, mixed>|null
	 */
	public function save(string $schemaConfigKey, array $object): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $object
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'TermInstanceStore persist failed',
				['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end save()
}//end class
