<?php

/**
 * The wire contract of the nine acts on a case.
 *
 * The services are driven one refusal at a time in their own files. What is
 * only true at this layer is the SHAPE the network sees: which status a
 * refusal answers with, that the body is `{message, error}` per ADR-050, that
 * the per-case guard runs BEFORE the act so a refused caller never learns
 * whether the case exists, and that a read of what a handler may do lists the
 * acts they may NOT perform rather than omitting them.
 *
 * 🔑 THE GUARD IS ASSERTED AS AN ORDER, not as a fact. A controller that ran
 * the act and then checked the caller would answer the same 403 on the happy
 * path and would already have written to the case. So the refused case asserts
 * that no service was touched at all.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseActsController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Lifecycle\CaseActs;
use OCA\Dossiq\Service\Lifecycle\CaseEndingActs;
use OCA\Dossiq\Service\Lifecycle\CaseHoldActs;
use OCA\Dossiq\Service\Lifecycle\CaseIncompleteness;
use OCA\Dossiq\Service\Lifecycle\DraftCaseActs;
use OCA\Dossiq\Service\Lifecycle\LifecycleActorGate;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Nine acts, their statuses and their refusal bodies.
 *
 * @covers \OCA\Dossiq\Controller\CaseActsController
 */
class CaseActsControllerContractTest extends TestCase {

	/**
	 * The case the store hands out.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE = [
		'id' => 'case-1',
		'caseType' => 'ct-1',
		'status' => 'st-2',
		'heldUntil' => '',
		'isDraft' => false,
	];

	/**
	 * The request, whose params each act reads.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * Finish, abort and archive.
	 *
	 * @var CaseEndingActs&MockObject
	 */
	private CaseEndingActs $endings;

	/**
	 * Hold and release.
	 *
	 * @var CaseHoldActs&MockObject
	 */
	private CaseHoldActs $holds;

	/**
	 * Begin and promote.
	 *
	 * @var DraftCaseActs&MockObject
	 */
	private DraftCaseActs $drafts;

	/**
	 * Record what is missing.
	 *
	 * @var CaseIncompleteness&MockObject
	 */
	private CaseIncompleteness $incompleteness;

	/**
	 * The per-act role gate.
	 *
	 * @var LifecycleActorGate&MockObject
	 */
	private LifecycleActorGate $gate;

	/**
	 * The per-case guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * The store the facade reads the case from.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The process-owned status rule, which the overview reports.
	 *
	 * @var ProcessOwnedStatusRule&MockObject
	 */
	private ProcessOwnedStatusRule $processStatus;

	/**
	 * The controller under test.
	 *
	 * @var CaseActsController
	 */
	private CaseActsController $controller;

	/**
	 * A REAL facade over doubled acts.
	 *
	 * The seam between the controller and the acts is exactly what these
	 * tests are about, so doubling the facade would let a wrong delegation
	 * pass: `releaseHold()` reaching `hold()` would answer the same 200.
	 *
	 * @return CaseActs The facade.
	 */
	private function facade(): CaseActs {
		return new CaseActs(
			endings: $this->endings,
			holds: $this->holds,
			drafts: $this->drafts,
			incompleteness: $this->incompleteness,
			gate: $this->gate,
			processStatus: $this->processStatus,
			store: $this->store,
		);
	}//end facade()

