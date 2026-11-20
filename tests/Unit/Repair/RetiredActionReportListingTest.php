<?php

/**
 * The report of stored automatic actions whose type is retired.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RetiredActionReport;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each stored action of a retired type is named once, by slug, id or uuid,
 * read without RBAC under the system identity; an unreadable type is logged.
 *
 * @covers \OCA\Dossiq\Repair\RetiredActionReport
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
final class RetiredActionReportListingTest extends TestCase {
	public function testEveryActionOfARetiredTypeIsNamedWithItsReason(): void {
		$store = new class {
			/** @var array<int, array> */
			public array $searches = [];

			public bool $asSystem = false;

			public function runAsSystem(callable $operation): mixed {
				$this->asSystem = true;
				return $operation();
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = [$register, $schema, $filters, $_rbac, $_multitenancy];
				if ($filters['type'] === 'broken') {
					throw new RuntimeException('store down');
				}

				return match ($filters['type']) {
					'webhook' => [['slug' => 'notify-crm', 'id' => 'a-1'], ['id' => 'a-2'], ['uuid' => 'u-3'], []],
					default => [],
				};
			}
		};

		$warnings = [];
		$output   = $this->createMock(originalClassName: IOutput::class);
		$output->method('warning')->willReturnCallback(
			static function (string $line) use (&$warnings): void {
				$warnings[] = $line;
			}
		);
		$logged = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);

		(new RetiredActionReport(objectService: $store, register: 'dossiq', schema: 'automaticAction', output: $output, logger: $logger))
			->report(['webhook' => 'replaced by flows', 'script' => 'never shipped', 'broken' => 'gone']);

		$this->assertTrue($store->asSystem);
		$this->assertSame(
			[
				['dossiq', 'automaticAction', ['type' => 'webhook', '_limit' => 1000], false, false],
				['dossiq', 'automaticAction', ['type' => 'script', '_limit' => 1000], false, false],
				['dossiq', 'automaticAction', ['type' => 'broken', '_limit' => 1000], false, false],
			],
			$store->searches
		);
		$this->assertSame(
			[
				'Dossiq: automatic action "notify-crm" has type webhook, which is retired (replaced by flows). It is kept, but it will not run.',
				'Dossiq: automatic action "a-2" has type webhook, which is retired (replaced by flows). It is kept, but it will not run.',
				'Dossiq: automatic action "u-3" has type webhook, which is retired (replaced by flows). It is kept, but it will not run.',
				'Dossiq: automatic action "?" has type webhook, which is retired (replaced by flows). It is kept, but it will not run.',
			],
			$warnings
		);
		$this->assertSame(['app' => 'dossiq', 'action' => 'notify-crm'], $logged[0][1]);
		$this->assertSame(
			['Dossiq: could not read the stored automatic actions of a retired type', ['app' => 'dossiq', 'type' => 'broken', 'exception' => 'store down']],
			$logged[4]
		);
		$this->assertCount(5, $logged);
	}//end testEveryActionOfARetiredTypeIsNamedWithItsReason()
}//end class
