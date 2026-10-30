<?php

/**
 * Sweeps the shipped statutory terms against the shipped case types.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Tests\Unit\Settings\ShippedPackageCaseTypes;
use PHPUnit\Framework\TestCase;

/**
 * A case type that declares a processingDeadline must have a term to run.
 *
 * The shipped termijn seed named three case-type slugs — omgevingsvergunning-
 * regulier, wmo-melding, woo-verzoek — that NO shipped seed ever creates. So
 * on a fresh install every definition was an orphan, no case could ever match
 * one, and no FlowTimer armed: zero flow_timers and zero deadlineInstance rows
 * across seven cases. Nothing failed; the clock simply never started.
 *
 * The rule below is mechanical rather than curated. A case type that declares
 * a `processingDeadline` is one where a statutory term runs, so it must have a
 * TermijnDefinitie, and that definition's duration must be the one the case
 * type itself declares. A case type with no processingDeadline (a toezichtzaak
 * has none) is exempt by its own data, not by a list someone maintains.
 *
 * @coversNothing
 */
class ShippedDeadlineDefinitionCoverageTest extends TestCase {

	/**
	 * Seed files that create case types on a fresh install.
	 *
	 * Driven by a repair step listed in info.xml's `<install>` block:
	 * CaseFlowSeedDataRepairStep. The case definition packages are read next
	 * to it, through ShippedPackageCaseTypes.
	 *
	 * @var array<int, string>
	 */
	private const CASE_TYPE_SEEDS = [
		'/../../../lib/Settings/case_flow_seed_data.json',
	];

	/**
	 * Case types whose term cannot be stated generically, decided by nobody yet.
	 *
	 * Ruben's rule (10 Oct) is that such a term is not invented. Listed here so
	 * the sweep stays mechanical for everything else, and held to the list by
	 * testTheOpenQuestionsAreStillOpen(): an entry that gets a definition, or
	 * stops being shipped, fails until it is taken off.
	 *
	 * Empty since 11 Oct: Ruben decided the last two, `subsidieaanvraag` and
	 * `zaaktype-innovatiefonds-2026`, onto the Awb 4:13 lid 2 fallback. They
	 * are pinned in DECIDED.
	 *
	 * @var array<string, string>
	 */
	private const OPEN_QUESTIONS = [];

	/**
	 * The terms decided for the register's case types (Ruben, 10 and 11 Oct).
	 *
	 * Keyed by the binding key CaseTypeSlugResolver answers (identifier first).
	 * Each row: kind, days, extension capacity, number of extensions, and a
	 * fragment the legal basis must contain.
	 *
	 * @var array<string, array{0: string, 1: int, 2: int, 3: int, 4: string}>
	 */
	private const DECIDED = [
		'klacht-behandeling' => ['statutory', 42, 28, 1, 'Awb 9:11'],
		// Awb 7:10: six weeks, one extension of at most six weeks (the Bezwaar case type of the Woo objections).
		'bezwaar' => ['statutory', 42, 42, 1, 'Awb 7:10'],
		'evenementenvergunning-2024' => ['statutory', 56, 56, 1, 'APV 1:2'],
		'evenementenvergunning' => ['statutory', 56, 56, 1, 'APV 1:2'],
		'zaaktype-cultuur-subsidie-2026' => ['statutory', 56, 0, 0, 'Awb 4:13'],
		// Ruben, 11 Oct: eight weeks under the Awb 4:13 lid 2 fallback, with
		// Awb 4:14 lid 3 as the notice of a later term. Before: P42D plus
		// P28D and P13W (ASV art. 3), numbers no generic rule gives.
		'subsidieaanvraag' => ['statutory', 56, 0, 0, 'Awb 4:13'],
		'zaaktype-innovatiefonds-2026' => ['statutory', 56, 0, 0, 'Awb 4:13'],
		// Ruben, 23 Oct: eight weeks for a benefit application; the
		// Participatiewet sets no decision term of its own, so Awb 4:13 lid 2.
		'bijstandsaanvraag' => ['statutory', 56, 0, 0, 'Awb 4:13'],
		// Ruben, 23 Oct: a Jeugdwet report on the same fallback.
		'melding-jeugdwet' => ['statutory', 56, 0, 0, 'Awb 4:13'],
		'melding-openbare-ruimte' => ['planned', 14, 0, 0, 'Servicenorm'],
		'parkeervergunning-bewoners' => ['statutory', 56, 0, 0, 'Awb 4:13'],
		'kenteken-wijzigen-parkeervergunning' => ['planned', 14, 0, 0, 'Servicenorm'],
		'building-permit' => ['planned', 56, 0, 0, 'Service norm'],
		'grant-application' => ['planned', 42, 0, 0, 'Service norm'],
		'citizen-complaint' => ['planned', 42, 0, 0, 'Service norm'],
		'foi-request' => ['planned', 30, 0, 0, 'Service norm'],
	];

