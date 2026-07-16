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
        ]:
            self.assertEqual(code, service._normalize_resource_code(code))

    def test_qq_paging_buttons_are_supported(self) -> None:
        self.assertIn("下一页", service.RESOURCE_CODE_NEXT_GROUP_BUTTON_KEYWORDS)
        self.assertNotIn("推送剩余全部文件", service.RESOURCE_CODE_GET_ALL_BUTTON_KEYWORDS)
        self.assertIn("请再次发送文件码", service.RESOURCE_CODE_REPEAT_CONFIRMATION_KEYWORDS)
        self.assertIn("You need to become a VIP member", service.RESOURCE_CODE_ACCOUNT_LIMIT_KEYWORDS)

    def test_qq_decoder_failure_is_not_found(self) -> None:
        text = "解码失败!!\n文件码错误或被举报删除"
        self.assertEqual("解码失败", service._match_bot_not_found_keyword(text))


class DeleteVerificationTest(unittest.IsolatedAsyncioTestCase):
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


if __name__ == "__main__":
    unittest.main()
