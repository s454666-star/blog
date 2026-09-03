import asyncio
import importlib.util
import subprocess
import tempfile
import unittest
import zipfile
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch


MODULE_PATH = Path(__file__).resolve().parents[2] / "scripts" / "telegram_media_queue.py"
SPEC = importlib.util.spec_from_file_location("telegram_media_queue", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
QUEUE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(QUEUE)


class TelegramMediaQueueTest(unittest.TestCase):
    def test_archive_image_retries_invalid_photo_as_document(self):
        calls = []
        events = []

        class PhotoSaveFileInvalidError(Exception):
            pass

        class FixtureClient:
            async def send_file(self, target, path, **kwargs):
                calls.append(kwargs)
                if len(calls) == 1:
                    raise PhotoSaveFileInvalidError()
                return SimpleNamespace(id=321)

        original_error = QUEUE.PhotoSaveFileInvalidError
        original_safe_log = QUEUE.safe_log
        QUEUE.PhotoSaveFileInvalidError = PhotoSaveFileInvalidError
        QUEUE.safe_log = lambda event, **fields: events.append((event, fields))
        try:
            sent = asyncio.run(QUEUE.send_archive_image(FixtureClient(), "fixture-target", Path("fixture.jpg")))
        finally:
            QUEUE.PhotoSaveFileInvalidError = original_error
            QUEUE.safe_log = original_safe_log

        self.assertEqual(321, sent.id)
        self.assertEqual([False, True], [call["force_document"] for call in calls])
        self.assertEqual("archive_image_document_fallback", events[0][0])
        self.assertEqual("PhotoSaveFileInvalidError", events[0][1]["error_class"])

    def test_archive_image_retries_invalid_dimensions_as_document(self):
        calls = []
        events = []

        class PhotoInvalidDimensionsError(Exception):
            pass

        class FixtureClient:
            async def send_file(self, target, path, **kwargs):
                calls.append(kwargs)
                if len(calls) == 1:
                    raise PhotoInvalidDimensionsError()
                return SimpleNamespace(id=654)

        original_error = QUEUE.PhotoInvalidDimensionsError
        original_safe_log = QUEUE.safe_log
        QUEUE.PhotoInvalidDimensionsError = PhotoInvalidDimensionsError
        QUEUE.safe_log = lambda event, **fields: events.append((event, fields))
        try:
            sent = asyncio.run(QUEUE.send_archive_image(FixtureClient(), "fixture-target", Path("fixture.jpg")))
        finally:
            QUEUE.PhotoInvalidDimensionsError = original_error
            QUEUE.safe_log = original_safe_log

        self.assertEqual(654, sent.id)
        self.assertEqual([False, True], [call["force_document"] for call in calls])
        self.assertEqual("archive_image_document_fallback", events[0][0])
        self.assertEqual("PhotoInvalidDimensionsError", events[0][1]["error_class"])

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

    def test_existing_failed_message_is_skipped_without_retry(self):
        with tempfile.TemporaryDirectory() as directory:
            original_path = QUEUE.PROCESSED_DB_PATH
            original_kind = QUEUE.message_kind
            original_peer_id = QUEUE.marked_peer_id
            original_download = QUEUE.download_video
            original_save_state = QUEUE.save_state
            original_safe_log = QUEUE.safe_log
            calls = []
            events = []

            async def download_video(*args, **kwargs):
                calls.append((args, kwargs))

            QUEUE.PROCESSED_DB_PATH = Path(directory) / "processed.sqlite3"
            QUEUE.message_kind = lambda message: "video"
            QUEUE.marked_peer_id = lambda source: -1001
            QUEUE.download_video = download_video
            QUEUE.save_state = lambda state, status: None
            QUEUE.safe_log = lambda event, **fields: events.append((event, fields))
            try:
                QUEUE.begin_processed_message(-1001, 42, "fixture", "video")
                QUEUE.fail_processed_message(-1001, 42, "download_failed")
                state = {
                    "sources": {
                        "fixture": {
                            "last_scanned_id": 0,
                            "videos": 0,
                            "images": 0,
                            "pending": {"42": {"kind": "video"}},
                        }
                    }
                }

                asyncio.run(
                    QUEUE.process_message(
                        None,
                        SimpleNamespace(),
                        None,
                        SimpleNamespace(id=42),
                        "fixture",
                        {"sources": [{"alias": "fixture", "delete_source": True}]},
                        state,
                    )
                )

                self.assertEqual([], calls)
                self.assertEqual(42, state["sources"]["fixture"]["last_scanned_id"])
                self.assertNotIn("42", state["sources"]["fixture"]["pending"])
                self.assertEqual("failed", QUEUE.processed_message_status(-1001, 42))
                self.assertEqual("processed_message_skipped", events[-1][0])
                self.assertEqual("failed", events[-1][1]["status"])
            finally:
                QUEUE.PROCESSED_DB_PATH = original_path
                QUEUE.message_kind = original_kind
                QUEUE.marked_peer_id = original_peer_id
                QUEUE.download_video = original_download
                QUEUE.save_state = original_save_state
                QUEUE.safe_log = original_safe_log

    def test_new_message_failure_is_recorded_and_skipped(self):
        with tempfile.TemporaryDirectory() as directory:
            original_path = QUEUE.PROCESSED_DB_PATH
            original_kind = QUEUE.message_kind
            original_peer_id = QUEUE.marked_peer_id
            original_download = QUEUE.download_video
            original_save_state = QUEUE.save_state
            original_safe_log = QUEUE.safe_log
            events = []

            async def download_video(*args, **kwargs):
                raise RuntimeError("download_failed")

            QUEUE.PROCESSED_DB_PATH = Path(directory) / "processed.sqlite3"
            QUEUE.message_kind = lambda message: "video"
            QUEUE.marked_peer_id = lambda source: -1001
            QUEUE.download_video = download_video
            QUEUE.save_state = lambda state, status: None
            QUEUE.safe_log = lambda event, **fields: events.append((event, fields))
            try:
                state = {
                    "sources": {
                        "fixture": {
                            "last_scanned_id": 0,
                            "videos": 0,
                            "images": 0,
                            "pending": {"42": {"kind": "video"}},
                        }
                    }
                }

                asyncio.run(
                    QUEUE.process_message(
                        None,
                        SimpleNamespace(),
                        None,
                        SimpleNamespace(id=42),
                        "fixture",
                        {"sources": [{"alias": "fixture", "delete_source": True}]},
                        state,
                    )
                )

                self.assertEqual(42, state["sources"]["fixture"]["last_scanned_id"])
                self.assertNotIn("42", state["sources"]["fixture"]["pending"])
                self.assertEqual("failed", QUEUE.processed_message_status(-1001, 42))
                self.assertEqual("message_failed", events[-1][0])
                self.assertEqual("skipped", events[-1][1]["disposition"])
            finally:
                QUEUE.PROCESSED_DB_PATH = original_path
                QUEUE.message_kind = original_kind
                QUEUE.marked_peer_id = original_peer_id
                QUEUE.download_video = original_download
                QUEUE.save_state = original_save_state
                QUEUE.safe_log = original_safe_log

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


class TelegramMediaQueueDeadlineTest(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.events = []
        self.patch("PROCESSED_DB_PATH", Path(directory.name) / "processed.sqlite3")
        self.patch("save_state", lambda *args: None)
        self.patch("safe_log", lambda event, **fields: self.events.append((event, fields)))

    def patch(self, name, value):
        patcher = patch.object(QUEUE, name, value)
        patcher.start()
        self.addCleanup(patcher.stop)

    def state(self):
        return {"sources": {"fixture": {"last_scanned_id": 0, "pending": {}, "videos": 0, "images": 0}}}

    def stalled_child(self):
        class Child:
            pid = 12345
            returncode = None
            reaped = False

            def __init__(self):
                self.done = asyncio.Event()

            async def communicate(self):
                await self.done.wait()
                self.reaped = True
                return b"", b""

            def kill(self):
                self.returncode = -9
                self.done.set()

        return Child()

    async def test_tdl_timeout_kills_and_reaps_exact_child(self):
        child = self.stalled_child()
        state = self.state()
        with patch.object(QUEUE.asyncio, "create_subprocess_exec", AsyncMock(return_value=child)):
            with self.assertRaises(TimeoutError):
                await asyncio.wait_for(QUEUE.run_tdl(["fixture"], state, "fixture"), 0.02)
        self.assertEqual(-9, child.returncode)
        self.assertTrue(child.reaped)
        self.assertNotIn("tdl_pid", state)

    async def test_extractor_timeout_kills_and_reaps_exact_child(self):
        child = self.stalled_child()
        with patch.object(QUEUE.asyncio, "create_subprocess_exec", AsyncMock(return_value=child)):
            with self.assertRaises(TimeoutError):
                await asyncio.wait_for(
                    QUEUE.extract_zip_once(Path("fixture.zip"), Path("fixture-out"), "fixture-7z", None), 0.02
                )
        self.assertEqual(-9, child.returncode)
        self.assertTrue(child.reaped)

    async def test_message_deadline_skips_hung_item_and_next_item_completes(self):
        calls = []

        async def download(client, source, message, *args):
            calls.append(message.id)
            if message.id == 42:
                await asyncio.Event().wait()

        self.patch("message_kind", lambda message: "video")
        self.patch("marked_peer_id", lambda source: -1001)
        self.patch("download_video", download)
        config = {"sources": [{"alias": "fixture", "delete_source": False}], "message_timeout_seconds": 0.02}
        state = self.state()
        for message_id in (42, 42, 43):
            await QUEUE.process_message(None, None, None, SimpleNamespace(id=message_id), "fixture", config, state)
        self.assertEqual([42, 43], calls)
        self.assertEqual("failed", QUEUE.processed_message_status(-1001, 42))
        self.assertEqual("completed", QUEUE.processed_message_status(-1001, 43))
        self.assertEqual(43, state["sources"]["fixture"]["last_scanned_id"])
        failures = [fields for event, fields in self.events if event == "message_failed"]
        self.assertEqual(1, len(failures))
        self.assertEqual("message_timeout", failures[0]["error_class"])
        self.assertEqual("skipped", failures[0]["disposition"])

    async def test_source_batch_yields_without_skipping_unvisited_messages(self):
        class Client:
            async def get_messages(self, *args, **kwargs):
                return [SimpleNamespace(id=99)]

            async def iter_messages(self, source, min_id, **kwargs):
                for message_id in (10, 20, 30):
                    if message_id > min_id:
                        yield SimpleNamespace(id=message_id)

        visited = []

        async def process(client, source, target, message, alias, config, state):
            visited.append(message.id)
            state["sources"][alias]["last_scanned_id"] = message.id

        self.patch("message_kind", lambda message: "video")
        self.patch("process_message", process)
        state = self.state()
        config = {"source_batch_size": 2}
        self.assertTrue(await QUEUE.process_source(Client(), None, None, "fixture", config, state))
        self.assertEqual([10, 20], visited)
        self.assertEqual(20, state["sources"]["fixture"]["last_scanned_id"])
        self.assertFalse(await QUEUE.process_source(Client(), None, None, "fixture", config, state))
        self.assertEqual([10, 20, 30], visited)
        self.assertEqual(99, state["sources"]["fixture"]["last_scanned_id"])

    async def test_source_time_slice_yields_at_item_boundary(self):
        class Client:
            async def get_messages(self, *args, **kwargs):
                return [SimpleNamespace(id=99)]

            async def iter_messages(self, *args, **kwargs):
                yield SimpleNamespace(id=10)
                yield SimpleNamespace(id=20)

        async def process(client, source, target, message, alias, config, state):
            await asyncio.sleep(1.01)
            state["sources"][alias]["last_scanned_id"] = message.id

        self.patch("message_kind", lambda message: "video")
        self.patch("process_message", process)
        state = self.state()
        await QUEUE.process_source(Client(), None, None, "fixture", {"source_time_slice_seconds": 1}, state)
        self.assertEqual(10, state["sources"]["fixture"]["last_scanned_id"])
        self.assertEqual("source_scan_yielded", self.events[-1][0])

    async def test_worker_waits_five_minutes_only_after_backlog_is_drained(self):
        for yielded, expected_delay in ((True, 1), (False, 300)):
            with self.subTest(yielded=yielded):
                client = SimpleNamespace(
                    connect=AsyncMock(), is_user_authorized=AsyncMock(return_value=True), disconnect=AsyncMock()
                )
                sleep = AsyncMock(side_effect=asyncio.CancelledError)
                with (
                    patch.object(QUEUE, "load_state", return_value=self.state()),
                    patch.object(QUEUE, "TelegramClient", return_value=client),
                    patch.object(QUEUE, "resolve_exact_dialogs", AsyncMock(return_value={"fixture": None, "image_target": None})),
                    patch.object(QUEUE, "process_source", AsyncMock(return_value=yielded)),
                    patch.object(QUEUE.asyncio, "sleep", sleep),
                ):
                    with self.assertRaises(asyncio.CancelledError):
                        await QUEUE.run_worker({"api_id": 1, "api_hash": "fixture", "sources": [{"alias": "fixture"}]}, False)
                sleep.assert_awaited_once_with(expected_delay)
                client.disconnect.assert_awaited_once()


if __name__ == "__main__":
    unittest.main()
