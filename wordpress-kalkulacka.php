<?php
/**
 * Hypoteční kalkulačka a sběr kontaktů (Code Snippets na max-reality.cz, rozsah global).
 * Shortcode [hypotecni_kalkulacka] na https://www.max-reality.cz/hypotecni-kalkulacka/
 * Potřebuje snippet 15 (agregátor): mxr_agr_css(), mxr_agr_card(), mxr_agr_json(), mxr_agr_pages(), mxr_agr_top10().
 *
 * Kontakty z formuláře se ukládají jako soukromý typ obsahu „Leady“ (administrace, menu Leady) a chodí e-mailem
 * na adresu administrátora webu. Formulář se zobrazí jen když je v nastavení (option mxr_lead_config)
 * vyplněný provozovatel a partner a zapnuto 'on', na testovací stránce (ID 90620) vždy.
 * Tipař podle zákona 257/2016 smí jen předat kontakt jmenovanému partnerovi se souhlasem klienta.
 */

// ── Leady: soukromý typ obsahu ────────────────────────────────────────────────
add_action( 'init', function () {
	register_post_type( 'mxr_lead', array(
		'labels'          => array( 'name' => 'Leady', 'singular_name' => 'Lead', 'menu_name' => 'Leady z kalkulačky' ),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-groups',
		'supports'        => array( 'title', 'custom-fields' ),
		'capability_type' => 'post',
		'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap'    => true,
	) );
} );
add_filter( 'manage_mxr_lead_posts_columns', function ( $c ) {
	return array( 'cb' => $c['cb'], 'title' => 'Jméno', 'mxr_tel' => 'Telefon', 'mxr_mail' => 'E-mail', 'mxr_uver' => 'Úvěr', 'mxr_mesto' => 'Město', 'date' => 'Přijato' );
} );
add_action( 'manage_mxr_lead_posts_custom_column', function ( $col, $id ) {
	$map = array( 'mxr_tel' => 'telefon', 'mxr_mail' => 'email', 'mxr_uver' => 'uver', 'mxr_mesto' => 'mesto' );
	if ( isset( $map[ $col ] ) ) {
		$v = get_post_meta( $id, $map[ $col ], true );
		echo esc_html( 'mxr_uver' === $col && $v ? number_format( (float) $v, 0, ',', ' ' ) . ' Kč' : $v );
	}
}, 10, 2 );

function mxr_lead_config() {
	return wp_parse_args( get_option( 'mxr_lead_config', array() ), array( 'on' => false, 'provozovatel' => '', 'partner' => '' ) );
}

function mxr_lead_enabled() {
	$c = mxr_lead_config();
	if ( is_singular() && 90620 === (int) get_queried_object_id() ) {
		return true;   // testovací stránka
	}
	return $c['on'] && $c['provozovatel'] && $c['partner'];
}

// ── Leady: příjem formuláře ──────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	register_rest_route( 'mxr/v1', '/lead', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $r ) {
			$p = $r->get_json_params();
			if ( ! empty( $p['web'] ) ) {                       // past na roboty, lidé pole nevidí
				return array( 'ok' => true );
			}
			if ( empty( $p['t'] ) || time() - (int) $p['t'] / 1000 < 4 ) {
				return new WP_Error( 'rychle', 'Formulář byl odeslán příliš rychle.', array( 'status' => 400 ) );
			}
			$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$key = 'mxr_lead_' . md5( $ip );
			$cnt = (int) get_transient( $key );
			if ( $cnt >= 5 ) {
				return new WP_Error( 'limit', 'Z vaší adresy už přišlo několik žádostí, zkuste to prosím později.', array( 'status' => 429 ) );
			}
			$jmeno   = sanitize_text_field( isset( $p['jmeno'] ) ? $p['jmeno'] : '' );
			$telefon = preg_replace( '/[^0-9+ ]/', '', isset( $p['telefon'] ) ? $p['telefon'] : '' );
			$email   = sanitize_email( isset( $p['email'] ) ? $p['email'] : '' );
			if ( strlen( $jmeno ) < 2 || ( strlen( preg_replace( '/\D/', '', $telefon ) ) < 9 && ! is_email( $email ) ) ) {
				return new WP_Error( 'udaje', 'Vyplňte prosím jméno a telefon nebo e-mail.', array( 'status' => 400 ) );
			}
			if ( empty( $p['souhlas'] ) ) {
				return new WP_Error( 'souhlas', 'Bez souhlasu s předáním kontaktu vám specialista nemůže zavolat.', array( 'status' => 400 ) );
			}
			$c   = mxr_lead_config();
			$id  = wp_insert_post( array( 'post_type' => 'mxr_lead', 'post_status' => 'private', 'post_title' => $jmeno ) );
			$meta = array(
				'telefon' => $telefon, 'email' => $email,
				'poznamka' => sanitize_textarea_field( isset( $p['poznamka'] ) ? $p['poznamka'] : '' ),
				'cena' => (int) ( isset( $p['cena'] ) ? $p['cena'] : 0 ), 'uver' => (int) ( isset( $p['uver'] ) ? $p['uver'] : 0 ),
				'doba' => (int) ( isset( $p['doba'] ) ? $p['doba'] : 0 ), 'sazba' => (float) ( isset( $p['sazba'] ) ? $p['sazba'] : 0 ),
				'vek' => (int) ( isset( $p['vek'] ) ? $p['vek'] : 0 ), 'mesto' => sanitize_text_field( isset( $p['mesto'] ) ? $p['mesto'] : '' ),
				'stranka' => esc_url_raw( isset( $p['stranka'] ) ? $p['stranka'] : '' ),
				'souhlas_text' => sanitize_text_field( isset( $p['souhlas_text'] ) ? $p['souhlas_text'] : '' ),
				'souhlas_cas' => current_time( 'mysql' ), 'partner' => $c['partner'],
			);
			foreach ( $meta as $k => $v ) {
				update_post_meta( $id, $k, $v );
			}
			set_transient( $key, $cnt + 1, HOUR_IN_SECONDS );
			$body = "Nový kontakt z hypoteční kalkulačky\n\nJméno: $jmeno\nTelefon: $telefon\nE-mail: $email\n"
				. 'Cena nemovitosti: ' . number_format( $meta['cena'], 0, ',', ' ' ) . " Kč\nÚvěr: " . number_format( $meta['uver'], 0, ',', ' ' ) . " Kč\n"
				. "Doba: {$meta['doba']} let, sazba {$meta['sazba']} %, věk {$meta['vek']}, město {$meta['mesto']}\nPoznámka: {$meta['poznamka']}\n\n"
				. 'Souhlas: ' . $meta['souhlas_text'] . "\n\nVšechny kontakty: " . admin_url( 'edit.php?post_type=mxr_lead' );
			wp_mail( get_option( 'admin_email' ), 'Nový zájemce o hypotéku: ' . $jmeno, $body );
			return array( 'ok' => true );
		},
	) );
} );

