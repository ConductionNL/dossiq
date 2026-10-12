<?php

/**
 * ZRC values a zaak takes from its zaaktype
 *
 * What a zaak inherits from the zaaktype it names: the handler a new zaak gets
 * when it states none, and the vertrouwelijkheidaanduiding (zrc-009), which the
 * zaaktype always decides when it has one.
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

use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use OCA\Dossiq\Service\ZgwRulesBase;

/**
 * Default assignee and confidentiality of a zaak, read from its zaaktype.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZrcCaseTypeDefaults extends ZgwRulesBase {
	/**
	 * Stamp the zaaktype's `defaultAssignee` on a zaak that carries no handler yet.
	 *
	 * @param array $body The ZGW request body
	 * @param mixed $caseTypeUrl The `zaaktype` value from the request body
	 *
	 * @return array The body, with `assignee` filled in when one could be resolved
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function applyDefaultAssignee(array $body, mixed $caseTypeUrl): array {
		if (empty($body['assignee']) === false || empty($caseTypeUrl) === true || $this->objectService === null) {
			return $body;
		}

		$assignee = $this->caseTypeDefaultAssignee(objectService: $this->objectService, caseTypeUrl: $caseTypeUrl);
		if ($assignee !== null) {
			$body['assignee'] = $assignee;
		}

		return $body;
	}//end applyDefaultAssignee()

	/**
	 * Read the `defaultAssignee` off the zaaktype a zaak points at.
	 *
	 * Returns null whenever the zaaktype cannot be resolved or declares no default — a lookup
	 * failure is swallowed, exactly as the inline block did.
	 *
	 * @param object $objectService The OpenRegister ObjectService
	 * @param mixed $caseTypeUrl The `zaaktype` value from the request body
	 *
	 * @return mixed The default assignee, or null when there is none
	 */
	private function caseTypeDefaultAssignee(object $objectService, mixed $caseTypeUrl): mixed {
		$extractedUuid = $this->extractUuid(url: $caseTypeUrl);
		if ($extractedUuid === null) {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$caseType = $objectService->find(
				id: $extractedUuid,
				register: $register,
				schema: $schema
			);

			$ztData = $caseType;
			if (is_array($caseType) === false) {
				$ztData = $caseType->jsonSerialize();
			}

			// CaseTypeHandling is the one reader: the declared block first, the
			// legacy `defaultAssignee` for a case type nobody has migrated.
			$handler = (new CaseTypeHandling())->defaultHandler(caseType: (array)$ztData);
			if ($handler !== '') {
				return $handler;
			}
		} catch (\Throwable $e) {
			// Zaaktype not found; skip auto-assignment.
			return null;
		}//end try

		return null;
	}//end zaaktypeDefaultAssignee()

	/**
	 * Derive vertrouwelijkheidaanduiding from zaaktype (zrc-009).
	 *
	 * The zaaktype's vertrouwelijkheidaanduiding ALWAYS overrides any value from
	 * the request or mapping template to prevent template leakage. The incoming
	 * value is only used as a fallback when the zaaktype field is absent.
	 *
	 * @param array $body The request body
	 * @param string $caseTypeUrl The zaaktype URL
	 *
	 * @return array The body with derived vertrouwelijkheidaanduiding
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function deriveVertrouwelijkheidaanduiding(array $body, string $caseTypeUrl): array {
		$uuid = $this->extractUuid(url: $caseTypeUrl);
		if ($uuid === null) {
			return $body;
		}

		$ztData = $this->findBySchemaKey(uuid: $uuid, schemaKey: 'case_type_schema');
		if ($ztData === null) {
			return $body;
		}

		// Zrc-009: zaaktype value always overrides template default to prevent leakage.
		$val = $ztData['confidentiality'] ?? ($ztData['confidentialityDesignation'] ?? ($ztData['vertrouwelijkheidaanduiding'] ?? ''));
		if ($val !== '') {
			$body['vertrouwelijkheidaanduiding'] = $val;
		}

		// When zaaktype has no value, preserve any incoming value (fallback).
		return $body;
	}//end deriveVertrouwelijkheidaanduiding()
}//end class
