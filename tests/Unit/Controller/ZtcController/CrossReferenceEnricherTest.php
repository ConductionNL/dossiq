<?php

/**
 * CrossReferenceEnricherTest — Unit tests for CrossReferenceEnricher.
 *
 * @category Test
 * @package  OCA\Procest\Tests\Unit\Controller\ZtcController
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller\ZtcController;

use OCA\Procest\Controller\ZtcController\CrossReferenceEnricher;
use OCA\Procest\Service\ZgwService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CrossReferenceEnricher.
 */
class CrossReferenceEnricherTest extends TestCase
{

    /**
     * Verify enrichCrossReferences returns unchanged data for unknown resource types.
     *
     * @return void
     */
    public function testEnrichCrossReferencesReturnsUnchangedDataForUnknownResource(): void
    {
        $zgwService = $this->createMock(ZgwService::class);
        $request    = $this->createMock(IRequest::class);
        $handler    = new CrossReferenceEnricher(zgwService: $zgwService, request: $request);
        $result     = $handler->enrichCrossReferences(resource: 'unknown', data: ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $result);
    }//end testEnrichCrossReferencesReturnsUnchangedDataForUnknownResource()

}//end class
