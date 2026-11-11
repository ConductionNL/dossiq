<?php

/**
 * Dossiq SlugRename.
 *
 * One colliding schema slug while RenameCollidingSchemaSlugs namespaces it:
 * the slug, its new name, the app it collided with, and the schema ids found
 * under each name.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

/**
 * One slug rename, built and read by RenameCollidingSchemaSlugs only.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
 */
final class SlugRename {
	/**
	 * The slug being moved away from.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * The namespaced slug.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * The app this slug collided with.
	 *
	 * @var string
	 */
	public string $with = '';

	/**
	 * This app's schema ids under the old slug, or null when unreadable.
	 *
	 * @var array<int, mixed>|null
	 */
	public ?array $old = null;

	/**
	 * This app's schema ids under the new slug, or null when unreadable.
	 *
	 * @var array<int, mixed>|null
	 */
	public ?array $new = null;

	/**
	 * Start renaming one slug.
	 *
	 * @param string $from The slug being moved away from.
	 * @param string $to   The namespaced slug.
	 */
	public function __construct(string $from, string $to) {
		$this->from = $from;
		$this->to = $to;
	}//end __construct()
}//end class
