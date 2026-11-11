<?php

/**
 * PortalCaseDocuments golden.
 *
 * Pins, entry for entry, which documents of a case reach the resident and
 * which are held back, and the exact entry each one becomes: the decision
 * date over the creation date, the optional mimeType and size, and the
 * guards on the decision, link and join rows that are never trusted.
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
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Portal;

use OCA\Dossiq\Portal\PortalCaseDocuments;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The resident's documents of a case, allowed and refused, as exact entries.
 *
 * @covers \OCA\Dossiq\Portal\PortalCaseDocuments
 * @covers \OCA\Dossiq\Portal\PortalCaseReads
 * @covers \OCA\Dossiq\Portal\PortalCaseRead
 * @covers \OCA\Dossiq\Portal\PortalDocumentsRun
 * @uses \OCA\Dossiq\Service\Support\UnscopedObjectReader
 * @uses \OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue
 */
class PortalCaseDocumentsGoldenTest extends TestCase {
	private const CASE_ID = 'case-1';

	/**
	 * Informatieobjecten by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $documents = [];

	/**
	 * Rows per schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Configuration values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Every search as [schema, filters].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $searches = [];

	/**
	 * Every find as [id, schema].
	 *
	 * @var array<int, array{0: string, 1: mixed}>
	 */
	private array $finds = [];

	/**
	 * Thrown by every search when set.
	 *
	 * @var RuntimeException|null
	 */
	private ?RuntimeException $failure = null;

