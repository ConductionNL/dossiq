<?php

/**
 * ZgwReferenceResolver Tests
 *
 * @category Service
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\ZgwReferenceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwReferenceResolver.
 */
class ZgwReferenceResolverTest extends TestCase
{

    private ZgwReferenceResolver $resolver;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->resolver = new ZgwReferenceResolver(
            logger: $this->createMock(LoggerInterface::class),
            settingsService: $this->createMock(SettingsService::class)
        );
    }//end setUp()

    /**
     * Test that resolveTypeReferences returns body unchanged when field is absent.
     *
     * @return void
     */
    public function testResolveTypeReferencesReturnsBodyWhenFieldAbsent(): void
    {
        $body   = ['other' => 'value'];
        $result = $this->resolver->resolveTypeReferences(
            body: $body,
            field: 'informatieobjecttypen',
            schemaKey: 'document_type_schema',
            lookupField: 'name'
        );
        $this->assertSame($body, $result);
    }//end testResolveTypeReferencesReturnsBodyWhenFieldAbsent()

    /**
     * Test that resolveTypeReferences returns body unchanged when objectService is null.
     *
     * @return void
     */
    public function testResolveTypeReferencesReturnsBodyWhenNoObjectService(): void
    {
        $this->resolver->setContext(objectService: null, mappingConfig: null);
        $body   = ['informatieobjecttypen' => ['some-name']];
        $result = $this->resolver->resolveTypeReferences(
            body: $body,
            field: 'informatieobjecttypen',
            schemaKey: 'document_type_schema',
            lookupField: 'name'
        );
        $this->assertSame($body, $result);
    }//end testResolveTypeReferencesReturnsBodyWhenNoObjectService()

    /**
     * Test that resolveGerelateerdeZaaktypen returns body unchanged when field is absent.
     *
     * @return void
     */
    public function testResolveGerelateerdeZaaktypenReturnsBodyWhenFieldAbsent(): void
    {
        $body   = ['other' => 'value'];
        $result = $this->resolver->resolveGerelateerdeZaaktypen(body: $body);
        $this->assertSame($body, $result);
    }//end testResolveGerelateerdeZaaktypenReturnsBodyWhenFieldAbsent()

    /**
     * Test that resolveGerelateerdeZaaktypen keeps URL references unchanged.
     *
     * @return void
     */
    public function testResolveGerelateerdeZaaktypenKeepsUrlReferences(): void
    {
        $this->resolver->setContext(
            objectService: new class {
                // phpcs:ignore
                public function buildSearchQuery(array $requestParams, $register, $schema): array { return []; }
                // phpcs:ignore
                public function searchObjectsPaginated(array $query): array { return ['results' => []]; }
            },
            mappingConfig: ['sourceRegister' => '1']
        );

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getConfigValue')->willReturn('some-schema');
        $resolver = new ZgwReferenceResolver(
            logger: $this->createMock(LoggerInterface::class),
            settingsService: $settingsService
        );
        $resolver->setContext(
            objectService: new class {
                // phpcs:ignore
                public function buildSearchQuery(array $requestParams, $register, $schema): array { return []; }
                // phpcs:ignore
                public function searchObjectsPaginated(array $query): array { return ['results' => []]; }
            },
            mappingConfig: ['sourceRegister' => '1']
        );

        $urlRef = 'https://example.com/api/v1/zaaktypen/123e4567-e89b-12d3-a456-426614174000';
        $body   = ['gerelateerdeZaaktypen' => [['zaaktype' => $urlRef, 'aard' => 'bijdrage']]];
        $result = $resolver->resolveGerelateerdeZaaktypen(body: $body);

        $this->assertCount(1, $result['gerelateerdeZaaktypen']);
        $this->assertSame($urlRef, $result['gerelateerdeZaaktypen'][0]['zaaktype']);
    }//end testResolveGerelateerdeZaaktypenKeepsUrlReferences()

}//end class
