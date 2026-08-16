"""
Sreality.cz scraper — Agregátor realitních nabídek
Stahuje inzeráty ze Sreality, počítá score výhodnosti, ukládá feed.json + archived.json

Zdroj dat: Sreality běží na Next.js, data jsou v __NEXT_DATA__ / _next/data.
Původní API /api/cs/v2/estates bylo vypnuto 25. 5. 2026 (vrací 404).
"""

import json
import re
import sys
import time
import statistics
import unicodedata
from datetime import datetime, timezone
from collections import defaultdict
import urllib.request

# ── Konfigurace ────────────────────────────────────────────────────────────────

SITE_BASE    = "https://www.sreality.cz"
OUTPUT_FILE  = "feed.json"
ARCHIVE_FILE = "archived.json"
HISTORY_FILE = "price_history.json"   # denní mediány cen/m² per město

# Kolik stránek stáhnout na kategorii (22 inzerátů/stránka)
MAX_PAGES = 12

# Minimální plocha v m²
MIN_AREA = 15

# Pod tímto počtem stažených inzerátů považujeme běh za selhaný a nic nezapíšeme.
# Chrání feed před hromadnou archivací při výpadku zdroje.
MIN_SCRAPED_OK = 100

# Kolik detailních stránek stáhnout za jeden běh (kvůli extras/vlastnictví).
# Inzeráty, na které se nedostane, se doplní v dalších bězích.
MAX_DETAILS   = 250
DETAIL_SLEEP  = 0.3

# Pozor: hlavičku "Accept" s text/html NEPOSÍLAT. Sreality na ni reagují
# přesměrováním na login.seznam.cz/autologin, což skončí jako smyčka 301/302.
HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/122.0.0.0 Safari/537.36"
    ),
    "Accept-Language": "cs,en;q=0.8",
}

# Kategorie: (category_main_cb, category_type_cb, cesta v /hledani/, popis)
CATEGORIES = [
    (1, 1, "prodej/byty", "byty-prodej"),
    (2, 1, "prodej/domy", "domy-prodej"),
]

# ── URL mappingy (pro správnou sestavu odkazu na Sreality) ─────────────────────
# Pozor: Sreality doredirectují špatný slug lokality i subkategorie,
# ale ŠPATNÝ hlavní typ (byt/dum) vrací 404 — ten musí sedět.

TYPE_URL = {1: "prodej", 2: "pronajem", 3: "drazby"}
MAIN_URL = {1: "byt", 2: "dum", 3: "pozemek", 4: "komercni", 5: "ostatni"}

# Slug subkategorie se odvozuje z jejího názvu (viz sub_slug).
# Číselné kódy se na Sreality přečíslovaly, hardcodovaná mapa proto zastarává.
# Ověřeno na všech 17 subkategoriích, které se aktuálně ve výpisech vyskytují.
SUB_SLUG_OVERRIDES: dict[str, str] = {}

# Města, kde je smysluplnější grupovat podle městské části než podle města
BIG_CITIES = {"Praha", "Brno", "Ostrava", "Plzeň"}

# ── HTTP ───────────────────────────────────────────────────────────────────────

