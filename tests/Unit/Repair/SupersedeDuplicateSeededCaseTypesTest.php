<?php

/**
 * A seeded case type stored twice keeps one current copy.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\SupersedeDuplicateSeededCaseTypes;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An ObjectService double holding case types and recording partial writes.
 */
class FakeDuplicateCaseTypeObjectService {

	/**
	 * The stored case types.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * Every partial write, as [id, changes].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $patches = [];

	/**
	 * Mimic ObjectService::searchObjects() on the numeric path.
	 *
	 * @param array<string, mixed> $query The query.
	 * @param mixed ...$scope Scope arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query, mixed ...$scope): array {
		return $this->rows;
	}//end searchObjects()

	/**
	 * Mimic ObjectService::patchObject().
	 *
	 * @param string $objectId The object.
	 * @param array<string, mixed> $data The changes.
	 * @param mixed $register The register.
	 * @param mixed $schema The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function patchObject(string $objectId, array $data, mixed $register, mixed $schema): array {
		$this->patches[] = [$objectId, $data];
		return array_merge(['id' => $objectId], $data);
	}//end patchObject()

	/**
	 * Mimic ObjectService::runAsSystem().
	 *
	 * @param callable $operation The work.
	 *
	 * @return mixed
	 */
	public function runAsSystem(callable $operation): mixed {
		return $operation();
	}//end runAsSystem()
}//end class

/**
 * SupersedeDuplicateSeededCaseTypes.
 *
 * @covers \OCA\Dossiq\Repair\SupersedeDuplicateSeededCaseTypes
 */
class SupersedeDuplicateSeededCaseTypesTest extends TestCase {

	/**
	 * A stored case type row.
	 *
	 * @param string      $id           The uuid.
	 * @param string      $slug         The seed slug.
	 * @param string      $created      When it was stored.
	 * @param int         $version      Its version.
	 * @param string|null $supersededBy What replaced it.
	 * @param string      $organisation The organisation that owns it.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $id, string $slug, string $created, int $version=1, ?string $supersededBy=null, string $organisation='org-a'): array {
		return [
			'@self' => ['id' => $id, 'slug' => $slug, 'created' => $created, 'organisation' => $organisation],
			'title' => $slug,
			'version' => $version,
			'supersededBy' => $supersededBy,
		];
	}//end row()

	/**
	 * The step over the given object service.
	 *
	 * @param FakeDuplicateCaseTypeObjectService $objects The object service.
	 *
	 * @return SupersedeDuplicateSeededCaseTypes
	 */
	private function step(FakeDuplicateCaseTypeObjectService $objects): SupersedeDuplicateSeededCaseTypes {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '20',
				'case_type_schema' => '28',
				default => '',
			}
		);

		return new SupersedeDuplicateSeededCaseTypes(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end step()

	/**
	 * Two copies of one seeded case type and version: the newer stays
	 * current, the older is superseded by it, so the new case picker offers
	 * one. Its cases stay where they are.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function testTheOlderCopyIsSupersededByTheNewer(): void {
		$objects = new FakeDuplicateCaseTypeObjectService();
		$objects->rows = [
			$this->row(id: 'early', slug: 'omgevingsvergunning-kleinbouw', created: '2026-10-10T14:08:00+00:00'),
			$this->row(id: 'late', slug: 'omgevingsvergunning-kleinbouw', created: '2026-10-10T23:34:00+00:00'),
			$this->row(id: 'woo', slug: 'woo-verzoek', created: '2026-10-01T09:00:00+00:00'),
		];

		$this->step(objects: $objects)->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [['early', ['supersededBy' => 'late']]], actual: $objects->patches);
	}//end testTheOlderCopyIsSupersededByTheNewer()

	/**
	 * Versions of one case type share a slug but not a version number: a
	 * draft version 2 does not supersede the published version 1, and a
	 * superseded copy is not touched again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function testVersionsAndSupersededCopiesAreLeftAlone(): void {
		$objects = new FakeDuplicateCaseTypeObjectService();
		$objects->rows = [
			$this->row(id: 'v1', slug: 'evenementenvergunning', created: '2026-09-01T09:00:00+00:00', version: 1),
			$this->row(id: 'v2', slug: 'evenementenvergunning', created: '2026-10-01T09:00:00+00:00', version: 2),
			$this->row(id: 'old', slug: 'sloopmelding', created: '2026-09-01T09:00:00+00:00', supersededBy: 'new'),
			$this->row(id: 'new', slug: 'sloopmelding', created: '2026-10-01T09:00:00+00:00'),
			$this->row(id: 'own', slug: '', created: '2026-10-02T09:00:00+00:00'),
			$this->row(id: 'own2', slug: '', created: '2026-10-03T09:00:00+00:00'),
		];

		$this->step(objects: $objects)->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [], actual: $objects->patches);
	}//end testVersionsAndSupersededCopiesAreLeftAlone()
	/**
	 * On a multi-tenant install each organisation keeps its own copy: one
	 * organisation's copy never supersedes another's, so neither drops out of
	 * its own picker. Within one organisation the older copy still goes.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function testAnOrganisationsCopyIsNeverSupersededByAnothers(): void {
		$objects = new FakeDuplicateCaseTypeObjectService();
		$objects->rows = [
			$this->row(id: 'a-early', slug: 'omgevingsvergunning-kleinbouw', created: '2026-10-10T14:08:00+00:00', organisation: 'org-a'),
			$this->row(id: 'b-only', slug: 'omgevingsvergunning-kleinbouw', created: '2026-10-11T09:00:00+00:00', organisation: 'org-b'),
			$this->row(id: 'a-late', slug: 'omgevingsvergunning-kleinbouw', created: '2026-10-10T23:34:00+00:00', organisation: 'org-a'),
		];

		$this->step(objects: $objects)->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [['a-early', ['supersededBy' => 'a-late']]], actual: $objects->patches);
	}//end testAnOrganisationsCopyIsNeverSupersededByAnothers()
}//end class
