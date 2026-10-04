"""
Sreality.cz scraper — Agregátor realitních nabídek (verze 2)

Stahuje inzeráty ze Sreality, drží jejich historii v SQLite a z celého trhu
počítá srovnávací ceny. Každý inzerát dostane skóre výhodnosti 0–100.
Výstupy pro web: feed.json, archived.json, price_history.json, market_stats.json.

Režimy:
  --mode full   projde celý výpis (~42 000 inzerátů, ~40 min), jednou denně na NUC
  --mode quick  jen nejnovější stránky, několikrát denně

Stav je v SQLite (--db). Když je databáze prázdná, naplní se z feed.json,
takže scraper funguje i v GitHub Action bez trvalého úložiště (záložní režim).

Zdroj dat: Sreality běží na Next.js, data jsou v __NEXT_DATA__ / _next/data.
Původní API /api/cs/v2/estates bylo vypnuto 25. 5. 2026 (vrací 404).
"""

import argparse
import json
import math
import os
import re
import sqlite3
import statistics
import sys
import time
import unicodedata
from collections import defaultdict
from datetime import datetime, timedelta, timezone
import urllib.error
import urllib.request

# ── Konfigurace ────────────────────────────────────────────────────────────────

SITE_BASE    = "https://www.sreality.cz"
OUTPUT_FILE  = "feed.json"
ARCHIVE_FILE = "archived.json"
HISTORY_FILE = "price_history.json"   # denní mediány cen/m² per město (starý formát, čte ho web)
STATS_FILE   = "market_stats.json"    # týdenní mediány pro grafy na stránkách měst

PAGE_SIZE        = 22     # inzerátů na stránku výpisu
QUICK_PAGES      = 12     # quick režim: nejnovější stránky na kategorii
FULL_MAX_PAGES   = 1500   # pojistka proti nekonečné smyčce
FULL_COMPLETE    = 0.9    # full běh je kompletní, když stáhl aspoň 90 % hlášeného počtu

MIN_AREA = 15

# Pod tímto počtem stažených inzerátů považujeme běh za selhaný a nic nezapíšeme.
# Chrání feed před hromadnou archivací při výpadku zdroje.
MIN_SCRAPED_OK = 100

# Tempo dotazů. Sreality je potřeba šetřit: z NUC jede domácí IP adresa.
REQUEST_INTERVAL = float(os.environ.get("SREALITY_INTERVAL", "1.2"))
MAX_FAILS_IN_ROW = 40

DETAILS_BUDGET = {"quick": 200, "full": 2500}   # detailů za běh
ARCHIVE_BUDGET = {"quick": 60,  "full": 300}    # ověření 404 za běh
STALE_DAYS     = 14      # bez potvrzení déle než tohle jde inzerát z aktivních pryč
MAX_FEED_SIZE  = 2000
ARCHIVE_KEEP_DAYS = 120
HISTORY_KEEP_DAYS = 400

# Srovnávací cena: minimální vzorek na úrovni lokality
BENCH_MIN_N = 8
# Cena za m² s plochou klesá (velký byt je za m² levnější). Srovnávací cena se proto
# přepočítá na plochu inzerátu: × (plocha / medián plochy) ^ −elasticita, v mezích 0,7–1,3.
SIZE_ELASTICITY = {"byt": 0.20, "dum": 0.30, "rekreace": 0.25, "ostatni": 0.30}

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

TYPE_URL = {1: "prodej", 2: "pronajem", 3: "drazby"}
MAIN_URL = {1: "byt", 2: "dum", 3: "pozemek", 4: "komercni", 5: "ostatni"}
SUB_SLUG_OVERRIDES: dict[str, str] = {}

# Města, kde je smysluplnější grupovat podle městské části než podle města
BIG_CITIES = {"Praha", "Brno", "Ostrava", "Plzeň"}

# Segmenty pro srovnání cen. Chata se nesrovnává s rodinným domem.
SEG_BY_SUB = {
    "chata": "rekreace", "chalupa": "rekreace",
    "pamatka": "ostatni", "zemedelska-usedlost": "ostatni",
}
EXCLUDED_SUBS = {"na-klic"}   # projekt domu bez pozemku, není to nemovitost

# Hranice, pod kterými cena nedává smysl jako prodejní (nájem, rezervace, překlep)
MIN_PRICE = {"byt": 300_000, "dum": 300_000, "rekreace": 100_000, "ostatni": 200_000}
MAX_AREA  = {"byt": 400, "dum": 2000, "rekreace": 600, "ostatni": 5000}
# Cena/m² pod tímto podílem srovnávací ceny je skoro jistě chyba v datech
SUSPICIOUS_RATIO = 0.35

# Koeficienty, které skóre snižují (inzerát může být výhodný, ale cena není srovnatelná)
PENALTIES = {
    "druzstevni":  (0.80, "Družstevní vlastnictví"),
    "obecni":      (0.60, "Státní nebo obecní vlastnictví"),
    "vystavba":    (0.85, "Ve výstavbě nebo projekt"),
    "bez_detailu": (0.90, "Parametry zatím neověřené"),
    "extremni":    (0.80, "Neobvykle nízká cena, ověřte stav nemovitosti"),
}
EXTREME_DISCOUNT = 0.50
# Výsledné skóre je pořadí v celém trhu: body z score_listing se převedou tak,
# aby 80+ měl horní 1 % nabídek, 60+ horních 5 %, 40+ horních 20 % a medián kolem 15.
CALIBRATION = [(0.0, 0), (0.50, 15), (0.80, 40), (0.95, 60), (0.99, 80), (1.0, 100)]
CONFIDENCE = {"lokalita": 1.0, "lokalita_maly": 0.92, "okres": 0.85, "kraj": 0.75, "cr": 0.6}

# ── HTTP ───────────────────────────────────────────────────────────────────────

_last_request = 0.0
_fails_in_row = 0


class SourceDown(Exception):
    pass


def _throttle() -> None:
    global _last_request
    wait = REQUEST_INTERVAL - (time.monotonic() - _last_request)
    if wait > 0:
        time.sleep(wait)
    _last_request = time.monotonic()


def http_request(url: str, timeout: int = 25) -> tuple[int, str | None]:
    """Vrací (HTTP status, tělo). Status 0 = síťová chyba. Při 429/503 počká a zkusí znovu."""
    global _fails_in_row
    for attempt in range(3):
        _throttle()
        req = urllib.request.Request(url, headers=HEADERS)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                _fails_in_row = 0
                return resp.status, resp.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            if e.code in (429, 502, 503) and attempt < 2:
                print(f"  [ZPOMALUJI] HTTP {e.code}, cekam 30 s")
                time.sleep(30)
                continue
            if e.code not in (404, 410):
                _fails_in_row += 1
            return e.code, None
        except Exception as e:
            print(f"  [CHYBA] {e}  ({url[:110]})")
            _fails_in_row += 1
            if attempt < 2:
                time.sleep(5)
                continue
            return 0, None
        finally:
            if _fails_in_row >= MAX_FAILS_IN_ROW:
                raise SourceDown(f"{MAX_FAILS_IN_ROW} selhani v rade")
    return 0, None


