<?php

/**
 * Golden test for BackfilledTemplateLabels::healed: exactly which fields a stored
 * backfilled template gets back, and in which form.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Workflow;

use OCA\Dossiq\Service\Workflow\BackfilledTemplateLabels;
use PHPUnit\Framework\TestCase;

class BackfilledTemplateLabelsGoldenTest extends TestCase {

	private const STATUSES = [
		's1' => ['name' => ['nl' => 'Ontvangen', 'en' => 'Received'], 'description' => ['nl' => 'Binnen']],
		's2' => ['name' => 'Besluit/genomen'],
	];

	public function testATemplateWithoutTheMarkerIsLeftAlone(): void {
		$labels = new BackfilledTemplateLabels();
		$this->assertSame([], $labels->healed(['title' => 'Array (basis)', 'steps' => '[{"title":"Array"}]'], ['title' => 'X'], self::STATUSES));
		$this->assertSame([], $labels->healed(['description' => ' ' . BackfilledTemplateLabels::MARKER, 'title' => 'Array (basis)'], [], []));
	}//end testATemplateWithoutTheMarkerIsLeftAlone()

	public function testEveryArrayLabelIsHealed(): void {
		$template = [
			'description' => BackfilledTemplateLabels::MARKER,
			'title' => 'Array (basis)',
			'steps' => json_encode(
				[
					['status' => 's1', 'title' => 'Array', 'description' => 'Array'],
					'not-a-step',
					['status' => 'gone', 'title' => 'Array', 'description' => 'Array'],
					['status' => 's2', 'title' => 'Al goed', 'description' => 'Array'],
					['status' => 's2', 'title' => 'Al goed'],
				]
			),
			'transitions' => [
				'x' => ['toStatus' => 's2', 'label' => 'Array'],
				'y' => ['toStatus' => 'gone', 'label' => 'Array'],
				'z' => ['toStatus' => 's1', 'label' => 'Verder'],
			],
		];

		$this->assertSame(
			[
				'title' => 'Bezwaar (basis)',
				'steps' => '[{"status":"s1","title":"Ontvangen","description":"Binnen"},"not-a-step",'
					. '{"status":"gone","title":"Stap","description":""},{"status":"s2","title":"Al goed","description":""},'
					. '{"status":"s2","title":"Al goed"}]',
				'transitions' => '[{"toStatus":"s2","label":"Besluit/genomen"},{"toStatus":"gone","label":"Door"},'
					. '{"toStatus":"s1","label":"Verder"}]',
			],
			(new BackfilledTemplateLabels())->healed($template, ['title' => ['nl' => 'Bezwaar']], self::STATUSES)
		);
	}//end testEveryArrayLabelIsHealed()

	public function testOnlyWhatChangedIsReturned(): void {
		$labels = new BackfilledTemplateLabels();
		$base   = ['description' => BackfilledTemplateLabels::MARKER];

		$this->assertSame([], $labels->healed($base + ['title' => 'Bezwaar (basis)', 'steps' => '[{"title":"Ok"}]', 'transitions' => 'not json'], [], self::STATUSES));
		$this->assertSame(['title' => 'Workflow (basis)'], $labels->healed($base + ['title' => 'Array (basis)', 'steps' => 5, 'transitions' => []], [], []));
		$this->assertSame([], $labels->healed($base + ['title' => 'Array'], [], []));
		$this->assertSame(
			['transitions' => '[{"label":"Door"}]'],
			$labels->healed($base + ['transitions' => '{"k":{"label":"Array"}}'], [], [])
		);
	}//end testOnlyWhatChangedIsReturned()
}//end class
