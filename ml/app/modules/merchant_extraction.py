import re
import unicodedata

IBAN_RE = re.compile(r"[A-Z]{2}\d{2}\s?\d{4}\s?\d{4}\s?\d{4}\s?\d{4}\s?\d{0,4}")
CARD_MASK_RE = re.compile(r"\d{6}\*{4,}\d{4}")
LONG_NUM_RE = re.compile(r"\b\d{8,}\b")
VS_SS_KS_RE = re.compile(r"\b[VvSsKk][Ss]\s*[:=]?\s*\d+", re.IGNORECASE)
DATE_INLINE_RE = re.compile(r"\b\d{1,2}[./]\d{1,2}[./]\d{2,4}\b")
LEGAL_SUFFIX_RE = re.compile(
    r"\b(s\.?\s?r\.?\s?o\.?|a\.?\s?s\.?|spol\.?|"
    r"ltd\.?|gmbh|inc\.?|corp\.?|llc|b\.?v\.?|n\.?v\.?|"
    r"se|ag|plc|oy|ab|sa|sas|srl|kft|sp\.?\s?z\.?\s?o\.?\s?o\.?)\s*$",
    re.IGNORECASE,
)
STORE_NUM_PREFIX_RE = re.compile(r"^\d{3,6}[_\-\s]")
TRAILING_NUMS_RE = re.compile(r"\s+\d{2,}$")
DOMAIN_RE = re.compile(
    r"\b([a-zA-Z0-9-]+)\.(cz|sk|com|eu|net|org|io|de|at|hu|pl|co\.uk|com\.au)\b"
)


def ascii_fold(text: str) -> str:
    if not text:
        return ""
    nfkd = unicodedata.normalize("NFKD", text)
    return "".join(c for c in nfkd if not unicodedata.combining(c))


def clean_text(text: str) -> str:
    if not text or not isinstance(text, str):
        return ""
    t = text.strip()
    t = IBAN_RE.sub("", t)
    t = CARD_MASK_RE.sub("", t)
    t = LONG_NUM_RE.sub("", t)
    t = VS_SS_KS_RE.sub("", t)
    t = DATE_INLINE_RE.sub("", t)
    t = re.sub(r"\s+", " ", t).strip()
    t = t.strip(" ,-/_~")
    return t


def normalize_merchant(text: str) -> str:
    if not text:
        return ""
    t = text.strip()
    t = STORE_NUM_PREFIX_RE.sub("", t)
    t = LEGAL_SUFFIX_RE.sub("", t).strip()
    t = TRAILING_NUMS_RE.sub("", t).strip()
    for prefix in ["Payment from ", "To ", "From "]:
        if t.startswith(prefix) and len(t) > len(prefix) + 2:
            t = t[len(prefix) :]
    t = t.lower().strip()
    t = re.sub(r"\s+", " ", t)
    t = t.strip(" ,-/_~.*")
    return t


def extract_domain(text: str) -> str | None:
    if not text:
        return None
    m = DOMAIN_RE.search(text)
    if m:
        return m.group(1).lower()
    return None


def score_entity_type(text: str, norm: str) -> tuple[str, float]:
    score = 0

    if LEGAL_SUFFIX_RE.search(text):
        score -= 3
    if DOMAIN_RE.search(text):
        score -= 2
    if STORE_NUM_PREFIX_RE.search(text):
        score -= 2

    words = norm.split()
    if len(words) == 2:
        if all(w[0].isupper() for w in words if w):
            score += 2
        if all(w.isupper() for w in words):
            score += 1

    internal_patterns = [
        "pocket",
        "exchanged",
        "closing transaction",
        "sporenie",
        "space ucet",
        "referral",
        "roundups",
        "savings",
    ]
    if any(p in norm for p in internal_patterns):
        score -= 3

    if score <= -2:
        return "merchant", min(0.9, 0.5 + abs(score) * 0.1)
    elif score >= 1:
        return "person", min(0.9, 0.5 + score * 0.2)
    else:
        return "unknown", 0.5


def extract_merchant_single(
    description: str, counterparty_name: str | None = None
) -> dict:
    raw = counterparty_name if counterparty_name else description
    if not raw:
        return {
            "transaction_id": 0,
            "merchant_name": None,
            "merchant_normalized": None,
            "entity_type": "empty",
            "confidence": 1.0,
        }

    cleaned = clean_text(raw)
    norm = normalize_merchant(cleaned)

    if not norm:
        return {
            "transaction_id": 0,
            "merchant_name": cleaned if cleaned else None,
            "merchant_normalized": None,
            "entity_type": "empty",
            "confidence": 1.0,
        }

    entity_type, confidence = score_entity_type(cleaned, norm)

    return {
        "transaction_id": 0,
        "merchant_name": cleaned,
        "merchant_normalized": norm,
        "entity_type": entity_type,
        "confidence": confidence,
    }


def extract_merchants_batch(user_id: int, transactions: list[dict]) -> list[dict]:
    results = []
    for txn in transactions:
        result = extract_merchant_single(
            description=txn.get("description", ""),
            counterparty_name=txn.get("counterparty_name"),
        )
        result["transaction_id"] = txn.get("id", 0)
        results.append(result)
    return results
