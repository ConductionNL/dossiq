<?php

/**
 * The result half of a closing transition.
 *
 * Kept apart from CaseStatusStore on purpose. The store owns the case, its
 * status records and their history; this owns one question — "what came of
 * the case" — and the three rows it takes to answer it (the statusType that
 * says the case is closing, the resultType the handler picked, and the
 * `result` row written between them). Folding it into the store would have
 * pushed that class past the public-method and complexity ceilings for a
 * concern it does not share.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use RuntimeException;

/**
 * Reads whether a status closes a case, and writes the result it closes with.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class CaseResultWriter {

	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService  Bridge to OpenRegister + config.
	 * @param CaseTypeResolver $caseTypeResolver The effective blueprint, so a child type
	 *                                          closes with its parent's result types.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
	) {
	}//end __construct()

	/**
	 * Answer whether a statusType is a terminal one.
	 *
	 * A statusType that cannot be read answers false. The asymmetry is
	 * deliberate: a false negative only means the result question goes
	 * unasked, while a false positive would refuse an ordinary transition on
	 * a row nobody could read.
	 *
	 * @param string $statusTypeId StatusType UUID.
	 *
	 * @return bool True when the statusType carries isFinal.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function isFinalStatus(string $statusTypeId): bool {
		$statusType = $this->findRow(schemaKey: 'status_type_schema', id: $statusTypeId);
		$flag = ($statusType['isFinal'] ?? false);

		return in_array($flag, [true, 1, '1', 'true'], true);
	}//end isFinalStatus()

	/**
	 * Resolve the `result` reference a closing transition writes onto a case.
	 *
	 * Returns null when nothing is to be written: the case type declares no
	 * result types, so there is no question to ask. Refuses when it declares
	 * some and the caller picked none — a case that closes with an unanswered
	 * result is one no archivist, citizen or WOO request can read later.
	 *
	 * @param string $caseId Case UUID.
	 * @param string $caseTypeId The case's caseType UUID.
	 * @param string|null $resultTypeId The resultType the handler picked.
	 *
	 * @return string|null The written result's UUID, or null when none is needed.
	 *
	 * @throws RuntimeException When a result is required and none was given, or
	 *                          when the result row cannot be written.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function resolveClosingResult(string $caseId, string $caseTypeId, ?string $resultTypeId): ?string {
		if ($resultTypeId === null || $resultTypeId === '') {
			if ($this->listResultTypeIds(caseTypeId: $caseTypeId) === []) {
				return null;
			}

			throw new RuntimeException('result_type_required');
		}

		$result = $this->writeResult(caseId: $caseId, resultTypeId: $resultTypeId);
		$resultId = (string)($result['id'] ?? ($result['@self']['id'] ?? ''));
		if ($resultId === '') {
			throw new RuntimeException('result_not_written');
		}

		return $resultId;
	}//end resolveClosingResult()

	/**
	 * List the result type UUIDs a case type offers.
	 *
	 * 🔴 THROUGH THE RESOLVER. Asking the store for `resultType where caseType
	 * = X` answers with the type's OWN rows, so a child type that derives its
	 * results from a parent declared none — and a closing transition on a case
	 * of that type wrote no result at all, silently, which is exactly the
	 * unanswered result this class exists to prevent.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, string> The result type UUIDs, empty when none or unreadable.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function listResultTypeIds(string $caseTypeId): array {
		$ids = [];
		foreach ($this->caseTypeResolver->resultTypesFor(caseTypeId: $caseTypeId) as $row) {
			$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end listResultTypeIds()

	/**
	 * Write the `result` row itself.
	 *
	 * @param string $caseId Case UUID.
	 * @param string $resultTypeId The chosen resultType UUID.
	 *
	 * @return array<string, mixed> The written result row.
	 *
	 * @throws RuntimeException When OpenRegister or the result schema is unavailable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function writeResult(string $caseId, string $resultTypeId): array {
		$context = $this->resolveContext(schemaKey: 'result_schema');
		if ($context === null) {
			throw new RuntimeException('result_schema_not_configured');
		}

		$payload = [
			'case' => $caseId,
			'resultType' => $resultTypeId,
			'name' => $this->lookupResultTypeName(resultTypeId: $resultTypeId),
		];

		return $this->toArray(
			value: $context['objectService']->saveObject(
				object: $payload,
				register: $context['register'],
				schema: $context['schema'],
			),
		);
	}//end writeResult()

	/**
	 * The result type's own name, so the result row reads as a word.
	 *
	 * @param string $resultTypeId ResultType UUID.
	 *
	 * @return string The name, or the id when it cannot be resolved.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function lookupResultTypeName(string $resultTypeId): string {
		$row = $this->findRow(schemaKey: 'result_type_schema', id: $resultTypeId);
		$name = (string)($row['name'] ?? ($row['title'] ?? ''));
		if ($name === '') {
			return $resultTypeId;
		}

		return $name;
	}//end lookupResultTypeName()

	/**
	 * Read one row of a configured schema by id.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param string $id The row's UUID.
	 *
	 * @return array<string, mixed> The row, empty when unresolvable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function findRow(string $schemaKey, string $id): array {
		$context = $this->resolveContext(schemaKey: $schemaKey);
		if ($context === null || $id === '') {
			return [];
		}

		try {
			return $this->toArray(
				value: $context['objectService']->find($id, register: $context['register'], schema: $context['schema']),
			);
		} catch (\Throwable $e) {
			return [];
		}
	}//end findRow()

	/**
	 * The object service plus the register/schema pair a read or write needs.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 *
	 * @return array{objectService: mixed, register: string, schema: string}|null
	 *         The context, or null when OpenRegister or the schema is unconfigured.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function resolveContext(string $schemaKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end resolveContext()

	/**
	 * Coerce ObjectService results to an array.
	 *
	 * @param mixed $value Raw result.
	 *
	 * @return array<string, mixed> The coerced array, empty when uncoercible.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialized = $value->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return [];
	}//end toArray()
}//end class
