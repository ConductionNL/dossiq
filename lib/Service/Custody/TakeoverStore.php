<?php

/**
 * Dossiq takeover store.
 *
 * Reading and writing the takeover records and the cases they are about: the
 * register and schema they live in, the rows a filter answers, the uuid a row
 * carries under either of its two keys, and the refusal each failure earns.
 *
 * Split out of {@see CaseTakeoverRequest}, which was over its complexity
 * ceiling. What is left there is the rules a takeover follows; what moved here
 * is where the records are kept.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Where takeover records and the cases they are about are kept.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class TakeoverStore {

	use SearchesObjects;

	/**
	 * The register and the configured schemas, read and refused the one shared way.
	 *
	 * @var DossierRegister
	 */
	private readonly DossierRegister $dossierRegister;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Records what could not be read or written.
	 */
	public function __construct(
		SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		$this->dossierRegister = new DossierRegister(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Read takeover rows under a filter.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function rows(array $filters): array {
		try {
			[$objectService, $register] = $this->context();

			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_takeover_schema'),
				filters: $filters,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq takeover: the requests could not be read',
				['exception' => $e->getMessage()],
			);

			return [];
		}
	}//end rows()

	/**
	 * Store a request, new or existing.
	 *
	 * @param array<string, mixed> $record The request.
	 * @param string|null          $uuid   The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws RefusedException When it could not be stored.
	 */
	public function write(array $record, ?string $uuid): array {
		unset($record['@self'], $record['id'], $record['uuid']);

		try {
			$saved = $this->store(record: $record, uuid: $uuid);
		} catch (Throwable $e) {
			throw $this->unrecorded(previous: $e);
		}

		if ($saved === null) {
			throw $this->unrecorded(previous: null);
		}

		return $saved;
	}//end write()

	/**
	 * Save the request in the register.
	 *
	 * @param array<string, mixed> $record The request, without its metadata.
	 * @param string|null          $uuid   The uuid to update, or null to create.
	 *
	 * @return array<string, mixed>|null The stored row, or null when it could not be read back.
	 *
	 * @throws Throwable When the register is not available or refuses the write.
	 */
	private function store(array $record, ?string $uuid): ?array {
		[$objectService, $register] = $this->context();

		return $this->saveObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'case_takeover_schema'),
			object: $record,
			uuid: $uuid,
		);
	}//end store()

	/**
	 * The refusal when the request could not be recorded.
	 *
	 * @param Throwable|null $previous The cause, if any.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unrecorded(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: CaseTakeoverRequest::UNWRITABLE,
			sentence: 'The request could not be recorded, so nobody was asked.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end unrecorded()

	/**
	 * The uuid of a stored row, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	public function uuidOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
	}//end uuidOf()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	public function context(): array {
		return $this->dossierRegister->context();
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	public function schema(string $key): string {
		return $this->dossierRegister->schema(key: $key);
	}//end schema()

	/**
	 * The stored case, or a refusal.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The case.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	public function requireCase(string $caseId): array {
		try {
			[$objectService, $register] = $this->context();
			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				id: trim($caseId),
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: CaseTakeoverRequest::CASE_UNREADABLE,
				sentence: 'We could not read that case, so nothing was asked.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($case === null) {
			throw new RefusedException(
				rule: CaseTakeoverRequest::CASE_UNREADABLE,
				sentence: 'We could not read that case, so nothing was asked.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $case;
	}//end requireCase()

	/**
	 * Apply changes to the stored case.
	 *
	 * @param array<string, mixed> $case    The case as it was read.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return void
	 */
	public function writeCase(array $case, array $changes): void {
		$caseId = $this->uuidOf(row: $case);
		if ($caseId === '' || $changes === []) {
			return;
		}

		$payload = array_merge($case, $changes);
		unset($payload['@self'], $payload['id'], $payload['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $this->schema(key: 'case_schema'),
				uuid: $caseId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq takeover: the case seat could not be written',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);
		}
	}//end writeCase()
}//end class
