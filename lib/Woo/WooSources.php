<?php

/**
 * Dossiq Woo sources
 *
 * The sources a handler can search from a Woo case to gather documents
 * (woo-requests-gather-documents-from-sources, design D-1 and D-2). Two are
 * the platform's own and always there: Nextcloud files and the documents on
 * other cases, both searched by the dialog through Nextcloud's unified search
 * so each answers with the searcher's own access. The third is the Microsoft
 * 365 connection integriq holds (SharePoint, Teams and mail), which dossiq
 * reaches through integriq's typed search and fetch commands, named by string
 * so dossiq stays installable without integriq.
 *
 * Nothing is indexed. A source is asked when the handler searches.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the sources, searches integriq's, and fetches an integriq hit.
 *
 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
 */
class WooSources {

	/**
	 * Nextcloud files, searched through the unified search provider `files`.
	 */
	public const SOURCE_FILES = 'files';

	/**
	 * Documents on other cases, searched through OpenRegister's unified search provider.
	 */
	public const SOURCE_CASES = 'cases';

	/**
	 * The Microsoft 365 connection integriq holds: SharePoint, Teams and mail.
	 */
	public const SOURCE_MICROSOFT365 = 'microsoft365';

	/**
	 * The most rows one source answers per search (design risks: narrow, do not page).
	 */
	public const ROW_LIMIT = 50;

	/**
	 * integriq's search command, relative to integriq's namespace.
	 */
	public const SEARCH_EVENT = 'Event\\DocumentSearchRequestedEvent';

	/**
	 * integriq's fetch command, relative to integriq's namespace.
	 */
	public const FETCH_EVENT = 'Event\\DocumentFetchRequestedEvent';

	/**
	 * The app config key naming integriq's connection; empty lets integriq use the one linked to dossiq.
	 */
	public const CONNECTION_KEY = 'woo_sources_connection';

	/**
	 * Constructor.
	 *
	 * @param IAppManager      $appManager      Whether integriq is installed and enabled.
	 * @param IEventDispatcher $dispatcher      Carries the search and fetch to integriq.
	 * @param IAppConfig       $appConfig       Holds the connection key.
	 * @param SettingsService  $settingsService The register and the document schema, for the cases source.
	 * @param IL10N            $l10n            Source labels.
	 * @param LoggerInterface  $logger          Logs a search or fetch integriq could not answer.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IEventDispatcher $dispatcher,
		private readonly IAppConfig $appConfig,
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every source with whether it can be searched now, and how.
	 *
	 * A source that cannot answer is listed with the reason, never left out
	 * (REQ-WOO-012 "A source that is not connected").
	 *
	 * @return array<int, array<string, mixed>> Each `{id, label, kind, available, reason, search}`.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	 */
	public function list(): array {
		$reason = $this->microsoft365Refusal();

		return [
			[
				'id' => self::SOURCE_FILES,
				'label' => $this->l10n->t('Files'),
				'kind' => 'platform',
				'available' => true,
				'reason' => '',
				'search' => ['provider' => 'files', 'filters' => []],
			],
			[
				'id' => self::SOURCE_CASES,
				'label' => $this->l10n->t('Cases'),
				'kind' => 'platform',
				'available' => true,
				'reason' => '',
				'search' => [
					'provider' => 'openregister_objects',
					'filters' => [
						'register' => $this->settingsService->getConfigValue('register'),
						'schema' => $this->settingsService->getConfigValue('dossier_informatieobject_schema'),
					],
				],
			],
			[
				'id' => self::SOURCE_MICROSOFT365,
				'label' => $this->l10n->t('SharePoint, Teams and mail'),
				'kind' => 'integriq',
				'available' => ($reason === ''),
				'reason' => $reason,
				'search' => null,
			],
		];
	}//end list()

	/**
	 * Whether a source id is one this class knows.
	 *
	 * @param string $source The source id.
	 *
	 * @return bool True when it is.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	 */
	public static function isKnown(string $source): bool {
		return in_array($source, [self::SOURCE_FILES, self::SOURCE_CASES, self::SOURCE_MICROSOFT365], true);
	}//end isKnown()

