<?php

/**
 * How lodging a remedy fails: no OpenRegister, no decision schema, a case that
 * cannot be opened, a withdrawal that fails, and an undated or termless remedy.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Remedy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Remedy;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\CaseRemedy;
use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\Remedy\RemedyLodging;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The failure and edge paths of a lodging.
 *
 * @covers \OCA\Dossiq\Service\Remedy\RemedyLodging
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class RemedyLodgingFailuresTest extends TestCase {
	use MakesCaseDateNormaliser;

	private BezwaarCreationHook $hook;

	private LoggerInterface $logger;

	private function lodging(?object $objects, array $config = [], int $termDays = 42): RemedyLodging {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config + ['register' => 'dossiq', 'case_schema' => 'case', 'decision_schema' => 'decision'])[$key] ?? $default
		);

		$remedy = $this->createMock(CaseRemedy::class);
		$remedy->method('declarationOf')->willReturn(['kind' => 'bezwaar', 'termDays' => $termDays, 'body' => 'het college', 'enabled' => true, 'caseType' => 'bezwaar-type']);

		$this->hook   = $this->createMock(BezwaarCreationHook::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new RemedyLodging(settingsService: $settings, caseRemedy: $remedy, objections: $this->hook, logger: $this->logger, dates: $this->caseDates());
	}

	private function objects(): RemedyObjectServiceDouble {
		$objects            = new RemedyObjectServiceDouble();
		$objects->decisions = [['id' => 'decision-1', 'case' => 'woo-1', 'decisionDate' => '2026-10-30', 'publicationDate' => '2026-11-02']];

		return $objects;
	}

	public function testWithoutOpenRegisterNothingIsLodged(): void {
		$this->expectExceptionObject(new RuntimeException('OpenRegister is not available'));

		$this->lodging(null)->lodge('woo-1', ['receivedChannel' => 'brief', 'grounds' => 'x']);
	}

	public function testWithoutADecisionSchemaThereIsNoDecisionToObjectTo(): void {
		$objects = $this->objects();

		try {
			$this->lodging($objects, ['decision_schema' => ''])->lodge('woo-1', ['receivedChannel' => 'brief', 'grounds' => 'x']);
			$this->fail('lodged without a decision');
		} catch (RefusedException $e) {
			$this->assertSame('remedy-no-decision', $e->getRule());
		}

		$this->assertSame([], $objects->saved);
	}

	public function testACaseThatCannotBeOpenedIsAnError(): void {
		$objects = new class extends RemedyObjectServiceDouble {
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, mixed $uuid = null): array {
				$this->saved[] = [(string)$schema, $object];
				return [];
			}
		};
		$objects->decisions = $this->objects()->decisions;
		$lodging            = $this->lodging($objects);
		$this->hook->expects($this->never())->method('onBezwaarCreated');

		$this->expectExceptionObject(new RuntimeException('The remedy case could not be opened'));
		$lodging->lodge('woo-1', ['receivedDate' => '2026-12-01', 'receivedChannel' => 'brief', 'grounds' => 'x']);
	}

	public function testAWithdrawalThatFailsIsLoggedAndTheObjectionErrorStands(): void {
		$objects = new class extends RemedyObjectServiceDouble {
			public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null): bool {
				throw new RuntimeException('locked');
			}
		};
		$objects->decisions = $this->objects()->decisions;
		$lodging            = $this->lodging($objects);
		$this->hook->method('onBezwaarCreated')->willThrowException(new RuntimeException('objection refused'));
		$this->logger->expects($this->once())->method('error')->with(
			'A remedy case whose objection failed could not be deleted again',
			['app' => 'dossiq', 'caseId' => 'bezwaar-1', 'error' => 'locked']
		);

		$this->expectExceptionObject(new RuntimeException('objection refused'));
		$lodging->lodge('woo-1', ['receivedDate' => '2026-12-01', 'receivedChannel' => 'brief', 'grounds' => 'x']);
	}

	public function testARemedyWithoutATermIsAlwaysTimely(): void {
		$lodging = $this->lodging($this->objects(), termDays: 0);
		$this->hook->expects($this->once())->method('onBezwaarCreated')
			->with('bezwaar-1', 'decision-1', $this->callback(static fn (array $p): bool => $p['isTimely'] === true))
			->willReturn(['id' => 'objection-1']);

		$this->assertTrue($lodging->lodge('woo-1', ['receivedDate' => '2030-01-01', 'receivedChannel' => 'brief', 'grounds' => 'x'])['isTimely']);
	}
}
