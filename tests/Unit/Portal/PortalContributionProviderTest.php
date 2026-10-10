<?php

/**
 * Portal Contribution Provider Test
 *
 * Verifies dossiq's three-audience Portaliq contribution (ADR-046,
 * procest#162): the advertised audiences, per-audience collections/actions and
 * the fail-closed null for an unserved audience — plus a register-drift pin that
 * asserts every declared scopeField and every field-projection entry exists as a
 * property on its schema in dossiq_register.json (so a schema rename can never
 * silently break a portal scope key or leak a dropped column).
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
 * @spec openspec/changes/move-portals-to-portaliq/tasks.md#T6
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses   \OCA\Dossiq\Portal\PortalPages
 * @uses   \OCA\Dossiq\Portal\CitizenManifest
 * @uses   \OCA\Dossiq\Portal\PortalConversation
 */
class PortalContributionProviderTest extends TestCase {
	/**
	 * The provider under test.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * Schema definitions from dossiq_register.json, keyed by slug.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $schemas;

	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

		// Load the base register + every register.d fragment exactly as the
		// runtime does (SettingsService merges lib/Settings/register.d/*.json
		// on top of dossiq_register.json), so a scope key that lives in a
		// fragment schema (e.g. portaalBericht in 50-zaakportaal.json) is
		// resolvable by the drift pin.
		$settingsDir = __DIR__ . '/../../../lib/Settings';
		$basePath = $settingsDir . '/dossiq_register.json';
		$this->assertFileExists($basePath);

		$files = array_merge([$basePath], glob($settingsDir . '/register.d/*.json'));

		// Union every schema's properties across the base + all fragments — a
		// fragment that re-declares a schema (e.g. `case`) contributes ADDED
		// properties rather than replacing the whole definition, mirroring the
		// runtime union merge.
		$this->schemas = [];
		foreach ($files as $file) {
			foreach ($this->schemasFrom($file) as $slug => $definition) {
				$props = (is_array(($definition['properties'] ?? null)) === true ? $definition['properties'] : []);
				$this->schemas[$slug]['properties'] = array_merge(($this->schemas[$slug]['properties'] ?? []), $props);
			}
		}

		$this->assertNotEmpty($this->schemas, 'the register must declare schemas');
	}

	/**
	 * What the organisation still needs from the resident: the collection,
	 * its scope, and what it does and does not project
	 * (site-resident-portal-design D1).
	 *
	 * @return void
	 */
	public function testTheQuestionsToTheResidentAreScopedAndMinimal(): void {
		$collection = $this->citizenCollection(id: 'vragenAanU');

		$this->assertSame('aanvullingsverzoek', $collection['schema']);
		$this->assertSame('portalSubject', $collection['scopeField']);
		$this->assertSame('Wat wij nog van u nodig hebben', $collection['label']);
		$this->assertSame(
			['case', 'summary', 'missingItems', 'hersteltermijn', 'state', 'requestedAt'],
			$collection['fields']
		);
		// Written for a colleague, so never handed to the applicant.
		foreach (['rationale', 'party', 'recipient', 'requestedBy', 'pauseReason', 'deadlineInstance', 'pauseDays'] as $internal) {
			$this->assertNotContains($internal, $collection['fields'], $internal);
		}

		$this->assertSame(['state' => 'open'], $collection['defaultFilters']);

		// EVERY PROJECTED FIELD EXISTS ON THE SCHEMA. A field that does not
		// is projected as nothing at all, which reads exactly like a field
		// the organisation chose not to show.
		foreach ($collection['fields'] as $field) {
			$this->assertArrayHasKey(
				$field,
				$this->schemas['aanvullingsverzoek']['properties'],
				$field . ' must exist on aanvullingsverzoek'
			);
		}
	}//end testTheQuestionsToTheResidentAreScopedAndMinimal()

	/**
	 * Who is at turn, by when, and where the case stands: one field each for
	 * the card, the words beside them, and the steps provider
	 * (site-resident-portal-design D2 and D3).
	 *
	 * @return void
	 */
	public function testTheCaseCardSaysWhoIsAtTurnAndWhereTheCaseStands(): void {
		$cases = $this->citizenCollection(id: 'mijnZaken');

		$this->assertSame('portalTurn', $cases['turnField']);
		$this->assertSame('deadline', $cases['dueField']);
		$this->assertSame(
			[
				'applicant' => 'Wacht op u',
				'thirdParty' => 'Wij wachten op informatie van een ander',
				'us' => 'De gemeente is aan zet',
			],
			$cases['valueLabels']['portalTurn'],
			'the words are dossiq\'s, so a portal administrator can reword them'
		);
		$this->assertSame(
			['label' => 'Waar staat uw aanvraag?', 'provider' => 'caseSteps'],
			$cases['steps']
		);

		// The card reads one field; the two facts behind it travel with it.
		foreach (['portalTurn', 'waitingOnApplicant', 'waitingOn', 'assignedGroupPublicName'] as $field) {
			$this->assertContains($field, $cases['fields'], $field);
			$this->assertArrayHasKey($field, $this->schemas['case']['properties'], $field . ' must exist on case');
		}

		$this->assertContains('assignedGroupPublicName', $cases['detail']['fields']);
		$this->assertArrayHasKey('publicName', $this->schemas['organisatieRol']['properties']);
	}//end testTheCaseCardSaysWhoIsAtTurnAndWhereTheCaseStands()

	/**
	 * The steps provider answers through the provider's own method, and
	 * answers nothing rather than throwing when it is not wired.
	 *
	 * @return void
	 */
	public function testTheStepsProviderIsReachableFromTheContribution(): void {
		$this->assertTrue(method_exists($this->provider, 'caseSteps'));
		$this->assertSame([], $this->provider->caseSteps('a-case'));
	}//end testTheStepsProviderIsReachableFromTheContribution()

