import asyncio
import importlib.util
import os
import shutil
import tempfile
import types
import unittest
from pathlib import Path


TEST_SERVICE_HOME = tempfile.TemporaryDirectory()
Path(TEST_SERVICE_HOME.name, "session").mkdir()
os.environ["TELEGRAM_SERVICE_HOME"] = TEST_SERVICE_HOME.name
os.environ["TELEGRAM_SERVICE_SESSION"] = str(
    Path(TEST_SERVICE_HOME.name, "session", "test_account")
)

MODULE_PATH = (
    Path(__file__).resolve().parents[2]
    / "python"
    / "telegram_service_shared.py"
)
SPEC = importlib.util.spec_from_file_location("telegram_service_shared", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class TelegramMediaFileIdDedupeTest(unittest.TestCase):
    def test_file_id_only_mode_is_explicit(self):
        self.assertEqual(
            "telegram_file_unique_id",
            MODULE._validate_media_dedupe_mode("telegram_file_unique_id"),
        )
        with self.assertRaisesRegex(ValueError, "unsupported media dedupe mode"):
            MODULE._validate_media_dedupe_mode("sha1")

    def test_extracts_stable_photo_and_document_ids(self):
        photo = types.SimpleNamespace(
            photo=types.SimpleNamespace(id=123),
            document=None,
        )
        document = types.SimpleNamespace(
            photo=None,
            document=types.SimpleNamespace(id=456),
        )
        empty = types.SimpleNamespace(photo=None, document=None)

        self.assertEqual(
            "photo:123",
            MODULE._telegram_media_file_unique_id(photo),
        )
        self.assertEqual(
            "document:456",
            MODULE._telegram_media_file_unique_id(document),
        )
        self.assertEqual("", MODULE._telegram_media_file_unique_id(empty))

    def test_file_id_catalog_round_trip(self):
        original_path = MODULE.MEDIA_DEDUPE_DB_PATH
        try:
            with tempfile.TemporaryDirectory() as temporary_directory:
                MODULE.MEDIA_DEDUPE_DB_PATH = str(
                    Path(temporary_directory) / "dedupe.sqlite3"
                )
                MODULE._media_file_id_register(
                    "scope",
                    "document:456",
                    200,
                    300,
                    "a" * 64,
                    400,
                    500,
                    600,
                )

                found = MODULE._media_file_id_lookup("scope", "document:456")

                self.assertIsNotNone(found)
                self.assertEqual(200, found["target_peer_id"])
                self.assertEqual(300, found["target_message_id"])
                self.assertEqual("a" * 64, found["content_sha256"])
                self.assertEqual(600, found["file_size"])

                MODULE._media_file_id_delete("scope", "document:456")
                self.assertIsNone(
                    MODULE._media_file_id_lookup("scope", "document:456")
                )
        finally:
            MODULE.MEDIA_DEDUPE_DB_PATH = original_path

    def test_matching_file_id_skips_download(self):
        original_path = MODULE.MEDIA_DEDUPE_DB_PATH
        original_client = MODULE.client
        original_ensure_connected = MODULE._ensure_client_connected
        original_resolve = MODULE._resolve_any_input_entity_by_id
        original_verify = MODULE._verified_dedupe_target_message
        original_download = MODULE._download_media_to_temporary_file

        source_message = types.SimpleNamespace(
            id=10,
            photo=None,
            video=object(),
            document=types.SimpleNamespace(id=456, mime_type="video/mp4"),
            noforwards=True,
        )
        target_message = types.SimpleNamespace(
            id=300,
            photo=None,
            video=object(),
            document=types.SimpleNamespace(mime_type="video/mp4"),
            message="",
            fwd_from=None,
        )

        async def ensure_connected():
            return True

        async def resolve(peer_id):
            return peer_id

        async def get_messages(peer, ids):
            return [source_message]

        async def verify(*args, **kwargs):
            return target_message

        async def fail_download(*args, **kwargs):
            self.fail("matching Telegram file ID must skip download")

        try:
            with tempfile.TemporaryDirectory() as temporary_directory:
                MODULE.MEDIA_DEDUPE_DB_PATH = str(
                    Path(temporary_directory) / "dedupe.sqlite3"
                )
                MODULE._media_file_id_register(
                    "scope",
                    "document:456",
                    200,
                    300,
                    "a" * 64,
                    400,
                    500,
                    600,
                )
                MODULE.client = types.SimpleNamespace(get_messages=get_messages)
                MODULE._ensure_client_connected = ensure_connected
                MODULE._resolve_any_input_entity_by_id = resolve
                MODULE._verified_dedupe_target_message = verify
                MODULE._download_media_to_temporary_file = fail_download

                response = asyncio.run(
                    MODULE.copy_protected_media_batch(
                        MODULE.CopyProtectedMediaBatchRequest(
                            source_peer_id=100,
                            source_bot_username="source",
                            message_ids=[10],
                            target_peer_id=200,
                            dedupe_scope="scope",
                        )
                    )
                )

                self.assertEqual("ok", response["status"])
                self.assertEqual(1, response["duplicate_count"])
                self.assertEqual(
                    "telegram_file_unique_id",
                    response["results"][0]["duplicate_match"],
                )
                self.assertIsNone(response["results"][0]["downloader"])
        finally:
            MODULE.MEDIA_DEDUPE_DB_PATH = original_path
            MODULE.client = original_client
            MODULE._ensure_client_connected = original_ensure_connected
            MODULE._resolve_any_input_entity_by_id = original_resolve
            MODULE._verified_dedupe_target_message = original_verify
            MODULE._download_media_to_temporary_file = original_download


if __name__ == "__main__":
    unittest.main()


def tearDownModule():
    MODULE.client.session.close()
    shutil.rmtree(TEST_SERVICE_HOME.name, ignore_errors=True)
