<?php

/**
 * CaseDeadlineFollowsTermListener unit tests.
 *
 * One term engine (REQ-OTE-01): every save of a case keeps its statutory
 * term's end date, whatever the payload or the calculation carried. The
 * events and the entity are OpenRegister's real classes, so the modified-data
 * merge is the one OpenRegister applies.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar;
use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener
 * @covers \OCA\Dossiq\AppInfo\Registrar\CaseTypeListenerRegistrar
 * @uses   \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses   \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses   \OCA\Dossiq\Service\TermKind
 */
class CaseDeadlineFollowsTermListenerTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * The listener under test.
	 *
	 * @var CaseDeadlineFollowsTermListener
	 */
	private CaseDeadlineFollowsTermListener $listener;

	/**
	 * Wire the listener over an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case-schema-id',
				'termijn_instance_schema' => 'deadlineInstance',
				default => '',
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->listener = new CaseDeadlineFollowsTermListener(
			settingsService: $settings,
			mirror: new CaseDeadlineMirror(
				settingsService: $settings,
				store: new TermInstanceStore(settingsService: $settings, logger: $logger),
				logger: $logger,
			),
			logger: $logger,
		);
	}//end setUp()

	/**
	 * A case about to be updated, after the listener ran.
	 *
	 * @param array<string, mixed> $payload   Its fields.
	 * @param array<string, mixed> $modified  What earlier listeners set.
	 * @param string               $schemaId  Its schema.
	 *
	 * @return ObjectUpdatingEvent The event.
	 */
	private function update(array $payload, array $modified = [], string $schemaId = 'case-schema-id'): ObjectUpdatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('case-1');

		$event = new ObjectUpdatingEvent($entity, null);
		if ($modified !== []) {
			$event->setModifiedData($modified);
		}

		$this->listener->handle($event);

		return $event;
	}//end update()

	/**
	 * A later save does not bring the calculated date back (scenario 2).
	 *
	 * @return void
	 */
	public function testALaterSaveKeepsTheExtendedDate(): void {
		$this->objects->seed(
			'deadlineInstance',
			['id' => 't1', 'case' => 'case-1', 'status' => 'verlengd', 'endDateCurrent' => '2026-11-16', 'startDate' => '2026-09-21T10:00:00+02:00']
		);

		// The calculation listener ran first and put back startDate + P6W.
		$event = $this->update(
			['title' => 'Nieuwe titel', 'deadline' => '2026-11-02'],
			['deadline' => '2026-11-02', 'note' => 'set by another listener']
		);

		self::assertSame(
			['deadline' => '2026-11-16', 'note' => 'set by another listener', CaseDeadlineMirror::FIELD => '2026-11-16'],
			$event->getModifiedData(),
			'The term decides, and another listener\'s data survives.'
		);
	}//end testALaterSaveKeepsTheExtendedDate()

	/**
	 * A case type without a term keeps the fallback (scenario 4).
	 *
	 * @return void
	 */
	public function testACaseWithoutAStatutoryTermKeepsTheFallback(): void {
		$this->objects->seed(
			'deadlineInstance',
			['id' => 'p1', 'case' => 'case-1', 'kind' => 'planned', 'status' => 'lopend', 'endDateCurrent' => '2026-10-01']
		);

		self::assertSame([], $this->update(['deadline' => '2026-11-02'])->getModifiedData());
	}//end testACaseWithoutAStatutoryTermKeepsTheFallback()

	/**
	 * Another schema is left alone, and a create is not handled.
	 *
	 * @return void
	 */
	public function testAnotherSchemaAndACreateAreLeftAlone(): void {
		$this->objects->seed(
			'deadlineInstance',
			['id' => 't1', 'case' => 'case-1', 'status' => 'lopend', 'endDateCurrent' => '2026-11-16']
		);

		self::assertSame([], $this->update(['deadline' => '2026-11-02'], [], 'other-schema')->getModifiedData());

		$entity = new ObjectEntity();
		$entity->setObject(['deadline' => '2026-11-02']);
		$entity->setSchema('case-schema-id');
		$entity->setUuid('case-1');
		$creating = new ObjectCreatingEvent($entity);
		$this->listener->handle($creating);
		self::assertSame([], $creating->getModifiedData());
	}//end testAnotherSchemaAndACreateAreLeftAlone()

	/**
	 * The listener runs after the calculation and after the inherited deadline.
	 *
	 * @return void
	 */
	public function testItIsRegisteredBelowTheInheritedDeadline(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener, $priority];
			}
		);

		(new CaseTypeListenerRegistrar())->register($context);

		self::assertContains([ObjectUpdatingEvent::class, CaseDeadlineFollowsTermListener::class, -110], $registered);
		self::assertNotContains([ObjectCreatingEvent::class, CaseDeadlineFollowsTermListener::class, -110], $registered);
		self::assertLessThan(
			CaseTypeListenerRegistrar::INHERITED_DEADLINE_PRIORITY,
			CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY
		);
	}//end testItIsRegisteredBelowTheInheritedDeadline()
}//end class
