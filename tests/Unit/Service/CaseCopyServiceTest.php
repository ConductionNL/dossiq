<?php

/**
 * CaseCopyService Unit Tests.
 *
 * The fields a copy must NOT carry are the point of the service, so they are
 * what this asserts hardest: a case number, a deadline, a result, a status
 * history, decisions and publications belong to the case they were recorded
 * on. A copy that inherited them would be a second case claiming another
 * case's legal facts, and nothing downstream would notice.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseCopyService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for CaseCopyService.
 *
 * @covers \OCA\Dossiq\Service\CaseCopyService
 */
class CaseCopyServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * Everything the fake object service was asked to write.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->writes = [];
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'dossiq',
					'case_schema' => 'case',
					'case_document_schema' => 'caseDocument',
					'case_type_schema' => 'caseType',
					default => $default,
				};
			}
		);
	}//end setUp()

	/**
	 * A source case with every field a copy must and must not carry.
	 *
	 * @return array<string, mixed> The source case.
	 */
	private function sourceCase(): array {
		return [
			'id' => 'case-1',
			'title' => 'Dakkapel Kerkstraat 12',
			'description' => 'Aanvraag voor een dakkapel.',
			'caseType' => 'type-1',
			'requester' => 'person-1',
			'initiatorType' => 'person',
			'initiatorSourceId' => '999999011',
			'initiatorDisplayName' => 'J. Jansen',
			'confidentiality' => 'openbaar',
			'priority' => 'high',
			'intakeChannel' => 'website',
			'properties' => [['propertyDefinition' => 'pd-1', 'name' => 'Oppervlakte', 'value' => '12']],
			'status' => 'status-received',
			'identifier' => '2026-0042',
			'deadline' => '2026-10-01',
			'endDate' => '2026-09-01',
			'result' => 'result-granted',
			'statusHistory' => '[{"status":"status-received"}]',
			'decisions' => ['decision-1'],
			'publications' => ['publication-1'],
			'publishedAt' => '2026-08-01',
		];
	}//end sourceCase()

	/**
	 * Build the service over an in-memory object service.
	 *
	 * @param array<string, array<string, mixed>> $store Objects addressable by id.
	 * @param array<int, array<string, mixed>> $documents caseDocument rows findAll() answers.
	 *
	 * @return CaseCopyService The service under test.
	 */
	private function makeService(array $store, array $documents = []): CaseCopyService {
		$writes = &$this->writes;

		$objectService = new class($store, $documents, $writes) {
			/**
			 * @param array<string, array<string, mixed>> $store Objects by id.
			 * @param array<int, array<string, mixed>> $documents The caseDocument rows.
			 * @param array<int, array{schema: string, object: array<string, mixed>}> $writes Write log.
			 */
			public function __construct(
				private array $store,
				private array $documents,
				private array &$writes,
			) {
			}//end __construct()

			/**
			 * Mimic OpenRegister's find(): null when the id is unknown.
			 *
			 * @param string $id The object id.
			 * @param mixed $register The register (ignored).
			 * @param mixed $schema The schema (ignored).
			 *
			 * @return array<string, mixed>|null The object.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}//end find()

			/**
			 * Mimic OpenRegister's findAll() for the caseDocument read.
			 *
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $query): array {
				return $this->documents;
			}//end findAll()

			/**
			 * Mimic OpenRegister's saveObject(): record and mint an id.
			 *
			 * @param array<string, mixed> $object The object to write.
			 * @param mixed $register The register (ignored).
			 * @param mixed $schema The schema.
			 *
			 * @return array<string, mixed> The written object.
			 */
			public function saveObject(array $object, $register = null, $schema = null): array {
				$object['id'] = 'new-' . (count($this->writes) + 1);
				$this->writes[] = ['schema' => (string)$schema, 'object' => $object];

				return $object;
			}//end saveObject()
		};

		$this->settingsService->method('getObjectService')->willReturn($objectService);

		return new CaseCopyService($this->settingsService, $this->createMock(LoggerInterface::class));
	}//end makeService()

	/**
	 * The copy carries the type, the requester and the properties.
	 *
	 * @return void
	 */
	public function testCopyCarriesTypeRequesterAndProperties(): void {
		$service = $this->makeService(['case-1' => $this->sourceCase()]);

		$service->copy('case-1', ['title' => 'Copy of Dakkapel Kerkstraat 12']);

		$written = $this->writes[0]['object'];
		$this->assertSame('type-1', $written['caseType']);
		$this->assertSame('person-1', $written['requester']);
		$this->assertSame('openbaar', $written['confidentiality']);
		$this->assertSame('high', $written['priority']);
		$this->assertSame('website', $written['intakeChannel']);
		$this->assertSame('J. Jansen', $written['initiatorDisplayName']);
		$this->assertSame(
			[['propertyDefinition' => 'pd-1', 'name' => 'Oppervlakte', 'value' => '12']],
			$written['properties']
		);
		$this->assertSame('Copy of Dakkapel Kerkstraat 12', $written['title']);
	}//end testCopyCarriesTypeRequesterAndProperties()

	/**
	 * Every banned field is absent from the write, one assertion per field so
	 * a regression names which one came back.
	 *
	 * @return void
	 */
	public function testCopyNeverCarriesTheSourcesLegalFacts(): void {
		$service = $this->makeService(['case-1' => $this->sourceCase()]);

		$service->copy('case-1', []);

		$written = $this->writes[0]['object'];
		foreach (CaseCopyService::NEVER_COPIED as $field) {
			$this->assertArrayNotHasKey($field, $written, sprintf('"%s" must not be copied', $field));
		}
	}//end testCopyNeverCarriesTheSourcesLegalFacts()

	/**
	 * The two lists stay disjoint as the carried list grows.
	 *
	 * The ban is a rule about the CONSTANTS, not a filter run at copy time: an
	 * allow-list and a deny-list that are provably disjoint make any runtime
	 * guard between them dead code. So the rule is asserted here, where adding
	 * a banned field to `CARRIED` fails loudly.
	 *
	 * @return void
	 */
	public function testTheCarriedAndBannedListsAreDisjoint(): void {
		$reflection = new \ReflectionClass(CaseCopyService::class);
		$carried = $reflection->getConstant('CARRIED');

		$this->assertSame(
			[],
			array_values(array_intersect($carried, CaseCopyService::NEVER_COPIED)),
			'a field cannot be both carried and banned'
		);
	}//end testTheCarriedAndBannedListsAreDisjoint()

	/**
	 * The copy opens at its type's initial status, and NOT at the source's.
	 *
	 * This test used to assert the opposite — that `status` was absent from
	 * the write — on the grounds that `case.caseType` carries
	 * `x-openregister-prefill` and that the prefill was the one write path.
	 * It is not: that block fills a FORM when a picker resolves, and does not
	 * run on a write. Measured against a running register, an API create
	 * naming a type whose `initialStatus` is set and passing no status stores
	 * `status: null`.
	 *
	 * So the old assertion described the implementation rather than the
	 * outcome, and passed for as long as every copy landed with no status at
	 * all — off every status-filtered lens, with no available transitions.
	 *
	 * @return void
	 */
	public function testTheCopyOpensAtItsTypesInitialStatus(): void {
		$service = $this->makeService(
			[
				'case-1' => $this->sourceCase(),
				'type-1' => ['id' => 'type-1', 'initialStatus' => 'status-intake'],
			]
		);

		$service->copy('case-1', []);

		$written = $this->writes[0]['object'];
		$this->assertSame('status-intake', ($written['status'] ?? null));
		// And NOT where the source had got to: a copy starts at the beginning.
		$this->assertNotSame('status-received', ($written['status'] ?? null));
	}//end testTheCopyOpensAtItsTypesInitialStatus()

	/**
	 * A type with no initial status still yields a copy, without a status.
	 *
	 * Fail soft rather than refuse: that is the state a case of such a type
	 * would be in however it was created, so a copy is no worse off than an
	 * original.
	 *
	 * @return void
	 */
	public function testACopyIsStillWrittenWhenTheTypeHasNoInitialStatus(): void {
		$service = $this->makeService(
			[
				'case-1' => $this->sourceCase(),
				'type-1' => ['id' => 'type-1'],
			]
		);

		$service->copy('case-1', []);

		$this->assertArrayNotHasKey('status', $this->writes[0]['object']);
	}//end testACopyIsStillWrittenWhenTheTypeHasNoInitialStatus()

	/**
	 * A case type that does not resolve at all is the same soft failure.
	 *
	 * @return void
	 */
	public function testACopyIsStillWrittenWhenTheTypeDoesNotResolve(): void {
		// No `type-1` in the store: `fetch()` answers null.
		$service = $this->makeService(['case-1' => $this->sourceCase()]);

		$service->copy('case-1', []);

		$this->assertArrayNotHasKey('status', $this->writes[0]['object']);
	}//end testACopyIsStillWrittenWhenTheTypeDoesNotResolve()

	/**
	 * The source is listed under the copy's related cases, in the encoded
	 * shape the schema declares.
	 *
	 * @return void
	 */
	public function testCopyRelatesBackToTheSource(): void {
		$service = $this->makeService(['case-1' => $this->sourceCase()]);

		$service->copy('case-1', []);

		$related = json_decode((string)$this->writes[0]['object']['relatedCases'], true);
		$this->assertIsArray($related);
		$this->assertSame('case-1', $related[0]['caseId']);
		$this->assertSame('vervolg', $related[0]['aardRelatie']);
	}//end testCopyRelatesBackToTheSource()

	/**
	 * Without the option, no document link is written at all.
	 *
	 * @return void
	 */
	public function testCopyWithoutDocumentsWritesOnlyTheCase(): void {
		$service = $this->makeService(
			['case-1' => $this->sourceCase()],
			[['id' => 'link-1', 'case' => 'case-1', 'document' => 'nc://file/1', 'title' => 'Bouwtekening']]
		);

		$service->copy('case-1', ['documents' => false]);

		$this->assertCount(1, $this->writes);
		$this->assertSame('case', $this->writes[0]['schema']);
	}//end testCopyWithoutDocumentsWritesOnlyTheCase()

	/**
	 * With the option, each document is linked by its URI — the same file,
	 * a second link, never a second file.
	 *
	 * @return void
	 */
	public function testCopyWithDocumentsLinksTheSameFile(): void {
		$service = $this->makeService(
			['case-1' => $this->sourceCase()],
			[
				['id' => 'link-1', 'case' => 'case-1', 'document' => 'nc://file/1', 'title' => 'Bouwtekening'],
				['id' => 'link-2', 'case' => 'case-1', 'document' => 'nc://file/2', 'title' => 'Situatieschets'],
			]
		);

		$copy = $service->copy('case-1', ['documents' => true]);

		$this->assertSame(2, $copy['documentsLinked']);
		$this->assertCount(3, $this->writes);
		$this->assertSame('caseDocument', $this->writes[1]['schema']);
		$this->assertSame('nc://file/1', $this->writes[1]['object']['document']);
		$this->assertSame($this->writes[0]['object']['id'], $this->writes[1]['object']['case']);
		$this->assertSame('nc://file/2', $this->writes[2]['object']['document']);
	}//end testCopyWithDocumentsLinksTheSameFile()

	/**
	 * A source the caller cannot read is a refusal, not an empty copy.
	 *
	 * @return void
	 */
	public function testCopyRefusesWhenTheSourceDoesNotResolve(): void {
		$service = $this->makeService([]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_found');

		$service->copy('case-1', []);
	}//end testCopyRefusesWhenTheSourceDoesNotResolve()

	/**
	 * The proposed title is used when the caller gives none.
	 *
	 * @return void
	 */
	public function testCopyProposesACopyOfTitle(): void {
		$service = $this->makeService(['case-1' => $this->sourceCase()]);

		$service->copy('case-1', []);

		$this->assertSame('Copy of Dakkapel Kerkstraat 12', $this->writes[0]['object']['title']);
	}//end testCopyProposesACopyOfTitle()
}//end class
