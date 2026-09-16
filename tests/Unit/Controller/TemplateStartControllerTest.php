<?php

/**
 * TemplateStartController wire-contract tests.
 *
 * Contract coverage for the three handler-facing endpoints (gate-25):
 * GET /api/case-templates, POST /api/case-templates/{templateId}/start and
 * GET /api/content-templates/{kind}. These carry `#[NoAdminRequired]`, which
 * means any authenticated user, so the defect each test pins is the per-object
 * guard being absent: a handler starting from a template they may not read, and
 * a template store that is unreachable reading as empty.
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
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\TemplateStartController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Starter\CaseTemplateService;
use OCA\Dossiq\Service\Starter\ContentTemplateService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wire-contract tests for TemplateStartController.
 *
 * @covers \OCA\Dossiq\Controller\TemplateStartController
 */
class TemplateStartControllerTest extends TestCase {

	/**
	 * The case templates.
	 *
	 * @var CaseTemplateService|MockObject
	 */
	private CaseTemplateService $cases;

	/**
	 * The template library.
	 *
	 * @var ContentTemplateService|MockObject
	 */
	private ContentTemplateService $content;

	/**
	 * Who may read which case.
	 *
	 * @var CaseAccessGuard|MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $session;

	/**
	 * The controller under test.
	 *
	 * @var TemplateStartController
	 */
	private TemplateStartController $controller;

	/**
	 * Build the controller, signed in as one handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cases = $this->getMockBuilder(CaseTemplateService::class)
			->disableOriginalConstructor()
			->onlyMethods(['templates', 'startFrom'])
			->getMock();

		$this->content = $this->getMockBuilder(ContentTemplateService::class)
			->disableOriginalConstructor()
			->onlyMethods(['offered'])
			->getMock();

		$this->guard = $this->getMockBuilder(CaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCaseReadAccess'])
			->getMock();

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ahmed');
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $default
		);

		$this->controller = new TemplateStartController(
			appName: 'dossiq',
			request: $request,
			cases: $this->cases,
			content: $this->content,
			guard: $this->guard,
			session: $this->session,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * The case templates come back as a counted list.
	 *
	 * @return void
	 */
	public function testCaseTemplatesAnswersTheTemplatesAsACountedList(): void {
		$this->cases->method('templates')->willReturn(
			[['id' => 'tpl-1', 'templateName' => 'Standaardzaak sloopmelding', 'caseType' => 'ct-vth']]
		);

		$data = $this->controller->caseTemplates()->getData();

		self::assertSame(expected: 1, actual: $data['total']);
		self::assertSame(expected: 'Standaardzaak sloopmelding', actual: $data['items'][0]['templateName']);
	}//end testCaseTemplatesAnswersTheTemplatesAsACountedList()

	/**
	 * A register that cannot be reached is a 503, never an empty list.
	 *
	 * An empty list would tell a handler there are no templates on an instance
	 * that has a hundred.
	 *
	 * @return void
	 */
	public function testAnUnreachableRegisterIsNotAnEmptyList(): void {
		$this->cases->method('templates')->willReturn(null);

		$response = $this->controller->caseTemplates();

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
	}//end testAnUnreachableRegisterIsNotAnEmptyList()

	/**
	 * A handler who may not read the template may not start from it.
	 *
	 * 🔑 `#[NoAdminRequired]` IS ANY AUTHENTICATED USER. Without this guard
	 * every handler in the instance could start a case from the bezwaar
	 * templates, presets and all, whatever their access to bezwaar.
	 *
	 * @return void
	 */
	public function testStartingFromATemplateTheHandlerMayNotReadIsRefused(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(false);
		$this->cases->expects($this->never())->method('startFrom');

		$response = $this->controller->startFromTemplate(templateId: 'tpl-1');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testStartingFromATemplateTheHandlerMayNotReadIsRefused()

	/**
	 * A handler who may read the template starts a case, and it answers 201.
	 *
	 * The control for the test above: without it a guard that refused everybody
	 * would pass and nobody could start from a template at all.
	 *
	 * @return void
	 */
	public function testAPermittedHandlerStartsACaseFromTheTemplate(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->cases->method('startFrom')->willReturn(
			['ok' => true, 'reason' => '', 'case' => ['id' => 'case-9', 'startedFromTemplate' => 'tpl-1']]
		);

		$response = $this->controller->startFromTemplate(templateId: 'tpl-1');

		self::assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		self::assertSame(expected: 'tpl-1', actual: $response->getData()['case']['startedFromTemplate']);
	}//end testAPermittedHandlerStartsACaseFromTheTemplate()

	/**
	 * Starting from a row that is not a template is a conflict, not a 500.
	 *
	 * @return void
	 */
	public function testStartingFromSomethingThatIsNotATemplateIsAConflict(): void {
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->cases->method('startFrom')->willReturn(
			['ok' => false, 'reason' => 'not_a_template', 'case' => []]
		);

		$response = $this->controller->startFromTemplate(templateId: 'case-1');

		self::assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		self::assertSame(expected: 'not_a_template', actual: $response->getData()['reason']);
	}//end testStartingFromSomethingThatIsNotATemplateIsAConflict()

	/**
	 * The templates of one kind come back as a counted list.
	 *
	 * @return void
	 */
	public function testContentTemplatesAnswersTheKindAsked(): void {
		$this->content->expects($this->once())
			->method('offered')
			->with('task', '', '')
			->willReturn([['id' => 'tpl-task', 'kind' => 'task', 'name' => 'Vraag advies', 'body' => '', 'presets' => []]]);

		$data = $this->controller->contentTemplates(kind: 'task')->getData();

		self::assertSame(expected: 1, actual: $data['total']);
		self::assertSame(expected: 'task', actual: $data['items'][0]['kind']);
	}//end testContentTemplatesAnswersTheKindAsked()

	/**
	 * A caller with no session is refused before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnauthenticatedCallerIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$controller = new TemplateStartController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			cases: $this->cases,
			content: $this->content,
			guard: $this->guard,
			session: $session,
			logger: new NullLogger(),
		);

		self::assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $controller->caseTemplates()->getStatus()
		);
		self::assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $controller->startFromTemplate(templateId: 'tpl-1')->getStatus()
		);
		self::assertSame(
			expected: Http::STATUS_UNAUTHORIZED,
			actual: $controller->contentTemplates(kind: 'task')->getStatus()
		);
	}//end testAnUnauthenticatedCallerIsRefused()
}//end class
