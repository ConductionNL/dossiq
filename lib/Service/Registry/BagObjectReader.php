<?php

/**
 * Dossiq BagObjectReader.
 *
 * Asks integriq whether a BAG object exists.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dossiq holds no BAG client (ADR-118). Integriq reads PDOK's BAG. This class
 * reaches integriq's read by class name at runtime, so dossiq installs and
 * runs without integriq.
 *
 * The answer always says whether the question was answered. "Not available"
 * is never folded into "not found": a guard that decides on the answer must
 * be able to tell an address the BAG does not hold from a read that did not
 * happen.
 *
 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
 */
class BagObjectReader {
	/**
	 * Integriq's BAG object read, below integriq's app namespace.
	 */
	public const INTEGRIQ_READER = 'PropertySource\\Provider\\BagObjectReader';

	/**
	 * Integriq is not installed on this instance.
	 */
	public const REASON_NO_INTEGRIQ = 'integriq-not-installed';

	/**
	 * Integriq is installed, in a version without the BAG object read.
	 */
	public const REASON_NO_READER = 'integriq-has-no-bag-read';

	/**
	 * The read was asked and gave no usable answer.
	 */
	public const REASON_UNREACHABLE = 'unreachable';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container The service container.
	 * @param IAppManager $appManager Tells whether integriq is installed.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the BAG holds an object.
	 *
	 * @param string $type `pand`, `verblijfsobject` or `nummeraanduiding`.
	 * @param string $identificatie The 16-digit BAG identificatie.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool}
	 *
	 * @spec openspec/specs/bag-location-save-validation/spec.md#requirement-a-bag-location-is-checked-through-integriq-where-the-check-is-switched-on
	 */
	public function read(string $type, string $identificatie): array {
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			return self::unanswered(reason: self::REASON_NO_INTEGRIQ);
		}

		$reader = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: self::INTEGRIQ_READER);
		if ($reader === null || method_exists($reader, 'read') === false) {
			return self::unanswered(reason: self::REASON_NO_READER);
		}

		try {
			$answer = $reader->read($type, $identificatie);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the BAG object read failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

			return self::unanswered(reason: self::REASON_UNREACHABLE);
		}

		return self::normalised(answer: $answer);
	}//end read()

	/**
	 * Integriq's answer in the shape this class promises, whatever came back.
	 *
	 * @param mixed $answer What integriq's read returned.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool}
	 */
	private static function normalised(mixed $answer): array {
		if (is_array($answer) === false
			|| is_bool($answer['available'] ?? null) === false
			|| is_bool($answer['found'] ?? null) === false
		) {
			return self::unanswered(reason: self::REASON_UNREACHABLE);
		}

		// An answer that does not say whether it is simulated is treated as one.
		$simulated = (($answer['simulated'] ?? true) !== false);

		if ($answer['available'] === false) {
			$reason = self::REASON_UNREACHABLE;
			if (is_string($answer['reason'] ?? null) === true && $answer['reason'] !== '') {
				$reason = $answer['reason'];
			}

			return ['available' => false, 'reason' => $reason, 'found' => false, 'simulated' => $simulated];
		}

		return ['available' => true, 'reason' => '', 'found' => $answer['found'], 'simulated' => $simulated];
	}//end normalised()

	/**
	 * The answer for a question that was not answered.
	 *
	 * @param string $reason Why not.
	 *
	 * @return array{available:bool,reason:string,found:bool,simulated:bool}
	 */
	private static function unanswered(string $reason): array {
		return ['available' => false, 'reason' => $reason, 'found' => false, 'simulated' => false];
	}//end unanswered()
}//end class
