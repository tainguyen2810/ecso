#!/usr/bin/env python3
"""
Builds database/data/vn-admin-units.json: Vietnam's administrative units in force since 1 July 2025
(Resolution 202/2025/QH15: 34 provinces, wards/communes directly under them, no districts), with the codes of the
General Statistics Office.

Source: npm package `vietnam-address-database` (MIT, https://github.com/quangtam/vietnam-address-database),
file address.json. Usage:

    npm pack vietnam-address-database && tar xzf vietnam-address-database-*.tgz
    python3 scripts/build-vn-admin-units.py package/address.json > database/data/vn-admin-units.json

Each ward keeps the pre-2025 wards it absorbed ("former"), only so that a search for an old ward or district name
still finds the new ward and a pasted old address can be read. Addresses are always stored with the new units.
Some rows of the source mapping carry a wrong new-ward code; rows are matched to the new ward by name and province.
"""
import collections
import json
import re
import sys


def short_old(name: str) -> str:
    """'Phường 12' -> 'P.12', 'Quận Bình Thạnh' -> 'Q. Bình Thạnh' for the compact hint shown under a ward."""
    for full, abbr in (('Phường ', 'P. '), ('Xã ', 'X. '), ('Thị trấn ', 'TT. '), ('Quận ', 'Q. '), ('Huyện ', 'H. '), ('Thị xã ', 'TX. '), ('Thành phố ', 'TP. ')):
        if name.startswith(full):
            rest = name[len(full):]
            return (abbr.strip() + rest) if re.fullmatch(r'\d+', rest) else abbr + rest
    return name


def main(path: str) -> None:
    tables = {t['name']: t['data'] for t in json.load(open(path, encoding='utf-8')) if t.get('type') == 'table'}
    provinces = sorted(tables['provinces'], key=lambda p: p['province_code'])
    by_code = {p['province_code']: p for p in provinces}

    def full_province(p):
        name = p['name']
        return name if name.startswith('Thành phố ') else 'Tỉnh ' + name

    wards = sorted(tables['wards'], key=lambda w: w['ward_code'])
    ward_codes = {w['ward_code'] for w in wards}
    by_name = {(w['name'], full_province(by_code[w['province_code']])): w['ward_code'] for w in wards}
    by_name_short = {(w['name'], by_code[w['province_code']]['name']): w['ward_code'] for w in wards}

    former = collections.defaultdict(list)  # new ward code -> [(old ward, old district, old province)]
    old_provinces = collections.defaultdict(set)  # new province code -> old province names
    for m in tables['ward_mappings']:
        if not m['old_ward_name']:
            continue
        # The new ward's name + province decide; the code of a few rows points at a ward elsewhere.
        code = by_name.get((m['new_ward_name'], m['new_province_name'])) or by_name_short.get((m['new_ward_name'], m['new_province_name'])) or (
            m['new_ward_code'] if m['new_ward_code'] in ward_codes else None)
        if code is None:
            print(f"unmatched mapping {m['id']}", file=sys.stderr)
            continue
        item = (m['old_ward_name'], m['old_district_name'] or '', m['old_province_name'] or '')
        if item not in former[code]:
            former[code].append(item)

    ward_province = {w['ward_code']: w['province_code'] for w in wards}
    # A pre-2025 province counts as merged into a new one when several of its wards went there (the source has a
    # few stray rows, e.g. one Hà Nội ward listed under Lâm Đồng).
    counts = collections.Counter()
    for code, items in former.items():
        for _, _, prov in items:
            if prov:
                counts[(ward_province[code], re.sub(r'^(Tỉnh|Thành phố) ', '', prov))] += 1
    for (new_code, old_name), n in counts.items():
        if n >= 5:
            old_provinces[new_code].add(old_name)

    out_provinces = []
    for p in provinces:
        short = p['name'].removeprefix('Thành phố ')
        out_provinces.append({
            'code': p['province_code'],
            'name': full_province(p),
            'short_name': short,
            'type': 'city' if p['name'].startswith('Thành phố ') else 'province',
            'former': sorted(o for o in old_provinces[p['province_code']] if o != short),
        })

    out_wards = []
    for w in wards:
        items = former.get(w['ward_code'], [])
        groups = collections.OrderedDict()
        for ward, district, _ in items:
            groups.setdefault(district, []).append(short_old(ward))
        hint = '; '.join(', '.join(names) + (f' ({short_old(d)})' if d else '') for d, names in groups.items())
        out_wards.append({
            'code': w['ward_code'],
            'province_code': w['province_code'],
            'name': w['name'],
            'former': [[ward, district] for ward, district, _ in items],
            'former_hint': hint,
        })

    json.dump({'source': 'vietnam-address-database 1.0.0 (Resolution 202/2025/QH15)', 'provinces': out_provinces, 'wards': out_wards},
              sys.stdout, ensure_ascii=False, separators=(',', ':'))


if __name__ == '__main__':
    main(sys.argv[1])
