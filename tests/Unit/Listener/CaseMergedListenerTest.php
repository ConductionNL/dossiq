<?php

/**
 * Dossiq CaseMergedListener test.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseMergedListener;
use OCA\Dossiq\Service\CaseMergeService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The follower reads the platform's event and does nothing else.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */
class CaseMergedListenerTest extends TestCase {
	/**
	 * A merge is applied once per case that was merged away.
	 *
	 * @return void
	 */
	public function testAMergeIsAppliedToEveryCaseItMergedAway(): void {
		$service = $this->createMock(CaseMergeService::class);
		$applied = [];
		$service->method('applyMerge')->willReturnCallback(
			static function (string $mergedId, string $survivorId) use (&$applied): bool {
				$applied[] = $mergedId . '->' . $survivorId;
				return true;
			}
		);
		$service->expects($this->never())->method('applyReversal');

		$listener = new CaseMergedListener(mergeService: $service, logger: new NullLogger());
		$listener->handle(new FakeMergedEvent('case-a', ['case-b', 'case-c'], false));

		$this->assertSame(['case-b->case-a', 'case-c->case-a'], $applied);
	}//end testAMergeIsAppliedToEveryCaseItMergedAway()

	/**
	 * The same event with the reversal flag set is the other direction.
	 *
	 * @return void
	 */
	public function testAReversalTakesTheReversalPath(): void {
		$service = $this->createMock(CaseMergeService::class);
		$reversed = [];
		$service->method('applyReversal')->willReturnCallback(
			static function (string $mergedId, string $survivorId) use (&$reversed): bool {
				$reversed[] = $mergedId . '->' . $survivorId;
				return true;
			}
		);
		$service->expects($this->never())->method('applyMerge');

		$listener = new CaseMergedListener(mergeService: $service, logger: new NullLogger());
		$listener->handle(new FakeMergedEvent('case-a', ['case-b'], true));

		$this->assertSame(['case-b->case-a'], $reversed);
	}//end testAReversalTakesTheReversalPath()

	/**
	 * An event that does not answer to the readers is not this listener's.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsLeftAlone(): void {
		$service = $this->createMock(CaseMergeService::class);
		$service->expects($this->never())->method('applyMerge');
		$service->expects($this->never())->method('applyReversal');

		$listener = new CaseMergedListener(mergeService: $service, logger: new NullLogger());
		$listener->handle(new Event());

		$this->addToAssertionCount(1);
	}//end testAnUnrelatedEventIsLeftAlone()

	/**
	 * A follower that throws does not fail the dispatch: the merge is already
	 * committed, and an exception here would only stop the other followers.
	 *
	 * @return void
	 */
	public function testAFailingFollowerDoesNotEscape(): void {
		$service = $this->createMock(CaseMergeService::class);
		$service->method('applyMerge')->willThrowException(new RuntimeException('store unavailable'));

		$listener = new CaseMergedListener(mergeService: $service, logger: new NullLogger());
		$listener->handle(new FakeMergedEvent('case-a', ['case-b'], false));

		$this->addToAssertionCount(1);
	}//end testAFailingFollowerDoesNotEscape()
}//end class

/**
 * The shape of OpenRegister's `ObjectsMergedEvent`, written out so the
 * listener's duck-typing is exercised against the real three readers.
 */
final class FakeMergedEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string            $survivorUuid    The surviving object.
	 * @param array<int,string> $mergedFromUuids The objects merged away.
	 * @param bool              $isReversal      Whether this reverses a merge.
	 */
	public function __construct(
		private readonly string $survivorUuid,
		private readonly array $mergedFromUuids,
		private readonly bool $isReversal,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The surviving object.
	 *
	 * @return string The uuid.
	 */
	public function getSurvivorUuid(): string {
		return $this->survivorUuid;
	}//end getSurvivorUuid()

	/**
	 * The objects merged away.
	 *
	 * @return array<int, string> The uuids.
	 */
	public function getMergedFromUuids(): array {
		return $this->mergedFromUuids;
	}//end getMergedFromUuids()

	/**
	 * Whether this event reverses a merge.
	 *
	 * @return bool True on a reversal.
	 */
	public function isReversal(): bool {
		return $this->isReversal;
	}//end isReversal()
}//end class
