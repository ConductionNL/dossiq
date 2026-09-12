<?php

/**
 * InspectionChecklistController Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\InspectionChecklistController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\InspectionChecklistService;
use OCA\OpenRegister\Exception\CustomValidationException as OpenRegisterCustomValidationException;
use OCA\OpenRegister\Exception\ValidationException as OpenRegisterValidationException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for InspectionChecklistController.
 *
 * @covers \OCA\Dossiq\Controller\InspectionChecklistController
 */
class InspectionChecklistControllerTest extends TestCase {

	/**
	 * @var InspectionChecklistService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private InspectionChecklistService $inspectionChecklistService;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var CaseAccessGuard|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * @var InspectionChecklistController
	 */
	private InspectionChecklistController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->inspectionChecklistService = $this->createMock(InspectionChecklistService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new InspectionChecklistController(
			appName: 'dossiq',
			request: $this->request,
			checklistService: $this->inspectionChecklistService,
			userSession: $this->userSession,
			logger: $this->logger,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Test that index returns 200 with checklist list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testIndexReturns200(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->inspectionChecklistService
			->method('listChecklists')
			->willReturn([['id' => 'uuid-1', 'name' => 'Test Checklist']]);

		$response = $this->controller->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testIndexReturns200()

	/**
	 * Test that destroy returns 200 on successful deletion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testDestroyReturns200OnSuccess(): void {
		$this->inspectionChecklistService
			->method('deleteChecklist')
			->willReturn(true);

		$response = $this->controller->destroy(id: 'uuid-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testDestroyReturns200OnSuccess()

	/**
	 * Test that destroy returns 500 when deletion fails.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testDestroyReturns500OnFailure(): void {
		$this->inspectionChecklistService
			->method('deleteChecklist')
			->willReturn(false);

		$response = $this->controller->destroy(id: 'uuid-nonexistent');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
	}//end testDestroyReturns500OnFailure()

	/**
	 * Test that getResults returns 200 with a list.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testGetResultsReturns200(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('inspector1');
		$this->userSession->method('getUser')->willReturn($mockUser);

		// Until the per-case guard landed, "is anyone logged in" WAS this
		// endpoint's entire authorization model, and this case asserted exactly
		// that. It now has to state the relationship it always assumed.
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->inspectionChecklistService
			->method('getResultsForCase')
			->willReturn([]);

		$response = $this->controller->getResults(id: 'case-uuid');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testGetResultsReturns200()

	/**
	 * An authenticated caller who does not work on the case is refused, and no
	 * inspection result is read.
	 *
	 * @return void
	 */
	public function testGetResultsRefusesACallerWithoutCaseAccess(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('outsider');
		$this->userSession->method('getUser')->willReturn($mockUser);

		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(false);
		$this->inspectionChecklistService
			->expects($this->never())
			->method('getResultsForCase');

		$response = $this->controller->getResults(id: 'someone-elses-case');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testGetResultsRefusesACallerWithoutCaseAccess()

	/**
	 * THE BYPASS, PINNED. An authenticated account that is not the case's
	 * stored assignee is refused, and nothing is written.
	 *
	 * Before #799 this could not fail: the guard compared the caller's uid
	 * against `assignedInspector` FROM THE REQUEST BODY, so a caller who named
	 * themselves passed and a caller who named nobody passed too.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultRefusesACallerWhoIsNotTheStoredInspector(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('buitenstaander');
		$this->userSession->method('getUser')->willReturn($mockUser);

		// The caller names THEMSELVES as the assigned inspector. Under the old
		// guard this was the winning move.
		$this->request->method('getParams')->willReturn([
			'checklistId' => 'checklist-uuid',
			'assignedInspector' => 'buitenstaander',
		]);

		// Stored state disagrees: this account does not handle the case.
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(false);
		$this->inspectionChecklistService
			->expects($this->never())
			->method('submitResult');

		$response = $this->controller->submitResult(id: 'someone-elses-case');

		$this->assertSame(
			expected: Http::STATUS_FORBIDDEN,
			actual: $response->getStatus(),
			message: 'An account that is not the stored assignee must be refused, '
				. 'whatever it claims about itself in the body.'
		);
	}//end testSubmitResultRefusesACallerWhoIsNotTheStoredInspector()

	/**
	 * THE OTHER HALF OF THE BYPASS. Omitting `assignedInspector` entirely used
	 * to short-circuit the comparison — `$assignedUid !== ''` was false, so the
	 * refusal branch was skipped and the submission went through.
	 *
	 * The body is now read AFTER the decision, so an absent field cannot reach
	 * it at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultRefusesWhenTheInspectorFieldIsOmittedEntirely(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('buitenstaander');
		$this->userSession->method('getUser')->willReturn($mockUser);

		// No `assignedInspector` key at all: the exact bypass filed in #799.
		$this->request->method('getParams')->willReturn(['checklistId' => 'checklist-uuid']);

		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(false);
		$this->inspectionChecklistService
			->expects($this->never())
			->method('submitResult');

		$response = $this->controller->submitResult(id: 'someone-elses-case');

		$this->assertSame(
			expected: Http::STATUS_FORBIDDEN,
			actual: $response->getStatus(),
			message: 'Sending less must not buy more: omitting the inspector field '
				. 'must not skip the authorization check.'
		);
	}//end testSubmitResultRefusesWhenTheInspectorFieldIsOmittedEntirely()

	/**
	 * The stored assignee submits and is accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultAcceptsTheStoredInspector(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('inspecteur-a');
		$this->userSession->method('getUser')->willReturn($mockUser);

		$this->request->method('getParams')->willReturn([
			'checklistId' => 'checklist-uuid',
			'answers' => [],
		]);

		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->inspectionChecklistService
			->expects($this->once())
			->method('submitResult')
			->willReturn(['id' => 'result-uuid', 'completedBy' => 'inspecteur-a']);

		$response = $this->controller->submitResult(id: 'case-uuid');

		$this->assertSame(
			expected: Http::STATUS_CREATED,
			actual: $response->getStatus(),
			message: 'The case\'s stored assignee must be able to submit a result.'
		);
	}//end testSubmitResultAcceptsTheStoredInspector()

	/**
	 * An admin submits and is accepted. `CaseAccessGuard` performs the admin
	 * bypass itself, so from this controller's point of view an admin is
	 * simply a caller the guard admits.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultAcceptsAnAdmin(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($mockUser);

		$this->request->method('getParams')->willReturn(['checklistId' => 'checklist-uuid']);

		// The guard admits an admin on any case; see CaseAccessGuard's own
		// decision table.
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->inspectionChecklistService
			->expects($this->once())
			->method('submitResult')
			->willReturn(['id' => 'result-uuid']);

		$response = $this->controller->submitResult(id: 'any-case');

		$this->assertSame(
			expected: Http::STATUS_CREATED,
			actual: $response->getStatus(),
			message: 'An admin must still be able to submit a result.'
		);
	}//end testSubmitResultAcceptsAnAdmin()

	/**
	 * The authorization decision is taken BEFORE the payload is read, so an
	 * unresolvable case is refused with 403 rather than answered with the
	 * "checklistId is required" 400 that would otherwise leak that the guard
	 * ran second.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultRefusesBeforeValidatingThePayload(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('buitenstaander');
		$this->userSession->method('getUser')->willReturn($mockUser);

		// An empty body: no checklistId either.
		$this->request->method('getParams')->willReturn([]);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(false);

		$response = $this->controller->submitResult(id: 'someone-elses-case');

		$this->assertSame(
			expected: Http::STATUS_FORBIDDEN,
			actual: $response->getStatus(),
			message: 'Authorization must be decided before the body is inspected.'
		);
	}//end testSubmitResultRefusesBeforeValidatingThePayload()

	/**
	 * A payload OpenRegister refuses is the CALLER'S fault, so it must come
	 * back in the 4xx range naming the property that failed.
	 *
	 * Before this arm existed OpenRegister's ValidationException fell through
	 * to `catch (Throwable)` and the caller was told the server had broken.
	 * The message below is verbatim what a real submission produced, and every
	 * submission the e2e citation for this endpoint ever made produced it:
	 * that test asserted `not.toBe(403)`, which a 500 satisfies, so nothing was
	 * ever stored and nothing ever went red.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultAnswersFourHundredWhenOpenRegisterRefusesThePayload(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('inspecteur-a');
		$this->userSession->method('getUser')->willReturn($mockUser);

		$this->request->method('getParams')->willReturn([
			'checklistId' => 'e2e-checklist',
			'answers' => [],
		]);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->inspectionChecklistService
			->method('submitResult')
			->willThrowException(
				new OpenRegisterValidationException(
					message: "Property 'checklist' should match format 'uuid' but 'e2e-checklist' does not"
				)
			);

		$response = $this->controller->submitResult(id: 'case-uuid');

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $response->getStatus(),
			message: 'A payload the schema refuses is the caller\'s fault, not a server fault.'
		);
		$this->assertStringContainsString(
			needle: "Property 'checklist'",
			haystack: (string)($response->getData()['message'] ?? ''),
			message: 'The response must name the property that failed, the way OpenRegister words it.'
		);
	}//end testSubmitResultAnswersFourHundredWhenOpenRegisterRefusesThePayload()

	/**
	 * The sibling exception OpenRegister throws for its own rule checks lands
	 * in the same 4xx arm. Catching only one of the two would leave half the
	 * rejections still reading as a server fault.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultAnswersFourHundredForACustomValidationRefusal(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('inspecteur-a');
		$this->userSession->method('getUser')->willReturn($mockUser);

		$this->request->method('getParams')->willReturn(['checklistId' => 'checklist-uuid']);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->inspectionChecklistService
			->method('submitResult')
			->willThrowException(
				new OpenRegisterCustomValidationException(
					message: 'Referenced object for property case does not exist',
					errors: ['case' => 'not found']
				)
			);

		$response = $this->controller->submitResult(id: 'case-uuid');

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $response->getStatus(),
			message: 'A custom validation refusal is the caller\'s fault too.'
		);
	}//end testSubmitResultAnswersFourHundredForACustomValidationRefusal()

	/**
	 * WHICH WAY THIS FAILS IS THE POINT. The fix for the 500-on-bad-payload
	 * defect is a NARROW catch, and the tempting wrong fix -- widening the
	 * `Throwable` arm into a 4xx -- would report a genuine server fault as the
	 * caller's fault, which is worse than the defect it removes.
	 *
	 * This test fails if anyone ever does that. It is the mutation check for
	 * the two above: break the arm the other way and this reddens.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testSubmitResultStillAnswersFiveHundredForAGenuineServerFault(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('inspecteur-a');
		$this->userSession->method('getUser')->willReturn($mockUser);

		$this->request->method('getParams')->willReturn(['checklistId' => 'checklist-uuid']);
		$this->caseAccessGuard->method('hasCaseMutationAccess')->willReturn(true);
		$this->inspectionChecklistService
			->method('submitResult')
			->willThrowException(new \TypeError('Argument #1 must be of type string, null given'));

		$response = $this->controller->submitResult(id: 'case-uuid');

		$this->assertSame(
			expected: Http::STATUS_INTERNAL_SERVER_ERROR,
			actual: $response->getStatus(),
			message: 'A code fault is the server\'s fault and must stay a 500.'
		);
	}//end testSubmitResultStillAnswersFiveHundredForAGenuineServerFault()

	/**
	 * The admin CRUD half of this controller carried the identical arm: a
	 * checklist whose payload the schema refuses came back as a 500, so an
	 * author who mistyped a field was told the server had broken.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testCreateAnswersFourHundredWhenOpenRegisterRefusesThePayload(): void {
		$this->request->method('getParams')->willReturn(['name' => '']);
		$this->inspectionChecklistService
			->method('createChecklist')
			->willThrowException(
				new OpenRegisterValidationException(
					message: "Property 'caseTypeRef' should match format 'uuid' but 'vth' does not"
				)
			);

		$response = $this->controller->create();

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $response->getStatus(),
			message: 'A refused checklist payload is the author\'s fault, not a server fault.'
		);
		$this->assertStringContainsString(
			needle: "Property 'caseTypeRef'",
			haystack: (string)($response->getData()['message'] ?? ''),
			message: 'The response must name the property that failed.'
		);
	}//end testCreateAnswersFourHundredWhenOpenRegisterRefusesThePayload()

	/**
	 * The same on update.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testUpdateAnswersFourHundredWhenOpenRegisterRefusesThePayload(): void {
		$this->request->method('getParams')->willReturn(['name' => '']);
		$this->inspectionChecklistService
			->method('updateChecklist')
			->willThrowException(
				new OpenRegisterValidationException(
					message: "Property 'items' should be of type array but string given"
				)
			);

		$response = $this->controller->update(id: 'checklist-uuid');

		$this->assertSame(
			expected: Http::STATUS_BAD_REQUEST,
			actual: $response->getStatus(),
			message: 'A refused checklist update is the author\'s fault, not a server fault.'
		);
	}//end testUpdateAnswersFourHundredWhenOpenRegisterRefusesThePayload()

	/**
	 * And the other way on create: a genuine fault stays a 500.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function testCreateStillAnswersFiveHundredForAGenuineServerFault(): void {
		$this->request->method('getParams')->willReturn(['name' => 'VTH']);
		$this->inspectionChecklistService
			->method('createChecklist')
			->willThrowException(new \TypeError('Argument #2 must be of type array, null given'));

		$response = $this->controller->create();

		$this->assertSame(
			expected: Http::STATUS_INTERNAL_SERVER_ERROR,
			actual: $response->getStatus(),
			message: 'A code fault is the server\'s fault and must stay a 500.'
		);
	}//end testCreateStillAnswersFiveHundredForAGenuineServerFault()
}//end class
