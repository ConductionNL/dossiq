<?php

/**
 * Dossiq Communication Channel
 *
 * `case.communicationChannel` is a slug: how letters about the case reach the
 * applicant (email, portal, post, website, zgw-api). Ruben decided on 10 Oct
 * (decision 171, Q-dossiq-L2-3) that it is declared as that enum and that ZGW
 * URLs are mapped to slugs at the ZGW boundary, so no case the ZGW Zaken API
 * creates is refused. This class is that mapping, for the boundary, for the
 * channel intake and for the repair step that converts stored values.
 *
 * A ZGW `communicatiekanaal` is a URL into a referentielijsten API whose name
 * cannot be read off the URL. An administrator may map known URLs to slugs in
 * the app config key `zgw_communication_channel_map` (`{"<url>": "<slug>"}`);
 * every other URL becomes `zgw-api`, the slug for "came in through the ZGW
 * API". The value a case came in with is kept in `communicationChannelSource`,
 * so the ZGW API answers with the URL it was given.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Maps every value a channel arrives as onto a channel slug, and back to ZGW.
 *
 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md
 */
class CommunicationChannel {

	/**
	 * The channel slugs, in the order the schema declares them.
	 *
	 * @var array<int, string>
	 */
	public const SLUGS = ['email', 'portal', 'post', 'website', 'zgw-api'];

	/**
	 * The slug for a value that came in through the ZGW API and maps to nothing known.
	 */
	public const ZGW = 'zgw-api';

	/**
	 * The app-config key holding the administrator's ZGW URL map.
	 */
	public const MAP_KEY = 'zgw_communication_channel_map';

	/**
	 * Words an older intake or a channel message wrote, by slug.
	 *
	 * @var array<string, string>
	 */
	private const WORDS = [
		'e-mail' => 'email',
		'mail' => 'email',
		'email' => 'email',
		'portaal' => 'portal',
		'mijn omgeving' => 'portal',
		'mijn zaken' => 'portal',
		'digitaal loket' => 'portal',
		'post' => 'post',
		'brief' => 'post',
		'papier' => 'post',
		'per post' => 'post',
		'website' => 'website',
		'internet' => 'website',
		'webformulier' => 'website',
		'zgw' => 'zgw-api',
		'zgw-api' => 'zgw-api',
	];

	/**
	 * The parsed URL map, read once.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $map = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig|null $appConfig Holds the administrator's ZGW URL map, or null for none.
	 */
	public function __construct(
		private readonly ?IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * The slug a value is, or null when it names no channel a letter can go by.
	 *
	 * @param mixed $value The stored or incoming value.
	 *
	 * @return string|null The slug.
	 */
	public function toSlug(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		if ($this->isUrl(value: $text) === true) {
			return ($this->map()[$text] ?? self::ZGW);
		}

		$word = strtolower(preg_replace('/\s+/', ' ', $text) ?? $text);
		if (in_array($word, self::SLUGS, true) === true) {
			return $word;
		}

		return (self::WORDS[$word] ?? null);
	}//end toSlug()

	/**
	 * The case fields a value becomes: the slug, plus the original when it was
	 * not already a slug. Empty when there is no value at all.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return array<string, string|null>
	 */
	public function normalise(mixed $value): array {
		if (is_scalar($value) === false || trim((string)$value) === '') {
			return [];
		}

		$text = trim((string)$value);
		$slug = $this->toSlug(value: $text);
		if ($slug === $text) {
			return ['communicationChannel' => $slug];
		}

		return ['communicationChannel' => $slug, 'communicationChannelSource' => $text];
	}//end normalise()

	/**
	 * The ZGW `communicatiekanaal` a case answers with: the URL it came in
	 * with, else the URL the administrator mapped to its slug, else ''.
	 *
	 * @param string|null $slug   The case's slug.
	 * @param string|null $source The value it came in with.
	 *
	 * @return string The URL, or ''.
	 */
	public function toZgwUrl(?string $slug, ?string $source): string {
		$source = trim((string)$source);
		if ($source !== '' && $this->isUrl(value: $source) === true) {
			return $source;
		}

		$slug = trim((string)$slug);
		if ($slug === '') {
			return '';
		}

		$url = array_search($slug, $this->map(), true);
		if ($url === false) {
			return '';
		}

		return (string)$url;
	}//end toZgwUrl()

	/**
	 * The ZGW boundary, inbound: a zaak's `communicatiekanaal` (or whatever a
	 * stored mapping put into `communicationChannel`) becomes a slug, with the
	 * value it came in as kept beside it. An empty `communicatiekanaal` clears
	 * the channel; a body without one leaves the mapped case alone.
	 *
	 * @param array<string, mixed> $body   The Dutch request body.
	 * @param array<string, mixed> $mapped The case fields the mapping produced.
	 *
	 * @return array<string, mixed> The case fields, never holding a URL in the channel.
	 */
	public function inbound(array $body, array $mapped): array {
		$value = ($mapped['communicationChannel'] ?? null);
		if (array_key_exists('communicatiekanaal', $body) === true) {
			$value = $body['communicatiekanaal'];
		}

		unset($mapped['communicationChannel']);
		if ($value === null) {
			return $mapped;
		}

		$fields = $this->normalise(value: $value);
		if ($fields === []) {
			// An empty communicatiekanaal is ZGW's "none": nothing to write.
			return $mapped;
		}

		return array_merge($mapped, $fields);
	}//end inbound()

	/**
	 * The ZGW boundary, outbound: the zaak answers with a URL or ''.
	 *
	 * @param array<string, mixed> $case   The stored case.
	 * @param array<string, mixed> $mapped The zaak the mapping produced.
	 *
	 * @return array<string, mixed> The zaak with its `communicatiekanaal`.
	 */
	public function outbound(array $case, array $mapped): array {
		$slug = null;
		if (is_string($case['communicationChannel'] ?? null) === true) {
			$slug = $case['communicationChannel'];
		}

		$source = null;
		if (is_string($case['communicationChannelSource'] ?? null) === true) {
			$source = $case['communicationChannelSource'];
		}

		$mapped['communicatiekanaal'] = $this->toZgwUrl(slug: $slug, source: $source);

		return $mapped;
	}//end outbound()

	/**
	 * Whether a value is an absolute http(s) URL.
	 *
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	public function isUrl(string $value): bool {
		$scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
		return ($scheme === 'https' || $scheme === 'http') && filter_var($value, FILTER_VALIDATE_URL) !== false;
	}//end isUrl()

	/**
	 * The administrator's URL map, keeping only entries that name a slug.
	 *
	 * @return array<string, string>
	 */
	private function map(): array {
		if ($this->map !== null) {
			return $this->map;
		}

		$this->map = [];
		if ($this->appConfig === null) {
			return $this->map;
		}

		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::MAP_KEY, ''), true);
		if (is_array($decoded) === false) {
			return $this->map;
		}

		foreach ($decoded as $url => $slug) {
			if (is_string($url) === true && is_string($slug) === true && in_array($slug, self::SLUGS, true) === true) {
				$this->map[trim($url)] = $slug;
			}
		}

		return $this->map;
	}//end map()
}//end class
