#!/usr/local/bin/python3
import json
from pathlib import Path
from geodata import categories

root = Path('/usr/local/share/xray')
directory = root / 'current' if (root / 'current').is_dir() else root
result = {}
for kind in ('geosite', 'geoip'):
    path = directory / (kind + '.dat')
    result[kind] = sorted(categories(path)) if path.is_file() else []
print(json.dumps(result))
