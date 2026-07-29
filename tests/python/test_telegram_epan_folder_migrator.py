import importlib.util
import json
import tempfile
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

    def test_systemd_continuous_flags_cover_both_manifests(self):
        unit_path = (
            Path(__file__).resolve().parents[2]
            / "deploy"
            / "systemd"
            / "blog-telegram-epan-migration.service"
        )
        exec_start = next(
            line
            for line in unit_path.read_text(encoding="utf-8").splitlines()
            if line.startswith("ExecStart=")
        )

        for manifest_name in ("original_backup.json", "ygt7319.json"):
            command = next(
                part
                for part in exec_start.split(";")
                if manifest_name in part
            )
            self.assertIn("--fresh", command)
            self.assertIn("--restart-complete", command)
            self.assertIn("--restart-target-drift", command)
        self.assertNotIn("set -e", exec_start)
        self.assertIn("original_status=$?", exec_start)
        self.assertIn("ygt_status=$?", exec_start)
        self.assertLess(
            exec_start.index("original_backup.json"),
            exec_start.index("ygt7319.json"),
        )

    def test_source_unavailable_has_a_distinct_retry_exit(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "start_first_folder",
                "source_unavailable_count": 0,
            }
        )
        migrator.api = types.SimpleNamespace(
            post=lambda path, payload, timeout: {
                "status": "error",
                "reason": "bot_unavailable",
            }
        )
        migrator.target_counts = lambda peer_id: {
            "media": 0,
            "images": 0,
            "videos": 0,
            "text": 0,
            "attribution": 0,
        }

        with self.assertRaises(MODULE.MigrationSourceUnavailable):
            migrator.start_first_folder()

    def test_empty_source_folder_at_recovery_limit_advances_without_counters(self):
        start_counts = self.folder_start_counts()
        migrator = bare_migrator(
            {
                **start_counts,
                "status": "running",
                "stage": "process_page",
                "folder_index": 8,
                "folder_expected": 1,
                "folder_processed": 0,
                "source_recovery_count": 10,
                "folder_start_counts": start_counts,
            }
        )

        migrator.schedule_folder_control_recovery(
            "folder_control_missing_next_before_expected_count",
            46072,
        )

        self.assertEqual("running", migrator.state["status"])
        self.assertEqual("advance_folder", migrator.state["stage"])
        self.assertEqual(1515, migrator.state["processed_total"])
        self.assertEqual(1475, migrator.state["copied_media"])
        self.assertEqual(28, migrator.state["duplicate_media"])
        self.assertEqual(1, migrator.state["unavailable_source_missing_total"])
        self.assertEqual(
            [
                {
                    "folder_index": 8,
                    "missing_count": 1,
                    "recovery_count": 10,
                }
            ],
            migrator.state["unavailable_source_folders"],
        )

    def test_resumed_process_page_timeout_uses_unavailable_folder_ledger(self):
        start_counts = self.folder_start_counts()
        migrator = bare_migrator(
            {
                **start_counts,
                "status": "running",
                "stage": "process_page",
                "folder_index": 8,
                "folder_expected": 1,
                "folder_processed": 0,
                "source_recovery_count": 10,
                "folder_start_counts": start_counts,
                "previous_control_id": 46072,
            }
        )
        migrator.current_page = lambda: (_ for _ in ()).throw(
            MODULE.MigrationBlocked(MODULE.PAGE_CONTROL_TIMEOUT_MESSAGE)
        )

        migrator.process_current_page()

        self.assertEqual("advance_folder", migrator.state["stage"])
        self.assertEqual(1, migrator.state["unavailable_source_missing_total"])
        self.assertEqual(1515, migrator.state["processed_total"])
        self.assertEqual(1475, migrator.state["copied_media"])
        self.assertEqual(28, migrator.state["duplicate_media"])

    def test_bot_control_requests_include_durable_source_peer_id(self):
        migrator = bare_migrator({"start_message_id": 10})
        posts = []

        def post(path, payload, timeout):
            posts.append((path, payload, timeout))
            if path == "/bots/click-matching-button":
                return {
                    "status": "ok",
                    "button_clicked": True,
                    "clicked_button_text": "1",
                    "clicked_message_id": 11,
                }
            return {"status": "ok"}

        migrator.api = types.SimpleNamespace(post=post)

        migrator.backfill_source()
        migrator.click("1", wait_attempts=1)
        migrator.delete_source([12])

        payloads = {path: payload for path, payload, _timeout in posts}
        self.assertEqual(
            8766016058,
            payloads["/bots/files"]["bot_peer_id"],
        )
        self.assertEqual(
            8766016058,
            payloads["/bots/click-matching-button"]["bot_peer_id"],
        )
        self.assertEqual(
            "8766016058",
            payloads["/bots/delete-messages"]["chat_peer"],
        )

    def test_fresh_restart_archives_completed_checkpoint(self):
        with tempfile.TemporaryDirectory() as temporary_directory:
            state_path = Path(temporary_directory) / "state.json"
            manifest = {
                "name": "test",
                "source_bot": "source",
                "source_peer_id": 100,
                "target_peer_id": 200,
                "video_target_peer_id": 200,
                "image_target_peer_id": 300,
                "dedupe_scope": "scope",
            }
            state_path.write_text(
                json.dumps(
                    {
                        "status": "complete",
                        "stage": "complete",
                        "manifest_name": "test",
                        "source_bot": "source",
                        "source_peer_id": 100,
                        "target_peer_id": 200,
                        "video_target_peer_id": 200,
                        "image_target_peer_id": 300,
                        "dedupe_scope": "scope",
                        "processed_total": 10,
                        "copied_media": 5,
                    }
                ),
                encoding="utf-8",
            )
            migrator = MODULE.Migrator.__new__(MODULE.Migrator)
            migrator.args = types.SimpleNamespace(
                state_path=str(state_path),
                fresh=True,
                restart_complete=True,
                restart_target_drift=False,
            )
            migrator.manifest = manifest
            migrator.dedupe_scope = "scope"
            migrator.video_target_peer_id = 200
            migrator.image_target_peer_id = 300

            state = migrator.load_or_initialize_state()

            backups = list(
                Path(temporary_directory).glob("state.json.complete-*")
            )
            self.assertEqual(1, len(backups))
            archived = json.loads(backups[0].read_text(encoding="utf-8"))
            self.assertEqual("complete", archived["status"])
            self.assertEqual("running", state["status"])
            self.assertEqual("start_first_folder", state["stage"])
            self.assertEqual(0, state["processed_total"])
            self.assertEqual(0, state["copied_media"])

    def test_fresh_restart_archives_target_drift_checkpoint(self):
        with tempfile.TemporaryDirectory() as temporary_directory:
            state_path = Path(temporary_directory) / "state.json"
            manifest = {
                "name": "test",
                "source_bot": "source",
                "source_peer_id": 100,
                "target_peer_id": 200,
                "video_target_peer_id": 200,
                "image_target_peer_id": 300,
                "dedupe_scope": "scope",
            }
            state_path.write_text(
                json.dumps(
                    {
                        "status": "blocked",
                        "stage": "verify_target",
                        "blocked_reason": MODULE.TARGET_MEDIA_DELTA_BELOW_COMMITTED,
                        "manifest_name": "test",
                        "source_bot": "source",
                        "source_peer_id": 100,
                        "target_peer_id": 200,
                        "video_target_peer_id": 200,
                        "image_target_peer_id": 300,
                        "dedupe_scope": "scope",
                        "processed_total": 10,
                        "copied_media": 0,
                    }
                ),
                encoding="utf-8",
            )
            migrator = MODULE.Migrator.__new__(MODULE.Migrator)
            migrator.args = types.SimpleNamespace(
                state_path=str(state_path),
                fresh=True,
                restart_complete=True,
                restart_target_drift=True,
            )
            migrator.manifest = manifest
            migrator.dedupe_scope = "scope"
            migrator.video_target_peer_id = 200
            migrator.image_target_peer_id = 300

            state = migrator.load_or_initialize_state()

            backups = list(
                Path(temporary_directory).glob(
                    "state.json.blocked-target-drift-*"
                )
            )
            self.assertEqual(1, len(backups))
            archived = json.loads(backups[0].read_text(encoding="utf-8"))
            self.assertEqual("blocked", archived["status"])
            self.assertEqual("running", state["status"])
            self.assertEqual("start_first_folder", state["stage"])
            self.assertEqual(0, state["processed_total"])
            self.assertEqual(0, state["copied_media"])

    def test_folder_list_uses_ten_numeric_positions_per_page(self):
        self.assertEqual((1, 10), MODULE.folder_list_location(10))
        self.assertEqual((2, 1), MODULE.folder_list_location(11))
        self.assertEqual((2, 10), MODULE.folder_list_location(20))
        self.assertEqual((3, 1), MODULE.folder_list_location(21))

    def test_advance_folder_resumes_without_reprocessing_completed_page(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "advance_folder",
                "folder_index": 9,
                "folder_processed": 486,
            }
        )
        migrator.folders = [("folder", 1)] * 14
        navigated = []
        migrator.navigate_from_source_root = (
            lambda index, *, reason: navigated.append((index, reason))
        )

        migrator.advance_folder()

        self.assertEqual([(10, "checkpoint_resume")], navigated)
        self.assertEqual(486, migrator.state["folder_processed"])

    def test_current_page_accepts_stable_media_without_control(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "process_page",
                "folder_index": 10,
                "previous_control_id": 100,
            }
        )
        page_items = [
            {
                "_": "Message",
                "id": 101,
                "media": {
                    "_": "MessageMediaDocument",
                    "document": {"mime_type": "video/mp4"},
                },
            },
            {
                "_": "Message",
                "id": 102,
                "media": {
                    "_": "MessageMediaDocument",
                    "document": {"mime_type": "video/mp4"},
                },
            },
        ]
        migrator.messages = lambda peer_id, limit: page_items
        fake_clock = [0.0]
        original_time = MODULE.time.time
        original_sleep = MODULE.time.sleep
        MODULE.time.time = lambda: fake_clock[0]
        MODULE.time.sleep = lambda seconds: fake_clock.__setitem__(
            0,
            fake_clock[0] + seconds,
        )
        try:
            items, control = migrator.current_page()
        finally:
            MODULE.time.time = original_time
            MODULE.time.sleep = original_sleep

        self.assertEqual(page_items, items)
        self.assertTrue(control["partial_without_control"])
        self.assertEqual(102, control["id"])

    def test_recovery_navigation_preserves_exhausted_replay_evidence(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "resume_current_folder",
                "folder_index": 10,
                "source_recovery_count": 5,
                "last_exhausted_replay_observed_count": 387,
                "matching_exhausted_replay_count": 5,
            }
        )
        migrator.folders = [("folder", 1)] * 10
        migrator.click = lambda keyword: {
            "clicked_message_id": 500,
        }
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "message": "folder 消息数：1",
                    }
                ],
            }
        )
        migrator.current_page = lambda: ([], {"id": 501})

        migrator.navigate_to_folder(10)

        self.assertEqual(
            387,
            migrator.state["last_exhausted_replay_observed_count"],
        )
        self.assertEqual(
            5,
            migrator.state["matching_exhausted_replay_count"],
        )
        self.assertEqual(5, migrator.state["source_recovery_count"])

    def test_navigation_accepts_renamed_folder_when_count_and_position_match(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "advance_folder",
                "folder_index": 10,
                "source_recovery_count": 10,
            }
        )
        migrator.folders = [("folder", 1)] * 10 + [("old-name", 496)]
        migrator.click = lambda keyword: {
            "clicked_message_id": 700,
        }
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "message": "renamed-folder 消息数：496",
                    }
                ],
            }
        )
        migrator.current_page = lambda: ([], {"id": 701})

        migrator.navigate_to_folder(11)

        self.assertEqual(11, migrator.state["folder_index"])
        self.assertEqual(496, migrator.state["folder_expected"])
        self.assertEqual(0, migrator.state["source_recovery_count"])
        self.assertTrue(
            any(
                message == "folder_name_drift_accepted"
                for message, _ in migrator.logs
            )
        )

    def test_navigation_accepts_and_records_folder_count_decrease(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "advance_folder",
                "folder_index": 10,
            }
        )
        migrator.folders = [("folder", 1)] * 10 + [("old-name", 496)]
        migrator.click = lambda keyword: {
            "clicked_message_id": 700,
        }
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "message": "renamed-folder 消息数：495",
                    }
                ],
            }
        )
        migrator.current_page = lambda: ([], {"id": 701})

        migrator.navigate_to_folder(11)

        self.assertEqual(495, migrator.state["folder_expected"])
        self.assertEqual({"11": 495}, migrator.state["folder_count_overrides"])
        self.assertEqual(-1, migrator.state["manifest_folder_count_drift_total"])

    def test_navigation_blocks_when_previously_observed_count_changes(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "advance_folder",
                "folder_index": 10,
                "folder_count_overrides": {"11": 495},
            }
        )
        migrator.folders = [("folder", 1)] * 10 + [("old-name", 496)]
        migrator.click = lambda keyword: {
            "clicked_message_id": 700,
        }
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "message": "renamed-folder 消息数：494",
                    }
                ],
            }
        )

        with self.assertRaisesRegex(
            MODULE.MigrationBlocked,
            "folder detail count mismatch",
        ):
            migrator.navigate_to_folder(11)

    def test_navigation_accepts_and_records_folder_count_increase(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "advance_folder",
                "folder_index": 0,
            }
        )
        migrator.folders = [("folder", 32)]
        migrator.click = lambda keyword: {
            "clicked_message_id": 700,
        }
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "message": "folder 消息数：52",
                    }
                ],
            }
        )
        migrator.current_page = lambda: ([], {"id": 701})

        migrator.navigate_to_folder(1)

        self.assertEqual(52, migrator.state["folder_expected"])
        self.assertEqual({"1": 52}, migrator.state["folder_count_overrides"])
        self.assertEqual(20, migrator.state["manifest_folder_count_drift_total"])

    def test_navigation_waits_for_separate_folder_detail_message(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "stage": "advance_folder",
                "folder_index": 2,
            }
        )
        migrator.folders = [("folder", 1)] * 2 + [("folder", 28)]
        click_responses = [
            {"clicked_message_id": 18054},
            {"clicked_message_id": 18055},
        ]
        migrator.click = lambda keyword: click_responses.pop(0)
        migrator.backfill_source = lambda: None
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "id": 18054,
                        "message": "pending",
                    }
                ],
            }
        )
        migrator.messages = lambda *args, **kwargs: [
            {
                "id": 18054,
                "message": "pending",
            },
            {
                "id": 18055,
                "message": "folder 消息数：28",
            },
        ]
        migrator.current_page = lambda: ([], {"id": 18056})

        migrator.navigate_to_folder(3)

        self.assertEqual(3, migrator.state["folder_index"])
        self.assertEqual(28, migrator.state["folder_expected"])
        self.assertTrue(
            any(
                message == "folder_detail_ready"
                and fields["delayed_response"]
                for message, fields in migrator.logs
            )
        )

    def test_navigation_completes_zero_count_folder_without_waiting_for_page(self):
        migrator = bare_migrator(
            {
                **self.folder_start_counts(),
                "status": "running",
                "stage": "resume_current_folder",
                "folder_index": 15,
                "source_recovery_count": 10,
            }
        )
        migrator.folders = [("folder", 1)] * 14 + [
            ("empty-folder", 4),
            ("next-folder", 1),
        ]
        clicks = []

        def click(keyword):
            clicks.append(keyword)
            return {"clicked_message_id": 18756}

        migrator.click = click
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "id": 18756,
                        "message": "empty-folder 消息数：0",
                    }
                ],
            }
        )
        migrator.current_page = lambda: self.fail(
            "zero-count folder must not wait for a source page"
        )
        navigated = []
        migrator.navigate_from_source_root = (
            lambda index, *, reason: navigated.append((index, reason))
        )

        migrator.navigate_to_folder(15)

        self.assertEqual(["下一页", "5"], clicks)
        self.assertEqual(0, migrator.state["folder_expected"])
        self.assertEqual(0, migrator.state["folder_processed"])
        self.assertEqual(0, migrator.state["source_recovery_count"])
        self.assertEqual([(16, "folder_completed")], navigated)
        self.assertTrue(
            any(
                message == "empty_folder_completed"
                for message, _ in migrator.logs
            )
        )

    def test_navigation_recovers_when_initial_page_control_times_out(self):
        start_counts = self.folder_start_counts()
        migrator = bare_migrator(
            {
                **start_counts,
                "status": "running",
                "stage": "resume_current_folder",
                "folder_index": 1,
                "source_recovery_count": 1,
                "folder_start_counts": start_counts,
            }
        )
        migrator.folders = [("folder", 1)]
        migrator.click = lambda keyword: {
            "clicked_message_id": 700,
        }
        migrator.api = types.SimpleNamespace(
            get=lambda path, timeout: {
                "items": [
                    {
                        "message": "folder 消息数：1",
                    }
                ],
            }
        )
        migrator.current_page = lambda: (_ for _ in ()).throw(
            MODULE.MigrationBlocked(MODULE.PAGE_CONTROL_TIMEOUT_MESSAGE)
        )

        migrator.navigate_to_folder(1)

        self.assertEqual("resume_current_folder", migrator.state["stage"])
        self.assertEqual(2, migrator.state["source_recovery_count"])
        self.assertEqual(0, migrator.state["folder_processed"])

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
        self.assertEqual(8766016058, posts[0][1]["bot_peer_id"])
        self.assertEqual(["文件夹"], clicks)
        self.assertEqual([5], navigated)
        self.assertEqual(2711, migrator.state["source_recovery_start_message_id"])

    def test_repeated_empty_source_pages_restart_current_folder(self):
        migrator = bare_migrator(
            {
                **self.folder_start_counts(),
                "status": "running",
                "stage": "process_page",
                "folder_index": 5,
                "folder_processed": 224,
                "folder_next_group_clicks": 673,
                "consecutive_empty_source_pages": 2,
                "current_page_processed": 0,
                "source_recovery_count": 1,
                "folder_start_counts": self.folder_start_counts(),
            }
        )
        control = {
            "id": 8084,
            "reply_markup": {
                "rows": [{"buttons": [{"text": "下一组"}]}],
            },
        }
        migrator.current_page = lambda: ([], control)
        migrator.click = lambda keyword: self.fail("empty-page recovery must not click again")

        migrator.process_current_page()

        self.assertEqual("resume_current_folder", migrator.state["stage"])
        self.assertEqual(2, migrator.state["source_recovery_count"])
        self.assertEqual(1515, migrator.state["processed_total"])
        self.assertEqual(0, migrator.state["folder_processed"])
        self.assertEqual(0, migrator.state["folder_next_group_clicks"])
        self.assertEqual(0, migrator.state["consecutive_empty_source_pages"])
        self.assertEqual("repeated_empty_source_pages", migrator.state["source_recovery_reason"])

    def test_right_arrow_advances_updated_media_page(self):
        migrator = bare_migrator(
            {
                **self.folder_start_counts(),
                "status": "running",
                "stage": "process_page",
                "folder_index": 5,
                "folder_processed": 1,
                "folder_next_group_clicks": 0,
                "consecutive_empty_source_pages": 0,
                "current_page_processed": 0,
            }
        )
        control = {
            "id": 9000,
            "reply_markup": {
                "rows": [{"buttons": [{"text": "➡"}]}],
            },
        }
        migrator.current_page = lambda: ([], control)
        clicks = []
        migrator.click = lambda keyword: clicks.append(keyword) or {}

        migrator.process_current_page()

        self.assertEqual([["下一组", "下一組", "➡"]], clicks)
        self.assertEqual(9000, migrator.state["previous_control_id"])
        self.assertEqual(1, migrator.state["folder_next_group_clicks"])

    def test_missing_next_page_navigation_restarts_current_folder(self):
        start_counts = self.folder_start_counts()
        migrator = bare_migrator(
            {
                **start_counts,
                "status": "running",
                "stage": "process_page",
                "folder_index": 17,
                "folder_expected": 255,
                "folder_processed": 209,
                "folder_next_group_clicks": 8,
                "consecutive_empty_source_pages": 0,
                "current_page_processed": 20,
                "source_recovery_count": 0,
                "folder_start_counts": start_counts,
                "processed_total": start_counts["processed_total"] + 209,
                "duplicate_media": start_counts["duplicate_media"] + 209,
            }
        )
        control = {
            "id": 9001,
            "reply_markup": {
                "rows": [{"buttons": [{"text": "下一组"}]}],
            },
        }
        migrator.current_page = lambda: ([], control)
        migrator.click = lambda keyword: (_ for _ in ()).throw(
            MODULE.MigrationBlocked(MODULE.NO_MATCHING_NAVIGATION_MESSAGE)
        )

        migrator.process_current_page()

        self.assertEqual("resume_current_folder", migrator.state["stage"])
        self.assertEqual(1, migrator.state["source_recovery_count"])
        self.assertEqual(0, migrator.state["folder_processed"])
        self.assertEqual(
            start_counts["processed_total"],
            migrator.state["processed_total"],
        )
        self.assertEqual(
            "next_page_navigation_button_missing",
            migrator.state["source_recovery_reason"],
        )
        self.assertEqual(9001, migrator.state["source_recovery_control_id"])

    def test_matching_exhausted_duplicate_replay_advances_folder(self):
        start_counts = self.folder_start_counts()
        migrator = bare_migrator(
            {
                **start_counts,
                "status": "running",
                "stage": "process_page",
                "folder_index": 5,
                "folder_expected": 483,
                "folder_processed": 247,
                "folder_next_group_clicks": 8,
                "consecutive_empty_source_pages": 2,
                "current_page_processed": 0,
                "source_recovery_count": 5,
                "folder_start_counts": start_counts,
                "processed_total": start_counts["processed_total"] + 247,
                "duplicate_media": start_counts["duplicate_media"] + 247,
                "last_exhausted_replay_observed_count": 247,
                "matching_exhausted_replay_count": 1,
            }
        )
        migrator.folders = [("folder", 1)] * 6
        control = {
            "id": 9149,
            "reply_markup": {
                "rows": [{"buttons": [{"text": "下一组"}]}],
            },
        }
        migrator.current_page = lambda: ([], control)
        navigated = []
        migrator.navigate_from_source_root = (
            lambda index, *, reason: navigated.append((index, reason))
        )

        migrator.process_current_page()

        self.assertEqual([(6, "exhausted_replay_completed")], navigated)
        self.assertEqual(
            236,
            migrator.state["exhausted_replay_missing_total"],
        )
        self.assertTrue(
            any(
                message == "folder_exhausted_after_verified_duplicate_replays"
                for message, _ in migrator.logs
            )
        )

    def test_missing_terminal_control_records_exhausted_replay_evidence(self):
        start_counts = self.folder_start_counts()
        migrator = bare_migrator(
            {
                **start_counts,
                "status": "running",
                "stage": "process_page",
                "folder_index": 20,
                "folder_expected": 24,
                "folder_processed": 13,
                "source_recovery_count": 1,
                "folder_start_counts": start_counts,
                "processed_total": start_counts["processed_total"] + 13,
                "duplicate_media": start_counts["duplicate_media"] + 13,
                "last_exhausted_replay_observed_count": 0,
                "matching_exhausted_replay_count": 0,
            }
        )

        migrator.schedule_folder_control_recovery(
            "folder_control_missing_next_before_expected_count",
            21175,
        )

        self.assertEqual(13, migrator.state["last_exhausted_replay_observed_count"])
        self.assertEqual(1, migrator.state["matching_exhausted_replay_count"])
        self.assertEqual("resume_current_folder", migrator.state["stage"])

    def test_matching_exhausted_replay_without_next_control_advances_folder(self):
        start_counts = self.folder_start_counts()
        migrator = bare_migrator(
            {
                **start_counts,
                "status": "running",
                "stage": "process_page",
                "folder_index": 20,
                "folder_expected": 24,
                "folder_processed": 13,
                "source_recovery_count": 10,
                "folder_start_counts": start_counts,
                "processed_total": start_counts["processed_total"] + 13,
                "duplicate_media": start_counts["duplicate_media"] + 13,
                "last_exhausted_replay_observed_count": 13,
                "matching_exhausted_replay_count": 1,
            }
        )
        migrator.folders = [("folder", 1)] * 21
        migrator.current_page = lambda: (
            [],
            {"id": 21175, "reply_markup": {"rows": []}},
        )
        navigated = []
        migrator.navigate_from_source_root = (
            lambda index, *, reason: navigated.append((index, reason))
        )

        migrator.process_current_page()

        self.assertEqual([(21, "exhausted_replay_completed")], navigated)
        self.assertEqual(11, migrator.state["exhausted_replay_missing_total"])

    def test_reconciles_rollback_counters_from_clean_target_deltas(self):
        migrator = bare_migrator(
            {
                "processed_total": 8,
                "source_media_processed": 7,
                "source_images": 2,
                "source_videos": 5,
                "deleted_text": 1,
                "copied_media": 3,
                "copied_images": 1,
                "copied_videos": 2,
                "duplicate_media": 4,
                "duplicate_images": 1,
                "duplicate_videos": 3,
                "image_target_baseline_images": 1,
                "video_target_baseline_videos": 2,
                "exhausted_replay_missing_total": 4,
            }
        )
        migrator.expected_total = 12
        migrator.expected_media = 10
        migrator.expected_images = 3
        migrator.expected_videos = 7
        migrator.expected_text = 2

        reconciled = migrator.reconcile_exhausted_replay_counters(
            {"videos": 6},
            {"images": 3},
        )

        self.assertTrue(reconciled)
        self.assertEqual(12, migrator.state["processed_total"])
        self.assertEqual(6, migrator.state["copied_media"])
        self.assertEqual(4, migrator.state["duplicate_media"])
        self.assertEqual(2, migrator.state["deleted_text"])

    def test_validates_category_increases_against_folder_count_drift(self):
        migrator = bare_migrator(
            {
                "processed_total": 14,
                "source_media_processed": 11,
                "source_images": 4,
                "source_videos": 7,
                "deleted_text": 3,
                "copied_media": 6,
                "duplicate_media": 5,
                "manifest_folder_count_drift_total": 2,
            }
        )
        migrator.expected_total = 12
        migrator.expected_media = 10
        migrator.expected_images = 3
        migrator.expected_videos = 7
        migrator.expected_text = 2

        migrator.validate_source_counters()

    def test_accepts_category_redistribution_with_matching_folder_count_drift(self):
        migrator = bare_migrator(
            {
                "processed_total": 14,
                "source_media_processed": 10,
                "source_images": 2,
                "source_videos": 8,
                "deleted_text": 4,
                "copied_media": 6,
                "duplicate_media": 4,
                "manifest_folder_count_drift_total": 2,
            }
        )
        migrator.expected_total = 12
        migrator.expected_media = 10
        migrator.expected_images = 3
        migrator.expected_videos = 7
        migrator.expected_text = 2

        migrator.validate_source_counters()

    def test_validates_category_decreases_against_negative_folder_count_drift(self):
        migrator = bare_migrator(
            {
                "processed_total": 10,
                "source_media_processed": 9,
                "source_images": 2,
                "source_videos": 7,
                "deleted_text": 1,
                "copied_media": 5,
                "duplicate_media": 4,
                "manifest_folder_count_drift_total": -2,
            }
        )
        migrator.expected_total = 12
        migrator.expected_media = 10
        migrator.expected_images = 3
        migrator.expected_videos = 7
        migrator.expected_text = 2

        migrator.validate_source_counters()

    def test_accepts_verified_exhausted_shortfall_with_signed_count_drift(self):
        migrator = bare_migrator(
            {
                "processed_total": 8,
                "source_media_processed": 7,
                "source_images": 2,
                "source_videos": 5,
                "deleted_text": 1,
                "copied_media": 4,
                "duplicate_media": 3,
                "manifest_folder_count_drift_total": -1,
                "exhausted_replay_missing_total": 3,
            }
        )
        migrator.expected_total = 12
        migrator.expected_media = 10
        migrator.expected_images = 3
        migrator.expected_videos = 7
        migrator.expected_text = 2

        migrator.validate_source_counters()

    def test_drifted_exhausted_replay_does_not_invent_category_counters(self):
        state = {
            "processed_total": 8,
            "source_media_processed": 7,
            "source_images": 2,
            "source_videos": 5,
            "deleted_text": 1,
            "copied_media": 4,
            "copied_images": 1,
            "copied_videos": 3,
            "duplicate_media": 3,
            "duplicate_images": 1,
            "duplicate_videos": 2,
            "image_target_baseline_images": 10,
            "video_target_baseline_videos": 20,
            "manifest_folder_count_drift_total": -1,
            "exhausted_replay_missing_total": 3,
        }
        migrator = bare_migrator(state)
        migrator.expected_total = 12
        migrator.expected_media = 10
        migrator.expected_images = 3
        migrator.expected_videos = 7
        migrator.expected_text = 2

        reconciled = migrator.reconcile_exhausted_replay_counters(
            {"videos": 23},
            {"images": 11},
        )

        self.assertFalse(reconciled)
        self.assertEqual(state["processed_total"], migrator.state["processed_total"])
        self.assertEqual(state["source_images"], migrator.state["source_images"])
        self.assertEqual(state["source_videos"], migrator.state["source_videos"])
        self.assertNotIn(
            "exhausted_replay_counters_reconciled",
            migrator.state,
        )

    def test_rejects_target_delta_below_committed_copy_counter(self):
        migrator = bare_migrator(
            {
                "copied_images": 2,
                "copied_videos": 5,
                "image_target_baseline_images": 10,
                "video_target_baseline_videos": 20,
            }
        )

        with self.assertRaisesRegex(
            MODULE.MigrationBlocked,
            "target media delta is below committed copied counters",
        ):
            migrator.target_copy_deltas(
                {"videos": 24},
                {"images": 12},
            )

    def test_page_media_is_checkpointed_before_source_deletion(self):
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
        self.assertEqual([], calls)
        self.assertEqual(
            [
                {"source_message_id": 2717, "kind": "video"},
                {"source_message_id": 2718, "kind": "video"},
                {"source_message_id": 2719, "kind": "video"},
            ],
            migrator.state["active_page_media"],
        )

    def test_ready_page_copies_media_then_deletes_text(self):
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
        copied = []
        deleted_text = []
        migrator.copy_page_media_entries = lambda entries: copied.extend(entries)
        migrator.delete_text_item = lambda message_id: deleted_text.append(message_id)

        migrator.complete_page_items()

        self.assertEqual(
            [
                {"source_message_id": 2717, "kind": "video"},
                {"source_message_id": 2718, "kind": "video"},
            ],
            copied,
        )
        self.assertEqual([2715], deleted_text)
        self.assertEqual("process_page", migrator.state["stage"])
        self.assertNotIn("active_page_results", migrator.state)

    def test_copy_requests_file_id_only_dedupe(self):
        migrator = bare_migrator(
            {
                "stage": "process_page",
                "folder_index": 5,
                "processed_total": 0,
            }
        )
        migrator.dedupe_scope = "epan_originals_combined"
        migrator.latest_message_id = lambda peer_id: 100
        calls = []

        def post(path, payload, timeout):
            calls.append((path, payload, timeout))
            return {
                "status": "ok",
                "results": [
                    {
                        "source_message_id": 200,
                        "target_message_id": 300,
                        "file_unique_id": "document:400",
                        "duplicate": False,
                    }
                ],
            }

        migrator.api = types.SimpleNamespace(post=post)
        completed = []
        migrator.mark_source_complete = lambda *args, **kwargs: completed.append(
            (args, kwargs)
        )

        migrator.copy_media({"id": 200, "media_kind": "video"})

        self.assertEqual(
            "/messages/copy-protected-media-batch",
            calls[0][0],
        )
        self.assertEqual(
            "telegram_file_unique_id",
            calls[0][1]["dedupe_mode"],
        )
        self.assertEqual(
            "document:400",
            completed[0][1]["file_unique_id"],
        )

    def test_page_batch_requests_file_id_only_dedupe(self):
        migrator = bare_migrator(
            {
                "stage": "page_items_ready",
                "folder_index": 5,
            }
        )
        migrator.dedupe_scope = "epan_originals_combined"
        calls = []

        def post(path, payload, timeout):
            calls.append((path, payload, timeout))
            return {
                "status": "ok",
                "results": [
                    {
                        "source_message_id": 201,
                        "target_message_id": 301,
                        "file_unique_id": "document:401",
                        "duplicate": True,
                    },
                    {
                        "source_message_id": 202,
                        "target_message_id": 302,
                        "file_unique_id": "document:402",
                        "duplicate": False,
                    },
                ],
            }

        migrator.api = types.SimpleNamespace(post=post)
        completed = []
        migrator.mark_source_complete = lambda *args, **kwargs: completed.append(
            (args, kwargs)
        )

        migrator.copy_page_media_entries(
            [
                {"source_message_id": 201, "kind": "video"},
                {"source_message_id": 202, "kind": "video"},
            ]
        )

        self.assertEqual(1, len(calls))
        self.assertEqual(
            "/messages/copy-protected-media-batch",
            calls[0][0],
        )
        self.assertEqual([201, 202], calls[0][1]["message_ids"])
        self.assertEqual(
            "telegram_file_unique_id",
            calls[0][1]["dedupe_mode"],
        )
        self.assertEqual(2, len(completed))
        self.assertEqual("document:401", completed[0][1]["file_unique_id"])
        self.assertEqual("document:402", completed[1][1]["file_unique_id"])

    def test_click_waits_for_async_navigation_button(self):
        migrator = bare_migrator(
            {
                "status": "running",
                "start_message_id": 11000,
                "source_recovery_start_message_id": 12000,
            }
        )
        migrator.backfill_source = lambda: None
        responses = [
            {
                "status": "fail",
                "reason": "no matching button found",
                "button_clicked": False,
            },
            {
                "status": "ok",
                "button_clicked": True,
                "clicked_button_text": "9",
                "clicked_message_id": 12000,
            },
        ]
        calls = []

        def post(path, payload, timeout):
            calls.append((path, payload, timeout))
            return responses.pop(0)

        migrator.api = types.SimpleNamespace(post=post)
        original_sleep = MODULE.time.sleep
        MODULE.time.sleep = lambda seconds: None
        try:
            result = migrator.click("9")
        finally:
            MODULE.time.sleep = original_sleep

        self.assertEqual(2, len(calls))
        self.assertEqual(12000, calls[0][1]["sent_message_id"])
        self.assertEqual(12000, calls[1][1]["sent_message_id"])
        self.assertEqual(12000, result["clicked_message_id"])


if __name__ == "__main__":
    unittest.main()
