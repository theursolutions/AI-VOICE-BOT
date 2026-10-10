"""DuckDB knowledge-base search: one broken source must not sink the lookup.

Production (2026-10-11): a docs table without its full-text index made every
search fail with "match_bm25 does not exist", so the AI answered with no
knowledge-base data at all.

    cd voice-engine && .venv/Scripts/python -m unittest discover -s tests -v
"""

import tempfile
import unittest

from app.services.duck_store import DuckStore


class SearchDocsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.store = DuckStore(self.tmp.name)

    def tearDown(self):
        for con in self.store._conns.values():
            con.close()
        self.tmp.cleanup()

    def test_a_source_that_lost_its_index_is_indexed_and_searched(self):
        self.store.load_docs(1, 10, [{"text": "Delivery to Lahore takes two working days."}])
        with self.store._lock(1):
            self.store._conn(1).execute("PRAGMA drop_fts_index('docs_10')")

        hits = self.store.search_docs(1, [10], "Lahore delivery")
        self.assertEqual(len(hits), 1)
        self.assertIn("Lahore", hits[0]["text"])

    def test_an_empty_source_is_skipped_not_fatal(self):
        self.store.load_docs(1, 11, [])                       # table, no index
        self.store.load_docs(1, 12, [{"text": "Karachi delivery takes three days."}])

        hits = self.store.search_docs(1, [11, 12], "Karachi")
        self.assertEqual([h["text"] for h in hits], ["Karachi delivery takes three days."])
