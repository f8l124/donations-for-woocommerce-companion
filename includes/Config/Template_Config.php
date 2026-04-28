<?php
/**
 * Template_Config — value object representing one named template.
 *
 * Templates are admin-defined reusable configuration packages (e.g.
 * "School Sponsorship", "Emergency Relief"). One Template_Config instance
 * is the canonical in-memory representation; Template_Repository handles
 * persistence to the `dfwc_companion_templates` option.
 *
 * Immutable mutators (rename, with_config, duplicate) return new instances
 * rather than mutating in place.
 *
 * @package   DFWC\Companion
 * @copyright Copyright (c) 2026 David Stells
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace DFWC\Companion\Config;

defined( 'ABSPATH' ) || exit;

final class Template_Config {

	/** @var string Stable, sanitize_key()-shaped identifier. */
	public string $id;

	/** @var string Admin-defined display name (translatable via WPML). */
	public string $name;

	/** @var string Admin-defined description (translatable via WPML). */
	public string $description;

	/** @var int Unix timestamp of creation. */
	public int $created_at;

	/** @var int Unix timestamp of last modification. */
	public int $modified_at;

	/**
	 * @var array<string,mixed> Config payload — same shape as Defaults::for_campaign()
	 *                          (one_time/monthly/annual interval blocks + display).
	 */
	public array $config;

	/**
	 * @param array<string,mixed> $config
	 */
	public function __construct(
		string $id,
		string $name,
		string $description,
		int $created_at,
		int $modified_at,
		array $config
	) {
		$this->id          = $id;
		$this->name        = $name;
		$this->description = $description;
		$this->created_at  = $created_at;
		$this->modified_at = $modified_at;
		$this->config      = $config;
	}

	/**
	 * Hydrate from a stored array (the shape `Template_Repository` reads from
	 * the option).
	 *
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			(string) ( $data['description'] ?? '' ),
			(int) ( $data['created_at'] ?? time() ),
			(int) ( $data['modified_at'] ?? time() ),
			(array) ( $data['config'] ?? array() )
		);
	}

	/**
	 * Serialize for storage. Inverse of from_array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'          => $this->id,
			'name'        => $this->name,
			'description' => $this->description,
			'created_at'  => $this->created_at,
			'modified_at' => $this->modified_at,
			'config'      => $this->config,
		);
	}

	/**
	 * Return a new instance with a different name. Touches modified_at.
	 */
	public function rename( string $new_name ): self {
		return new self( $this->id, $new_name, $this->description, $this->created_at, time(), $this->config );
	}

	/**
	 * Return a new instance with a different config. Touches modified_at.
	 *
	 * @param array<string,mixed> $new_config
	 */
	public function with_config( array $new_config ): self {
		return new self( $this->id, $this->name, $this->description, $this->created_at, time(), $new_config );
	}

	/**
	 * Return a new instance with a different description. Touches modified_at.
	 */
	public function with_description( string $new_description ): self {
		return new self( $this->id, $this->name, $new_description, $this->created_at, time(), $this->config );
	}

	/**
	 * Return a duplicate of this template under a new ID and name. Fresh
	 * created_at/modified_at timestamps; config is deep-copied via PHP's
	 * copy-on-write.
	 */
	public function duplicate( string $new_id, string $new_name ): self {
		$now = time();
		return new self( $new_id, $new_name, $this->description, $now, $now, $this->config );
	}
}
