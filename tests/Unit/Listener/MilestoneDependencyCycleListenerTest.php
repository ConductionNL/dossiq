<?php

/**
 * MilestoneDependencyCycleListener unit tests.
 *
 * REQ-MST-01: a milestone definition whose `dependsOn` closes a loop is
 * refused on save, and the refusal names the items in the loop.
 *
 * THE TEST THIS FILE EXISTS FOR is the one about an instance whose setup never
 * finished. Measured on 2026-09-19 against a live instance: a milestone that
 * waits for itself was stored with a 201 while this listener was wired and its
 * schedule was correct, because the first line of its schema check read an
 * appconfig key the failed configuration load had never written and stood
 * aside. Every other test here would have passed that day.
 *
 * The cycle rule is the REAL MilestoneSchedule, because the question is
 * whether the listener asks it the right question. A mocked `cycle()` passes
 * with the arguments swapped, and with the guard never reached at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\MilestoneDependencyCycleListener;
use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\Milestone\MilestoneSchedule;
use OCA\Dossiq\Service\Settings\SchemaScopeResolver;
use OCA\Dossiq\Service\Settings\SchemaSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Listener\MilestoneDependencyCycleListener
 * @covers \OCA\Dossiq\Service\Settings\SchemaScopeResolver
 * @uses   \OCA\Dossiq\Service\Milestone\MilestoneSchedule
 * @uses   \OCA\Dossiq\Service\WorkingDayCalculator
 */
class MilestoneDependencyCycleListenerTest extends TestCase {

	/**
	 * The schema id the configuration load writes when it completes.
	 *
	 * @var string
	 */
	private const SCHEMA_ID = '412';

	/**
	 * The case type every definition in this file belongs to.
	 *
	 * @var string
	 */
	private const CASE_TYPE = '84bedc50-1f2e-4c35-9f22-0f2f8f1f0001';

