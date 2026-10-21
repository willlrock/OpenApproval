#!/usr/bin/env python3
"""Atomically publish a link; preserve a legacy file/directory on first use."""
import ctypes
import os
from pathlib import Path
import sys
import tempfile


def publish(target, link):
    target = Path(target).absolute()
    if not target.exists():
        raise RuntimeError("Activation target does not exist")
    link = Path(link).absolute()
    fd, temporary = tempfile.mkstemp(prefix=".activate-", dir=link.parent)
    os.close(fd)
    os.unlink(temporary)
    os.symlink(target, temporary)
    try:
        if link.exists() and not link.is_symlink():
            legacy = link.with_name(f".{link.name}.legacy")
            if legacy.exists() or legacy.is_symlink():
                raise RuntimeError("Legacy path already exists; inspect before activating")
            libc = ctypes.CDLL(None, use_errno=True)
            # RENAME_EXCHANGE also exchanges a non-empty directory and a link.
            if libc.renameat2(-100, os.fsencode(temporary), -100, os.fsencode(link), 2):
                raise OSError(ctypes.get_errno(), "Atomic directory exchange failed")
            os.rename(temporary, legacy)
        else:
            os.replace(temporary, link)
    finally:
        if os.path.islink(temporary):
            os.unlink(temporary)


if __name__ == "__main__":
    publish(sys.argv[1], sys.argv[2])
