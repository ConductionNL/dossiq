<?php

/**
 * What the upgrade reports about the generic Gemachtigde role type.
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

use OCA\Dossiq\Repair\SeedGemachtigdeRoleType;
use OCA\Dossiq\Service\People\GemachtigdeRoleTypeSeeder;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unavailable, refused, created, adopted and already there.
 *
 * @covers \OCA\Dossiq\Repair\SeedGemachtigdeRoleType
 */
final class SeedGemachtigdeRoleTypeTest extends TestCase {

	/** @var array<int, array{0: string, 1: string}> */
	private array $out = [];

	/**
	 * An output that records every line with its level.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(originalClassName: IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				function (string $line) use ($level): void {
					$this->out[] = [$level, $line];
				}
			);
		}

		return $output;
	}//end recordingOutput()

	/**
	 * Run the step over this seed result.
	 *
	 * @param array                $result The seeder's answer.
	 * @param LoggerInterface|null $logger The logger, a silent one when null.
	 */
	private function seed(array $result, ?LoggerInterface $logger = null): void {
		$seeder = $this->createMock(originalClassName: GemachtigdeRoleTypeSeeder::class);
		$seeder->expects($this->once())->method('seed')->willReturn($result);
		if ($logger === null) {
			$logger = $this->createMock(originalClassName: LoggerInterface::class);
			$logger->expects($this->never())->method('error');
		}

		(new SeedGemachtigdeRoleType(seeder: $seeder, logger: $logger))->run($this->recordingOutput());
	}//end seed()

	/**
	 * A seeder result.
	 *
	 * @param array $overrides The fields that differ from "nothing to do".
	 */
	private static function seedResult(array $overrides): array {
		return ($overrides + ['available' => true, 'refused' => null, 'created' => 0, 'adopted' => 0]);
	}//end seedResult()

	public function testAnUnreachableRegisterIsLeftForTheNextUpgrade(): void {
		$this->seed(result: self::seedResult(['available' => false, 'created' => 1]));

		$this->assertSame(
			[['warning', 'OpenRegister could not be asked for the role types, so the generic Gemachtigde role was not seeded. This seed re-runs on the next upgrade.']],
			$this->out
		);
	}//end testAnUnreachableRegisterIsLeftForTheNextUpgrade()

	public function testARefusalIsWarnedAndLoggedWithItsReason(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq: the generic Gemachtigde role type could not be written', ['reason' => 'validation: key taken']);

		$this->seed(result: self::seedResult(['refused' => 'validation: key taken', 'created' => 1]), logger: $logger);

		$this->assertSame([['warning', 'The generic Gemachtigde role type was refused. See the log.']], $this->out);
	}//end testARefusalIsWarnedAndLoggedWithItsReason()

	public function testCreatedWinsOverAdopted(): void {
		$this->seed(result: self::seedResult(['created' => 1, 'adopted' => 1]));

		$this->assertSame([['info', 'Created the generic Gemachtigde role type. Every case type now offers it.']], $this->out);
	}//end testCreatedWinsOverAdopted()

	public function testAnAdoptedRoleTypeKeepsItsRoles(): void {
		$this->seed(result: self::seedResult(['adopted' => 1]));

		$this->assertSame(
			[['info', 'The Gemachtigde role type this instance already held now carries the generic key, so every case type offers it. The roles pointing at it are unchanged.']],
			$this->out
		);
	}//end testAnAdoptedRoleTypeKeepsItsRoles()

	public function testNothingToDoSaysSo(): void {
		$this->seed(result: self::seedResult([]));

		$this->assertSame([['info', 'The generic Gemachtigde role type is already there. Nothing was written.']], $this->out);
	}//end testNothingToDoSaysSo()
}//end class
