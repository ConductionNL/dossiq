<?php

/**
 * End-to-end integration tests for the AWB termijnbewaking + dwangsom engine.
 *
 * Drives all five required scenarios (REQ-TERM-011-A..E) against a single
 * in-memory ObjectService fake so the full chain (termijn → pause/extend →
 * overschrijding → ingebrekestelling → dwangsom accrual → beschikking →
 * payment → bezwaar) is exercised in one place.
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
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Procest\Service\BerichtenboxRoutingService;
use OCA\Procest\Service\DwangsomBezwaarService;
use OCA\Procest\Service\DwangsomCalculationService;
use OCA\Procest\Service\DwangsomUitbetalingService;
use OCA\Procest\Service\IngebrekestellingService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\TermijnDailyScanService;
use OCA\Procest\Service\DeadlineEscalationService;
use OCA\Procest\Service\DeadlineExtensionService;
use OCA\Procest\Service\TermijnNotificationService;
use OCA\Procest\Service\DeadlinePauseService;
use OCA\Procest\Service\TermijnService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Drives all 5 termijnbewaking E2E scenarios against the chain.
 */
class TermijnbewakingEndToEndTest extends TestCase
{
    private FakeTermijnStore $objects;
    private SettingsService $settings;
    private TermijnService $termijnService;
    private DeadlinePauseService $pauseService;
    private DeadlineExtensionService $extService;
    private IngebrekestellingService $ingService;
    private DwangsomCalculationService $calcService;
    private DwangsomUitbetalingService $uitService;
    private DwangsomBezwaarService $bezService;
    private TermijnNotificationService $notifService;
    private TermijnDailyScanService $scanService;

