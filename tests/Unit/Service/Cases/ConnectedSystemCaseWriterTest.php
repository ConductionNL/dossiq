<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A connected system writes cases and documents as the service account (decision 206).
 *
 * The writer, the document filer, the service account and the case type
 * resolver are the production classes. The object store is a double that
 * records who was signed in at each call and with which access scope, and the
 * case it is handed is validated against the schema dossiq ships.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\ConnectedSystemCaseWriter;
use OCA\Dossiq\Service\Cases\ConnectedSystemDocumentFiler;
use OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Cases\ConnectedSystemCaseWriter
 * @covers \OCA\Dossiq\Service\Cases\ConnectedSystemDocumentFiler
 * @uses   \OCA\Dossiq\Service\CaseType\CaseTypeReferenceResolver
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 */
class ConnectedSystemCaseWriterTest extends TestCase {

	use MakesBackgroundServiceAccount;

	private const CASE_TYPE = '0b6b1c1e-6f0a-4d3e-9f57-0a4b5e6c7d80';

	private const STATUS = '1c7c2d2f-7a1b-4e4f-8a68-1b5c6f7d8e91';

	private const DOCUMENT_TYPE = '2d8d3e3a-8b2c-4f5a-9b79-2c6d7a8e9fa2';

	private const CONNECTION = [
		'acceptsWrites' => true,
		'caseTypes' => ['kapvergunning' => 'Kapvergunning'],
		'documentTypes' => ['Besluit' => self::DOCUMENT_TYPE],
	];

	/**
	 * The stored cases, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * Every call the store got: the method, who was signed in, and the access scope it was given.
	 *
	 * @var array<int, array{0: string, 1: string|null, 2: array}>
	 */
	public array $calls = [];

	/**
	 * The uploads the upload path got.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $uploads = [];

	/**
	 * What the upload path answers.
	 *
	 * @var array<string, mixed>
	 */
	private array $uploadResult = ['success' => true, 'informatieobject' => ['id' => 'doc-uuid-1']];

	/**
	 * Whether the store throws.
	 *
	 * @var bool
	 */
	public bool $storeBroken = false;

	/**
	 * Start with one case opened in dossiq.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls   = [];
		$this->uploads = [];
		$this->cases   = [
			'case-uuid-1' => ['id' => 'case-uuid-1', 'identifier' => '2026-0042', 'title' => 'Kapvergunning Dorpsstraat', 'caseType' => self::CASE_TYPE],
		];
	}//end setUp()

	/**
	 * A create opens a case the real schema accepts, as the service account, with the access rules on.
	 *
	 * @return void
	 */
	public function testACreateOpensACaseAsTheServiceAccount(): void {
		$outcome = $this->writer()->carryOut(operation: self::createOperation(), connection: self::CONNECTION, system: 'src-9');

		$this->assertSame(['identifier' => '2026-0043'], $outcome);
		$saved = end($this->cases);
		$this->assertSame(
			[
				'title' => 'Kapvergunning Dorpsstraat 1',
				'description' => 'Twee eiken',
				'caseType' => self::CASE_TYPE,
				'status' => self::STATUS,
				'startDate' => '2026-10-12',
				'plannedEndDate' => '2026-12-07',
				'intakeChannel' => 'other',
				'initiatorType' => 'person',
				'initiatorSourceId' => '999993653',
				'initiatorDisplayName' => 'Jan Jansen',
				'originSystem' => 'src-9',
				'originIdentifier' => 'ZSH-2026-0001',
			],
			array_diff_key($saved, ['id' => 1, 'identifier' => 1])
		);
		$validator = new RealSchemaValidator();
		$this->assertSame([], $validator->errors(slug: 'case', payload: array_diff_key($saved, ['identifier' => 1])));
		// The control: the same validator refuses a wrong value, and knows the origin fields as declared properties.
		$this->assertNotSame([], $validator->errors(slug: 'case', payload: (['intakeChannel' => 'stuf'] + $saved)));
		$this->assertNotSame([], $validator->errors(slug: 'case', payload: (['originSystem' => 12] + $saved)));
		$this->assertNotSame([], $validator->errors(slug: 'case', payload: (['originIdentifier' => ['x']] + $saved)));

		$this->assertNotSame([], $this->calls);
		foreach ($this->calls as [$method, $actor, $scope]) {
			$this->assertSame('dossiq-achtergrond', $actor, $method . ' ran as the service account');
			$this->assertSame([], $scope, $method . ' did not switch the access rules off');
		}

		$this->assertNull($this->actingUid(), 'The session is handed back afterwards');
	}//end testACreateOpensACaseAsTheServiceAccount()

