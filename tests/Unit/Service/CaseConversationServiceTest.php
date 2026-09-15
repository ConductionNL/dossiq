<?php

/**
 * Tests for the case conversation service.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
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
 * A live conversation runs from any case, and the case records it.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class CaseConversationServiceTest extends TestCase {

	/**
	 * The stand-in OpenRegister store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The Talk seam.
	 *
	 * @var TalkConversationBroker|MockObject
	 */
	private TalkConversationBroker $broker;

	/**
	 * The service under test.
	 *
	 * @var CaseConversationService
	 */
	private CaseConversationService $service;

	/**
	 * Build the service over an in-memory register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Kapvergunning', 'identifier' => 'ZAAK-001', 'caseType' => 'type-1'],
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_type_schema' => 'caseType',
					default => '',
				};
			}
		);

		$this->broker = $this->createMock(TalkConversationBroker::class);

		$this->service = new CaseConversationService(
			cases: new CaseRecordStore(settingsService: $settings),
			broker: $this->broker,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A conversation starts from an ordinary case, and the room is linked to it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAConversationStartsFromAnOrdinaryCase(): void {
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->expects($this->once())
			->method('createRoom')
			->willReturn(['id' => 'room-9', 'url' => 'https://nc.test/call/room-9']);

		$result = $this->service->startConversation(caseId: 'case-1', subject: null, startedBy: 'anna');

		$this->assertTrue($result['ok']);
		$this->assertSame('https://nc.test/call/room-9', $result['conversation']['roomUrl']);

		$stored = $this->register->row(schema: 'case', uuid: 'case-1');
		$this->assertCount(1, $stored['conversations']);
		$this->assertSame('room-9', $stored['conversations'][0]['roomId']);
		$this->assertSame('anna', $stored['conversations'][0]['startedBy']);
	}//end testAConversationStartsFromAnOrdinaryCase()

	/**
	 * Without Talk there is no conversation, and the case says why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testNoTalkMeansNoAffordanceAndAStatedReason(): void {
		$this->broker->method('isAvailable')->willReturn(false);
		$this->broker->expects($this->never())->method('createRoom');

		$this->assertSame(
			['available' => false, 'reason' => CaseConversationService::REASON_NO_TALK],
			$this->service->availability()
		);

		$result = $this->service->startConversation(caseId: 'case-1');

		$this->assertFalse($result['ok']);
		$this->assertSame(CaseConversationService::REASON_NO_TALK, $result['reason']);
		$this->assertArrayNotHasKey('conversations', $this->register->row(schema: 'case', uuid: 'case-1'));
	}//end testNoTalkMeansNoAffordanceAndAStatedReason()

	/**
	 * When a conversation ends the case holds the moment, who joined and how long.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testTheCaseRecordsWhoWasHeardAndForHowLong(): void {
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->method('createRoom')->willReturn(['id' => 'room-9', 'url' => 'https://nc.test/call/room-9']);

		$this->service->startConversation(caseId: 'case-1', subject: 'Hoorzitting', startedBy: 'anna');

		$result = $this->service->recordConversationEnd(
			caseId: 'case-1',
			roomId: 'room-9',
			participants: ['anna', 'bram'],
			durationSeconds: 1800,
		);

		$this->assertTrue($result['ok']);

		$record = $this->register->row(schema: 'case', uuid: 'case-1')['conversations'][0];
		$this->assertSame(['anna', 'bram'], $record['participants']);
		$this->assertSame(1800, $record['durationSeconds']);
		$this->assertNotSame('', $record['startedAt']);
		$this->assertNotSame('', $record['endedAt']);
	}//end testTheCaseRecordsWhoWasHeardAndForHowLong()

	/**
	 * A conversation on a case nobody can read is refused, and writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testAnUnreadableCaseIsRefused(): void {
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->expects($this->never())->method('createRoom');

		$result = $this->service->startConversation(caseId: 'nope');

		$this->assertFalse($result['ok']);
		$this->assertSame(CaseConversationService::REASON_NO_CASE, $result['reason']);
	}//end testAnUnreadableCaseIsRefused()

	/**
	 * The subject a handler gives is the name the room carries into Talk.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testTheSubjectNamesTheRoom(): void {
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->expects($this->once())
			->method('createRoom')
			->with($this->equalTo('Hoorzitting bezwaar'), $this->anything())
			->willReturn(['id' => 'room-2', 'url' => 'https://nc.test/call/room-2']);

		$this->service->startConversation(caseId: 'case-1', subject: 'Hoorzitting bezwaar', startedBy: 'anna');
	}//end testTheSubjectNamesTheRoom()

}//end class
