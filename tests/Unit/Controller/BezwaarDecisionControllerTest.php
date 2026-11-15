<?php

/**
 * BezwaarDecisionController tests.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BezwaarDecisionController;
use OCA\Dossiq\Service\Bezwaar\DecisionService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\BezwaarDecisionController
 */
class BezwaarDecisionControllerTest extends TestCase {

	/** @var DecisionService&MockObject */
	private DecisionService $decisions;

	/** @var CaseAccessGuard&MockObject */
	private CaseAccessGuard $guard;

	/** @var IRequest&MockObject */
	private IRequest $request;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	private IUser $user;

	protected function setUp(): void {
		$this->decisions = $this->createMock(DecisionService::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->request = $this->createMock(IRequest::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->user = $this->createMock(IUser::class);
	}

	private function controller(): BezwaarDecisionController {
		return new BezwaarDecisionController('dossiq', $this->request, $this->decisions, $this->guard, $this->session);
	}

	public function testDraftRefusesAnonymousCaller(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->decisions->method('caseIdForObjection')->willReturn('case-1');
		$this->decisions->expects($this->never())->method('draft');

		$response = $this->controller()->draft('obj-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());
	}

	public function testDraftRefusesWhenTheObjectionHasNoCase(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForObjection')->with('obj-1')->willReturn(null);
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');
		$this->decisions->expects($this->never())->method('draft');

		$response = $this->controller()->draft('obj-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'Not authorized'], $response->getData());
	}

	public function testDraftRefusesSomeoneWhoDoesNotHandleTheCase(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForObjection')->willReturn('case-1');
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('case-1', $this->user)->willReturn(false);
		$this->decisions->expects($this->never())->method('draft');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->draft('obj-1')->getStatus());
	}

	public function testDraftRefusesANonArrayPayload(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForObjection')->willReturn('case-1');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->with('decision')->willReturn('gegrond');
		$this->decisions->expects($this->never())->method('draft');

		$response = $this->controller()->draft('obj-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Name the disposition, the reasoning and the legal basis.'], $response->getData());
	}

	public function testDraftReturnsTheDraftedDecision(): void {
		$payload = ['disposition' => 'gegrond', 'reasoning' => 'r', 'legalBasis' => 'Awb 7:11'];
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForObjection')->willReturn('case-1');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->with('decision')->willReturn($payload);
		$this->decisions->expects($this->once())->method('draft')->with('obj-1', $payload)->willReturn(['id' => 'dec-1']);

		$response = $this->controller()->draft('obj-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'dec-1'], $response->getData());
	}

	public function testDraftReturnsTheValidatorsOwnSentence(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForObjection')->willReturn('case-1');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn(['disposition' => 'x']);
		$this->decisions->method('draft')->willThrowException(new RuntimeException('Unknown disposition x.'));

		$response = $this->controller()->draft('obj-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Unknown disposition x.'], $response->getData());
	}

	public function testPublishChecksAccessOnTheDecisionsCase(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForDecision')->with('dec-1')->willReturn('case-7');
		$this->guard->expects($this->once())->method('hasCaseMutationAccess')->with('case-7', $this->user)->willReturn(false);
		$this->decisions->expects($this->never())->method('publish');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->publish('dec-1')->getStatus());
	}

	public function testPublishRefusesAnUnknownDecision(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForDecision')->willReturn(null);
		$this->decisions->expects($this->never())->method('publish');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->publish('dec-9')->getStatus());
	}

	public function testPublishReturnsThePublishedDecision(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForDecision')->willReturn('case-7');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->decisions->expects($this->once())->method('publish')->with('dec-1')->willReturn(['status' => 'published']);

		$response = $this->controller()->publish('dec-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['status' => 'published'], $response->getData());
	}

	public function testPublishTurnsARefusalIntoBadRequest(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->decisions->method('caseIdForDecision')->willReturn('case-7');
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->decisions->method('publish')->willThrowException(new RuntimeException('Already published.'));

		$response = $this->controller()->publish('dec-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Already published.'], $response->getData());
	}
}
