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
	 * The detail and reason of each hoofdzaak refusal, by its code.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const HOOFDZAAK_REFUSALS = [
		'bad-url'                => ['De hoofdzaak URL is ongeldig.', 'De URL is ongeldig.'],
		'self-forbidden'         => ['Een zaak kan niet zijn eigen hoofdzaak zijn.', 'Een zaak kan niet zijn eigen hoofdzaak zijn.'],
		'does-not-exist'         => ['De hoofdzaak is ongeldig.', 'De hoofdzaak URL verwijst niet naar een bekende zaak.'],
		'deelzaak-als-hoofdzaak' => ['Een deelzaak van een deelzaak is niet toegestaan.', 'De opgegeven hoofdzaak is zelf een deelzaak.'],
	];

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
		$hoofdzaak = $body['hoofdzaak'] ?? '';
		if ($hoofdzaak === '') {
			return null;
		}

		// Zrc-013d: not the zaak itself; zrc-013c: a deelzaak of a deelzaak is not allowed.
		return $this->hoofdzaakUrlRefusal(hoofdzaak: $hoofdzaak, existingObject: $existingObject)
			?? $this->validateHoofdzaakNesting(hoofdzaakUrl: $hoofdzaak);
	}//end checkHoofdzaak()

	/**
	 * The refusal for a hoofdzaak that is no URL or that names the zaak being written.
	 *
	 * @param string     $hoofdzaak      The hoofdzaak URL
	 * @param array|null $existingObject The existing zaak, null on create
	 *
	 * @return array|null The refusal, or null when the URL may be followed
	 */
	private function hoofdzaakUrlRefusal(string $hoofdzaak, ?array $existingObject): ?array {
		if ($this->isValidUrl(url: $hoofdzaak) === false) {
			return $this->hoofdzaakRefusal(code: 'bad-url');
		}

		if ($this->pointsAtItself(hoofdzaak: $hoofdzaak, existingObject: $existingObject) === true) {
			return $this->hoofdzaakRefusal(code: 'self-forbidden');
		}

		return null;
	}//end hoofdzaakUrlRefusal()

	/**
	 * Whether the hoofdzaak URL names the zaak being written.
	 *
	 * @param string     $hoofdzaak      The hoofdzaak URL
	 * @param array|null $existingObject The existing zaak, null on create
	 *
	 * @return bool True when the zaak would be its own hoofdzaak
	 */
	private function pointsAtItself(string $hoofdzaak, ?array $existingObject): bool {
		$selfUuid = $existingObject['id'] ?? ($existingObject['@self']['id'] ?? null);
		return $selfUuid !== null && $selfUuid === $this->extractUuid(url: $hoofdzaak);
	}//end pointsAtItself()

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
		$hoofdzaakUuid = $this->lookupUuid(url: $hoofdzaakUrl);
		if ($hoofdzaakUuid === null) {
			return null;
		}

		return $this->nestingRefusal(hoofdzaakData: $this->findBySchemaKey(uuid: $hoofdzaakUuid, schemaKey: 'case_schema'));
	}//end validateHoofdzaakNesting()

	/**
	 * The UUID to look a URL up by, null when there is no object store or the URL carries none.
	 *
	 * @param string $url The resource URL
	 *
	 * @return string|null The UUID
	 */
	private function lookupUuid(string $url): ?string {
		if ($this->objectService === null) {
			return null;
		}

		return $this->extractUuid(url: $url);
	}//end lookupUuid()

	/**
	 * The refusal for a hoofdzaak that is unknown or itself a deelzaak.
	 *
	 * @param array|null $hoofdzaakData The stored hoofdzaak, null when not found
	 *
	 * @return array|null The refusal, or null when the hoofdzaak may carry deelzaken
	 */
	private function nestingRefusal(?array $hoofdzaakData): ?array {
		if ($hoofdzaakData === null) {
			return $this->hoofdzaakRefusal(code: 'does-not-exist');
		}

		if ($this->isDeelzaak(zaak: $hoofdzaakData) === true) {
			return $this->hoofdzaakRefusal(code: 'deelzaak-als-hoofdzaak');
		}

		return null;
	}//end nestingRefusal()

	/**
	 * Whether a zaak itself has a hoofdzaak, read as parentCase, mainCase or hoofdzaak.
	 *
	 * @param array $zaak The stored zaak
	 *
	 * @return bool True when the zaak is a deelzaak
	 */
	private function isDeelzaak(array $zaak): bool {
		$parentHoofdzaak = $zaak['parentCase'] ?? ($zaak['mainCase'] ?? ($zaak['hoofdzaak'] ?? null));
		return $parentHoofdzaak !== null && $parentHoofdzaak !== '';
	}//end isDeelzaak()

	/**
	 * The 400 refusal on the hoofdzaak field for one code.
	 *
	 * @param string $code The refusal code, a key of HOOFDZAAK_REFUSALS
	 *
	 * @return array The refusal
	 */
	private function hoofdzaakRefusal(string $code): array {
		[$detail, $reason] = self::HOOFDZAAK_REFUSALS[$code];
		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [$this->fieldError(fieldName: 'hoofdzaak', code: $code, reason: $reason)]
		);
	}//end hoofdzaakRefusal()

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
		$requestProducts = $this->requestedProducts(body: $body);
		if ($requestProducts === []) {
			return null;
		}

		$allowedProducts = $this->allowedProducts(caseTypeUrl: $body['caseType'] ?? '');
		if (empty($allowedProducts) === true) {
			return null;
		}

		// Every product URL format is checked first (basic URL check, no UUID required).
		return $this->invalidProductUrl(products: $requestProducts)
			?? $this->unofferedProduct(products: $requestProducts, allowedProducts: $allowedProducts);
	}//end validateProductenOfDiensten()

	/**
	 * The productenOfDiensten the body asks for, empty when it names none or no list.
	 *
	 * @param array $body The request body
	 *
	 * @return array The requested product URLs
	 */
	private function requestedProducts(array $body): array {
		$requestProducts = $body['productenOfDiensten'] ?? null;
		if (is_array($requestProducts) === false) {
			return [];
		}

		return $requestProducts;
	}//end requestedProducts()

	/**
	 * The refusal for the first requested product that is not a URL.
	 *
	 * @param array $products The requested products
	 *
	 * @return array|null The refusal, or null when every product is a URL
	 */
	private function invalidProductUrl(array $products): ?array {
		foreach ($products as $product) {
			if (filter_var($product, FILTER_VALIDATE_URL) === false) {
				return $this->productRefusal(
					detail: 'productenOfDiensten bevat een ongeldige URL.',
					reason: "'{$product}' is geen geldige URL."
				);
			}
		}

		return null;
	}//end invalidProductUrl()

	/**
	 * The refusal for the first requested product the zaaktype does not offer.
	 *
	 * @param array $products        The requested products
	 * @param array $allowedProducts The products the zaaktype offers
	 *
	 * @return array|null The refusal, or null when every product is offered
	 */
	private function unofferedProduct(array $products, array $allowedProducts): ?array {
		foreach ($products as $product) {
			if (in_array($product, $allowedProducts, true) === false) {
				return $this->productRefusal(
					detail: 'productenOfDiensten bevat een waarde die niet in het zaaktype voorkomt.',
					reason: "Product '{$product}' is niet toegestaan voor dit zaaktype."
				);
			}
		}

		return null;
	}//end unofferedProduct()

	/**
	 * The 400 refusal on the productenOfDiensten field.
	 *
	 * @param string $detail The refusal detail
	 * @param string $reason The field reason
	 *
	 * @return array The refusal
	 */
	private function productRefusal(string $detail, string $reason): array {
		return $this->error(
			status: 400,
			detail: $detail,
			invalidParams: [
				$this->fieldError(fieldName: 'productenOfDiensten', code: 'invalid-products-services', reason: $reason),
			]
		);
	}//end productRefusal()

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
