<?php

/**
 * The messages a resident reads on one of their cases.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalCaseMessages;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Portal\PortalCaseMessages
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses   \OCA\Dossiq\Portal\PortalPages
 * @uses   \OCA\Dossiq\Portal\CitizenManifest
 * @uses   \OCA\Dossiq\Portal\PortalConversation
 */
class PortalCaseMessagesTest extends TestCase {
	private const CASE_ID = '22222222-2222-4222-8222-222222222222';

	private const RESIDENT = 'subject-resident';

	/**
	 * The stored case.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $case = ['portalSubject' => self::RESIDENT, 'title' => 'Kapvergunning Dorpsstraat 4'];

	/**
	 * The stored messages, as OpenRegister answers a search.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $messages = [];

	/**
	 * Whether the reads ran inside runAsSystem.
	 *
	 * @var bool
	 */
	private bool $asSystem = false;

	/**
	 * The filters each message search was asked with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $searches = [];

	/**
	 * A case with a question, the handler's answer, a message to someone else
	 * on the same case, and a row the search should not have answered.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->messages = [
			[
				'id' => 'm-question', 'caseId' => self::CASE_ID, 'direction' => 'citizen_to_handler',
				'senderRef' => self::RESIDENT, 'subject' => 'Termijn', 'content' => 'When will I hear back?',
				'sentAt' => '2026-10-01T09:00:00+00:00', 'attachments' => ['doc-1'], 'recipientRef' => 'handler-uid',
			],
			[
				'id' => 'm-answer', 'caseId' => self::CASE_ID, 'direction' => 'handler_to_citizen',
				'recipientRef' => self::RESIDENT, 'senderName' => 'Team Vergunningen', 'senderType' => 'medewerker',
				'subject' => 'Re: Termijn', 'content' => 'Within two weeks.', 'sentAt' => '2026-10-02T14:00:00+00:00',
				'readByRecipientAt' => '2026-10-02T15:00:00+00:00', 'senderRef' => 'handler-uid',
			],
			// A co-applicant's message on the same case: not this resident's.
			[
				'id' => 'm-other', 'caseId' => self::CASE_ID, 'direction' => 'citizen_to_handler',
				'senderRef' => 'subject-neighbour', 'content' => 'Not yours', 'sentAt' => '2026-10-03T10:00:00+00:00',
			],
			// A letter to someone else on the same case.
			[
				'id' => 'm-other-in', 'caseId' => self::CASE_ID, 'direction' => 'handler_to_citizen',
				'recipientRef' => 'subject-neighbour', 'content' => 'Also not yours', 'sentAt' => '2026-10-03T11:00:00+00:00',
			],
			// A row on another case the search should not have answered: never trusted.
			[
				'id' => 'm-foreign', 'caseId' => 'another-case', 'direction' => 'handler_to_citizen',
				'recipientRef' => self::RESIDENT, 'content' => 'Wrong case', 'sentAt' => '2026-10-04T10:00:00+00:00',
			],
		];
	}//end setUp()

	/**
	 * The service under test, over a real-contract object service double.
	 *
	 * @return PortalCaseMessages
	 */
	private function service(): PortalCaseMessages {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(function (callable $operation) {
			$this->asSystem = true;
			$result = $operation();
			$this->asSystem = false;
			return $result;
		});
		$objects->method('searchObjectsBySlug')->willReturnCallback(function (string $register, string $schema, array $filters) {
			$this->assertTrue($this->asSystem, 'the portal read runs as the system');
			$this->assertSame('dossiq', $register);
			$this->assertSame('portaalBericht', $schema);
			$this->searches[] = $filters;
			return $this->messages;
		});
		$objects->method('find')->willReturnCallback(function ($id) {
			if ($id !== self::CASE_ID || $this->case === null) {
				return null;
			}

			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($this->case);
			return $entity;
		});

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'portaal_bericht_schema' => 'portaalBericht',
		][$key] ?? '');

		return new PortalCaseMessages($settings, new NullLogger());
	}//end service()

	/**
	 * Only the resident's own messages on that case, both directions, newest
	 * first, in the inbox's words.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#4-ask-from-the-case
	 */
	public function testTheResidentReadsTheirOwnMessagesOnTheCaseNewestFirst(): void {
		$entries = $this->service()->forCase(self::CASE_ID);

		$this->assertSame(['m-answer', 'm-question'], array_column($entries, 'id'));
		$this->assertSame(
			[
				'id' => 'm-answer',
				'subject' => 'Re: Termijn',
				'body' => 'Within two weeks.',
				'receivedAt' => '2026-10-02T14:00:00+00:00',
				'direction' => 'handler_to_citizen',
				'senderName' => 'Team Vergunningen',
				'read' => true,
				'attachments' => [],
			],
			$entries[0]
		);
		$this->assertSame('citizen_to_handler', $entries[1]['direction']);
		$this->assertSame(['doc-1'], $entries[1]['attachments']);
		$this->assertSame(['caseId' => self::CASE_ID], array_intersect_key($this->searches[0], ['caseId' => true]));
	}//end testTheResidentReadsTheirOwnMessagesOnTheCaseNewestFirst()

	/**
	 * No reference of anybody travels: not the handler's user id, not the
	 * resident's subject reference.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#4-ask-from-the-case
	 */
	public function testNoReferenceLeavesTheServer(): void {
		foreach ($this->service()->forCase(self::CASE_ID) as $entry) {
			$this->assertArrayNotHasKey('senderRef', $entry);
			$this->assertArrayNotHasKey('recipientRef', $entry);
			$this->assertArrayNotHasKey('caseId', $entry);
		}
	}//end testNoReferenceLeavesTheServer()

	/**
	 * A case without a portal subject, a missing case or an empty id answers nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#4-ask-from-the-case
	 */
	public function testACaseWithoutAResidentAnswersNothing(): void {
		$this->assertSame([], $this->service()->forCase(''));
		$this->assertSame([], $this->service()->forCase('no-such-case'));

		$this->case = ['title' => 'Intake by phone'];
		$this->assertSame([], $this->service()->forCase(self::CASE_ID));
	}//end testACaseWithoutAResidentAnswersNothing()

	/**
	 * The contribution answers the provider method it declares, and a provider
	 * built without the reader answers an empty list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/communication-portal-conversation-on-the-case/tasks.md#4-ask-from-the-case
	 */
	public function testTheContributionAnswersCaseMessages(): void {
		$provider = new PortalContributionProvider(null, null, null, null, $this->service());
		$this->assertSame(['m-answer', 'm-question'], array_column($provider->caseMessages(self::CASE_ID), 'id'));

		$this->assertSame([], (new PortalContributionProvider())->caseMessages(self::CASE_ID));
	}//end testTheContributionAnswersCaseMessages()
}//end class
