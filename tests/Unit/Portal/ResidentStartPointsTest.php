<?php

/**
 * Resident Start Points Test
 *
 * An action a resident starts from nothing gets a page that offers it and a
 * tile to that page on the overview (resident-start-points-have-a-page).
 * Portaliq makes a start tile only for an action a page offers through an
 * `action` block (StartTileCollector), so without the page the general Woo
 * request had no way in for a resident without a dossier.
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
 * @spec openspec/changes/resident-start-points-have-a-page/specs/portal-contribution/spec.md#requirement-every-start-point-has-a-page-and-a-tile-on-the-overview-req-rsp-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Portal\PortalPages;
use OCA\Dossiq\Service\Intake\IntakeCaseTypes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Portal\PortalPages
 * @uses   \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses   \OCA\Dossiq\Portal\CitizenManifest
 * @uses   \OCA\Dossiq\Portal\PortalConversation
 */
class ResidentStartPointsTest extends TestCase {
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
	 * The page portaliq's start tile routes to offers the general Woo request, and nothing else.
	 *
	 * Mirrors StartTileCollector: the first page with an `action` block on the
	 * action is the tile's page. The action must declare the label and
	 * summary the tile reads.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return void
	 */
	public function testAPageOffersTheRequestThatStartsFromNothing(string $audience): void {
		$contribution = $this->provider()->getContribution(['audience' => $audience]);

		$this->assertSame('startWooVerzoekAlgemeen', $this->tilePageOf(contribution: $contribution, actionId: 'startWooVerzoekAlgemeen'));

		$page = $this->page(contribution: $contribution, id: 'startWooVerzoekAlgemeen');
		$this->assertSame([['type' => 'action', 'action' => 'startWooVerzoekAlgemeen']], $page['blocks']);
		$this->assertFalse($page['menu']);
		$this->assertSame('Mijn zaken en verzoeken', $page['group']);

		$action = $this->action(contribution: $contribution, id: 'startWooVerzoekAlgemeen');
		$this->assertIsString($action['summary']);
		$this->assertNotSame([], $action['audiences']);
	}//end testAPageOffersTheRequestThatStartsFromNothing()

	/**
	 * The overview carries a tile to that page right after what the resident still has to do.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @param string $audience The resident audience.
	 *
	 * @return void
	 */
	public function testTheOverviewHasATileToTheStartPage(string $audience): void {
		$overview = $this->page(contribution: $this->provider()->getContribution(['audience' => $audience]), id: 'overzicht');
		$types = array_column($overview['blocks'], 'type');

		$cta = $overview['blocks'][array_search('cta', $types, true)];
		$this->assertSame(['type' => 'cta', 'page' => 'startWooVerzoekAlgemeen', 'label' => 'Informatie opvragen (Woo-verzoek)'], $cta);
		$this->assertSame(['greeting', 'tasks', 'cta', 'cases', 'inbox'], $types);
	}//end testTheOverviewHasATileToTheStartPage()

	/**
	 * What needs a case, a row or a dossier gets no start page.
	 *
	 * A bezwaar and a klacht are about one case, the plate change acts on one
	 * permit, the dossier Woo request on one dossier.
	 *
	 * @return void
	 */
	public function testAnActionThatNeedsSomethingToStartFromGetsNoPage(): void {
		$ids = array_column($this->provider()->getContribution(['audience' => 'citizen'])['pages'], 'id');

		foreach (['createBezwaar', 'createKlacht', 'startWooVerzoek', 'changePermitPlate', 'replyToMessage'] as $id) {
			$this->assertNotContains($id, $ids);
		}
	}//end testAnActionThatNeedsSomethingToStartFromGetsNoPage()

	/**
	 * The rule reads the declaration, not a name: any start point gets a page, once.
	 *
	 * @return void
	 */
	public function testAnyStartPointGetsOnePageAndAnOfferedOneNone(): void {
		$collections = [
			['id' => 'verzoeken', 'schema' => 'portaalVerzoek'],
		];
		$actions = [
			['id' => 'askSomething', 'label' => 'Iets vragen', 'summary' => 'Stel uw vraag.', 'audiences' => ['citizen']],
			['id' => 'fileRequest', 'type' => 'create', 'schema' => 'portaalVerzoek', 'label' => 'Verzoek', 'summary' => 'Dien een verzoek in.', 'audiences' => ['citizen']],
			['id' => 'noSummary', 'label' => 'Zonder samenvatting', 'audiences' => ['citizen']],
			['id' => 'noAudience', 'label' => 'Zonder publiek', 'summary' => 'Geen publiek.'],
		];

		$pages = (new PortalPages())->forResident(collections: $collections, actions: $actions, group: 'G');
		$ids = array_column($pages, 'id');

		// `fileRequest` already starts the verzoeken page, so it gets no second page.
		$this->assertSame(['overzicht', 'mijnZaken', 'verzoeken', 'askSomething'], $ids);
		$this->assertSame(
			[['type' => 'cta', 'page' => 'askSomething', 'label' => 'Iets vragen']],
			array_values(array_filter($pages[0]['blocks'], static fn (array $b): bool => $b['type'] === 'cta'))
		);
	}//end testAnyStartPointGetsOnePageAndAnOfferedOneNone()

	/**
	 * The page a start tile routes to, as portaliq's StartTileCollector finds it.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 * @param string               $actionId     The action id.
	 *
	 * @return string The page id, or '' when no page offers the action.
	 */
	private function tilePageOf(array $contribution, string $actionId): string {
		foreach ($contribution['pages'] as $page) {
			foreach ($page['blocks'] as $block) {
				if (($block['type'] ?? '') === 'action' && ($block['action'] ?? '') === $actionId) {
					return (string)$page['id'];
				}
			}
		}

		return '';
	}//end tilePageOf()

	/**
	 * One page by its id.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 * @param string               $id           The page id.
	 *
	 * @return array<string, mixed> The page.
	 */
	private function page(array $contribution, string $id): array {
		foreach ($contribution['pages'] as $page) {
			if ($page['id'] === $id) {
				return $page;
			}
		}

		$this->fail('no page ' . $id);
	}//end page()

	/**
	 * One action by its id.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 * @param string               $id           The action id.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function action(array $contribution, string $id): array {
		foreach ($contribution['actions'] as $action) {
			if ($action['id'] === $id) {
				return $action;
			}
		}

		$this->fail('no action ' . $id);
	}//end action()

	/**
	 * The provider, built the way portaliq builds it.
	 *
	 * @return PortalContributionProvider
	 */
	private function provider(): PortalContributionProvider {
		$caseTypes = $this->createMock(IntakeCaseTypes::class);
		$caseTypes->method('caseTypes')->willReturn(
			[
				'createBezwaar' => 'bbbbbbbb-0000-4000-a000-000000000001',
				'createKlacht' => 'kkkkkkkk-0000-4000-a000-000000000002',
			]
		);
		return new PortalContributionProvider(caseTypes: $caseTypes);
	}//end provider()
}//end class
