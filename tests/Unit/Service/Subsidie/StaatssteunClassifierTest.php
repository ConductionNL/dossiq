<?php

/**
 * StaatssteunClassifier Unit Tests.
 *
 * Exercises the EU state-aid classification (REQ-SUB-008): de-minimis ceiling
 * checking, AGVV article validation, DAEB detection, and the overall
 * classification decision tree.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\Subsidie\StaatssteunClassifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Subsidie\StaatssteunClassifier
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-25
 */
class StaatssteunClassifierTest extends TestCase {

	private StaatssteunClassifier $classifier;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->classifier = new StaatssteunClassifier();
	}//end setUp()

	/**
	 * @return void
	 */
	public function testDeMinimisCeiling(): void {
		// €250k prior + €40k new = €290k <= €300k.
		$this->assertTrue($this->classifier->fitsDeMinimis(40000.0, 250000.0));
		// €250k prior + €60k new = €310k > €300k.
		$this->assertFalse($this->classifier->fitsDeMinimis(60000.0, 250000.0));
		// Exactly on the ceiling.
		$this->assertTrue($this->classifier->fitsDeMinimis(50000.0, 250000.0));
	}//end testDeMinimisCeiling()

	/**
	 * @return void
	 */
	public function testHeadroom(): void {
		$this->assertSame(50000.0, $this->classifier->deMinimisHeadroom(250000.0));
		$this->assertSame(0.0, $this->classifier->deMinimisHeadroom(350000.0));
	}//end testHeadroom()

	/**
	 * REQ-SUB-008: above the ceiling a state-aid ground is mandatory.
	 *
	 * @return void
	 */
	public function testRequiresGrondslag(): void {
		$this->assertFalse($this->classifier->requiresStaatssteunGrondslag(40000.0, 250000.0));
		$this->assertTrue($this->classifier->requiresStaatssteunGrondslag(60000.0, 250000.0));
	}//end testRequiresGrondslag()

	/**
	 * @return void
	 */
	public function testClassificationTree(): void {
		// DAEB wins.
		$this->assertSame('daeb', $this->classifier->classify(500000.0, 0.0, 'art14', true));
		// Within ceiling -> de_minimis.
		$this->assertSame('de_minimis', $this->classifier->classify(40000.0, 250000.0));
		// Zero amount within ceiling -> geen.
		$this->assertSame('none', $this->classifier->classify(0.0, 0.0));
		// Above ceiling with a valid AGVV article -> agvv.
		$this->assertSame('agvv', $this->classifier->classify(60000.0, 250000.0, 'art14'));
		// Above ceiling without cover -> notificatieplicht.
		$this->assertSame('notificatieplicht', $this->classifier->classify(60000.0, 250000.0));
		$this->assertSame('notificatieplicht', $this->classifier->classify(60000.0, 250000.0, 'art999'));
	}//end testClassificationTree()

	/**
	 * @return void
	 */
	public function testTamMelding(): void {
		$report = $this->classifier->buildTamMelding('SUB-2026-000001', 'art14', 60000.0);
		$this->assertSame('TAM', $report['register']);
		$this->assertSame('SUB-2026-000001', $report['beschikkingnummer']);
		$this->assertStringContainsString('art14', (string)$report['rechtsgrond']);
		$this->assertSame(60000.0, $report['amount']);
	}//end testTamMelding()
}//end class
