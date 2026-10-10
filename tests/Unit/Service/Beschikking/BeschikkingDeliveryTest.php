<?php

/**
 * BeschikkingDelivery unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\BeschikkingDelivery;
use OCA\Dossiq\Service\Notification\RequesterNoticeSender;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;

/**
 * The decision notice goes to the one requester sender, with the requester the case names.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\BeschikkingDelivery
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
 */
class BeschikkingDeliveryTest extends TestCase {

	/**
	 * Every send call, as handed to the sender.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A delivery over a recording sender and a store that answers $case.
	 *
	 * @param array<string, mixed>|null $case     What the store answers.
	 * @param boolean                   $readable Whether the store is asked at all.
	 *
	 * @return BeschikkingDelivery
	 */
	private function delivery(?array $case, bool $readable = true): BeschikkingDelivery {
		$this->calls = [];
		$sender = $this->createMock(RequesterNoticeSender::class);
		$sender->method('send')->willReturnCallback(
			function (array $case, string $template, array $rendered, string $moment, array $options = []): array {
				$this->calls[] = compact('case', 'template', 'rendered', 'moment', 'options');

				return ['status' => RequesterNoticeSender::STATUS_SENT, 'channel' => 'email', 'messageId' => 'm-1'];
			}
		);

		$store = $this->createMock(CaseStatusStore::class);
		if ($readable === true) {
			$store->method('loadCase')->willReturn($case);
		} else {
			$store->expects($this->never())->method('loadCase');
		}

		return new BeschikkingDelivery($sender, $store);
	}//end delivery()

	/**
	 * The sender gets the stored case, the decision moment and the Dutch notice.
	 *
	 * @return void
	 */
	public function testItSendsTheDecisionNoticeForTheStoredCase(): void {
		$delivery = $this->delivery(['portalSubject' => 'sub-1', 'verzoekerEmail' => 'a@example.nl']);

		$result = $delivery->deliver(
			[
				'caseId' => 'case-1',
				'reference' => 'B-2026-7',
				'rationale' => 'Toegekend.',
				'legalRemediesClause' => 'U kunt binnen zes weken bezwaar maken.',
			],
			'besch-1'
		);

		$this->assertSame('m-1', $result['messageId']);
		$this->assertCount(1, $this->calls);
		$call = $this->calls[0];
		$this->assertSame('case-1', $call['case']['id']);
		$this->assertSame('sub-1', $call['case']['portalSubject']);
		$this->assertSame('beschikking', $call['template']);
		$this->assertSame('decision', $call['moment']);
		$this->assertSame('Besluit over uw zaak B-2026-7', $call['rendered']['subject']);
		$this->assertSame(
			"Wij hebben een besluit genomen over uw zaak B-2026-7.\n\nToegekend.\n\nU kunt binnen zes weken bezwaar maken.",
			$call['rendered']['body']
		);
		$this->assertSame('besch-1', $call['options']['instanceId']);
		$this->assertSame('beschikking:besch-1', $call['options']['dedupeKey']);
		$this->assertSame(['beschikkingId' => 'besch-1', 'reference' => 'B-2026-7'], $call['options']['recordExtras']);
	}//end testItSendsTheDecisionNoticeForTheStoredCase()

	/**
	 * A case without a BSN takes the burger addressee's BSN for digital post.
	 *
	 * @return void
	 */
	public function testABurgerAddresseeLendsHerBsnToACaseWithout(): void {
		$delivery = $this->delivery(['initiatorType' => 'organisation', 'initiatorSourceId' => 'kvk-1']);

		$delivery->deliver(['caseId' => 'case-1', 'addressee' => ['type' => 'burger', 'bsn' => '123456789']], 'besch-1');

		$this->assertSame('person', $this->calls[0]['case']['initiatorType']);
		$this->assertSame('123456789', $this->calls[0]['case']['initiatorSourceId']);
	}//end testABurgerAddresseeLendsHerBsnToACaseWithout()

	/**
	 * The case's own BSN wins over the addressee's.
	 *
	 * @return void
	 */
	public function testTheCasesOwnBsnIsKept(): void {
		$delivery = $this->delivery(['initiatorType' => 'person', 'initiatorSourceId' => '999999990']);

		$delivery->deliver(['caseId' => 'case-1', 'addressee' => ['type' => 'burger', 'bsn' => '123456789']], 'besch-1');

		$this->assertSame('999999990', $this->calls[0]['case']['initiatorSourceId']);
	}//end testTheCasesOwnBsnIsKept()

	/**
	 * A company addressee lends nothing; an unreadable case leaves only its id.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseLeavesOnlyItsId(): void {
		$delivery = $this->delivery(null);

		$delivery->deliver(['caseId' => 'case-1', 'addressee' => ['type' => 'bedrijf', 'oin' => '000']], 'besch-1');

		$this->assertSame(['id' => 'case-1'], $this->calls[0]['case']);
		$this->assertSame('Besluit over uw zaak', $this->calls[0]['rendered']['subject']);
		$this->assertSame('Wij hebben een besluit genomen over uw zaak.', $this->calls[0]['rendered']['body']);
	}//end testAnUnreadableCaseLeavesOnlyItsId()

	/**
	 * A beschikking with no case is not looked up.
	 *
	 * @return void
	 */
	public function testABeschikkingWithoutACaseIsNotLookedUp(): void {
		$delivery = $this->delivery(null, false);

		$delivery->deliver([], 'besch-1');

		$this->assertSame(['id' => ''], $this->calls[0]['case']);
	}//end testABeschikkingWithoutACaseIsNotLookedUp()
}//end class