	/**
	 * "Bericht sturen" on a case page presets the open case into the one
	 * field the action's own cross-reference guard checks
	 * (site-mijn-omgeving-components REQ-SMO-024).
	 *
	 * @return void
	 */
	public function testTheReplyActionNamesTheFieldARecordLandsIn(): void {
		$reply = $this->citizenAction(id: 'replyToMessage');

		$this->assertSame('caseId', $reply['recordField']);
		$this->assertContains('caseId', $reply['fields']);
		$this->assertArrayHasKey('caseId', $reply['crossRefs']);

		$page = $this->citizenPage(id: 'mijnZaken');
		$cta = null;
		foreach ($page['blocks'] as $block) {
			if (($block['type'] ?? '') === 'cta' && ($block['action'] ?? '') === 'replyToMessage') {
				$cta = $block;
			}
		}

		$this->assertSame(
			['type' => 'cta', 'action' => 'replyToMessage', 'label' => 'Bericht sturen', 'withRecord' => true],
			$cta
		);

		// Bezwaar and klacht land the open case the same way, in the field
		// their own guard checks (case-actions-on-the-case-page).
		foreach (['createBezwaar', 'createKlacht'] as $id) {
			$action = $this->citizenAction(id: $id);
			$this->assertSame('againstCaseId', $action['recordField'], $id);
			$this->assertContains('againstCaseId', $action['fields'], $id);
			$this->assertArrayHasKey('againstCaseId', $action['crossRefs'], $id);
			// The case is given by the page, never typed.
			$this->assertFalse($action['fieldConfigs']['againstCaseId']['visible'], $id);
		}
	}//end testTheReplyActionNamesTheFieldARecordLandsIn()

	/**
	 * The start points a home page lists say in one sentence what they are
	 * for, and to whom they are offered (site-resident-portal-design D5).
	 *
	 * @return void
	 */
	public function testTheStartPointsCarryTheirOwnSentence(): void {
		foreach (['createBezwaar' => 'Bezwaar maken', 'createKlacht' => 'Klacht indienen'] as $id => $label) {
			$action = $this->citizenAction(id: $id);
			$this->assertSame($label, $action['label']);
			$this->assertNotSame('', trim($action['summary']));
			$this->assertLessThanOrEqual(200, mb_strlen($action['summary']), $id . ': a tile reads one sentence');
			$this->assertSame(['citizen', 'client'], $action['audiences']);
			// A service promise dossiq cannot know is true for an instance
			// stays off the tile; an editor may add it on the page.
			$this->assertStringNotContainsStringIgnoringCase('werkdagen', $action['summary']);
		}
	}//end testTheStartPointsCarryTheirOwnSentence()

	/**
	 * 🔴 A REQUIRED FIELD IS DECLARED WHERE PORTALIQ READS ONE. Portaliq takes
	 * a required marker from the written schema's own `required` list and from
	 * an action's `requiredFields` (REQ-SMF-023/024). `required` on a field
	 * config is dropped in silence, so the Woo form read "(niet verplicht)"
	 * on the one question it cannot do without. Measured by running this
	 * manifest through portaliq's own resolvers: the key was the only one of
	 * ours they threw away.
	 *
	 * @return void
	 */
	public function testARequiredFieldIsDeclaredWherePortaliqReadsOne(): void {
		$woo = $this->citizenAction(id: 'startWooVerzoek');

		$this->assertSame(['onderwerp'], $woo['requiredFields']);
		$this->assertContains('onderwerp', $woo['fields'], 'a required field must be one the action sends');

		foreach ($this->provider->getContribution(['audience' => 'citizen'])['actions'] as $action) {
			foreach ((array)($action['fieldConfigs'] ?? []) as $field => $config) {
				$this->assertArrayNotHasKey(
					'required',
					(array)$config,
					($action['id'] ?? '?') . '.' . $field . ': portaliq drops a required field config; use requiredFields'
				);
			}
		}
	}//end testARequiredFieldIsDeclaredWherePortaliqReadsOne()

	/**
	 * The case page's question block is scoped to the case on screen, so a
	 * resident with questions on two cases reads only this one's.
	 *
	 * @return void
	 */
	public function testTheCasePageAsksOnlyAboutTheCaseOnScreen(): void {
		$pages = $this->provider->getContribution(['audience' => 'citizen'])['pages'];
		$case = null;
		foreach ($pages as $page) {
			if (($page['id'] ?? '') === 'mijnZaken') {
				$case = $page;
			}
		}

		$this->assertNotNull($case, 'the case page must be declared');
		$tasks = $case['blocks'][0];
		$this->assertSame('tasks', $tasks['type']);
		$this->assertSame('vragenAanU', $tasks['collection']);
		$this->assertSame('case', $tasks['recordField'], 'the open record is matched on the request\'s case');
	}//end testTheCasePageAsksOnlyAboutTheCaseOnScreen()

	/**
	 * 🔴 The Woo request runs in four steps, and every field it asks sits in
	 * exactly one of them.
	 *
	 * Portaliq keeps a step only when every field it names is one of the
	 * action's own and no earlier step holds it, so a field left out of every
	 * step would simply never be asked, and a field in two steps would drop
	 * the second one. Neither failure says anything at the time.
	 *
	 * @return void
	 */
	public function testEveryWooFieldSitsInExactlyOneStep(): void {
		foreach (['startWooVerzoek', 'startWooVerzoekAlgemeen'] as $id) {
			$action = $this->citizenAction(id: $id);

			$this->assertSame(
				['vraag', 'periode', 'gegevens', 'controleren'],
				array_column($action['steps'], 'id'),
				$id . ': the four steps of the design'
			);
			$this->assertSame(
				['Uw vraag', 'Periode en documenten', 'Uw gegevens', 'Controleren en versturen'],
				array_column($action['steps'], 'title')
			);

			$placed = [];
			foreach ($action['steps'] as $step) {
				foreach (($step['fields'] ?? []) as $field) {
					$this->assertContains($field, $action['fields'], $id . ': a step may only ask a field the action sends');
					$this->assertNotContains($field, $placed, $id . ': ' . $field . ' sits in two steps');
					$placed[] = $field;
				}
			}

			$review = end($action['steps']);
			$this->assertTrue($review['review'], $id . ': the last step reviews the answers');
			$this->assertArrayNotHasKey('fields', $review, 'a review asks nothing of its own');

			// EVERY field, `collectionId` included: portaliq gathers whatever
			// no step names into a loose step with no title, so a field left
			// out does not go missing, it appears as a step of its own.
			$asked = $action['fields'];
			sort($asked);
			sort($placed);
			$this->assertSame($asked, $placed, $id . ': every field it asks has a step');
		}
	}//end testEveryWooFieldSitsInExactlyOneStep()

