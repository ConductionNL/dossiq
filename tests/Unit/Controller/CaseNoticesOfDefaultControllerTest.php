<?php

/**
 * Unit tests for CaseNoticesOfDefaultController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseNoticesOfDefaultController;
use OCA\Dossiq\Service\Access\ReadableObjects;
use OCA\Dossiq\Service\Termijn\NoticeOfDefaultRows;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for CaseNoticesOfDefaultController.
 *
 * @covers \OCA\Dossiq\Controller\CaseNoticesOfDefaultController
 */
class CaseNoticesOfDefaultControllerTest extends TestCase {

	/**
	 * The notice rows double.
	 *
	 * @var NoticeOfDefaultRows&MockObject
	 */
	private NoticeOfDefaultRows&MockObject $notices;

	/**
	 * The read check double.
	 *
	 * @var ReadableObjects&MockObject
	 */
	private ReadableObjects&MockObject $readable;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->notices  = $this->createMock(NoticeOfDefaultRows::class);
		$this->readable = $this->createMock(ReadableObjects::class);
	}//end setUp()

	/**
	 * The controller over the doubles.
	 *
	 * @return CaseNoticesOfDefaultController The controller.
	 */
	private function controller(): CaseNoticesOfDefaultController {
		return new CaseNoticesOfDefaultController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			notices: $this->notices,
			readable: $this->readable
		);
	}//end controller()

	/**
	 * A readable case answers its notices unchanged.
	 *
	 * @return void
	 */
	public function testAReadableCaseAnswersItsNotices(): void {
		$rows = ['notices' => [['id' => 'nod-1', 'caseId' => 'case-1', 'financeState' => 'payout-due']]];
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->expects($this->once())->method('findsAsCaller')->with('case', 'case-1')->willReturn(true);
		$this->notices->expects($this->once())->method('forCase')->with('case-1')->willReturn($rows);

		$response = $this->controller()->index(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($rows, $response->getData());
	}//end testAReadableCaseAnswersItsNotices()

	/**
	 * Without a signed-in caller nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutACallerIs401AndReadsNothing(): void {
		$this->readable->method('hasCaller')->willReturn(false);
		$this->readable->expects($this->never())->method('findsAsCaller');
		$this->notices->expects($this->never())->method('forCase');

		$response = $this->controller()->index(caseId: 'case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Sign in to read this case.'], $response->getData());
	}//end testWithoutACallerIs401AndReadsNothing()

	/**
	 * A case the caller may not read is a 404, and its notices are never read.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseIs404(): void {
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->method('findsAsCaller')->willReturn(false);
		$this->notices->expects($this->never())->method('forCase');

		$response = $this->controller()->index(caseId: 'case-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Case not found'], $response->getData());
	}//end testAnUnreadableCaseIs404()

	/**
	 * A failing read check or a failing notice read is a 503, never an empty list.
	 *
	 * @return void
	 */
	public function testAFailingReadIs503(): void {
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->method('findsAsCaller')->willThrowException(new RuntimeException('down'));
		$this->notices->expects($this->never())->method('forCase');

		$response = $this->controller()->index(caseId: 'case-1');
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'The notices of default could not be read. Try again later.'], $response->getData());

		$this->setUp();
		$this->readable->method('hasCaller')->willReturn(true);
		$this->readable->method('findsAsCaller')->willReturn(true);
		$this->notices->method('forCase')->willThrowException(new RuntimeException('down'));

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller()->index(caseId: 'case-1')->getStatus());
	}//end testAFailingReadIs503()
}//end class
