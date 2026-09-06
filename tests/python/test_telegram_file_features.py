import asyncio
import base64
import hashlib
import importlib.util
import json
import tempfile
import subprocess
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

SPEC = importlib.util.spec_from_file_location("queue_features_fixture", Path(__file__).resolve().parents[2] / "scripts" / "telegram_media_queue.py")
Q = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(Q)
F = Q.file_features


class FileFeaturesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.patches = [patch.object(Q, "PROCESSED_DB_PATH", self.root / "db.sqlite3"),
                        patch.object(Q, "APP_DIR", self.root), patch.object(Q, "save_state"),
                        patch.object(Q, "safe_log"), patch.object(Q, "marked_peer_id", lambda s: s)]
        for p in self.patches:
            p.start()
        self.cfg = {"download_dir": str(self.root / "output"), "fingerprint_work_dir": str(self.root / "cache"),
                    "file_fingerprints_enabled": True, "fingerprint_source_aliases": ["a", "b"],
                    "sources": [{"alias": "a", "peer_id": 1, "delete_source": False}, {"alias": "b", "peer_id": 2, "delete_source": False}]}
        self.state = {"sources": {a: Q.empty_source_state() for a in ("a", "b")}}
        F.connect(Q.feature_api()).close()

    def tearDown(self):
        for p in reversed(self.patches):
            p.stop()
        self.tmp.cleanup()

    def message(self, mid=1, identity=777):
        return SimpleNamespace(id=mid, photo=None, document=SimpleNamespace(id=identity, size=3, mime_type="video/mp4", attributes=[]))

    def completed(self, peer, mid, alias):
        Q.begin_processed_message(peer, mid, alias, "video")
        Q.finish_processed_message(peer, mid, 1)

    def test_hash_ignores_names_and_distinguishes_same_size(self):
        a, b, c = [self.root / x for x in ("one.mp4", "different-name.bin", "other.mp4")]
        a.write_bytes(b"abc"); b.write_bytes(b"abc"); c.write_bytes(b"abd")
        expected = base64.b64encode(hashlib.sha256(b"abc").digest()).decode()
        self.assertEqual((expected, 3), F.digest_file(a))
        self.assertEqual(F.digest_file(a), F.digest_file(b))
        self.assertNotEqual(F.digest_file(a), F.digest_file(c))

    def test_additive_migration_keeps_completed_rows(self):
        self.completed(1, 1, "a")
        for _ in range(2):
            db = F.connect(Q.feature_api()); self.assertEqual("ok", db.execute("PRAGMA quick_check").fetchone()[0]); db.close()
        self.assertEqual("completed", Q.processed_message_status(1, 1))

    def test_cross_group_identity_skips_network_and_copies_feature(self):
        digest = base64.b64encode(hashlib.sha256(b"abc").digest()).decode()
        F.register(Q.feature_api(), digest, 3, True, "document:777:3")
        self.completed(2, 4, "b")
        client = SimpleNamespace(download_media=AsyncMock(side_effect=AssertionError("network")))
        with patch.object(F, "download_document", AsyncMock(side_effect=AssertionError("network"))):
            r = asyncio.run(F.prepare(Q.feature_api(), client, 2, self.message(4), "b", self.cfg, self.state, historical=True))
        self.assertTrue(r["duplicate"])
        db=F.connect(Q.feature_api()); row=db.execute("SELECT fingerprint_base64 FROM processed_messages WHERE source_peer_id=2 AND message_id=4").fetchone(); db.close()
        self.assertEqual(digest, row[0])

    def test_existing_local_backfill_does_not_download_or_delete(self):
        output=Path(self.cfg["download_dir"]); output.mkdir()
        token=hashlib.sha256(b"1:1").hexdigest()[:20]
        path=output/f"tg_{token}.mp4"; path.write_bytes(b"abc")
        self.completed(1,1,"a")
        with patch.object(F,"download_document",AsyncMock(side_effect=AssertionError("network"))):
            r=asyncio.run(F.prepare(Q.feature_api(),None,1,self.message(),"a",self.cfg,self.state,True))
        F.cleanup(Q.feature_api(),self.cfg,1,1)
        self.assertEqual(b"abc",path.read_bytes())
        self.assertIsNotNone(r["digest"])

    def test_new_identity_equal_bytes_skips_delivery_after_single_temp_download(self):
        digest=base64.b64encode(hashlib.sha256(b"abc").digest()).decode()
        F.register(Q.feature_api(),digest,3,True)
        staging=F.job_staging(Q.feature_api(),self.cfg,2,1); staging.mkdir(parents=True)
        temp=staging/"fixture.bin";temp.write_bytes(b"abc")
        with patch.object(F,"download_document",AsyncMock(return_value=temp)) as download, patch.object(Q,"download_video",AsyncMock()) as deliver:
            asyncio.run(Q.process_message(None,2,None,self.message(identity=999),"b",self.cfg,self.state))
        self.assertEqual(1,download.await_count);deliver.assert_not_awaited()
        self.assertFalse(staging.exists());self.assertEqual("completed",Q.processed_message_status(2,1))

    def test_undelivered_hash_is_not_duplicate_and_reuses_prepared_bytes(self):
        digest=base64.b64encode(hashlib.sha256(b"abc").digest()).decode()
        F.register(Q.feature_api(),digest,3,False,"document:777:3")
        staging=F.job_staging(Q.feature_api(),self.cfg,1,1);staging.mkdir(parents=True)
        path=staging/"fixture.mp4";path.write_bytes(b"abc")
        with patch.object(F,"download_document",AsyncMock(return_value=path)) as download:
            asyncio.run(Q.process_message(None,1,None,self.message(),"a",self.cfg,self.state))
        self.assertEqual(1,download.await_count)
        self.assertEqual(1,len(list(Path(self.cfg["download_dir"]).glob("*.mp4"))))
        self.assertEqual("completed",Q.processed_message_status(1,1))

    def test_flood_wait_preserves_cursor_and_processing_for_retry(self):
        with patch.object(F,"prepare",AsyncMock(side_effect=Q.FloodWaitError(None,60))):
            with self.assertRaises(Q.FloodWaitError):
                asyncio.run(Q.process_message(None,1,None,self.message(),"a",self.cfg,self.state))
        self.assertEqual(0,self.state["sources"]["a"]["last_scanned_id"])
        self.assertEqual("processing",Q.processed_message_status(1,1))

    def test_backfill_failure_retains_temp_and_reports_gap_then_resumes(self):
        self.completed(1,1,"a");self.completed(1,2,"a")
        client=SimpleNamespace(get_messages=AsyncMock(side_effect=lambda source,ids:self.message(ids)))
        digest=base64.b64encode(hashlib.sha256(b"abc").digest()).decode()
        async def prepare(q,client,source,message,alias,config,state,**kwargs):
            if message.id==1:
                path=F.job_staging(q,config,source,1);path.mkdir(parents=True,exist_ok=True);(path/"partial").write_bytes(b"a")
                raise RuntimeError("fixture_download_error")
            F.register(q,digest,3,True);F.record_job(q,source,message.id,alias,digest,"fixture")
            return {"digest":digest,"path":None}
        with patch.object(F,"prepare",prepare):
            asyncio.run(F.backfill_batch(Q.feature_api(),client,1,None,"a",self.cfg,self.state))
        report=F.report(Q.feature_api(),self.cfg)[0]
        self.assertEqual(1,report["pending"]);self.assertEqual(0,report["covered_through_id"])
        self.assertTrue(F.job_staging(Q.feature_api(),self.cfg,1,1).exists())
        F.record_job(Q.feature_api(),1,1,"a",digest,"recovered")
        report=F.report(Q.feature_api(),self.cfg)[0]
        self.assertEqual(0,report["pending"]);self.assertEqual(2,report["covered_through_id"])

    def test_zip_children_dedupe_with_and_without_password(self):
        seven=Path(r"C:\Program Files\7-Zip\7z.exe")
        if not seven.exists():
            self.skipTest("7-Zip unavailable")
        self.cfg["seven_zip_path"]=str(seven)
        self.cfg["archive_work_dir"]=str(self.root/"archives")
        inputs=self.root/"inputs";inputs.mkdir()
        (inputs/"one.mp4").write_bytes(b"synthetic-video")
        (inputs/"renamed.mp4").write_bytes(b"synthetic-video")
        for mid,password in ((10,None),(11,"fixture-password")):
            archive=self.root/f"fixture-{mid}.zip"
            command=[str(seven),"a","-tzip",str(archive),str(inputs/"one.mp4"),str(inputs/"renamed.mp4")]
            if password:
                command += ["-p"+password,"-mem=AES256"]
            subprocess.run(command,check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            message=SimpleNamespace(id=mid,document=SimpleNamespace(size=archive.stat().st_size))
            Q.begin_processed_message(1,mid,"a","archive")
            with patch.object(Q,"archive_password_candidates",AsyncMock(return_value=[password] if password else [])):
                result=asyncio.run(Q.process_archive(None,1,None,message,"a",self.cfg,self.state,prepared_path=archive))
            self.assertEqual(2,result["video"])
        self.assertEqual(1,len(list(Path(self.cfg["download_dir"]).glob("*.mp4"))))
        db=F.connect(Q.feature_api())
        rows=db.execute("SELECT COUNT(*),COUNT(DISTINCT fingerprint_base64) FROM processed_archive_items").fetchone()
        db.close()
        self.assertEqual((4,1),tuple(rows))


if __name__ == "__main__":
    unittest.main()
