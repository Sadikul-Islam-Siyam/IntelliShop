"""Tests for Statistical Anomaly Detection (Modified Z-Score + IQR Fences) (#52)."""

import unittest
from models.zscore_anomaly import detect_anomalies


class TestZScoreAnomalyDetection(unittest.TestCase):
    def test_injected_spike_flagged_by_mad_and_iqr(self):
        # Baseline series with typical sales around 10 units/day
        series = [
            {"date": f"2026-10-{i:02d}", "quantity": val}
            for i, val in enumerate([10, 11, 10, 9, 10, 12, 10, 11, 9, 10, 10, 11, 10, 9, 10], start=1)
        ]

        # Inject an extreme sales spike on day 16
        spike_date = "2026-10-16"
        series.append({"date": spike_date, "quantity": 100.0})

        result = detect_anomalies(series, mad_threshold=3.5, iqr_k=1.5)

        self.assertGreaterEqual(result["anomaly_count"], 1)

        flagged = next((a for a in result["anomalies"] if a["date"] == spike_date), None)
        self.assertNotNull = self.assertIsNotNone(flagged)
        self.assertEqual(flagged["actual_value"], 100.0)
        self.assertEqual(flagged["expected_value"], 10.0)  # median is 10.0
        self.assertTrue(flagged["flagged_by_mad"])
        self.assertTrue(flagged["flagged_by_iqr"])
        self.assertGreater(flagged["deviation_score"], 3.5)

    def test_stable_series_produces_no_anomalies(self):
        series = [
            {"date": f"2026-10-{i:02d}", "quantity": 10 + (i % 2)}
            for i in range(1, 20)
        ]

        result = detect_anomalies(series, mad_threshold=3.5, iqr_k=1.5)
        self.assertEqual(result["anomaly_count"], 0)


if __name__ == "__main__":
    unittest.main()
