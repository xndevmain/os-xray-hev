"""Build a clean source distribution; never archive the working directory wholesale."""
import gzip
import hashlib
import io
from pathlib import Path
import re
import tarfile

ROOT = Path(__file__).resolve().parents[1]
VERSION = (ROOT / 'plugin/mvc/app/models/OPNsense/Xray/version.txt').read_text().strip()
if not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.]+)?', VERSION):
    raise SystemExit('Invalid release version')

def source_files():
    paths = [ROOT / name for name in ['LICENSE', 'README.md', 'README.ru.md', 'Changelog.md', 'install.sh', '.gitattributes', '.gitignore']]
    for directory, extensions in [('plugin', {'.php','.py','.xml','.volt','.conf','.txt','.inc'}), ('locale',{'.po','.mo'}), ('tools',{'.py','.php'}), ('tests',{'.py','.php'}), ('.github/workflows',{'.yml','.yaml'})]:
        for path in (ROOT / directory).rglob('*'):
            if any(part in {'__pycache__','output'} for part in path.parts):
                continue
            if path.is_file() and (path.suffix in extensions or path.name == '50-xray'):
                paths.append(path)
    paths += [ROOT / 'docs' / name for name in ['PUBLIC-AUDIT.txt', 'SECURITY.txt']]
    required = ROOT / 'plugin/etc/inc/plugins.inc.d/xray.inc'
    if required not in paths: raise RuntimeError('Missing native plugin hook')
    return sorted(set(paths), key=lambda p: p.relative_to(ROOT).as_posix())

def build():
    out = ROOT / 'dist'
    out.mkdir(exist_ok=True)
    name = 'os-xray-hev-' + VERSION
    manifest=[]
    with open(out / (name + '.tar.gz'), 'wb') as raw:
        with gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as gz:
            with tarfile.open(fileobj=gz, mode='w') as archive:
                for path in source_files():
                    if path.is_symlink() or not path.is_file():
                        raise RuntimeError('Missing or symlinked release input: ' + str(path))
                    relative=path.relative_to(ROOT).as_posix()
                    payload=path.read_bytes()
                    if path.suffix != '.mo':
                        text=payload.decode('utf-8')
                        if re.search(r'-----BEGIN (?:OPENSSH |RSA |EC )?PRIVATE KEY-----|github_pat_[A-Za-z0-9_]+|gh[pousr]_[A-Za-z0-9]{20,}', text):
                            raise RuntimeError('Possible secret in ' + relative)
                        payload=text.replace('\r\n','\n').encode('utf-8')
                    info=tarfile.TarInfo(name+'/'+relative)
                    info.size=len(payload);info.uid=info.gid=0;info.uname=info.gname='root';info.mtime=0
                    info.mode=0o755 if relative=='install.sh' or relative.startswith('plugin/scripts/') or 'rc.syshook' in relative else 0o644
                    archive.addfile(info,io.BytesIO(payload));manifest.append(relative)
    digest=hashlib.sha256((out/(name+'.tar.gz')).read_bytes()).hexdigest()
    (out/'SHA256SUMS').write_text(digest+'  '+name+'.tar.gz\n',encoding='utf-8')
    (out/'public-manifest.txt').write_text('\n'.join(manifest)+'\n',encoding='utf-8')
    print(name + ': ' + str(len(manifest)) + ' allowlisted files; SHA-256 ' + digest)

if __name__=='__main__':
    build()
