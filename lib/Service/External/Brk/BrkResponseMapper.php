<?php

/**
 * Pure normalization mapper for Kadaster BRK Bevragen v2 fragments.
 *
 * Contains zero I/O — takes an already-decoded JSON fragment (one
 * `kadastraalOnroerendeZaken[]` entry, or a single
 * `kadastraalOnroerendeZaak` resource) and returns the stable
 * Dossiq-internal DTO shape. All HTTP concerns (request building,
 * headers, retries, error mapping) live exclusively in `BrkApiAdapter`,
 * mirroring `BagResponseMapper` in spirit but scoped to the BRK payload
 * shape.
 *
 * `zakelijkGerechtigden` is deliberately mapped to REFERENCE identifiers
 * only (`identificatie` + `aardZakelijkRecht`), never inline natural-person
 * detail — see design.md Decision 2 (privacy scoping). Dossiq's VTH/tax
 * workflows need to know THAT a parcel has a registered title holder and
 * what kind of right it is, not the holder's personal data; a caller that
 * needs the full rightholder record must resolve it through BRK's own
 * `zakelijkGerechtigden` sub-resource directly (out of scope here).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://kadaster.github.io/BRK-bevragen/
 *
 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Brk;

use OCA\Dossiq\Service\External\RegisterValues;

/**
 * Normalizes Kadaster BRK Bevragen v2 kadastraalOnroerendeZaak fragments
 * into the Dossiq-internal DTO shape.
 *
 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
 */
final class BrkResponseMapper {
	/**
	 * Normalize a single Kadaster fragment into the stable DTO shape.
	 *
	 * Numeric fields (`perceelnummer`, `oppervlakte`) are `null` when
	 * absent from the source — never coerced to `0`, so a missing value
	 * stays distinguishable from a real zero. `soortCultuurBebouwd` is
	 * always an array, even when the source carries a single string.
	 *
	 * @param array<string,mixed> $raw Decoded Kadaster JSON fragment.
	 *
	 * @return array{
	 *     kadastraleGemeente: string|null,
	 *     kadastraleGemeenteCode: string|null,
	 *     sectie: string|null,
	 *     perceelnummer: int|null,
	 *     appartementsrechtVolgnummer: string|null,
	 *     kadastraleAanduiding: string|null,
	 *     oppervlakte: int|null,
	 *     soortCultuurBebouwd: array<int,string>,
	 *     zakelijkGerechtigden: array<int,array{identificatie: string|null, aardZakelijkRecht: string|null}>,
	 *     geo: array{lat: float, lng: float}|null,
	 * }
	 *
	 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
	 */
	public function map(array $raw): array {
		$values = new RegisterValues();
		$aanduidingRaw = ($raw['kadastraleAanduiding'] ?? []);
		if (is_array($aanduidingRaw) === false) {
			$aanduidingRaw = [];
		}

		$municipalityNameRaw = ($aanduidingRaw['kadastraleGemeente']['value'] ?? $raw['kadastraleGemeenteNaam'] ?? null);
		$municipalityCodeRaw = ($aanduidingRaw['kadastraleGemeentecode']['value'] ?? $raw['kadastraleGemeenteCode'] ?? null);
		$sequenceNumberRaw = ($aanduidingRaw['appartementsrechtvolgnummer'] ?? $raw['appartementsrechtVolgnummer'] ?? null);
		$grootteRaw = ($raw['kadastraleGrootte']['value'] ?? $raw['kadastraleGrootte'] ?? null);
		$gerechtigdenRaw = ($raw['zakelijkGerechtigdheid'] ?? $raw['zakelijkGerechtigden'] ?? []);

		return [
			'kadastraleGemeente' => $values->stringOrNull(value: $municipalityNameRaw),
			'kadastraleGemeenteCode' => $values->stringOrNull(value: $municipalityCodeRaw),
			'sectie' => $values->stringOrNull(value: $aanduidingRaw['sectie'] ?? $raw['sectie'] ?? null),
			'perceelnummer' => $values->intOrNull(value: $aanduidingRaw['perceelnummer'] ?? $raw['perceelnummer'] ?? null),
			'appartementsrechtVolgnummer' => $values->stringOrNull(value: $sequenceNumberRaw),
			'kadastraleAanduiding' => $values->stringOrNull(value: $raw['kadastraleAanduidingVolledig'] ?? $raw['aanduiding'] ?? null),
			'oppervlakte' => $values->intOrNull(value: $grootteRaw),
			'soortCultuurBebouwd' => $values->toStringArray(value: $raw['soortCultuurBebouwd'] ?? []),
			'zakelijkGerechtigden' => $this->mapBusinessGerechtigden(raw: $gerechtigdenRaw),
			'geo' => $this->extractGeo(raw: $raw),
		];
	}//end map()

	/**
	 * Normalize a list of raw fragments (e.g. a
	 * `_embedded.kadastraalOnroerendeZaken[]` multi-match search result).
	 *
	 * @param array<int,array<string,mixed>> $rawList Decoded Kadaster JSON
	 *                                                fragments.
	 *
	 * @return array<int,array<string,mixed>> Normalized DTOs, same order.
	 *
	 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
	 */
	public function mapMany(array $rawList): array {
		return (new RegisterValues())->mapFragments(rawList: $rawList, map: fn (array $item): array => $this->map(raw: $item));
	}//end mapMany()

	/**
	 * Map zakelijk-gerechtigdheid entries to REFERENCE-only envelopes —
	 * identificatie + aard van het recht — never inline personal data.
	 *
	 * @param mixed $raw Decoded `zakelijkGerechtigdheid` fragment(s).
	 *
	 * @return array<int,array{identificatie: string|null, aardZakelijkRecht: string|null}>
	 */
	private function mapBusinessGerechtigden(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		// A single associative entry (not a list) is wrapped.
		if (array_is_list($raw) === false) {
			$raw = [$raw];
		}

		$values = new RegisterValues();
		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$out[] = [
				'identificatie' => $values->stringOrNull(value: $entry['identificatie'] ?? null),
				'aardZakelijkRecht' => $values->stringOrNull(value: $entry['aardZakelijkRecht']['value'] ?? $entry['aardZakelijkRecht'] ?? null),
			];
		}

		return $out;
	}//end mapZakelijkGerechtigden()

	/**
	 * Extract a WGS84 geo point when the fragment carries a
	 * `centroide_ll`/`centroideLL` point (percelen typically also carry a
	 * `geometrie` polygon in RD (EPSG:28992), which this method does not
	 * expose — mirrors BAG pand's "vlak has no punt" precedent).
	 *
	 * @param array<string,mixed> $raw Decoded Kadaster JSON fragment.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	private function extractGeo(array $raw): ?array {
		$centroid = ($raw['centroideLL'] ?? $raw['centroide_ll'] ?? null);
		if (is_array($centroid) === false) {
			return null;
		}

		$coordinates = ($centroid['coordinates'] ?? null);
		if (is_array($coordinates) === false || count($coordinates) < 2) {
			return null;
		}

		return [
			'lng' => (float)$coordinates[0],
			'lat' => (float)$coordinates[1],
		];
	}//end extractGeo()
}//end class
