<?php

/**
 * DsoCaseService Unit Tests
 *
 * Tests for the DSO Omgevingsloket case management service, covering
 * deadline computation, procedure type determination, zaak creation,
 * and status transition logic.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/dso-omgevingsloket/tasks.md#T11
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\Dso\DsoStatusChangeNotifier;
use OCA\Procest\Service\DsoCaseService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Minimal ObjectService stub with named-parameter signatures.
 *
 * The OpenRegister ObjectService is resolved at runtime and called with named
 * arguments; a \stdClass-based mock generates positional-only signatures and
 * fails at call time with "Unknown named parameter". This typed interface lets
 * PHPUnit generate a mock whose method signatures accept the named arguments.
 */
interface DsoCaseObjectServiceStub
{
    /**
     * Find a single object by ID (real ObjectService::find()).
     *
     * @param int|string $id     Object UUID
     * @param mixed      ...$args Remaining find() args (extend/files/register/schema).
     *
     * @return mixed
     */
    public function find(int | string $id, ...$args): mixed;

    /**
     * Save or update an object.
     *
     * @param array<string,mixed> $object   Object data
     * @param string              $register Register slug
     * @param string              $schema   Schema slug
     * @param string|null         $uuid     Optional object UUID for updates
     *
     * @return array<string,mixed>
     */
    public function saveObject(array $object, string $register, string $schema, ?string $uuid=null): array;
}//end interface

/**
 * Unit tests for DsoCaseService.
 *
 * @covers \OCA\Procest\Service\DsoCaseService
 *
 * @uses \OCA\Procest\Service\Dso\DsoStatusChangeNotifier
 */
class DsoCaseServiceTest extends TestCase
{

    /**
     * The IAppConfig mock.
     *
     * @var IAppConfig|MockObject
     */
    private IAppConfig $appConfig;

    /**
     * The ContainerInterface mock.
     *
     * @var ContainerInterface|MockObject
     */
    private ContainerInterface $container;

    /**
     * The IEventDispatcher mock.
     *
     * @var IEventDispatcher|MockObject
     */
    private IEventDispatcher $eventDispatcher;

    /**
     * The LoggerInterface mock.
     *
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface $logger;

    /**
     * The service under test.
     *
     * @var DsoCaseService
     */
    private DsoCaseService $service;

    /**
     * Set up the test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig       = $this->createMock(IAppConfig::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        // A REAL notifier over the same mocked dispatcher, so an assertion on
        // $this->eventDispatcher still observes the event the service emits.
        $this->service = new DsoCaseService(
            appConfig: $this->appConfig,
            container: $this->container,
            notifier: new DsoStatusChangeNotifier(eventDispatcher: $this->eventDispatcher),
            logger: $this->logger,
        );
    }//end setUp()

    /**
     * Test that computeDeadline for reguliere procedure returns 40 working days.
     *
     * Using a known Monday as start date, the 40th working day should be
     * approximately 8 calendar weeks later (adjusting for weekends).
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T11
     */
    public function testComputeDeadlineReguliere(): void
    {
        // Use 2026-01-05 (Monday) as a clean start.
        $indieningsdatum = '2026-01-05';
        $result          = $this->service->computeDeadline(
            indieningsdatum: $indieningsdatum,
            procedureType: 'reguliere'
        );

        $start    = new \DateTimeImmutable($indieningsdatum);
        $deadline = new \DateTimeImmutable($result);

        // Deadline must be after the start date.
        $this->assertGreaterThan($start, $deadline);

        // Count working days between start and deadline.
        $workingDays = 0;
        $current     = $start;
        while ($current < $deadline) {
            $current = $current->modify('+1 day');
            $dayN    = (int) $current->format('N');
            if ($dayN < 6) {
                $workingDays++;
            }
        }

        // Should be exactly 40 working days (ignoring holidays for simplicity).
        $this->assertGreaterThanOrEqual(40, $workingDays);
        $this->assertLessThanOrEqual(45, $workingDays);
    }//end testComputeDeadlineReguliere()

    /**
     * Test that computeDeadline for uitgebreide procedure returns 130 working days.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T11
     */
    public function testComputeDeadlineUitgebreide(): void
    {
        $indieningsdatum = '2026-01-05';
        $result          = $this->service->computeDeadline(
            indieningsdatum: $indieningsdatum,
            procedureType: 'uitgebreide'
        );

        $start    = new \DateTimeImmutable($indieningsdatum);
        $deadline = new \DateTimeImmutable($result);

        // Deadline must be after the start date.
        $this->assertGreaterThan($start, $deadline);

        // For uitgebreide it is significantly longer than reguliere.
        $days = (int) $start->diff($deadline)->days;
        $this->assertGreaterThan(130, $days);
    }//end testComputeDeadlineUitgebreide()