	/**
	 * Nothing is read or written for a connection that did not opt in, or without a usable service account.
	 *
	 * @return void
	 */
	public function testAConnectionThatDidNotOptInAndAMissingServiceAccountAreForbidden(): void {
		$closed = $this->writer()->carryOut(operation: self::createOperation(), connection: (['acceptsWrites' => false] + self::CONNECTION), system: 'src-9');
		$absent = $this->writer()->carryOut(operation: self::createOperation(), connection: array_diff_key(self::CONNECTION, ['acceptsWrites' => 1]), system: 'src-9');

		$this->configuredAccount = '';
		$noAccount = $this->writer()->carryOut(operation: self::createOperation(), connection: self::CONNECTION, system: 'src-9');

		foreach ([$closed, $absent, $noAccount] as $outcome) {
			$this->assertSame('forbidden', $outcome['kind']);
			$this->assertNotSame('', $outcome['refusal']);
		}

		$this->assertSame([], $this->calls);
		$this->assertCount(1, $this->cases);
	}//end testAConnectionThatDidNotOptInAndAMissingServiceAccountAreForbidden()

	/**
	 * A create is refused without a title, without an identifier and for an unmapped zaaktype; a second one conflicts.
	 *
	 * @return void
	 */
	public function testACreateIsRefusedWhenItCannotBeFiled(): void {
		$writer   = $this->writer();
		$untitled = $writer->carryOut(operation: array_diff_key(self::createOperation(), ['title' => 1]), connection: self::CONNECTION, system: 'src-9');
		$nameless = $writer->carryOut(operation: array_diff_key(self::createOperation(), ['identifier' => 1]), connection: self::CONNECTION, system: 'src-9');
		$unmapped = $writer->carryOut(
			operation: (['caseTypeName' => 'Evenement', 'caseTypeCode' => 'EVT'] + self::createOperation()),
			connection: self::CONNECTION,
			system: 'src-9'
		);
		foreach ([$untitled, $nameless, $unmapped] as $outcome) {
			$this->assertSame('refused', $outcome['kind']);
		}

		$this->assertCount(1, $this->cases);

		$this->assertArrayHasKey('identifier', $writer->carryOut(operation: self::createOperation(), connection: self::CONNECTION, system: 'src-9'));
		$again = $writer->carryOut(operation: self::createOperation(), connection: self::CONNECTION, system: 'src-9');
		$this->assertSame('conflict', $again['kind']);
		$this->assertCount(2, $this->cases);

		// The same identifier from another connection is another system's case, not a duplicate.
		$other = $writer->carryOut(operation: self::createOperation(), connection: self::CONNECTION, system: 'src-other');
		$this->assertArrayHasKey('identifier', $other);
	}//end testACreateIsRefusedWhenItCannotBeFiled()

