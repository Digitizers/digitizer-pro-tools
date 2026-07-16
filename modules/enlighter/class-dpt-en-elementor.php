<?php
/**
 * Enlighter module - Elementor widget. Loaded only when Elementor registers
 * its widgets, so the class safely extends the Elementor base.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

class DPT_EN_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'dpt_enlighter';
	}

	public function get_title() {
		return __( 'Code (Enlighter)', 'digitizer-pro-tools' );
	}

	public function get_icon() {
		return 'eicon-code';
	}

	public function get_categories() {
		return array( 'basic' );
	}

	public function get_keywords() {
		return array( 'code', 'highlight', 'syntax', 'enlighter' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'section_code',
			array( 'label' => __( 'Code', 'digitizer-pro-tools' ) )
		);

		$options = array();
		foreach ( DPT_EN_Settings::languages() as $key => $label ) {
			$options[ $key ] = $label;
		}

		$this->add_control(
			'language',
			array(
				'label'   => __( 'Language', 'digitizer-pro-tools' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $options,
				'default' => DPT_EN_Settings::get( 'default_lang' ),
			)
		);

		$this->add_control(
			'code',
			array(
				'label'   => __( 'Code', 'digitizer-pro-tools' ),
				'type'    => \Elementor\Controls_Manager::CODE,
				'default' => '',
			)
		);

		$this->add_control(
			'lines',
			array(
				'label'        => __( 'Line numbers', 'digitizer-pro-tools' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '1' === DPT_EN_Settings::get( 'line_numbers' ) ? 'yes' : '',
			)
		);

		$this->add_control(
			'copy',
			array(
				'label'        => __( 'Copy button', 'digitizer-pro-tools' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'default'      => '1' === DPT_EN_Settings::get( 'copy_button' ) ? 'yes' : '',
			)
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$code     = isset( $settings['code'] ) ? (string) $settings['code'] : '';
		if ( '' === trim( $code ) ) {
			return;
		}
		// build_markup() is static and escapes the code; safe to echo.
		echo DPT_Enlighter_Module::build_markup( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$code,
			isset( $settings['language'] ) ? $settings['language'] : 'plain',
			! empty( $settings['lines'] ) && 'yes' === $settings['lines'],
			! empty( $settings['copy'] ) && 'yes' === $settings['copy']
		);
	}
}
