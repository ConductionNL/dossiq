<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every status a shipped VTH workflow template names must exist on the case
 * type it names.
 *
 * 🔴 THIS IS THE CONTROL THAT WAS MISSING, AND IT COST THE WHOLE ENTRY.
 * `toezichtbezoek` named the status `Inspectie` while its case type
 * `toezichtzaak-bouw` ships `Inspectie fase 1` to `fase 3`. Nothing compared
 * the two: the seeder resolved names against whatever the instance held,
 * failed, and reported the entry inside a count of "5 skipped (already present
 * or unresolved)". The template was dropped on every install since the
 * catalogue shipped, and no run ever said which status was missing.
 *
 * `Inspectie` is a real status name, which is why it reads as correct. It
 * belongs to `toezichtzaak-milieu`. A name that exists somewhere else on the
 * instance is exactly the mistake a human review does not catch.
 *
 * @coversNothing
 */
class ShippedVthTemplateStatusesTest extends TestCase {

	/**
	 * Catalogue entries whose case type this app deliberately does not ship.
	 *
	 * An entry listed here is seeded on an instance that creates the case type
	 * itself, and skipped with a named reason everywhere else. Adding one is a
	 * decision, which is the point of writing it down.
	 *
	 * @var array<string, string>
	 */
	private const CASE_TYPE_NOT_SHIPPED = [
		'klacht-toezicht' => 'a complaint about an inspector is not the generic klacht-behandeling case type, and dossiq ships no case type of its own for it yet',
	];

