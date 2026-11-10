<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Flow
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * ShippedFlowAdoption::adoptAll() tests: each answer of the adoption pass, no
 * OpenRegister, no runAs(), no FlowService, no stored flows, and per flow
 * skipped (owned by somebody else, already adopted), adopted (dry run or not,
 * enabled or not) and failed. The pass runs as the named user.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\ShippedFlowAdoption;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ShippedFlowAdoption::adoptAll().
 */
class ShippedFlowAdoptAllTest extends TestCase {
	/**
	 * What the FlowService was asked, in order.
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	/**
	 * The user the pass ran as.
	 *
	 * @var string
	 */
	public string $ranAs = '';

	/**
	 * A stored flow double.
	 *
	 * @param string      $uuid    The uuid.
	 * @param bool        $enabled Whether it is enabled.
	 * @param string|null $owner   Its owner.
	 *
	 * @return object The flow.
	 */
	private static function flow(string $uuid, bool $enabled, ?string $owner): object {
		return new class ($uuid, $enabled, $owner) {
			/**
			 * Constructor.
			 *
			 * @param string      $uuid    The uuid.
			 * @param bool        $enabled Whether it is enabled.
			 * @param string|null $owner   Its owner.
			 */
			public function __construct(private string $uuid, private bool $enabled, private ?string $owner) {
			}//end __construct()

			/** @return string */
			public function getUuid(): string {
				return $this->uuid;
			}//end getUuid()

			/** @return string */
			public function getName(): string {
				return 'Flow ' . $this->uuid;
			}//end getName()

			/** @return bool */
			public function getEnabled(): bool {
				return $this->enabled;
			}//end getEnabled()

			/** @return string|null */
			public function getOwner(): ?string {
				return $this->owner;
			}//end getOwner()
		};
	}//end flow()

