<?php

/**
 * Pure normalization mapper for Kadaster BAG API Individuele Bevragingen v2
 * fragments.
 *
 * Contains zero I/O — takes an already-decoded JSON fragment (one
 * `adressen[]` entry, or a single `verblijfsobject`/`pand` resource) and
 * returns the stable Dossiq-internal DTO shape. All HTTP concerns
 * (request building, headers, retries, error mapping) live exclusively in
 * `BagApiAdapter`, per REQ-BAG-004 (one testable normalization seam,
 * mirrors `PdokBagService::normaliseFeature()` in spirit but scoped to the
 * authoritative Kadaster payload shape, which differs from the WFS/GeoJSON
 * feature shape PDOK returns).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://lvbag.github.io/BAG-API/Technische%20specificatie/
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Bag;

/**
 * Normalizes Kadaster BAG API Individuele Bevragingen v2 address / pand /
 * verblijfsobject fragments into the Dossiq-internal DTO shape.
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */
final class BagResponseMapper {
	/**
	 * Normalize a single Kadaster fragment (an `adressen[]` entry, or a
	 * `verblijfsobject`/`pand` resource) into the stable DTO shape.
	 *
	 * Numeric fields (`oorspronkelijkBouwjaar`, `oppervlakte`) are `null`
	 * when absent from the source — never coerced to `0`, so a missing
	 * value stays distinguishable from a real zero. `gebruiksdoel` is
	 * always an array, even when the source carries a single string.
	 *
	 * @param array<string,mixed> $raw Decoded Kadaster JSON fragment.
	 *
	 * @return array{
	 *     street: string|null,
	 *     houseNumber: int|null,
	 *     houseLetter: string|null,
	 *     houseNumberAddition: string|null,
	 *     postcode: string|null,
	 *     city: string|null,
	 *     gebruiksdoel: array<int,string>,
	 *     oorspronkelijkBouwjaar: int|null,
	 *     oppervlakte: int|null,
	 *     geo: array{lat: float, lng: float}|null,
	 * }
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
	 */
	public function map(array $raw): array {
		return [
			'street' => $this->stringOrNull(value: $raw['openbareRuimteNaam'] ?? $raw['street'] ?? null),
			'houseNumber' => $this->intOrNull(value: $raw['houseNumber'] ?? null),
			'houseLetter' => $this->stringOrNull(value: $raw['huisletter'] ?? null),
			'houseNumberAddition' => $this->stringOrNull(value: $raw['huisnummertoevoeging'] ?? null),
			'postcode' => $this->stringOrNull(value: $raw['postcode'] ?? null),
			'city' => $this->stringOrNull(value: $raw['woonplaatsNaam'] ?? $raw['city'] ?? null),
			'gebruiksdoel' => $this->toStringArray(value: $raw['gebruiksdoelen'] ?? $raw['gebruiksdoel'] ?? []),
			'oorspronkelijkBouwjaar' => $this->intOrNull(value: $raw['oorspronkelijkBouwjaar'] ?? null),
			'oppervlakte' => $this->intOrNull(value: $raw['oppervlakte'] ?? null),
			'geo' => $this->extractGeo(raw: $raw),
		];
	}//end map()

	/**
	 * Normalize a list of raw fragments (e.g. an `_embedded.adressen[]`
	 * multi-match address search result).
	 *
	 * @param array<int,array<string,mixed>> $rawList Decoded Kadaster JSON
	 *                                                fragments.
	 *
	 * @return array<int,array<string,mixed>> Normalized DTOs, same order.
	 *
	 * @spec openspec/changes/bag-register-adapter/proposal.md
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
	 * Extract a WGS84 geo point when the fragment carries one
	 * (`geometrie.punt.coordinates` = `[lng, lat]`, present on
	 * `Accept-Crs: epsg:4326` responses for verblijfsobjecten; panden
	 * typically carry only a `vlak` polygon and yield `null` here).
	 *
	 * @param array<string,mixed> $raw Decoded Kadaster JSON fragment.
	 *
	 * @return array{lat: float, lng: float}|null
	 */
	private function extractGeo(array $raw): ?array {
		$geometrie = ($raw['geometrie'] ?? null);
		if (is_array($geometrie) === false) {
			return null;
		}

		$punt = ($geometrie['punt'] ?? null);
		if (is_array($punt) === false) {
			return null;
		}

		$coordinates = ($punt['coordinates'] ?? null);
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
