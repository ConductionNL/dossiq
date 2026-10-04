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
 * @uses   \OCA\Dossiq\Portal\PortalPages
 * @uses   \OCA\Dossiq\Portal\CitizenManifest
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
		// Renamed so it does not repeat the site's own "Mijn zaken" (portal-pages-in-resident-groups).
		// The case page of the design (site-resident-portal-design D4): the
		// question on this case first, then where it stands, its documents,
		// what happened, the four facts, a way to write and the case screen.
		$this->assertSame('Uw zaak', $pages['mijnZaken']['label']);
		$this->assertSame(
			[
				'tasks',
				'steps',
				'documents',
				'timeline',
				'detail',
				'cta',
				'citizenCase',
			],
			array_column($pages['mijnZaken']['blocks'], 'type')
		);
		// It is a record page on its own collection, which is how portaliq
		// finds it when a case is opened from a list, a card or a notice, and
		// what lets steps, documents and timeline render at all.
		$this->assertSame(
			['collection' => 'mijnZaken', 'titleFields' => ['title']],
			$pages['mijnZaken']['record']
		);
		$this->assertFalse($pages['mijnZaken']['menu']);
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

		$this->assertSame(['overzicht', 'mijnZaken', 'berichten', 'verzoeken'], array_keys($pages));
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
		$this->assertTrue($pages['overzicht']['home'], 'the resident lands on the overview');
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
				// A cta names an action, a page or a route; the ones here all
				// name an action (site-resident-portal-design D4).
				if ($block['type'] === 'action' || $block['type'] === 'cta') {
					$this->assertContains($block['action'], $actionIds);
					continue;
				}

				$this->assertContains($block['collection'], $collectionIds);
			}
		}
	}

	/**
	 * "Mijn zaken" shows the status in words, from a field the case list projects.
	 *
	 * Portaliq drops a `statusLabelField` that names a field outside the
	 * collection's `fields`, and then shows the uuid in `status` again.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-case-page-withdraws/specs/portal-contribution/spec.md#requirement-req-portal-022-mijn-zaken-must-show-the-status-in-words
	 */
	public function testMyCasesShowsTheStatusInWords(string $audience): void {
		$cases = null;
		foreach ($this->provider()->getContribution(['audience' => $audience])['collections'] as $collection) {
			if ($collection['id'] === 'mijnZaken') {
				$cases = $collection;
			}
		}

		$this->assertNotNull($cases);
		$this->assertSame('cases', $cases['kind']);
		$this->assertSame('statusPublicLabel', $cases['statusLabelField']);
		$this->assertContains($cases['statusLabelField'], $cases['fields']);
	}

	/**
	 * Suppliers and inspectors keep the blocks portaliq builds, declared only to carry a group, and no case screen.
	 *
	 * @return void
	 */
	public function testOtherAudiencesKeepPortaliqsBlocksAndNoCaseScreen(): void {
		foreach (['supplier', 'inspector'] as $audience) {
			foreach ($this->provider()->getContribution(['audience' => $audience])['pages'] as $page) {
				$this->assertNotSame('', $page['group']);
				$this->assertNotContains('citizenCase', array_column($page['blocks'], 'type'), $page['id']);
			}
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