// ── Kalkulačka ───────────────────────────────────────────────────────────────
add_shortcode( 'hypotecni_kalkulacka', function () {
	if ( ! function_exists( 'mxr_agr_css' ) ) {
		return '';
	}
	$pages   = mxr_agr_pages();
	$stranky = mxr_agr_json( 'stranky.json' );
	$top     = isset( $stranky['pages']['all'] ) ? $stranky['pages']['all'] : array();
	$init    = array_slice( array_values( array_filter( $top, function ( $l ) { return $l['p'] <= 5500000; } ) ), 0, 9 );
	$top10   = mxr_agr_top10();
	$cities  = array();
	foreach ( array_keys( mxr_agr_cities() ) as $c ) {
		if ( isset( $pages[ 'real/' . mxr_agr_slug( $c ) ] ) && ! in_array( $c, $top10, true ) ) { $cities[] = $c; }
	}
	usort( $cities, function ( $a, $b ) { return strcoll( remove_accents( $a ), remove_accents( $b ) ); } );
	$opt = '<option value="">Celá ČR</option>';
	foreach ( $top10 as $c ) { $opt .= '<option>' . esc_html( $c ) . '</option>'; }
	$opt .= '<option disabled>---</option>';
	foreach ( $cities as $c ) { $opt .= '<option>' . esc_html( $c ) . '</option>'; }
	$mesta = mxr_agr_json( 'mesta.json' );
	$kraje = array();
	foreach ( array_merge( $top10, $cities ) as $c ) {
		if ( isset( $mesta['mesta'][ $c ]['kraj_slug'] ) ) { $kraje[ $c ] = $mesta['mesta'][ $c ]['kraj_slug']; }
	}
	$city_urls = array();
	foreach ( array_merge( $top10, $cities ) as $c ) { $city_urls[ $c ] = $pages[ 'real/' . mxr_agr_slug( $c ) ]; }
	$lead  = mxr_lead_enabled();
	$cfg   = mxr_lead_config();
	$souhlas = 'Souhlasím, aby ' . ( $cfg['provozovatel'] ? $cfg['provozovatel'] : '[provozovatel webu]' ) . ' předal moje kontaktní údaje a zadání z kalkulačky hypotečnímu specialistovi '
		. ( $cfg['partner'] ? $cfg['partner'] : '[partner]' ) . ', který mě kvůli nezávazné konzultaci kontaktuje. Souhlas mohu kdykoli odvolat.';

	ob_start();
	echo mxr_agr_css();
	?>
<div class="rv2 hk" data-base="<?php echo esc_url( mxr_agr_base() ); ?>">
<style>
.hk { --ground:#f7f6f6; --soft:#fbeef0; }
.hk-lead { font-size:17px; line-height:1.65; color:#333; max-width:880px; margin:0 0 22px; }
.hk-calc { display:grid; grid-template-columns:minmax(0,5fr) minmax(0,7fr); gap:20px; background:var(--ground); padding:20px; }
.hk-in, .hk-out { background:#fff; padding:26px; }
.hk .lbl { display:flex; justify-content:space-between; align-items:baseline; gap:10px; font:600 12px Poppins,sans-serif; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin-bottom:6px; }
.hk .lbl output { font:700 16px Poppins,sans-serif; color:var(--ink); text-transform:none; letter-spacing:0; font-variant-numeric:tabular-nums; }
.hk-f { margin-bottom:22px; }
.hk input[type=range] { width:100%; accent-color:var(--red); }
.hk select, .hk input[type=text], .hk input[type=tel], .hk input[type=email], .hk textarea { width:100%; font:15px "Nunito Sans",sans-serif; padding:9px 10px; border:1px solid #d9d5d6; border-radius:0; background:#fff; color:var(--ink); }
.hk-hint { font-size:12.5px; color:var(--muted); margin-top:4px; }
.hk-pay { font:700 46px/1.05 Poppins,sans-serif; color:var(--ink); font-variant-numeric:tabular-nums; margin:4px 0 2px; }
.hk-sub { color:var(--muted); font-size:14px; }
.hk-kpi { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:18px; margin:24px 0 20px; }
.hk-kpi b { display:block; font:700 19px Poppins,sans-serif; color:var(--ink); font-variant-numeric:tabular-nums; }
.hk-kpi span { font-size:12.5px; color:var(--muted); }
.hk-facts { display:grid; gap:8px; font-size:14.5px; background:var(--ground); padding:14px 16px; }
.hk-good { color:var(--ok); font-weight:700; } .hk-bad { color:var(--red); font-weight:700; }
.hk-charts { display:grid; grid-template-columns:150px 1fr; gap:24px; margin-top:24px; align-items:start; }
.hk-leg { display:flex; gap:16px; flex-wrap:wrap; font-size:12.5px; color:var(--muted); margin-top:6px; }
.hk-leg i { display:inline-block; width:10px; height:10px; margin-right:5px; vertical-align:-1px; }
.hk table { border-collapse:collapse; width:100%; font-size:14px; }
.hk th, .hk td { text-align:left; padding:9px 10px; border-bottom:1px solid #ebe8e8; }
.hk th { font:600 12px Poppins,sans-serif; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
.hk td.r, .hk th.r { text-align:right; font-variant-numeric:tabular-nums; }
.hk tr.cur td { font-weight:700; color:var(--ink); background:var(--soft); }
.hk-leadbox { background:#fff; margin:20px 0 0; padding:24px 26px; display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.2fr); gap:24px; border:2px solid var(--ink); }
.hk-leadbox h2 { font:700 21px Poppins,sans-serif; color:var(--ink); margin:0 0 8px; }
.hk-leadbox p { font-size:14.5px; margin:0 0 8px; }
.hk-form { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.hk-form .full { grid-column:1/-1; }
.hk-form label { font:600 12px Poppins,sans-serif; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); display:block; margin-bottom:4px; }
.hk-form .chk { display:grid; grid-template-columns:auto 1fr; gap:10px; font:13px/1.5 "Nunito Sans",sans-serif; text-transform:none; letter-spacing:0; color:var(--ink-2); }
.hk-form .hp { position:absolute; left:-9999px; }
.hk-msg { font-size:14px; font-weight:700; }
.hk-homes-h { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:baseline; gap:8px; margin:40px 0 6px; }
.hk-homes-h h2 { font:700 22px Poppins,sans-serif; color:var(--ink); margin:0; }
.hk-text { max-width:880px; }
.hk-text h2 { font:700 22px Poppins,sans-serif; color:var(--ink); margin:40px 0 10px; }
.hk-text h3 { font:600 17px Poppins,sans-serif; color:var(--ink); margin:22px 0 6px; }
.hk-text p, .hk-text li { font-size:16px; line-height:1.7; color:#333; }
.hk-text a { color:var(--red); }
.hk-text .tbl { overflow-x:auto; margin:10px 0 14px; }
@media (max-width:860px) { .hk-calc, .hk-charts, .hk-leadbox { grid-template-columns:1fr; } .hk-calc { padding:0; background:none; gap:12px; } .hk-pay { font-size:38px; } }
@media (max-width:460px) { .hk-kpi, .hk-form { grid-template-columns:1fr; } }
</style>

<p class="hk-lead">Spočítejte si měsíční splátku hypotéky, celkovou cenu úvěru i to, jestli vám banka podle pravidel ČNB půjčí. Pod kalkulačkou rovnou uvidíte nejvýhodnější byty a domy na prodej ve vybraném městě, na které s hypotékou dosáhnete.</p>

<div class="hk-calc">
  <form class="hk-in" id="hk-form" onsubmit="return false">
    <div class="hk-f"><div class="lbl"><label for="hk-price">Cena nemovitosti</label><output id="hk-price-o"></output></div><input type="range" id="hk-price" min="1000000" max="20000000" step="100000" value="5500000"></div>
    <div class="hk-f"><div class="lbl"><label for="hk-own">Vlastní zdroje</label><output id="hk-own-o"></output></div><input type="range" id="hk-own" min="0" max="60" step="1" value="20"><div class="hk-hint" id="hk-own-h"></div></div>
    <div class="hk-f"><div class="lbl"><label for="hk-years">Doba splácení</label><output id="hk-years-o"></output></div><input type="range" id="hk-years" min="5" max="40" step="1" value="30"></div>
    <div class="hk-f"><div class="lbl"><label for="hk-rate">Úroková sazba</label><output id="hk-rate-o"></output></div><input type="range" id="hk-rate" min="2" max="8" step="0.05" value="4.59"><div class="hk-hint">Orientační sazba, upravte ji podle nabídky banky.</div></div>
    <div class="hk-f"><div class="lbl"><label for="hk-age">Věk nejstaršího žadatele</label><output id="hk-age-o"></output></div><input type="range" id="hk-age" min="18" max="65" step="1" value="32"><div class="hk-hint">Do 36 let povoluje ČNB financovat až 90 % ceny.</div></div>
    <div class="hk-f"><label class="lbl" for="hk-city">Město</label><select id="hk-city"><?php echo $opt; ?></select><div class="hk-hint">Podle města vybereme nemovitosti pod kalkulačkou.</div></div>
  </form>
  <div class="hk-out">
    <div class="lbl" style="margin:0">Měsíční splátka</div>
    <div class="hk-pay" id="hk-pay">22 530 Kč</div>
    <div class="hk-sub" id="hk-loan">úvěr 4 400 000 Kč na 30 let</div>
    <div class="hk-kpi">
      <div><b id="hk-total"></b><span>zaplatíte celkem</span></div>
      <div><b id="hk-int"></b><span>z toho úroky</span></div>
      <div><b id="hk-inc"></b><span>doporučený čistý příjem domácnosti</span></div>
    </div>
    <div class="hk-facts"><div id="hk-ltv"></div><div id="hk-ins"></div></div>
    <div class="hk-charts">
      <div><svg id="hk-donut" viewBox="0 0 120 120" width="150" height="150" role="img" aria-label="Poměr jistiny a úroků"></svg>
        <div class="hk-leg"><span><i style="background:#2d2d2d"></i>jistina</span><span><i style="background:#c8102e"></i>úroky</span></div></div>
      <div><div class="lbl">Zůstatek úvěru a zaplacené úroky po letech</div><svg id="hk-amort" viewBox="0 0 520 180" width="100%" role="img" aria-label="Splácení po letech"></svg>
        <div class="hk-leg"><span><i style="background:#2d2d2d"></i>zbývá doplatit</span><span><i style="background:#c8102e"></i>zaplacené úroky</span></div></div>
    </div>
    <h3 style="font:600 16px Poppins,sans-serif;color:var(--ink);margin:26px 0 6px">Co když se změní sazba</h3>
    <div style="overflow-x:auto"><table><thead><tr><th>Sazba</th><th class="r">Splátka</th><th class="r">Rozdíl měsíčně</th><th class="r">Úroky celkem</th></tr></thead><tbody id="hk-whatif"></tbody></table></div>
  </div>
</div>

<?php if ( $lead ) : ?>
<div class="hk-leadbox" id="hk-lead">
  <div>
    <h2>Chcete vědět, kolik vám banky reálně nabídnou?</h2>
    <p>Nechte nám kontakt a hypoteční specialista vám zdarma a nezávazně porovná nabídky bank pro vaši situaci. Zadání z kalkulačky mu pošleme, nemusíte nic vyplňovat znovu.</p>
    <p style="color:var(--muted);font-size:13px">Sami úvěry neposkytujeme ani nezprostředkováváme, kontakt předáme jen se souhlasem specialistovi s registrací u ČNB.</p>
  </div>
  <form class="hk-form" id="hk-lf" novalidate>
    <div><label for="hk-l-jmeno">Jméno</label><input type="text" id="hk-l-jmeno" autocomplete="name" required></div>
    <div><label for="hk-l-tel">Telefon</label><input type="tel" id="hk-l-tel" autocomplete="tel"></div>
    <div class="full"><label for="hk-l-mail">E-mail</label><input type="email" id="hk-l-mail" autocomplete="email"></div>
    <div class="full"><label for="hk-l-pozn">Poznámka, nepovinné</label><textarea id="hk-l-pozn" rows="2"></textarea></div>
    <input type="text" class="hp" id="hk-l-web" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label class="chk full"><input type="checkbox" id="hk-l-souhlas"><span id="hk-l-souhlas-t"><?php echo esc_html( $souhlas ); ?> Více v <a href="<?php echo esc_url( home_url( '/zasady-ochrany/' ) ); ?>">zásadách ochrany osobních údajů</a>.</span></label>
    <div class="full"><button class="rv2-btn red" type="submit" id="hk-l-btn">Chci nezávaznou konzultaci</button> <span class="hk-msg" id="hk-l-msg" role="status"></span></div>
  </form>
</div>
<?php endif; ?>

<div class="hk-homes-h"><h2 id="hk-homes-t">Nemovitosti, na které dosáhnete</h2><a id="hk-homes-a" class="rv2-btn line" href="<?php echo esc_url( $pages['real'] ); ?>">Všechny výhodné nabídky →</a></div>
<p class="rv2-stat" id="hk-homes-s">Nejvýhodnější nabídky do 5,5 mil. Kč podle skóre výhodnosti.</p>
<div class="rv2-grid" id="hk-homes"><?php foreach ( $init as $l ) { echo mxr_agr_card( $l ); } ?></div>

<div class="hk-text">
<h2>Jak používat hypoteční kalkulačku</h2>
<p>Zadejte cenu nemovitosti, kolik do koupě vložíte vlastních peněz, dobu splácení a úrokovou sazbu. Kalkulačka okamžitě spočítá měsíční splátku, celkovou částku, kterou bance zaplatíte, a kolik z ní tvoří úroky. Posuvníky můžete libovolně měnit a výsledek se přepočítá hned.</p>
<p>Podle věku kalkulačka hlídá limit ČNB pro výši úvěru proti ceně nemovitosti a ukáže, kolik vlastních peněz případně chybí. Spočítá také doporučený čistý příjem domácnosti, při kterém splátka nezatíží rozpočet víc, než banky obvykle připustí. Když vyberete město, pod kalkulačkou se ukážou nejvýhodnější byty a domy na prodej v ceně, kterou jste zadali.</p>

<h2>Jak se počítá splátka hypotéky</h2>
<p>Hypotéka se splácí anuitně, tedy stejnou měsíční částkou po celou dobu fixace. Mění se jen to, z čeho se splátka skládá. Na začátku jde většina peněz na úroky, protože dlužíte nejvíc, a postupně roste část, která snižuje jistinu. Graf splácení po letech v kalkulačce ukazuje, jak zůstatek úvěru klesá a jak přibývají zaplacené úroky.</p>
<p>Splátka se počítá podle vzorce splátka = úvěr × r ÷ (1 − (1 + r)<sup>−n</sup>), kde r je měsíční úroková sazba (roční sazba děleno 12) a n počet měsíčních splátek. Při úvěru 4 400 000 Kč na 30 let se sazbou 4,59 % vychází splátka 22 530 Kč. Za celou dobu zaplatíte 8 110 823 Kč, z toho 3 710 823 Kč na úrocích.</p>
<h3>Delší, nebo kratší doba splácení</h3>
<p>Kratší doba splácení zvýší měsíční splátku, ale výrazně sníží úroky. Stejný úvěr 4 400 000 Kč na 20 let místo 30 znamená splátku 28 051 Kč, tedy o 5 521 Kč víc měsíčně, úroky ale klesnou z 3,71 na 2,33 milionu. Delší splatnost dává rozpočtu víc prostoru a nadbytečné peníze můžete posílat jako mimořádné splátky.</p>

<h2>Kolik vlastních peněz potřebujete na hypotéku</h2>
<p>Česká národní banka doporučuje bankám půjčit na bydlení nejvýš 80 % hodnoty nemovitosti, žadatelům mladším 36 let až 90 %. Na byt za 5 500 000 Kč tedy potřebujete aspoň 1 100 000 Kč vlastních peněz, do 36 let 550 000 Kč. Hodnotu přitom určuje odhad banky, ne kupní cena, a odhad bývá někdy nižší.</p>
<p>K vlastním zdrojům si připočtěte i vedlejší náklady. Odhad nemovitosti stojí obvykle 3 000 až 6 000 Kč, vklad do katastru 2 000 Kč a u koupě přes realitní kancelář může přibýt provize, pokud ji neplatí prodávající. Rozumná je i rezerva na stěhování a vybavení. Daň z nabytí nemovitosti se od roku 2020 neplatí.</p>

<h2>Kolik vám banka půjčí</h2>
<p>Výši hypotéky omezuje vedle vlastních zdrojů hlavně příjem. Banky sledují, jaký podíl čistého příjmu domácnosti jdou na splátky všech úvěrů, a obvykle nepřipustí víc než 40 až 50 %. ČNB limity příjmu v roce 2023 zrušila, banky ale stejná pravidla dál používají ve vlastním hodnocení.</p>
<div class="tbl"><table><thead><tr><th>Čistý příjem domácnosti</th><th class="r">Splátka do 45 % příjmu</th><th class="r">Hypotéka na 30 let při 4,59 %</th></tr></thead>
<tbody><tr><td>40 000 Kč</td><td class="r">18 000 Kč</td><td class="r">3 515 303 Kč</td></tr><tr><td>60 000 Kč</td><td class="r">27 000 Kč</td><td class="r">5 272 954 Kč</td></tr><tr><td>80 000 Kč</td><td class="r">36 000 Kč</td><td class="r">7 030 606 Kč</td></tr></tbody></table></div>
<p>Od příjmu se odečítají splátky jiných úvěrů, leasingů a limity kreditních karet. Banky hodnotí i stabilitu příjmu, u podnikatelů daňové přiznání a u zaměstnanců pracovní smlouvu na dobu neurčitou. Splatnost hypotéky navíc obvykle končí nejpozději v 70 letech žadatele.</p>

<h2>Úroková sazba a délka fixace</h2>
<p>Úroková sazba je garantovaná po dobu fixace, nejčastěji na 3, 5 nebo 10 let. Kratší fixace bývá levnější, když se čeká pokles sazeb, delší chrání před jejich růstem a dává jistotu splátky na dlouho dopředu. Tabulka „Co když se změní sazba“ v kalkulačce ukazuje, jak citlivá je vaše splátka na změnu o půl nebo jeden procentní bod.</p>
<p>Sazbu ovlivňuje i výše úvěru proti hodnotě nemovitosti, příjem, sjednané pojištění a vedení účtu u banky. Při srovnání nabídek sledujte RPSN, roční procentní sazbu nákladů, která vedle úroku zahrnuje i poplatky a povinné pojištění.</p>

<h2>Co dělat, když končí fixace</h2>
<p>Před koncem fixace pošle banka novou nabídku sazby. To je nejlepší chvíle na refinancování, protože k výročí fixace můžete hypotéku převést k jiné bance nebo ji částečně splatit bez poplatku. Nabídky od jiných bank si zjistěte aspoň tři měsíce dopředu, nový úvěr potřebuje odhad a schválení.</p>

<h2>Předčasné a mimořádné splacení hypotéky</h2>
<p>Mimořádně splatit až 25 % úvěru ročně můžete k výročí smlouvy bez poplatku. Bez poplatku je i splacení k výročí fixace, při prodeji nemovitosti po dvou letech od podpisu smlouvy nebo při vážné životní události, jako je úmrtí, invalidita nebo dlouhodobá nemoc. Jinak smí banka účtovat jen skutečné náklady, nejvýš 1 % splácené částky.</p>

<h2>Pojištění k hypotéce</h2>
<p>Pojištění nemovitosti banka vyžaduje vždy a pojistná smlouva se vinkuluje v její prospěch. Pojištění schopnosti splácet je dobrovolné a stojí obvykle 5 až 12 % splátky. Chrání rodinu při nemoci, úrazu nebo ztrátě zaměstnání a některé banky za ně snižují sazbu.</p>

<h2>Americká hypotéka</h2>
<p>Americká hypotéka je úvěr zajištěný nemovitostí, na který nemusíte dokládat účel. Peníze můžete použít třeba na podnikání nebo splacení jiných dluhů. Sazby jsou vyšší a banky půjčují obvykle jen do 60 až 70 % hodnoty nemovitosti. Na koupi bydlení je výhodnější klasická hypotéka.</p>

<h2>Hypotéka a výhodné nemovitosti na prodej</h2>
<p>Kalkulačka je propojená s <a href="<?php echo esc_url( $pages['real'] ); ?>">agregátorem výhodných nabídek</a>. Každý den procházíme všechny byty a domy na prodej a každou nabídku srovnáváme s cenou podobných nemovitostí v okolí. Pod kalkulačkou proto vidíte nabídky, které jsou levnější než trh a vejdou se do vámi zadané ceny. Jak se ceny vyvíjejí ve velkých městech, ukazují stránky <a href="<?php echo esc_url( isset( $pages['real/praha/ceny'] ) ? $pages['real/praha/ceny'] : $pages['real'] ); ?>">ceny bytů v Praze</a>, <a href="<?php echo esc_url( isset( $pages['real/brno/ceny'] ) ? $pages['real/brno/ceny'] : $pages['real'] ); ?>">v Brně</a>, <a href="<?php echo esc_url( isset( $pages['real/ostrava/ceny'] ) ? $pages['real/ostrava/ceny'] : $pages['real'] ); ?>">v Ostravě</a> a <a href="<?php echo esc_url( isset( $pages['real/plzen/ceny'] ) ? $pages['real/plzen/ceny'] : $pages['real'] ); ?>">v Plzni</a>.</p>

<h2>Časté otázky</h2>
<?php
$faq = array(
	array( 'Jak vysokou hypotéku dostanu s příjmem 60 000 Kč?', 'Při splátce do 45 % čistého příjmu unesete splátku zhruba 27 000 Kč. Na 30 let se sazbou 4,59 % to odpovídá hypotéce kolem 5,27 milionu Kč. Musíte mít ale i vlastní zdroje, obvykle 20 % ceny nemovitosti, do 36 let 10 %.' ),
	array( 'Kolik je splátka hypotéky na 4 miliony?', 'Při sazbě 4,59 % je splátka úvěru 4 000 000 Kč na 30 let zhruba 20 480 Kč měsíčně, na 20 let asi 25 500 Kč. Přesnou částku pro své podmínky si spočítejte v kalkulačce.' ),
	array( 'Kolik procent musím mít na hypotéku?', 'Obvykle 20 % ceny nemovitosti, protože banky podle doporučení ČNB půjčí nejvýš 80 % hodnoty. Žadatelé do 36 let mohou dostat až 90 %, stačí jim tedy 10 % vlastních peněz.' ),
	array( 'Vyplatí se kratší, nebo delší fixace?', 'Záleží na tom, jak se vyvíjejí sazby a jak moc potřebujete jistotu. Delší fixace chrání před růstem sazeb, kratší umožní dřív využít jejich pokles a k výročí fixace můžete bez poplatku refinancovat nebo mimořádně splatit.' ),
	array( 'Můžu hypotéku splatit dřív?', 'Ano. Až 25 % úvěru ročně splatíte bez poplatku k výročí smlouvy a celou hypotéku bez poplatku k výročí fixace. Mimo tato data smí banka účtovat jen skutečné náklady, nejvýš 1 % splácené částky.' ),
	array( 'Je výsledek kalkulačky závazný?', 'Ne. Kalkulačka počítá s anuitní splátkou a zadanou sazbou. Skutečnou nabídku ovlivní odhad nemovitosti, váš příjem a pojištění. Konkrétní sazbu vám sdělí banka nebo hypoteční specialista.' ),
);
foreach ( $faq as $q ) { echo '<h3>' . esc_html( $q[0] ) . '</h3><p>' . esc_html( $q[1] ) . '</p>'; }
$ld = array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array() );
foreach ( $faq as $q ) { $ld['mainEntity'][] = array( '@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $q[1] ) ); }
echo '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'WebApplication', 'name' => 'Hypoteční kalkulačka', 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web', 'url' => home_url( '/hypotecni-kalkulacka/' ), 'offers' => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'CZK' ) ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
?>
</div>
<script type="application/json" id="hk-data"><?php echo wp_json_encode( array( 'kraje' => $kraje, 'urls' => $city_urls, 'real' => $pages['real'] ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?></script>
<script>
(function () {
  var root = document.querySelector(".hk"), BASE = root.dataset.base, D = JSON.parse(document.getElementById("hk-data").textContent);
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function num(n) { return Math.round(n).toLocaleString("cs-CZ"); }
  function kc(n) { return num(n) + " Kč"; }
  function mil(n) { return (n / 1e6).toLocaleString("cs-CZ", { maximumFractionDigits: 2 }) + " mil. Kč"; }
  function pay(loan, rate, years) { var r = rate / 100 / 12, n = years * 12; return r ? loan * r / (1 - Math.pow(1 + r, -n)) : loan / n; }
  function after(loan, rate, years, months) { var r = rate / 100 / 12, p = pay(loan, rate, years), b = loan, i = 0; for (var m = 0; m < months && b > 0; m++) { var x = b * r; i += x; b -= p - x; } return { balance: Math.max(0, b), interest: i }; }
  var S = {};

  function calc() {
    S = { price: +$("hk-price").value, own: +$("hk-own").value, years: +$("hk-years").value, rate: +$("hk-rate").value, age: +$("hk-age").value, city: $("hk-city").value };
    var own = S.price * S.own / 100, loan = S.price - own, p = pay(loan, S.rate, S.years), total = p * S.years * 12, interest = total - loan;
    S.loan = loan;
    $("hk-price-o").textContent = kc(S.price); $("hk-own-o").textContent = S.own + " %"; $("hk-own-h").textContent = kc(own) + " z vlastních peněz";
    $("hk-years-o").textContent = S.years + " let"; $("hk-rate-o").textContent = S.rate.toLocaleString("cs-CZ") + " %"; $("hk-age-o").textContent = S.age + " let";
    $("hk-pay").textContent = kc(p); $("hk-loan").textContent = "úvěr " + kc(loan) + " na " + S.years + " let";
    $("hk-total").textContent = kc(total); $("hk-int").textContent = kc(interest); $("hk-inc").textContent = kc(p / 0.45);
    var ltv = loan / S.price * 100, limit = S.age < 36 ? 90 : 80;
    $("hk-ltv").innerHTML = ltv <= limit ? "Úvěr pokrývá <b>" + Math.round(ltv) + ' % ceny</b>, <span class="hk-good">vejde se do limitu ČNB</span> ' + limit + " % pro váš věk."
      : "Úvěr pokrývá <b>" + Math.round(ltv) + ' % ceny</b>, <span class="hk-bad">to je nad limitem ČNB</span> ' + limit + " %. Chybí " + kc(loan - S.price * limit / 100) + " vlastních zdrojů.";
    $("hk-ins").innerHTML = "Pojištění schopnosti splácet vyjde zhruba na <b>" + kc(p * 0.08) + "</b> měsíčně, obvykle 5 až 12 % splátky.";
    var frac = loan / total, R = 48, C = 2 * Math.PI * R;
    $("hk-donut").innerHTML = '<circle cx="60" cy="60" r="' + R + '" fill="none" stroke="#c8102e" stroke-width="16"/><circle cx="60" cy="60" r="' + R + '" fill="none" stroke="#2d2d2d" stroke-width="16" stroke-dasharray="' + (C * frac) + " " + C + '" transform="rotate(-90 60 60)"/>' +
      '<text x="60" y="57" text-anchor="middle" font-family="Poppins,sans-serif" font-size="15" font-weight="700" fill="#222">+' + Math.round(interest / loan * 100) + ' %</text><text x="60" y="73" text-anchor="middle" font-size="9" fill="#6e6a6b">úroky z úvěru</text>';
    var W = 520, H = 180, L = 46, B = 22, T = 8, out = "", step = S.years > 20 ? 2 : 1, cols = Math.ceil(S.years / step), bw = (W - L - 8) / cols;
    [0, .5, 1].forEach(function (f) { var yy = H - B - (H - B - T) * f; out += '<line x1="' + L + '" x2="' + (W - 4) + '" y1="' + yy + '" y2="' + yy + '" stroke="#ebe8e8"/><text x="' + (L - 6) + '" y="' + (yy + 3) + '" text-anchor="end" font-size="10" fill="#6e6a6b">' + (loan * f / 1e6).toLocaleString("cs-CZ", { maximumFractionDigits: 1 }) + ' mil.</text>'; });
    for (var y = step, k = 0; y <= S.years; y += step, k++) {
      var st = after(loan, S.rate, S.years, y * 12), h1 = (H - B - T) * st.balance / loan, h2 = (H - B - T) * Math.min(st.interest, loan) / loan, x = L + k * bw;
      out += '<rect x="' + (x + 1) + '" y="' + (H - B - h1) + '" width="' + Math.max(1, bw / 2 - 2) + '" height="' + h1 + '" fill="#2d2d2d"/><rect x="' + (x + bw / 2) + '" y="' + (H - B - h2) + '" width="' + Math.max(1, bw / 2 - 2) + '" height="' + h2 + '" fill="#c8102e"/>';
      if (k % Math.ceil(cols / 6) === 0) out += '<text x="' + (x + bw / 2) + '" y="' + (H - 6) + '" text-anchor="middle" font-size="10" fill="#6e6a6b">' + y + '. rok</text>';
    }
    $("hk-amort").innerHTML = out;
    $("hk-whatif").innerHTML = [-1, -0.5, 0, 0.5, 1].map(function (d) {
      var rr = Math.max(0.5, S.rate + d), pp = pay(loan, rr, S.years);
      return "<tr" + (d === 0 ? ' class="cur"' : "") + "><td>" + rr.toLocaleString("cs-CZ", { maximumFractionDigits: 2 }) + " %" + (d === 0 ? ", vaše sazba" : "") + '</td><td class="r">' + kc(pp) + '</td><td class="r">' + (d === 0 ? "" : (pp > p ? "+" : "") + kc(pp - p)) + '</td><td class="r">' + kc(pp * S.years * 12 - loan) + "</td></tr>";
    }).join("");
    homesSoon();
  }

  // ── Nemovitosti z feedu, stejné karty jako v kategoriích ──
  function img(u) { return u + "?fl=res,800,600,3|shr,,20|webp,60"; }
  function below(l) { return l.benchmark ? Math.round((1 - l.price_per_m2 / l.benchmark.ppm2) * 100) : 0; }
  function rating(s) { return s >= 80 ? "Výborná nabídka" : s >= 60 ? "Velmi dobrá" : s >= 40 ? "Dobrá" : "Běžná cena"; }
  function short(t) { return t.replace(/^Prodej /, "").replace(/^bytu/, "Byt").replace(/^rodinného domu/, "Rodinný dům").replace(/^chaty/, "Chata").replace(/^chalupy/, "Chalupa").replace(/^vily/, "Vila").replace(/^vícegeneračního domu/, "Vícegenerační dům").replace(/^zemědělské usedlosti/, "Zemědělská usedlost").replace(/^památky/, "Památka"); }
  function tags(l) { var t = []; if (l.price_drop) t.push(["Zlevněno " + Math.round(l.price_drop.pct) + " %", "ink"]); if ((l.signals || []).some(function (s) { return s.code === "nove"; })) t.push(["Nové", "white"]); if (l.seller === "soukromy") t.push(["Od majitele", "white"]); return t.slice(0, 3).map(function (x) { return '<span class="rv2-tag ' + x[1] + '">' + x[0] + "</span>"; }).join(""); }
  function chips(l) { var out = [], SH = { prohlidka: "Video / 3D", znovu: "Znovu vložený", povoluje: "Prodávající zlevňuje" }; (l.signals || []).forEach(function (s) { if (["povoluje", "dlouho", "znovu", "prohlidka", "vicekrat"].indexOf(s.code) >= 0) out.push('<span class="rv2-chip sig">' + esc(SH[s.code] || s.label) + "</span>"); }); if (l.ownership) out.push('<span class="rv2-chip">' + esc(l.ownership) + "</span>"); (l.extras || []).forEach(function (e) { out.push('<span class="rv2-chip">' + esc(e) + "</span>"); }); return out.slice(0, 4).join(""); }
  function card(l) {
    var d = below(l), pos = Math.max(3, Math.min(97, 50 - d)), on = Math.round(l.deal_score / 10), segs = "";
    for (var k = 0; k < 10; k++) segs += '<i class="' + (k < on ? "on" : "") + '"></i>';
    var ph = l.images && l.images.length ? '<a class="rv2-photo" href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow" style="background-image:url(\'' + esc(img(l.images[0])) + '\')"><span class="rv2-count">' + (l.image_count || l.images.length) + ((l.image_count || 0) >= 6 ? "+" : "") + " fotek</span>" : '<a class="rv2-photo" href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow">';
    var mp = pay(l.price * (1 - S.own / 100), S.rate, S.years);
    return '<article class="rv2-card">' + ph + '<span class="rv2-tags">' + tags(l) + '</span><span class="rv2-over"><span class="p">' + kc(l.price) + '</span><br><span class="s">' + num(l.price_per_m2) + " Kč/m² · " + num(l.area) + " m²</span></span></a>" +
      '<div class="rv2-body"><h3 class="rv2-title" title="' + esc(short(l.title)) + '">' + esc(short(l.title)) + '</h3><div class="rv2-loc">' + esc(l.locality) + "</div>" +
      '<div class="rv2-score"><div class="n">' + l.deal_score + '<small>/100</small></div><div class="r"><b>' + rating(l.deal_score) + '</b><div class="rv2-segs">' + segs + "</div></div></div>" +
      '<div class="rv2-mkt"><div class="t">Splátka zhruba <strong>' + kc(mp) + "</strong> měsíčně" + (d > 0 ? ", o " + d + " % levnější než okolí" : "") + "</div>" +
      '<div class="rv2-track"><span class="mid"></span><span class="mk" style="left:' + pos + '%"></span></div><div class="l"><span>levnější</span><span>trh ' + num(l.benchmark.ppm2) + " Kč/m²</span><span>dražší</span></div></div>" +
      '<div class="rv2-chips">' + chips(l) + '</div></div><div class="rv2-foot"><a class="rv2-btn red" href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow">Prohlédnout inzerát →</a></div></article>';
  }
  var cache = {}, timer = null;
  function getJSON(path) { if (!cache[path]) cache[path] = fetch(BASE + path + "?t=" + Math.floor(Date.now() / 6e5)).then(function (r) { if (!r.ok) throw r.status; return r.json(); }); return cache[path]; }
  function homesSoon() { clearTimeout(timer); timer = setTimeout(homes, 250); }
  function homes() {
    var city = S.city, path = city && D.kraje[city] ? "feed-kraje/" + D.kraje[city] + ".json" : "feed.json", max = S.price;
    $("hk-homes-t").textContent = "Nemovitosti, na které dosáhnete" + (city ? ", " + city : "");
    $("hk-homes-a").href = city && D.urls[city] ? D.urls[city] : D.real;
    $("hk-homes-a").textContent = city ? "Všechny nabídky " + city + " →" : "Všechny výhodné nabídky →";
    getJSON(path).then(function (d) {
      var list = (d.listings || []).filter(function (l) { return l.benchmark && l.price <= max && (!city || l.city === city); });
      list.sort(function (a, b) { return b.deal_score - a.deal_score; });
      var nine = list.slice(0, 9);
      $("hk-homes-s").textContent = nine.length ? "Nejvýhodnější nabídky do " + mil(max) + (city ? " v lokalitě " + city : " v celé ČR") + " podle skóre výhodnosti. U každé je orientační splátka s vašimi podmínkami."
        : "Do " + mil(max) + " teď v této lokalitě nemáme výhodnou nabídku. Zkuste vyšší cenu nebo celou ČR.";
      $("hk-homes").innerHTML = nine.map(card).join("");
    }).catch(function () {});
  }
  ["hk-price", "hk-own", "hk-years", "hk-rate", "hk-age", "hk-city"].forEach(function (id) { $(id).addEventListener("input", calc); });

  // ── Formulář ──
  var lf = $("hk-lf"), opened = Date.now();
  if (lf) lf.addEventListener("submit", function (e) {
    e.preventDefault();
    var msg = $("hk-l-msg"), btn = $("hk-l-btn");
    if (!$("hk-l-souhlas").checked) { msg.textContent = "Zaškrtněte prosím souhlas s předáním kontaktu."; return; }
    btn.disabled = true; msg.textContent = "Odesílám…";
    fetch("/wp-json/mxr/v1/lead", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({
      jmeno: $("hk-l-jmeno").value, telefon: $("hk-l-tel").value, email: $("hk-l-mail").value, poznamka: $("hk-l-pozn").value,
      web: $("hk-l-web").value, souhlas: true, souhlas_text: $("hk-l-souhlas-t").textContent, t: opened,
      cena: S.price, uver: S.loan, doba: S.years, sazba: S.rate, vek: S.age, mesto: S.city, stranka: location.href
    }) }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); }).then(function (x) {
      if (x.ok) { lf.innerHTML = '<p class="hk-msg full">Děkujeme, kontakt jsme předali. Specialista se vám ozve obvykle do jednoho pracovního dne.</p>'; }
      else { msg.textContent = (x.j && x.j.message) || "Odeslání se nepovedlo, zkuste to prosím znovu."; btn.disabled = false; }
    }).catch(function () { msg.textContent = "Odeslání se nepovedlo, zkontrolujte připojení a zkuste to znovu."; btn.disabled = false; });
  });
  calc();
})();
</script>
</div>
	<?php
	return ob_get_clean();
} );
