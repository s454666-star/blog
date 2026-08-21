import asyncio
import importlib.util
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path
from types import SimpleNamespace


MODULE_PATH = Path(__file__).resolve().parents[2] / "scripts" / "telegram_media_queue.py"
SPEC = importlib.util.spec_from_file_location("telegram_media_queue", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
QUEUE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(QUEUE)


class TelegramMediaQueueTest(unittest.TestCase):
    def test_password_tokens_are_limited_to_explicit_password_labels(self):
        self.assertEqual(["@fixture665"], QUEUE.archive_password_tokens("密碼：@fixture665"))
        self.assertEqual(["abc123"], QUEUE.archive_password_tokens("password = abc123"))
        self.assertEqual([], QUEUE.archive_password_tokens("unrelated words only"))
        self.assertEqual("@fixture665", QUEUE.archive_bare_password_token("@fixture665"))
        self.assertIsNone(QUEUE.archive_bare_password_token("a sentence is not a password"))

    def test_message_kind_classifies_zip_without_reading_message_text(self):
        filename = SimpleNamespace(file_name="fixture.zip")
        document = SimpleNamespace(mime_type="application/octet-stream", attributes=[filename])
        original_type = QUEUE.DocumentAttributeFilename
        try:
            QUEUE.DocumentAttributeFilename = type(filename)
            message = SimpleNamespace(photo=None, document=document)
            self.assertEqual("archive", QUEUE.message_kind(message))
        finally:
            QUEUE.DocumentAttributeFilename = original_type

    def test_processed_message_table_is_idempotent(self):
        with tempfile.TemporaryDirectory() as directory:
            original_path = QUEUE.PROCESSED_DB_PATH
            QUEUE.PROCESSED_DB_PATH = Path(directory) / "processed.sqlite3"
            try:
                QUEUE.begin_processed_message(-1001, 42, "fixture", "archive")
                self.assertFalse(QUEUE.processed_message_complete(-1001, 42))
                QUEUE.finish_archive_item(-1001, 42, "item", "video", 123, anonymous_name="tg_fixture.mp4")
                QUEUE.finish_processed_message(-1001, 42, 1)
                self.assertTrue(QUEUE.processed_message_complete(-1001, 42))
                QUEUE.begin_processed_message(-1001, 42, "fixture", "archive")
                self.assertTrue(QUEUE.processed_message_complete(-1001, 42))
            finally:
                QUEUE.PROCESSED_DB_PATH = original_path

    def test_zip_header_validation_rejects_parent_traversal(self):
        with tempfile.TemporaryDirectory() as directory:
            archive_path = Path(directory) / "unsafe.zip"
            with zipfile.ZipFile(archive_path, "w") as archive:
                archive.writestr("../escape.jpg", b"fixture")
            with self.assertRaisesRegex(RuntimeError, "archive_unsafe_path"):
                QUEUE.validate_zip_headers(archive_path)

    def test_password_and_passwordless_zip_extraction(self):
        seven_zip = Path(r"C:\Program Files\7-Zip\7z.exe")
        if not seven_zip.is_file():
            self.skipTest("7-Zip is not installed")
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            fixture = root / "fixture.jpg"
            fixture.write_bytes(b"generated-fixture")
            plain = root / "plain.zip"
            protected = root / "protected.zip"
            subprocess.run(
                [str(seven_zip), "a", "-tzip", str(plain), str(fixture), "-y", "-bd", "-bb0"],
                check=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            subprocess.run(
                [str(seven_zip), "a", "-tzip", "-pfixture-secret", str(protected), str(fixture), "-y", "-bd", "-bb0"],
                check=True,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            plain_output = root / "plain-output"
            protected_output = root / "protected-output"
            wrong_output = root / "wrong-output"
            plain_output.mkdir()
            protected_output.mkdir()
            wrong_output.mkdir()
            self.assertTrue(asyncio.run(QUEUE.extract_zip_once(plain, plain_output, str(seven_zip), None)))
            self.assertFalse(asyncio.run(QUEUE.extract_zip_once(protected, wrong_output, str(seven_zip), "wrong")))
            self.assertTrue(
                asyncio.run(QUEUE.extract_zip_once(protected, protected_output, str(seven_zip), "fixture-secret"))
            )


if __name__ == "__main__":
    unittest.main()
