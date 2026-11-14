<?php

/**
 * CaseSeatsController tests.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseSeatsController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\People\CoordinatorRequirement;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\CaseSeatsController
 * @uses   \OCA\Dossiq\Controller\Support\CaseCaller
 */
class CaseSeatsControllerTest extends TestCase {

	/** @var CaseSeats&MockObject */
	private CaseSeats $seats;

	/** @var CoordinatorRequirement&MockObject */
	private CoordinatorRequirement $requirement;

	/** @var CaseAccessGuard&MockObject */
	private CaseAccessGuard $guard;

	/** @var SettingsService&MockObject */
	private SettingsService $settings;

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var IRequest&MockObject */
	private IRequest $request;

	private IUser $user;

	protected function setUp(): void {
		$this->seats = $this->createMock(CaseSeats::class);
		$this->requirement = $this->createMock(CoordinatorRequirement::class);
		$this->guard = $this->createMock(CaseAccessGuard::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->request = $this->createMock(IRequest::class);
		$this->user = $this->createMock(IUser::class);
		$this->settings->method('getConfigValue')->willReturnMap([['register', '', '3'], ['case_schema', '', '9']]);
	}

	private function controller(): CaseSeatsController {
		return new CaseSeatsController(
			'dossiq',
			$this->request,
			$this->seats,
			$this->requirement,
			$this->guard,
			$this->settings,
			$this->session,
			$this->logger,
		);
	}

	/**
	 * Give the settings an object service that returns or throws for a find.
	 *
	 * @param mixed $result The found object, or a Throwable to throw.
	 *
	 * @return object The object service.
	 */
	private function caseStore(mixed $result): object {
		$store = new class($result) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			public function __construct(private mixed $result) {
			}

			public function find(string $id, int|string $register, int|string $schema): mixed {
				$this->calls[] = [$id, $register, $schema];
				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}

				return $this->result;
			}
		};
		$this->settings->method('getObjectService')->willReturn($store);

		return $store;
	}

	private function readable(bool $readable): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->guard->method('hasCaseReadAccess')->with('case-1', $this->user)->willReturn($readable);
	}

	public function testShowRefusesAnonymousCaller(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->seats->expects($this->never())->method('seatsOf');

		$response = $this->controller()->show('case-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'You cannot read the people on this case.', 'error' => 'case-access-denied'], $response->getData());
	}

	public function testShowRefusesAUserWithoutReadAccess(): void {
		$this->readable(false);
		$this->settings->expects($this->never())->method('getObjectService');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->show('case-1')->getStatus());
	}

	public function testShowReportsAnUnreadableCaseWhenThereIsNoObjectService(): void {
		$this->readable(true);
		$this->settings->method('getObjectService')->willReturn(null);

		$response = $this->controller()->show('case-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'That case could not be read.', 'error' => 'case-unreadable'], $response->getData());
	}

	public function testShowReportsAMissingCaseAsUnreadable(): void {
		$this->readable(true);
		$this->caseStore(new DoesNotExistException('nope'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show('case-1')->getStatus());
	}

	public function testShowLogsAndReportsAFailingRead(): void {
		$this->readable(true);
		$this->caseStore(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('CaseSeatsController: the case could not be read', ['caseId' => 'case-1', 'exception' => 'db down']);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->show('case-1')->getStatus());
	}

	public function testShowReturnsTheSeatsAndWhetherACoordinatorIsRequired(): void {
		$this->readable(true);
		$store = $this->caseStore(['id' => 'case-1', 'title' => 'Bouw']);
		$this->seats->expects($this->once())->method('seatsOf')->with(['id' => 'case-1', 'title' => 'Bouw'])
			->willReturn(['coordinator' => null, 'participants' => []]);
		$this->requirement->method('applies')->with('case-1')->willReturn(true);

		$response = $this->controller()->show('case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['coordinator' => null, 'participants' => [], 'coordinatorRequiredBeforeSigning' => true],
			$response->getData()
		);
		$this->assertSame([['case-1', '3', '9']], $store->calls);
	}

	public function testShowAcceptsASerialisableEntity(): void {
		$this->readable(true);
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'case-1'];
			}
		};
		$this->caseStore($entity);
		$this->seats->expects($this->once())->method('seatsOf')->with(['id' => 'case-1'])->willReturn([]);

		$this->assertSame(['coordinatorRequiredBeforeSigning' => false], $this->controller()->show('case-1')->getData());
	}

	public function testNameCoordinatorRefusesAReaderWhoCannotChangeTheCase(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->guard->method('hasCaseMutationAccess')->with('case-1', $this->user)->willReturn(false);
		$this->seats->expects($this->never())->method('nameCoordinator');
		$this->seats->expects($this->never())->method('clearCoordinator');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller()->nameCoordinator('case-1')->getStatus());
	}

	public function testNameCoordinatorFillsTheSeatWithTrimmedValuesAndReturnsTheSeats(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['participant', '', ' p-7 '], ['name', '', ' Jan ']]);
		$this->caseStore(['id' => 'case-1']);
		$this->seats->expects($this->once())->method('nameCoordinator')->with('case-1', 'p-7', 'Jan')->willReturn('role-1');
		$this->seats->expects($this->never())->method('clearCoordinator');
		$this->seats->method('seatsOf')->willReturn(['coordinator' => 'p-7']);

		$response = $this->controller()->nameCoordinator('case-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['coordinator' => 'p-7', 'coordinatorRequiredBeforeSigning' => false], $response->getData());
	}

	public function testAnEmptyParticipantClearsTheSeat(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->guard->method('hasCaseReadAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['participant', '', '  '], ['name', '', '']]);
		$this->caseStore(['id' => 'case-1']);
		$this->seats->expects($this->once())->method('clearCoordinator')->with('case-1')->willReturn('');
		$this->seats->expects($this->never())->method('nameCoordinator');
		$this->seats->method('seatsOf')->willReturn(['coordinator' => null]);

		$this->assertSame(['coordinator' => null, 'coordinatorRequiredBeforeSigning' => false], $this->controller()->nameCoordinator('case-1')->getData());
	}

	public function testAFailingSeatChangeIsLoggedAndExplained(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->request->method('getParam')->willReturnMap([['participant', '', 'p-7'], ['name', '', '']]);
		$this->seats->method('nameCoordinator')->willThrowException(new RuntimeException('no role type'));
		$this->seats->expects($this->never())->method('seatsOf');
		$this->logger->expects($this->once())->method('warning')
			->with('CaseSeatsController: the coordinator seat could not be set', ['caseId' => 'case-1', 'exception' => 'no role type']);

		$response = $this->controller()->nameCoordinator('case-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('no-coordinator-role-type', $response->getData()['error']);
	}
}
