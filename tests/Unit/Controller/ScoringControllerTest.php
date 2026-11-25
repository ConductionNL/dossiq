<?php

/**
 * ScoringControllerTest
 *
 * A reader of the case reads its scoring sheet; only a handler who may change
 * the case scores a bid, as the session user; a refusal answers 422 with the
 * sentence.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\ScoringController;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\ScoringSheetInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for ScoringController.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */
class ScoringControllerTest extends TestCase {

	/**
	 * The sheet.
	 *
	 * @var ScoringSheetInterface
	 */
	private ScoringSheetInterface $sheet;

	/**
	 * The access guard.
	 *
	 * @var CaseAccessGuardInterface
	 */
	private CaseAccessGuardInterface $caseAccess;

	/**
	 * Mocks with a signed-in evaluator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sheet      = $this->createMock(ScoringSheetInterface::class);
		$this->caseAccess = $this->createMock(CaseAccessGuardInterface::class);
	}//end setUp()

	/**
	 * A reader gets the sheet for themselves.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheirSheet(): void {
		$sheet = ['formula' => ['key' => 'price-quality', 'title' => 'P'], 'inputs' => ['quality'], 'fixed' => [], 'submissions' => [], 'refusal' => null];
		$this->caseAccess->method('hasCaseReadAccess')->with('case-1', $this->anything())->willReturn(true);
		$this->sheet->expects($this->once())->method('forCase')->with('case-1', 'eva')->willReturn($sheet);

		$response = $this->controller()->sheet(caseId: ' case-1 ');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($sheet, $response->getData());
	}//end testAReaderGetsTheirSheet()

	/**
	 * A non-reader reads nothing.
	 *
	 * @return void
	 */
	public function testANonReaderIsRefused(): void {
		$this->caseAccess->method('hasCaseReadAccess')->willReturn(false);
		$this->sheet->expects($this->never())->method('forCase');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->sheet(caseId: 'case-1')->getStatus());
	}//end testANonReaderIsRefused()

	/**
	 * A handler scores a bid as the session user, never as a body field.
	 *
	 * @return void
	 */
	public function testAHandlerScoresABidAsThemselves(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->sheet->expects($this->once())
			->method('score')
			->with('case-1', 'bid-1', ['quality' => 70], 'eva')
			->willReturn(['evaluationId' => 'evaluation-1', 'total' => 74.0]);

		$response = $this->controller(params: ['bid' => ' bid-1 ', 'scores' => ['quality' => 70], 'evaluator' => 'mallory'])->score(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['evaluationId' => 'evaluation-1', 'total' => 74.0], $response->getData());
	}//end testAHandlerScoresABidAsThemselves()

	/**
	 * A user who may only read the case scores nothing.
	 *
	 * @return void
	 */
	public function testAReaderWhoMayNotChangeTheCaseScoresNothing(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(false);
		$this->sheet->expects($this->never())->method('score');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(params: ['bid' => 'bid-1'])->score(caseId: 'case-1')->getStatus());
	}//end testAReaderWhoMayNotChangeTheCaseScoresNothing()

	/**
	 * A refusal answers 422 with the sentence; scores that are no object count as none.
	 *
	 * @return void
	 */
	public function testARefusalAnswersItsSentence(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->sheet->method('score')->with('case-1', 'bid-1', [], 'eva')->willReturn(['refusal' => 'Enter a number for quality.']);

		$response = $this->controller(params: ['bid' => 'bid-1', 'scores' => 'seventy'])->score(caseId: 'case-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'Enter a number for quality.'], $response->getData());
	}//end testARefusalAnswersItsSentence()

	/**
	 * A failing register answers unavailable, on read and on score.
	 *
	 * @return void
	 */
	public function testAFailingRegisterAnswersUnavailable(): void {
		$this->caseAccess->method('hasCaseReadAccess')->willReturn(true);
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->sheet->method('forCase')->willThrowException(new RuntimeException('down'));
		$this->sheet->method('score')->willThrowException(new RuntimeException('down'));

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller()->sheet(caseId: 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $this->controller(params: ['bid' => 'bid-1'])->score(caseId: 'case-1')->getStatus());
	}//end testAFailingRegisterAnswersUnavailable()

	/**
	 * Without a session user nothing is read or scored.
	 *
	 * @return void
	 */
	public function testWithoutAUserNothingHappens(): void {
		$this->sheet->expects($this->never())->method('forCase');
		$this->sheet->expects($this->never())->method('score');

		$controller = $this->controller(user: false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->sheet(caseId: 'case-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->score(caseId: 'case-1')->getStatus());
	}//end testWithoutAUserNothingHappens()

	/**
	 * The controller with the given request parameters.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool                 $user   Whether someone is signed in.
	 *
	 * @return ScoringController
	 */
	private function controller(array $params = [], bool $user = true): ScoringController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));

		$evaluator = $this->createMock(IUser::class);
		$evaluator->method('getUID')->willReturn('eva');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user === true ? $evaluator : null);

		return new ScoringController(
			appName: 'dossiq',
			request: $request,
			sheet: $this->sheet,
			userSession: $session,
			caseAccess: $this->caseAccess,
		);
	}//end controller()
}//end class