    protected function setUp(): void
    {
        $this->objects = new FakeTermijnStore();
        $settings      = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($this->objects);
        $settings->method('getConfigValue')->willReturnCallback(
            static function (string $key): string {
                return match ($key) {
                    'register'                     => 'procest',
                    'termijn_definitie_schema'     => 'termijnDefinitie',
                    'termijn_instance_schema'      => 'termijnInstance',
                    'termijn_gebeurtenis_schema'   => 'termijnGebeurtenis',
                    'ingebrekestelling_schema'     => 'ingebrekestelling',
                    'dwangsom_berekening_schema'   => 'dwangsomBerekening',
                    'dwangsom_uitbetaling_schema'  => 'dwangsomUitbetaling',
                    default                        => '',
                };
            },
        );
        $this->settings = $settings;

        $logger = $this->createMock(LoggerInterface::class);
        $this->termijnService = new TermijnService($settings, $logger);
        $this->pauseService   = new DeadlinePauseService($this->termijnService);
        $this->extService     = new DeadlineExtensionService($this->termijnService);
        $this->ingService     = new IngebrekestellingService($settings, $this->termijnService, $logger);
        $this->calcService    = new DwangsomCalculationService($settings, $logger);
        $this->uitService     = new DwangsomUitbetalingService($settings);
        $this->bezService     = new DwangsomBezwaarService($settings, $this->termijnService, $logger);
        $this->notifService   = new TermijnNotificationService(
            $this->termijnService,
            new BerichtenboxRoutingService($logger),
            $logger
        );
        $this->scanService    = new TermijnDailyScanService(
            $settings,
            $this->termijnService,
            new DeadlineEscalationService($this->termijnService, $logger),
            $logger,
            $this->calcService
        );

        // Seed AWB-default Wmo definition.
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
     * Scenario 1: normal case (no pause/extension → voltooi before deadline).
     *
     * @return void
     */
    public function testScenario1NormalCase(): void
    {
        $instance = $this->termijnService->createTermijnInstance(
            'Z/2026/S1',
            'omgevingsvergunning-regulier',
            new DateTimeImmutable('2026-06-01T10:00:00+00:00')
        );

        $voltooid = $this->termijnService->markTermijnCompleted(
            (string) $instance['id'],
            new DateTimeImmutable('2026-07-15')
        );

        self::assertSame('voltooid', $voltooid['status']);
        self::assertSame('2026-07-15', $voltooid['voltooiDatum']);
    }

    /**
     * Scenario 2: pause case (incomplete aanvraag → hersteltermijn → resume).
     *
     * @return void
     */
    public function testScenario2PauseCase(): void
    {
        $this->termijnService->getTermijnDefinitie('omgevingsvergunning-regulier');
        $instance = $this->termijnService->createTermijnInstance(
            'Z/2026/S2',
            'omgevingsvergunning-regulier',
            new DateTimeImmutable('2026-06-01T10:00:00+00:00')
        );
        $id = (string) $instance['id'];

        $paused = $this->pauseService->registerPauze($id, 14, 'Aanvulling vereist');
        self::assertSame('gepauzeerd', $paused['status']);
        self::assertSame('2026-08-10', $paused['einddatumActueel']);

        // Resume 7 days into the pause (7 days unused).
        $pauseStart = new DateTimeImmutable($paused['pauzeStartDatum']);
        $resumed    = $this->pauseService->resumeAfterPauze($id, $pauseStart->modify('+7 days'));
        self::assertSame('lopend', $resumed['status']);
        self::assertSame('2026-08-03', $resumed['einddatumActueel']);
    }

    /**
     * Scenario 3: extension case (first extension → voltooi after).
     *
     * @return void
     */
    public function testScenario3ExtensionCase(): void
    {
        $this->termijnService->getTermijnDefinitie('omgevingsvergunning-regulier');
        $instance = $this->termijnService->createTermijnInstance(
            'Z/2026/S3',
            'omgevingsvergunning-regulier',
            new DateTimeImmutable('2026-06-01T10:00:00+00:00')
        );
        $id = (string) $instance['id'];

        $extended = $this->extService->requestExtension(
            $id,
            'Aanvullend onderzoek vereist',
            '2026-09-30'
        );
        self::assertSame('verlengd', $extended['status']);
        self::assertSame(1, $extended['aantalVerlengingen']);

        $voltooid = $this->termijnService->markTermijnCompleted($id, new DateTimeImmutable('2026-09-20'));
        self::assertSame('voltooid', $voltooid['status']);
    }

    /**
     * Scenario 4: overschrijding + dwangsom + beschikking + payment signal.
     *
     * @return void
     */
    public function testScenario4OverschrijdingAndDwangsom(): void
    {
        // Seed overdue instance directly to simulate elapsed time without sleeping.
        $instance = $this->objects->saveObject('procest', 'termijnInstance', [
            'zaak'                  => 'Z/2026/S4',
            'termijnDefinitie'      => 'td-ov',
            'startDatum'            => '2026-01-01T10:00:00+00:00',
            'einddatumBerekend'     => '2026-02-26',
            'einddatumActueel'      => '2026-02-26',
            'status'                => 'overschreden',
            'notificatiesVerstuurd' => [],
        ]);
        $instanceId = (string) $instance['id'];

        // Register ingebrekestelling (after deadline).
        $row = $this->ingService->registerIngebrekestelling(
            $instanceId,
            new DateTimeImmutable('2026-03-15'),
            'email',
            'doc:notice'
        );
        self::assertTrue($row['gevalideerd']);
        self::assertArrayHasKey('dwangsomBerekening', $row);
        $berekeningId = (string) $row['dwangsomBerekening']['id'];

        // Accrue 5 days.
        for ($i = 0; $i < 5; $i++) {
            $this->calcService->calculateDaily($berekeningId);
        }
        $accrued = $this->objects->store['dwangsomBerekening'][$berekeningId];
        self::assertSame(5, $accrued['huidigeDag']);
        self::assertSame(11500, $accrued['cumulatievBedrag']);

        // Beschikking arrives — stop.
        $stopped = $this->calcService->stopForBeschikking($berekeningId);
        self::assertSame('gestopt-wegens-beschikking', $stopped['status']);
        self::assertSame(11500, $stopped['definitievBedrag']);

        // Prepare payment.
        $uitb = $this->uitService->prepareBetaling(
            $berekeningId,
            'J. Burger',
            'NL91ABNA0417164300',
            new DateTimeImmutable('2026-03-15')
        );
        self::assertSame(11500, $uitb['bedrag']);
        self::assertSame('voorbereid', $uitb['status']);

        // Callback arrives confirming payment.
        $paid = $this->uitService->handleCallback(
            (string) $uitb['referentie'],
            'betaald',
            new DateTimeImmutable('2026-04-05'),
            'ERP-S4-001'
        );
        self::assertSame('betaald', $paid['status']);
    }

    /**
     * Scenario 5: bezwaar (dwangsom → bezwaar → resolution with amount change).
     *
     * @return void
     */
    public function testScenario5Bezwaar(): void
    {
        // Stand up a stopped berekening + linked uitbetaling.
        $this->objects->saveObject('procest', 'dwangsomBerekening', [
            'id'                => 'b-s5',
            'termijnInstance'   => 'ti-s5',
            'status'            => 'gestopt-wegens-beschikking',
            'definitievBedrag'  => 50000,
        ]);
        $this->objects->saveObject('procest', 'dwangsomUitbetaling', [
            'id'                 => 'u-s5',
            'dwangsomBerekening' => 'b-s5',
            'bedrag'             => 50000,
            'status'             => 'voorbereid',
        ]);

        $frozen = $this->bezService->registerBezwaar('b-s5', 'AWB 7:1', 'Bedrag te hoog');
        self::assertSame('bezwaar-bevroren', $frozen['status']);
        $uitFrozen = $this->objects->store['dwangsomUitbetaling']['u-s5'];
        self::assertSame('on-hold-bezwaar', $uitFrozen['status']);

        // Heroverweging halves the amount.
        $resolved = $this->bezService->resolveBezwaar('b-s5', 25000, 'AWB 7:11');
        self::assertSame(25000, $resolved['definitievBedrag']);
        self::assertSame('voltooid', $resolved['status']);
        $uitResumed = $this->objects->store['dwangsomUitbetaling']['u-s5'];
        self::assertSame(25000, $uitResumed['bedrag']);
        self::assertSame('voorbereid', $uitResumed['status']);
    }
}