	/**
	 * The catalogue files, by template slug.
	 *
	 * @return array<string, array<string, mixed>> Slug to decoded entry.
	 */
	private function catalogue(): array {
		$entries = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/seed/vth-workflow-templates/*.json') as $file) {
			$data = json_decode((string)file_get_contents((string)$file), true);
			self::assertIsArray($data, 'Catalogue file must be valid JSON: ' . basename((string)$file));
			$entries[(string)($data['slug'] ?? basename((string)$file))] = $data;
		}

		self::assertNotSame([], $entries, 'The catalogue must hold entries, or this sweep is vacuous.');

		return $entries;
	}

	/**
	 * The statuses this app ships per case-type slug.
	 *
	 * Two sources, because the case types arrive by two routes: the VTH seed
	 * file carries its statuses inline, and the register JSONs carry them as
	 * separate `statusType` objects pointing back at the case type's own slug.
	 *
	 * @return array<string, array<int, string>> Case-type slug to status names.
	 */
	private function shippedStatuses(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$byCaseType = [];

		$vth = json_decode((string)file_get_contents($settings . '/vth_seed_data.json'), true);
		foreach ((array)(($vth['caseTypes'] ?? [])) as $caseType) {
			$slug = (string)($caseType['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			foreach ((array)($caseType['statusTypes'] ?? []) as $status) {
				$byCaseType[$slug][] = (string)($status['name'] ?? '');
			}
		}

		$registers = array_merge(
			[$settings . '/dossiq_register.json'],
			(array)glob($settings . '/register.d/*.json')
		);

		foreach ($registers as $register) {
			$decoded = json_decode((string)file_get_contents((string)$register), true);
			foreach ((array)(($decoded['components']['objects'] ?? [])) as $object) {
				if (is_array($object) === false
					|| (string)(($object['@self']['schema'] ?? '')) !== 'statusType'
				) {
					continue;
				}

				$owner = (string)($object['caseType'] ?? '');
				if ($owner !== '') {
					$byCaseType[$owner][] = (string)($object['name'] ?? '');
				}
			}
		}

		return $byCaseType;
	}

	/**
	 * THE SWEEP: a template may not name a status its case type does not have.
	 *
	 * @return void
	 */
	public function testEveryTemplateStatusExistsOnItsCaseType(): void {
		$shipped = $this->shippedStatuses();
		self::assertNotSame([], $shipped, 'No shipped statuses were found, so this sweep would pass on anything.');

		$missing = [];
		foreach ($this->catalogue() as $slug => $entry) {
			$caseTypeSlug = (string)($entry['caseTypeSlug'] ?? '');
			if (isset($shipped[$caseTypeSlug]) === false) {
				continue;
			}

			$known = $shipped[$caseTypeSlug];
			foreach ($this->statusNamesIn(entry: $entry) as $name) {
				if (in_array($name, $known, true) === false) {
					$missing[] = $slug . ' names "' . $name . '", but ' . $caseTypeSlug
						. ' has "' . implode('", "', $known) . '"';
				}
			}
		}

		self::assertSame(
			[],
			array_values(array_unique($missing)),
			"These shipped VTH templates name a status their case type does not have. The seeder\n"
			. "cannot resolve it, so the whole template is skipped on every install, and the skip\n"
			. "looks like idempotency rather than a defect:\n - "
			. implode("\n - ", array_unique($missing))
		);
	}

	/**
	 * A catalogue entry whose case type nothing ships is a decision, not a typo.
	 *
	 * @return void
	 */
	public function testEveryTemplateNamesACaseTypeThisAppShipsOrExplainsWhyNot(): void {
		$shipped = $this->shippedStatuses();

		$unshipped = [];
		foreach ($this->catalogue() as $slug => $entry) {
			if ((bool)($entry['crossLink'] ?? false) === true) {
				continue;
			}

			$caseTypeSlug = (string)($entry['caseTypeSlug'] ?? '');
			if (isset($shipped[$caseTypeSlug]) === true
				|| array_key_exists($slug, self::CASE_TYPE_NOT_SHIPPED) === true
			) {
				continue;
			}

			$unshipped[] = $slug . ' (case type "' . $caseTypeSlug . '")';
		}

		self::assertSame(
			[],
			$unshipped,
			"These VTH templates name a case type this app ships nowhere, so they can never be\n"
			. "seeded. Ship the case type, point the entry at one that exists, or add it to\n"
			. "CASE_TYPE_NOT_SHIPPED with the reason:\n - "
			. implode("\n - ", $unshipped)
		);
	}

	/**
	 * The exemption table may not outlive the entries it excuses.
	 *
	 * @return void
	 */
	public function testNoExemptionIsStale(): void {
		$catalogue = $this->catalogue();
		$shipped = $this->shippedStatuses();

		$stale = [];
		foreach (array_keys(self::CASE_TYPE_NOT_SHIPPED) as $slug) {
			if (isset($catalogue[$slug]) === false) {
				$stale[] = $slug . ' is no longer in the catalogue';
				continue;
			}

			$caseTypeSlug = (string)($catalogue[$slug]['caseTypeSlug'] ?? '');
			if (isset($shipped[$caseTypeSlug]) === true) {
				$stale[] = $slug . ' now has a shipped case type, so the exemption hides a working entry';
			}
		}

		self::assertSame([], $stale, 'Stale CASE_TYPE_NOT_SHIPPED entries: ' . implode(', ', $stale));
	}

	/**
	 * Two catalogue entries on one case type must both say so.
	 *
	 * 🔴 THE SECOND PUBLISH RETIRES THE FIRST, AND THAT IS THE MODEL WORKING.
	 * One published definition per case type is what `lifecycleStatus`
	 * declares, so `handhavingszaak` carrying both `handhavingstraject` and
	 * `spoedig-herstel` means whichever the glob reaches last deprecates the
	 * other. Nothing is broken and nothing errors, which is why it went
	 * unnoticed: a workflow simply stops backing new cases.
	 *
	 * The model has no variant mechanism to express it with, and choosing
	 * between a second case type and a new engine feature is a product
	 * decision. Until it is taken, the pairing is recorded on both entries, so
	 * a third template arriving on an occupied case type cannot be silent.
	 *
	 * @return void
	 */
	public function testEntriesSharingACaseTypeRecordThatTheyDo(): void {
		$byCaseType = [];
		foreach ($this->catalogue() as $slug => $entry) {
			if ((bool)($entry['crossLink'] ?? false) === true) {
				continue;
			}

			$byCaseType[(string)($entry['caseTypeSlug'] ?? '')][$slug] = $entry;
		}

		$unrecorded = [];
		foreach ($byCaseType as $caseTypeSlug => $entries) {
			if (count($entries) < 2) {
				continue;
			}

			foreach ($entries as $slug => $entry) {
				$note = (string)($entry['_sharesItsCaseTypeWith'] ?? '');
				$siblings = array_values(array_diff(array_keys($entries), [$slug]));
				foreach ($siblings as $sibling) {
					if (str_contains($note, $sibling) === false) {
						$unrecorded[] = $slug . ' shares case type "' . $caseTypeSlug
							. '" with ' . $sibling . ', and does not name it in _sharesItsCaseTypeWith';
					}
				}
			}
		}

		self::assertSame(
			[],
			$unrecorded,
			"Publishing the second template for a case type deprecates the first, silently.\n"
			. "Record the pairing on every entry that shares a case type, naming the other:\n - "
			. implode("\n - ", $unrecorded)
		);
	}

	/**
	 * Every status name one catalogue entry refers to.
	 *
	 * The wildcard `*` is a legal fromStatus meaning "from any status", so it
	 * is not a name to look up.
	 *
	 * @param array<string, mixed> $entry The decoded catalogue entry.
	 *
	 * @return array<int, string> The status names, de-duplicated.
	 */
	private function statusNamesIn(array $entry): array {
		$names = [];
		foreach ((array)($entry['steps'] ?? []) as $step) {
			$names[] = (string)($step['statusName'] ?? '');
		}

		foreach ((array)($entry['transitions'] ?? []) as $transition) {
			$names[] = (string)($transition['fromStatus'] ?? '');
			$names[] = (string)($transition['toStatus'] ?? '');
		}

		return array_values(
			array_unique(
				array_filter(
					$names,
					static function (string $name): bool {
						return ($name !== '' && $name !== '*');
					}
				)
			)
		);
	}
}
