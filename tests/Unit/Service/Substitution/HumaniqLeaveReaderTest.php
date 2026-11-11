<?php

/**
 * The humaniq leave read behind a substitution's period
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Substitution
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

namespace OCA\Dossiq\Tests\Unit\Service\Substitution;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Substitution\HumaniqLeaveReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When the reader answers no leave, and what it logs.
 *
 * @covers \OCA\Dossiq\Service\Substitution\HumaniqLeaveReader
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
final class HumaniqLeaveReaderTest extends TestCase {
	/**
	 * The log lines, as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * A reader over a store that answers the rows per schema, or throws.
	 *
	 * @param bool                   $installed Whether humaniq is installed.
	 * @param array|RuntimeException $rows      The rows per schema, or the error the store throws.
	 * @param string                 $register  The configured register.
	 *
	 * @return HumaniqLeaveReader
	 */
	private function reader(bool $installed, array|RuntimeException $rows = [], string $register = 'humaniq'): HumaniqLeaveReader {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($installed);
		$store = new class ($rows) {
			/**
			 * Constructor.
			 *
			 * @param array|RuntimeException $rows The answer.
			 */
			public function __construct(private array|RuntimeException $rows) {
			}

			/**
			 * The rows of the schema asked for.
			 *
			 * @return array
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($this->rows instanceof RuntimeException) {
					throw $this->rows;
				}

				return ($this->rows[$schema] ?? []);
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturn($register);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = [$level, $message];
				}
			);
		}

		return new HumaniqLeaveReader(appManager: $apps, settingsService: $settings, logger: $logger);
	}//end reader()

	/**
	 * Without humaniq there is no leave, said once.
	 *
	 * @return void
	 */
	public function testWithoutHumaniqThereIsNoLeaveSaidOnce(): void {
		$reader = $this->reader(installed: false);
		$this->assertNull($reader->approvedLeaveCovering('jan', '2026-07-10'));
		$this->assertNull($reader->approvedLeaveCovering('jan', '2026-07-11'));
		$this->assertSame([['info', 'Dossiq: humaniq is not installed, so a substitution keeps its typed dates']], $this->logged);
	}//end testWithoutHumaniqThereIsNoLeaveSaidOnce()

	/**
	 * No register, no employee, or a store that throws answers no leave; only the throw is logged.
	 *
	 * @return void
	 */
	public function testNoRegisterNoEmployeeOrAFailedReadIsNoLeave(): void {
		$this->assertNull($this->reader(installed: true, register: '')->approvedLeaveCovering('jan', '2026-07-10'));
		$this->assertNull($this->reader(installed: true, rows: ['Employee' => []])->approvedLeaveCovering('jan', '2026-07-10'));
		$this->assertSame([], $this->logged);

		$this->assertNull($this->reader(installed: true, rows: new RuntimeException('down'))->approvedLeaveCovering('jan', '2026-07-10'));
		$this->assertSame([['warning', 'Dossiq: could not read humaniq leave for a substitution']], $this->logged);
	}//end testNoRegisterNoEmployeeOrAFailedReadIsNoLeave()
}//end class
