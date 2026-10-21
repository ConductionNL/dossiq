<?php

/**
 * Tests for the renamed layout ids and the rewrite of a stored delta.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Dashboard
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
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Service\Dashboard\RenamedLayoutIds;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Dashboard\RenamedLayoutIds
 */
final class RenamedLayoutIdsTest extends TestCase {

	/**
	 * A delta as buildiq stores it, naming old ids in every place a delta can.
	 *
	 * @return array<string, mixed>
	 */
	private static function storedDelta(): array {
		return [
			'pages' => [
				[
					'id' => 'TermijnDashboard',
					'config' => [
						'widgets' => [
							[
								'id' => 'td-running',
								'title' => 'Mine',
								'visibleWhen' => ['field' => 'visible.td-running', 'op' => 'eq', 'value' => true],
							],
							['id' => 'pm-kpis', 'title' => 'Kept'],
						],
						'layout' => [['id' => 3, 'widgetId' => 'td-quarterly', 'gridX' => 6]],
						'kpiRow' => ['td-running', 'td-overdue', 'own-tile'],
						'__order' => ['widgets' => ['td-notices', 'td-running']],
					],
				],
				[
					'id' => 'CaseDetail',
					'config' => [
						'widgets' => [['id' => 'family-plan', 'type' => 'family-plan']],
						'layout' => [['id' => 'family-plan-panel', 'widgetId' => 'family-plan']],
						'layoutOverrides' => ['family-plan-panel' => ['gridX' => 0]],
					],
				],
				['id' => 'WooReports', 'route' => '/woo-reports'],
				['id' => 'Dashboard', 'config' => ['widgets' => [['id' => 'analytics-series:procest-sla-monthly-trend']]]],
			],
			'menu' => [['id' => 'WooReportsMenu', 'route' => 'WooReports', 'label' => 'Woo reports']],
		];
	}//end storedDelta()

	/**
	 * Every place a delta names an id follows the rename; a registry type does not.
	 *
	 * @return void
	 */
	public function testEveryIdReferenceFollowsTheRename(): void {
		$out = RenamedLayoutIds::rewrite(value: self::storedDelta());
		$deadlines = $out['pages'][0]['config'];

		self::assertSame('TermijnDashboard', $out['pages'][0]['id'], 'A page id that was not renamed stays.');
		self::assertSame('deadline-report-running', $deadlines['widgets'][0]['id']);
		self::assertSame('visible.deadline-report-running', $deadlines['widgets'][0]['visibleWhen']['field']);
		self::assertSame('pm-kpis', $deadlines['widgets'][1]['id']);
		self::assertSame('deadline-report-quarterly', $deadlines['layout'][0]['widgetId']);
		self::assertSame(3, $deadlines['layout'][0]['id']);
		self::assertSame(['deadline-report-running', 'deadline-report-overdue', 'own-tile'], $deadlines['kpiRow']);
		self::assertSame(['widgets' => ['deadline-report-notices', 'deadline-report-running']], $deadlines['__order']);

		$case = $out['pages'][1]['config'];
		self::assertSame(['id' => 'goal-plan', 'type' => 'family-plan'], $case['widgets'][0], 'The registry key keeps its name.');
		self::assertSame(['id' => 'goal-plan-panel', 'widgetId' => 'goal-plan'], $case['layout'][0]);
		self::assertSame(['goal-plan-panel' => ['gridX' => 0]], $case['layoutOverrides']);

		self::assertSame(['id' => 'DocumentReviewReports', 'route' => '/document-review-reports'], $out['pages'][2]);
		self::assertSame('analytics-series:dossiq-sla-monthly-trend', $out['pages'][3]['config']['widgets'][0]['id']);
		self::assertSame(['id' => 'DocumentReviewReportsMenu', 'route' => 'DocumentReviewReports', 'label' => 'Woo reports'], $out['menu'][0]);
	}//end testEveryIdReferenceFollowsTheRename()

	/**
	 * A second rewrite changes nothing, and a delta without old ids is returned as it was.
	 *
	 * @return void
	 */
	public function testTheRewriteIsIdempotent(): void {
		$once = RenamedLayoutIds::rewrite(value: self::storedDelta());

		self::assertSame($once, RenamedLayoutIds::rewrite(value: $once));
		$untouched = ['pages' => [['id' => 'Cases', 'config' => ['title' => 'td-running is a word here']]]];
		self::assertSame($untouched, RenamedLayoutIds::rewrite(value: $untouched));
	}//end testTheRewriteIsIdempotent()

	/**
	 * The bundled manifest names no old id and declares every new widget id the map points at.
	 *
	 * @return void
	 */
	public function testTheShippedManifestCarriesOnlyTheNewIds(): void {
		$root = dirname(__DIR__, 4);
		$json = (string)file_get_contents($root . '/src/manifest.json');
		foreach (glob($root . '/src/manifest.d/*.json') as $fragment) {
			$json .= (string)file_get_contents($fragment);
		}

		$json .= (string)file_get_contents($root . '/src/menu-layout.simple.json');

		foreach (RenamedLayoutIds::IDS as $old => $new) {
			if (str_starts_with($old, 'analytics-series:') === true) {
				continue;
			}

			self::assertStringNotContainsString('"id": "' . $old . '"', $json, $old . ' is still a declared id');
			self::assertStringNotContainsString('"widgetId": "' . $old . '"', $json, $old . ' is still placed');
			self::assertStringNotContainsString('"visible.' . $old . '"', $json);
			if (str_starts_with($new, '/') === false) {
				self::assertMatchesRegularExpression('/"(id|widgetId|route)": "' . preg_quote($new, '/') . '"|"' . preg_quote($new, '/') . '": \{/', $json, $new . ' is not declared');
			}
		}
	}//end testTheShippedManifestCarriesOnlyTheNewIds()
}//end class
