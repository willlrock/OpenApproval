#!/usr/bin/env python3
import importlib.util
import os
from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


def module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


atomic = module("atomic", ROOT / "scripts/atomic-link.py")
backup = module("backup", ROOT / "scripts/online-backup.py")


class ReleaseOperations(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)

    def release(self, name):
        release = self.root / name
        (release / "public").mkdir(parents=True)
        (release / "public/version").write_text(name)
        (release / "vendor").mkdir()
        (release / "vendor/version").write_text(name)
        (release / "artisan").write_text(name)
        return release

    def test_atomic_exchange_preserves_legacy_and_current_indirection(self):
        old = self.release("old")
        new = self.release("new")
        (self.root / "public").mkdir()
        (self.root / "public/version").write_text("legacy")
        (self.root / "artisan").write_text("legacy")
        atomic.publish(old, self.root / "current")
        atomic.publish(self.root / "current/public", self.root / "public")
        atomic.publish(self.root / "current/artisan", self.root / "artisan")
        self.assertEqual((self.root / ".public.legacy/version").read_text(), "legacy")
        self.assertEqual((self.root / ".artisan.legacy").read_text(), "legacy")
        atomic.publish(new, self.root / "current")
        self.assertEqual((self.root / "public/version").read_text(), "new")
        self.assertEqual((self.root / "artisan").read_text(), "new")
        atomic.publish(old, self.root / "current")
        self.assertEqual((self.root / "public/version").read_text(), "old")

    def test_concurrent_readers_never_observe_missing_or_mixed_release(self):
        old, new = self.release("old"), self.release("new")
        atomic.publish(old, self.root / "current")
        atomic.publish(self.root / "current/public", self.root / "public")
        done, errors, observed = threading.Event(), [], []

        def read():
            try:
                while not done.is_set():
                    public = (self.root / "public").resolve(strict=True)
                    self.assertEqual((public / "version").read_text(), (public.parent / "vendor/version").read_text())
                    observed.append(True)
            except BaseException as error:
                errors.append(error)

        reader = threading.Thread(target=read)
        reader.start()
        try:
            for _ in range(200):
                atomic.publish(new, self.root / "current")
                atomic.publish(old, self.root / "current")
        finally:
            done.set()
            reader.join()
        self.assertTrue(observed)
        self.assertEqual(errors, [])

    def snapshot_fixture(self, mutation=""):
        source, destination, commands = self.root / "private", self.root / "snapshot", self.root / "bin"
        source.mkdir()
        destination.mkdir()
        commands.mkdir()
        (source / "file").write_text("original")
        dump = commands / "pg_dump"
        dump.write_text("#!/usr/bin/env bash\nset -eu\n" + mutation + "\nfor value in \"$@\"; do case \"$value\" in --file=*) printf dump > \"${value#--file=}\";; esac; done\n")
        dump.chmod(0o755)
        return source, destination, commands

    def test_online_snapshot_without_mutation(self):
        source, destination, commands = self.snapshot_fixture()
        with patch.dict(os.environ, {"PATH": str(commands) + ":" + os.environ["PATH"]}):
            backup.snapshot(source, destination)
        self.assertEqual((destination / "database.dump").read_text(), "dump")
        self.assertEqual((destination / "private/file").read_text(), "original")
        self.assertNotEqual((source / "file").stat().st_ino, (destination / "private/file").stat().st_ino)

    def test_mutation_and_revert_is_detected(self):
        source, destination, commands = self.snapshot_fixture(
            f"printf changed > '{self.root}/private/file'\nprintf original > '{self.root}/private/file'")
        with patch.dict(os.environ, {"PATH": str(commands) + ":" + os.environ["PATH"]}):
            with self.assertRaises(backup.ChangedFiles):
                backup.snapshot(source, destination)

    def test_stream_opened_before_watch_is_not_copied_partially(self):
        source, destination, commands = self.snapshot_fixture()
        with (source / "file").open("w"), patch.dict(os.environ, {"PATH": str(commands) + ":" + os.environ["PATH"]}):
            with self.assertRaises(backup.ChangedFiles):
                backup.snapshot(source, destination)

    def test_external_hardlink_cannot_hide_a_mutation(self):
        source, destination, _ = self.snapshot_fixture()
        os.link(source / "file", self.root / "external-file")
        with self.assertRaises(RuntimeError):
            backup.snapshot(source, destination)

    def test_new_directory_or_rename_invalidates_snapshot(self):
        source, destination, commands = self.snapshot_fixture(f"mkdir '{self.root}/private/new'; mv '{self.root}/private/file' '{self.root}/private/new/file'")
        with patch.dict(os.environ, {"PATH": str(commands) + ":" + os.environ["PATH"]}):
            with self.assertRaises(backup.ChangedFiles):
                backup.snapshot(source, destination)

    def test_symlink_and_unwatchable_tree_fail_closed(self):
        source, destination, _ = self.snapshot_fixture()
        (source / "link").symlink_to(self.root)
        with self.assertRaises(RuntimeError):
            backup.snapshot(source, destination)
        (source / "link").unlink()
        with patch.object(backup.ctypes, "CDLL") as library:
            library.return_value.inotify_init1.return_value = -1
            with self.assertRaises(OSError):
                backup.snapshot(source, destination)

    def test_lost_watch_or_overflow_invalidates_snapshot(self):
        source, _, _ = self.snapshot_fixture()
        watch = backup.FileWatch(source)
        try:
            with patch.object(backup.select, "select", return_value=([watch.fd], [], [])), patch.object(backup.os, "read", return_value=b"overflow"):
                with self.assertRaises(backup.ChangedFiles):
                    watch.check()
        finally:
            watch.close()


if __name__ == "__main__":
    unittest.main()
