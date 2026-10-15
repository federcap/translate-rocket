<?php
/**
 * «Languages» on every Elementor element (1.8.6).
 *
 * @package TranslateRocket
 */

namespace TranslateRocket\Integrations;

use TranslateRocket\Frontend\LanguageOnly;
use TranslateRocket\Languages;
use TranslateRocket\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A «TranslateRocket — Languages» section in the Advanced tab of every widget, section, column and container:
 * shown everywhere, only in some languages, or hidden in some — the same rule as the blocks and the shortcode
 * (Frontend\LanguageOnly). In the Elementor editor everything stays visible, so it can be edited.
 */
class ElementorLanguages {

	const MODE  = 'trrocket_langs_mode';
	const LIST  = 'trrocket_langs';

	public function boot(): void {
		if ( ! did_action( 'elementor/loaded' ) && ! defined( 'ELEMENTOR_VERSION' ) ) {
			add_action( 'elementor/loaded', array( $this, 'hooks' ) );
			return;
		}
		$this->hooks();
	}

	public function hooks(): void {
		// Where each kind of element ends its Advanced tab.
		foreach ( array(
			'common/_section_style',
			'section/section_advanced',
			'column/section_advanced',
			'container/section_layout',
		) as $where ) {
			add_action( 'elementor/element/' . $where . '/after_section_end', array( $this, 'controls' ), 10, 2 );
		}
		foreach ( array( 'widget', 'section', 'column', 'container' ) as $type ) {
			add_filter( 'elementor/frontend/' . $type . '/should_render', array( $this, 'should_render' ), 10, 2 );
			add_action( 'elementor/frontend/' . $type . '/before_render', array( $this, 'before_render' ) );
		}
	}

	/**
	 * The languages of the site, for the list.
	 *
	 * @return array<string,string> code => name.
	 */
	private static function options(): array {
		$router = Plugin::instance()->router();
		$out    = array();
		foreach ( array_values( array_unique( array_merge( array( (string) $router->default_language() ), (array) $router->secondary_languages() ) ) ) as $code ) {
			$out[ $code ] = Languages::label( $code );
		}
		return $out;
	}

	/**
	 * The section, in the Advanced tab.
	 *
	 * @param \Elementor\Element_Base $element The element.
	 */
	public function controls( $element ): void {
		if ( ! class_exists( '\Elementor\Controls_Manager' ) || count( self::options() ) < 2 ) {
			return;
		}
		$element->start_controls_section(
			'trrocket_langs_section',
			array(
				'label' => __( 'TranslateRocket — Languages', 'translate-rocket' ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);
		$element->add_control(
			self::MODE,
			array(
				'label'       => __( 'Show this element', 'translate-rocket' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'all',
				'options'     => array(
					'all'  => __( 'In every language', 'translate-rocket' ),
					'only' => __( 'Only in these languages', 'translate-rocket' ),
					'hide' => __( 'Hidden in these languages', 'translate-rocket' ),
				),
				'description' => __( 'Shown only in some languages, it is kept exactly as you wrote it (not translated): write it in that language. Hidden in some, it is translated as usual everywhere else. In this editor it always stays visible.', 'translate-rocket' ),
			)
		);
		$element->add_control(
			self::LIST,
			array(
				'label'       => __( 'Languages', 'translate-rocket' ),
				'type'        => \Elementor\Controls_Manager::SELECT2,
				'multiple'    => true,
				'options'     => self::options(),
				'label_block' => true,
				'condition'   => array( self::MODE . '!' => 'all' ),
			)
		);
		$element->end_controls_section();
	}

	/**
	 * Only and hide lists of an element.
	 *
	 * @param \Elementor\Element_Base $element The element.
	 * @return array{0:string[],1:string[]}
	 */
	private static function rule( $element ): array {
		$s    = (array) $element->get_settings();
		$mode = (string) ( $s[ self::MODE ] ?? 'all' );
		$list = array_values( array_filter( array_map( 'strval', (array) ( $s[ self::LIST ] ?? array() ) ) ) );
		if ( 'all' === $mode || empty( $list ) ) {
			return array( array(), array() );
		}
		$list = LanguageOnly::codes( implode( ' ', $list ) );
		return 'only' === $mode ? array( $list, array() ) : array( array(), $list );
	}

	private static function editing(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$e = \Elementor\Plugin::$instance;
		return ( isset( $e->editor ) && $e->editor->is_edit_mode() ) || ( isset( $e->preview ) && $e->preview->is_preview_mode() );
	}

	/**
	 * Leave the element out in the languages it is not for.
	 *
	 * @param bool                    $render  Render it.
	 * @param \Elementor\Element_Base $element The element.
	 */
	public function should_render( $render, $element ) {
		if ( ! $render || self::editing() || \TranslateRocket\Coexistence::on() ) {
			return $render;
		}
		list( $only, $hide ) = self::rule( $element );
		if ( empty( $only ) && empty( $hide ) ) {
			return $render;
		}
		return LanguageOnly::visible( $only, $hide );
	}

	/**
	 * Shown only in some languages, it is written in them: keep it as it is (translate="no").
	 *
	 * @param \Elementor\Element_Base $element The element.
	 */
	public function before_render( $element ): void {
		list( $only ) = self::rule( $element );
		if ( ! empty( $only ) && ! self::editing() ) {
			$element->add_render_attribute( '_wrapper', 'translate', 'no' );
		}
	}
}
