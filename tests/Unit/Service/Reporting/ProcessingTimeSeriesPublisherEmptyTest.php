<?php

/**
 * Locks what the series publisher does with a report that has no chart data.
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A report without chart rows publishes nothing and never reaches the leaf.
 *
 * @spec openspec/specs/sla-charts-via-analytics-leaf/spec.md
 */
#[CoversClass(ProcessingTimeSeriesPublisher::class)]
class ProcessingTimeSeriesPublisherEmptyTest extends TestCase {

	/**
	 * An empty report does not look up the series service or log.
	 *
	 * @return void
	 */
	public function testEmptyReportPublishesNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$publisher = new ProcessingTimeSeriesPublisher(
			container: $container,
			l10n: $this->createMock(IL10N::class),
			logger: $logger,
		);

		$publisher->publish(report: ['sla' => [], 'distribution' => [], 'period' => ['preset' => '12m']]);

		self::assertSame([], $publisher->seriesOf(report: ['sla' => [], 'trend' => []]));
	}//end testEmptyReportPublishesNothing()
}//end class
