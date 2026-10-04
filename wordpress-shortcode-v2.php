<?php
/**
 * Shortcode [nemovitosti_real_v2] — agregátor verze 2 na max-reality.cz (Code Snippets ID 15, rozsah global).
 * Data: JSONy z repa patrikpilous-dev/realitni-agregator (scraper verze 2).
 *
 * Atributy jako u starého [nemovitosti_real]: city, type (byt|dům), disp, extra, own.
 *   [nemovitosti_real_v2]                        hub /real/
 *   [nemovitosti_real_v2 city="Brno"]            /real/brno/
 *   [nemovitosti_real_v2 type="byt" city="Brno"] /real/byty/brno/
 *   [nemovitosti_real_v2 disp="2+kk"]            /real/2-kk/
 *
 * Server vypisuje texty, statistiky trhu, 12 nejvýhodnějších nabídek (stranky.json), schéma ItemList
 * a síť odkazů na celý hub. JavaScript pak načte celý výpis, filtry a graf. Filtry, které mají
 * vlastní stránku (město, typ, dispozice, vybavení, vlastnictví), přesměrují na její URL.
 * Noindex má jen testovací stránka (ID 90620).
 */

// Nastavení postranního panelu šablony WpResidence čitelné a zapisovatelné přes REST (stránky agregátoru bez sidebaru)
add_action( 'rest_api_init', function () {
	foreach ( array( 'sidebar_option', 'sidebar_select' ) as $mxr_klic ) {
		register_post_meta( 'page', $mxr_klic, array(
			'type'          => 'string',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => function () { return current_user_can( 'edit_pages' ); },
		) );
	}
} );

add_filter( 'wpseo_robots', function ( $robots ) {
	global $post;
	if ( is_singular() && $post && 90620 === (int) $post->ID ) {
		return 'noindex, follow';
	}
	return $robots;
} );

function mxr_agr_base() {
	return 'https://raw.githubusercontent.com/patrikpilous-dev/realitni-agregator/refs/heads/main/';
}

/** JSON z repa s hodinovou cache. Když GitHub neodpoví, použije se poslední úspěšně stažená verze. */
function mxr_agr_json( $file ) {
	static $mem = array();
	if ( isset( $mem[ $file ] ) ) {
		return $mem[ $file ];
	}
	$key  = 'mxr_agr_' . md5( $file );
	$data = get_transient( $key );
	if ( false === $data ) {
		$data = null;
		$r    = wp_remote_get( mxr_agr_base() . $file . '?t=' . floor( time() / 600 ), array( 'timeout' => 8 ) );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$data = json_decode( wp_remote_retrieve_body( $r ), true );
		}
		if ( is_array( $data ) ) {
			set_transient( $key, $data, HOUR_IN_SECONDS );
			update_option( $key . '_zaloha', $data, false );
		} else {
			$data = get_option( $key . '_zaloha', array() );
			set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		}
	}
	return $mem[ $file ] = $data;
}

/** Stránky pod /real/: { "real/byty/brno" => "https://.../real/byty/brno/" } */
function mxr_agr_pages() {
	$paths = get_transient( 'mxr_agr_stranky_webu' );
	if ( false === $paths ) {
		$paths = array();
		$root  = get_page_by_path( 'real' );
		if ( $root ) {
			$paths['real'] = get_permalink( $root );
			foreach ( get_pages( array( 'child_of' => $root->ID, 'post_status' => 'publish' ) ) as $p ) {
				$paths[ get_page_uri( $p ) ] = get_permalink( $p );
			}
		}
		set_transient( 'mxr_agr_stranky_webu', $paths, 12 * HOUR_IN_SECONDS );
	}
	return $paths;
}
add_action( 'save_post_page', function () { delete_transient( 'mxr_agr_stranky_webu' ); } );

function mxr_agr_slug( $s ) {
	return sanitize_title( remove_accents( str_replace( '+', '-', $s ) ) );
}

/** Cesta stránky pro kombinaci filtrů, nebo '' když taková stránka není. Stejnou logiku má JS (pageUrl). */
function mxr_agr_path( $st ) {
	$typ  = array( 'byt' => 'byty', 'dum' => 'domy' );
	$dims = array_filter( array( $st['city'], $st['disp'], $st['extra'], $st['own'] ) );
	if ( count( $dims ) > 1 ) {
		return '';
	}
	if ( $st['city'] ) {
		return $st['seg'] ? 'real/' . $typ[ $st['seg'] ] . '/' . mxr_agr_slug( $st['city'] ) : 'real/' . mxr_agr_slug( $st['city'] );
	}
	if ( $st['seg'] && ! $dims ) {
		return 'real/' . $typ[ $st['seg'] ];
	}
	if ( $st['seg'] ) {
		return '';
	}
	foreach ( array( 'disp', 'extra', 'own' ) as $d ) {
		if ( $st[ $d ] ) {
			return 'real/' . mxr_agr_slug( $st[ $d ] );
		}
	}
	return 'real';
}

function mxr_agr_url( $st ) {
	$pages = mxr_agr_pages();
	$p     = mxr_agr_path( $st );
	return ( $p && isset( $pages[ $p ] ) ) ? $pages[ $p ] : '';
}

/**
 * Data o městech. ZDROJE: ČSÚ 2024 (obyvatelé, pořadí), vlastní průzkum spokojenosti, nájmy Bezrealitky 2024,
 * vlak IDOS 2024, dálnice ŘSD 2024, fotbal 1. liga 2024/25, nemocnice ÚZIS 2023, školy MŠMT 2023 a 2024.
 * Fotbal aktualizovat každé léto, ostatní jednou za 2 až 3 roky.
 * info: [pořadí v ČR, pořadí v kraji, kraj v 6. pádě, obyvatel, spokojenost, podobné město]
 * infra: [vlak do Prahy min, dálnice, prvoligový klub, nemocnic, středních škol, vysokých škol, kin a divadel]
 */
