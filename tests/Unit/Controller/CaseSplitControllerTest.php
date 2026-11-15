<?php

/**
 * CaseSplitController unit tests: who may split, what reaches the executor, how refusals read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseSplitController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseSplitExecutorInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A split needs mutation access on the case; the chosen parts reach the executor as given.
 *
 * @covers \OCA\Dossiq\Controller\CaseSplitController
 * @uses   \OCA\Dossiq\Controller\Support\CaseCaller
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class CaseSplitControllerTest extends TestCase {

	/**
	 * The split executor double.
	 *
	 * @var CaseSplitExecutorInterface&MockObject
	 */
	private CaseSplitExecutorInterface $splits;

	/**
	 * The access guard double.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $guard;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A signed-in caller named sofie.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->splits = $this->createMock(CaseSplitExecutorInterface::class);
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return CaseSplitController
	 */
	private function controller(): CaseSplitController {
		return new CaseSplitController(
			appName: 'dossiq',
			request: $this->request,
			splits: $this->splits,
			accessGuard: $this->guard,
			userSession: $this->session,
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Without mutation access the split is refused and nothing moves.
	 *
	 * @return void
	 */
	public function testSplitWithoutMutationAccessIsForbidden(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->splits->expects($this->never())->method('split');

		$response = $this->controller()->split(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(
			['message' => 'You cannot split this case.', 'error' => 'case-access-denied'],
			$response->getData()
		);
	}//end testSplitWithoutMutationAccessIsForbidden()

	/**
	 * An anonymous caller is refused before the guard is even asked.
	 *
	 * @return void
	 */
	public function testSplitWithoutASessionIsForbidden(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');
		$this->splits->expects($this->never())->method('split');

		$response = $this->controller()->split(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testSplitWithoutASessionIsForbidden()

	/**
	 * The trimmed title, the chosen parts and the caller reach the executor; its result is the body.
	 *
	 * @return void
	 */
	public function testSplitPassesTheChoiceToTheExecutor(): void {
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')
			->with('case-1', $this->isInstanceOf(IUser::class))
			->willReturn(true);
		$this->request->method('getParam')->willReturnMap([
			['title', '', '  Second half  '],
			['documents', [], ['doc-1', 'doc-2']],
			['parties', [], 'party-1'],
			['tasks', [], []],
			['partiesOnBoth', [], ['party-9']],
		]);
		$this->splits->expects($this->once())->method('split')
			->with(
				'case-1',
				'Second half',
				[
					'documents' => ['doc-1', 'doc-2'],
					'parties' => ['party-1'],
					'tasks' => [],
					'partiesOnBoth' => ['party-9'],
				],
				'sofie'
			)
			->willReturn(['case' => 'case-2', 'moved' => ['documents' => 2]]);

		$response = $this->controller()->split(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['case' => 'case-2', 'moved' => ['documents' => 2]], $response->getData());
	}//end testSplitPassesTheChoiceToTheExecutor()

	/**
	 * A refusal from the executor keeps its status and rule, and is logged as a refusal.
	 *
	 * @return void
	 */
	public function testSplitRefusalIsTranslatedIntoItsStatus(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnArgument(1);
		$this->splits->method('split')->willThrowException(
			new RefusedException(rule: 'split-nothing-chosen', sentence: '', status: RefusedException::STATUS_UNPROCESSABLE)
		);
		$this->logger->expects($this->once())->method('info')
			->with(
				'CaseSplitController: case split refused',
				['rule' => 'split-nothing-chosen', 'status' => 422]
			);
		$this->logger->expects($this->never())->method('warning');

		$response = $this->controller()->split(caseId: 'case-1');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(
			['message' => '', 'error' => 'split-nothing-chosen', 'code' => 'split_nothing_chosen'],
			$response->getData()
		);
	}//end testSplitRefusalIsTranslatedIntoItsStatus()

	/**
	 * The divisible parts need mutation access too.
	 *
	 * @return void
	 */
	public function testDivisibleWithoutMutationAccessIsForbidden(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->splits->expects($this->never())->method('divisibleParts');

		$response = $this->controller()->divisible(caseId: 'case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('case-access-denied', $response->getData()['error']);
	}//end testDivisibleWithoutMutationAccessIsForbidden()

	/**
	 * The divisible parts come back under `allowed`.
	 *
	 * @return void
	 */
	public function testDivisibleReturnsTheAllowedParts(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->splits->expects($this->once())->method('divisibleParts')->with('case-1')
			->willReturn(['documents', 'tasks']);

		$response = $this->controller()->divisible(caseId: 'case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['allowed' => ['documents', 'tasks']], $response->getData());
	}//end testDivisibleReturnsTheAllowedParts()

	/**
	 * An indeterminate answer is a 503 and logged as a warning, not as a refusal.
	 *
	 * @return void
	 */
	public function testDivisibleIndeterminateIsA503Warning(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->splits->method('divisibleParts')->willThrowException(
			RefusedException::indeterminate(rule: 'case-type-unreadable', sentence: '')
		);
		$this->logger->expects($this->once())->method('warning')
			->with(
				'CaseSplitController: case split parts could not be answered',
				['rule' => 'case-type-unreadable', 'status' => 503]
			);
		$this->logger->expects($this->never())->method('info');

		$response = $this->controller()->divisible(caseId: 'case-1');

		$this->assertSame(503, $response->getStatus());
		$this->assertSame('case-type-unreadable', $response->getData()['error']);
	}//end testDivisibleIndeterminateIsA503Warning()
}//end class
