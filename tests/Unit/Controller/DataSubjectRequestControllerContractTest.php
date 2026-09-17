<?php

/**
 * The contract of the four AVG endpoints: they are routed, they are guarded
 * per case, and a platform refusal reaches the caller with its own rule.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\DataSubjectRequestController;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Gdpr\DataSubjectRequestCase;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Gate 25: every new public endpoint gets a contract.
 */
class DataSubjectRequestControllerContractTest extends TestCase {

	/**
	 * The case side.
	 *
	 * @var DataSubjectRequestCase&MockObject
	 */
	private DataSubjectRequestCase $requests;

	/**
	 * The per-case guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard $guard;

	/**
	 * The controller under test.
	 *
	 * @var DataSubjectRequestController
	 */
	private DataSubjectRequestController $controller;

	/**
	 * Wire the controller with an ordinary signed-in caller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->requests = $this->createMock(DataSubjectRequestCase::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);

		$userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler1');
		$userSession->method('getUser')->willReturn($user);

		$this->controller = new DataSubjectRequestController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			requests: $this->requests,
			accessGuard: $this->guard,
			userSession: $userSession,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * The four routes exist, and each names a method the controller has.
	 *
	 * 🔑 THE ROUTE TABLE IS READ, NOT ASSUMED. An endpoint that exists as a
	 * method and not as a route is a 404 at runtime and a green unit test
	 * here, which is the pairing gate-25 exists to break.
	 *
	 * @return void
	 */
	public function testEveryEndpointIsRoutedAndExists(): void {
		$routes = (include __DIR__ . '/../../../appinfo/routes.php')['routes'];
		$named = [];
		foreach ($routes as $route) {
			if (str_starts_with((string)($route['name'] ?? ''), 'dataSubjectRequest#') === true) {
				$named[] = ['name' => $route['name'], 'url' => $route['url'], 'verb' => $route['verb']];
			}
		}

		self::assertCount(4, $named);
		foreach ($named as $route) {
			$method = substr($route['name'], strlen('dataSubjectRequest#'));
			self::assertTrue(
				method_exists(DataSubjectRequestController::class, $method),
				'routed method is missing: ' . $method
			);
			self::assertStringContainsString('{caseId}', $route['url']);
		}
	}//end testEveryEndpointIsRoutedAndExists()

	/**
	 * Every endpoint declares its auth posture, and none of them demands a
	 * Nextcloud administrator: a privacy officer is not one.
	 *
	 * @return void
	 */
	public function testEveryEndpointIsReachableByAHandler(): void {
		foreach (['preview', 'run', 'requestExport', 'exportState'] as $method) {
			$attributes = (new ReflectionMethod(DataSubjectRequestController::class, $method))
				->getAttributes(NoAdminRequired::class);
			self::assertCount(1, $attributes, $method . ' is admin-only');
		}
	}//end testEveryEndpointIsReachableByAHandler()

	/**
	 * A caller who may not write to the case is refused, and the case side is
	 * never asked, so no count of a named person leaks through a 403 body.
	 *
	 * @return void
	 */
	public function testACallerWithoutAccessIsRefused(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(false);
		$this->requests->expects(self::never())->method('preview');
		$this->requests->expects(self::never())->method('run');

		foreach ([$this->controller->preview(caseId: 'c1'), $this->controller->run(caseId: 'c1')] as $response) {
			self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
			self::assertSame('case-access-denied', $response->getData()['error']);
		}
	}//end testACallerWithoutAccessIsRefused()

	/**
	 * The preview answers with the platform's counts and protected items.
	 *
	 * @return void
	 */
	public function testThePreviewAnswersWithTheCountsAndTheGrounds(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('preview')->willReturn(
			[
				'uuid' => 'p1',
				'report' => [
					'counts' => ['erasable' => ['objects' => 8]],
					'protected' => [['name' => 'Subsidiedossier 2019', 'ground' => 'Archiefwet']],
				],
			]
		);

		$response = $this->controller->preview(caseId: 'c1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('Archiefwet', $response->getData()['report']['protected'][0]['ground']);
	}//end testThePreviewAnswersWithTheCountsAndTheGrounds()

	/**
	 * A refusal keeps its rule and its status, so a stale preview reads as
	 * something a handler can fix rather than as a generic failure.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsRuleAndStatus(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('run')->willThrowException(
			new RefusedException(
				rule: 'erasure-preview-stale',
				sentence: 'Take the preview again.',
				status: RefusedException::STATUS_REFUSED,
			)
		);

		$response = $this->controller->run(caseId: 'c1');

		self::assertSame(RefusedException::STATUS_REFUSED, $response->getStatus());
		self::assertSame('erasure-preview-stale', $response->getData()['error']);
		self::assertSame('Take the preview again.', $response->getData()['message']);
	}//end testARefusalKeepsItsRuleAndStatus()

	/**
	 * Something that broke rather than refused says nothing was erased, and
	 * says it at 500 rather than dressing it up as a refusal.
	 *
	 * @return void
	 */
	public function testABreakageSaysNothingWasErased(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('run')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->run(caseId: 'c1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('data-subject-request-failed', $response->getData()['error']);
		self::assertStringContainsString('Nothing was erased', $response->getData()['message']);
	}//end testABreakageSaysNothingWasErased()

	/**
	 * The export state is read from the case side and answered as it stands.
	 *
	 * @return void
	 */
	public function testTheExportStateIsAnsweredAsItStands(): void {
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->requests->method('exportState')->willReturn(
			['exportId' => 'e1', 'downloadable' => false, 'expiresAt' => '2026-09-08T10:00:00+00:00', 'expired' => true]
		);

		$response = $this->controller->exportState(caseId: 'c1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertTrue($response->getData()['expired']);
		self::assertFalse($response->getData()['downloadable']);
	}//end testTheExportStateIsAnsweredAsItStands()
}//end class
