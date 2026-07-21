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
    migrator.args = types.SimpleNamespace(source_peer_id=8766016058)
    migrator.saved = 0
    migrator.logs = []
    migrator.save = lambda: setattr(migrator, "saved", migrator.saved + 1)
    migrator.log = lambda message, **fields: migrator.logs.append((message, fields))
    return migrator


class TelegramEpanRecoveryTest(unittest.TestCase):
    def test_missing_source_schedules_guarded_folder_recovery(self):
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
                "blocked_reason": "old",
                "blocked_at": "old",
            }
        )

        migrator.schedule_source_page_recovery(2668, "video")

        self.assertEqual("running", migrator.state["status"])
        self.assertEqual("resume_current_folder", migrator.state["stage"])
        self.assertEqual(1, migrator.state["source_recovery_count"])
        self.assertNotIn("active_source_message_id", migrator.state)
        self.assertNotIn("copy_target_baseline", migrator.state)
        self.assertNotIn("blocked_reason", migrator.state)

    def test_replay_page_advances_without_changing_canonical_progress(self):
        migrator = bare_migrator(
            {
                "stage": "replay_source_pages",
                "folder_index": 5,
                "folder_next_group_clicks": 302,
                "current_page_processed": 3,
                "replay_next_groups_remaining": 2,
            }
        )
        control = {
            "id": 900,
            "reply_markup": {
                "rows": [{"buttons": [{"text": "➡️ 点击查看下一组"}]}]
            },
        }
        migrator.current_page = lambda: ([], control)
        clicks = []
        migrator.click = lambda keyword: clicks.append(keyword) or {}

        migrator.replay_source_page()

        self.assertEqual(["下一组"], clicks)
        self.assertEqual(1, migrator.state["replay_next_groups_remaining"])
        self.assertEqual(302, migrator.state["folder_next_group_clicks"])
        self.assertEqual(3, migrator.state["current_page_processed"])
        self.assertEqual(900, migrator.state["previous_control_id"])

    def test_recovered_page_processes_pending_item_before_replay_cleanup(self):
        migrator = bare_migrator(
            {
                "stage": "process_recovered_page",
                "folder_processed": 227,
                "current_page_processed": 2,
                "replay_current_page_processed": 2,
                "replay_next_groups_remaining": 0,
            }
        )
        items = [
            {"_": "Message", "id": 1, "message": "old text"},
            {"_": "Message", "id": 2, "message": "old text"},
            {
                "_": "Message",
                "id": 3,
                "media": {
                    "_": "MessageMediaDocument",
                    "document": {"mime_type": "video/mp4"},
                },
            },
        ]
        control = {"id": 4, "reply_markup": {"rows": []}}
        migrator.current_page = lambda: (items, control)
        actions = []

        def copy_media(item):
            actions.append(("copy", item["id"]))
            migrator.state["folder_processed"] += 1
            migrator.state["stage"] = "process_page"

        migrator.copy_media = copy_media
        migrator.delete_text_item = lambda message_id: actions.append(("text", message_id))
        migrator.message_exists = lambda _peer, _message_id: True
        migrator.delete_source = lambda ids: actions.append(("cleanup", ids))
        migrator.finish_folder = lambda _control: actions.append(("finish", None))

        migrator.process_recovered_page()

        self.assertEqual(("copy", 3), actions[0])
        self.assertEqual(("cleanup", [1, 2]), actions[1])
        self.assertEqual(("finish", None), actions[2])
        self.assertEqual(228, migrator.state["folder_processed"])
        self.assertEqual(3, migrator.state["current_page_processed"])
        self.assertNotIn("replay_current_page_processed", migrator.state)

    def test_recovered_page_blocks_when_pending_item_is_not_regenerated(self):
        migrator = bare_migrator(
            {
                "stage": "process_recovered_page",
                "folder_processed": 227,
                "current_page_processed": 2,
                "replay_current_page_processed": 2,
                "source_recovery_source_message_id": 2668,
                "source_recovery_media_kind": "video",
            }
        )
        items = [
            {"_": "Message", "id": 1, "message": "old text"},
            {"_": "Message", "id": 2, "message": "old text"},
        ]
        migrator.current_page = lambda: (items, {"id": 3, "reply_markup": {}})

        with self.assertRaisesRegex(
            MODULE.MigrationBlocked,
            "did not regenerate the pending item",
        ):
            migrator.process_recovered_page()


if __name__ == "__main__":
    unittest.main()
