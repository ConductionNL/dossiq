<?php

/**
 * RoutingController characterisation tests.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\RoutingController;
use OCA\Dossiq\Controller\Support\AdminCaller;
use OCA\Dossiq\Controller\Support\CaseStepReroute;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseRouterInterface;
use OCA\Dossiq\Kernel\Contracts\RoleResolverServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every answer of reroute and route: the admin guard, the missing store, the
 * steps resolved against the case, the refusal and the failure.
 *
 * @covers \OCA\Dossiq\Controller\RoutingController
 * @uses   \OCA\Dossiq\Controller\Support\AdminCaller
 * @uses   \OCA\Dossiq\Controller\Support\CaseStepReroute
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Support\ObjectArray
 * @uses   \OCA\Dossiq\Service\Support\RowList
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class RoutingControllerTest extends TestCase {

	/**
	 * The request double.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The resolver double.
	 *
	 * @var RoleResolverServiceInterface&MockObject
	 */
	private RoleResolverServiceInterface&MockObject $resolver;

	/**
	 * The router double.
	 *
	 * @var CaseRouterInterface&MockObject
	 */
	private CaseRouterInterface&MockObject $router;

	/**
	 * The settings double.
	 *
	 * @var SettingsServiceInterface&MockObject
	 */
	private SettingsServiceInterface&MockObject $settings;

	/**
	 * The session double.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh doubles; the caller is a signed-in admin unless a test says otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request  = $this->createMock(originalClassName: IRequest::class);
		$this->resolver = $this->createMock(originalClassName: RoleResolverServiceInterface::class);
		$this->router   = $this->createMock(originalClassName: CaseRouterInterface::class);
		$this->settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$this->session  = $this->createMock(originalClassName: IUserSession::class);
		$this->groups   = $this->createMock(originalClassName: IGroupManager::class);
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);

		$this->settings->method('getConfigValue')->willReturnMap(
			valueMap: [
				['register', '', 'dossiq'],
				['case_schema', '', 'case'],
				['workflow_template_schema', '', 'workflowTemplate'],
			]
		);
		$this->signedIn(admin: true);
	}//end setUp()

	/**
	 * The controller over the doubles.
	 *
	 * @return RoutingController
	 */
	private function controller(): RoutingController {
		return new RoutingController(
			appName: 'dossiq',
			request: $this->request,
			reroute: new CaseStepReroute(settingsService: $this->settings, resolver: $this->resolver),
			router: $this->router,
			caller: new AdminCaller(userSession: $this->session, groupManager: $this->groups),
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Sign a user in, an admin or not.
	 *
	 * @param bool $admin Whether the user is an admin.
	 *
	 * @return void
	 */
	private function signedIn(bool $admin): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'pieter');
		$this->session->method('getUser')->willReturn(value: $user);
		$this->groups->method('isAdmin')->willReturnCallback(
			callback: static fn (string $uid): bool => $uid === 'pieter' && $admin
		);
	}//end signedIn()

	/**
	 * An object service answering the given objects by id, recording each find.
	 *
	 * @param array<string, mixed> $objects The objects by id; a Throwable is thrown.
	 *
	 * @return object
	 */
	private function objectService(array $objects): object {
		return new class($objects) {
			/**
			 * Each find, as [id, schema].
			 *
			 * @var list<array{0: string, 1: string}>
			 */
			public array $finds = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $objects The objects by id.
			 */
			public function __construct(private readonly array $objects) {
			}

			/**
			 * The object by id, or the failure stored under it.
			 *
			 * @param string $id The id.
			 * @param ObjectFindOptions $options Where to look.
			 *
			 * @return mixed
			 */
			public function find(string $id, ObjectFindOptions $options): mixed {
				$this->finds[] = [$id, (string)$options->schema];
				$object = $this->objects[$id] ?? null;
				if ($object instanceof \Throwable) {
					throw $object;
				}

				return $object;
			}
		};
	}//end objectService()

	/**
	 * Without a session reroute and route answer 401; a non-admin gets 403.
	 *
	 * @return void
	 */
	public function testOnlyASignedInAdminMayRoute(): void {
		$anonymous = $this->createMock(originalClassName: IUserSession::class);
		$anonymous->method('getUser')->willReturn(value: null);
		$this->session = $anonymous;

		self::assertSame(expected: 401, actual: $this->controller()->reroute(id: 'c-1')->getStatus());
		self::assertSame(expected: ['error' => 'Authenticatie vereist'], actual: $this->controller()->route(id: 'c-1')->getData());

		$this->session = $this->createMock(originalClassName: IUserSession::class);
		$this->groups  = $this->createMock(originalClassName: IGroupManager::class);
		$this->signedIn(admin: false);
		$this->settings->expects($this->never())->method('getObjectService');

		$refused = $this->controller()->reroute(id: 'c-1');
		self::assertSame(expected: 403, actual: $refused->getStatus());
		self::assertSame(expected: ['error' => 'Admin-rechten op de zaak vereist'], actual: $refused->getData());
		self::assertSame(expected: 403, actual: $this->controller()->route(id: 'c-1')->getStatus());
	}//end testOnlyASignedInAdminMayRoute()

	/**
	 * Without OpenRegister reroute answers 503.
	 *
	 * @return void
	 */
	public function testRerouteWithoutOpenRegisterAnswers503(): void {
		$this->settings->method('getObjectService')->willReturn(value: null);

		$answer = $this->controller()->reroute(id: 'c-1');

		self::assertSame(expected: 503, actual: $answer->getStatus());
		self::assertSame(expected: ['error' => 'OpenRegister is niet beschikbaar'], actual: $answer->getData());
	}//end testRerouteWithoutOpenRegisterAnswers503()

	/**
	 * Every step with a rule is resolved against the case; a step without one is left out.
	 *
	 * @return void
	 */
	public function testRerouteResolvesEveryStepThatCarriesARule(): void {
		$case    = ['id' => 'c-1', 'workflowTemplate' => 'wf-1'];
		$service = $this->objectService(
			objects: [
				'c-1' => $case,
				'wf-1' => ['steps' => '[{"id":"s-1","order":2,"rule":"r"},{"id":"s-2","order":3}]'],
			]
		);
		$this->settings->method('getObjectService')->willReturn(value: $service);
		$this->resolver->method('normaliseRule')->willReturnCallback(
			callback: static fn (array $step): ?array => [null, ['roleType' => 'behandelaar']][(int)array_key_exists('rule', $step)]
		);
		$this->resolver->expects($this->once())->method('resolve')
			->with(['roleType' => 'behandelaar'], $case)
			->willReturn(value: ['anna']);

		$answer = $this->controller()->reroute(id: 'c-1');

		self::assertSame(expected: 200, actual: $answer->getStatus());
		self::assertSame(
			expected: ['caseId' => 'c-1', 'affectedSteps' => [['stepId' => 's-1', 'order' => 2, 'assignees' => ['anna']]]],
			actual: $answer->getData()
		);
		self::assertSame(expected: [['c-1', 'case'], ['wf-1', 'workflowTemplate']], actual: $service->finds);
	}//end testRerouteResolvesEveryStepThatCarriesARule()

	/**
	 * A case on no workflow has no steps, and no workflow is read.
	 *
	 * @return void
	 */
	public function testACaseWithoutAWorkflowHasNoSteps(): void {
		$service = $this->objectService(objects: ['c-1' => ['id' => 'c-1']]);
		$this->settings->method('getObjectService')->willReturn(value: $service);

		$answer = $this->controller()->reroute(id: 'c-1');

		self::assertSame(expected: ['caseId' => 'c-1', 'affectedSteps' => []], actual: $answer->getData());
		self::assertSame(expected: [['c-1', 'case']], actual: $service->finds);
	}//end testACaseWithoutAWorkflowHasNoSteps()

	/**
	 * A failed read answers 500 and logs the case and the message.
	 *
	 * @return void
	 */
	public function testAFailedRerouteAnswers500AndLogs(): void {
		$this->settings->method('getObjectService')->willReturn(
			value: $this->objectService(objects: ['c-1' => new RuntimeException('gone')])
		);
		$this->logger->expects($this->once())->method('error')
			->with('Dossiq: reroute failed for case c-1: gone');

		$answer = $this->controller()->reroute(id: 'c-1');

		self::assertSame(expected: 500, actual: $answer->getStatus());
		self::assertSame(expected: ['error' => 'Herberekening mislukt'], actual: $answer->getData());
	}//end testAFailedRerouteAnswers500AndLogs()

	/**
	 * A refusal from the resolver keeps its own status and rule.
	 *
	 * @return void
	 */
	public function testARefusalKeepsItsStatus(): void {
		$this->settings->method('getObjectService')->willReturn(
			value: $this->objectService(
				objects: ['c-1' => ['id' => 'c-1', 'workflowTemplate' => 'wf-1'], 'wf-1' => ['steps' => [['id' => 's-1']]]]
			)
		);
		$this->resolver->method('normaliseRule')->willReturn(value: ['roleType' => 'x']);
		$this->resolver->method('resolve')->willThrowException(
			exception: new RefusedException(rule: 'roles-unreadable', sentence: '', status: RefusedException::STATUS_INDETERMINATE)
		);

		$answer = $this->controller()->reroute(id: 'c-1');

		self::assertSame(expected: 503, actual: $answer->getStatus());
		self::assertSame(expected: 'roles-unreadable', actual: $answer->getData()['error']);
	}//end testARefusalKeepsItsStatus()

	/**
	 * Route needs a strategy, answers the router, and a failure is a logged 500.
	 *
	 * @return void
	 */
	public function testRouteNeedsAStrategyAndAnswersTheRouter(): void {
		$this->request->method('getParams')->willReturnOnConsecutiveCalls(
			['strategy' => ' '],
			['strategy' => 'weight'],
			['strategy' => 'weight']
		);
		$this->router->method('route')->willReturnOnConsecutiveCalls(
			['caseId' => 'c-1', 'assignee' => 'anna'],
			$this->throwException(exception: new RuntimeException('down'))
		);
		$this->logger->expects($this->once())->method('error')->with('Dossiq: routing failed for case c-1: down');

		$missing = $this->controller()->route(id: 'c-1');
		self::assertSame(expected: 400, actual: $missing->getStatus());

		self::assertSame(expected: ['caseId' => 'c-1', 'assignee' => 'anna'], actual: $this->controller()->route(id: 'c-1')->getData());

		$failed = $this->controller()->route(id: 'c-1');
		self::assertSame(expected: 500, actual: $failed->getStatus());
		self::assertSame(expected: ['error' => 'Routeren mislukt'], actual: $failed->getData());
	}//end testRouteNeedsAStrategyAndAnswersTheRouter()
}//end class
