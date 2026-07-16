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