def http_get(url: str) -> str | None:
    return http_request(url)[1]


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
    data = parse_next_data(html) if html else None
    build_id = (data or {}).get("buildId")
    if build_id:
        print(f"Sreality buildId: {build_id}")
    return build_id


def extract_search_results(next_data: dict) -> tuple[list[dict], int]:
    """Vytáhne inzeráty z react-query cache (klíč 'estatesSearch')."""
    props = next_data.get("props", {}).get("pageProps") or next_data.get("pageProps", {})
    for query in props.get("dehydratedState", {}).get("queries", []):
        key = query.get("queryKey") or []
        if key and key[0] == "estatesSearch":
            data = query.get("state", {}).get("data") or {}
            total = (data.get("pagination") or {}).get("total") or 0
            return data.get("results") or [], total
    return [], 0


def fetch_search_page(path: str, page: int, build_id: str | None) -> tuple[list[dict], int]:
    """Primárně _next/data (4× menší přenos), při selhání HTML + __NEXT_DATA__."""
    if build_id:
        body = http_get(f"{SITE_BASE}/_next/data/{build_id}/cs/hledani/{path}.json?strana={page}")
        if body:
            try:
                return extract_search_results(json.loads(body))
            except json.JSONDecodeError:
                pass
    html = http_get(f"{SITE_BASE}/hledani/{path}?strana={page}")
    data = parse_next_data(html) if html else None
    if not data:
        return [], 0
    return extract_search_results(data)


# ── Parsování výpisu ───────────────────────────────────────────────────────────

def parse_area(name: str) -> float | None:
    match = re.search(r"(\d+(?:[.,]\d+)?)\s*m[²2]", name, re.IGNORECASE)
    return float(match.group(1).replace(",", ".")) if match else None


def parse_disposition(name: str) -> str:
    match = re.search(r"(\d+\+(?:kk|\d+))", name, re.IGNORECASE)
    return match.group(1).lower() if match else "ostatní"


def parse_disposition_group(name: str) -> str:
    """Skupina dispozice podle prvního čísla (2+kk i 2+1 → '2')."""
    match = re.search(r"(\d+)\+", name, re.IGNORECASE)
    return match.group(1) if match else "ostatní"


def big_city_head(loc: dict) -> str:
    """
    Pro Prahu/Brno/... vrátí městskou část ("Praha 4", "Brno-město").
    U inzerátů bez přesné adresy je v 'district' název kraje — pak název města.
    """
    city     = (loc.get("city") or "").strip()
    district = (loc.get("district") or "").strip()
    region   = (loc.get("region") or "").strip()
    if district and district != region:
        return district
    return city


def build_locality_text(loc: dict) -> str:
    """"Rovnoběžná, Praha 4 - Nusle" / "Hradečno - Nová Ves, okres Kladno" """
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
    """Klíč pro filtr MĚSTO na webu a pro nejnižší úroveň srovnání."""
    city = (loc.get("city") or "").strip()
    if city in BIG_CITIES:
        return big_city_head(loc)
    return city or (loc.get("municipality") or "").strip()


def sub_slug(name: str) -> str:
    """"2+kk" → "2+kk", "Rodinný" → "rodinny", "Památka/jiné" → "pamatka" """
    if not name:
        return ""
    if name in SUB_SLUG_OVERRIDES:
        return SUB_SLUG_OVERRIDES[name]
    base = unicodedata.normalize("NFKD", name.split("/")[0])
    base = "".join(c for c in base if not unicodedata.combining(c)).lower()
    return re.sub(r"[^a-z0-9+]+", "-", base).strip("-")


def build_locality_slug(loc: dict) -> str:
    parts = [loc.get("citySeoName") or "", loc.get("cityPartSeoName") or "", loc.get("streetSeoName") or ""]
    return "-".join(p for p in parts if p) or "cesko"


def build_sreality_url(result: dict) -> str:
    """Hlavní typ i subkategorie musí sedět přesně, jinak 404. Slug lokality se doredirectuje."""
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


def image_urls(result: dict, limit: int = 6) -> list[str]:
    """Základní URL fotek bez parametru velikosti. Web si připojí ?fl=res,… sám (bez něj CDN vrací 401)."""
    out = []
    for img in result.get("images") or []:
        url = (img or {}).get("url") or ""
        if not url:
            continue
        if url.startswith("//"):
            url = "https:" + url
        out.append(url.split("?")[0])
        if len(out) >= limit:
            break
    return out


def norm_text(s: str) -> str:
    s = unicodedata.normalize("NFKD", s or "")
    return "".join(c for c in s if not unicodedata.combining(c)).lower().strip()


def parse_estate(result: dict, cat_main: int, cat_type: int) -> dict | None:
    """Z položky výpisu udělá záznam pro databázi. None = nepoužitelný inzerát."""
    name  = result.get("name", "")
    price = result.get("priceCzk")
    loc   = result.get("locality") or {}
    unit  = ((result.get("priceUnitCb") or {}).get("name") or "").lower()

    # bez ceny, "na vyžádání" (Sreality vrací 1 Kč), nebo cena za m² / za měsíc
    if not price or price <= 1 or not loc:
        return None
    if unit and unit != "za nemovitost":
        return None

    sub = sub_slug((result.get("categorySubCb") or {}).get("name", ""))
    area = parse_area(name)
    ppm2 = result.get("priceCzkPerSqM") or 0
    if area is None and ppm2 > 0:
        area = round(price / ppm2, 1)
    if area is None or area < MIN_AREA:
        return None

    city_key = locality_to_city(loc)
    if not city_key:
        return None

    seg = "byt" if cat_main == 1 else SEG_BY_SUB.get(sub, "dum")
    street = (loc.get("street") or "").strip()
    part   = (loc.get("cityPart") or "").strip()
    city   = (loc.get("city") or "").strip()
    disposition = parse_disposition(name)

    return {
        "id":          str(result.get("id", "")),
        "seg":         seg,
        "type":        "byt" if cat_main == 1 else "dům",
        "sub":         sub,
        "title":       name,
        "price":       int(price),
        "area":        round(area, 1),
        "disposition": disposition,
        "dgroup":      parse_disposition_group(name),
        "city_key":    city_key,
        "city":        city,
        "district":    (loc.get("district") or "").strip(),
        "region":      (loc.get("region") or "").strip(),
        "locality":    build_locality_text(loc),
        "lat":         loc.get("latitude"),
        "lon":         loc.get("longitude"),
        "url":         build_sreality_url(result),
        "images":      json.dumps(image_urls(result)),
        "premise":     ((result.get("premise") or {}).get("seoName") or "") if result.get("premise") else "",
        "is_rk":       1 if (result.get("premise") or result.get("premiseId")) else 0,
        "has_tour":    1 if (result.get("hasMatterport") or result.get("hasVideo")) else 0,
        "discount_flag": 1 if result.get("discountShow") else 0,
        "fingerprint": "|".join([
            "byt" if cat_main == 1 else "dum", disposition, str(round(area)),
            norm_text(street or part), norm_text(city or city_key),
        ]),
    }


