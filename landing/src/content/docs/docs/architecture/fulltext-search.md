---
title: Full-Text Search
description: Fast transaction search powered by SQLite FTS5 virtual tables.
---

## Overview

Spendly uses SQLite **FTS5** (Full-Text Search 5) virtual tables for fast, flexible transaction search. FTS5 creates an inverted index that maps terms to rows, enabling sub-millisecond lookups even on large datasets.

## How It Works

### Virtual Tables

A virtual table in SQLite is backed by a custom module rather than stored as a regular table. FTS5 provides optimized full-text search capabilities through this mechanism.

### Creating the Index

```sql
CREATE VIRTUAL TABLE transactions_fts USING fts5(partner, type, tags, comments);
```

### Populating the Index

```sql
INSERT INTO transactions_fts(transactions_fts) VALUES('rebuild');
```

### Searching

```sql
SELECT * FROM transactions_fts WHERE transactions_fts MATCH 'search_term*';
```

## FTS5 Capabilities

### Query Types

- **Exact matches** — Find rows containing a specific term
- **Prefix matches** — Wildcards like `term*` for terms starting with a prefix
- **Boolean operators** — Combine terms with `AND`, `OR`, `NOT`
- **Phrase matching** — Search for exact phrases

### Tokenization

FTS5 tokenizes text into searchable terms. The `unicode61` tokenizer supports Unicode characters and normalizes text for better results.

### Relevance Ranking

Results can be ranked by term frequency and position in the text.

## Benefits Over LIKE

| Feature             | FTS5                                   | LIKE/instr            |
| ------------------- | -------------------------------------- | --------------------- |
| **Performance**     | Inverted index, fast on large datasets | Full table scan       |
| **Partial matches** | Native `term*` support                 | Requires `%term%`     |
| **Boolean logic**   | `AND`, `OR`, `NOT` operators           | Manual query building |
| **Phrase matching** | Built-in                               | Not supported         |
| **Ranking**         | Relevance scoring                      | No ranking            |
| **Scalability**     | Efficient for millions of rows         | Degrades linearly     |
