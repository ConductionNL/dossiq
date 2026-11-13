<?php

/**
 * Unit tests for StoreFailureAnswer: a store failure is answered as 404 for a
 * missing object, 403 for a refused one, and otherwise as "not completed",
 * each logged at its own level.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\StoreFailureAnswer;
use OCA\Dossiq\Service\Zgw\ZgwRuleResults;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Zgw\StoreFailureAnswer
 */
class StoreFailureAnswerTest extends TestCase {

	private LoggerInterface&MockObject $logger;

	private StoreFailureAnswer $answer;

	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->answer = new StoreFailureAnswer($this->logger);
	}//end setUp()

	public function testAMissingObjectIsNotFoundAndLoggedAsInfo(): void {
		$error = new DoesNotExistException('gone');
		$this->logger->expects($this->once())->method('info')
			->with('{operation}: {object} not found', ['operation' => 'zaken.read', 'object' => 'zaak 1', 'exception' => $error]);
		$this->logger->expects($this->never())->method('error');

		$response = $this->answer->respond($error, 'zaken.read', 'zaak 1');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['detail' => 'Not found'], $response->getData());
	}//end testAMissingObjectIsNotFoundAndLoggedAsInfo()

	public function testAForbiddenCodeIsPermissionDeniedAndLoggedAsWarning(): void {
		$error = new RuntimeException('rbac', 403);
		$this->logger->expects($this->once())->method('warning')
			->with('{operation}: not allowed on {object}: rbac', ['operation' => 'zaken.update', 'object' => 'zaak 1', 'exception' => $error]);

		$response = $this->answer->respond($error, 'zaken.update', 'zaak 1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			[
				'type' => 'PermissionDenied',
				'code' => 'permission_denied',
				'title' => 'U heeft geen toestemming voor deze actie.',
				'status' => 403,
				'detail' => 'U heeft geen toestemming voor deze actie.',
			],
			$response->getData()
		);
	}//end testAForbiddenCodeIsPermissionDeniedAndLoggedAsWarning()

	public function testAnyOtherFailureIsNotCompletedAndLoggedAsError(): void {
		$error = new RuntimeException('database is locked', 500);
		$this->logger->expects($this->once())->method('error')
			->with(
				'{operation}: the store did not complete the call for {object}: database is locked',
				['operation' => 'zaken.create', 'object' => 'zaak', 'exception' => $error]
			);
		$this->logger->expects($this->never())->method('warning');

		$response = $this->answer->respond($error, 'zaken.create', 'zaak');

		$this->assertSame(ZgwRuleResults::NOT_VERIFIED_STATUS, $response->getStatus());
		$this->assertSame(
			['code' => 'store-not-reached', 'detail' => 'De registratie kon de opdracht niet voltooien. Probeer het later opnieuw.'],
			$response->getData()
		);
	}//end testAnyOtherFailureIsNotCompletedAndLoggedAsError()

	public function testNotFoundOnItsOwn(): void {
		$this->assertSame(404, $this->answer->notFound()->getStatus());
		$this->assertSame(['detail' => 'Not found'], $this->answer->notFound()->getData());
	}//end testNotFoundOnItsOwn()
}//end class
