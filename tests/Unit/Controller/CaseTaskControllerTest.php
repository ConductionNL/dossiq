<?php

/**
 * The task gestures a handler makes from the case page, behind their guards.
 *
 * Every method here is `#[NoAdminRequired]`, so without the per-case guard
 * these would be five ways for any signed-in user to act on any case by its
 * uuid, which is the IDOR shape ADR-005 rule 3 names. The refusals are the
 * half a browser cannot show: Playwright signs in as admin, who passes every
 * guard, so the only place the refused branch runs is here.
 *
 * 🔑 THE CASE IS READ FROM THE TASK. A caseId in the url beside a taskId would
 * be two claims about the same relationship, and a caller could pass a case
 * they may see to reach a task on one they may not. So the task routes take
 * only a task uuid, and the test below pins that the guard is asked about the
 * case the ENGINE says the task is on.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseTaskController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Task\CaseTaskActions;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\CaseTaskController
 */
class CaseTaskControllerTest extends TestCase {

	/**
	 * The completion seam.
	 *
	 * @var CaseTaskActions&MockObject
	 */
	private CaseTaskActions $completion;

	/**
	 * The attachment hold.
	 *
	 * @var TaskAttachmentService&MockObject
	 */
	private TaskAttachmentService $attachments;

	/**
	 * The per-case guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The parameters the mocked request answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Wire a controller with a signed-in handler and a task on a case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->completion = $this->createMock(originalClassName: CaseTaskActions::class);
		$this->attachments = $this->createMock(originalClassName: TaskAttachmentService::class);
		$this->guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->request = $this->createMock(originalClassName: IRequest::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);

		$this->params = ['data' => ['verslag' => 'Gehoord op 3 maart'], 'file' => 'file-7'];

		// A NON-static closure reading `$this->params`, so a test that changes
		// one afterwards is answered with the new value.
		$this->request->method('getParam')->willReturnCallback(
			function (string $key, mixed $default = null): mixed {
				return ($this->params[$key] ?? $default);
			}
		);
	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @return CaseTaskController The controller.
	 */
	private function controller(): CaseTaskController {
		return new CaseTaskController(
			appName: 'dossiq',
			request: $this->request,
			tasks: $this->completion,
			attachments: $this->attachments,
			caseAccess: $this->guard,
			userSession: $this->userSession,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end controller()

	/**
	 * The engine is asked what it can do, and the answer is passed on whole.
	 *
	 * @return void
	 */
	public function testCapabilitiesAnswerTheEngine(): void {
		$this->completion->method('engineAnswersClaim')->willReturn(true);

		$response = $this->controller()->capabilities();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['claim' => true], actual: $response->getData());
	}//end testCapabilitiesAnswerTheEngine()

	/**
	 * A task nobody can find is a 404 carrying the envelope, not a 500.
	 *
	 * @return void
	 */
	public function testAMissingTaskIsRefusedByName(): void {
		$this->completion->method('find')->willReturn(null);

		$response = $this->controller()->complete(taskId: 'task-9');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: 'task_not_found', actual: $response->getData()['error']);
	}//end testAMissingTaskIsRefusedByName()

	/**
	 * The guard is asked about the case the ENGINE says the task is on.
	 *
	 * @return void
	 */
	public function testTheGuardIsAskedAboutTheTasksOwnCase(): void {
		$this->completion->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'case-7']);
		$this->guard->expects($this->once())
			->method('hasCaseMutationAccess')
			->with('case-7')
			->willReturn(false);
		$this->completion->expects($this->never())->method('complete');

		$response = $this->controller()->complete(taskId: 'task-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testTheGuardIsAskedAboutTheTasksOwnCase()

	/**
	 * A required field left empty is refused with the FIELD named.
	 *
	 * @return void
	 */
	public function testABlankRequiredFieldNamesTheField(): void {
		$this->completion->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'case-7']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->completion->method('complete')->willThrowException(new RuntimeException('required_field:verslag'));

		$response = $this->controller()->complete(taskId: 'task-1');
		$body = $response->getData();

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: 'required_field', actual: $body['error']);
		$this->assertSame(expected: 'verslag', actual: $body['field']);
		// ADR-050: the envelope carries a sentence for the person as well as a
		// code for the client, and the sentence names the field too.
		$this->assertStringContainsString(needle: 'verslag', haystack: $body['message']);
	}//end testABlankRequiredFieldNamesTheField()

	/**
	 * An effect nothing answers to refuses the completion, naming the handler.
	 *
	 * @return void
	 */
	public function testAnUnresolvableEffectRefusesTheCompletion(): void {
		$this->completion->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'case-7']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->completion->method('complete')->willThrowException(
			new RuntimeException('unresolvable_effect:teleport')
		);

		$response = $this->controller()->complete(taskId: 'task-1');
		$body = $response->getData();

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'teleport', actual: $body['effect']);
		// The task STAYS OPEN, and the message says so: a completion that ran
		// no effect and reported success is the failure this refuses.
		$this->assertStringContainsString(needle: 'stays open', haystack: $body['message']);
	}//end testAnUnresolvableEffectRefusesTheCompletion()

	/**
	 * A claim the engine refuses keeps the engine's own reason.
	 *
	 * @return void
	 */
	public function testARefusedClaimKeepsTheEnginesReason(): void {
		$this->completion->method('find')->willReturn(['id' => 'task-1', 'objectUuid' => 'case-7']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->completion->method('claim')->willThrowException(
			new RuntimeException('The task engine answers no claim act.')
		);

		$response = $this->controller()->claim(taskId: 'task-1');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertStringContainsString(
			needle: 'claim act',
			haystack: $response->getData()['message']
		);
	}//end testARefusedClaimKeepsTheEnginesReason()

	/**
	 * Holding a file against a task is a write, so it takes the write guard.
	 *
	 * @return void
	 */
	public function testAttachIsRefusedWithoutMutationAccess(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->attachments->expects($this->never())->method('bind');

		$response = $this->controller()->attach(caseId: 'case-1', taskId: 'task-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testAttachIsRefusedWithoutMutationAccess()

	/**
	 * A held file comes back under `held`, so the surface can render it.
	 *
	 * @return void
	 */
	public function testAttachAnswersWhatIsHeld(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->attachments->method('bind')->willReturn([['task' => 'task-1', 'file' => 'file-7']]);

		$response = $this->controller()->attach(caseId: 'case-1', taskId: 'task-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'file-7', actual: $response->getData()['held'][0]['file']);
	}//end testAttachAnswersWhatIsHeld()

	/**
	 * Taking a file off an open task takes the write guard too.
	 *
	 * @return void
	 */
	public function testDetachTakesTheWriteGuard(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->attachments->method('release')->willReturn([]);

		$response = $this->controller()->detach(caseId: 'case-1', taskId: 'task-1', fileId: 'file-7');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: [], actual: $response->getData()['held']);
	}//end testDetachTakesTheWriteGuard()

	/**
	 * A caller with no session is refused before anything is read.
	 *
	 * @return void
	 */
	public function testNoSessionIsRefused(): void {
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->userSession = $userSession;
		$this->guard->expects($this->never())->method('hasCaseMutationAccess');

		$response = $this->controller()->attach(caseId: 'case-1', taskId: 'task-1');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testNoSessionIsRefused()
}//end class
