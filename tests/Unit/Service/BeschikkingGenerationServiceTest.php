<?php

/**
 * BeschikkingGenerationService Unit Tests
 *
 * Tests for the beschikking document generation service covering template
 * selection, filinq availability fallback, and stub bijlage creation.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\BeschikkingGenerationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BeschikkingGenerationService.
 *
 * The second annotation below is LOAD-BEARING, not decoration, and its name must
 * never be written with an at-sign anywhere in this prose: PHPUnit parses
 * annotations ANYWHERE in a docblock, so even a quoted mention becomes a second,
 * malformed annotation and every test in the class errors as invalid.
 *
 * Why it is needed: the service resolves filinq's DocumentService through
 * FleetAppId, so these tests execute that class. PHPUnit reports code executed
 * but not declared as RISKY, the suite runs with failOnRisky, and a risky test
 * turns a passing run into one that prints `OK, but there were issues!` and
 * exits 1. It only fires when a coverage driver is loaded, which CI has and a
 * plain local `composer test:all` does not, so the check is silently absent
 * locally and the local green means nothing about it.
 *
 * @covers \OCA\Dossiq\Service\BeschikkingGenerationService
 * @uses \OCA\Dossiq\Support\FleetAppId
 */
class BeschikkingGenerationServiceTest extends TestCase {

