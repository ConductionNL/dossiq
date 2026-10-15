<?php

/**
 * Unit tests for ZtcTypeNameResolver.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
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

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZtcTypeNameResolver;
use OCA\Dossiq\Service\ZgwService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An informatieobjecttype named by its omschrijving is replaced by its uuid (ztc-010m).
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZtcTypeNameResolver
 * @uses   \OCA\Dossiq\Support\NormalisesObjectRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ZtcTypeNameResolverTest extends TestCase {
	/**
	 * The searches made, as their request parameters.
	 *
	 * @var array<int, array>
	 */
	private array $searches = [];

	/**
	 * The body fields written back, as [field, value].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $written = [];

	/**
	 * A resolver on a store that answers each search from the given closure.
	 *
	 * @param \Closure|null $answer Answers the request parameters; null for no store.
	 * @param array|null $mapping The informatieobjecttypen mapping.
	 *
	 * @return ZtcTypeNameResolver
	 */
	private function resolver(?\Closure $answer, ?array $mapping = ['sourceRegister' => '5', 'sourceSchema' => '9']): ZtcTypeNameResolver {
		$store = null;
		if ($answer !== null) {
			$store = new class($answer, $this) {
				public function __construct(private \Closure $answer, private ZtcTypeNameResolverTest $test) {
				}//end __construct()

				public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
					return $requestParams;
				}//end buildSearchQuery()

				public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
					$this->test->recordSearch(query: $query + ['_rbac' => $_rbac]);
					return ($this->answer)($query);
				}//end searchObjectsPaginated()
			};
		}

		$zgw = $this->createMock(ZgwService::class);
		$zgw->method('getObjectService')->willReturn($store);
		$zgw->method('loadMappingConfig')->willReturnCallback(
			static fn (string $api, string $resource): ?array => ($api === 'catalogi' && $resource === 'informatieobjecttypen') ? $mapping : null
		);
		$zgw->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
		$zgw->method('updateCachedBodyField')->willReturnCallback(
			function (string $key, mixed $value): void {
				$this->written[] = [$key, $value];
			}
		);

		return new ZtcTypeNameResolver(zgwService: $zgw);
	}//end resolver()

	/**
	 * Record one search.
	 *
	 * @param array $query The request parameters.
	 *
	 * @return void
	 */
	public function recordSearch(array $query): void {
		$this->searches[] = $query;
	}//end recordSearch()

	/**
	 * A name is looked up by name, then by full text, outside the caller's access, and the hit's
	 * uuid is written into the body.
	 *
	 * @return void
	 */
	public function testANameBecomesTheTypesUuid(): void {
		$answer = static fn (array $query): array => isset($query['_search']) === true
			? ['total' => 1, 'results' => [['@self' => ['id' => 'iot-1']]]]
			: ['total' => 0, 'results' => []];

		$this->resolver(answer: $answer)->resolveInformatieobjecttype(body: ['informatieobjecttype' => 'Brief']);

		$this->assertSame(
			[['name' => 'Brief', '_limit' => 1, '_rbac' => false], ['_search' => 'Brief', '_limit' => 1, '_rbac' => false]],
			$this->searches
		);
		$this->assertSame([['informatieobjecttype', 'iot-1']], $this->written);
	}//end testANameBecomesTheTypesUuid()

	/**
	 * A uuid, a URL, an empty value, no store, no mapping, no hit and a failed search all leave the body alone.
	 *
	 * @return void
	 */
	public function testEverythingElseLeavesTheBodyAlone(): void {
		$none = static fn (): array => ['total' => 0, 'results' => []];

		foreach (['', '0b4a27f2-5a6c-4d3e-9f1a-2b3c4d5e6f70', 'https://example.org/iot/1'] as $value) {
			$this->resolver(answer: $none)->resolveInformatieobjecttype(body: ['informatieobjecttype' => $value]);
		}

		$this->assertSame([], $this->searches, 'a uuid or URL is not looked up');

		$this->resolver(answer: null)->resolveInformatieobjecttype(body: ['informatieobjecttype' => 'Brief']);
		$this->resolver(answer: $none, mapping: null)->resolveInformatieobjecttype(body: ['informatieobjecttype' => 'Brief']);
		$this->resolver(answer: $none)->resolveInformatieobjecttype(body: ['informatieobjecttype' => 'Brief']);
		$this->resolver(answer: static fn (): never => throw new \RuntimeException('down'))->resolveInformatieobjecttype(body: ['informatieobjecttype' => 'Brief']);

		$this->assertSame([], $this->written);
	}//end testEverythingElseLeavesTheBodyAlone()
}//end class