	/**
	 * A signed-in handler who works on the case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ([
				'reason' => 'Omdat het moet',
				'until' => '2099-03-01',
				'resultTypeId' => 'rt-1',
				'fields' => ['applicantAddress'],
			][$key] ?? $default)
		);

		$this->endings = $this->createMock(originalClassName: CaseEndingActs::class);
		$this->holds = $this->createMock(originalClassName: CaseHoldActs::class);
		$this->drafts = $this->createMock(originalClassName: DraftCaseActs::class);
		$this->incompleteness = $this->createMock(originalClassName: CaseIncompleteness::class);
		$this->incompleteness->method('missingOn')->willReturn([]);

		$this->gate = $this->createMock(originalClassName: LifecycleActorGate::class);
		$this->gate->method('may')->willReturnCallback(
			static fn (string $act): bool => ($act !== 'archive')
		);
		$this->gate->method('roleFor')->willReturnCallback(
			static function (string $act): string {
				if ($act === 'archive') {
					return 'archivaris';
				}

				return '';
			}
		);
		$this->gate->method('refusalSentence')->willReturn('This act needs the archivaris group.');

		$this->store = $this->createMock(originalClassName: CaseStatusStore::class);
		$this->store->method('loadCase')->willReturn(self::CASE);

		$this->processStatus = $this->createMock(originalClassName: ProcessOwnedStatusRule::class);
		$this->processStatus->method('allowsHandSet')->willReturn(true);

		$this->caseAccessGuard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new CaseActsController(
			appName: 'dossiq',
			request: $this->request,
			acts: $this->facade(),
			caseAccessGuard: $this->caseAccessGuard,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The read the one menu is drawn from lists every act, refused ones included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testTheActsReadListsRefusedActsWithTheirReason(): void {
		$this->holds->method('isHeld')->willReturn(false);
		$this->drafts->method('isDraft')->willReturn(false);
		$this->endings->method('endingOf')->willReturn([]);

		$response = $this->controller->acts(caseId: 'case-1');
		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());

		$body = $response->getData();
		$acts = array_column(array: $body['acts'], column_key: null, index_key: 'act');

		$this->assertArrayHasKey(key: 'archive', array: $acts, message: 'a refused act is listed, not omitted');
		$this->assertFalse(condition: $acts['archive']['allowed']);
		$this->assertSame(expected: 'archivaris', actual: $acts['archive']['role']);
		$this->assertStringContainsString(needle: 'archivaris', haystack: $acts['archive']['reason']);

		$this->assertTrue(condition: $acts['finish']['allowed']);
		$this->assertSame(expected: '', actual: $acts['finish']['reason'], message: 'a permitted act carries no reason');
	}//end testTheActsReadListsRefusedActsWithTheirReason()

	/**
	 * Marking a case a draft answers with what the draft now is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testTheDraftActAnswersTheDraftState(): void {
		$this->drafts->expects($this->once())
			->method('begin')
			->with(caseId: 'case-1')
			->willReturn(['caseId' => 'case-1', 'draft' => true, 'draftCreatedAt' => '2026-09-15T08:00:00+02:00']);

		$response = $this->controller->draft(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertTrue(condition: $response->getData()['draft']);
	}//end testTheDraftActAnswersTheDraftState()

	/**
	 * Taking a case off hold reaches the hold service with its reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testReleaseHoldReachesTheService(): void {
		$this->holds->expects($this->once())
			->method('release')
			->with(caseId: 'case-1', reason: 'Omdat het moet')
			->willReturn(['caseId' => 'case-1', 'held' => false]);

		$response = $this->controller->releaseHold(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertFalse(condition: $response->getData()['held']);
	}//end testReleaseHoldReachesTheService()

	/**
	 * Recording incompleteness passes the named fields through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testIncompletenessPassesTheNamedFields(): void {
		$this->incompleteness->expects($this->once())
			->method('record')
			->with(caseId: 'case-1', missing: ['applicantAddress'])
			->willReturn(['caseId' => 'case-1', 'incomplete' => true, 'missingFields' => ['applicantAddress']]);

		$response = $this->controller->incompleteness(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['applicantAddress'], actual: $response->getData()['missingFields']);
	}//end testIncompletenessPassesTheNamedFields()

	/**
	 * A refusal answers its own status, with the rule slug in `error`.
	 *
	 * ADR-050: the envelope is `{message, error}`, the slug is for code and
	 * the sentence is for a person. A refusal that answered 500, or that put
	 * the slug where the sentence goes, would reach the handler as "something
	 * went wrong" and end the conversation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testARefusalCarriesItsStatusAndItsRule(): void {
		$this->endings->method('archive')->willThrowException(
			new RefusedException(
				rule: 'archive-role-required',
				sentence: 'This act needs the archivaris group.',
				status: RefusedException::STATUS_FORBIDDEN,
			)
		);

		$response = $this->controller->archive(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: 'archive-role-required', actual: $response->getData()['error']);
		$this->assertStringContainsString(
			needle: 'archivaris',
			haystack: $response->getData()['message'],
			message: 'the sentence, not the slug, is what a person reads'
		);
	}//end testARefusalCarriesItsStatusAndItsRule()

	/**
	 * A caller who does not work on the case reaches no service at all.
	 *
	 * The ORDER is the assertion. A controller that ran the act and then
	 * checked the caller would answer the same 403 and would already have
	 * written to the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testARefusedCallerNeverReachesTheAct(): void {
		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn(false);

		$this->endings = $this->createMock(originalClassName: CaseEndingActs::class);
		$this->endings->expects($this->never())->method('finish');

		$this->store = $this->createMock(originalClassName: CaseStatusStore::class);
		$this->store->expects($this->never())->method('loadCase');

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$controller = new CaseActsController(
			appName: 'dossiq',
			request: $this->request,
			acts: $this->facade(),
			caseAccessGuard: $guard,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$response = $controller->finish(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: 'not-authorized', actual: $response->getData()['error']);
	}//end testARefusedCallerNeverReachesTheAct()

	/**
	 * With nobody signed in, every act answers 401 and touches nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testNoSessionAnswers401(): void {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$this->holds = $this->createMock(originalClassName: CaseHoldActs::class);
		$this->holds->expects($this->never())->method('hold');

		$controller = new CaseActsController(
			appName: 'dossiq',
			request: $this->request,
			acts: $this->facade(),
			caseAccessGuard: $this->caseAccessGuard,
			userSession: $session,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$response = $controller->hold(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: 'not-authenticated', actual: $response->getData()['error']);
	}//end testNoSessionAnswers401()

	/**
	 * An unexpected failure is a 500 with a static sentence, never the message.
	 *
	 * A controller in this app never returns an exception message to a client:
	 * it is not translated, it is not written for a person, and it can name
	 * internals.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnexpectedFailureNeverLeaksItsMessage(): void {
		$this->drafts->method('promote')->willThrowException(
			new \RuntimeException('PDOException: could not connect to db-01')
		);

		$response = $this->controller->promote(caseId: 'case-1');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertStringNotContainsString(needle: 'db-01', haystack: json_encode($response->getData()));
		$this->assertSame(expected: 'act-failed', actual: $response->getData()['error']);
	}//end testAnUnexpectedFailureNeverLeaksItsMessage()
}//end class