	/**
	 * Saving halfway and the confirmation are declared, on both doors.
	 *
	 * @return void
	 */
	public function testTheWooRequestSavesHalfwayAndNamesTheCaseWhenItIsIn(): void {
		foreach (['startWooVerzoek', 'startWooVerzoekAlgemeen'] as $id) {
			$action = $this->citizenAction(id: $id);

			$this->assertSame(['retentionDays' => 30], $action['draft'], $id . ': within portaliq\'s 1 to 90 days');
			$this->assertSame('Wij hebben uw Woo-verzoek ontvangen', $action['confirmation']['title']);
			$this->assertStringContainsString('{identifier}', $action['confirmation']['body']);
			$this->assertStringContainsString('{deadline}', $action['confirmation']['body']);
			$this->assertNotSame('', trim($action['confirmation']['next']));
		}
	}//end testTheWooRequestSavesHalfwayAndNamesTheCaseWhenItIsIn()

	/**
	 * Two doors, one route: only the dossier variant is attached to a
	 * dossier, and the other carries the sentence the home tile reads.
	 *
	 * @return void
	 */
	public function testTheWooRequestHasADoorWithoutADossier(): void {
		$dossier = $this->citizenAction(id: 'startWooVerzoek');
		$open = $this->citizenAction(id: 'startWooVerzoekAlgemeen');

		$this->assertSame($dossier['endpoint'], $open['endpoint'], 'one route');
		$this->assertSame(
			array_column($dossier['steps'], 'title'),
			array_column($open['steps'], 'title'),
			'the same questions, in the same order'
		);
		$this->assertSame(
			['onderwerp', 'omschrijving'],
			$open['steps'][0]['fields'],
			'without a dossier the first step asks only the question'
		);
		$this->assertSame(
			['collectionId', 'onderwerp', 'omschrijving'],
			$dossier['steps'][0]['fields'],
			'the dossier rides hidden in the first step, so portaliq makes no titleless step for it'
		);
		$this->assertArrayNotHasKey('attachTo', $open, 'it is offered anywhere a resident is signed in');
		$this->assertArrayNotHasKey('rowField', $open);
		$this->assertArrayNotHasKey('collectionId', array_flip($open['fields']));
		$this->assertSame(['app' => 'opencatalogi', 'schema' => 'collection'], $dossier['attachTo'], 'unchanged');
		$this->assertSame('collectionId', $dossier['rowField'], 'unchanged');

		$this->assertSame('Informatie opvragen (Woo-verzoek)', $open['label']);
		$this->assertLessThanOrEqual(200, mb_strlen($open['summary']));
		$this->assertSame(['citizen', 'client'], $open['audiences']);
		$this->assertStringNotContainsStringIgnoringCase('werkdagen', $open['summary']);
	}//end testTheWooRequestHasADoorWithoutADossier()

	/**
	 * One citizen collection by its id.
	 *
	 * @param string $id The collection id.
	 *
	 * @return array<string, mixed> The collection.
	 */
	private function citizenCollection(string $id): array {
		foreach ($this->provider->getContribution(['audience' => 'citizen'])['collections'] as $collection) {
			if (($collection['id'] ?? '') === $id) {
				return $collection;
			}
		}

		$this->fail('the citizen contribution must declare the collection ' . $id);
	}//end citizenCollection()

	/**
	 * One citizen action by its id.
	 *
	 * @param string $id The action id.
	 *
	 * @return array<string, mixed> The action.
	 */
	private function citizenAction(string $id): array {
		foreach ($this->provider->getContribution(['audience' => 'citizen'])['actions'] as $action) {
			if (($action['id'] ?? '') === $id) {
				return $action;
			}
		}

		$this->fail('the citizen contribution must declare the action ' . $id);
	}//end citizenAction()

	/**
	 * One citizen page by its id.
	 *
	 * @param string $id The page id.
	 *
	 * @return array<string, mixed> The page.
	 */
	private function citizenPage(string $id): array {
		foreach ($this->provider->getContribution(['audience' => 'citizen'])['pages'] as $page) {
			if (($page['id'] ?? '') === $id) {
				return $page;
			}
		}

		$this->fail('the citizen contribution must declare the page ' . $id);
	}//end citizenPage()

	public function testAdvertisesFourAudiences(): void {
		$this->assertSame(['supplier', 'citizen', 'client', 'inspector'], $this->provider->getAudiences());
	}

	public function testPrimaryAudienceFallbackIsSupplier(): void {
		$this->assertSame('supplier', $this->provider->getAudience());
	}

	public function testUnservedAudienceContributesNull(): void {
		$this->assertNull($this->provider->getContribution(['audience' => 'employee']));
		$this->assertNull($this->provider->getContribution(['audience' => '']));
		$this->assertNull($this->provider->getContribution([]));
	}

	public function testSupplierContributionShape(): void {
		$contribution = $this->provider->getContribution(['audience' => 'supplier']);
		$this->assertIsArray($contribution);
		$ids = array_column($contribution['collections'], 'id');
		// `performance` joins them: supplierKpi shipped with the portal's
		// schema foundation and no surface, so an instance computed a
		// supplier's payment record and showed it to nobody, least of all the
		// supplier it was about. The order is asserted because the portal
		// renders the collections in it.
		$this->assertSame(
			['tenders', 'contracts', 'invoices', 'performance', 'messages'],
			$ids
		);
		foreach ($contribution['collections'] as $collection) {
			$this->assertSame('supplierRef', $collection['scopeField']);
		}
	}