	/**
	 * A case with documents on both sides of every rule.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$base = ['status' => 'final', 'vertrouwelijkheidaanduiding' => 'openbaar', 'direction' => 'outgoing', 'creatiedatum' => '2026-05-01'];
		$this->documents = [
			'd-out' => $base + ['title' => ' Brief ', 'fileId' => 7, 'format' => 'application/pdf', 'bestandsomvang' => 10],
			'd-plain' => $base + ['title' => 'Kaal', 'fileId' => '8', 'format' => '', 'bestandsomvang' => '12'],
			'd-dec' => ['direction' => 'incoming', 'vertrouwelijkheidaanduiding' => 'beperkt_openbaar'] + $base + ['title' => 'Besluit', 'fileId' => 9],
			'd-dec-nodate' => ['direction' => 'internal'] + $base + ['title' => 'Besluit zonder datum', 'fileId' => 10],
			'd-archived' => ['status' => 'archived', 'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk'] + $base + ['title' => 'Archief', 'fileId' => 11],
			'd-incoming' => ['direction' => 'incoming'] + $base + ['title' => 'Inkomend', 'fileId' => 12],
			'd-draft' => ['status' => 'draft'] + $base + ['title' => 'Concept', 'fileId' => 13],
			'd-secret' => ['vertrouwelijkheidaanduiding' => 'geheim'] + $base + ['title' => 'Geheim', 'fileId' => 14],
			'd-excepted' => $base + ['title' => 'Uitzondering', 'fileId' => 15, 'accessException' => ['users' => ['anna']]],
			'd-empty-exception' => $base + ['title' => 'Lege uitzondering', 'fileId' => 16, 'accessException' => ['users' => [' ']]],
			'd-notitle' => $base + ['title' => '   ', 'fileId' => 17],
			'd-nofile' => $base + ['title' => 'Geen bestand', 'fileId' => 0],
			'd-nodirection' => ['status' => 'final', 'vertrouwelijkheidaanduiding' => 'openbaar', 'title' => 'Geen richting', 'fileId' => 18],
		];
		$joins = [];
		foreach (array_keys($this->documents) as $id) {
			$joins[] = ['case' => self::CASE_ID, 'informatieobject' => $id];
		}

		$joins[] = ['case' => self::CASE_ID, 'informatieobject' => 'd-gone'];
		$joins[] = ['case' => self::CASE_ID, 'informatieobject' => ''];
		$joins[] = ['case' => 'other', 'informatieobject' => 'd-incoming'];
		$joins[] = ['case' => self::CASE_ID, 'informatieobject' => 'd-out'];
		$this->rows = [
			'join' => $joins,
			'decision' => [
				['id' => 'b-1', 'case' => self::CASE_ID, 'decisionDate' => '2026-06-02'],
				['@self' => ['id' => 'b-2'], 'case' => self::CASE_ID],
				['id' => 'b-3', 'case' => 'other', 'decisionDate' => '2026-06-03'],
				['case' => self::CASE_ID, 'decisionDate' => '2026-06-04'],
			],
			'link' => [
				['decision' => 'b-1', 'document' => 'https://x.test/api/documents/d-dec/'],
				['decision' => 'b-2', 'document' => 'https://x.test/api/documents/d-dec-nodate'],
				['decision' => 'b-9', 'document' => 'https://x.test/api/documents/d-incoming'],
				['decision' => 'b-1', 'document' => ''],
			],
		];
		$this->config = [
			'register' => 'reg',
			'case_schema' => 'case',
			'decision_schema' => 'decision',
			'decision_document_schema' => 'link',
			'dossier_informatieobject_schema' => 'info',
			'dossier_zaakinformatieobject_schema' => 'join',
		];
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param LoggerInterface|null $logger The logger, a mock by default.
	 *
	 * @return PortalCaseDocuments
	 */
	private function service(?LoggerInterface $logger = null): PortalCaseDocuments {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(fn (callable $operation) => $operation());
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $register, string $schema, array $filters) {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->searches[] = [$schema, $filters];

				return $this->rows[$schema] ?? [];
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, $_extend = [], $files = false, $register = null, $schema = null) {
				$this->finds[] = [$id, $schema];
				if (isset($this->documents[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($this->documents[$id]);
				return $entity;
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));

		return new PortalCaseDocuments($settings, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * The entries, exactly, in join order.
	 *
	 * @return void
	 */
	public function testTheResidentSeesExactlyTheseEntries(): void {
		$file = fn (string $fileId): array => ['register' => 'reg', 'schema' => 'case', 'id' => self::CASE_ID, 'fileId' => $fileId];

		$this->assertSame(
			[
				[
					'id' => 'd-out',
					'title' => 'Brief',
					'kind' => 'document',
					'date' => '2026-05-01',
					'file' => $file('7'),
					'mimeType' => 'application/pdf',
					'size' => 10,
				],
				['id' => 'd-plain', 'title' => 'Kaal', 'kind' => 'document', 'date' => '2026-05-01', 'file' => $file('8')],
				['id' => 'd-dec', 'title' => 'Besluit', 'kind' => 'decision', 'date' => '2026-06-02', 'file' => $file('9')],
				['id' => 'd-dec-nodate', 'title' => 'Besluit zonder datum', 'kind' => 'decision', 'date' => '2026-05-01', 'file' => $file('10')],
				['id' => 'd-archived', 'title' => 'Archief', 'kind' => 'document', 'date' => '2026-05-01', 'file' => $file('11')],
				['id' => 'd-empty-exception', 'title' => 'Lege uitzondering', 'kind' => 'document', 'date' => '2026-05-01', 'file' => $file('16')],
			],
			$this->service()->forCase(self::CASE_ID)
		);
	}//end testTheResidentSeesExactlyTheseEntries()

	/**
	 * The reads: decisions, their links, then the joins, then one find per trusted join.
	 *
	 * @return void
	 */
	public function testTheReadsAndTheirFilters(): void {
		$this->service()->forCase(self::CASE_ID);

		$this->assertSame(
			[
				['decision', ['case' => self::CASE_ID, '_limit' => 100]],
				['link', ['decision' => 'b-1', '_limit' => 100]],
				['link', ['decision' => 'b-2', '_limit' => 100]],
				['join', ['case' => self::CASE_ID, '_limit' => 500]],
			],
			$this->searches
		);
		$found = array_column($this->finds, 0);
		$this->assertSame(array_merge(array_keys($this->documents), ['d-gone', 'd-out']), $found);
		$this->assertSame(['info'], array_values(array_unique(array_column($this->finds, 1))));
	}//end testTheReadsAndTheirFilters()

	/**
	 * Without the decision schemas there are no decisions, only documents.
	 *
	 * @return void
	 */
	public function testWithoutDecisionSchemasNothingIsADecision(): void {
		$this->config['decision_document_schema'] = '';

		$entries = $this->service()->forCase(self::CASE_ID);

		$this->assertSame(['d-out', 'd-plain', 'd-archived', 'd-empty-exception'], array_column($entries, 'id'));
		$this->assertNotContains('decision', array_column($this->searches, 0));
	}//end testWithoutDecisionSchemasNothingIsADecision()

	/**
	 * Without any of the three document schemas nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutADocumentSchemaNothingIsRead(): void {
		foreach (['case_schema', 'dossier_informatieobject_schema', 'dossier_zaakinformatieobject_schema'] as $key) {
			$this->setUp();
			$this->config[$key] = '';

			$this->assertSame([], $this->service()->forCase(self::CASE_ID), $key);
			$this->assertSame([], $this->searches, $key);
		}
	}//end testWithoutADocumentSchemaNothingIsRead()

	/**
	 * Without a register nothing is read.
	 *
	 * @return void
	 */
	public function testWithoutARegisterNothingIsRead(): void {
		$this->config['register'] = '';

		$this->assertSame([], $this->service()->forCase(self::CASE_ID));
		$this->assertSame([], $this->searches);
	}//end testWithoutARegisterNothingIsRead()

	/**
	 * A failing read is logged and answers no documents.
	 *
	 * @return void
	 */
	public function testAFailingReadIsLoggedAndAnswersNothing(): void {
		$this->failure = new RuntimeException('down');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: the portal documents of a case could not be read',
			['reason' => 'down']
		);

		$this->assertSame([], $this->service($logger)->forCase(self::CASE_ID));
	}//end testAFailingReadIsLoggedAndAnswersNothing()

	/**
	 * The publication rule on its own.
	 *
	 * @return void
	 */
	public function testIsPublished(): void {
		$service = $this->service();

		$this->assertTrue($service->isPublished(['status' => 'final', 'vertrouwelijkheidaanduiding' => 'openbaar', 'direction' => 'outgoing'], false));
		$this->assertTrue($service->isPublished(['status' => 'final', 'vertrouwelijkheidaanduiding' => 'openbaar'], true));
		$this->assertFalse($service->isPublished(['status' => 'final', 'vertrouwelijkheidaanduiding' => 'openbaar'], false));
		$this->assertFalse($service->isPublished(['status' => 'draft', 'vertrouwelijkheidaanduiding' => 'openbaar'], true));
		$this->assertFalse($service->isPublished(['status' => 'final', 'vertrouwelijkheidaanduiding' => 'vertrouwelijk'], true));
		$this->assertFalse(
			$service->isPublished(
				['status' => 'final', 'vertrouwelijkheidaanduiding' => 'openbaar', 'accessException' => ['groups' => ['g']]],
				true
			)
		);
	}//end testIsPublished()
}//end class
