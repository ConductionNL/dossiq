<?php

/**
 * ZRC rules for the sub-resources of a zaak
 *
 * A status, resultaat, rol or zaakeigenschap must name a type that belongs to
 * the zaaktype of its zaak (zrc-016, zrc-018, zrc-019, zrc-020).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwRulesBase;

/**
 * Create rules for statussen, resultaten, rollen and zaakeigenschappen.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZrcSubResourceRules extends ZgwRulesBase {
	/**
	 * Rules for creating a status (POST /zaken/v1/statussen).
	 *
	 * Implements:
	 * - zrc-016: Validate that statustype belongs to Zaak.zaaktype.statustypen.
	 *
	 * NOTE: From `status-transition-engine` onwards, the actual `case.status`
	 * mutation is owned by `StatusTransitionService`. This method retains
	 * the zrc-016 ZGW request-shape validation only; callers that want a
	 * `statusRecord` written for every status mutation should funnel through
	 * `StatusTransitionService::execute()` or `executeFreeForm()`. The legacy
	 * write path remains for ZGW API contract compatibility.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 */
	public function rulesStatussenCreate(array $body): array {
		// Zrc-016: Validate statustype belongs to zaak's zaaktype.
		$statustypeUrl = $body['statustype'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($statustypeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $statustypeUrl,
				fieldName: 'statustype',
				typeSchemaKey: 'status_type_schema'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
	}//end rulesStatussenCreate()

	/**
	 * Rules for creating a resultaat (POST /zaken/v1/resultaten).
	 *
	 * Implements:
	 * - zrc-020: Validate that resultaattype belongs to Zaak.zaaktype.resultaattypen.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesResultatenCreate(array $body): array {
		// Zrc-020: Validate resultaattype belongs to zaak's zaaktype.
		$resultaattypeUrl = $body['resultaattype'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($resultaattypeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $resultaattypeUrl,
				fieldName: 'resultaattype',
				typeSchemaKey: 'result_type_schema'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
	}//end rulesResultatenCreate()

	/**
	 * Rules for creating a rol (POST /zaken/v1/rollen).
	 *
	 * Implements:
	 * - zrc-019: Validate that roltype belongs to Zaak.zaaktype.roltypen.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesRollenCreate(array $body): array {
		// Zrc-019: Validate roltype belongs to zaak's zaaktype.
		$roltypeUrl = $body['roltype'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($roltypeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $roltypeUrl,
				fieldName: 'roltype',
				typeSchemaKey: 'role_type_schema'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
	}//end rulesRollenCreate()

	/**
	 * Rules for creating a zaakeigenschap (POST /zaken/{zaakUuid}/zaakeigenschappen).
	 *
	 * Implements:
	 * - zrc-018: Validate that eigenschap belongs to Zaak.zaaktype.eigenschappen.
	 *
	 * @param array $body The ZGW request body
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function rulesZaakeigenschappenCreate(array $body): array {
		// Zrc-018: Validate eigenschap belongs to zaak's zaaktype.
		$attributeUrl = $body['eigenschap'] ?? '';
		$caseUrl = $body['case'] ?? '';
		if ($attributeUrl !== '' && $caseUrl !== '') {
			$error = $this->validateSubResourceType(
				caseUrl: $caseUrl,
				typeUrl: $attributeUrl,
				fieldName: 'eigenschap',
				typeSchemaKey: 'property_definition_schema'
			);
			if ($error !== null) {
				return $error;
			}
		}

		return $this->isValid(body: $body);
	}//end rulesZaakeigenschappenCreate()

	/**
	 * Validate a sub-resource type belongs to the zaak's zaaktype (zrc-016..020).
	 *
	 * Checks that the type object the URL points at references the zaak's zaaktype. An unknown
	 * type is refused like a mismatching one; an unresolvable zaak or zaaktype skips the check.
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $typeUrl The sub-resource type URL (statustype, roltype, etc.)
	 * @param string $fieldName The field name for error reporting
	 * @param string $typeSchemaKey Settings key for the type's schema
	 *
	 * @return array|null Validation error, or null if valid
	 */
	private function validateSubResourceType(
		string $caseUrl,
		string $typeUrl,
		string $fieldName,
		string $typeSchemaKey,
	): ?array {
		$zaaktypeUuid = $this->getCaseTypeUuidOfCase(caseUrl: $caseUrl);
		if ($zaaktypeUuid === null) {
			return null;
		}

		// Extract UUID from the provided type URL.
		$typeUuid = $this->extractUuid(url: $typeUrl);
		if ($typeUuid === null) {
			return null;
		}

		// Look up the type object and verify its caseType references this zaaktype.
		$typeData = $this->findBySchemaKey(uuid: $typeUuid, schemaKey: $typeSchemaKey);
		if ($typeData !== null && $this->extractUuid(url: (string)($typeData['caseType'] ?? '')) === $zaaktypeUuid) {
			return null;
		}

		$detail = "Het {$fieldName} hoort niet bij het zaaktype van de zaak.";
		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [$this->fieldError(fieldName: 'nonFieldErrors', code: 'zaaktype-mismatch', reason: $detail)]
		);
	}//end validateSubResourceType()

	/**
	 * The uuid of the zaaktype a zaak URL's zaak points at.
	 *
	 * @param string $caseUrl The zaak URL
	 *
	 * @return string|null The zaaktype uuid, or null when the zaak or its zaaktype cannot be resolved
	 */
	private function getCaseTypeUuidOfCase(string $caseUrl): ?string {
		if ($this->objectService === null) {
			return null;
		}

		$zaakUuid = $this->extractUuid(url: $caseUrl);
		if ($zaakUuid === null) {
			return null;
		}

		$caseTypeId = $this->findBySchemaKey(uuid: $zaakUuid, schemaKey: 'case_schema')['caseType'] ?? '';
		if (empty($caseTypeId) === true) {
			return null;
		}

		return $this->extractUuid(url: (string)$caseTypeId);
	}//end getCaseTypeUuidOfCase()
}//end class
