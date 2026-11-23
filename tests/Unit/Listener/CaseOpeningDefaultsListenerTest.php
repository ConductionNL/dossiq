<?php

/**
 * CaseOpeningDefaultsListener unit tests.
 *
 * A case created through the API or a dialog opens the way a case the intake
 * writes does: the opening fields it lacks come from its case type, and the
 * fields the writer gave are kept. The resolver is the real one over a fixed
 * store, so the listener reads the case type the case names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseOpeningDefaultsListener;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\CaseOpeningDefaultsListener
 * @uses   \OCA\Dossiq\Service\CaseTypeResolver
 * @uses   \OCA\Dossiq\Service\CaseTypeStore
 * @uses   \OCA\Dossiq\Service\CaseType\InheritedRowValues
 * @uses   \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses   \OCA\Dossiq\Service\Intake\CaseOpeningDefaults
 * @uses   \OCA\Dossiq\Listener\Support\PreSaveCheck
 * @uses   \OCA\Dossiq\Listener\Support\SchemaMembership
 * @uses   \OCA\Dossiq\Service\Support\ReferenceId
 * @uses   \OCA\Dossiq\Service\Support\SerialisedResult
 */
class CaseOpeningDefaultsListenerTest extends TestCase {

	/**
	 * A Woo request handled by its team, and a type that names no defaults.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const STORED = [
		'woo' => ['id' => 'woo', 'title' => 'Woo-verzoek', 'handling' => ['defaultGroup' => 'woo-team'], 'confidentiality' => 'intern'],
		'bare' => ['id' => 'bare', 'title' => 'Zonder standaarden'],
	];

	/**
	 * Build the listener over a fixed store of case types.
	 *
	 * @return CaseOpeningDefaultsListener The listener.
	 */
	private function listener(): CaseOpeningDefaultsListener {
		$objectService = new class(self::STORED) {
			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, mixed>> $stored Case types, keyed by id.
			 */
			public function __construct(private array $stored) {
			}

			/**
			 * Read one object by id.
			 *
			 * @param string                 $id            The id.
			 * @param ObjectFindOptions|null $options       The options.
			 * @param boolean                $_rbac         Unused.
			 * @param boolean                $_multitenancy Unused.
			 *
			 * @return array<string, mixed> The row, or an empty array.
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->stored[$id] ?? []);
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_type_schema' => 'case-type-schema-id',
				'case_schema' => 'case-schema-id',
				default => $default,
			}
		);

		return new CaseOpeningDefaultsListener(
			settingsService: $settings,
			resolver: new CaseTypeResolver(store: new CaseTypeStore(settingsService: $settings)),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end listener()

	/**
	 * A case about to be created, after the listener ran.
	 *
	 * @param array<string, mixed> $payload  Its fields.
	 * @param string               $schemaId Its schema.
	 *
	 * @return ObjectCreatingEvent The event.
	 */
	private function create(array $payload, string $schemaId = 'case-schema-id'): ObjectCreatingEvent {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		$entity->setSchema($schemaId);
		$entity->setUuid('case-1');

		$event = new ObjectCreatingEvent($entity);
		$this->listener()->handle(event: $event);

		return $event;
	}//end create()

	/**
	 * A case from Mail's "New Case from this email" gets its case type's team.
	 *
	 * @return void
	 */
	public function testACaseWithoutATeamGetsItsCaseTypesTeam(): void {
		$modified = $this->create(payload: ['caseType' => 'woo', 'title' => 'Woo-verzoek Havenpark'])->getModifiedData();

		self::assertSame(expected: 'woo-team', actual: ($modified['assignedGroup'] ?? null));
		self::assertSame(expected: 'intern', actual: ($modified['confidentiality'] ?? null));
	}//end testACaseWithoutATeamGetsItsCaseTypesTeam()

	/**
	 * A team and a confidentiality the writer gave are kept.
	 *
	 * @return void
	 */
	public function testWhatTheWriterGaveIsKept(): void {
		$modified = $this->create(
			payload: ['caseType' => ['id' => 'woo'], 'assignedGroup' => 'other-team', 'confidentiality' => 'openbaar']
		)->getModifiedData();

		self::assertArrayNotHasKey(key: 'assignedGroup', array: $modified);
		self::assertArrayNotHasKey(key: 'confidentiality', array: $modified);
	}//end testWhatTheWriterGaveIsKept()

	/**
	 * A type with no defaults, a case without a type and another schema write nothing.
	 *
	 * @return void
	 */
	public function testNothingToFillWritesNothing(): void {
		self::assertSame(expected: [], actual: $this->create(payload: ['caseType' => 'bare'])->getModifiedData());
		self::assertSame(expected: [], actual: $this->create(payload: ['title' => 'No type'])->getModifiedData());
		self::assertSame(expected: [], actual: $this->create(payload: ['caseType' => 'woo'], schemaId: 'task-schema-id')->getModifiedData());
	}//end testNothingToFillWritesNothing()

	/**
	 * An update is not a create: the defaults apply once, when the case is made.
	 *
	 * @return void
	 */
	public function testAnUpdateIsLeftAlone(): void {
		$entity = new ObjectEntity();
		$entity->setObject(['caseType' => 'woo']);
		$entity->setSchema('case-schema-id');
		$entity->setUuid('case-1');
		$event = new ObjectUpdatingEvent($entity, null);

		$this->listener()->handle(event: $event);

		self::assertSame(expected: [], actual: $event->getModifiedData());
	}//end testAnUpdateIsLeftAlone()
}//end class