	public function testCitizenContributionShape(): void {
		$contribution = $this->provider->getContribution(['audience' => 'citizen']);
		$this->assertIsArray($contribution);
		$ids = array_column($contribution['collections'], 'id');
		// `vragenAanU` is what the organisation still needs from the resident
		// (site-resident-portal-design D1), between their cases and their
		// messages.
		$this->assertSame(['mijnZaken', 'vragenAanU', 'berichten', 'verzoeken'], $ids);

		// Three creates and the one update a resident makes on their own
		// case (dossiq#3152). The two creates that name a case were deferred until Portaliq
		// could check a reference against the sender's own scope; they are
		// asserted to carry that check below, not merely to exist.
		$actionIds = array_column($contribution['actions'], 'id');
		$this->assertSame(
			['createKlacht', 'createBezwaar', 'replyToMessage', 'askAboutCase', 'amendCase', 'startWooVerzoek', 'startWooVerzoekAlgemeen'],
			$actionIds
		);
	}

	/**
	 * A resident starts a Woo request from their dossier, as citizen and as client.
	 *
	 * An endpoint action: portaliq forwards it to dossiq with a signed
	 * assertion, and dossiq opens the case, its case objects and the dossier
	 * link in one go. No `type`, `register` or `schema`, so portaliq's flat
	 * writer can never take it for a create of its own.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-request-from-a-portal-dossier/specs/portal-contribution/spec.md#requirement-a-resident-starts-a-woo-request-from-the-portal-req-portal-020
	 */
	public function testAResidentStartsAWooRequestFromTheirDossier(string $audience): void {
		$actions = [];
		foreach ($this->provider->getContribution(['audience' => $audience])['actions'] as $action) {
			$actions[(string)$action['id']] = $action;
		}

		$this->assertArrayHasKey('startWooVerzoek', $actions);
		$action = $actions['startWooVerzoek'];
		$this->assertSame('/index.php/apps/dossiq/api/portal/woo-verzoek', $action['endpoint']);
		$this->assertSame('POST', $action['method']);
		$this->assertSame(
			[
				'collectionId',
				'onderwerp',
				'omschrijving',
				'periodeVan',
				'periodeTot',
				'documentSoorten',
				'toelichting',
				'verzoekerNaam',
				'verzoekerEmail',
				'verzoekerType',
			],
			$action['fields'],
			'the dossier variant asks what the steps ask, with the dossier it was started from'
		);
		$this->assertSame('Start een Woo-verzoek', $action['label']);
		$this->assertSame(['app' => 'opencatalogi', 'schema' => 'collection'], $action['attachTo']);
		$this->assertSame('collectionId', $action['rowField']);
		foreach (['type', 'register', 'schema', 'subjectRef', 'origin'] as $absent) {
			$this->assertArrayNotHasKey($absent, $action);
			$this->assertNotContains($absent, $action['fields']);
		}
	}

	/**
	 * The decision notice is dossiq's own message, so dossiq declares its key and no change rule.
	 *
	 * A change rule on `wooPublicationUrl` made portaliq write its generic
	 * "<case> is bijgewerkt" for the publish. dossiq now writes the message
	 * (WooDecisionNotice) and only declares the key, so portaliq sends the
	 * e-mail for it and writes nothing of its own.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function testTheWooDecisionNoticeIsADeclaredKeyNotAChangeRule(string $audience): void {
		$contribution = $this->provider->getContribution(['audience' => $audience]);

		$this->assertSame(['dossiq.wooRequest.published'], $contribution['notifications']);
	}//end testTheWooDecisionNoticeIsADeclaredKeyNotAChangeRule()

	/**
	 * Every page names its menu group, and no page repeats the site's own case list or inbox.
	 *
	 * @dataProvider residentAudienceProvider
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
	 */
	public function testTheResidentPagesShareOneGroupAndDoNotRepeatTheSiteSections(string $audience): void {
		$contribution = $this->provider->getContribution(['audience' => $audience]);

		$this->assertSame(['overzicht', 'mijnZaken', 'berichten', 'verzoeken'], array_column($contribution['pages'], 'id'));
		$this->assertSame(
			['Overzicht', 'Uw zaak', 'Berichten', 'Mijn verzoeken'],
			array_column($contribution['pages'], 'label')
		);
		foreach ($contribution['pages'] as $page) {
			$this->assertSame('Mijn zaken en verzoeken', $page['group']);
			// WHY THE RENAME IS GONE. These pages were renamed because they
			// stood in the portal's menu beside the site's own sections
			// (portal-pages-in-resident-groups). They declare `menu: false`
			// now, so they keep their route and leave the menu
			// (site-resident-portal-design D4, portaliq REQ-SMO-020): there
			// is nothing left to collide with, and a page a resident opens
			// may say what it is.
			$this->assertFalse($page['menu']);
		}

		$this->assertNotSame('Dossiq', $contribution['label']);
	}//end testTheResidentPagesShareOneGroupAndDoNotRepeatTheSiteSections()

