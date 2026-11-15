<?php

/**
 * Dossiq record template reader
 *
 * Reads a named OpenRegister record template as the signed-in caller, for any
 * schema. A record template is a named set of values for one schema that a
 * user saved and shared in OpenRegister (`records-saved-templates`). dossiq
 * keeps no template store of its own: it asks OpenRegister, which decides who
 * may use a template (the owner, a group it is shared with, or everyone when
 * it is public, and only users who may create records in the schema).
 *
 * OpenRegister answers 404 both for a template that is not there and for one
 * the caller may not use, so the two read the same here: null. Anything else
 * that goes wrong is thrown, never answered as "no template".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\RecordTemplate
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-named-record-template-is-read-as-the-caller-req-rts-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\RecordTemplate;

use OCA\Dossiq\Kernel\Contracts\RecordTemplateReaderInterface;
use OCP\App\IAppManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads OpenRegister record templates for the signed-in caller.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-named-record-template-is-read-as-the-caller-req-rts-001
 */
class RecordTemplateReader implements RecordTemplateReaderInterface {

	/**
	 * OpenRegister's template service, resolved by name: an optional runtime dependency.
	 */
	private const SERVICE = 'OCA\OpenRegister\Service\RecordTemplate\RecordTemplateService';

	/**
	 * OpenRegister's resolver of a caller's groups and admin right.
	 */
	private const REACH = 'OCA\OpenRegister\Service\Rbac\ViewerReachResolver';

	/**
	 * The status OpenRegister refuses an absent or unusable template with.
	 */
	private const NOT_FOUND = 404;

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager  Whether OpenRegister is installed.
	 * @param ContainerInterface $container   Resolves OpenRegister's services.
	 * @param IUserSession       $userSession The signed-in caller.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * One template of a schema, when the signed-in caller may use it.
	 *
	 * @param string $uuid   The template uuid.
	 * @param string $schema The schema id the template must be for, as OpenRegister stores it.
	 *
	 * @return array{uuid: string, name: string, values: array<string, mixed>}|null The template, null when
	 *                                                                              nobody is signed in, it is
	 *                                                                              absent, not usable by the
	 *                                                                              caller, or for another schema.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 * @throws Throwable        When OpenRegister fails for any reason but "no such template".
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-named-record-template-is-read-as-the-caller-req-rts-001
	 */
	public function find(string $uuid, string $schema): ?array {
		$userId = $this->callerId();
		if ($userId === '' || $uuid === '' || $schema === '') {
			return null;
		}

		$template = $this->readTemplate(userId: $userId, uuid: $uuid);

		return $this->templateFor(template: $template, schema: $schema);
	}//end find()

	/**
	 * The signed-in caller's uid, '' when nobody is signed in.
	 *
	 * @return string
	 */
	private function callerId(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end callerId()

	/**
	 * Read one template within the caller's reach.
	 *
	 * @param string $userId The caller's uid.
	 * @param string $uuid   The template uuid.
	 *
	 * @return mixed The template, null when there is no such template.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 * @throws Throwable        When OpenRegister fails for any reason but "no such template".
	 */
	private function readTemplate(string $userId, string $uuid): mixed {
		if ($this->appManager->isInstalled('openregister') === false) {
			throw new RuntimeException('Record templates need OpenRegister, which is not installed.');
		}

		$reach = $this->container->get(self::REACH)->reachOf(userId: $userId);

		$template = null;
		try {
			$template = $this->container->get(self::SERVICE)->get(uuid: $uuid, reach: $reach);
		} catch (Throwable $e) {
			if ((int)$e->getCode() !== self::NOT_FOUND) {
				throw $e;
			}
		}

		return $template;
	}//end readTemplate()

	/**
	 * The template as an answer, when it is for the schema.
	 *
	 * @param mixed  $template The template, or null.
	 * @param string $schema   The schema id the template must be for.
	 *
	 * @return array{uuid: string, name: string, values: array<string, mixed>}|null
	 */
	private function templateFor(mixed $template, string $schema): array|null {
		if ($template === null || (string)$template->getSchema() !== $schema) {
			return null;
		}

		return [
			'uuid' => (string)$template->getUuid(),
			'name' => (string)$template->getName(),
			'values' => (array)$template->getValues(),
		];
	}//end templateFor()
}//end class
