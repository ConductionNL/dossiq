<?php

/**
 * Procest MCP case reader.
 *
 * Every OpenRegister read the Procest MCP tools perform, plus the shape
 * normalisation that turns whatever ObjectService returns (entity, array,
 * null) into a plain associative array. Split out of ProcestToolProvider so
 * that provider keeps only the MCP protocol surface — the tool catalogue,
 * argument parsing and the dispatch envelope — while the knowledge of which
 * register/schema a case lives in, how its transition history is ordered, and
 * how a source descriptor is built lives here and nowhere else.
 *
 * @category Mcp
 * @package  OCA\Procest\Mcp\Tool
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Mcp\Tool;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Reads Procest cases and their transition history for the MCP tools.
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */
class ProcestCaseReader {
	use SearchesObjects;

	/**
	 * Maximum number of items / source descriptors returned per tool result.
	 *
	 * @var int
	 */
	public const ITEMS_CAP = 20;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The Procest settings service (OpenRegister bridge + config).
	 * @param LoggerInterface $logger The PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the OpenRegister object store + configured register/case schema.
	 *
	 * Returns a discriminated result rather than an MCP error envelope: the
	 * envelope shape is the provider's protocol concern, this class only
	 * reports why the store could not be resolved.
	 *
	 * @return array The resolved store, or the reason it is unavailable.
	 *
	 * @phpstan-return array{ok: true, objectService: object, register: string, caseSchema: string}|array{ok: false, code: string, message: string}
	 * @psalm-return   array{ok: true, objectService: object, register: string, caseSchema: string}|array{ok: false, code: string, message: string}
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function resolveCaseStore(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [
				'ok' => false,
				'code' => 'storage_unavailable',
				'message' => 'The OpenRegister object store is not available.',
			];
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($register === '' || $caseSchema === '') {
			return [
				'ok' => false,
				'code' => 'not_configured',
				'message' => 'The Procest case schema is not configured.',
			];
		}

		return [
			'ok' => true,
			'objectService' => $objectService,
			'register' => $register,
			'caseSchema' => $caseSchema,
		];
	}//end resolveCaseStore()

	/**
	 * Find cases via the OpenRegister object store.
	 *
	 * @param array<string, mixed> $store The resolved case store.
	 * @param array<string, mixed> $filters The OpenRegister filter map.
	 * @param int $limit The maximum number of rows to fetch.
	 *
	 * @return array<int, mixed>|null The raw rows, or null on backend failure.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function findCases(array $store, array $filters, int $limit): ?array {
		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $store['caseSchema'],
				filters: array_merge($filters, ['_limit' => $limit]),
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest MCP: listProcesses search failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $rows;
	}//end findCases()

	/**
	 * Find a single case via the OpenRegister object store.
	 *
	 * @param array<string, mixed> $store The resolved case store.
	 * @param string $caseId The case id or uuid.
	 *
	 * @return array<string, mixed>|null The case array (empty when not found), or null on backend failure.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function findCase(array $store, string $caseId): ?array {
		try {
			return $this->toArray(value: $store['objectService']->find($caseId, register: $store['register'], schema: $store['caseSchema']));
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest MCP: getProcessDetails findObject failed',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end findCase()

	/**
	 * Load the chronological transition history (statusRecord rows) for a case.
	 *
	 * @param array<string, mixed> $store The resolved case store.
	 * @param string $caseUuid The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The history rows, oldest first.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function loadHistory(array $store, string $caseUuid): array {
		if ($caseUuid === '') {
			return [];
		}

		$recordSchema = $this->settingsService->getConfigValue(key: 'status_record_schema');
		if ($recordSchema === '') {
			return [];
		}

		try {
			$records = $this->searchObjectsAsArrays(
				objectService: $store['objectService'],
				register: $store['register'],
				schema: $recordSchema,
				filters: ['case' => $caseUuid, '_limit' => self::ITEMS_CAP],
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Procest MCP: loadHistory search failed',
				['caseUuid' => $caseUuid, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$rows = [];
		if (is_array($records) === true) {
			$rows = $records;
		}

		$list = [];
		foreach ($rows as $record) {
			$list[] = $this->toArray(value: $record);
		}

		usort(
			$list,
			static function (array $left, array $right): int {
				$leftAt = (string)($left['createdAt'] ?? ($left['@self']['createdAt'] ?? ''));
				$rightAt = (string)($right['createdAt'] ?? ($right['@self']['createdAt'] ?? ''));
				return strcmp($leftAt, $rightAt);
			}
		);

		return $list;
	}//end loadHistory()

	/**
	 * Build a source descriptor for a case.
	 *
	 * @param array<string, mixed> $case The case array.
	 *
	 * @return array{type: string, uuid: string, url: string, label: string} The source descriptor.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function buildCaseSource(array $case): array {
		$uuid = $this->extractUuid(item: $case);
		return [
			'type' => 'procest.case',
			'uuid' => $uuid,
			'url' => "/apps/procest/cases/{$uuid}",
			'label' => (string)($case['title'] ?? ($case['identifier'] ?? 'Case')),
		];
	}//end buildCaseSource()

	/**
	 * Normalise an OpenRegister object (entity / array / null) to a plain array.
	 *
	 * @param mixed $value Raw value from ObjectService.
	 *
	 * @return array<string, mixed> The normalised object.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === false) {
			return [];
		}

		if (method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (method_exists($value, 'getObject') === true) {
			$object = $value->getObject();
			if (is_array($object) === true) {
				return $object;
			}
		}

		return (array)$value;
	}//end toArray()

	/**
	 * Extract the uuid from a normalised object array.
	 *
	 * @param array<string, mixed> $item The normalised object array.
	 *
	 * @return string The uuid, or empty string when not found.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	public function extractUuid(array $item): string {
		$uuid = $item['uuid'] ?? ($item['id'] ?? ($item['@self']['uuid'] ?? ($item['@self']['id'] ?? '')));
		return (string)$uuid;
	}//end extractUuid()
}//end class
