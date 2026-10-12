<?php

/**
 * Dossiq StUF Provider Query Listener.
 *
 * Answers the StUF provider questions integriq receives from a case system
 * (decision 199): integriq owns the wire, dossiq owns the cases. It answers
 * only for an integriq source a stufEndpoint names in `integriqSource`, and
 * uses that endpoint's `zaaktypeMappings` to name each case's zaaktype.
 *
 * - zakLv01: the cases with that identificatie. A query without an
 *   identificatie is refused: the endpoint is not a case export.
 * - npsLv01: the person as dossiq knows them, as the initiator of a case
 *   (initiatorType person, initiatorSourceId = BSN). Dossiq is not a BRP; a
 *   BSN no case names is an empty answer, because dossiq looked.
 * - zakLk01, edcLk01: not this listener's. {@see StufProviderWriteListener}
 *   carries them out (decision 206). One that reaches this listener anyway is
 *   refused.
 *
 * Replaces StufZknMessageResponder, whose four handlers were stubs.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Stuf\StufRegisterAccess;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers integriq's StUF provider events from dossiq's cases.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
 */
class StufProviderQueryListener implements IEventListener {

	/**
	 * Most cases one zakLv01 answers with.
	 *
	 * @var integer
	 */
	private const MAX_CASES = 10;

	/**
	 * Constructor.
	 *
	 * @param StufRegisterAccess $register  The stufEndpoint rows.
	 * @param SettingsService    $settings  The register and case schema.
	 * @param CaseObjectReader   $caseTypes Reads a case type for its slug.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly StufRegisterAccess $register,
		private readonly SettingsService $settings,
		private readonly CaseObjectReader $caseTypes,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Answer, refuse, or stay silent when the source is not one of dossiq's.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/stuf-provider-through-integriq/specs/stuf-integration/spec.md#requirement-dossiq-answers-stuf-provider-questions-through-integriq
	 */
	public function handle(Event $event): void {
		// Integriq's classes, optional at runtime: instanceof on an absent class is false.
		if (($event instanceof \OCA\Integriq\Event\StufProviderQueryEvent) === false) {
			return;
		}

		$endpoint = $this->endpointFor(sourceId: $event->getSourceId());
		if ($endpoint === null) {
			return;
		}

		if ($event instanceof \OCA\Integriq\Event\StufCaseQueryEvent) {
			$this->answerCaseQuery(event: $event, endpoint: $endpoint);
			return;
		}

		if ($event instanceof \OCA\Integriq\Event\StufPersonQueryEvent) {
			$this->answerPersonQuery(event: $event);
			return;
		}

		$event->refuse(
			appId: Application::APP_ID,
			reason: 'Dossiq neemt geen zaak- of documentwijzigingen aan via StUF; gebruik de API van dossiq.'
		);
	}//end handle()

	/**
	 * Answer a zakLv01 by identificatie.
	 *
	 * @param \OCA\Integriq\Event\StufCaseQueryEvent $event    The event.
	 * @param array                                  $endpoint The stufEndpoint row.
	 *
	 * @return void
	 */
	private function answerCaseQuery(\OCA\Integriq\Event\StufCaseQueryEvent $event, array $endpoint): void {
		$identificatie = trim((string)($event->getCriteria()['identificatie'] ?? ''));
		if ($identificatie === '') {
			$event->refuse(appId: Application::APP_ID, reason: 'Dossiq beantwoordt een zakLv01 alleen op identificatie.');
			return;
		}

		$cases = $this->findCases(filters: ['identifier' => $identificatie]);
		if ($cases === null) {
			return;
		}

		$mappings = (array)($endpoint['zaaktypeMappings'] ?? []);
		$answer   = [];
		foreach ($cases as $case) {
			$answer[] = array_filter(
				[
					'zaakidentificatie' => (string)($case['identifier'] ?? ''),
					'omschrijving' => (string)($case['title'] ?? ''),
					'startdatum' => self::stufDate(iso: (string)($case['startDate'] ?? '')),
					'einddatum' => self::stufDate(iso: (string)($case['endDate'] ?? '')),
					'zaaktype' => $this->zaaktype(caseTypeId: (string)($case['caseType'] ?? ''), mappings: $mappings),
				],
				static fn(string $value): bool => $value !== ''
			);
		}

		$event->answerCases(appId: Application::APP_ID, cases: $answer);
	}//end answerCaseQuery()

