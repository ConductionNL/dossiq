<?php

/**
 * A resident's portal message lands on the case timeline with a follow-up.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
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

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\PortalMessageTimelineListener;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\PortalMessageTimelineListener
 */
class PortalMessageTimelineListenerTest extends TestCase {

	/**
	 * Every entry the seam was asked to write.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The listener under test.
	 *
	 * @var PortalMessageTimelineListener
	 */
	private PortalMessageTimelineListener $listener;

	/**
	 * Build the listener over a capturing timeline seam.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->written = [];

		$timeline = $this->createMock(CaseTimeline::class);
		$timeline->method('record')->willReturnCallback(
			function (
				string $caseId,
				string $kind,
				string $message,
				array $fields = [],
				string $visibility = 'internal',
				array $relatedCaseIds = [],
			): string {
				$this->written[] = compact('caseId', 'kind', 'message', 'fields', 'visibility', 'relatedCaseIds');
				return 'entry-1';
			}
		);

		$resolver = $this->createMock(ObjectSchemaSlugResolver::class);
		$resolver->method('resolveFromPayload')->willReturnCallback(
			static fn (array $payload): string => (string)($payload['@self']['schema'] ?? '')
		);

		$this->listener = new PortalMessageTimelineListener($timeline, $resolver);
	}//end setUp()

	/**
	 * A resident's question is an internal entry of the inbound kind, naming
	 * the message, and that kind is declared with a follow-up.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testAResidentsMessageIsAnInternalEntryWithAFollowUp(): void {
		$this->listener->handle($this->created([
			'caseId' => 'case-1',
			'direction' => 'citizen_to_handler',
			'subject' => 'Termijn',
			'content' => 'When will I hear back?',
			'senderName' => 'J. Jansen',
			'sentAt' => '2026-10-01T09:00:00+00:00',
		]));

		$this->assertCount(1, $this->written);
		$entry = $this->written[0];
		$this->assertSame('case-1', $entry['caseId']);
		$this->assertSame(TimelineKinds::PORTAL_MESSAGE_IN, $entry['kind']);
		$this->assertSame('internal', $entry['visibility']);
		$this->assertSame('Termijn', $entry['message']);
		$this->assertSame(
			['subject' => 'Termijn', 'messageId' => 'msg-1', 'sender' => 'J. Jansen', 'sentAt' => '2026-10-01T09:00:00+00:00'],
			$entry['fields']
		);

		$declared = array_column(TimelineKinds::DECLARATIONS, null, 'slug')[TimelineKinds::PORTAL_MESSAGE_IN];
		$this->assertTrue($declared['followUp'], 'a question from a resident is open until a handler answers it');
		foreach (array_keys($entry['fields']) as $field) {
			$this->assertArrayHasKey($field, $declared['properties'], "OpenRegister drops an undeclared field: {$field}");
		}

		foreach ($declared['required'] as $required) {
			$this->assertNotSame('', $entry['fields'][$required] ?? '', "{$required} is filled");
		}
	}//end testAResidentsMessageIsAnInternalEntryWithAFollowUp()

	/**
	 * A message without a subject still reads as something on the timeline.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testAMessageWithoutASubjectReadsAsAQuestion(): void {
		$this->listener->handle($this->created(['caseId' => 'case-1', 'direction' => 'citizen_to_handler', 'content' => 'Hello']));

		$this->assertSame(PortalMessageTimelineListener::FALLBACK_MESSAGE, $this->written[0]['message']);
		$this->assertSame(PortalMessageTimelineListener::FALLBACK_MESSAGE, $this->written[0]['fields']['subject']);
	}//end testAMessageWithoutASubjectReadsAsAQuestion()

	/**
	 * The handler's message is a public entry of the outbound kind: the
	 * resident has received it, and the portal's case history says so. No
	 * follow-up opens.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheHandlersMessageIsAPublicOutboundEntry(): void {
		$this->listener->handle($this->created([
			'caseId' => 'case-1',
			'direction' => 'handler_to_citizen',
			'subject' => 'Re: Termijn',
			'recipientRef' => 'subject-resident',
		]));

		$this->assertCount(1, $this->written);
		$entry = $this->written[0];
		$this->assertSame(TimelineKinds::PORTAL_MESSAGE, $entry['kind']);
		$this->assertSame('public', $entry['visibility']);
		$this->assertSame(['subject' => 'Re: Termijn', 'messageId' => 'msg-1', 'status' => 'sent'], $entry['fields']);

		$declared = array_column(TimelineKinds::DECLARATIONS, null, 'slug')[TimelineKinds::PORTAL_MESSAGE];
		$this->assertFalse($declared['followUp']);
		foreach (array_keys($entry['fields']) as $field) {
			$this->assertArrayHasKey($field, $declared['properties'], "OpenRegister drops an undeclared field: {$field}");
		}
	}//end testTheHandlersMessageIsAPublicOutboundEntry()

	/**
	 * Another schema, a message without a direction or a case, and an update
	 * write nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testOnlyANewMessageOnACaseIsRecorded(): void {
		$this->listener->handle($this->created(['caseId' => 'case-1', 'direction' => 'citizen_to_handler'], 'contactmoment'));
		$this->listener->handle($this->created(['caseId' => '', 'direction' => 'citizen_to_handler']));
		$this->listener->handle($this->created(['caseId' => 'case-1']));

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'msg-1', 'schema' => 'portaalBericht'], 'caseId' => 'case-1', 'direction' => 'citizen_to_handler']);
		$this->listener->handle(new ObjectUpdatedEvent($entity));

		$this->assertSame([], $this->written);
	}//end testOnlyANewMessageOnACaseIsRecorded()

	/**
	 * A created-object event for one record.
	 *
	 * @param array<string, mixed> $record The stored record.
	 * @param string               $schema Its schema slug.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function created(array $record, string $schema = 'portaalBericht'): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(
			array_merge(['@self' => ['id' => 'msg-1', 'schema' => $schema]], $record)
		);

		return new ObjectCreatedEvent($entity);
	}//end created()
}//end class
