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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IUserSession;
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
	 * @param SettingsService   $settingsService Register and schema ids, and the object service.
	 * @param LoggerInterface   $logger          Logger.
	 * @param IUserSession|null $userSession     Whether anybody is signed in; nobody means a write runs as the system.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?IUserSession $userSession = null,
	) {
	}//end __construct()

	/**
	 * Get TermijnInstance by id.
	 *
	 * @param string $termInstanceId Instance id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function read(string $termInstanceId): ?array {
		$store = $this->storeFor(schemaKey: 'termijn_instance_schema');
		if ($store === null) {
			return null;
		}

		try {
			return $this->findIn(store: $store, termInstanceId: $termInstanceId);
		} catch (\Throwable $e) {
			$context = ['id' => $termInstanceId, 'error' => $e->getMessage()];
			$this->logger->warning('TermInstanceStore.read failed', $context);
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
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle/tasks.md
	 */
	public function latestForCase(string $caseId): ?array {
		$store = $this->storeFor(schemaKey: 'termijn_instance_schema');
		if ($store === null) {
			return null;
		}

		try {
			$rows = $this->instancesOn(store: $store, caseId: $caseId);
		} catch (\Throwable $e) {
			return null;
		}

		if (count($rows) === 0) {
			return null;
		}

		return self::newestFirst(rows: $rows)[0];
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
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function allForCase(string $caseId): array {
		$store = $this->storeFor(schemaKey: 'termijn_instance_schema');
		if ($store === null || $caseId === '') {
			return [];
		}

		try {
			$rows = $this->instancesOn(store: $store, caseId: $caseId);
		} catch (\Throwable $e) {
			// NOT an empty list. A case with no clocks and a case whose clocks
			// could not be read are opposite facts, and the second rendered as
			// the first tells a handler there is no deadline.
			throw $this->unreadable(caseId: $caseId, failure: $e);
		}

		return self::newestFirst(rows: $rows);
	}//end allForCase()

	/**
	 * Persist an object to a configured schema.
	 *
	 * A write with nobody signed in (an install, an example set, a cron job)
	 * runs as the system: OpenRegister refuses a create by "Anonymous", and
	 * every case such a run made got no term (rehearsal R3, twelve times
	 * "TermInstanceStore persist failed" during the example set install). A
	 * write by a signed-in user keeps that user's rights.
	 *
	 * @param string $schemaConfigKey The schema config key (e.g. 'termijn_instance_schema').
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function save(string $schemaConfigKey, array $object): ?array {
		$store = $this->storeFor(schemaKey: $schemaConfigKey);
		if ($store === null) {
			return null;
		}

		try {
			return $this->saveAsSystem(store: $store, object: $object);
		} catch (\Throwable $e) {
			$context = ['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()];
			$this->logger->error('TermInstanceStore persist failed', $context);
			return null;
		}
	}//end save()

	/**
	 * The object service, register and schema for one schema key, null when any is missing.
	 *
	 * The register and schema are only read once there is an object service.
	 *
	 * @param string $schemaKey The settings key of the schema.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null The store.
	 */
	private function storeFor(string $schemaKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema   = (string)$this->settingsService->getConfigValue($schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end storeFor()

	/**
	 * One term instance by id.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store          The instance store.
	 * @param string                                                          $termInstanceId The instance.
	 *
	 * @return array<string, mixed>|null The instance, null when it does not exist.
	 */
	private function findIn(array $store, string $termInstanceId): ?array {
		return $this->findObjectAsArray(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			id: $termInstanceId
		);
	}//end findIn()

	/**
	 * Save an object, as the system when nobody is signed in.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store  The target store.
	 * @param array<string, mixed>                                            $object The payload.
	 *
	 * @return array<string, mixed>|null The saved object.
	 */
	private function saveAsSystem(array $store, array $object): ?array {
		return $this->asSystemWithoutUser(
			objectService: $store['objectService'],
			userSession: $this->userSession,
			operation: fn (): ?array => $this->saveObjectAsArray(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['schema'],
				object: $object
			)
		);
	}//end saveAsSystem()

	/**
	 * Every term instance bound to a case, as stored.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store  The instance store.
	 * @param string                                                          $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The instances.
	 */
	private function instancesOn(array $store, string $caseId): array {
		return $this->searchObjectsAsArrays(
			objectService: $store['objectService'],
			register: $store['register'],
			schema: $store['schema'],
			filters: ['case' => $caseId]
		);
	}//end instancesOn()

	/**
	 * Instances ordered by start date, newest first.
	 *
	 * @param array<int, array<string, mixed>> $rows The instances.
	 *
	 * @return array<int, array<string, mixed>> The instances, newest start first.
	 */
	private static function newestFirst(array $rows): array {
		usort(
			$rows,
			static fn (array $a, array $b): int
				=> strcmp((string)($b['startDate'] ?? ''), (string)($a['startDate'] ?? ''))
		);

		return $rows;
	}//end newestFirst()

	/**
	 * The refusal for a case whose terms could not be read, logged.
	 *
	 * @param string     $caseId  The case.
	 * @param \Throwable $failure What went wrong.
	 *
	 * @return RefusedException The refusal to throw.
	 */
	private function unreadable(string $caseId, \Throwable $failure): RefusedException {
		$this->logger->warning(
			'TermInstanceStore.allForCase lookup failed, so the read is refused',
			['case' => $caseId, 'error' => $failure->getMessage()]
		);

		return new RefusedException(
			rule: 'term-instances-unreadable',
			sentence: 'The terms on this case could not be read.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $failure,
		);
	}//end unreadable()
}//end class
