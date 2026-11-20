<?php

/**
 * Portal submission ledger test
 *
 * The ledger claims a submission key once, keeps the first send's answer for a
 * resend, and lets a key go after a send that made nothing. These tests drive
 * it over a plain distributed cache (read-then-write) and over a memcache
 * whose claim expired between the two reads.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalSubmissionLedger;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IMemcache;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\PortalSubmissionLedger
 */
class PortalSubmissionLedgerTest extends TestCase {
	private const KEY = 'abcdefghijklmnop-1234';

	/**
	 * What the plain cache holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $values = [];

	/**
	 * Every `set` as `[value, ttl]`.
	 *
	 * @var array<int, array{0: mixed, 1: int}>
	 */
	private array $sets = [];

	/**
	 * A ledger over a plain ICache that is not an IMemcache.
	 *
	 * @return PortalSubmissionLedger
	 */
	private function plainLedger(): PortalSubmissionLedger {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => ($this->values[$key] ?? null));
		$cache->method('set')->willReturnCallback(function ($key, $value, $ttl = 0): bool {
			$this->values[$key] = $value;
			$this->sets[] = [$value, $ttl];
			return true;
		});
		$cache->method('remove')->willReturnCallback(function ($key): bool {
			unset($this->values[$key]);
			return true;
		});

		$factory = $this->createMock(ICacheFactory::class);
		$factory->expects($this->once())->method('createDistributed')->with('dossiq_portal_submission')->willReturn($cache);

		return new PortalSubmissionLedger($factory);
	}//end plainLedger()

	/**
	 * Only a plain token of 16 to 128 characters counts as a key.
	 *
	 * @return void
	 */
	public function testOnlyAPlainTokenIsAKey(): void {
		$ledger = $this->plainLedger();

		$this->assertSame(self::KEY, $ledger->keyOf('  ' . self::KEY . ' '));
		$this->assertSame('', $ledger->keyOf('too-short'));
		$this->assertSame('', $ledger->keyOf('abcdefghijklmnop qrst'));
		$this->assertSame('', $ledger->keyOf(str_repeat('a', 129)));
		$this->assertSame(str_repeat('a', 128), $ledger->keyOf(str_repeat('a', 128)));
	}//end testOnlyAPlainTokenIsAKey()

	/**
	 * Over a plain cache the first send claims, a resend while it works waits.
	 *
	 * @return void
	 */
	public function testAPlainCacheClaimsOnceAndThenReportsProcessing(): void {
		$ledger = $this->plainLedger();

		$this->assertSame(['state' => 'claimed'], $ledger->claim('woo-verzoek', 'subject-a', self::KEY));
		$this->assertSame([['processing', 300]], $this->sets);
		$this->assertSame(['state' => 'processing'], $ledger->claim('woo-verzoek', 'subject-a', self::KEY));
		$this->assertCount(1, $this->sets, 'a held claim is not written again');
	}//end testAPlainCacheClaimsOnceAndThenReportsProcessing()

	/**
	 * A remembered answer is what a resend gets back, for a day.
	 *
	 * @return void
	 */
	public function testARememberedAnswerIsReturnedToTheResend(): void {
		$ledger = $this->plainLedger();
		$ledger->claim('woo-verzoek', 'subject-a', self::KEY);
		$ledger->remember('woo-verzoek', 'subject-a', self::KEY, ['caseId' => 'case-1', 'number' => 'W-1']);

		$this->assertSame(['{"caseId":"case-1","number":"W-1"}', 86400], $this->sets[1]);
		$this->assertSame(
			['state' => 'answered', 'answer' => ['caseId' => 'case-1', 'number' => 'W-1']],
			$ledger->claim('woo-verzoek', 'subject-a', self::KEY)
		);
	}//end testARememberedAnswerIsReturnedToTheResend()

	/**
	 * The resident and the action are part of the entry, so another's key never matches.
	 *
	 * @return void
	 */
	public function testAnotherResidentOrActionIsAFirstSend(): void {
		$ledger = $this->plainLedger();
		$ledger->claim('woo-verzoek', 'subject-a', self::KEY);

		$this->assertSame(['state' => 'claimed'], $ledger->claim('woo-verzoek', 'subject-b', self::KEY));
		$this->assertSame(['state' => 'claimed'], $ledger->claim('klacht', 'subject-a', self::KEY));
	}//end testAnotherResidentOrActionIsAFirstSend()

	/**
	 * A released key makes the corrected resend a first send.
	 *
	 * @return void
	 */
	public function testAReleasedKeyIsClaimedAgain(): void {
		$ledger = $this->plainLedger();
		$ledger->claim('woo-verzoek', 'subject-a', self::KEY);
		$ledger->release('woo-verzoek', 'subject-a', self::KEY);

		$this->assertSame([], $this->values);
		$this->assertSame(['state' => 'claimed'], $ledger->claim('woo-verzoek', 'subject-a', self::KEY));
	}//end testAReleasedKeyIsClaimedAgain()

	/**
	 * A held value that is not an answer reads as still processing.
	 *
	 * @return void
	 */
	public function testAnUnreadableHeldValueIsStillProcessing(): void {
		$ledger = $this->plainLedger();
		$ledger->claim('woo-verzoek', 'subject-a', self::KEY);
		$entry = array_key_first($this->values);
		$this->values[$entry] = 'not json';

		$this->assertSame(['state' => 'processing'], $ledger->claim('woo-verzoek', 'subject-a', self::KEY));
	}//end testAnUnreadableHeldValueIsStillProcessing()

	/**
	 * On a memcache whose claim expired between `add` and `get`, this send claims.
	 *
	 * @return void
	 */
	public function testAClaimThatExpiredBetweenTheReadsIsClaimedAnew(): void {
		$cache = $this->createMock(IMemcache::class);
		$cache->expects($this->once())->method('add')->with($this->isType('string'), 'processing', 300)->willReturn(false);
		$cache->expects($this->once())->method('get')->willReturn(null);
		$cache->expects($this->once())->method('set')->with($this->isType('string'), 'processing', 300)->willReturn(true);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		$this->assertSame(['state' => 'claimed'], (new PortalSubmissionLedger($factory))->claim('woo-verzoek', 'subject-a', self::KEY));
	}//end testAClaimThatExpiredBetweenTheReadsIsClaimedAnew()
}//end class
