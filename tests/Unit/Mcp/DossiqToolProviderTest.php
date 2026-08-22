<?php

/**
 * DossiqToolProvider Unit Tests
 *
 * Tests for the Dossiq MCP tool provider (hydra ADR-034 / ADR-035): asserts
 * the tool catalogue shape and the structured-error contract of invokeTool().
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Mcp;

use OCA\Dossiq\Mcp\DossiqToolProvider;
use OCA\Dossiq\Mcp\Tool\DossiqCaseAuthorizer;
use OCA\Dossiq\Mcp\Tool\DossiqCaseReader;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the DossiqToolProvider class.
 *
 * @covers \OCA\Dossiq\Mcp\DossiqToolProvider
 *
 * @uses \OCA\Dossiq\Mcp\Tool\DossiqCaseAuthorizer
 * @uses \OCA\Dossiq\Mcp\Tool\DossiqCaseReader
 */
class DossiqToolProviderTest extends TestCase {

	/**
	 * The provider under test.
	 *
	 * @var DossiqToolProvider
	 */
	private DossiqToolProvider $provider;

	/**
	 * Set up a provider with mocked dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$settingsService = $this->createMock(SettingsService::class);
		$userSession = $this->createMock(IUserSession::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		// Real collaborators over mocked infrastructure: the provider's error
		// contract depends on what the reader/authorizer actually decide, so
		// mocking them out would make these tests assert nothing.
		$this->provider = new DossiqToolProvider(
			caseReader: new DossiqCaseReader(
				settingsService: $settingsService,
				logger: $logger,
			),
			authorizer: new DossiqCaseAuthorizer(
				settingsService: $settingsService,
				userSession: $userSession,
				groupManager: $groupManager,
				logger: $logger,
			),
		);

	}//end setUp()

	/**
	 * getAppId() returns the dossiq app slug.
	 *
	 * @return void
	 */
	public function testGetAppIdReturnsDossiq(): void {
		$this->assertSame('dossiq', $this->provider->getAppId());

	}//end testGetAppIdReturnsDossiq()

	/**
	 * getTools() returns exactly 2 well-formed descriptors namespaced under dossiq.
	 *
	 * @return void
	 */
	public function testGetToolsReturnsTwoWellFormedDescriptors(): void {
		$tools = $this->provider->getTools();

		$this->assertCount(2, $tools);

		$ids = [];
		foreach ($tools as $tool) {
			$this->assertIsArray($tool);
			$this->assertArrayHasKey('id', $tool);
			$this->assertArrayHasKey('name', $tool);
			$this->assertArrayHasKey('description', $tool);
			$this->assertArrayHasKey('inputSchema', $tool);

			$this->assertIsString($tool['id']);
			$this->assertStringStartsWith('dossiq.', $tool['id']);

			$this->assertIsString($tool['description']);
			$this->assertNotSame('', trim($tool['description']));

			$this->assertIsArray($tool['inputSchema']);
			$this->assertArrayHasKey('type', $tool['inputSchema']);
			$this->assertSame('object', $tool['inputSchema']['type']);
			$this->assertArrayHasKey('properties', $tool['inputSchema']);
			$this->assertIsArray($tool['inputSchema']['properties']);
			$this->assertArrayHasKey('required', $tool['inputSchema']);
			$this->assertIsArray($tool['inputSchema']['required']);

			$ids[] = $tool['id'];
		}//end foreach

		$this->assertEqualsCanonicalizing(
			['dossiq.listProcesses', 'dossiq.getProcessDetails'],
			$ids
		);

	}//end testGetToolsReturnsTwoWellFormedDescriptors()

	/**
	 * invokeTool() with an unknown tool id returns a structured error (does not throw).
	 *
	 * @return void
	 */
	public function testInvokeUnknownToolReturnsErrorEnvelope(): void {
		$result = $this->provider->invokeTool('dossiq.bogus', []);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('error', $result);
		$this->assertIsArray($result['error']);
		$this->assertArrayHasKey('code', $result['error']);
		$this->assertArrayHasKey('message', $result['error']);
		$this->assertSame('unknown_tool', $result['error']['code']);

	}//end testInvokeUnknownToolReturnsErrorEnvelope()

	/**
	 * invokeTool() surfaces a not_configured / storage error rather than throwing
	 * when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testInvokeToolWithoutStorageReturnsErrorEnvelope(): void {
		// The mocked SettingsService::getObjectService() returns null by default.
		$result = $this->provider->invokeTool('dossiq.listProcesses', []);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('error', $result);
		$this->assertContains(
			$result['error']['code'],
			['storage_unavailable', 'not_configured']
		);

	}//end testInvokeToolWithoutStorageReturnsErrorEnvelope()

	/**
	 * invokeTool('dossiq.getProcessDetails') with no id argument returns an
	 * invalid_arguments error.
	 *
	 * @return void
	 */
	public function testGetProcessDetailsWithoutIdReturnsInvalidArguments(): void {
		$result = $this->provider->invokeTool('dossiq.getProcessDetails', []);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('error', $result);
		$this->assertSame('invalid_arguments', $result['error']['code']);

	}//end testGetProcessDetailsWithoutIdReturnsInvalidArguments()
}//end class
