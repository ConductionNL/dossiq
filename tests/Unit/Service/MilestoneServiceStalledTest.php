<?php

/**
 * MilestoneService stalled-case detection Unit Tests
 *
 * Tests for MilestoneService::findStalledCases(): which active cases get
 * flagged as bottlenecks based on their earliest unreached milestone's
 * working-day deadline.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
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

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\MilestoneService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MilestoneService::findStalledCases().
 *
 * @covers \OCA\Procest\Service\MilestoneService
 */
class MilestoneServiceStalledTest extends TestCase
{

    /**
     * The mocked settings service.
     *
     * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
     */
    private SettingsService $settingsService;

    /**
     * The mocked logger.
     *
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private LoggerInterface $logger;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

    }//end setUp()


    /**
     * Build a fake object service that returns scripted result sets keyed by
     * the schema id present in the query's `@self` block.
     *
     * @param array<string, array<int, array<string, mixed>>> $bySchema Result
     *        sets keyed by schema id ('1' = case, '2' = definition,
     *        '3' = record).
     *
     * @return object The fake object service.
     */
    private function fakeObjectService(array $bySchema): object
    {
        return new class($bySchema) {

            /**
             * @param array<string, array<int, array<string, mixed>>> $bySchema Scripted results.
             */
            public function __construct(private array $bySchema)
            {
            }

            /**
             * @param array<string, mixed> $query The search query.
             *
             * @return array<int, array<string, mixed>>
             */
            public function searchObjects(array $query): array
            {
                $schema = (string) ($query['@self']['schema'] ?? '');
                // Record lookups filter by case id; honour it so each case
                // sees only its own records.
                $rows = ($this->bySchema[$schema] ?? []);
                if ($schema === '3' && isset($query['case']) === true) {
                    $rows = array_values(
                        array_filter(
                            $rows,
                            static fn(array $r): bool => (($r['case'] ?? '') === $query['case'])
                        )
                    );
                }

                if ($schema === '2' && isset($query['caseType']) === true) {
                    $rows = array_values(
                        array_filter(
                            $rows,
                            static fn(array $r): bool => (($r['caseType'] ?? '') === $query['caseType'])
                        )
                    );
                }

                return $rows;
            }
        };

    }//end fakeObjectService()


    /**
     * Wire the settings-service mock to resolve config keys + object service.
     *
     * @param object $objectService The fake object service.
     *
     * @return void
     */
    private function wireSettings(object $objectService): void
    {
        $this->settingsService->method('getObjectService')->willReturn($objectService);
        $this->settingsService->method('getConfigValue')->willReturnMap(
            [
                ['register', '', '10'],
                ['case_schema', '', '1'],
                ['milestone_definition_schema', '', '2'],
                ['milestone_record_schema', '', '3'],
            ]
        );

    }//end wireSettings()


