<?php

/**
 * Dossiq AnonymisedCaseExport.
 *
 * Exports a list of cases with every field their case type marks as
 * identifying removed or generalised: an address to its postcode area, a
 * date to its year, anything else removed. The export is written to the
 * processing log.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Export
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
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Export;

use OCA\Dossiq\Kernel\Contracts\TenantAuditTrailServiceInterface;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Anonymises a case list by the identifying fields of each case's case type.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
 */
class AnonymisedCaseExport {

	use SearchesObjects;

	/**
	 * The most cases one export reads.
	 *
	 * @var integer
	 */
	public const MAX_CASES = 1000;

	/**
	 * A Dutch postcode: four digits, then two letters.
	 *
	 * @var string
	 */
	private const POSTCODE = '/\b([1-9][0-9]{3})\s?[A-Za-z]{2}\b/';

	/**
	 * A date or moment, read for its year.
	 *
	 * @var string
	 */
	private const DATE = '/^([0-9]{4})-[0-9]{2}-[0-9]{2}/';

	/**
	 * The keys of an address object that hold its postcode.
	 *
	 * @var array<int, string>
	 */
	private const POSTCODE_KEYS = ['postcode', 'postalCode', 'zipCode'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService                  $settingsService Where the register and schemas are configured.
	 * @param TenantAuditTrailServiceInterface $auditTrail      The processing log.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TenantAuditTrailServiceInterface $auditTrail,
	) {
	}//end __construct()

	/**
	 * The listed cases, anonymised, and the export written to the processing log.
	 *
	 * A case that cannot be found is left out. A case type that cannot be
	 * read refuses the whole export: without its identifying fields nothing
	 * says what must be removed, and an export that might hold them does not leave.
	 *
	 * @param array<int, mixed> $caseIds The cases of the filtered list.
	 * @param string            $userId  Who exports.
	 *
	 * @return array<int, array<string, mixed>> The anonymised cases, in list order.
	 *
	 * @throws RuntimeException When nothing is listed, too much is, or a case type cannot be read.
	 *
	 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
	 */
	public function export(array $caseIds, string $userId): array {
		$ids = $this->listedIds(caseIds: $caseIds);
		$register = new DossierRegister(settingsService: $this->settingsService);
		[$objectService, $registerId] = $register->context();
		$caseSchema = $register->schema(key: 'case_schema');
		$caseTypeSchema = $register->schema(key: 'case_type_schema');

		$fieldsByType = [];
		$rows = [];
		foreach ($ids as $id) {
			$case = $this->findObjectAsArray(objectService: $objectService, register: $registerId, schema: $caseSchema, id: $id);
			if ($case === null) {
				continue;
			}

			$typeId = trim((string)($case['caseType'] ?? ''));
			if (array_key_exists($typeId, $fieldsByType) === false) {
				$fieldsByType[$typeId] = $this->identifyingFieldsOf(
					caseType: $this->caseTypeOf(objectService: $objectService, register: $registerId, schema: $caseTypeSchema, id: $typeId)
				);
			}

			$rows[] = $this->anonymise(row: $case, identifyingFields: $fieldsByType[$typeId]);
		}

		$this->auditTrail->emit(
			[
				'action' => 'case_list_exported_anonymised',
				'actor' => $userId,
				'resource' => 'cases',
				'bio' => ['count' => count($rows), 'caseTypes' => array_keys($fieldsByType)],
			]
		);

		return $rows;
	}//end export()

	/**
	 * One case with its identifying fields removed or generalised.
	 *
	 * The register's own `@self` block goes too: it carries who created and
	 * owns the object, which an anonymised export has no use for.
	 *
	 * @param array<string, mixed> $row               The case.
	 * @param array<int, string>   $identifyingFields The case type's identifying fields.
	 *
	 * @return array<string, mixed> The anonymised case.
	 *
	 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
	 */
	public function anonymise(array $row, array $identifyingFields): array {
		unset($row['@self']);
		foreach ($identifyingFields as $field) {
			if (array_key_exists($field, $row) === false) {
				continue;
			}

			$general = $this->generalise(value: $row[$field]);
			if ($general === null) {
				unset($row[$field]);
				continue;
			}

			$row[$field] = $general;
		}

		return $row;
	}//end anonymise()

	/**
	 * What an identifying value may still say: a postcode area, a year, or nothing.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The generalised value, or null to remove it.
	 */
	private function generalise(mixed $value): ?string {
		if (is_array($value) === true) {
			foreach (self::POSTCODE_KEYS as $key) {
				if (is_string($value[$key] ?? null) === true) {
					return $this->postcodeArea(text: $value[$key]);
				}
			}

			return null;
		}

		if (is_string($value) === false) {
			return null;
		}

		if (preg_match(self::DATE, trim($value), $match) === 1) {
			return $match[1];
		}

		return $this->postcodeArea(text: $value);
	}//end generalise()

	/**
	 * The four digits of the postcode in a text, or null when there is none.
	 *
	 * @param string $text The text.
	 *
	 * @return string|null The postcode area.
	 */
	private function postcodeArea(string $text): ?string {
		if (preg_match(self::POSTCODE, $text, $match) === 1) {
			return $match[1];
		}

		return null;
	}//end postcodeArea()

	/**
	 * The listed ids, trimmed, once each.
	 *
	 * @param array<int, mixed> $caseIds The ids as asked.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @throws RuntimeException When none is listed or more than MAX_CASES.
	 */
	private function listedIds(array $caseIds): array {
		$ids = [];
		foreach ($caseIds as $id) {
			if (is_scalar($id) === true && trim((string)$id) !== '') {
				$ids[trim((string)$id)] = true;
			}
		}

		if ($ids === []) {
			throw new RuntimeException('No cases to export');
		}

		if (count($ids) > self::MAX_CASES) {
			throw new RuntimeException('At most ' . self::MAX_CASES . ' cases can be exported at once');
		}

		return array_keys($ids);
	}//end listedIds()

	/**
	 * The case type, or a refusal when it cannot be read.
	 *
	 * @param object $objectService The object service.
	 * @param string $register      The register.
	 * @param string $schema        The case type schema.
	 * @param string $id            The case type id.
	 *
	 * @return array<string, mixed> The case type.
	 *
	 * @throws RuntimeException When the case type cannot be read.
	 */
	private function caseTypeOf(object $objectService, string $register, string $schema, string $id): array {
		$caseType = null;
		if ($id !== '') {
			$caseType = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
		}

		if ($caseType === null) {
			throw new RuntimeException('The case type of a listed case could not be read, so nothing was exported');
		}

		return $caseType;
	}//end caseTypeOf()

	/**
	 * The property names a case type marks as identifying.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return array<int, string> The field names.
	 */
	private function identifyingFieldsOf(array $caseType): array {
		$fields = [];
		foreach ((array)($caseType['identifyingFields'] ?? []) as $field) {
			if (is_string($field) === true && trim($field) !== '') {
				$fields[] = trim($field);
			}
		}

		return $fields;
	}//end identifyingFieldsOf()
}//end class