	/**
	 * The adoption over a container answering the given mapper and FlowService.
	 *
	 * @param object|null  $objectService The ObjectService, or null.
	 * @param array<int, object> $flows   The stored flows.
	 * @param bool         $withFlowService Whether a FlowService resolves.
	 *
	 * @return ShippedFlowAdoption
	 */
	private function adoption(?object $objectService, array $flows, bool $withFlowService = true): ShippedFlowAdoption {
		$test = $this;
		$mapper = new class ($flows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, object> $flows The flows.
			 */
			public function __construct(private array $flows) {
			}//end __construct()

			/**
			 * @param string $app   The app.
			 * @param int    $limit The page.
			 *
			 * @return array<int, object> The flows.
			 */
			public function findAllFlows(string $app, int $limit): array {
				return $this->flows;
			}//end findAllFlows()
		};
		$flowService = new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param ShippedFlowAdoptAllTest $test Where the calls go.
			 */
			public function __construct(private ShippedFlowAdoptAllTest $test) {
			}//end __construct()

			/**
			 * @param string $uuid The uuid.
			 *
			 * @return string The flow.
			 */
			public function find(string $uuid): string {
				$this->test->calls[] = 'find ' . $uuid;
				if ($uuid === 'broken') {
					throw new RuntimeException('flow store said no');
				}

				return 'flow:' . $uuid;
			}//end find()

			/**
			 * @param string $flow The flow.
			 *
			 * @return void
			 */
			public function adopt(string $flow): void {
				$this->test->calls[] = 'adopt ' . $flow;
			}//end adopt()

			/**
			 * @param array<string, mixed> $changes The changes.
			 * @param string               $uuid    The uuid.
			 *
			 * @return void
			 */
			public function save(array $changes, string $uuid): void {
				$this->test->calls[] = 'save ' . $uuid . ' ' . json_encode($changes);
			}//end save()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($mapper, $flowService, $withFlowService): object {
				if (str_ends_with($id, 'FlowMapper') === true) {
					return $mapper;
				}

				if ($withFlowService === false) {
					throw new RuntimeException('no FlowService');
				}

				return $flowService;
			}
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);

		return new ShippedFlowAdoption($container, $settings, $this->createMock(LoggerInterface::class));
	}//end adoption()

	/**
	 * An ObjectService with runAs(), recording who the pass ran as.
	 *
	 * @return object The ObjectService.
	 */
	private function objectService(): object {
		$test = $this;
		return new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param ShippedFlowAdoptAllTest $test Where the identity goes.
			 */
			public function __construct(private ShippedFlowAdoptAllTest $test) {
			}//end __construct()

			/**
			 * @param IUser    $user The user.
			 * @param callable $work The work.
			 *
			 * @return mixed What the work answered.
			 */
			public function runAs(IUser $user, callable $work): mixed {
				$this->test->ranAs = $user->getUID();
				return $work();
			}//end runAs()
		};
	}//end objectService()

	/**
	 * The administrator adopting.
	 *
	 * @return IUser
	 */
	private function admin(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		return $user;
	}//end admin()

	/**
	 * Without OpenRegister, runAs(), a FlowService or stored flows nothing is adopted, each with its note.
	 *
	 * @return void
	 */
	public function testNothingIsAdoptedWithoutItsPrerequisites(): void {
		self::assertSame(['note' => 'OpenRegister is not available.', 'rows' => []], $this->adoption(null, [])->adoptAll($this->admin(), false, false));
		self::assertSame(
			['note' => 'OpenRegister exposes no runAs(); adoption needs an acting identity.', 'rows' => []],
			$this->adoption(new \stdClass(), [])->adoptAll($this->admin(), false, false)
		);
		self::assertSame(
			['note' => 'OpenRegister exposes no FlowService on this instance.', 'rows' => []],
			$this->adoption($this->objectService(), [], false)->adoptAll($this->admin(), false, false)
		);
		self::assertSame(
			['note' => 'No dossiq flows are stored yet. They are imported when the register is, so run `occ maintenance:repair` first.', 'rows' => []],
			$this->adoption($this->objectService(), [])->adoptAll($this->admin(), false, false)
		);
		self::assertSame([], $this->calls);
	}//end testNothingIsAdoptedWithoutItsPrerequisites()

	/**
	 * Each flow is skipped, adopted or failed, as the named user, and enabled when asked.
	 *
	 * @return void
	 */
	public function testEachFlowIsSkippedAdoptedOrFailed(): void {
		$flows = [
			self::flow('theirs', false, 'jan'),
			self::flow('done', true, 'admin'),
			self::flow('new', false, null),
			self::flow('broken', false, ''),
		];

		$result = $this->adoption($this->objectService(), $flows)->adoptAll($this->admin(), true, false);

		self::assertSame('admin', $this->ranAs);
		self::assertSame(
			[
				'note' => '',
				'rows' => [
					['name' => 'Flow theirs', 'uuid' => 'theirs', 'outcome' => 'skipped', 'detail' => 'already owned by "jan"; adoption is not a takeover'],
					['name' => 'Flow done', 'uuid' => 'done', 'outcome' => 'skipped', 'detail' => 'already adopted'],
					['name' => 'Flow new', 'uuid' => 'new', 'outcome' => 'adopted', 'detail' => 'owner "admin", enabled'],
					['name' => 'Flow broken', 'uuid' => 'broken', 'outcome' => 'failed', 'detail' => 'flow store said no'],
				],
			],
			$result
		);
		self::assertSame(['find new', 'adopt flow:new', 'save new {"enabled":true}', 'find broken'], $this->calls);
	}//end testEachFlowIsSkippedAdoptedOrFailed()

	/**
	 * A dry run writes nothing and says what would happen; without enabling the flow stays disabled.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothingAndAnUnenabledAdoptionSaysSo(): void {
		$flows = [self::flow('new', false, null)];

		$dry = $this->adoption($this->objectService(), $flows)->adoptAll($this->admin(), true, true);
		self::assertSame('would be adopted by "admin" and enabled', $dry['rows'][0]['detail']);
		self::assertSame('adopted', $dry['rows'][0]['outcome']);
		self::assertSame([], $this->calls);

		$plain = $this->adoption($this->objectService(), $flows)->adoptAll($this->admin(), false, false);
		self::assertSame('owner "admin", still disabled', $plain['rows'][0]['detail']);
		self::assertSame(['find new', 'adopt flow:new'], $this->calls);
	}//end testADryRunWritesNothingAndAnUnenabledAdoptionSaysSo()
}//end class
