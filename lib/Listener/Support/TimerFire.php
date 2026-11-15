<?php

/**
 * What every listener on an OpenRegister flow timer fire asks first.
 *
 * A fire is this app's only when the timer's app is `dossiq` and its metadata
 * names the listener's own source; most listeners act only on a breach rung;
 * and every one of them writes as the background service account, because the
 * fire comes from a cron job with nobody signed in. Those three answers live
 * here once.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use Throwable;

/**
 * Reads a timer fire and runs its work as the background service account.
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
final class TimerFire {

	/**
	 * The fire's metadata when the timer is this app's and from the given source.
	 *
	 * @param FlowTimerFiredEvent $event  The fire.
	 * @param string              $source The listener's own metadata source.
	 *
	 * @return array<string, mixed>|null The metadata, or null for another app's or another source's timer.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public static function metadataFrom(FlowTimerFiredEvent $event, string $source): ?array {
		$timer    = $event->getTimer();
		$metadata = ($timer->getMetadata() ?? []);
		if ((string) $timer->getAppId() !== 'dossiq'
			|| (string) ($metadata['source'] ?? '') !== $source
		) {
			return null;
		}

		return $metadata;
	}//end metadataFrom()

	/**
	 * Whether the fire is a breach rung.
	 *
	 * @param FlowTimerFiredEvent $event The fire.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public static function isBreach(FlowTimerFiredEvent $event): bool {
		return str_starts_with((string) $event->getRungKey(), 'slaBreached:');
	}//end isBreach()

	/**
	 * Run the fire's work as the service account when nobody is signed in.
	 *
	 * Without a usable account nothing was written, and that is already logged
	 * and told to the admins, so it is not a failure to report again.
	 *
	 * @param BackgroundServiceAccount $account   The account a cron-driven fire writes as.
	 * @param callable                 $operation The work.
	 *
	 * @return Throwable|null The failure for the listener to log, or null.
	 *
	 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
	 */
	public static function runAsAccount(BackgroundServiceAccount $account, callable $operation): ?Throwable {
		try {
			$account->runAsWhenNobodyIsSignedIn(operation: $operation);
		} catch (ServiceAccountUnavailableException $e) {
			return null;
		} catch (Throwable $e) {
			return $e;
		}

		return null;
	}//end runAsAccount()
}//end class
