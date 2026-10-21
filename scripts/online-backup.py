#!/usr/bin/env python3
"""Dump PostgreSQL + copy private files only across an unchanged file interval.

Linux/inotify, no third party packages. A mutation (including ABA or overflow)
invalidates the attempt; readers and writers are never paused or locked out.
"""
import ctypes
import os
from pathlib import Path
import select
import subprocess
import sys


class ChangedFiles(RuntimeError):
    pass


class FileWatch:
    # MODIFY, ATTRIB, CLOSE_WRITE, MOVED_FROM/TO, CREATE, DELETE, DELETE_SELF,
    # MOVE_SELF, UNMOUNT. Overflow/ignored events are delivered independently.
    MASK = 0x2 | 0x4 | 0x8 | 0x40 | 0x80 | 0x100 | 0x200 | 0x400 | 0x800 | 0x2000

    def __init__(self, root):
        libc = ctypes.CDLL(None, use_errno=True)
        self.fd = libc.inotify_init1(os.O_NONBLOCK | os.O_CLOEXEC)
        if self.fd < 0:
            raise OSError(ctypes.get_errno(), "Cannot initialize inotify")
        try:
            if root.is_symlink() or not root.is_dir():
                raise RuntimeError("Private storage must be a regular directory")
            filesystem = subprocess.check_output(["stat", "--file-system", "--format=%T", str(root)], text=True).strip()
            if filesystem not in {"ext2/ext3", "xfs", "btrfs", "tmpfs", "overlayfs"}:
                raise RuntimeError("Online snapshots require a supported local filesystem")
            device = root.stat().st_dev
            pending = [root]
            while pending:
                directory = pending.pop()
                # Install the watch BEFORE enumerating children. Otherwise a
                # directory created between readdir and add_watch could escape
                # both recursive traversal and the event queue.
                if libc.inotify_add_watch(self.fd, os.fsencode(directory), self.MASK | 0x01000000 | 0x02000000) < 0:
                    raise OSError(ctypes.get_errno(), "Cannot watch private storage")
                # Re-check unsupported paths rather than silently following links.
                for path in directory.iterdir():
                    if path.is_symlink() or not (path.is_dir() or path.is_file()):
                        raise RuntimeError("Unsupported private storage entry")
                    if path.stat().st_dev != device or (path.is_file() and path.stat().st_nlink != 1):
                        raise RuntimeError("Private storage cannot contain mounts or hardlinks")
                    if path.is_dir():
                        pending.append(path)
            self.check()
        except BaseException:
            os.close(self.fd)
            raise

    def check(self):
        # Every subscribed event invalidates the interval, including a lost watch
        # or IN_Q_OVERFLOW. Drain while commands run so a full pipe cannot stall.
        if select.select([self.fd], [], [], 0)[0]:
            os.read(self.fd, 65536)
            raise ChangedFiles("Private files changed during the snapshot")

    def run(self, command):
        process = subprocess.Popen(command)
        try:
            while process.poll() is None:
                self.check()
                select.select([self.fd], [], [], 0.05)
            self.check()
            if process.returncode:
                raise subprocess.CalledProcessError(process.returncode, command[0])
        finally:
            if process.poll() is None:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()

    def close(self):
        os.close(self.fd)


def check_open_writers(source):
    # A stream opened before watches were installed may still contain partial
    # bytes even if no write happens during the dump. Reject that interval too.
    prefix = str(source.resolve()) + "/"
    for process in Path('/proc').glob('[0-9]*'):
        try:
            for descriptor in (process / 'fd').iterdir():
                try:
                    if os.readlink(descriptor).startswith(prefix):
                        info = (process / 'fdinfo' / descriptor.name).read_text()
                        flags = next(line.split()[1] for line in info.splitlines() if line.startswith('flags:'))
                        if int(flags, 8) & os.O_ACCMODE:
                            raise ChangedFiles("A private file is open for writing")
                except FileNotFoundError:
                    continue
        except FileNotFoundError:
            continue
        except PermissionError:
            if os.geteuid() == 0:
                raise RuntimeError("Cannot verify application file writers through /proc")


def snapshot(source, destination, previous=None):
    watch = FileWatch(source)
    try:
        check_open_writers(source)
        watch.run(["pg_dump", "--format=custom", "--no-owner", "--no-privileges",
                   "--lock-wait-timeout=2s", f"--file={destination / 'database.dump'}"])
        command = ["rsync", "--archive", "--checksum"]
        if previous:
            command.append(f"--link-dest={previous}")
        watch.run(command + [str(source) + "/", str(destination / "private") + "/"])
        check_open_writers(source)
        watch.check()
    finally:
        watch.close()


if __name__ == "__main__":
    try:
        snapshot(Path(sys.argv[1]), Path(sys.argv[2]), sys.argv[3] if len(sys.argv) > 3 else None)
    except ChangedFiles as error:
        print(str(error) + "; retrying without interrupting the site.", file=sys.stderr)
        sys.exit(75)
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        # Do not echo command arguments or credentials.
        print(f"Online snapshot failed ({type(error).__name__}); site remains online.", file=sys.stderr)
        sys.exit(1)