def http_get(url: str, timeout: int = 20) -> str | None:
    req = urllib.request.Request(url, headers=HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.read().decode("utf-8", errors="replace")
    except Exception as e:
        print(f"  [CHYBA] {e}  ({url[:110]})")
        return None


NEXT_DATA_RE = re.compile(
    r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>', re.S
)


def parse_next_data(html: str) -> dict | None:
    match = NEXT_DATA_RE.search(html)
    if not match:
        return None
    try:
        return json.loads(match.group(1))
    except json.JSONDecodeError:
        return None


def get_build_id() -> str | None:
    """Načte buildId Next.js aplikace — mění se s každým deployem Sreality."""
    html = http_get(f"{SITE_BASE}/hledani/prodej/byty")
    if not html:
        return None
    data = parse_next_data(html)
    if not data:
        return None
    build_id = data.get("buildId")
    if build_id:
        print(f"Sreality buildId: {build_id}")
    return build_id


def extract_search_results(next_data: dict) -> tuple[list[dict], int]:
    """Vytáhne inzeráty z react-query cache (klíč 'estatesSearch')."""
    props = next_data.get("props", {}).get("pageProps") or next_data.get("pageProps", {})
    queries = props.get("dehydratedState", {}).get("queries", [])
    for query in queries:
        key = query.get("queryKey") or []
        if key and key[0] == "estatesSearch":
            data = query.get("state", {}).get("data") or {}
            results = data.get("results") or []
            total = (data.get("pagination") or {}).get("total") or 0
            return results, total
    return [], 0


def fetch_search_page(path: str, page: int, build_id: str | None) -> tuple[list[dict], int]:
    """
    Stáhne jednu stránku výpisu. Primárně přes _next/data (4× menší přenos),
    při selhání fallback na HTML + __NEXT_DATA__.
    """
    if build_id:
        url = f"{SITE_BASE}/_next/data/{build_id}/cs/hledani/{path}.json?strana={page}"
        body = http_get(url)
        if body:
            try:
                return extract_search_results(json.loads(body))
            except json.JSONDecodeError:
                pass

    url = f"{SITE_BASE}/hledani/{path}?strana={page}"
    html = http_get(url)
    if not html:
        return [], 0
    data = parse_next_data(html)
    if not data:
        print(f"  [CHYBA] __NEXT_DATA__ nenalezen ({url})")
        return [], 0
    return extract_search_results(data)


# ── Parsování ──────────────────────────────────────────────────────────────────

def parse_area(name: str) -> float | None:
    match = re.search(r"(\d+(?:[.,]\d+)?)\s*m[²2]", name, re.IGNORECASE)
    if match:
        return float(match.group(1).replace(",", "."))
    return None


def parse_disposition(name: str) -> str:
    match = re.search(r"(\d+\+(?:kk|\d+))", name, re.IGNORECASE)
    if match:
        return match.group(1).lower()
    return "ostatní"


def parse_disposition_group(name: str) -> str:
    """Vrátí skupinu dispozice podle prvního čísla (2+kk i 2+1 → '2')."""
    match = re.search(r"(\d+)\+", name, re.IGNORECASE)
    if match:
        return match.group(1)
    return "ostatní"


def big_city_head(loc: dict) -> str:
    """
    Pro Prahu/Brno/... vrátí městskou část ("Praha 4", "Brno-město").
    U inzerátů bez přesné adresy je ale v 'district' název kraje
    ("Hlavní město Praha") — tam se vracíme k názvu města.
    """
    city     = (loc.get("city") or "").strip()
    district = (loc.get("district") or "").strip()
    region   = (loc.get("region") or "").strip()
    if district and district != region:
        return district
    return city


def build_locality_text(loc: dict) -> str:
    """
    Složí čitelný popis lokality ve stylu Sreality:
      "Rovnoběžná, Praha 4 - Nusle"
      "Hradečno - Nová Ves, okres Kladno"
      "Vráž, okres Písek"
    """
    street    = (loc.get("street") or "").strip()
    city      = (loc.get("city") or "").strip()
    city_part = (loc.get("cityPart") or "").strip()
    district  = (loc.get("district") or "").strip()

    if city in BIG_CITIES:
        head = big_city_head(loc)
        place = f"{head} - {city_part}" if city_part and city_part != head else head
        return f"{street}, {place}" if street else place

    place = f"{city} - {city_part}" if city_part and city_part != city else city
    if street:
        place = f"{street}, {place}"
    if district and district != city:
        place = f"{place}, okres {district}"
    return place


def locality_to_city(loc: dict) -> str:
    """Klíč pro grupování mediánů a pro filtr MĚSTO na webu."""
    city = (loc.get("city") or "").strip()
    if city in BIG_CITIES:
        return big_city_head(loc)
    return city or (loc.get("municipality") or "").strip()


def sub_slug(name: str) -> str:
    """
    Název subkategorie → slug v URL:
      "2+kk"           → "2+kk"      ('+' zůstává, nekóduje se)
      "Rodinný"        → "rodinny"
      "Památka/jiné"   → "pamatka"   (bere se jen část před lomítkem)
    """
    if not name:
        return ""
    if name in SUB_SLUG_OVERRIDES:
        return SUB_SLUG_OVERRIDES[name]
    base = name.split("/")[0]
    base = unicodedata.normalize("NFKD", base)
    base = "".join(c for c in base if not unicodedata.combining(c)).lower()
    return re.sub(r"[^a-z0-9+]+", "-", base).strip("-")


def build_locality_slug(loc: dict) -> str:
    parts = [
        loc.get("citySeoName") or "",
        loc.get("cityPartSeoName") or "",
        loc.get("streetSeoName") or "",
    ]
    return "-".join(p for p in parts if p) or "cesko"


def build_sreality_url(result: dict) -> str:
    """
    Sestaví URL na detail inzerátu.
    Slug lokality Sreality doredirectují, ale hlavní typ i subkategorie
    musí sedět přesně — jinak vrací 404.
    """
    estate_id = result.get("id", "")
    cat_main  = (result.get("categoryMainCb") or {}).get("value", 1)
    cat_type  = (result.get("categoryTypeCb") or {}).get("value", 1)

    sale_type = TYPE_URL.get(cat_type, "prodej")
    main_type = MAIN_URL.get(cat_main, "byt")
    sub_type  = sub_slug((result.get("categorySubCb") or {}).get("name", ""))
    locality  = build_locality_slug(result.get("locality") or {})

    if sub_type:
        return f"{SITE_BASE}/detail/{sale_type}/{main_type}/{sub_type}/{locality}/{estate_id}"
    return f"{SITE_BASE}/detail/{sale_type}/{main_type}/{locality}/{estate_id}"


def process_estate(result: dict, cat_main: int, cat_type: int) -> dict | None:
    name  = result.get("name", "")
    price = result.get("priceCzk")
    loc   = result.get("locality") or {}

    # Filtruj bez ceny, nulové, nebo "na vyžádání" (Sreality vrací 1 Kč)
    if not price or price <= 1 or not loc:
        return None

    area = parse_area(name)
    price_per_m2 = result.get("priceCzkPerSqM") or 0

    # Když plocha není v názvu, dopočítej ji z ceny za m²
    if area is None and price_per_m2 > 0:
        area = round(price / price_per_m2, 1)
    if area is None or area < MIN_AREA:
        return None

    if not price_per_m2:
        price_per_m2 = round(price / area)

    city = locality_to_city(loc)
    if not city:
        return None

    return {
        "id":                 str(result.get("id", "")),
        "title":              name,
        "price":              int(price),
        "area":               round(area, 1),
        "price_per_m2":       round(price_per_m2),
        "score":              0,
        "median_price_per_m2": None,
        "disposition":        parse_disposition(name),
        "disposition_group":  parse_disposition_group(name),
        "locality":           build_locality_text(loc),
        "locality_city":      city,
        "type":               "byt" if cat_main == 1 else "dům",
        "transaction":        "prodej" if cat_type == 1 else "pronájem",
        "ownership":          "",
        "building_type":      "",
        "extras":             [],
        "detail_fetched":     False,
        "url":                build_sreality_url(result),
        "scraped_at":         datetime.now(timezone.utc).isoformat(),
    }


# ── Scraping výpisů ────────────────────────────────────────────────────────────

def fetch_category(cat_main: int, cat_type: int, path: str, label: str,
                   build_id: str | None) -> list[dict]:
    """Stáhne stránky dané kategorie, nejnovější inzeráty jako první."""
    raw_results = []
    print(f"\n[{label}] Stahuji...")

    for page in range(1, MAX_PAGES + 1):
        results, total = fetch_search_page(path, page, build_id)
        if not results:
            break

        raw_results.extend(results)
        print(f"  Strana {page}: +{len(results)} (dostupnych: {total})")

        if len(results) < 20:
            break

        time.sleep(0.4)

    print(f"  >> Celkem: {len(raw_results)}")
    return raw_results


# ── Detaily inzerátů (vlastnictví, konstrukce, vybavení) ───────────────────────

# params → štítek ve filtru VYBAVENÍ na webu
DETAIL_BOOL_EXTRAS = [
    ("balcony",     "Balkón"),
    ("loggia",      "Lodžie"),
    ("terrace",     "Terasa"),
    ("garage",      "Garáž"),
    ("parkingLots", "Parkování"),
    ("cellar",      "Sklep"),
    ("basin",       "Bazén"),
    ("lowEnergy",   "Nízkoenergetický"),
    ("garret",      "Podkroví"),
]


def fetch_detail_params(estate_id: str, url: str) -> dict | None:
    html = http_get(url)
    if not html:
        return None
    data = parse_next_data(html)
    if not data:
        return None
    props = data.get("props", {}).get("pageProps", {})
    for query in props.get("dehydratedState", {}).get("queries", []):
        key = query.get("queryKey") or []
        if key and key[0] == "estate":
            return (query.get("state", {}).get("data") or {}).get("params") or {}
    return None


def _named(value) -> str:
    """params vrací buď dict {'name','value'} nebo prosté bool/None."""
    if isinstance(value, dict):
        name = (value.get("name") or "").strip()
        # Sreality používá placeholdery typu "- vyber možnost" / "- nezadáno"
        if name.startswith("-") or not name:
            return ""
        return name
    return ""


def apply_detail(listing: dict, params: dict) -> None:
    listing["ownership"]     = _named(params.get("ownership"))
    listing["building_type"] = _named(params.get("buildingType"))

    extras = []
    for key, label in DETAIL_BOOL_EXTRAS:
        if params.get(key) is True:
            extras.append(label)

    if _named(params.get("elevator")) == "Ano":
        extras.append("Výtah")

    # furnished vrací "Ano" / "Ne" / "Částečně" — na štítek to musí přeložit
    furnished = _named(params.get("furnished"))
    if furnished == "Ano":
        extras.append("Zařízeno")
    elif furnished == "Částečně":
        extras.append("Částečně zařízeno")

    condition = _named(params.get("buildingCondition"))
    if condition in ("Novostavba", "Ve výstavbě", "Projekt"):
        extras.append(condition)

    if (params.get("gardenArea") or 0) > 0:
        extras.append("Zahrada")

    listing["extras"] = extras
    listing["detail_fetched"] = True

    usable = params.get("usableArea")
    if isinstance(usable, (int, float)) and usable >= MIN_AREA:
        listing["area"] = round(float(usable), 1)
        if listing["price"] > 0:
            listing["price_per_m2"] = round(listing["price"] / listing["area"])

    since = params.get("since")
    if since:
        listing["listed_since"] = since


def enrich_with_details(listings: list[dict]) -> int:
    """Dotáhne detaily u inzerátů, které je ještě nemají. Vrací počet stažených."""
    pending = [l for l in listings if not l.get("detail_fetched")]
    if not pending:
        print("\nDetaily: vse uz stazeno")
        return 0

    batch = pending[:MAX_DETAILS]
    print(f"\nDetaily: stahuji {len(batch)} z {len(pending)} chybejicich...")

    done = 0
    failed = 0
    for listing in batch:
        params = fetch_detail_params(listing["id"], listing["url"])
        if params:
            apply_detail(listing, params)
            done += 1
        else:
            failed += 1
            # Po sérii selhání nemá smysl pokračovat (rate limit / výpadek)
            if failed >= 25 and done == 0:
                print("  [STOP] 25 selhani v rade, prerusuji dotahovani detailu")
                break
        time.sleep(DETAIL_SLEEP)

    print(f"  >> Dotazeno: {done}, selhalo: {failed}")
    return done


# ── Score výhodnosti ───────────────────────────────────────────────────────────

def compute_scores(listings: list[dict]) -> list[dict]:
    # Skupinujeme podle PRVNÍHO ČÍSLA dispozice (2+kk i 2+1 → skupina "2")
    groups: dict[tuple, list[float]] = defaultdict(list)
    for l in listings:
        key = (l["disposition_group"], l["locality_city"])
        groups[key].append(l["price_per_m2"])

    medians: dict[tuple, float] = {}
    for key, prices in groups.items():
        if len(prices) >= 3:
            medians[key] = statistics.median(prices)

    for l in listings:
        key    = (l["disposition_group"], l["locality_city"])
        median = medians.get(key)
        if median and median > 0:
            l["score"]              = round((1 - l["price_per_m2"] / median) * 100, 1)
            l["median_price_per_m2"] = round(median)
        else:
            l["score"]              = 0
            l["median_price_per_m2"] = None

    return listings


# ── Archiv prodaných ───────────────────────────────────────────────────────────

def load_json_file(path: str) -> dict:
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return {}


def update_archive(new_ids: set, previous_listings: list[dict]) -> list[dict]:
    """
    Inzeráty z předchozího feedu, které už nejsou v novém scrapování,
    jsou považovány za prodané → přejdou do archivu.
    """
    now = datetime.now(timezone.utc).isoformat()
    archived = []
    for listing in previous_listings:
        if listing["id"] not in new_ids:
            listing = dict(listing)
            if "sold_at" not in listing:
                listing["sold_at"] = now
            archived.append(listing)
    return archived


# ── Cenová historie ────────────────────────────────────────────────────────────

def compute_city_medians(listings: list[dict]) -> dict:
    """
    Vrátí slovník { město: { "byty": median|None, "domy": median|None, "celkem": median|None } }
    Počítá se z předaného seznamu listingů (typicky celý merged feed).
    """
    buckets: dict[str, dict[str, list]] = defaultdict(lambda: {"byty": [], "domy": [], "celkem": []})

    for l in listings:
        city = l.get("locality_city", "").strip()
        if not city:
            continue
        pm2 = l.get("price_per_m2", 0)
        if not pm2 or pm2 <= 0:
            continue
        t = l.get("type", "")
        buckets[city]["celkem"].append(pm2)
        if t == "byt":
            buckets[city]["byty"].append(pm2)
        elif t == "dům":
            buckets[city]["domy"].append(pm2)

    result = {}
    for city, groups in buckets.items():
        result[city] = {}
        for key, prices in groups.items():
            if prices:
                s = sorted(prices)
                result[city][key] = s[len(s) // 2]
            else:
                result[city][key] = None
    return result


def update_price_history(listings: list[dict]) -> None:
    """
    Načte price_history.json, přidá záznam pro dnešní den (přepíše pokud již existuje),
    a uloží zpět. Struktura:
      { "updated": "...", "days": [ { "date": "YYYY-MM-DD", "cities": { ... } }, ... ] }
    """
    today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    medians = compute_city_medians(listings)

    prev = load_json_file(HISTORY_FILE)
    days: list[dict] = prev.get("days", [])

    # Odstraň případný starší záznam pro dnešní datum
    days = [d for d in days if d.get("date") != today]

    days.append({"date": today, "cities": medians})

    # Seřadit vzestupně dle data (pro přehlednost a grafy)
    days.sort(key=lambda d: d["date"])

    history = {
        "updated": datetime.now(timezone.utc).isoformat(),
        "days":    days,
    }
    with open(HISTORY_FILE, "w", encoding="utf-8") as f:
        json.dump(history, f, ensure_ascii=False, indent=2)

    print(f"Ulozeno: {HISTORY_FILE} ({len(days)} dni, {len(medians)} mest)")


# ── Hlavní funkce ──────────────────────────────────────────────────────────────

def main():
    print("=" * 60)
    print("Sreality scraper")
    print(f"Cas: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)

    # Načteme předchozí feed (pro archiv a pro zachování už stažených detailů)
    prev_feed     = load_json_file(OUTPUT_FILE)
    prev_listings = prev_feed.get("listings", [])
    prev_by_id    = {l["id"]: l for l in prev_listings}
    prev_archived = load_json_file(ARCHIVE_FILE).get("listings", [])
    prev_arch_ids = {l["id"] for l in prev_archived}

    build_id = get_build_id()
    if not build_id:
        print("\n[VAROVANI] buildId se nepodarilo zjistit, jedu pres HTML fallback")

    # Scraping výpisů
    all_listings = []
    for cat_main, cat_type, path, label in CATEGORIES:
        raw = fetch_category(cat_main, cat_type, path, label, build_id)
        for result in raw:
            processed = process_estate(result, cat_main, cat_type)
            if processed:
                all_listings.append(processed)

    print(f"\nZpracovano: {len(all_listings)} inzeratu")

    # ── Pojistka: při výpadku zdroje nic nepřepisujeme ─────────────
    # Bez ní by se celý feed archivoval jako "prodáno" (viz výpadek API 25. 5. 2026).
    if len(all_listings) < MIN_SCRAPED_OK:
        print(
            f"\n[FATAL] Staženo jen {len(all_listings)} inzeratu "
            f"(minimum {MIN_SCRAPED_OK}). Zdroj je pravdepodobne rozbity."
        )
        print("Feed zustava beze zmeny, nic se nezapisuje.")
        sys.exit(1)

    # Deduplikace (stejné ID z různých stránek)
    seen = set()
    unique = []
    for l in all_listings:
        if l["id"] not in seen:
            seen.add(l["id"])
            unique.append(l)
    all_listings = unique

    # Převezmi už stažené detaily z předchozího feedu (šetří requesty)
    for l in all_listings:
        prev = prev_by_id.get(l["id"])
        if prev and prev.get("detail_fetched"):
            l["ownership"]      = prev.get("ownership", "")
            l["building_type"]  = prev.get("building_type", "")
            l["extras"]         = prev.get("extras", [])
            l["detail_fetched"] = True
            if prev.get("listed_since"):
                l["listed_since"] = prev["listed_since"]
            if prev.get("area"):
                l["area"] = prev["area"]
                l["price_per_m2"] = round(l["price"] / l["area"]) if l["area"] else l["price_per_m2"]

    # ── Akumulace: sloučíme staré + nové inzeráty ──────────────────
    # Nové scraping data přepíší staré záznamy stejného ID (čerstvější info)
    merged: dict[str, dict] = {l["id"]: l for l in prev_listings}
    for l in all_listings:
        merged[l["id"]] = l  # přepíše starý záznam čerstvým

    # Archiv — co bylo minule ve feedu a teď Sreality nevrátilo
    newly_archived = update_archive(seen, prev_listings)
    for l in newly_archived:
        if l["id"] not in prev_arch_ids:
            prev_archived.append(l)
            prev_arch_ids.add(l["id"])
            merged.pop(l["id"], None)  # vyřaď prodané z feedu

    print(f"Nove archivovano (prodano): {len(newly_archived)}")
    print(f"Celkem v archivu: {len(prev_archived)}")

    # Dotáhni chybějící detaily (vlastnictví, konstrukce, vybavení)
    enrich_with_details(list(merged.values()))

    # ── Score: přepočítá se z CELÉHO merged feedu ──────────────────
    # Tím se medián průběžně zpřesňuje s každým dalším dnem scrapingu
    all_merged = compute_scores(list(merged.values()))
    merged = {l["id"]: l for l in all_merged}

    # Výsledný feed — seřadit dle score, zachovat vše
    top_listings = sorted(merged.values(), key=lambda x: x["score"], reverse=True)

    # Uložit feed.json
    feed = {
        "updated":       datetime.now(timezone.utc).isoformat(),
        "total_scraped": len(all_listings),
        "total_in_feed": len(top_listings),
        "listings":      top_listings,
    }
    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(feed, f, ensure_ascii=False, indent=2)

    # Uložit archived.json
    archive = {
        "updated":  datetime.now(timezone.utc).isoformat(),
        "total":    len(prev_archived),
        "listings": prev_archived,
    }
    with open(ARCHIVE_FILE, "w", encoding="utf-8") as f:
        json.dump(archive, f, ensure_ascii=False, indent=2)

    # Uložit price_history.json
    update_price_history(top_listings)

    print(f"\nUlozeno: {OUTPUT_FILE} ({len(top_listings)} inzeratu)")
    print(f"Ulozeno: {ARCHIVE_FILE} ({len(prev_archived)} prodanych)")
    print("=" * 60)


if __name__ == "__main__":
    main()
