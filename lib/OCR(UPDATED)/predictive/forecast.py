#!/usr/bin/env python3
"""Forecast upcoming document volume for the CHRMO Document Tracking System.

Usage (from project root):
    python lib/OCR(UPDATED)/predictive/forecast.py

The script pulls recent tracking records, trains a Holt-Winters model,
and stores the next N days of predictions in the `predictions_cache` table.
If the database lacks enough history, a synthetic dataset is generated so
that the pipeline remains demo-ready.
"""
from __future__ import annotations

import math
import os
import random
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Dict, List, Tuple

import mysql.connector  # type: ignore
import pandas as pd
from mysql.connector.connection import MySQLConnection  # type: ignore
from statsmodels.tsa.holtwinters import ExponentialSmoothing  # type: ignore
from sklearn.compose import ColumnTransformer
from sklearn.linear_model import Ridge
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder

# ---------------------------------------------------------------------------
# Configuration (override via environment variables where needed)
# ---------------------------------------------------------------------------
DB_CONFIG = {
    "host": os.getenv("DB_HOST", "localhost"),
    "user": os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASS", ""),
    "database": os.getenv("DB_NAME", "chrmo_db"),
    "charset": os.getenv("DB_CHARSET", "utf8mb4"),
}

WINDOW_DAYS = int(os.getenv("FORECAST_WINDOW_DAYS", "120"))
FORECAST_DAYS = int(os.getenv("FORECAST_DAYS", "14"))
MIN_HISTORY_POINTS = int(os.getenv("MIN_HISTORY_POINTS", "45"))
METRIC_NAME = os.getenv("FORECAST_METRIC", "documents_per_day")

BASELINE_LEVEL = int(os.getenv("SYNTHETIC_BASELINE", "8"))
RANDOM_SEED = int(os.getenv("SYNTHETIC_SEED", "20241119"))

OUTPUT_TABLE = os.getenv("FORECAST_TABLE", "predictions_cache")
SLA_TABLE = os.getenv("SLA_TABLE", "sla_predictions")

TRAINING_STATUSES = os.getenv(
    "TRAINING_STATUSES",
    "Archived,Completed,Released,Approved"
).split(",")
PENDING_STATUSES = os.getenv("PENDING_STATUSES", "Pending,In Review").split(",")
DEFAULT_SLA = int(os.getenv("DEFAULT_SLA_DAYS", "7"))

SLA_MAP: Dict[str, int] = {
    "leave": int(os.getenv("SLA_LEAVE", "5")),
    "memo": int(os.getenv("SLA_MEMO", "4")),
    "request": int(os.getenv("SLA_REQUEST", "6")),
    "report": int(os.getenv("SLA_REPORT", "7")),
}

ROOT = Path(__file__).resolve().parent


# ---------------------------------------------------------------------------
def get_connection() -> MySQLConnection:
    return mysql.connector.connect(**DB_CONFIG)  # type: ignore[arg-type]


def ensure_predictions_table(conn: MySQLConnection) -> None:
    ddl = f"""
        CREATE TABLE IF NOT EXISTS {OUTPUT_TABLE} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            metric VARCHAR(64) NOT NULL,
            forecast_date DATE NOT NULL,
            forecast_value DECIMAL(10, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY metric_day (metric, forecast_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """
    with conn.cursor() as cur:  # type: ignore[attr-defined]
        cur.execute(ddl)
    conn.commit()


def ensure_sla_table(conn: MySQLConnection) -> None:
    ddl = f"""
        CREATE TABLE IF NOT EXISTS {SLA_TABLE} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_id VARCHAR(255) NOT NULL,
            document_type VARCHAR(255) NOT NULL,
            department VARCHAR(255) DEFAULT NULL,
            predicted_total_days DECIMAL(10,2) NOT NULL,
            elapsed_days DECIMAL(10,2) NOT NULL,
            sla_days INT NOT NULL,
            risk_score DECIMAL(5,4) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY doc_unique (document_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """
    with conn.cursor() as cur:  # type: ignore[attr-defined]
        cur.execute(ddl)
    conn.commit()


def fetch_history() -> pd.DataFrame:
    """Pull recent document counts per day from the tracking table."""
    query = f"""
        SELECT DATE(date_submitted) AS day, COUNT(*) AS doc_count
        FROM tracking
        WHERE date_submitted IS NOT NULL
          AND date_submitted >= DATE_SUB(CURDATE(), INTERVAL %s DAY)
        GROUP BY day
        ORDER BY day;
    """
    conn = get_connection()
    try:
        df = pd.read_sql(query, conn, params=[WINDOW_DAYS])
    finally:
        conn.close()

    if df.empty:
        return df

    df["day"] = pd.to_datetime(df["day"]).dt.date
    df = df.set_index("day")

    # Reindex to guarantee continuous dates (fill missing days with zero volume)
    full_range = pd.date_range(df.index.min(), df.index.max(), freq="D").date
    df = df.reindex(full_range, fill_value=0)
    df.index.name = "day"
    return df


