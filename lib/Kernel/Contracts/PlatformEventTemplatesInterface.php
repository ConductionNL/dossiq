<?php

/**
 * Contract of PlatformEventTemplates
 *
 * The public surface of PlatformEventTemplates that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of PlatformEventTemplates.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlatformEventTemplatesInterface {

	/**
	 * The Dutch text dossiq offers for each platform event it raises.
	 *
	 * The variable names are the ones the event declares in the `variables` map
	 * of `GET /api/notification-templates`. A token this event does not carry
	 * renders as itself, which is why each entry names only that event's own.
	 *
	 * @var array<string, array{subject: string, body: string}>
	 */
	public const DUTCH = [
		'object_created' => [
			'subject' => 'Nieuw: {{title}}',
			'body' => '{{actor}} heeft {{title}} aangemaakt in {{schema}}. Open het om te zien wat erin staat.',
		],
		'object_updated' => [
			'subject' => '{{title}} is gewijzigd',
			'body' => '{{actor}} heeft {{title}} in {{schema}} gewijzigd. Bekijk wat er anders is.',
		],
		'object_transitioned' => [
			'subject' => '{{title}}: van {{from}} naar {{to}}',
			'body' => '{{title}} staat nu op {{to}} en was {{from}}. Kijk of er iets van je wordt verwacht.',
		],
		'retention_holds_skipped' => [
			'subject' => 'Bewaartermijn: {{skippedCount}} records overgeslagen',
			'body' => 'De opschoning van {{schemaSlug}} liet {{skippedCount}} records staan. Ze liggen vast onder een blokkade.',
		],
		'destruction_holds_skipped' => [
			'subject' => 'Vernietiging: {{skippedCount}} records overgeslagen',
			'body' => 'De vernietiging van {{schemaSlug}} liet {{skippedCount}} records staan. Ze liggen vast onder een blokkade.',
		],
		'destruction_review_pending' => [
			'subject' => '{{pendingCount}} records wachten op een beoordelaar',
			'body' => 'Van {{schemaSlug}} wachten {{pendingCount}} records op iemand die de vernietiging beoordeelt.',
		],
	];
}//end interface
