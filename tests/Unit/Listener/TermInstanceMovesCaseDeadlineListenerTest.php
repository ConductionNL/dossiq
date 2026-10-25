<?php

/**
 * TermInstanceMovesCaseDeadlineListener unit tests.
 *
 * A statutory term written through OpenRegister directly (an example set, an
 * import, the API) moves its case's deadline, as one written through the term
 * engine does (REQ-OTE-01). Without it a case keeps the calculated fallback,
 * startDate plus the case type's term, until something else saves it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\DeadlineTimerRegistrar;
use OCA\Dossiq\Listener\TermInstanceMovesCaseDeadlineListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\TermInstanceMovesCaseDeadlineListener
 * @covers \OCA\Dossiq\AppInfo\Registrar\DeadlineTimerRegistrar
 */
class TermInstanceMovesCaseDeadlineListenerTest extends TestCase {

	/**
	 * An object of a schema, with data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('instance-1');
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * The listener, with the mirror that should or should not be asked to follow.
	 *
	 * @param CaseDeadlineMirror $mirror The mirror.
	 *
	 * @return TermInstanceMovesCaseDeadlineListener
	 */
	private function listener(CaseDeadlineMirror $mirror): TermInstanceMovesCaseDeadlineListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'termijn_instance_schema' ? '17' : '')
		);

		return new TermInstanceMovesCaseDeadlineListener(settingsService: $settings, mirror: $mirror);
	}//end listener()

	/**
	 * A created or updated term instance is followed by its case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function testACreatedOrUpdatedTermInstanceIsFollowedByItsCase(): void {
		$data   = ['case' => 'case-1', 'kind' => 'statutory', 'endDateCurrent' => '2026-11-02'];
		$mirror = $this->createMock(CaseDeadlineMirror::class);
		$mirror->expects($this->exactly(2))
			->method('followInstance')
			->with($this->callback(static fn (array $instance): bool => $instance['case'] === 'case-1' && $instance['endDateCurrent'] === '2026-11-02'))
			->willReturn(true);
		$listener = $this->listener(mirror: $mirror);

		$listener->handle(new ObjectCreatedEvent($this->entity(schema: '17', data: $data)));
		$listener->handle(new ObjectUpdatedEvent($this->entity(schema: '17', data: $data)));
	}//end testACreatedOrUpdatedTermInstanceIsFollowedByItsCase()

	/**
	 * Any other object is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function testAnyOtherObjectIsLeftAlone(): void {
		$mirror = $this->createMock(CaseDeadlineMirror::class);
		$mirror->expects($this->never())->method('followInstance');

		$this->listener(mirror: $mirror)->handle(new ObjectCreatedEvent($this->entity(schema: '4', data: ['case' => 'case-1'])));
	}//end testAnyOtherObjectIsLeftAlone()

	/**
	 * It runs after every create and update of an object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function testItIsRegisteredAfterCreateAndUpdate(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		(new DeadlineTimerRegistrar())->register($context);

		self::assertContains([ObjectCreatedEvent::class, TermInstanceMovesCaseDeadlineListener::class], $registered);
		self::assertContains([ObjectUpdatedEvent::class, TermInstanceMovesCaseDeadlineListener::class], $registered);
	}//end testItIsRegisteredAfterCreateAndUpdate()
}//end class
