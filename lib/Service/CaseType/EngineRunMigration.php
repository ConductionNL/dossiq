<?php

/**
 * The one seam between a dossiq rebind and OpenRegister's flow run.
 *
 * 🔴 A REBIND THAT MOVED THE CASE AND LEFT THE RUN WHERE IT WAS, REPORTING
 * PLAIN SUCCESS, IS THE HALF-WRITE THIS FILE EXISTS TO PREVENT. The case's
 * process is an engine flow, and the run in flight is pinned to the flow
 * definition version it was queued against. Moving one is
 * `migrate-run-between-versions`, register row 3.16, which is specified in
 * openregister and not yet shipped: measured 2026-09-18 against that repo's
 * `lib/Service/Flow`, there is no migration class there at all.
 *
 * So this seam answers three states and never two. ASKED AND MIGRATED is the
 * happy path once openregister ships. ASKED AND REFUSED stops the whole
 * rebind, with the engine's own sentence carried out to the caller, because a
 * case whose run refused to move is a case whose blueprint must not move
 * either. NOT ASKED is today: the capability is absent, every answer says so
 * in the same words {@see \OCA\Dossiq\Service\CaseType\CaseVersionMove} uses
 * for the same gap, and no answer pretends the run travelled.
 *
 * The lookup is duck-typed by string, like every other OpenRegister seam in
 * this app, because openregister is an optional runtime dependency and its
 * classes cannot be type-hinted. That fails SILENTLY when wrong, which is why
 * {@see reasonFor()} tells "openregister is not installed" apart from "the
 * class is not there": the second is a rename or a capability that moved, and
 * it is logged at ERROR rather than passed off as a configuration.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ask the engine to move a case's run onto another definition.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
class EngineRunMigration {

	/**
	 * OpenRegister's run migration service, by name.
	 *
	 * The class `migrate-run-between-versions` ships. Until it does,
	 * `class_exists` answers false and every rebind says the run stayed.
	 *
	 * @var string
	 */
	public const MIGRATION_SERVICE = 'OCA\OpenRegister\Service\Flow\FlowRunMigrationService';

	/**
	 * The method that moves one subject's run onto another definition.
	 *
	 * Named as a constant because it is checked with `method_exists` before it
	 * is called: a service that exists with a different verb on it is a rename,
	 * and calling into it blindly is how a rebind reports a migration that
	 * never happened.
	 *
	 * @var string
	 */
	public const MIGRATION_METHOD = 'migrateRunForSubject';

	/**
	 * What every answer says while openregister has not shipped the migration.
	 *
	 * The same sentence {@see CaseVersionMove::RUN_NOT_MOVED} carries, because
	 * it is the same gap in the same register row, and two wordings for one
	 * fact is how a reader concludes they are two facts.
	 *
	 * @var string
	 */
	public const RUN_NOT_MOVED = 'The case moves to the new case type. A flow run already in progress stays on the '
		. 'definition version it started under, until openregister ships migrate-run-between-versions.';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settings  The dossiq settings seam.
	 * @param ContainerInterface $container The DI container, for the optional service.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Move this case's run onto the target case type's flow definition.
	 *
	 * @param string $caseId           The case whose run moves.
	 * @param string $targetCaseTypeId The case type it is being rebound to.
	 * @param string $actorUid         The coordinator performing the rebind, recorded as `runAs` (ADR-099).
	 *
	 * @return array{asked: bool, migrated: bool, reason: string} What the engine did, or why it was not asked.
	 *
	 * @throws RefusedException When the engine was asked and refused.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function migrate(string $caseId, string $targetCaseTypeId, string $actorUid): array {
		$service = $this->resolve();
		if ($service === null) {
			return ['asked' => false, 'migrated' => false, 'reason' => self::RUN_NOT_MOVED];
		}

		try {
			$outcome = $service->{self::MIGRATION_METHOD}($caseId, $targetCaseTypeId, $actorUid);
		} catch (Throwable $e) {
			// THE ENGINE'S OWN SENTENCE TRAVELS. A rebind refused by the run is
			// refused for a reason the coordinator can act on, and replacing it
			// here with "the rebind failed" is how "this run is suspended
			// awaiting an advice" becomes a mystery.
			throw new RefusedException(
				rule: 'engine-refused-the-run-migration',
				sentence: $e->getMessage(),
				status: RefusedException::STATUS_UNPROCESSABLE,
				previous: $e,
			);
		}

		if ($this->migrated(outcome: $outcome) === false) {
			throw new RefusedException(
				rule: 'engine-refused-the-run-migration',
				sentence: $this->reasonOf(outcome: $outcome),
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return ['asked' => true, 'migrated' => true, 'reason' => $this->reasonOf(outcome: $outcome)];
	}//end migrate()

	/**
	 * Why the engine was not asked, or '' when it was reachable.
	 *
	 * Three states rather than one, for the reason
	 * {@see \OCA\Dossiq\Service\Task\EngineTaskGateway::unavailableReason()}
	 * keeps three: an instance without openregister is not a defect, and a
	 * missing class on an instance that HAS openregister is a rename that would
	 * otherwise be invisible.
	 *
	 * @return string The reason, or '' when the migration can be asked for.
	 *
	 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
	 */
	public function reasonFor(): string {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return 'openregister is not installed or not enabled';
		}

		if (class_exists(self::MIGRATION_SERVICE) === false) {
			return 'openregister has not shipped migrate-run-between-versions yet';
		}

		if ($this->resolve() === null) {
			return 'the container could not construct the run migration service';
		}

		return '';
	}//end reasonFor()

	/**
	 * The migration service, or null when it cannot be reached.
	 *
	 * @return object|null The service.
	 */
	private function resolve(): ?object {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return null;
		}

		if (class_exists(self::MIGRATION_SERVICE) === false) {
			return null;
		}

		try {
			$service = $this->container->get(self::MIGRATION_SERVICE);
		} catch (Throwable $e) {
			$this->logger->error(
				'EngineRunMigration: openregister has ' . self::MIGRATION_SERVICE
					. ' but the container could not construct it, so no run is being migrated',
				['exception' => $e->getMessage()]
			);

			return null;
		}

		if (method_exists($service, self::MIGRATION_METHOD) === false) {
			// A RENAME, NOT A CONFIGURATION. The class is there and the verb is
			// not, which means the capability moved and every rebind from here
			// on would silently leave its run behind.
			$this->logger->error(
				'EngineRunMigration: ' . self::MIGRATION_SERVICE . ' has no ' . self::MIGRATION_METHOD
					. '(): the run migration verb moved and dossiq rebinds are NOT migrating runs'
			);

			return null;
		}

		return $service;
	}//end resolve()

	/**
	 * Whether the engine says it moved the run.
	 *
	 * @param mixed $outcome Whatever the engine answered.
	 *
	 * @return boolean True when it moved.
	 */
	private function migrated(mixed $outcome): bool {
		if (is_bool($outcome) === true) {
			return $outcome;
		}

		if (is_array($outcome) === true) {
			return (($outcome['migrated'] ?? false) === true);
		}

		// An object answer with nothing to read is not a success. A migration
		// that cannot say it happened is one nobody can check afterwards.
		return false;
	}//end migrated()

	/**
	 * The engine's sentence about what it did, or a plain one.
	 *
	 * @param mixed $outcome Whatever the engine answered.
	 *
	 * @return string The sentence.
	 */
	private function reasonOf(mixed $outcome): string {
		if (is_array($outcome) === true && trim((string)($outcome['reason'] ?? '')) !== '') {
			return trim((string)$outcome['reason']);
		}

		if ($this->migrated(outcome: $outcome) === true) {
			return 'The flow run moved to the target case type\'s definition.';
		}

		return 'The engine refused to move this case\'s flow run, and gave no reason.';
	}//end reasonOf()
}//end class
