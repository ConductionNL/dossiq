<?php

/**
 * The cases of a party, read by its master entity, as pipelinq's client page
 * asks for them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/case-leaf-by-master-entity/specs/case-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Integration;

use OCA\Dossiq\Integration\CaseLeafProvider;
use OCA\Dossiq\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Integration\CaseLeafProvider
 */
class CaseLeafProviderTest extends TestCase {

	/**
	 * The filters each read was asked with, per schema.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The leaf over these rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
	 *
	 * @return CaseLeafProvider The leaf.
	 */
	private function leaf(array $rows): CaseLeafProvider {
		$this->objects = new class ($rows) {
			/**
			 * The reads, as [schema, filters].
			 *
			 * @var array<int, array{0: string, 1: array<string, mixed>}>
			 */
			public array $reads = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * @param string               $register The register.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->reads[] = [$schema, $filters];
				return array_values(
					array_filter(
						$this->rows[$schema] ?? [],
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if ($key === '_limit') {
									continue;
								}

								if ($key === '_ids') {
									if (in_array($row['id'], $value, true) === false) {
										return false;
									}

									continue;
								}

								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}
		};
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturn('dossiq');
		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => ['lars' => 'Lars de Koning'][$uid] ?? null);
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/apps/dossiq/');

		return new CaseLeafProvider(
			settingsService: $settings,
			userManager: $users,
			urlGenerator: $urls,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end leaf()

	/**
	 * Buurtvereniging Oost in dossiq: one open case and two closed ones, and
	 * a case of another party.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function buurtoost(): array {
		return [
			'brpPerson' => [],
			'kvkCompany' => [
				['id' => 'org-1', 'masterEntityRef' => 'master-7'],
				['id' => 'org-2', 'masterEntityRef' => 'master-8'],
			],
			'case' => [
				[
					'id' => 'c-old', 'requester' => 'org-1', 'identifier' => '2025-0388', 'title' => 'Melding kapot speeltoestel',
					'caseType' => 't-melding', 'status' => 's-done', 'isFinalStatus' => true,
					'endDate' => '2025-11-03', 'deadline' => '2025-11-10', 'assignee' => 'anouk',
				],
				[
					'id' => 'c-open', 'requester' => 'org-1', 'identifier' => '2026-0070', 'title' => 'Subsidie voor het straatfeest',
					'caseType' => 't-subsidie', 'status' => 's-besluit', 'isFinalStatus' => false,
					'deadline' => '2026-10-20', 'assignee' => 'lars',
				],
				[
					'id' => 'c-recent', 'requester' => 'org-1', 'identifier' => '2026-0031', 'title' => 'Evenementenvergunning',
					'caseType' => 't-melding', 'status' => 's-done', 'isFinalStatus' => true,
					'endDate' => '2026-06-12', 'deadline' => '2026-06-30',
				],
				['id' => 'c-other', 'requester' => 'org-2', 'identifier' => '2026-0001', 'title' => 'Not this party', 'isFinalStatus' => false],
			],
			'caseType' => [
				['id' => 't-subsidie', 'title' => 'Subsidie'],
				['id' => 't-melding', 'title' => 'Melding'],
			],
			'statusType' => [
				['id' => 's-besluit', 'name' => 'Besluit', 'colour' => '#0b6e4f'],
				['id' => 's-done', 'name' => 'Afgerond', 'colour' => ''],
			],
		];
	}//end buurtoost()

	/**
	 * The party's cases come back open first, then closed newest first, with
	 * their type, status, handler and a link to the case page.
	 *
	 * @return void
	 */
	public function testReadsThePartysCasesOpenFirstThenNewestClosed(): void {
		$answer = $this->leaf(rows: $this->buurtoost())->byMasterEntity(ref: 'master-7');

		self::assertSame(expected: 200, actual: $answer['status']);
		self::assertSame(expected: ['2026-0070', '2026-0031', '2025-0388'], actual: array_column($answer['cases'], 'identifier'));
		self::assertSame(
			expected: [
				'id' => 'c-open',
				'identifier' => '2026-0070',
				'title' => 'Subsidie voor het straatfeest',
				'caseTypeTitle' => 'Subsidie',
				'statusName' => 'Besluit',
				'statusColour' => '#0b6e4f',
				'isFinal' => false,
				'deadline' => '2026-10-20',
				'endDate' => '',
				'assignee' => 'lars',
				'assigneeName' => 'Lars de Koning',
				'url' => '/index.php/apps/dossiq/cases/c-open',
			],
			actual: $answer['cases'][0]
		);
		self::assertSame(expected: 'anouk', actual: $answer['cases'][2]['assigneeName']);
	}//end testReadsThePartysCasesOpenFirstThenNewestClosed()

	/**
	 * The status filter keeps the open or the closed cases only.
	 *
	 * @return void
	 */
	public function testFiltersOpenAndClosed(): void {
		$open = $this->leaf(rows: $this->buurtoost())->byMasterEntity(ref: 'master-7', status: 'open');
		$closed = $this->leaf(rows: $this->buurtoost())->byMasterEntity(ref: 'master-7', status: 'closed');

		self::assertSame(expected: ['c-open'], actual: array_column($open['cases'], 'id'));
		self::assertSame(expected: ['c-recent', 'c-old'], actual: array_column($closed['cases'], 'id'));
	}//end testFiltersOpenAndClosed()

	/**
	 * An empty or unknown master entity has no cases, and reads no case.
	 *
	 * @return void
	 */
	public function testAnUnknownMasterEntityHasNoCases(): void {
		self::assertSame(expected: ['status' => 200, 'cases' => []], actual: $this->leaf(rows: $this->buurtoost())->byMasterEntity(ref: ' '));
		self::assertSame(expected: ['status' => 200, 'cases' => []], actual: $this->leaf(rows: $this->buurtoost())->byMasterEntity(ref: 'master-unknown'));
		self::assertNotContains(needle: 'case', haystack: array_column($this->objects->reads, 0));
	}//end testAnUnknownMasterEntityHasNoCases()

	/**
	 * An unknown status filter is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownStatusFilter(): void {
		self::assertSame(expected: 400, actual: $this->leaf(rows: $this->buurtoost())->byMasterEntity(ref: 'master-7', status: 'pending')['status']);
	}//end testRefusesAnUnknownStatusFilter()

	/**
	 * A case type title and a status name are translatable, so a read answers
	 * them as language maps; the leaf hands the host app their text, not "Array".
	 *
	 * @return void
	 */
	public function testReadsTranslatableTitlesAsText(): void {
		$rows = $this->buurtoost();
		$rows['caseType'] = [['id' => 't-subsidie', 'title' => ['en' => 'Grant', 'nl' => 'Subsidie']]];
		$rows['statusType'] = [['id' => 's-besluit', 'name' => ['en' => 'Decision', 'nl' => 'Besluit'], 'colour' => 'purple']];

		$answer = $this->leaf(rows: $rows)->byMasterEntity(ref: 'master-7', status: 'open');

		self::assertSame(expected: 'Subsidie', actual: $answer['cases'][0]['caseTypeTitle']);
		self::assertSame(expected: 'Besluit', actual: $answer['cases'][0]['statusName']);
	}//end testReadsTranslatableTitlesAsText()

	/**
	 * Without a readable status the case's own public label is the status name.
	 *
	 * @return void
	 */
	public function testFallsBackToThePublicLabelForAnEmptyStatusName(): void {
		$rows = $this->buurtoost();
		$rows['statusType'] = [['id' => 's-besluit', 'name' => [], 'colour' => '']];
		$rows['case'][1]['statusPublicLabel'] = 'In behandeling';

		$answer = $this->leaf(rows: $rows)->byMasterEntity(ref: 'master-7', status: 'open');

		self::assertSame(expected: 'In behandeling', actual: $answer['cases'][0]['statusName']);
	}//end testFallsBackToThePublicLabelForAnEmptyStatusName()
}//end class
