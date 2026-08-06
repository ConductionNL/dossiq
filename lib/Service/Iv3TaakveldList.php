<?php

/**
 * Procest Iv3TaakveldList
 *
 * The single testable source of truth for valid IV3/BBV taakveld codes and
 * labels. Loads and caches `lib/Settings/iv3_taakvelden.json` (9 main BBV
 * categories, ~55 subcodes). Every other part of the IV3 reporting feature
 * (case-type classification, quarterly aggregation, CSV export, the
 * settings picker) validates/labels taakveld codes through this class
 * instead of re-encoding the list.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use RuntimeException;

/**
 * Loads and exposes the IV3/BBV taakveld reference list.
 *
 * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/tasks.md#1.2
 */
class Iv3TaakveldList
{

    /**
     * In-memory cache of the decoded taakveld bundle (per-request; this
     * service is stateless across requests since NC recreates it per DI
     * scope).
     *
     * @var array<string, mixed>|null
     */
    private ?array $bundle = null;

    /**
     * In-memory cache of the flattened taakveld list.
     *
     * @var array<int, array{
     *     code: string, label: string, categoryCode: string,
     *     categoryLabel: string, deprecated: bool, aggregatesUnder: string|null
     * }>|null
     */
    private ?array $flattened = null;

    /**
     * Return every taakveld as a flat list, in category then code order.
     *
     * `deprecated` is TRUE for a pre-2023-refinement taakveld-6 code that
     * was split into finer codes (`6.71`, `6.72`, `6.81`, `6.82`) — it
     * remains resolvable (`isValidCode()`/`labelFor()`) for backward
     * compatibility with cases classified before the refinement.
     * `aggregatesUnder` is set on a 2023-refinement code to the pre-2023
     * parent code it rolls up under for quarterly reporting (see
     * {@see aggregationKeyFor()}); `null` for every other taakveld.
     *
     * @return array<int, array{
     *     code: string, label: string, categoryCode: string,
     *     categoryLabel: string, deprecated: bool, aggregatesUnder: string|null
     * }>
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     * @spec openspec/changes/archive/2026-07-14-iv3-taakveld-2023-refinement/specs/iv3-taakveld-2023-refinement/spec.md
     */
    public function allTaakvelden(): array
    {
        if ($this->flattened !== null) {
            return $this->flattened;
        }

        $bundle = $this->load();
        $out    = [];
        foreach ((array) ($bundle['categories'] ?? []) as $category) {
            $categoryCode  = (string) ($category['code'] ?? '');
            $categoryLabel = (string) ($category['label'] ?? '');
            foreach ((array) ($category['taakvelden'] ?? []) as $taakveld) {
                $out[] = $this->flattenTaakveld(taakveld: $taakveld, categoryCode: $categoryCode, categoryLabel: $categoryLabel);
            }
        }

        $this->flattened = $out;
        return $out;
    }//end allTaakvelden()

    /**
     * Flatten one raw JSON taakveld entry into its public shape.
     *
     * @param array<string, mixed> $taakveld      Raw taakveld entry.
     * @param string               $categoryCode  Owning category code.
     * @param string               $categoryLabel Owning category label.
     *
     * @return array{code: string, label: string, categoryCode: string, categoryLabel: string, deprecated: bool, aggregatesUnder: string|null}
     */
    private function flattenTaakveld(array $taakveld, string $categoryCode, string $categoryLabel): array
    {
        $aggregatesUnder = ($taakveld['aggregatesUnder'] ?? null);
        if (is_string($aggregatesUnder) === false || $aggregatesUnder === '') {
            $aggregatesUnder = null;
        }

        return [
            'code'            => (string) ($taakveld['code'] ?? ''),
            'label'           => (string) ($taakveld['label'] ?? ''),
            'categoryCode'    => $categoryCode,
            'categoryLabel'   => $categoryLabel,
            'deprecated'      => (bool) ($taakveld['deprecated'] ?? false),
            'aggregatesUnder' => $aggregatesUnder,
        ];
    }//end flattenTaakveld()