	/**
	 * A case still opens from the site's case list: a page shows `mijnZaken` with its detail.
	 *
	 * The site finds the page for a case by a `collection` or `detail` block on
	 * that collection (portaliq src/shared/openRecord.js navKeyFor). Without it
	 * a case title in "Mijn zaken" is plain text and a notice link opens nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
	 */
	public function testACaseStillOpensOnItsPage(): void {
		$pages = $this->provider->getContribution(['audience' => 'citizen'])['pages'];

		// The overview opens with what the resident still has to do, and the
		// case page is the one a case opens on: it is a record page on
		// mijnZaken and it still carries the case screen.
		$this->assertSame('overzicht', $pages[0]['id']);
		// The greeting opens the overview (resident-overview-reads-as-designed);
		// what the resident still has to do comes right after it.
		$this->assertSame('greeting', $pages[0]['blocks'][0]['type']);
		$this->assertSame('tasks', $pages[0]['blocks'][1]['type']);
		$this->assertSame(['type' => 'greeting', 'showDate' => false], $pages[0]['blocks'][0]);
		$this->assertSame('Wat u nog moet doen', $pages[0]['blocks'][1]['label']);
		$this->assertSame(
			['Lopende zaken', 'Nieuwe berichten'],
			[$pages[0]['blocks'][2]['label'], $pages[0]['blocks'][3]['label']]
		);
		$this->assertSame(['cases', 'inbox'], [$pages[0]['blocks'][2]['type'], $pages[0]['blocks'][3]['type']]);
		// Bezwaar and klacht act on one case: they left the overview for the
		// case page (case-actions-on-the-case-page).
		$this->assertCount(4, $pages[0]['blocks']);
		$this->assertNotContains('cta', array_column($pages[0]['blocks'], 'type'));
		$this->assertSame(
			['collection' => 'mijnZaken', 'titleFields' => ['title'], 'heading' => 'record', 'under' => 'cases'],
			$pages[1]['record']
		);
		$this->assertContains('citizenCase', array_column($pages[1]['blocks'], 'type'));
		$this->assertSame(['type' => 'action', 'action' => 'replyToMessage'], $pages[2]['blocks'][0]);
		$this->assertSame(['type' => 'action', 'action' => 'createKlacht'], $pages[3]['blocks'][0]);
	}//end testACaseStillOpensOnItsPage()


	/**
	 * The resident pages declare the Mijn Zuiddrecht board keys, each in the
	 * one form portaliq's BoardKeys and SchoolBlockKeys keep (portaliq#1253,
	 * read from fb7a5203). Portaliq drops a key that does not fit in silence,
	 * so a misspelt value would read as declared and draw nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-resident-pages-are-declared-and-none-of-them-is-a-menu-entry-req-srpd-005
	 */
	public function testTheResidentPagesDeclareTheBoardKeys(): void {
		$pages = $this->provider->getContribution(['audience' => 'citizen'])['pages'];
		$byType = static function (array $page, string $type): array {
			foreach ($page['blocks'] as $block) {
				if ($block['type'] === $type) {
					return $block;
				}
			}

			return [];
		};

		$tasks = $byType($pages[0], 'tasks');
		$this->assertSame('highlight', $tasks['display']);
		$this->assertContains($tasks['tone'], ['warning', 'info']);
		$this->assertTrue($tasks['dueInLine']);
		$this->assertSame('Document toevoegen', $tasks['buttonLabel']);

		$cases = $byType($pages[0], 'cases');
		$this->assertSame('compact', $cases['display']);
		$this->assertTrue($cases['showAll']);
		// The turn value must be one the case card has words for.
		$turnWords = $this->citizenCollection(id: 'mijnZaken')['valueLabels']['portalTurn'];
		$this->assertSame(['applicant'], $cases['yourTurn']);
		$this->assertSame('Wacht op u', $turnWords['applicant']);

		$this->assertSame('list', $byType($pages[0], 'inbox')['display']);

		$documents = $byType($pages[1], 'documents');
		$this->assertSame(['Stukken', true], [$documents['label'], $documents['upload']]);
		$detail = $byType($pages[1], 'detail');
		$this->assertSame(['Gegevens', false], [$detail['label'], $detail['timeline']]);
		$this->assertSame('actions', $byType($pages[1], 'citizenCase')['display']);
		$this->assertSame('record', $pages[1]['record']['heading']);
		$this->assertSame('cases', $pages[1]['record']['under']);
	}//end testTheResidentPagesDeclareTheBoardKeys()

	/**
	 * Every audience's pages carry a group, and every block names something the manifest declares.
	 *
	 * Portaliq drops a block whose reference does not resolve, and a page
	 * whose blocks all drop, so a typo here would lose a page in silence.
	 *
	 * @dataProvider audienceProvider
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-every-dossiq-portal-page-names-its-menu-group
	 */
	public function testEveryPageHasAGroupAndResolvableBlocks(string $audience): void {
		$contribution = $this->provider->getContribution(['audience' => $audience]);
		$collections = array_column($contribution['collections'], 'id');
		$actions = array_column($contribution['actions'], 'id');

		$this->assertNotSame([], $contribution['pages']);
		foreach ($contribution['pages'] as $page) {
			$this->assertNotSame('', $page['group']);
			$this->assertNotSame('', $page['label']);
			foreach ($page['blocks'] as $block) {
				// A cta names an action here; portaliq also allows a page or
				// a route (REQ-SMO-024), which no dossiq tile needs.
				if ($block['type'] === 'action' || $block['type'] === 'cta') {
					$this->assertContains($block['action'], $actions);
					continue;
				}

				// A greeting names nothing: it reads the session (resident-overview-reads-as-designed).
				if ($block['type'] === 'greeting') {
					$this->assertArrayNotHasKey('collection', $block);
					continue;
				}

				$this->assertContains($block['collection'], $collections);
			}
		}
	}//end testEveryPageHasAGroupAndResolvableBlocks()

