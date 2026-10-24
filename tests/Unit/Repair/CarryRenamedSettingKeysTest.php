<?php

/**
 * Repair-step tests for carrying renamed settings onto their current keys.
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
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\CarryRenamedSettingKeys
 */
final class CarryRenamedSettingKeysTest extends TestCase {

	/**
	 * The stored app config the step reads and writes.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The step over an in-memory app config.
	 *
	 * @return CarryRenamedSettingKeys
	 */
	private function step(): CarryRenamedSettingKeys {
		$config = $this->createMock(IAppConfig::class);
		$config->method('hasKey')->willReturnCallback(fn (string $app, string $key): bool => array_key_exists($key, $this->stored));
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;
			return true;
		});

		return new CarryRenamedSettingKeys(appConfig: $config, logger: $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * The old values reach the current keys once, and the old keys stay.
	 *
	 * @return void
	 */
	public function testOldValuesAreCarriedOnceAndKept(): void {
		$this->stored = ['wooReviewerThroughputReport' => 'true', 'wooReviewerThroughputReaders' => 'woo-leiding'];
		$step = $this->step();

		$first = $this->createMock(IOutput::class);
		$first->expects($this->once())->method('info')->with($this->stringContains('Carried 2'));
		$step->run($first);

		self::assertSame('true', $this->stored['reviewerThroughputReport']);
		self::assertSame('woo-leiding', $this->stored['reviewerThroughputReaders']);
		self::assertArrayNotHasKey('collectionPartiesReport', $this->stored, 'A key never set is not invented.');
		self::assertSame('true', $this->stored['wooReviewerThroughputReport'], 'The old key stays for a rollback.');

		$before = $this->stored;
		$second = $this->createMock(IOutput::class);
		$second->expects($this->never())->method('info');
		$step->run($second);
		self::assertSame($before, $this->stored);
	}//end testOldValuesAreCarriedOnceAndKept()

	/**
	 * The money keys that named a procedure reach their generic keys, the callback secret included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-003)
	 */
	public function testMoneyKeysAreCarriedToTheirGenericNames(): void {
		$this->stored = [
			'dwangsom_berekening_schema' => '41',
			'dwangsom_uitbetaling_schema' => '42',
			'dwangsom_callback_secret' => 's3cret',
			'ingebrekestelling_schema' => '40',
		];
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains(string: 'Carried 4'));

		$this->step()->run($output);

		self::assertSame(expected: '41', actual: $this->stored['accruing_amount_schema']);
		self::assertSame(expected: '42', actual: $this->stored['payout_schema']);
		self::assertSame(expected: 's3cret', actual: $this->stored['payout_callback_secret']);
		self::assertSame(expected: '40', actual: $this->stored['notice_of_default_schema']);
		self::assertSame(expected: 's3cret', actual: $this->stored['dwangsom_callback_secret'], message: 'The old key stays for a rollback.');
	}//end testMoneyKeysAreCarriedToTheirGenericNames()

	/**
	 * A current key an administrator already set is never overwritten, and no old keys means nothing to do.
	 *
	 * @return void
	 */
	public function testNothingToDoAndNothingOverwritten(): void {
		$this->stored = ['wooCollectionPartiesReport' => 'true', 'collectionPartiesReport' => 'false'];
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');
		$this->step()->run($output);
		self::assertSame('false', $this->stored['collectionPartiesReport']);

		$this->stored = [];
		$this->step()->run($output);
		self::assertSame([], $this->stored);
	}//end testNothingToDoAndNothingOverwritten()
}//end class
