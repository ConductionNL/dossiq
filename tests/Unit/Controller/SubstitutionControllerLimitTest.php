<?php

/**
 * SubstitutionController pagination-hardening tests.
 *
 * Verifies `allSubstitutions()` applies a bounded `_limit` to the underlying
 * OpenRegister query instead of fetching the entire substitution register
 * unbounded (performance-hardening-audit-log-and-boot, REQ-PERF-01b).
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/performance-hardening-audit-log-and-boot/specs/performance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\SubstitutionController;
use OCA\Procest\Service\CaseReassignmentService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\SubstitutionAuditService;
use OCA\Procest\Service\SubstitutionService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pagination-hardening test for SubstitutionController.
 *
 * @covers \OCA\Procest\Controller\SubstitutionController
 */
class SubstitutionControllerLimitTest extends TestCase
{
    /**
     * `allSubstitutions()` (private) MUST pass a `_limit` filter through to
     * `searchObjectsAsArrays()` rather than fetching the register unbounded.
     *
     * @return void
     */
    public function testAllSubstitutionsAppliesLimit(): void
    {
        $settingsService = $this->createMock(originalClassName: SettingsService::class);
        $settingsService->method('getConfigValue')->willReturnCallback(
            static function (string $key, string $default=''): string {
                $map = [
                    'register'            => 'main',
                    'substitution_schema' => 'substitution',
                ];
                return ($map[$key] ?? $default);
            }
        );

        $objectService = new class {

            /**
             * Filters captured from the searchObjectsBySlug() call under test.
             *
             * @var array<string,mixed>|null
             */
            public $captured;

            /**
             * Fake slug-search that captures the filters it was called with.
             *
             * @param string $register Register slug.
             * @param string $schema   Schema slug.
             * @param array  $filters  Query filters.
             *
             * @return array
             */
            public function searchObjectsBySlug(string $register, string $schema, array $filters=[]): array
            {
                $this->captured = $filters;
                return [];
            }//end searchObjectsBySlug()
        };

        $settingsService->method('getObjectService')->willReturn($objectService);

        $controller = new SubstitutionController(
            appName: 'procest',
            request: $this->createMock(originalClassName: IRequest::class),
            substitutionService: $this->createMock(originalClassName: SubstitutionService::class),
            auditService: $this->createMock(originalClassName: SubstitutionAuditService::class),
            reassignmentService: $this->createMock(originalClassName: CaseReassignmentService::class),
            settingsService: $settingsService,
            userSession: $this->createMock(originalClassName: IUserSession::class),
            groupManager: $this->createMock(originalClassName: IGroupManager::class),
            logger: $this->createMock(originalClassName: LoggerInterface::class),
        );

        $method = new \ReflectionMethod(SubstitutionController::class, 'allSubstitutions');
        $method->setAccessible(true);
        $method->invoke($controller);

        $this->assertIsArray(actual: $objectService->captured);
        $this->assertArrayHasKey(key: '_limit', array: $objectService->captured);
        $this->assertGreaterThan(expected: 0, actual: $objectService->captured['_limit']);
    }//end testAllSubstitutionsAppliesLimit()
}//end class