	/**
	 * The audiences a resident arrives as.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function residentAudienceProvider(): array {
		return [['citizen'], ['client']];
	}

	/**
	 * Every citizen create that names a case declares that case as a guarded
	 * reference to the citizen's own cases.
	 *
	 * This is the assertion the two deferred creates were waiting on. Without
	 * it, `againstCaseId` and `caseId` are uuids the writer takes as typed,
	 * which is how a citizen could object to somebody else's case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-creates-with-cross-refs/specs/portal-contribution/spec.md
	 */
	public function testEveryCitizenCreateNamingACaseGuardsIt(): void {
		$contribution = $this->provider->getContribution(['audience' => 'citizen']);
		$guarded = [];

		foreach ($contribution['actions'] as $action) {
			foreach (['againstCaseId', 'caseId'] as $reference) {
				if (in_array($reference, (array)($action['fields'] ?? []), true) === false) {
					continue;
				}

				$guarded[(string)$action['id']] = ($action['crossRefs'][$reference] ?? null);
			}
		}

		$this->assertSame(['createKlacht', 'createBezwaar', 'replyToMessage', 'askAboutCase'], array_keys($guarded));
		foreach ($guarded as $id => $declaration) {
			$this->assertIsArray($declaration, $id . ' names a case without guarding it');
			$this->assertSame('case', $declaration['schema'], $id);
			$this->assertSame('portalSubject', $declaration['scopeField'], $id);
			// A klacht may be about the municipality in general, so its case
			// is optional; when it names one, the guard still checks it is the
			// citizen's own (case-actions-on-the-case-page).
			$this->assertSame($id !== 'createKlacht', $declaration['required'], $id);
		}
	}

	/**
	 * What the write IS never comes from the sender.
	 *
	 * A bezwaar and a klacht run different statutory clocks, and a reply that
	 * could name its own direction could be filed as one the desk sent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-creates-with-cross-refs/specs/portal-contribution/spec.md
	 */
	public function testTheKindAndDirectionAreStampedNotOffered(): void {
		$actions = [];
		foreach ($this->provider->getContribution(['audience' => 'citizen'])['actions'] as $action) {
			$actions[(string)$action['id']] = $action;
		}

		$this->assertSame('bezwaarschrift', $actions['createBezwaar']['defaults']['kind']);
		$this->assertNotContains('kind', $actions['createBezwaar']['fields']);

		$this->assertSame('klachtschrift', $actions['createKlacht']['defaults']['kind']);
		$this->assertNotContains('kind', $actions['createKlacht']['fields']);

		// Every create on portaalVerzoek stamps a kind the schema accepts. A
		// kind left to the client arrived as 'klacht', outside the enum, and
		// every complaint filed from the portal answered 502 write_failed.
		$schemas = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/register.d/50-zaakportaal.json'), true)['components']['schemas'];
		$kinds = $schemas['portaalVerzoek']['properties']['kind']['enum'];
		foreach ($actions as $id => $action) {
			if (($action['type'] ?? '') !== 'create' || ($action['schema'] ?? '') !== 'portaalVerzoek') {
				continue;
			}

			$this->assertContains(($action['defaults']['kind'] ?? null), $kinds, "'{$id}' must stamp a kind the schema accepts");
			$this->assertNotContains('kind', $action['fields'], "'{$id}' must not let the sender choose the kind");
		}

		$this->assertSame('citizen_to_handler', $actions['replyToMessage']['defaults']['direction']);
		$this->assertNotContains('direction', $actions['replyToMessage']['fields']);
		$this->assertSame('senderRef', $actions['replyToMessage']['scopeField']);
	}

	/**
	 * The citizen's case detail declares a timeline, and names the reader.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testTheCitizenCaseDeclaresATimeline(): void {
		$contribution = $this->provider->getContribution(['audience' => 'citizen']);
		$cases = $contribution['collections'][0];

		$this->assertSame('mijnZaken', $cases['id']);
		$this->assertSame('caseTimeline', $cases['timeline']['provider']);
		$this->assertTrue(method_exists($this->provider, $cases['timeline']['provider']));
	}//end testTheCitizenCaseDeclaresATimeline()

	/**
	 * The contribution's timeline is exactly what the one reader answers.
	 *
	 * THE POINT OF THE ASSERTION IS THE IDENTITY, NOT THE COUNT. The provider
	 * is handed a case carrying two public entries and three internal ones,
	 * and what comes back must be the reader's own answer rather than a list
	 * the provider filtered for itself. A second filter here would drift from
	 * the one `#PublicStatus` uses, and the drift would be an internal note on
	 * a citizen's screen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testTheTimelineIsWhatPublicEntriesAnswers(): void {
		$public = [
			['id' => 'e1', 'kind' => 'beschikking-verzonden', 'message' => 'Beschikking verzonden'],
			['id' => 'e2', 'kind' => 'statuswijziging', 'message' => 'Status: In behandeling'],
		];

		$reader = $this->createMock(CaseTimeline::class);
		$reader->expects($this->once())
			->method('publicEntries')
			->with(caseId: 'case-1')
			->willReturn($public);

		$provider = new PortalContributionProvider($reader);

		$this->assertSame($public, $provider->caseTimeline('case-1'));
	}//end testTheTimelineIsWhatPublicEntriesAnswers()

	/**
	 * A read that threw costs the citizen the history, not the page.
	 *
	 * The reader deliberately rethrows so it never reports an emptiness it did
	 * not establish. This boundary is where that decision is made, because it
	 * is the edge of a foreign app rendering our contribution, and it is the
	 * only place that owns the consequence.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAReadThatThrewCostsTheHistoryAndNotThePage(): void {
		$reader = $this->createMock(CaseTimeline::class);
		$reader->method('publicEntries')->willThrowException(new RuntimeException('OpenRegister threw'));

		$this->assertSame([], (new PortalContributionProvider($reader))->caseTimeline('case-1'));
	}//end testAReadThatThrewCostsTheHistoryAndNotThePage()

	/**
	 * Without OpenRegister there is no reader, and the timeline is empty
	 * rather than fatal: portaliq builds this class with `new`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function testAProviderBuiltWithoutAReaderAnswersNoTimeline(): void {
		$this->assertSame([], (new PortalContributionProvider())->caseTimeline('case-1'));
	}//end testAProviderBuiltWithoutAReaderAnswersNoTimeline()

	public function testInspectorContributionShape(): void {
		$contribution = $this->provider->getContribution(['audience' => 'inspector']);
		$this->assertIsArray($contribution);
		$ids = array_column($contribution['collections'], 'id');
		$this->assertSame(['inspectieRapporten', 'checklistRuns'], $ids);
		foreach ($contribution['collections'] as $collection) {
			$this->assertSame('assignedInspectorRef', $collection['scopeField']);
		}
		// The run submit ships as an UPDATE on a run the inspector already
		// holds, which is what removes the write-IDOR rather than guarding it:
		// the client sends no `case` and no `template` at all, and the status
		// is the server's.
		$actions = $contribution['actions'];
		$this->assertSame(['submitChecklistRun'], array_column($actions, 'id'));
		$this->assertSame('update', $actions[0]['type']);
		$this->assertSame('assignedInspectorRef', $actions[0]['scopeField']);
		$this->assertNotContains('case', $actions[0]['fields']);
		$this->assertNotContains('template', $actions[0]['fields']);
		$this->assertSame(['status' => 'submitted'], $actions[0]['set']);
	}

	/**
	 * Register-drift pin: every scopeField + projected field declared by every
	 * collection AND action, across all three audiences, must exist as a
	 * property on its schema.
	 *
	 * @dataProvider audienceProvider
	 */
	public function testEveryDeclaredFieldExistsOnItsSchema(string $audience): void {
		$contribution = $this->provider->getContribution(['audience' => $audience]);
		$this->assertIsArray($contribution);

		$entries = array_merge(($contribution['collections'] ?? []), ($contribution['actions'] ?? []));
		$this->assertNotEmpty($entries);

		foreach ($entries as $entry) {
			// An endpoint action writes nothing through portaliq's flat writer;
			// the app behind the endpoint owns what it writes.
			if (isset($entry['endpoint']) === true) {
				continue;
			}

			$schemaSlug = $entry['schema'];
			$props = $this->propertiesFor($schemaSlug);

			$this->assertArrayHasKey(
				$entry['scopeField'],
				$props,
				"scopeField '{$entry['scopeField']}' must exist on schema '{$schemaSlug}' ({$audience}/{$entry['id']})"
			);

			foreach (($entry['fields'] ?? []) as $field) {
				$this->assertArrayHasKey(
					$field,
					$props,
					"projected field '{$field}' must exist on schema '{$schemaSlug}' ({$audience}/{$entry['id']})"
				);
			}
		}
	}

