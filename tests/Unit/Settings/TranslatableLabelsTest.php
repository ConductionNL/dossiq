<?php

/**
 * Guards the key OpenRegister actually reads to find a translatable label.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
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

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * One prefix stood between fifty labels and a translation engine.
 *
 * WHAT THIS TEST CANNOT DO, said first so nobody reads more into it. It does
 * not prove the engine translates anything. It reads the shipped register and
 * applies OpenRegister's own predicate to it, and that is all. The proof that
 * the engine SEES these properties is `tests/e2e/translatable-labels.spec.ts`,
 * which asks a running OpenRegister for `_meta.languageMeta` and fails if the
 * property is not in it.
 *
 * WHY THE TEST EXISTS ANYWAY. Until 2026-09-18 the register declared
 * `x-translatable` fifty times. OpenRegister's
 * `TranslationHandler::getTranslatableProperties()` reads
 * `($propertyDef['translatable'] ?? false) === true` (verified against
 * `lib/Service/Object/TranslationHandler.php` on `parity/round2`), and
 * `docs/i18n.md` says "Translatable properties carry `translatable: true`".
 * So every mark was decorative, and two service docblocks reasoned about a
 * declaration that did nothing. Nothing failed, because nothing looked. This
 * test is what looks.
 *
 * WHY THE PREFIX CANNOT COME BACK. The x-prefixed spelling is a plausible
 * guess: OpenRegister really does read `x-openregister-encrypted` off a
 * property, and it really does fold `x-openregister-*` blocks into a schema's
 * configuration. `translatable` is the exception, and an exception nobody
 * asserts is an exception somebody re-breaks.
 *
 * @spec openspec/changes/case-type-labels-are-translatable/specs/case-configuration-i18n/spec.md
 */
class TranslatableLabelsTest extends TestCase {

	/**
	 * Every register file that ships with the app.
	 *
	 * @return array<int, string> Absolute paths.
	 */
	private function registerFiles(): array {
		$root  = __DIR__ . '/../../../lib/Settings';
		$files = [
			$root . '/dossiq_register.json',
			$root . '/dossiq_mock_register.json',
		];

		$fragments = glob($root . '/register.d/*.json');
		if ($fragments !== false) {
			$files = array_merge($files, $fragments);
		}

		return $files;
	}//end registerFiles()

	/**
	 * Walk a decoded register and collect every property carrying $key.
	 *
	 * Returns the JSON path of each hit, so a nested one is recognisable as
	 * nested rather than counted with the rest.
	 *
	 * @param mixed  $node The node being walked.
	 * @param string $key  The property key being looked for.
	 * @param string $path The path walked so far.
	 *
	 * @return array<int, string> The paths that carry the key.
	 */
	private function pathsCarrying(mixed $node, string $key, string $path = ''): array {
		$hits = [];

		if (is_array($node) === false) {
			return $hits;
		}

		if (array_key_exists($key, $node) === true) {
			$hits[] = $path;
		}

		foreach ($node as $childKey => $child) {
			$hits = array_merge($hits, $this->pathsCarrying($child, $key, $path . '/' . $childKey));
		}

		return $hits;
	}//end pathsCarrying()

	/**
	 * The shipped registers carry no `x-translatable` anywhere.
	 *
	 * @return void
	 */
	public function testThePrefixedKeyIsGone(): void {
		foreach ($this->registerFiles() as $file) {
			$raw = file_get_contents($file);
			$this->assertIsString($raw, $file . ' must be readable');

			$this->assertStringNotContainsString(
				'"x-translatable"',
				$raw,
				basename($file) . ' declares "x-translatable", which OpenRegister does not read. '
				. 'The key it reads is "translatable": see TranslationHandler::getTranslatableProperties().'
			);
		}
	}//end testThePrefixedKeyIsGone()

	/**
	 * The label properties this app translates carry the key the engine reads.
	 *
	 * The list is the configuration schemas: a case type, a status, a result, a
	 * role, a document type and a decision type are labels an admin authors, so
	 * a municipality serving two languages authors them twice.
	 *
	 * @return void
	 */
	public function testConfigurationLabelsAreMarkedTranslatable(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$this->assertIsArray($register, 'the shipped register must be valid JSON');

		$schemas = $register['components']['schemas'];

		$expected = [
			'caseType'     => ['title', 'description', 'purpose', 'trigger', 'subject'],
			'statusType'   => ['name', 'description', 'publicLabel', 'publicDescription'],
			'resultType'   => ['name', 'description'],
			'roleType'     => ['name', 'description'],
			'documentType' => ['name', 'description'],
			'decisionType' => ['name', 'description'],
		];

		foreach ($expected as $schema => $properties) {
			foreach ($properties as $property) {
				$definition = $schemas[$schema]['properties'][$property] ?? null;
				$this->assertIsArray(
					$definition,
					$schema . '.' . $property . ' must exist to be translatable'
				);

				// OpenRegister's own predicate, verbatim.
				$this->assertTrue(
					($definition['translatable'] ?? false) === true,
					$schema . '.' . $property . ' is a label an admin authors, so it must carry '
					. 'translatable: true. OpenRegister tests exactly ($def[\'translatable\'] ?? false) === true.'
				);

				$this->assertSame(
					'string',
					$definition['type'] ?? null,
					$schema . '.' . $property . ' must be a string: OpenRegister stores a translatable '
					. 'value as a language-keyed object and rejects the rest.'
				);
			}
		}
	}//end testConfigurationLabelsAreMarkedTranslatable()

