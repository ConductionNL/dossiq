<?php

/**
 * SubsidieController unit tests: listing, creating and transitioning subsidy applications.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/tasks.md#TASK-SUB-06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\SubsidieController;
use OCA\Dossiq\Kernel\Contracts\SubsidieServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TussenrapportageServiceInterface;
use OCA\Dossiq\Kernel\Contracts\VaststellingServiceInterface;
use OCA\Dossiq\Service\Subsidie\BeschikkingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The application endpoints: a session is required, the service's refusal is a 400.
 *
 * @covers \OCA\Dossiq\Controller\SubsidieController
 */
class SubsidieControllerApplicationTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * The subsidy service double.
	 *
	 * @var SubsidieServiceInterface&MockObject
	 */
	private SubsidieServiceInterface $subsidies;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->subsidies = $this->createMock(SubsidieServiceInterface::class);
		$this->session = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller.
	 *
	 * @return SubsidieController
	 */
	private function controller(): SubsidieController {
		return new SubsidieController(
			request: $this->request,
			subsidyService: $this->subsidies,
			decisionService: $this->createMock(BeschikkingService::class),
			tussenrapportage: $this->createMock(TussenrapportageServiceInterface::class),
			determinationService: $this->createMock(VaststellingServiceInterface::class),
			userSession: $this->session,
		);
	}//end controller()

	/**
	 * Sign sofie in.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Without a session every application endpoint is a 401 and the service is never reached.
	 *
	 * @return void
	 */
	public function testWithoutASessionEveryEndpointIsUnauthorised(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->subsidies->expects($this->never())->method('listAanvragen');
		$this->subsidies->expects($this->never())->method('createAanvraag');
		$this->subsidies->expects($this->never())->method('transitionAanvraag');

		foreach ([$this->controller()->index(), $this->controller()->create(), $this->controller()->transition(id: 'a-1')] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Authenticatie vereist'], $response->getData());
		}
	}//end testWithoutASessionEveryEndpointIsUnauthorised()

	/**
	 * The query's status, regeling and handler become the service's filters.
	 *
	 * @return void
	 */
	public function testIndexFiltersByTheQuery(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturnMap([
			['status', '', 'ingediend'],
			['regeling', '', 'reg-1'],
			['handler', '', ''],
		]);
		$this->subsidies->expects($this->once())->method('listAanvragen')
			->with(['status' => 'ingediend', 'subsidyScheme' => 'reg-1', 'handler' => ''])
			->willReturn([['id' => 'a-1']]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['results' => [['id' => 'a-1']]], $response->getData());
	}//end testIndexFiltersByTheQuery()

	/**
	 * A filter the service refuses is a 400.
	 *
	 * @return void
	 */
	public function testIndexWithARefusedFilterIsABadRequest(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturnArgument(1);
		$this->subsidies->method('listAanvragen')->willThrowException(new OCSBadRequestException('Unknown status'));

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Unknown status'], $response->getData());
	}//end testIndexWithARefusedFilterIsABadRequest()

	/**
	 * Without a handler the caller handles it; the route ids and the term are kept out of the payload.
	 *
	 * @return void
	 */
	public function testCreateDefaultsTheHandlerAndTheTerm(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['title' => 'Buurtfeest', 'id' => 'x', '_route' => 'r']);
		$this->subsidies->expects($this->once())->method('createAanvraag')
			->with(['title' => 'Buurtfeest', 'handler' => 'sofie'], SubsidieServiceInterface::DEFAULT_AANVRAAG_TERMIJN_WEKEN)
			->willReturn(['id' => 'a-1']);

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'a-1'], $response->getData());
	}//end testCreateDefaultsTheHandlerAndTheTerm()

	/**
	 * An assigned handler and term are kept, the term is passed separately.
	 *
	 * @return void
	 */
	public function testCreateKeepsAnAssignedHandlerAndTerm(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn(['title' => 'Buurtfeest', 'handler' => 'piet', 'termijnWeken' => '8']);
		$this->subsidies->expects($this->once())->method('createAanvraag')
			->with(['title' => 'Buurtfeest', 'handler' => 'piet'], 8)
			->willReturn(['id' => 'a-2']);

		$this->assertSame(Http::STATUS_CREATED, $this->controller()->create()->getStatus());
	}//end testCreateKeepsAnAssignedHandlerAndTerm()

	/**
	 * A create the service refuses is a 400.
	 *
	 * @return void
	 */
	public function testARefusedCreateIsABadRequest(): void {
		$this->signIn();
		$this->request->method('getParams')->willReturn([]);
		$this->subsidies->method('createAanvraag')->willThrowException(new OCSBadRequestException('regeling is required'));

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'regeling is required'], $response->getData());
	}//end testARefusedCreateIsABadRequest()

	/**
	 * A transition goes to the requested status; a refused one is a 400.
	 *
	 * @return void
	 */
	public function testTransitionAndItsRefusal(): void {
		$this->signIn();
		$this->request->method('getParam')->with('status', '')->willReturn('in-behandeling');
		$this->subsidies->method('transitionAanvraag')->willReturnCallback(
			static fn (string $id, string $to): array => match ($id) {
				'a-1' => ['id' => 'a-1', 'status' => $to],
				default => throw new OCSBadRequestException('Not allowed from afgewezen'),
			}
		);

		$ok = $this->controller()->transition(id: 'a-1');
		$refused = $this->controller()->transition(id: 'a-9');

		$this->assertSame(['id' => 'a-1', 'status' => 'in-behandeling'], $ok->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'Not allowed from afgewezen'], $refused->getData());
	}//end testTransitionAndItsRefusal()
}//end class
