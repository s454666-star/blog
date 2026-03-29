import os

os.environ.setdefault("TELEGRAM_SERVICE_HOME", os.path.dirname(os.path.abspath(__file__)))
os.environ.setdefault("TELEGRAM_SERVICE_SESSION", "session/main_account")

from telegram_service_shared import app
