<?php

/**
 * `POST /api/cases/{caseId}/award-proposal` proposes a bid for someone who may
 * change the case, and refuses anyone else before anything is read.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AwardProposalController;
use OCA\Dossiq\Kernel\Contracts\AwardProposalInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-TND-CASE-01, the route behind the case page's Propose award action.
 *
 * @covers \OCA\Dossiq\Controller\AwardProposalController
 */
class AwardProposalControllerTest extends TestCase {

	/** @var AwardProposalInterface&MockObject */
	private AwardProposalInterface $proposal;

	/** @var CaseAccessGuardInterface&MockObject */
	private CaseAccessGuardInterface $caseAccess;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/**
	 * Doubles for the service, the guard and a signed-in caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->proposal   = $this->createMock(originalClassName: AwardProposalInterface::class);
		$this->caseAccess = $this->createMock(originalClassName: CaseAccessGuardInterface::class);
		$this->session    = $this->createMock(originalClassName: IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(originalClassName: IUser::class));
	}//end setUp()

	/**
	 * Someone who may change the case proposes the bid the body names.
	 *
	 * @return void
	 */
	public function testAHandlerProposesTheBid(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->with('case-1', $this->anything())->willReturn(true);
		$this->proposal->expects($this->once())->method('propose')->with('case-1', 'bid-a')
			->willReturn(['proposed' => 'bid-a', 'released' => ['bid-b']]);

		$response = $this->controller(bid: ' bid-a ')->propose(caseId: ' case-1 ');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['proposed' => 'bid-a', 'released' => ['bid-b']], actual: $response->getData());
	}//end testAHandlerProposesTheBid()

	/**
	 * Someone who may not change the case is refused and nothing is proposed.
	 *
	 * @return void
	 */
	public function testANonHandlerIsRefusedBeforeAnythingIsRead(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(false);
		$this->proposal->expects($this->never())->method('propose');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller(bid: 'bid-a')->propose(caseId: 'case-1')->getStatus());
	}//end testANonHandlerIsRefusedBeforeAnythingIsRead()

	/**
	 * A refusal of the service answers 422 with its sentence.
	 *
	 * @return void
	 */
	public function testARefusedBidAnswersItsReason(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->proposal->method('propose')->willReturn(['refusal' => 'Only an admissible bid on this case can be proposed for the award.']);

		$response = $this->controller(bid: 'bid-c')->propose(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		$this->assertSame(expected: 'Only an admissible bid on this case can be proposed for the award.', actual: $response->getData()['message']);
	}//end testARefusedBidAnswersItsReason()

	/**
	 * A failing register answers 503 with a sentence.
	 *
	 * @return void
	 */
	public function testAFailingWriteAnswersUnavailable(): void {
		$this->caseAccess->method('hasCaseMutationAccess')->willReturn(true);
		$this->proposal->method('propose')->willThrowException(new RuntimeException('down'));

		$response = $this->controller(bid: 'bid-a')->propose(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertSame(expected: 'The award could not be proposed. Try again later.', actual: $response->getData()['message']);
	}//end testAFailingWriteAnswersUnavailable()

	/**
	 * The controller over the doubles, its request naming the bid.
	 *
	 * @param string $bid The bid in the body.
	 *
	 * @return AwardProposalController
	 */
	private function controller(string $bid): AwardProposalController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($bid): mixed {
				if ($key === 'bid') {
					return $bid;
				}

				return $default;
			}
		);

		return new AwardProposalController(
			appName: 'dossiq',
			request: $request,
			proposal: $this->proposal,
			userSession: $this->session,
			caseAccess: $this->caseAccess,
		);
	}//end controller()
}//end class
