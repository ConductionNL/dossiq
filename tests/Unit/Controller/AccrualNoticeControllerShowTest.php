<?php

/**
 * AccrualNoticeController unit tests: reading a notice, and the failure answers of registering one.
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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\AccrualNoticeController;
use OCA\Dossiq\Kernel\Contracts\AccrualNoticeServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The show endpoint and the register branches the happy-path test leaves out.
 *
 * @covers \OCA\Dossiq\Controller\AccrualNoticeController
 */
class AccrualNoticeControllerShowTest extends TestCase {

	/**
	 * The service double.
	 *
	 * @var AccrualNoticeServiceInterface&MockObject
	 */
	private AccrualNoticeServiceInterface $service;

	/**
	 * The settings double.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface $settings;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The guard double.
	 *
	 * @var CaseAccessGuardInterface&MockObject
	 */
	private CaseAccessGuardInterface $guard;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(AccrualNoticeServiceInterface::class);
		$this->settings = $this->createMock(SettingsServiceInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->guard = $this->createMock(CaseAccessGuardInterface::class);
	}//end setUp()

	/**
	 * The controller, reading the given body.
	 *
	 * @param array<string, mixed>|null $body The body, or null to read the (empty) real input.
	 *
	 * @return AccrualNoticeController
	 */
	private function controller(?array $body=null): AccrualNoticeController {
		$args = ['dossiq', $this->createMock(IRequest::class), $this->service, $this->settings, $this->session, $this->logger, $this->guard];
		if ($body === null) {
			return new AccrualNoticeController(...$args);
		}

		return new class($body, ...$args) extends AccrualNoticeController {
			/**
			 * The body this request carries.
			 *
			 * @var array<string, mixed>
			 */
			private array $body;

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $body The body.
			 * @param mixed                ...$args The parent's arguments.
			 */
			public function __construct(array $body, mixed ...$args) {
				$this->body = $body;
				parent::__construct(...$args);
			}

			/**
			 * The body.
			 *
			 * @return array<string, mixed>
			 */
			protected function jsonBody(): array {
				return $this->body;
			}
		};
	}//end controller()

