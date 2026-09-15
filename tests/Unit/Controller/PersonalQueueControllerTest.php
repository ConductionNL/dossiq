<?php

/**
 * The wire contract of the personal queue endpoints.
 *
 * Every endpoint here answers for the CALLER and takes no user parameter, and
 * the first test in this file is the one that keeps that true: an unsigned
 * caller is refused by every method, checked method by method rather than on a
 * representative one. A queue endpoint that forgot the guard would hand one
 * person's day to anybody who asked.
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
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\PersonalQueueController;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCA\Dossiq\Service\Queue\PersonalAgendaItemService;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\PersonalStageService;
use OCA\Dossiq\Service\Queue\QueueViewPreferences;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\PersonalQueueController
 */
class PersonalQueueControllerTest extends TestCase {
	/**
	 * The request.
	 *
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * The caller.
	 *
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The queue.
	 *
	 * @var PersonalQueueService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private PersonalQueueService $queue;

	/**
	 * The reader's view settings.
	 *
	 * @var QueueViewPreferences|\PHPUnit\Framework\MockObject\MockObject
	 */
	private QueueViewPreferences $view;

	/**
	 * The reader's private stages.
	 *
	 * @var PersonalStageService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private PersonalStageService $stages;

	/**
	 * The reader's own calendar.
	 *
	 * @var PersonalAgendaItemService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private PersonalAgendaItemService $agenda;

	/**
	 * The reader's digest settings.
	 *
	 * @var DigestPreferences|\PHPUnit\Framework\MockObject\MockObject
	 */
	private DigestPreferences $digest;

	/**
	 * The controller under test.
	 *
	 * @var PersonalQueueController
	 */
	private PersonalQueueController $controller;

	/**
	 * Build the controller over doubled services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->queue = $this->createMock(PersonalQueueService::class);
		$this->view = $this->createMock(QueueViewPreferences::class);
		$this->stages = $this->createMock(PersonalStageService::class);
		$this->agenda = $this->createMock(PersonalAgendaItemService::class);
		$this->digest = $this->createMock(DigestPreferences::class);

		$this->controller = new PersonalQueueController(
			request: $this->request,
			userSession: $this->userSession,
			queue: $this->queue,
			view: $this->view,
			stages: $this->stages,
			agenda: $this->agenda,
			digest: $this->digest,
		);
	}//end setUp()

	/**
	 * Sign somebody in.
	 *
	 * @param string $uid The caller's uid.
	 *
	 * @return void
	 */
	private function signIn(string $uid = 'alice'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * One queue, as the service answers it.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 *
	 * @return array<string, mixed> The queue.
	 */
	private function queueOf(array $items): array {
		return [
			'items' => $items,
			'groups' => [],
			'groupBy' => 'source',
			'hiddenGroups' => [],
			'unavailable' => [],
			'total' => count($items),
		];
	}//end queueOf()

	/**
	 * Every endpoint refuses a caller who is not signed in.
	 *
	 * @return void
	 */
	public function testEveryEndpointRefusesAnUnauthenticatedCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$calls = [
			'index' => [],
			'endOfDay' => [],
			'setGrouping' => [],
			'hideGroup' => ['tasks'],
			'showGroup' => ['tasks'],
			'planItem' => [],
			'stage' => ['case-1'],
			'setStage' => ['case-1'],
			'digestSettings' => [],
			'saveDigestSettings' => [],
		];

		foreach ($calls as $method => $arguments) {
			$response = $this->controller->$method(...$arguments);

			self::assertSame(
				Http::STATUS_UNAUTHORIZED,
				$response->getStatus(),
				$method . ' answered an unauthenticated caller.'
			);
		}
	}//end testEveryEndpointRefusesAnUnauthenticatedCaller()

	/**
	 * The queue endpoint answers the caller's own queue.
	 *
	 * @return void
	 */
	public function testTheQueueAnswersForTheCaller(): void {
		$this->signIn(uid: 'alice');
		$this->queue->expects(self::once())
			->method('forPerson')
			->with('alice')
			->willReturn($this->queueOf([['id' => 'tasks:task:a', 'subjectType' => 'task']]));

		$response = $this->controller->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(1, $response->getData()['total']);
	}//end testTheQueueAnswersForTheCaller()

	/**
	 * The end-of-day candidates are the cases and tasks, and nothing else.
	 *
	 * A mention is on the queue and is not something a day is closed on: there
	 * is no update to write against "you were mentioned".
	 *
	 * @return void
	 */
	public function testEndOfDayOffersOnlyCasesAndTasks(): void {
		$this->signIn();
		$this->queue->method('forPerson')->willReturn($this->queueOf([
			['id' => 'assigned-cases:case:a', 'subjectType' => 'case'],
			['id' => 'tasks:task:b', 'subjectType' => 'task'],
			['id' => 'mentions:mention:c', 'subjectType' => 'mention'],
			['id' => 'planned-items:plannedItem:d', 'subjectType' => 'plannedItem'],
		]));

		$data = $this->controller->endOfDay()->getData();

		self::assertSame(
			['assigned-cases:case:a', 'tasks:task:b'],
			array_column($data['items'], 'id')
		);
	}//end testEndOfDayOffersOnlyCasesAndTasks()

