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

use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\BerichtenboxAdapter\MockAdapter;
use OCA\Dossiq\Service\Beschikking\MockTemplateEngineAdapter;
use OCA\Dossiq\Service\Beschikking\TemplateEngineAdapterInterface;
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
		'unconfiguredMessage',
		'sourceTemplate',
		'reportedOnly',
	];

	/**
	 * The fields design D2 allows inside `adapter`.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_ADAPTER_FIELDS = [
		'configKey',
		'jsonPath',
		'simulatedValues',
		'simulatedMessage',
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
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

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
		// Read the file ourselves rather than letting libxml do it. Nextcloud's
		// `lib/base.php` nulls libxml's external entity loader, and that same
		// resolver is what fetches the PRIMARY document, so under the Nextcloud
		// bootstrap — which is every CI cell — `simplexml_load_file()` returns
		// false for a perfectly valid file. `simplexml_load_string()` never
		// reaches the loader.
		$source = file_get_contents($this->root() . '/appinfo/info.xml');
		$this->assertNotFalse(condition: $source);

		$infoXml = simplexml_load_string((string)$source);

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string)$infoXml->id, actual: $declaration['app']);
		$this->assertSame(expected: ['app', 'connections'], actual: array_keys($declaration));
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

		$this->assertSame(expected: IntegrationStatusService::KEYS, actual: $keys);
		$this->assertCount(expectedCount: 12, haystack: $keys);
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

			$this->assertSame(
				expected: [],
				actual: array_diff(array_keys($connection), self::ALLOWED_FIELDS),
				message: $key . ' carries a field D2 does not allow'
			);
			$this->assertMatchesRegularExpression(pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/', string: $key);
			$this->assertNotSame(expected: '', actual: trim((string)($connection['title'] ?? '')), message: $key . ' has no title');
			$this->assertIsInt(actual: $connection['order']);
			$this->assertGreaterThan(expected: $previousOrder, actual: $connection['order'], message: $key . ' breaks the page order');
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

		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $raw);
		$this->assertStringNotContainsString(needle: ' -- ', haystack: $raw);
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
			$this->assertStringStartsWith(prefix: '/settings/admin/dossiq#section-', string: $url, message: $connection['key']);
			$anchor = substr($url, strpos($url, '#') + 1);
			$this->assertStringContainsString(
				needle: 'id="' . $anchor . '"',
				haystack: $adminRoot,
				message: $connection['key'] . ' links to a missing section'
			);
			$linked++;
		}

		$this->assertSame(expected: 7, actual: $linked);
		$this->assertStringNotContainsString(needle: 'id="section-pdok"', haystack: $adminRoot);
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

		$this->assertSame(expected: IntegrationStatusService::SAVE_REQUIRED_KEYS, actual: $declared);
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
			$this->assertArrayNotHasKey(key: 'requiredConfig', array: $byKey[$key]);
			$this->assertArrayNotHasKey(key: 'adapter', array: $byKey[$key]);
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

		$this->assertSame(expected: ['kvk'], actual: array_column($unavailable, 'key'));
		$message = (string)$this->connectionsByKey()['kvk']['unavailableMessage'];
		$this->assertMatchesRegularExpression(pattern: '/built and bound/i', string: $message);
		$this->assertDoesNotMatchRegularExpression(pattern: '/not built/i', string: $message);
	}//end testOnlyKvkIsUnavailableAndSaysWhy()

	/**
	 * BRP names the key that wakes it, and offers no settings link.
	 *
	 * BRP is built and called, and reaches nothing until its tier key moves off
	 * `log`. No admin section writes that key, so the row's message is the only
	 * place an integrator learns it (REQ-ADMIN-019).
	 *
	 * @return void
	 */
	public function testBrpNamesTheKeyThatWakesIt(): void {
		$brp = $this->connectionsByKey()['brp'];

		$message = (string)($brp['unconfiguredMessage'] ?? '');

		$this->assertMatchesRegularExpression(pattern: '/integration\\.brp\\.mode/', string: $message);
		$this->assertDoesNotMatchRegularExpression(pattern: '/not built/i', string: $message);
		$this->assertArrayNotHasKey(key: 'settingsUrl', array: $brp);
		$this->assertArrayNotHasKey(key: 'available', array: $brp);
	}//end testBrpNamesTheKeyThatWakesIt()

	/**
	 * An adapter block uses only D2 fields, and `simulatedValues` is a list of strings.
	 *
	 * @return void
	 */
	public function testAdapterBlocksUseOnlyD2Fields(): void {
		foreach ($this->declaration()['connections'] as $connection) {
			if (isset($connection['adapter']) === false) {
				continue;
			}

			$this->assertSame(
				expected: [],
				actual: array_diff(array_keys($connection['adapter']), self::ALLOWED_ADAPTER_FIELDS),
				message: $connection['key'] . ' carries an adapter field D2 does not allow'
			);
			foreach (($connection['adapter']['simulatedValues'] ?? []) as $value) {
				$this->assertIsString(actual: $value, message: $connection['key']);
			}
		}
	}//end testAdapterBlocksUseOnlyD2Fields()

	/**
	 * A seam reads Simulated exactly when a mock is really what answers.
	 *
	 * Contract D4 rule 3 matches the key's value against `simulatedValues`,
	 * and an admin who typed the mock class into the key would otherwise read
	 * Configured under rule 5 while the mock answered. So each list names the
	 * class, and that class must exist and implement the seam, or the entry
	 * matches a value nothing can bind.
	 *
	 * THE TWO SEAMS NO LONGER AGREE ABOUT THE EMPTY STRING, and that is the
	 * point of this test. `beschikking_template_adapter` still falls back to
	 * its mock, so empty is simulated there. `berichtenbox_adapter` falls back
	 * to {@see \OCA\Dossiq\Service\BerichtenboxAdapter\IntegriqAdapter}, which
	 * refuses rather than simulating, so listing empty there told an admin a
	 * mock was answering when nothing was. `mock` is listed beside the class
	 * because the registrar accepts it as a shorthand.
	 *
	 * @return void
	 */
	public function testMockBackedSeamsNameTheirMockAsSimulated(): void {
		$byKey = $this->connectionsByKey();
		$seams = [
			'berichtenbox' => [
				[MockAdapter::class, 'mock'],
				MockAdapter::class,
				BerichtenboxAdapterInterface::class,
			],
			'templates' => [
				['', MockTemplateEngineAdapter::class],
				MockTemplateEngineAdapter::class,
				TemplateEngineAdapterInterface::class,
			],
		];

		foreach ($seams as $key => [$expected, $mockClass, $interface]) {
			$values = $byKey[$key]['adapter']['simulatedValues'] ?? null;

			$this->assertSame(expected: $expected, actual: $values, message: $key);
			$this->assertTrue(condition: is_a($mockClass, $interface, true), message: $mockClass . ' does not implement ' . $interface);
		}

		// The default adapter refuses, so an instance that set nothing is not
		// simulating. A regression that put '' back here would say it was.
		$this->assertNotContains(
			needle: '',
			haystack: $byKey['berichtenbox']['adapter']['simulatedValues'],
			message: 'an empty berichtenbox_adapter binds the integriq adapter, which never simulates'
		);
	}//end testMockBackedSeamsNameTheirMockAsSimulated()

	/**
	 * The Berichtenbox row names the key an administrator has to set.
	 *
	 * The seam is bound and real, and a send still cannot leave until
	 * `digital_post_source` names an integriq digital post source: integriq's
	 * `DigitalPostService::sourceConfig()` returns null on the empty string
	 * before it looks anything up. No admin section writes the key, so the row
	 * has to name it, the way the BRP row names its own.
	 *
	 * @return void
	 */
	public function testBerichtenboxNamesTheSourceKeyItNeeds(): void {
		$row = $this->connectionsByKey()['berichtenbox'];

		$this->assertSame(expected: ['digital_post_source'], actual: $row['requiredConfig']);
		$this->assertStringContainsString(needle: 'digital_post_source', haystack: $row['unconfiguredMessage']);
		$this->assertArrayNotHasKey(key: 'settingsUrl', array: $row);
	}//end testBerichtenboxNamesTheSourceKeyItNeeds()

	/**
	 * No dossiq row is `reportedOnly`.
	 *
	 * The flag makes integriq skip rules 3 and 5. Every dossiq row that has an
	 * adapter key or required settings is one integriq can judge from app
	 * config, and the rows only a probe can judge (StUF, mailbox, store) carry
	 * neither, so the flag would change nothing on them.
	 *
	 * @return void
	 */
	public function testNoRowIsReportedOnly(): void {
		foreach ($this->declaration()['connections'] as $connection) {
			$this->assertArrayNotHasKey(key: 'reportedOnly', array: $connection, message: $connection['key']);
		}
	}//end testNoRowIsReportedOnly()
}//end class
