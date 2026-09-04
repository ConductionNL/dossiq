<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair\Vth
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Vth;

use OCA\Dossiq\Repair\Vth\VthCatalogueReport;
use PHPUnit\Framework\TestCase;

/**
 * The summary an administrator reads after the VTH catalogue is seeded.
 *
 * The case under test is the one the catalogue actually hits: two entries on
 * one case type, so the second publish deprecates the first. The deprecation is
 * correct behaviour and used to be invisible.
 *
 * @covers \OCA\Dossiq\Repair\Vth\VthCatalogueReport
 */
class VthCatalogueReportTest extends TestCase {

	/**
	 * The report under test.
	 *
	 * @var VthCatalogueReport
	 */
	private VthCatalogueReport $report;

	/**
	 * Build a fresh report.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->report = new VthCatalogueReport();
	}//end setUp()

	/**
	 * A publish onto an empty case type displaces nothing.
	 *
	 * @return void
	 */
	public function testNothingIsDisplacedWhenTheCaseTypeHadNoDefinition(): void {
		self::assertSame(
			'',
			$this->report->displacedTitle(displaced: null, publishedId: 'new-id')
		);
	}//end testNothingIsDisplacedWhenTheCaseTypeHadNoDefinition()

	/**
	 * Republishing the same definition does not count as displacing itself.
	 *
	 * @return void
	 */
	public function testADefinitionDoesNotDisplaceItself(): void {
		self::assertSame(
			'',
			$this->report->displacedTitle(
				displaced: ['id' => 'same-id', 'title' => 'Handhavingstraject'],
				publishedId: 'same-id'
			)
		);
	}//end testADefinitionDoesNotDisplaceItself()

	/**
	 * A second definition on one case type displaces the first, by name.
	 *
	 * @return void
	 */
	public function testTheDefinitionAPublishRetiresIsNamed(): void {
		self::assertSame(
			'Handhavingstraject',
			$this->report->displacedTitle(
				displaced: ['id' => 'first-id', 'title' => 'Handhavingstraject'],
				publishedId: 'second-id'
			)
		);
	}//end testTheDefinitionAPublishRetiresIsNamed()

	/**
	 * An ordinary seed reads as an ordinary seed.
	 *
	 * @return void
	 */
	public function testTheSeededLineSaysOnlyWhatHappened(): void {
		$reason = $this->report->seededReason(
			title: 'Toezichtbezoek',
			version: 1,
			displacedTitle: ''
		);

		self::assertSame('seeded and published as "Toezichtbezoek" version 1.', $reason);
	}//end testTheSeededLineSaysOnlyWhatHappened()

	/**
	 * A seed that retires another definition says which one, and why.
	 *
	 * @return void
	 */
	public function testTheSeededLineNamesWhatItDeprecated(): void {
		$reason = $this->report->seededReason(
			title: 'Spoedig herstel (Awb 5:31)',
			version: 1,
			displacedTitle: 'Handhavingstraject'
		);

		self::assertStringContainsString('"Spoedig herstel (Awb 5:31)" version 1', $reason);
		self::assertStringContainsString('This deprecated "Handhavingstraject"', $reason);
		self::assertStringContainsString('one published definition per case type', $reason);
	}//end testTheSeededLineNamesWhatItDeprecated()

	/**
	 * The summary counts every entry and prints one line each.
	 *
	 * @return void
	 */
	public function testTheSummaryPrintsALinePerEntry(): void {
		$this->report->reset();
		$this->report->record(entry: 'handhavingstraject', outcome: 'seeded', reason: 'seeded.');
		$this->report->record(entry: 'klacht-toezicht', outcome: 'skipped', reason: 'no case type.');

		$lines = [];
		$output = $this->createMock(\OCP\Migration\IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $line) use (&$lines): void {
				$lines[] = $line;
			}
		);

		$this->report->write(output: $output);

		self::assertStringContainsString('1 seeded', $lines[0]);
		self::assertStringContainsString('1 skipped', $lines[0]);
		self::assertContains('  handhavingstraject: seeded.', $lines);
		self::assertContains('  klacht-toezicht: no case type.', $lines);
	}//end testTheSummaryPrintsALinePerEntry()
}//end class