    /**
     * Whether the given code is a deprecated (pre-2023-refinement)
     * taakveld-6 code. A deprecated code remains resolvable — this only
     * flags it for UI/reporting treatment, it never affects
     * `isValidCode()`/`labelFor()`.
     *
     * @param string $code The taakveld code.
     *
     * @return bool
     *
     * @spec openspec/changes/archive/2026-07-14-iv3-taakveld-2023-refinement/specs/iv3-taakveld-2023-refinement/spec.md
     */
    public function isDeprecated(string $code): bool
    {
        foreach ($this->allTaakvelden() as $taakveld) {
            if ($taakveld['code'] === $code) {
                return $taakveld['deprecated'];
            }
        }

        return false;
    }//end isDeprecated()

    /**
     * Resolve the aggregation bucket key for a taakveld code — the single
     * entry point a taakveld consumer uses so cases classified under a
     * deprecated pre-2023 code (e.g. `6.72`) and cases classified under one
     * of its 2023-refinement successors (e.g. `6.72a`, `6.73a`, `6.74b`)
     * land in the SAME quarterly report bucket, keyed by the pre-2023
     * parent code.
     *
     * A code with no `aggregatesUnder` entry (every non-refinement code,
     * and every deprecated parent code itself) aggregates under itself. An
     * unknown code also passes through unchanged, so an unrecognised
     * `caseType.iv3Taakveld` value still buckets predictably instead of
     * being silently dropped.
     *
     * @param string $code The taakveld code.
     *
     * @return string The aggregation bucket key.
     *
     * @spec openspec/changes/archive/2026-07-14-iv3-taakveld-2023-refinement/specs/iv3-taakveld-2023-refinement/spec.md
     */
    public function aggregationKeyFor(string $code): string
    {
        foreach ($this->allTaakvelden() as $taakveld) {
            if ($taakveld['code'] === $code) {
                return ($taakveld['aggregatesUnder'] ?? $code);
            }
        }

        return $code;
    }//end aggregationKeyFor()

    /**
     * Whether the given code exists in the taakveld list.
     *
     * @param string $code The taakveld code (e.g. "8.1").
     *
     * @return bool
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     */
    public function isValidCode(string $code): bool
    {
        foreach ($this->allTaakvelden() as $taakveld) {
            if ($taakveld['code'] === $code) {
                return true;
            }
        }

        return false;
    }//end isValidCode()

    /**
     * Look up the label for a taakveld code.
     *
     * @param string $code The taakveld code (e.g. "8.1").
     *
     * @return string|null The label, or null when the code is unknown.
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     */
    public function labelFor(string $code): ?string
    {
        foreach ($this->allTaakvelden() as $taakveld) {
            if ($taakveld['code'] === $code) {
                return $taakveld['label'];
            }
        }

        return null;
    }//end labelFor()

    /**
     * The version tag of the shipped taakveld list.
     *
     * @return string
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
     */
    public function version(): string
    {
        return (string) ($this->load()['version'] ?? 'unknown');
    }//end version()

    /**
     * The date the shipped taakveld list became officially valid
     * (`geldigVanaf` in `iv3_taakvelden.json`, e.g. the 2023 Wmo/Jeugd
     * refinement's effective date), or an empty string when unset.
     *
     * @return string
     *
     * @spec openspec/changes/archive/2026-07-14-iv3-taakveld-2023-refinement/specs/iv3-taakveld-2023-refinement/spec.md
     */
    public function geldigVanaf(): string
    {
        return (string) ($this->load()['geldigVanaf'] ?? '');
    }//end geldigVanaf()

    /**
     * Load + decode `iv3_taakvelden.json`, cached for the lifetime of this
     * instance.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the bundle file is missing or invalid JSON.
     */
    private function load(): array
    {
        if ($this->bundle !== null) {
            return $this->bundle;
        }

        $path = __DIR__.'/../Settings/iv3_taakvelden.json';
        if (file_exists($path) === false) {
            throw new RuntimeException('IV3 taakveld-bestand ontbreekt: '.basename($path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Kon IV3 taakveld-bestand niet lezen: '.basename($path));
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) === false) {
            throw new RuntimeException('IV3 taakveld-bestand bevat ongeldige JSON: '.basename($path));
        }

        $this->bundle = $decoded;
        return $decoded;
    }//end load()
}//end class
