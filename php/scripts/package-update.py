"""Package regular release files without directory entries or symbolic links."""
import sys
import zipfile
from pathlib import Path

root, output = map(Path, sys.argv[1:])
with zipfile.ZipFile(output, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
    for file in sorted(root.rglob('*')):
        if file.is_symlink():
            raise ValueError('Release links are forbidden')
        if file.is_file():
            archive.write(file, file.relative_to(root).as_posix())
