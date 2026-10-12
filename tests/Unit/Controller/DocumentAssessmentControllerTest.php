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
use OCA\Dossiq\Service\Assessment\OutstandingDocuments;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-DAS-001 through the route.
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
	 *
	 * @return DocumentAssessmentController
	 */
	private function controller(bool $signedIn, bool $mayRead, ?OutstandingDocuments $outstanding = null): DocumentAssessmentController {
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

		return new DocumentAssessmentController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			outstanding: $outstanding,
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
}//end class