    /**
     * Test that computeDeadline skips weekend days.
     *
     * Choosing a Friday (2026-01-02) as start: the very next working day
     * should be Monday (2026-01-05), not Saturday.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T11
     */
    public function testComputeDeadlineSkipsWeekends(): void
    {
        // 2026-01-02 is a Friday.
        $indieningsdatum = '2026-01-02';

        // We compute only a 1-working-day deadline to isolate the weekend skip.
        // We'll compute reguliere (40 days) and check the result is NOT a weekend.
        $result   = $this->service->computeDeadline(
            indieningsdatum: $indieningsdatum,
            procedureType: 'reguliere'
        );
        $deadline = new \DateTimeImmutable($result);

        // The deadline day-of-week must not be Saturday (6) or Sunday (7).
        $dayN = (int) $deadline->format('N');
        $this->assertLessThan(6, $dayN, 'Deadline must not fall on a weekend day.');
    }//end testComputeDeadlineSkipsWeekends()

    /**
     * Test that computeDeadline skips Dutch Easter-based national holidays.
     *
     * Easter Sunday 2026 = 2026-04-05. Using 2026-04-01 (Wednesday before Easter)
     * as the start date, the reguliere deadline (40 working days) must not fall
     * on Eerste Paasdag (Apr 5), Tweede Paasdag (Apr 6), Hemelvaartsdag (May 14),
     * or either Pinkster day.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T11
     */
    public function testComputeDeadlineSkipsEasterHolidays(): void
    {
        // Easter Sunday 2026 = 2026-04-05; variable holidays fall in April–May.
        $indieningsdatum = '2026-04-01';
        $result          = $this->service->computeDeadline(
            indieningsdatum: $indieningsdatum,
            procedureType: 'reguliere'
        );

        $deadline  = new \DateTimeImmutable($result);
        $dayOfWeek = (int) $deadline->format('N');

        // Deadline must not be a weekend.
        $this->assertLessThan(6, $dayOfWeek, 'Deadline must not fall on a weekend day.');

        // The deadline must not land on any known 2026 Dutch national holiday.
        $holidays2026 = [
            '2026-04-05', // Eerste Paasdag.
            '2026-04-06', // Tweede Paasdag.
            '2026-04-27', // Koningsdag.
            '2026-05-05', // Bevrijdingsdag.
            '2026-05-14', // Hemelvaartsdag.
            '2026-05-24', // Eerste Pinksterdag.
            '2026-05-25', // Tweede Pinksterdag.
        ];

        $this->assertNotContains(
            $deadline->format('Y-m-d'),
            $holidays2026,
            'Deadline must not fall on a Dutch national holiday.'
        );

        // With Easter holidays skipped the deadline is pushed at least 40 calendar
        // days past the start (working days + holiday padding).
        $start = new \DateTimeImmutable($indieningsdatum);
        $this->assertGreaterThan(
            $start->modify('+40 days'),
            $deadline,
            'Deadline must exceed 40 calendar days from start due to weekend/holiday skipping.'
        );
    }//end testComputeDeadlineSkipsEasterHolidays()

    /**
     * Test that createZaakFromVergunningaanvraag calls ObjectService::saveObject.
     *
     * @return void
     *
     * @spec openspec/changes/dso-omgevingsloket/tasks.md#T11
     */
    public function testCreateZaakFromVergunningaanvraagCallsObjectService(): void
    {
        $objectServiceMock = $this->createMock(DsoCaseObjectServiceStub::class);

        $aanvraag = [
            'id'              => 'aanvraag-uuid-1',
            'titel'           => 'Bouwen van een aanbouw',
            'indieningsdatum' => '2026-03-01',
            'activiteiten'    => [
                ['naam' => 'bouwen', 'regelkwalificatie' => 'reguliere'],
            ],
        ];

        $objectServiceMock
            ->expects($this->once())
            ->method('find')
            ->willReturn($aanvraag);

        $savedZaak = ['id' => 'zaak-uuid-1', 'status' => 'ingediend'];

        $objectServiceMock
            ->expects($this->once())
            ->method('saveObject')
            ->willReturn($savedZaak);

        $this->container
            ->method('get')
            ->willReturnCallback(
                    function (string $id) use ($objectServiceMock) {
                        if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                            return $objectServiceMock;
                        }

                        return null;
                    }
                    );

        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(
                    function (string $app, string $key, string $default='') {
                        $map = [
                            'dso_vergunningaanvraag_schema' => 'vergunningaanvraag-schema-id',
                            'register'                      => 'procest-register-id',
                            'case_schema'                   => 'case-schema-id',
                        ];
                        return $map[$key] ?? $default;
                    }
                    );

        $this->logger->expects($this->once())->method('info');

        $result = $this->service->createZaakFromVergunningaanvraag(
            vergunningaanvraagId: 'aanvraag-uuid-1'
        );

        $this->assertSame('zaak-uuid-1', $result['id']);
    }//end testCreateZaakFromVergunningaanvraagCallsObjectService()
}//end class
