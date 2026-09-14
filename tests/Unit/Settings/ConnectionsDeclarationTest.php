<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of dossiq's Integrations page. Nothing in dossiq reads it at runtime, so
 * a broken file fails nowhere in this repo: integriq skips it whole and the
 * page goes empty on some other instance. Every assertion here is a way that
 * file could go wrong without a sound.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\IntegrationStatusService;
use PHPUnit\Framework\TestCase;

/**
 * Guards lib/Settings/connections.json against design D2 of connection-registry.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * The fields design D2 allows on one connection.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_FIELDS = [
		'key',
		'title',
		'description',
		'order',
		'settingsUrl',
		'requiredConfig',
		'adapter',
		'available',
		'unavailableMessage',
		'sourceTemplate',
	];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString($raw, 'lib/Settings/connections.json must exist');

		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The declared connections, keyed by connection key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function connectionsByKey(): array {
		$byKey = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$byKey[$connection['key']] = $connection;
		}

		return $byKey;
	}//end connectionsByKey()

	/**
	 * The file names the app it ships in, and nothing else at the top level.
	 *
	 * Integriq refuses a file whose `app` differs from the app it was read from.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$declaration = $this->declaration();
		$infoXml = simplexml_load_file($this->root() . '/appinfo/info.xml');

		$this->assertNotFalse($infoXml);
		$this->assertSame((string)$infoXml->id, $declaration['app']);
		$this->assertSame(['app', 'connections'], array_keys($declaration));
	}//end testTheFileNamesThisApp()

	/**
	 * The twelve keys the page used before, in the same order, and no other.
	 *
	 * A row is keyed by app and key, and the e2e suite asserts these keys, so a
	 * renamed key orphans a row and breaks the test's meaning at once.
	 *
	 * @return void
	 */
	public function testTheTwelveKeysAreTheOnesTheServiceAccepts(): void {
		$keys = array_column($this->declaration()['connections'], 'key');

		$this->assertSame(IntegrationStatusService::KEYS, $keys);
		$this->assertCount(12, $keys);
	}//end testTheTwelveKeysAreTheOnesTheServiceAccepts()

	/**
	 * Every entry uses only D2 fields, a valid key, a title and a rising order.
	 *
	 * @return void
	 */
	public function testEveryEntryHasTheShapeIntegriqValidates(): void {
		$previousOrder = 0;
		foreach ($this->declaration()['connections'] as $connection) {
			$key = (string)$connection['key'];

			$this->assertSame([], array_diff(array_keys($connection), self::ALLOWED_FIELDS), $key . ' carries a field D2 does not allow');
			$this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key);
			$this->assertNotSame('', trim((string)($connection['title'] ?? '')), $key . ' has no title');
			$this->assertIsInt($connection['order']);
			$this->assertGreaterThan($previousOrder, $connection['order'], $key . ' breaks the page order');
			$previousOrder = $connection['order'];
		}
	}//end testEveryEntryHasTheShapeIntegriqValidates()

	/**
	 * No text a reader sees carries an em-dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$raw = (string)file_get_contents($this->root() . '/lib/Settings/connections.json');

		$this->assertStringNotContainsString("\u{2014}", $raw);
		$this->assertStringNotContainsString(' -- ', $raw);
	}//end testNoTextCarriesAnEmDash()

	/**
	 * Every settings link lands on a section the admin page really has.
	 *
	 * A link into a section that does not exist scrolls nowhere and logs
	 * nothing, which is the untruth this page exists to stop.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtAnExistingSection(): void {
		$adminRoot = (string)file_get_contents($this->root() . '/src/views/settings/AdminRoot.vue');
		$linked = 0;

		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string)$connection['settingsUrl'];
			$this->assertStringStartsWith('/settings/admin/dossiq#section-', $url, $connection['key']);
			$anchor = substr($url, strpos($url, '#') + 1);
			$this->assertStringContainsString('id="' . $anchor . '"', $adminRoot, $connection['key'] . ' links to a missing section');
			$linked++;
		}

		$this->assertSame(7, $linked);
		$this->assertStringNotContainsString('id="section-pdok"', $adminRoot);
	}//end testEverySettingsLinkPointsAtAnExistingSection()

	/**
	 * The keys a save refreshes are exactly the declared config keys.
	 *
	 * Integriq decides a status from `requiredConfig` and `adapter.configKey`.
	 * If the service asked for a refresh on different keys, a save would change
	 * a value integriq reads and nobody would tell it.
	 *
	 * @return void
	 */
	public function testTheSaveMapMatchesTheDeclaredConfigKeys(): void {
		$declared = [];
		foreach ($this->declaration()['connections'] as $connection) {
			$keys = ($connection['requiredConfig'] ?? []);
			if (isset($connection['adapter']['configKey']) === true) {
				$keys[] = $connection['adapter']['configKey'];
			}

			$keys = array_values(array_unique($keys));
			if ($keys !== []) {
				$declared[$connection['key']] = $keys;
			}
		}

		$this->assertSame(IntegrationStatusService::SAVE_REQUIRED_KEYS, $declared);
	}//end testTheSaveMapMatchesTheDeclaredConfigKeys()

	/**
	 * The connections dossiq probes itself declare no config keys.
	 *
	 * They arrive as reports. A `requiredConfig` on them would let a saved form
	 * read as Configured before any probe ran.
	 *
	 * @return void
	 */
	public function testProbedConnectionsArriveAsReports(): void {
		$byKey = $this->connectionsByKey();

		foreach (['stuf', 'mailbox', 'store'] as $key) {
			$this->assertArrayNotHasKey('requiredConfig', $byKey[$key]);
			$this->assertArrayNotHasKey('adapter', $byKey[$key]);
		}
	}//end testProbedConnectionsArriveAsReports()

	/**
	 * Only KvK is declared unavailable, and it says why in words that are true.
	 *
	 * BRP and KvK were both once marked "Specified, not built yet", which was
	 * false for both. KvK's adapter is built and bound and nothing calls it.
	 *
	 * @return void
	 */
	public function testOnlyKvkIsUnavailableAndSaysWhy(): void {
		$unavailable = array_filter(
			$this->declaration()['connections'],
			static fn (array $connection): bool => ($connection['available'] ?? true) === false
		);

		$this->assertSame(['kvk'], array_column($unavailable, 'key'));
		$message = (string)$this->connectionsByKey()['kvk']['unavailableMessage'];
		$this->assertMatchesRegularExpression('/built and bound/i', $message);
		$this->assertDoesNotMatchRegularExpression('/not built/i', $message);
	}//end testOnlyKvkIsUnavailableAndSaysWhy()
}//end class
