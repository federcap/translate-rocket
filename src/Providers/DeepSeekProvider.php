<?php
/**
 * DeepSeek — with the owner's own paid key.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * DeepSeek's API (api.deepseek.com, OpenAI format). Its models think before answering unless told
 * not to: {"thinking":{"type":"disabled"}} goes with every request (api-docs.deepseek.com, 15/11/2026).
 */
class DeepSeekProvider extends PaidCompatProvider {

	const MODELS = array( 'deepseek-flash', 'deepseek-v4-pro' );

	public function id(): string {
		return 'deepseek';
	}

	public function label(): string {
		return 'DeepSeek';
	}

	protected function base(): string {
		return 'https://api.deepseek.com';
	}

	protected function default_model(): string {
		return self::MODELS[0];
	}

	protected function extra(): array {
		return array( 'thinking' => array( 'type' => 'disabled' ) );
	}
}
