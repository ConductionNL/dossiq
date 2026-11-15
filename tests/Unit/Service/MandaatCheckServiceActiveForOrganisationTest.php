<?php

/**
 * MandaatCheckService::hasActiveMandateFor unit tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md#requirement-the-overview-reads-decisions-from-the-mandate-register
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Whether an organisation holds a mandate active on a date.
 *
 * @covers \OCA\Dossiq\Service\MandaatCheckService
 */
class MandaatCheckServiceActiveForOrganisationTest extends TestCase {
	/**
	 * The calls the fake object service received.
	 *
	 * @var array<int, array<int|string, mixed>>
	 */
	private array $calls = [];

	/**
	 * The rows the fake object service returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	private bool $fail = false;

	private LoggerInterface $logger;

	/**
	 * Build the service over a fake object service.
	 *
	 * @param array<string, string> $config The config values.
	 *
	 * @return MandaatCheckService The service.
	 */
	private function service(array $config = ['register' => 'dossiq', 'mandaat_schema' => 'mandate']): MandaatCheckService {
		$test = $this;
		$objects = new class($test) {
			public function __construct(private readonly MandaatCheckServiceActiveForOrganisationTest $test) {
			}

			public function searchObjectsBySlug(string $register, string $schema, array $filters = [], ...$scope): array {
				return $this->test->answer(args: [$register, $schema, $filters, $scope]);
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		$this->logger = $this->createMock(LoggerInterface::class);
		return new MandaatCheckService($settings, $this->logger);
	}

	/**
	 * Record a call and answer it.
	 *
	 * @param array<int, mixed> $args The call's arguments.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function answer(array $args): array {
		$this->calls[] = $args;
		if ($this->fail === true) {
			throw new RuntimeException('register down');
		}

		return $this->rows;
	}

	public function testAnActiveMandateOfTheOrganisationCounts(): void {
		$this->rows = [
			['status' => 'active', 'validFrom' => '2026-01-01', '@self' => ['organisation' => 'org-other']],
			['status' => 'active', 'validFrom' => '2026-01-01', 'validUntil' => '2026-12-31', '@self' => ['organisation' => 'org-1']],
		];

		$this->assertTrue($this->service()->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01')));
		$this->assertSame(
			[['dossiq', 'mandate', ['status' => 'active'], ['_rbac' => false, '_multitenancy' => false]]],
			$this->calls
		);
	}

	public function testAMandateOfAnotherOrganisationDoesNotCount(): void {
		$this->rows = [['status' => 'active', 'validFrom' => '2026-01-01', '@self' => ['organisation' => 'org-other']]];

		$this->assertFalse($this->service()->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01')));
	}

	public function testAMandateOutsideItsWindowDoesNotCount(): void {
		$this->rows = [
			['status' => 'active', 'validFrom' => '2026-07-01', '@self' => ['organisation' => 'org-1']],
			['status' => 'active', 'validFrom' => '2026-01-01', 'validUntil' => '2026-05-31', '@self' => ['organisation' => 'org-1']],
		];

		$this->assertFalse($this->service()->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01')));
	}

	public function testTheLastDayOfTheWindowStillCounts(): void {
		$this->rows = [['status' => 'active', 'validFrom' => '2026-01-01', 'validUntil' => '2026-06-01', '@self' => ['organisation' => 'org-1']]];

		$this->assertTrue($this->service()->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01')));
	}

	public function testANonActiveRowDoesNotCountEvenWhenReturned(): void {
		$this->rows = [['status' => 'lapsed', 'validFrom' => '2026-01-01', '@self' => ['organisation' => 'org-1']]];

		$this->assertFalse($this->service()->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01')));
	}

	public function testTheOrganisationIsReadFromTheRowOrAnObjectReference(): void {
		$this->rows = [['status' => 'active', 'organisation' => 'org-1']];
		$this->assertTrue($this->service()->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01')));

		$this->rows = [['status' => 'active', '@self' => ['organisation' => ['uuid' => 'org-2']]]];
		$this->assertTrue($this->service()->hasActiveMandateFor('org-2', new DateTimeImmutable('2026-06-01')));

		$this->rows = [['status' => 'active', '@self' => ['organisation' => ['id' => 'org-3']]]];
		$this->assertTrue($this->service()->hasActiveMandateFor('org-3', new DateTimeImmutable('2026-06-01')));

		$this->rows = [['status' => 'active', '@self' => ['organisation' => new \stdClass()]]];
		$this->assertFalse($this->service()->hasActiveMandateFor('org-3', new DateTimeImmutable('2026-06-01')));
	}

	public function testABlankOrganisationAsksNothing(): void {
		$this->rows = [['status' => 'active', 'organisation' => '']];

		$this->assertFalse($this->service()->hasActiveMandateFor('  ', new DateTimeImmutable('2026-06-01')));
		$this->assertSame([], $this->calls);
	}

	public function testWithoutAMandateSchemaTheAnswerIsNo(): void {
		$this->assertFalse(
			$this->service(config: ['register' => 'dossiq'])->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01'))
		);
		$this->assertSame([], $this->calls);
	}

	public function testAnUnreadableRegisterAnswersNoAndIsLogged(): void {
		$this->fail = true;
		$service = $this->service();
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Dossiq MandaatCheckService: mandate register could not be read for an organisation',
				['organisation' => 'org-1', 'exception' => 'register down']
			);

		$this->assertFalse($service->hasActiveMandateFor('org-1', new DateTimeImmutable('2026-06-01')));
	}
}
