"""
Sreality.cz scraper — Agregátor realitních nabídek
Stahuje inzeráty z Sreality API, počítá score výhodnosti, ukládá feed.json + archived.json
"""

import json
import re
import time
import statistics
from datetime import datetime, timezone
from collections import defaultdict
import urllib.request
import urllib.parse

# ── Konfigurace ────────────────────────────────────────────────────────────────

API_BASE = "https://www.sreality.cz/api/cs/v2/estates"
OUTPUT_FILE  = "feed.json"
ARCHIVE_FILE = "archived.json"

# Kolik stránek stáhnout na kategorii (60 inzerátů/stránka)
MAX_PAGES = 5

# Výstup: top N inzerátů dle score výhodnosti
TOP_N = 200

# Minimální plocha v m²
MIN_AREA = 15

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) "
        "Chrome/122.0.0.0 Safari/537.36"
    ),
    "Accept": "application/json",
    "Referer": "https://www.sreality.cz/",
}

# Kategorie: (category_main_cb, category_type_cb, popis)
CATEGORIES = [
    (1, 1, "byty-prodej"),
    (2, 1, "domy-prodej"),
]

# ── URL mappingy (pro správnou sestavu odkazu na Sreality) ─────────────────────

TYPE_URL = {1: "prodej", 2: "pronajem", 3: "drazby"}
MAIN_URL = {1: "byt", 2: "dum", 3: "pozemek", 4: "komercni", 5: "ostatni"}

# Subkategorie → URL slug (chybějící subcategory způsobuje 404!)
SUB_URL = {
    # Byty
    2:  "1%2Bkk",   # 1+kk
    3:  "1%2B1",    # 1+1
    4:  "2%2Bkk",   # 2+kk
    5:  "2%2B1",    # 2+1
    6:  "3%2Bkk",   # 3+kk
    7:  "3%2B1",    # 3+1
    8:  "4%2Bkk",   # 4+kk
    9:  "4%2B1",    # 4+1
    10: "5-a-vice",
    11: "atypicky",
    16: "pokoj",
    # Domy
    37: "rodinny-dum",
    38: "vila",
    39: "chalupa-chata",
    40: "bytovy-dum",
    41: "zemedelska-usedlost",
    43: "ostatni",
    # Pozemky
    52: "bydleni",
    53: "komercni",
    54: "smiseny",
    55: "les",
    56: "rybnik",
    57: "ostatni",
}

# ── Pomocné funkce ─────────────────────────────────────────────────────────────

def api_get(params: dict) -> dict | None:
    url = API_BASE + "?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers=HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        print(f"  [CHYBA] {e}")
        return None


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


def locality_to_city(locality: str) -> str:
    """
    Extrahuje město z lokality.
    Formáty Sreality:
      "Ulice, Praha 5 - Stodůlky"       → "Praha 5"
      "Kralupy nad Vltavou - Minice, okres Mělník" → "Kralupy nad Vltavou"
      "Město, Část - Podčást"            → "Město"
    """
    if not locality:
        return ""
    parts = [p.strip() for p in locality.split(",")]
    last = parts[-1]
    if re.match(r"^okres\s+", last, re.IGNORECASE) and len(parts) > 1:
        # "Město - Část, okres X" → bereme část před čárkou
        city = parts[-2]
    else:
        # "Ulice, Město - Část" → bereme část po čárce
        city = last
    # Odstraň podčást za " - "
    city = city.split(" - ")[0].strip()
    return city


# labelsAll obsahuje kódy (ne česky) — mapujeme na srozumitelné hodnoty
LABEL_OWNERSHIP = {
    "personal":    "Osobní",
    "cooperative": "Družstevní",
    "state":       "Státní/obecní",
}
LABEL_BUILDING = {
    "brick":   "Cihlová",
    "panel":   "Panelová",
    "wooden":  "Dřevostavba",
    "prefab":  "Montovaná",
}
LABEL_EXTRAS = {
    "elevator":      "Výtah",
    "balcony":       "Balkón",
    "loggia":        "Lodžie",
    "terrace":       "Terasa",
    "garage":        "Garáž",
    "parking_lots":  "Parkování",
    "cellar":        "Sklep",
    "garden":        "Zahrada",
    "pool":          "Bazén",
    "new_building":  "Novostavba",
    "furnished":     "Zařízeno",
    "partly_furnished": "Částečně zařízeno",
    "air_conditioning": "Klimatizace",
}


def extract_labels(estate: dict) -> dict:
    """Extrahuje doplňkové informace z labelsAll (kódové hodnoty Sreality API)."""
    info = {
        "ownership":     "",
        "building_type": "",
        "extras":        [],
    }

    # labelsAll je pole polí: první pole = vlastnosti nemovitosti, druhé = POI okolí
    labels_raw = estate.get("labelsAll", [])
    flat_codes: list[str] = []
    for group in labels_raw:
        if isinstance(group, list):
            for item in group:
                if isinstance(item, str):
                    flat_codes.append(item)
                elif isinstance(item, dict):
                    flat_codes.append(item.get("name", ""))
        elif isinstance(group, str):
            flat_codes.append(group)

    extras_found = []
    for code in flat_codes:
        if code in LABEL_OWNERSHIP and not info["ownership"]:
            info["ownership"] = LABEL_OWNERSHIP[code]
        if code in LABEL_BUILDING and not info["building_type"]:
            info["building_type"] = LABEL_BUILDING[code]
        if code in LABEL_EXTRAS:
            val = LABEL_EXTRAS[code]
            if val not in extras_found:
                extras_found.append(val)

    info["extras"] = extras_found
    return info


