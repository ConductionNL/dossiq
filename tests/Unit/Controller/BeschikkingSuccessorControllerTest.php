<?php

/**
 * BeschikkingSuccessorController unit tests.
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
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BeschikkingSuccessorController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\BeschikkingRepository;
use OCA\Dossiq\Service\Beschikking\BeschikkingSuccession;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BeschikkingSuccessorController.
 *
 * @covers \OCA\Dossiq\Controller\BeschikkingSuccessorController
 *
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class BeschikkingSuccessorControllerTest extends TestCase {

	/**
	 * The succession double.
	 *
	 * @var BeschikkingSuccession&MockObject
	 */
	private BeschikkingSuccession $succession;

	/**
	 * The repository double.
	 *
	 * @var BeschikkingRepository&MockObject
	 */
	private BeschikkingRepository $repository;

	/**
	 * The access guard double.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

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
	 * Set up doubles: a signed-in user, and a beschikking on case-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->succession = $this->createMock(BeschikkingSuccession::class);
		$this->repository = $this->createMock(BeschikkingRepository::class);
		$this->repository->method('find')->willReturnCallback(
			static fn (string $decisionId): ?array => ($decisionId === 'besch-1' ? ['id' => 'besch-1', 'caseId' => 'case-1'] : null)
		);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($this->createMock(IUser::class));
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return BeschikkingSuccessorController
	 */
	private function controller(): BeschikkingSuccessorController {
		return new BeschikkingSuccessorController(
			'dossiq',
			$this->request,
			$this->succession,
			$this->repository,
			$this->guard,
			$this->session,
			$this->createMock(LoggerInterface::class),
		);
	}//end controller()

	/**
	 * A handler who may change the case gets the successor, with exactly the fields they sent.
	 *
	 * @return void
	 */
	public function testAHandlerWithMutationAccessIssuesTheSuccessor(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturn(
			['id' => 'besch-1', 'decisionType' => 'amendment', 'rationale' => 'herzien', 'currentStatus' => 'signed']
		);
		$this->succession->expects(self::once())->method('issue')
			->with('besch-1', 'amendment', ['rationale' => 'herzien'], null)
			->willReturn(['id' => 'besch-2', 'reference' => 'B-2026-000124', 'supersedes' => 'besch-1']);

		$response = $this->controller()->create('besch-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertSame('B-2026-000124', $response->getData()['reference']);
	}//end testAHandlerWithMutationAccessIssuesTheSuccessor()

	/**
	 * Without mutation access on the case, nothing is issued.
	 *
	 * @return void
	 */
	public function testWithoutMutationAccessTheAnswerIs403(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->succession->expects(self::never())->method('issue');

		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller()->create('besch-1')->getStatus());
	}//end testWithoutMutationAccessTheAnswerIs403()

	/**
	 * A beschikking that does not exist is a 404, before any access question.
	 *
	 * @return void
	 */
	public function testAnUnknownBeschikkingIs404(): void {
		$this->succession->expects(self::never())->method('issue');

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller()->create('nope')->getStatus());
	}//end testAnUnknownBeschikkingIs404()

	/**
	 * Nobody signed in is a 401.
	 *
	 * @return void
	 */
	public function testAnonymousIs401(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->session = $session;

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->create('besch-1')->getStatus());
	}//end testAnonymousIs401()

	/**
	 * A refusal answers with its own status and sentence.
	 *
	 * @return void
	 */
	public function testARefusalCarriesItsStatusAndSentence(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturn(['decisionType' => 'amendment']);
		$this->succession->method('issue')->willThrowException(
			new RefusedException(rule: 'already-superseded', sentence: 'This beschikking has already been replaced by B-2026-000124. Correct that beschikking instead.')
		);

		$response = $this->controller()->create('besch-1');

		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		self::assertSame('already-superseded', $response->getData()['error']);
		self::assertStringContainsString('B-2026-000124', $response->getData()['message']);
	}//end testARefusalCarriesItsStatusAndSentence()

	/**
	 * An unexpected failure is a generic 500 that leaks nothing.
	 *
	 * @return void
	 */
	public function testAnUnexpectedFailureIsAGeneric500(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParams')->willReturn(['decisionType' => 'amendment', 'templateId' => 'tpl-2']);
		$this->succession->method('issue')->willThrowException(new \RuntimeException('secret detail'));

		$response = $this->controller()->create('besch-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertStringNotContainsString('secret', json_encode($response->getData()));
	}//end testAnUnexpectedFailureIsAGeneric500()
}//end class
