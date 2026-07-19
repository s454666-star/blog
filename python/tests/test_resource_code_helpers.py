import glob
import os
import sys
import tempfile
import unittest
import uuid
from pathlib import Path


PYTHON_DIR = Path(__file__).resolve().parents[1]
SESSION_BASE = os.path.join(
    tempfile.gettempdir(),
    f"blog-telegram-helper-test-{uuid.uuid4().hex}",
)
os.environ["TELEGRAM_SERVICE_HOME"] = str(PYTHON_DIR)
os.environ["TELEGRAM_SERVICE_SESSION"] = SESSION_BASE
sys.path.insert(0, str(PYTHON_DIR))

import telegram_service_shared as service


class ResourceCodeDormantTextTest(unittest.TestCase):
    @classmethod
    def tearDownClass(cls) -> None:
        service.client.session.close()
        for path in glob.glob(f"{SESSION_BASE}*"):
            try:
                os.remove(path)
            except FileNotFoundError:
                pass

    def test_individual_dormant_status_is_invalid(self) -> None:
        self.assertTrue(service._resource_code_is_dormant_text("取件码 abc 处于休眠状态。"))
        self.assertTrue(service._resource_code_is_dormant_text("取件碼 abc 處於休眠狀態。"))

    def test_zero_sent_dormant_batch_is_invalid(self) -> None:
        self.assertTrue(service._resource_code_is_dormant_text(
            "✅ 打包取件完成\n📥 成功发送 0 个\n💤 休眠待激活 5 个"
        ))
        self.assertTrue(service._resource_code_is_dormant_text(
            "✅ 打包取件完成\n📥 成功發送 0 個\n💤 休眠待啟用 2 個"
        ))

    def test_mixed_or_empty_batch_is_not_misclassified(self) -> None:
        self.assertFalse(service._resource_code_is_dormant_text(
            "📥 成功发送 1 个\n💤 休眠待激活 5 个"
        ))
        self.assertFalse(service._resource_code_is_dormant_text(
            "📥 成功发送 0 个\n💤 休眠待激活 0 个"
        ))

    def test_qq_codes_are_accepted_without_changing_case(self) -> None:
        for code in [
            "QQer16_bot:qqcode1ebfce2af1_3V",
            "QQn8zw_bot:qqcode1099c74e81_8P_17V",
            "QQyptu_bot:qqcode10884fe700_9V",
            "QQfile_bot:10506_69799_143-42",
            "QQnext_gen_bot:FutureCode_AZ09-7",
        ]:
            self.assertEqual(code, service._normalize_resource_code(code))

    def test_jsfile_codes_are_accepted_and_prefix_is_normalized(self) -> None:
        self.assertEqual(
            "JSfile_bot_87V0P0D_2TZN-NN8C",
            service._normalize_resource_code("jsfile_bot_87V0P0D_2TZN-NN8C"),
        )
        self.assertIsNone(service._normalize_resource_code("JSfilebot_87V0P0D_2TZN-NN8C"))

    def test_wenjianjiji_code_is_accepted_and_prefix_is_normalized(self) -> None:
        self.assertEqual(
            "WenJianJiJibot_1v_EY7hgrHmiujLKVaV",
            service._normalize_resource_code("wenjianjijibot_1v_EY7hgrHmiujLKVaV"),
        )
        self.assertIsNone(service._normalize_resource_code("WenJianJibot_1v_EY7hgrHmiujLKVaV"))

    def test_qq_paging_buttons_are_supported(self) -> None:
        self.assertIn("下一页", service.RESOURCE_CODE_NEXT_GROUP_BUTTON_KEYWORDS)
        self.assertNotIn("推送剩余全部文件", service.RESOURCE_CODE_GET_ALL_BUTTON_KEYWORDS)
        self.assertIn("请再次发送文件码", service.RESOURCE_CODE_REPEAT_CONFIRMATION_KEYWORDS)
        self.assertIn("You need to become a VIP member", service.RESOURCE_CODE_ACCOUNT_LIMIT_KEYWORDS)
        self.assertIn("普通用户暂停解析至", service.RESOURCE_CODE_ACCOUNT_LIMIT_KEYWORDS)
        self.assertIn("violated Telegram's Terms of Service", service.RESOURCE_CODE_ACCOUNT_LIMIT_KEYWORDS)

    def test_qq_decoder_failure_is_not_found(self) -> None:
        text = "解码失败!!\n文件码错误或被举报删除"
        self.assertEqual("解码失败", service._match_bot_not_found_keyword(text))

    def test_next_page_callback_excludes_push_action(self) -> None:
        class Button:
            def __init__(self, text: str, data: bytes):
                self.text = text
                self.data = data

        class Message:
            buttons = [
                [Button("📁推送剩余全部文件", b"push")],
                [Button("下一页⏩", b"next")],
            ]

        self.assertEqual(
            ("下一页⏩", b"next".hex()),
            service._find_next_page_callback_button(Message()),
        )

    def test_page_callback_matches_only_requested_safe_page(self) -> None:
        class Button:
            def __init__(self, text: str, data: bytes):
                self.text = text
                self.data = data

        class Message:
            buttons = [
                [Button("➡️51", b"page-51"), Button("➡️101", b"page-101")],
                [Button("📁推送剩余全部文件 51", b"push")],
            ]

        self.assertEqual(
            ("➡️51", b"page-51".hex()),
            service._find_page_callback_button(Message(), 51),
        )
        self.assertIsNone(service._find_page_callback_button(Message(), 97))


