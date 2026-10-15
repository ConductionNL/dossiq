<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-dossiq-ships-an-initial-prompt-library-and-stops-owning-it-req-aic-06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Controller\AssistantController;
use OCA\Dossiq\Repair\ShipAssistantPrompts;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The initial prompt library goes to hermiq once, under the scope the case
 * surface reads back, and dossiq restores nothing an administrator touched.
 *
 * @covers \OCA\Dossiq\Repair\ShipAssistantPrompts
 * @uses   \OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity
 */
class ShipAssistantPromptsTest extends TestCase {

	/**
	 * A stand-in for hermiq's library that records what it was handed.
	 *
	 * @return object The fake library.
	 */
	private function library(): object {
		return new class {
			/**
			 * The calls made.
			 *
			 * @var array<int, array{0: string, 1: array<int, array<string, mixed>>}>
			 */
			public array $calls = [];

			/**
			 * Record one seed and answer as hermiq would.
			 *
			 * @param string                           $appId   The shipping app.
			 * @param array<int, array<string, mixed>> $prompts The prompts.
			 *
			 * @return array{installed: int, skipped: int}
			 */
			public function seed(string $appId, array $prompts): array {
				$this->calls[] = [$appId, $prompts];
				return ['installed' => count($prompts) - 1, 'skipped' => 1];
			}
		};
	}//end library()

	/**
	 * Build the step.
	 *
	 * @param boolean     $installed Whether hermiq is installed.
	 * @param mixed       $library   What the container answers, or a Throwable to throw.
	 * @param string|null $file      The library file.
	 *
	 * @return ShipAssistantPrompts
	 */
	private function step(bool $installed, mixed $library, ?string $file=null): ShipAssistantPrompts {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('hermiq')->willReturn($installed);

		$container = $this->createMock(ContainerInterface::class);
		if ($library instanceof \Throwable) {
			$container->method('get')->willThrowException($library);
		} else {
			$container->method('get')->with(ShipAssistantPrompts::LIBRARY_CLASS)->willReturn($library);
		}

		return new ShipAssistantPrompts($container, $apps, null, $file);
	}//end step()

	/**
	 * The shipped prompts reach hermiq as dossiq's, under the case scope.
	 *
	 * @return void
	 */
	public function testTheShippedLibraryReachesHermiqUnderTheCaseScope(): void {
		$library = $this->library();
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('kept as an administrator left them'));

		$this->step(true, $library)->run($output);

		self::assertCount(1, $library->calls);
		[$appId, $prompts] = $library->calls[0];
		self::assertSame('dossiq', $appId);
		self::assertNotEmpty($prompts);
		foreach ($prompts as $prompt) {
			self::assertSame(AssistantController::CASE_PROMPT_SCOPE, $prompt['usageScope'], 'the case surface reads this scope back');
			self::assertNotSame('', trim((string)$prompt['prompt']));
		}
	}//end testTheShippedLibraryReachesHermiqUnderTheCaseScope()

	/**
	 * Without hermiq nothing is asked of the container.
	 *
	 * @return void
	 */
	public function testWithoutHermiqNothingIsShipped(): void {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn(false);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');

		(new ShipAssistantPrompts($container, $apps))->run($this->createMock(IOutput::class));
	}//end testWithoutHermiqNothingIsShipped()

	/**
	 * A hermiq without the library, or with one that cannot seed, warns and stops.
	 *
	 * @return void
	 */
	public function testAHermiqWithoutASeedableLibraryWarnsAndStops(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))->method('warning');

		$this->step(true, new RuntimeException('no such service'))->run($output);
		$this->step(true, new \stdClass())->run($output);
	}//end testAHermiqWithoutASeedableLibraryWarnsAndStops()

	/**
	 * An unreadable or malformed library file ships nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableLibraryShipsNothing(): void {
		$library = $this->library();
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		$this->step(true, $library, '/nonexistent/assistant-prompts.json')->run($output);

		self::assertSame([], $library->calls);

		$file = tempnam(sys_get_temp_dir(), 'prompts');
		file_put_contents($file, json_encode(['usageScope' => 's', 'prompts' => [['label' => 'x', 'prompt' => ' '], 'bad']]));
		self::assertSame([], $this->step(true, $library, $file)->shippedPrompts());
		unlink($file);
	}//end testAnUnreadableLibraryShipsNothing()
}//end class