	/**
	 * Sign in a user.
	 *
	 * @return void
	 */
	private function signIn(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('sofie');
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Configure the register and the notice schema.
	 *
	 * @param object|null $objectService The object service to hand out.
	 *
	 * @return void
	 */
	private function configure(?object $objectService): void {
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturnMap([
			['register', '', '5'],
			[MoneyConfigKeys::NOTICE_OF_DEFAULT_SCHEMA, '', '9'],
		]);
	}//end configure()

	/**
	 * An object service whose find() answers from a callback.
	 *
	 * @param callable $find The find body.
	 *
	 * @return object
	 */
	private function objectService(callable $find): object {
		return new class($find) {
			/**
			 * The find body.
			 *
			 * @var callable
			 */
			private $find;

			/**
			 * Constructor.
			 *
			 * @param callable $find The find body.
			 */
			public function __construct(callable $find) {
				$this->find = $find;
			}

			/**
			 * Find one object.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, int|string $register, int|string $schema): mixed {
				return ($this->find)($id, $register, $schema);
			}
		};
	}//end objectService()

	/**
	 * Registering without a session is a 403 and nothing is looked up.
	 *
	 * @return void
	 */
	public function testRegisterWithoutASessionIsForbidden(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('registerNotice');

		$response = $this->controller(body: [])->register();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testRegisterWithoutASessionIsForbidden()

	/**
	 * Without a JSON request body (the real input is empty) the request is incomplete; the legacy path answers the same.
	 *
	 * @return void
	 */
	public function testAnEmptyRequestBodyIsIncomplete(): void {
		$this->signIn();

		$response = $this->controller()->registerLegacyPath();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			['message' => 'caseId or termijnInstanceId, receiptDate and notificationChannel are required'],
			$response->getData()
		);
	}//end testAnEmptyRequestBodyIsIncomplete()

	/**
	 * A receipt date that is not a date is a 400.
	 *
	 * @return void
	 */
	public function testAReceiptDateThatIsNotADateIsRefused(): void {
		$this->signIn();

		$response = $this->controller(
			body: ['caseId' => 'case-1', 'notificationChannel' => 'post', 'receiptDate' => 'not a date at all']
		)->register();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'receiptDate is not a date'], $response->getData());
	}//end testAReceiptDateThatIsNotADateIsRefused()

	/**
	 * A failing term lookup is logged and answered as "no decision term" (404).
	 *
	 * @return void
	 */
	public function testAFailingTermLookupIsNotFound(): void {
		$this->signIn();
		$this->service->method('statutoryTermOf')->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())->method('info')
			->with('Notice of default: term lookup failed: register down');
		$this->service->expects($this->never())->method('registerNotice');

		$response = $this->controller(
			body: ['caseId' => 'case-1', 'notificationChannel' => 'post', 'receiptDate' => '2026-03-01']
		)->register();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'This case has no decision term to give notice on'], $response->getData());
	}//end testAFailingTermLookupIsNotFound()

	/**
	 * A failing store is logged and answered 400 with the failure's message.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsABadRequest(): void {
		$this->signIn();
		$this->service->method('statutoryTermOf')->with('case-1')
			->willReturn(['id' => 'term-1', 'case' => 'case-1']);
		$this->guard->method('hasCaseMutationAccess')->willReturn(true);
		$this->service->method('prematureReason')->willReturn('');
		$this->service->expects($this->once())->method('registerNotice')
			->willThrowException(new RuntimeException('schema missing'));
		$this->logger->expects($this->once())->method('info')
			->with('Ingebrekestelling register failed: schema missing');

		$response = $this->controller(
			body: ['caseId' => 'case-1', 'notificationChannel' => 'post', 'receiptDate' => '2026-03-01']
		)->register();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'schema missing'], $response->getData());
	}//end testAFailingStoreIsABadRequest()

	/**
	 * Showing without a session is a 403.
	 *
	 * @return void
	 */
	public function testShowWithoutASessionIsForbidden(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->settings->expects($this->never())->method('getObjectService');

		$response = $this->controller()->show(id: 'n-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Not authenticated'], $response->getData());
	}//end testShowWithoutASessionIsForbidden()

	/**
	 * Without an object service the show is a 503.
	 *
	 * @return void
	 */
	public function testShowWithoutAnObjectServiceIsUnavailable(): void {
		$this->signIn();
		$this->configure(objectService: null);

		$response = $this->controller()->show(id: 'n-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['message' => 'Service unavailable'], $response->getData());
	}//end testShowWithoutAnObjectServiceIsUnavailable()

	/**
	 * Without the notice schema configured the show is a 503 too.
	 *
	 * @return void
	 */
	public function testShowWithoutTheSchemaIsUnavailable(): void {
		$this->signIn();
		$this->settings->method('getObjectService')->willReturn($this->objectService(fn () => []));
		$this->settings->method('getConfigValue')->willReturnMap([
			['register', '', '5'],
			[MoneyConfigKeys::NOTICE_OF_DEFAULT_SCHEMA, '', ''],
		]);

		$response = $this->controller()->show(id: 'n-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
	}//end testShowWithoutTheSchemaIsUnavailable()

	/**
	 * The stored notice is found in the configured register and schema and returned.
	 *
	 * @return void
	 */
	public function testShowReturnsTheStoredNotice(): void {
		$this->signIn();
		$seen = [];
		$this->configure(objectService: $this->objectService(
			function (string $id, int|string $register, int|string $schema) use (&$seen) {
				$seen = [$id, $register, $schema];
				return ['id' => 'n-1', 'notificationChannel' => 'post'];
			}
		));

		$response = $this->controller()->showLegacyPath(id: 'n-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'n-1', 'notificationChannel' => 'post'], $response->getData());
		$this->assertSame(['n-1', '5', '9'], $seen);
	}//end testShowReturnsTheStoredNotice()

	/**
	 * A lookup that throws, or finds something that is not an object row, is a 404.
	 *
	 * @return void
	 */
	public function testShowOfAMissingNoticeIsNotFound(): void {
		$this->signIn();
		$this->configure(objectService: $this->objectService(
			function (string $id) {
				if ($id === 'broken') {
					throw new RuntimeException('boom');
				}

				return 'not a row';
			}
		));

		$broken = $this->controller()->show(id: 'broken');
		$missing = $this->controller()->show(id: 'n-404');

		$this->assertSame(Http::STATUS_NOT_FOUND, $broken->getStatus());
		$this->assertSame(['message' => 'Not found'], $broken->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Not found'], $missing->getData());
	}//end testShowOfAMissingNoticeIsNotFound()
}//end class
