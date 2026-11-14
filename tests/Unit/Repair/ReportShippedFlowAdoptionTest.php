<?php

/**
 * What the upgrade reports about shipped flows that still need adopting.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ReportShippedFlowAdoption;
use OCA\Dossiq\Service\Flow\ShippedFlowAdoption;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unavailable, none stored, all adopted, and each reason a flow is pending.
 *
 * @covers \OCA\Dossiq\Repair\ReportShippedFlowAdoption
 */
final class ReportShippedFlowAdoptionTest extends TestCase {
	/** @var array<int, array{0: string, 1: string}> */
	private array $out = [];

	/** @var array<int, array{0: string, 1: array}> */
	private array $logged = [];

	/**
	 * Run the report over this census and these outstanding flows.
	 *
	 * @param array|RuntimeException $census  The census, or what it throws.
	 * @param array                  $pending What outstanding() answers.
	 */
	private function report(array|RuntimeException $census, array $pending = []): void {
		$adoption = $this->createMock(originalClassName: ShippedFlowAdoption::class);
		if ($census instanceof RuntimeException) {
			$adoption->method('census')->willThrowException($census);
		} else {
			$adoption->method('census')->willReturn($census);
			$adoption->method('outstanding')->with($census['flows'])->willReturn($pending);
		}

		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $line) use ($level): void {
					$this->out[] = [$level, $line];
				}
			);
		}

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		(new ReportShippedFlowAdoption(adoption: $adoption, logger: $logger))->run($output);
	}//end report()

	/**
	 * A flow row.
	 *
	 * @param string $name    The name.
	 * @param string $owner   The owner.
	 * @param bool   $enabled Whether enabled.
	 */
	private static function flow(string $name, string $owner, bool $enabled): array {
		return ['uuid' => 'u-'.$name, 'name' => $name, 'enabled' => $enabled, 'owner' => $owner];
	}//end flow()

	public function testAFailingCensusIsLoggedAndReportsNothing(): void {
		$this->report(census: new RuntimeException('no flow table'));

		$this->assertSame([], $this->out);
		$this->assertSame([['Dossiq: could not census the shipped flows: no flow table', []]], $this->logged);
	}//end testAFailingCensusIsLoggedAndReportsNothing()

	public function testAnUnavailableCensusSaysWhy(): void {
		$this->report(census: ['available' => false, 'note' => 'OpenRegister is too old for flows.', 'flows' => []]);

		$this->assertSame([['info', 'Dossiq: OpenRegister is too old for flows.']], $this->out);
	}//end testAnUnavailableCensusSaysWhy()

	public function testNoStoredFlowsPointsAtTheImport(): void {
		$this->report(census: ['available' => true, 'note' => '', 'flows' => []]);

		$this->assertCount(1, $this->out);
		$this->assertSame('warning', $this->out[0][0]);
		$this->assertStringStartsWith('Dossiq: no shipped flows are stored.', $this->out[0][1]);
		$this->assertStringContainsString('[SchemaFlowImport]', $this->out[0][1]);
	}//end testNoStoredFlowsPointsAtTheImport()

	public function testAllAdoptedIsCounted(): void {
		$flows = [self::flow('a', 'admin', true), self::flow('b', 'admin', true)];
		$this->report(census: ['available' => true, 'note' => '', 'flows' => $flows], pending: []);

		$this->assertSame([['info', 'Dossiq: all 2 shipped flow(s) are adopted and enabled.']], $this->out);
		$this->assertSame([], $this->logged);
	}//end testAllAdoptedIsCounted()

	public function testEachPendingFlowSaysWhyAndTheCommandFollows(): void {
		$pending = [self::flow('Intake', '', false), self::flow('Besluit', '', true), self::flow('Archief', 'admin', false)];
		$flows   = array_merge($pending, [self::flow('Klaar', 'admin', true)]);

		$this->report(census: ['available' => true, 'note' => '', 'flows' => $flows], pending: $pending);

		$this->assertSame(
			[
				['warning', 'Dossiq: shipped flow "Intake" has no owner and is disabled, so it will not run.'],
				['warning', 'Dossiq: shipped flow "Besluit" has no owner, so it will not run.'],
				['warning', 'Dossiq: shipped flow "Archief" is disabled, so it will not run.'],
			],
			array_slice($this->out, 0, 3)
		);
		$this->assertCount(4, $this->out);
		$this->assertStringStartsWith('Dossiq: complete the adoption with: occ dossiq:flows:adopt --user <admin> --enable', $this->out[3][1]);
		$this->assertSame(
			[['Dossiq: shipped flows await adoption', ['app' => 'dossiq', 'pending' => 3, 'total' => 4, 'command' => 'occ dossiq:flows:adopt --user <admin> --enable']]],
			$this->logged
		);
	}//end testEachPendingFlowSaysWhyAndTheCommandFollows()
}//end class
