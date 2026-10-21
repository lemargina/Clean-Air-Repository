#!/usr/bin/env python3
"""Read-only verification of the running local Compose deployment; never inserts fixtures."""
import urllib.request,urllib.error,json
from pathlib import Path
base='http://127.0.0.1:8080'
def get(path,expected=200,headers=None):
 try:
  r=urllib.request.urlopen(urllib.request.Request(base+path,headers=headers or {}),timeout=30);body=r.read();status=r.status
 except urllib.error.HTTPError as e:body=e.read();status=e.code
 assert status==expected,(path,status,body[:200])
 return body
report={}
report['liveness']=json.loads(get('/health/live'))
report['readiness']=json.loads(get('/health/ready'))
c=json.loads(get('/api/v1/data-coverage'));report['coverage']=c
report['cities']=len(json.loads(get('/api/v1/cities'))['data']);assert report['cities']==257
html=get('/').decode();assert 'Clean Air Finder' in html and 'type="date"' in html
get('/api/v1/air-quality/cleanest?from=invalid&to=2026-10-19',422)
get('/api/v1/air-quality/cities/999999?from=2026-10-18&to=2026-10-19',404)
get('/metrics',403)
metrics=get('/metrics',headers={'Authorization':'Bearer '+Path('.metrics-token').read_text().strip()}).decode();assert 'air_database_up 1' in metrics
report['metrics_verified']=True
if c['qualified_from']:
 path=f"/api/v1/air-quality/cleanest?from={c['qualified_from']}&to={c['qualified_to']}"
 result=json.loads(get(path));report['real_data_query']={'path':path,'status':result['status'],'winners':result['winners'],'eligible_cities':len(result['ranking']),'excluded_cities':len(result['excluded'])};assert result['coverage']['source'].startswith('Sensor.Community')
 report['partial_history_warning']='Пълният 12-месечен исторически импорт не е завършен' in html
prom=json.load(urllib.request.urlopen('http://127.0.0.1:9090/api/v1/targets'))
report['prometheus_targets']=[{'health':t['health'],'error':t['lastError']} for t in prom['data']['activeTargets']];assert all(t['health']=='up' for t in report['prometheus_targets'])
report['grafana']=json.load(urllib.request.urlopen('http://127.0.0.1:3000/api/health'));assert report['grafana']['database']=='ok'
Path('docs/deployment-verification.json').write_text(json.dumps(report,indent=2,ensure_ascii=False));print(json.dumps(report,indent=2,ensure_ascii=False))
