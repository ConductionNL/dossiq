<?php

/**
 * Dossiq bezwaar archive trigger: what happens when the bezwaartermijn ends.
 *
 * A beschikking gets a `bezwaarTrigger` when it is announced. When its
 * objection term has run out, the beschikking is archived, unless an
 * objection came in, and either way the trigger is switched off. This is
 * the domain act BezwaarTermijnJob performed once a day for every active
 * trigger; the WHEN is now an armed engine timer
 * ({@see BezwaarArchiveTimer}), and this class keeps the WHAT, so the
 * transition still runs through BeschikkingService's state machine.
 *
 * The trigger is read FRESH before acting: an objection may have been
 * registered after the timer was armed, and the timer carries no copy of it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Service\BeschikkingService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Archives a beschikking whose objection term ran out, and switches the trigger off.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class BezwaarArchiveTrigger {
	use SearchesObjects;

	/**
	 * The config key naming the bezwaarTrigger schema.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'bezwaar_trigger_schema';

	/**
	 * Outcome: the beschikking was archived and the trigger switched off.
	 *
	 * @var string
	 */
	public const ARCHIVED = 'archived';

	/**
	 * Outcome: an objection came in; the trigger is switched off, nothing archived.
	 *
	 * @var string
	 */
	public const OBJECTED = 'objected';

	/**
	 * Outcome: nothing to do (no trigger, switched off already, no beschikking).
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Outcome: the archive was refused; the trigger stays on for a retry.
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * Build the service.
	 *
	 * @param SettingsService    $settingsService Reaches OpenRegister and the schema.
	 * @param BeschikkingService $decisions       Archives the beschikking through its state machine.
	 * @param LoggerInterface    $logger          Logs a refused archive.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly BeschikkingService $decisions,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read one trigger fresh.
	 *
	 * @param string $triggerId The trigger id.
	 *
	 * @return array<string, mixed>|null The trigger, or null when it does not exist or nothing is configured.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function find(string $triggerId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue(self::SCHEMA_CONFIG_KEY);
		if ($objectService === null || $register === '' || $schema === '' || $triggerId === '') {
			return null;
		}

		// A trigger that does not exist reads as null; any other failure is
		// thrown to the caller, which logs it with the fire it belongs to.
		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $triggerId);
	}//end find()

	/**
	 * Act on a trigger whose objection term has ended.
	 *
	 * @param array<string, mixed> $trigger The trigger, as read fresh.
	 *
	 * @return string One of the outcome constants.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
	 */
	public function process(array $trigger): string {
		$decisionId = (string) ($trigger['decisionId'] ?? '');
		if ($decisionId === '' || ($trigger['archiveTriggerActive'] ?? false) !== true) {
			return self::SKIPPED;
		}

		if (($trigger['objectionReceived'] ?? false) === true) {
			$this->deactivate(trigger: $trigger);
			return self::OBJECTED;
		}

		try {
			$this->decisions->archive($decisionId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq bezwaar: archiving the beschikking failed',
				['decisionId' => $decisionId, 'error' => $e->getMessage()]
			);
			return self::FAILED;
		}

		$this->deactivate(trigger: $trigger);
		return self::ARCHIVED;
	}//end process()

	/**
	 * Switch the trigger off.
	 *
	 * @param array<string, mixed> $trigger The trigger.
	 *
	 * @return void
	 */
	private function deactivate(array $trigger): void {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue(self::SCHEMA_CONFIG_KEY);
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		$trigger['archiveTriggerActive'] = false;

		try {
			$objectService->saveObject(register: $register, schema: $schema, object: $trigger);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq bezwaar: switching the trigger off failed', ['error' => $e->getMessage()]);
		}
	}//end deactivate()
}//end class
