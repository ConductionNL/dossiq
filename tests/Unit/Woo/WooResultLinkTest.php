<?php

/**
 * Unit tests for PublicationResultLink: a published Woo decision gives the requester a
 * link to what was made public, a withdrawal clears it, and any other state
 * leaves the case alone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\Publication\PublicationResultLink;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Publication\PublicationResultLink
 */
class WooResultLinkTest extends TestCase {

	public function testAPublishedDecisionWithAUrlLinksTheRequester(): void {
		$this->assertSame(
			['resultLink' => ['label' => 'Bekijk wat openbaar is gemaakt', 'url' => 'https://open.example/woo/42']],
			(new PublicationResultLink())->changesFor(['wooPublicationStatus' => 'published', 'wooPublicationUrl' => 'https://open.example/woo/42'])
		);
	}//end testAPublishedDecisionWithAUrlLinksTheRequester()

	public function testAWithdrawalClearsTheLinkEvenWithAUrl(): void {
		$this->assertSame(
			['resultLink' => null],
			(new PublicationResultLink())->changesFor(['wooPublicationStatus' => 'withdrawn', 'wooPublicationUrl' => 'https://open.example/woo/42'])
		);
	}//end testAWithdrawalClearsTheLinkEvenWithAUrl()

	public function testAnyOtherStateChangesNothing(): void {
		$link = new PublicationResultLink();

		$this->assertSame([], $link->changesFor(['wooPublicationStatus' => 'published', 'wooPublicationUrl' => '']));
		$this->assertSame([], $link->changesFor(['wooPublicationStatus' => 'published']));
		$this->assertSame([], $link->changesFor(['wooPublicationStatus' => 'pending', 'wooPublicationUrl' => 'https://open.example/woo/42']));
		$this->assertSame([], $link->changesFor([]));
	}//end testAnyOtherStateChangesNothing()
}//end class