	/**
	 * portaliq#702: the inbox reads `body`, `receivedAt` and `read`, and a
	 * handler's letter arrived as a subject line only. The citizen inbox names
	 * its own fields and opts into its files.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-messages-name-their-inbox-fields/specs/portal-contribution/spec.md#requirement-req-portal-005-an-inbox-collection-must-name-the-fields-that-carry-its-message
	 */
	public function testTheInboxNamesItsMessageFields(): void {
		$collections = $this->provider->getContribution(['audience' => 'citizen'])['collections'];
		$berichten = array_column($collections, null, 'id')['berichten'];

		$this->assertSame(
			['body' => 'content', 'receivedAt' => 'sentAt', 'readAt' => 'readByRecipientAt', 'attachments' => 'attachments'],
			$berichten['messageFields']
		);
		$this->assertTrue($berichten['filesDownload']);

		$supplier = array_column($this->provider->getContribution(['audience' => 'supplier'])['collections'], null, 'id')['messages'];
		$this->assertSame(['receivedAt' => 'sentAt', 'readAt' => 'readByRecipientAt', 'attachments' => 'attachmentRefs'], $supplier['messageFields']);
	}

	/**
	 * Every inbox names its fields, each name is a property of its schema, and
	 * a projected collection projects it: portaliq projects before it maps, so
	 * a name left out of `fields` would arrive empty.
	 *
	 * @dataProvider audienceProvider
	 *
	 * @spec openspec/changes/portal-messages-name-their-inbox-fields/specs/portal-contribution/spec.md#requirement-req-portal-005-an-inbox-collection-must-name-the-fields-that-carry-its-message
	 */
	public function testEveryMessageFieldIsProjectedAndOnItsSchema(string $audience): void {
		$contribution = $this->provider->getContribution(['audience' => $audience]);
		foreach (($contribution['collections'] ?? []) as $collection) {
			if (($collection['kind'] ?? '') !== 'inbox') {
				continue;
			}

			$this->assertNotEmpty($collection['messageFields'] ?? [], "{$audience}/{$collection['id']} names its message fields");
			$props = $this->propertiesFor($collection['schema']);
			foreach ($collection['messageFields'] as $key => $field) {
				$this->assertArrayHasKey($field, $props, "{$audience}/{$collection['id']}: {$key} -> '{$field}' is on '{$collection['schema']}'");
				if (isset($collection['fields']) === true) {
					$this->assertContains($field, $collection['fields'], "{$audience}/{$collection['id']}: '{$field}' survives the projection");
				}
			}
		}
	}

	/**
	 * @return array<int, array<int, string>>
	 */
	public static function audienceProvider(): array {
		return [['supplier'], ['citizen'], ['client'], ['inspector']];
	}

	/**
	 * The fields of the citizen case list that are uuids, and say nothing to a person.
	 *
	 * @var array<int, string>
	 */
	private const UUID_FIELDS = ['caseType', 'status', 'result'];

	/**
	 * The column render kinds portaliq's contract accepts (IPortalContributionProvider,
	 * CollectionConfigNormaliser::RENDER_KINDS at portaliq development).
	 *
	 * @var array<int, string>
	 */
	private const RENDER_KINDS = ['text', 'date', 'datetime', 'badge', 'currency', 'boolean', 'link'];

	/**
	 * The citizen case collection.
	 *
	 * @return array<string, mixed>
	 */
	private function mijnZaken(): array {
		$contribution = $this->provider->getContribution(['audience' => 'citizen']);
		$cases = $contribution['collections'][0];
		$this->assertSame('mijnZaken', $cases['id']);

		return $cases;
	}