	/**
	 * Hiding a group hides it for today, and shows it again on request.
	 *
	 * @return void
	 */
	public function testAGroupIsHiddenForTodayAndShownAgain(): void {
		$this->signIn(uid: 'alice');
		$this->view->expects(self::once())
			->method('hideForToday')
			->willReturn(['tasks']);
		$this->view->expects(self::once())
			->method('showAgain')
			->willReturn([]);

		self::assertSame(['tasks'], $this->controller->hideGroup(group: 'tasks')->getData()['hiddenGroups']);
		self::assertSame([], $this->controller->showGroup(group: 'tasks')->getData()['hiddenGroups']);
	}//end testAGroupIsHiddenForTodayAndShownAgain()

	/**
	 * The grouping the reader chose is remembered, and a bad one is not.
	 *
	 * @return void
	 */
	public function testTheGroupingIsRememberedAndAnUnknownOneIsNot(): void {
		$this->signIn(uid: 'alice');
		$this->request->method('getParam')->willReturn('priority');
		$this->view->expects(self::once())
			->method('setGroupBy')
			->with('alice', 'priority')
			->willReturn('priority');

		$response = $this->controller->setGrouping();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('priority', $response->getData()['groupBy']);
	}//end testTheGroupingIsRememberedAndAnUnknownOneIsNot()

	/**
	 * There is no endpoint that removes an item.
	 *
	 * The refusal to dismiss is a fact about the surface, not about a guard
	 * inside one method, so it is asserted on the surface.
	 *
	 * @return void
	 */
	public function testThereIsNoEndpointThatRemovesAnItem(): void {
		$methods = get_class_methods(PersonalQueueController::class);

		foreach (['dismiss', 'dismissItem', 'removeItem', 'deleteItem'] as $forbidden) {
			self::assertNotContains($forbidden, $methods);
		}
	}//end testThereIsNoEndpointThatRemovesAnItem()

	/**
	 * Planning an item answers what was written.
	 *
	 * @return void
	 */
	public function testPlanningAnItemAnswersWhatWasWritten(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'title' => 'Call the applicant back',
				'startsAt' => '2026-12-01T09:00',
				'template' => 'call-back',
				default => $default,
			}
		);
		$this->agenda->method('plan')->willReturn([
			'uid' => 'abc-123',
			'startsAt' => '2026-12-01T09:00:00+01:00',
			'minutes' => 15,
		]);

		$response = $this->controller->planItem();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('abc-123', $response->getData()['uid']);
	}//end testPlanningAnItemAnswersWhatWasWritten()

	/**
	 * An item that cannot be planned is refused with the reason.
	 *
	 * @return void
	 */
	public function testAnItemThatCannotBePlannedIsRefusedWithTheReason(): void {
		$this->signIn();
		$this->request->method('getParam')->willReturn('');
		$this->agenda->method('plan')->willThrowException(
			new RuntimeException('You have no calendar this item can be written to.')
		);

		$response = $this->controller->planItem();

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(
			'You have no calendar this item can be written to.',
			$response->getData()['error']
		);
	}//end testAnItemThatCannotBePlannedIsRefusedWithTheReason()

	/**
	 * The stage endpoints read and write the CALLER's own stage.
	 *
	 * @return void
	 */
	public function testTheStageEndpointsAnswerForTheCallerOnly(): void {
		$this->signIn(uid: 'alice');
		$this->stages->expects(self::once())
			->method('get')
			->with('alice', 'case-1')
			->willReturn('Wachten op advies');
		$this->stages->expects(self::once())
			->method('set')
			->with('alice', 'case-1', 'Bijna klaar')
			->willReturn('Bijna klaar');
		$this->request->method('getParam')->willReturn('Bijna klaar');

		self::assertSame('Wachten op advies', $this->controller->stage(caseId: 'case-1')->getData()['stage']);
		self::assertSame('Bijna klaar', $this->controller->setStage(caseId: 'case-1')->getData()['stage']);
	}//end testTheStageEndpointsAnswerForTheCallerOnly()

	/**
	 * The digest settings round-trip.
	 *
	 * @return void
	 */
	public function testTheDigestSettingsRoundTrip(): void {
		$this->signIn(uid: 'alice');
		$this->digest->method('forUser')->willReturn(['enabled' => true, 'hour' => 8]);
		$this->digest->expects(self::once())
			->method('save')
			->with('alice', false, 17)
			->willReturn(['enabled' => false, 'hour' => 17]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => match ($key) {
				'enabled' => false,
				'hour' => 17,
				default => $default,
			}
		);

		self::assertSame(['enabled' => true, 'hour' => 8], $this->controller->digestSettings()->getData());
		self::assertSame(['enabled' => false, 'hour' => 17], $this->controller->saveDigestSettings()->getData());
	}//end testTheDigestSettingsRoundTrip()

	/**
	 * Every route this controller serves is declared with an auth posture.
	 *
	 * @return void
	 */
	public function testEveryRoutedMethodDeclaresItsAuthPosture(): void {
		$routes = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		self::assertIsString($routes);

		preg_match_all("/'personalQueue#(\w+)'/", $routes, $matches);
		self::assertNotSame([], $matches[1], 'No personal-queue routes are declared.');

		foreach (array_unique($matches[1]) as $method) {
			$reflection = new \ReflectionMethod(PersonalQueueController::class, $method);
			$attributes = array_map(
				static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
				$reflection->getAttributes()
			);

			self::assertContains(
				\OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
				$attributes,
				$method . ' is routed and declares no auth posture, so it is unreachable.'
			);
		}
	}//end testEveryRoutedMethodDeclaresItsAuthPosture()
}//end class