    /**
     * A case whose earliest unreached milestone's deadline is long past MUST be
     * flagged, reporting that milestone's identifier and label.
     *
     * @return void
     */
    public function testOverdueCaseIsFlagged(): void
    {
        $longAgo = (new \DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');

        $objectService = $this->fakeObjectService(
            [
                '1' => [
                    [
                        'id'        => 'zaak-1',
                        'title'     => 'Omgevingsvergunning',
                        'caseType'  => 'ct-1',
                        'status'    => 'in_behandeling',
                        'assignee'  => 'behandelaar-a',
                        'startDate' => $longAgo,
                    ],
                ],
                '2' => [
                    [
                        'id'                          => 'def-1',
                        'caseType'                    => 'ct-1',
                        'identifier'                  => 'documenten_compleet',
                        'label'                       => 'Documenten compleet',
                        'order'                       => 1,
                        'expectedDurationWorkingDays' => 5,
                    ],
                ],
                '3' => [],
            ]
        );

        $this->wireSettings($objectService);
        $service = new MilestoneService($this->settingsService, $this->logger);

        $stalled = $service->findStalledCases(thresholdDays: 0);

        $this->assertCount(1, $stalled);
        $this->assertSame('zaak-1', $stalled[0]['caseId']);
        $this->assertSame('documenten_compleet', $stalled[0]['milestoneIdentifier']);
        $this->assertSame('behandelaar-a', $stalled[0]['assignee']);
        $this->assertGreaterThan(0, $stalled[0]['daysOverdue']);

    }//end testOverdueCaseIsFlagged()


    /**
     * A case whose milestone deadline is still in the future MUST NOT be
     * flagged.
     *
     * @return void
     */
    public function testOnTrackCaseIsNotFlagged(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $objectService = $this->fakeObjectService(
            [
                '1' => [
                    [
                        'id'        => 'zaak-2',
                        'caseType'  => 'ct-1',
                        'status'    => 'in_behandeling',
                        'assignee'  => 'behandelaar-a',
                        'startDate' => $today,
                    ],
                ],
                '2' => [
                    [
                        'id'                          => 'def-1',
                        'caseType'                    => 'ct-1',
                        'identifier'                  => 'documenten_compleet',
                        'label'                       => 'Documenten compleet',
                        'order'                       => 1,
                        'expectedDurationWorkingDays' => 10,
                    ],
                ],
                '3' => [],
            ]
        );

        $this->wireSettings($objectService);
        $service = new MilestoneService($this->settingsService, $this->logger);

        $this->assertCount(0, $service->findStalledCases(thresholdDays: 0));

    }//end testOnTrackCaseIsNotFlagged()


    /**
     * A closed case MUST be skipped even when its deadline is long past.
     *
     * @return void
     */
    public function testClosedCaseIsSkipped(): void
    {
        $longAgo = (new \DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');

        $objectService = $this->fakeObjectService(
            [
                '1' => [
                    [
                        'id'        => 'zaak-3',
                        'caseType'  => 'ct-1',
                        'status'    => 'afgehandeld',
                        'assignee'  => 'behandelaar-a',
                        'startDate' => $longAgo,
                    ],
                ],
                '2' => [
                    [
                        'id'                          => 'def-1',
                        'caseType'                    => 'ct-1',
                        'identifier'                  => 'documenten_compleet',
                        'label'                       => 'Documenten compleet',
                        'order'                       => 1,
                        'expectedDurationWorkingDays' => 5,
                    ],
                ],
                '3' => [],
            ]
        );

        $this->wireSettings($objectService);
        $service = new MilestoneService($this->settingsService, $this->logger);

        $this->assertCount(0, $service->findStalledCases(thresholdDays: 0));

    }//end testClosedCaseIsSkipped()


    /**
     * When the earliest unreached milestone is already reached, the NEXT
     * unreached milestone determines stall — a case with all milestones
     * reached is complete and MUST NOT be flagged.
     *
     * @return void
     */
    public function testAllMilestonesReachedIsNotFlagged(): void
    {
        $longAgo = (new \DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');

        $objectService = $this->fakeObjectService(
            [
                '1' => [
                    [
                        'id'        => 'zaak-4',
                        'caseType'  => 'ct-1',
                        'status'    => 'in_behandeling',
                        'assignee'  => 'behandelaar-a',
                        'startDate' => $longAgo,
                    ],
                ],
                '2' => [
                    [
                        'id'                          => 'def-1',
                        'caseType'                    => 'ct-1',
                        'identifier'                  => 'documenten_compleet',
                        'label'                       => 'Documenten compleet',
                        'order'                       => 1,
                        'expectedDurationWorkingDays' => 5,
                    ],
                ],
                '3' => [
                    [
                        'id'                  => 'rec-1',
                        'case'                => 'zaak-4',
                        'milestoneDefinition' => 'def-1',
                        'reached'             => true,
                    ],
                ],
            ]
        );

        $this->wireSettings($objectService);
        $service = new MilestoneService($this->settingsService, $this->logger);

        $this->assertCount(0, $service->findStalledCases(thresholdDays: 0));

    }//end testAllMilestonesReachedIsNotFlagged()


}//end class
