<?php

/**
 * DeclareTimelineKinds edge Unit Tests.
 *
 * The paths the main test leaves alone: an OpenRegister that carries the kind
 * service but not the text block service, a block the service refuses, and a
 * container that answers with something that is not a service at all.
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
 * @spec openspec/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Kernel\Contracts\TimelineKindsInterface;
use OCA\Dossiq\Repair\DeclareTimelineKinds;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Edge paths of declaring the kinds and seeding the notes.
 *
 * @covers \OCA\Dossiq\Repair\DeclareTimelineKinds
 */
class DeclareTimelineKindsEdgesTest extends TestCase {

	/**
	 * Lines the step wrote to the repair output.
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * A kind service that accepts every declaration.
	 *
	 * @return object The service.
	 */
	private function kindService(): object {
		return new class {
			/**
			 * Declared slugs.
			 *
			 * @var array<int, string>
			 */
			public array $slugs = [];

			/**
			 * Declare a kind.
			 *
			 * @param array<string, mixed> $data The declaration.
			 *
			 * @return array<string, mixed> The declaration.
			 */
			public function declareKind(array $data): array {
				$this->slugs[] = (string)$data['slug'];
				return $data;
			}//end declareKind()
		};
	}//end kindService()

	/**
	 * A repair output that remembers what it was told.
	 *
	 * @return IOutput The output.
	 */
	private function repairOutput(): IOutput {
		$this->lines = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->lines[] = $message;
			}
		);

		return $output;
	}//end repairOutput()

	/**
	 * Build the step over a container answering from a map.
	 *
	 * @param array<string, mixed> $services What the container returns per name;
	 *                                       a missing name throws.
	 * @param LoggerInterface|null $logger   The logger, a stub when null.
	 *
	 * @return DeclareTimelineKinds The step under test.
	 */
	private function step(array $services, ?LoggerInterface $logger = null): DeclareTimelineKinds {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($services) {
				if (array_key_exists($name, $services) === false) {
					throw new RuntimeException('not found: ' . $name);
				}

				return $services[$name];
			}
		);

		return new DeclareTimelineKinds(
			container: $container,
			logger: $logger ?? $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * Kinds are declared even when the instance has no text block service,
	 * and the summary reports zero notes rather than failing.
	 *
	 * @return void
	 */
	public function testAMissingBlockServiceSeedsNoNotesButStillDeclaresTheKinds(): void {
		$kinds = $this->kindService();

		$this->step([DeclareTimelineKinds::KIND_SERVICE => $kinds])->run($this->repairOutput());

		$this->assertSame(array_column(TimelineKindsInterface::DECLARATIONS, 'slug'), $kinds->slugs);
		$this->assertSame(
			[
				sprintf(
					'Declared %d timeline kind(s) and 0 standard note(s).',
					count(TimelineKindsInterface::DECLARATIONS)
				),
			],
			$this->lines
		);
	}//end testAMissingBlockServiceSeedsNoNotesButStillDeclaresTheKinds()

	/**
	 * A refused block is logged with its slug, is left out of the count, and
	 * does not stop the blocks after it.
	 *
	 * @return void
	 */
	public function testARefusedBlockIsLoggedAndTheRestAreSeeded(): void {
		$refused = TimelineKindsInterface::TEXT_BLOCKS[0]['slug'];
		$blocks = new class($refused) {
			/**
			 * Seeded slugs.
			 *
			 * @var array<int, string>
			 */
			public array $slugs = [];

			/**
			 * Constructor.
			 *
			 * @param string $refused The slug this service refuses.
			 */
			public function __construct(private readonly string $refused) {
			}//end __construct()

			/**
			 * Declare a block.
			 *
			 * @param array<string, string> $data The block.
			 *
			 * @return array<string, string> The block.
			 */
			public function declareBlock(array $data): array {
				if ($data['slug'] === $this->refused) {
					throw new RuntimeException('duplicate title');
				}

				$this->slugs[] = $data['slug'];
				return $data;
			}//end declareBlock()
		};

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq timeline: text block {block} was not seeded, {reason}',
			[
				'app' => 'dossiq',
				'block' => $refused,
				'reason' => 'duplicate title',
			]
		);

		$this->step(
			[
				DeclareTimelineKinds::KIND_SERVICE => $this->kindService(),
				DeclareTimelineKinds::BLOCK_SERVICE => $blocks,
			],
			$logger
		)->run($this->repairOutput());

		$expected = array_values(
			array_filter(
				array_column(TimelineKindsInterface::TEXT_BLOCKS, 'slug'),
				static fn (string $slug): bool => $slug !== $refused
			)
		);
		$this->assertSame($expected, $blocks->slugs);
		$this->assertStringEndsWith(
			sprintf('and %d standard note(s).', count(TimelineKindsInterface::TEXT_BLOCKS) - 1),
			$this->lines[0]
		);
	}//end testARefusedBlockIsLoggedAndTheRestAreSeeded()

	/**
	 * A container that answers with a non-object is treated as an instance
	 * without the timeline: nothing is declared and the step says so.
	 *
	 * @return void
	 */
	public function testANonObjectServiceCountsAsAbsent(): void {
		$this->step(
			[
				DeclareTimelineKinds::KIND_SERVICE => 'not a service',
				DeclareTimelineKinds::BLOCK_SERVICE => 'not a service',
			]
		)->run($this->repairOutput());

		$this->assertSame(
			['OpenRegister does not carry timeline kinds on this instance, so none were declared.'],
			$this->lines
		);
	}//end testANonObjectServiceCountsAsAbsent()
}//end class
