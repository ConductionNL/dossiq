<?php

/**
 * How many times a planned series has fired.
 *
 * Split out of {@see \OCA\Dossiq\Service\Flow\PlannedSeriesLedger}. The count
 * lives in this app's config keyed by the flow uuid, beside the last firing
 * already counted, because a published flow's graph cannot be written back to
 * (see the ledger's header).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 * @spec openspec/specs/workflow-definition-engine/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use DateTimeInterface;
use OCA\Dossiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Counts one firing of a series once.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class SeriesFiringCount {

	/**
	 * Config-key prefix for how many occurrences a series has created.
	 *
	 * @var string
	 */
	public const FIRED_KEY = 'planned_series_fired_';

	/**
	 * Config-key prefix for the last firing this app has already counted.
	 *
	 * The sweep runs hourly and a flow's `lastRunAt` does not change between
	 * firings, so counting on every pass would spend a yearly series in a day.
	 * The stamp is what makes one firing one increment.
	 *
	 * @var string
	 */
	public const SEEN_KEY = 'planned_series_seen_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Remembers how often a series has fired.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * How many times a planned flow has fired, counting this firing once.
	 *
	 * @param string $flowId    The flow uuid.
	 * @param mixed  $lastRunAt The flow's last-run stamp.
	 *
	 * @return integer The count including the firing just observed.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function count(string $flowId, mixed $lastRunAt): int {
		$stamp = '';
		if ($lastRunAt instanceof DateTimeInterface === true) {
			$stamp = $lastRunAt->format('Y-m-d H:i:s');
		}

		$seen = $this->appConfig->getValueString(AppIdentity::APP_ID, (self::SEEN_KEY . $flowId), '');
		$fired = (int)$this->appConfig->getValueString(AppIdentity::APP_ID, (self::FIRED_KEY . $flowId), '0');
		if ($seen === $stamp) {
			return $fired;
		}

		$fired++;
		$this->appConfig->setValueString(AppIdentity::APP_ID, (self::SEEN_KEY . $flowId), $stamp);
		$this->appConfig->setValueString(AppIdentity::APP_ID, (self::FIRED_KEY . $flowId), (string)$fired);

		return $fired;
	}//end count()
}//end class
