import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from src.Neshan.neshan_search import (
    Category,
    NeshanSearcher,
    detect_place_kind,
    is_relevant_record,
    normalize_text,
    paginate_results,
    parse_place_record,
)


class NeshanSearchHelpersTest(unittest.TestCase):
    def test_normalizes_persian_letters_digits_and_joiners(self):
        self.assertEqual(normalize_text("هتل‌ در تهران ۱۲۳"), "هتل در تهران 123")
        self.assertEqual(normalize_text("كافه ياس"), "کافه یاس")

    def test_filters_a_medical_result_from_a_hotel_search(self):
        hotel = Category("hotel", "هتل")
        self.assertFalse(
            is_relevant_record(
                "فروشگاه سمعک و شنوایی سنجی غرب تهران",
                [
                    "فروشگاه سمعک و شنوایی سنجی غرب تهران",
                    "مجتمع پزشکی",
                    "بلوار آیت الله کاشانی",
                ],
                hotel,
            )
        )

    def test_accepts_the_requested_type_and_rejects_a_different_type(self):
        hotel = Category("hotel", "هتل")
        self.assertTrue(
            is_relevant_record("هتل اسپارو", ["هتل اسپارو", "هتل", "در حال ساخت"], hotel)
        )
        self.assertFalse(
            is_relevant_record(
                "خوابگاه نمایندگی دانشگاه علوم پزشکی",
                ["خوابگاه نمایندگی دانشگاه علوم پزشکی", "خوابگاه و پانسیون"],
                hotel,
            )
        )
        self.assertFalse(
            is_relevant_record(
                "بوفه مجلل صبحانه در هتل پارسیان آزادی",
                ["بوفه مجلل صبحانه در هتل پارسیان آزادی"],
                hotel,
            )
        )
        self.assertFalse(
            is_relevant_record(
                "سمینار سرمایه گذاری در وقت اضافه - هتل هما",
                ["سمینار سرمایه گذاری در وقت اضافه - هتل هما"],
                hotel,
            )
        )

    def test_detects_the_actual_type_from_card_text(self):
        kind, category_line = detect_place_kind(["۲ رای", "خوابگاه و پانسیون"])
        self.assertEqual(kind, "hostel")
        self.assertEqual(category_line, "خوابگاه و پانسیون")
        kind, _ = detect_place_kind(["اقامتگاه بوم‌گردی"])
        self.assertEqual(kind, "vernacular-accommodation")

    def test_parses_a_stable_id_address_phone_and_rating(self):
        hotel = Category("hotel", "هتل")
        place = parse_place_record(
            {
                "href": "https://neshan.org/maps/places/abc123",
                "link_text": "هتل اسپارو",
                "card_text": "هتل اسپارو\n۵\n۲ رای\nهتل\nخیابان ولیعصر\n۰۲۱۱۲۳۴۵۶۷۸",
                "external_links": ["https://example.com"],
            },
            hotel,
        )
        self.assertIsNotNone(place)
        assert place is not None
        self.assertEqual(place.place_id, "abc123")
        self.assertEqual(place.name, "هتل اسپارو")
        self.assertEqual(place.address, "خیابان ولیعصر")
        self.assertEqual(place.phone, "02112345678")
        self.assertEqual(place.rating, 5.0)
        self.assertEqual(place.website, "https://example.com")

        # A name containing the search term is not itself a displayed type.
        sparse_place = parse_place_record(
            {
                "href": "https://neshan.org/maps/places/sparse",
                "link_text": "هتل اسپارو",
                "card_text": "هتل اسپارو",
            },
            hotel,
        )
        self.assertIsNotNone(sparse_place)
        assert sparse_place is not None
        self.assertIsNone(sparse_place.category)

    def test_paginates_with_lookahead_without_claiming_an_unknown_total(self):
        places = [{"place_id": str(index)} for index in range(41)]
        first_page, has_more, total = paginate_results(places, 1, 20, complete=False)
        self.assertEqual(len(first_page), 20)
        self.assertTrue(has_more)
        self.assertIsNone(total)

        final_page, has_more, total = paginate_results(places, 3, 20, complete=True)
        self.assertEqual(len(final_page), 1)
        self.assertFalse(has_more)
        self.assertEqual(total, 41)

    def test_next_page_uses_cached_results_instead_of_reopening_neshan(self):
        category = Category("hotel", "هتل")
        results = [{"place_id": str(index), "name": f"هتل {index}"} for index in range(41)]
        with tempfile.TemporaryDirectory() as cache_dir:
            cache_path = Path(cache_dir) / "results.json"
            with patch.object(NeshanSearcher, "_cache_path", return_value=cache_path):
                searcher = NeshanSearcher()
                searcher._write_cache("تهران", category, results, complete=False)
                page = searcher.search_category(category, "تهران", max_results=20, page=2)

        self.assertEqual([place["place_id"] for place in page["results"]], [str(i) for i in range(20, 40)])
        self.assertTrue(page["has_more"])
        self.assertFalse(page["complete"])


if __name__ == "__main__":
    unittest.main()
