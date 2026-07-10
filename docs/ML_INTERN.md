# ML-Intern for Spendly: Local-Only ML Workflow

> **Status: PARKED (2026-07).** Datasets were exported and prepared, but no MLX models were trained and none are planned. Decision: Spendly targets any self-hosted Docker host (CPU-only Linux), which MLX inference (Apple Silicon only) cannot serve; the shipped `ml/` scikit-learn stack is the strategic model path (see `docs/v1.1-roadmap.md`). Kept for reference in case a local-LLM track is revisited. Prepared datasets under `ml-intern/data/` contain real personal data — keep local, never commit.

[ml-intern](https://github.com/huggingface/ml-intern) is used here as a local autonomous ML engineer for Spendly. It researches, plans, and writes training code, but the actual dataset prep and model training stay on your Apple Silicon machine.

## Goals

- keep all Spendly data local
- use `huggingface/novita/moonshotai/kimi-k2.6` as the recommended ml-intern agent model
- train local MLX models for Spendly transaction tasks
- keep trained artifacts under `ml-intern/models/`
- avoid HF Hub pushes, HF Jobs, and remote session uploads

## Local Architecture

- **Agent brain:** Kimi K2.6 via HuggingFace Router
- **Training runtime:** MLX + `mlx-lm`
- **Data source:** Spendly database exports from `php artisan ml:export-dataset`
- **Prepared data:** local JSONL train/test splits in `ml-intern/data/*_mlx/`
- **Artifacts:** local adapters/models/metrics in `ml-intern/models/<task-name>/`

## Prerequisites

- Apple Silicon Mac with 32GB+ unified memory recommended
- Python/uv working for `ml-intern/`
- HuggingFace token for Router access
- OpenCode Go subscription if that is how you access Kimi K2.6
- Spendly app with imported and labeled transactions

## Configuration

Copy env file:

```bash
cp ml-intern/.env.example ml-intern/.env
```

Set at least:

```env
HF_TOKEN=hf_...
```

Optional fallback if you switch to Claude:

```env
ANTHROPIC_API_KEY=sk-ant-...
```

Current local-safe defaults in `ml-intern/configs/main_agent_config.json`:

- model: `huggingface/novita/moonshotai/kimi-k2.6`
- `save_sessions: false`
- no HF MCP server
- `yolo_mode: false`

Verify CLI:

```bash
uv run --project ml-intern ml-intern --help
```

## Spendly Labeling Workflow

### 1. Import transactions into Spendly

Import SLSP/Revolut data into Spendly first. Keep raw CSV handling in Spendly, not in `ml-intern/`.

### 2. Use the rule engine for easy wins

Create Spendly rules for repeated patterns before manual labeling. Good targets:

- stable merchants like Lidl, Kaufland, Spotify, Shell
- obvious transfers by IBAN/account patterns
- common partner cleanup patterns

Rule engine reference: `docs/ai/RULE_ENGINE.md`

### 3. Manually label remaining transactions in the UI

Focus on:

- category assignment
- transfer correctness
- counterparty normalization

The ML export quality is only as good as these labels.

## Export Datasets from Spendly

Export all three local training datasets:

```bash
php artisan ml:export-dataset categories --output=ml-intern/data
php artisan ml:export-dataset transfers --output=ml-intern/data
php artisan ml:export-dataset partners --output=ml-intern/data
```

For seeded/demo data, user `3` is often the useful local dev user:

```bash
php artisan ml:export-dataset categories --user=3 --output=ml-intern/data
```

Outputs:

- `ml-intern/data/categories.jsonl`
- `ml-intern/data/transfers.jsonl`
- `ml-intern/data/partners.jsonl`

Notes for `partners.jsonl`:

- rows are filtered to high-confidence non-system counterparties only
- partner normalization is role-aware when needed, e.g. `Websupport [merchant]` vs `Websupport [employer]`
- the prompt includes income/direction context so local models can learn role splits

## Prepare Local Train/Test Splits

Convert exports into prompt/completion train/test splits plus local HuggingFace Arrow datasets:

```bash
uv run --project ml-intern python ml-intern/scripts/prepare_datasets.py --task all --input-dir ml-intern/data
```

Per-task output:

- `ml-intern/data/<task>_mlx/train.jsonl`
- `ml-intern/data/<task>_mlx/test.jsonl`
- `ml-intern/data/<task>_hf/`

Example:

```bash
uv run --project ml-intern python ml-intern/scripts/prepare_datasets.py --task categories
```

## Install Local MLX Training Tools

Install MLX fine-tuning deps into the `ml-intern` project:

```bash
uv add --project ml-intern "mlx-lm[train]"
```

Optional for fuzzy partner-name evaluation:

```bash
uv add --project ml-intern rapidfuzz
```

Quick download/smoke test for the recommended base model:

```bash
uv run --project ml-intern mlx_lm.generate --model mlx-community/Qwen2.5-1.5B-Instruct-4bit --prompt "hello"
```

## Run ml-intern

Interactive mode:

```bash
cd ml-intern
ml-intern
```

Headless mode with the bundled local-only task prompts:

```bash
cd ml-intern
ml-intern --max-iterations 100 "$(cat tasks/transaction_classification.md)"
ml-intern --max-iterations 80 "$(cat tasks/transfer_detection.md)"
ml-intern --max-iterations 80 "$(cat tasks/partner_normalization.md)"
```

## Recommended Local MLX Training Pattern

For categorization:

```bash
uv run --project ml-intern mlx_lm.lora \
  --model mlx-community/Qwen2.5-1.5B-Instruct-4bit \
  --train \
  --data ml-intern/data/categories_mlx \
  --adapter-path ml-intern/models/spendly-categorizer/adapters \
  --iters 600 \
  --batch-size 1 \
  --grad-checkpoint
```

For local test evaluation:

```bash
uv run --project ml-intern mlx_lm.lora \
  --model mlx-community/Qwen2.5-1.5B-Instruct-4bit \
  --adapter-path ml-intern/models/spendly-categorizer/adapters \
  --data ml-intern/data/categories_mlx \
  --test
```

Use the same pattern for:

- `ml-intern/data/transfers_mlx` → `ml-intern/models/spendly-transfer-detector/`
- `ml-intern/data/partners_mlx` → `ml-intern/models/spendly-partner-normalizer/`

## Task Files

- `ml-intern/tasks/LOCAL_CONTEXT.md`
- `ml-intern/tasks/transaction_classification.md`
- `ml-intern/tasks/transfer_detection.md`
- `ml-intern/tasks/partner_normalization.md`

These prompts are rewritten for local MLX training only.

## How to Use Trained Models in `ml/`

Current Spendly inference stack:

- categorization: `ml/app/modules/model_training.py`
- transfers: `ml/app/modules/transfer_detection.py`
- partner cleanup: `ml/app/modules/merchant_extraction.py`

Recommended integration path:

1. Keep current scikit-learn/rule-based logic as fallback.
2. Add a new local inference module in `ml/app/modules/` that loads the MLX model or adapter from disk.
3. Configure model paths with env vars, for example:
    - `MLX_CATEGORIZER_MODEL_PATH=ml-intern/models/spendly-categorizer`
    - `MLX_TRANSFER_MODEL_PATH=ml-intern/models/spendly-transfer-detector`
    - `MLX_PARTNER_MODEL_PATH=ml-intern/models/spendly-partner-normalizer`
4. Gate rollout behind config so the existing FastAPI service can fall back safely if MLX artifacts are missing.

## Verification Checklist

```bash
uv run --project ml-intern ml-intern --help
php artisan ml:export-dataset categories --output=ml-intern/data
uv run --project ml-intern python ml-intern/scripts/prepare_datasets.py --task categories
```

Then confirm:

- ml-intern starts with Kimi K2.6 by default
- no MCP config is loaded
- no session saving/upload is active
- task prompts reference local paths only
- docs mention no HF Hub pushes or HF Jobs

## Troubleshooting

**No HF token found**

- Set `HF_TOKEN` in `ml-intern/.env`
- or run `huggingface-cli login`

**Not enough labeled data**

- add more labels in Spendly
- improve rule coverage first
- lower `--min-samples` if needed for experimentation

**MLX memory issues**

- reduce batch size to `1`
- use quantized 4-bit base models
- use fewer LoRA layers or fewer iterations
- fall back from 1.5B to a smaller MLX-compatible model

**Need faster iteration**

- start with one task only
- train on categories first
- keep the current `ml/` baseline for production fallback until metrics improve
