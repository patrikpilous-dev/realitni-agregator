<?php
/**
 * Shortcode [nemovitosti_real_v2] — agregátor verze 2 (karty varianta C, statistika trhu, graf cen).
 * Code Snippets na max-reality.cz (ID 15), rozsah global. Data: JSONy z repa realitni-agregator (scraper verze 2).
 *
 * Atributy jako u starého [nemovitosti_real]: city, type (byt|dům), disp, extra, own.
 *   [nemovitosti_real_v2]                        celý trh, feed.json (nejvýhodnějších 2 000 v ČR)
 *   [nemovitosti_real_v2 city="Brno"]            feed kraje města (feed-kraje/<kraj>.json), filtr na město
 *   [nemovitosti_real_v2 type="byt" city="Brno"] totéž jen byty
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

add_shortcode( 'nemovitosti_real_v2', function ( $atts ) {
	$atts = shortcode_atts( array(
		'base'  => 'https://raw.githubusercontent.com/patrikpilous-dev/realitni-agregator/refs/heads/main/',
		'city'  => '',
		'type'  => '',
		'disp'  => '',
		'extra' => '',
		'own'   => '',
	), $atts );
	$seg = '';
	if ( in_array( $atts['type'], array( 'byt', 'byty' ), true ) ) { $seg = 'byt'; }
	if ( in_array( $atts['type'], array( 'dům', 'dum', 'domy' ), true ) ) { $seg = 'dum'; }

	ob_start();
	?>
<div class="rv2" data-base="<?php echo esc_url( $atts['base'] ); ?>" data-city="<?php echo esc_attr( $atts['city'] ); ?>"
  data-seg="<?php echo esc_attr( $seg ); ?>" data-disp="<?php echo esc_attr( $atts['disp'] ); ?>"
  data-extra="<?php echo esc_attr( $atts['extra'] ); ?>" data-own="<?php echo esc_attr( $atts['own'] ); ?>">
<style>
.rv2 { --red:#c8102e; --red-dark:#9e0c24; --ink:#222; --ink-2:#2d2d2d; --muted:#6b6b6b; --line:#e0e0e0; --ok:#1d7a3a;
  font-family:"Nunito Sans",sans-serif; color:var(--ink-2); }
.rv2 * { box-sizing:border-box; }
.rv2-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin:0 0 18px; }
.rv2-bar label { display:flex; flex-direction:column; gap:4px; font:600 11px Poppins,sans-serif; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
.rv2-bar select, .rv2-bar input { font:14px "Nunito Sans",sans-serif; padding:8px 10px; border:1px solid #cfcfcf; border-radius:0; background:#fff; color:var(--ink); min-width:150px; height:40px; }
.rv2-stat { font-size:14px; color:var(--muted); margin:0 0 16px; }
.rv2-stat b { color:var(--ink); }
.rv2-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:24px; align-items:start; }
.rv2-card { background:#fff; border:1px solid var(--line); display:flex; flex-direction:column; transition:box-shadow .2s, transform .2s, border-color .2s; }
.rv2-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.09); transform:translateY(-2px); border-color:#d2d2d2; }
.rv2-photo { overflow:hidden; }
.rv2-photo { position:relative; aspect-ratio:3/2; background:#e9e9e9 center/cover no-repeat; display:block; }
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
.rv2-title { font:600 15px/1.35 Poppins,sans-serif; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
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
.rv2-intro { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); border:1px solid var(--line); background:#fff; margin:0 0 22px; }
.rv2-intro div { padding:14px 18px; border-right:1px solid var(--line); }
.rv2-intro div:last-child { border-right:0; }
.rv2-intro b { display:block; font:700 21px/1.2 Poppins,sans-serif; color:var(--ink); }
.rv2-intro span { font-size:12.5px; color:var(--muted); }
.rv2-chart { margin:44px 0 0; }
.rv2-chart h2 { font:700 22px Poppins,sans-serif; color:var(--ink); margin:0 0 6px; }
.rv2-chart p { color:var(--muted); font-size:14px; margin:0 0 14px; }
.rv2-svg { background:#fff; border:1px solid var(--line); padding:14px 10px 6px; }
.rv2-svg svg { display:block; width:100%; height:auto; }
.rv2-leg { display:flex; gap:18px; font-size:13px; color:var(--ink-2); margin:10px 4px 4px; }
.rv2-leg i { display:inline-block; width:14px; height:3px; vertical-align:middle; margin-right:6px; }
.rv2-empty { padding:40px; text-align:center; color:var(--muted); border:1px dashed var(--line); }
@media (max-width:600px) { .rv2-bar label, .rv2-bar select, .rv2-bar input { width:100%; } }
</style>

<div class="rv2-intro" hidden></div>
<div class="rv2-bar">
  <label>Typ<select data-f="seg"><option value="">Vše</option><option value="byt">Byty</option><option value="dum">Domy</option><option value="rekreace">Chaty a chalupy</option></select></label>
  <label>Kraj<select data-f="region"><option value="">Celá ČR</option></select></label>
  <label>Město nebo část<input data-f="q" type="search" placeholder="např. Praha 5, Brno"></label>
  <label>Max. cena<select data-f="max"><option value="">Bez omezení</option><option>2000000</option><option>3000000</option><option>5000000</option><option>8000000</option><option>12000000</option></select></label>
  <label>Řadit<select data-f="sort"><option value="score">Nejvýhodnější</option><option value="new">Nejnovější</option><option value="drop">Největší zlevnění</option><option value="price">Nejlevnější</option></select></label>
</div>
<p class="rv2-stat"></p>
<div class="rv2-grid"></div>
<button class="rv2-btn line rv2-load" type="button" hidden>Načíst další</button>
<section class="rv2-chart" hidden><h2></h2><p></p><div class="rv2-svg"></div></section>

<script>
(function () {
  var root = (document.currentScript && document.currentScript.closest(".rv2")) || document.querySelector(".rv2");
  var BASE = root.getAttribute("data-base");
  var FIX = { city: root.dataset.city || "", seg: root.dataset.seg || "", disp: root.dataset.disp || "", extra: root.dataset.extra || "", own: root.dataset.own || "" };
  var intro = root.querySelector(".rv2-intro"), chart = root.querySelector(".rv2-chart");
  var PAGE = 24, shown = PAGE, all = [], list = [];
  var grid = root.querySelector(".rv2-grid"), stat = root.querySelector(".rv2-stat"), more = root.querySelector(".rv2-load");
  var f = {}; root.querySelectorAll("[data-f]").forEach(function (el) { f[el.getAttribute("data-f")] = el; });
  f.max.querySelectorAll("option").forEach(function (o) { if (o.value) o.textContent = "do " + (o.value / 1e6) + " mil. Kč"; });
  if (FIX.seg) { f.seg.value = FIX.seg; f.seg.closest("label").hidden = true; }
  if (FIX.city) { f.region.closest("label").hidden = true; f.q.placeholder = "část města nebo ulice"; f.q.closest("label").firstChild.textContent = "Část města"; }
  function fixedOk(l) {
    return (!FIX.city || l.city === FIX.city) && (!FIX.disp || l.disposition === FIX.disp) &&
      (!FIX.extra || (l.extras || []).indexOf(FIX.extra) >= 0) && (!FIX.own || l.ownership === FIX.own);
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
    var out = [];
    var SHORT = { prohlidka: "Video / 3D", znovu: "Znovu vložený", povoluje: "Prodávající zlevňuje" };
    (l.signals || []).forEach(function (s) { if (s.code === "povoluje" || s.code === "dlouho" || s.code === "znovu" || s.code === "prohlidka" || s.code === "vicekrat") out.push('<span class="rv2-chip sig">' + esc(SHORT[s.code] || s.label) + "</span>"); });
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
      '<div class="rv2-body"><div class="rv2-title" title="' + esc(short(l)) + '">' + esc(short(l)) + '</div><div class="rv2-loc">' + esc(l.locality) + "</div>" +
      '<div class="rv2-score"><div class="n">' + l.deal_score + '<small>/100</small></div><div class="r"><b>' + rating(l.deal_score) + '</b><div class="rv2-segs">' + segs + '</div><button class="rv2-why" type="button" data-why>Proč je výhodný?</button></div></div>' +
      '<div class="rv2-mkt"><div class="t">' + (d > 0 ? "O <strong>" + d + " % levnější</strong> než srovnatelné nabídky" : "Cena odpovídá srovnatelným nabídkám") + "</div>" +
      '<div class="rv2-track"><span class="mid"></span><span class="mk" style="left:' + pos + '%"></span></div>' +
      '<div class="l"><span>levnější</span><span>trh ' + num(l.benchmark.ppm2) + " Kč/m²</span><span>dražší</span></div></div>" +
      '<div class="rv2-chips">' + chips(l) + "</div></div>" +
      '<div class="rv2-foot"><a class="rv2-btn red" href="' + esc(l.url) + '" target="_blank" rel="noopener nofollow">Prohlédnout inzerát →</a></div>' +
      '<div class="rv2-more">' + why(l) + "</div></article>";
  }

  function apply() {
    var seg = f.seg.value, reg = f.region.value, q = f.q.value.trim().toLowerCase(), max = +f.max.value || 0, sort = f.sort.value;
    list = all.filter(function (l) {
      return fixedOk(l) && (!seg || l.segment === seg) && (!reg || l.region === reg) && (!max || l.price <= max) &&
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
    stat.innerHTML = "Zobrazeno <b>" + num(list.length) + "</b> nabídek, z toho <b>" + num(good) + "</b> se skóre 60 a víc.";
    grid.innerHTML = list.length ? list.slice(0, shown).map(card).join("") : '<div class="rv2-empty">Žádná nabídka neodpovídá filtru.</div>';
    more.hidden = shown >= list.length;
  }
  grid.addEventListener("click", function (e) {
    var b = e.target.closest("[data-why]"); if (!b) return;
    var c = b.closest(".rv2-card"); c.classList.toggle("open");
    b.textContent = c.classList.contains("open") ? "Skrýt zdůvodnění" : "Proč je výhodný?";
  });
  more.addEventListener("click", function () { shown += PAGE; render(); });
  Object.keys(f).forEach(function (k) { f[k].addEventListener(k === "q" ? "input" : "change", apply); });

  // ── Statistika a graf ──────────────────────────────────────────────────────
  function introHtml(m, n60) {
    var cells = [];
    if (m) {
      cells.push([num(m.aktivnich), "nabídek k prodeji, " + esc(FIX.city)]);
      if (m.byt && FIX.seg !== "dum") cells.push([kc(m.byt) + "/m²", "medián ceny bytů"]);
      if (m.dum && FIX.seg !== "byt") cells.push([kc(m.dum) + "/m²", "medián ceny domů"]);
    }
    cells.push([num(n60), "nabídek se skóre 60 a víc"]);
    return cells.map(function (c) { return "<div><b>" + c[0] + "</b><span>" + c[1] + "</span></div>"; }).join("");
  }
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
      '<div class="rv2-leg">' + lines.map(function (ln) { return '<span><i style="background:' + ln.color + '"></i>' + ln.name + "</span>"; }).join("") + "</div>";
  }
  function renderChart(series) {
    var segs = FIX.seg ? [FIX.seg] : ["byt", "dum"], NAMES = { byt: "byty", dum: "domy" }, COLORS = { byt: "#c8102e", dum: "#222" };
    var lines = segs.map(function (sg) {
      var key;
      if (FIX.city) key = "mesto|" + FIX.city + "|" + sg + "|";
      else if (FIX.disp && sg === "byt" && /^[0-9]/.test(FIX.disp)) key = "cr|CZ|byt|" + FIX.disp.charAt(0);
      else key = "cr|CZ|" + sg + "|";
      return { name: NAMES[sg], color: COLORS[sg], data: series[key] || [] };
    }).filter(function (ln) { return ln.data.length; });
    if (!lines.length) return;
    var where = FIX.city ? FIX.city : "celá ČR";
    chart.querySelector("h2").textContent = "Vývoj cen " + (FIX.seg === "byt" ? "bytů" : FIX.seg === "dum" ? "domů" : "nemovitostí") + (FIX.city ? ", " + FIX.city : " v ČR");
    var last = lines.map(function (ln) { var d = ln.data[ln.data.length - 1]; return ln.name + " " + kc(d[1]) + "/m² (z " + num(d[2]) + " nabídek)"; }).join(", ");
    chart.querySelector("p").textContent = "Medián nabídkové ceny za m², " + where + ". Aktuálně " + last + ". " +
      (lines[0].data.length < 14 ? "Data z celého trhu sbíráme od 4. 10. 2026, graf se plní každý den." : "Počítáno denně z celé nabídky Sreality.");
    chart.querySelector(".rv2-svg").innerHTML = svgChart(lines);
    chart.hidden = false;
  }
  function getJSON(path) { return fetch(BASE + path, { cache: "no-cache" }).then(function (r) { if (!r.ok) throw r.status; return r.json(); }); }

  stat.textContent = "Načítám nabídky…";
  var cityInfo = null;
  var source = FIX.city
    ? getJSON("mesta.json").then(function (m) {
        cityInfo = (m.mesta || {})[FIX.city] || null;
        return getJSON(cityInfo ? "feed-kraje/" + cityInfo.kraj_slug + ".json" : "feed.json");
      })
    : getJSON("feed.json");
  source.then(function (d) {
    all = (d.listings || []).filter(function (l) { return l.benchmark && l.deal_score != null; });
    // Tip dne: nejlepší tři nabídky přidané za poslední 3 dny
    var cut = new Date(Date.now() - 3 * 864e5).toISOString();
    all.filter(function (l) { return fixedOk(l) && (l.first_seen || "") >= cut; }).sort(function (a, b) { return b.deal_score - a.deal_score; })
      .slice(0, 3).forEach(function (l) { l._tip = true; });
    var regs = {}; all.forEach(function (l) { if (l.region) regs[l.region] = 1; });
    Object.keys(regs).sort(function (a, b) { return a.localeCompare(b, "cs"); }).forEach(function (r) {
      var o = document.createElement("option"); o.value = r; o.textContent = r; f.region.appendChild(o);
    });
    apply();
    var n60 = cityInfo ? cityInfo.vyhodnych : all.filter(function (l) { return fixedOk(l) && l.deal_score >= 60; }).length;
    intro.innerHTML = introHtml(cityInfo, n60);
    intro.hidden = false;
  }).catch(function () { stat.textContent = "Nabídky se nepodařilo načíst."; });
  getJSON("market_stats.json").then(function (m) { renderChart(m.series || {}); }).catch(function () {});
})();
</script>
</div>
	<?php
	return ob_get_clean();
} );
