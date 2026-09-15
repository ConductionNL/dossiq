<?php

/**
 * The five channels a case arrives through, as objects nobody switches on by accident.
 *
 * Two properties carry the whole point of holding the channels as objects, and
 * both of them fail silently if they break. A source created enabled starts
 * polling a mailbox nobody configured. An upgrade that rewrote `enabled` turns
 * a channel an administrator deliberately switched off back on, months later,
 * with no line anywhere saying it did.
 *
 * The catalogue is driven as data rather than asserted against a literal list,
 * so adding a sixth channel does not need this file edited, and a channel
 * declared with no slug still cannot reach the register.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Intake\IntakeSourceSeeder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The intake-source catalogue and the upsert over it.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */
class IntakeSourceSeederTest extends TestCase {

	/**
	 * A seeder wired to a container that answers nothing.
	 *
	 * @return IntakeSourceSeeder The seeder.
	 */
	private function seeder(): IntakeSourceSeeder {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not here'));

		return new IntakeSourceSeeder($container, new NullLogger());
	}//end seeder()

	/**
	 * The catalogue names the five channels dossiq handles.
	 *
	 * @return void
	 */
	public function testCatalogueCarriesTheDeclaredChannels(): void {
		$slugs = array_column($this->seeder()->catalogue(), 'slug');

		$this->assertSame(
			['dossiq-mail', 'dossiq-portal', 'dossiq-api', 'dossiq-kcc', 'dossiq-dso'],
			$slugs
		);
	}//end testCatalogueCarriesTheDeclaredChannels()

	/**
	 * Every channel names a transport the intake-source schema accepts.
	 *
	 * The schema's `kind` enum is the transport and takes three values. A
	 * fourth is refused on save, one channel at a time, and the seed reports
	 * it as a refusal rather than as an absence.
	 *
	 * @return void
	 */
	public function testEveryChannelNamesATransportTheSchemaAccepts(): void {
		foreach ($this->seeder()->catalogue() as $source) {
			$this->assertContains(
				$source['kind'],
				['watchedFolder', 'mailbox', 'endpoint'],
				sprintf('%s declares a kind the intake-source schema does not take', $source['slug'])
			);
		}
	}//end testEveryChannelNamesATransportTheSchemaAccepts()

	/**
	 * Every channel carries the words a list needs.
	 *
	 * @return void
	 */
	public function testEveryChannelCarriesATitleAndADescription(): void {
		foreach ($this->seeder()->catalogue() as $source) {
			$this->assertNotEmpty($source['title'], $source['slug'] . ' has no title');
			$this->assertNotEmpty($source['description'], $source['slug'] . ' has no description');
		}
	}//end testEveryChannelCarriesATitleAndADescription()

	/**
	 * A new row is written switched off.
	 *
	 * @return void
	 */
	public function testANewChannelIsCreatedSwitchedOff(): void {
		foreach ($this->seeder()->catalogue() as $source) {
			$row = $this->seeder()->newRow($source);

			$this->assertFalse($row['enabled'], $source['slug'] . ' would start polling on install');
			$this->assertSame($source['slug'], $row['slug']);
			$this->assertSame($source['title'], $row['title']);
		}
	}//end testANewChannelIsCreatedSwitchedOff()

	/**
	 * An upgrade refreshes the wording and leaves the switch alone.
	 *
	 * @return void
	 */
	public function testAnUpgradeNeverSwitchesAChannelBackOn(): void {
		$existing = [
			'slug' => 'dossiq-mail',
			'title' => 'Mail (oud)',
			'description' => 'Oude omschrijving',
			'kind' => 'mailbox',
			'enabled' => false,
			'connection' => 'mail-kcc',
			'location' => 'INBOX/Zaken',
			'state' => 'failing',
			'settings' => ['folder' => 'Zaken'],
		];

		// The CATALOGUE ENTRY, not a hand-made one, and that is the whole test.
		// Every declared channel carries `enabled: false` itself, so a seeder
		// that refreshed `enabled` would switch every configured channel off on
		// every upgrade. Driving a trimmed source instead would pass either way,
		// because the field would simply be absent.
		$catalogue = $this->catalogueEntry('dossiq-mail');
		$refreshed = $this->seeder()->refreshedRow($existing, $catalogue);

		$this->assertSame($catalogue['title'], $refreshed['title']);
		$this->assertSame($catalogue['description'], $refreshed['description']);

		// The five an administrator owns.
		$this->assertFalse($refreshed['enabled']);
		$this->assertSame('mail-kcc', $refreshed['connection']);
		$this->assertSame('INBOX/Zaken', $refreshed['location']);
		$this->assertSame('failing', $refreshed['state']);
		$this->assertSame(['folder' => 'Zaken'], $refreshed['settings']);
	}//end testAnUpgradeNeverSwitchesAChannelBackOn()

	/**
	 * An enabled channel stays enabled too, which is the other direction.
	 *
	 * @return void
	 */
	public function testAnEnabledChannelStaysEnabled(): void {
		$refreshed = $this->seeder()->refreshedRow(
			['slug' => 'dossiq-portal', 'enabled' => true],
			$this->catalogueEntry('dossiq-portal')
		);

		$this->assertTrue($refreshed['enabled']);
	}//end testAnEnabledChannelStaysEnabled()

	/**
	 * One declared channel, as the catalogue ships it.
	 *
	 * @param string $slug The channel's slug.
	 *
	 * @return array<string, mixed> The catalogue entry.
	 */
	private function catalogueEntry(string $slug): array {
		foreach ($this->seeder()->catalogue() as $source) {
			if ($source['slug'] === $slug) {
				return $source;
			}
		}

		$this->fail('The catalogue declares no channel ' . $slug);
	}//end catalogueEntry()

	/**
	 * Without OpenRegister the seed writes nothing and says so.
	 *
	 * A repair step that threw here would abort the upgrade. The register
	 * belongs to OpenRegister and is seeded by its own repair step, so its
	 * absence is a "not yet", not a failure.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheSeedReportsRatherThanThrows(): void {
		$result = $this->seeder()->seed();

		$this->assertFalse($result['available']);
		$this->assertSame(0, $result['created']);
		$this->assertSame(0, $result['updated']);
		$this->assertSame([], $result['refused']);
	}//end testWithoutOpenRegisterTheSeedReportsRatherThanThrows()
}//end class
