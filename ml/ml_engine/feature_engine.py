"""Shared feature extraction for ML tasks."""

import numpy as np
import pandas as pd
from .preprocessor import clean_description


def build_text_feature(row: pd.Series) -> str:
    """Combine partner + description + place into single text input."""
    parts = []
    for col in ("partner", "description", "place"):
        val = row.get(col)
        if pd.notna(val) and str(val).strip():
            parts.append(str(val).strip())
    return " ".join(parts)


def extract_amount_features(df: pd.DataFrame) -> pd.DataFrame:
    """Extract amount-based features."""
    out = pd.DataFrame(index=df.index)
    amount = df["amount"].astype(float)
    out["amount_abs"] = amount.abs()
    out["amount_log"] = np.log1p(amount.abs())
    out["amount_sign"] = np.sign(amount).astype(int)  # -1=expense, 1=income
    return out


def extract_temporal_features(df: pd.DataFrame) -> pd.DataFrame:
    """Extract date-based features from booked_date."""
    out = pd.DataFrame(index=df.index)
    dt = pd.to_datetime(df["booked_date"])
    out["day_of_week"] = dt.dt.dayofweek  # 0=Mon, 6=Sun
    out["day_of_month"] = dt.dt.day
    out["is_month_start"] = (dt.dt.day <= 5).astype(int)
    out["is_month_end"] = (dt.dt.day >= 25).astype(int)
    out["month"] = dt.dt.month
    return out


def extract_type_features(df: pd.DataFrame) -> pd.DataFrame:
    """One-hot encode transaction type."""
    if "type" not in df.columns:
        return pd.DataFrame(index=df.index)
    dummies = pd.get_dummies(df["type"], prefix="type", dtype=int)
    return dummies


def extract_mcc_code(metadata: dict | str | None) -> str | None:
    """Extract MCC code from transaction metadata or remittance info.

    Looks for patterns like 'MCC-5411' or 'MCC:5411' in metadata fields.
    """
    if metadata is None:
        return None

    import json
    if isinstance(metadata, str):
        try:
            metadata = json.loads(metadata)
        except (json.JSONDecodeError, TypeError):
            # Might be raw remittance string like "MCC-5411"
            import re
            match = re.search(r"MCC[- :]?(\d{4})", metadata, re.IGNORECASE)
            return match.group(1) if match else None

    if isinstance(metadata, dict):
        # Check common GoCardless fields
        for key in ("remittanceInformationUnstructured", "remittance_info", "bankTransactionCode"):
            val = metadata.get(key, "")
            if val:
                import re
                match = re.search(r"MCC[- :]?(\d{4})", str(val), re.IGNORECASE)
                if match:
                    return match.group(1)

    return None


def extract_bank_tx_code(metadata: dict | str | None) -> str | None:
    """Extract bankTransactionCode for payment method indicator."""
    if metadata is None:
        return None

    import json
    if isinstance(metadata, str):
        try:
            metadata = json.loads(metadata)
        except (json.JSONDecodeError, TypeError):
            return None

    if isinstance(metadata, dict):
        return metadata.get("bankTransactionCode")

    return None


def build_feature_matrix(df: pd.DataFrame) -> tuple[list[str], pd.DataFrame]:
    """Build combined feature matrix from transactions.

    Returns:
        texts: list of combined text features (for TF-IDF)
        numeric_features: DataFrame of numeric features
    """
    texts = df.apply(build_text_feature, axis=1).tolist()

    parts = [
        extract_amount_features(df),
        extract_temporal_features(df),
        extract_type_features(df),
    ]
    numeric = pd.concat(parts, axis=1).fillna(0)

    return texts, numeric