# ── Databáze ───────────────────────────────────────────────────────────────────

SCHEMA = """
CREATE TABLE IF NOT EXISTS listings (
    id TEXT PRIMARY KEY,
    seg TEXT, type TEXT, sub TEXT, title TEXT,
    price INTEGER, area REAL, disposition TEXT, dgroup TEXT,
    city_key TEXT, city TEXT, district TEXT, region TEXT, locality TEXT,
    lat REAL, lon REAL, url TEXT, images TEXT,
    premise TEXT, is_rk INTEGER, has_tour INTEGER, discount_flag INTEGER,
    fingerprint TEXT,
    first_seen TEXT, last_seen TEXT, listed_since TEXT, edited TEXT,
    detail_at TEXT, ownership TEXT DEFAULT '', building_type TEXT DEFAULT '',
    extras TEXT DEFAULT '[]', price_old INTEGER, is_share INTEGER DEFAULT 0,
    is_auction INTEGER DEFAULT 0, is_rental INTEGER DEFAULT 0,
    status TEXT DEFAULT 'active', gone_at TEXT, last_checked_at TEXT,
    missing_full INTEGER DEFAULT 0, relisted_from TEXT
);
CREATE INDEX IF NOT EXISTS ix_status ON listings(status);
CREATE INDEX IF NOT EXISTS ix_fp ON listings(fingerprint);
CREATE TABLE IF NOT EXISTS prices (
    id TEXT, date TEXT, price INTEGER, PRIMARY KEY (id, date)
);
CREATE TABLE IF NOT EXISTS stats (
    date TEXT, level TEXT, key TEXT, seg TEXT, dgroup TEXT, median INTEGER, n INTEGER,
    PRIMARY KEY (date, level, key, seg, dgroup)
);
CREATE TABLE IF NOT EXISTS runs (
    started TEXT, mode TEXT, scraped INTEGER, total_reported INTEGER, complete INTEGER, note TEXT
);
"""

LISTING_FIELDS = [
    "seg", "type", "sub", "title", "price", "area", "disposition", "dgroup", "city_key", "city",
    "district", "region", "locality", "lat", "lon", "url", "images", "premise", "is_rk",
    "has_tour", "discount_flag", "fingerprint",
]


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def today() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%d")


def open_db(path: str) -> sqlite3.Connection:
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    con.executescript(SCHEMA)
    return con


def load_json_file(path: str) -> dict:
    try:
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return {}


TITLE_SUBS = [
    ("chaty", "chata"), ("chalupy", "chalupa"), ("pamatky", "pamatka"),
    ("zemedelske usedlosti", "zemedelska-usedlost"), ("projektu na klic", "na-klic"),
    ("vily", "vila"), ("vicegeneracniho", "vicegeneracni-dum"), ("rodinneho", "rodinny"),
]


def sub_from_title(title: str) -> str:
    t = norm_text(title)
    for needle, sub in TITLE_SUBS:
        if needle in t:
            return sub
    m = re.search(r"(\d+\+(?:kk|\d+))", t)
    return m.group(1) if m else ""


