<?php

/**
 * Finds one of integriq's registry reads, and reads why an answer is unavailable.
 *
 * The BAG object read and the person relation read both ask integriq
 * through the same seam: only when integriq is installed, and only when it
 * ships the read they need. Each names its own read and its own reason for
 * a missing one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Registry;

use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;

/**
 * Integriq's registry reads, as one seam.
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
 */
final class IntegriqLeaf {
	/**
	 * Why nothing was asked: integriq is not installed.
	 *
	 * @var string
	 */
	public const REASON_NOT_INSTALLED = 'integriq-not-installed';

	/**
	 * Why an unavailable answer was unavailable when it does not say.
	 *
	 * @var string
	 */
	public const REASON_UNREACHABLE = 'unreachable';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container  Resolves integriq's read.
	 * @param IAppManager        $appManager Detects whether integriq is installed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * Integriq's read, or the reason it cannot be asked.
	 *
	 * @param string $relative The read's class, relative to integriq's namespace.
	 * @param string $missing  The reason when integriq is installed without that read.
	 *
	 * @return object|string The read, or the reason.
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
	 */
	public function readerOr(string $relative, string $missing): object|string {
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			return self::REASON_NOT_INSTALLED;
		}

		$reader = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: $relative);
		if ($reader === null || method_exists($reader, 'read') === false) {
			return $missing;
		}

		return $reader;
	}//end readerOr()

	/**
	 * The reason an unavailable answer gives, or `unreachable` when it gives none.
	 *
	 * @param array<string, mixed> $answer Integriq's unavailable answer.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
	 */
	public static function unavailableReason(array $answer): string {
		if (is_string($answer['reason'] ?? null) === true && $answer['reason'] !== '') {
			return $answer['reason'];
		}

		return self::REASON_UNREACHABLE;
	}//end unavailableReason()
}//end class