	/**
	 * Build the listener.
	 *
	 * @param string $configured The stored schema id, or '' for an instance
	 *                           whose configuration load never completed.
	 * @param string|null $live The id the slug resolves to live, or null when
	 *                          OpenRegister cannot be reached at all.
	 * @param array<int, array<string, mixed>> $stored The case type's other definitions.
	 *
	 * @return MilestoneDependencyCycleListener The listener.
	 */
	private function listener(
		string $configured = self::SCHEMA_ID,
		?string $live = null,
		array $stored = [],
	): MilestoneDependencyCycleListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				MilestoneDependencyCycleListener::SCHEMA_CONFIG_KEY => $configured,
				default => $default,
			}
		);

		$slugResolver = $this->getMockBuilder(SchemaSlugResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolve'])
			->getMock();
		$container = $this->createMock(ContainerInterface::class);

		if ($live === null) {
			// Nothing answers for the slug either: OpenRegister's mapper is
			// not reachable from this container.
			$container->method('get')->willThrowException(new RuntimeException('no mapper here'));
		} else {
			$container->method('get')->willReturn(new \stdClass());
			$slugResolver->method('resolve')->willReturn(
				new class($live) {
					/**
					 * @param string $id The schema id.
					 */
					public function __construct(private string $id) {
					}

					/**
					 * The schema id.
					 *
					 * @return string The id.
					 */
					public function getId(): string {
						return $this->id;
					}
				}
			);
		}

		$repository = $this->getMockBuilder(MilestoneRepository::class)
			->disableOriginalConstructor()
			->onlyMethods(['findDefinitions'])
			->getMock();
		$repository->method('findDefinitions')->willReturn($stored);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new MilestoneDependencyCycleListener(
			new SchemaScopeResolver(
				$settings,
				$slugResolver,
				$container,
				$this->createMock(LoggerInterface::class),
			),
			$repository,
			new MilestoneSchedule(workingDays: new WorkingDayCalculator()),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A milestone definition as the object API would write it.
	 *
	 * @param array<string, mixed> $payload The fields.
	 * @param string $schemaId The schema the write is addressed to.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload, string $schemaId = self::SCHEMA_ID): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('664c4f14-0a2b-4f4e-8a1c-4f1a2b3c4d5e');

		return $entity;
	}//end entity()

	/**
	 * The payload the live instance stored with a 201 on 2026-09-19.
	 *
	 * @return array<string, mixed> A milestone that waits for itself.
	 */
	private function selfDependentMilestone(): array {
		return [
			'caseType' => self::CASE_TYPE,
			'identifier' => 'lane3-loop',
			'label' => 'Waits for itself',
			'order' => 1,
			'dependsOn' => ['lane3-loop'],
		];
	}//end selfDependentMilestone()

	/**
	 * Run a create through a listener and hand back the event.
	 *
	 * @param MilestoneDependencyCycleListener $listener The listener.
	 * @param array<string, mixed> $payload The payload being written.
	 * @param string $schemaId The schema the write is addressed to.
	 *
	 * @return ObjectCreatingEvent The event, after the listener ran.
	 */
	private function create(
		MilestoneDependencyCycleListener $listener,
		array $payload,
		string $schemaId = self::SCHEMA_ID,
	): ObjectCreatingEvent {
		$event = new ObjectCreatingEvent($this->entity($payload, $schemaId));
		$listener->handle($event);

		return $event;
	}//end create()

	/**
	 * The control: a configured instance refuses the loop.
	 *
	 * @return void
	 */
	public function testAMilestoneThatWaitsForItselfIsRefusedOnAConfiguredInstance(): void {
		$event = $this->create($this->listener(), $this->selfDependentMilestone());

		$this->assertTrue($event->isPropagationStopped(), 'the save must be stopped');
		$errors = $event->getErrors();
		$this->assertSame([MilestoneDependencyCycleListener::ERROR_CODE], $errors['codes']);
		$this->assertSame('A milestone cannot wait for itself: lane3-loop', $errors['message']);
		$this->assertSame(['lane3-loop'], $errors['cycle']);
	}//end testAMilestoneThatWaitsForItselfIsRefusedOnAConfiguredInstance()

	/**
	 * THE REGRESSION. An instance whose configuration load never wrote the
	 * schema key still refuses the loop.
	 *
	 * Before this fix the listener read the empty key as "not a milestone" and
	 * returned, so this create was stored.
	 *
	 * @return void
	 */
	public function testTheLoopIsRefusedEvenWhenTheSchemaKeyWasNeverWritten(): void {
		$event = $this->create(
			$this->listener(configured: '', live: null),
			$this->selfDependentMilestone()
		);

		$this->assertTrue(
			$event->isPropagationStopped(),
			'a guard whose config key is missing must not stand aside: '
			. 'this is the write that was stored with a 201 on the live instance'
		);
		$this->assertSame(['lane3-loop'], $event->getErrors()['cycle']);
	}//end testTheLoopIsRefusedEvenWhenTheSchemaKeyWasNeverWritten()

	/**
	 * With no key but a resolvable slug, the LIVE id decides, and it decides
	 * both ways.
	 *
	 * @return void
	 */
	public function testWithNoKeyTheLiveSchemaIdDecides(): void {
		$refused = $this->create(
			$this->listener(configured: '', live: self::SCHEMA_ID),
			$this->selfDependentMilestone()
		);
		$this->assertTrue($refused->isPropagationStopped());

		$other = $this->create(
			$this->listener(configured: '', live: '999'),
			['identifier' => 'x', 'caseType' => self::CASE_TYPE, 'dependsOn' => ['x']],
			'412'
		);
		$this->assertFalse(
			$other->isPropagationStopped(),
			'a write to another schema is not this guard\'s business'
		);
	}//end testWithNoKeyTheLiveSchemaIdDecides()

	/**
	 * A write to a different schema is left alone on a configured instance.
	 *
	 * @return void
	 */
	public function testAWriteToAnotherSchemaIsLeftAlone(): void {
		$event = $this->create(
			$this->listener(),
			$this->selfDependentMilestone(),
			'777'
		);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAWriteToAnotherSchemaIsLeftAlone()

	/**
	 * The payload fallback is narrow: nothing that lacks the milestone's own
	 * three fields is touched when the schema cannot be named.
	 *
	 * A consultation is the real case, because it is the one other Dossiq
	 * schema that carries `dependsOn`.
	 *
	 * @return void
	 */
	public function testAConsultationIsNotMistakenForAMilestoneWhenNothingNamesTheSchema(): void {
		$event = $this->create(
			$this->listener(configured: '', live: null),
			[
				'consultationNumber' => 'ADV-2026-001',
				'parentCase' => self::CASE_TYPE,
				'dependsOn' => ['ADV-2026-001'],
			],
			'888'
		);

		$this->assertFalse(
			$event->isPropagationStopped(),
			'the payload fallback must not reach another schema'
		);
	}//end testAConsultationIsNotMistakenForAMilestoneWhenNothingNamesTheSchema()

	/**
	 * A longer loop, through the definitions already stored on the case type.
	 *
	 * @return void
	 */
	public function testALoopThroughTheStoredDefinitionsIsRefused(): void {
		$listener = $this->listener(
			configured: self::SCHEMA_ID,
			stored: [
				[
					'caseType' => self::CASE_TYPE,
					'identifier' => 'advies',
					'order' => 2,
					'dependsOn' => ['intake'],
				],
			],
		);

		$event = $this->create(
			$listener,
			[
				'caseType' => self::CASE_TYPE,
				'identifier' => 'intake',
				'label' => 'Intake',
				'order' => 1,
				'dependsOn' => ['advies'],
			]
		);

		$this->assertTrue($event->isPropagationStopped());
		// The loop is reported from the stored definition the walk reaches
		// first, which is the set the save would leave behind.
		$this->assertSame(['advies', 'intake'], $event->getErrors()['cycle']);
	}//end testALoopThroughTheStoredDefinitionsIsRefused()

	/**
	 * A definition that waits for something upstream is an ordinary save.
	 *
	 * @return void
	 */
	public function testAMilestoneThatWaitsForSomethingElseIsSaved(): void {
		$event = $this->create(
			$this->listener(),
			[
				'caseType' => self::CASE_TYPE,
				'identifier' => 'besluit',
				'label' => 'Besluit',
				'order' => 3,
				'dependsOn' => ['advies'],
			]
		);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAMilestoneThatWaitsForSomethingElseIsSaved()

	/**
	 * An update is guarded too, and it is guarded on the NEW object.
	 *
	 * @return void
	 */
	public function testAnUpdateThatClosesTheLoopIsRefused(): void {
		// The NEW object is the first argument, and it is the one guarded.
		$event = new ObjectUpdatingEvent(
			$this->entity($this->selfDependentMilestone()),
			$this->entity(['caseType' => self::CASE_TYPE, 'identifier' => 'lane3-loop', 'dependsOn' => []])
		);
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['lane3-loop'], $event->getErrors()['cycle']);
	}//end testAnUpdateThatClosesTheLoopIsRefused()
}//end class
