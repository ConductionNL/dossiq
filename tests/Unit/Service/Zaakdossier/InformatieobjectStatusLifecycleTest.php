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

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCA\Dossiq\Tests\Unit\Fixtures\PatchingObjectService;
use OCA\Dossiq\Tests\Unit\Fixtures\ReplacingObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Status transitions keep the document whole.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\InformatieobjectStatusLifecycle
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
	 * The double enforces the informatieobject schema the app ships.
	 *
	 * @param class-string<ReplacingObjectService> $serviceClass The object-service shape.
	 *
	 * @return ReplacingObjectService
	 */
	private function objectService(string $serviceClass): ReplacingObjectService {
		return $serviceClass::forShippedSchema('informatieobject');

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
		);

	}//end lifecycle()
}//end class
