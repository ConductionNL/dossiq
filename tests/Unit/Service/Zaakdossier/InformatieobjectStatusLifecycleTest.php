<?php

/**
 * InformatieobjectStatusLifecycle against an object service that replaces.
 *
 * OpenRegister's `saveObject()` with a uuid is PUT-semantic: the payload IS the
 * new object. A property the payload leaves out is written back as null, and
 * with hard validation on (the default) a payload missing a required property
 * is refused outright. `transition()` used to save `['status' => ...]` alone,
 * so every document status change dropped the title, the file name, the
 * confidentiality and the document type, and OpenRegister refused the save.
 * No document ever left draft, and the bulk run reported a failure for every
 * document while the e2e citation for it stayed green.
 *
 * The earlier tests could not see this because their doubled object service
 * accepted any payload. The double here behaves like OpenRegister: it reads
 * the required and declared properties from the register fragment the app
 * ships, refuses a save missing a required one, and null-fills the rest.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An object service that replaces on save, the way OpenRegister does.
 *
 * Deliberately has no `patchObject()`: this is the older OpenRegister shape,
 * where a partial write can only be done by reading and saving the whole.
 */
class ReplacingObjectService {

	/**
	 * Stored objects by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $stored = [];

	/**
	 * Every payload saveObject() was handed, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Constructor.
	 *
	 * @param string[] $required   The schema's required properties.
	 * @param string[] $properties The schema's declared properties.
	 */
	public function __construct(
		private readonly array $required,
		private readonly array $properties,
	) {
	}

	/**
	 * Read one object back, shaped like ObjectEntity::jsonSerialize().
	 *
	 * @param string          $id       The uuid.
	 * @param string|int|null $register Unused register scope.
	 * @param string|int|null $schema   Unused schema scope.
	 *
	 * @return object The stored object with its id and an `@self` block.
	 *
	 * @throws DoesNotExistException When nothing is stored under the id.
	 */
	public function find(string $id, string|int|null $register = null, string|int|null $schema = null): object {
		if (isset($this->stored[$id]) === false) {
			throw new DoesNotExistException('not found: ' . $id);
		}

		return $this->entity(uuid: $id);
	}

	/**
	 * Save with PUT semantics: the payload replaces the stored object.
	 *
	 * @param array<string, mixed> $object   The payload.
	 * @param string|int|null      $register Unused register scope.
	 * @param string|int|null      $schema   Unused schema scope.
	 * @param string|null          $uuid     The uuid to update, or null to take it from the payload.
	 *
	 * @return object The stored object.
	 *
	 * @throws RuntimeException When a required property is missing, as OpenRegister's validation does.
	 */
	public function saveObject(
		array $object,
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): object {
		$this->saves[] = $object;

		$self = (array)($object['@self'] ?? []);
		$uuid = ($uuid ?? (string)($object['id'] ?? ($self['id'] ?? '')));
		unset($object['@self'], $object['id']);

		$missing = [];
		foreach ($this->required as $property) {
			if (isset($object[$property]) === false) {
				$missing[] = $property;
			}
		}

		if ($missing !== []) {
			throw new RuntimeException('The required properties (' . implode(', ', $missing) . ') are missing.');
		}

		foreach ($this->properties as $property) {
			if (array_key_exists($property, $object) === false) {
				$object[$property] = null;
			}
		}

		$this->stored[$uuid] = $object;

		return $this->entity(uuid: $uuid);
	}

