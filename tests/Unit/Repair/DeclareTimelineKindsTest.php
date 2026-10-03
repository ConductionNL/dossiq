<?php

/**
 * DeclareTimelineKinds Unit Tests.
 *
 * The step runs on every upgrade and on install. The two things that must
 * hold are that it declares everything dossiq writes, and that it cannot take
 * the install down: it is registered under `<install>`, where a throwing step
 * aborts the install and takes every route in the app with it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\DeclareTimelineKinds;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for OpenRegister's TimelineKindService.
 */
class FakeKindService {

	/**
	 * Every declaration handed in, keyed by slug so a second declaration of
	 * the same name overwrites rather than appends, exactly as the real
	 * upsert does.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $kinds = [];

	/**
	 * Slugs this service refuses.
	 *
	 * @var array<int, string>
	 */
	public array $refuses = [];

	/**
	 * Declare a kind.
	 *
	 * @param array<string, mixed> $data The declaration.
	 *
	 * @return array<string, mixed> The stored declaration.
	 */
	public function declareKind(array $data): array {
		$slug = (string)$data['slug'];
		if (in_array($slug, $this->refuses, true) === true) {
			throw new RuntimeException('refused: ' . $slug);
		}

		$this->kinds[$slug] = $data;

		return $data;
	}//end declareKind()
}//end class

/**
 * A stand-in for OpenRegister's TextBlockService.
 */
class FakeBlockService {

	/**
	 * Every block handed in, keyed by slug.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $blocks = [];

	/**
	 * Declare a block.
	 *
	 * @param array<string, string> $data The block.
	 *
	 * @return array<string, string> The stored block.
	 */
	public function declareBlock(array $data): array {
		$this->blocks[(string)$data['slug']] = $data;

		return $data;
	}//end declareBlock()
}//end class

/**
 * Declaring the kinds, twice, and on an instance that has nowhere to put them.
 *
 * @covers \OCA\Dossiq\Repair\DeclareTimelineKinds
 */
class DeclareTimelineKindsTest extends TestCase {

	/**
	 * The stand-in kind service.
	 *
	 * @var FakeKindService
	 */
	private FakeKindService $kinds;

	/**
	 * The stand-in block service.
	 *
	 * @var FakeBlockService
	 */
	private FakeBlockService $blocks;

	/**
	 * Lines the step wrote to the repair output.
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * Set up the stand-ins.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->kinds = new FakeKindService();
		$this->blocks = new FakeBlockService();
		$this->lines = [];
	}//end setUp()

	/**
	 * Build the step.
	 *
	 * @param boolean $resolves Whether the container answers for the services.
	 *
	 * @return DeclareTimelineKinds The step under test.
	 */
	private function step(bool $resolves = true): DeclareTimelineKinds {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $name) use ($resolves): object {
				if ($resolves === false) {
					throw new RuntimeException('not found: ' . $name);
				}

				if ($name === DeclareTimelineKinds::BLOCK_SERVICE) {
					return $this->blocks;
				}

				return $this->kinds;
			}
		);

		return new DeclareTimelineKinds(
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * A repair output that remembers what it was told.
	 *
	 * @return IOutput The output.
	 */
	private function repairOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->lines[] = $message;
			}
		);

		return $output;
	}//end repairOutput()

	/**
	 * Every declared kind reaches the instance.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testEveryKindIsDeclared(): void {
		$this->step()->run($this->repairOutput());

		$this->assertSame(
			array_column(TimelineKinds::DECLARATIONS, 'slug'),
			array_keys($this->kinds->kinds)
		);
		$this->assertArrayHasKey(TimelineKinds::CONTACTMOMENT, $this->kinds->kinds);
		$this->assertSame(
			['channel', 'direction'],
			$this->kinds->kinds[TimelineKinds::CONTACTMOMENT]['required']
		);
	}//end testEveryKindIsDeclared()

	/**
	 * The standard notes are seeded alongside the kinds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testTheStandardNotesAreSeeded(): void {
		$this->step()->run($this->repairOutput());

		$this->assertSame(
			array_column(TimelineKinds::TEXT_BLOCKS, 'slug'),
			array_keys($this->blocks->blocks)
		);
	}//end testTheStandardNotesAreSeeded()

	/**
	 * Running twice rewrites the declarations in place and makes no second
	 * copy of any of them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testRunningTwiceChangesNothing(): void {
		$step = $this->step();
		$step->run($this->repairOutput());
		$first = $this->kinds->kinds;

		$step->run($this->repairOutput());

		$this->assertSame($first, $this->kinds->kinds);
		$this->assertCount(count(TimelineKinds::DECLARATIONS), $this->kinds->kinds);
	}//end testRunningTwiceChangesNothing()

	/**
	 * An OpenRegister without the timeline is a line of output, not a failed
	 * install.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testAnOpenRegisterWithoutTheTimelineDoesNotThrow(): void {
		$this->step(resolves: false)->run($this->repairOutput());

		$this->assertSame([], $this->kinds->kinds);
		$this->assertCount(1, $this->lines);
		$this->assertStringContainsString('none were declared', $this->lines[0]);
	}//end testAnOpenRegisterWithoutTheTimelineDoesNotThrow()

	/**
	 * One refused declaration does not stop the other six.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testOneRefusedKindDoesNotStopTheRest(): void {
		$this->kinds->refuses = [TimelineKinds::MAIL_IN];

		$this->step()->run($this->repairOutput());

		$this->assertArrayNotHasKey(TimelineKinds::MAIL_IN, $this->kinds->kinds);
		$this->assertCount(count(TimelineKinds::DECLARATIONS) - 1, $this->kinds->kinds);
		$this->assertStringContainsString(
			sprintf('%d timeline kind(s)', count(TimelineKinds::DECLARATIONS) - 1),
			$this->lines[0]
		);
	}//end testOneRefusedKindDoesNotStopTheRest()

	/**
	 * The step names itself in words an admin reads while it runs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function testTheStepNamesItself(): void {
		$this->assertStringContainsString('timeline', $this->step()->getName());
	}//end testTheStepNamesItself()
}//end class
