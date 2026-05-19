<?php

/**
 * CatalogiFilterHandlerTest — Unit tests for CatalogiFilterHandler.
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

use OCA\Procest\Controller\ZtcController\CatalogiFilterHandler;
use OCA\Procest\Service\ZgwService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CatalogiFilterHandler.
 */
class CatalogiFilterHandlerTest extends TestCase
{

    /**
     * Verify filterByDatumGeldigheid returns an empty array when given no results.
     *
     * @return void
     */
    public function testFilterByDatumGeldigheidsWithEmptyResults(): void
    {
        $zgwService = $this->createMock(ZgwService::class);
        $handler    = new CatalogiFilterHandler(zgwService: $zgwService);
        $result     = $handler->filterByDatumGeldigheid(results: [], datumGeldigheid: '2024-01-01');
        $this->assertSame([], $result);
    }//end testFilterByDatumGeldigheidsWithEmptyResults()

}//end class
