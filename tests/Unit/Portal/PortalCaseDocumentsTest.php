<?php

/**
 * Portal case documents test
 *
 * dossiq#3205: portaliq lists the documents a case app publishes on a
 * resident's case, and asks the app through the method its `documents`
 * declaration names. The entry shape asserted here is the one portaliq keeps,
 * read at portaliq development 4176916 (#923):
 * `lib/Service/PortalCaseDocumentReader.php::wellFormed()` drops an entry
 * without `id`, `title` or a `file` with `register`, `schema`, `id` and
 * `fileId`, reads `kind: decision` as the decision and anything else as a
 * document, and keeps `mimeType` (string) and `size` (int).
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
 * @spec openspec/changes/portal-case-documents/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalCaseDocuments;
use OCA\Dossiq\Portal\PortalContributionProvider;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Portal\PortalCaseDocuments
 * @covers \OCA\Dossiq\Portal\PortalContributionProvider
 * @uses   \OCA\Dossiq\Portal\PortalPages
 * @uses   \OCA\Dossiq\Portal\CitizenManifest
 */
class PortalCaseDocumentsTest extends TestCase {
	private const CASE_ID = '11111111-1111-4111-8111-111111111111';

	/**
	 * Informatieobjecten by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $documents = [];

	/**
	 * Rows per schema, answered by searchObjectsBySlug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Whether the reads ran inside runAsSystem.
	 *
	 * @var bool
	 */
	private bool $asSystem = false;

	/**
	 * A case with a decision letter, an outgoing letter, a draft, an internal
	 * note, a neighbour's incoming letter and a letter on another case.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$base = ['status' => 'final', 'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk', 'format' => 'application/pdf', 'bestandsomvang' => 2048];
		$this->documents = [
			'doc-decision' => $base + ['title' => 'Besluit omgevingsvergunning', 'fileId' => 101, 'direction' => 'outgoing', 'creatiedatum' => '2026-09-01'],
			'doc-letter' => $base + ['title' => 'Ontvangstbevestiging', 'fileId' => 102, 'direction' => 'outgoing', 'creatiedatum' => '2026-08-20'],
			'doc-draft' => ['status' => 'draft'] + $base + ['title' => 'Concept besluit', 'fileId' => 103, 'direction' => 'outgoing'],
			'doc-internal' => ['vertrouwelijkheidaanduiding' => 'intern'] + $base + ['title' => 'Werkaantekening', 'fileId' => 104, 'direction' => 'internal'],
			'doc-neighbour' => $base + ['title' => 'Zienswijze buren', 'fileId' => 105, 'direction' => 'incoming'],
			'doc-nofile' => $base + ['title' => 'Zonder bestand', 'direction' => 'outgoing'],
			// An archived letter was final first; it stays readable once the case is closed.
			'doc-archived' => ['status' => 'archived'] + $base + ['title' => 'Verzonden brief', 'fileId' => 106, 'direction' => 'outgoing', 'creatiedatum' => '2026-07-01'],
		];
		$this->rows = [
			'zaakinformatieobject' => [
				['case' => self::CASE_ID, 'informatieobject' => 'doc-letter'],
				['case' => self::CASE_ID, 'informatieobject' => 'doc-decision'],
				['case' => self::CASE_ID, 'informatieobject' => 'doc-draft'],
				['case' => self::CASE_ID, 'informatieobject' => 'doc-internal'],
				['case' => self::CASE_ID, 'informatieobject' => 'doc-neighbour'],
				['case' => self::CASE_ID, 'informatieobject' => 'doc-nofile'],
				['case' => self::CASE_ID, 'informatieobject' => 'doc-archived'],
				// A row the search should not have answered: never trusted.
				['case' => 'another-case', 'informatieobject' => 'doc-letter'],
			],
			'decision' => [['id' => 'decision-1', 'case' => self::CASE_ID, 'decisionDate' => '2026-09-02']],
			'decisionDocument' => [['decision' => 'decision-1', 'document' => 'https://example.test/api/documents/doc-decision']],
		];
	}

	/**
	 * The service under test, over a real-contract object service double.
	 *
	 * @return PortalCaseDocuments
	 */
	protected function service(): PortalCaseDocuments {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(function (callable $operation) {
			$this->asSystem = true;
			$result = $operation();
			$this->asSystem = false;
			return $result;
		});
		$objects->method('searchObjectsBySlug')->willReturnCallback(function (string $register, string $schema, array $filters) {
			$this->assertTrue($this->asSystem, 'the portal read runs as the system');
			$this->assertSame('dossiq', $register);
			return $this->rows[$schema] ?? [];
		});
		$objects->method('find')->willReturnCallback(function ($id) {
			if (isset($this->documents[$id]) === false) {
				return null;
			}

			$entity = $this->createMock(ObjectEntityInterface::class);
			$entity->method('jsonSerialize')->willReturn($this->documents[$id]);
			return $entity;
		});

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => [
			'register' => 'dossiq',
			'case_schema' => 'case',
			'decision_schema' => 'decision',
			'decision_document_schema' => 'decisionDocument',
			'dossier_informatieobject_schema' => 'informatieobject',
			'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
		][$key] ?? '');

