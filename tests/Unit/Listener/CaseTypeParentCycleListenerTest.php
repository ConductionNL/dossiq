<?php

/**
 * CaseTypeParentCycleListener unit tests.
 *
 * REQ-CT-20: a parent chain that returns to itself is refused ON SAVE, with a
 * message naming the loop. The resolver here is the REAL one, over a fixed
 * store, because the question is whether the listener asks it the right
 * question: the INCOMING parent checked against the STORED chain above it.
 * A test that mocked `cycleFor()` would pass with the arguments swapped.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseTypeParentCycleListener;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseTypeParentCycleListener
 * @covers \OCA\Dossiq\Service\CaseTypeResolver
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 */
class CaseTypeParentCycleListenerTest extends TestCase {

	/**
	 * The stored case types: Bezwaar, and Bezwaar (verkort) naming it as parent.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const STORED = [
		'bezwaar' => ['id' => 'bezwaar', 'title' => 'Bezwaar', 'processingDeadline' => 'P12W'],
		'verkort' => ['id' => 'verkort', 'title' => 'Bezwaar (verkort)', 'parentCaseType' => 'bezwaar'],
		'beroep' => ['id' => 'beroep', 'title' => 'Beroep'],
	];

	/**
	 * Build the listener over a fixed store of case types.
	 *
	 * @param array<string, array<string, mixed>> $stored The case types, keyed by id.
	 *
	 * @return CaseTypeParentCycleListener The listener.
	 */
	private function listener(array $stored = self::STORED): CaseTypeParentCycleListener {
		$objectService = new class($stored) {
			/**
			 * @param array<string, array<string, mixed>> $stored Case types, keyed by id.
			 */
			public function __construct(private array $stored) {
			}

			/**
			 * Read one object by id.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return array<string, mixed> The row, or an empty array.
			 */
			public function find(string $id, string $register, string $schema): array {
				return ($this->stored[$id] ?? []);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'case-type-schema-id',
				default => $default,
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new CaseTypeParentCycleListener(
			$settings,
			new CaseTypeResolver(new CaseTypeStore($settings)),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A case type entity as the object API would write it.
	 *
	 * @param string               $uuid     The case type's id.
	 * @param array<string, mixed> $payload  Its fields.
	 * @param string               $schemaId Its schema.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $payload, string $schemaId = 'case-type-schema-id'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid($uuid);

		return $entity;
	}//end entity()

	/**
	 * Save Bezwaar with a new parent, as an update over its stored row.
	 *
	 * @param string $parent The parent the save names.
	 *
	 * @return ObjectUpdatingEvent The event, after the listener ran.
	 */
	private function saveBezwaarWithParent(string $parent): ObjectUpdatingEvent {
		$event = new ObjectUpdatingEvent(
			$this->entity('bezwaar', ['title' => 'Bezwaar', 'parentCaseType' => $parent]),
			$this->entity('bezwaar', self::STORED['bezwaar'])
		);
		$this->listener()->handle($event);

		return $event;
	}//end saveBezwaarWithParent()

	/**
	 * The scenario: Bezwaar's parent set to the child that names Bezwaar.
	 *
	 * @return void
	 */
	public function testAParentThatDescendsFromTheTypeIsRefusedAndTheMessageNamesTheLoop(): void {
		$event = $this->saveBezwaarWithParent('verkort');

		$this->assertTrue($event->isPropagationStopped(), 'the save must be stopped');
		$errors = $event->getErrors();
		$this->assertSame([CaseTypeParentCycleListener::ERROR_CODE], $errors['codes']);
		// The shared form dialog prints every STRING value of `errors`, so
		// the message must be the only one.
		$this->assertSame(
			['message'],
			array_keys(array_filter($errors, 'is_string')),
			'only the message may be a string, or the dialog prints the rest after it'
		);
		$this->assertSame(
			'A case type cannot inherit from itself: Bezwaar -> Bezwaar (verkort) -> Bezwaar',
			$errors['message']
		);
		$this->assertSame(['Bezwaar', 'Bezwaar (verkort)', 'Bezwaar'], $errors['cycle']);
	}//end testAParentThatDescendsFromTheTypeIsRefusedAndTheMessageNamesTheLoop()

	/**
	 * A type named as its own parent is the shortest loop there is.
	 *
	 * @return void
	 */
	public function testATypeNamedAsItsOwnParentIsRefused(): void {
		$event = $this->saveBezwaarWithParent('bezwaar');

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['Bezwaar', 'Bezwaar'], $event->getErrors()['cycle']);
	}//end testATypeNamedAsItsOwnParentIsRefused()

	/**
	 * A parent that does not lead back is an ordinary save.
	 *
	 * @return void
	 */
	public function testAParentThatDoesNotLeadBackIsSaved(): void {
		$event = $this->saveBezwaarWithParent('beroep');

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAParentThatDoesNotLeadBackIsSaved()

	/**
	 * A type with no parent has no chain to check.
	 *
	 * @return void
	 */
	public function testATypeWithoutAParentIsSaved(): void {
		$event = $this->saveBezwaarWithParent('');

		$this->assertFalse($event->isPropagationStopped());
	}//end testATypeWithoutAParentIsSaved()

	/**
	 * A NEW type naming itself is refused too, by the title it is created with.
	 *
	 * @return void
	 */
	public function testANewTypeNamingItselfIsRefusedByTheTitleItIsCreatedWith(): void {
		$event = new ObjectCreatingEvent(
			$this->entity('nieuw', ['title' => 'Nieuw', 'parentCaseType' => 'nieuw'])
		);
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(['Nieuw', 'Nieuw'], $event->getErrors()['cycle']);
	}//end testANewTypeNamingItselfIsRefusedByTheTitleItIsCreatedWith()

	/**
	 * A new child of an existing type is an ordinary create.
	 *
	 * @return void
	 */
	public function testANewChildOfAnExistingTypeIsCreated(): void {
		$event = new ObjectCreatingEvent(
			$this->entity('nieuw', ['title' => 'Nieuw', 'parentCaseType' => 'verkort'])
		);
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testANewChildOfAnExistingTypeIsCreated()

	/**
	 * A parent given as a row rather than a uuid is read by its id.
	 *
	 * @return void
	 */
	public function testAParentGivenAsARowIsReadByItsId(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity('bezwaar', ['title' => 'Bezwaar', 'parentCaseType' => ['id' => 'verkort']]),
			$this->entity('bezwaar', self::STORED['bezwaar'])
		);
		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAParentGivenAsARowIsReadByItsId()

	/**
	 * Another schema carrying a `parentCaseType` field is none of this listener's business.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity('bezwaar', ['parentCaseType' => 'verkort'], schemaId: 'some-other-schema'),
			null
		);
		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * An event that is not a pre-persist object event is ignored.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$this->listener()->handle(new Event());
		$this->addToAssertionCount(1);
	}//end testAnUnrelatedEventIsIgnored()
}//end class
