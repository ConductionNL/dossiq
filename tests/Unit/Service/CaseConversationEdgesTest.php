<?php

/**
 * Case conversation edges test
 *
 * Who moderates a new room (the starter, then the case's assignee, once),
 * what availability answers, a room Talk would not make, the end of a
 * conversation on a case that is gone, and that ending one conversation
 * leaves the case's other conversations as they were.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Conversation\CaseConversationService;
use OCA\Dossiq\Service\Conversation\CaseRecordStore;
use OCA\Dossiq\Service\Conversation\TalkConversationBroker;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Service\Conversation\CaseConversationService
 * @uses   \OCA\Dossiq\Service\Conversation\CaseRecordStore
 */
class CaseConversationEdgesTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The Talk broker.
	 *
	 * @var TalkConversationBroker&MockObject
	 */
	private $broker;

	/**
	 * The service.
	 *
	 * @var CaseConversationService
	 */
	private CaseConversationService $service;

	/**
	 * A case with an assignee and two conversations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: [
				'title' => 'Kapvergunning',
				'assignee' => 'bram',
				'conversations' => [
					['kind' => 'conversation', 'roomId' => 'room-1', 'participants' => [], 'durationSeconds' => 0, 'endedAt' => ''],
					['kind' => 'conversation', 'roomId' => 'room-2', 'participants' => [], 'durationSeconds' => 0, 'endedAt' => ''],
				],
			],
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case', 'case_type_schema' => 'caseType'][$key] ?? ''
		);

		$this->broker = $this->createMock(TalkConversationBroker::class);
		$this->service = new CaseConversationService(
			cases: new CaseRecordStore(settingsService: $settings),
			broker: $this->broker,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * Availability follows the broker.
	 *
	 * @return void
	 */
	public function testAvailabilityFollowsTalk(): void {
		$this->broker->method('isAvailable')->willReturnOnConsecutiveCalls(true, false);

		$this->assertSame(['available' => true, 'reason' => null], $this->service->availability());
		$this->assertSame(['available' => false, 'reason' => CaseConversationService::REASON_NO_TALK], $this->service->availability());
	}//end testAvailabilityFollowsTalk()

	/**
	 * The starter moderates, then the assignee, never twice.
	 *
	 * @return void
	 */
	public function testTheStarterAndTheAssigneeModerateOnce(): void {
		$this->broker->method('isAvailable')->willReturn(true);
		$calls = [];
		$this->broker->method('createRoom')->willReturnCallback(
			static function (string $name, array $moderators) use (&$calls): array {
				$calls[] = $moderators;
				return ['id' => 'room-' . count($calls) . '0', 'url' => 'u'];
			}
		);

		$this->service->startConversation(caseId: 'case-1', subject: 'Hoorzitting', startedBy: 'anna');
		$this->service->startConversation(caseId: 'case-1', subject: 'Hoorzitting', startedBy: 'bram');
		$this->service->startConversation(caseId: 'case-1', subject: 'Hoorzitting', startedBy: '');

		$this->assertSame([['anna', 'bram'], ['bram'], ['bram']], $calls);
	}//end testTheStarterAndTheAssigneeModerateOnce()

	/**
	 * A room Talk would not make records nothing on the case.
	 *
	 * @return void
	 */
	public function testARoomTalkWouldNotMakeRecordsNothing(): void {
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->method('createRoom')->willReturn(null);

		$this->assertSame(
			['ok' => false, 'reason' => CaseConversationService::REASON_NO_TALK],
			$this->service->startConversation(caseId: 'case-1', subject: null, startedBy: 'anna')
		);
		$this->assertCount(2, $this->register->row(schema: 'case', uuid: 'case-1')['conversations']);
	}//end testARoomTalkWouldNotMakeRecordsNothing()

	/**
	 * The end of a conversation on a case that is gone is refused as such.
	 *
	 * @return void
	 */
	public function testEndingOnAMissingCaseIsRefused(): void {
		$this->assertSame(
			['ok' => false, 'reason' => CaseConversationService::REASON_NO_CASE],
			$this->service->recordConversationEnd(caseId: 'case-gone', roomId: 'room-1', participants: ['anna'], durationSeconds: 60)
		);
	}//end testEndingOnAMissingCaseIsRefused()

	/**
	 * Ending one conversation leaves the other as it was, and a negative duration is zero.
	 *
	 * @return void
	 */
	public function testEndingOneConversationLeavesTheOther(): void {
		$result = $this->service->recordConversationEnd(
			caseId: 'case-1',
			roomId: 'room-2',
			participants: ['x' => 'anna', 'y' => 'burger'],
			durationSeconds: -5
		);

		$this->assertTrue($result['ok']);
		$this->assertSame(['anna', 'burger'], $result['conversation']['participants']);
		$this->assertSame(0, $result['conversation']['durationSeconds']);
		$this->assertNotSame('', $result['conversation']['endedAt']);

		$stored = $this->register->row(schema: 'case', uuid: 'case-1')['conversations'];
		$this->assertSame(['kind' => 'conversation', 'roomId' => 'room-1', 'participants' => [], 'durationSeconds' => 0, 'endedAt' => ''], $stored[0]);
		$this->assertSame($result['conversation'], $stored[1]);
	}//end testEndingOneConversationLeavesTheOther()
}//end class
