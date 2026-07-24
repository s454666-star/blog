import importlib.util
import unittest
from pathlib import Path


SCRIPT_PATH = Path(__file__).resolve().parents[2] / "scripts" / "telegram_epan_folder_migrator.py"
SPEC = importlib.util.spec_from_file_location("telegram_epan_folder_migrator_retry", SCRIPT_PATH)
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class TelegramEpanRetryPolicyTest(unittest.TestCase):
    def test_tdl_is_available_for_every_outer_attempt(self) -> None:
        self.assertEqual("source", MODULE.copy_source_bot_for_attempt("source", 1))
        self.assertEqual("source", MODULE.copy_source_bot_for_attempt("source", 2))
        self.assertEqual("source", MODULE.copy_source_bot_for_attempt("source", 8))

    def test_flood_wait_uses_telegram_delay(self) -> None:
        self.assertEqual(121, MODULE.copy_retry_delay_seconds("flood_wait", 120, 1))
        self.assertEqual(20, MODULE.copy_retry_delay_seconds("copy_failed", 0, 2))


if __name__ == "__main__":
    unittest.main()
