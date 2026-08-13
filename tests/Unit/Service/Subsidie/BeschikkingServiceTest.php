<?php

/**
 * BeschikkingService Unit Tests.
 *
 * Exercises the grant-decision validation and bezwaartermijn math
 * (REQ-SUB-001): draft validity (voorschot-schema reconciliation) and the
 * 6-week objection window.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Subsidie
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Subsidie;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Subsidie\BeschikkingService;
use OCA\Procest\Service\Subsidie\SubsidieService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\Subsidie\BeschikkingService
 *
 * @uses \OCA\Procest\Service\Subsidie\SubsidieService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-09
 */
class BeschikkingServiceTest extends TestCase {

	private BeschikkingService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(SettingsService::class);
		$session = $this->createMock(IUserSession::class);
		$logger = $this->createMock(LoggerInterface::class);
		$core = new SubsidieService($settings, $logger);
		$this->service = new BeschikkingService($settings, $core, $session, $logger);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testBezwaartermijnSixWeeks(): void {
		$publication = new DateTimeImmutable('2026-06-01');
		$this->assertSame('2026-07-13', $this->service->computeBezwaartermijn($publication)->format('Y-m-d'));
	}//end testBezwaartermijnSixWeeks()

	/**
	 * REQ-SUB-001: a draft with a non-reconciling voorschot-schema is rejected.
	 *
	 * @return void
	 */
	public function testDraftRejectsMismatchedVoorschot(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->assertDraftValid([
			'grantedAmount' => 450000,
			'voorschotSchema' => [
				['date' => '2026-01-15', 'amount' => 100000],
			],
		]);
	}//end testDraftRejectsMismatchedVoorschot()

	/**
	 * A draft with a reconciling voorschot-schema (passed as a JSON string)
	 * is accepted.
	 *
	 * @return void
	 */
	public function testDraftAcceptsReconcilingVoorschot(): void {
		$this->service->assertDraftValid([
			'grantedAmount' => 240000,
			'voorschotSchema' => json_encode([
				['date' => '2026-01-15', 'amount' => 120000],
				['date' => '2027-01-15', 'amount' => 120000],
			]),
		]);
		$this->addToAssertionCount(1);
	}//end testDraftAcceptsReconcilingVoorschot()

	/**
	 * A non-positive verleendBedrag is rejected.
	 *
	 * @return void
	 */
	public function testDraftRejectsNonPositiveAmount(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->assertDraftValid(['grantedAmount' => 0]);
	}//end testDraftRejectsNonPositiveAmount()

	/**
	 * A draft with no voorschot-schema (lump-sum grant) is accepted.
	 *
	 * @return void
	 */
	public function testDraftAllowsNoVoorschot(): void {
		$this->service->assertDraftValid(['grantedAmount' => 10000]);
		$this->addToAssertionCount(1);
	}//end testDraftAllowsNoVoorschot()
}//end class
