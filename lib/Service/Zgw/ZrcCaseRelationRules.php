<?php

/**
 * ZRC rules for what a zaak points at
 *
 * The rules that need another object to answer: the hoofdzaak a deelzaak names
 * (zrc-013, including the one-level nesting limit) and the products or services
 * a zaak may carry, which are the ones its zaaktype offers (zrc-015).
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
 * Hoofdzaak and productenOfDiensten rules of a zaak.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class ZrcCaseRelationRules extends ZgwRulesBase {
	/**
	 * Zrc-013: the hoofdzaak is a resource URL, not the zaak itself, and not a deelzaak.
	 *
	 * @param array $body The request body
	 * @param array|null $existingObject The existing zaak, null on create
	 *
	 * @return array|null The refusal, or null when acceptable
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function checkHoofdzaak(array $body, ?array $existingObject): ?array {
		$hoofdzaak = $body['hoofdzaak'] ?? null;
		if ($hoofdzaak === null || $hoofdzaak === '') {
			return null;
		}

		if ($this->isValidUrl(url: $hoofdzaak) === false) {
			return $this->error(
				status: 400,
				detail: 'De hoofdzaak URL is ongeldig.',
				invalidParams: [
					$this->fieldError(fieldName: 'hoofdzaak', code: 'bad-url', reason: 'De URL is ongeldig.'),
				]
			);
		}

		// Zrc-013d: A zaak cannot be a deelzaak of itself.
		$selfUuid      = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
		$hoofdzaakUuid = $this->extractUuid(url: $hoofdzaak);
		if ($selfUuid !== null && $hoofdzaakUuid !== null && $selfUuid === $hoofdzaakUuid) {
			return $this->error(
				status: 400,
				detail: 'Een zaak kan niet zijn eigen hoofdzaak zijn.',
				invalidParams: [$this->fieldError(
					fieldName: 'hoofdzaak',
					code: 'self-forbidden',
					reason: 'Een zaak kan niet zijn eigen hoofdzaak zijn.'
				)
				]
			);
		}

		// Zrc-013c: Deelzaak of deelzaak is not allowed.
		return $this->validateHoofdzaakNesting(hoofdzaakUrl: $hoofdzaak);
	}//end checkHoofdzaak()

	/**
	 * Validate hoofdzaak is not a deelzaak itself (zrc-013).
	 *
	 * A deelzaak of a deelzaak is not allowed.
	 *
	 * @param string $hoofdzaakUrl The hoofdzaak URL
	 *
	 * @return array|null Validation error if hoofdzaak is itself a deelzaak
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 */
	private function validateHoofdzaakNesting(string $hoofdzaakUrl): ?array {
		if ($this->objectService === null) {
			return null;
		}

		$hoofdzaakUuid = $this->extractUuid(url: $hoofdzaakUrl);
		if ($hoofdzaakUuid === null) {
			return null;
		}

		$hoofdzaakData = $this->findBySchemaKey(uuid: $hoofdzaakUuid, schemaKey: 'case_schema');
		if ($hoofdzaakData === null) {
			return $this->error(
				status: 400,
				detail: 'De hoofdzaak is ongeldig.',
				invalidParams: [$this->fieldError(
					fieldName: 'hoofdzaak',
					code: 'does-not-exist',
					reason: 'De hoofdzaak URL verwijst niet naar een bekende zaak.'
				)
				]
			);
		}

		// If the hoofdzaak itself has a hoofdzaak, it's a deelzaak of a deelzaak.
		$parentHoofdzaak = $hoofdzaakData['parentCase'] ?? ($hoofdzaakData['mainCase'] ?? ($hoofdzaakData['hoofdzaak'] ?? null));
		if ($parentHoofdzaak !== null && $parentHoofdzaak !== '') {
			return $this->error(
				status: 400,
				detail: 'Een deelzaak van een deelzaak is niet toegestaan.',
				invalidParams: [$this->fieldError(
					fieldName: 'hoofdzaak',
					code: 'deelzaak-als-hoofdzaak',
					reason: 'De opgegeven hoofdzaak is zelf een deelzaak.'
				)
				]
			);
		}

		return null;
	}//end validateHoofdzaakNesting()

	/**
	 * Validate productenOfDiensten subset of zaaktype (zrc-015).
	 *
	 * ProductenOfDiensten of the zaak must be a subset of
	 * Zaaktype.productenOfDiensten. A zaaktype that offers no products allows any.
	 *
	 * @param array $body The request body
	 *
	 * @return array|null Validation error, or null if valid
	 *
	 * @link https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function validateProductenOfDiensten(array $body): ?array {
		$requestProducts = $body['productenOfDiensten'] ?? null;
		if (is_array($requestProducts) === false || empty($requestProducts) === true) {
			return null;
		}

		$allowedProducts = $this->allowedProducts(caseTypeUrl: $body['caseType'] ?? '');
		if (empty($allowedProducts) === true) {
			return null;
		}

		// Validate each product URL format first (basic URL check, no UUID required).
		foreach ($requestProducts as $product) {
			if (filter_var($product, FILTER_VALIDATE_URL) === false) {
				return $this->error(
					status: 400,
					detail: 'productenOfDiensten bevat een ongeldige URL.',
					invalidParams: [
						$this->fieldError(
							fieldName: 'productenOfDiensten',
							code: 'invalid-products-services',
							reason: "'{$product}' is geen geldige URL."
						),
					]
				);
			}
		}

		foreach ($requestProducts as $product) {
			if (in_array($product, $allowedProducts, true) === false) {
				return $this->error(
					status: 400,
					detail: 'productenOfDiensten bevat een waarde die niet in het zaaktype voorkomt.',
					invalidParams: [
						$this->fieldError(
							fieldName: 'productenOfDiensten',
							code: 'invalid-products-services',
							reason: "Product '{$product}' is niet toegestaan voor dit zaaktype."
						),
					]
				);
			}
		}

		return null;
	}//end validateProductenOfDiensten()

	/**
	 * The products a zaaktype offers, empty when it offers none or cannot be resolved.
	 *
	 * Reads `productsOrServices`, then `productsAndServices`, then `productenOfDiensten`; a JSON
	 * string is decoded.
	 *
	 * @param mixed $caseTypeUrl The zaaktype URL from the body
	 *
	 * @return array The allowed product URLs
	 */
	private function allowedProducts(mixed $caseTypeUrl): array {
		if ($this->objectService === null || empty($caseTypeUrl) === true) {
			return [];
		}

		$zaaktypeUuid = $this->extractUuid(url: $caseTypeUrl);
		if ($zaaktypeUuid === null) {
			return [];
		}

		$ztData = $this->findBySchemaKey(uuid: $zaaktypeUuid, schemaKey: 'case_type_schema');
		if ($ztData === null) {
			return [];
		}

		$allowedProducts = $ztData['productsOrServices'] ?? ($ztData['productsAndServices'] ?? ($ztData['productenOfDiensten'] ?? []));
		if (is_string($allowedProducts) === true) {
			$allowedProducts = json_decode($allowedProducts, true) ?? [];
		}

		if (is_array($allowedProducts) === false) {
			return [];
		}

		return $allowedProducts;
	}//end allowedProducts()
}//end class
