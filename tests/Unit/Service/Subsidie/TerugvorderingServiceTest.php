<?php

/**
 * TerugvorderingService Unit Tests.
 *
 * Exercises the clawback math (REQ-SUB-005): bezwaartermijn/betaaltermijn
 * dates, invorderingsrente accrual per AWB 4:97, and the (partial-)payment
 * status machine.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\TerugvorderingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Subsidie\TerugvorderingService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-20
 */
class TerugvorderingServiceTest extends TestCase {

	private TerugvorderingService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new TerugvorderingService($settings, $logger);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testTermijnDates(): void {
		$publication = new DateTimeImmutable('2026-06-01');
		// 6 weeks bezwaartermijn.
		$this->assertSame('2026-07-13', $this->service->computeBezwaartermijn($publication)->format('Y-m-d'));
		// 4 weeks betaaltermijn.
		$this->assertSame('2026-06-29', $this->service->computeBetaaltermijn($publication)->format('Y-m-d'));
	}//end testTermijnDates()

	/**
	 * AWB 4:97 invorderingsrente: 6 % p/a over a one-year window on €30.000.
	 *
	 * @return void
	 */
	public function testInvorderingsrenteOneYear(): void {
		$from = new DateTimeImmutable('2026-01-01');
		$tot = new DateTimeImmutable('2027-01-01');
		// 365 days at 6 %: 30000 * 0.06 * (365/365) = 1800.00.
		$this->assertSame(1800.0, $this->service->computeInvorderingsrente(30000.0, $from, $tot));
	}//end testInvorderingsrenteOneYear()

	/**
	 * Rente is zero when the end date is on or before the start date, or when
	 * nothing is outstanding.
	 *
	 * @return void
	 */
	public function testInvorderingsrenteGuards(): void {
		$from = new DateTimeImmutable('2026-01-01');
		$this->assertSame(0.0, $this->service->computeInvorderingsrente(30000.0, $from, $from));
		$tot = new DateTimeImmutable('2025-01-01');
		$this->assertSame(0.0, $this->service->computeInvorderingsrente(30000.0, $from, $tot));
		$tot2 = new DateTimeImmutable('2027-01-01');
		$this->assertSame(0.0, $this->service->computeInvorderingsrente(0.0, $from, $tot2));
	}//end testInvorderingsrenteGuards()

	/**
	 * @return void
	 */
	public function testPaymentStatusMachine(): void {
		$this->assertSame('opgelegd', $this->service->statusAfterPayment(30000.0, 0.0));
		$this->assertSame('gedeeltelijk_paid', $this->service->statusAfterPayment(30000.0, 10000.0));
		$this->assertSame('paid', $this->service->statusAfterPayment(30000.0, 30000.0));
	}//end testPaymentStatusMachine()
}//end class
