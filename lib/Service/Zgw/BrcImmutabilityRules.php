<?php

/**
 * BRC rules for what a besluit may not change
 *
 * A besluit keeps its besluittype (brc-001), its identificatie and its
 * verantwoordelijkeOrganisatie (brc-002) for life. A PUT that leaves the last
 * two out gets them from the stored besluit.
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
 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\ZgwRulesBase;

/**
 * Update and patch rules of a besluit.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class BrcImmutabilityRules extends ZgwRulesBase {
	/**
	 * Rules for updating a besluit (PUT /besluiten/v1/besluiten/{uuid}).
	 *
	 * Implements brc-001 and brc-002 immutability.
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/besluiten/
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function rulesBesluitenUpdate(array $body, ?array $existingObject = null): array {
		$result = $this->isValid(body: $body);

		$result = $this->checkDecisionTypeImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		$result = $this->checkDecisionFieldImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		// Preserve immutable fields from existing object when omitted in PUT body.
		$result = $this->preserveImmutableDecisionFields(
			result: $result,
			existingObject: $existingObject
		);

		return $result;
	}//end rulesBesluitenUpdate()

	/**
	 * Rules for patching a besluit (PATCH /besluiten/v1/besluiten/{uuid}).
	 *
	 * @param array $body The ZGW request body
	 * @param array|null $existingObject The existing besluit data
	 *
	 * @return array The validation result
	 *
	 * @see rulesBesluitenUpdate() Same immutability rules apply.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function rulesBesluitenPatch(array $body, ?array $existingObject = null): array {
		$result = $this->isValid(body: $body);

		$result = $this->checkDecisionTypeImmutability(
			result: $result,
			existingObject: $existingObject
		);
		if ($result['valid'] === false) {
			return $result;
		}

		return $this->checkDecisionFieldImmutability(
			result: $result,
			existingObject: $existingObject
		);
	}//end rulesBesluitenPatch()

	/**
	 * Check that besluittype is not changed on update/patch (brc-001).
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result
	 */
	private function checkDecisionTypeImmutability(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];
		$newBesluittype = $body['besluittype'] ?? null;
		$existBesluittype = $existingObject['decisionType'] ?? '';

		if ($newBesluittype !== null && $existBesluittype !== '') {
			$newUuid = $this->extractUuid(url: $newBesluittype);
			if ($newUuid !== null && $newUuid !== $existBesluittype
				&& $this->extractUuid(url: $existBesluittype) !== $newUuid
			) {
				return $this->fieldImmutableError(fieldName: 'besluittype');
			}
		}

		return $result;
	}//end checkDecisionTypeImmutability()

	/**
	 * Check besluit field immutability (brc-002).
	 *
	 * Identificatie and verantwoordelijkeOrganisatie are immutable after creation.
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result
	 */
	private function checkDecisionFieldImmutability(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];

		// Brc-002: identificatie is immutable.
		if (isset($body['identificatie']) === true) {
			$existingId = $existingObject['title'] ?? $existingObject['identifier'] ?? '';
			if ($existingId === '') {
				$existingId = $existingObject['identificatie'] ?? '';
			}

			if ($existingId !== '' && $body['identificatie'] !== $existingId) {
				return $this->fieldImmutableError(fieldName: 'identificatie');
			}
		}

		// Brc-002: verantwoordelijkeOrganisatie is immutable.
		if (isset($body['verantwoordelijkeOrganisatie']) === true) {
			$orgKey = 'responsibleOrganisation';
			$orgFallback = 'verantwoordelijkeOrganisatie';
			$existingOrg = $existingObject[$orgKey] ?? $existingObject[$orgFallback] ?? '';
			if ($existingOrg !== '' && $body['verantwoordelijkeOrganisatie'] !== $existingOrg) {
				return $this->fieldImmutableError(fieldName: 'verantwoordelijkeOrganisatie');
			}
		}

		return $result;
	}//end checkDecisionFieldImmutability()

	/**
	 * Preserve immutable besluit fields from existing object when omitted in PUT body.
	 *
	 * @param array $result The current validation result
	 * @param array|null $existingObject The existing object data
	 *
	 * @return array The updated validation result with preserved fields
	 */
	private function preserveImmutableDecisionFields(array $result, ?array $existingObject): array {
		if ($existingObject === null) {
			return $result;
		}

		$body = $result['enrichedBody'];

		if (isset($body['identificatie']) === false || $body['identificatie'] === '') {
			$existingId = $existingObject['title'] ?? $existingObject['identifier'] ?? '';
			if ($existingId === '') {
				$existingId = $existingObject['identificatie'] ?? '';
			}

			if ($existingId !== '') {
				$body['identificatie'] = $existingId;
			}
		}

		if (isset($body['verantwoordelijkeOrganisatie']) === false
			|| $body['verantwoordelijkeOrganisatie'] === ''
		) {
			$orgKey = 'responsibleOrganisation';
			$orgFallback = 'verantwoordelijkeOrganisatie';
			$existingOrg = $existingObject[$orgKey] ?? $existingObject[$orgFallback] ?? '';
			if ($existingOrg !== '') {
				$body['verantwoordelijkeOrganisatie'] = $existingOrg;
			}
		}

		$result['enrichedBody'] = $body;

		return $result;
	}//end preserveImmutableDecisionFields()
}//end class
