<?php

/**
 * Procest Raadsinformatie Feed Controller
 *
 * Provides Atom RSS feeds for ORI (Open Raadsinformatie) entity types so that
 * citizens, journalists, and open-data aggregators can subscribe to council-
 * information updates without authentication.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Serves publicly-accessible Atom feeds for ORI entity types.
 *
 * Endpoints:
 *   GET /apps/procest/feed/ori/vergaderingen.rss
 *   GET /apps/procest/feed/ori/agendapunten.rss
 *   GET /apps/procest/feed/ori/documenten.rss
 *
 * All endpoints are accessible without authentication (PublicPage) and return
 * valid Atom 1.0 XML per RFC 4287.
 *
 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-7
 *
 * @psalm-suppress UnusedClass
 */
class RaadsinformatieFeedController extends Controller {

	use SearchesObjects;

	/**
	 * Maximum number of entries returned per feed.
	 *
	 * @var int
	 */
	private const FEED_LIMIT = 50;

	/**
	 * Constructor for RaadsinformatieFeedController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Serve the Atom feed for vergaderingen.
	 *
	 * @param string $organisation Optional organisatie filter
	 *
	 * @return DataDisplayResponse Atom XML feed
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-7
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function vergaderingen(string $organisation = ''): DataDisplayResponse {
		return $this->buildFeed(
			type: 'vergaderingen',
			schema: 'vergadering',
			organisation: $organisation
		);

	}//end vergaderingen()

	/**
	 * Serve the Atom feed for agendapunten.
	 *
	 * @param string $organisation Optional organisatie filter (not directly on schema, used as hint)
	 *
	 * @return DataDisplayResponse Atom XML feed
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-7
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function agendapunten(string $organisation = ''): DataDisplayResponse {
		return $this->buildFeed(
			type: 'agendapunten',
			schema: 'agendapunt',
			organisation: $organisation
		);

	}//end agendapunten()

	/**
	 * Serve the Atom feed for raadsdocumenten.
	 *
	 * @param string $organisation Optional organisatie filter
	 *
	 * @return DataDisplayResponse Atom XML feed
	 *
	 * @spec openspec/changes/open-raadsinformatie/tasks.md#task-7
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function documenten(string $organisation = ''): DataDisplayResponse {
		return $this->buildFeed(
			type: 'documenten',
			schema: 'raadsdocument',
			organisation: $organisation
		);

	}//end documenten()

	/**
	 * Build an Atom feed for the requested ORI entity type.
	 *
	 * @param string $type Feed type label used in titles (vergaderingen / agendapunten / documenten)
	 * @param string $schema ORI schema slug
	 * @param string $organisation Optional organisatie filter value
	 *
	 * @return DataDisplayResponse Atom XML response
	 */
	private function buildFeed(string $type, string $schema, string $organisation): DataDisplayResponse {
		$objects = $this->fetchObjects(schema: $schema, organisation: $organisation);

		$xml = $this->renderAtom(type: $type, schema: $schema, objects: $objects, organisation: $organisation);

		$response = new DataDisplayResponse($xml, Http::STATUS_OK);
		$response->addHeader('Content-Type', 'application/atom+xml; charset=utf-8');
		$response->addHeader('Cache-Control', 'public, max-age=300');

		return $response;
	}//end buildFeed()

	/**
	 * Fetch the latest objects for the given schema from the ORI register.
	 *
	 * @param string $schema The ORI schema slug (e.g. "vergadering")
	 * @param string $organisation Optional organisatie filter
	 *
	 * @return array<int,array> Array of object arrays
	 */
	private function fetchObjects(string $schema, string $organisation): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$params = [
			'_limit' => self::FEED_LIMIT,
			'_order[created]' => 'desc',
		];

