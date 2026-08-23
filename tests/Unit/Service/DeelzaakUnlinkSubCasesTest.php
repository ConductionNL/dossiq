<?php

/**
 * DeelzaakService::unlinkSubCases Unit Tests
 *
 * Covers procest#793: the unlink used to report a bare count, silently
 * truncating at the 200-record page and swallowing per-record failures, while
 * the caller went on to delete the parent.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\DeelzaakService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for DeelzaakService::unlinkSubCases().
 *
 * The paging loop calls listSubCases(), and the service is constructed with a
 * REAL CaseObjectReader (as DeelzaakServiceTest does, for the same reason), so
 * both are executed here without being the subject, and are therefore declared
 * below so PHPUnit's strict coverage metadata does not report every case as
 * risky.
 *
 * ⚠️ Never write a literal coverage-annotation token in this prose. PHPUnit
 * matches them MID-LINE anywhere in the docblock, so the sentence above
 * originally read "Declared with <token> so ..." and PHPUnit registered a
 * coverage target literally named `so`, failing the cell with an "is invalid"
 * warning while every test still passed.
 *
 * ⚠️ Declared at CLASS scope, matching DeelzaakServiceTest. A method-scoped
 * declaration is not enough: constructing the service runs `__construct` and
 * the SearchesObjects trait, neither of which is the named method, so strict
 * coverage metadata reports every case as risky even with the collaborators
 * declared.
 *
 * @covers \OCA\Dossiq\Service\DeelzaakService
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses   \OCA\Dossiq\Service\Deelzaak\CaseObjectReader
 */
class DeelzaakUnlinkSubCasesTest extends TestCase {

