"""MCC (Merchant Category Code) to category mapping."""

from __future__ import annotations

# ~100 common MCC codes mapped to generic English category names.
# Used as Stage 1 lookup in the categorizer.
MCC_TO_CATEGORY: dict[str, str] = {
    # Grocery / Supermarkets
    "5411": "Groceries",
    "5422": "Groceries",
    "5441": "Groceries",
    "5451": "Groceries",
    "5462": "Groceries",
    # Restaurants / Food
    "5812": "Restaurants",
    "5813": "Restaurants",
    "5814": "Fast Food",
    "5921": "Alcohol & Tobacco",
    # Transportation
    "4111": "Public Transport",
    "4112": "Railways",
    "4121": "Taxi",
    "4131": "Bus",
    "4784": "Tolls",
    "5541": "Gas Station",
    "5542": "Gas Station",
    "5983": "Gas Station",
    # Auto
    "5511": "Auto Dealer",
    "5521": "Auto Dealer",
    "5531": "Auto Parts",
    "5571": "Motorcycle",
    "7511": "Parking",
    "7523": "Parking",
    "7531": "Auto Repair",
    "7534": "Tire Shop",
    "7535": "Auto Paint",
    "7538": "Auto Service",
    "7542": "Car Wash",
    # Shopping / Retail
    "5200": "Home Improvement",
    "5211": "Home Improvement",
    "5251": "Hardware",
    "5261": "Garden",
    "5300": "Wholesale",
    "5311": "Department Store",
    "5331": "Variety Store",
    "5399": "General Merchandise",
    "5651": "Clothing",
    "5661": "Shoes",
    "5681": "Fur Store",
    "5691": "Clothing",
    "5699": "Clothing",
    "5732": "Electronics",
    "5733": "Music Store",
    "5734": "Software",
    "5735": "Music Store",
    "5912": "Pharmacy",
    "5942": "Books",
    "5943": "Office Supplies",
    "5944": "Jewelry",
    "5945": "Toys & Games",
    "5947": "Gift Shop",
    "5948": "Leather Goods",
    "5949": "Sewing & Fabric",
    "5970": "Art & Craft",
    "5977": "Cosmetics",
    "5995": "Pet Supplies",
    "5999": "Miscellaneous Retail",
    # Health
    "5047": "Medical Equipment",
    "5975": "Hearing Aids",
    "7011": "Hotel",
    "8011": "Doctor",
    "8021": "Dentist",
    "8031": "Osteopath",
    "8041": "Chiropractor",
    "8042": "Optician",
    "8049": "Healthcare",
    "8050": "Nursing",
    "8062": "Hospital",
    "8099": "Healthcare",
    # Entertainment
    "7832": "Cinema",
    "7841": "Video Rental",
    "7911": "Dance",
    "7922": "Theater",
    "7929": "Entertainment",
    "7932": "Billiards",
    "7933": "Bowling",
    "7941": "Sports",
    "7991": "Tourism",
    "7992": "Golf",
    "7993": "Arcade",
    "7994": "Video Games",
    "7996": "Amusement Park",
    "7997": "Recreation",
    "7998": "Aquarium",
    "7999": "Recreation",
    # Services
    "4814": "Telecom",
    "4816": "Internet",
    "4899": "Utilities",
    "4900": "Utilities",
    "6300": "Insurance",
    "6513": "Rent",
    "7210": "Laundry",
    "7211": "Laundry",
    "7230": "Beauty Salon",
    "7251": "Shoe Repair",
    "7261": "Funeral",
    "7273": "Dating",
    "7276": "Tax Preparation",
    "7277": "Counseling",
    "7278": "Shopping Club",
    "7296": "Clothing Rental",
    "7297": "Massage",
    "7298": "Spa",
    "7299": "Services",
    "7311": "Advertising",
    "7333": "Photography",
    "7338": "Printing",
    "7339": "Secretarial",
    "7342": "Pest Control",
    "7349": "Cleaning",
    "7361": "Employment Agency",
    "7372": "Software",
    "7375": "Information Services",
    "7379": "IT Services",
    "7392": "Consulting",
    "7393": "Security",
    "7394": "Equipment Rental",
    "7395": "Photo Lab",
    "7399": "Business Services",
    # Travel
    "3000": "Airlines",
    "3001": "Airlines",
    "4411": "Cruise",
    "4511": "Airlines",
    "4722": "Travel Agency",
    "7012": "Timeshare",
    # Education
    "8211": "School",
    "8220": "University",
    "8241": "Correspondence School",
    "8244": "Business School",
    "8249": "Vocational School",
    "8299": "Education",
    # Financial
    "6010": "Cash Withdrawal",
    "6011": "Cash Withdrawal",
    "6012": "Financial Services",
    "6051": "Crypto / Foreign Exchange",
    "6211": "Investments",
    "6540": "Stored Value Load",
    # Subscriptions
    "5815": "Digital Goods",
    "5816": "Digital Games",
    "5817": "Software Subscription",
    "5818": "Digital Subscription",
}


def get_mcc_category_name(mcc_code: str) -> str | None:
    """Look up generic category name for an MCC code."""
    return MCC_TO_CATEGORY.get(mcc_code)


def match_mcc_to_user_category(
    mcc_code: str,
    user_categories: list[dict],
    embedding_service: object | None = None,
) -> tuple[int | None, float]:
    """Map MCC code to user's actual category by name similarity.

    Args:
        mcc_code: 4-digit MCC code string
        user_categories: list of {"id": int, "name": str} dicts
        embedding_service: optional EmbeddingService for fuzzy matching

    Returns:
        (category_id, confidence) or (None, 0.0)
    """
    generic_name = get_mcc_category_name(mcc_code)
    if not generic_name:
        return None, 0.0

    if not user_categories:
        return None, 0.0

    # Exact name match (case-insensitive)
    for cat in user_categories:
        if cat["name"].lower() == generic_name.lower():
            return cat["id"], 0.95

    # Substring match
    for cat in user_categories:
        cat_lower = cat["name"].lower()
        gen_lower = generic_name.lower()
        if gen_lower in cat_lower or cat_lower in gen_lower:
            return cat["id"], 0.90

    # Embedding similarity fallback
    if embedding_service is not None and hasattr(embedding_service, "similarity"):
        cat_names = [c["name"] for c in user_categories]
        scores = embedding_service.similarity(generic_name, cat_names)
        if scores:
            best_idx = int(np.argmax(scores))
            best_score = scores[best_idx]
            if best_score >= 0.60:
                return user_categories[best_idx]["id"], float(best_score)

    return None, 0.0


# Need numpy for argmax in match function
import numpy as np