	/**
	 * Read and decode one shipped settings file.
	 *
	 * @param string $relative The path relative to this file.
	 *
	 * @return array<string, mixed> The decoded contents.
	 */
	private function readJson(string $relative): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . $relative), true);
		self::assertIsArray($decoded, 'The shipped file ' . $relative . ' must parse.');

		return $decoded;
	}//end readJson()

	/**
	 * Every case type a fresh install creates, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>> The case types.
	 */
	private function shippedCaseTypes(): array {
		$caseTypes = [];
		foreach (self::CASE_TYPE_SEEDS as $seed) {
			foreach (($this->readJson($seed)['caseTypes'] ?? []) as $caseType) {
				$slug = trim((string)($caseType['slug'] ?? ($caseType['identifier'] ?? '')));
				if ($slug === '') {
					continue;
				}

				$caseTypes[$slug] = $caseType;
			}
		}

		// Only the packages a fresh install installs. A package installed on
		// request (bezwaar-beroep, besluitvorming, subsidie) ships no deadline
		// definition yet; that is a gap in those packages, not a silent one on
		// install, and it is theirs to close.
		foreach (ShippedPackageCaseTypes::nested(install: 'auto') as $caseType) {
			$slug = trim((string)$caseType['slug']);
			if ($slug !== '') {
				$caseTypes[$slug] = $caseType;
			}
		}

		return array_merge($caseTypes, $this->registerCaseTypes());
	}//end shippedCaseTypes()

	/**
	 * Every case type the register ships, keyed the way a case resolves it.
	 *
	 * A case carries its case type as a uuid, and CaseTypeSlugResolver turns
	 * that into the binding key: the `identifier` first, then `@self.slug`.
	 * Keying by the seed slug instead would let a definition pass this test
	 * and bind to nothing, because `building-permit-type` is not what a case
	 * of that type resolves to (`building-permit` is).
	 *
	 * @return array<string, array<string, mixed>> The case types.
	 */
	private function registerCaseTypes(): array {
		$files = array_merge(
			[__DIR__ . '/../../../lib/Settings/dossiq_register.json'],
			(glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') ?: [])
		);

		$caseTypes = [];
		foreach ($files as $file) {
			$decoded = json_decode((string)file_get_contents($file), true);
			self::assertIsArray($decoded, 'The shipped file ' . basename($file) . ' must parse.');
			foreach (($decoded['components']['objects'] ?? []) as $object) {
				if (($object['@self']['schema'] ?? '') !== 'caseType') {
					continue;
				}

				$key = trim((string)($object['identifier'] ?? ''));
				if ($key === '') {
					$key = trim((string)($object['@self']['slug'] ?? ''));
				}

				if ($key !== '') {
					$caseTypes[$key] = $object;
				}
			}
		}

		return $caseTypes;
	}//end registerCaseTypes()

	/**
	 * The shipped TermijnDefinities, keyed by the case-type slug they bind to.
	 *
	 * @return array<string, array<string, mixed>> The definitions.
	 */
	private function shippedDefinitions(): array {
		$definitions = [];
		foreach (($this->readJson('/../../../lib/Settings/termijnbewaking_seed_data.json')['termijnDefinities'] ?? []) as $definition) {
			$slug = trim((string)($definition['caseType'] ?? ''));
			if ($slug === '') {
				continue;
			}

			$definitions[$slug] = $definition;
		}

		return $definitions;
	}//end shippedDefinitions()

	/**
	 * Whole days in an ISO-8601 `P{n}D` or `P{n}W` duration, or null.
	 *
	 * Weeks count: the subsidy schemes and the event permits declare P8W and
	 * P13W, and reading only days let them through the sweep unseen.
	 *
	 * @param mixed $duration The declared duration.
	 *
	 * @return int|null The days.
	 */
	private function days(mixed $duration): ?int {
		if (is_string($duration) !== true || preg_match('/^P(\d+)([DW])$/', trim($duration), $matches) !== 1) {
			return null;
		}

		if ($matches[2] === 'W') {
			return ((int)$matches[1] * 7);
		}

		return (int)$matches[1];
	}//end days()

	/**
	 * THE SWEEP: no deadline-bearing case type may ship without a term.
	 *
	 * @return void
	 */
	public function testEveryShippedCaseTypeWithADeadlineHasATermijnDefinitie(): void {
		$caseTypes = $this->shippedCaseTypes();
		$definitions = $this->shippedDefinitions();

		self::assertNotSame([], $caseTypes, 'The shipped seeds must create case types, or this sweep is vacuous.');
		self::assertNotSame([], $definitions, 'The shipped seed must create TermijnDefinities, or this sweep is vacuous.');

		$uncovered = [];
		foreach ($caseTypes as $slug => $caseType) {
			if ($this->days($caseType['processingDeadline'] ?? null) === null) {
				// No statutory beslistermijn declared; a toezicht- or
				// handhavingszaak legitimately runs without one.
				continue;
			}

			if (array_key_exists($slug, $definitions) === true || array_key_exists($slug, self::OPEN_QUESTIONS) === true) {
				continue;
			}

			$uncovered[] = $slug;
		}

		self::assertSame(
			[],
			$uncovered,
			"These shipped case types declare a processingDeadline but no shipped TermijnDefinitie binds to them,\n"
			. "so a case of that type starts NO statutory clock on a fresh install and nothing says so.\n"
			. "A definition binds by case-type SLUG (deadlineDefinition.caseType), so add one to\n"
			. "lib/Settings/termijnbewaking_seed_data.json for:\n - "
			. implode("\n - ", $uncovered)
		);
	}//end testEveryShippedCaseTypeWithADeadlineHasATermijnDefinitie()

	/**
	 * A definition's duration must be the one its case type declares.
	 *
	 * Two numbers for the same statutory term is a defect either way round —
	 * the dashboard shows one deadline while the timer fires on the other.
	 *
	 * @return void
	 */
	public function testEachDefinitionMatchesItsCaseTypesDeclaredDeadline(): void {
		$definitions = $this->shippedDefinitions();

		$mismatched = [];
		foreach ($this->shippedCaseTypes() as $slug => $caseType) {
			$declared = $this->days($caseType['processingDeadline'] ?? null);
			$definition = ($definitions[$slug] ?? null);
			if ($declared === null || $definition === null) {
				continue;
			}

			$configured = (int)($definition['standardDurationDays'] ?? 0);
			if ($configured !== $declared) {
				$mismatched[] = $slug . ': case type says ' . $declared . ' days, definition says ' . $configured;
			}

			// A service norm has no statutory extension to agree with.
			if (($definition['kind'] ?? 'statutory') !== 'statutory') {
				continue;
			}

			$extension = $this->days($caseType['extensionDuration'] ?? ($caseType['extensionPeriod'] ?? null));
			if ($extension !== null && (int)($definition['extensionCapacity'] ?? 0) !== $extension) {
				$mismatched[] = $slug . ': case type allows ' . $extension . ' extension days, definition allows '
					. (int)($definition['extensionCapacity'] ?? 0);
			}
		}

		self::assertSame([], $mismatched, "Shipped term durations disagree with the case types they bind to:\n - " . implode("\n - ", $mismatched));
	}//end testEachDefinitionMatchesItsCaseTypesDeclaredDeadline()

	/**
	 * At least one shipped definition binds to a shipped case type.
	 *
	 * The measured failure state was that NONE did, which every other
	 * assertion in this file would have passed vacuously.
	 *
	 * @return void
	 */
	public function testAtLeastOneShippedDefinitionBindsToAShippedCaseType(): void {
		$bound = array_intersect(array_keys($this->shippedDefinitions()), array_keys($this->shippedCaseTypes()));

		self::assertNotSame(
			[],
			$bound,
			'Not one shipped TermijnDefinitie binds to a case type a fresh install creates, so no term can ever start.'
		);
	}//end testAtLeastOneShippedDefinitionBindsToAShippedCaseType()

	/**
	 * The register's Omgevingsvergunning starts a statutory term.
	 *
	 * The sweep above reads the VTH and case-flow seeds only. The
	 * Omgevingsvergunning case type the register ships (slug and identifier
	 * `omgevingsvergunning`, P56D) had no definition, so a case filed through
	 * the new case form started no term at all (finding B5, 10 Oct). Pinned
	 * here by name, with the duration and extension the case type declares.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/omgevingsvergunning-starts-its-term/specs/termijnbewaking-schemas/spec.md
	 */
	public function testTheRegistersOmgevingsvergunningHasATermijnDefinitie(): void {
		$caseType = null;
		foreach (($this->readJson('/../../../lib/Settings/dossiq_register.json')['components']['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') === 'caseType' && ($object['@self']['slug'] ?? '') === 'omgevingsvergunning') {
				$caseType = $object;
			}
		}

		self::assertIsArray($caseType, 'The register must ship the omgevingsvergunning case type.');

		$definition = ($this->shippedDefinitions()['omgevingsvergunning'] ?? null);
		self::assertIsArray($definition, 'No shipped TermijnDefinitie binds to omgevingsvergunning.');
		self::assertSame($this->days($caseType['processingDeadline'] ?? null), (int)$definition['standardDurationDays']);
		self::assertSame($this->days($caseType['extensionPeriod'] ?? null), (int)$definition['extensionCapacity']);
	}//end testTheRegistersOmgevingsvergunningHasATermijnDefinitie()

	/**
	 * Each decided register case type carries the term Ruben decided.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function testEachDecidedCaseTypeCarriesItsDecidedTerm(): void {
		$caseTypes = $this->shippedCaseTypes();
		$definitions = $this->shippedDefinitions();

		foreach (self::DECIDED as $key => [$kind, $days, $extension, $extensions, $basis]) {
			self::assertArrayHasKey($key, $caseTypes, 'No shipped case type resolves to ' . $key . ', so its definition binds to nothing.');
			self::assertArrayHasKey($key, $definitions, 'No shipped TermijnDefinitie binds to ' . $key . '.');

			$definition = $definitions[$key];
			self::assertSame($kind, $definition['kind'] ?? null, $key . ': kind');
			self::assertSame($days, (int)($definition['standardDurationDays'] ?? 0), $key . ': days');
			self::assertSame($this->days($caseTypes[$key]['processingDeadline'] ?? null), $days, $key . ': the case type\'s own deadline');
			self::assertSame($extension, (int)($definition['extensionCapacity'] ?? 0), $key . ': extension capacity');
			self::assertSame($extensions, (int)($definition['countExtensions'] ?? 0), $key . ': number of extensions');
			self::assertSame('calendarDays', $definition['countingMode'] ?? null, $key . ': counting mode');
			self::assertStringContainsString($basis, (string)($definition['legalBasis'] ?? ''), $key . ': legal basis');
		}
	}//end testEachDecidedCaseTypeCarriesItsDecidedTerm()

	/**
	 * Every citation names an article and an official source.
	 *
	 * A statutory row added for the register's case types must say where its
	 * rule is written, and a service norm must not pretend to have a source.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function testEveryShippedCitationNamesAnArticleAndAnOfficialSource(): void {
		$definitions = $this->shippedDefinitions();

		foreach (self::DECIDED as $key => [$kind]) {
			$sources = ($definitions[$key]['legalBasisSources'] ?? null);
			self::assertIsArray($sources, $key . ': legalBasisSources must be a list.');

			if ($kind !== 'statutory') {
				self::assertSame([], $sources, $key . ': a service norm cites no law.');
				continue;
			}

			self::assertNotSame([], $sources, $key . ': a statutory term must cite its source.');
			foreach ($sources as $source) {
				self::assertNotSame('', trim((string)($source['article'] ?? '')), $key . ': a citation names its article.');
				self::assertMatchesRegularExpression(
					'#^https://(wetten\.overheid\.nl|vng\.nl)/#',
					(string)($source['url'] ?? ''),
					$key . ': a citation points at an official text.'
				);
			}
		}
	}//end testEveryShippedCitationNamesAnArticleAndAnOfficialSource()

	/**
	 * The open questions are still shipped, still deadline-bearing, still open.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijnbewaking-schemas/spec.md
	 */
	public function testTheOpenQuestionsAreStillOpen(): void {
		$caseTypes = $this->shippedCaseTypes();
		$definitions = $this->shippedDefinitions();

		foreach (array_keys(self::OPEN_QUESTIONS) as $key) {
			self::assertArrayHasKey($key, $caseTypes, $key . ' is no longer shipped; take it off OPEN_QUESTIONS.');
			self::assertNotNull($this->days($caseTypes[$key]['processingDeadline'] ?? null), $key . ' no longer declares a deadline; take it off OPEN_QUESTIONS.');
			self::assertArrayNotHasKey($key, $definitions, $key . ' has a definition now; take it off OPEN_QUESTIONS.');
		}
	}//end testTheOpenQuestionsAreStillOpen()

}//end class