def fetch_training_dataset() -> pd.DataFrame:
    query = f"""
        WITH history AS (
            SELECT doc_id,
                   GREATEST(
                       1,
                       COALESCE(TIMESTAMPDIFF(DAY, MIN(created_at), MAX(created_at)), 1)
                   ) AS duration_days
            FROM document_history
            GROUP BY doc_id
        )
        SELECT t.id, t.type, t.department, t.date_submitted,
               COALESCE(h.duration_days, GREATEST(1, DATEDIFF(CURDATE(), t.date_submitted))) AS duration_days
        FROM tracking t
        LEFT JOIN history h ON h.doc_id = t.id
        WHERE t.status IN ({','.join(['%s'] * len(TRAINING_STATUSES))})
          AND t.date_submitted IS NOT NULL;
    """
    conn = get_connection()
    try:
        df = pd.read_sql(query, conn, params=TRAINING_STATUSES)
    finally:
        conn.close()
    return df


def fetch_pending_documents() -> pd.DataFrame:
    query = f"""
        SELECT id, type, department, date_submitted
        FROM tracking
        WHERE status IN ({','.join(['%s'] * len(PENDING_STATUSES))})
          AND date_submitted IS NOT NULL;
    """
    conn = get_connection()
    try:
        df = pd.read_sql(query, conn, params=PENDING_STATUSES)
    finally:
        conn.close()
    return df


def generate_synthetic_training(rows: int = 120) -> pd.DataFrame:
    random.seed(RANDOM_SEED + 42)
    types = ["Leave Application", "Memo", "Purchase Request", "Report"]
    departments = ["CHRMO", "GSO", "Finance", "Mayor's Office", "Records"]
    base_duration = {"leave": 4, "memo": 3, "request": 6, "report": 7}
    synthetic: List[Dict[str, object]] = []
    today = date.today()
    for i in range(rows):
        doc_type = random.choice(types)
        dept = random.choice(departments)
        submitted = today - timedelta(days=random.randint(10, 120))
        key = doc_type.lower()
        baseline = base_duration.get(
            next((k for k in base_duration if k in key), None),
            DEFAULT_SLA
        )
        duration = max(1, round(random.gauss(baseline, 1.5)))
        synthetic.append(
            {
                "id": f"SYN-{i}",
                "type": doc_type,
                "department": dept,
                "date_submitted": submitted,
                "duration_days": duration,
            }
        )
    return pd.DataFrame(synthetic)


def train_regression_model(df: pd.DataFrame) -> Pipeline:
    df = df.copy()
    df["date_submitted"] = pd.to_datetime(df["date_submitted"])
    df["weekday"] = df["date_submitted"].dt.weekday
    features = ["type", "department", "weekday"]
    target = "duration_days"

    transformer = ColumnTransformer(
        transformers=[
            ("cat", OneHotEncoder(handle_unknown="ignore"), ["type", "department"]),
            ("num", "passthrough", ["weekday"]),
        ]
    )
    model = Pipeline([("prep", transformer), ("reg", Ridge(alpha=0.8))])
    model.fit(df[features], df[target])
    return model


def lookup_sla(document_type: str) -> int:
    lowered = document_type.lower() if document_type else ""
    for key, sla in SLA_MAP.items():
        if key in lowered:
            return sla
    return DEFAULT_SLA


def risk_score(predicted_total: float, elapsed: float, sla_days: int) -> float:
    # Sigmoid-style risk: >1 => high risk
    if sla_days <= 0:
        sla_days = DEFAULT_SLA
    ratio = (elapsed + max(0.0, predicted_total - elapsed) - sla_days) / max(1.0, sla_days)
    return 1 / (1 + math.exp(-ratio * 3))


