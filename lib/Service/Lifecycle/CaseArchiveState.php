<?php

/**
 * The archive state of a case is the platform's marker, read and written here.
 *
 * An archived case has to leave the Cases page, the queue, the tiles and the
 * search, and it has to come back whole. dossiq keeps no flag of its own for
 * that (ADR-022). OpenRegister's `object-archive-state` writes `@self.archived`
 * and every list, aggregation and search provider excludes a marked object by
 * default, so the exclusion happens once, in the query, rather than in every
 * lens that would otherwise have to remember it.
 *
 * 🔴 `case.archiveStatus` IS NOT THE STATE AND MUST NOT BE READ AS ONE. It is
 * the ZGW fact, it keeps its ZGW values, and the ZGW delete guard in
 * `ZrcController` keeps reading it. The two are written by the same act and
 * that is the whole of their relationship: asking `archiveStatus` whether a
 * case is archived would give a case imported over ZGW with
 * `archiefstatus: gearchiveerd` an answer no list agrees with, because no list
 * looks there.
 *
 * 🔑 THE ORDER OF THE TWO WRITES IS NOT A DETAIL. An archived object refuses
 * every write to its data, so the ZGW field is written BEFORE the marker goes
 * on and AFTER it comes off. Reversed, archiving would refuse its own second
 * half and the case would end up marked with `archiveStatus` still reading
 * `nog_te_archiveren`, which is the disagreement this class exists to prevent.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
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
 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Put a case in the archive, take it back out, and say whether it is in one.
 *
 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
 */
class CaseArchiveState {

	/**
	 * OpenRegister's archive handler, resolved at call time.
	 *
	 * The same lazy-resolve contract every other OpenRegister seam in dossiq
	 * uses: OpenRegister is a runtime dependency rather than a composer one,
	 * so the class is fetched from the container when it is needed and never
	 * type-hinted in a constructor.
	 *
	 * @var string
	 */
	private const ARCHIVE_HANDLER = 'OCA\OpenRegister\Service\Object\ArchiveHandler';

	/**
	 * The `@self` key carrying the marker.
	 *
	 * @var string
	 */
	public const MARKER_KEY = 'archived';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Names the register and the case schema.
	 * @param ContainerInterface $container Resolves OpenRegister's archive handler.
	 * @param LoggerInterface $logger Records a marker that could not be written.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this case carries the platform's archive marker.
	 *
	 * @param array<string, mixed> $case The loaded case, as the store returns it.
	 *
	 * @return bool True when the case is archived.
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function isArchived(array $case): bool {
		return ($this->markerOn(case: $case) !== []);
	}//end isArchived()

	/**
	 * The marker itself: who archived the case, when, and why.
	 *
	 * @param array<string, mixed> $case The loaded case, as the store returns it.
	 *
	 * @return array<string, mixed> The marker, empty when the case is not archived.
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function markerOn(array $case): array {
		$self = ($case['@self'] ?? []);
		if (is_array($self) === false) {
			return [];
		}

		$marker = ($self[self::MARKER_KEY] ?? null);
		if (is_array($marker) === false || $marker === []) {
			return [];
		}

		return $marker;
	}//end markerOn()

	/**
	 * Put the case in the archive.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being archived, kept on the marker.
	 *
	 * @return array<string, mixed> The stored marker.
	 *
	 * @throws RefusedException When the platform refuses or cannot be reached.
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function mark(string $caseId, string $reason): array {
		return $this->call(verb: 'archive', caseId: $caseId, reason: $reason);
	}//end mark()

	/**
	 * Take the case back out of the archive.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being restored, kept on the audit entry.
	 *
	 * @return array<string, mixed> The cleared marker.
	 *
	 * @throws RefusedException When the platform refuses or cannot be reached.
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function clear(string $caseId, string $reason): array {
		return $this->call(verb: 'unarchive', caseId: $caseId, reason: $reason);
	}//end clear()

	/**
	 * One call to the platform's archive handler, with its refusal kept whole.
	 *
	 * 🔴 A FAILURE HERE IS A REFUSAL, NEVER A SHRUG. The act that calls this
	 * has already written `archiveStatus`, and swallowing an unreachable
	 * platform would leave a case whose ZGW field says archived sitting in
	 * every working lens, with nothing on screen saying so. The sentence the
	 * platform gave is carried through unchanged, because the reason the user
	 * reads has to be the reason the server gave (ADR-105).
	 *
	 * @param string $verb `archive` or `unarchive`.
	 * @param string $caseId The case UUID.
	 * @param string $reason Why.
	 *
	 * @return array<string, mixed> What the handler answered.
	 *
	 * @throws RefusedException When the platform refuses or cannot be reached.
	 */
	private function call(string $verb, string $caseId, string $reason): array {
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'case_schema');
		if ($register === '' || $schema === '') {
			throw new RefusedException(
				rule: 'archive-state-unavailable',
				sentence: 'The case register is not configured, so the archive could not be reached.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		$handler = $this->handler();

		try {
			/** @psalm-suppress MixedMethodCall */
			$answer = $handler->{$verb}($caseId, $reason, $register, $schema);
		} catch (RefusedException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseArchiveState: the platform refused an archive state change',
				['caseId' => $caseId, 'verb' => $verb, 'exception' => $e->getMessage()],
			);

			throw new RefusedException(
				rule: 'archive-state-refused',
				sentence: $e->getMessage(),
				status: RefusedException::STATUS_REFUSED,
			);
		}

		if (is_array($answer) === false) {
			return [];
		}

		return $answer;
	}//end call()

	/**
	 * OpenRegister's archive handler, or a refusal naming its absence.
	 *
	 * @return object The handler.
	 *
	 * @throws RefusedException When OpenRegister is not installed or too old.
	 */
	private function handler(): object {
		try {
			$handler = $this->container->get(self::ARCHIVE_HANDLER);
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseArchiveState: OpenRegister does not offer an archive handler',
				['exception' => $e->getMessage()],
			);
			$handler = null;
		}

		if (is_object($handler) === false) {
			throw new RefusedException(
				rule: 'archive-state-unavailable',
				sentence: 'This instance of OpenRegister does not offer the archive, so a case cannot leave the working lists yet.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $handler;
	}//end handler()
}//end class
