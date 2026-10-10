<?php

/**
 * Tests for keeping the advice timer in step with the saved request.
 *
 * The case that would hurt is the re-arm on every save: an advice request is
 * saved for many reasons (a note, a document), and re-arming on each one
 * would rewrite the timer's history with a cancel and an arm per save. So a
 * save that moved neither the status nor the deadline is asserted to leave
 * the timer alone.
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\AdviceTimerListener;
use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Created and updated advice requests, and everything else left alone.
 */
class AdviceTimerListenerTest extends TestCase {

	/**
	 * The timer service.
	 *
	 * @var AdviceTimer&MockObject
	 */
	private AdviceTimer&MockObject $timer;

	/**
	 * The advice service, for the overdue expiry.
	 *
	 * @var AdviceService&MockObject
	 */
	private AdviceService&MockObject $advice;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timer  = $this->createMock(AdviceTimer::class);
		$this->advice = $this->createMock(AdviceService::class);
	}//end setUp()

	/**
	 * The listener, with the adviesAanvraag schema configured as id 42.
	 *
	 * @return AdviceTimerListener
	 */
	private function listener(): AdviceTimerListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')
			->willReturnCallback(static fn (string $key): string => $key === 'advies_aanvraag_schema' ? '42' : '');

		return new AdviceTimerListener(
			settingsService: $settings,
			timer: $this->timer,
			adviceService: $this->advice,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * One stored object.
	 *
	 * @param array<string, mixed> $data   Its fields.
	 * @param string               $schema Its schema id.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $schema = '42'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('adv-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A new request is synced.
	 *
	 * @return void
	 */
	public function testANewRequestIsSynced(): void {
		$this->timer->expects($this->once())->method('sync')
			->with($this->callback(static fn (array $a): bool => $a['id'] === 'adv-1' && $a['deadline'] === '2026-10-20'))
			->willReturn(AdviceTimer::ARMED);

		$this->listener()->handle(
			event: new ObjectCreatedEvent($this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-20']))
		);
	}//end testANewRequestIsSynced()

	/**
	 * A moved deadline re-syncs; a closed request re-syncs.
	 *
	 * @return void
	 */
	public function testAMovedDeadlineOrStatusResyncs(): void {
		$this->timer->expects($this->exactly(2))->method('sync')->willReturn(AdviceTimer::ARMED);
		$listener = $this->listener();

		$listener->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-27']),
				$this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-20'])
			)
		);
		$listener->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['status' => 'received', 'deadline' => '2026-10-20']),
				$this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-20'])
			)
		);
	}//end testAMovedDeadlineOrStatusResyncs()

	/**
	 * A save that moved neither leaves the timer alone.
	 *
	 * @return void
	 */
	public function testAnUnrelatedSaveLeavesTheTimerAlone(): void {
		$this->timer->expects($this->never())->method('sync');

		$this->listener()->handle(
			event: new ObjectUpdatedEvent(
				$this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-20', 'subject' => 'Nieuw']),
				$this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-20', 'subject' => 'Oud'])
			)
		);
	}//end testAnUnrelatedSaveLeavesTheTimerAlone()

	/**
	 * Another schema's object is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$this->timer->expects($this->never())->method('sync');

		$this->listener()->handle(
			event: new ObjectCreatedEvent($this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-20'], schema: '7'))
		);
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * A request saved with a deadline already past is expired now.
	 *
	 * @return void
	 */
	public function testAnOverdueRequestIsExpiredNow(): void {
		$this->timer->method('sync')->willReturn(AdviceTimer::OVERDUE);
		$this->advice->expects($this->once())->method('expireAdvice')->with('adv-1');

		$this->listener()->handle(
			event: new ObjectCreatedEvent($this->entity(data: ['status' => 'requested', 'deadline' => '2026-10-01']))
		);
	}//end testAnOverdueRequestIsExpiredNow()
}//end class