		if (empty($organisation) === false) {
			$params['organisation'] = $organisation;
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: 'ori',
				schema: $schema,
				filters: $params
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Procest: could not fetch ORI objects for feed',
				['schema' => $schema, 'exception' => $e->getMessage(), 'app' => Application::APP_ID]
			);
			return [];
		}//end try

	}//end fetchObjects()

	/**
	 * Render an Atom 1.0 feed (RFC 4287) from an array of ORI objects.
	 *
	 * @param string $type Feed type label (vergaderingen / agendapunten / documenten)
	 * @param string $schema ORI schema slug
	 * @param array<int,array> $objects The objects to include in the feed
	 * @param string $organisation Active organisatie filter (for self link)
	 *
	 * @return string Atom XML string
	 */
	private function renderAtom(string $type, string $schema, array $objects, string $organisation): string {
		$feedId = 'urn:procest:ori:feed:' . $type;
		$feedTitle = $this->feedTitle(type: $type, organisation: $organisation);
		$feedUpdated = gmdate('Y-m-d\TH:i:s\Z');

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
		$xml .= '  <id>' . htmlspecialchars(string: $feedId, flags: ENT_XML1) . '</id>' . "\n";
		$xml .= '  <title>' . htmlspecialchars(string: $feedTitle, flags: ENT_XML1) . '</title>' . "\n";
		$xml .= '  <updated>' . $feedUpdated . '</updated>' . "\n";
		$xml .= '  <author><name>Procest - Open Raadsinformatie</name></author>' . "\n";

		foreach ($objects as $object) {
			$xml .= $this->renderEntry(schema: $schema, object: $object);
		}

		$xml .= '</feed>' . "\n";

		return $xml;
	}//end renderAtom()

	/**
	 * Render a single Atom <entry> element from an ORI object.
	 *
	 * @param string $schema The ORI schema slug
	 * @param array $object The ORI object data
	 *
	 * @return string The <entry> XML fragment
	 */
	private function renderEntry(string $schema, array $object): string {
		$slug = (string)($object['@self']['slug'] ?? ($object['id'] ?? ''));
		$entryId = 'urn:procest:ori:' . $schema . ':' . $slug;

		$title = $this->extractTitle(schema: $schema, object: $object);
		$updated = (string)($object['updated'] ?? ($object['created'] ?? gmdate('Y-m-d\TH:i:s\Z')));
		$summary = $this->extractSummary(schema: $schema, object: $object);

		$e = '  <entry>' . "\n";
		$e .= '    <id>' . htmlspecialchars(string: $entryId, flags: ENT_XML1) . '</id>' . "\n";
		$e .= '    <title>' . htmlspecialchars(string: $title, flags: ENT_XML1) . '</title>' . "\n";
		$e .= '    <updated>' . htmlspecialchars(string: $updated, flags: ENT_XML1) . '</updated>' . "\n";
		if (empty($summary) === false) {
			$e .= '    <summary>' . htmlspecialchars(string: $summary, flags: ENT_XML1) . '</summary>' . "\n";
		}

		$e .= '  </entry>' . "\n";

		return $e;
	}//end renderEntry()

	/**
	 * Extract a human-readable title from an ORI object based on its schema.
	 *
	 * @param string $schema The ORI schema slug
	 * @param array $object The ORI object data
	 *
	 * @return string Title string
	 */
	private function extractTitle(string $schema, array $object): string {
		return match ($schema) {
			'vergadering' => (string)($object['name'] ?? ''),
			'agendapunt' => (string)($object['onderwerp'] ?? ''),
			'raadsdocument' => (string)($object['titel'] ?? ''),
			default => (string)($object['name'] ?? ($object['titel'] ?? $schema)),
		};

	}//end extractTitle()

	/**
	 * Extract a brief summary/content description from an ORI object.
	 *
	 * @param string $schema The ORI schema slug
	 * @param array $object The ORI object data
	 *
	 * @return string Summary string
	 */
	private function extractSummary(string $schema, array $object): string {
		if ($schema === 'vergadering') {
			$parts = [];
			if (empty($object['startDate']) === false) {
				$parts[] = 'Datum: ' . $object['startDate'];
			}

			if (empty($object['location']) === false) {
				$parts[] = 'Locatie: ' . $object['location'];
			}

			if (empty($object['status']) === false) {
				$parts[] = 'Status: ' . $object['status'];
			}

			return implode(separator: ' | ', array: $parts);
		}

		if ($schema === 'agendapunt') {
			return (string)($object['omschrijving'] ?? '');
		}

		if ($schema === 'raadsdocument') {
			$parts = [];
			if (empty($object['type']) === false) {
				$parts[] = 'Type: ' . $object['type'];
			}

			if (empty($object['classification']) === false) {
				$parts[] = 'Classificatie: ' . $object['classification'];
			}

			return implode(separator: ' | ', array: $parts);
		}

		return '';
	}//end extractSummary()

	/**
	 * Build a descriptive feed title.
	 *
	 * @param string $type Feed type (vergaderingen / agendapunten / documenten)
	 * @param string $organisation Optional organisatie filter
	 *
	 * @return string
	 */
	private function feedTitle(string $type, string $organisation): string {
		$labels = [
			'vergaderingen' => 'Vergaderingen',
			'agendapunten' => 'Agendapunten',
			'documenten' => 'Raadsdocumenten',
		];

		$label = ($labels[$type] ?? ucfirst(string: $type));

		if (empty($organisation) === false) {
			return 'Open Raadsinformatie — ' . $label . ' — ' . $organisation;
		}

		return 'Open Raadsinformatie — ' . $label;
	}//end feedTitle()
}//end class
