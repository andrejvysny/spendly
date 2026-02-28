"""Tests for the text normalization pipeline."""

import pytest

from ml_engine.preprocessor import clean_description, normalize_merchant


class TestCleanDescription:
    def test_strips_bank_prefix(self):
        assert clean_description("CARD PAYMENT TO Lidl") == "Lidl"
        assert clean_description("POS PURCHASE McDonald's") == "McDonald's"

    def test_strips_platba_kartou(self):
        assert "Platba kartou" not in clean_description("Platba kartou LIDL dakuje 165")

    def test_strips_iban(self):
        result = clean_description("Transfer SK9009000000005124514591 Lidl")
        assert "SK90090" not in result
        assert "Lidl" in result

    def test_strips_card_mask(self):
        result = clean_description("454412****4461 payment")
        assert "454412" not in result
        assert "payment" in result

    def test_strips_date(self):
        result = clean_description("Payment 03/02/2026 Lidl")
        assert "03/02/2026" not in result
        result2 = clean_description("Payment 03.02.2026 Lidl")
        assert "03.02.2026" not in result2

    def test_strips_long_numbers(self):
        result = clean_description("Lidl 1770151458340")
        assert "1770151458340" not in result
        assert "Lidl" in result

    def test_strips_reference_numbers(self):
        result = clean_description("Payment /VS1028746901/SS/KS")
        assert "VS1028746901" not in result

    def test_strips_legal_suffixes(self):
        result = clean_description("FIRMA s.r.o.")
        assert "s.r.o." not in result
        assert "FIRMA" in result

    def test_normalizes_whitespace(self):
        result = clean_description("  Lidl   dakuje  ")
        assert result == "Lidl dakuje"

    def test_empty_input(self):
        assert clean_description("") == ""
        assert clean_description(None) == ""


class TestNormalizeMerchant:
    def test_basic_normalization(self):
        assert normalize_merchant("Lidl") == "lidl"
        assert normalize_merchant("SUPER ZOO") == "super zoo"

    def test_strips_store_numbers(self):
        assert normalize_merchant("Lidl dakuje 165") == "lidl dakuje"

    def test_strips_legal_suffix(self):
        assert normalize_merchant("FIRMA s.r.o.") == "firma"

    def test_strips_long_numbers(self):
        result = normalize_merchant("3520_SUPER ZOO")
        assert "3520" not in result

    def test_empty_input(self):
        assert normalize_merchant("") == ""
        assert normalize_merchant(None) == ""
