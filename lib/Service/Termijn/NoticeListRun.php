<?php

/**
 * Dossiq NoticeListRun.
 *
 * One listing of the notices of default, built by `NoticeOfDefaultRows::list()`
 * and never handed out: the object service and register it reads, and the
 * penalty calculations and payouts it names each notice's money from.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/specs/termijn-reporting/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * What one notices listing reads with.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
final class NoticeListRun {
	/**
	 * The penalty calculations, by the notice each started from.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $penalties = [];

	/**
	 * The payouts, by the calculation they pay.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $payouts = [];

	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register id.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
	) {
	}//end __construct()
}//end class
