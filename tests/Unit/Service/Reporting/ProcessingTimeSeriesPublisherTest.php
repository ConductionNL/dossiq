<?php

/**
 * Locks the series the processing-time report hands to the analytics leaf.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use OCA\Dossiq\Service\Reporting\ProcessingTimeSeriesPublisher;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The four chart series keep their stored keys and their shapes.
 *
 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
 */
class ProcessingTimeSeriesPublisherTest extends TestCase {

	/**
	 * A report with every chart block filled.
	 *
	 * @return array<string, mixed>
	 */
	private function report(): array {
		return [
			'sla'          => [['name' => 'Permit', 'withinSla' => 3], ['name' => 'Objection', 'withinSla' => 1]],
			'distribution' => [['label' => '0-7', 'count' => 2], ['label' => '8-14', 'count' => 0]],
			'trend'        => [['month' => '2026-05', 'rate' => null], ['month' => '2026-06', 'rate' => 50]],
			'throughput'   => [['weekLabel' => 'W23 2026', 'count' => 4]],
		];
	}//end report()

	/**
	 * The publisher with a container answering the given service.
	 *
	 * @param object|null          $service The series service, or null for an absent leaf.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return ProcessingTimeSeriesPublisher
	 */
	private function publisher(?object $service, ?LoggerInterface $logger = null): ProcessingTimeSeriesPublisher {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new RuntimeException('no such service'));
		} else {
			$container->method('get')->willReturn($service);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return new ProcessingTimeSeriesPublisher(
			container: $container,
			l10n: $l10n,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end publisher()

	/**
	 * The series carry their `dossiq-sla-` names.
	 *
	 * @return void
	 */
	public function testSeriesCarryTheirGenericKeys(): void {
		$series = $this->publisher(service: null)->seriesOf(report: $this->report());

		self::assertSame(
			[
				'dossiq-sla-compliance-by-type',
				'dossiq-sla-processing-time-distribution',
				'dossiq-sla-monthly-trend',
				'dossiq-sla-weekly-throughput',
			],
			array_column($series, 'seriesKey')
		);
	}//end testSeriesCarryTheirGenericKeys()

	/**
	 * The donut is a flat list; the others are named datasets.
	 *
	 * @return void
	 */
	public function testSeriesShapes(): void {
		$series = $this->publisher(service: null)->seriesOf(report: $this->report());

		self::assertSame(['Permit', 'Objection'], $series[0]['labels']);
		self::assertSame([3, 1], $series[0]['datasets']);
		self::assertSame('doughnut', $series[0]['chartType']);
		self::assertSame([['name' => 'Cases', 'data' => [2, 0]]], $series[1]['datasets']);
		self::assertSame([['name' => 'SLA Compliance %', 'data' => [null, 50]]], $series[2]['datasets']);
		self::assertSame(['W23 2026'], $series[3]['labels']);
	}//end testSeriesShapes()

	/**
	 * Empty blocks publish nothing.
	 *
	 * @return void
	 */
	public function testEmptyBlocksPublishNothing(): void {
		self::assertSame([], $this->publisher(service: null)->seriesOf(report: ['sla' => [], 'distribution' => []]));
	}//end testEmptyBlocksPublishNothing()

	/**
	 * Every series reaches the leaf's register call.
	 *
	 * @return void
	 */
	public function testPublishRegistersEachSeries(): void {
		$service = new class {
			/**
			 * The keys registered.
			 *
			 * @var string[]
			 */
			public array $keys = [];

			/**
			 * Record a registration.
			 *
			 * @param string $seriesKey The key.
			 * @param mixed  ...$rest   The other arguments.
			 *
			 * @return array<string, mixed>
			 */
			public function register(string $seriesKey, mixed ...$rest): array {
				$this->keys[] = $seriesKey;
				return $rest;
			}//end register()
		};

		$this->publisher(service: $service)->publish(report: $this->report());

		self::assertSame(
			[
				'dossiq-sla-compliance-by-type',
				'procest-sla-compliance-by-type',
				'dossiq-sla-processing-time-distribution',
				'procest-sla-processing-time-distribution',
				'dossiq-sla-monthly-trend',
				'procest-sla-monthly-trend',
				'dossiq-sla-weekly-throughput',
				'procest-sla-weekly-throughput',
			],
			$service->keys,
			'Each series is published under its new key and, for one release, its old one.'
		);
	}//end testPublishRegistersEachSeries()

	/**
	 * An absent leaf is logged and never thrown into the report.
	 *
	 * @return void
	 */
	public function testAbsentLeafIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning');

		$this->publisher(service: null, logger: $logger)->publish(report: $this->report());
	}//end testAbsentLeafIsLogged()
}//end class
