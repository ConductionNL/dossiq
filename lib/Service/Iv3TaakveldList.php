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
     * @var array<int, array{code: string, label: string, categoryCode: string, categoryLabel: string}>|null
     */
    private ?array $flattened = null;

    /**
     * Return every taakveld as a flat list, in category then code order.
     *
     * @return array<int, array{code: string, label: string, categoryCode: string, categoryLabel: string}>
     *
     * @spec openspec/changes/archive/2026-07-13-iv3-case-cost-reporting/specs/iv3-case-cost-reporting/spec.md
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
                $out[] = [
                    'code'          => (string) ($taakveld['code'] ?? ''),
                    'label'         => (string) ($taakveld['label'] ?? ''),
                    'categoryCode'  => $categoryCode,
                    'categoryLabel' => $categoryLabel,
                ];
            }
        }

        $this->flattened = $out;
        return $out;
    }//end allTaakvelden()

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
