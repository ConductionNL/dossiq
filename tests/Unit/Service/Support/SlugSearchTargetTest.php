<?php

/**
 * The search bridge passes the slugs in the shape the installed OpenRegister takes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/slug-search-takes-the-openregister-target/specs/or-object-search/spec.md#requirement-a-slug-search-passes-the-target-openregister-asks-for-req-sst-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\SearchesObjects;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the slug-search target shape.
 */
final class SlugSearchTargetTest extends TestCase {
	/**
	 * A user of the bridge with its search exposed.
	 *
	 * @return object The bridge.
	 */
	private function bridge(): object {
		return new class {
			use SearchesObjects;

			/**
			 * Search register `dossiq`, schema `case`.
			 *
			 * @param object               $service The object service.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function search(object $service, array $filters): array {
				return $this->searchObjectsAsArrays(objectService: $service, register: 'dossiq', schema: 'case', filters: $filters);
			}//end search()
		};
	}//end bridge()

	/**
	 * OpenRegister main: one target value, then the filters.
	 *
	 * @return void
	 */
	public function testATargetTakingServiceGetsOneTargetValue(): void {
		$service = new class {
			/**
			 * Every call's arguments.
			 *
			 * @var list<array<int|string, mixed>>
			 */
			public array $calls = [];

			/**
			 * The slug search of OpenRegister main.
			 *
			 * @param SlugSearchTargetValue $target        The slugs.
			 * @param array<string, mixed>  $filters       The filters.
			 * @param bool                  $_rbac         Unused.
			 * @param bool                  $_multitenancy Unused.
			 *
			 * @return list<array<string, mixed>> One row naming the slugs.
			 */
			public function searchObjectsBySlug(SlugSearchTargetValue $target, array $filters=[], bool $_rbac=true, bool $_multitenancy=true): array {
				$this->calls[] = func_get_args();
				return [['register' => $target->register, 'schema' => $target->schema]];
			}//end searchObjectsBySlug()
		};

		$rows = $this->bridge()->search(service: $service, filters: ['status' => 'open']);

		self::assertSame(expected: [['register' => 'dossiq', 'schema' => 'case']], actual: $rows);
		self::assertCount(expectedCount: 1, haystack: $service->calls);
		self::assertInstanceOf(expected: SlugSearchTargetValue::class, actual: $service->calls[0][0]);
		self::assertSame(expected: ['status' => 'open'], actual: $service->calls[0][1]);
	}//end testATargetTakingServiceGetsOneTargetValue()

	/**
	 * Released OpenRegister: the two slugs, then the filters.
	 *
	 * @return void
	 */
	public function testASlugTakingServiceGetsTheTwoSlugs(): void {
		$service = new class {
			/**
			 * Every call's arguments.
			 *
			 * @var list<array<int|string, mixed>>
			 */
			public array $calls = [];

			/**
			 * The slug search of released OpenRegister versions.
			 *
			 * @param string               $registerSlug  The register slug.
			 * @param string               $schemaSlug    The schema slug.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Unused.
			 * @param bool                 $_multitenancy Unused.
			 *
			 * @return list<array<string, mixed>> One row naming the slugs.
			 */
			public function searchObjectsBySlug(
				string $registerSlug,
				string $schemaSlug,
				array $filters=[],
				bool $_rbac=true,
				bool $_multitenancy=true
			): array {
				$this->calls[] = func_get_args();
				return [['register' => $registerSlug, 'schema' => $schemaSlug]];
			}//end searchObjectsBySlug()
		};

		$rows = $this->bridge()->search(service: $service, filters: ['status' => 'open']);

		self::assertSame(expected: [['register' => 'dossiq', 'schema' => 'case']], actual: $rows);
		self::assertSame(expected: ['dossiq', 'case', ['status' => 'open']], actual: $service->calls[0]);
	}//end testASlugTakingServiceGetsTheTwoSlugs()
}//end class