	/**
	 * Search integriq's connection for terms in a period, as one person.
	 *
	 * @param string      $terms  The search terms.
	 * @param string|null $from   Start of the period, Y-m-d.
	 * @param string|null $to     End of the period, Y-m-d.
	 * @param string      $userId The person searching; mail and chat stay within what they may read.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, remaining: int, notices: array<int, string>, refusal: string}
	 *         `refusal` is empty when integriq answered.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-a-handler-searches-the-organisations-sources-from-a-woo-case-req-woo-012
	 */
	public function searchMicrosoft365(string $terms, ?string $from, ?string $to, string $userId): array {
		$refusal = $this->microsoft365Refusal();
		if ($refusal !== '') {
			return ['rows' => [], 'remaining' => 0, 'notices' => [], 'refusal' => $refusal];
		}

		$result = $this->ask(
			relative: self::SEARCH_EVENT,
			arguments: [Application::APP_ID, $this->connectionKey(), $userId, $terms, $from, $to, [], self::ROW_LIMIT],
		);
		if ($result === null) {
			return ['rows' => [], 'remaining' => 0, 'notices' => [], 'refusal' => 'integriq-did-not-answer'];
		}

		$hits = array_values(array_filter((array)($result['hits'] ?? []), 'is_array'));
		$rows = array_map(fn (array $hit): array => $this->row(hit: $hit), array_slice($hits, 0, self::ROW_LIMIT));
		$remaining = max(0, (int)($result['moreCount'] ?? 0)) + max(0, (count($hits) - self::ROW_LIMIT));
		$notices = array_values(array_map('strval', array_filter((array)($result['notices'] ?? []), 'is_scalar')));

		return ['rows' => $rows, 'remaining' => $remaining, 'notices' => $notices, 'refusal' => ''];
	}//end searchMicrosoft365()

	/**
	 * Fetch one integriq hit by its handle, as one person.
	 *
	 * @param string $handle The hit's `key` as the search answered it.
	 * @param string $userId The person adding it.
	 *
	 * @return array{fileName: string, mimeType: string, content: string}|null Null when integriq cannot answer.
	 *
	 * @spec openspec/changes/woo-requests-gather-documents-from-sources/specs/woo-case-type/spec.md#requirement-picked-results-become-documents-on-the-case-req-woo-013
	 */
	public function fetchMicrosoft365(string $handle, string $userId): ?array {
		if ($handle === '' || $this->microsoft365Refusal() !== '') {
			return null;
		}

		$result = $this->ask(relative: self::FETCH_EVENT, arguments: [Application::APP_ID, $this->connectionKey(), $userId, $handle]);
		if ($result === null || is_string($result['content'] ?? null) === false || trim((string)($result['fileName'] ?? '')) === '') {
			return null;
		}

		return [
			'fileName' => basename(trim((string)$result['fileName'])),
			'mimeType' => (string)($result['mimeType'] ?? 'application/octet-stream'),
			'content' => $result['content'],
		];
	}//end fetchMicrosoft365()

	/**
	 * Why integriq's source cannot be searched now, or '' when it can.
	 *
	 * @return string `integriq-not-installed`, `search-not-offered`, or ''.
	 */
	private function microsoft365Refusal(): string {
		if (FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: 'integriq') === false) {
			return 'integriq-not-installed';
		}

		if (FleetAppId::resolveClass(canonical: 'integriq', relative: self::SEARCH_EVENT) === null) {
			return 'search-not-offered';
		}

		return '';
	}//end microsoft365Refusal()

	/**
	 * The configured connection key, '' for the one integriq links to dossiq.
	 *
	 * @return string The key.
	 */
	private function connectionKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::CONNECTION_KEY, '');
	}//end connectionKey()

	/**
	 * Dispatch one integriq command and read its result slot.
	 *
	 * @param string            $relative  The event class, relative to integriq's namespace.
	 * @param array<int, mixed> $arguments The constructor arguments, in the contract's order.
	 *
	 * @return array<string, mixed>|null The answer, null when integriq did not answer.
	 */
	private function ask(string $relative, array $arguments): ?array {
		$eventClass = FleetAppId::resolveClass(canonical: 'integriq', relative: $relative);
		if ($eventClass === null) {
			return null;
		}

		try {
			$event = new $eventClass(...$arguments);
			if (($event instanceof Event) === false) {
				return null;
			}

			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning('WooSources: integriq could not be asked', ['event' => $relative, 'error' => $e->getMessage()]);
			return null;
		}

		if (method_exists($event, 'isHandled') === false || $event->isHandled() !== true || method_exists($event, 'getResult') === false) {
			return null;
		}

		$result = $event->getResult();
		if (is_array($result) === false) {
			return null;
		}

		return $result;
	}//end ask()

	/**
	 * One integriq hit as a dialog row.
	 *
	 * @param array<string, mixed> $hit A REQ-DCC-004 hit with `entityType`.
	 *
	 * @return array<string, mixed> `{key, name, location, date, snippet, entityType}`.
	 */
	private function row(array $hit): array {
		return [
			'key' => (string)($hit['remoteId'] ?? ''),
			'name' => (string)($hit['title'] ?? ''),
			'location' => (string)($hit['path'] ?? ''),
			'date' => (string)($hit['modifiedAt'] ?? ''),
			'snippet' => (string)($hit['snippet'] ?? ''),
			'entityType' => (string)($hit['entityType'] ?? ''),
		];
	}//end row()
}//end class
