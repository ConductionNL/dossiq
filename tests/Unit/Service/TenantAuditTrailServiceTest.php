<?php

/**
 * TenantAuditTrailService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-12-isolation-tests-compliance/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\TenantAuditTrailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\TenantAuditTrailService
 */
class TenantAuditTrailServiceTest extends TestCase
{
    private TenantAuditTrailService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TenantAuditTrailService(
            logger: $this->createMock(LoggerInterface::class),
        );
    }

    public function testEmitNormalisesFields(): void
    {
        $entry = $this->svc->emit([
            'action'   => 'case.create',
            'actor'    => 'alice',
            'role'     => 'case_handler',
            'resource' => 'case-uuid-1',
            'tenantId' => 'tenant-uuid-1',
            'ip'       => '127.0.0.1',
            'ua'       => 'TestAgent/1.0',
        ]);

        $this->assertSame('case.create', $entry['action']);
        $this->assertSame('alice', $entry['actor']);
        $this->assertSame('tenant-uuid-1', $entry['tenantId']);
        $this->assertNotSame('', $entry['ts']);
        $this->assertSame([], $entry['bio']);
    }

    public function testSanitiseBioKeepsWhitelist(): void
    {
        $bio = $this->svc->sanitiseBio([
            'deviceId'       => 'd-1',
            'geoLocation'    => 'NL',
            'mfaVerified'    => true,
            'sessionDuration'=> 3600,
            'evilUnknown'    => 'drop-me',
        ]);
        $this->assertSame(['deviceId' => 'd-1', 'geoLocation' => 'NL', 'mfaVerified' => true, 'sessionDuration' => 3600], $bio);
    }

    public function testHardeningChecklistShape(): void
    {
        $items = $this->svc->hardeningChecklist();
        $this->assertGreaterThanOrEqual(7, count($items));
        foreach ($items as $i) {
            $this->assertArrayHasKey('key', $i);
            $this->assertArrayHasKey('description', $i);
            $this->assertArrayHasKey('evidence', $i);
        }
    }
}
