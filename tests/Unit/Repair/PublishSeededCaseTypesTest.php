<?php

/**
 * The seeded case types that were stored as drafts are published once.
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
 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\PublishSeededCaseTypes;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An ObjectService double holding case types and recording partial writes.
 */
class FakePublishCaseTypeObjectService {

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
	 * Ids whose write fails.
	 *
	 * @var array<int, string>
	 */
	public array $failing = [];

	/**
	 * How often the case types were read.
	 *
	 * @var int
	 */
	public int $reads = 0;

	/**
	 * Mimic ObjectService::searchObjects() on the numeric path.
	 *
	 * @param array<string, mixed> $query The query.
	 * @param mixed ...$scope Scope arguments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query, mixed ...$scope): array {
		$this->reads++;
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
		if (in_array($objectId, $this->failing, true) === true) {
			throw new RuntimeException('refused');
		}

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
 * PublishSeededCaseTypes.
 *
 * @covers \OCA\Dossiq\Repair\PublishSeededCaseTypes
 */
class PublishSeededCaseTypesTest extends TestCase {

	/**
	 * The app config values written, by key.
	 *
	 * @var array<string, string>
	 */
	private array $written = [];

	/**
	 * A stored case type row.
	 *
	 * @param string $id The uuid.
	 * @param string $slug The seed slug.
	 * @param bool|null $isDraft The stored isDraft, or null for absent.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $id, string $slug, ?bool $isDraft): array {
		$row = ['@self' => ['id' => $id, 'slug' => $slug], 'title' => $slug];
		if ($isDraft !== null) {
			$row['isDraft'] = $isDraft;
		}

		return $row;
	}//end row()

	/**
	 * The step over the given object service.
	 *
	 * @param FakePublishCaseTypeObjectService $objects The object service.
	 * @param string $done The stored done marker.
	 *
	 * @return PublishSeededCaseTypes
	 */
	private function step(FakePublishCaseTypeObjectService $objects, string $done=''): PublishSeededCaseTypes {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '20',
				'case_type_schema' => '28',
				default => '',
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($done);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;
				return true;
			}
		);

		return new PublishSeededCaseTypes(
			settingsService: $settings,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * Only the seeded drafts are published; a published one and a case type
	 * the seeds do not ship are left alone; the run is recorded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
	 */
	public function testTheSeededDraftsArePublishedAndNothingElse(): void {
		$objects = new FakePublishCaseTypeObjectService();
		$objects->rows = [
			$this->row(id: 'sloop', slug: 'sloopmelding', isDraft: true),
			$this->row(id: 'klein', slug: 'omgevingsvergunning-kleinbouw', isDraft: null),
			$this->row(id: 'woo', slug: 'woo-verzoek', isDraft: false),
			$this->row(id: 'own', slug: 'eigen-concept', isDraft: true),
			$this->row(id: 'iban', slug: 'leverancier-iban-wijziging', isDraft: false),
		];

		$this->step(objects: $objects)->run($this->createMock(IOutput::class));

		$this->assertSame(
			[['sloop', ['isDraft' => false]], ['klein', ['isDraft' => false]]],
			$objects->patches
		);
		$this->assertSame('1', ($this->written[PublishSeededCaseTypes::DONE_KEY] ?? null));
	}//end testTheSeededDraftsArePublishedAndNothingElse()

	/**
	 * Once recorded, the step does not run again, so a case type an
	 * administrator made a draft afterwards stays a draft.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
	 */
	public function testARecordedRunDoesNotRunAgain(): void {
		$objects = new FakePublishCaseTypeObjectService();
		$objects->rows = [$this->row(id: 'sloop', slug: 'sloopmelding', isDraft: true)];

		$this->step(objects: $objects, done: '1')->run($this->createMock(IOutput::class));

		$this->assertSame(0, $objects->reads);
		$this->assertSame([], $objects->patches);
	}//end testARecordedRunDoesNotRunAgain()

	/**
	 * A failed write is not recorded as done, so the next repair tries again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
	 */
	public function testAFailedWriteIsTriedAgainNextTime(): void {
		$objects = new FakePublishCaseTypeObjectService();
		$objects->rows = [$this->row(id: 'sloop', slug: 'sloopmelding', isDraft: true)];
		$objects->failing = ['sloop'];

		$this->step(objects: $objects)->run($this->createMock(IOutput::class));

		$this->assertArrayNotHasKey(PublishSeededCaseTypes::DONE_KEY, $this->written);
	}//end testAFailedWriteIsTriedAgainNextTime()

	/**
	 * Every slug the step publishes is a case type the seeds ship as published,
	 * and every shipped case type says whether it is a draft. A seed without
	 * `isDraft` is stored as a draft, because the schema defaults it to true.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
	 */
	public function testEveryShippedCaseTypeDeclaresIsDraft(): void {
		$root = dirname(__DIR__, 3) . '/lib/Settings';
		$shipped = [];

		$registers = array_merge([$root . '/dossiq_register.json'], (glob($root . '/register.d/*.json') ?: []));
		foreach ($registers as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			foreach (($data['components']['objects'] ?? []) as $object) {
				if (($object['@self']['schema'] ?? '') === 'caseType') {
					$shipped[(string)($object['@self']['slug'] ?? '')] = $object;
				}
			}
		}

		foreach (['vth_seed_data.json', 'case_flow_seed_data.json'] as $seed) {
			$data = json_decode((string)file_get_contents($root . '/' . $seed), true);
			foreach (($data['caseTypes'] ?? []) as $caseType) {
				$shipped[(string)($caseType['slug'] ?? '')] = $caseType;
			}
		}

		$undeclared = array_keys(array_filter($shipped, static fn (array $row): bool => array_key_exists('isDraft', $row) === false));
		$this->assertSame([], $undeclared, 'these shipped case types do not say whether they are a draft');

		foreach (PublishSeededCaseTypes::SLUGS as $slug) {
			$this->assertArrayHasKey($slug, $shipped, $slug . ' is not a shipped case type');
			$this->assertFalse($shipped[$slug]['isDraft'], $slug . ' is not shipped as published');
		}
	}//end testEveryShippedCaseTypeDeclaresIsDraft()
}//end class