	/**
	 * A case's own words are never translatable.
	 *
	 * A case title is what somebody wrote about one case. Translating it would
	 * put words in a citizen's mouth, so the boundary is asserted rather than
	 * remembered.
	 *
	 * @return void
	 */
	public function testTheCaseItselfIsNotTranslatable(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		foreach (['case', 'task'] as $schema) {
			$properties = $register['components']['schemas'][$schema]['properties'] ?? [];
			foreach ($properties as $name => $definition) {
				if (is_array($definition) === false) {
					continue;
				}

				$this->assertNotTrue(
					($definition['translatable'] ?? false),
					$schema . '.' . $name . ' must not be translatable: it holds what somebody '
					. 'wrote about one record, not a label an admin authors.'
				);
			}
		}
	}//end testTheCaseItselfIsNotTranslatable()

	/**
	 * A mark below the top level is recorded, because the engine will not see it.
	 *
	 * `getTranslatableProperties()` iterates `$schema->getProperties()` and
	 * never descends, so a mark inside `items.properties` resolves nothing no
	 * matter how it is spelled. Three exist, all inside array items. They are
	 * left in place because they say what the intent is, and pinned here so the
	 * next reader learns the boundary from a test rather than from a bug.
	 *
	 * @return void
	 */
	public function testMarksBelowTheTopLevelAreKnownAndUnresolved(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$nested = [];
		foreach ($register['components']['schemas'] as $name => $schema) {
			foreach ($this->pathsCarrying($schema, 'translatable', $name) as $path) {
				// A top-level mark reads exactly "<schema>/properties/<property>".
				if (preg_match('#^[^/]+/properties/[^/]+$#', $path) === 1) {
					continue;
				}

				$nested[] = $path;
			}
		}

		sort($nested);

		$this->assertSame(
			[
				'caseType/properties/obligationKinds/items/properties/title',
				'statusType/properties/derivedWhen/items/properties/label',
				'statusType/properties/fieldRules/items/properties/message',
			],
			$nested,
			'A translatable mark below the top level is not resolved by OpenRegister: '
			. 'TranslationHandler iterates the schema properties and never descends. '
			. 'A new one here is either a mistake or a change OpenRegister has to make first.'
		);
	}//end testMarksBelowTheTopLevelAreKnownAndUnresolved()

	/**
	 * Every top-level translatable property in every shipped register file.
	 *
	 * Returns `<file basename>::<schema>.<property>` keys so a failure names
	 * the one property that is wrong rather than a count.
	 *
	 * @return array<string, array<string, mixed>> The definitions, by key.
	 */
	private function topLevelTranslatableProperties(): array {
		$found = [];

		foreach ($this->registerFiles() as $file) {
			$decoded = json_decode(file_get_contents($file), true);
			if (is_array($decoded) === false) {
				continue;
			}

			$schemas = ($decoded['components']['schemas'] ?? []);
			if (is_array($schemas) === false) {
				continue;
			}

			foreach ($schemas as $schemaName => $schema) {
				$properties = ($schema['properties'] ?? []);
				if (is_array($properties) === false) {
					continue;
				}

				foreach ($properties as $property => $definition) {
					if (is_array($definition) === false) {
						continue;
					}

					if (($definition['translatable'] ?? false) === true) {
						$found[basename($file) . '::' . $schemaName . '.' . $property] = $definition;
					}
				}
			}
		}

		ksort($found);

		return $found;
	}//end topLevelTranslatableProperties()

