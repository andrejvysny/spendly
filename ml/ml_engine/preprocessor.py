"""Text normalization pipeline for bank transaction descriptions."""

import re

# Bank payment prefixes to strip
_BANK_PREFIXES = [
    "CARD PAYMENT TO", "POS PURCHASE", "TRANSFER TO", "PAYMENT TO",
    "BILL PAYMENT", "DIRECT DEBIT", "STANDING ORDER", "SEPA CREDIT",
    "SEPA DIRECT DEBIT", "PLATBA KARTOU", "OKAMZITA PLATBA",
    "PREVOD NA UCET", "INKASO", "TRVALY PRIKAZ",
]

# Legal entity suffixes
_LEGAL_SUFFIXES = re.compile(
    r"\b(s\.r\.o\.|a\.s\.|spol\.|k\.s\.|v\.o\.s\.|"
    r"Ltd\.?|LLC|Inc\.?|Corp\.?|GmbH|AG|B\.V\.|N\.V\.|S\.A\.|"
    r"SE|PLC|Pty|Co\.)\s*$",
    re.IGNORECASE,
)

# Patterns to strip
_IBAN_RE = re.compile(r"\b[A-Z]{2}\d{2}[A-Z0-9]{4,30}\b")
_CARD_MASK_RE = re.compile(r"\d{4,6}\*{2,}\d{2,4}")
_DATE_RE = re.compile(r"\b\d{1,2}[./-]\d{1,2}[./-]\d{2,4}\b")
_LONG_NUM_RE = re.compile(r"\b\d{5,}\b")
_STORE_NUM_RE = re.compile(r"\b\d{2,4}(?=\s*$)")
_LEADING_NUM_PREFIX_RE = re.compile(r"^\d{2,6}[_\-]\s*")
_MULTI_SPACE_RE = re.compile(r"\s+")
_VS_SS_KS_RE = re.compile(r"/[VvSsKk][Ss]?\d+", re.IGNORECASE)
_REF_RE = re.compile(r"\b(VS|SS|KS)\s*[:=]?\s*\d+\b", re.IGNORECASE)


def clean_description(desc: str | None) -> str:
    """Clean raw bank description for ML features.

    Strips bank prefixes, card masks, IBANs, dates, long numeric IDs,
    reference numbers, and normalizes whitespace.
    """
    if not desc:
        return ""

    text = desc.strip()

    # Strip bank prefixes (case-insensitive)
    upper = text.upper()
    for prefix in _BANK_PREFIXES:
        if upper.startswith(prefix):
            text = text[len(prefix):]
            upper = text.upper()

    # Strip patterns
    text = _IBAN_RE.sub("", text)
    text = _CARD_MASK_RE.sub("", text)
    text = _DATE_RE.sub("", text)
    text = _VS_SS_KS_RE.sub("", text)
    text = _REF_RE.sub("", text)
    text = _LONG_NUM_RE.sub("", text)
    text = _LEGAL_SUFFIXES.sub("", text)

    # Normalize
    text = _MULTI_SPACE_RE.sub(" ", text).strip()
    return text


def normalize_merchant(name: str | None) -> str:
    """Normalize merchant name to canonical form for matching.

    Strips store numbers, legal suffixes, and lowercases.
    """
    if not name:
        return ""

    text = name.strip()

    # Remove leading store number prefix: "3520_SUPER ZOO" → "SUPER ZOO"
    text = _LEADING_NUM_PREFIX_RE.sub("", text)

    # Remove legal suffixes
    text = _LEGAL_SUFFIXES.sub("", text)

    # Remove trailing store/branch numbers: "Lidl dakuje 165" -> "Lidl dakuje"
    text = _STORE_NUM_RE.sub("", text)

    # Remove card masks and long numbers
    text = _CARD_MASK_RE.sub("", text)
    text = _LONG_NUM_RE.sub("", text)

    # Normalize
    text = _MULTI_SPACE_RE.sub(" ", text).strip().lower()
    return text
