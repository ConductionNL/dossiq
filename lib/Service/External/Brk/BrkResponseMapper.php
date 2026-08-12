<?php

/**
 * Pure normalization mapper for Kadaster BRK Bevragen v2 fragments.
 *
 * Contains zero I/O — takes an already-decoded JSON fragment (one
 * `kadastraalOnroerendeZaken[]` entry, or a single
 * `kadastraalOnroerendeZaak` resource) and returns the stable
 * Procest-internal DTO shape. All HTTP concerns (request building,
 * headers, retries, error mapping) live exclusively in `BrkApiAdapter`,
 * mirroring `BagResponseMapper` in spirit but scoped to the BRK payload
 * shape.
 *
 * `zakelijkGerechtigden` is deliberately mapped to REFERENCE identifiers
 * only (`identificatie` + `aardZakelijkRecht`), never inline natural-person
 * detail — see design.md Decision 2 (privacy scoping). Procest's VTH/tax
 * workflows need to know THAT a parcel has a registered title holder and
 * what kind of right it is, not the holder's personal data; a caller that
 * needs the full rightholder record must resolve it through BRK's own
 * `zakelijkGerechtigden` sub-resource directly (out of scope here).
 *
 * @category Service
 * @package  OCA\Procest\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 * @link https://kadaster.github.io/BRK-bevragen/
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service\External\Brk;

/**
 * Normalizes Kadaster BRK Bevragen v2 kadastraalOnroerendeZaak fragments
 * into the Procest-internal DTO shape.
 *
 * @SuppressWarnings(PHPMD.LongVariable) — kadastrale-aanduiding local names
 * are the canonical BRK domain terms (see interface).
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
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
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function map(array $raw): array {
		$kadastraleAanduidingRaw = ($raw['kadastraleAanduiding'] ?? []);
		if (is_array($kadastraleAanduidingRaw) === false) {
			$kadastraleAanduidingRaw = [];
		}

		$gemeenteNaamRaw = ($kadastraleAanduidingRaw['kadastraleGemeente']['waarde'] ?? $raw['kadastraleGemeenteNaam'] ?? null);
		$gemeenteCodeRaw = ($kadastraleAanduidingRaw['kadastraleGemeentecode']['waarde'] ?? $raw['kadastraleGemeenteCode'] ?? null);
		$volgnummerRaw = ($kadastraleAanduidingRaw['appartementsrechtvolgnummer'] ?? $raw['appartementsrechtVolgnummer'] ?? null);
		$grootteRaw = ($raw['kadastraleGrootte']['waarde'] ?? $raw['kadastraleGrootte'] ?? null);
		$gerechtigdenRaw = ($raw['zakelijkGerechtigdheid'] ?? $raw['zakelijkGerechtigden'] ?? []);

		return [
			'kadastraleGemeente' => $this->stringOrNull(value: $gemeenteNaamRaw),
			'kadastraleGemeenteCode' => $this->stringOrNull(value: $gemeenteCodeRaw),
			'sectie' => $this->stringOrNull(value: $kadastraleAanduidingRaw['sectie'] ?? $raw['sectie'] ?? null),
			'perceelnummer' => $this->intOrNull(value: $kadastraleAanduidingRaw['perceelnummer'] ?? $raw['perceelnummer'] ?? null),
			'appartementsrechtVolgnummer' => $this->stringOrNull(value: $volgnummerRaw),
			'kadastraleAanduiding' => $this->stringOrNull(value: $raw['kadastraleAanduidingVolledig'] ?? $raw['aanduiding'] ?? null),
			'oppervlakte' => $this->intOrNull(value: $grootteRaw),
			'soortCultuurBebouwd' => $this->toStringArray(value: $raw['soortCultuurBebouwd'] ?? []),
			'zakelijkGerechtigden' => $this->mapZakelijkGerechtigden(raw: $gerechtigdenRaw),
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
	 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
	 */
	public function mapMany(array $rawList): array {
		$out = [];
		foreach ($rawList as $item) {
			if (is_array($item) === true) {
				$out[] = $this->map(raw: $item);
			}
		}

		return $out;
	}//end mapMany()

	/**
	 * Map zakelijk-gerechtigdheid entries to REFERENCE-only envelopes —
	 * identificatie + aard van het recht — never inline personal data.
	 *
	 * @param mixed $raw Decoded `zakelijkGerechtigdheid` fragment(s).
	 *
	 * @return array<int,array{identificatie: string|null, aardZakelijkRecht: string|null}>
	 */
	private function mapZakelijkGerechtigden(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		// A single associative entry (not a list) is wrapped.
		if (array_is_list($raw) === false && $raw !== []) {
			$raw = [$raw];
		}

		$out = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$out[] = [
				'identificatie' => $this->stringOrNull(value: $entry['identificatie'] ?? null),
				'aardZakelijkRecht' => $this->stringOrNull(value: $entry['aardZakelijkRecht']['waarde'] ?? $entry['aardZakelijkRecht'] ?? null),
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

	/**
	 * Coerce a value to a non-empty string, or null.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string|null
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		if (is_int($value) === true || is_float($value) === true) {
			return (string)$value;
		}

		return null;
	}//end stringOrNull()

	/**
	 * Coerce a value to an int, or null when absent/non-numeric.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_int($value) === true) {
			return $value;
		}

		if (is_float($value) === true) {
			return (int)$value;
		}

		if (is_string($value) === true && $value !== '' && is_numeric($value) === true) {
			return (int)$value;
		}

		return null;
	}//end intOrNull()

	/**
	 * Coerce a value into a string array — a single string becomes a
	 * one-element array, an array is filtered to strings, anything else
	 * becomes an empty array.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return array<int,string>
	 */
	private function toStringArray(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $item) {
			if (is_string($item) === true && $item !== '') {
				$out[] = $item;
			}
		}

		return $out;
	}//end toStringArray()
}//end class
