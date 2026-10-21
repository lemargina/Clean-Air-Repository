#!/usr/bin/env python3
"""Rebuild GeoNames-only extract. City identifiers are reviewed against the official registry.
Download BG.zip, admin1CodesASCII.txt, admin2Codes.txt into storage/research first.
No NSI or OSM content is redistributed in this extract.
"""
import json,zipfile,re
from pathlib import Path
ids=json.load(open('database/geography/city-geonames-ids.json'))
geo={r.split('\t')[0]:r.split('\t') for r in zipfile.ZipFile('storage/research/BG.zip').read('BG.txt').decode().splitlines()}
a1={r.split('\t')[0]:r.split('\t')[1] for r in open('storage/research/admin1.txt')}
a2={r.split('\t')[0]:r.split('\t')[1] for r in open('storage/research/admin2.txt')}
# The existing preferred labels are editorial choices among GeoNames alternate names.
preferred={r['external_identifier'].split(':')[1]:r['name_bg'] for r in json.load(open('database/geography/cities.json'))}
result=[]
for id in ids:
 r=geo[id];bg=[v for v in r[3].split(',') if re.fullmatch('[А-Яа-яЍѝ -]+',v) and not v.startswith(('Община','Област'))]
 name=preferred.get(id,bg[0] if bg else r[1])
 result.append(dict(name_bg=name,name_en=r[2],latitude=float(r[4]),longitude=float(r[5]),municipality=a2.get('BG.'+r[10]+'.'+r[11]),region=a1.get('BG.'+r[10]),population=int(r[14]),external_identifier='geonames:'+id,geographic_source='GeoNames CC BY 4.0; id='+id+'; snapshot 2026-10-20'))
Path('database/geography/cities.json').write_text(json.dumps(result,ensure_ascii=False,indent=2));print(len(result),'cities')
