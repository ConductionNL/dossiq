<?php

/**
 * Repair-step edge tests for carrying renamed settings onto their current keys.
 *
 * A key whose copy fails must be logged and skipped without stopping the keys
 * after it, and the step names itself for `occ upgrade`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\CarryRenamedSettingKeys;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Woo\WooReportSwitches;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\CarryRenamedSettingKeys
 */
final class CarryRenamedSettingKeysEdgesTest extends TestCase {

	/**
	 * A failing write on one key is logged with both key names, the other keys
	 * are still carried, and the count only includes the ones that landed.
	 *
	 * @return void
	 */
	public function testAFailingCopyIsLoggedAndTheOtherKeysAreStillCarried(): void {
		$pairs = array_merge(WooReportSwitches::LEGACY_KEYS, MoneyConfigKeys::LEGACY_KEYS);
		$failing = array_key_first($pairs);
		$written = [];

		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(
			static fn (string $app, string $key): bool => in_array($key, $pairs, true)
		);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => 'value-of-' . $key
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($failing, &$written): bool {
				if ($key === $failing) {
					throw new RuntimeException('database is read-only');
				}

				$written[$key] = $value;
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: could not carry setting {legacy} onto {current}; the old key is still read',
			['legacy' => $pairs[$failing], 'current' => $failing, 'error' => 'database is read-only']
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Carried ' . (count($pairs) - 1) . ' renamed setting(s) onto their current keys.');

		(new CarryRenamedSettingKeys(appConfig: $config, logger: $logger))->run($output);

		$expected = $pairs;
		unset($expected[$failing]);
		$this->assertSame(
			array_map(static fn (string $legacy): string => 'value-of-' . $legacy, $expected),
			$written
		);
	}//end testAFailingCopyIsLoggedAndTheOtherKeysAreStillCarried()

	/**
	 * The step names what it does, in the words `occ upgrade` prints.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$step = new CarryRenamedSettingKeys(
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame('Carry renamed settings onto their current keys', $step->getName());
	}//end testTheStepNamesItself()
}//end class
