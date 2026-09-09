<?php
/**
 * Migration importer contract.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads translations from another plugin and brings them into TranslateRocket.
 */
interface ImporterInterface {

	/**
	 * Stable id (e.g. 'translatepress').
	 */
	public function id(): string;

	/**
	 * Human label (e.g. 'TranslatePress').
	 */
	public function label(): string;

	/**
	 * Whether the source plugin's data is present and importable.
	 */
	public function is_available(): bool;

	/**
	 * How many translations could be imported (for display).
	 */
	public function count_available(): int;

	/**
	 * Run the import.
	 *
	 * @return array{count:int,error:string}
	 */
	public function import(): array;
}
