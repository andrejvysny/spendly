"""Merchant extraction/normalization heuristics."""

from __future__ import annotations

from app.modules.merchant_extraction import (
    ascii_fold,
    clean_text,
    extract_merchant_single,
    normalize_merchant,
)


def test_ascii_fold_slovak_diacritics() -> None:
    assert ascii_fold("Potraviny Kráľovské Šaľa") == "Potraviny Kralovske Sala"


def test_clean_text_strips_bank_noise() -> None:
    cleaned = clean_text("LIDL SK 1234 VS:123456 SK9900000000000000000006 20.01.2026")
    assert "VS" not in cleaned
    assert "SK31" not in cleaned
    assert "LIDL" in cleaned.upper()


def test_normalize_merchant_strips_legal_suffix() -> None:
    assert "s r o" not in normalize_merchant("Websupport s.r.o.")
    assert normalize_merchant("Websupport s.r.o.").startswith("websupport")


def test_extract_merchant_single_returns_contract_shape() -> None:
    result = extract_merchant_single(description="NETFLIX.COM payment")
    assert {"merchant_name", "merchant_normalized", "confidence"} <= set(result.keys())
