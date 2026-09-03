<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Parafeer
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Parafeer;

use OCA\Dossiq\Service\Parafeer\ParafeerrouteDirectory;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the sign-off route lookup.
 *
 * This was inline in the retired parafering activate(), which carried on
 * with an EMPTY step list when it found nothing — parking the voorstel in
 * `in_parafering` at step 1 with nothing to travel. Pulling it out makes "no
 * route" a value the caller has to handle, so these tests are mostly about the
 * empty cases being reported rather than papered over.
 */
class ParafeerrouteDirectoryTest extends TestCase {

	/**
	 * Rows the fake register returns.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * Whether the fake register throws on read.
	 *
	 * @var boolean
	 */
	private bool $throws = false;

	/**
	 * Build the directory.
	 *
	 * @param boolean $withStore  Whether OpenRegister is available.
	 * @param boolean $configured Whether register/schema resolve.
	 *
	 * @return ParafeerrouteDirectory The directory.
	 */
	private function directory(bool $withStore = true, bool $configured = true): ParafeerrouteDirectory {
		$rows = &$this->rows;
		$throws = &$this->throws;

		$objectService = new class($rows, $throws) {
			/**
			 * @param array<int, array<string, mixed>> $rows   Rows.
			 * @param boolean                          $throws Whether to throw.
			 */
			public function __construct(private array &$rows, private bool &$throws) {
			}

			/**
			 * @param string               $register The register slug.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($this->throws === true) {
					throw new RuntimeException('register unavailable');
				}

				return $this->rows;
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($withStore === true ? $objectService : null);
		$settings->method('getConfigValue')->willReturn($configured === true ? 'configured' : '');

		return new ParafeerrouteDirectory($settings, $this->createMock(LoggerInterface::class));

	}//end directory()

	/**
	 * The default route's steps are returned in order.
	 *
	 * @return void
	 */
	public function testItReturnsTheDefaultRouteSteps(): void {
		$this->rows = [[
			'id' => 'pr-1',
			'name' => 'Collegeadvies',
			'steps' => [
				['order' => 1, 'type' => 'advies', 'actor' => 'a'],
				['order' => 2, 'type' => 'parafering', 'actor' => 'b'],
			],
		]];

		$steps = $this->directory()->stepsForCaseType('ct-1');

		$this->assertCount(2, $steps);
		$this->assertSame([1, 2], array_column($steps, 'order'));

	}//end testItReturnsTheDefaultRouteSteps()

	/**
	 * Steps stored as a JSON string are decoded.
	 *
	 * @return void
	 */
	public function testJsonEncodedStepsAreDecoded(): void {
		$this->rows = [[
			'id' => 'pr-1',
			'steps' => json_encode([['order' => 1, 'actor' => 'a']]),
		]];

		$this->assertCount(1, $this->directory()->stepsForCaseType('ct-1'));

	}//end testJsonEncodedStepsAreDecoded()

	/**
	 * No configured route is an EMPTY list, which the caller must refuse on.
	 *
	 * @return void
	 */
	public function testNoRouteYieldsNoSteps(): void {
		$this->rows = [];

		$this->assertSame([], $this->directory()->stepsForCaseType('ct-1'));
		$this->assertNull($this->directory()->localRoute('ct-1'));

	}//end testNoRouteYieldsNoSteps()

	/**
	 * A route with no steps is an empty list too, not a route.
	 *
	 * @return void
	 */
	public function testARouteWithNoStepsYieldsNoSteps(): void {
		$this->rows = [['id' => 'pr-1', 'name' => 'Leeg', 'steps' => []]];

		$this->assertSame([], $this->directory()->stepsForCaseType('ct-1'));

	}//end testARouteWithNoStepsYieldsNoSteps()

	/**
	 * Unusable stored steps degrade to none rather than to a broken chain.
	 *
	 * @return void
	 */
	public function testUnusableStepsYieldNone(): void {
		$this->rows = [['id' => 'pr-1', 'steps' => 'not json at all']];

		$this->assertSame([], $this->directory()->stepsForCaseType('ct-1'));

	}//end testUnusableStepsYieldNone()

	/**
	 * A non-array entry inside steps is dropped, not passed on.
	 *
	 * @return void
	 */
	public function testNonArrayStepEntriesAreDropped(): void {
		$this->rows = [['id' => 'pr-1', 'steps' => [['order' => 1], 'rubbish', null]]];

		$this->assertCount(1, $this->directory()->stepsForCaseType('ct-1'));

	}//end testNonArrayStepEntriesAreDropped()

	/**
	 * Without OpenRegister the lookup reports nothing rather than throwing.
	 *
	 * The caller refuses on an empty list, so reporting nothing is safe;
	 * throwing here would turn a missing optional dependency into a 500.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItReportsNothing(): void {
		$this->assertNull($this->directory(withStore: false)->localRoute('ct-1'));
		$this->assertSame([], $this->directory(withStore: false)->stepsForCaseType('ct-1'));

	}//end testWithoutOpenRegisterItReportsNothing()

	/**
	 * An unconfigured register/schema reports nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterReportsNothing(): void {
		$this->assertNull($this->directory(configured: false)->localRoute('ct-1'));

	}//end testAnUnconfiguredRegisterReportsNothing()

	/**
	 * A failing read is logged and reported as nothing, not propagated.
	 *
	 * @return void
	 */
	public function testAFailingReadReportsNothing(): void {
		$this->throws = true;

		$this->assertNull($this->directory()->localRoute('ct-1'));

	}//end testAFailingReadReportsNothing()

}//end class
