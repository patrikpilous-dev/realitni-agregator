"""
Sreality.cz scraper — Agregátor realitních nabídek
Stahuje inzeráty z Sreality API, počítá score výhodnosti, ukládá feed.json
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
OUTPUT_FILE = "feed.json"

# Kolik stránek stáhnout na kategorii (60 inzerátů/stránka)
# 5 stránek = 300 inzerátů na kategorii
MAX_PAGES = 5

# Výstup: top N inzerátů dle score výhodnosti
TOP_N = 200

# Minimální plocha v m² (filtrujeme nesmyslné záznamy)
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

# Kategorie ke stažení: (category_main_cb, category_type_cb, popis)
# 1=byty, 2=domy | 1=prodej, 2=pronájem
CATEGORIES = [
    (1, 1, "byty-prodej"),
    (2, 1, "domy-prodej"),
]

# ── Pomocné funkce ─────────────────────────────────────────────────────────────

def api_get(params: dict) -> dict | None:
    """Zavolá Sreality API a vrátí JSON nebo None při chybě."""
    url = API_BASE + "?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers=HEADERS)
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        print(f"  [CHYBA] {e} — {url}")
        return None


def parse_area(name: str) -> float | None:
    """Extrahuje plochu z názvu inzerátu (např. '65 m²' nebo '65 m2')."""
    match = re.search(r"(\d+(?:[.,]\d+)?)\s*m[²2]", name, re.IGNORECASE)
    if match:
        return float(match.group(1).replace(",", "."))
    return None


def parse_disposition(name: str) -> str:
    """Extrahuje dispozici z názvu (2+1, 3+kk, atd.)."""
    match = re.search(r"(\d+\+(?:kk|\d+))", name, re.IGNORECASE)
    if match:
        return match.group(1).lower()
    return "ostatní"


def build_sreality_url(estate: dict) -> str:
    """Sestaví URL na detail inzerátu na sreality.cz."""
    seo = estate.get("seo", {})
    hash_id = estate.get("hash_id", "")
    locality = seo.get("locality", "")
    cat_main = seo.get("category_main_cb", 1)
    cat_type = seo.get("category_type_cb", 1)
    cat_sub = seo.get("category_sub_cb", 2)

    type_map = {1: "prodej", 2: "pronajem"}
    main_map = {1: "byt", 2: "dum"}
    sale_type = type_map.get(cat_type, "prodej")
    main_type = main_map.get(cat_main, "byt")

    return (
        f"https://www.sreality.cz/detail/{sale_type}/{main_type}/"
        f"{locality}/{hash_id}"
    )


# ── Scraping ───────────────────────────────────────────────────────────────────

def fetch_category(cat_main: int, cat_type: int, label: str) -> list[dict]:
    """Stáhne všechny stránky dané kategorie a vrátí surová data."""
    raw_estates = []
    print(f"\n[{label}] Stahování...")

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
        print(f"  Stránka {page}: +{len(estates)} inzerátů (celkem dostupných: {total})")

        if len(estates) < 60:
            break  # poslední stránka

        time.sleep(0.5)  # slušné čekání mezi požadavky

    print(f"  >> Stazeno celkem: {len(raw_estates)} zaznamu")
    return raw_estates


def process_estate(estate: dict, cat_main: int, cat_type: int) -> dict | None:
    """Zpracuje jeden inzerát, vrátí strukturovaný dict nebo None."""
    name = estate.get("name", "")
    price = estate.get("price_czk", {}).get("value_raw") or estate.get("price")
    locality = estate.get("locality", "")

    # Filtrujeme inzeráty bez ceny nebo lokality
    if not price or price <= 0 or not locality:
        return None

    area = parse_area(name)
    if area is None or area < MIN_AREA:
        return None

    price_per_m2 = round(price / area)
    disposition = parse_disposition(name)

    type_label = "byt" if cat_main == 1 else "dům"
    transaction = "prodej" if cat_type == 1 else "pronájem"

    return {
        "id": str(estate.get("hash_id", "")),
        "title": name,
        "price": int(price),
        "area": round(area, 1),
        "price_per_m2": price_per_m2,
        "score": 0,  # bude doplněno po výpočtu mediánů
        "disposition": disposition,
        "locality": locality,
        "type": type_label,
        "transaction": transaction,
        "url": build_sreality_url(estate),
    }


# ── Výpočet score výhodnosti ───────────────────────────────────────────────────

def compute_scores(listings: list[dict]) -> list[dict]:
    """
    Vypočítá score výhodnosti pro každý inzerát.
    Score = % pod mediánem ceny/m² pro stejnou (dispozice + lokalita).
    Kladné score = výhodné, záporné = předražené.
    """
    # Seskupíme ceny/m² podle (dispozice, lokalita)
    groups: dict[tuple, list[float]] = defaultdict(list)
    for listing in listings:
        key = (listing["disposition"], listing["locality"])
        groups[key].append(listing["price_per_m2"])

    # Mediány
    medians: dict[tuple, float] = {}
    for key, prices in groups.items():
        if len(prices) >= 3:  # potřebujeme alespoň 3 záznamy pro smysluplný medián
            medians[key] = statistics.median(prices)

    # Přiřadíme score
    for listing in listings:
        key = (listing["disposition"], listing["locality"])
        median = medians.get(key)
        if median and median > 0:
            # Kladné score = inzerát je X % pod mediánem (výhodný)
            score = round((1 - listing["price_per_m2"] / median) * 100, 1)
            listing["score"] = score
        else:
            listing["score"] = 0  # nedostatek dat pro porovnání

    return listings


# ── Hlavní funkce ──────────────────────────────────────────────────────────────

def main():
    print("=" * 60)
    print("Sreality.cz scraper — spuštění")
    print(f"Čas: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print("=" * 60)

    all_listings = []

    for cat_main, cat_type, label in CATEGORIES:
        raw = fetch_category(cat_main, cat_type, label)
        for estate in raw:
            processed = process_estate(estate, cat_main, cat_type)
            if processed:
                all_listings.append(processed)

    print(f"\nZpracováno inzerátů: {len(all_listings)}")

    # Výpočet score
    all_listings = compute_scores(all_listings)

    # Seřadit dle score sestupně, vzít top N
    all_listings.sort(key=lambda x: x["score"], reverse=True)
    top_listings = all_listings[:TOP_N]

    výhodných = sum(1 for l in top_listings if l["score"] > 5)
    print(f"Výhodných inzerátů (score > 5 %): {výhodných}")

    # Uložit feed.json
    feed = {
        "updated": datetime.now(timezone.utc).isoformat(),
        "total_scraped": len(all_listings),
        "total_in_feed": len(top_listings),
        "listings": top_listings,
    }

    with open(OUTPUT_FILE, "w", encoding="utf-8") as f:
        json.dump(feed, f, ensure_ascii=False, indent=2)

    print(f"\nUloženo do: {OUTPUT_FILE}")
    top_title = top_listings[0]['title'] if top_listings else "zadny"
    print(f"Top inzerat: {top_title.encode('ascii', errors='replace').decode()}")
    print("=" * 60)


if __name__ == "__main__":
    main()
