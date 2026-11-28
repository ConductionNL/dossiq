<?php

/**
 * Unit tests for DossierRecords: the joins and informatieobject records of a case dossier.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use InvalidArgumentException;
use OCA\Dossiq\Service\InformatieobjectAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DossierRecords;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The dossier of a case in the register, through DossierRecords.
 *
 * @spec openspec/changes/archive/2026-06-13-document-zaakdossier/tasks.md#T02
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class DossierRecordsTest extends TestCase {

	private const CONFIG = [
		'register' => 'dossiq',
		'dossier_zaakinformatieobject_schema' => 'zio',
		'dossier_informatieobject_schema' => 'io',
		'dossier_informatieobjecttype_schema' => 'iot',
	];

	/**
	 * The register the dossier is stored in.
	 *
	 * @var LifecycleRegister
	 */
	private LifecycleRegister $register;

	/**
	 * A fresh register per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new LifecycleRegister();
	}//end setUp()

	/**
	 * Records over the register.
	 *
	 * @param array<string, string>            $config The configuration values.
	 * @param InformatieobjectAccessGuard|null $guard  The access guard; a mock when left out.
	 * @param bool                             $withOr Whether OpenRegister is available.
	 *
	 * @return DossierRecords The records.
	 */
	private function records(array $config = self::CONFIG, ?InformatieobjectAccessGuard $guard = null, bool $withOr = true): DossierRecords {
		$objectService = null;
		if ($withOr === true) {
			$objectService = $this->register;
		}

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new DossierRecords(
			settingsService: $settings,
			accessGuard: ($guard ?? $this->createMock(originalClassName: InformatieobjectAccessGuard::class))
		);
	}//end records()

	/**
	 * Linking twice creates one join; the second answers the first.
	 *
	 * @return void
	 */
	public function testLinkingTwiceCreatesOneJoin(): void {
		$first = $this->records()->link(caseId: 'case-1', infoObjectId: 'doc-1');
		$second = $this->records()->link(caseId: 'case-1', infoObjectId: 'doc-1');

		$this->assertTrue(condition: $first['duplicated']);
		$this->assertNotSame(expected: '', actual: $first['id']);
		$this->assertFalse(condition: $second['duplicated']);
		$this->assertSame(expected: $first['id'], actual: $second['id']);
		$this->assertCount(expectedCount: 1, haystack: $this->register->all('zio'));
		$this->assertSame(expected: 'doc-1', actual: array_values($this->register->all('zio'))[0]['informatieobject']);
	}//end testLinkingTwiceCreatesOneJoin()

	/**
	 * Unlinking removes the joins and keeps the document.
	 *
	 * @return void
	 */
	public function testUnlinkingRemovesTheJoinsOnly(): void {
		$this->register->seed(schema: 'zio', uuid: 'j-1', row: ['case' => 'case-1', 'informatieobject' => 'doc-1']);
		$this->register->seed(schema: 'io', uuid: 'doc-1', row: ['title' => 'Brief']);

		$this->assertTrue(condition: $this->records()->unlink(caseId: 'case-1', infoObjectId: 'doc-1'));
		$this->assertSame(expected: [], actual: $this->register->all('zio'));
		$this->assertSame(expected: 'Brief', actual: $this->register->row('io', 'doc-1')['title']);
		$this->assertFalse(condition: $this->records()->unlink(caseId: 'case-1', infoObjectId: 'doc-1'));
	}//end testUnlinkingRemovesTheJoinsOnly()

	/**
	 * The documents of a case are the ones its joins point at that resolve.
	 *
	 * @return void
	 */
	public function testTheDocumentsOfACaseAreItsResolvedJoins(): void {
		$this->register->seed(schema: 'zio', uuid: 'j-1', row: ['case' => 'case-1', 'informatieobject' => 'doc-1']);
		$this->register->seed(schema: 'zio', uuid: 'j-2', row: ['case' => 'case-1', 'informatieobject' => 'doc-gone']);
		$this->register->seed(schema: 'zio', uuid: 'j-3', row: ['case' => 'case-1']);
		$this->register->seed(schema: 'zio', uuid: 'j-4', row: ['case' => 'case-2', 'informatieobject' => 'doc-2']);
		$this->register->seed(schema: 'io', uuid: 'doc-1', row: ['title' => 'Brief']);
		$this->register->seed(schema: 'io', uuid: 'doc-2', row: ['title' => 'Ander']);

		$documents = $this->records()->documentsOf(caseId: 'case-1');

		$this->assertSame(expected: ['Brief'], actual: array_column($documents, 'title'));
	}//end testTheDocumentsOfACaseAreItsResolvedJoins()

	/**
	 * Find and patch read and change one record; both need OpenRegister.
	 *
	 * @return void
	 */
	public function testFindAndPatchOneRecord(): void {
		$this->register->seed(schema: 'io', uuid: 'doc-1', row: ['title' => 'Brief', 'status' => 'draft']);

		$this->records()->patch(infoObjectId: 'doc-1', changes: ['title' => 'Besluit']);

		$this->assertSame(expected: 'Besluit', actual: $this->records()->find(infoObjectId: 'doc-1')['title']);
		$this->assertSame(expected: 'draft', actual: $this->register->row('io', 'doc-1')['status']);
		$this->expectException(exception: RuntimeException::class);
		$this->records(withOr: false)->find(infoObjectId: 'doc-1');
	}//end testFindAndPatchOneRecord()

	/**
	 * A type's default classification is its own valid level, else `intern`.
	 *
	 * @return void
	 */
	public function testTheDefaultClassificationIsTheTypesLevel(): void {
		$this->register->seed(schema: 'iot', uuid: 'type-1', row: ['vertrouwelijkheidaanduiding' => 'vertrouwelijk']);
		$this->register->seed(schema: 'iot', uuid: 'type-2', row: ['vertrouwelijkheidaanduiding' => 'nonsense']);

		$this->assertSame(expected: 'vertrouwelijk', actual: $this->records()->defaultClassification(type: 'type-1'));
		$this->assertSame(expected: 'intern', actual: $this->records()->defaultClassification(type: 'type-2'));
		$this->assertSame(expected: 'intern', actual: $this->records(withOr: false)->defaultClassification(type: 'type-1'));
	}//end testTheDefaultClassificationIsTheTypesLevel()

	/**
	 * An upload takes the type default, may tighten it, and may not loosen it.
	 *
	 * @return void
	 */
	public function testAnUploadMayOnlyTightenTheClassification(): void {
		$this->register->seed(schema: 'iot', uuid: 'type-1', row: ['vertrouwelijkheidaanduiding' => 'vertrouwelijk']);
		$guard = $this->createMock(originalClassName: InformatieobjectAccessGuard::class);
		$guard->method('isClassificationAllowed')->willReturnCallback(
			static fn (string $default, string $requested): bool => $requested !== 'openbaar'
		);
		$records = $this->records(guard: $guard);

		$this->assertSame(expected: 'vertrouwelijk', actual: $records->uploadClassification(type: 'type-1', metadata: []));
		$this->assertSame(
			expected: 'geheim',
			actual: $records->uploadClassification(type: 'type-1', metadata: ['vertrouwelijkheidaanduiding' => 'geheim'])
		);
		$this->expectException(exception: InvalidArgumentException::class);
		$records->uploadClassification(type: 'type-1', metadata: ['vertrouwelijkheidaanduiding' => 'openbaar']);
	}//end testAnUploadMayOnlyTightenTheClassification()

	/**
	 * The saved uuid is read from an entity, an array id or its `@self`.
	 *
	 * @return void
	 */
	public function testTheSavedUuidIsReadFromEveryShape(): void {
		$entity = new class {
			/**
			 * The entity's uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'uuid-entity';
			}
		};

		$this->assertSame(expected: 'uuid-entity', actual: $this->records()->savedUuid(saved: $entity));
		$this->assertSame(expected: 'uuid-row', actual: $this->records()->savedUuid(saved: ['id' => 'uuid-row']));
		$this->assertSame(expected: 'uuid-self', actual: $this->records()->savedUuid(saved: ['@self' => ['id' => 'uuid-self']]));
		$this->assertSame(expected: '', actual: $this->records()->savedUuid(saved: []));
	}//end testTheSavedUuidIsReadFromEveryShape()
}//end class