	/**
	 * Settings service, mocked.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Logger, mocked.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the shared config stub.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'procest',
					'case_schema' => 'case',
					default => $default,
				};
			}
		);
	}//end setUp()

	/**
	 * Build an object service stub that pages like OpenRegister does.
	 *
	 * `searchObjects()` returns at most `$pageSize` records that still carry a
	 * `parentCase`, and `saveObject()` clears it — so paging terminates exactly
	 * as it does against a real register. `$failIds` never clear, which models
	 * a record that cannot be unlinked.
	 *
	 * @param array<int, string> $ids Sub-case ids to seed.
	 * @param int $pageSize Records returned per search.
	 * @param array<int, string> $failIds Ids whose save throws.
	 *
	 * @return object The stub.
	 */
	private function makeObjectService(array $ids, int $pageSize, array $failIds = []): object {
		$store = [];
		foreach ($ids as $id) {
			$store[$id] = ['id' => $id, 'parentCase' => 'parent-1'];
		}

		return new class($store, $pageSize, $failIds) {
			/**
			 * Number of saveObject() calls made.
			 *
			 * @var int
			 */
			public int $saveCalls = 0;

			/**
			 * Construct the stub.
			 *
			 * @param array<string, array<string, mixed>> $store Seed map.
			 * @param int $pageSize Page size.
			 * @param array<int, string> $failIds Failing ids.
			 */
			public function __construct(
				private array $store,
				private int $pageSize,
				private array $failIds,
			) {
			}//end __construct()

			/**
			 * Mimic the slug-aware search bridge used by SearchesObjects.
			 *
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 * @param array<string, mixed> $filters Query filters.
			 *
			 * @return array<int, array<string, mixed>> Matching rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$limit = (int)($filters['_limit'] ?? $this->pageSize);
				$limit = min($limit, $this->pageSize);
				$matches = [];
				foreach ($this->store as $row) {
					if (($row['parentCase'] ?? null) !== ($filters['parentCase'] ?? null)) {
						continue;
					}

					$matches[] = $row;
					if (count($matches) >= $limit) {
						break;
					}
				}

				return $matches;
			}//end searchObjectsBySlug()

			/**
			 * Mimic ObjectService::saveObject.
			 *
			 * @param array<string, mixed> $object The payload.
			 * @param string $register Register slug.
			 * @param string $schema Schema slug.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, string $register = '', string $schema = ''): array {
				$this->saveCalls++;
				$id = (string)($object['id'] ?? '');
				if (in_array($id, $this->failIds, true) === true) {
					throw new RuntimeException('save refused for ' . $id);
				}

				$this->store[$id] = $object;
				return $object;
			}//end saveObject()
		};
	}//end makeObjectService()

	/**
	 * Build the service over a given object-service stub.
	 *
	 * @param object $objectService The stub.
	 *
	 * @return DeelzaakService The service.
	 */
	private function makeService(object $objectService): DeelzaakService {
		$this->settingsService->method('getObjectService')->willReturn($objectService);

		return new DeelzaakService(
			settingsService: $this->settingsService,
			logger: $this->logger,
			caseReader: new CaseObjectReader(
				settingsService: $this->settingsService,
				logger: $this->logger,
			),
		);
	}//end makeService()

	/**
	 * A parent with no sub-cases reports a clean, complete zero.
	 *
	 * Negative control: without it, an implementation that always returned
	 * `complete: false` would satisfy the partial-failure tests below.
	 *
	 * @return void
	 */
	public function testNoSubCasesIsCompleteAndZero(): void {
		$service = $this->makeService($this->makeObjectService([], 200));

		$this->assertSame(
			['unlinked' => 0, 'failed' => 0, 'total' => 0, 'complete' => true],
			$service->unlinkSubCases('parent-1')
		);
	}//end testNoSubCasesIsCompleteAndZero()

	/**
	 * A single page unlinks completely.
	 *
	 * @return void
	 */
	public function testSinglePageUnlinksEverything(): void {
		$ids = array_map(static fn (int $i): string => 'sub-' . $i, range(1, 12));
		$service = $this->makeService($this->makeObjectService($ids, 200));

		$result = $service->unlinkSubCases('parent-1');

		$this->assertSame(12, $result['unlinked']);
		$this->assertSame(0, $result['failed']);
		$this->assertTrue($result['complete']);
	}//end testSinglePageUnlinksEverything()

	/**
	 * 🔴 The regression this change exists to prevent.
	 *
	 * With 450 sub-cases and a 200-record page, the previous implementation
	 * unlinked 200 and returned `200` as an unqualified success — the caller
	 * then deleted the parent and orphaned the other 250 under a dead
	 * reference. Paging to exhaustion must reach all 450.
	 *
	 * @return void
	 */
	public function testPagesBeyondTheTwoHundredRecordLimit(): void {
		$ids = array_map(static fn (int $i): string => 'sub-' . $i, range(1, 450));
		$service = $this->makeService($this->makeObjectService($ids, 200));

		$result = $service->unlinkSubCases('parent-1');

		$this->assertSame(450, $result['unlinked'], 'must not stop at the first 200-record page');
		$this->assertSame(450, $result['total']);
		$this->assertTrue($result['complete']);
	}//end testPagesBeyondTheTwoHundredRecordLimit()

	/**
	 * A record that cannot be unlinked makes the result incomplete.
	 *
	 * @return void
	 */
	public function testAFailedRecordMakesTheResultIncomplete(): void {
		$ids = array_map(static fn (int $i): string => 'sub-' . $i, range(1, 10));
		$service = $this->makeService($this->makeObjectService($ids, 200, ['sub-4']));

		$result = $service->unlinkSubCases('parent-1');

		$this->assertSame(9, $result['unlinked']);
		$this->assertSame(1, $result['failed']);
		$this->assertFalse($result['complete'], 'a partial unlink must not report success');
	}//end testAFailedRecordMakesTheResultIncomplete()

	/**
	 * A page of nothing but failures terminates instead of spinning forever.
	 *
	 * The paging loop re-queries the same filter, so a page that unlinks
	 * nothing would return identical rows indefinitely. The no-progress guard
	 * has to break; this test hangs the suite if it is removed.
	 *
	 * @return void
	 */
	public function testAllFailuresTerminateRatherThanLoopForever(): void {
		$ids = ['sub-1', 'sub-2', 'sub-3'];
		$service = $this->makeService($this->makeObjectService($ids, 200, $ids));

		$result = $service->unlinkSubCases('parent-1');

		$this->assertSame(0, $result['unlinked']);
		$this->assertSame(3, $result['failed']);
		$this->assertFalse($result['complete']);
	}//end testAllFailuresTerminateRatherThanLoopForever()

	/**
	 * An absent OpenRegister reports a clean zero rather than throwing.
	 *
	 * @return void
	 */
	public function testAbsentObjectServiceReportsZero(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$service = new DeelzaakService(
			settingsService: $this->settingsService,
			logger: $this->logger,
			caseReader: new CaseObjectReader(
				settingsService: $this->settingsService,
				logger: $this->logger,
			),
		);

		$result = $service->unlinkSubCases('parent-1');

		$this->assertSame(0, $result['total']);
		$this->assertTrue($result['complete']);
	}//end testAbsentObjectServiceReportsZero()
}//end class
