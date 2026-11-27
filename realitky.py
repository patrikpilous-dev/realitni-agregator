"""
Přehled realitních kanceláří pro max-reality.cz/realitni-kancelare/ (od 27. 11. 2026).

Z inzerátů v databázi scraperu počítá, jak realitka skutečně pracuje na trhu:
kde a co nabízí, jak rychle inzeráty mizí, jak často zlevňuje, jak nacení proti trhu,
jak často inzerát vloží znovu. Výstup realitky.json čte shortcode na webu.

Jen realitky (firmy). Jména a kontakty makléřů zůstávají v databázi a do JSONu nejdou,
JSON je veřejný na GitHubu. Ven jde jen počet makléřů realitky.

Metriky se počítají jen ze vzorku aspoň MIN_N inzerátů, jinak null.
Stažený inzerát nemusí být prodaný (mohl být zrušený), web to uvádí v metodice.
"""

import json
import os
import statistics
from collections import Counter, defaultdict
from datetime import datetime, timedelta, timezone

OUT_FILE = "realitky.json"
WINDOW_DAYS = 365      # okno pro stažené inzeráty
MIN_N = 5              # minimální vzorek pro metriku
MIN_LISTINGS = 3       # realitka do přehledu od tolika inzerátů (aktivní + stažené)
CITY_MIN_AGENCIES = 3  # město do žebříčku od tolika realitek
CITY_TOP = 40


def _date(s):
    if not s:
        return None
    try:
        d = datetime.fromisoformat(str(s)[:19])
    except ValueError:
        try:
            d = datetime.strptime(str(s)[:10], "%d.%m.%Y")
        except ValueError:
            return None
    return d.replace(tzinfo=timezone.utc) if d.tzinfo is None else d


def _days(a, b):
    da, db = _date(a), _date(b)
    if not da or not db:
        return None
    n = (db - da).days
    return n if 0 <= n <= 3650 else None


def _med(xs):
    xs = [x for x in xs if x is not None]
    return statistics.median(xs) if len(xs) >= MIN_N else None


def _share(flags):
    flags = [f for f in flags if f is not None]
    return round(100 * sum(1 for f in flags if f) / len(flags)) if len(flags) >= MIN_N else None


def _r(x, nd=0):
    if x is None:
        return None
    return round(x) if nd == 0 else round(x, nd)


