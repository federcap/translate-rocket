<?php
/**
 * Qwen (Alibaba Cloud Model Studio) — with the owner's own paid key.
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Alibaba Cloud Model Studio, OpenAI-compatible mode. A key works only in the region it was created
 * in, and each region has its own address: the owner picks the region next to the key. «Thinking» is
 * switched off with enable_thinking=false (Model Studio docs, 15/11/2026).
 */
class QwenProvider extends PaidCompatProvider {

	const MODELS = array( 'qwen3.8-flash', 'qwen3.7-plus' );

	/** Region => host. The first one is the default. */
	const REGIONS = array(
		'intl' => 'dashscope-intl.aliyuncs.com',
		'us'   => 'dashscope-us.aliyuncs.com',
		'cn'   => 'dashscope.aliyuncs.com',
	);

	public function id(): string {
		return 'qwen';
	}

	public function label(): string {
		return 'Qwen (Alibaba Cloud)';
	}

	/**
	 * The region saved with the key, or the international one.
	 */
	public function region(): string {
		$r = (string) ( $this->config['region'] ?? '' );
		return isset( self::REGIONS[ $r ] ) ? $r : 'intl';
	}

	protected function base(): string {
		return 'https://' . self::REGIONS[ $this->region() ] . '/compatible-mode/v1';
	}

	protected function default_model(): string {
		return self::MODELS[0];
	}

	protected function extra(): array {
		return array( 'enable_thinking' => false );
	}

	protected function model_ok( string $id ): bool {
		// Text models only: no speech, image, video, embedding or live-translation models.
		return (bool) preg_match( '/^(qwen|deepseek|glm|kimi|minimax)/i', $id )
			&& ! preg_match( '/(vl|omni|audio|tts|asr|image|video|embedding|rerank|ocr|livetranslate|realtime|-mt-|coder)/i', $id );
	}
}
