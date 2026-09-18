<?php

/**
 * Dossiq case timeline writer.
 *
 * The one seam in this app that writes a timeline entry. Every writer that
 * records a communication on a case calls this class, and nothing else in
 * dossiq knows how an entry is stored, because it is not stored here:
 * OpenRegister owns the record (`timeline-entries-are-records`, #3762) and
 * dossiq consumes it (ADR-022).
 *
 * IN PROCESS, NEVER OVER HTTP. ADR-080 D2/D3 forbids an app addressing its
 * own instance over HTTP, so the write goes through OpenRegister's
 * `TimelineWriteService` resolved from the container, the same lazy resolve
 * `SettingsService::getObjectService()` already uses. OpenRegister is an
 * optional runtime dependency and an instance carrying an older one has no
 * such class; asking the container at call time is what keeps that instance
 * sending mail instead of fatalling.
 *
 * IT NEVER THROWS AT ITS CALLERS. An entry records something that has
 * already happened: the mail went out, the call was taken, the statutory
 * acknowledgement was sent. Refusing the act because the log could not be
 * written would trade a missing line for a broken duty. So every path
 * answers the entry id or the empty string and logs its own failure at
 * warning, naming the case and the kind, so a timeline that silently stops
 * being written is visible in the log rather than only on the page.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Timeline;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one timeline entry per recorded communication.
 *
 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
 */
class CaseTimeline {

	/**
	 * OpenRegister's timeline writer, as a class name so this app never
	 * imports a class an older OpenRegister does not ship.
	 *
	 * @var string
	 */
	public const WRITE_SERVICE = 'OCA\\OpenRegister\\Service\\Timeline\\TimelineWriteService';

	/**
	 * OpenRegister's timeline reader, named the same way and for the same reason.
	 *
	 * @var string
	 */
	public const READ_SERVICE = 'OCA\\OpenRegister\\Service\\Timeline\\TimelineEntryService';

	/**
	 * An entry only the handling organisation reads.
	 *
	 * @var string
	 */
	public const INTERNAL = 'internal';

	/**
	 * An entry the applicant may read too.
	 *
	 * @var string
	 */
	public const PUBLIC_ENTRY = 'public';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settings  Resolves the register, the case schema and the object service.
	 * @param ContainerInterface $container The DI container, for the lazy resolve.
	 * @param LoggerInterface    $logger    Logger for the failures that stay soft.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this instance can carry a timeline at all.
	 *
	 * Asked before a caller bothers building a payload, and asked again
	 * inside {@see self::record()} because the answer can change between the
	 * two: an admin can disable OpenRegister while a batch is running.
	 *
	 * @return boolean True when an entry can be written.
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function isAvailable(): bool {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return false;
		}

