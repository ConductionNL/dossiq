<?php

/**
 * What the template repair step writes, and everything it leaves alone.
 *
 * THE ASSERTION THAT MATTERS IS THE ONE ABOUT NOT WRITING. OpenRegister's
 * template store is app config on `openregister`, so one text serves every app
 * on the instance. Writing case wording over a template pipelinq is already
 * using would relabel pipelinq's notices with nothing in any log to say so.
 * That failure is invisible in production and cheap to assert here, so it is
 * asserted here.
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
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\DeclareNotificationTemplates;
use OCA\Dossiq\Service\Notification\PlatformEventTemplates;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The gap-filling step: idempotent, and never an overwrite.
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
 */
class DeclareNotificationTemplatesTest extends TestCase {

	/**
	 * A registry double that answers a gap list and records every write.
	 *
	 * An anonymous class rather than a mock: OpenRegister's registry is not a
	 * class this app imports, and a mock that invents the methods it is asked
	 * for can only pass.
	 *
	 * @param array<int, string> $gaps The events with no text.
	 *
	 * @return object The double.
	 */
	private function registry(array $gaps): object {
		return new class($gaps) {
			/**
			 * Every write this double received, keyed by event.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			public array $written = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, string> $gaps The gap list.
			 */
			public function __construct(private array $gaps) {
			}

			/**
			 * The events with no text.
			 *
			 * @return array<int, string> The gaps.
			 */
			public function gaps(): array {
				return array_values($this->gaps);
			}

			/**
			 * Record a write and close the gap, the way the real store does.
			 *
			 * @param string     $event    The event.
			 * @param array|null $template The text.
			 *
			 * @return void
			 */
			public function edit(string $event, ?array $template): void {
				$this->written[$event] = (array)$template;
				$this->gaps = array_values(array_diff($this->gaps, [$event]));
			}
		};
	}//end registry()

	/**
	 * The step, wired to one registry.
	 *
	 * @param object|null $registry The registry, or null when absent.
	 *
	 * @return DeclareNotificationTemplates The step.
	 */
	private function step(?object $registry): DeclareNotificationTemplates {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if ($registry === null) {
			$container->method('get')->willThrowException(
				new class('absent') extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
				}
			);
		} else {
			$container->method('get')->willReturn($registry);
		}

		return new DeclareNotificationTemplates(container: $container, logger: new NullLogger());
	}//end step()

	/**
	 * A gap dossiq can answer is filled with Dutch text.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function testAGapIsFilledWithDutch(): void {
		$registry = $this->registry(gaps: ['object_created']);
		$this->step(registry: $registry)->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(
			expected: ['nl' => PlatformEventTemplates::DUTCH['object_created']],
			actual: ($registry->written['object_created'] ?? null),
			message: 'A reported gap dossiq has wording for must be filled.'
		);
	}//end testAGapIsFilledWithDutch()

	/**
	 * An event the platform does not report as a gap is never written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function testATemplateThatAlreadyHasTextIsNeverTouched(): void {
		// `object_updated` is NOT in the gap list, so it already carries text:
		// either what OpenRegister ships, or an administrator's own edit.
		$registry = $this->registry(gaps: ['object_created']);
		$this->step(registry: $registry)->run($this->createMock(originalClassName: IOutput::class));

		$this->assertArrayNotHasKey(
			key: 'object_updated',
			array: $registry->written,
			message: 'The store serves every app: overwriting existing text relabels another app\'s notices.'
		);
	}//end testATemplateThatAlreadyHasTextIsNeverTouched()

	/**
	 * A second run writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function testTheSecondRunWritesNothing(): void {
		$registry = $this->registry(gaps: ['object_created', 'object_transitioned']);
		$step = $this->step(registry: $registry);

		$step->run($this->createMock(originalClassName: IOutput::class));
		$afterFirst = $registry->written;

		$registry->written = [];
		$step->run($this->createMock(originalClassName: IOutput::class));

		$this->assertCount(expectedCount: 2, haystack: $afterFirst);
		$this->assertSame(
			expected: [],
			actual: $registry->written,
			message: 'A filled gap is no longer a gap, so the second run must write nothing at all.'
		);
	}//end testTheSecondRunWritesNothing()

	/**
	 * A gap dossiq has no wording for is left in the list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function testAGapDossiqCannotAnswerIsLeftAlone(): void {
		$registry = $this->registry(gaps: ['credential_relink_needed']);
		$this->step(registry: $registry)->run($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(
			expected: [],
			actual: $registry->written,
			message: 'Leaving a gap dossiq has nothing to say about keeps it finishable by somebody else.'
		);
		$this->assertSame(
			expected: ['credential_relink_needed'],
			actual: $registry->gaps(),
			message: 'The gap must still be reported, not quietly closed.'
		);
	}//end testAGapDossiqCannotAnswerIsLeftAlone()

	/**
	 * An instance without the registry is a line of output, not a failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function testAnAbsentRegistryDoesNotThrow(): void {
		$output = $this->createMock(originalClassName: IOutput::class);
		$output->expects($this->once())->method('info');

		$this->step(registry: null)->run($output);

		$this->addToAssertionCount(count: 1);
	}//end testAnAbsentRegistryDoesNotThrow()

	/**
	 * Every event dossiq offers wording for is one the platform can raise.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md#requirement-dossiq-fills-the-platforms-dutch-template-gaps-req-urs-07
	 */
	public function testEveryOfferedEventCarriesBothASubjectAndABody(): void {
		$this->assertNotSame(expected: [], actual: PlatformEventTemplates::DUTCH);

		foreach (PlatformEventTemplates::DUTCH as $event => $text) {
			$this->assertNotSame(
				expected: '',
				actual: trim((string)($text['subject'] ?? '')),
				message: sprintf('%s offers no subject, and the store refuses neither.', (string)$event)
			);
			$this->assertNotSame(
				expected: '',
				actual: trim((string)($text['body'] ?? '')),
				message: sprintf('%s offers no body, and the store refuses neither.', (string)$event)
			);
		}
	}//end testEveryOfferedEventCarriesBothASubjectAndABody()
}//end class
