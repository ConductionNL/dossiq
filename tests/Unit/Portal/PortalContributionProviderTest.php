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
		$this->assertSame(['mijnZaken', 'berichten', 'verzoeken'], $ids);

		// Three creates and the one update a resident makes on their own
		// case (dossiq#3152). The two creates that name a case were deferred until Portaliq
		// could check a reference against the sender's own scope; they are
		// asserted to carry that check below, not merely to exist.
		$actionIds = array_column($contribution['actions'], 'id');
		$this->assertSame(['createKlacht', 'createBezwaar', 'replyToMessage', 'amendCase', 'startWooVerzoek'], $actionIds);
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
		$this->assertSame(['collectionId', 'onderwerp', 'omschrijving', 'periodeVan', 'periodeTot'], $action['fields']);
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

		$this->assertSame(['mijnZaken', 'berichten', 'verzoeken'], array_column($contribution['pages'], 'id'));
		$this->assertSame(
			['Voortgang van uw zaken', 'Een bericht beantwoorden', 'Mijn verzoeken'],
			array_column($contribution['pages'], 'label')
		);
		foreach ($contribution['pages'] as $page) {
			$this->assertSame('Mijn zaken en verzoeken', $page['group']);
			$this->assertNotContains($page['label'], ['Mijn zaken', 'Berichten']);
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

		$this->assertSame(
			[['type' => 'collection', 'collection' => 'mijnZaken'], ['type' => 'detail', 'collection' => 'mijnZaken']],
			$pages[0]['blocks']
		);
		$this->assertSame(['type' => 'action', 'action' => 'replyToMessage'], $pages[1]['blocks'][0]);
		$this->assertSame(['type' => 'action', 'action' => 'createKlacht'], $pages[2]['blocks'][0]);
	}//end testACaseStillOpensOnItsPage()

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

		$this->assertCount(count($collections), $contribution['pages']);
		foreach ($contribution['pages'] as $page) {
			$this->assertNotSame('', $page['group']);
			$this->assertNotSame('', $page['label']);
			foreach ($page['blocks'] as $block) {
				if ($block['type'] === 'action') {
					$this->assertContains($block['action'], $actions);
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

		$this->assertSame(['createBezwaar', 'replyToMessage'], array_keys($guarded));
		foreach ($guarded as $id => $declaration) {
			$this->assertIsArray($declaration, $id . ' names a case without guarding it');
			$this->assertSame('case', $declaration['schema'], $id);
			$this->assertSame('portalSubject', $declaration['scopeField'], $id);
			$this->assertTrue($declaration['required'], $id);
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
