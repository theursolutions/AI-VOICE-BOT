"""Places worker store: area cache, filters, paging, geocode — no network.

The remote S3 scan is replaced by a function that writes a small fixture
Parquet with the same columns, so everything after the scan is the real code.

    cd voice-engine && .venv/Scripts/python -m unittest tests.test_places_store -v
"""

import os
import tempfile
import time
import unittest

import duckdb

from app.places.store import PlacesError, PlacesStore

AREA_ROWS = """
SELECT * FROM (VALUES
  ('p1', 'Acme Realty', NULL, 'real_estate_agent', ['services','real_estate_service','real_estate_agent'], 'real_estate_service', NULL,
   ['https://acme.ae'], ['+971501111111'], ['info@acme.ae'], ['https://facebook.com/acme'],
   [{'freeform': 'Marina', 'locality': 'Dubai', 'postcode': NULL, 'region': 'DU', 'country': 'AE'}],
   0.95, 'open', [{'dataset': 'meta', 'license': 'CDLA-Permissive-2.0', 'record_id': 'm1', 'update_time': '2026-09-01'}],
   55.14, 25.08),
  ('p2', 'دار العقارات', 'Dar Properties', 'real_estate_service', ['services','real_estate_service'], 'real_estate_service', NULL,
   [], ['04 555 1234'], [], [], [], 0.6, 'open', [], 55.20, 25.10),
  ('p3', 'Closed Cafe', NULL, 'cafe', ['food_and_drink','cafe'], 'cafe', NULL,
   ['https://cafe.ae'], [], [], [], [], 0.9, 'permanently_closed', [], 55.15, 25.09),
  ('p4', 'Far Away Realty', NULL, 'real_estate_agent', ['services','real_estate_service','real_estate_agent'], 'real_estate_service', 'Big Brand',
   ['https://far.ae'], [], [], [], [], 0.8, 'open', [], 55.60, 25.30)
) t(id, name, name_en, category, hierarchy, basic_category, brand, websites, phones, emails, socials, addresses,
    confidence, operating_status, sources, lng, lat)
"""

COUNTRY_ROWS = """
SELECT * FROM (VALUES
  ('d1', 'دبي', 'Dubai Emirate', 'region', 'AE-DU', NULL, 54.65, 24.62, 56.2, 25.37),
  ('d2', 'مركز دبي المالي العالمي', 'Dubai International Financial Centre', 'locality', 'AE-DU', NULL, 55.278, 25.211, 55.284, 25.216),
  ('d3', 'أبوظبي', 'Abu Dhabi Emirate', 'region', 'AE-AZ', NULL, 51.5, 22.6, 56.0, 25.2)
) t(id, name, name_en, subtype, region, population, x0, y0, x1, y1)
"""


class PlacesStoreTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.calls = []

        def remote(sql, params, out_path):
            self.calls.append(sql)
            fixture = COUNTRY_ROWS if "division" in sql else AREA_ROWS
            con = duckdb.connect()
            con.execute(f"COPY ({fixture}) TO '{out_path}' (FORMAT PARQUET)")
            con.close()

        self.store = PlacesStore(self.tmp.name, release="2026-09-23.1", remote=remote)

    def tearDown(self):
        self.store._pool.shutdown(wait=True)
        self.tmp.cleanup()

    def _ready(self, fn, timeout=10):
        deadline = time.time() + timeout
        while time.time() < deadline:
            r = fn()
            if r["status"] != "loading":
                return r
            time.sleep(0.05)
        self.fail("build never finished")

    def _area(self):
        return self._ready(lambda: self.store.ensure_area([55.0, 25.0, 55.7, 25.4]))

    def test_area_loads_once_then_comes_from_cache(self):
        first = self.store.ensure_area([55.0, 25.0, 55.7, 25.4])
        self.assertEqual(first["status"], "loading")
        area = self._area()
        self.assertEqual((area["status"], area["total"]), ("ready", 4))
        self.store.ensure_area([55.0, 25.0, 55.7, 25.4])
        self.assertEqual(len(self.calls), 1, "a cached area must not be scanned again")

    def test_area_id_pins_release_and_rejects_huge_or_bad_boxes(self):
        self.assertTrue(self.store.area_id([55, 25, 55.5, 25.5]).startswith("2026-09-23.1_"))
        for bad in ([50, 20, 56, 26], [55, 25, 54, 26], [1, 2, 3]):
            with self.assertRaises(PlacesError):
                self.store.ensure_area(bad)
        for bad_id in ("../../etc", "x_y", "2026-09-23.1_ZZZ"):
            with self.assertRaises(PlacesError):
                self.store.area_status(bad_id)

    def test_category_filter_matches_the_hierarchy_and_excludes_closed(self):
        area = self._area()
        res = self.store.search(area["area_id"], {"categories": ["real_estate_service"]}, 0, 10)
        self.assertEqual(sorted(i["id"] for i in res["items"]), ["p1", "p2", "p4"])
        res = self.store.search(area["area_id"], {"categories": ["cafe"]}, 0, 10)
        self.assertEqual(res["total"], 0, "permanently closed places are excluded by default")

    def test_contact_requirements_keywords_and_confidence(self):
        aid = self._area()["area_id"]
        self.assertEqual([i["id"] for i in self.store.search(aid, {"require_email": True}, 0, 10)["items"]], ["p1"])
        self.assertEqual([i["id"] for i in self.store.search(aid, {"keywords": "dar"}, 0, 10)["items"]], ["p2"])
        self.assertEqual([i["id"] for i in self.store.search(aid, {"keywords": "big brand"}, 0, 10)["items"]], ["p4"])
        self.assertEqual(self.store.search(aid, {"min_confidence": 0.85}, 0, 10)["total"], 1)

    def test_radius_trims_the_box_to_a_circle(self):
        aid = self._area()["area_id"]
        res = self.store.search(aid, {"center": [55.14, 25.08], "radius_km": 10}, 0, 10)
        self.assertNotIn("p4", [i["id"] for i in res["items"]])

    def test_paging_is_stable_and_ends(self):
        aid = self._area()["area_id"]
        page1 = self.store.search(aid, {}, 0, 2)
        page2 = self.store.search(aid, {}, page1["next_offset"], 2)
        self.assertEqual(page1["total"], 3)
        self.assertEqual(len({i["id"] for i in page1["items"] + page2["items"]}), 3)
        self.assertIsNone(page2["next_offset"])

    def test_values_are_bound_not_spliced(self):
        aid = self._area()["area_id"]
        res = self.store.search(aid, {"keywords": "' OR 1=1 --", "categories": ["x') OR TRUE --"]}, 0, 10)
        self.assertEqual(res["total"], 0)

    def test_categories_are_counted(self):
        aid = self._area()["area_id"]
        cats = {c["key"]: c["count"] for c in self.store.categories(aid)}
        self.assertEqual(cats["real_estate_agent"], 2)

    def test_geocode_prefers_exact_then_searchable_areas(self):
        self.assertEqual(self.store.geocode("AE", "Dubai")["status"], "loading")
        res = self._ready(lambda: self.store.geocode("AE", "Dubai"))
        self.assertEqual(res["matches"][0]["name"], "Dubai Emirate")
        self.assertEqual(res["matches"][0]["local_name"], "دبي")
        abu = self.store.geocode("AE", "Abu Dhabi")["matches"][0]
        self.assertTrue(abu["too_large"])
        with self.assertRaises(PlacesError):
            self.store.geocode("../x", "Dubai")

    def test_a_failed_build_reports_failure_instead_of_looping(self):
        store = PlacesStore(self.tmp.name + "/f", release="2026-09-23.1",
                            remote=lambda *a: (_ for _ in ()).throw(RuntimeError("s3 down")))
        try:
            res = self._ready(lambda: store.ensure_area([55.0, 25.0, 55.7, 25.4]))
            self.assertEqual(res["status"], "failed")
            self.assertIn("s3 down", res["error"])
        finally:
            store._pool.shutdown(wait=True)


if __name__ == "__main__":
    unittest.main()
