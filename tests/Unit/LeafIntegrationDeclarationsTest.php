<?php

/**
 * The leaf declarations, held to what actually reads them.
 *
 * 🔴 EVERY ONE OF THESE FAILS SILENTLY IN PRODUCTION. A `mailObjectTemplate`
 * key that is not a property of its schema prefills nothing and says nothing;
 * a `linkedTypes` value no leaf answers to is only LOGGED by OpenRegister's
 * `LogDanglingLinkedTypes` repair step, so a typo means a tab that never
 * appears and a log line nobody reads; and a register version left where it was
 * makes `ImportHandler` skip the whole import, because it skips when
 * `version_compare(new, existing, '<=')` holds. In all three the app keeps
 * working and the feature simply is not there.
 *
 * 🔴 THE LEAF IDS ARE READ OUT OF THE LIBRARY, NOT LISTED HERE. A list written
 * in this file would be a second copy of the registry that agrees with it on
 * the day it is written and drifts afterwards, and the drift is invisible
 * exactly because a dangling value is only logged.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit
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
 * @spec openspec/changes/leaf-integrations/specs/leaf-integrations/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * mailObjectTemplate, linkedTypes and the Deck decoupling.
 *
 * @coversNothing
 */
class LeafIntegrationDeclarationsTest extends TestCase {

	/**
	 * Leaf ids registered outside the library's own leaf factory.
	 *
	 * `decidesk-decisions` is decidiq's cross-app registration, reached through
	 * `leafTab('decidesk-decisions')`. The id keeps the OLD app name on
	 * purpose: a duck-typed runtime lookup pointed at a name nothing answers to
	 * makes the integration silently no-op rather than error, so it moves when
	 * decidiq's own id lands and not before.
	 *
	 * @var array<int, string>
	 */
	private const CROSS_APP_LEAVES = ['decidesk-decisions', 'pipelinq-contact-moments', 'pipelinq-party'];

