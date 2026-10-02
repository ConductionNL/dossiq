<?php

/**
 * Portal Case Page Test
 *
 * The resident's pages dossiq declares to portaliq: the case page carries
 * portaliq's case screen (`citizenCase`), so a Woo request opened from "Mijn
 * zaken" can be withdrawn, and every other collection keeps the page portaliq
 * would have built for it.
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
 * @spec openspec/changes/portal-case-page-withdraws/specs/portal-contribution/spec.md#requirement-req-portal-021-a-resident-must-open-their-own-case-on-a-page-that-can-withdraw-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
 */
class PortalCasePageTest extends TestCase {
	/**
	 * A resident arrives as `citizen` or, since DigiD, as `client`.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function residentAudienceProvider(): array {
		return [
			'citizen' => ['citizen'],
			'client'  => ['client'],
		];
	}

	/**
	 * The case page is the table, the selected case and portaliq's case screen.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return void
	 */
	public function testTheCasePageCarriesTheResidentsOwnCase(string $audience): void {
		$pages = $this->pagesById(audience: $audience);

		$this->assertArrayHasKey('mijnZaken', $pages);
		$this->assertSame('Mijn zaken', $pages['mijnZaken']['label']);
		$this->assertSame(
			[
				['type' => 'collection', 'collection' => 'mijnZaken'],
				['type' => 'detail', 'collection' => 'mijnZaken'],
				['type' => 'citizenCase', 'collection' => 'mijnZaken'],
			],
			$pages['mijnZaken']['blocks']
		);
	}

	/**
	 * "Mijn zaken" opens a case on the FIRST page that shows its collection.
	 *
	 * Portaliq's `navKeyFor` walks the pages in order and takes the first one
	 * with a `collection`, `detail` or `citizenCase` block on the case's
	 * collection. That page must be the one with the case screen, or the link
	 * lands where nothing can be withdrawn.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return void
	 */
	public function testMyCasesOpensACaseOnThePageThatCanWithdrawIt(string $audience): void {
		$contribution = $this->provider()->getContribution(['audience' => $audience]);

		$landing = null;
		foreach ($contribution['pages'] as $page) {
			foreach ($page['blocks'] as $block) {
				if (($block['collection'] ?? null) === 'mijnZaken'
					&& in_array($block['type'], ['collection', 'citizenCase', 'detail'], true) === true
				) {
					$landing = $page;
					break 2;
				}
			}
		}

		$this->assertNotNull($landing, 'a page must show mijnZaken, or "Mijn zaken" cannot open a case');
		$this->assertContains('citizenCase', array_column($landing['blocks'], 'type'));
	}

	/**
	 * Declaring pages switches portaliq's own off: every collection keeps one.
	 *
	 * Each listable collection keeps the page portaliq built for it, with the
	 * same id (so the site's routes stay) and the first create action of its
	 * schema on top.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return void
	 */
	public function testEveryCollectionKeepsThePagePortaliqGaveIt(string $audience): void {
		$pages = $this->pagesById(audience: $audience);

		$this->assertSame(['mijnZaken', 'berichten', 'verzoeken'], array_keys($pages));
		$this->assertSame('Berichten', $pages['berichten']['label']);
		$this->assertSame(
			[
				['type' => 'action', 'action' => 'replyToMessage'],
				['type' => 'collection', 'collection' => 'berichten'],
				['type' => 'detail', 'collection' => 'berichten'],
			],
			$pages['berichten']['blocks']
		);
		$this->assertSame('Mijn verzoeken', $pages['verzoeken']['label']);
		$this->assertSame(
			[
				['type' => 'action', 'action' => 'createKlacht'],
				['type' => 'collection', 'collection' => 'verzoeken'],
				['type' => 'detail', 'collection' => 'verzoeken'],
			],
			$pages['verzoeken']['blocks']
		);
	}

	/**
	 * Every block names something of the same contribution.
	 *
	 * Portaliq drops a block whose reference does not resolve, and a page whose
	 * blocks all dropped, without a word.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return void
	 */
	public function testEveryBlockResolvesWithinTheContribution(string $audience): void {
		$contribution = $this->provider()->getContribution(['audience' => $audience]);
		$collectionIds = array_column($contribution['collections'], 'id');
		$actionIds = array_column($contribution['actions'], 'id');

		foreach ($contribution['pages'] as $page) {
			foreach ($page['blocks'] as $block) {
				if ($block['type'] === 'action') {
					$this->assertContains($block['action'], $actionIds);
					continue;
				}

				$this->assertContains($block['collection'], $collectionIds);
			}
		}
	}

	/**
	 * Suppliers and inspectors keep the pages portaliq builds.
	 *
	 * @return void
	 */
	public function testOtherAudiencesDeclareNoPages(): void {
		foreach (['supplier', 'inspector'] as $audience) {
			$this->assertArrayNotHasKey('pages', $this->provider()->getContribution(['audience' => $audience]));
		}
	}

	/**
	 * The provider, built the way portaliq builds it.
	 *
	 * @return PortalContributionProvider
	 */
	private function provider(): PortalContributionProvider {
		return new PortalContributionProvider();
	}

	/**
	 * The resident's pages keyed by id, in declared order.
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function pagesById(string $audience): array {
		$contribution = $this->provider()->getContribution(['audience' => $audience]);
		$this->assertIsArray($contribution);
		$this->assertArrayHasKey('pages', $contribution);

		$pages = [];
		foreach ($contribution['pages'] as $page) {
			$pages[$page['id']] = $page;
		}

		return $pages;
	}
}
