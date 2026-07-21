import importlib.util
import types
import unittest
from pathlib import Path


MODULE_PATH = (
    Path(__file__).resolve().parents[2]
    / "scripts"
    / "telegram_epan_folder_migrator.py"
)
SPEC = importlib.util.spec_from_file_location("telegram_epan_folder_migrator", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


def bare_migrator(state):
    migrator = MODULE.Migrator.__new__(MODULE.Migrator)
    migrator.state = state
    migrator.args = types.SimpleNamespace(
        source_peer_id=8766016058,
        source_bot="yuanchaungbot",
    )
    migrator.video_target_peer_id = 3995547485
    migrator.image_target_peer_id = 4367037987
    migrator.saved = 0
    migrator.logs = []
    migrator.save = lambda: setattr(migrator, "saved", migrator.saved + 1)
    migrator.log = lambda message, **fields: migrator.logs.append((message, fields))
    return migrator


class TelegramEpanRecoveryTest(unittest.TestCase):
    @staticmethod
    def folder_start_counts():
        return {
            "processed_total": 1515,
            "copied_media": 1475,
            "copied_images": 210,
            "copied_videos": 1265,
            "deleted_text": 12,
            "source_media_processed": 1503,
            "source_images": 233,
            "source_videos": 1270,
            "duplicate_media": 28,
            "duplicate_images": 23,
            "duplicate_videos": 5,
        }

    def test_missing_source_rolls_back_to_folder_start_before_recovery(self):
        migrator = bare_migrator(
            {
                "status": "blocked",
                "stage": "copying_source",
                "folder_index": 5,
                "folder_processed": 227,
                "folder_next_group_clicks": 302,
                "current_page_processed": 3,
                "active_source_message_id": 2668,
                "active_media_kind": "video",
                "copy_target_baseline": 1491,
                "processed_total": 1742,
                "copied_media": 1698,
                "folder_start_counts": self.folder_start_counts(),
                "blocked_reason": "old",
                "blocked_at": "old",
            }
        )

        migrator.schedule_source_page_recovery(2668, "video")

        self.assertEqual("running", migrator.state["status"])
        self.assertEqual("resume_current_folder", migrator.state["stage"])
        self.assertEqual(1, migrator.state["source_recovery_count"])
        self.assertEqual(1515, migrator.state["processed_total"])
        self.assertEqual(1475, migrator.state["copied_media"])
        self.assertEqual(0, migrator.state["folder_processed"])
        self.assertEqual(0, migrator.state["folder_next_group_clicks"])
        self.assertEqual(0, migrator.state["current_page_processed"])
        self.assertNotIn("active_source_message_id", migrator.state)
        self.assertNotIn("copy_target_baseline", migrator.state)
        self.assertNotIn("blocked_reason", migrator.state)

    def test_recovery_blocks_without_folder_start_snapshot(self):
        migrator = bare_migrator({"source_recovery_count": 0})
        with self.assertRaisesRegex(
            MODULE.MigrationBlocked,
            "no complete folder-start counter snapshot",
        ):
            migrator.schedule_source_page_recovery(2668, "video")

    def test_resume_restarts_current_folder_from_its_first_page(self):
        migrator = bare_migrator(
            {
                "stage": "resume_current_folder",
                "folder_index": 5,
                "source_recovery_count": 1,
                "folder_start_counts": self.folder_start_counts(),
            }
        )
        migrator.folders = [("folder", 1)] * 5
        posts = []
        migrator.api = types.SimpleNamespace(
            post=lambda path, payload, timeout: posts.append((path, payload, timeout))
            or {"status": "ok", "sent_message_id": 2711}
        )
        clicks = []
        migrator.click = lambda keyword: clicks.append(keyword) or {}
        navigated = []
        migrator.navigate_to_folder = lambda index: navigated.append(index)

        migrator.resume_current_folder()

        self.assertEqual("/bots/send", posts[0][0])
        self.assertTrue(posts[0][1]["clear_previous_replies"])
        self.assertEqual(["文件夹列表"], clicks)
        self.assertEqual([5], navigated)
        self.assertEqual(2711, migrator.state["source_recovery_start_message_id"])

    def test_page_media_is_copied_as_one_batch_before_source_deletion(self):
        state = {
            **self.folder_start_counts(),
            "stage": "process_page",
            "folder_index": 5,
            "folder_processed": 0,
            "current_page_processed": 0,
        }
        migrator = bare_migrator(state)
        migrator.dedupe_scope = "epan_originals_combined"
        migrator.video_target_peer_id = 3995547485
        migrator.image_target_peer_id = 4367037987
        calls = []

        def post(path, payload, timeout):
            calls.append((path, payload, timeout))
            return {
                "status": "ok",
                "results": [
                    {
                        "source_message_id": message_id,
                        "target_message_id": 2000 + offset,
                        "content_sha256": f"{offset + 1:064x}",
                        "duplicate": False,
                    }
                    for offset, message_id in enumerate(payload["message_ids"])
                ],
            }

        migrator.api = types.SimpleNamespace(post=post)
        page_items = [
            {
                "_": "Message",
                "id": message_id,
                "media": {
                    "_": "MessageMediaDocument",
                    "document": {"mime_type": "video/mp4"},
                },
            }
            for message_id in (2717, 2718, 2719)
        ]

        migrator.prepare_page_items(page_items)

        self.assertEqual("page_items_ready", migrator.state["stage"])
        self.assertEqual(1, len(calls))
        self.assertEqual([2717, 2718, 2719], calls[0][1]["message_ids"])
        self.assertEqual(3, len(migrator.state["active_page_results"]))

    def test_ready_page_is_deleted_together_and_counted_once(self):
        state = {
            **self.folder_start_counts(),
            "stage": "page_items_ready",
            "folder_index": 5,
            "folder_processed": 0,
            "current_page_processed": 0,
            "last_source_message_id": 0,
            "last_target_message_id": 0,
            "last_video_target_message_id": 0,
            "active_page_media": [
                {"source_message_id": 2717, "kind": "video"},
                {"source_message_id": 2718, "kind": "video"},
            ],
            "active_page_text_ids": [2715],
            "active_page_results": [
                {
                    "source_message_id": 2717,
                    "target_message_id": 2001,
                    "target_peer_id": 3995547485,
                    "kind": "video",
                    "duplicate": True,
                    "content_sha256": "1" * 64,
                },
                {
                    "source_message_id": 2718,
                    "target_message_id": 2002,
                    "target_peer_id": 3995547485,
                    "kind": "video",
                    "duplicate": False,
                    "content_sha256": "2" * 64,
                },
            ],
        }
        migrator = bare_migrator(state)
        deleted = []
        migrator.delete_source = lambda ids: deleted.append(ids)

        migrator.complete_page_items()

        self.assertEqual([[2717, 2718, 2715]], deleted)
        self.assertEqual("process_page", migrator.state["stage"])
        self.assertEqual(1518, migrator.state["processed_total"])
        self.assertEqual(3, migrator.state["folder_processed"])
        self.assertEqual(1476, migrator.state["copied_media"])
        self.assertEqual(29, migrator.state["duplicate_media"])
        self.assertEqual(13, migrator.state["deleted_text"])
        self.assertEqual(3, migrator.state["current_page_processed"])
        self.assertNotIn("active_page_results", migrator.state)


if __name__ == "__main__":
    unittest.main()
