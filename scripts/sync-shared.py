"""Synchronize shared source into independently deployable application copies."""
import argparse
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = {
    'shared/styles/gallery.css': ['nextjs/app/globals.css', 'php/public/assets/site.css'],
    'shared/moderation/comment-rules.json': ['nextjs/lib/comment-rules.json', 'php/src/comment-rules.json'],
    'shared/assets/icon.svg': ['nextjs/app/icon.svg', 'php/public/assets/icon.svg'],
    'shared/assets/placeholder.svg': ['nextjs/public/test-placeholder.svg', 'php/public/assets/placeholder.svg'],
}

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Fail on drift without changing files')
    args = parser.parse_args()
    different = []
    for source, targets in FILES.items():
        content = (ROOT / source).read_bytes().replace(b'\r\n', b'\n')
        for target in targets:
            path = ROOT / target
            if path.is_file() and path.read_bytes().replace(b'\r\n', b'\n') == content:
                continue
            different.append(target)
            if not args.check:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(content)
    if args.check and different:
        raise SystemExit('Shared copies differ: '+', '.join(different)+'. Run python scripts/sync-shared.py.')
    print('Shared source copies match.' if args.check else f'Updated {len(different)} shared copies.')

if __name__ == '__main__':
    main()
