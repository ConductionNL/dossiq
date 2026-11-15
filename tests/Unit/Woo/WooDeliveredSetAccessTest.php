<?php

/**
 * A delivered set is granted to the people on its case when it is written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Kernel\Contracts\CaseReviewerAccessInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The reconcile after a delivered set is stored (REQ-WRT-006).
 *
 * @covers \OCA\Dossiq\Woo\WooDeliveredSetWriter
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md
 */
class WooDeliveredSetAccessTest extends TestCase {

	/**
	 * The register the sets land in.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A fresh register per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * The case is reconciled once, after the set is stored.
	 *
	 * @return void
	 */
	public function testOpeningASetReconcilesItsCaseAfterTheWrite(): void {
		$storedWhenReconciled = -1;
		$access = $this->createMock(CaseReviewerAccessInterface::class);
		$access->expects(self::once())->method('reconcileAfterCreate')->with('case-1', 1)->willReturnCallback(
			function () use (&$storedWhenReconciled): array {
				$storedWhenReconciled = count($this->store->all(schema: 'wooDeliveredSet'));

				return ['status' => 'reconciled', 'granted' => 1, 'revoked' => 0, 'refused' => 0];
			}
		);

		$set = $this->writer(access: $access)->open(caseId: 'case-1', decisionId: 'dec-1', delivered: []);

		self::assertSame('case-1', $set['case']);
		self::assertSame(1, $storedWhenReconciled, 'the grant follows the stored set, never precedes it');
	}//end testOpeningASetReconcilesItsCaseAfterTheWrite()

	/**
	 * A delivery reconciles through open(), once per set.
	 *
	 * @return void
	 */
	public function testEachDeliveryReconcilesItsOwnSet(): void {
		$access = $this->createMock(CaseReviewerAccessInterface::class);
		$access->expects(self::exactly(2))->method('reconcileAfterCreate')->with('case-2', 1)
			->willReturn(['status' => 'reconciled', 'granted' => 1, 'revoked' => 0, 'refused' => 0]);

		$writer = $this->writer(access: $access);
		$writer->deliver(caseId: 'case-2', decisionId: 'dec-1', delivered: [], send: static fn (): string => 'pub-1');
		$writer->deliver(caseId: 'case-2', decisionId: 'dec-2', delivered: [], send: static fn (): string => 'pub-2');
	}//end testEachDeliveryReconcilesItsOwnSet()

	/**
	 * A set that could not be stored grants nothing.
	 *
	 * @return void
	 */
	public function testASetThatWasNotStoredGrantsNothing(): void {
		$access = $this->createMock(CaseReviewerAccessInterface::class);
		$access->expects(self::never())->method('reconcileAfterCreate');

		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('dossiq');

		$this->expectException(RuntimeException::class);
		(new WooDeliveredSetWriter(settings: $settings, access: $access))->open(caseId: 'case-1', decisionId: 'dec-1', delivered: []);
	}//end testASetThatWasNotStoredGrantsNothing()

	/**
	 * The writer over the in-memory register.
	 *
	 * @param CaseReviewerAccessInterface $access The access service.
	 *
	 * @return WooDeliveredSetWriter The writer.
	 */
	private function writer(CaseReviewerAccessInterface $access): WooDeliveredSetWriter {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq'][$key] ?? $default)
		);

		return new WooDeliveredSetWriter(settings: $settings, access: $access);
	}//end writer()
}//end class
