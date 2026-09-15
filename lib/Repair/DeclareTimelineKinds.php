<?php

/**
 * Dossiq Declare Timeline Kinds Repair Step.
 *
 * Puts dossiq's timeline kinds and its standard notes on the instance, once,
 * so that every writer in this app can name a kind and have it accepted.
 *
 * WHY A REPAIR STEP AND NOT A CALL AT WRITE TIME. OpenRegister refuses an
 * undeclared kind with a 400 rather than degrading it to a plain note, which
 * is the right refusal: it makes a typo loud. That refusal only works if the
 * declaration is already there, and declaring it lazily on first write would
 * mean the very first contact moment after an upgrade decides whether the
 * kind exists, on whichever request happened to be first.
 *
 * IT IS IDEMPOTENT. `declareKind()` and `declareBlock()` both rewrite the
 * declaration that already carries the name, so running this on every upgrade
 * updates the seven kinds in place and never makes an eighth.
 *
 * IT NEVER THROWS. It is registered under `<install>` as well as
 * `<post-migration>`, and a step that throws during install aborts the
 * install and takes every route in the app with it. An instance whose
 * OpenRegister predates the timeline has no such service; that is a line in
 * the output, not a failed upgrade.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Declares the kinds dossiq writes and seeds the standard notes.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
class DeclareTimelineKinds implements IRepairStep {

	/**
	 * OpenRegister's kind service, named as a string so this app never imports
	 * a class an older OpenRegister does not ship.
	 *
	 * @var string
	 */
	public const KIND_SERVICE = 'OCA\\OpenRegister\\Service\\Timeline\\TimelineKindService';

	/**
	 * OpenRegister's text block service, named the same way.
	 *
	 * @var string
	 */
	public const BLOCK_SERVICE = 'OCA\\OpenRegister\\Service\\Timeline\\TextBlockService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The DI container, for the lazy resolve.
	 * @param LoggerInterface    $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The name shown while the step runs.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function getName(): string {
		return 'Declare the case timeline kinds and the standard notes';
	}//end getName()

	/**
	 * Declare every kind, then seed every standard note.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function run(IOutput $output): void {
		$kinds = $this->declareKinds();
		if ($kinds === null) {
			$output->info('OpenRegister does not carry timeline kinds on this instance, so none were declared.');
			return;
		}

		$blocks = $this->seedBlocks();

		$output->info(
			sprintf(
				'Declared %d timeline kind(s) and %d standard note(s).',
				$kinds,
				$blocks,
			)
		);
	}//end run()

	/**
	 * Declare the kinds.
	 *
	 * @return integer|null How many were declared, or null when the service is absent.
	 */
	private function declareKinds(): ?int {
		$service = $this->resolve(name: self::KIND_SERVICE);
		if ($service === null) {
			return null;
		}

		$declared = 0;
		foreach (TimelineKinds::DECLARATIONS as $declaration) {
			try {
				$service->declareKind($declaration);
				$declared++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq timeline: kind {kind} was not declared, {reason}',
					[
						'app' => Application::APP_ID,
						'kind' => $declaration['slug'],
						'reason' => $e->getMessage(),
					],
				);
			}
		}

		return $declared;
	}//end declareKinds()

	/**
	 * Seed the standard notes.
	 *
	 * @return integer How many were seeded.
	 */
	private function seedBlocks(): int {
		$service = $this->resolve(name: self::BLOCK_SERVICE);
		if ($service === null) {
			return 0;
		}

		$seeded = 0;
		foreach (TimelineKinds::TEXT_BLOCKS as $block) {
			try {
				$service->declareBlock($block);
				$seeded++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq timeline: text block {block} was not seeded, {reason}',
					[
						'app' => Application::APP_ID,
						'block' => $block['slug'],
						'reason' => $e->getMessage(),
					],
				);
			}
		}

		return $seeded;
	}//end seedBlocks()

	/**
	 * Resolve one optional OpenRegister service.
	 *
	 * @param string $name The fully qualified class name.
	 *
	 * @return object|null The service, or null when this instance has none.
	 */
	private function resolve(string $name): ?object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end resolve()
}//end class
