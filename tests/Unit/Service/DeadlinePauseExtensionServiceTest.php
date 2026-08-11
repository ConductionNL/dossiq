<?php

/**
 * Unit tests for DeadlinePauseService + DeadlineExtensionService.
 *
 * Exercises AWB 4:5/4:15 pause + resume math and AWB 4:14 verlenging
 * (ceiling + supervisor override) against an in-memory store.
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
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\DeadlineExtensionService;
use OCA\Procest\Service\DeadlinePauseService;
use OCA\Procest\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Procest\Service\DeadlinePauseService
 * @covers \OCA\Procest\Service\DeadlineExtensionService
 *
 * @uses \OCA\Procest\Service\Substitution\SubstitutedWorkResolver
 * @uses \OCA\Procest\Service\Support\SearchesObjects
 * @uses \OCA\Procest\Service\TermijnDailyScanService
 * @uses \OCA\Procest\Service\TermijnService
 */
class DeadlinePauseExtensionServiceTest extends TestCase
{
    private FakeTermijnStore $objects;
    private TermijnService $termijnService;
    private DeadlinePauseService $pauseService;
    private DeadlineExtensionService $extService;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                   => 'procest',
                    'termijn_definitie_schema'   => 'termijnDefinitie',
                    'termijn_instance_schema'    => 'termijnInstance',
                    'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
                    default                      => '',
                };
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $this->termijnService = new TermijnService($settings, $logger);
        $this->pauseService   = new DeadlinePauseService($this->termijnService);
        $this->extService     = new DeadlineExtensionService($this->termijnService);

        // Seed an Omgevingsvergunning definition (max 1 extension).
        $this->objects->saveObject('procest', 'termijnDefinitie', [
            'id'                  => 'td-ov',
            'zaaktype'            => 'omgevingsvergunning-regulier',
            'wettelijkeGrondslag' => 'Wabo 3.9 lid 1',
            'standaardDuurDagen'  => 56,
            'aantalVerlengingen'  => 1,
            'validFrom'           => '2026-01-01',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function newInstance(): array
    {
        // Resolve the definition so the cache gets populated.
        $this->termijnService->getTermijnDefinitie('omgevingsvergunning-regulier');
        return $this->termijnService->createTermijnInstance(
            'Z/2026/300',
            'omgevingsvergunning-regulier',
            new DateTimeImmutable('2026-06-01T10:00:00+00:00')
        );
    }

    /**
     * @return void
     */
    public function testPauseExtendsDeadlineAndSetsStatus(): void
    {
        $instance = $this->newInstance();
        $id       = (string) $instance['id'];

        $paused = $this->pauseService->registerPauze($id, 14, 'Aanvrager moet aanvulling indienen', 'doc:1');
        self::assertSame('gepauzeerd', $paused['status']);
        self::assertSame('2026-08-10', $paused['einddatumActueel']);
    }

    /**
     * @return void
     */
    public function testPauseRejectsNonPositiveDuration(): void
    {
        $instance = $this->newInstance();
        $this->expectException(RuntimeException::class);
        $this->pauseService->registerPauze((string) $instance['id'], 0, 'foo');
    }

    /**
     * @return void
     */
    public function testResumeConsumesElapsedDays(): void
    {
        $instance = $this->newInstance();
        $id       = (string) $instance['id'];

        // Pause for 14 days; deadline now 2026-08-10.
        $this->pauseService->registerPauze($id, 14, 'Aanvulling vereist');

        // Aanvulling arrives 4 days after pause-start (so 10 days unused).
        // After resume, deadline should pull back by 10 days → 2026-07-31.
        $currentInstance = $this->termijnService->getTermijnInstance($id);
        $pauseStart      = new DateTimeImmutable($currentInstance['pauzeStartDatum']);
        $aanvulling      = $pauseStart->modify('+4 days');

        $resumed = $this->pauseService->resumeAfterPauze($id, $aanvulling);
        self::assertSame('lopend', $resumed['status']);
        self::assertSame('2026-07-31', $resumed['einddatumActueel']);
    }

    /**
     * @return void
     */
    public function testFirstExtensionSucceeds(): void
    {
        $instance = $this->newInstance();
        $id       = (string) $instance['id'];

        $extended = $this->extService->requestExtension(
            $id,
            'Complex zaak; meer onderzoek vereist',
            '2026-08-31',
            'doc:verlengingsbrief-1'
        );
        self::assertSame('verlengd', $extended['status']);
        self::assertSame(1, $extended['aantalVerlengingen']);
        self::assertSame('2026-08-31', $extended['einddatumActueel']);
    }

    /**
     * @return void
     */
    public function testSecondExtensionBlockedByCeiling(): void
    {
        $instance = $this->newInstance();
        $id       = (string) $instance['id'];

        $this->extService->requestExtension($id, 'eerste', '2026-08-31');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AWB 4:14 lid 3');
        $this->extService->requestExtension($id, 'tweede', '2026-09-30');
    }

    /**
     * @return void
     */
    public function testSupervisorOverrideAllowsSecondExtension(): void
    {
        $instance = $this->newInstance();
        $id       = (string) $instance['id'];

        $this->extService->requestExtension($id, 'eerste', '2026-08-31');
        $second = $this->extService->requestSupervisorExtension(
            $id,
            'tweede; supervisor goedgekeurd',
            '2026-09-30',
            'doc:second'
        );
        self::assertSame(2, $second['aantalVerlengingen']);
    }

    /**
     * @return void
     */
    public function testExtensionRejectsEmptyMotivering(): void
    {
        $instance = $this->newInstance();
        $this->expectException(RuntimeException::class);
        $this->extService->requestExtension((string) $instance['id'], '   ', '2026-08-31');
    }

    /**
     * @return void
     */
    public function testExtensionRejectsEarlierDeadline(): void
    {
        $instance = $this->newInstance();
        $this->expectException(RuntimeException::class);
        $this->extService->requestExtension((string) $instance['id'], 'foo', '2026-07-01');
    }
}
