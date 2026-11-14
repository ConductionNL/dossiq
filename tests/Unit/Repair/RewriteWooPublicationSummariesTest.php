<?php

/**
 * RewriteWooPublicationSummaries: the old "WOO besluit voor zaak <uuid>" text.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RewriteWooPublicationSummaries;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The rows are shaped as they are stored on :8080: a publication whose
 * summary AND description both read the old text, and a decision whose
 * description does.
 */
class RewriteWooPublicationSummariesTest extends TestCase {
	private const CASE_ID = 'b9e5d911-16ef-4cbc-9d6d-659b5585e1f7';

	private const OLD = 'WOO besluit voor zaak b9e5d911-16ef-4cbc-9d6d-659b5585e1f7';

	/**
	 * A store that answers the calls the step makes and keeps what it patches,
	 * so a second run reads the repaired rows.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $rows Rows by schema, then by id.
	 *
	 * @return object The fake.
	 */
	private function store(array $rows): object {
		return new class($rows) {
			/**
			 * @var array<int, array{schema: string, id: string, data: array<string, mixed>}>
			 */
			public array $patches = [];

			/**
			 * @var array<int, string>
			 */
			public array $failOn = [];

			/**
			 * @param array<string, array<string, array<string, mixed>>> $rows The rows.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * @param callable $operation The operation.
			 *
			 * @return mixed
			 */
			public function runAsSystem(callable $operation): mixed {
				return $operation();
			}

			/**
			 * The numeric path (the dossiq register).
			 *
			 * @param array<string, mixed> $query The query.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->page(schema: (string)$query['@self']['schema'], query: $query);
			}

			/**
			 * The slug path (OpenCatalogi's register).
			 *
			 * @param string               $register The register.
			 * @param string               $schema The schema.
			 * @param array<string, mixed> $filters The filters.
			 * @param bool                 $_rbac Ignored.
			 * @param bool                 $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->page(schema: $schema, query: $filters);
			}

			/**
			 * @param string               $schema The schema.
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			private function page(string $schema, array $query): array {
				$all = array_values($this->rows[$schema] ?? []);
				return array_slice($all, (int)($query['_offset'] ?? 0), (int)($query['_limit'] ?? 100));
			}

			/**
			 * @param string     $id The id.
			 * @param int|string $register The register.
			 * @param int|string $schema The schema.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws DoesNotExistException When the row is not there.
			 */
			public function find(string $id, int|string $register, int|string $schema): array {
				if (isset($this->rows[(string)$schema][$id]) === false) {
					throw new DoesNotExistException('gone');
				}

				return $this->rows[(string)$schema][$id];
			}

			/**
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data The changes.
			 * @param int|string           $register The register.
			 * @param int|string           $schema The schema.
			 *
			 * @return array<string, mixed>
			 *
			 * @throws RuntimeException When told to fail on this row.
			 */
			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				if (in_array($objectId, $this->failOn, true) === true) {
					throw new RuntimeException('store refused');
				}

				$this->patches[] = ['schema' => (string)$schema, 'id' => $objectId, 'data' => $data];
				$this->rows[(string)$schema][$objectId] = array_merge($this->rows[(string)$schema][$objectId], $data);

				return $this->rows[(string)$schema][$objectId];
			}
		};
	}//end store()

	/**
	 * The step around a store.
	 *
	 * @param object $store The fake store.
	 *
	 * @return RewriteWooPublicationSummaries
	 */
	private function step(object $store): RewriteWooPublicationSummaries {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => '23',
				'case_schema' => '5',
				'decision_schema' => '7',
			][$key] ?? $default
		);
		$settings->method('getWooPublicationConfigValue')->willReturn('publication');

		return new RewriteWooPublicationSummaries($settings, $this->createMock(LoggerInterface::class));
	}//end step()

	/**
	 * The rows as :8080 holds them.
	 *
	 * @param bool $withCase Whether the case is there.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function rows(bool $withCase = true): array {
		$rows = [
			'publication' => [
				'pub-1' => ['id' => 'pub-1', 'title' => 'Verlichting fietspad Lindelaan', 'summary' => self::OLD, 'description' => self::OLD],
				'pub-2' => ['id' => 'pub-2', 'summary' => 'Een eigen samenvatting', 'description' => self::OLD],
			],
			'7' => [
				'dec-1' => ['id' => 'dec-1', 'case' => self::CASE_ID, 'description' => self::OLD],
			],
			'5' => [],
		];
		if ($withCase === true) {
			$rows['5'][self::CASE_ID] = ['id' => self::CASE_ID, 'title' => 'Verlichting fietspad Lindelaan'];
		}

		return $rows;
	}//end rows()

	/**
	 * A matching publication gets the summary new decisions carry, in both
	 * fields; the decision gets it too.
	 *
	 * @return void
	 */
	public function testAMatchIsRewrittenToTheCaseTitle(): void {
		$store = $this->store($this->rows());
		$this->step($store)->run($this->createMock(IOutput::class));

		$new = 'Besluit op het Woo-verzoek "Verlichting fietspad Lindelaan"';
		$this->assertSame($new, $store->rows['publication']['pub-1']['summary']);
		$this->assertSame($new, $store->rows['publication']['pub-1']['description']);
		$this->assertSame($new, $store->rows['7']['dec-1']['description']);
	}//end testAMatchIsRewrittenToTheCaseTitle()

	/**
	 * A summary somebody wrote is left alone, and so is a description that
	 * only matches when the summary does not.
	 *
	 * @return void
	 */
	public function testNoMatchIsNotTouched(): void {
		$store = $this->store($this->rows());
		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertSame('Een eigen samenvatting', $store->rows['publication']['pub-2']['summary']);
		$this->assertSame(self::OLD, $store->rows['publication']['pub-2']['description']);
		$this->assertNotContains('pub-2', array_column($store->patches, 'id'));

		$step = $this->step($store);
		$this->assertSame([], $step->changesFor(row: ['summary' => 'WOO besluit voor zaak 123'], fields: ['summary']));
		$this->assertSame([], $step->changesFor(row: ['summary' => 'Zie: ' . self::OLD], fields: ['summary']));
		$this->assertSame([], $step->changesFor(row: ['summary' => ['nl' => self::OLD]], fields: ['summary']));
	}//end testNoMatchIsNotTouched()

	/**
	 * A case that is gone still gets readable words, without a title.
	 *
	 * @return void
	 */
	public function testAMissingCaseReadsAsAWooRequest(): void {
		$store = $this->store($this->rows(withCase: false));
		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertSame('Besluit op een Woo-verzoek', $store->rows['publication']['pub-1']['summary']);
		$this->assertSame('Besluit op een Woo-verzoek', $store->rows['7']['dec-1']['description']);
	}//end testAMissingCaseReadsAsAWooRequest()

	/**
	 * A second run finds nothing to do.
	 *
	 * @return void
	 */
	public function testItIsIdempotent(): void {
		$store = $this->store($this->rows());
		$this->step($store)->run($this->createMock(IOutput::class));
		$this->assertCount(2, $store->patches, 'one publication and one decision');

		$this->step($store)->run($this->createMock(IOutput::class));
		$this->assertCount(2, $store->patches, 'the second run writes nothing');
	}//end testItIsIdempotent()

	/**
	 * One row that cannot be written does not stop the others or the upgrade.
	 *
	 * @return void
	 */
	public function testOneBadRowDoesNotFailTheUpgrade(): void {
		$store = $this->store($this->rows());
		$store->failOn = ['pub-1'];

		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertSame(self::OLD, $store->rows['publication']['pub-1']['summary']);
		$this->assertSame('Besluit op het Woo-verzoek "Verlichting fietspad Lindelaan"', $store->rows['7']['dec-1']['description']);
	}//end testOneBadRowDoesNotFailTheUpgrade()
}//end class
