"""Reject runtime data and common secrets in every available Git-history blob."""
import pathlib,re,subprocess
ROOT=pathlib.Path(__file__).resolve().parents[1]
objects=subprocess.check_output(['git','rev-list','--objects','--all'],cwd=ROOT,text=True).splitlines()
entries=[line.split(' ',1) for line in objects if ' ' in line]
entries.extend((':'+name,name) for name in subprocess.check_output(['git','ls-files'],cwd=ROOT,text=True).splitlines())
metadata=subprocess.check_output(['git','cat-file','--batch-check'],cwd=ROOT,input=''.join(oid+'\n' for oid,_ in entries),text=True).splitlines()
failures=[];checked=0
for (oid,name),info in zip(entries,metadata):
    parts=info.split()
    if len(parts)!=3 or parts[1]!='blob':continue
    checked+=1
    schema=bool(re.search(r'(^|/)(?:lib|src)/[a-z-]+-schema\.sql$',name))
    private_directory=re.search(r'(^|/)(storage|legacy|\.tools|node_modules|vendor|test-results|\.next|\.next-dev|\.private)/',name) and not name.endswith('storage/.gitkeep')
    private_file=not name.endswith('.env.example') and re.search(r'(config\.local\.php|\.sqlite(?:-|$)|\.db$|\.pem$|\.key$|(?:^|/)\.env(?:\.|$)|inventory\.local\.|operations\.local\.)',name)
    if private_directory or private_file or (not schema and re.search(r'\.sql(?:\.gz)?$',name)) or re.search(r'(^|/)public/(?:data|gallery|full)/',name):
        failures.append(name+': private/runtime file');continue
    if int(parts[2])>2_000_000:failures.append(name+': unexpected large source blob');continue
    content=subprocess.check_output(['git','cat-file','blob',oid],cwd=ROOT)
    for label,pattern in [
        ('private key',rb'-----BEGIN (?:OPENSSH |RSA |EC |DSA )?PRIVATE KEY-----'),
        ('GitHub token',rb'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{40,})\b'),
        ('Discord webhook',rb'https://(?:discord(?:app)?\.com)/api/webhooks/[0-9]+/[A-Za-z0-9_-]+'),
        ('AWS credential',rb'\bAKIA[A-Z0-9]{16}\b'),
    ]:
        if re.search(pattern,content):failures.append(name+': '+label)
if failures:raise SystemExit('Source publication check failed:\n'+'\n'.join(sorted(set(failures))))
print('Checked '+str(checked)+' history/index source blobs: no runtime data or matching secrets.')
