<?php

/**
 * The edges of the case leaf: configuration, trimming, names, links and the final flag.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Integration;

use OCA\Dossiq\Integration\CaseLeafProvider;
use OCA\Dossiq\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Integration\CaseLeafProvider
 * @covers \OCA\Dossiq\Integration\CaseLeafReader
 * @uses \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses \OCA\Dossiq\Integration\CaseLeafRegister
 */
class CaseLeafProviderEdgeTest extends TestCase {

	/**
	 * The user manager double.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $users;

	/**
	 * The schemas the store was asked about, in order.
	 *
	 * @var list<string>
	 */
	public array $reads = [];

	/**
	 * A leaf over $rows, with the given register and route.
	 *
	 * @param array<string, list<array<string, mixed>>> $rows     The rows per schema.
	 * @param string                                    $register The configured register.
	 * @param string                                    $route    What linkToRoute answers.
	 *
	 * @return CaseLeafProvider
	 */
	private function leaf(array $rows, string $register = 'dossiq', string $route = '/index.php/apps/dossiq/'): CaseLeafProvider {
		$objects = new class ($rows, $this) {
			/**
			 * Constructor.
			 *
			 * @param array<string, list<array<string, mixed>>> $rows The rows.
			 * @param CaseLeafProviderEdgeTest                  $test The test keeping the reads.
			 */
			public function __construct(private array $rows, private CaseLeafProviderEdgeTest $test) {
			}

			/**
			 * Answer the rows of the schema matching equality and `_ids` filters.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->test->reads[] = $schema;

				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
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

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnMap([['register', '', $register]]);
		$this->users = $this->createMock(IUserManager::class);
		$urls        = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->with('dossiq.dashboard.page')->willReturn($route);

		return new CaseLeafProvider(
			settingsService: $settings,
			userManager: $this->users,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end leaf()

	/**
	 * One person party with the given cases.
	 *
	 * @param list<array<string, mixed>> $cases The cases, each requested by p-1.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function world(array $cases): array {
		return [
			'brpPerson' => [['id' => 'p-1', 'masterEntityRef' => 'master-1']],
			'kvkCompany' => [],
			'case' => array_map(static fn (array $case): array => $case + ['requester' => 'p-1'], $cases),
			'caseType' => [],
			'statusType' => [],
		];
	}//end world()

	/**
	 * An object service without a register is not a configured surface, and nothing is read.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterAnswers503(): void {
		$answer = $this->leaf(rows: self::world(cases: [['id' => 'c-1']]), register: '')->byMasterEntity(ref: 'master-1');

		self::assertSame(['status' => 503, 'error' => 'The case surface is not configured.'], $answer);
		self::assertSame([], $this->reads);
	}//end testAnEmptyRegisterAnswers503()

	/**
	 * The reference is trimmed before it is looked up.
	 *
	 * @return void
	 */
	public function testThePaddedReferenceIsTrimmed(): void {
		$answer = $this->leaf(rows: self::world(cases: [['id' => 'c-1']]))->byMasterEntity(ref: "  master-1\n");

		self::assertSame(['c-1'], array_column($answer['cases'], 'id'));
	}//end testThePaddedReferenceIsTrimmed()

	/**
	 * Only a literal true closes a case; truthy look-alikes stay open.
	 *
	 * @return void
	 */
	public function testOnlyALiteralTrueIsFinal(): void {
		$leaf = $this->leaf(
			rows: self::world(
				cases: [
					['id' => 'c-true', 'isFinalStatus' => true],
					['id' => 'c-string', 'isFinalStatus' => 'true'],
					['id' => 'c-one', 'isFinalStatus' => 1],
				]
			)
		);

		self::assertSame(['c-true'], array_column($leaf->byMasterEntity(ref: 'master-1', status: 'closed')['cases'], 'id'));

		$open = $leaf->byMasterEntity(ref: 'master-1', status: 'open')['cases'];
		self::assertSame(['c-string', 'c-one'], array_column($open, 'id'));
		self::assertSame([false, false], array_column($open, 'isFinal'));
	}//end testOnlyALiteralTrueIsFinal()

	/**
	 * An assignee without a display name shows the uid, and an unassigned case asks nobody.
	 *
	 * @return void
	 */
	public function testAssigneeNames(): void {
		$leaf = $this->leaf(
			rows: self::world(
				cases: [
					['id' => 'c-1', 'deadline' => '2026-01-01', 'assignee' => 'ghost'],
					['id' => 'c-2', 'deadline' => '2026-02-01', 'assignee' => 'blank'],
					['id' => 'c-3', 'deadline' => '2026-03-01'],
				]
			)
		);
		$this->users->expects($this->exactly(2))
			->method('getDisplayName')
			->willReturnMap([['ghost', null], ['blank', '']]);

		$cases = $leaf->byMasterEntity(ref: 'master-1')['cases'];

		self::assertSame(['ghost', 'blank', ''], array_column($cases, 'assigneeName'));
		self::assertSame(['ghost', 'blank', ''], array_column($cases, 'assignee'));
	}//end testAssigneeNames()

	/**
	 * The case link is encoded and never doubles the slash.
	 *
	 * @return void
	 */
	public function testTheCaseLinkIsEncoded(): void {
		$answer = $this->leaf(rows: self::world(cases: [['id' => 'c/1 x']]), route: '/apps/dossiq')->byMasterEntity(ref: 'master-1');

		self::assertSame('/apps/dossiq/cases/c%2F1%20x', $answer['cases'][0]['url']);
	}//end testTheCaseLinkIsEncoded()

	/**
	 * A case whose type and status cannot be found shows empty text, and a case with neither reads no linked schema.
	 *
	 * @return void
	 */
	public function testUnresolvedLinksShowEmptyText(): void {
		$rows = self::world(cases: [['id' => 'c-1', 'caseType' => 't-gone', 'status' => 's-gone', 'identifier' => 7]]);

		$row = $this->leaf(rows: $rows)->byMasterEntity(ref: 'master-1')['cases'][0];

		self::assertSame('', $row['caseTypeTitle']);
		self::assertSame('', $row['statusName']);
		self::assertSame('', $row['statusColour']);
		self::assertSame('7', $row['identifier']);
		self::assertSame('', $row['title']);

		$this->reads = [];
		$this->leaf(rows: self::world(cases: [['id' => 'c-1']]))->byMasterEntity(ref: 'master-1');
		self::assertSame(['brpPerson', 'kvkCompany', 'case'], $this->reads);
	}//end testUnresolvedLinksShowEmptyText()

	/**
	 * Closed cases without an end date sort after dated ones, newest first.
	 *
	 * @return void
	 */
	public function testClosedCasesWithoutAnEndDateSortLast(): void {
		$leaf = $this->leaf(
			rows: self::world(
				cases: [
					['id' => 'c-undated', 'isFinalStatus' => true],
					['id' => 'c-old', 'isFinalStatus' => true, 'endDate' => '2024-05-01'],
					['id' => 'c-new', 'isFinalStatus' => true, 'endDate' => '2026-05-01'],
					['id' => 'c-open', 'isFinalStatus' => false],
				]
			)
		);

		self::assertSame(['c-open', 'c-new', 'c-old', 'c-undated'], array_column($leaf->byMasterEntity(ref: 'master-1')['cases'], 'id'));
	}//end testClosedCasesWithoutAnEndDateSortLast()
}//end class