function mxr_agr_cities() {
	return array(
		'Praha'              => array( 'info' => array( 1, 0, '', 1357000, 7.0, 'Brno' ), 'loc' => 'Praze', 'rent' => 370, 'near' => array( 'Kladno', 'Mladá Boleslav', 'Příbram', 'Kolín' ), 'infra' => array( null, 'D1, D2, D5, D8, D11', 'Sparta Praha, Slavia Praha, Bohemians 1905', 24, 200, 28, 80 ) ),
		'Brno'               => array( 'info' => array( 2, 1, 'Jihomoravském kraji', 382000, 7.1, 'Praha' ), 'loc' => 'Brně', 'rent' => 295, 'near' => array( 'Znojmo', 'Hodonín', 'Břeclav', 'Prostějov', 'Jihlava' ), 'infra' => array( 140, 'D1, D2', 'FC Zbrojovka Brno', 7, 80, 9, 20 ) ),
		'Ostrava'            => array( 'info' => array( 3, 1, 'Moravskoslezském kraji', 285000, 6.3, 'Kladno' ), 'loc' => 'Ostravě', 'rent' => 195, 'near' => array( 'Frýdek-Místek', 'Opava', 'Karviná', 'Havířov', 'Třinec' ), 'infra' => array( 205, 'D1, D56', 'FC Baník Ostrava', 5, 50, 4, 14 ) ),
		'Plzeň'              => array( 'info' => array( 4, 1, 'Plzeňském kraji', 175000, 7.2, 'Hradec Králové' ), 'loc' => 'Plzni', 'rent' => 240, 'near' => array( 'České Budějovice', 'Cheb', 'Sokolov', 'Příbram' ), 'infra' => array( 80, 'D5', 'FC Viktoria Plzeň', 4, 45, 3, 12 ) ),
		'Liberec'            => array( 'info' => array( 5, 1, 'Libereckém kraji', 105000, 7.5, 'Pardubice' ), 'loc' => 'Liberci', 'rent' => 215, 'near' => array( 'Jablonec nad Nisou', 'Česká Lípa', 'Trutnov' ), 'infra' => array( 100, 'R35', null, 2, 30, 2, 8 ) ),
		'Olomouc'            => array( 'info' => array( 6, 1, 'Olomouckém kraji', 101000, 7.4, 'České Budějovice' ), 'loc' => 'Olomouci', 'rent' => 225, 'near' => array( 'Prostějov', 'Přerov', 'Kroměříž', 'Šumperk' ), 'infra' => array( 125, 'D35', 'SK Sigma Olomouc', 4, 35, 2, 10 ) ),
		'České Budějovice'   => array( 'info' => array( 7, 1, 'Jihočeském kraji', 94000, 7.4, 'Olomouc' ), 'loc' => 'Českých Budějovicích', 'rent' => 230, 'near' => array( 'Tábor', 'Písek', 'Třebíč', 'Plzeň' ), 'infra' => array( 145, 'D3', 'SK Dynamo České Budějovice', 2, 35, 2, 8 ) ),
		'Hradec Králové'     => array( 'info' => array( 8, 1, 'Královéhradeckém kraji', 93000, 7.6, 'Zlín' ), 'loc' => 'Hradci Králové', 'rent' => 235, 'near' => array( 'Pardubice', 'Trutnov', 'Kolín' ), 'infra' => array( 105, 'D11', 'FC Hradec Králové', 3, 40, 2, 9 ) ),
		'Pardubice'          => array( 'info' => array( 9, 1, 'Pardubickém kraji', 92000, 7.8, 'Hradec Králové' ), 'loc' => 'Pardubicích', 'rent' => 220, 'near' => array( 'Hradec Králové', 'Kolín', 'Jihlava' ), 'infra' => array( 95, 'D11, D35', 'FK Pardubice', 2, 35, 2, 7 ) ),
		'Ústí nad Labem'     => array( 'info' => array( 10, 1, 'Ústeckém kraji', 91000, 6.0, 'Ostrava' ), 'loc' => 'Ústí nad Labem', 'rent' => 160, 'near' => array( 'Most', 'Teplice', 'Děčín', 'Chomutov' ), 'infra' => array( 90, 'D8', null, 2, 30, 2, 7 ) ),
		'Zlín'               => array( 'info' => array( 11, 1, 'Zlínském kraji', 74000, 7.7, 'Pardubice' ), 'loc' => 'Zlíně', 'rent' => 195, 'near' => array( 'Kroměříž', 'Uherské Hradiště', 'Vsetín', 'Frýdek-Místek' ), 'infra' => array( 245, 'D55', null, 2, 30, 2, 6 ) ),
		'Havířov'            => array( 'info' => array( 12, 2, 'Moravskoslezském kraji', 71000, 6.4, 'Teplice' ), 'loc' => 'Havířově', 'rent' => 160, 'near' => array( 'Ostrava', 'Karviná', 'Frýdek-Místek', 'Třinec' ), 'infra' => array( 235, 'D1', null, 1, 15, 0, 3 ) ),
		'Kladno'             => array( 'info' => array( 13, 1, 'Středočeském kraji', 70000, 6.3, 'Ostrava' ), 'loc' => 'Kladně', 'rent' => 210, 'near' => array( 'Praha', 'Mladá Boleslav', 'Příbram' ), 'infra' => array( 40, 'D7', null, 1, 18, 0, 4 ) ),
		'Most'               => array( 'info' => array( 14, 2, 'Ústeckém kraji', 65000, 5.8, 'Karviná' ), 'loc' => 'Mostě', 'rent' => 140, 'near' => array( 'Ústí nad Labem', 'Teplice', 'Chomutov', 'Sokolov' ), 'infra' => array( 90, 'D7', null, 1, 15, 0, 3 ) ),
		'Opava'              => array( 'info' => array( 15, 3, 'Moravskoslezském kraji', 57000, 6.8, 'Přerov' ), 'loc' => 'Opavě', 'rent' => 180, 'near' => array( 'Ostrava', 'Karviná', 'Nový Jičín', 'Krnov' ), 'infra' => array( 255, 'D1, D56', 'SFC Opava', 1, 20, 1, 5 ) ),
		'Frýdek-Místek'      => array( 'info' => array( 16, 4, 'Moravskoslezském kraji', 56000, 6.7, 'Příbram' ), 'loc' => 'Frýdku-Místku', 'rent' => 185, 'near' => array( 'Ostrava', 'Třinec', 'Havířov', 'Vsetín', 'Kopřivnice' ), 'infra' => array( 220, 'D56', null, 1, 20, 1, 4 ) ),
		'Jihlava'            => array( 'info' => array( 17, 1, 'kraji Vysočina', 55000, 7.3, 'Tábor' ), 'loc' => 'Jihlavě', 'rent' => 195, 'near' => array( 'Třebíč', 'Znojmo', 'Tábor', 'Brno' ), 'infra' => array( 130, 'D1', null, 1, 22, 1, 5 ) ),
		'Karviná'            => array( 'info' => array( 18, 5, 'Moravskoslezském kraji', 54000, 5.9, 'Most' ), 'loc' => 'Karviné', 'rent' => 150, 'near' => array( 'Ostrava', 'Havířov', 'Frýdek-Místek', 'Třinec' ), 'infra' => array( 240, 'D1', null, 1, 15, 1, 3 ) ),
		'Teplice'            => array( 'info' => array( 19, 3, 'Ústeckém kraji', 50000, 6.4, 'Děčín' ), 'loc' => 'Teplicích', 'rent' => 165, 'near' => array( 'Most', 'Ústí nad Labem', 'Děčín', 'Chomutov' ), 'infra' => array( 80, 'D8', 'FK Teplice', 1, 20, 0, 5 ) ),
		'Děčín'              => array( 'info' => array( 20, 4, 'Ústeckém kraji', 48000, 6.2, 'Chomutov' ), 'loc' => 'Děčíně', 'rent' => 160, 'near' => array( 'Teplice', 'Ústí nad Labem', 'Česká Lípa' ), 'infra' => array( 110, 'D8', null, 1, 15, 0, 3 ) ),
		'Chomutov'           => array( 'info' => array( 21, 5, 'Ústeckém kraji', 48000, 6.2, 'Děčín' ), 'loc' => 'Chomutově', 'rent' => 160, 'near' => array( 'Most', 'Teplice', 'Ústí nad Labem', 'Sokolov' ), 'infra' => array( 100, 'D7', null, 1, 15, 0, 3 ) ),
		'Mladá Boleslav'     => array( 'info' => array( 22, 2, 'Středočeském kraji', 45000, 6.8, 'Přerov' ), 'loc' => 'Mladé Boleslavi', 'rent' => 205, 'near' => array( 'Praha', 'Kladno', 'Kolín', 'Jablonec nad Nisou' ), 'infra' => array( 70, 'D10', 'FK Mladá Boleslav', 1, 20, 1, 4 ) ),
		'Jablonec nad Nisou' => array( 'info' => array( 23, 2, 'Libereckém kraji', 44000, 7.2, 'Jihlava' ), 'loc' => 'Jablonci nad Nisou', 'rent' => 205, 'near' => array( 'Liberec', 'Česká Lípa', 'Mladá Boleslav' ), 'infra' => array( 120, 'R35', 'FK Jablonec', 1, 15, 0, 4 ) ),
		'Přerov'             => array( 'info' => array( 24, 2, 'Olomouckém kraji', 43000, 6.8, 'Opava' ), 'loc' => 'Přerově', 'rent' => 185, 'near' => array( 'Olomouc', 'Prostějov', 'Kroměříž', 'Uherské Hradiště' ), 'infra' => array( 155, 'D1, D35', null, 1, 18, 0, 4 ) ),
		'Prostějov'          => array( 'info' => array( 25, 3, 'Olomouckém kraji', 43000, 6.9, 'Znojmo' ), 'loc' => 'Prostějově', 'rent' => 180, 'near' => array( 'Olomouc', 'Přerov', 'Kroměříž' ), 'infra' => array( 165, 'D46', null, 1, 20, 0, 4 ) ),
		'Třebíč'             => array( 'info' => array( 26, 2, 'kraji Vysočina', 36000, 7.2, 'Plzeň' ), 'loc' => 'Třebíči', 'rent' => 180, 'near' => array( 'Jihlava', 'Znojmo', 'Brno' ), 'infra' => array( 160, 'D1 (přes Jihlavu)', null, 1, 15, 0, 3 ) ),
		'Česká Lípa'         => array( 'info' => array( 27, 3, 'Libereckém kraji', 36000, 6.5, 'Cheb' ), 'loc' => 'České Lípě', 'rent' => 190, 'near' => array( 'Liberec', 'Jablonec nad Nisou', 'Děčín' ), 'infra' => array( 110, 'R35', null, 1, 15, 0, 3 ) ),
		'Tábor'              => array( 'info' => array( 28, 2, 'Jihočeském kraji', 35000, 7.2, 'Jablonec nad Nisou' ), 'loc' => 'Táboře', 'rent' => 195, 'near' => array( 'České Budějovice', 'Písek', 'Jihlava' ), 'infra' => array( 90, 'D3', null, 1, 15, 0, 4 ) ),
		'Třinec'             => array( 'info' => array( 29, 6, 'Moravskoslezském kraji', 35000, 7.0, 'Kolín' ), 'loc' => 'Třinci', 'rent' => 175, 'near' => array( 'Frýdek-Místek', 'Havířov', 'Karviná', 'Kopřivnice' ), 'infra' => array( 250, 'D56', 'FK Třinec', 1, 12, 0, 2 ) ),
		'Znojmo'             => array( 'info' => array( 30, 2, 'Jihomoravském kraji', 35000, 6.9, 'Prostějov' ), 'loc' => 'Znojmě', 'rent' => 175, 'near' => array( 'Brno', 'Jihlava', 'Třebíč', 'Hodonín' ), 'infra' => array( 200, 'D52', null, 1, 15, 0, 4 ) ),
		'Kolín'              => array( 'info' => array( 31, 4, 'Středočeském kraji', 33000, 7.0, 'Třinec' ), 'loc' => 'Kolíně', 'rent' => 205, 'near' => array( 'Praha', 'Hradec Králové', 'Pardubice', 'Příbram' ), 'infra' => array( 55, 'D11', null, 1, 15, 0, 3 ) ),
		'Příbram'            => array( 'info' => array( 32, 3, 'Středočeském kraji', 33000, 6.7, 'Frýdek-Místek' ), 'loc' => 'Příbrami', 'rent' => 195, 'near' => array( 'Praha', 'Kladno', 'Kolín', 'Písek' ), 'infra' => array( 70, 'D4', null, 1, 15, 0, 3 ) ),
		'Cheb'               => array( 'info' => array( 33, 2, 'Karlovarském kraji', 33000, 6.5, 'Česká Lípa' ), 'loc' => 'Chebu', 'rent' => 185, 'near' => array( 'Sokolov', 'Plzeň' ), 'infra' => array( 190, 'D6', null, 1, 12, 0, 3 ) ),
		'Trutnov'            => array( 'info' => array( 34, 2, 'Královéhradeckém kraji', 31000, 7.1, 'Třebíč' ), 'loc' => 'Trutnově', 'rent' => 190, 'near' => array( 'Hradec Králové', 'Liberec', 'Jablonec nad Nisou' ), 'infra' => array( 145, 'R11', null, 1, 15, 0, 3 ) ),
		'Písek'              => array( 'info' => array( 35, 3, 'Jihočeském kraji', 30000, 7.3, 'Jihlava' ), 'loc' => 'Písku', 'rent' => 195, 'near' => array( 'Tábor', 'České Budějovice', 'Příbram' ), 'infra' => array( 110, 'D4', null, 1, 12, 0, 3 ) ),
		'Kroměříž'           => array( 'info' => array( 36, 2, 'Zlínském kraji', 29000, 7.2, 'Uherské Hradiště' ), 'loc' => 'Kroměříži', 'rent' => 180, 'near' => array( 'Prostějov', 'Přerov', 'Uherské Hradiště', 'Zlín' ), 'infra' => array( 200, 'D55', null, 1, 12, 0, 4 ) ),
		'Šumperk'            => array( 'info' => array( 37, 4, 'Olomouckém kraji', 27000, 6.9, 'Prostějov' ), 'loc' => 'Šumperku', 'rent' => 175, 'near' => array( 'Olomouc', 'Přerov', 'Vsetín' ), 'infra' => array( 180, '', null, 1, 15, 0, 3 ) ),
		'Uherské Hradiště'   => array( 'info' => array( 38, 3, 'Zlínském kraji', 26000, 7.2, 'Kroměříž' ), 'loc' => 'Uherském Hradišti', 'rent' => 195, 'near' => array( 'Zlín', 'Kroměříž', 'Přerov', 'Hodonín', 'Vsetín' ), 'infra' => array( 190, 'D55', 'FC Slovácko', 1, 18, 1, 5 ) ),
		'Vsetín'             => array( 'info' => array( 39, 4, 'Zlínském kraji', 26000, 7.0, 'Třinec' ), 'loc' => 'Vsetíně', 'rent' => 175, 'near' => array( 'Zlín', 'Frýdek-Místek', 'Nový Jičín', 'Uherské Hradiště' ), 'infra' => array( 230, '', null, 1, 12, 0, 3 ) ),
		'Hodonín'            => array( 'info' => array( 40, 3, 'Jihomoravském kraji', 25000, 6.7, 'Příbram' ), 'loc' => 'Hodoníně', 'rent' => 170, 'near' => array( 'Brno', 'Znojmo', 'Břeclav', 'Uherské Hradiště' ), 'infra' => array( 200, 'D2', null, 1, 12, 0, 3 ) ),
		'Břeclav'            => array( 'info' => array( 41, 4, 'Jihomoravském kraji', 25000, 6.8, 'Mladá Boleslav' ), 'loc' => 'Břeclavi', 'rent' => 180, 'near' => array( 'Brno', 'Hodonín', 'Znojmo' ), 'infra' => array( 160, 'D2', null, 0, 10, 0, 2 ) ),
		'Sokolov'            => array( 'info' => array( 42, 3, 'Karlovarském kraji', 24000, 6.3, 'Ostrava' ), 'loc' => 'Sokolově', 'rent' => 155, 'near' => array( 'Cheb', 'Chomutov', 'Most' ), 'infra' => array( 175, 'D6', null, 1, 10, 0, 2 ) ),
		'Nový Jičín'         => array( 'info' => array( 43, 7, 'Moravskoslezském kraji', 24000, 7.0, 'Vsetín' ), 'loc' => 'Novém Jičíně', 'rent' => 185, 'near' => array( 'Ostrava', 'Opava', 'Kopřivnice', 'Frýdek-Místek', 'Vsetín' ), 'infra' => array( 230, 'D1, D56', null, 1, 12, 0, 3 ) ),
		'Kopřivnice'         => array( 'info' => array( 44, 8, 'Moravskoslezském kraji', 23000, 7.0, 'Třinec' ), 'loc' => 'Kopřivnici', 'rent' => 180, 'near' => array( 'Frýdek-Místek', 'Nový Jičín', 'Třinec', 'Ostrava' ), 'infra' => array( 230, 'D48', null, 0, 10, 0, 2 ) ),
		'Krnov'              => array( 'info' => array( 45, 9, 'Moravskoslezském kraji', 22000, 6.5, 'Cheb' ), 'loc' => 'Krnově', 'rent' => 165, 'near' => array( 'Opava', 'Ostrava', 'Nový Jičín' ), 'infra' => array( 275, '', null, 1, 8, 0, 2 ) ),
		'Karlovy Vary'       => array( 'info' => array( 17, 1, 'Karlovarském kraji', 49000, 6.2, 'Cheb' ), 'loc' => 'Karlových Varech', 'rent' => 185, 'near' => array( 'Cheb', 'Sokolov', 'Most' ), 'infra' => array( 185, 'D6', null, 1, 15, 1, 6 ) ),
	);
}

