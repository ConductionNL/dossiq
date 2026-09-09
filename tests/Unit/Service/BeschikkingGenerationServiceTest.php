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
	 * Test that generateBeschikking returns a stub when filinq is unavailable.
	 *
	 * When the container cannot resolve DocumentService, the method should
	 * log a warning and return a successful result with a stub bijlage ID.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T03
	 */
	public function testGenerateBeschikkingWhenDocumentAppUnavailableReturnsStub(): void {
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

		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('bijlageId', $result);
		$this->assertStringContainsString('stub', strtolower($result['message']));
	}//end testGenerateBeschikkingWhenDocumentAppUnavailableReturnsStub()

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
			public function saveObject(string $register, string $schema, array $object): array {
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
}//end class
