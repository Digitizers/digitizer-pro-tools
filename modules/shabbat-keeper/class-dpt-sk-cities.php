<?php
/**
 * Cities a site can pick its Shabbat times for. Coordinates are the city
 * centre to three decimals; a kilometre moves sunset by well under a
 * minute. Candle-lighting minutes follow the custom published for each
 * city.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class DPT_SK_Cities {

	public static function all() {
		return array(
			'jerusalem'   => array( 'name' => __( 'Jerusalem', 'digitizer-pro-tools' ),   'lat' => 31.778, 'lon' => 35.235, 'candle' => 40 ),
			'tel_aviv'    => array( 'name' => __( 'Tel Aviv', 'digitizer-pro-tools' ),    'lat' => 32.080, 'lon' => 34.780, 'candle' => 18 ),
			'haifa'       => array( 'name' => __( 'Haifa', 'digitizer-pro-tools' ),       'lat' => 32.794, 'lon' => 34.990, 'candle' => 30 ),
			'beer_sheva'  => array( 'name' => __( 'Beer Sheva', 'digitizer-pro-tools' ),  'lat' => 31.252, 'lon' => 34.791, 'candle' => 22 ),
			'eilat'       => array( 'name' => __( 'Eilat', 'digitizer-pro-tools' ),       'lat' => 29.558, 'lon' => 34.948, 'candle' => 18 ),
			'netanya'     => array( 'name' => __( 'Netanya', 'digitizer-pro-tools' ),     'lat' => 32.332, 'lon' => 34.860, 'candle' => 18 ),
			'petah_tikva' => array( 'name' => __( 'Petah Tikva', 'digitizer-pro-tools' ), 'lat' => 32.089, 'lon' => 34.888, 'candle' => 18 ),
			'ashdod'      => array( 'name' => __( 'Ashdod', 'digitizer-pro-tools' ),      'lat' => 31.804, 'lon' => 34.655, 'candle' => 18 ),
			'bnei_brak'   => array( 'name' => __( 'Bnei Brak', 'digitizer-pro-tools' ),   'lat' => 32.084, 'lon' => 34.834, 'candle' => 18 ),
			'modiin'      => array( 'name' => __( 'Modiin', 'digitizer-pro-tools' ),      'lat' => 31.898, 'lon' => 35.010, 'candle' => 18 ),
			'safed'       => array( 'name' => __( 'Safed', 'digitizer-pro-tools' ),       'lat' => 32.965, 'lon' => 35.496, 'candle' => 18 ),
			'tiberias'    => array( 'name' => __( 'Tiberias', 'digitizer-pro-tools' ),    'lat' => 32.796, 'lon' => 35.531, 'candle' => 18 ),
			'rehovot'     => array( 'name' => __( 'Rehovot', 'digitizer-pro-tools' ),     'lat' => 31.895, 'lon' => 34.809, 'candle' => 18 ),
			'hadera'      => array( 'name' => __( 'Hadera', 'digitizer-pro-tools' ),      'lat' => 32.434, 'lon' => 34.920, 'candle' => 18 ),
			'kfar_saba'   => array( 'name' => __( 'Kfar Saba', 'digitizer-pro-tools' ),   'lat' => 32.175, 'lon' => 34.907, 'candle' => 18 ),
		);
	}

	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}
}