def build_sreality_url(estate: dict) -> str:
    """Sestaví správnou URL na detail inzerátu včetně subkategorie."""
    seo      = estate.get("seo", {})
    hash_id  = estate.get("hash_id", "")
    locality = seo.get("locality", "")
    cat_main = seo.get("category_main_cb", 1)
    cat_type = seo.get("category_type_cb", 1)
    cat_sub  = seo.get("category_sub_cb", 0)

    sale_type = TYPE_URL.get(cat_type, "prodej")
    main_type = MAIN_URL.get(cat_main, "byt")
    sub_type  = SUB_URL.get(cat_sub, "")

    if sub_type:
        return f"https://www.sreality.cz/detail/{sale_type}/{main_type}/{sub_type}/{locality}/{hash_id}"
    else:
        return f"https://www.sreality.cz/detail/{sale_type}/{main_type}/{locality}/{hash_id}"


# ── Scraping ───────────────────────────────────────────────────────────────────

def fetch_category(cat_main: int, cat_type: int, label: str) -> list[dict]:
    """Stáhne stránky dané kategorie, nejnovější inzeráty jako první."""
    raw_estates = []
    print(f"\n[{label}] Stahuji...")

    for page in range(1, MAX_PAGES + 1):
        params = {
            "category_main_cb": cat_main,
            "category_type_cb": cat_type,
            "per_page": 60,
            "page": page,
        }
        data = api_get(params)
        if not data:
            break

        estates = data.get("_embedded", {}).get("estates", [])
        if not estates:
            break

        raw_estates.extend(estates)
        total = data.get("result_size", "?")
        print(f"  Strana {page}: +{len(estates)} (dostupnych: {total})")

        if len(estates) < 60:
            break

        time.sleep(0.5)

    print(f"  >> Celkem: {len(raw_estates)}")
    return raw_estates


def process_estate(estate: dict, cat_main: int, cat_type: int) -> dict | None:
    name     = estate.get("name", "")
    price    = estate.get("price_czk", {}).get("value_raw") or estate.get("price")
    locality = estate.get("locality", "")

    # Filtruj bez ceny, nulové, nebo "na vyžádání" (Sreality vrací 1 Kč)
    if not price or price <= 1 or not locality:
        return None

    area = parse_area(name)
    if area is None or area < MIN_AREA:
        return None

    price_per_m2      = round(price / area)
    disposition       = parse_disposition(name)
    disposition_group = parse_disposition_group(name)
    labels            = extract_labels(estate)

    type_label  = "byt" if cat_main == 1 else "dům"
    transaction = "prodej" if cat_type == 1 else "pronájem"
    city        = locality_to_city(locality)

    return {
        "id":                 str(estate.get("hash_id", "")),
        "title":              name,
        "price":              int(price),
        "area":               round(area, 1),
        "price_per_m2":       price_per_m2,
        "score":              0,
        "median_price_per_m2": None,
        "disposition":        disposition,
        "disposition_group":  disposition_group,
        "locality":           locality,
        "locality_city":      city,
        "type":               type_label,
        "transaction":        transaction,
        "ownership":          labels["ownership"],
        "building_type":      labels["building_type"],
        "extras":             labels["extras"],
        "url":                build_sreality_url(estate),
        "scraped_at":         datetime.now(timezone.utc).isoformat(),
    }


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


# ── Hlavní funkce ──────────────────────────────────────────────────────────────

def main():
    print("=" * 60)
    print("Sreality scraper")
    print(f"Cas: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)

    # Načteme předchozí feed (pro archiv)
    prev_feed     = load_json_file(OUTPUT_FILE)
    prev_listings = prev_feed.get("listings", [])
    prev_archived = load_json_file(ARCHIVE_FILE).get("listings", [])
    prev_arch_ids = {l["id"] for l in prev_archived}

    # Scraping
    all_listings = []
    for cat_main, cat_type, label in CATEGORIES:
        raw = fetch_category(cat_main, cat_type, label)
        for estate in raw:
            processed = process_estate(estate, cat_main, cat_type)
            if processed:
                all_listings.append(processed)

    print(f"\nZpracovano: {len(all_listings)} inzeratu")

    # Score
    all_listings = compute_scores(all_listings)

    # Deduplikace (stejné ID z různých stránek)
    seen = set()
    unique = []
    for l in all_listings:
        if l["id"] not in seen:
            seen.add(l["id"])
            unique.append(l)
    all_listings = unique

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
            del merged[l["id"]]  # vyřaď prodané z feedu

    print(f"Nove archivovano (prodano): {len(newly_archived)}")
    print(f"Celkem v archivu: {len(prev_archived)}")

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

    print(f"\nUlozeno: {OUTPUT_FILE} ({len(top_listings)} inzeratu)")
    print(f"Ulozeno: {ARCHIVE_FILE} ({len(prev_archived)} prodanych)")
    print("=" * 60)


if __name__ == "__main__":
    main()
