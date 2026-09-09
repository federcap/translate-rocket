<?php
/**
 * Provider registry.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

use TranslateRocket\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds providers from settings and resolves the active one.
 */
class Registry {

	/**
	 * All providers, configured from settings.
	 *
	 * @return ProviderInterface[]
	 */
	public static function all(): array {
		$cfg = (array) ( Settings::get()['providers'] ?? array() );

		return array(
			new DeepLProvider( (array) ( $cfg['deepl'] ?? array() ) ),
			new OpenAIProvider( (array) ( $cfg['openai'] ?? array() ) ),
			new GoogleProvider( (array) ( $cfg['google'] ?? array() ) ),
			new GeminiProvider( (array) ( $cfg['gemini'] ?? array() ) ),
			new AnthropicProvider( (array) ( $cfg['anthropic'] ?? array() ) ),
		);
	}

	/**
	 * A provider by id, or null.
	 */
	public static function get( string $id ): ?ProviderInterface {
		foreach ( self::all() as $provider ) {
			if ( $provider->id() === $id ) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Build a provider by id with an explicit config (e.g. a key just typed in
	 * the admin, not yet saved). Returns null for an unknown id.
	 *
	 * @param array<string,mixed> $config Provider config slice.
	 */
	public static function build( string $id, array $config ): ?ProviderInterface {
		$map = array(
			'deepl'     => DeepLProvider::class,
			'openai'    => OpenAIProvider::class,
			'google'    => GoogleProvider::class,
			'gemini'    => GeminiProvider::class,
			'anthropic' => AnthropicProvider::class,
		);
		if ( ! isset( $map[ $id ] ) ) {
			return null;
		}
		$class = $map[ $id ];
		return new $class( $config );
	}

	/**
	 * The active, configured provider, or null.
	 */
	public static function active(): ?ProviderInterface {
		$id = (string) ( Settings::get()['active_provider'] ?? '' );
		if ( '' === $id ) {
			return null;
		}
		$provider = self::get( $id );
		return ( $provider && $provider->is_configured() ) ? $provider : null;
	}

	/**
	 * Configured providers in the user's preferred order — the fallback chain used
	 * for bulk / automatic translation. If one runs out of credit (or its key is
	 * rejected) the next one in the list takes over, so a big translation job can
	 * span several accounts without stopping.
	 *
	 * Order: the saved provider_order first (only ids that are configured), then
	 * the active provider, then any other configured provider not yet listed — so
	 * an existing single-provider setup keeps working with no configuration.
	 *
	 * @return ProviderInterface[]
	 */
	public static function ordered(): array {
		$settings = Settings::get();
		$by_id    = array();
		foreach ( self::all() as $provider ) {
			$by_id[ $provider->id() ] = $provider;
		}

		$queue  = (array) ( $settings['provider_order'] ?? array() );
		$active = (string) ( $settings['active_provider'] ?? '' );
		if ( '' !== $active ) {
			array_unshift( $queue, $active );
		}

		$ordered = array();
		$seen    = array();
		foreach ( $queue as $id ) {
			$id = (string) $id;
			if ( isset( $by_id[ $id ] ) && empty( $seen[ $id ] ) && $by_id[ $id ]->is_configured() ) {
				$ordered[]   = $by_id[ $id ];
				$seen[ $id ] = true;
			}
		}
		// Any remaining configured providers become fallbacks in their natural order.
		foreach ( self::all() as $provider ) {
			if ( empty( $seen[ $provider->id() ] ) && $provider->is_configured() ) {
				$ordered[]                 = $provider;
				$seen[ $provider->id() ]   = true;
			}
		}
		return $ordered;
	}
}
