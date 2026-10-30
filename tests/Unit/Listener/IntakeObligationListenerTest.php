<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\IntakeObligationListener;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Listener\IntakeObligationListener
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
 */
class IntakeObligationListenerTest extends TestCase {

	/**
	 * Run the listener on one received object.
	 *
	 * @param array<string, mixed>|null $received What the receipt answers.
	 * @param string                    $slug     The schema slug of it.
	 * @param bool                      $places   Whether placing is expected.
	 *
	 * @return void
	 */
	private function handleOne(?array $received, string $slug, bool $places): void {
		$receipt = $this->createMock(ReceiptMoment::class);
		$receipt->method('receivedObject')->willReturn($received);
		$slugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$slugs->method('resolveFromPayload')->willReturn($slug);
		$obligations = $this->createMock(IntakeObligations::class);
		$obligations->expects($places ? self::once() : self::never())->method('placeFor');

		(new IntakeObligationListener(receipt: $receipt, slugResolver: $slugs, obligations: $obligations))->handle(new Event());
	}//end handleOne()

	/**
	 * A received case with entries is placed; anything else is not.
	 *
	 * @return void
	 */
	public function testOnlyAReceivedCaseWithEntriesIsPlaced(): void {
		$this->handleOne(received: ['id' => 'c', 'intakeObligations' => [['kind' => 'cosign']]], slug: 'case', places: true);
		$this->handleOne(received: ['id' => 'c', 'intakeObligations' => []], slug: 'case', places: false);
		$this->handleOne(received: ['id' => 'o', 'intakeObligations' => [['kind' => 'cosign']]], slug: 'obligation', places: false);
		$this->handleOne(received: null, slug: 'case', places: false);
	}//end testOnlyAReceivedCaseWithEntriesIsPlaced()
}//end class