	/**
	 * A translatable label says which language it was written in.
	 *
	 * WHY THIS IS NOT DECORATION EITHER. `sourceLanguage` is the second step
	 * of `TranslationProjectionService::resolveSourceLanguage()`, read off
	 * `properties.<property>.sourceLanguage` on the schema. Without it every
	 * property falls through to the register default, so the projection can
	 * record no per-property original, and a changed Dutch title cannot mark
	 * its English translation stale: the rows disagree and neither is flagged.
	 *
	 * WHAT THIS TEST DOES NOT PROVE, again. That OpenRegister honours it. The
	 * e2e spec reads `_meta.languageMeta.<property>.sourceLanguage` back off a
	 * running instance, which is the half a file assertion cannot do.
	 *
	 * @return void
	 */
	public function testEveryTranslatableLabelDeclaresItsSourceLanguage(): void {
		$properties = $this->topLevelTranslatableProperties();

		$this->assertNotSame(
			[],
			$properties,
			'no translatable property was found at all, so this test is asserting nothing'
		);

		foreach ($properties as $key => $definition) {
			$this->assertSame(
				'nl',
				($definition['sourceLanguage'] ?? null),
				$key . ' is translatable but declares no sourceLanguage. OpenRegister then '
				. 'falls back to the register default for it, and a changed Dutch value cannot '
				. 'mark its translations stale because nothing recorded which side is the original.'
			);
		}
	}//end testEveryTranslatableLabelDeclaresItsSourceLanguage()

	/**
	 * The register names the languages it serves, Dutch first.
	 *
	 * `Register::getDefaultLanguage()` returns `languages[0]` and falls back to
	 * `nl` when the list is empty, so an empty list and a Dutch-first list
	 * behave identically today and diverge the moment a second language is
	 * added. `TranslationHandler` builds the per-property fallback chain by
	 * walking this list in declared order, so the order is the behaviour.
	 *
	 * THE VERSION IS ASSERTED WITH IT, and not out of tidiness.
	 * `ImportHandler::importRegister()` returns on the version gate WITHOUT
	 * comparing content — unlike the schema path, which has
	 * `schemaContentDiffers()` behind it. A `languages` list added under an
	 * unmoved version reaches no instance that already holds the register, and
	 * nothing anywhere says so.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresItsLanguagesDutchFirst(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		$declared = $register['components']['registers']['dossiq'];

		$this->assertSame(
			['nl', 'en'],
			($declared['languages'] ?? null),
			'the register must declare its languages with Dutch first: the first entry is the '
			. 'default language every translatable property is authored in.'
		);

		$this->assertTrue(
			version_compare(($declared['version'] ?? '0.0.0'), '1.3.0', '>='),
			'the register version must be at least 1.3.0, the version that carries languages. '
			. 'importRegister() skips on the version gate without comparing content, so a '
			. 'languages list under an older version reaches no existing instance.'
		);
	}//end testTheRegisterDeclaresItsLanguagesDutchFirst()

	/**
	 * One instance field is marked as a label, and it is known.
	 *
	 * `complaint` is the klacht record itself, the sibling of `case`, and its
	 * `subject` is the citizen's own summary of what went wrong. By REQ-CFI-02
	 * it is not a label and should not be translatable. It carries the mark
	 * because the 2026-09-18 sweep matched a property name.
	 *
	 * IT IS PINNED RATHER THAN REMOVED, and that is a deliberate call.
	 * `TranslationHandler::normalizeTranslationsForSave()` wraps a scalar under
	 * the register default on EVERY save of a translatable property, with or
	 * without a language header. So every complaint written since the mark
	 * landed is stored as `subject: {"nl": "…"}`. Dropping the mark stops the
	 * render path resolving it, and those rows would show an object where the
	 * subject was. Unmarking it needs a repair step that unwraps them, which is
	 * its own change; until then the boundary is asserted here so no second one
	 * joins it quietly.
	 *
	 * @return void
	 */
	public function testTheOnlyInstanceFieldMarkedAsALabelIsTheKnownOne(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);

		// The schemas that hold what somebody wrote about one record, rather
		// than a label an administrator authors once for every record.
		$instanceSchemas = [
			'case',
			'task',
			'complaint',
			'objection',
			'decision',
			'document',
			'hearing',
			'advisoryReport',
		];

		$marked = [];
		foreach ($instanceSchemas as $schema) {
			$properties = ($register['components']['schemas'][$schema]['properties'] ?? []);
			foreach ($properties as $name => $definition) {
				if (is_array($definition) === true && ($definition['translatable'] ?? false) === true) {
					$marked[] = $schema . '.' . $name;
				}
			}
		}

		sort($marked);

		$this->assertSame(
			['complaint.subject'],
			$marked,
			'An instance field marked translatable puts a citizen\'s own words through a '
			. 'translation engine. complaint.subject is the one that already is, and unmarking '
			. 'it needs a repair step for the rows already wrapped. A second one is a mistake.'
		);
	}//end testTheOnlyInstanceFieldMarkedAsALabelIsTheKnownOne()
}//end class