def write_realitky(con, valid: list[dict], scored: list[dict], out_dir: str) -> None:
    now = datetime.now(timezone.utc)
    cutoff = (now - timedelta(days=WINDOW_DAYS)).isoformat()

    # Cenová historie: nejvyšší a poslední cena inzerátu
    pmax, plast = {}, {}
    for r in con.execute("SELECT id, date, price FROM prices ORDER BY id, date"):
        pmax[r[0]] = max(pmax.get(r[0], 0), r[2])
        plast[r[0]] = r[2]

    # Stažené inzeráty realitek za posledních 365 dní
    sold = [dict(r) for r in con.execute(
        """SELECT id, seg, city, region, premise, listed_since, first_seen, gone_at, price, price_old,
                  relisted_from, has_tour, exclusive, broker_id
           FROM listings WHERE status='sold' AND is_rk=1 AND premise<>'' AND gone_at>=?""", (cutoff,))]
    # Jen s datem vložení z detailu. first_seen začíná u starších inzerátů až založením databáze (4. 10. 2026)
    # a dobu by zkracoval.
    for s in sold:
        s["dom"] = _days(s["listed_since"], s["gone_at"]) if s["listed_since"] else None

    # Doba na trhu do stažení, medián trhu po segmentu a kraji (pro index rychlosti)
    mk = defaultdict(list)
    for s in sold:
        if s["dom"] is not None:
            mk[(s["seg"], s["region"])].append(s["dom"])
            mk[(s["seg"], "")].append(s["dom"])
    market_dom = {k: statistics.median(v) for k, v in mk.items() if len(v) >= 20}

    def ref_dom(s):
        return market_dom.get((s["seg"], s["region"])) or market_dom.get((s["seg"], ""))

    def dropped(lid, price, price_old):
        ref = max(pmax.get(lid, 0), price_old or 0)
        cur = plast.get(lid, price)
        return ((ref - cur) / ref) if ref and cur and ref > cur else 0.0

    extra = {r["id"]: dict(r) for r in con.execute(
        "SELECT id, exclusive, broker_id FROM listings WHERE status='active' AND is_rk=1")}

    ag = defaultdict(lambda: {"act": [], "sold": []})
    for l in valid:
        if l.get("is_rk") and l.get("premise"):
            ag[l["premise"]]["act"].append(l)
    for s in sold:
        ag[s["premise"]]["sold"].append(s)

    info = {}
    for r in con.execute("SELECT * FROM agencies"):
        info[r["seo"]] = dict(r)
    brokers = Counter()
    for r in con.execute(
            "SELECT premise, COUNT(DISTINCT broker_id) FROM listings WHERE broker_id IS NOT NULL "
            "AND (status='active' OR gone_at>=?) GROUP BY premise", (cutoff,)):
        brokers[r[0]] = r[1]

    out, cities = {}, defaultdict(list)
    for seo, d in ag.items():
        act, sl = d["act"], d["sold"]
        if len(act) + len(sl) < MIN_LISTINGS:
            continue
        a = info.get(seo, {})
        both = act + sl
        drops = [dropped(x["id"], x["price"], x.get("price_old")) for x in both]
        drop_flags = [dd >= 0.01 for dd in drops]
        drop_sizes = [dd * 100 for dd in drops if dd >= 0.01]
        vs_trh = [-x["discount"] for x in act if x.get("benchmark") and x.get("discount") is not None]
        doms = [s["dom"] for s in sl]
        idx = [s["dom"] / ref_dom(s) for s in sl if s["dom"] is not None and ref_dom(s)]
        excl = [extra.get(x["id"], x).get("exclusive") for x in both]
        seg = Counter(x["seg"] for x in act)
        cc = Counter(x["city"] for x in both if x.get("city"))
        cc_act = Counter(x["city"] for x in act if x.get("city"))
        rec = {
            "id": a.get("id"),
            "nazev": a.get("name") or None,
            "ico": a.get("ico"),
            "web": a.get("web") or None,
            "sidlo": a.get("city") or None,
            "recenze": {"n": a.get("review_count"), "skore": a.get("review_score")} if a.get("review_count") else None,
            "makleru": brokers.get(seo) or None,
            "aktivni": len(act),
            "stazeno_12m": len(sl),
            "segmenty": dict(seg),
            "mesta": cc.most_common(6),
            "cena_median": _r(_med([x["price"] for x in act])),
            "cena_vs_trh": _r(_med(vs_trh), 1),
            "zlevneno_podil": _share(drop_flags),
            "zlevneni_prumer": _r(statistics.mean(drop_sizes), 1) if len(drop_sizes) >= MIN_N else None,
            "doba_median": _r(_med(doms)),
            "doba_index": _r(_med(idx), 2),
            "znovu_vlozeno_podil": _share([bool(x.get("relisted_from")) for x in both]),
            "prohlidka_podil": _share([bool(x.get("has_tour")) for x in act]),
            "exkluzivni_podil": _share(excl),
        }
        out[seo] = rec
        for city, n in cc.items():
            cities[city].append([seo, cc_act.get(city, 0), n])

    mesta = {}
    for city, rows in cities.items():
        rows = [r for r in rows if r[2] >= 2]
        if len(rows) >= CITY_MIN_AGENCIES:
            rows.sort(key=lambda r: (r[2], r[1]), reverse=True)
            mesta[city] = rows[:CITY_TOP]

    all_drop = [dropped(x["id"], x["price"], x.get("price_old")) >= 0.01 for x in valid if x.get("is_rk")]
    data = {
        "updated": now.isoformat(timespec="seconds"),
        "metodika": {"okno_dni": WINDOW_DAYS, "min_vzorek": MIN_N, "min_inzeratu": MIN_LISTINGS},
        "trh": {
            "realitek": len(out),
            "aktivnich_rk": sum(1 for x in valid if x.get("is_rk")),
            "zlevneno_podil": round(100 * sum(all_drop) / len(all_drop)) if all_drop else None,
            "doba_median": {k[0]: round(v) for k, v in market_dom.items() if k[1] == ""},
        },
        "realitky": out,
        "mesta": mesta,
    }
    path = os.path.join(out_dir, OUT_FILE)
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, separators=(",", ":"))
    known = sum(1 for r in out.values() if r["nazev"])
    print(f"Realitky: {len(out)} (s nazvem z detailu {known}), mest v zebrickach {len(mesta)}")