	/**
	 * An update changes the descriptive fields only, on the system's own case or on a case by its dossiq number.
	 *
	 * @return void
	 */
	public function testAnUpdateChangesDescriptiveFieldsOnly(): void {
		$writer = $this->writer();
		$writer->carryOut(operation: self::createOperation(), connection: self::CONNECTION, system: 'src-9');
		$this->calls = [];

		$outcome = $writer->carryOut(
			operation: [
				'action' => 'update',
				'identifier' => 'ZSH-2026-0001',
				'title' => 'Kapvergunning Dorpsstraat 1a',
				'plannedEndDate' => '2026-12-31',
				'startDate' => '2020-01-01',
				'caseTypeCode' => 'EVT',
			],
			connection: self::CONNECTION,
			system: 'src-9'
		);

		$this->assertSame(['identifier' => '2026-0043'], $outcome);
		$patch = array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === 'patchObject'));
		$this->assertCount(1, $patch);
		$this->assertSame(['title' => 'Kapvergunning Dorpsstraat 1a', 'plannedEndDate' => '2026-12-31'], $patch[0][3]);
		$this->assertSame('dossiq-achtergrond', $patch[0][1]);
		$stored = end($this->cases);
		$this->assertSame('2026-10-12', $stored['startDate'], 'The start date is not a connected system\'s to change');
		$this->assertSame(self::CASE_TYPE, $stored['caseType']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: array_diff_key($stored, ['identifier' => 1])));

		$byNumber = $writer->carryOut(operation: ['action' => 'update', 'identifier' => '2026-0042', 'description' => 'Aangevuld'], connection: self::CONNECTION, system: 'src-9');
		$this->assertSame(['identifier' => '2026-0042'], $byNumber);
		$this->assertSame('Aangevuld', $this->cases['case-uuid-1']['description']);

		$unknown = $writer->carryOut(operation: ['action' => 'update', 'identifier' => 'NOPE', 'title' => 'x'], connection: self::CONNECTION, system: 'src-9');
		$this->assertSame('not-found', $unknown['kind']);
		$nothing = $writer->carryOut(operation: ['action' => 'update', 'identifier' => '2026-0042', 'startDate' => '2020-01-01'], connection: self::CONNECTION, system: 'src-9');
		$this->assertSame('refused', $nothing['kind']);
		$other = $writer->carryOut(operation: ['action' => 'delete', 'identifier' => '2026-0042'], connection: self::CONNECTION, system: 'src-9');
		$this->assertSame('refused', $other['kind']);
	}//end testAnUpdateChangesDescriptiveFieldsOnly()

	/**
	 * A document goes through the screened upload path with the mapped document type, as the service account.
	 *
	 * @return void
	 */
	public function testADocumentIsFiledThroughTheUploadPath(): void {
		$outcome = $this->writer()->carryOut(operation: self::documentOperation(), connection: self::CONNECTION, system: 'src-9');

		$this->assertSame(['identifier' => 'doc-uuid-1'], $outcome);
		$this->assertCount(1, $this->uploads);
		$upload = $this->uploads[0];
		$this->assertSame('case-uuid-1', $upload['caseId']);
		$this->assertSame('besluit.pdf', $upload['file']['name']);
		$this->assertSame('application/pdf', $upload['file']['type']);
		$this->assertSame('%PDF-1.7 besluit', $upload['bytes']);
		$this->assertSame('dossiq-achtergrond', $upload['actor']);
		$this->assertSame(
			[
				'title' => 'Besluit',
				'informatieobjecttype' => self::DOCUMENT_TYPE,
				'vertrouwelijkheidaanduiding' => 'beperkt_openbaar',
				'auteur' => 'A. Ambtenaar',
				'creatiedatum' => '2026-10-12',
				'direction' => 'incoming',
			],
			$upload['metadata']
		);
		$this->assertFileDoesNotExist($upload['file']['tmp_name'], 'The temporary file is removed');
	}//end testADocumentIsFiledThroughTheUploadPath()

	/**
	 * A document is refused for an unknown case, an unmapped type, no content, and when the upload path says no.
	 *
	 * @return void
	 */
	public function testADocumentIsRefusedWhenItCannotBeFiled(): void {
		$writer  = $this->writer();
		$unknown = $writer->carryOut(operation: (['caseIdentifier' => 'NOPE'] + self::documentOperation()), connection: self::CONNECTION, system: 'src-9');
		$this->assertSame('not-found', $unknown['kind']);

		$unmapped = $writer->carryOut(operation: (['documentTypeName' => 'Aanvraag'] + self::documentOperation()), connection: self::CONNECTION, system: 'src-9');
		$empty    = $writer->carryOut(operation: array_diff_key(self::documentOperation(), ['content' => 1]), connection: self::CONNECTION, system: 'src-9');
		$this->assertSame('refused', $unmapped['kind']);
		$this->assertSame('refused', $empty['kind']);
		$this->assertSame([], $this->uploads);

		$this->uploadResult = ['success' => false, 'error' => 'Executable files are not permitted: besluit.pdf'];
		$screened           = $writer->carryOut(operation: self::documentOperation(), connection: self::CONNECTION, system: 'src-9');
		$this->assertSame('refused', $screened['kind']);
		$this->assertStringNotContainsString('Executable', $screened['refusal'], 'The reason the system gets does not quote an internal error');
	}//end testADocumentIsRefusedWhenItCannotBeFiled()

	/**
	 * A failing store is not an outcome: it throws, so the adapter answers a fault and never a confirmation.
	 *
	 * @return void
	 */
	public function testAFailingStoreThrows(): void {
		$this->storeBroken = true;
		$this->expectException(RuntimeException::class);
		$this->writer()->carryOut(operation: self::createOperation(), connection: self::CONNECTION, system: 'src-9');
	}//end testAFailingStoreThrows()

	/**
	 * The generic create operation integriq maps a creeerZaak onto.
	 *
	 * @return array<string, mixed> The operation.
	 */
	private static function createOperation(): array {
		return [
			'action' => 'create',
			'identifier' => 'ZSH-2026-0001',
			'title' => 'Kapvergunning Dorpsstraat 1',
			'description' => 'Twee eiken',
			'startDate' => '2026-10-12',
			'registrationDate' => '2026-10-12',
			'plannedEndDate' => '2026-12-07',
			'caseTypeCode' => 'KAP',
			'caseTypeName' => 'Kapvergunning',
			'initiator' => ['type' => 'person', 'sourceId' => '999993653', 'name' => 'Jan Jansen'],
		];
	}//end createOperation()

	/**
	 * The generic add-document operation integriq maps a voegZaakdocumentToe onto.
	 *
	 * @return array<string, mixed> The operation.
	 */
	private static function documentOperation(): array {
		return [
			'action' => 'add-document',
			'identifier' => 'DOC-1',
			'title' => 'Besluit',
			'documentTypeName' => 'Besluit',
			'format' => 'application/pdf',
			'author' => 'A. Ambtenaar',
			'caseIdentifier' => '2026-0042',
			'creationDate' => '2026-10-12',
			'confidentiality' => 'beperkt_openbaar',
			'fileName' => '../besluit.pdf',
			'content' => '%PDF-1.7 besluit',
		];
	}//end documentOperation()

	/**
	 * The writer over production collaborators and a recording store.
	 *
	 * @return ConnectedSystemCaseWriter
	 */
	private function writer(): ConnectedSystemCaseWriter {
		$store = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param ConnectedSystemCaseWriterTest $test The test.
			 */
			public function __construct(private readonly ConnectedSystemCaseWriterTest $test) {
			}

			/**
			 * OpenRegister's slug search.
			 *
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 * @param array  $filters  The filters.
			 * @param mixed  ...$scope The access scope, empty when the rules are on.
			 *
			 * @return array The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, mixed ...$scope): array {
				$this->test->record(method: 'searchObjectsBySlug', scope: $scope);
				unset($filters['_limit']);
				return array_values(array_filter($this->test->cases, static fn (array $case): bool => array_intersect_assoc($filters, $case) === $filters));
			}

			/**
			 * OpenRegister's save.
			 *
			 * @param array       $object   The object.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 * @param string|null $uuid     The uuid.
			 * @param mixed       ...$scope The access scope.
			 *
			 * @return array The stored object.
			 */
			public function saveObject(array $object, ?string $register=null, ?string $schema=null, ?string $uuid=null, mixed ...$scope): array {
				$this->test->record(method: 'saveObject', scope: $scope);
				$uuid = 'case-uuid-' . (count($this->test->cases) + 1);
				$this->test->cases[$uuid] = ($object + ['id' => $uuid, 'identifier' => '2026-00' . (41 + count($this->test->cases) + 1)]);
				return $this->test->cases[$uuid];
			}

			/**
			 * OpenRegister's partial write.
			 *
			 * @param string      $objectId The uuid.
			 * @param array       $data     The changes.
			 * @param string|null $register The register.
			 * @param string|null $schema   The schema.
			 * @param mixed       ...$scope The access scope.
			 *
			 * @return array The stored object.
			 */
			public function patchObject(string $objectId, array $data, ?string $register=null, ?string $schema=null, mixed ...$scope): array {
				$this->test->record(method: 'patchObject', scope: $scope, changes: $data);
				$this->test->cases[$objectId] = ($data + $this->test->cases[$objectId]);
				return $this->test->cases[$objectId];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => (['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ''));

		$caseTypes = $this->createMock(CaseTypeStore::class);
		$caseTypes->method('versionsWithIdentifier')->willReturnCallback(
			static fn (string $identifier): array => ($identifier === 'kapvergunning' ? [
				['id' => self::CASE_TYPE, 'identifier' => 'kapvergunning', 'version' => '1.0.0', 'isDraft' => false, 'initialStatus' => self::STATUS],
			] : [])
		);
		$caseTypes->method('rowId')->willReturnCallback(static fn (array $row): string => (string)($row['id'] ?? ''));
		$caseTypes->method('referenceId')->willReturnCallback(static fn (mixed $value): string => (is_array($value) === true ? (string)($value['id'] ?? '') : trim((string)$value)));

		$uploads = $this->createMock(DossierUploadHandler::class);
		$uploads->method('uploadOne')->willReturnCallback(
			function (string $caseId, array $file, array $metadata): array {
				$this->uploads[] = [
					'caseId' => $caseId,
					'file' => $file,
					'metadata' => $metadata,
					'bytes' => (string)file_get_contents($file['tmp_name']),
					'actor' => $this->actingUid(),
				];
				return $this->uploadResult;
			}
		);

		return new ConnectedSystemCaseWriter(
			$settings,
			$this->backgroundAccount(),
			new CaseTypeReferenceResolver($caseTypes),
			new ConnectedSystemDocumentFiler($uploads, new NullLogger()),
			new NullLogger()
		);
	}//end writer()

	/**
	 * Record one store call with who is signed in.
	 *
	 * @param string $method  The store method.
	 * @param array  $scope   The access scope arguments it got.
	 * @param array  $changes The changes, for a partial write.
	 *
	 * @return void
	 */
	public function record(string $method, array $scope, array $changes=[]): void {
		if ($this->storeBroken === true) {
			throw new RuntimeException('database down');
		}

		$this->calls[] = [$method, $this->actingUid(), $scope, $changes];
	}//end record()
}//end class
