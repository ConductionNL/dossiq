<?php

/**
 * Golden record of the resident overview's blocks.
 *
 * Records the blocks the overview page opens with, per world, so a refactor
 * cannot change what a resident sees first or in which order.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalPages;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Golden test for PortalPages::residentOverviewBlocks.
 *
 * @covers \OCA\Dossiq\Portal\PortalPages
 */
class ResidentOverviewBlocksGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every world the overview distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testOverviewAcrossWorlds(): void {
		$worlds = [
			'no questions, no start points' => [[['id' => 'mijnZaken']], [], []],
			'questions to answer' => [[['id' => 'mijnZaken'], ['id' => 'vragenAanU']], [], []],
			'start points and a label' => [[['id' => 'mijnZaken']], ['lopendeZaken' => 'Uw zaken'], [['id' => 'start-vergunning', 'label' => 'Vergunning aanvragen'], ['id' => 'start-melding', 'label' => 'Melding doen']]],
		];

		$blocks = new ReflectionMethod(PortalPages::class, 'residentOverviewBlocks');
		$blocks->setAccessible(true);
		$record = [];
		foreach ($worlds as $name => [$collections, $labels, $startPages]) {
			$record[$name] = $blocks->invoke(new PortalPages(), $collections, $labels, $startPages);
		}

		$this->assertMatchesGolden(name: 'resident-overview-blocks', value: $record);
	}//end testOverviewAcrossWorlds()
}//end class