	/**
	 * Values in `linkedTypes` that are NOT leaf ids and must not be read as one.
	 *
	 * 🔴 `mail` IS NOT THE EMAIL LEAF. The leaf and its PHP provider are both
	 * called `email`. `mail` is a separate, literal sentinel that OpenRegister's
	 * Mail sidebar filters on (`ActionsTab.vue`: `lt.includes('mail')`) to
	 * decide which schemas a caseworker may link an email to and which get a
	 * create-from-email button. Two consumers read one list in two vocabularies,
	 * and neither says so. Removing `mail` on the grounds that it resolves to no
	 * leaf would silently take away the link button AND the create button.
	 *
	 * @var array<int, string>
	 */
	private const MAIL_SIDEBAR_SENTINEL = ['mail'];

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 2);
	}//end root()

	/**
	 * The main register.
	 *
	 * @return array<string, mixed> The decoded register.
	 */
	private function register(): array {
		return json_decode(
			file_get_contents($this->root() . '/lib/Settings/dossiq_register.json'),
			true
		);
	}//end register()

	/**
	 * Every leaf id the installed library registers.
	 *
	 * Read out of `leaves.js` and the bespoke `builtin/*.js` descriptors, which
	 * is where the ids actually live.
	 *
	 * @return array<int, string> The ids.
	 */
	private function registeredLeafIds(): array {
		$base = $this->root() . '/node_modules/@conduction/nextcloud-vue/src/integrations/builtin';
		if (is_dir($base) === false) {
			$this->markTestSkipped('the library is not installed, so its leaf ids cannot be read');
		}

		$ids = array_merge(self::CROSS_APP_LEAVES, self::MAIL_SIDEBAR_SENTINEL);
		foreach (glob($base . '/*.js') as $file) {
			$source = file_get_contents($file);
			if (preg_match_all("/\bid:\s*'([a-z0-9-]+)'/", $source, $matches) === 0) {
				continue;
			}

			$ids = array_merge($ids, $matches[1]);
		}

		$ids = array_values(array_unique($ids));
		$this->assertNotSame([], $ids, 'no leaf id could be read, so nothing below is a real check');

		return $ids;
	}//end registeredLeafIds()

	/**
	 * Every schema in this repository that declares linkedTypes.
	 *
	 * @return array<string, array<int, string>> Schema name to its linked types.
	 */
	private function allLinkedTypes(): array {
		$declared = [];

		$files = array_merge(
			[$this->root() . '/lib/Settings/dossiq_register.json'],
			glob($this->root() . '/lib/Settings/register.d/*.json')
		);

		foreach ($files as $file) {
			$decoded = json_decode(file_get_contents($file), true);
			$schemas = ($decoded['components']['schemas'] ?? []);
			foreach ($schemas as $name => $schema) {
				$types = ($schema['configuration']['linkedTypes'] ?? null);
				if (is_array($types) === true) {
					$declared[$name] = $types;
				}
			}
		}

		return $declared;
	}//end allLinkedTypes()

	/**
	 * Only `case` and `complaint` offer a create-from-email button.
	 *
	 * @return void
	 */
	public function testExactlyTwoSchemasCarryAMailTemplate(): void {
		$schemas = $this->register()['components']['schemas'];

		$carrying = [];
		foreach ($schemas as $name => $schema) {
			if (isset($schema['configuration']['mailObjectTemplate']) === true) {
				$carrying[] = $name;
			}
		}

		sort($carrying);
		$this->assertSame(
			['case', 'complaint'],
			$carrying,
			'a schema declaring a template gets a create button in the Mail sidebar, so the set is exact'
		);
	}//end testExactlyTwoSchemasCarryAMailTemplate()

	/**
	 * Every template key is a real property, and every value a scalar.
	 *
	 * @return void
	 */
	public function testEveryTemplateKeyIsARealProperty(): void {
		$schemas = $this->register()['components']['schemas'];

		foreach (['case', 'complaint'] as $name) {
			$template = $schemas[$name]['configuration']['mailObjectTemplate'];
			$properties = $schemas[$name]['properties'];

			$this->assertNotSame([], $template, sprintf('%s must declare a template', $name));

			foreach ($template as $field => $value) {
				$this->assertArrayHasKey(
					$field,
					$properties,
					sprintf('"%s" is not a property of %s, so it would prefill nothing', $field, $name)
				);
				// validateMailObjectTemplate refuses the whole schema on a
				// non-scalar, and a refused schema takes the import with it.
				$this->assertIsScalar(
					$value,
					sprintf('"%s" must be a scalar or the import is refused', $field)
				);
			}
		}
	}//end testEveryTemplateKeyIsARealProperty()

	/**
	 * No template prefills who the sender is.
	 *
	 * @return void
	 */
	public function testNoTemplatePrefillsAnIdentity(): void {
		$schemas = $this->register()['components']['schemas'];

		$forbidden = [
			'case' => ['initiatorSourceId', 'initiatorType', 'requester'],
			'complaint' => ['complainant'],
		];

		foreach ($forbidden as $name => $fields) {
			$template = $schemas[$name]['configuration']['mailObjectTemplate'];
			foreach ($fields as $field) {
				$this->assertArrayNotHasKey(
					$field,
					$template,
					sprintf(
						'"%s" must never be prefilled from an email: an address in the From header '
						. 'is not the identity of the person the case is about',
						$field
					)
				);
			}
		}
	}//end testNoTemplatePrefillsAnIdentity()

	/**
	 * Every declared linkedType resolves to a registered leaf.
	 *
	 * @return void
	 */
	public function testEveryLinkedTypeResolves(): void {
		$registered = $this->registeredLeafIds();
		$declared = $this->allLinkedTypes();

		$this->assertNotSame([], $declared, 'no schema declares linkedTypes, so nothing was checked');

		foreach ($declared as $schema => $types) {
			foreach ($types as $type) {
				$this->assertContains(
					$type,
					$registered,
					sprintf(
						'"%s" on %s resolves to no registered leaf. OpenRegister only LOGS a '
						. 'dangling value, so this is a tab that never appears and nothing that says why',
						$type,
						$schema
					)
				);
			}
		}
	}//end testEveryLinkedTypeResolves()

	/**
	 * The case, the checklist run and the field inspection carry their leaves.
	 *
	 * @return void
	 */
	public function testTheNewLeavesAreDeclared(): void {
		$declared = $this->allLinkedTypes();

		$this->assertContains('talk', $declared['case']);
		$this->assertContains('deck', $declared['case']);
		$this->assertContains('maps', $declared['inspectionChecklistRun']);
		// The inspection is the most location-bound record dossiq owns, and its
		// fragment carried no `configuration` object at all until now.
		$this->assertContains('maps', $declared['fieldInspection']);
	}//end testTheNewLeavesAreDeclared()

	/**
	 * A case type can name the form that opens a case of it.
	 *
	 * @return void
	 */
	public function testTheCaseTypeCanNameAnIntakeForm(): void {
		$caseType = $this->register()['components']['schemas']['caseType'];

		$this->assertArrayHasKey('intakeFormRef', $caseType['properties']);
		$this->assertSame('string', $caseType['properties']['intakeFormRef']['type']);
		// Additive only. Making it required would refuse every case type that
		// exists on every instance that upgrades.
		$this->assertNotContains('intakeFormRef', ($caseType['required'] ?? []));
	}//end testTheCaseTypeCanNameAnIntakeForm()

	/**
	 * The register version moved, or the import is skipped entirely.
	 *
	 * @return void
	 */
	public function testTheRegisterVersionMoved(): void {
		$version = $this->register()['info']['version'];

		// ImportHandler skips an app import when
		// version_compare(new, existing, '<=') holds, so a schema change with
		// the number left alone reaches a fresh CI install and no existing
		// instance at all.
		//
		// 🔴 THE FLOOR IS 0.20.1, NOT THE 0.19.2 THIS CHANGE STARTED FROM.
		// This change and status-capacity-limit both took 0.20.0 on their own
		// branches, and two branches writing the same new number merge that
		// line with no conflict, silently.
		$this->assertTrue(
			version_compare($version, '0.20.0', '>'),
			sprintf('the register version must move past 0.20.0, got %s', $version)
		);
	}//end testTheRegisterVersionMoved()

	/**
	 * Nothing in dossiq mirrors a task onto a Deck card, or back.
	 *
	 * @return void
	 */
	public function testNoCodePathLinksTasksToDeck(): void {
		// 🔴 CODE, NOT PROSE. The first form of this test matched `deck`
		// case-insensitively anywhere in a file and reddened on
		// `LeaverHandoverService`, whose docblock says "Nextcloud Deck does it
		// in one call". A sentence about Deck is not a call to Deck, and a gate
		// that cannot tell them apart gets suppressed rather than fixed. So the
		// patterns below are the shapes a real Deck call takes.
		$patterns = [
			'OCA\\Deck',
			'apps/deck',
			'deckCard',
			'deck_card',
			"'deck'",
			'"deck"',
		];

		$hits = [];
		$searched = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root() . '/lib')
		);

		foreach ($iterator as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$searched++;
			$source = file_get_contents($file->getPathname());
			foreach ($patterns as $pattern) {
				if (str_contains($source, $pattern) === true) {
					$hits[] = sprintf('%s (%s)', $file->getPathname(), $pattern);
				}
			}
		}

		// 🔴 THE SEARCHED COUNT IS ASSERTED. A grep gate that searched zero
		// files reports the same clean result as one that searched them all.
		$this->assertGreaterThan(
			100,
			$searched,
			'too few PHP files were searched for this to mean anything'
		);
		$this->assertSame(
			[],
			$hits,
			'task lifecycle belongs to WorkflowEngineService alone; a Deck mirror would give a '
			. 'handler two places to finish the same work and no rule about which one counts'
		);
	}//end testNoCodePathLinksTasksToDeck()

	/**
	 * Both create-from-email schemas carry the Mail sidebar's own sentinel.
	 *
	 * 🔴 A TEMPLATE WITHOUT `mail` IN `linkedTypes` IS DARK. The sidebar builds
	 * its schema list by filtering on `linkedTypes.includes('mail')` and draws
	 * the create button per schema in THAT list, so a schema declaring a
	 * template and not the sentinel is offered nothing at all, silently.
	 * `complaint` was exactly that until this change.
	 *
	 * @return void
	 */
	public function testTheMailTemplatesAreActuallyReachable(): void {
		$schemas = $this->register()['components']['schemas'];

		foreach (['case', 'complaint'] as $name) {
			$this->assertContains(
				'mail',
				($schemas[$name]['configuration']['linkedTypes'] ?? []),
				sprintf(
					'%s declares a mailObjectTemplate, so it must also carry the "mail" sentinel '
					. 'or the Mail sidebar never lists it and the button never appears',
					$name
				)
			);
		}
	}//end testTheMailTemplatesAreActuallyReachable()
}//end class
