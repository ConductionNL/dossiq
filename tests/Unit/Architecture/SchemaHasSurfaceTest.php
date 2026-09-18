<?php

/**
 * Structural guard: every schema dossiq registers can be reached.
 *
 * A registered schema is storage. The instance creates it on install, the
 * descriptor tells anyone reading it that the capability is there, and if no
 * page, controller or service ever names it then the capability is not there.
 * That is storage ahead of the feature, and a privacy question the moment the
 * schema holds personal data: `supplierUser` carries an activation token and
 * an eHerkenning level, `avgIncident` is a datalek register.
 *
 * 🔑 IT MATCHES QUOTED SLUGS, NOT WORDS, and `toestemming` is why. The word is
 * ordinary Dutch and appears all over `lib/` in refusal messages: "U heeft geen
 * toestemming om deze actie uit te voeren". A word-bounded grep calls the
 * schema reached and it is not. The mirror mistake is in this directory too:
 * `DeclaredDisplayFlagHasReaderTest` records a scanner that matched names and
 * opened a defect against a control that worked.
 *
 * It fails in BOTH directions. A schema with no surface and no allowlist entry
 * fails, which is the point. An allowlisted schema that has since gained one
 * also fails, so the list can only shrink deliberately rather than quietly
 * outliving the reason it was written.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every declared schema has a surface, or a reason-bearing allowlist entry.
 *
 * @covers \OCA\Dossiq\Tests\Unit\Architecture\SchemaSurfaceScanner
 */
class SchemaHasSurfaceTest extends TestCase {
	/**
	 * The repository root.
	 *
	 * @var string
	 */
	private const ROOT = __DIR__.'/../../..';

	/**
	 * The allowlist of schemas nothing shows yet.
	 *
	 * @var string
	 */
	private const ALLOWLIST = __DIR__.'/schema-surface.allowlist.json';

	/**
	 * Registers whose schemas are known by construction.
	 *
	 * @var string
	 */
	private const FIXTURES = __DIR__.'/fixtures/schemaSurface';

	/**
	 * The scanner pointed at the real register.
	 *
	 * @return SchemaSurfaceScanner The scanner under test.
	 */
	private function scanner(): SchemaSurfaceScanner {
		return new SchemaSurfaceScanner(
			registerFiles: array_merge(
				[self::ROOT.'/lib/Settings/dossiq_register.json'],
				(glob(self::ROOT.'/lib/Settings/register.d/*.json') ?: [])
			),
			manifestFile: self::ROOT.'/src/manifest.json',
			sourceDirs: [self::ROOT.'/lib', self::ROOT.'/src'],
			// The descriptors themselves never count. A schema mentioned only
			// by the file that declares it, or seeded into the mock register,
			// is precisely the case under test.
			excludeDirs: [self::ROOT.'/lib/Settings'],
		);
	}

	/**
	 * The allowlist, as written.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function allowlist(): array {
		$decoded = json_decode((string)file_get_contents(self::ALLOWLIST), true);
		$this->assertIsArray($decoded, 'the allowlist is valid JSON');

		return $decoded;
	}

	/**
	 * The slugs the allowlist excuses.
	 *
	 * @return array<int, string> The slugs.
	 */
	private function allowlisted(): array {
		return array_map(
			static fn (array $entry): string => (string)($entry['slug'] ?? ''),
			($this->allowlist()['entries'] ?? [])
		);
	}

