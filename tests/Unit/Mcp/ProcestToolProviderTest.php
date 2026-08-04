<?php

/**
 * ProcestToolProvider Unit Tests
 *
 * Tests for the Procest MCP tool provider (hydra ADR-034 / ADR-035): asserts
 * the tool catalogue shape and the structured-error contract of invokeTool().
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Mcp;

use OCA\Procest\Mcp\ProcestToolProvider;
use OCA\Procest\Mcp\Tool\ProcestCaseAuthorizer;
use OCA\Procest\Mcp\Tool\ProcestCaseReader;
use OCA\Procest\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the ProcestToolProvider class.
 *
 * @covers \OCA\Procest\Mcp\ProcestToolProvider
 */
class ProcestToolProviderTest extends TestCase
{

    /**
     * The provider under test.
     *
     * @var ProcestToolProvider
     */
    private ProcestToolProvider $provider;

    /**
     * Set up a provider with mocked dependencies.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $settingsService = $this->createMock(SettingsService::class);
        $userSession     = $this->createMock(IUserSession::class);
        $groupManager    = $this->createMock(IGroupManager::class);
        $logger          = $this->createMock(LoggerInterface::class);

        // Real collaborators over mocked infrastructure: the provider's error
        // contract depends on what the reader/authorizer actually decide, so
        // mocking them out would make these tests assert nothing.
        $this->provider = new ProcestToolProvider(
            caseReader: new ProcestCaseReader(
                settingsService: $settingsService,
                logger: $logger,
            ),
            authorizer: new ProcestCaseAuthorizer(
                settingsService: $settingsService,
                userSession: $userSession,
                groupManager: $groupManager,
                logger: $logger,
            ),
        );

    }//end setUp()

    /**
     * getAppId() returns the procest app slug.
     *
     * @return void
     */
    public function testGetAppIdReturnsProcest(): void
    {
        $this->assertSame('procest', $this->provider->getAppId());

    }//end testGetAppIdReturnsProcest()

    /**
     * getTools() returns exactly 2 well-formed descriptors namespaced under procest.
     *
     * @return void
     */
    public function testGetToolsReturnsTwoWellFormedDescriptors(): void
    {
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
            $this->assertStringStartsWith('procest.', $tool['id']);

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
            ['procest.listProcesses', 'procest.getProcessDetails'],
            $ids
        );

    }//end testGetToolsReturnsTwoWellFormedDescriptors()

    /**
     * invokeTool() with an unknown tool id returns a structured error (does not throw).
     *
     * @return void
     */
    public function testInvokeUnknownToolReturnsErrorEnvelope(): void
    {
        $result = $this->provider->invokeTool('procest.bogus', []);

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
    public function testInvokeToolWithoutStorageReturnsErrorEnvelope(): void
    {
        // The mocked SettingsService::getObjectService() returns null by default.
        $result = $this->provider->invokeTool('procest.listProcesses', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertContains(
            $result['error']['code'],
            ['storage_unavailable', 'not_configured']
        );

    }//end testInvokeToolWithoutStorageReturnsErrorEnvelope()

    /**
     * invokeTool('procest.getProcessDetails') with no id argument returns an
     * invalid_arguments error.
     *
     * @return void
     */
    public function testGetProcessDetailsWithoutIdReturnsInvalidArguments(): void
    {
        $result = $this->provider->invokeTool('procest.getProcessDetails', []);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_arguments', $result['error']['code']);

    }//end testGetProcessDetailsWithoutIdReturnsInvalidArguments()
}//end class
