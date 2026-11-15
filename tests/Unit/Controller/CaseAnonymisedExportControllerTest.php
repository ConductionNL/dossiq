<?php

/**
 * CaseAnonymisedExportController: the signed-in user's anonymised export of a listed case set.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-a-case-list-can-be-exported-anonymised-req-sdc-anon-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use LogicException;
use OCA\Dossiq\Controller\CaseAnonymisedExportController;
use OCA\Dossiq\Service\Export\AnonymisedCaseExport;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * 401 without a session, 400 for a refused list, 500 logged for a failure.
 *
 * @covers \OCA\Dossiq\Controller\CaseAnonymisedExportController
 */
class CaseAnonymisedExportControllerTest extends TestCase {

	/**
	 * The export.
	 *
	 * @var AnonymisedCaseExport&MockObject
	 */
	private $export;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The controller for a user (or nobody) and a caseIds parameter.
	 *
	 * @param string|null $uid     The user.
	 * @param mixed       $caseIds The caseIds parameter.
	 *
	 * @return CaseAnonymisedExportController
	 */
	private function controller(?string $uid, mixed $caseIds): CaseAnonymisedExportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('caseIds', [])->willReturn($caseIds);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$this->export ??= $this->createMock(AnonymisedCaseExport::class);
		$this->logger ??= $this->createMock(LoggerInterface::class);

		return new CaseAnonymisedExportController('dossiq', $request, $this->export, $session, $this->logger);
	}//end controller()

	/**
	 * The listed cases come back anonymised, exported as the signed-in user.
	 *
	 * @return void
	 */
	public function testTheCasesAreExportedAsTheUser(): void {
		$this->export = $this->createMock(AnonymisedCaseExport::class);
		$this->export->expects($this->once())->method('export')->with(['c-1', 'c-2'], 'pieter')->willReturn([['id' => 'c-1'], ['id' => 'c-2']]);

		$response = $this->controller('pieter', ['c-1', 'c-2'])->export();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['cases' => [['id' => 'c-1'], ['id' => 'c-2']]], $response->getData());
	}//end testTheCasesAreExportedAsTheUser()

	/**
	 * Without a session, or with caseIds that are not a list, nothing is exported.
	 *
	 * @return void
	 */
	public function testNothingIsExportedForNobodyOrANonList(): void {
		$this->export = $this->createMock(AnonymisedCaseExport::class);
		$this->export->expects($this->never())->method('export');

		$nobody = $this->controller(null, ['c-1'])->export();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $nobody->getStatus());

		$notAList = $this->controller('pieter', 'c-1')->export();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $notAList->getStatus());
		$this->assertSame(['error' => 'caseIds must be a list'], $notAList->getData());
	}//end testNothingIsExportedForNobodyOrANonList()

	/**
	 * A refusal is 400 with its reason; any other failure is 500, logged, without its detail.
	 *
	 * @return void
	 */
	public function testRefusalsAndFailures(): void {
		$this->export = $this->createMock(AnonymisedCaseExport::class);
		$this->export->method('export')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('No cases to export')),
			$this->throwException(new LogicException('register exploded'))
		);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('error')
			->with('Dossiq: the anonymised case export failed', ['exception' => 'register exploded']);

		$refused = $this->controller('pieter', [])->export();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'No cases to export'], $refused->getData());

		$failed = $this->controller('pieter', ['c-1'])->export();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $failed->getStatus());
		$this->assertSame(['error' => 'The anonymised export failed'], $failed->getData());
	}//end testRefusalsAndFailures()
}//end class
