<?php

/**
 * The sources a personal queue is built from.
 *
 * This list IS the queue. A mechanism that wants to reach a person adds itself
 * here and implements {@see QueueSource}; no page changes. The architecture
 * test reads this same constant, so a mechanism that asks a person for
 * something and is missing from it fails the build rather than quietly
 * reaching nobody.
 *
 * A source that will not construct is reported as unavailable, not skipped.
 * Skipping it would let an instance with a broken dependency show a shorter
 * queue that looks exactly like a quiet day, which is the ADR-102 failure this
 * whole change is written against.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCA\Dossiq\Service\Queue\Source\ApprovalSource;
use OCA\Dossiq\Service\Queue\Source\AssignedCasesSource;
use OCA\Dossiq\Service\Queue\Source\ConsultationSource;
use OCA\Dossiq\Service\Queue\Source\CoordinatorSeatSource;
use OCA\Dossiq\Service\Queue\Source\CoveredWorkSource;
use OCA\Dossiq\Service\Queue\Source\EngineTaskSource;
use OCA\Dossiq\Service\Queue\Source\MentionSource;
use OCA\Dossiq\Service\Queue\Source\OpenIncidentSource;
use OCA\Dossiq\Service\Queue\Source\PlannedActionSource;
use OCA\Dossiq\Service\Queue\Source\PlannedItemSource;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The declared queue sources, resolved.
 *
 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
 */
class QueueSourceCatalogue {
	/**
	 * Every mechanism that may put something on a person's queue.
	 *
	 * @var array<int, class-string<QueueSource>>
	 */
	public const SOURCES = [
		AssignedCasesSource::class,
		CoordinatorSeatSource::class,
		EngineTaskSource::class,
		ConsultationSource::class,
		ApprovalSource::class,
		MentionSource::class,
		CoveredWorkSource::class,
		PlannedItemSource::class,
		PlannedActionSource::class,
		OpenIncidentSource::class,
	];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The declared source classes.
	 *
	 * @return array<int, string> The class names.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function declared(): array {
		return self::SOURCES;
	}//end declared()

	/**
	 * Resolve every declared source.
	 *
	 * @return array{sources: array<int, QueueSource>, unavailable: array<int, array<string, string>>}
	 *         The sources that resolved, and one entry per source that did not.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function resolve(): array {
		$sources = [];
		$unavailable = [];

		foreach (self::SOURCES as $class) {
			try {
				$source = $this->container->get($class);
			} catch (Throwable $e) {
				$unavailable[] = [
					'source' => $class,
					'label' => $class,
					'reason' => $e->getMessage(),
				];
				continue;
			}

			if (($source instanceof QueueSource) === false) {
				$unavailable[] = [
					'source' => $class,
					'label' => $class,
					'reason' => 'This source does not implement the queue source contract.',
				];
				continue;
			}

			$sources[] = $source;
		}

		return ['sources' => $sources, 'unavailable' => $unavailable];
	}//end resolve()
}//end class
