<?php
/**
 * Registry of migration importers.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Importers;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the available importers and resolves one by id.
 */
class Importers {

	/**
	 * All implemented importers.
	 *
	 * @return ImporterInterface[]
	 */
	public static function all(): array {
		return array(
			new TranslatePressImporter(),
			new PolylangImporter(),
			new WpmlImporter(),
			new InlineImporter(),
			new BogoImporter(),
			new MultilanguageImporter(),
			new LocoImporter(),
		);
	}

	/**
	 * Importer by id, or null.
	 */
	public static function get( string $id ): ?ImporterInterface {
		foreach ( self::all() as $importer ) {
			if ( $importer->id() === $id ) {
				return $importer;
			}
		}
		return null;
	}
}
