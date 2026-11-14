<?php

/**
 * The outstanding route needs case read access and answers count and titled documents.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DocumentAssessmentController;
use OCA\Dossiq\Service\Assessment\AssessedDocuments;
use OCA\Dossiq\Service\Assessment\OutstandingDocuments;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-DAS-001 and REQ-DAS-004 through their routes.
 *
 * @covers \OCA\Dossiq\Controller\DocumentAssessmentController
 */
class DocumentAssessmentControllerTest extends TestCase {

	/**
	 * The controller for a caller.
	 *
	 * @param bool                      $signedIn    Whether there is a user.
	 * @param bool                      $mayRead     Whether the guard grants read access.
	 * @param OutstandingDocuments|null $outstanding The service, a refusing mock when left out.
	 * @param AssessedDocuments|null    $assessed    The full list, a refusing mock when left out.
	 *
	 * @return DocumentAssessmentController
	 */
	private function controller(
		bool $signedIn,
		bool $mayRead,
		?OutstandingDocuments $outstanding = null,
		?AssessedDocuments $assessed = null,
	): DocumentAssessmentController {
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn($mayRead);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		if ($outstanding === null) {
			$outstanding = $this->createMock(OutstandingDocuments::class);
			$outstanding->expects($this->never())->method('forCase');
		}

		if ($assessed === null) {
			$assessed = $this->createMock(AssessedDocuments::class);
			$assessed->expects($this->never())->method('forCase');
		}

		return new DocumentAssessmentController(
			request: $this->createMock(IRequest::class),
			outstanding: $outstanding,
			assessed: $assessed,
			guard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * Nobody signed in answers 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testNoUserAnswers401(): void {
		$this->assertSame(401, $this->controller(signedIn: false, mayRead: true)->outstanding(id: 'case-1')->getStatus());
	}//end testNoUserAnswers401()

	/**
	 * A user without case read access gets 403 with a sentence, and the list is never read.
	 *
	 * @return void
	 */
	public function testAUserWithoutCaseAccessIsRefused(): void {
		$response = $this->controller(signedIn: true, mayRead: false)->outstanding(id: 'case-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);
		$this->assertNotSame('', $response->getData()['message']);
		$this->assertArrayNotHasKey('documents', $response->getData());
	}//end testAUserWithoutCaseAccessIsRefused()

	/**
	 * A reader gets the count and the titled documents of the case asked for.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheOutstandingDocuments(): void {
		$answer = ['count' => 1, 'documents' => [['id' => 'io-1', 'title' => 'Notitie budget verlichting']]];
		$outstanding = $this->createMock(OutstandingDocuments::class);
		$outstanding->expects($this->once())->method('forCase')->with('case-1')->willReturn($answer);

		$response = $this->controller(signedIn: true, mayRead: true, outstanding: $outstanding)->outstanding(id: 'case-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testAReaderGetsTheOutstandingDocuments()

	/**
	 * A register that cannot answer is 503, never an empty list that reads as all assessed.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterAnswers503(): void {
		$outstanding = $this->createMock(OutstandingDocuments::class);
		$outstanding->method('forCase')->willThrowException(new RuntimeException('down'));

		$response = $this->controller(signedIn: true, mayRead: true, outstanding: $outstanding)->outstanding(id: 'case-1');

		$this->assertSame(503, $response->getStatus());
		$this->assertArrayNotHasKey('count', $response->getData());
	}//end testAnUnreadableRegisterAnswers503()

	/**
	 * The full list refuses like the outstanding one: 401 without a user, 403 without case access, and reads nothing.
	 *
	 * @return void
	 */
	public function testTheFullListRefusesWithoutAUserOrCaseAccess(): void {
		$this->assertSame(401, $this->controller(signedIn: false, mayRead: true)->index(id: 'case-1')->getStatus());

		$response = $this->controller(signedIn: true, mayRead: false)->index(id: 'case-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame('forbidden', $response->getData()['error']);
		$this->assertNotSame('', $response->getData()['message']);
		$this->assertArrayNotHasKey('documents', $response->getData());
	}//end testTheFullListRefusesWithoutAUserOrCaseAccess()

	/**
	 * A reader gets every document of the case asked for, with its assessment.
	 *
	 * @return void
	 */
	public function testAReaderGetsEveryDocumentWithItsAssessment(): void {
		$answer = [
			'count' => 2,
			'outstanding' => 1,
			'documents' => [
				['id' => 'io-1', 'title' => 'Notitie budget verlichting', 'outstanding' => true, 'assessment' => null],
				['id' => 'io-2', 'title' => 'Mail aannemer', 'outstanding' => false, 'assessment' => ['classification' => 'openbaar']],
			],
		];
		$assessed = $this->createMock(AssessedDocuments::class);
		$assessed->expects($this->once())->method('forCase')->with('case-1')->willReturn($answer);

		$response = $this->controller(signedIn: true, mayRead: true, assessed: $assessed)->index(id: 'case-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testAReaderGetsEveryDocumentWithItsAssessment()

	/**
	 * A register that cannot answer the full list is 503, never an empty list.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterAnswers503ForTheFullList(): void {
		$assessed = $this->createMock(AssessedDocuments::class);
		$assessed->method('forCase')->willThrowException(new RuntimeException('down'));

		$response = $this->controller(signedIn: true, mayRead: true, assessed: $assessed)->index(id: 'case-1');

		$this->assertSame(503, $response->getStatus());
		$this->assertArrayNotHasKey('count', $response->getData());
	}//end testAnUnreadableRegisterAnswers503ForTheFullList()

	/**
	 * Both endpoints refuse and fail with the same exact bodies.
	 *
	 * @param string $endpoint The action under test.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testRefusalsAndFailuresCarryTheirExactBodies(string $endpoint): void {
		$response = $this->controller(signedIn: false, mayRead: true)->$endpoint(id: 'case-1');
		$this->assertSame([401, ['error' => 'not_authenticated']], [$response->getStatus(), $response->getData()]);

		$response = $this->controller(signedIn: true, mayRead: false)->$endpoint(id: 'case-1');
		$this->assertSame(
			[403, ['error' => 'forbidden', 'message' => 'You do not have access to this case.']],
			[$response->getStatus(), $response->getData()]
		);

		$outstanding = $this->createMock(OutstandingDocuments::class);
		$outstanding->method('forCase')->willThrowException(new RuntimeException('down'));
		$assessed = $this->createMock(AssessedDocuments::class);
		$assessed->method('forCase')->willThrowException(new RuntimeException('down'));
		$response = $this->controller(signedIn: true, mayRead: true, outstanding: $outstanding, assessed: $assessed)->$endpoint(id: 'case-1');
		$this->assertSame(
			[503, ['error' => 'unavailable', 'message' => 'OpenRegister is not available.']],
			[$response->getStatus(), $response->getData()]
		);
	}//end testRefusalsAndFailuresCarryTheirExactBodies()

	/**
	 * The guard is asked about the case in the route, for the signed-in user.
	 *
	 * @param string $endpoint The action under test.
	 *
	 * @return void
	 *
	 * @dataProvider endpoints
	 */
	public function testTheGuardIsAskedAboutTheRoutedCase(string $endpoint): void {
		$user = $this->createMock(IUser::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->expects($this->once())->method('hasCaseReadAccess')->with('case-7', $user)->willReturn(false);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$controller = new DocumentAssessmentController(
			request: $this->createMock(IRequest::class),
			outstanding: $this->createMock(OutstandingDocuments::class),
			assessed: $this->createMock(AssessedDocuments::class),
			guard: $guard,
			userSession: $session,
			l10n: $l10n,
		);

		$this->assertSame(403, $controller->$endpoint(id: 'case-7')->getStatus());
	}//end testTheGuardIsAskedAboutTheRoutedCase()

	/**
	 * The two endpoints.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function endpoints(): array {
		return ['outstanding' => ['outstanding'], 'index' => ['index']];
	}//end endpoints()
}//end class
