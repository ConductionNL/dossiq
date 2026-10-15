<?php

/**
 * Tests for RoutedCaseAcceptanceListener: the assignee's first move or edit accepts.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateTime;
use OCA\Dossiq\Listener\RoutedCaseAcceptanceListener;
use OCA\Dossiq\Service\Routing\TakeBackWindow;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A case store that records patches.
 */
class AcceptanceCaseStore {

	/**
	 * Every patch, keyed by id.
	 *
	 * @var array<int, array{id: string, data: array<string, mixed>}>
	 */
	public array $patches = [];

	/**
	 * Patch one case.
	 *
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The fields.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function patchObject(string $objectId, array $data, string $register = '', string $schema = ''): array {
		$this->patches[] = ['id' => $objectId, 'data' => $data];
		return $data;
	}//end patchObject()
}//end class

/**
 * Who accepts a routed case, and what counts as accepting.
 *
 * @spec openspec/specs/role-based-step-routing/spec.md#requirement-work-not-taken-up-returns-to-the-pool-req-rtp-03
 */
class RoutedCaseAcceptanceListenerTest extends TestCase {

	/**
	 * The routing record of a case routed to aad and not yet accepted.
	 *
	 * @var array<string, mixed>
	 */
	private const ROUTING = ['rule' => ['strategy' => 'round-robin'], 'routedTo' => 'aad', 'routedAt' => '2026-10-12T09:00:00+02:00'];

	/**
	 * The store.
	 *
	 * @var AcceptanceCaseStore
	 */
	private AcceptanceCaseStore $store;

	/**
	 * The window.
	 *
	 * @var TakeBackWindow&MockObject
	 */
	private TakeBackWindow&MockObject $window;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store  = new AcceptanceCaseStore();
		$this->window = $this->getMockBuilder(TakeBackWindow::class)->disableOriginalConstructor()->onlyMethods(['cancel'])->getMock();
	}//end setUp()

	/**
	 * The listener with this user signed in.
	 *
	 * @param string|null $uid The signed-in user, or null.
	 *
	 * @return RoutedCaseAcceptanceListener
	 */
	private function listener(?string $uid): RoutedCaseAcceptanceListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => '3'][$key] ?? '');
		$settings->method('getObjectService')->willReturn($this->store);

		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-10-13T08:30:00+02:00'));

		return new RoutedCaseAcceptanceListener(
			settingsService: $settings,
			window: $this->window,
			userSession: $session,
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * One case save.
	 *
	 * @param array<string, mixed> $after  The case after.
	 * @param array<string, mixed> $before The case before.
	 * @param string               $schema The schema id.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function save(array $after, array $before, string $schema = '3'): ObjectUpdatedEvent {
		$new = new ObjectEntity();
		$new->setUuid('case-1');
		$new->setSchema($schema);
		$new->setObject($after);
		$old = new ObjectEntity();
		$old->setUuid('case-1');
		$old->setSchema($schema);
		$old->setObject($before);

		return new ObjectUpdatedEvent($new, $old);
	}//end save()

	/**
	 * The assignee's first status move stamps the acceptance and cancels the window.
	 *
	 * @return void
	 */
	public function testTheAssigneesFirstStatusMoveAccepts(): void {
		$this->window->expects($this->once())->method('cancel')->with('case-1');

		$this->listener(uid: 'aad')->handle(
			event: $this->save(
				after: ['assignee' => 'aad', 'status' => 'st-2', 'routing' => self::ROUTING],
				before: ['assignee' => 'aad', 'status' => 'st-1', 'routing' => self::ROUTING]
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $this->store->patches);
		$this->assertSame(expected: 'case-1', actual: $this->store->patches[0]['id']);
		$this->assertSame(
			expected: self::ROUTING + ['acceptedAt' => '2026-10-13T08:30:00+02:00'],
			actual: $this->store->patches[0]['data']['routing']
		);
	}//end testTheAssigneesFirstStatusMoveAccepts()

	/**
	 * Any other edit by the assignee accepts too; a new field counts as an edit.
	 *
	 * @return void
	 */
	public function testAnEditByTheAssigneeAccepts(): void {
		$this->window->expects($this->once())->method('cancel');

		$this->listener(uid: 'aad')->handle(
			event: $this->save(
				after: ['assignee' => 'aad', 'description' => 'Gebeld met de aanvrager', 'routing' => self::ROUTING],
				before: ['assignee' => 'aad', 'routing' => self::ROUTING]
			)
		);

		$this->assertCount(expectedCount: 1, haystack: $this->store->patches);
	}//end testAnEditByTheAssigneeAccepts()

	/**
	 * Saves that are not the routed person's first edit accept nothing.
	 *
	 * @return void
	 */
	public function testOtherSavesAcceptNothing(): void {
		$this->window->expects($this->never())->method('cancel');

		// Somebody else edits the case.
		$this->listener(uid: 'carla')->handle(
			event: $this->save(after: ['assignee' => 'aad', 'status' => 'st-2', 'routing' => self::ROUTING], before: ['assignee' => 'aad', 'status' => 'st-1', 'routing' => self::ROUTING])
		);
		// A background save, nobody signed in.
		$this->listener(uid: null)->handle(
			event: $this->save(after: ['assignee' => 'aad', 'status' => 'st-2', 'routing' => self::ROUTING], before: ['assignee' => 'aad', 'status' => 'st-1', 'routing' => self::ROUTING])
		);
		// The router's own write: only the routing and the assignee moved.
		$this->listener(uid: 'aad')->handle(
			event: $this->save(
				after: ['assignee' => 'aad', 'routing' => self::ROUTING, '@self' => ['updated' => '2026-10-13T08:30:00+02:00']],
				before: ['assignee' => 'bea', 'routing' => ['routedTo' => 'bea'], '@self' => ['updated' => '2026-10-12T09:00:00+02:00']]
			)
		);
		// Already accepted.
		$this->listener(uid: 'aad')->handle(
			event: $this->save(
				after: ['assignee' => 'aad', 'status' => 'st-3', 'routing' => self::ROUTING + ['acceptedAt' => '2026-10-12T10:00:00+02:00']],
				before: ['assignee' => 'aad', 'status' => 'st-2', 'routing' => self::ROUTING + ['acceptedAt' => '2026-10-12T10:00:00+02:00']]
			)
		);
		// A case nobody routed, and another schema.
		$this->listener(uid: 'aad')->handle(event: $this->save(after: ['assignee' => 'aad', 'status' => 'st-2'], before: ['assignee' => 'aad', 'status' => 'st-1']));
		$this->listener(uid: 'aad')->handle(
			event: $this->save(after: ['assignee' => 'aad', 'status' => 'st-2', 'routing' => self::ROUTING], before: ['assignee' => 'aad', 'status' => 'st-1', 'routing' => self::ROUTING], schema: '9')
		);

		$this->assertSame(expected: [], actual: $this->store->patches);
	}//end testOtherSavesAcceptNothing()
}//end class