def run_sla_predictions() -> None:
    train_df = fetch_training_dataset()
    if len(train_df) < 25:
        print(
            f"Only {len(train_df)} completed records available; augmenting with synthetic samples."
        )
        synthetic = generate_synthetic_training(max(80, 120 - len(train_df)))
        train_df = pd.concat([train_df, synthetic], ignore_index=True)

    model = train_regression_model(train_df)

    pending_df = fetch_pending_documents()
    if pending_df.empty:
        print("No pending documents found for SLA prediction.")
        conn = get_connection()
        try:
            ensure_sla_table(conn)
            with conn.cursor() as cur:  # type: ignore[attr-defined]
                cur.execute(f"TRUNCATE TABLE {SLA_TABLE}")
            conn.commit()
        finally:
            conn.close()
        return

    pending = pending_df.copy()
    pending["date_submitted"] = pd.to_datetime(pending["date_submitted"])
    pending["weekday"] = pending["date_submitted"].dt.weekday
    features = pending[["type", "department", "weekday"]]
    preds = model.predict(features)

    today_ts = pd.Timestamp(date.today())
    pending["elapsed_days"] = (today_ts - pending["date_submitted"]).dt.days.clip(lower=0)
    pending["predicted_total"] = preds.clip(min=1)
    pending["sla_days"] = pending["type"].apply(lookup_sla)
    pending["risk_score"] = pending.apply(
        lambda row: risk_score(row["predicted_total"], row["elapsed_days"], row["sla_days"]),
        axis=1,
    )

    rows = [
        (
            str(row["id"]),
            row["type"],
            row.get("department"),
            float(round(row["predicted_total"], 2)),
            float(round(row["elapsed_days"], 2)),
            int(row["sla_days"]),
            float(round(row["risk_score"], 4)),
        )
        for _, row in pending.iterrows()
    ]

    conn = get_connection()
    try:
        ensure_sla_table(conn)
        with conn.cursor() as cur:  # type: ignore[attr-defined]
            cur.execute(f"TRUNCATE TABLE {SLA_TABLE}")
            cur.executemany(
                f"""
                INSERT INTO {SLA_TABLE}
                    (document_id, document_type, department, predicted_total_days,
                     elapsed_days, sla_days, risk_score)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
                """,
                rows,
            )
        conn.commit()
    finally:
        conn.close()

    print(f"Stored SLA predictions for {len(rows)} pending documents in `{SLA_TABLE}`.")


def generate_synthetic_history(days: int = 120) -> pd.DataFrame:
    """Create a plausible workload curve for demo purposes."""
    random.seed(RANDOM_SEED)
    today = date.today()
    rng = pd.date_range(today - timedelta(days=days - 1), today, freq="D")

    rows: List[Tuple[date, int]] = []
    for dt in rng:
        weekday_factor = 1.25 if dt.weekday() < 5 else 0.55
        seasonal = 1.0 + 0.35 * math.sin(2 * math.pi * (dt.dayofyear % 30) / 30)
        noise = random.uniform(-0.18, 0.18)
        value = max(0, round(BASELINE_LEVEL * weekday_factor * seasonal * (1 + noise)))
        rows.append((dt.date(), value))

    df = pd.DataFrame(rows, columns=["day", "doc_count"]).set_index("day")
    return df


def train_model(series: pd.Series) -> ExponentialSmoothing:
    """Create and fit a Holt-Winters model."""
    model = ExponentialSmoothing(
        series,
        trend="add",
        seasonal="add",
        seasonal_periods=7,
        initialization_method="estimated",
    )
    fitted = model.fit(optimized=True)
    return fitted


def persist_forecast(records: List[Tuple[str, date, float]]) -> None:
    if not records:
        print("No forecast generated; skipping write.")
        return

    conn = get_connection()
    try:
        ensure_predictions_table(conn)
        with conn.cursor() as cur:  # type: ignore[attr-defined]
            cur.execute(f"DELETE FROM {OUTPUT_TABLE} WHERE metric = %s", (METRIC_NAME,))
            cur.executemany(
                f"""
                INSERT INTO {OUTPUT_TABLE} (metric, forecast_date, forecast_value)
                VALUES (%s, %s, %s)
                ON DUPLICATE KEY UPDATE forecast_value = VALUES(forecast_value),
                                        created_at = CURRENT_TIMESTAMP
                """,
                records,
            )
        conn.commit()
    finally:
        conn.close()


def main() -> None:
    history = fetch_history()
    source = "database"

    if len(history) < MIN_HISTORY_POINTS:
        print(
            f"Only {len(history)} historical points found (minimum {MIN_HISTORY_POINTS}). "
            "Using synthetic history for now."
        )
        history = generate_synthetic_history(max(MIN_HISTORY_POINTS, WINDOW_DAYS))
        source = "synthetic"

    series = history["doc_count"].astype(float)

    print(f"Training Holt-Winters model on {len(series)} points ({source} data)...")
    model = train_model(series)

    forecast_index = pd.date_range(date.today() + timedelta(days=1), periods=FORECAST_DAYS, freq="D")
    forecast_values = model.forecast(FORECAST_DAYS)

    rows = [
        (METRIC_NAME, forecast_date.date(), float(max(0.0, round(value, 2))))
        for forecast_date, value in zip(forecast_index, forecast_values)
    ]

    persist_forecast(rows)
    print(f"Stored {len(rows)} forecast rows in `{OUTPUT_TABLE}` (metric={METRIC_NAME}).")

    # --- SLA Risk Predictions ---
    run_sla_predictions()


if __name__ == "__main__":
    main()
