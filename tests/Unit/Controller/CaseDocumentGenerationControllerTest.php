<?php

/**
 * CaseDocumentGenerationController Wire-Contract Tests
 *
 * The endpoint WRITES into a case dossier, so the property worth pinning is
 * the guard: an authenticated caller with no write access to the case must be
 * refused, and refused BEFORE the service runs. A guard that fires after the
 * generation would still answer 403 and still have filed the letter.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseDocumentGenerationController;
use OCA\Dossiq\Service\Actions\ActionResult;
use OCA\Dossiq\Service\CaseDocumentGenerationService;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire-contract tests for CaseDocumentGenerationController.
 *
 * @covers \OCA\Dossiq\Controller\CaseDocumentGenerationController
 *
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class CaseDocumentGenerationControllerTest extends TestCase {

	/**
	 * The request handed to the controller.
	 *
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * The generation service.
	 *
	 * @var CaseDocumentGenerationService|MockObject
	 */
	private CaseDocumentGenerationService $generationService;

	/**
	 * The upload handler supplying the case guard.
	 *
	 * @var DossierUploadHandler|MockObject
	 */
	private DossierUploadHandler $uploadHandler;

	/**
	 * The user session.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->generationService = $this->createMock(CaseDocumentGenerationService::class);
		$this->uploadHandler = $this->createMock(DossierUploadHandler::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return CaseDocumentGenerationController The controller.
	 */
	private function controller(): CaseDocumentGenerationController {
		return new CaseDocumentGenerationController(
			appName: 'dossiq',
			request: $this->request,
			generationService: $this->generationService,
			uploadHandler: $this->uploadHandler,
			userSession: $this->userSession,
		);
	}//end controller()

	/**
	 * Sign a user in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$this->userSession->method('getUser')->willReturn($this->createMock(IUser::class));
	}//end signIn()

	/**
	 * An anonymous caller is refused and nothing is generated.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsRefused(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->generationService->expects($this->never())->method('generate');

		$response = $this->controller()->generateDocument('case-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAnAnonymousCallerIsRefused()

	/**
	 * A caller without write access to the case is refused BEFORE generation.
	 *
	 * @return void
	 */
	public function testACallerWithoutCaseAccessIsRefusedBeforeAnythingIsWritten(): void {
		$this->signIn();
		$this->uploadHandler->method('hasCaseUploadAccess')->willReturn(false);
		$this->generationService->expects($this->never())->method('generate');

		$response = $this->controller()->generateDocument('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testACallerWithoutCaseAccessIsRefusedBeforeAnythingIsWritten()

	/**
	 * A request naming no template is a 400, not a guess.
	 *
	 * @return void
	 */
	public function testAMissingTemplateIdIsABadRequest(): void {
		$this->signIn();
		$this->uploadHandler->method('hasCaseUploadAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn('');
		$this->generationService->expects($this->never())->method('generate');

		$response = $this->controller()->generateDocument('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testAMissingTemplateIdIsABadRequest()

	/**
	 * A successful generation answers 201 with the new informatieobject.
	 *
	 * @return void
	 */
	public function testASuccessfulGenerationAnswersTheCreatedDocument(): void {
		$this->signIn();
		$this->uploadHandler->method('hasCaseUploadAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn('ontvangstbevestiging');
		$this->generationService->method('generate')->willReturn(
			new ActionResult(succeeded: true, data: ['informatieobject' => 'inf-1'])
		);

		$response = $this->controller()->generateDocument('case-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			['informatieobject' => 'inf-1', 'case' => 'case-1'],
			$response->getData()
		);
	}//end testASuccessfulGenerationAnswersTheCreatedDocument()

	/**
	 * A named failure reaches the caller as itself, not as a generic error.
	 *
	 * @return void
	 */
	public function testAFailedGenerationReportsTheHandlersOwnError(): void {
		$this->signIn();
		$this->uploadHandler->method('hasCaseUploadAccess')->willReturn(true);
		$this->request->method('getParam')->willReturn('ontvangstbevestiging');
		$this->generationService->method('generate')->willReturn(
			new ActionResult(succeeded: false, error: 'missing_template_field:case.geadresseerde.naam')
		);

		$response = $this->controller()->generateDocument('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			'missing_template_field:case.geadresseerde.naam',
			$response->getData()['error']
		);
	}//end testAFailedGenerationReportsTheHandlersOwnError()

	/**
	 * The route the frontend posts to resolves to this controller method.
	 *
	 * A controller method with no route entry is a 404 at runtime, and the
	 * dialog would report "the document could not be generated" for a reason
	 * no log explains.
	 *
	 * @return void
	 */
	public function testTheGenerateRouteIsRegistered(): void {
		$routes = (require __DIR__ . '/../../../appinfo/routes.php')['routes'];

		$match = array_values(
			array_filter(
				$routes,
				static fn (array $route): bool => ($route['name'] ?? '') === 'caseDocumentGeneration#generateDocument'
			)
		);

		$this->assertCount(1, $match, 'The generate endpoint must be routed exactly once');
		$this->assertSame('/api/cases/{caseId}/dossier/generate', $match[0]['url']);
		$this->assertSame('POST', $match[0]['verb']);
	}//end testTheGenerateRouteIsRegistered()
}//end class