	/**
	 * Wrap a stored object the way OpenRegister's ObjectEntity serialises.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object An object exposing jsonSerialize().
	 */
	protected function entity(string $uuid): object {
		$data = array_merge(
			$this->stored[$uuid],
			['id' => $uuid, '@self' => ['id' => $uuid, 'register' => 'dossiq', 'schema' => 'informatieobject']]
		);

		return new class($data) {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The serialised object.
			 */
			public function __construct(
				private readonly array $data,
			) {
			}

			/**
			 * The serialised object.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}
}//end class

/**
 * The current OpenRegister shape: a merging `patchObject()` beside the replacing save.
 */
class PatchingObjectService extends ReplacingObjectService {

	/**
	 * Merge the patch onto the stored object and save the result.
	 *
	 * @param string               $objectId The uuid.
	 * @param array<string, mixed> $data     The fields to change.
	 * @param string|int|null      $register Unused register scope.
	 * @param string|int|null      $schema   Unused schema scope.
	 *
	 * @return object The stored object.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string|int|null $register = null,
		string|int|null $schema = null,
	): object {
		if (isset($this->stored[$objectId]) === false) {
			throw new DoesNotExistException('not found: ' . $objectId);
		}

		return $this->saveObject(
			object: array_merge($this->stored[$objectId], $data),
			register: $register,
			schema: $schema,
			uuid: $objectId
		);
	}
}//end class

/**
 * Status transitions keep the document whole.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle
 *
 * @uses \OCA\Dossiq\Service\CaseFieldWriter
 */
class InformatieobjectStatusLifecycleTest extends TestCase {

	/**
	 * A document as the upload stores it: every required property present.
	 *
	 * @var array<string, mixed>
	 */
	private const DOCUMENT = [
		'title' => 'Aanvraagformulier',
		'fileName' => 'aanvraag.pdf',
		'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk',
		'informatieobjecttype' => 'iot-1',
		'auteur' => 'behandelaar',
		'format' => 'application/pdf',
		'fileId' => 4711,
		'status' => 'draft',
	];

	/**
	 * The two object-service shapes the lifecycle has to work against.
	 *
	 * @return array<string, array{0: class-string<ReplacingObjectService>}>
	 */
	public static function objectServices(): array {
		return [
			'OpenRegister with patchObject' => [PatchingObjectService::class],
			'OpenRegister without patchObject' => [ReplacingObjectService::class],
		];
	}//end objectServices()

	/**
	 * A single transition changes the status and keeps everything else.
	 *
	 * @dataProvider objectServices
	 *
	 * @param class-string<ReplacingObjectService> $serviceClass The object-service shape.
	 *
	 * @return void
	 */
	public function testAStatusChangeKeepsTheRestOfTheDocument(string $serviceClass): void {
		$objectService = $this->objectService(serviceClass: $serviceClass);
		$objectService->stored['inf-1'] = self::DOCUMENT;

		$result = $this->lifecycle(objectService: $objectService)->transition('inf-1', 'final');

		$this->assertSame('final', $result['status']);
		$stored = $objectService->stored['inf-1'];
		$this->assertSame('final', $stored['status'], 'the new status must be stored');
		$this->assertIsString($stored['lockedOn'], 'going final must stamp the lock');
		foreach (array_diff_key(self::DOCUMENT, ['status' => true]) as $field => $value) {
			$this->assertSame($value, $stored[$field], sprintf('the status change must keep %s', $field));
		}

	}//end testAStatusChangeKeepsTheRestOfTheDocument()

	/**
	 * Archiving a final document keeps its lock stamp.
	 *
	 * @dataProvider objectServices
	 *
	 * @param class-string<ReplacingObjectService> $serviceClass The object-service shape.
	 *
	 * @return void
	 */
	public function testArchivingKeepsTheLockStamp(string $serviceClass): void {
		$objectService = $this->objectService(serviceClass: $serviceClass);
		$objectService->stored['inf-1'] = array_merge(
			self::DOCUMENT,
			['status' => 'final', 'lockedOn' => '2026-09-01T10:00:00']
		);

		$this->lifecycle(objectService: $objectService)->transition('inf-1', 'archived');

		$stored = $objectService->stored['inf-1'];
		$this->assertSame('archived', $stored['status']);
		$this->assertSame('2026-09-01T10:00:00', $stored['lockedOn'], 'archiving must not clear the lock');
		$this->assertSame('Aanvraagformulier', $stored['title']);

	}//end testArchivingKeepsTheLockStamp()

	/**
	 * The bulk run reports every document it moved as a success.
	 *
	 * This is REQ-ZAK-008c: a bulk status transition answers per document.
	 * The answer has to be TRUE for a document that could move, not merely
	 * present, which is all the old citation checked.
	 *
	 * @dataProvider objectServices
	 *
	 * @param class-string<ReplacingObjectService> $serviceClass The object-service shape.
	 *
	 * @return void
	 */
	public function testABulkRunMovesEveryDocumentThatMayMove(string $serviceClass): void {
		$objectService = $this->objectService(serviceClass: $serviceClass);
		$objectService->stored['inf-1'] = self::DOCUMENT;
		$objectService->stored['inf-2'] = array_merge(self::DOCUMENT, ['title' => 'Besluit']);
		$objectService->stored['inf-3'] = array_merge(self::DOCUMENT, ['status' => 'archived']);

		$results = $this->lifecycle(objectService: $objectService)->transitionMany(
			['inf-1', 'inf-2', 'inf-3'],
			'final'
		);

		$this->assertSame(['id' => 'inf-1', 'success' => true], $results[0]);
		$this->assertSame(['id' => 'inf-2', 'success' => true], $results[1]);
		$this->assertFalse($results[2]['success'], 'an archived document may not go back to final');
		$this->assertSame('final', $objectService->stored['inf-1']['status']);
		$this->assertSame('Besluit', $objectService->stored['inf-2']['title']);
		$this->assertSame('archived', $objectService->stored['inf-3']['status']);

	}//end testABulkRunMovesEveryDocumentThatMayMove()

	/**
	 * The double reads the schema from the register fragment the app ships.
	 *
	 * @param class-string<ReplacingObjectService> $serviceClass The object-service shape.
	 *
	 * @return ReplacingObjectService
	 */
	private function objectService(string $serviceClass): ReplacingObjectService {
		$fragment = json_decode(
			(string)file_get_contents(
				__DIR__ . '/../../../../lib/Settings/register.d/70-document-zaakdossier.json'
			),
			true
		);
		$schema = $fragment['components']['schemas']['informatieobject'];

		return new $serviceClass($schema['required'], array_keys($schema['properties']));

	}//end objectService()

	/**
	 * The lifecycle under test, over mocked settings.
	 *
	 * @param object $objectService The object service it should reach.
	 *
	 * @return InformatieobjectStatusLifecycle
	 */
	private function lifecycle(object $objectService): InformatieobjectStatusLifecycle {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'dossier_informatieobject_schema' => 'informatieobject',
			][$key] ?? $default
		);

		return new InformatieobjectStatusLifecycle(
			$settings,
			$this->createMock(LoggerInterface::class),
			new CaseFieldWriter(),
		);

	}//end lifecycle()
}//end class
