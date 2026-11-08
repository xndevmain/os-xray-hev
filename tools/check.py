"""Portable checks: python tools/check.py [--php /path/to/php]."""
import argparse
import ast
from pathlib import Path
import re
import shutil
import subprocess
import sys
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--php', default='php')
args = parser.parse_args()
def run(*command):
    subprocess.run(command, cwd=root, check=True)
php_files = list((root / 'plugin').rglob('*.php')) + list((root / 'plugin').rglob('*.inc')) + list((root / 'tools').glob('*.php'))
for path in sorted(php_files):
    subprocess.run([args.php, '-l', str(path)], check=True, stdout=subprocess.DEVNULL)
for path in (root / 'plugin').rglob('*.xml'):
    ET.parse(path)
for path in list((root / 'plugin').rglob('*.py')) + list((root / 'tests').glob('*.py')):
    ast.parse(path.read_text(encoding='utf-8'))
output = root / 'tests/output'
output.mkdir(exist_ok=True)
for path in (root / 'plugin').rglob('*.volt'):
    source = re.sub(r'\{\{.*?\}\}', 'TRANSLATED', path.read_text(encoding='utf-8'), flags=re.S)
    source = re.sub(r'\{#.*?#\}', '', source, flags=re.S)
    scripts = re.findall(r'<script[^>]*>(.*?)</script>', source, re.S)
    if scripts:
        target = output / (path.stem + '-syntax.js')
        target.write_text('\n'.join(scripts), encoding='utf-8')
        run('node', '--check', str(target))
if shutil.which('sh'):
    run('sh', '-n', 'install.sh')
for test in ['config', 'subscription', 'catalog', 'download', 'ip_lists', 'subscription_refresh', 'geodata_releases']:
    run(args.php, 'tests/' + test + '.php')
run(sys.executable, '-m', 'unittest', 'discover', '-s', 'tests', '-p', 'test_*.py')
print('All portable checks passed')