	/**
	 * No schema is registered without a surface or an entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testEveryDeclaredSchemaHasASurface(): void {
		$unexcused = array_values(
			array_diff($this->scanner()->orphanSlugs(), $this->allowlisted())
		);

		$this->assertSame(
			[],
			$unexcused,
			"These schemas are registered and nothing shows them:\n  ".implode("\n  ", $unexcused)
				."\nGive each one a page, a reference under lib/ outside lib/Settings, or an entry in "
				.basename(self::ALLOWLIST)." naming the change that will surface it. Registering storage "
				."for a capability the edition cannot use is the thing this test exists to stop."
		);
	}

	/**
	 * The allowlist only shrinks: an entry whose schema has a surface fails.
	 *
	 * Without this the list outlives its reasons. A schema that gained a page
	 * six months ago would sit here for ever, and the ceiling would stop
	 * meaning anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testTheAllowlistHoldsNoSchemaThatNowHasASurface(): void {
		$orphans = $this->scanner()->orphanSlugs();
		$stale = array_values(array_diff($this->allowlisted(), $orphans));

		$this->assertSame(
			[],
			$stale,
			"These allowlisted schemas now have a surface; take them off the list:\n  "
				.implode("\n  ", $stale)
		);
	}

	/**
	 * Every entry names who owns it.
	 *
	 * An entry with no reason and no owner is not an entry, it is a silence
	 * with a slug on it. The whole value of the list is that the next reader
	 * can find out why each one is still here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testEveryEntryCarriesAReasonAndAnOwner(): void {
		foreach (($this->allowlist()['entries'] ?? []) as $entry) {
			$slug = (string)($entry['slug'] ?? '');
			$this->assertNotSame('', $slug, 'every entry names a slug');
			$this->assertNotSame(
				'',
				trim((string)($entry['reason'] ?? '')),
				sprintf("the entry for '%s' says why it is still here", $slug)
			);
			$this->assertTrue(
				trim((string)($entry['ownerChange'] ?? '')) !== ''
					|| trim((string)($entry['readerApp'] ?? '')) !== '',
				sprintf("the entry for '%s' names an ownerChange or a readerApp", $slug)
			);
		}
	}

	/**
	 * The ceiling, written down.
	 *
	 * A count nobody asserts is a count that drifts. This is the number the
	 * change measured, and the test above is what stops a 17th arriving
	 * quietly; this one stops the 17th arriving loudly and being waved
	 * through by adding a line here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testTheCeilingOnlyGoesDown(): void {
		$this->assertLessThanOrEqual(
			8,
			count($this->allowlisted()),
			'the allowlist was 16 when it was written, 10 within the day, and 8 once the two schemas holding personal data got pages; it may only shrink, and a new schema needs a surface rather than a line here'
		);
	}

	/**
	 * A schema nothing shows is found, over a register known by construction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testAnOrphanInAFixtureRegisterIsFound(): void {
		$orphans = $this->fixtureScanner()->orphanSlugs();

		$this->assertContains('orphan', $orphans);
	}

	/**
	 * A schema on a page passes, and so does a child of one.
	 *
	 * The one-hop branch is the half most likely to be wrong in the quiet
	 * direction: without it every child collection on every detail page reads
	 * as an orphan, and the test would name forty schemas that are on screen.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testAShownSchemaAndItsChildBothPass(): void {
		$orphans = $this->fixtureScanner()->orphanSlugs();

		$this->assertNotContains('shownCase', $orphans, 'a schema on a page has a surface');
		$this->assertNotContains('caseObject', $orphans, 'a child of a shown parent has a surface');
	}

	/**
	 * A schema named only in code still passes, and only when it is quoted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testACodeReferenceCountsAndProseDoesNot(): void {
		$orphans = $this->fixtureScanner()->orphanSlugs();

		$this->assertNotContains('quotedInCode', $orphans, 'a quoted slug in a service is a surface');
		// The `toestemming` case in miniature: the slug appears in the same
		// file as a bare word inside a sentence, and that is not a reader.
		$this->assertContains('mentionedInProse', $orphans, 'a word in a comment is not a surface');
	}

	/**
	 * A child of an ORPHAN parent is still an orphan.
	 *
	 * The control for the one-hop branch. Without it, a scanner that let any
	 * referenced schema pass would report zero orphans on a register where a
	 * whole disconnected island of schemas refers only to itself, which is
	 * exactly what `50-sociaal-domein.json` looks like.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md
	 */
	public function testAChildOfAnOrphanIsStillAnOrphan(): void {
		$orphans = $this->fixtureScanner()->orphanSlugs();

		$this->assertContains('orphanChild', $orphans);
	}

	/**
	 * The scanner pointed at the fixture register.
	 *
	 * @return SchemaSurfaceScanner The scanner.
	 */
	private function fixtureScanner(): SchemaSurfaceScanner {
		return new SchemaSurfaceScanner(
			registerFiles: [self::FIXTURES.'/register.json'],
			manifestFile: self::FIXTURES.'/manifest.json',
			sourceDirs: [self::FIXTURES.'/src'],
			excludeDirs: [],
		);
	}
}//end class
