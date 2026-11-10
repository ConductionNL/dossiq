<?php

/**
 * CaseIncidentController access golden.
 *
 * Pins who may list incidents on a case and who may record, assign and
 * settle them: a caller without a session and a caller the guard refuses get
 * the same 403 and nothing is read or written; a reader lists; a writer
 * changes. The read and write checks ask the guard with the caller and the
 * case, before anything else happens.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseIncidentController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Cases\IncidentStore;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Allowed and refused callers of every incident endpoint.
 *
 * @covers \OCA\Dossiq\Controller\CaseIncidentController
 */
class CaseIncidentControllerAccessTest extends TestCase {

	/**
	 * The incident store.
	 *
	 * @var IncidentStore&MockObject
	 */
	private IncidentStore&MockObject $incidents;

	/**
	 * The guard.
	 *
	 * @var CaseAccessGuard&MockObject
	 */
	private CaseAccessGuard&MockObject $guard;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The signed-in user.
	 *
	 * @var IUser&MockObject
	 */
	private IUser&MockObject $user;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->incidents = $this->createMock(IncidentStore::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->user = $this->createMock(IUser::class);
	}//end setUp()

	/**
	 * The controller over these request parameters.
	 *
	 * @param array<string, string> $params The request parameters.
	 *
	 * @return CaseIncidentController
	 */
	private function controller(array $params = []): CaseIncidentController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));

		return new CaseIncidentController('dossiq', $request, $this->incidents, $this->guard, $this->session, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * Every endpoint, called on case-1.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function endpoints(): array {
		return [
			'index' => ['index', false],
			'record' => ['record', true],
			'assign' => ['assign', true],
			'settle' => ['settle', true],
		];
	}//end endpoints()

	/**
	 * Call one endpoint.
	 *
	 * @param CaseIncidentController $controller The controller.
	 * @param string                 $endpoint   The endpoint.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function call(CaseIncidentController $controller, string $endpoint) {
		if ($endpoint === 'index' || $endpoint === 'record') {
			return $controller->$endpoint('case-1');
		}

		return $controller->$endpoint('case-1', 'inc-1');
	}//end call()

	/**
	 * The refusal every endpoint answers.
	 *
	 * @param \OCP\AppFramework\Http\JSONResponse $response The response.
	 *
	 * @return void
	 */
	private function assertRefused($response): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'You cannot do that on this case.', 'error' => 'case-access-denied'], $response->getData());
	}//end assertRefused()

	/**
	 * A caller without a session is refused, and the guard is not asked.
	 *
	 * @param string $endpoint The endpoint.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testACallerWithoutASessionIsRefused(string $endpoint): void {
		$this->session->method('getUser')->willReturn(null);
		$this->guard->expects($this->never())->method($this->anything());
		$this->incidents->expects($this->never())->method($this->anything());

		$this->assertRefused($this->call($this->controller(), $endpoint));
	}//end testACallerWithoutASessionIsRefused()

	/**
	 * A caller the guard refuses is refused, and nothing is read or written.
	 *
	 * @param string $endpoint The endpoint.
	 * @param bool   $writes   Whether it is a write.
	 *
	 * @dataProvider endpoints
	 *
	 * @return void
	 */
	public function testACallerTheGuardRefusesIsRefused(string $endpoint, bool $writes): void {
		$this->session->method('getUser')->willReturn($this->user);
		$asked = $writes === true ? 'hasCaseMutationAccess' : 'hasCaseReadAccess';
		$this->guard->expects($this->once())->method($asked)->with('case-1', $this->user)->willReturn(false);
		$this->incidents->expects($this->never())->method($this->anything());

		$this->assertRefused($this->call($this->controller(['eventDate' => '2026-01-01', 'description' => 'x', 'outcome' => 'y']), $endpoint));
	}//end testACallerTheGuardRefusesIsRefused()

	/**
	 * A reader may list but not write; a writer may write.
	 *
	 * @return void
	 */
	public function testAReaderListsAndAWriterWrites(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->guard->method('hasCaseMutationAccess')->willReturnOnConsecutiveCalls(false, true, true, true);
		$this->incidents->method('onCase')->willReturn([['id' => 'inc-1']]);
		$this->incidents->method('recordingDelayDays')->willReturn(2);
		$this->incidents->expects($this->once())->method('record')
			->with('case-1', '2026-01-01', 'Viel van de trap', 'buurman', 'anna')
			->willReturn(['id' => 'inc-2']);
		$this->incidents->expects($this->once())->method('assign')->with('inc-1', 'bert')->willReturn(['id' => 'inc-1', 'assignee' => 'bert']);
		$this->incidents->expects($this->once())->method('settle')->with('inc-1', 'afgehandeld')->willReturn(['id' => 'inc-1', 'outcome' => 'afgehandeld']);

		$listed = $this->controller()->index('case-1');
		$this->assertSame(['caseId' => 'case-1', 'incidents' => [['id' => 'inc-1', 'recordingDelayDays' => 2]], 'total' => 1], $listed->getData());

		$params = ['eventDate' => ' 2026-01-01 ', 'description' => 'Viel van de trap', 'reporter' => 'buurman', 'assignee' => 'anna'];
		$this->assertRefused($this->controller($params)->record('case-1'));
		$this->assertSame(['id' => 'inc-2'], $this->controller($params)->record('case-1')->getData());
		$this->assertSame('bert', $this->controller(['assignee' => 'bert'])->assign('case-1', 'inc-1')->getData()['assignee']);
		$this->assertSame('afgehandeld', $this->controller(['outcome' => 'afgehandeld'])->settle('case-1', 'inc-1')->getData()['outcome']);
	}//end testAReaderListsAndAWriterWrites()
}//end class
