<?php

/**
 * TenderViewModelService Unit Tests
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\TenderViewModelService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\TenderViewModelService
 */
class TenderViewModelServiceTest extends TestCase
{
    public function testBadgeColors(): void
    {
        $svc = new TenderViewModelService();
        $this->assertSame('gray', $svc->badgeColor('submitted'));
        $this->assertSame('blue', $svc->badgeColor('evaluating'));
        $this->assertSame('green', $svc->badgeColor('awarded'));
        $this->assertSame('red', $svc->badgeColor('rejected'));
        $this->assertSame('orange', $svc->badgeColor('withdrawn'));
        $this->assertSame('gray', $svc->badgeColor('unknown'));
    }

    public function testVisibilityFlagsForRejectedWithEvalRef(): void
    {
        $svc = new TenderViewModelService();
        $f   = $svc->visibilityFlags(['status' => 'rejected', 'evaluationReportRef' => 'r1']);
        $this->assertTrue($f['showRejection']);
        $this->assertTrue($f['showEvaluationDownload']);
        $this->assertFalse($f['showAward']);
        $this->assertFalse($f['showWithdrawal']);
    }

    public function testVisibilityFlagsForAwardedNoEval(): void
    {
        $svc = new TenderViewModelService();
        $f   = $svc->visibilityFlags(['status' => 'awarded']);
        $this->assertTrue($f['showAward']);
        $this->assertFalse($f['showEvaluationDownload']);
    }

    public function testCacheControlHeader(): void
    {
        $svc = new TenderViewModelService();
        $this->assertSame('private, max-age=300', $svc->cacheControlHeader());
    }
}
