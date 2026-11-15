<?php

/**
 * BelplanController list and admin tests.
 *
 * Pins `index`, `create` and `update`: who may call them, what they answer
 * when the belplan schema is not configured, which fields a new belplan gets
 * and which a change sends. The object service is a recording double; the
 * settings, session and group manager are mocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\BelplanController;
use OCA\Dossiq\Service\BelplanRoutingService;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Controller\BelplanController
 * @uses   \OCA\Dossiq\Service\Support\CastArray
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class BelplanControllerAdminTest extends TestCase {

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The controller under test.
	 *
	 * @var BelplanController
	 */
	private BelplanController $controller;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->params   = [];
		$this->settings = $this->createMock(SettingsService::class);
		$this->session  = $this->createMock(IUserSession::class);
		$this->groups   = $this->createMock(IGroupManager::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, mixed $default = null): mixed => (array_key_exists($key, $this->params) === true ? $this->params[$key] : $default)
		);

		$this->controller = new BelplanController(
			appName: 'dossiq',
			request: $request,
			routingService: $this->createMock(BelplanRoutingService::class),
			settingsService: $this->settings,
			userSession: $this->session,
			groupManager: $this->groups,
		);

	}//end setUp()

	/**
	 * Sign a user in, admin or not.
	 *
	 * @param bool $admin Whether the user is an administrator.
	 *
	 * @return void
	 */
	private function signIn(bool $admin): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$this->session->method('getUser')->willReturn($user);
		$this->groups->method('isAdmin')->with('beheerder')->willReturn($admin);

	}//end signIn()

	/**
	 * Configure the register and the belplan schema, and the object service.
	 *
	 * @param object|null $objects The object service.
	 *
	 * @return void
	 */
	private function configured(?object $objects): void {
		$this->settings->method('getObjectService')->willReturn($objects);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['belplan_schema', '', 'belplan'],
			]
		);

	}//end configured()

	/**
	 * An object service that records its calls.
	 *
	 * @param mixed           $answer What each call answers.
	 * @param \Throwable|null $throws What each call throws.
	 *
	 * @return object
	 */
	private function objects(mixed $answer = [], ?\Throwable $throws = null): object {
		return new class($answer, $throws) {
			/**
			 * @var array<int, array<int|string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param mixed           $answer What each call answers.
			 * @param \Throwable|null $throws What each call throws.
			 */
			public function __construct(private mixed $answer, private ?\Throwable $throws) {
			}

			/**
			 * @return mixed
			 */
			private function answer(array $call): mixed {
				$this->calls[] = $call;
				if ($this->throws !== null) {
					throw $this->throws;
				}

				return $this->answer;
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters): mixed {
				return $this->answer(['searchObjectsBySlug', $register, $schema, $filters]);
			}

			public function saveObject(array $object, string $register, string $schema): mixed {
				return $this->answer(['saveObject', $object, $register, $schema]);
			}

			public function patchObject(string $objectId, array $data, string $register, string $schema): mixed {
				return $this->answer(['patchObject', $objectId, $data, $register, $schema]);
			}
		};

	}//end objects()

	public function testIndexRefusesACallerWithoutASession(): void {
		$this->settings->expects($this->never())->method('getObjectService');

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'Not authenticated'], $response->getData());

	}//end testIndexRefusesACallerWithoutASession()

	public function testIndexListsTheBelplannenAsArrays(): void {
		$this->signIn(admin: false);
		$entity = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['id' => 'b2', 'name' => 'Avond'];
			}
		};
		$objects = $this->objects([['id' => 'b1', 'name' => 'Dag'], $entity]);
		$this->configured($objects);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['belplannen' => [['id' => 'b1', 'name' => 'Dag'], ['id' => 'b2', 'name' => 'Avond']]], $response->getData());
		$this->assertSame([['searchObjectsBySlug', 'dossiq', 'belplan', ['_limit' => 200]]], $objects->calls);

	}//end testIndexListsTheBelplannenAsArrays()

	public function testIndexIsEmptyWhenNotConfiguredOrTheReadFails(): void {
		$this->signIn(admin: false);
		$this->settings->method('getObjectService')->willReturnOnConsecutiveCalls(null, $this->objects([], new RuntimeException('down')));
		$this->settings->method('getConfigValue')->willReturnMap([['register', '', 'dossiq'], ['belplan_schema', '', 'belplan']]);

		$this->assertSame(['belplannen' => []], $this->controller->index()->getData());
		$this->assertSame(['belplannen' => []], $this->controller->index()->getData());

	}//end testIndexIsEmptyWhenNotConfiguredOrTheReadFails()

	public function testCreateAndUpdateRefuseANonAdministrator(): void {
		$this->signIn(admin: false);
		$this->settings->expects($this->never())->method('getObjectService');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->create()->getStatus());
		$this->assertSame(['error' => 'Forbidden'], $this->controller->update('b1')->getData());

	}//end testCreateAndUpdateRefuseANonAdministrator()

	public function testCreateAndUpdateRefuseWithoutASession(): void {
		$this->settings->expects($this->never())->method('getObjectService');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->create()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->update('b1')->getStatus());

	}//end testCreateAndUpdateRefuseWithoutASession()

	public function testCreateAndUpdateNeedTheBelplanSchema(): void {
		$this->signIn(admin: true);
		$this->settings->method('getObjectService')->willReturn($this->objects());
		$this->settings->method('getConfigValue')->willReturn('');

		foreach ([$this->controller->create(), $this->controller->update('b1')] as $response) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'Belplan schema not configured'], $response->getData());
		}

	}//end testCreateAndUpdateNeedTheBelplanSchema()

	public function testCreateNeedsAName(): void {
		$this->signIn(admin: true);
		$objects = $this->objects();
		$this->configured($objects);
		$this->params = ['name' => '   '];

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'naam is required'], $response->getData());
		$this->assertSame([], $objects->calls);

	}//end testCreateNeedsAName()

	public function testCreateStoresTheBelplanWithItsDefaults(): void {
		$this->signIn(admin: true);
		$objects = $this->objects(['id' => 'b9', 'name' => 'Vergunningen']);
		$this->configured($objects);
		$this->params = ['name' => 'Vergunningen', 'triggerNumber' => '14 013', 'priority' => '3'];

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'b9', 'name' => 'Vergunningen'], $response->getData());
		$this->assertSame(
			[[
				'saveObject',
				[
					'name' => 'Vergunningen',
					'triggerNumber' => ['14 013'],
					'routingSteps' => [],
					'openingHours' => '',
					'fallbackAction' => 'voicemail',
					'priority' => 3,
					'isActive' => true,
				],
				'dossiq',
				'belplan',
			]],
			$objects->calls
		);

	}//end testCreateStoresTheBelplanWithItsDefaults()

	public function testUpdateSendsOnlyTheFieldsGiven(): void {
		$this->signIn(admin: true);
		$objects = $this->objects(['id' => 'b1', 'isActive' => false]);
		$this->configured($objects);
		$this->params = ['isActive' => false, 'priority' => 5, 'unknown' => 'x'];

		$response = $this->controller->update('b1');

		$this->assertSame(['id' => 'b1', 'isActive' => false], $response->getData());
		$this->assertSame([['patchObject', 'b1', ['priority' => 5, 'isActive' => false], 'dossiq', 'belplan']], $objects->calls);

	}//end testUpdateSendsOnlyTheFieldsGiven()

	public function testAFailingUpdateAnswers500(): void {
		$this->signIn(admin: true);
		$this->configured($this->objects([], new RuntimeException('locked')));
		$this->params = ['name' => 'Nieuw'];

		$response = $this->controller->update('b1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not update belplan'], $response->getData());

	}//end testAFailingUpdateAnswers500()
}//end class
