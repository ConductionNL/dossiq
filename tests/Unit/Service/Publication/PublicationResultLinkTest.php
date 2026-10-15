<?php

/**
 * Unit tests for the link a requester reads to what was made public.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Publication
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-the-requester-sees-where-the-decision-became-public-req-wds-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Publication;

use OCA\Dossiq\Service\Publication\PublicationResultLink;
use PHPUnit\Framework\TestCase;

/**
 * Pins what each publication state writes to `resultLink`.
 *
 * @covers \OCA\Dossiq\Service\Publication\PublicationResultLink
 */
class PublicationResultLinkTest extends TestCase {

	/**
	 * The url a published decision carries in these tests.
	 */
	private const URL = 'https://zuiddrecht.nl/apps/opencatalogi/publications/p-87';

	/**
	 * The label the requester reads keeps its stored wording.
	 *
	 * @return void
	 */
	public function testTheLabelKeepsItsWording(): void {
		$this->assertSame('Bekijk wat openbaar is gemaakt', PublicationResultLink::LABEL);
	}//end testTheLabelKeepsItsWording()

	/**
	 * A published state with a url writes the label and exactly that url.
	 *
	 * @return void
	 */
	public function testPublishedWithAUrlWritesTheLink(): void {
		$this->assertSame(
			['resultLink' => ['label' => PublicationResultLink::LABEL, 'url' => self::URL]],
			(new PublicationResultLink())->changesFor(state: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => self::URL])
		);
	}//end testPublishedWithAUrlWritesTheLink()

	/**
	 * A withdrawn state clears the link, whatever url is still in the write.
	 *
	 * @return void
	 */
	public function testWithdrawnClearsTheLinkEvenWithAUrl(): void {
		$links = new PublicationResultLink();

		$this->assertSame(['resultLink' => null], $links->changesFor(state: ['wooPublicationStatus' => 'withdrawn']));
		$this->assertSame(['resultLink' => null], $links->changesFor(state: ['wooPublicationStatus' => 'withdrawn', 'wooPublicationUrl' => self::URL]));
	}//end testWithdrawnClearsTheLinkEvenWithAUrl()

	/**
	 * Every other state, and a published one without a url, changes nothing.
	 *
	 * @return void
	 */
	public function testOtherStatesChangeNothing(): void {
		$links = new PublicationResultLink();

		$this->assertSame([], $links->changesFor(state: []));
		$this->assertSame([], $links->changesFor(state: ['wooPublicationUrl' => self::URL]));
		$this->assertSame([], $links->changesFor(state: ['wooPublicationStatus' => 'ready', 'wooPublicationUrl' => self::URL]));
		$this->assertSame([], $links->changesFor(state: ['wooPublicationStatus' => 'failed', 'wooPublicationUrl' => self::URL]));
		$this->assertSame([], $links->changesFor(state: ['wooPublicationStatus' => 'published']));
		$this->assertSame([], $links->changesFor(state: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => '']));
	}//end testOtherStatesChangeNothing()

	/**
	 * A non-string status or url is read as its string form.
	 *
	 * @return void
	 */
	public function testScalarValuesAreReadAsStrings(): void {
		$this->assertSame(
			['resultLink' => ['label' => PublicationResultLink::LABEL, 'url' => '87']],
			(new PublicationResultLink())->changesFor(state: ['wooPublicationStatus' => 'published', 'wooPublicationUrl' => 87])
		);
	}//end testScalarValuesAreReadAsStrings()
}//end class