	/**
	 * Answer an npsLv01 from the cases that name the BSN as their initiator.
	 *
	 * @param \OCA\Integriq\Event\StufPersonQueryEvent $event The event.
	 *
	 * @return void
	 */
	private function answerPersonQuery(\OCA\Integriq\Event\StufPersonQueryEvent $event): void {
		$bsn = trim($event->getBsn());
		if (preg_match('/^\d{9}$/', $bsn) !== 1) {
			$event->refuse(appId: Application::APP_ID, reason: 'Een npsLv01 vraagt naar een BSN van negen cijfers.');
			return;
		}

		$cases = $this->findCases(filters: ['initiatorType' => 'person', 'initiatorSourceId' => $bsn]);
		if ($cases === null) {
			return;
		}

		$persons = [];
		if ($cases !== []) {
			$persons[] = array_filter(
				['inp.bsn' => $bsn, 'geslachtsnaam' => (string)($cases[0]['initiatorDisplayName'] ?? '')],
				static fn(string $value): bool => $value !== ''
			);
		}

		$event->answerPersons(appId: Application::APP_ID, persons: $persons);
	}//end answerPersonQuery()

	/**
	 * The stufEndpoint that names this integriq source, or null when none does.
	 *
	 * @param string $sourceId The integriq source id.
	 *
	 * @return array|null The endpoint row.
	 */
	private function endpointFor(string $sourceId): ?array {
		if ($sourceId === '') {
			return null;
		}

		return $this->register->findOne(schema: StufRegisterAccess::SCHEMA_ENDPOINT, filters: ['integriqSource' => $sourceId]);
	}//end endpointFor()

	/**
	 * Cases matching the filters, or null when the case store cannot be read.
	 *
	 * Null leaves the event unanswered, so integriq answers StUF055 rather than
	 * an empty La01 that would read as "no such zaak".
	 *
	 * @param array<string, string> $filters Case property filters.
	 *
	 * @return array<int, array<string, mixed>>|null The cases.
	 */
	private function findCases(array $filters): ?array {
		$objectService = $this->settings->getObjectService();
		$register      = $this->settings->getConfigValue('register');
		$schema        = $this->settings->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$this->logger->warning('StUF provider query: the case register is not configured');
			return null;
		}

		try {
			$found = $objectService->findAll(
				config: [
					'filters' => array_merge(['register' => $register, 'schema' => $schema], $filters),
					'limit' => self::MAX_CASES,
				],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning('StUF provider query: the case lookup failed: {error}', ['error' => $exception->getMessage()]);
			return null;
		}

		return self::rows(found: $found);
	}//end findCases()

	/**
	 * OpenRegister's result as plain case arrays.
	 *
	 * @param mixed $found What findAll returned.
	 *
	 * @return array<int, array<string, mixed>>|null The cases, null when the result is unreadable.
	 */
	private static function rows(mixed $found): ?array {
		if (is_array($found) === false) {
			return null;
		}

		$rows = ($found['results'] ?? $found);
		if (is_array($rows) === false) {
			return null;
		}

		$cases = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$cases[] = $row;
			}
		}

		return $cases;
	}//end rows()

	/**
	 * The zaaksysteem's zaaktype for a case type, from the endpoint's mapping.
	 *
	 * @param string                $caseTypeId The case's caseType uuid.
	 * @param array<string, string> $mappings   Case type slug (or uuid) to zaaktype omschrijving.
	 *
	 * @return string The zaaktype, '' when unmapped.
	 */
	private function zaaktype(string $caseTypeId, array $mappings): string {
		if ($caseTypeId === '' || $mappings === []) {
			return '';
		}

		if (isset($mappings[$caseTypeId]) === true) {
			return (string)$mappings[$caseTypeId];
		}

		$slug = (string)($this->caseTypes->loadCaseType(caseTypeId: $caseTypeId)['slug'] ?? '');
		return (string)($mappings[$slug] ?? '');
	}//end zaaktype()

	/**
	 * An ISO date or date-time as a StUF date (Ymd).
	 *
	 * @param string $iso The ISO value.
	 *
	 * @return string The StUF date, '' when empty or unreadable.
	 */
	private static function stufDate(string $iso): string {
		if ($iso === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($iso))->format('Ymd');
		} catch (Throwable) {
			return '';
		}
	}//end stufDate()
}//end class
