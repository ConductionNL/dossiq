<?php

/**
 * Dossiq CaseLeafReader.
 *
 * The object reads behind the case leaf: the parties that carry a master
 * entity, their cases, and the case types and statuses those cases point at.
 * Built per answer by {@see CaseLeafProvider} once the register and the
 * object service are known.
 *
 * @category Integration
 * @package  OCA\Dossiq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Integration;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the parties, cases, case types and statuses of the case leaf, as the caller.
 *
 * @spec openspec/specs/case-leaf/spec.md
 */
final class CaseLeafReader {

	use SearchesObjects;

	/**
	 * The schema of the objects a case field points at.
	 *
	 * @var array<string, string>
	 */
	private const LINKED_SCHEMAS = [
		'caseType' => 'caseType',
		'status' => 'statusType',
	];

	/**
	 * Constructor.
	 *
	 * @param object          $objectService OpenRegister's object service.
	 * @param string          $register      The register.
	 * @param LoggerInterface $logger        Logger.
	 *
	 * @spec openspec/specs/case-leaf/spec.md#requirement-a-host-app-reads-a-partys-cases-by-master-entity
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $register,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The ids of the parties that carry the master entity.
	 *
	 * @param string $ref The master entity reference.
	 *
	 * @return array<int, string> The party ids.
	 *
	 * @spec openspec/specs/case-leaf/spec.md#requirement-a-host-app-reads-a-partys-cases-by-master-entity
	 */
	public function partyIds(string $ref): array {
		$ids = [];
		foreach (CaseLeafProvider::PARTY_SCHEMAS as $schema) {
			foreach ($this->read(schema: $schema, filters: ['masterEntityRef' => $ref]) as $party) {
				$id = (string)($party['id'] ?? '');
				if ($id !== '' && (string)($party['masterEntityRef'] ?? '') === $ref) {
					$ids[$id] = true;
				}
			}
		}

		return array_keys($ids);
	}//end partyIds()

	/**
	 * The cases the parties requested that fit the status filter, by id.
	 *
	 * @param array<int, string> $partyIds The party ids.
	 * @param string             $status   `open`, `closed` or `all`.
	 *
	 * @return array<string, array<string, mixed>> The cases by id.
	 *
	 * @spec openspec/specs/case-leaf/spec.md#requirement-a-host-app-reads-a-partys-cases-by-master-entity
	 */
	public function casesOf(array $partyIds, string $status): array {
		$cases = [];
		foreach ($partyIds as $partyId) {
			foreach ($this->requestedBy(partyId: $partyId, status: $status) as $id => $case) {
				$cases[$id] = $case;
			}
		}

		return $cases;
	}//end casesOf()

	/**
	 * The cases one party requested that fit the status filter, by id.
	 *
	 * @param string $partyId The party id.
	 * @param string $status  `open`, `closed` or `all`.
	 *
	 * @return array<string, array<string, mixed>> The cases by id.
	 */
	private function requestedBy(string $partyId, string $status): array {
		$cases = [];
		foreach ($this->read(schema: 'case', filters: ['requester' => $partyId]) as $case) {
			$id = (string)($case['id'] ?? '');
			if ($id !== '' && $this->matches(case: $case, status: $status) === true) {
				$cases[$id] = $case;
			}
		}

		return $cases;
	}//end requestedBy()

	/**
	 * The objects the cases point at through one field, by id, read once.
	 *
	 * @param array<string, array<string, mixed>> $cases The cases.
	 * @param string                              $field The field holding the id: `caseType` or `status`.
	 *
	 * @return array<string, array<string, mixed>> The objects by id.
	 *
	 * @spec openspec/specs/case-leaf/spec.md#requirement-a-host-app-reads-a-partys-cases-by-master-entity
	 */
	public function linkedById(array $cases, string $field): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map(static fn (array $case): string => (string)($case[$field] ?? ''), $cases),
					static fn (string $id): bool => $id !== ''
				)
			)
		);
		if ($ids === []) {
			return [];
		}

		$found = [];
		foreach ($this->read(schema: self::LINKED_SCHEMAS[$field], filters: ['_ids' => $ids]) as $row) {
			$found[(string)($row['id'] ?? '')] = $row;
		}

		return $found;
	}//end linkedById()

	/**
	 * Whether a case fits the asked status filter.
	 *
	 * @param array<string, mixed> $case   The stored case.
	 * @param string               $status `open`, `closed` or `all`.
	 *
	 * @return bool True when it fits.
	 */
	private function matches(array $case, string $status): bool {
		if ($status === 'all') {
			return true;
		}

		return (($case['isFinalStatus'] ?? false) === true) === ($status === 'closed');
	}//end matches()

	/**
	 * Read objects as the caller; a failed read is logged and reads as none.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function read(string $schema, array $filters): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $this->objectService,
				register: $this->register,
				schema: $schema,
				filters: array_merge($filters, ['_limit' => CaseLeafProvider::MAX_ROWS])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq case leaf: reading {schema} failed: {message}',
				['schema' => $schema, 'message' => $e->getMessage()]
			);
			return [];
		}
	}//end read()
}//end class
