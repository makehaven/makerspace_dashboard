#!/usr/bin/env python3
"""Per-capita MakeHaven membership by New Haven neighborhood.

Fetches the City's official neighborhood boundaries and Census 2020 block
population, assigns member points and block centroids by point-in-polygon,
and prints members per 10,000 residents per neighborhood.

Usage:
    lando mysql pantheon < member-geography.sql > pts.tsv
    python3 neighborhood-rates.py

Correctness check: assigned population MUST total 134,023 (New Haven's official
2020 population). A mismatch means the polygon handling is wrong -- most likely
multipolygon rings or a swapped lon/lat. See ../equity-report.md.
"""

import csv
import json
import urllib.parse
import urllib.request
from collections import defaultdict

NH_2020_POPULATION = 134_023

BOUNDARIES = (
    "https://services1.arcgis.com/7uJv7I3kgh2y7Pe0/arcgis/rest/services/"
    "New_Haven_Neighborhoods/FeatureServer/0/query"
    "?where=1%3D1&outFields=*&outSR=4326&f=geojson"
)

# TIGERweb carries POP100 + centroids and needs no API key.
# (api.census.gov's block endpoint DOES require one -- don't use it.)
BLOCKS = (
    "https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/"
    "tigerWMS_Census2020/MapServer/10/query?"
    + urllib.parse.urlencode({
        "where": "STATE='09' AND COUNTY='009'",
        "geometry": "-73.05,41.20,-72.80,41.40",
        "geometryType": "esriGeometryEnvelope",
        "inSR": "4326",
        "spatialRel": "esriSpatialRelIntersects",
        "outFields": "GEOID,CENTLAT,CENTLON,POP100,HU100",
        "returnGeometry": "false",
        "resultRecordCount": "5000",
        "f": "json",
    })
)

# DataHaven's 2020 Census classification, used for the summary table.
COMPOSITION = {
    "East Rock": "majority white", "East Shore": "majority white",
    "Westville": "majority white", "Wooster Sq/ Mill River": "majority white",
    "Amity": "majority Black", "Beaver Hills": "majority Black",
    "Dixwell": "majority Black", "Edgewood": "majority Black",
    "Newhallville": "majority Black", "West River": "majority Black",
    "Annex": "majority Latino", "Fair Haven": "majority Latino", "Hill": "majority Latino",
}


def fetch(url):
    with urllib.request.urlopen(url, timeout=120) as r:
        return json.load(r)


def build_index(geojson):
    """Flatten features into (name, rings, bbox) for fast rejection."""
    out = []
    for feat in geojson["features"]:
        name = feat["properties"]["Neighborhood"]
        geom = feat["geometry"]
        polys = [geom["coordinates"]] if geom["type"] == "Polygon" else geom["coordinates"]
        for rings in polys:
            xs = [p[0] for p in rings[0]]
            ys = [p[1] for p in rings[0]]
            out.append((name, rings, min(xs), max(xs), min(ys), max(ys)))
    return out


def in_rings(x, y, rings):
    """Ray casting. rings[0] is the outer boundary; the rest are holes."""
    inside = False
    for i, ring in enumerate(rings):
        crossed = False
        n = len(ring)
        for j in range(n):
            x1, y1 = ring[j][0], ring[j][1]
            x2, y2 = ring[(j + 1) % n][0], ring[(j + 1) % n][1]
            if (y1 > y) != (y2 > y) and x < (x2 - x1) * (y - y1) / (y2 - y1) + x1:
                crossed = not crossed
        if i == 0:
            inside = crossed
        elif crossed:
            return False
    return inside


def locate(index, lat, lon):
    for name, rings, minx, maxx, miny, maxy in index:
        if minx <= lon <= maxx and miny <= lat <= maxy and in_rings(lon, lat, rings):
            return name
    return None


def main():
    index = build_index(fetch(BOUNDARIES))

    pop = defaultdict(int)
    for b in fetch(BLOCKS)["features"]:
        a = b["attributes"]
        name = locate(index, float(a["CENTLAT"]), float(a["CENTLON"]))
        if name:
            pop[name] += int(a.get("POP100") or 0)

    total_pop = sum(pop.values())
    if total_pop != NH_2020_POPULATION:
        raise SystemExit(
            f"FAIL: assigned population {total_pop:,} != {NH_2020_POPULATION:,}. "
            "Polygon handling is wrong -- check multipolygon rings and lon/lat order."
        )
    print(f"population check OK: {total_pop:,}\n")

    members = defaultdict(int)
    with open("pts.tsv") as fh:
        for row in csv.DictReader(fh, delimiter="\t"):
            if row["is_current"] != "1":
                continue
            try:
                lat, lon = float(row["geo_code_1"]), float(row["geo_code_2"])
            except (ValueError, TypeError):
                continue
            name = locate(index, lat, lon)
            if name:
                members[name] += 1

    total_members = sum(members.values())
    base = total_members / total_pop * 10000
    print(f"{total_members} current members in New Haven -- {base:.1f} per 10,000 citywide\n")

    print(f"{'neighborhood':24}{'pop':>8}{'members':>9}{'per 10k':>9}{'index':>7}{'gap':>6}")
    rows = [(n, pop[n], members.get(n, 0), members.get(n, 0) / pop[n] * 10000)
            for n in pop if pop[n]]
    for name, po, m, rate in sorted(rows, key=lambda r: r[3]):
        print(f"{name:24}{po:>8,}{m:>9}{rate:>9.1f}{rate/base:>7.2f}{m - po*base/10000:>+6.0f}")

    shortfall = sum(max(0, po * base / 10000 - m) for _, po, m, _ in rows)
    under = sum(1 for _, _, _, rate in rows if rate < base)
    print(f"\n{under} neighborhoods below the citywide rate; "
          f"{shortfall:.0f} members short of parity")

    print(f"\n{'composition':20}{'pop':>9}{'members':>9}{'per 10k':>9}{'index':>7}")
    agg = defaultdict(lambda: [0, 0])
    for name, po, m, _ in rows:
        g = agg[COMPOSITION.get(name, "no majority")]
        g[0] += po
        g[1] += m
    for g, (po, m) in sorted(agg.items(), key=lambda kv: -kv[1][1] / kv[1][0]):
        print(f"{g:20}{po:>9,}{m:>9}{m/po*10000:>9.1f}{(m/po*10000)/base:>7.2f}")


if __name__ == "__main__":
    main()