	/**
	 * The resident reads the outcome in words, not as the result's uuid (dossiq#3143).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheCaseListCarriesTheOutcomeInWordsAndNotItsUuid(): void {
		$fields = $this->mijnZaken()['fields'];

		$this->assertNotContains('result', $fields, 'the result uuid says nothing to a resident');
		$this->assertContains('resultPublicLabel', $fields);
		$this->assertContains('resultPublicDescription', $fields);
	}

	/**
	 * The case list declares labelled, typed columns and shows no uuid.
	 *
	 * Without columns portaliq falls back to every projected field as plain
	 * text under its key, which is how a resident came to read
	 * `receivedOutsideWorkingHours: true` and a status uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheCaseListDeclaresLabelledColumnsWithoutUuids(): void {
		$cases = $this->mijnZaken();
		$this->assertArrayHasKey('columns', $cases);
		$this->assertNotEmpty($cases['columns']);

		$byField = [];
		foreach ($cases['columns'] as $column) {
			$this->assertContains($column['field'], $cases['fields'], $column['field'] . ' is a column but not a projected field');
			$this->assertNotContains($column['field'], self::UUID_FIELDS, $column['field'] . ' is a uuid and must not be a column');
			$this->assertNotSame('', trim((string)($column['label'] ?? '')), $column['field'] . ' has no label');
			$this->assertNotSame($column['field'], $column['label'], $column['field'] . ' is labelled with its own key');
			$this->assertContains($column['render'] ?? '', self::RENDER_KINDS);
			$byField[$column['field']] = $column['render'];
		}

		$this->assertSame(
			[
				'identifier' => 'text',
				'title' => 'text',
				'statusPublicLabel' => 'badge',
				'resultPublicLabel' => 'text',
				'startDate' => 'date',
				'deadline' => 'date',
				'termStartsAt' => 'date',
				'receivedOutsideWorkingHours' => 'boolean',
			],
			$byField
		);
	}

	/**
	 * The case detail lists the readable fields, and no uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheCaseDetailListsTheReadableFields(): void {
		$cases = $this->mijnZaken();
		$this->assertArrayHasKey('detail', $cases);
		$this->assertSame('card', $cases['detail']['layout']);
		$this->assertNotEmpty($cases['detail']['fields']);

		foreach ($cases['detail']['fields'] as $field) {
			$this->assertContains($field, $cases['fields'], $field . ' is a detail field but not a projected field');
			$this->assertNotContains($field, self::UUID_FIELDS, $field . ' is a uuid and must not be shown');
		}

		$this->assertContains('resultPublicLabel', $cases['detail']['fields']);
		$this->assertContains('resultPublicDescription', $cases['detail']['fields']);
	}

	/**
	 * Every field on the case detail reads under a Dutch label, never under its key as words.
	 *
	 * Portaliq labels a detail field by its column, then by the collection's
	 * `fieldConfigs.<field>.label`, then by the key as words, which is how a
	 * resident read "Status public description" and "End date".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testEveryCaseDetailFieldHasADutchLabel(): void {
		$cases = $this->mijnZaken();

		$labels = [];
		foreach ($cases['fieldConfigs'] as $field => $config) {
			$this->assertContains(needle: $field, haystack: $cases['fields'], message: $field . ' has a label but is not projected');
			$labels[$field] = $config['label'];
		}

		foreach ($cases['columns'] as $column) {
			$labels[$column['field']] = $column['label'];
		}

		foreach ($cases['detail']['fields'] as $field) {
			$this->assertArrayHasKey(key: $field, array: $labels, message: $field . ' would read under its key as words');
			$this->assertNotSame(expected: '', actual: trim($labels[$field]), message: $field . ' has an empty label');
		}

		$this->assertSame(expected: 'Toelichting op de status', actual: $labels['statusPublicDescription']);
		$this->assertSame(expected: 'Toelichting op de uitkomst', actual: $labels['resultPublicDescription']);
		$this->assertSame(expected: 'Einddatum', actual: $labels['endDate']);
	}

	/**
	 * The two result fields are calculated the way the status label is.
	 *
	 * Read from the raw register, because the calculation is what fills them:
	 * a property with no calculation behind it would be projected and always
	 * empty.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function testTheResultFieldsAreCalculatedFromTheResultRecord(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);
		$case = $register['components']['schemas']['case'];

		$this->assertSame(
			['schema' => 'result', 'mode' => 'relatedObject', 'field' => 'result'],
			$case['configuration']['x-openregister-references']['result']
		);

		$calculations = $case['configuration']['x-openregister-calculations'];
		$this->assertSame(['prop' => '@ref.result.name'], $calculations['resultPublicLabel']['expression']);
		$this->assertSame(['prop' => '@ref.result.publicExplanation'], $calculations['resultPublicDescription']['expression']);
		$this->assertTrue($calculations['resultPublicLabel']['materialise']);
		$this->assertTrue($calculations['resultPublicDescription']['materialise']);

		$this->assertArrayHasKey('publicExplanation', $register['components']['schemas']['result']['properties']);
		$this->assertTrue($case['properties']['resultPublicLabel']['readOnly']);
		$this->assertTrue($case['properties']['resultPublicDescription']['readOnly']);
	}

	/**
	 * Read the schema definitions from one register JSON file, keyed by slug.
	 *
	 * @param string $path The register/fragment JSON path.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemasFrom(string $path): array {
		$decoded = json_decode((string)file_get_contents($path), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$schemas = ($decoded['components']['schemas'] ?? []);
		return (is_array($schemas) === true ? $schemas : []);
	}

	/**
	 * Resolve a schema's declared properties by slug.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function propertiesFor(string $slug): array {
		$this->assertArrayHasKey($slug, $this->schemas, "schema '{$slug}' must exist in dossiq_register.json");
		$props = ($this->schemas[$slug]['properties'] ?? []);
		$this->assertNotEmpty($props, "schema '{$slug}' must declare properties");
		return $props;
	}
}