		return $this->container->has(self::WRITE_SERVICE);
	}//end isAvailable()

	/**
	 * Record one entry on a case, and optionally on its related cases.
	 *
	 * @param string               $caseId         The case the entry hangs on.
	 * @param string               $kind           The declared kind, e.g. `contactmoment`.
	 * @param string               $message        The text a handler reads.
	 * @param array<string, mixed> $fields         The values the kind declares. Anything else is dropped by OpenRegister.
	 * @param string               $visibility     `internal` or `public`.
	 * @param array<int, string>   $relatedCaseIds Further cases the same entry belongs on.
	 *
	 * @return string The entry uuid, or '' when nothing was written.
	 *
	 * @spec openspec/changes/one-timeline-on-the-case/specs/case-history-surface/spec.md
	 */
	public function record(
		string $caseId,
		string $kind,
		string $message,
		array $fields = [],
		string $visibility = self::INTERNAL,
		array $relatedCaseIds = [],
	): string {
		if (trim($caseId) === '' || trim($kind) === '') {
			return '';
		}

		if ($this->isAvailable() === false) {
			return '';
		}

		$coordinates = $this->coordinates();
		if ($coordinates === null) {
			$this->soften(caseId: $caseId, kind: $kind, reason: 'the register or the case schema is not configured');
			return '';
		}

		[$objectService, $register, $schema] = $coordinates;

		try {
			$writer = $this->writer();

			$object = $objectService->find($caseId, register: $register, schema: $schema);
			if ($object === null) {
				$this->soften(caseId: $caseId, kind: $kind, reason: 'the case could not be read');
				return '';
			}

			$data = [
				'message' => $message,
				'kind' => $kind,
				'fields' => $fields,
				'visibility' => $visibility,
				'register' => (string)$register,
				'schema' => (string)$schema,
			];

			$related = $this->relatedObjects(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				caseId: $caseId,
				relatedCaseIds: $relatedCaseIds,
			);

			if ($related === []) {
				return (string)$writer->write(object: $object, data: $data)->getUuid();
			}

			$written = $writer->writeToMany(objects: array_merge([$object], $related), data: $data);
			if ($written === []) {
				return '';
			}

			return (string)$written[0]->getUuid();
		} catch (Throwable $e) {
			$this->soften(caseId: $caseId, kind: $kind, reason: $e->getMessage());
			return '';
		}//end try
	}//end record()

	/**
	 * The entries on a case that the applicant may read.
	 *
	 * ONE READER FOR BOTH OUTSIDE SURFACES. The portal contribution and the
	 * public status page ask the same question, and a second reader beside
	 * this one is how the two would come to disagree: the disagreement would
	 * be an internal note on a citizen's screen, and it would look like a
	 * working page right up until someone read it.
	 *
	 * THE FILTER IS THE READER'S, NOT THE CALLER'S. `publicEntries()` takes no
	 * visibility argument, so no caller can ask it for the internal ones. A
	 * surface that wants the whole feed reads OpenRegister's own endpoint with
	 * a signed-in user behind it, which is where the access check belongs.
	 *
	 * THE AUTHOR DOES NOT TRAVEL. A handler's user id is not part of what
	 * happened on the case as far as the applicant is concerned, and a public
	 * projection is the last place to hand one out.
	 *
	 * IT ANSWERS THE EMPTY LIST ONLY WHEN IT KNOWS THE LIST IS EMPTY: no case
	 * was asked for, OpenRegister or its reader is absent, the register is
	 * unconfigured, or the case is not there. Each of those is a fact the
	 * method establishes, not a failure it hides. A read that THROWS is a
	 * different fact, so it is logged at warning naming the case and then
	 * travels on. Unlike {@see self::record()}, which softens because an entry
	 * records something that already happened, a reader has nothing to protect
	 * by lying about what it found.
	 *
	 * @param string  $caseId The case to read.
	 * @param integer $limit  How many entries at most, newest first.
	 *
	 * @return array<int, array<string, mixed>> The public entries.
	 *
	 * @throws Throwable When the timeline could not be read at all.
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	public function publicEntries(string $caseId, int $limit = 50): array {
		if (trim($caseId) === '') {
			return [];
		}

		if ($this->settings->isOpenRegisterAvailable() === false || $this->container->has(self::READ_SERVICE) === false) {
			return [];
		}

		$coordinates = $this->coordinates();
		if ($coordinates === null) {
			return [];
		}

		[$objectService, $register, $schema] = $coordinates;

		try {
			$object = $objectService->find($caseId, register: $register, schema: $schema);
			if ($object === null) {
				return [];
			}

			$entries = $this->container->get(self::READ_SERVICE)->listForObject(
				object: $object,
				visibility: self::PUBLIC_ENTRY,
				limit: $limit,
			);
		} catch (Throwable $e) {
			// LOGGED AND RETHROWN, NOT SWALLOWED. "I could not read" and
			// "there are nothing public here" are different facts, and a
			// reader that answered the empty list for both would report the
			// second while meaning the first. That is the conflation ADR-105
			// and `ServiceCatchReturnsNullTest` exist to stop, and on this
			// method it would be a citizen told nothing has happened on their
			// case because an optional dependency threw. The caller that owns
			// the consequence decides; see
			// `PortalContributionProvider::caseTimeline()`.
			$this->soften(caseId: $caseId, kind: 'public read', reason: $e->getMessage());
			throw $e;
		}

		$projected = [];
		foreach ((array)$entries as $entry) {
			$projected[] = $this->project(entry: $entry);
		}

		return $projected;
	}//end publicEntries()

	/**
	 * One entry, cut down to what an applicant may be shown.
	 *
	 * @param mixed $entry The entry as OpenRegister answered it.
	 *
	 * @return array<string, mixed> The projection.
	 *
	 * @spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md
	 */
	private function project(mixed $entry): array {
		$row = $entry;
		if (is_object($entry) === true && method_exists($entry, 'jsonSerialize') === true) {
			$row = $entry->jsonSerialize();
		}

		$row = (array)$row;

		return [
			'id' => (string)($row['uuid'] ?? ($row['id'] ?? '')),
			'kind' => (string)($row['kind'] ?? ''),
			'message' => (string)($row['message'] ?? ''),
			'fields' => (array)($row['fields'] ?? []),
			'occurredAt' => $this->moment(value: ($row['created'] ?? null)),
		];
	}//end project()

	/**
	 * A timestamp as a string, whichever shape it arrived in.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string The moment, or '' when there is none.
	 */
	private function moment(mixed $value): string {
		if ($value instanceof \DateTimeInterface === true) {
			return $value->format(\DateTimeInterface::ATOM);
		}

		return (string)($value ?? '');
	}//end moment()

	/**
	 * Resolve the further cases an entry also belongs on.
	 *
	 * A case that cannot be read is LEFT OUT rather than written as null:
	 * `writeToMany` takes resolved objects, and handing it a hole would write
	 * the note on some of the cases and fail on the rest, which is the exact
	 * half-write the endpoint's own contract refuses.
	 *
	 * @param object             $objectService  OpenRegister's object service.
	 * @param mixed              $register       The register as configured.
	 * @param mixed              $schema         The case schema as configured.
	 * @param string             $caseId         The case already resolved, never repeated.
	 * @param array<int, string> $relatedCaseIds The further cases.
	 *
	 * @return array<int, object> The resolved objects.
	 */
	private function relatedObjects(
		object $objectService,
		$register,
		$schema,
		string $caseId,
		array $relatedCaseIds,
	): array {
		$resolved = [];
		$seen = [$caseId => true];

		foreach ($relatedCaseIds as $relatedId) {
			$relatedId = trim((string)$relatedId);
			if ($relatedId === '' || isset($seen[$relatedId]) === true) {
				continue;
			}

			$seen[$relatedId] = true;

			try {
				$object = $objectService->find($relatedId, register: $register, schema: $schema);
			} catch (Throwable $e) {
				$object = null;
			}

			if ($object === null) {
				$this->logger->warning(
					'Dossiq timeline: related case {case} could not be read, so the entry was not written on it',
					['app' => Application::APP_ID, 'case' => $relatedId],
				);
				continue;
			}

			$resolved[] = $object;
		}//end foreach

		return $resolved;
	}//end relatedObjects()

	/**
	 * OpenRegister's timeline writer.
	 *
	 * DELIBERATELY NOT WRAPPED IN A TRY. A catch here that answered null would
	 * be a swallowing catch in `lib/Service`, which `ServiceCatchReturnsNullTest`
	 * counts against a ceiling that only goes down, and it would put a SECOND
	 * place in this class that decides an entry is not going to be written.
	 * {@see self::record()} already has that place, and it logs what happened.
	 * So the absence is asked about with `has()`, which answers rather than
	 * throws, and anything that still goes wrong inside `get()` travels to the
	 * one catch that reports it.
	 *
	 * @return object The writer.
	 *
	 * @throws \Throwable When the container cannot build it.
	 */
	private function writer(): object {
		return $this->container->get(self::WRITE_SERVICE);
	}//end writer()

	/**
	 * The object service, register and case schema, or null when unconfigured.
	 *
	 * @return array{0: object, 1: mixed, 2: mixed}|null The three, or null.
	 */
	private function coordinates(): ?array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('case_schema');
		if ((string)$register === '' || (string)$schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end coordinates()

	/**
	 * Log a write that did not happen, without failing the act it recorded.
	 *
	 * @param string $caseId The case.
	 * @param string $kind   The kind that was not written.
	 * @param string $reason Why.
	 *
	 * @return void
	 */
	private function soften(string $caseId, string $kind, string $reason): void {
		$this->logger->warning(
			'Dossiq timeline: no {kind} entry was written on case {case}, {reason}',
			[
				'app' => Application::APP_ID,
				'kind' => $kind,
				'case' => $caseId,
				'reason' => $reason,
			],
		);
	}//end soften()
}//end class