class DeleteVerificationTest(unittest.IsolatedAsyncioTestCase):
    async def test_manual_resource_batch_forwards_only_requested_media(self) -> None:
        class Item:
            def __init__(self, message_id: int):
                self.id = message_id
                self.photo = object()
                self.document = None
                self.video = None

        original_connected = service._ensure_client_connected
        original_resolve = service._resolve_any_input_entity_by_id
        original_get_messages = service.client.get_messages
        original_forward = service._forward_resource_code_media

        async def fake_connected(*args, **kwargs):
            return True

        async def fake_resolve(peer_id):
            return object()

        async def fake_get_messages(*args, **kwargs):
            return [Item(mid) for mid in kwargs.get("ids", [])]

        async def fake_forward(*args, **kwargs):
            return [501, 502]

        service._ensure_client_connected = fake_connected
        service._resolve_any_input_entity_by_id = fake_resolve
        service.client.get_messages = fake_get_messages
        service._forward_resource_code_media = fake_forward
        try:
            result = await service.forward_resource_code_batch(
                service.ForwardResourceCodeBatchRequest(
                    source_peer_id=8901775677,
                    message_ids=[101, 102],
                    target_peer_id=3967395258,
                )
            )
        finally:
            service._ensure_client_connected = original_connected
            service._resolve_any_input_entity_by_id = original_resolve
            service.client.get_messages = original_get_messages
            service._forward_resource_code_media = original_forward

        self.assertEqual("ok", result["status"])
        self.assertEqual(2, result["forwarded_count"])
        self.assertEqual([101, 102], result["source_message_ids"])

    async def test_history_clear_service_marker_is_not_remaining_content(self) -> None:
        class MessageService:
            id = 184154

        original_get_messages = service.client.get_messages

        async def fake_get_messages(*args, **kwargs):
            return [MessageService()]

        service.client.get_messages = fake_get_messages
        try:
            remaining = await service._find_remaining_message_ids(object(), [184154])
        finally:
            service.client.get_messages = original_get_messages

        self.assertEqual([], remaining)

    async def test_forward_error_is_reconciled_when_single_media_arrived(self) -> None:
        class Document:
            def __init__(self, document_id: int):
                self.id = document_id
                self.mime_type = "video/mp4"

        class Item:
            def __init__(self, message_id: int, document_id: int = 0):
                self.id = message_id
                self.document = Document(document_id) if document_id else None
                self.photo = None
                self.video = None

        source = Item(1, 123)
        target_messages = [Item(10)]
        original_get_messages = service.client.get_messages
        original_forward_messages = service.client.forward_messages

        async def fake_get_messages(*args, **kwargs):
            min_id = int(kwargs.get("min_id", 0) or 0)
            if min_id > 0:
                return [item for item in target_messages if item.id > min_id]
            return [target_messages[-1]]

        async def fake_forward_messages(*args, **kwargs):
            target_messages.append(Item(11, 123))
            raise ValueError("response lost after delivery")

        service.client.get_messages = fake_get_messages
        service.client.forward_messages = fake_forward_messages
        try:
            forwarded_ids = await service._forward_resource_code_media(object(), [source], False)
        finally:
            service.client.get_messages = original_get_messages
            service.client.forward_messages = original_forward_messages

        self.assertEqual([11], forwarded_ids)

    async def test_unreconciled_forward_error_rolls_back_prior_items(self) -> None:
        class Document:
            def __init__(self, document_id: int):
                self.id = document_id
                self.mime_type = "video/mp4"

        class Item:
            def __init__(self, message_id: int, document_id: int = 0):
                self.id = message_id
                self.document = Document(document_id) if document_id else None
                self.photo = None
                self.video = None

        sources = [Item(1, 101), Item(2, 102)]
        target_messages = [Item(10)]
        cleaned_ids = []
        original_get_messages = service.client.get_messages
        original_forward_messages = service.client.forward_messages
        original_reconcile = service._resource_code_reconcile_forward_after_error
        original_cleanup = service._cleanup_resource_code_bot_messages

        async def fake_get_messages(*args, **kwargs):
            return [target_messages[-1]]

        async def fake_forward_messages(*args, **kwargs):
            if len(target_messages) == 1:
                delivered = Item(11, 101)
                target_messages.append(delivered)
                return [delivered]
            raise ValueError("not delivered")

        async def fake_reconcile(*args, **kwargs):
            return 0

        async def fake_cleanup(peer, message_ids):
            cleaned_ids.extend(message_ids)
            return []

        service.client.get_messages = fake_get_messages
        service.client.forward_messages = fake_forward_messages
        service._resource_code_reconcile_forward_after_error = fake_reconcile
        service._cleanup_resource_code_bot_messages = fake_cleanup
        try:
            with self.assertRaises(ValueError):
                await service._forward_resource_code_media(object(), sources, False)
        finally:
            service.client.get_messages = original_get_messages
            service.client.forward_messages = original_forward_messages
            service._resource_code_reconcile_forward_after_error = original_reconcile
            service._cleanup_resource_code_bot_messages = original_cleanup

        self.assertEqual([11], cleaned_ids)


if __name__ == "__main__":
    unittest.main()
