<?php

/**
 * Dossiq PersonRelationReader.
 *
 * Reads who a person's partner, parents and children are, through integriq.
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
 * Dossiq holds no population-register client (ADR-118). Integriq holds the
 * BRP source and decides, per source, whether relation numbers may be asked
 * for at all. This class reaches integriq's read by class name at runtime, so
 * dossiq installs and runs without integriq.
 *
 * The answer always says whether the question was answered. "Not available"
 * and "unanswered" are never folded into an empty list: a caller that decides
 * on the answer must be able to tell a person without relations from a read
 * that did not happen.
 *
 * No number and no part of an answer is logged here.
 *
 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
 */
class PersonRelationReader {
	/**
	 * Integriq's relation read, below integriq's app namespace.
	 */
	public const INTEGRIQ_READER = 'PropertySource\\Provider\\BrpRelationReader';

	/**
	 * Integriq is not installed on this instance.
	 */
	public const REASON_NO_INTEGRIQ = 'integriq-not-installed';

	/**
	 * Integriq is installed, in a version without the relation read.
	 */
	public const REASON_NO_READER = 'integriq-has-no-relation-read';

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
	 * Read the relations of one person.
	 *
	 * @param string $burgerservicenummer The person. Held in memory only.
	 *
	 * @return array{available:bool,reason:string,relations:array<int,array{relation:string,burgerservicenummer:string}>}
	 *
	 * @spec openspec/changes/brp-client-retired/specs/mandaat-matrix/spec.md#requirement-conflict-of-interest-detection
	 */
	public function read(string $burgerservicenummer): array {
		if (FleetAppId::isInstalled(appManager: $this->appManager, canonical: 'integriq') === false) {
			return self::unanswered(reason: self::REASON_NO_INTEGRIQ);
		}

		$reader = FleetAppId::getService(container: $this->container, canonical: 'integriq', relative: self::INTEGRIQ_READER);
		if ($reader === null || method_exists($reader, 'read') === false) {
			return self::unanswered(reason: self::REASON_NO_READER);
		}

		try {
			$answer = $reader->read($burgerservicenummer);
		} catch (Throwable $e) {
			// The message is left out: a registry may echo the number asked for.
			$this->logger->warning('Dossiq: the person relation read failed', ['exception' => $e::class]);

			return self::unanswered(reason: self::REASON_UNREACHABLE);
		}

		return self::normalised(answer: $answer);
	}//end read()

	/**
	 * Integriq's answer in the shape this class promises, whatever came back.
	 *
	 * @param mixed $answer What integriq's read returned.
	 *
	 * @return array{available:bool,reason:string,relations:array<int,array{relation:string,burgerservicenummer:string}>}
	 */
	private static function normalised(mixed $answer): array {
		if (is_array($answer) === false || is_bool($answer['available'] ?? null) === false) {
			return self::unanswered(reason: self::REASON_UNREACHABLE);
		}

		if ($answer['available'] === false) {
			$reason = self::REASON_UNREACHABLE;
			if (is_string($answer['reason'] ?? null) === true && $answer['reason'] !== '') {
				$reason = $answer['reason'];
			}

			return self::unanswered(reason: $reason);
		}

		$relations = self::relationsIn(rows: ($answer['relations'] ?? null));
		if ($relations === null) {
			return self::unanswered(reason: self::REASON_UNREACHABLE);
		}

		return ['available' => true, 'reason' => '', 'relations' => $relations];
	}//end normalised()

	/**
	 * The relation rows of an answer, or null when one of them cannot be read.
	 *
	 * One unreadable row makes the whole answer unusable. Dropping it would
	 * turn "a relation we could not read" into "no relation".
	 *
	 * @param mixed $rows What the answer holds under `relations`.
	 *
	 * @return array<int,array{relation:string,burgerservicenummer:string}>|null
	 */
	private static function relationsIn(mixed $rows): ?array {
		if (is_array($rows) === false) {
			return null;
		}

		$relations = [];
		foreach ($rows as $row) {
			$relation = null;
			$number = null;
			if (is_array($row) === true) {
				$relation = ($row['relation'] ?? null);
				$number = ($row['burgerservicenummer'] ?? null);
			}

			if (is_string($relation) === false || is_string($number) === false || $relation === '' || $number === '') {
				return null;
			}

			$relations[] = ['relation' => $relation, 'burgerservicenummer' => $number];
		}

		return $relations;
	}//end relationsIn()

	/**
	 * The answer for a question that was not answered.
	 *
	 * @param string $reason Why not.
	 *
	 * @return array{available:bool,reason:string,relations:array<int,array{relation:string,burgerservicenummer:string}>}
	 */
	private static function unanswered(string $reason): array {
		return ['available' => false, 'reason' => $reason, 'relations' => []];
	}//end unanswered()
}//end class
