<?php

/**
 * Unit tests for SeriesFiringCount.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Flow\SeriesFiringCount;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * One firing is one increment, kept per flow under this app's id.
 *
 * @covers \OCA\Dossiq\Service\Flow\SeriesFiringCount
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SeriesFiringCountTest extends TestCase {

	/**
	 * What the config holds, by app and key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The count over a config that keeps what is written, under its app id.
	 *
	 * @return SeriesFiringCount The count.
	 */
	private function firingCount(): SeriesFiringCount {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$app.'/'.$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$app.'/'.$key] = $value;
				return true;
			}
		);

		return new SeriesFiringCount(appConfig: $appConfig);
	}//end firingCount()

	/**
	 * A new stamp counts once; the same stamp again counts nothing; flows count apart.
	 *
	 * @return void
	 */
	public function testOneFiringIsOneIncrementPerFlow(): void {
		$count = $this->firingCount();
		$stamp = new DateTimeImmutable('2026-10-15 07:00:00');

		self::assertSame(expected: 1, actual: $count->count(flowId: 'flow-1', lastRunAt: $stamp));
		self::assertSame(expected: 1, actual: $count->count(flowId: 'flow-1', lastRunAt: $stamp));
		self::assertSame(expected: 1, actual: $count->count(flowId: 'flow-2', lastRunAt: $stamp));
		self::assertSame(expected: 2, actual: $count->count(flowId: 'flow-1', lastRunAt: new DateTimeImmutable('2026-11-15 07:00:00')));

		$app = AppIdentity::APP_ID.'/';
		self::assertSame(expected: '2', actual: $this->config[$app.SeriesFiringCount::FIRED_KEY.'flow-1']);
		self::assertSame(expected: '2026-11-15 07:00:00', actual: $this->config[$app.SeriesFiringCount::SEEN_KEY.'flow-1']);
		self::assertSame(expected: '1', actual: $this->config[$app.SeriesFiringCount::FIRED_KEY.'flow-2']);
	}//end testOneFiringIsOneIncrementPerFlow()

	/**
	 * A flow that never ran, or whose stamp is no date, has fired nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testNoStampIsNoFiring(): void {
		$count = $this->firingCount();

		self::assertSame(expected: 0, actual: $count->count(flowId: 'flow-3', lastRunAt: null));
		self::assertSame(expected: 0, actual: $count->count(flowId: 'flow-3', lastRunAt: '2026-10-15 07:00:00'));
		self::assertSame(expected: [], actual: $this->config);
	}//end testNoStampIsNoFiring()
}//end class
