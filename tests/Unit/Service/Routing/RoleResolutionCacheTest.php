<?php

/**
 * Unit tests for RoleResolutionCache: resolved members are kept per rule and
 * case for a minute in the app's local cache, read back as strings, and a
 * case without an id is never cached.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\RoleResolutionCache;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Routing\RoleResolutionCache
 */
class RoleResolutionCacheTest extends TestCase {

	private ICache&MockObject $cache;

	private RoleResolutionCache $resolutions;

	protected function setUp(): void {
		parent::setUp();
		$this->cache = $this->createMock(ICache::class);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createLocal')->with('dossiq_routing')->willReturn($this->cache);
		$this->resolutions = new RoleResolutionCache($factory);
	}//end setUp()

	private function key(array $rule, string $caseId): string {
		return 'rrs.' . md5(serialize($rule)) . '.' . $caseId;
	}//end key()

	public function testMembersAreRememberedForAMinuteUnderRuleAndCase(): void {
		$rule = ['strategy' => 'or-set', 'roleTypes' => ['behandelaar']];
		$this->cache->expects($this->once())->method('set')->with($this->key($rule, 'case-1'), ['alice', 'bob'], 60);

		$this->resolutions->remember($rule, 'case-1', ['alice', 'bob']);
	}//end testMembersAreRememberedForAMinuteUnderRuleAndCase()

	public function testAHitIsReadBackAsAListOfStrings(): void {
		$rule = ['strategy' => 'single-role', 'roleType' => 'behandelaar'];
		$this->cache->expects($this->once())->method('get')->with($this->key($rule, 'case-1'))->willReturn(['a' => 'alice', 'b' => 42]);

		$this->assertSame(['alice', '42'], $this->resolutions->get($rule, 'case-1'));
	}//end testAHitIsReadBackAsAListOfStrings()

	public function testAMissIsNull(): void {
		$this->cache->method('get')->willReturn(null);

		$this->assertNull($this->resolutions->get(['strategy' => 'or-set'], 'case-1'));
	}//end testAMissIsNull()

	public function testDifferentRulesUseDifferentKeys(): void {
		$keys = [];
		$this->cache->method('set')->willReturnCallback(
			static function (string $key) use (&$keys): bool {
				$keys[] = $key;
				return true;
			}
		);

		$this->resolutions->remember(['roleType' => 'a'], 'case-1', ['x']);
		$this->resolutions->remember(['roleType' => 'b'], 'case-1', ['x']);

		$this->assertCount(2, array_unique($keys));
	}//end testDifferentRulesUseDifferentKeys()

	public function testACaseWithoutAnIdIsNeverCachedReadOrCleared(): void {
		$this->cache->expects($this->never())->method('get');
		$this->cache->expects($this->never())->method('set');
		$this->cache->expects($this->never())->method('clear');

		$this->assertNull($this->resolutions->get(['roleType' => 'a'], ''));
		$this->resolutions->remember(['roleType' => 'a'], '', ['x']);
		$this->resolutions->forget('');
	}//end testACaseWithoutAnIdIsNeverCachedReadOrCleared()

	public function testForgettingACaseClearsTheSegment(): void {
		$this->cache->expects($this->once())->method('clear');

		$this->resolutions->forget('case-1');
	}//end testForgettingACaseClearsTheSegment()
}//end class
