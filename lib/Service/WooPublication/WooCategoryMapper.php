<?php

/**
 * Dossiq WOO Category Mapper
 *
 * Maps a dossiq WOO decision onto OpenCatalogi's DIWOO informatiecategorie
 * vocabulary (`OCA\OpenCatalogi\Service\TooiVocabularyService::INFORMATIECATEGORIEEN`
 * at `origin/development` HEAD in the opencatalogi repo). The real DIWOO
 * vocabulary has 17 categories, not the 11 originally assumed for this
 * feature; a WOO besluit maps cleanly onto exactly one of them ("Woo-verzoeken
 * en -besluiten", route code `infocat014`). The mapping is kept as a lookup
 * table — not an inline constant at the call site — so a follow-up change can
 * add further entries (e.g. `infocat016` "Beschikkingen" for
 * `subsidie`-domain decisions) without touching {@see WooPublicationService}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\WooPublication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\WooPublication;

/**
 * Maps dossiq WOO decisions to a DIWOO informatiecategorie.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d3
 */
class WooCategoryMapper {

	/**
	 * The default/fallback informatiecategorie: every WOO besluit is
	 * definitionally in "Woo-verzoeken en -besluiten". Values verbatim from
	 * OpenCatalogi's `TooiVocabularyService::INFORMATIECATEGORIEEN['infocat014']`.
	 */
	private const DEFAULT_CATEGORY = [
		'code' => 'infocat014',
		'label' => 'Woo-verzoeken en -besluiten',
		'uri' => 'https://identifier.overheid.nl/tooi/def/thes/kern/c_3baef532',
	];

	/**
	 * Lookup table keyed by `decision.decisionType`. Every entry not present
	 * here falls back to {@see self::DEFAULT_CATEGORY}.
	 *
	 * @var array<string, array{code: string, label: string, uri: string}>
	 */
	private const DECISION_TYPE_MAP = [
		'WOO-besluit' => self::DEFAULT_CATEGORY,
	];

	/**
	 * Resolve the DIWOO informatiecategorie for a WOO decision.
	 *
	 * @param array<string, mixed> $decision The decision object as an array
	 *                                       (expects `decisionType`, optional).
	 *
	 * @return array{code: string, label: string, uri: string} The resolved category.
	 *
	 * @spec openspec/changes/woo-publication-via-opencatalogi/design.md#d3
	 */
	public function forDecision(array $decision): array {
		$decisionType = (string)($decision['decisionType'] ?? '');

		return (self::DECISION_TYPE_MAP[$decisionType] ?? self::DEFAULT_CATEGORY);
	}//end forDecision()
}//end class
