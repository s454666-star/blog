import asyncio
import importlib.util
import os
import pathlib
import sys
import tempfile
import types
import unittest
from unittest.mock import AsyncMock


TEST_SERVICE_HOME = tempfile.TemporaryDirectory()
pathlib.Path(TEST_SERVICE_HOME.name, "session").mkdir()
os.environ["TELEGRAM_SERVICE_HOME"] = TEST_SERVICE_HOME.name
os.environ["TELEGRAM_SERVICE_SESSION"] = str(
    pathlib.Path(TEST_SERVICE_HOME.name, "session", "test_account")
)

MODULE_PATH = pathlib.Path(__file__).resolve().parents[2] / "python" / "telegram_service_shared.py"
SPEC = importlib.util.spec_from_file_location("telegram_service_shared_zyxfiles", MODULE_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


def tearDownModule():
    MODULE.client.session.close()
    TEST_SERVICE_HOME.cleanup()


class TelegramResourceCodeZyxfilesTest(unittest.TestCase):
    def test_default_decoder_is_current_yyjmq_decoder(self):
        request = MODULE.ProcessResourceCodeRequest(
            code="yyjmq_A1-b2_C3",
            target_peer_id=3967395258,
        )
        self.assertEqual("XDJMQBot", request.bot_username)

    def test_uncached_decoder_username_is_refreshed_from_telegram(self):
        refreshed_peer = object()
        original_ensure_connected = MODULE._ensure_client_connected
        original_refresh = MODULE._refresh_peer_for_bot
        original_client = MODULE.client

        class FakeClient:
            async def get_input_entity(self, _username):
                raise ValueError("not in local entity cache")

        MODULE._PEER_CACHE.pop("zyxfiles3_bot", None)
        try:
            MODULE.client = FakeClient()
            MODULE._ensure_client_connected = AsyncMock(return_value=True)
            MODULE._refresh_peer_for_bot = AsyncMock(return_value=refreshed_peer)

            resolved = asyncio.run(MODULE._get_peer_for_bot("zyxfiles3_bot"))
        finally:
            MODULE.client = original_client
            MODULE._ensure_client_connected = original_ensure_connected
            MODULE._refresh_peer_for_bot = original_refresh
            MODULE._PEER_CACHE.pop("zyxfiles3_bot", None)

        self.assertIs(refreshed_peer, resolved)

    def test_normalizes_both_supported_delimiters_without_changing_suffix_case(self):
        self.assertEqual(
            "zyxfiles-1v-9p-b991bea8665480cd9ee7a81c",
            MODULE._normalize_resource_code("ZYXFILES-1v-9p-b991bea8665480cd9ee7a81c"),
        )
        self.assertEqual(
            "zyxfiles_A1-b2_C3",
            MODULE._normalize_resource_code("ZYXFILES_A1-b2_C3"),
        )

    def test_rejects_invalid_zyxfiles_prefix_forms(self):
        self.assertIsNone(MODULE._normalize_resource_code("zyxfiles1v-9p-token"))
        self.assertIsNone(MODULE._normalize_resource_code("zyxfile-1v-9p-token"))

    def test_normalizes_all_current_prefixes_and_preserves_suffix_case(self):
        cases = {
            "YYJMQ_A1-b2": "yyjmq_A1-b2",
            "xvngkllbot:AbC-123": "XVNgkllbot:AbC-123",
            "PXXXAJSBOT_file_N7-z6": "PxxxaJSbot_file_N7-z6",
            "PXXQZJZJSBOT_file_X9-y8": "PxxqzjzJSbot_file_X9-y8",
            "nw7X-9_token": "NW7X-9_token",
        }
        for raw_code, expected in cases.items():
            with self.subTest(raw_code=raw_code):
                self.assertEqual(expected, MODULE._normalize_resource_code(raw_code))

    def test_current_prefixes_allow_only_safe_next_page_callbacks(self):
        for code in (
            "yyjmq_A1-b2",
            "XVNgkllbot:AbC-123",
            "PxxxaJSbot_file_N7-z6",
            "PxxqzjzJSbot_file_X9-y8",
            "NW7X-9_token",
        ):
            with self.subTest(code=code):
                self.assertEqual("next_group", MODULE._resource_code_callback_action(code, "下一页"))
                self.assertEqual("next_group", MODULE._resource_code_callback_action(code, "下一頁"))
                self.assertIsNone(MODULE._resource_code_callback_action(code, "推送剩余全部文件"))
                self.assertIsNone(MODULE._resource_code_callback_action(code, "开通VIP"))

    def test_zyxfiles_allows_next_page_but_not_get_all_or_vip_callbacks(self):
        code = "zyxfiles-1v-9p-b991bea8665480cd9ee7a81c"
        self.assertEqual("next_group", MODULE._resource_code_callback_action(code, "下一页"))
        self.assertEqual("next_group", MODULE._resource_code_callback_action(code, "获取下一组"))
        self.assertIsNone(MODULE._resource_code_callback_action(code, "全部获取"))
        self.assertIsNone(MODULE._resource_code_callback_action(code, "推送剩余全部文件"))
        self.assertIsNone(MODULE._resource_code_callback_action(code, "开通VIP"))

    def test_wenjianji_keeps_its_required_get_all_callback(self):
        code = "WenJianJiJibot_1a_0123456789AbCdEf"
        self.assertEqual("get_all", MODULE._resource_code_callback_action(code, "全部获取"))

    def test_zyxfiles_collects_the_next_page_without_clicking_get_all(self):
        clicked = []
        page = {"value": 1}

        class CallbackMessage(types.SimpleNamespace):
            async def click(self, data):
                clicked.append(data)
                if data == b"next":
                    page["value"] = 2

        def media(message_id, photo_id):
            return types.SimpleNamespace(
                id=message_id,
                out=False,
                message="",
                photo=types.SimpleNamespace(id=photo_id),
                video=None,
                document=None,
                file=None,
                grouped_id=0,
                reply_markup=None,
            )

        page_one_control = CallbackMessage(
            id=101,
            out=False,
            message="",
            photo=None,
            video=None,
            document=None,
            file=None,
            reply_markup=types.SimpleNamespace(rows=[
                types.SimpleNamespace(buttons=[
                    types.SimpleNamespace(text="全部获取", data=b"get_all"),
                    types.SimpleNamespace(text="下一页", data=b"next"),
                    types.SimpleNamespace(text="开通VIP", data=b"vip"),
                ])
            ]),
        )
        completion = types.SimpleNamespace(
            id=104,
            out=False,
            message="成功发送 2 个",
            photo=None,
            video=None,
            document=None,
            file=None,
            reply_markup=None,
        )

        class FakeClient:
            async def get_messages(self, _peer, limit, min_id):
                messages = [page_one_control, media(102, 1002)]
                if page["value"] == 2:
                    messages.extend([completion, media(105, 1005)])
                return messages

        original_client = MODULE.client
        try:
            MODULE.client = FakeClient()
            result = asyncio.run(MODULE._resource_code_bot_media(
                peer=object(),
                code="zyxfiles-1v-9p-b991bea8665480cd9ee7a81c",
                sent_message_id=100,
                wait_timeout_seconds=5,
                poll_interval_seconds=0.01,
                settle_seconds=0.01,
            ))
        finally:
            MODULE.client = original_client

        media_messages, _reply_ids, outcome, expected_count, declared_count = result
        self.assertEqual("settled", outcome)
        self.assertEqual(2, len(media_messages))
        self.assertEqual(2, expected_count)
        self.assertIsNone(declared_count)
        self.assertEqual([b"next"], clicked)

    def test_hung_poll_is_bounded_by_the_resource_code_deadline(self):
        class HungClient:
            async def get_messages(self, _peer, limit, min_id):
                await asyncio.Event().wait()

        original_client = MODULE.client
        original_poll_timeout = MODULE.RESOURCE_CODE_POLL_READ_TIMEOUT_SECONDS
        try:
            MODULE.client = HungClient()
            MODULE.RESOURCE_CODE_POLL_READ_TIMEOUT_SECONDS = 0.01
            result = asyncio.run(MODULE._resource_code_bot_media(
                peer=object(),
                code="zyxfiles_timeout_A1-b2",
                sent_message_id=100,
                wait_timeout_seconds=0.04,
                poll_interval_seconds=0.01,
                settle_seconds=0.01,
            ))
        finally:
            MODULE.client = original_client
            MODULE.RESOURCE_CODE_POLL_READ_TIMEOUT_SECONDS = original_poll_timeout

        media_messages, reply_ids, outcome, expected_count, declared_count = result
        self.assertEqual([], media_messages)
        self.assertEqual([], reply_ids)
        self.assertEqual("timeout", outcome)
        self.assertIsNone(expected_count)
        self.assertIsNone(declared_count)


if __name__ == "__main__":
    unittest.main()
