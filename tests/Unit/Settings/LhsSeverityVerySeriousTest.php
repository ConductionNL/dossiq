<?php

/**
 * LHS severity "zeer ernstig" test
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lhs-advice/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * A recommendation's severity offers the fourth LHS consequence, "zeer
 * ernstig", after the three it had, with a label, and the schema version
 * moved so an instance re-imports it.
 */
class LhsSeverityVerySeriousTest extends TestCase {

	/**
	 * The base register plus the fragment, merged the way the loader merges them.
	 *
	 * @return array<string, mixed> The lhsRecommendation severity property and the version.
	 */
	private function merged(): array {
		$settings = dirname(__DIR__, 3).'/lib/Settings/';
		$base = json_decode((string) file_get_contents($settings.'dossiq_register.json'), true);
		$fragment = json_decode((string) file_get_contents($settings.'register.d/97-lhs-severity-very-serious.json'), true);
		$labels = json_decode((string) file_get_contents($settings.'register.d/96-lhs-recommendation-labels.json'), true);

		$baseSeverity = $base['components']['schemas']['lhsRecommendation']['properties']['severity'];
		$addition = $fragment['components']['schemas']['lhsRecommendation'];

		return [
			'enum' => array_merge($baseSeverity['enum'], $addition['properties']['severity']['enum']),
			'labels' => array_merge(
				$labels['components']['schemas']['lhsRecommendation']['properties']['severity']['x-enum-labels'],
				$addition['properties']['severity']['x-enum-labels']
			),
			'version' => $addition['version'],
			'previous' => $labels['components']['schemas']['lhsRecommendation']['version'],
		];
	}

	/**
	 * The four consequences, in the board's order, each with a label.
	 *
	 * @return void
	 */
	public function testOffersTheFourthConsequence(): void {
		$merged = $this->merged();
		$this->assertSame(['gering', 'aanzienlijk', 'ernstig', 'zeer_ernstig'], $merged['enum']);
		$this->assertSame('very serious', $merged['labels']['zeer_ernstig']);

		$nl = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/l10n/nl.json'), true);
		$this->assertSame('zeer ernstig', $nl['translations']['very serious']);
	}

	/**
	 * The schema version moves past the labels fragment's.
	 *
	 * @return void
	 */
	public function testTheVersionMoves(): void {
		$merged = $this->merged();
		$this->assertTrue(version_compare($merged['version'], $merged['previous'], '>'));
	}
}