def bootstrap_from_feed(con: sqlite3.Connection, out_dir: str) -> None:
    """
    Prázdná databáze se naplní z posledního feed.json a archived.json.
    Zachová se tím, odkdy inzerát známe, stažené detaily a archiv prodaných.
    """
    if con.execute("SELECT COUNT(*) FROM listings").fetchone()[0]:
        return
    feed = load_json_file(os.path.join(out_dir, OUTPUT_FILE)).get("listings", [])
    arch = load_json_file(os.path.join(out_dir, ARCHIVE_FILE)).get("listings", [])
    if not feed and not arch:
        return
    print(f"Databaze je prazdna, nacitam z feedu: {len(feed)} aktivnich, {len(arch)} v archivu")
    for l, status in [(x, "active") for x in feed] + [(x, "sold") for x in arch]:
        if not l.get("id"):
            continue
        sub = l.get("subtype") or sub_from_title(l.get("title", ""))
        seg = l.get("segment") or ("byt" if l.get("type") == "byt" else SEG_BY_SUB.get(sub, "dum"))
        # starý feed nemá first_seen, scraped_at v něm je čas posledního stažení
        first = l.get("first_seen") or min(x for x in (l.get("listed_since"), l.get("scraped_at"), now_iso()) if x)
        con.execute(
            """INSERT OR IGNORE INTO listings (id, seg, type, sub, title, price, area, disposition, dgroup,
               city_key, city, district, region, locality, lat, lon, url, images, premise, is_rk, has_tour,
               discount_flag, fingerprint, first_seen, last_seen, listed_since, detail_at, ownership,
               building_type, extras, price_old, status, gone_at, last_checked_at)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                l["id"], seg, l.get("type", ""), sub, l.get("title", ""),
                l.get("price"), l.get("area"), l.get("disposition", ""), l.get("disposition_group", ""),
                l.get("locality_city", ""), l.get("city", ""), l.get("district", ""), l.get("region", ""),
                l.get("locality", ""), l.get("lat"), l.get("lon"), l.get("url", ""),
                json.dumps(l.get("images") or []), l.get("premise", ""),
                1 if l.get("seller") == "rk" else (0 if l.get("seller") == "soukromy" else 1),
                1 if l.get("has_tour") else 0, 0, l.get("fingerprint", ""),
                first, l.get("scraped_at") or first, l.get("listed_since"),
                None,   # detail se stáhne znovu kvůli novým polím (podíl, dražba, původní cena)
                l.get("ownership", ""), l.get("building_type", ""), json.dumps(l.get("extras") or []),
                (l.get("price_drop") or {}).get("from"), status, l.get("sold_at"), l.get("last_checked_at"),
            ),
        )
    con.commit()


def upsert(con: sqlite3.Connection, rec: dict, ts: str) -> None:
    row = con.execute("SELECT price, status, first_seen FROM listings WHERE id=?", (rec["id"],)).fetchone()
    if row is None:
        cols = ["id"] + LISTING_FIELDS + ["first_seen", "last_seen", "status"]
        vals = [rec["id"]] + [rec[f] for f in LISTING_FIELDS] + [ts, ts, "active"]
        con.execute(f"INSERT INTO listings ({','.join(cols)}) VALUES ({','.join('?' * len(cols))})", vals)
        # Stejná nemovitost, která nedávno zmizela, se vrátila pod novým ID
        cutoff = (datetime.now(timezone.utc) - timedelta(days=120)).isoformat()
        prev = con.execute(
            """SELECT id, price FROM listings WHERE fingerprint=? AND id<>? AND status<>'active'
               AND COALESCE(gone_at, last_seen) >= ? ORDER BY last_seen DESC LIMIT 1""",
            (rec["fingerprint"], rec["id"], cutoff),
        ).fetchone()
        if prev:
            con.execute("UPDATE listings SET relisted_from=? WHERE id=?", (prev["id"], rec["id"]))
    else:
        # Plochu z detailu (užitná plocha) nepřepisujeme plochou z titulku
        sets = ", ".join(
            "area=CASE WHEN detail_at IS NULL THEN ? ELSE area END" if f == "area" else f"{f}=?"
            for f in LISTING_FIELDS
        )
        con.execute(
            f"UPDATE listings SET {sets}, last_seen=?, status='active', gone_at=NULL, missing_full=0 WHERE id=?",
            [rec[f] for f in LISTING_FIELDS] + [ts, rec["id"]],
        )
    last = con.execute("SELECT price FROM prices WHERE id=? ORDER BY date DESC LIMIT 1", (rec["id"],)).fetchone()
    if last is None or last["price"] != rec["price"]:
        con.execute("INSERT OR REPLACE INTO prices (id, date, price) VALUES (?,?,?)", (rec["id"], today(), rec["price"]))


# ── Scraping výpisů ────────────────────────────────────────────────────────────

# Sreality vrátí na jeden dotaz nejvýš ~10 000 inzerátů (454 stránek). Celý trh se proto
# prochází po krajích, každý kraj je pod limitem. Součet krajů = celostátní počet (ověřeno 4. 10. 2026).
REGIONS = [
    "praha", "stredocesky-kraj", "jihocesky-kraj", "plzensky-kraj", "karlovarsky-kraj",
    "ustecky-kraj", "liberecky-kraj", "kralovehradecky-kraj", "pardubicky-kraj", "vysocina-kraj",
    "jihomoravsky-kraj", "olomoucky-kraj", "zlinsky-kraj", "moravskoslezsky-kraj",
]


def crawl_path(con: sqlite3.Connection, path: str, cat_main: int, cat_type: int, max_pages: int,
               build_id: str | None, ts: str, seen: set, full: bool) -> tuple[int, int]:
    """Projde stránky jednoho výpisu. Vrací (staženo, hlášený počet)."""
    got = total = 0
    page = 1
    while page <= max_pages:
        results, t = fetch_search_page(path, page, build_id)
        total = max(total, t)
        if full and total:
            max_pages = min(FULL_MAX_PAGES, math.ceil(total / PAGE_SIZE) + 1)
        if not results:
            break
        for r in results:
            rec = parse_estate(r, cat_main, cat_type)
            if rec and rec["sub"] not in EXCLUDED_SUBS:
                upsert(con, rec, ts)
                seen.add(rec["id"])
        got += len(results)
        if page % 50 == 0:
            con.commit()
            print(f"    strana {page}: {got} z {total}")
        if len(results) < PAGE_SIZE - 2:
            break
        page += 1
    con.commit()
    return got, total


def crawl(con: sqlite3.Connection, mode: str, build_id: str | None, ts: str) -> tuple[set, int, bool]:
    """Vrací (viděná ID, hlášený celkový počet, jestli je full běh kompletní)."""
    seen: set[str] = set()
    total_reported = 0
    complete = mode == "full"

    for cat_main, cat_type, path, label in CATEGORIES:
        print(f"\n[{label}] Stahuji ({mode})...")
        if mode == "quick":
            got, total = crawl_path(con, path, cat_main, cat_type, QUICK_PAGES, build_id, ts, seen, False)
        else:
            got = total = 0
            for region in REGIONS:
                g, t = crawl_path(con, f"{path}/{region}", cat_main, cat_type, FULL_MAX_PAGES, build_id, ts, seen, True)
                print(f"  {region}: {g} z {t}")
                got += g
                total += t
        print(f"  >> {got} z {total} hlasenych")
        total_reported += total
        if mode == "full" and (not total or got < total * FULL_COMPLETE):
            complete = False

    return seen, total_reported, complete


# ── Detaily inzerátů ───────────────────────────────────────────────────────────

DETAIL_BOOL_EXTRAS = [
    ("balcony", "Balkón"), ("loggia", "Lodžie"), ("terrace", "Terasa"), ("garage", "Garáž"),
    ("parkingLots", "Parkování"), ("cellar", "Sklep"), ("basin", "Bazén"),
    ("lowEnergy", "Nízkoenergetický"), ("garret", "Podkroví"),
]


def _named(value) -> str:
    """params vrací buď dict {'name','value'} nebo prosté bool/None."""
    if isinstance(value, dict):
        name = (value.get("name") or "").strip()
        return "" if name.startswith("-") or not name else name
    return ""


def fetch_detail(url: str) -> tuple[str, dict | None]:
    """('ok', data) | ('gone', None) | ('error', None)"""
    status, html = http_request(url)
    if status in (404, 410):
        return "gone", None
    data = parse_next_data(html) if html else None
    if not data:
        return "error", None
    for query in data.get("props", {}).get("pageProps", {}).get("dehydratedState", {}).get("queries", []):
        key = query.get("queryKey") or []
        if key and key[0] == "estate":
            return "ok", query.get("state", {}).get("data") or {}
    return "error", None


def apply_detail(con: sqlite3.Connection, row: sqlite3.Row, data: dict) -> None:
    params = data.get("params") or {}
    extras = [label for key, label in DETAIL_BOOL_EXTRAS if params.get(key) is True]
    if _named(params.get("elevator")) == "Ano":
        extras.append("Výtah")
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

    area = row["area"]
    usable = params.get("usableArea")
    if isinstance(usable, (int, float)) and usable >= MIN_AREA:
        area = round(float(usable), 1)

    num, den = params.get("shareNumerator"), params.get("shareDenominator")
    is_share = 1 if (isinstance(num, (int, float)) and isinstance(den, (int, float))
                     and num and den and num < den) else 0
    if "podil" in norm_text(data.get("name") or row["title"]):
        is_share = 1
    is_auction = 1 if (_named(params.get("auctionKind")) or params.get("auctionDate")
                       or (data.get("categoryTypeCb") or {}).get("value") == 3) else 0
    is_rental = 1 if norm_text(data.get("name") or "").startswith("pronajem") else 0
    price_old = data.get("priceSummaryOldCzk")
    price_old = int(price_old) if isinstance(price_old, (int, float)) and price_old > row["price"] else None

    con.execute(
        """UPDATE listings SET ownership=?, building_type=?, extras=?, area=?, listed_since=?, edited=?,
           price_old=?, is_share=?, is_auction=?, is_rental=?, detail_at=? WHERE id=?""",
        (
            _named(params.get("ownership")), _named(params.get("buildingType")),
            json.dumps(extras, ensure_ascii=False), area,
            params.get("since") or row["listed_since"], params.get("edited"),
            price_old, is_share, is_auction, is_rental, now_iso(), row["id"],
        ),
    )


def enrich_details(con: sqlite3.Connection, budget: int, prelim: dict[str, float]) -> None:
    """
    Detaily (vlastnictví, plocha, stáří inzerátu, původní cena, podíl, dražba) se stahují
    jen u nových inzerátů. Přednost mají ty, které podle výpisu vypadají nejvýhodněji.
    """
    rows = con.execute("SELECT * FROM listings WHERE status='active' AND detail_at IS NULL").fetchall()
    if not rows:
        print("\nDetaily: vse uz stazeno")
        return
    rows.sort(key=lambda r: prelim.get(r["id"], 0), reverse=True)
    batch = rows[:budget]
    print(f"\nDetaily: stahuji {len(batch)} z {len(rows)} chybejicich...")
    done = gone = 0
    for i, row in enumerate(batch, 1):
        state, data = fetch_detail(row["url"])
        if state == "ok":
            apply_detail(con, row, data)
            done += 1
        elif state == "gone":
            con.execute("UPDATE listings SET status='sold', gone_at=?, last_checked_at=? WHERE id=?",
                        (now_iso(), now_iso(), row["id"]))
            gone += 1
        if i % 100 == 0:
            con.commit()
    con.commit()
    print(f"  >> Dotazeno: {done}, mezitim pryc: {gone}")


# ── Archiv prodaných ───────────────────────────────────────────────────────────

def update_archive(con: sqlite3.Connection, seen: set, mode: str, complete: bool, budget: int) -> None:
    """
    Zmizení z výpisu neznamená prodej (quick režim vidí jen nejnovější stránky).
    Do archivu jde jen inzerát, jehož detail vrací 404/410. V kompletním full běhu se navíc
    počítá, kolikrát po sobě v celém výpisu chyběl, a ti se ověřují přednostně.
    """
    ts = now_iso()
    active = con.execute("SELECT id, url, missing_full, last_checked_at, last_seen FROM listings WHERE status='active'").fetchall()
    missing = [r for r in active if r["id"] not in seen]
    if mode == "full" and complete:
        for r in missing:
            con.execute("UPDATE listings SET missing_full=missing_full+1 WHERE id=?", (r["id"],))
        con.commit()
        missing = con.execute(
            "SELECT id, url, missing_full, last_checked_at, last_seen FROM listings WHERE status='active' AND missing_full>0"
        ).fetchall()
    missing = sorted(missing, key=lambda r: (-(r["missing_full"] or 0), r["last_checked_at"] or r["last_seen"] or ""))
    batch = missing[:budget]
    print(f"\nArchiv: overuji {len(batch)} z {len(missing)} kandidatu...")
    sold = 0
    for r in batch:
        status, _ = http_request(r["url"])
        if status in (404, 410):
            con.execute("UPDATE listings SET status='sold', gone_at=?, last_checked_at=? WHERE id=?", (ts, ts, r["id"]))
            sold += 1
        elif status == 200:
            con.execute("UPDATE listings SET last_checked_at=? WHERE id=?", (ts, r["id"]))
    # Neověřené a dlouho neviděné: z aktivních pryč, ale ne do archivu (o prodeji nic nevíme)
    cutoff = (datetime.now(timezone.utc) - timedelta(days=STALE_DAYS)).isoformat()
    stale = con.execute(
        """UPDATE listings SET status='gone', gone_at=? WHERE status='active'
           AND MAX(COALESCE(last_seen,''), COALESCE(last_checked_at,'')) < ?""",
        (ts, cutoff),
    ).rowcount
    con.commit()
    print(f"  >> Prodano/stazeno: {sold}, vyrazeno jako neoverene: {stale}")


# ── Srovnávací ceny a skóre ────────────────────────────────────────────────────

def row_dict(r: sqlite3.Row) -> dict:
    d = dict(r)
    d["images"] = json.loads(d.get("images") or "[]")
    d["extras"] = json.loads(d.get("extras") or "[]")
    d["ppm2"] = round(d["price"] / d["area"]) if d.get("area") else 0
    return d


def is_valid(l: dict) -> bool:
    """Inzeráty, které do srovnání ani do feedu nepatří."""
    seg = l["seg"]
    if l["is_rental"] or l["is_share"] or l["is_auction"]:
        return False
    if l["price"] < MIN_PRICE.get(seg, 200_000) or l["area"] > MAX_AREA.get(seg, 5000):
        return False
    if seg == "byt" and l["dgroup"].isdigit() and l["area"] > 80 * int(l["dgroup"]) + 80:
        return False   # 3+kk o 3722 m² je překlep
    return l["ppm2"] > 0


def bench_keys(l: dict) -> list[tuple[str, str, str, str]]:
    """Úrovně srovnání od nejužší: (úroveň, klíč, segment, dispozice)."""
    seg, dg = l["seg"], l["dgroup"] if l["seg"] == "byt" else ""
    loc_levels = [("lokalita", l["city_key"]), ("okres", l["district"]), ("kraj", l["region"]), ("cr", "CZ")]
    if seg in ("rekreace", "ostatni"):
        loc_levels = loc_levels[1:]
    keys = []
    for level, key in loc_levels:
        if not key:
            continue
        if dg:
            keys.append((level, key, seg, dg))
        keys.append((level, key, seg, ""))
    return keys


def build_benchmarks(listings: list[dict]) -> dict[tuple, tuple[int, int, float]]:
    """{úroveň: (medián ceny/m², počet, medián plochy)}"""
    buckets: dict[tuple, list[tuple[int, float]]] = defaultdict(list)
    for l in listings:
        for k in bench_keys(l):
            buckets[k].append((l["ppm2"], l["area"]))
    return {
        k: (round(statistics.median(p for p, _ in v)), len(v), statistics.median(a for _, a in v))
        for k, v in buckets.items()
    }


LEVEL_LABEL = {"lokalita": "{key}", "okres": "okres {key}", "kraj": "{key}", "cr": "celá ČR"}
SEG_LABEL = {"byt": "byty", "dum": "domy", "rekreace": "chaty a chalupy", "ostatni": "ostatní domy"}


def pick_benchmark(l: dict, bench: dict) -> dict | None:
    for k in bench_keys(l):
        med, n, med_area = bench.get(k, (0, 0, 0))
        if n >= BENCH_MIN_N and med > 0:
            size_adj = 1.0
            if med_area and l["area"]:
                size_adj = (l["area"] / med_area) ** -SIZE_ELASTICITY.get(l["seg"], 0.2)
                size_adj = min(1.3, max(0.7, size_adj))
            level, key, seg, dg = k
            conf_key = level
            if level == "lokalita" and n < 20:
                conf_key = "lokalita_maly"
            if level == "okres" and key == l["city_key"]:
                conf_key = "lokalita" if n >= 20 else "lokalita_maly"   # velká města: okres = městská část
            label = LEVEL_LABEL[level].format(key=key)
            what = f"{SEG_LABEL[seg]} {dg}+" if dg else SEG_LABEL[seg]
            return {"ppm2": round(med * size_adj), "market_ppm2": med, "size_adj": round(size_adj, 2),
                    "typical_area": round(med_area), "n": n, "level": level,
                    "label": f"{what}, {label}", "confidence": CONFIDENCE[conf_key]}
    return None


def days_between(a: str | None, b: datetime) -> int | None:
    if not a:
        return None
    try:
        d = datetime.fromisoformat(a[:19]) if "T" in a else datetime.strptime(a[:10], "%Y-%m-%d")
    except ValueError:
        return None
    return max(0, (b.replace(tzinfo=None) - d.replace(tzinfo=None)).days)


def fmt_kc(n: int) -> str:
    return f"{n:,}".replace(",", " ") + " Kč"


def score_listing(l: dict, bench: dict, price_max: dict, relisted_price: dict) -> None:
    """
    Skóre výhodnosti 0–100:
      cena pod srovnávací cenou  až 70 b. (40 % pod trhem = plný počet)
      zlevnění                   až 15 b. (15 % a víc)
      přímo od majitele             5 b.
      na trhu 90+ dní               5 b. (prostor k vyjednávání)
      znovu vložený inzerát         5 b.
    × jistota srovnání (vzorek, úroveň lokality) × koeficienty za nesrovnatelnou cenu.
    """
    now = datetime.now(timezone.utc)
    b = pick_benchmark(l, bench)
    l["benchmark"] = b
    discount = (1 - l["ppm2"] / b["ppm2"]) if b else 0.0
    l["discount"] = round(discount * 100, 1)

    if b and l["ppm2"] < b["ppm2"] * SUSPICIOUS_RATIO:
        l["suspicious"] = True
        return

    signals, penalties = [], []
    pts_price = max(0.0, min(discount / 0.40, 1.0)) * 70

    # Zlevnění: maximum z naší historie, původní cena ze Sreality a cena předchozího inzerátu
    ref = max(price_max.get(l["id"], 0), l.get("price_old") or 0, relisted_price.get(l["id"], 0))
    drop = (ref - l["price"]) / ref if ref > l["price"] else 0.0
    if drop < 0.01 and l.get("discount_flag"):
        signals.append({"code": "zlevneno", "label": "Zlevněno"})
    pts_drop = max(0.0, min(drop / 0.15, 1.0)) * 15
    if drop >= 0.01:
        l["price_drop"] = {"pct": round(drop * 100, 1), "from": ref}
        signals.append({"code": "zlevneno", "label": f"Zlevněno o {round(drop * 100)} % (z {fmt_kc(ref)})"})

    pts_private = 0
    if not l["is_rk"]:
        pts_private = 5
        signals.append({"code": "majitel", "label": "Přímo od majitele"})

    dom = days_between(l.get("listed_since"), now)
    if dom is None:
        dom = days_between(l.get("first_seen"), now)
    l["days_on_market"] = dom
    pts_dom = 0
    if dom is not None and dom >= 90:
        pts_dom = 5
        signals.append({"code": "dlouho", "label": f"Na trhu {dom} dní"})
    first_age = days_between(l.get("first_seen"), now)
    if first_age is not None and first_age <= 2 and (dom is None or dom <= 7):
        signals.append({"code": "nove", "label": "Nové v nabídce"})

    pts_relist = 0
    if l.get("relisted_from"):
        pts_relist = 5
        signals.append({"code": "znovu", "label": "Znovu vložený inzerát"})
    if l.get("has_tour"):
        signals.append({"code": "prohlidka", "label": "Video nebo 3D prohlídka"})

    factor = 1.0
    own = l.get("ownership") or ""
    if own == "Družstevní":
        factor *= PENALTIES["druzstevni"][0]
        penalties.append({"code": "druzstevni", "label": PENALTIES["druzstevni"][1], "factor": PENALTIES["druzstevni"][0]})
    elif own == "Státní/obecní":
        factor *= PENALTIES["obecni"][0]
        penalties.append({"code": "obecni", "label": PENALTIES["obecni"][1], "factor": PENALTIES["obecni"][0]})
    if any(e in ("Ve výstavbě", "Projekt") for e in l["extras"]):
        factor *= PENALTIES["vystavba"][0]
        penalties.append({"code": "vystavba", "label": PENALTIES["vystavba"][1], "factor": PENALTIES["vystavba"][0]})
    if discount > EXTREME_DISCOUNT:
        factor *= PENALTIES["extremni"][0]
        penalties.append({"code": "extremni", "label": PENALTIES["extremni"][1], "factor": PENALTIES["extremni"][0]})
    if not l.get("detail_at"):
        factor *= PENALTIES["bez_detailu"][0]
        penalties.append({"code": "bez_detailu", "label": PENALTIES["bez_detailu"][1], "factor": PENALTIES["bez_detailu"][0]})
    conf = b["confidence"] if b else 0.0
    if b and conf < 1.0:
        penalties.append({"code": "vzorek", "label": f"Srovnání: {b['label']} ({b['n']} inzerátů)", "factor": conf})

    raw = (pts_price + pts_drop + pts_private + pts_dom + pts_relist) * conf * factor
    l["raw_score"] = max(0.0, raw)
    l["deal_score"] = int(round(max(0.0, min(100.0, raw))))
    l["score_parts"] = {
        "body": round(max(0.0, raw), 1),
        "cena": round(pts_price, 1), "zlevneni": round(pts_drop, 1), "majitel": pts_private,
        "doba": pts_dom, "znovu": pts_relist, "jistota": conf, "koeficient": round(factor, 2),
    }
    l["signals"] = signals
    l["penalties"] = penalties


def calibrate(listings: list[dict]) -> None:
    """Převede body na skóre 0–100 podle pořadí v trhu (viz CALIBRATION)."""
    raws = sorted(l["raw_score"] for l in listings)
    if len(raws) < 200:
        return
    def at(q: float) -> float:
        return raws[min(len(raws) - 1, int(q * (len(raws) - 1)))]
    anchors = [(at(q), sc) for q, sc in CALIBRATION]
    for l in listings:
        r = l["raw_score"]
        out = anchors[-1][1]
        for (r0, s0), (r1, s1) in zip(anchors, anchors[1:]):
            if r <= r1:
                out = s0 if r1 <= r0 else s0 + (s1 - s0) * (r - r0) / (r1 - r0)
                break
        l["deal_score"] = int(round(max(0, min(100, out))))


def dedupe(listings: list[dict]) -> list[dict]:
    """Stejná nemovitost od víc realitek: nechá nejlevnější, ostatní připojí jako alternativy."""
    groups: dict[str, list[dict]] = defaultdict(list)
    for l in listings:
        groups[l["fingerprint"] or l["id"]].append(l)
    out = []
    for items in groups.values():
        items.sort(key=lambda x: (x["price"], -x.get("deal_score", 0)))
        main = items[0]
        if len(items) > 1:
            main["duplicates"] = len(items) - 1
            main["alt_urls"] = [x["url"] for x in items[1:4]]
            main.setdefault("signals", []).append({"code": "vicekrat", "label": f"Inzerováno {len(items)}×"})
        out.append(main)
    return out


# ── Výstupy ────────────────────────────────────────────────────────────────────

def to_feed_item(l: dict) -> dict:
    """Pole ze starého feedu zůstávají (web na ně spoléhá), nová jsou navíc."""
    b = l.get("benchmark") or {}
    return {
        "id": l["id"], "title": l["title"], "price": l["price"], "area": l["area"],
        "price_per_m2": l["ppm2"],
        "score": max(0.0, l.get("discount", 0.0)),          # % pod srovnávací cenou (starý význam)
        "median_price_per_m2": b.get("ppm2"),
        "disposition": l["disposition"], "disposition_group": l["dgroup"],
        "locality": l["locality"], "locality_city": l["city_key"],
        "type": l["type"], "transaction": "prodej",
        "ownership": l.get("ownership") or "", "building_type": l.get("building_type") or "",
        "extras": l["extras"], "detail_fetched": bool(l.get("detail_at")),
        "url": l["url"], "scraped_at": l.get("last_seen"), "listed_since": l.get("listed_since"),
        "last_checked_at": l.get("last_checked_at"),
        # nové
        "deal_score": l.get("deal_score", 0), "segment": l["seg"], "subtype": l["sub"],
        "city": l["city"], "district": l["district"], "region": l["region"],
        "lat": l.get("lat"), "lon": l.get("lon"),
        "images": l["images"][:3], "image_count": len(l["images"]),   # DB drží až 6, web potřebuje první
        "seller": "rk" if l["is_rk"] else "soukromy", "premise": l.get("premise") or "",
        "has_tour": bool(l.get("has_tour")), "first_seen": l.get("first_seen"),
        "days_on_market": l.get("days_on_market"), "price_drop": l.get("price_drop"),
        "benchmark": b or None, "signals": l.get("signals", []), "penalties": l.get("penalties", []),
        "score_parts": l.get("score_parts"), "duplicates": l.get("duplicates", 0),
        "alt_urls": l.get("alt_urls", []),
    }


def write_json(path: str, data) -> None:
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, separators=(",", ":"))
    os.replace(tmp, path)


def save_stats(con: sqlite3.Connection, bench: dict) -> None:
    d = today()
    con.execute("DELETE FROM stats WHERE date=?", (d,))
    con.executemany(
        "INSERT INTO stats (date, level, key, seg, dgroup, median, n) VALUES (?,?,?,?,?,?,?)",
        [(d, k[0], k[1], k[2], k[3], med, n) for k, (med, n, _) in bench.items() if n >= 3],
    )
    con.commit()


def write_price_history(con: sqlite3.Connection, out_dir: str) -> None:
    """Starý formát pro graf na /real/: { days: [ {date, cities: {město: {byty, domy, celkem}}} ] }"""
    path = os.path.join(out_dir, HISTORY_FILE)
    prev = load_json_file(path).get("days", [])
    rows = con.execute(
        "SELECT key, seg, median FROM stats WHERE date=? AND level='lokalita' AND dgroup='' AND seg IN ('byt','dum')",
        (today(),),
    ).fetchall()
    cities: dict[str, dict] = defaultdict(lambda: {"byty": None, "domy": None, "celkem": None})
    for r in rows:
        cities[r["key"]]["byty" if r["seg"] == "byt" else "domy"] = r["median"]
    for c in cities.values():
        vals = [v for v in (c["byty"], c["domy"]) if v]
        c["celkem"] = round(statistics.median(vals)) if vals else None
    cutoff = (datetime.now(timezone.utc) - timedelta(days=HISTORY_KEEP_DAYS)).strftime("%Y-%m-%d")
    days = [x for x in prev if x.get("date") != today() and x.get("date", "") >= cutoff]
    days.append({"date": today(), "cities": dict(cities)})
    days.sort(key=lambda x: x["date"])
    write_json(path, {"updated": now_iso(), "days": days})


def write_market_stats(con: sqlite3.Connection, out_dir: str) -> None:
    """
    Týdenní mediány ceny/m² za 2 roky pro grafy na stránkách měst a filtrů.
    series[klíč] = [[pondělí týdne, medián, počet], ...], klíč "úroveň|lokalita|segment|dispozice".
    """
    since = (datetime.now(timezone.utc) - timedelta(days=730)).strftime("%Y-%m-%d")
    rows = con.execute(
        "SELECT date, level, key, seg, dgroup, median, n FROM stats WHERE date>=? ORDER BY date", (since,)
    ).fetchall()
    weekly: dict[str, dict[str, list]] = defaultdict(lambda: defaultdict(list))
    for r in rows:
        d = datetime.strptime(r["date"], "%Y-%m-%d")
        week = (d - timedelta(days=d.weekday())).strftime("%Y-%m-%d")
        weekly[f'{r["level"]}|{r["key"]}|{r["seg"]}|{r["dgroup"]}'][week].append((r["median"], r["n"]))
    series = {}
    for key, weeks in weekly.items():
        pts = [[w, round(statistics.median(m for m, _ in v)), max(n for _, n in v)] for w, v in sorted(weeks.items())]
        if pts and pts[-1][2] >= 5:
            series[key] = pts
    write_json(os.path.join(out_dir, STATS_FILE), {"updated": now_iso(), "series": series})


# ── Hlavní funkce ──────────────────────────────────────────────────────────────

def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--mode", choices=["quick", "full"], default="quick")
    ap.add_argument("--db", default="agregator.sqlite")
    ap.add_argument("--out", default=".")
    ap.add_argument("--details", type=int, default=None, help="kolik detailů stáhnout (přepíše výchozí)")
    ap.add_argument("--offline", action="store_true", help="nic nestahovat, jen přepočítat skóre a výstupy z databáze")
    args = ap.parse_args()

    print("=" * 60)
    print(f"Sreality scraper v2, rezim {args.mode}, {datetime.now():%Y-%m-%d %H:%M:%S}")
    print("=" * 60)

    con = open_db(args.db)
    bootstrap_from_feed(con, args.out)
    ts = now_iso()

    if args.offline:
        seen = {r[0] for r in con.execute("SELECT id FROM listings WHERE status='active'")}
        last = con.execute("SELECT total_reported FROM runs ORDER BY started DESC LIMIT 1").fetchone()
        total_reported = last[0] if last else 0
    try:
        if args.offline:
            raise StopIteration
        build_id = get_build_id()
        if not build_id:
            print("[VAROVANI] buildId se nepodarilo zjistit, jedu pres HTML fallback")
        seen, total_reported, complete = crawl(con, args.mode, build_id, ts)
    except StopIteration:
        complete = False
    except SourceDown as e:
        con.rollback()
        print(f"\n[FATAL] Zdroj neodpovida ({e}). Nic se nezapisuje.")
        sys.exit(1)

    print(f"\nVideno ve vypisu: {len(seen)} (hlaseno {total_reported}), kompletni full beh: {complete}")
    if len(seen) < MIN_SCRAPED_OK:
        con.rollback()
        print(f"[FATAL] Stazeno jen {len(seen)} inzeratu (minimum {MIN_SCRAPED_OK}). Feed zustava beze zmeny.")
        sys.exit(1)

    if not args.offline:
        con.execute("INSERT INTO runs VALUES (?,?,?,?,?,?)", (ts, args.mode, len(seen), total_reported, int(complete), ""))
        con.commit()

    try:
        if args.offline:
            raise StopIteration
        # Předběžné skóre jen kvůli pořadí, v jakém se stahují detaily
        active = [row_dict(r) for r in con.execute("SELECT * FROM listings WHERE status='active'")]
        valid = [l for l in active if is_valid(l)]
        bench = build_benchmarks(valid)
        prelim = {}
        for l in valid:
            b = pick_benchmark(l, bench)
            prelim[l["id"]] = (1 - l["ppm2"] / b["ppm2"]) if b else 0
        enrich_details(con, args.details if args.details is not None else DETAILS_BUDGET[args.mode], prelim)
        update_archive(con, seen, args.mode, complete, ARCHIVE_BUDGET[args.mode])
    except StopIteration:
        pass
    except SourceDown as e:
        print(f"\n[VAROVANI] {e}, detaily a archiv dokoncim pristi beh")
        con.commit()

    # Finální skóre z celého aktivního trhu
    active = [row_dict(r) for r in con.execute("SELECT * FROM listings WHERE status='active'")]
    valid = [l for l in active if is_valid(l)]
    bench = build_benchmarks(valid)
    save_stats(con, bench)

    price_max = {r[0]: r[1] for r in con.execute("SELECT id, MAX(price) FROM prices GROUP BY id")}
    relisted_price = {
        r[0]: r[1] for r in con.execute(
            "SELECT a.id, b.price FROM listings a JOIN listings b ON b.id=a.relisted_from WHERE a.status='active'"
        )
    }
    for l in valid:
        score_listing(l, bench, price_max, relisted_price)
    suspicious = [l for l in valid if l.get("suspicious")]
    scored = [l for l in valid if not l.get("suspicious") and l.get("benchmark")]
    calibrate(scored)
    scored = dedupe(scored)
    scored.sort(key=lambda l: (l.get("deal_score", 0), l.get("discount", 0)), reverse=True)

    # Feed: nejvýhodnější + vše nové z posledních 2 dnů, aby web ukazoval i čerstvé inzeráty
    fresh_cut = (datetime.now(timezone.utc) - timedelta(days=2)).isoformat()
    top = scored[:MAX_FEED_SIZE]
    top_ids = {l["id"] for l in top}
    fresh = [l for l in scored if l["id"] not in top_ids and (l.get("first_seen") or "") >= fresh_cut]
    room = max(0, MAX_FEED_SIZE + 500 - len(top))
    feed_items = [to_feed_item(l) for l in top + fresh[:room]]

    write_json(os.path.join(args.out, OUTPUT_FILE), {
        "updated": now_iso(), "version": 2, "mode": args.mode,
        "total_scraped": len(seen), "total_active": len(active), "total_market": total_reported,
        "excluded": len(active) - len(valid), "suspicious": len(suspicious),
        "total_in_feed": len(feed_items), "listings": feed_items,
    })

    arch_cut = (datetime.now(timezone.utc) - timedelta(days=ARCHIVE_KEEP_DAYS)).isoformat()
    sold = [row_dict(r) for r in con.execute(
        "SELECT * FROM listings WHERE status='sold' AND gone_at>=? ORDER BY gone_at DESC LIMIT 2000", (arch_cut,)
    )]
    arch_items = []
    for l in sold:
        l.setdefault("discount", 0.0)
        item = to_feed_item(l)
        item["sold_at"] = l.get("gone_at")
        arch_items.append(item)
    write_json(os.path.join(args.out, ARCHIVE_FILE), {"updated": now_iso(), "total": len(arch_items), "listings": arch_items})

    write_price_history(con, args.out)
    write_market_stats(con, args.out)
    con.close()

    print(f"\nAktivnich: {len(active)}, vyrazeno nesmyslnych: {len(active) - len(valid)}, podezrelych cen: {len(suspicious)}")
    print(f"Feed: {len(feed_items)} inzeratu, skore 60+: {sum(1 for l in scored if l['deal_score'] >= 60)}, 80+: {sum(1 for l in scored if l['deal_score'] >= 80)}")
    print(f"Archiv: {len(arch_items)}")
    print("=" * 60)


if __name__ == "__main__":
    main()
