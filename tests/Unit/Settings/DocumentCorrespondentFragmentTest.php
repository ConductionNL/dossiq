<?php

/**
 * What this change adds to the shipped register, read off the MERGED
 * configuration rather than off the fragment.
 *
 * A fragment is merged into the monolith at import, so a property declared in
 * a fragment that the base already declares differently ends up as whatever
 * the merge produced, and nothing anywhere says which won. The merge is what
 * OpenRegister imports, so the merge is what this asserts.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Zaakdossier\DocumentCorrespondents;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class DocumentCorrespondentFragmentTest extends TestCase {

	/**
	 * The merged register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Merge the shipped register the way SettingsService does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$root = dirname(__DIR__, 3);
		$base = json_decode(
			(string)file_get_contents($root . '/lib/Settings/dossiq_register.json'),
			true
		);

		$merger = new RegisterFragmentMerger();
		[$merged] = $merger->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');

		$this->config = $merged;
	}//end setUp()

	/**
	 * 🔴 A DOCUMENT CANNOT CARRY A CORRESPONDENT THE SCHEMA DOES NOT DECLARE.
	 * OpenRegister drops an undeclared property in SILENCE, so a writer that
	 * sets `sender` against a schema without it reports success and stores
	 * nothing. This is the assertion that the two fields exist at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-011-a-document-names-its-sender-and-its-recipients-and-both-are-parties
	 */
	public function testTheDocumentSchemaDeclaresBothCorrespondents(): void {
		$properties = $this->propertiesOf(schema: 'informatieobject');

		$this->assertArrayHasKey(key: 'sender', array: $properties);
		$this->assertArrayHasKey(key: 'recipients', array: $properties);
		$this->assertSame(expected: 'string', actual: $properties['sender']['type']);
		$this->assertSame(expected: 'array', actual: $properties['recipients']['type']);
		// Facetable, or the dossier tab cannot filter on a correspondent.
		$this->assertTrue(condition: $properties['sender']['facetable']);
		$this->assertTrue(condition: $properties['recipients']['facetable']);
	}//end testTheDocumentSchemaDeclaresBothCorrespondents()

	/**
	 * The filed message keeps its sender and direction for the same reason.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-013-the-writers-set-the-correspondent-not-the-person
	 */
	public function testTheFiledMessageSchemaDeclaresItsSenderAndDirection(): void {
		$properties = $this->propertiesOf(schema: 'caseDocument');

		$this->assertArrayHasKey(key: 'sender', array: $properties);
		$this->assertSame(
			expected: DocumentCorrespondents::DIRECTION_INCOMING,
			actual: $properties['direction']['default'],
		);
	}//end testTheFiledMessageSchemaDeclaresItsSenderAndDirection()

	/**
	 * A dispatch names one of exactly two roles, and they are the case
	 * schema's own link roles rather than a second vocabulary.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function testADispatchNamesOneOfTheCaseSchemasOwnLinkRoles(): void {
		$dispatch = $this->propertiesOf(schema: 'dispatch');

		$this->assertSame(
			expected: [DocumentCorrespondents::ROLE_SENDER, DocumentCorrespondents::ROLE_RECIPIENT],
			actual: $dispatch['relationshipType']['enum'],
		);
		$this->assertArrayHasKey(key: 'case', array: $dispatch);

		$declared = array_column(
			(array)($this->schemaOf(schema: 'case')['configuration']['linkRoles'] ?? []),
			'key'
		);
		foreach ($dispatch['relationshipType']['enum'] as $role) {
			$this->assertContains(
				needle: $role,
				haystack: $declared,
				message: sprintf('a dispatch role the case schema does not declare is a role the People tab cannot name: %s', $role)
			);
		}
	}//end testADispatchNamesOneOfTheCaseSchemasOwnLinkRoles()

	/**
	 * 🔴 `case.caseDeclaredMajor` shipped with a subject and no body (#2859),
	 * which the routing lane's sweep reported rather than fixed because it
	 * routes no domain. Without one the responder gets a derived notice, which
	 * reads as the subject line twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
	 */
	public function testTheMajorCaseNoticeCarriesADutchBody(): void {
		$rule = (array)(
			($this->schemaOf(schema: 'case')['x-openregister-notifications'] ?? [])['caseDeclaredMajor'] ?? []
		);

		$this->assertNotSame(
			expected: '',
			actual: trim((string)(($rule['message'] ?? [])['nl'] ?? '')),
			message: 'case.caseDeclaredMajor has no Dutch body, so its notice falls back to a derived one.'
		);
		$this->assertNotSame(
			expected: '',
			actual: trim((string)(($rule['message'] ?? [])['en'] ?? '')),
		);
		// The subject it already had is still there: a body is an addition,
		// never a replacement.
		$this->assertNotSame(
			expected: '',
			actual: trim((string)(($rule['subject'] ?? [])['nl'] ?? '')),
		);
	}//end testTheMajorCaseNoticeCarriesADutchBody()

	/**
	 * One schema of the merged register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schemaOf(string $schema): array {
		$schemas = (array)(($this->config['components'] ?? [])['schemas'] ?? []);
		$this->assertArrayHasKey(key: $schema, array: $schemas);

		return (array)$schemas[$schema];
	}//end schemaOf()

	/**
	 * One schema's properties, from the merged register.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function propertiesOf(string $schema): array {
		return (array)($this->schemaOf(schema: $schema)['properties'] ?? []);
	}//end propertiesOf()
}//end class
