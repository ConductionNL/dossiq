<?php

/**
 * Failure paths of PenaltyPaymentRows: an unreadable payment list and unreadable linked records.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\PenaltyPaymentRows;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for PenaltyPaymentRows when a read fails.
 *
 * @covers \OCA\Dossiq\Service\Termijn\PenaltyPaymentRows
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class PenaltyPaymentRowsFailuresTest extends TestCase {

	/**
	 * Settings over a store.
	 *
	 * @param FakeTermijnStore $objects The store.
	 *
	 * @return SettingsService
	 */
	private function settings(FakeTermijnStore $objects): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				'accruing_amount_schema' => 'penaltyPaymentCalculation',
				'case_schema' => 'case',
				'case_type_schema' => 'caseType',
				'payout_schema' => 'dwangsomUitbetaling',
				default => '',
			},
		);

		return $settings;
	}//end settings()

	/**
	 * A payment list that cannot be read is an empty year, not an error.
	 *
	 * @return void
	 */
	public function testAnUnreadablePaymentListIsAnEmptyYear(): void {
		$objects = new class extends FakeTermijnStore {
			/**
			 * Every search fails.
			 *
			 * @param string               $registerSlug The register.
			 * @param string               $schemaSlug   The schema.
			 * @param array<string, mixed> $filters      The filters.
			 *
			 * @return never
			 */
			public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): never {
				throw new RuntimeException('search down');
			}
		};

		self::assertSame(
			[
				'year'        => 2026,
				'rows'        => [],
				'summary'     => ['count' => 0, 'totalEuros' => 0.0, 'disputedCount' => 0],
				'summaryRows' => [['year' => 2026, 'count' => 0, 'totalEuros' => 0.0, 'disputedCount' => 0]],
			],
			(new PenaltyPaymentRows($this->settings(objects: $objects)))->forYear(2026)
		);
	}//end testAnUnreadablePaymentListIsAnEmptyYear()

	/**
	 * A payment whose calculation cannot be read keeps its amount but has no date, so falls outside every year.
	 *
	 * @return void
	 */
	public function testAPaymentWithAnUnreadableCalculationHasNoForfeitDate(): void {
		$objects = new class extends FakeTermijnStore {
			/**
			 * Every single-object read fails.
			 *
			 * @param int|string      $id       The id.
			 * @param array|null      $_extend  Unused.
			 * @param bool            $files    Unused.
			 * @param string|int|null $register Unused.
			 * @param string|int|null $schema   Unused.
			 *
			 * @return never
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): never {
				throw new RuntimeException('store offline');
			}
		};
		$objects->seed('dwangsomUitbetaling', ['id' => 'pay-1', 'penaltyPaymentCalculation' => 'calc-1', 'amount' => 12345, 'status' => 'paid']);

		$year = (new PenaltyPaymentRows($this->settings(objects: $objects)))->forYear(2026);

		self::assertSame([], $year['rows']);
		self::assertSame(0, $year['summary']['count']);
	}//end testAPaymentWithAnUnreadableCalculationHasNoForfeitDate()
}//end class
