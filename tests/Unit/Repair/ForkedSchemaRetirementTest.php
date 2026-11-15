<?php

/**
 * ForkedSchemaRetirement unit tests
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ForkedSchemaRetirement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Retires a forked demo schema only when it is unlinked and has exactly one
 * twin under the app, and says which condition held when it is kept.
 *
 * @covers \OCA\Dossiq\Repair\ForkedSchemaRetirement
 */
final class ForkedSchemaRetirementTest extends TestCase {

	/**
	 * The findAll calls the schema mapper received.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $lookups = [];

	/**
	 * The schemas the deletion service was asked to delete, by slug.
	 *
	 * @var list<string>
	 */
	public array $deleted = [];

	/**
	 * A schema stub.
	 *
	 * @param int    $id   The schema id.
	 * @param string $slug The slug.
	 *
	 * @return object
	 */
	private function schema(int $id, string $slug): object {
		return new class ($id, $slug) {
			/**
			 * Constructor.
			 *
			 * @param int    $id   The id.
			 * @param string $slug The slug.
			 */
			public function __construct(private int $id, private string $slug) {
			}

			/**
			 * The id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return $this->id;
			}

			/**
			 * The slug.
			 *
			 * @return string
			 */
			public function getSlug(): string {
				return $this->slug;
			}
		};
	}//end schema()

	/**
	 * A retirement whose mapper answers $twins schemas for any slug and whose
	 * deletion answers $deletedCount objects, or throws when $fails.
	 *
	 * @param int  $twins        Twins found under the app.
	 * @param int  $deletedCount Objects the cascade deletes.
	 * @param bool $fails        Whether the deletion throws.
	 *
	 * @return ForkedSchemaRetirement
	 */
	private function retirement(int $twins, int $deletedCount=0, bool $fails=false): ForkedSchemaRetirement {
		$test = $this;
		$mapper = new class ($test, $twins) {
			/**
			 * Constructor.
			 *
			 * @param ForkedSchemaRetirementTest $test  The test.
			 * @param int                        $twins The twin count.
			 */
			public function __construct(private ForkedSchemaRetirementTest $test, private int $twins) {
			}

			/**
			 * Record the lookup and answer the twins.
			 *
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 *
			 * @return array<int, string>
			 */
			public function findAll(array $filters, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->test->lookups[] = ['filters' => $filters, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return array_fill(0, $this->twins, 'twin');
			}
		};
		$deletion = new class ($test, $deletedCount, $fails) {
			/**
			 * Constructor.
			 *
			 * @param ForkedSchemaRetirementTest $test  The test.
			 * @param int                        $count Objects deleted.
			 * @param bool                       $fails Whether to throw.
			 */
			public function __construct(private ForkedSchemaRetirementTest $test, private int $count, private bool $fails) {
			}

			/**
			 * Record the deletion.
			 *
			 * @param object $schema The schema.
			 *
			 * @return array<string, int>
			 */
			public function cascadeDeleteSchema(object $schema): array {
				if ($this->fails === true) {
					throw new RuntimeException('locked');
				}

				$this->test->deleted[] = $schema->getSlug();
				return ['deletedCount' => $this->count];
			}
		};

		return new ForkedSchemaRetirement(schemaMapper: $mapper, deletion: $deletion);
	}//end retirement()

	/**
	 * An unlinked fork with exactly one twin under the app goes, with its objects counted.
	 *
	 * @return void
	 */
	public function testAnUnlinkedForkWithOneTwinIsRetiredWithItsObjects(): void {
		$retirement = $this->retirement(twins: 1, deletedCount: 7);

		$retirement->retire(schema: $this->schema(id: 41, slug: 'case'));
		$retirement->retire(schema: $this->schema(id: 42, slug: 'task'));

		self::assertSame(['case', 'task'], $this->deleted);
		self::assertSame(2, $retirement->retired);
		self::assertSame(14, $retirement->objects);
		self::assertSame([], $retirement->spared);
		self::assertSame(
			['filters' => ['application' => 'dossiq', 'slug' => 'case'], 'rbac' => false, 'multitenancy' => false],
			$this->lookups[0]
		);
	}//end testAnUnlinkedForkWithOneTwinIsRetiredWithItsObjects()

	/**
	 * A fork a register still links is kept without looking for twins.
	 *
	 * @return void
	 */
	public function testALinkedForkIsSpared(): void {
		$retirement = $this->retirement(twins: 1);
		$retirement->linked = [41];

		$retirement->retire(schema: $this->schema(id: 41, slug: 'case'));

		self::assertSame(['case' => 'a register still links it, so it is reachable'], $retirement->spared);
		self::assertSame([], $this->deleted);
		self::assertSame([], $this->lookups);
		self::assertSame(0, $retirement->retired);
	}//end testALinkedForkIsSpared()

	/**
	 * A fork whose slug has no twin may be the only copy and is kept.
	 *
	 * @return void
	 */
	public function testAForkWithoutATwinIsSpared(): void {
		$retirement = $this->retirement(twins: 0);

		$retirement->retire(schema: $this->schema(id: 41, slug: 'case'));

		self::assertSame(['case' => 'no schema of that slug exists under `dossiq`, so this may be the only copy'], $retirement->spared);
		self::assertSame([], $this->deleted);
	}//end testAForkWithoutATwinIsSpared()

	/**
	 * A fork whose slug has two twins has an ambiguous home and is kept.
	 *
	 * @return void
	 */
	public function testAForkWithTwoTwinsIsSpared(): void {
		$retirement = $this->retirement(twins: 2);

		$retirement->retire(schema: $this->schema(id: 41, slug: 'case'));

		self::assertSame(['case' => 'more than one schema of that slug exists under `dossiq`, so the real home is ambiguous'], $retirement->spared);
		self::assertSame([], $this->deleted);
	}//end testAForkWithTwoTwinsIsSpared()

	/**
	 * A fork without a slug cannot be matched to a twin and is kept.
	 *
	 * @return void
	 */
	public function testAForkWithoutASlugIsSpared(): void {
		$retirement = $this->retirement(twins: 1);

		$retirement->retire(schema: $this->schema(id: 41, slug: ''));

		self::assertSame(['' => 'it has no slug, so its twin cannot be identified'], $retirement->spared);
		self::assertSame([], $this->lookups);
	}//end testAForkWithoutASlugIsSpared()

	/**
	 * A failed deletion is recorded as the reason the fork is still there, and counts nothing.
	 *
	 * @return void
	 */
	public function testAFailedDeletionIsRecordedAndNotCounted(): void {
		$retirement = $this->retirement(twins: 1, deletedCount: 3, fails: true);

		$retirement->retire(schema: $this->schema(id: 41, slug: 'case'));

		self::assertSame(['case' => 'deletion failed: locked'], $retirement->spared);
		self::assertSame(0, $retirement->retired);
		self::assertSame(0, $retirement->objects);
	}//end testAFailedDeletionIsRecordedAndNotCounted()
}//end class
