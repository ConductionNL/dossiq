<?php

/**
 * PublicationController Unit Tests.
 *
 * Pins the authentication guard, the payload handed to the publication
 * service (route parameters stripped), and the 400 and logging on failure.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\PublicationController;
use OCA\Dossiq\Service\PublicationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for PublicationController.
 *
 * @covers \OCA\Dossiq\Controller\PublicationController
 */
class PublicationControllerTest extends TestCase {

	/**
	 * The publication service double.
	 *
	 * @var PublicationService&MockObject
	 */
	private PublicationService&MockObject $publications;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->publications = $this->createMock(PublicationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @param bool                $signedIn Whether a user is signed in.
	 * @param array<string,mixed> $params   The request parameters.
	 *
	 * @return PublicationController
	 */
	private function controller(bool $signedIn, array $params = []): PublicationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new PublicationController($request, $this->publications, $session, $this->logger);
	}//end controller()

	/**
	 * Without a user the publish is refused with 400 and nothing is published.
	 *
	 * @return void
	 */
	public function testPublishRequiresAUser(): void {
		$this->publications->expects($this->never())->method('publish');

		$response = $this->controller(signedIn: false)->publish('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
	}//end testPublishRequiresAUser()

	/**
	 * The body goes to the service without the route's id and _route members.
	 *
	 * @return void
	 */
	public function testPublishPassesTheBodyWithoutRouteParameters(): void {
		$this->publications->expects($this->once())->method('publish')
			->with('case-1', ['title' => 'Besluit', 'documents' => [4]])
			->willReturn(['published' => true]);

		$response = $this->controller(
			signedIn: true,
			params: ['id' => 'case-1', '_route' => 'dossiq.publication.publish', 'title' => 'Besluit', 'documents' => [4]]
		)->publish('case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['published' => true], $response->getData());
	}//end testPublishPassesTheBodyWithoutRouteParameters()

	/**
	 * An invalid payload is a 400 with the service message and is not logged.
	 *
	 * @return void
	 */
	public function testAnInvalidPayloadIs400(): void {
		$this->publications->method('publish')->willThrowException(new InvalidArgumentException('title is required'));
		$this->logger->expects($this->never())->method('error');

		$response = $this->controller(signedIn: true)->publish('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'title is required'], $response->getData());
	}//end testAnInvalidPayloadIs400()

	/**
	 * Any other failure is logged with the case and answered with 400.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsLoggedAnd400(): void {
		$this->publications->method('publish')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('error')->with(
			'PublicationController::publish failed: register down',
			['app' => 'dossiq', 'caseId' => 'case-1']
		);

		$response = $this->controller(signedIn: true)->publish('case-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'register down'], $response->getData());
	}//end testAnyOtherFailureIsLoggedAnd400()
}//end class
