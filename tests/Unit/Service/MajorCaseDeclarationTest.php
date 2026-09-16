<?php

/**
 * Tests for declaring a case major.
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
use OCA\Dossiq\Service\Conversation\MajorCaseDeclaration;
use OCA\Dossiq\Service\Conversation\ResponderResolver;
use OCA\Dossiq\Service\Conversation\TalkConversationBroker;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A major declaration opens one channel, or refuses and says who is missing.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class MajorCaseDeclarationTest extends TestCase {

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
	 * The responder resolver.
	 *
	 * @var ResponderResolver|MockObject
	 */
	private ResponderResolver $responders;

	/**
	 * The service under test.
	 *
	 * @var MajorCaseDeclaration
	 */
	private MajorCaseDeclaration $service;

	/**
	 * Build the service over an in-memory register holding one case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Brand loods', 'identifier' => 'ZAAK-042', 'caseType' => 'calamiteit'],
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
		$this->responders = $this->createMock(ResponderResolver::class);

		$this->service = new MajorCaseDeclaration(
			cases: new CaseRecordStore(settingsService: $settings),
			broker: $this->broker,
			responders: $this->responders,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * One act stands a calamiteit up: one channel, four responders recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testACalamiteitIsStoodUpInOneAct(): void {
		$this->responders->method('resolve')->willReturn(
			['ok' => true, 'users' => ['anna', 'bram', 'chris', 'dina'], 'unresolved' => []]
		);
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->expects($this->once())
			->method('createRoom')
			->willReturn(['id' => 'room-7', 'url' => 'https://nc.test/call/room-7']);

		$result = $this->service->declareMajor(caseId: 'case-1', declaredBy: 'coordinator');

		$this->assertTrue($result['ok']);
		$this->assertFalse($result['alreadyMajor']);

		$stored = $this->register->row(schema: 'case', uuid: 'case-1');
		$this->assertTrue($stored['isMajor']);
		$this->assertSame('room-7', $stored['majorChannel']['roomId']);
		$this->assertSame('coordinator', $stored['majorDeclaredBy']);
		$this->assertSame(['anna', 'bram', 'chris', 'dina'], $stored['majorResponders']);
		$this->assertNotSame('', $stored['majorDeclaredAt']);
	}//end testACalamiteitIsStoodUpInOneAct()

	/**
	 * A second declaration finds the channel that is already open.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testOneChannelNotTwo(): void {
		$this->responders->method('resolve')->willReturn(
			['ok' => true, 'users' => ['anna'], 'unresolved' => []]
		);
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->expects($this->once())
			->method('createRoom')
			->willReturn(['id' => 'room-7', 'url' => 'https://nc.test/call/room-7']);

		$this->service->declareMajor(caseId: 'case-1', declaredBy: 'coordinator');
		$second = $this->service->declareMajor(caseId: 'case-1', declaredBy: 'someone-else');

		$this->assertTrue($second['ok']);
		$this->assertTrue($second['alreadyMajor']);
		$this->assertSame('room-7', $second['channel']['roomId']);
	}//end testOneChannelNotTwo()

	/**
	 * Responders that do not resolve refuse the declaration, and name the group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testUnresolvableRespondersRefuseTheDeclaration(): void {
		$this->responders->method('resolve')->willReturn(
			['ok' => false, 'users' => [], 'unresolved' => ['group:crisisteam']]
		);
		$this->broker->expects($this->never())->method('createRoom');

		$result = $this->service->declareMajor(caseId: 'case-1', declaredBy: 'coordinator');

		$this->assertFalse($result['ok']);
		$this->assertSame(CaseConversationService::REASON_UNRESOLVED_RESPONDERS, $result['reason']);
		$this->assertSame(['group:crisisteam'], $result['unresolved']);

		$stored = $this->register->row(schema: 'case', uuid: 'case-1');
		$this->assertArrayNotHasKey('isMajor', $stored);
		$this->assertArrayNotHasKey('majorChannel', $stored);
	}//end testUnresolvableRespondersRefuseTheDeclaration()

	/**
	 * Closing the case closes the channel, and what was said stays on the case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testTheChannelClosesWithTheCaseAndItsContentStays(): void {
		$this->responders->method('resolve')->willReturn(
			['ok' => true, 'users' => ['anna', 'bram'], 'unresolved' => []]
		);
		$this->broker->method('isAvailable')->willReturn(true);
		$this->broker->method('createRoom')->willReturn(['id' => 'room-7', 'url' => 'https://nc.test/call/room-7']);
		$this->broker->expects($this->once())->method('closeRoom')->with($this->equalTo('room-7'))->willReturn(true);

		$this->service->declareMajor(caseId: 'case-1', declaredBy: 'coordinator');

		$result = $this->service->closeMajorChannel(caseId: 'case-1');

		$this->assertTrue($result['ok']);
		$this->assertTrue($result['closed']);

		$stored = $this->register->row(schema: 'case', uuid: 'case-1');
		$this->assertNotSame('', $stored['majorChannel']['closedAt']);
		$this->assertCount(1, $stored['conversations']);
		$this->assertSame('majorChannel', $stored['conversations'][0]['kind']);
		$this->assertSame(['anna', 'bram'], $stored['conversations'][0]['participants']);
	}//end testTheChannelClosesWithTheCaseAndItsContentStays()

	/**
	 * Closing a case that never had a channel closes nothing and fails nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function testClosingACaseWithNoChannelIsNotAnError(): void {
		$this->broker->expects($this->never())->method('closeRoom');

		$result = $this->service->closeMajorChannel(caseId: 'case-1');

		$this->assertTrue($result['ok']);
		$this->assertFalse($result['closed']);
	}//end testClosingACaseWithNoChannelIsNotAnError()

}//end class