		return new PortalCaseDocuments($settings, new NullLogger());
	}

	/**
	 * Only final, readable, outgoing documents and the decision reach the resident.
	 *
	 * @return void
	 */
	public function testTheResidentSeesTheDecisionAndWhatWasSentToThem(): void {
		$entries = $this->service()->forCase(self::CASE_ID);
		$byId = array_column($entries, null, 'id');

		$this->assertSame(['doc-letter', 'doc-decision', 'doc-archived'], array_column($entries, 'id'));
		$this->assertSame('decision', $byId['doc-decision']['kind']);
		$this->assertSame('2026-09-02', $byId['doc-decision']['date']);
		$this->assertSame('document', $byId['doc-letter']['kind']);
		$this->assertSame('2026-08-20', $byId['doc-letter']['date']);
	}

	/**
	 * Every entry has the shape portaliq keeps, and the file is the case's folder.
	 *
	 * @return void
	 */
	public function testEveryEntryIsWellFormedForPortaliq(): void {
		foreach ($this->service()->forCase(self::CASE_ID) as $entry) {
			$this->assertNotSame('', $entry['id']);
			$this->assertNotSame('', $entry['title']);
			$this->assertContains($entry['kind'], ['decision', 'document']);
			$this->assertSame(['register', 'schema', 'id', 'fileId'], array_keys($entry['file']));
			$this->assertSame('dossiq', $entry['file']['register']);
			$this->assertSame('case', $entry['file']['schema']);
			$this->assertSame(self::CASE_ID, $entry['file']['id']);
			$this->assertIsString($entry['file']['fileId']);
			$this->assertSame('application/pdf', $entry['mimeType']);
			$this->assertSame(2048, $entry['size']);
		}
	}

	/**
	 * An empty id or an unavailable OpenRegister answers nothing, not an error.
	 *
	 * @return void
	 */
	public function testNothingIsAnsweredWithoutACaseOrOpenRegister(): void {
		$this->assertSame([], $this->service()->forCase(''));

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$this->assertSame([], (new PortalCaseDocuments($settings, new NullLogger()))->forCase(self::CASE_ID));
	}

	/**
	 * The case collection declares the method, and the provider answers through the service.
	 *
	 * @return void
	 */
	public function testTheCaseCollectionDeclaresTheDocumentsMethod(): void {
		$provider = new PortalContributionProvider(documents: $this->service());
		$cases = $provider->getContribution(['audience' => 'client'])['collections'][0];
		$this->assertSame('mijnZaken', $cases['id']);
		$this->assertSame(['label' => 'Documenten', 'provider' => 'caseDocuments'], $cases['documents']);
		$this->assertTrue(method_exists($provider, 'caseDocuments'));
		$this->assertSame(['doc-letter', 'doc-decision', 'doc-archived'], array_column($provider->caseDocuments(self::CASE_ID), 'id'));
		$this->assertSame([], (new PortalContributionProvider())->caseDocuments(self::CASE_ID));
	}
}