	/**
	 * The IAppConfig mock.
	 *
	 * @var IAppConfig|MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The ContainerInterface mock.
	 *
	 * @var ContainerInterface|MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The LoggerInterface mock.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The IUserSession mock.
	 *
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * The service under test.
	 *
	 * @var BeschikkingGenerationService
	 */
	private BeschikkingGenerationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$this->service = new BeschikkingGenerationService(
			appConfig: $this->appConfig,
			container: $this->container,
			userSession: $this->userSession,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Test that constructor successfully sets required properties.
	 *
	 * The service must be instantiable with the correct dependencies.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testConstructorSetsProperties(): void {
		$service = new BeschikkingGenerationService(
			appConfig: $this->appConfig,
			container: $this->container,
			userSession: $this->userSession,
			logger: $this->logger,
		);

		$this->assertInstanceOf(BeschikkingGenerationService::class, $service);
	}//end testConstructorSetsProperties()

	/**
	 * Without the document app or the object service, nothing is saved, and the answer says so.
	 *
	 * The container resolves neither filinq's DocumentService nor OpenRegister's
	 * ObjectService, so the stub save throws as well.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGenerateBeschikkingWhenNothingCanBeSavedAnswersFailure(): void {
		$this->appConfig
			->method('getValueString')
			->willReturn('');

		$this->container
			->method('get')
			->willThrowException(new \RuntimeException('Docudesk not installed'));

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$result = $this->service->generateBeschikking(
			caseId: 'zaak-123',
			outcome: 'granted',
			motivation: 'Voldoet aan alle eisen.'
		);

		$this->assertFalse(condition: $result['success']);
		$this->assertSame(expected: '', actual: $result['bijlageId']);
		$this->assertStringContainsString(needle: 'could not be saved', haystack: $result['message']);
	}//end testGenerateBeschikkingWhenNothingCanBeSavedAnswersFailure()

	/**
	 * The happy path, asserted against the names and the method filinq really
	 * ships.
	 *
	 * This is the test that was missing. Every existing case made
	 * `container->get()` throw, so the suite was green while the service asked
	 * for `OCA\Docudesk\Service\DocumentService`, a class no instance
	 * registers since the docudesk to filinq rename, and called
	 * `generateFromTemplate()`, which filinq has never published. Both misses
	 * land in the same catch and produce the same text stub, so nothing
	 * downstream could tell generation from failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGenerateBeschikkingCallsFilinqUnderItsCurrentNameAndContract(): void {
		$documentService = new class {

			/**
			 * The arguments the service under test passed in.
			 *
			 * @var array<string,mixed>
			 */
			public array $captured = [];


			/**
			 * Mirrors OCA\Filinq\Service\DocumentService::generateDocument().
			 *
			 * @param string $templateId The template UUID.
			 * @param array  $dataRefs   OpenRegister data references.
			 * @param array  $options    Generation options.
			 *
			 * @return array<string,mixed> The filinq generation envelope.
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options = []): array {
				$this->captured = [
					'templateId' => $templateId,
					'dataRefs' => $dataRefs,
					'options' => $options,
				];

				return [
					'content' => '%PDF-1.4',
					'format' => 'pdf',
					'metadata' => [],
					'warnings' => [],
					'output' => [
						'mode' => 'files',
						'fileId' => 4242,
						'path' => 'Filinq/dso',
						'name' => 'beschikking_granted.pdf',
						'size' => 8,
					],
				];
			}
		};

		$objectService = new class {

			/**
			 * The object last persisted.
			 *
			 * @var array<string,mixed>
			 */
			public array $saved = [];


			/**
			 * Mirrors the ObjectService::saveObject() signature dossiq calls.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The object to persist.
			 *
			 * @return array<string,mixed> The saved object.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				$this->saved = $object;
				return ['id' => 'bijlage-1'];
			}
		};

		$this->appConfig->method('getValueString')->willReturn('tpl-1');

		$this->container
			->method('get')
			->willReturnCallback(
				static function (string $id) use ($documentService, $objectService): object {
					// The CURRENT name only. A revert to OCA\Docudesk\... makes
					// this throw, which is the point.
					if ($id === 'OCA\\Filinq\\Service\\DocumentService') {
						return $documentService;
					}

					if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
						return $objectService;
					}

					throw new \RuntimeException('not registered: ' . $id);
				}
			);

		$result = $this->service->generateBeschikking(
			caseId: 'zaak-123',
			outcome: 'granted',
			motivation: 'Voldoet aan alle eisen.'
		);

		$this->assertTrue($result['success']);
		$this->assertSame('bijlage-1', $result['bijlageId']);
		$this->assertStringNotContainsString('stub', strtolower($result['message']));

		$captured = $documentService->captured;
		$this->assertSame('tpl-1', $captured['templateId']);
		$this->assertSame('pdf', $captured['options']['format']);
		$this->assertSame('admin', $captured['options']['userId']);
		$this->assertSame('files', $captured['options']['output']['mode']);
		$this->assertSame('zaak-123', $captured['options']['adHocData']['caseId']);
		$this->assertSame('granted', $captured['options']['adHocData']['outcome']);

		$this->assertSame(4242, $objectService->saved['fileId']);
		$this->assertSame('beschikking_granted.pdf', $objectService->saved['fileName']);
	}//end testGenerateBeschikkingCallsFilinqUnderItsCurrentNameAndContract()

	/**
	 * Test that generateBeschikking selects the correct template key based on outcome.
	 *
	 * outcome 'granted' must use dso_beschikking_template_verleend;
	 * outcome 'refused' must use dso_beschikking_template_geweigerd.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGenerateBeschikkingOutcomeSelectsCorrectTemplate(): void {
		$capturedKeys = [];

		$this->appConfig
			->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = '') use (&$capturedKeys) {
					$capturedKeys[] = $key;
					return '';
				}
			);

		$this->container
			->method('get')
			->willThrowException(new \RuntimeException('Docudesk not available'));

		$this->logger->method('warning');

		// For 'refused' outcome.
		$this->service->generateBeschikking(
			caseId: 'zaak-456',
			outcome: 'refused',
			motivation: 'Voldoet niet.'
		);

		$this->assertContains('dso_beschikking_template_geweigerd', $capturedKeys);
		$this->assertNotContains('dso_beschikking_template_verleend', $capturedKeys);

		$capturedKeys = [];

		// For 'granted' outcome.
		$this->service->generateBeschikking(
			caseId: 'zaak-789',
			outcome: 'granted',
			motivation: 'Alles in orde.'
		);

		$this->assertContains('dso_beschikking_template_verleend', $capturedKeys);
		$this->assertNotContains('dso_beschikking_template_geweigerd', $capturedKeys);
	}//end testGenerateBeschikkingOutcomeSelectsCorrectTemplate()

	/**
	 * A container that resolves the given document service (or none) and object service.
	 *
	 * @param object|null $documents The document service, or null when filinq is absent.
	 * @param object      $objects   The object service.
	 *
	 * @return void
	 */
	private function serve(?object $documents, object $objects): void {
		$this->container->method('get')->willReturnCallback(
			static function (string $id) use ($documents, $objects): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objects;
				}

				if ($documents !== null && $id === 'OCA\\Filinq\\Service\\DocumentService') {
					return $documents;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
	}//end serve()

	/**
	 * A document service that renders, or throws when told to.
	 *
	 * @param bool $fails Whether rendering throws.
	 *
	 * @return object The document service.
	 */
	private function documents(bool $fails): object {
		return new class ($fails) {
			/**
			 * Constructor.
			 *
			 * @param bool $fails Whether rendering throws.
			 */
			public function __construct(private bool $fails) {
			}

			/**
			 * Mirrors OCA\Filinq\Service\DocumentService::generateDocument().
			 *
			 * @param string $templateId The template UUID.
			 * @param array  $dataRefs   OpenRegister data references.
			 * @param array  $options    Generation options.
			 *
			 * @return array<string,mixed> The filinq generation envelope.
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options = []): array {
				if ($this->fails === true) {
					throw new \RuntimeException('render failed');
				}

				return ['output' => ['mode' => 'files', 'fileId' => 9, 'name' => 'beschikking_granted.pdf']];
			}
		};
	}//end documents()

	/**
	 * An object service whose saveObject() answers the given entity, or throws when given null.
	 *
	 * @param ObjectEntity|null $saved The saved entity, or null to refuse the save.
	 *
	 * @return object The object service.
	 */
	private function objects(?ObjectEntity $saved): object {
		return new class ($saved) {
			/**
			 * Constructor.
			 *
			 * @param ObjectEntity|null $saved The saved entity, or null to refuse the save.
			 */
			public function __construct(private ?ObjectEntity $saved) {
			}

			/**
			 * Mirrors ObjectService::saveObject(), which answers an ObjectEntity.
			 *
			 * @param string              $register The register slug.
			 * @param string              $schema   The schema slug.
			 * @param array<string,mixed> $object   The object to persist.
			 *
			 * @return ObjectEntity The saved object.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				if ($this->saved === null) {
					throw new \RuntimeException('save refused');
				}

				return $this->saved;
			}
		};
	}//end objects()

	/**
	 * A saved entity with the given uuid, as OpenRegister answers a save.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);

		return $entity;
	}//end entity()

	/**
	 * The attachment's id is read off the entity OpenRegister answers the save with.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/specs/dso-omgevingsloket/spec.md
	 */
	public function testGeneratedDocumentIsAttachedUnderTheSavedEntitysId(): void {
		$this->appConfig->method('getValueString')->willReturn('tpl-1');
		$this->serve(documents: $this->documents(fails: false), objects: $this->objects(saved: $this->entity(uuid: 'bijlage-7')));

		$result = $this->service->generateBeschikking(caseId: 'zaak-1', outcome: 'granted', motivation: 'Past.');

		$this->assertSame(expected: ['success' => true, 'bijlageId' => 'bijlage-7', 'message' => 'Beschikking generated and attached.'], actual: $result);
	}//end testGeneratedDocumentIsAttachedUnderTheSavedEntitysId()

	/**
	 * A generated document whose attachment cannot be saved is not reported as attached.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/specs/dso-omgevingsloket/spec.md
	 */
	public function testAttachmentSaveFailureAnswersFailure(): void {
		$this->appConfig->method('getValueString')->willReturn('tpl-1');
		$this->serve(documents: $this->documents(fails: false), objects: $this->objects(saved: null));
		$this->logger->expects($this->once())->method('error')
			->with($this->stringContains(string: 'could not attach bijlage: save refused'));

		$result = $this->service->generateBeschikking(caseId: 'zaak-1', outcome: 'granted', motivation: 'Past.');

		$expected = ['success' => false, 'bijlageId' => '', 'message' => 'Beschikking generated, but the bijlage could not be saved.'];
		$this->assertSame(expected: $expected, actual: $result);
	}//end testAttachmentSaveFailureAnswersFailure()

	/**
	 * A failed generation whose stub cannot be saved either answers failure, not a stub.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/specs/dso-omgevingsloket/spec.md
	 */
	public function testGenerationAndStubSaveFailureAnswersFailure(): void {
		$this->appConfig->method('getValueString')->willReturn('tpl-1');
		$this->serve(documents: $this->documents(fails: true), objects: $this->objects(saved: null));

		$result = $this->service->generateBeschikking(caseId: 'zaak-1', outcome: 'granted', motivation: 'Past.');

		$expected = ['success' => false, 'bijlageId' => '', 'message' => 'Beschikking generation failed, and the stub bijlage could not be saved.'];
		$this->assertSame(expected: $expected, actual: $result);
	}//end testGenerationAndStubSaveFailureAnswersFailure()

	/**
	 * A failed generation whose stub is saved answers with the stub's id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-dso-omgevingsloket/specs/dso-omgevingsloket/spec.md
	 */
	public function testGenerationFailureAnswersWithTheSavedStub(): void {
		$this->appConfig->method('getValueString')->willReturn('tpl-1');
		$this->serve(documents: $this->documents(fails: true), objects: $this->objects(saved: $this->entity(uuid: 'stub-7')));

		$result = $this->service->generateBeschikking(caseId: 'zaak-1', outcome: 'granted', motivation: 'Past.');

		$expected = ['success' => true, 'bijlageId' => 'stub-7', 'message' => 'Stub beschikking bijlage created (document generation failed).'];
		$this->assertSame(expected: $expected, actual: $result);
	}//end testGenerationFailureAnswersWithTheSavedStub()
}//end class
