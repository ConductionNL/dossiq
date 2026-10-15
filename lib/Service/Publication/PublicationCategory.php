<?php

/**
 * Dossiq publication category
 *
 * The information category a published decision is filed under in
 * OpenCatalogi (its DIWOO `informatiecategorie`). The category is
 * configuration of the decision type, not code (decision 182, Q-R8-1): a
 * decision type row carries it in `publicationCategory`, and the procedure
 * that ships the decision type seeds it. A decision whose type is not a
 * stored row, or whose row carries no valid category, falls back to the
 * category every decision dossiq published before this existed was filed
 * under, so nothing that published before changes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * Reads a decision's publication category from its decision type row.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */
class PublicationCategory {

	use SearchesObjects;

	/**
	 * The category a decision falls back to: values verbatim from OpenCatalogi's
	 * `TooiVocabularyService::INFORMATIECATEGORIEEN['infocat014']`.
	 */
	public const FALLBACK = [
		'code' => 'infocat014',
		'label' => 'Woo-verzoeken en -besluiten',
		'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService|null $settingsService Where OpenRegister and the decision type schema are; null reads the fallback only.
	 */
	public function __construct(
		private readonly ?SettingsService $settingsService = null,
	) {
	}//end __construct()

	/**
	 * The category of a decision: its decision type's, or the fallback.
	 *
	 * @param array<string, mixed> $decision The decision, with `decisionType` (a decision type uuid).
	 *
	 * @return array{code: string, label: string, uri: string}
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function forDecision(array $decision): array {
		$typeId = trim((string)($decision['decisionType'] ?? ''));
		if ($typeId === '') {
			return self::FALLBACK;
		}

		return ($this->fromRow(row: $this->decisionType(id: $typeId)) ?? self::FALLBACK);
	}//end forDecision()

	/**
	 * A row's category when it is complete, or null.
	 *
	 * @param array<string, mixed> $row The decision type row, [] for none.
	 *
	 * @return array{code: string, label: string, uri: string}|null
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function fromRow(array $row): ?array {
		$category = ($row['publicationCategory'] ?? null);
		if (is_array($category) === false) {
			return null;
		}

		$read = [];
		foreach (['code', 'label', 'uri'] as $key) {
			$value = $category[$key] ?? '';
			if (is_string($value) === false || trim($value) === '') {
				return null;
			}

			$read[$key] = trim($value);
		}

		return $read;
	}//end fromRow()

	/**
	 * The stored decision type row, or [] when it cannot be read.
	 *
	 * @param string $id The decision type uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function decisionType(string $id): array {
		if ($this->settingsService === null) {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		$schema = $this->settingsService->getConfigValue('decision_type_schema');
		if ($objectService === null || $schema === '') {
			return [];
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $this->settingsService->getConfigValue('register'),
				schema: $schema,
				id: $id,
			) ?? [];
		} catch (Throwable) {
			// A type that cannot be read publishes under the fallback, as before.
			return [];
		}
	}//end decisionType()
}//end class
