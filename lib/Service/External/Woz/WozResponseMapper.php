<?php

/**
 * Pure normalization mapper for Kadaster WOZ Bevragen fragments.
 *
 * Contains zero I/O — takes an already-decoded JSON fragment (one
 * `wozObjecten[]` entry, or a single `wozobject` resource) and returns the
 * stable Dossiq-internal DTO shape. All HTTP concerns (request building,
 * headers, retries, error mapping) live exclusively in `WozApiAdapter`,
 * mirroring `BagResponseMapper` / `BrkResponseMapper` in spirit but scoped
 * to the WOZ payload shape.
 *
 * A WOZ object carries one established value PER waardepeildatum
 * (valuation date) — `vastgesteldeWaarden[]`. This mapper surfaces the
 * MOST RECENT entry (by `waardepeildatum`, descending) as the flat
 * `waarde`/`waardepeildatum` fields, since Dossiq's VTH/tax callers need
 * "the current WOZ value", not the full valuation history; the full list
 * is still available via `extras.matches` on the adapter's search results
 * for a caller that needs history.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Woz
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://kadaster.github.io/WOZ-bevragen/
 *
 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Woz;

use OCA\Dossiq\Service\External\RegisterValues;

/**
 * Normalizes Kadaster WOZ Bevragen wozobject fragments into the
 * Dossiq-internal DTO shape.
 *
 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
 */
final class WozResponseMapper {
	/**
	 * Normalize a single Kadaster fragment into the stable DTO shape.
	 *
	 * Numeric fields (`waarde`, `grondoppervlakte`) are `null` when absent
	 * from the source — never coerced to `0`. `gebruiksdoel` is always an
	 * array, even when the source carries a single string.
	 *
	 * @param array<string,mixed> $raw Decoded Kadaster JSON fragment.
	 *
	 * @return array{
	 *     wozobjectnummer: string|null,
	 *     value: int|null,
	 *     waardepeildatum: string|null,
	 *     grondoppervlakte: int|null,
	 *     gebruiksdoel: array<int,string>,
	 *     addressDesignationId: string|null,
	 * }
	 *
	 * @spec openspec/changes/archive/2026-07-14-brk-woz-register-adapters/proposal.md
	 */
	public function map(array $raw): array {
		$values = new RegisterValues();
		$current = $this->mostRecentValue(raw: $raw);

		return [
			'wozobjectnummer' => $values->stringOrNull(value: $raw['wozobjectnummer'] ?? null),
			'value' => $values->intOrNull(value: $current['vastgesteldeWaarde'] ?? $raw['value'] ?? null),
			'waardepeildatum' => $values->stringOrNull(value: $current['waardepeildatum'] ?? $raw['waardepeildatum'] ?? null),
			'grondoppervlakte' => $values->intOrNull(value: $raw['grondoppervlakte'] ?? null),
			'gebruiksdoel' => $values->toStringArray(value: $raw['gebruiksdoelen'] ?? $raw['gebruiksdoel'] ?? []),
			'addressDesignationId' => $values->stringOrNull(
				value: $raw['nummeraanduidingIdentificatie'] ?? $raw['adresseerbaarObjectIdentificatie'] ?? $raw['addressDesignationId'] ?? null
			),
		];
	}//end map()

	/**
	 * Normalize a list of raw fragments (e.g. a `_embedded.wozObjecten[]`
	 * multi-match search result).
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
	 * Select the most recent `vastgesteldeWaarden[]` entry by
	 * `waardepeildatum` (descending lexicographic — dates are ISO 8601
	 * `YYYY-MM-DD`, so lexicographic order equals chronological order).
	 *
	 * @param array<string,mixed> $raw Decoded Kadaster JSON fragment.
	 *
	 * @return array<string,mixed> The most recent entry, or an empty array
	 *                             when the fragment carries no history.
	 */
	private function mostRecentValue(array $raw): array {
		$waarden = ($raw['vastgesteldeWaarden'] ?? null);
		if (is_array($waarden) === false || $waarden === []) {
			return [];
		}

		$sorted = $waarden;
		usort($sorted, static fn (mixed $a, mixed $b): int => self::newerFirst(a: $a, b: $b));

		$first = ($sorted[0] ?? []);
		if (is_array($first) === true) {
			return $first;
		}

		return [];
	}//end mostRecentWaarde()

	/**
	 * Order two `vastgesteldeWaarden[]` entries newest `waardepeildatum` first.
	 *
	 * @param mixed $a One entry.
	 * @param mixed $b The other entry.
	 *
	 * @return int
	 */
	private static function newerFirst(mixed $a, mixed $b): int {
		$dateA = '';
		if (is_array($a) === true) {
			$dateA = (string)($a['waardepeildatum'] ?? '');
		}

		$dateB = '';
		if (is_array($b) === true) {
			$dateB = (string)($b['waardepeildatum'] ?? '');
		}

		return $dateB <=> $dateA;
	}//end newerFirst()
}//end class
