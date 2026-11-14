<?php

/**
 * The verdicts route answers a reader of the case and refuses anyone else before reading.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DocumentVerdictsController;
use OCA\Dossiq\Review\DocumentVerdicts;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\DocumentVerdictsController
 */
class DocumentVerdictsControllerTest extends TestCase {

	/**
	 * The controller over the given verdicts, for a user who may or may not read the case.
	 *
	 * @param DocumentVerdicts&MockObject $verdicts The verdicts.
	 * @param bool                        $read     Whether the user may read the case.
	 * @param bool                        $loggedIn Whether there is a user.
	 *
	 * @return DocumentVerdictsController
	 */
	private function controller(DocumentVerdicts&MockObject $verdicts, bool $read = true, bool $loggedIn = true): DocumentVerdictsController {
		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn($read);
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($loggedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
		}

		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new DocumentVerdictsController(
			request: $this->createMock(originalClassName: IRequest::class),
			verdicts: $verdicts,
			caseAccessGuard: $guard,
			userSession: $session,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * A reader of the case gets its verdicts.
	 *
	 * @return void
	 */
	public function testAReaderGetsTheVerdicts(): void {
		$verdicts = $this->createMock(originalClassName: DocumentVerdicts::class);
		$verdicts->expects($this->once())->method('forCase')->with('case-1')->willReturn(['documents' => 7, 'assessed' => 4]);

		$response = $this->controller(verdicts: $verdicts)->forCase(id: 'case-1');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['documents' => 7, 'assessed' => 4], actual: $response->getData());
	}//end testAReaderGetsTheVerdicts()

	/**
	 * A user who may not read the case is refused and nothing is read.
	 *
	 * @return void
	 */
	public function testAUserWhoMayNotReadTheCaseIsRefusedBeforeAnyRead(): void {
		$verdicts = $this->createMock(originalClassName: DocumentVerdicts::class);
		$verdicts->expects($this->never())->method('forCase');

		$this->assertSame(expected: 403, actual: $this->controller(verdicts: $verdicts, read: false)->forCase(id: 'case-1')->getStatus());
		$this->assertSame(expected: 401, actual: $this->controller(verdicts: $verdicts, loggedIn: false)->forCase(id: 'case-1')->getStatus());
	}//end testAUserWhoMayNotReadTheCaseIsRefusedBeforeAnyRead()
}//end class
