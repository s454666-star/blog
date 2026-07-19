import importlib.util
import json
import tempfile
import unittest
import urllib.parse
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve().parents[2] / "scripts" / "telegram_media_router.py"
SPEC = importlib.util.spec_from_file_location("telegram_media_router", SCRIPT_PATH)
router_module = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(router_module)


class FakeApi:
    def __init__(self, groups, register_results=None):
        self.groups = groups
        self.register_results = register_results or {}
        self.copy_calls = []
        self.register_calls = []
        self.delete_calls = []

    def get(self, path, timeout=180.0):
        parsed = urllib.parse.urlparse(path)
        peer_id = int(parsed.path.split("/")[-1])
        query = urllib.parse.parse_qs(parsed.query)
        min_id = int(query.get("min_id", [0])[0])
        limit = int(query.get("limit", [100])[0])
        items = [
            dict(item)
            for item in self.groups.get(peer_id, [])
            if int(item.get("id") or 0) > min_id
        ][:limit]
        return {"status": "ok", "items": items, "count": len(items)}

    def post(self, path, payload, timeout=7200.0):
        if path == "/messages/copy-protected-media-batch":
            self.copy_calls.append(dict(payload))
            message_id = int(payload["message_ids"][0])
            target_message_id = 1000 + message_id
            return {
                "status": "ok",
                "results": [
                    {
                        "source_message_id": message_id,
                        "target_message_id": target_message_id,
                        "content_sha256": f"hash-{message_id}",
                        "duplicate": False,
                    }
                ],
            }
        if path == "/messages/register-media-hash":
            self.register_calls.append(dict(payload))
            message_id = int(payload["message_id"])
            result = dict(self.register_results.get(message_id) or {})
            return {
                "status": "ok",
                "target_message_id": int(result.get("target_message_id") or message_id),
                "content_sha256": result.get("content_sha256") or f"target-hash-{message_id}",
                "file_size": 10,
                "duplicate": bool(result.get("duplicate")),
            }
        if path == "/bots/delete-messages":
            self.delete_calls.append(dict(payload))
            return {"status": "ok", "deleted_count": len(payload["message_ids"])}
        raise AssertionError(f"unexpected POST {path}: {json.dumps(payload)}")


class TelegramMediaRouterTest(unittest.TestCase):
    def setUp(self):
        self.tempdir = tempfile.TemporaryDirectory()
        self.database_path = str(Path(self.tempdir.name) / "router.sqlite3")
        self.connection = router_module.connect_database(self.database_path)
        router_module.configure_router(
            self.connection,
            base_uri="http://127.0.0.1:8004",
            video_target_peer_id=200,
            image_target_peer_id=300,
            dedupe_scope="test_scope",
            scan_limit=40,
            idle_seconds=1,
        )

    def tearDown(self):
        self.connection.close()
        self.tempdir.cleanup()

    def test_sources_are_table_driven_and_upserted(self):
        router_module.add_source(self.connection, 100, "Source A")
        router_module.add_source(self.connection, 100, "Source A renamed")
        router_module.add_source(self.connection, 101, "Source B")
        router_module.set_source_enabled(self.connection, 101, False)

        status = router_module.router_status(self.connection, self.database_path)

        self.assertEqual(2, len(status["sources"]))
        self.assertEqual("Source A renamed", status["sources"][0]["title"])
        self.assertEqual(0, status["sources"][1]["enabled"])

    def test_routes_images_and_videos_and_ignores_other_messages(self):
        router_module.add_source(self.connection, 100, "Source")
        api = FakeApi(
            {
                200: [{"id": 1, "media_kind": None}],
                300: [{"id": 1, "media_kind": None}],
                100: [
                    {"id": 1, "media_kind": None},
                    {"id": 2, "media_kind": "photo"},
                    {"id": 3, "media_kind": "video_document"},
                    {"id": 4, "media_kind": "document"},
                ],
            }
        )
        router = router_module.Router(self.connection, self.database_path, api=api)

        processed = router.run_once()

        self.assertEqual(6, processed)
        self.assertEqual([300, 200], [call["target_peer_id"] for call in api.copy_calls])
        source = self.connection.execute(
            "SELECT * FROM router_sources WHERE peer_id = 100"
        ).fetchone()
        self.assertEqual(4, source["last_message_id"])
        counts = router_module.router_status(self.connection, self.database_path)["item_counts"]
        self.assertEqual(2, counts["completed"])
        self.assertEqual(2, counts["ignored"])

        api.groups[200] = [{"id": 1003, "media_kind": "video_document"}]
        api.groups[300] = [{"id": 1002, "media_kind": "photo"}]
        register_call_count = len(api.register_calls)

        second_processed = router.run_once()

        self.assertEqual(2, second_processed)
        self.assertEqual(register_call_count, len(api.register_calls))
        managed_count = self.connection.execute(
            "SELECT COUNT(*) FROM router_target_items WHERE status = 'managed'"
        ).fetchone()[0]
        self.assertEqual(2, managed_count)

    def test_target_index_keeps_first_hash_and_deletes_later_duplicate(self):
        api = FakeApi(
            {
                200: [
                    {"id": 1, "media_kind": "video"},
                    {"id": 2, "media_kind": "video"},
                ],
                300: [],
            },
            register_results={
                1: {"target_message_id": 1, "duplicate": False},
                2: {"target_message_id": 1, "duplicate": True},
            },
        )
        router = router_module.Router(self.connection, self.database_path, api=api)

        processed = router.run_once()

        self.assertEqual(2, processed)
        self.assertEqual(
            [{"chat_peer": "200", "message_ids": [2]}],
            api.delete_calls,
        )
        settings = self.connection.execute(
            "SELECT * FROM router_settings WHERE id = 1"
        ).fetchone()
        self.assertEqual(2, settings["video_target_cursor"])
        row = self.connection.execute(
            """
            SELECT * FROM router_target_items
            WHERE target_peer_id = 200 AND target_message_id = 2
            """
        ).fetchone()
        self.assertEqual("duplicate_deleted", row["status"])
        self.assertEqual(1, row["canonical_target_message_id"])


if __name__ == "__main__":
    unittest.main()
