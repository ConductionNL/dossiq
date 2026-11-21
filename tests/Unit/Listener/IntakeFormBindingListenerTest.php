<?php

/**
 * IntakeFormBindingListener unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Kernel\Contracts\IntakeFormBindingCheckInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\IntakeFormBindingListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A case type whose intake form binding has findings is refused before it is stored.
 *
 * @covers \OCA\Dossiq\Listener\IntakeFormBindingListener
 * @uses   \OCA\Dossiq\Listener\Support\SchemaMembership
 */
class IntakeFormBindingListenerTest extends TestCase {

	/**
	 * The binding check.
	 *
	 * @var IntakeFormBindingCheckInterface&MockObject
	 */
	private IntakeFormBindingCheckInterface&MockObject $check;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var IntakeFormBindingListener
	 */
	private IntakeFormBindingListener $listener;

	/**
	 * Wire the listener; the case type schema is `caseType`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(originalClassName: SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'case_type_schema' => 'caseType',
				default => '',
			}
		);
		$this->check = $this->createMock(originalClassName: IntakeFormBindingCheckInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new IntakeFormBindingListener(settingsService: $settings, check: $this->check, logger: $this->logger);
	}//end setUp()

	/**
	 * An entity of a schema.
	 *
	 * @param string               $schema Its schema.
	 * @param array<string, mixed> $data   Its fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ct-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A create with findings is refused with the check's sentence and findings.
	 *
	 * @return void
	 */
	public function testACreateWithFindingsIsRefused(): void {
		$findings = [['field' => 'subject', 'problem' => 'unmapped']];
		$this->check->expects($this->once())->method('findingsFor')
			->with($this->callback(callback: static fn (array $ct): bool => $ct['intakeFormRef'] === 'form-1'))
			->willReturn($findings);
		$this->check->expects($this->once())->method('sentenceFor')->with($findings)->willReturn('The form leaves subject empty.');

		$event = new ObjectCreatingEvent(object: $this->entity(schema: 'caseType', data: ['intakeFormRef' => 'form-1']));
		$this->listener->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: [
				'message' => 'The form leaves subject empty.',
				'error' => IntakeFormBindingCheckInterface::RULE,
				'code' => IntakeFormBindingCheckInterface::RULE,
				'findings' => $findings,
			],
			actual: $event->getErrors()
		);
	}//end testACreateWithFindingsIsRefused()

	/**
	 * An update is judged on the new object; a schema URL ending in the slug counts.
	 *
	 * @return void
	 */
	public function testAnUpdateIsJudgedOnTheNewObject(): void {
		$this->check->expects($this->once())->method('findingsFor')
			->with($this->callback(callback: static fn (array $ct): bool => $ct['intakeFormRef'] === 'new'))
			->willReturn([['field' => 'x']]);
		$this->check->method('sentenceFor')->willReturn('bad');

		$event = new ObjectUpdatingEvent(
			$this->entity(schema: 'https://host/schemas/caseType', data: ['intakeFormRef' => 'new']),
			$this->entity(schema: 'caseType', data: ['intakeFormRef' => 'old'])
		);
		$this->listener->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'bad', actual: $event->getErrors()['message']);
	}//end testAnUpdateIsJudgedOnTheNewObject()

	/**
	 * A binding without findings is stored.
	 *
	 * @return void
	 */
	public function testABindingWithoutFindingsPasses(): void {
		$this->check->method('findingsFor')->willReturn([]);
		$this->check->expects($this->never())->method('sentenceFor');

		$event = new ObjectCreatingEvent(object: $this->entity(schema: 'caseType', data: ['intakeFormRef' => 'form-1']));
		$this->listener->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [], actual: $event->getErrors());
	}//end testABindingWithoutFindingsPasses()

	/**
	 * An object of another schema is never judged.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsNotJudged(): void {
		$this->check->expects($this->never())->method('findingsFor');

		$event = new ObjectCreatingEvent(object: $this->entity(schema: 'case', data: ['intakeFormRef' => 'form-1']));
		$this->listener->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnotherSchemaIsNotJudged()

	/**
	 * An unreadable payload is skipped with a debug line.
	 *
	 * @return void
	 */
	public function testAnUnreadablePayloadIsSkipped(): void {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willThrowException(new RuntimeException('no data'));
		$this->check->expects($this->never())->method('findingsFor');
		$this->logger->expects($this->once())->method('debug')
			->with('Dossiq intake: the case type payload could not be read: no data');

		$event = new ObjectCreatingEvent($entity);
		$this->listener->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
	}//end testAnUnreadablePayloadIsSkipped()

	/**
	 * A post-persist event is not this listener's: the binding was judged before it.
	 *
	 * @return void
	 */
	public function testAPostPersistEventIsIgnored(): void {
		$this->check->expects($this->never())->method('findingsFor');

		$this->listener->handle(new ObjectCreatedEvent(object: $this->entity(schema: 'caseType', data: ['intakeFormRef' => 'form-1'])));
	}//end testAPostPersistEventIsIgnored()
}//end class
