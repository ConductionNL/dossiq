<?php

/**
 * The configurations and targets OutcomeCascade refuses before it writes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/linked-case-with-a-role/specs/linked-case-with-a-role/spec.md#requirement-an-outcome-cascade-runs-its-configured-steps-req-lcr-5
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Relation;

use InvalidArgumentException;
use OCA\Dossiq\Service\Relation\OutcomeCascade;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * No steps, no target schema, an unconfigured schema, no OpenRegister, a
 * missing target and a step that is not an object are each refused, and
 * none of them writes.
 *
 * @covers \OCA\Dossiq\Service\Relation\OutcomeCascade
 * @uses   \OCA\Dossiq\Service\Relation\CascadeRun
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class OutcomeCascadeRefusalTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A cascade service over the given cascades and schema map.
	 *
	 * @param array<string, mixed>  $cascades  The configured cascades.
	 * @param array<string, string> $schemas   Config key => schema.
	 * @param bool                  $available Whether OpenRegister is there.
	 *
	 * @return OutcomeCascade The service.
	 */
	private function cascade(array $cascades, array $schemas = ['charge_schema' => 'charge'], bool $available = true): OutcomeCascade {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'charge', uuid: 'ch-1', row: ['case' => 'case-1', 'status' => 'open']);
		$config = ['register' => 'dossiq', OutcomeCascade::CASCADES_KEY => (string)json_encode($cascades)] + $schemas;

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($available === true ? $this->store : null);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
		$transitions = $this->createMock(StatusTransitionService::class);
		$transitions->expects($this->never())->method('execute');

		return new OutcomeCascade(settingsService: $settings, transitions: $transitions);

	}//end cascade()

	/**
	 * The exception a run throws.
	 *
	 * @param OutcomeCascade $cascade The service.
	 * @param string         $name    The cascade.
	 * @param string         $target  The target uuid.
	 *
	 * @return \Throwable The refusal.
	 */
	private function refusal(OutcomeCascade $cascade, string $name, string $target = 'ch-1'): \Throwable {
		try {
			$cascade->run(name: $name, targetId: $target);
		} catch (\Throwable $e) {
			return $e;
		}

		self::fail("Cascade '$name' ran where it should have been refused.");

	}//end refusal()

	/**
	 * The charge as stored, to prove nothing was written.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function charge(): array {
		return $this->store->row(schema: 'charge', uuid: 'ch-1');

	}//end charge()

	/**
	 * A cascade without steps, or whose steps are not a list, is refused.
	 *
	 * @return void
	 */
	public function testACascadeWithoutStepsIsRefused(): void {
		$cascade = $this->cascade(
			cascades: [
				'empty'   => ['target' => 'charge_schema', 'steps' => []],
				'missing' => ['target' => 'charge_schema'],
				'scalar'  => ['target' => 'charge_schema', 'steps' => 'set status'],
			]
		);

		foreach (['empty', 'missing', 'scalar'] as $name) {
			$e = $this->refusal(cascade: $cascade, name: $name);
			self::assertInstanceOf(InvalidArgumentException::class, $e);
			self::assertSame("Cascade '$name' has no steps.", $e->getMessage());
		}

		self::assertSame('open', $this->charge()['status']);
	}//end testACascadeWithoutStepsIsRefused()

	/**
	 * A cascade that names no target schema, or a blank or non-text one, is refused.
	 *
	 * @return void
	 */
	public function testACascadeWithoutATargetIsRefused(): void {
		$cascade = $this->cascade(
			cascades: [
				'none'  => ['steps' => [['set' => ['status' => 'x']]]],
				'blank' => ['target' => '  ', 'steps' => [['set' => ['status' => 'x']]]],
				'list'  => ['target' => ['charge_schema'], 'steps' => [['set' => ['status' => 'x']]]],
			]
		);

		foreach (['none', 'blank', 'list'] as $name) {
			$e = $this->refusal(cascade: $cascade, name: $name);
			self::assertInstanceOf(InvalidArgumentException::class, $e);
			self::assertSame("Cascade '$name' names no target schema.", $e->getMessage());
		}
	}//end testACascadeWithoutATargetIsRefused()

	/**
	 * A target schema key that is not configured is a runtime refusal.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredTargetSchemaIsRefused(): void {
		$e = $this->refusal(
			cascade: $this->cascade(cascades: ['hold' => ['target' => 'fine_schema', 'steps' => [['set' => ['status' => 'held']]]]]),
			name: 'hold'
		);

		self::assertInstanceOf(RuntimeException::class, $e);
		self::assertSame("The schema 'fine_schema' is not configured.", $e->getMessage());
		self::assertSame('open', $this->charge()['status']);
	}//end testAnUnconfiguredTargetSchemaIsRefused()

	/**
	 * Without OpenRegister a run is a runtime refusal.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterARunIsRefused(): void {
		$e = $this->refusal(
			cascade: $this->cascade(cascades: ['hold' => ['target' => 'charge_schema', 'steps' => [['set' => ['status' => 'held']]]]], available: false),
			name: 'hold'
		);

		self::assertInstanceOf(RuntimeException::class, $e);
		self::assertSame('OpenRegister is not available', $e->getMessage());
		self::assertSame('open', $this->charge()['status']);
	}//end testWithoutOpenRegisterARunIsRefused()

	/**
	 * A target that does not exist is refused.
	 *
	 * @return void
	 */
	public function testAMissingTargetIsRefused(): void {
		$e = $this->refusal(
			cascade: $this->cascade(cascades: ['hold' => ['target' => 'charge_schema', 'steps' => [['set' => ['status' => 'held']]]]]),
			name: 'hold',
			target: 'ch-gone'
		);

		self::assertInstanceOf(InvalidArgumentException::class, $e);
		self::assertSame('The cascade target was not found.', $e->getMessage());
	}//end testAMissingTargetIsRefused()

	/**
	 * A step that is not an object is of no known kind; the steps before it ran.
	 *
	 * @return void
	 */
	public function testAStepThatIsNotAnObjectIsRefused(): void {
		$e = $this->refusal(
			cascade: $this->cascade(cascades: ['hold' => ['target' => 'charge_schema', 'steps' => [['set' => ['status' => 'held']], 'transition']]]),
			name: 'hold'
		);

		self::assertInstanceOf(InvalidArgumentException::class, $e);
		self::assertSame('A cascade step must be set, setDependents or transition.', $e->getMessage());
		self::assertSame('held', $this->charge()['status']);
	}//end testAStepThatIsNotAnObjectIsRefused()
}//end class
