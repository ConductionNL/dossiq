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
}//end class