function mxr_agr_num( $n ) { return number_format( (float) $n, 0, ',', ' ' ); }
function mxr_agr_kc( $n ) { return mxr_agr_num( $n ) . ' Kč'; }

/** Poslední hodnota řady z market_stats.json: array( medián, počet ) nebo null. */
function mxr_agr_last( $series, $key ) {
	if ( empty( $series[ $key ] ) ) {
		return null;
	}
	$p = end( $series[ $key ] );
	return array( (int) $p[1], (int) $p[2] );
}

add_shortcode( 'nemovitosti_real_v2', function ( $atts ) {
	$atts = shortcode_atts( array( 'city' => '', 'type' => '', 'disp' => '', 'extra' => '', 'own' => '' ), $atts );
	$seg  = '';
	if ( in_array( $atts['type'], array( 'byt', 'byty' ), true ) ) { $seg = 'byt'; }
	if ( in_array( $atts['type'], array( 'dům', 'dum', 'domy' ), true ) ) { $seg = 'dum'; }
	$st = array( 'seg' => $seg, 'city' => $atts['city'], 'disp' => $atts['disp'], 'extra' => $atts['extra'], 'own' => $atts['own'] );

	$cities  = mxr_agr_cities();
	$pages   = mxr_agr_pages();
	$mesta   = mxr_agr_json( 'mesta.json' );
	$mesta   = isset( $mesta['mesta'] ) ? $mesta['mesta'] : array();
	$stats   = mxr_agr_json( 'market_stats.json' );
	$series  = isset( $stats['series'] ) ? $stats['series'] : array();
	$stranky = mxr_agr_json( 'stranky.json' );
	$stranky = isset( $stranky['pages'] ) ? $stranky['pages'] : array();

	$city = $st['city'];
	$ci   = ( $city && isset( $cities[ $city ] ) ) ? $cities[ $city ] : null;
	$loc  = $ci ? $ci['loc'] : $city;
	$m    = ( $city && isset( $mesta[ $city ] ) ) ? $mesta[ $city ] : null;
	$kraj = $m ? $m['kraj_slug'] : '';
	$cz_byt = mxr_agr_last( $series, 'cr|CZ|byt|' );
	$cz_dum = mxr_agr_last( $series, 'cr|CZ|dum|' );
	$what   = 'byt' === $seg ? 'bytů' : ( 'dum' === $seg ? 'domů' : 'bytů a domů' );

	// Odkaz na stránku jiného města se zachováním typu (na /real/byty/brno/ vede Kladno na /real/byty/kladno/)
	$city_link = function ( $name, $label = null ) use ( $seg ) {
		$u = mxr_agr_url( array( 'seg' => $seg, 'city' => $name, 'disp' => '', 'extra' => '', 'own' => '' ) );
		if ( ! $u ) {
			$u = mxr_agr_url( array( 'seg' => '', 'city' => $name, 'disp' => '', 'extra' => '', 'own' => '' ) );
		}
		$label = esc_html( null === $label ? $name : $label );
		return $u ? '<a href="' . esc_url( $u ) . '">' . $label . '</a>' : $label;
	};

	// ── Úvodní text ──────────────────────────────────────────────────────────
	$intro = array();
	if ( $city ) {
		if ( $ci ) {
			list( $r_cz, $r_kraj, $kraj_loc, $pop, $hap, $hap_cmp ) = $ci['info'];
			$s = '<strong>' . esc_html( $city ) . '</strong> je ' . $r_cz . '. největší město ČR';
			if ( $r_kraj > 0 && $kraj_loc ) {
				$s .= ' a ' . ( 1 === $r_kraj ? 'největší' : $r_kraj . '. největší' ) . ' v ' . $kraj_loc;
			}
			$s .= ', žije tu zhruba ' . mxr_agr_num( $pop ) . ' obyvatel.';
			$scale = $hap >= 7.5 ? 'výrazně nadprůměrná' : ( $hap >= 7.1 ? 'nadprůměrná' : ( $hap >= 6.6 ? 'průměrná' : ( $hap >= 6.0 ? 'podprůměrná' : 'výrazně podprůměrná' ) ) );
			$s .= ' Spokojenost obyvatel je ' . $scale . ', ' . number_format( $hap, 1, ',', '' ) . ' z 10';
			if ( $hap_cmp && isset( $cities[ $hap_cmp ] ) ) {
				$s .= ', podobně jako v ' . $city_link( $hap_cmp, $cities[ $hap_cmp ]['loc'] );
			}
			$intro[] = $s . '.';
		}
		if ( $m ) {
			$n = 0; $n60 = 0;
			foreach ( $m['seg'] as $k => $v ) {
				if ( ! $seg || $seg === $k ) { $n += $v['n']; $n60 += $v['n60']; }
			}
			$s = 'Právě teď je v ' . esc_html( $loc ) . ' na prodej ' . mxr_agr_num( $n ) . ' ' . $what . '.';
			$med_b = isset( $m['seg']['byt']['median'] ) ? $m['seg']['byt']['median'] : 0;
			$med_d = isset( $m['seg']['dum']['median'] ) ? $m['seg']['dum']['median'] : 0;
			if ( $med_b && 'dum' !== $seg ) {
				$s .= ' Medián nabídkové ceny bytů je ' . mxr_agr_kc( $med_b ) . ' za m²';
				if ( $cz_byt ) {
					$d = round( ( $med_b / $cz_byt[0] - 1 ) * 100 );
					$s .= abs( $d ) < 3 ? ', zhruba jako průměr ČR' : ', o ' . abs( $d ) . ' % ' . ( $d > 0 ? 'víc' : 'méně' ) . ' než medián celé ČR';
				}
				$s .= '.';
			}
			if ( $med_d && 'byt' !== $seg ) {
				$s .= ' U domů je to ' . mxr_agr_kc( $med_d ) . ' za m².';
			}
			$s .= ' Nabídek se skóre výhodnosti 60 a víc, tedy mezi 5 % nejvýhodnějšími na trhu, je ' . mxr_agr_num( $n60 ) . '.';
			$intro[] = $s;
		}
	} elseif ( $st['disp'] ) {
		$g    = preg_match( '/^(\d)/', $st['disp'], $mm ) ? $mm[1] : '';
		$s    = 'Byty ' . esc_html( $st['disp'] ) . ' na prodej z celé ČR, seřazené podle toho, jak výhodná je cena proti podobným bytům v okolí.';
		$last = $g ? mxr_agr_last( $series, 'cr|CZ|byt|' . $g ) : null;
		if ( $last ) {
			$s .= ' Medián nabídkové ceny bytů s ' . $g . ' pokoji (' . $g . '+kk i ' . $g . '+1) je ' . mxr_agr_kc( $last[0] ) . ' za m², počítáno z ' . mxr_agr_num( $last[1] ) . ' nabídek.';
		}
		$intro[] = $s;
	} elseif ( $st['extra'] ) {
		$phr = array( 'Balkón' => 's balkónem', 'Garáž' => 's garáží', 'Lodžie' => 's lodžií', 'Novostavba' => 'v novostavbách', 'Parkování' => 's parkováním', 'Sklep' => 'se sklepem', 'Terasa' => 's terasou', 'Výtah' => 's výtahem', 'Zařízeno' => 'zařízené', 'Částečně zařízeno' => 'částečně zařízené' );
		$p   = isset( $phr[ $st['extra'] ] ) ? $phr[ $st['extra'] ] : 's vybavením ' . esc_html( $st['extra'] );
		$intro[] = 'Byty a domy na prodej ' . $p . ' z celé ČR, seřazené podle výhodnosti ceny. Každou nabídku srovnáváme s mediánem ceny za m² u podobných nemovitostí ve stejné lokalitě.';
	} elseif ( $st['own'] ) {
		if ( 'Družstevní' === $st['own'] ) {
			$intro[] = 'Družstevní byty na prodej z celé ČR. U družstevního bytu kupujete členský podíl v družstvu, ne byt samotný, a u novostaveb bývá v inzerované ceně jen část, zbytek se splácí přes družstvo. Skóre výhodnosti proto u družstevních bytů krátíme koeficientem 0,8.';
		} else {
			$intro[] = 'Byty a domy v osobním vlastnictví na prodej z celé ČR, seřazené podle výhodnosti ceny proti podobným nemovitostem v okolí.';
		}
	} elseif ( $seg ) {
		$last    = 'byt' === $seg ? $cz_byt : $cz_dum;
		$s       = ( 'byt' === $seg ? 'Byty' : 'Rodinné domy a vily' ) . ' na prodej z celé ČR, seřazené podle výhodnosti ceny.';
		if ( $last ) {
			$s .= ' V nabídce je teď ' . mxr_agr_num( $last[1] ) . ' srovnatelných ' . $what . ' a medián ceny je ' . mxr_agr_kc( $last[0] ) . ' za m².';
		}
		$intro[] = $s;
	} else {
		$s = 'Každý den projdeme všechny byty a domy na prodej na Sreality a každou nabídku porovnáme s cenou podobných nemovitostí ve stejné lokalitě. Tady jsou ty nejvýhodnější.';
		if ( $cz_byt && $cz_dum ) {
			$s .= ' Medián nabídkové ceny bytů v ČR je ' . mxr_agr_kc( $cz_byt[0] ) . ' za m², domů ' . mxr_agr_kc( $cz_dum[0] ) . ' za m².';
		}
		$intro[] = $s;
	}

	// ── 12 nejvýhodnějších nabídek ze serveru ────────────────────────────────
	if ( $city ) {
		$key = 'c:' . $city . ( $seg ? '|t:' . $seg : '' );
	} elseif ( $st['disp'] ) { $key = 'd:' . $st['disp'];
	} elseif ( $st['extra'] ) { $key = 'e:' . $st['extra'];
	} elseif ( $st['own'] ) { $key = 'o:' . $st['own'];
	} elseif ( $seg ) { $key = 't:' . $seg;
	} else { $key = 'all'; }
	$top = isset( $stranky[ $key ] ) ? $stranky[ $key ] : array();

	// ── Stránky pro filtry (JS z nich skládá URL) ────────────────────────────
	$disps  = array( '1+kk', '1+1', '2+kk', '2+1', '3+kk', '3+1', '4+kk', '4+1', '5+kk', '5+1', 'ostatní' );
	$extras = array( 'Balkón', 'Garáž', 'Lodžie', 'Novostavba', 'Parkování', 'Sklep', 'Terasa', 'Výtah', 'Zařízeno', 'Částečně zařízeno' );
	$owns   = array( 'Osobní', 'Družstevní' );
	$page_cities = array();
	foreach ( array_keys( $cities ) as $c ) {
		if ( isset( $pages[ 'real/' . mxr_agr_slug( $c ) ] ) ) { $page_cities[] = $c; }
	}
	usort( $page_cities, function ( $a, $b ) { return strcoll( remove_accents( $a ), remove_accents( $b ) ); } );
	$opt = function ( $vals, $sel, $empty, $labels = array() ) {
		$h = '<option value="">' . $empty . '</option>';
		foreach ( $vals as $v ) {
			$h .= '<option value="' . esc_attr( $v ) . '"' . selected( $v, $sel, false ) . '>' . esc_html( isset( $labels[ $v ] ) ? $labels[ $v ] : $v ) . '</option>';
		}
		return $h;
	};
	$paths = array();
	foreach ( $pages as $p => $u ) { $paths[ $p ] = wp_make_link_relative( $u ); }

	ob_start();
	?>
<div class="rv2" data-base="<?php echo esc_url( mxr_agr_base() ); ?>" data-kraj="<?php echo esc_attr( $kraj ); ?>"
  data-seg="<?php echo esc_attr( $seg ); ?>" data-city="<?php echo esc_attr( $city ); ?>" data-disp="<?php echo esc_attr( $st['disp'] ); ?>"
  data-extra="<?php echo esc_attr( $st['extra'] ); ?>" data-own="<?php echo esc_attr( $st['own'] ); ?>">
<style>
.rv2 { --red:#c8102e; --red-dark:#9e0c24; --ink:#222; --ink-2:#2d2d2d; --muted:#6b6b6b; --line:#e0e0e0; --ok:#1d7a3a;
  font-family:"Nunito Sans",sans-serif; color:var(--ink-2); }
.rv2 * { box-sizing:border-box; }
.rv2-lead p { font-size:16px; line-height:1.65; color:#333; margin:0 0 10px; max-width:900px; }
.rv2-lead a, .rv2-sec a, .rv2-hub a { color:var(--red); }
.rv2-intro { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); border:1px solid var(--line); background:#fff; margin:18px 0 22px; }
.rv2-intro div { padding:14px 18px; border-right:1px solid var(--line); }
.rv2-intro div:last-child { border-right:0; }
.rv2-intro b { display:block; font:700 21px/1.2 Poppins,sans-serif; color:var(--ink); }
.rv2-intro span { font-size:12.5px; color:var(--muted); }
.rv2-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:0 0 18px; }
.rv2-bar label { display:flex; flex-direction:column; gap:4px; font:600 11px Poppins,sans-serif; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
.rv2-bar select, .rv2-bar input { font:14px "Nunito Sans",sans-serif; padding:8px 10px; border:1px solid #cfcfcf; border-radius:0; background:#fff; color:var(--ink); min-width:140px; height:40px; }
.rv2-stat { font-size:14px; color:var(--muted); margin:0 0 16px; }
.rv2-stat b { color:var(--ink); }
.rv2-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:24px; align-items:start; }
.rv2-card { background:#fff; border:1px solid var(--line); display:flex; flex-direction:column; transition:box-shadow .2s, transform .2s, border-color .2s; }
.rv2-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.09); transform:translateY(-2px); border-color:#d2d2d2; }
.rv2-photo { position:relative; aspect-ratio:3/2; background:#e9e9e9 center/cover no-repeat; display:block; overflow:hidden; }
.rv2-photo::after { content:""; position:absolute; inset:45% 0 0 0; background:linear-gradient(180deg,rgba(0,0,0,0),rgba(0,0,0,.78)); }
.rv2-nophoto { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#999; font-size:13px; }
.rv2-tags { position:absolute; left:0; top:14px; display:flex; flex-direction:column; gap:4px; align-items:flex-start; z-index:2; }
.rv2-tag { display:inline-block; font:600 11.5px/1 Poppins,sans-serif; letter-spacing:.04em; text-transform:uppercase; padding:6px 9px; }
.rv2-tag.red { background:var(--red); color:#fff; } .rv2-tag.ink { background:var(--ink); color:#fff; } .rv2-tag.white { background:#fff; color:var(--ink); }
.rv2-count { position:absolute; right:10px; top:10px; z-index:2; background:rgba(0,0,0,.6); color:#fff; font-size:12px; padding:2px 7px; }
.rv2-over { position:absolute; left:18px; right:18px; bottom:12px; color:#fff; z-index:2; }
.rv2-over .p { font:700 23px/1.15 Poppins,sans-serif; }
.rv2-over .s { font-size:13px; opacity:.92; }
.rv2-body { padding:14px 18px 0; display:flex; flex-direction:column; flex:1; }
.rv2-title { font:600 15px/1.35 Poppins,sans-serif; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin:0; }
.rv2-loc { color:var(--muted); font-size:13.5px; line-height:1.4; height:2.8em; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; margin:2px 0 12px; }
.rv2-score { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.rv2-score .n { font:700 30px/1 Poppins,sans-serif; color:var(--ink); }
.rv2-score .n small { font-size:13px; color:var(--muted); font-weight:500; }
.rv2-score .r { flex:1; }
.rv2-score .r b { display:block; font:600 12px Poppins,sans-serif; text-transform:uppercase; letter-spacing:.05em; color:var(--ink); margin-bottom:5px; }
.rv2-segs { display:grid; grid-template-columns:repeat(10,1fr); gap:3px; }
.rv2-segs i { height:6px; background:#e6e6e6; } .rv2-segs i.on { background:var(--red); }
.rv2-mkt { margin:0 0 12px; }
.rv2-track { position:relative; height:8px; background:linear-gradient(90deg,#1d7a3a 0%,#8bbf6a 35%,#e7e7e7 50%,#e9a3a3 70%,#c8102e 100%); }
.rv2-track .mid { position:absolute; top:-3px; left:50%; width:1px; height:14px; background:#fff; }
.rv2-track .mk { position:absolute; top:-5px; width:3px; height:18px; background:var(--ink); margin-left:-1px; }
.rv2-mkt .l { display:flex; justify-content:space-between; font-size:11.5px; color:var(--muted); margin-top:4px; }
.rv2-mkt .t { font-size:13px; margin-bottom:6px; }
.rv2-mkt .t strong { color:var(--ok); }
.rv2-chips { display:flex; flex-wrap:wrap; align-content:flex-start; gap:6px; height:58px; overflow:hidden; margin-bottom:12px; }
.rv2-chip { display:inline-block; font-size:12.5px; line-height:18px; border:1px solid var(--line); padding:3px 8px; margin:0; background:#fff; white-space:nowrap; }
.rv2-chip.sig { border-color:var(--ink-2); }
.rv2-foot { display:flex; padding:0 18px 18px; margin-top:auto; }
.rv2-why { background:none; border:0; padding:0; margin-top:6px; font:600 12.5px "Nunito Sans",sans-serif; color:var(--muted); text-decoration:underline; text-underline-offset:2px; cursor:pointer; }
.rv2-why:hover { color:var(--red); }
.rv2-btn { display:inline-flex; align-items:center; justify-content:center; font:700 13px Poppins,sans-serif; padding:11px 14px; text-decoration:none !important; cursor:pointer; border:0; border-radius:0; line-height:1.2; }
.rv2-btn.red { background:var(--red); color:#fff !important; flex:1; } .rv2-btn.red:hover { background:var(--red-dark); }
.rv2-btn.line { background:#fff; color:var(--ink) !important; border:1px solid var(--ink); }
.rv2-more { display:none; border-top:1px solid var(--line); padding:16px 18px 18px; font-size:14px; background:#fcfcfc; }
.rv2-card.open .rv2-more { display:block; }
.rv2-more h4 { font:600 12.5px Poppins,sans-serif; margin:0 0 8px; color:var(--ink); text-transform:uppercase; letter-spacing:.04em; }
.rv2-more p { margin:0 0 10px; color:var(--muted); font-size:13.5px; line-height:1.55; }
.rv2-more table { width:100%; border-collapse:collapse; margin:0 0 12px; font-size:13.5px; }
.rv2-more td { padding:5px 0; border-bottom:1px solid #eee; vertical-align:top; }
.rv2-more td:last-child { text-align:right; font-weight:700; white-space:nowrap; padding-left:10px; }
.rv2-more td.neg { color:var(--red); }
.rv2-more tr.total td { border-bottom:0; border-top:2px solid var(--ink); color:var(--ink); padding-top:8px; }
.rv2-more .alt a { color:var(--red); }
.rv2-load { display:block; margin:28px auto 0; }
.rv2-empty { padding:40px; text-align:center; color:var(--muted); border:1px dashed var(--line); }
.rv2-sec { margin:44px 0 0; }
.rv2-sec h2, .rv2-hub h2 { font:700 22px Poppins,sans-serif; color:var(--ink); margin:0 0 10px; }
.rv2-sec p { font-size:15px; line-height:1.65; color:#333; margin:0 0 10px; max-width:900px; }
.rv2-sec .note { font-size:13px; color:var(--muted); }
.rv2-cmp { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:10px; margin:0 0 8px; }
.rv2-cmp div { background:#fff; border:1px solid var(--line); padding:12px 14px; }
.rv2-cmp div.cur { border-color:var(--ink); }
.rv2-cmp b { display:block; font:700 17px Poppins,sans-serif; color:var(--ink); margin-top:2px; }
.rv2-facts { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:0; border:1px solid var(--line); background:#fff; max-width:900px; }
.rv2-facts div { padding:11px 16px; border-bottom:1px solid var(--line); font-size:14.5px; }
.rv2-facts span { display:block; font-size:12px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
.rv2-yield { display:inline-block; font:700 18px Poppins,sans-serif; color:var(--ink); }
.rv2-svg { background:#fff; border:1px solid var(--line); padding:14px 10px 6px; min-height:60px; }
.rv2-svg svg { display:block; width:100%; height:auto; }
.rv2-leg { display:flex; gap:18px; font-size:13px; color:var(--ink-2); margin:10px 4px 4px; }
.rv2-leg i { display:inline-block; width:14px; height:3px; vertical-align:middle; margin-right:6px; }
.rv2-hub { margin:48px 0 0; padding:28px 0 0; border-top:2px solid var(--ink); }
.rv2-hub h3 { font:600 13px Poppins,sans-serif; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin:18px 0 8px; }
.rv2-hub ul { list-style:none; margin:0; padding:0; display:flex; flex-wrap:wrap; gap:6px 18px; }
.rv2-hub li { margin:0; font-size:14.5px; }
.rv2-hub li.cur { font-weight:700; color:var(--ink); }
@media (max-width:600px) { .rv2-bar label, .rv2-bar select, .rv2-bar input { width:100%; } }
</style>

<div class="rv2-lead"><?php foreach ( $intro as $p ) { echo '<p>' . $p . '</p>'; } ?></div>

<?php
// Dlaždice s čísly trhu
$tiles = array();
if ( $m ) {
	$n = 0; $n60 = 0;
	foreach ( $m['seg'] as $k => $v ) { if ( ! $seg || $seg === $k ) { $n += $v['n']; $n60 += $v['n60']; } }
	$tiles[] = array( mxr_agr_num( $n ), $what . ' na prodej, ' . esc_html( $city ) );
	if ( ! empty( $m['seg']['byt']['median'] ) && 'dum' !== $seg ) { $tiles[] = array( mxr_agr_kc( $m['seg']['byt']['median'] ) . '/m²', 'medián ceny bytů' ); }
	if ( ! empty( $m['seg']['dum']['median'] ) && 'byt' !== $seg ) { $tiles[] = array( mxr_agr_kc( $m['seg']['dum']['median'] ) . '/m²', 'medián ceny domů' ); }
	$tiles[] = array( mxr_agr_num( $n60 ), 'nabídek se skóre 60 a víc' );
} elseif ( ! $city && $cz_byt && $cz_dum ) {
	if ( 'dum' !== $seg ) { $tiles[] = array( mxr_agr_num( $cz_byt[1] ), 'bytů na prodej v ČR' ); $tiles[] = array( mxr_agr_kc( $cz_byt[0] ) . '/m²', 'medián ceny bytů v ČR' ); }
	if ( 'byt' !== $seg ) { $tiles[] = array( mxr_agr_num( $cz_dum[1] ), 'domů na prodej v ČR' ); $tiles[] = array( mxr_agr_kc( $cz_dum[0] ) . '/m²', 'medián ceny domů v ČR' ); }
}
if ( $tiles ) {
	echo '<div class="rv2-intro">';
	foreach ( $tiles as $t ) { echo '<div><b>' . $t[0] . '</b><span>' . $t[1] . '</span></div>'; }
	echo '</div>';
}
?>

<div class="rv2-bar">
  <label>Typ<select data-f="seg"><?php echo $opt( array( 'byt', 'dum', 'rekreace' ), $seg, 'Byty i domy', array( 'byt' => 'Byty', 'dum' => 'Domy', 'rekreace' => 'Chaty a chalupy' ) ); ?></select></label>
  <label>Město<select data-f="city"><?php echo $opt( $page_cities, $city, 'Celá ČR' ); ?></select></label>
  <?php if ( $city ) : ?>
  <label>Část města<input data-f="q" type="search" placeholder="část města nebo ulice"></label>
  <?php else : ?>
  <label>Kraj<select data-f="region"><option value="">Všechny kraje</option></select></label>
  <label>Obec<input data-f="q" type="search" placeholder="např. Beroun"></label>
  <?php endif; ?>
  <label>Dispozice<select data-f="disp"><?php echo $opt( $disps, $st['disp'], 'Všechny' ); ?></select></label>
  <label>Vybavení<select data-f="extra"><?php echo $opt( $extras, $st['extra'], 'Jakékoli' ); ?></select></label>
  <label>Vlastnictví<select data-f="own"><?php echo $opt( $owns, $st['own'], 'Jakékoli' ); ?></select></label>
  <label>Max. cena<select data-f="max"><option value="">Bez omezení</option><option value="2000000">do 2 mil. Kč</option><option value="3000000">do 3 mil. Kč</option><option value="5000000">do 5 mil. Kč</option><option value="8000000">do 8 mil. Kč</option><option value="12000000">do 12 mil. Kč</option></select></label>
  <label>Řadit<select data-f="sort"><option value="score">Nejvýhodnější</option><option value="new">Nejnovější</option><option value="drop">Největší zlevnění</option><option value="price">Nejlevnější</option></select></label>
</div>
<p class="rv2-stat"><?php echo $top ? 'Nejvýhodnější nabídky podle skóre. Celý výpis se načítá…' : 'Načítám nabídky…'; ?></p>

<div class="rv2-grid">
<?php
foreach ( $top as $l ) {
	$d   = (int) $l['d'];
	$pos = max( 3, min( 97, 50 - $d ) );
	$on  = (int) round( $l['s'] / 10 );
	$sg  = '';
	for ( $k = 0; $k < 10; $k++ ) { $sg .= '<i class="' . ( $k < $on ? 'on' : '' ) . '"></i>'; }
	$title = preg_replace( array( '/^Prodej bytu/u', '/^Prodej rodinného domu/u', '/^Prodej /u' ), array( 'Byt', 'Rodinný dům', '' ), $l['t'] );
	$rating = $l['s'] >= 80 ? 'Výborná nabídka' : ( $l['s'] >= 60 ? 'Velmi dobrá' : ( $l['s'] >= 40 ? 'Dobrá' : 'Běžná cena' ) );
	$img = $l['i'] ? ' style="background-image:url(\'' . esc_url( $l['i'] . '?fl=res,800,600,3|shr,,20|webp,60' ) . '\')"' : '';
	echo '<article class="rv2-card"><a class="rv2-photo" href="' . esc_url( $l['u'] ) . '" target="_blank" rel="noopener nofollow"' . $img . '>'
		. '<span class="rv2-over"><span class="p">' . mxr_agr_kc( $l['p'] ) . '</span><br><span class="s">' . mxr_agr_num( $l['m'] ) . ' Kč/m² · ' . mxr_agr_num( $l['a'] ) . ' m²</span></span></a>'
		. '<div class="rv2-body"><h3 class="rv2-title">' . esc_html( $title ) . '</h3><div class="rv2-loc">' . esc_html( $l['l'] ) . '</div>'
		. '<div class="rv2-score"><div class="n">' . (int) $l['s'] . '<small>/100</small></div><div class="r"><b>' . $rating . '</b><div class="rv2-segs">' . $sg . '</div></div></div>'
		. '<div class="rv2-mkt"><div class="t">' . ( $d > 0 ? 'O <strong>' . $d . ' % levnější</strong> než srovnatelné nabídky' : 'Cena odpovídá srovnatelným nabídkám' ) . '</div>'
		. '<div class="rv2-track"><span class="mid"></span><span class="mk" style="left:' . $pos . '%"></span></div>'
		. '<div class="l"><span>levnější</span><span>trh ' . mxr_agr_num( $l['b'] ) . ' Kč/m²</span><span>dražší</span></div></div><div class="rv2-chips"></div></div>'
		. '<div class="rv2-foot"><a class="rv2-btn red" href="' . esc_url( $l['u'] ) . '" target="_blank" rel="noopener nofollow">Prohlédnout inzerát →</a></div></article>';
}
?>
</div>
<button class="rv2-btn line rv2-load" type="button" hidden>Načíst další</button>

<?php
// ── Sekce s texty (převzaté z verze 1, revidované) ──────────────────────────
$seg_med = function ( $name ) use ( $mesta, $seg ) {
	$s = 'dum' === $seg ? 'dum' : 'byt';
	return isset( $mesta[ $name ]['seg'][ $s ]['median'] ) ? (int) $mesta[ $name ]['seg'][ $s ]['median'] : 0;
};
if ( $ci ) {
	// Srovnání okolních měst
	$rows = array();
	foreach ( array_merge( array( $city ), $ci['near'] ) as $nm ) {
		$v = $seg_med( $nm );
		if ( $v ) { $rows[] = array( $nm, $v ); }
	}
	if ( count( $rows ) >= 2 ) {
		usort( $rows, function ( $a, $b ) { return $a[1] - $b[1]; } );
		echo '<section class="rv2-sec"><h2>Srovnání okolních měst</h2><p>Medián nabídkové ceny ' . ( 'dum' === $seg ? 'domů' : 'bytů' ) . ' za m² v ' . esc_html( $loc ) . ' a v okolních městech, od nejlevnějšího.</p><div class="rv2-cmp">';
		foreach ( $rows as $r ) {
			$cur = $r[0] === $city;
			echo '<div' . ( $cur ? ' class="cur"' : '' ) . '>' . ( $cur ? esc_html( $r[0] ) : $city_link( $r[0] ) ) . '<b>' . mxr_agr_kc( $r[1] ) . '/m²</b></div>';
		}
		echo '</div><p class="note">Počítáno denně z celé nabídky na Sreality.</p></section>';
	}
	// Výnosnost pronájmu
	$med = isset( $m['seg']['byt']['median'] ) ? $m['seg']['byt']['median'] : 0;
	if ( $ci['rent'] && $med && 'dum' !== $seg ) {
		$y = $ci['rent'] * 12 / $med * 100;
		$lbl = $y < 3 ? array( 'nízký výnos', 'Výnos je pod hranicí inflace, ceny bytů jsou vůči nájmům vysoko.' )
			: ( $y < 4 ? array( 'podprůměrný výnos', 'Pod průměrem ČR, spíš konzervativní investice s nižším výnosem.' )
			: ( $y < 5 ? array( 'průměrný výnos', 'Odpovídá průměru českého trhu, stabilní, ale bez výrazného zhodnocení.' )
			: ( $y < 7 ? array( 'nadprůměrný výnos', 'Nad průměrem ČR. Vyšší výnos ale často vyvažuje nižší poptávka po nájmu nebo horší stav domů.' )
			: array( 'vysoký výnos', 'Výrazně nad průměrem ČR. Ověřte poptávku po nájmu v konkrétní lokalitě a stav nemovitosti.' ) ) ) );
		echo '<section class="rv2-sec"><h2>Výnosnost pronájmu v ' . esc_html( $loc ) . '</h2>'
			. '<p>Průměrný nájem v ' . esc_html( $loc ) . ' je ' . mxr_agr_num( $ci['rent'] ) . ' Kč za m² měsíčně. Při mediánové ceně bytu ' . mxr_agr_kc( $med ) . ' za m² vychází hrubý výnos z pronájmu na <span class="rv2-yield">' . number_format( $y, 1, ',', '' ) . ' % ročně</span>, ' . $lbl[0] . '.</p>'
			. '<p>' . $lbl[1] . '</p>'
			. '<p class="note">Odhad vychází z průměrných nájmů (Bezrealitky, 2024) a mediánu cen aktuálních nabídek. Nezohledňuje daně, správu, pojištění ani období bez nájemníka, čistý výnos bývá o 1 až 2 procentní body nižší.</p></section>';
	}
	// Výhody města
	list( $train, $hw, $foot, $hosp, $hs, $uni, $cul ) = $ci['infra'];
	$facts = array();
	if ( null !== $train ) { $facts[] = array( 'Vlakem do Prahy', 'zhruba ' . $train . ' minut' ); }
	$facts[] = array( 'Dálniční napojení', $hw ? $hw : 'bez přímého napojení' );
	$facts[] = array( 'Prvoligový fotbal', $foot ? $foot : 'ne' );
	$facts[] = array( 'Nemocnice', $hosp );
	$facts[] = array( 'Střední školy', $hs );
	$facts[] = array( 'Vysoké školy a pobočky', $uni );
	$facts[] = array( 'Kina a divadla', $cul );
	echo '<section class="rv2-sec"><h2>Výhody města ' . esc_html( $city ) . '</h2><div class="rv2-facts">';
	foreach ( $facts as $f ) { echo '<div><span>' . $f[0] . '</span>' . esc_html( $f[1] ) . '</div>'; }
	echo '</div><p class="note">Zdroje: IDOS, ŘSD, ÚZIS, MŠMT, Fortuna liga, údaje za roky 2023 až 2025.</p></section>';
}
// Vývoj cen (graf kreslí JS z market_stats.json)
$ttl_what = 'byt' === $seg ? 'bytů' : ( 'dum' === $seg ? 'domů' : 'nemovitostí' );
echo '<section class="rv2-sec rv2-chart"><h2>Vývoj cen ' . $ttl_what . ' ' . ( $city ? 'v ' . esc_html( $loc ) : 'v ČR' ) . '</h2>'
	. '<p>Medián nabídkové ceny za m² počítaný každý den z celé nabídky na Sreality. Data z celého trhu sbíráme od 4. 10. 2026, graf se den po dni prodlužuje.</p><div class="rv2-svg"></div></section>';
?>

<section class="rv2-sec"><h2>Jak vybíráme výhodné nabídky</h2>
<p>Každou noc projdeme všechny byty a domy na prodej na Sreality. U každé nabídky spočítáme cenu za m² a porovnáme ji s mediánem podobných nemovitostí ve stejném městě nebo městské části. Byt srovnáváme s byty se stejným počtem pokojů, dům s domy, chatu s chatami, a srovnání přepočítáme na plochu, protože velké byty jsou za m² levnější.</p>
<p>Body přidává zlevnění, prodávající, který po delší době v nabídce snižuje cenu, čerstvá nabídka a prodej přímo od majitele. Ubírá nabídka, která je dlouho v nabídce bez zlevnění, družstevní vlastnictví a stavby ve výstavbě. Výsledné skóre 0 až 100 je pořadí na celém trhu: 60 a víc má 5 % nejvýhodnějších nabídek. Nájmy vydávané za prodej, podíly, dražby a zjevné chyby v inzerátech vyřazujeme.</p></section>

<nav class="rv2-hub" aria-label="Nabídky podle lokality a parametrů"><h2>Další nabídky na prodej</h2>
<?php
$li = function ( $label, $s ) use ( $st ) {
	$u = mxr_agr_url( array_merge( array( 'seg' => '', 'city' => '', 'disp' => '', 'extra' => '', 'own' => '' ), $s ) );
	if ( ! $u ) { return ''; }
	$cur = mxr_agr_path( array_merge( array( 'seg' => '', 'city' => '', 'disp' => '', 'extra' => '', 'own' => '' ), $s ) ) === mxr_agr_path( $st );
	return $cur ? '<li class="cur">' . esc_html( $label ) . '</li>' : '<li><a href="' . esc_url( $u ) . '">' . esc_html( $label ) . '</a></li>';
};
if ( $city ) {
	echo '<h3>' . esc_html( $city ) . '</h3><ul>' . $li( 'Nemovitosti ' . $city, array( 'city' => $city ) ) . $li( 'Byty ' . $city, array( 'city' => $city, 'seg' => 'byt' ) ) . $li( 'Domy ' . $city, array( 'city' => $city, 'seg' => 'dum' ) ) . '</ul>';
}
echo '<h3>' . ( 'byt' === $seg ? 'Byty podle města' : ( 'dum' === $seg ? 'Domy podle města' : 'Podle města' ) ) . '</h3><ul>';
foreach ( $page_cities as $c ) { echo $li( $c, array( 'city' => $c, 'seg' => $seg ) ); }
echo '</ul><h3>Byty podle dispozice</h3><ul>';
foreach ( $disps as $d ) { echo $li( 'Byty ' . $d, array( 'disp' => $d ) ); }
echo '</ul><h3>Podle vybavení</h3><ul>';
foreach ( $extras as $e ) { echo $li( $e, array( 'extra' => $e ) ); }
echo '</ul><h3>Podle typu a vlastnictví</h3><ul>' . $li( 'Všechny nabídky', array() ) . $li( 'Byty', array( 'seg' => 'byt' ) ) . $li( 'Domy', array( 'seg' => 'dum' ) );
foreach ( $owns as $o ) { echo $li( $o . ' vlastnictví', array( 'own' => $o ) ); }
echo '</ul>';
?>
</nav>

<?php
// Strukturovaná data: seznam nejvýhodnějších nabídek
if ( $top ) {
	$items = array();
	foreach ( $top as $i => $l ) {
		$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => $l['u'], 'name' => $l['t'] . ', ' . $l['l'] );
	}
	echo '<script type="application/ld+json">' . wp_json_encode( array(
		'@context' => 'https://schema.org', '@type' => 'ItemList',
		'name' => wp_strip_all_tags( get_the_title() ), 'numberOfItems' => count( $items ), 'itemListElement' => $items,
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
}
?>
<script type="application/json" class="rv2-pages"><?php echo wp_json_encode( $paths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>

<script>
(function () {
  var root = (document.currentScript && document.currentScript.closest(".rv2")) || document.querySelector(".rv2");
  var BASE = root.dataset.base, KRAJ = root.dataset.kraj || "";
  var INIT = { seg: root.dataset.seg || "", city: root.dataset.city || "", disp: root.dataset.disp || "", extra: root.dataset.extra || "", own: root.dataset.own || "" };
  var PAGES = {}; try { PAGES = JSON.parse(root.querySelector(".rv2-pages").textContent); } catch (e) {}
  var chart = root.querySelector(".rv2-chart");
  var PAGE = 24, shown = PAGE, all = [], list = [];
  var grid = root.querySelector(".rv2-grid"), stat = root.querySelector(".rv2-stat"), more = root.querySelector(".rv2-load");
  var f = {}; root.querySelectorAll("[data-f]").forEach(function (el) { f[el.getAttribute("data-f")] = el; });
  function val(k) { return f[k] ? f[k].value : ""; }

  // ── Stránka pro kombinaci filtrů (stejná logika jako mxr_agr_path v PHP) ──
  function slug(s) { return s.replace(/\+/g, "-").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/\s+/g, "-").replace(/[^a-z0-9-]/g, "").replace(/-+/g, "-"); }
  function pagePath(st) {
    var TYP = { byt: "byty", dum: "domy" };
    var dims = [st.city, st.disp, st.extra, st.own].filter(Boolean);
    if (dims.length > 1) return "";
    if (st.seg === "rekreace") return dims.length ? "" : "";
    if (st.city) return st.seg ? "real/" + TYP[st.seg] + "/" + slug(st.city) : "real/" + slug(st.city);
    if (st.seg && !dims.length) return "real/" + TYP[st.seg];
    if (st.seg) return "";
    if (st.disp) return "real/" + slug(st.disp);
    if (st.extra) return "real/" + slug(st.extra);
    if (st.own) return "real/" + slug(st.own);
    return "real";
  }
  function state() { return { seg: val("seg"), city: val("city"), disp: val("disp"), extra: val("extra"), own: val("own") }; }
  // Změněný filtr určuje, kam vést: město na stránku města (s typem), dispozice, vybavení a vlastnictví
  // mimo město na svou celostátní stránku. Co stránku nemá, filtruje se na místě.
  function navigate(changed) {
    var st = state();
    if (changed === "city") { st.disp = st.extra = st.own = ""; }
    if (["disp", "extra", "own"].indexOf(changed) >= 0 && !st.city && st[changed]) {
      var keep = st[changed]; st.seg = st.disp = st.extra = st.own = ""; st[changed] = keep;
    }
    var p = pagePath(st);
    var cur = pagePath(INIT);
    if (p && PAGES[p] && p !== cur) { location.href = PAGES[p]; return true; }
    // Odebrání města na městské stránce bez jiné stránky vede na hub
    if (INIT.city && !val("city") && PAGES.real) { location.href = PAGES.real; return true; }
    return false;
  }

  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function num(n) { return Math.round(n).toLocaleString("cs-CZ"); }
  function kc(n) { return num(n) + " Kč"; }
  function img(u) { return u + "?fl=res,800,600,3|shr,,20|webp,60"; }
  function below(l) { return l.benchmark ? Math.round((1 - l.price_per_m2 / l.benchmark.ppm2) * 100) : 0; }
  function rating(s) { return s >= 80 ? "Výborná nabídka" : s >= 60 ? "Velmi dobrá" : s >= 40 ? "Dobrá" : "Běžná cena"; }
  function short(l) {
    return l.title.replace(/^Prodej /, "").replace(/^bytu/, "Byt").replace(/^rodinného domu/, "Rodinný dům")
      .replace(/^chaty/, "Chata").replace(/^chalupy/, "Chalupa").replace(/^vily/, "Vila").replace(/^vícegeneračního domu/, "Vícegenerační dům")
      .replace(/^zemědělské usedlosti/, "Zemědělská usedlost").replace(/^památky/, "Památka");
  }
  function has(l, code) { return (l.signals || []).some(function (s) { return s.code === code; }); }
  function tags(l) {
    var t = [];
    if (l._tip) t.push(["Tip dne", "red"]);
    if (l.price_drop) t.push(["Zlevněno " + Math.round(l.price_drop.pct) + " %", "ink"]);
    if (has(l, "nove")) t.push(["Nové", "white"]);
    if (l.seller === "soukromy") t.push(["Od majitele", "white"]);
    return t.slice(0, 3).map(function (x) { return '<span class="rv2-tag ' + x[1] + '">' + x[0] + "</span>"; }).join("");
  }
  function chips(l) {
    var out = [], SHORT = { prohlidka: "Video / 3D", znovu: "Znovu vložený", povoluje: "Prodávající zlevňuje" };
    (l.signals || []).forEach(function (s) { if (["povoluje", "dlouho", "znovu", "prohlidka", "vicekrat"].indexOf(s.code) >= 0) out.push('<span class="rv2-chip sig">' + esc(SHORT[s.code] || s.label) + "</span>"); });
    if (l.ownership) out.push('<span class="rv2-chip">' + esc(l.ownership) + "</span>");
    (l.extras || []).forEach(function (e) { out.push('<span class="rv2-chip">' + esc(e) + "</span>"); });
    return out.slice(0, 4).join("");
  }
  function why(l) {
    var p = l.score_parts || {}, b = l.benchmark;
    var rows = [
      ["Cena za m² pod srovnávací cenou (" + below(l) + " %)", Math.round(p.cena || 0) + " / 75"],
      ["Zlevnění" + (l.price_drop ? " (z " + kc(l.price_drop.from) + ")" : ""), Math.round(p.zlevneni || 0) + " / 15"],
      ["Prodávající zlevňuje po 60+ dnech na trhu", (p.povoluje || 0) + " / 10"],
      ["Čerstvá nabídka (do 7 dní)" + (l.days_on_market == null ? ", stáří inzerátu zatím neznámé, body přepočteny" : ""), (p.cerstve || 0) + " / 5"],
      ["Přímo od majitele", (p.majitel || 0) + " / 5"],
      ["Znovu vložený inzerát", (p.znovu || 0) + " / 5"]
    ].map(function (r) { return "<tr><td>" + r[0] + "</td><td>" + r[1] + "</td></tr>"; }).join("");
    var pens = (l.penalties || []).map(function (x) { return '<tr><td>' + esc(x.label) + '</td><td class="neg">× ' + String(x.factor).replace(".", ",") + "</td></tr>"; }).join("");
    var alt = (l.alt_urls || []).length ? '<p class="alt">Stejná nemovitost je inzerovaná i jinde: ' + l.alt_urls.map(function (u, i) { return '<a href="' + esc(u) + '" target="_blank" rel="noopener nofollow">nabídka ' + (i + 2) + "</a>"; }).join(", ") + "</p>" : "";
    return "<h4>Proč je výhodný</h4><p>Srovnáváme s mediánem <b>" + kc(b.market_ppm2) + "/m²</b> u " + b.n + " inzerátů (" + esc(b.label) + "). " +
      "Po přepočtu na plochu " + num(l.area) + " m² (typicky " + b.typical_area + " m²) vychází srovnávací cena <b>" + kc(b.ppm2) + "/m²</b>, tahle nabídka má <b>" + kc(l.price_per_m2) + "/m²</b>.</p>" +
      "<table>" + rows + pens + "<tr><td>Body celkem</td><td>" + Math.round(p.body || 0) + "</td></tr>" +
      '<tr class="total"><td>Skóre výhodnosti</td><td>' + l.deal_score + " / 100</td></tr></table>" +
      "<p>Skóre ukazuje pořadí mezi všemi nabídkami na trhu: 60 a víc má 5 % nejvýhodnějších, 80 a víc 1 %. Inzerát, který je dlouho v nabídce bez zlevnění, skóre ztrácí.</p>" + alt;
  }
  function card(l) {
    var d = below(l), pos = Math.max(3, Math.min(97, 50 - d));
    var on = Math.round(l.deal_score / 10), segs = "";
    for (var k = 0; k < 10; k++) segs += '<i class="' + (k < on ? "on" : "") + '"></i>';
    var photo = l.images && l.images.length
      ? '<a class="rv2-photo" href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow" style="background-image:url(\'' + esc(img(l.images[0])) + '\')"><span class="rv2-count">' + (l.image_count || l.images.length) + ((l.image_count || 0) >= 6 ? "+" : "") + " fotek</span>"
      : '<a class="rv2-photo" href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow"><span class="rv2-nophoto">Fotka se načte při další aktualizaci</span>';
    return '<article class="rv2-card">' + photo +
      '<span class="rv2-tags">' + tags(l) + "</span>" +
      '<span class="rv2-over"><span class="p">' + kc(l.price) + '</span><br><span class="s">' + num(l.price_per_m2) + " Kč/m² · " + num(l.area) + " m²</span></span></a>" +
      '<div class="rv2-body"><h3 class="rv2-title" title="' + esc(short(l)) + '">' + esc(short(l)) + '</h3><div class="rv2-loc">' + esc(l.locality) + "</div>" +
      '<div class="rv2-score"><div class="n">' + l.deal_score + '<small>/100</small></div><div class="r"><b>' + rating(l.deal_score) + '</b><div class="rv2-segs">' + segs + '</div><button class="rv2-why" type="button" data-why>Proč je výhodný?</button></div></div>' +
      '<div class="rv2-mkt"><div class="t">' + (d > 0 ? "O <strong>" + d + " % levnější</strong> než srovnatelné nabídky" : "Cena odpovídá srovnatelným nabídkám") + "</div>" +
      '<div class="rv2-track"><span class="mid"></span><span class="mk" style="left:' + pos + '%"></span></div>' +
      '<div class="l"><span>levnější</span><span>trh ' + num(l.benchmark.ppm2) + " Kč/m²</span><span>dražší</span></div></div>" +
      '<div class="rv2-chips">' + chips(l) + "</div></div>" +
      '<div class="rv2-foot"><a class="rv2-btn red" href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow">Prohlédnout inzerát →</a></div>' +
      '<div class="rv2-more">' + why(l) + "</div></article>";
  }

  function apply() {
    var st = state(), reg = val("region"), q = val("q").trim().toLowerCase(), max = +val("max") || 0, sort = val("sort");
    list = all.filter(function (l) {
      return (!st.seg || l.segment === st.seg) && (!st.city || l.city === st.city) && (!st.disp || l.disposition === st.disp) &&
        (!st.extra || (l.extras || []).indexOf(st.extra) >= 0) && (!st.own || l.ownership === st.own) &&
        (!reg || l.region === reg) && (!max || l.price <= max) &&
        (!q || (l.locality + " " + l.locality_city).toLowerCase().indexOf(q) >= 0);
    });
    var by = {
      score: function (a, b) { return b.deal_score - a.deal_score; },
      "new": function (a, b) { return (b.first_seen || "").localeCompare(a.first_seen || ""); },
      drop: function (a, b) { return ((b.price_drop || {}).pct || 0) - ((a.price_drop || {}).pct || 0); },
      price: function (a, b) { return a.price - b.price; }
    }[sort];
    list.sort(by);
    shown = PAGE; render();
  }
  function render() {
    var good = list.filter(function (l) { return l.deal_score >= 60; }).length;
    stat.innerHTML = "Zobrazujeme <b>" + num(list.length) + "</b> nejvýhodnějších nabídek, z toho <b>" + num(good) + "</b> se skóre 60 a víc.";
    grid.innerHTML = list.length ? list.slice(0, shown).map(card).join("") : '<div class="rv2-empty">Žádná nabídka neodpovídá filtru.</div>';
    more.hidden = shown >= list.length;
  }
  grid.addEventListener("click", function (e) {
    var b = e.target.closest("[data-why]"); if (!b) return;
    var c = b.closest(".rv2-card"); c.classList.toggle("open");
    b.textContent = c.classList.contains("open") ? "Skrýt zdůvodnění" : "Proč je výhodný?";
  });
  more.addEventListener("click", function () { shown += PAGE; render(); });
  ["seg", "city", "disp", "extra", "own"].forEach(function (k) { if (f[k]) f[k].addEventListener("change", function () { if (!navigate(k)) apply(); }); });
  ["region", "max", "sort"].forEach(function (k) { if (f[k]) f[k].addEventListener("change", apply); });
  if (f.q) f.q.addEventListener("input", apply);

  // ── Graf ──────────────────────────────────────────────────────────────────
  function svgChart(lines) {
    var W = 760, H = 260, L = 64, R = 14, T = 12, B = 30, pts = [];
    lines.forEach(function (ln) { ln.data.forEach(function (d) { pts.push(d); }); });
    var dates = pts.map(function (d) { return +new Date(d[0]); }), vals = pts.map(function (d) { return d[1]; });
    var x0 = Math.min.apply(null, dates), x1 = Math.max.apply(null, dates);
    if (x1 === x0) { x0 -= 864e5 * 3; x1 += 864e5 * 3; }
    var v0 = Math.min.apply(null, vals) * 0.9, v1 = Math.max.apply(null, vals) * 1.1;
    function X(t) { return L + (W - L - R) * (t - x0) / (x1 - x0); }
    function Y(v) { return T + (H - T - B) * (1 - (v - v0) / (v1 - v0)); }
    var g = "";
    for (var i = 0; i <= 4; i++) {
      var v = v0 + (v1 - v0) * i / 4, y = Y(v);
      g += '<line x1="' + L + '" x2="' + (W - R) + '" y1="' + y + '" y2="' + y + '" stroke="#ececec"/>' +
        '<text x="' + (L - 8) + '" y="' + (y + 4) + '" text-anchor="end" font-size="11" fill="#6b6b6b">' + num(v / 1000) + " tis.</text>";
    }
    [x0, (x0 + x1) / 2, x1].forEach(function (t, i) {
      var d = new Date(t);
      g += '<text x="' + X(t) + '" y="' + (H - 8) + '" text-anchor="' + ["start", "middle", "end"][i] + '" font-size="11" fill="#6b6b6b">' + d.getDate() + ". " + (d.getMonth() + 1) + ". " + d.getFullYear() + "</text>";
    });
    lines.forEach(function (ln) {
      var path = ln.data.map(function (d, k) { return (k ? "L" : "M") + X(+new Date(d[0])).toFixed(1) + " " + Y(d[1]).toFixed(1); }).join(" ");
      g += '<path d="' + path + '" fill="none" stroke="' + ln.color + '" stroke-width="2.5"/>';
      ln.data.forEach(function (d) { g += '<circle cx="' + X(+new Date(d[0])) + '" cy="' + Y(d[1]) + '" r="4" fill="' + ln.color + '"><title>' + d[0] + ": " + kc(d[1]) + "/m²</title></circle>"; });
    });
    return '<svg viewBox="0 0 ' + W + " " + H + '" role="img" aria-label="Vývoj mediánu ceny za m²">' + g + "</svg>" +
      '<div class="rv2-leg">' + lines.map(function (ln) { return '<span><i style="background:' + ln.color + '"></i>' + ln.name + " " + kc(ln.data[ln.data.length - 1][1]) + "/m²</span>"; }).join("") + "</div>";
  }
  function renderChart(series) {
    var segs = INIT.seg ? [INIT.seg] : ["byt", "dum"], NAMES = { byt: "byty", dum: "domy" }, COLORS = { byt: "#c8102e", dum: "#222" };
    var lines = segs.map(function (sg) {
      var key;
      if (INIT.city) key = "mesto|" + INIT.city + "|" + sg + "|";
      else if (INIT.disp && sg === "byt" && /^[0-9]/.test(INIT.disp)) key = "cr|CZ|byt|" + INIT.disp.charAt(0);
      else key = "cr|CZ|" + sg + "|";
      return { name: NAMES[sg], color: COLORS[sg], data: series[key] || [] };
    }).filter(function (ln) { return ln.data.length; });
    if (!lines.length) { chart.hidden = true; return; }
    chart.querySelector(".rv2-svg").innerHTML = svgChart(lines);
  }
  // Parametr t mění adresu každých 10 minut: CDN GitHubu jinak umí držet starou kopii i po aktualizaci dat
  function getJSON(path) { return fetch(BASE + path + "?t=" + Math.floor(Date.now() / 6e5), { cache: "no-cache" }).then(function (r) { if (!r.ok) throw r.status; return r.json(); }); }

  getJSON(KRAJ ? "feed-kraje/" + KRAJ + ".json" : "feed.json").then(function (d) {
    all = (d.listings || []).filter(function (l) { return l.benchmark && l.deal_score != null; });
    var cut = new Date(Date.now() - 3 * 864e5).toISOString();   // Tip dne: nejlepší tři nabídky za poslední 3 dny
    all.filter(function (l) { return (l.first_seen || "") >= cut; }).sort(function (a, b) { return b.deal_score - a.deal_score; })
      .slice(0, 3).forEach(function (l) { l._tip = true; });
    if (f.region) {
      var regs = {}; all.forEach(function (l) { if (l.region) regs[l.region] = 1; });
      Object.keys(regs).sort(function (a, b) { return a.localeCompare(b, "cs"); }).forEach(function (r) {
        var o = document.createElement("option"); o.value = r; o.textContent = r; f.region.appendChild(o);
      });
    }
    apply();
  }).catch(function (e) { if (!all.length) stat.textContent = "Celý výpis se nepodařilo načíst, zobrazujeme nejvýhodnější nabídky."; if (window.console) console.warn("rv2", e); });
  getJSON("market_stats.json").then(function (m) { renderChart(m.series || {}); }).catch(function () {});
})();
</script>
</div>
	<?php
	return ob_get_clean();
} );
