<?php

/**
 * Woo Publication Summary Test
 *
 * The line under the title of a published decision names the request by the
 * case's title and never prints an id.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Service\WOODecisionService;
use OCA\Dossiq\Woo\WooPublicationSummary;
use PHPUnit\Framework\TestCase;

/**
 * Tests for WooPublicationSummary.
 *
 * @covers \OCA\Dossiq\Woo\WooPublicationSummary
 */
class WooPublicationSummaryTest extends TestCase {
	/**
	 * A titled case is named by its title, trimmed.
	 *
	 * @return void
	 */
	public function testNamesTheRequestByTheCaseTitle(): void {
		$this->assertSame('Besluit op het Woo-verzoek "Raadsstukken"', WooPublicationSummary::forTitle(caseTitle: '  Raadsstukken '));
	}//end testNamesTheRequestByTheCaseTitle()

	/**
	 * A case without a title gets the generic line.
	 *
	 * @return void
	 */
	public function testACaseWithoutATitleGetsTheGenericLine(): void {
		$this->assertSame('Besluit op een Woo-verzoek', WooPublicationSummary::forTitle(caseTitle: ''));
		$this->assertSame('Besluit op een Woo-verzoek', WooPublicationSummary::forTitle(caseTitle: "\t "));
	}//end testACaseWithoutATitleGetsTheGenericLine()

	/**
	 * The decision service answers the same line.
	 *
	 * @return void
	 */
	public function testTheDecisionServiceAnswersTheSameLine(): void {
		$this->assertSame(WooPublicationSummary::forTitle(caseTitle: 'Brug'), WOODecisionService::publicationSummary(caseTitle: 'Brug'));
	}//end testTheDecisionServiceAnswersTheSameLine()
}//end class
