<?php

/**
 * The payout move to finance on the paths the main test leaves alone: no
 * payout schema configured, rows that cannot be read, finance refusing a row,
 * and finance answering without a payable id.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Kernel\Contracts\PayoutServiceInterface;
use OCA\Dossiq\Repair\MigratePayoutRowsToFinance;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\MigratePayoutRowsToFinance
 *
 * @uses \OCA\Dossiq\Repair\PayoutRowStore
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class MigratePayoutRowsToFinanceEdgesTest extends TestCase {

	/**
	 * A payout store over the given rows; reading throws when $rows is null.
	 *
	 * @param array<int, array<string, mixed>>|null $rows The rows.
	 *
	 * @return object The fake object service.
	 */
	private function store(?array $rows): object {
		return new class($rows) {
			/**
			 * @var array<int, array{id: string, data: array<string, mixed>}>
			 */
			public array $patches = [];

			/**
			 * @param array<int, array<string, mixed>>|null $rows The rows.
			 */
			public function __construct(private readonly ?array $rows) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 *
			 * @throws RuntimeException When the rows are unreadable.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->rows === null) {
					throw new RuntimeException('payout table is locked');
				}

				return $this->rows;
			}

			/**
			 * @param ObjectPatch $patch The patch.
			 * @param bool        $_rbac Ignored.
			 * @param bool        $_multitenancy Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(ObjectPatch $patch, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->patches[] = ['id' => $patch->objectId, 'data' => $patch->data];
				return $patch->data;
			}
		};
	}//end store()

	/**
	 * The step over a store.
	 *
	 * @param object|null            $store   The object service.
	 * @param PayoutServiceInterface $payouts The finance hand-off.
	 * @param LoggerInterface        $logger  The logger.
	 * @param string                 $schema  The configured payout schema.
	 *
	 * @return MigratePayoutRowsToFinance The step.
	 */
	private function step(?object $store, PayoutServiceInterface $payouts, LoggerInterface $logger, string $schema = 'dwangsomUitbetaling'): MigratePayoutRowsToFinance {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'payout_schema' => $schema,
				default => '',
			}
		);

		return new MigratePayoutRowsToFinance(settingsService: $settings, payouts: $payouts, logger: $logger);
	}//end step()

	/**
	 * Without a configured payout schema nothing is read, nothing is raised and nothing is said.
	 *
	 * @return void
	 */
	public function testWithoutAPayoutSchemaNothingHappens(): void {
		$payouts = $this->createMock(PayoutServiceInterface::class);
		$payouts->expects($this->never())->method('raiseRowInFinance');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$this->step($this->store([]), $payouts, $this->createMock(LoggerInterface::class), schema: '')->run($output);
	}//end testWithoutAPayoutSchemaNothingHappens()

	/**
	 * Rows that cannot be read are logged and nothing is raised.
	 *
	 * @return void
	 */
	public function testUnreadableRowsAreLoggedAndNothingIsRaised(): void {
		$payouts = $this->createMock(PayoutServiceInterface::class);
		$payouts->expects($this->never())->method('raiseRowInFinance');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the payout rows could not be read for the move to finance',
			['error' => 'payout table is locked']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$this->step($this->store(null), $payouts, $logger)->run($output);
	}//end testUnreadableRowsAreLoggedAndNothingIsRaised()

	/**
	 * A row finance refuses is logged by reference and left without a payable;
	 * a row finance answers without a payable id is left too; a row finance
	 * takes keeps its payable id and is the only one counted.
	 *
	 * @return void
	 */
	public function testARefusedOrUnansweredRowStaysForTheNextRun(): void {
		$rows = [
			['id' => 'u-refused', 'reference' => 'DWS-REF-0001', 'status' => 'voorbereid'],
			['id' => 'u-silent', 'reference' => 'DWS-SIL-0001', 'status' => 'voorbereid'],
			['id' => 'u-taken', 'reference' => 'DWS-TAK-0001', 'status' => 'paid', 'actualPaymentDate' => '2026-03-03', 'betalingsreferentie' => 'ERP-9'],
		];
		$store = $this->store($rows);

		$payouts = $this->createMock(PayoutServiceInterface::class);
		$payouts->expects($this->exactly(3))->method('raiseRowInFinance')->willReturnCallback(
			static function (array $row, array $history): array {
				return match ($row['id']) {
					'u-refused' => throw new RuntimeException('no account holder'),
					'u-silent' => [],
					default => ['payableId' => 'payable-1', 'history' => $history],
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the finance app refused penalty payout {reference}; it stays for the next run',
			['reference' => 'DWS-REF-0001', 'error' => 'no account holder']
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Handed 1 penalty payout(s) to the finance app.');

		$this->step($store, $payouts, $logger)->run($output);

		$this->assertSame([['id' => 'u-taken', 'data' => ['payableId' => 'payable-1']]], $store->patches);
	}//end testARefusedOrUnansweredRowStaysForTheNextRun()

	/**
	 * The step names what it does, as `occ upgrade` prints it.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		$this->assertSame(
			'Hand the penalty payouts dossiq kept to the finance app',
			$this->step(null, $this->createMock(PayoutServiceInterface::class), $this->createMock(LoggerInterface::class))->getName()
		);
	}//end testTheStepNamesItself()
}//end class
