from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from models.croston import fit_croston_sba, forecast_croston_sba, run_croston_recursion
from models.holt_winters import (
    fit_holt_winters,
    forecast_holt_winters,
    holt_winters_step,
    run_holt_winters_recursion,
)
from models.linear_trend import fit_linear_trend, forecast_linear_trend
from models.weekday_index import (
    compute_weekday_factors,
    fit_weekday_index,
    forecast_weekday_index,
)


class EquationModelsTest(unittest.TestCase):
    def test_weekday_index_on_four_week_series_reproduces_level_times_weekday_factor(self) -> None:
        # 4-week (28-day) series with distinct weekday pattern
        weekly_pattern = [10.0, 12.0, 14.0, 16.0, 20.0, 28.0, 26.0]
        series = []
        for week in range(4):
            scale = 1.0 + 0.05 * week
            series.extend([round(v * scale, 6) for v in weekly_pattern])

        fitted = fit_weekday_index(series, season_length=7)
        level = fitted["level"]
        factors = fitted["seasonal_indices"]

        expected_level = sum(series) / len(series)
        self.assertAlmostEqual(level, expected_level, places=6)
        self.assertAlmostEqual(sum(factors) / 7.0, 1.0, places=6)

        # Hand-computed weekday means and factors
        for d in range(7):
            day_vals = [series[w * 7 + d] for w in range(4)]
            day_mean = sum(day_vals) / 4.0
            expected_factor = day_mean / expected_level
            self.assertAlmostEqual(factors[d], expected_factor, places=6)

        preds = forecast_weekday_index(series, horizon=7, season_length=7, round_digits=None)
        for d in range(7):
            expected_pred = expected_level * factors[d]
            self.assertAlmostEqual(preds[d], expected_pred, places=6)

    def test_holt_winters_fixed_parameters_match_hand_computed_values_to_1e_6(self) -> None:
        alpha = 0.3
        beta = 0.2
        gamma = 0.4

        l_0 = 20.0
        b_0 = 1.0
        s_init = [-4.0, -2.0, 0.0, 1.0, 2.0, 3.0, 0.0]
        observations = [18.0, 21.0, 24.0, 26.0, 28.0, 30.0, 27.0]

        # Hand-compute step-by-step recursion for 7 days
        hand_l_prev = l_0
        hand_b_prev = b_0
        hand_s = list(s_init)
        hand_levels = []
        hand_trends = []

        for t, y_t in enumerate(observations):
            s_lag = hand_s[t]
            l_t = alpha * (y_t - s_lag) + (1.0 - alpha) * (hand_l_prev + hand_b_prev)
            b_t = beta * (l_t - hand_l_prev) + (1.0 - beta) * hand_b_prev
            s_t = gamma * (y_t - l_t) + (1.0 - gamma) * s_lag

            step_l, step_b, step_s = holt_winters_step(
                y_t=y_t,
                l_prev=hand_l_prev,
                b_prev=hand_b_prev,
                s_lag=s_lag,
                alpha=alpha,
                beta=beta,
                gamma=gamma,
            )
            self.assertAlmostEqual(step_l, l_t, places=6)
            self.assertAlmostEqual(step_b, b_t, places=6)
            self.assertAlmostEqual(step_s, s_t, places=6)

            hand_levels.append(l_t)
            hand_trends.append(b_t)
            hand_s.append(s_t)
            hand_l_prev, hand_b_prev = l_t, b_t

        res = run_holt_winters_recursion(
            observations,
            alpha=alpha,
            beta=beta,
            gamma=gamma,
            season_length=7,
            initial_level=l_0,
            initial_trend=b_0,
            initial_seasonals=s_init,
        )

        self.assertAlmostEqual(res["level"], hand_l_prev, places=6)
        self.assertAlmostEqual(res["trend"], hand_b_prev, places=6)
        for idx in range(7):
            self.assertAlmostEqual(res["levels"][idx], hand_levels[idx], places=6)
            self.assertAlmostEqual(res["trends"][idx], hand_trends[idx], places=6)
            self.assertAlmostEqual(res["seasonals"][idx], hand_s[7 + idx], places=6)

        preds = forecast_holt_winters(
            observations,
            horizon=7,
            season_length=7,
            alpha=alpha,
            beta=beta,
            gamma=gamma,
            initial_level=l_0,
            initial_trend=b_0,
            initial_seasonals=s_init,
            round_digits=None,
        )
        for h in range(1, 8):
            expected_y_hat = hand_l_prev + h * hand_b_prev + hand_s[7 + h - 1]
            self.assertAlmostEqual(preds[h - 1], expected_y_hat, places=6)

    def test_holt_winters_grid_search_selects_smoothing_parameters(self) -> None:
        # 28-day additive trend + 7-day seasonal pattern
        season = [-3.0, -2.0, -1.0, 0.0, 1.0, 3.0, 2.0]
        series = [20.0 + 0.5 * t + season[t % 7] for t in range(28)]

        fitted = fit_holt_winters(series, season_length=7)
        self.assertIn(fitted["alpha"], (0.1, 0.2, 0.3, 0.5, 0.7))
        self.assertIn(fitted["beta"], (0.05, 0.1, 0.2, 0.3))
        self.assertIn(fitted["gamma"], (0.1, 0.2, 0.3, 0.5))

        preds = forecast_holt_winters(series, horizon=7, season_length=7)
        self.assertEqual(len(preds), 7)
        for h in range(7):
            expected_next = 20.0 + 0.5 * (28 + h) + season[h]
            self.assertAlmostEqual(preds[h], expected_next, delta=1.5)

    def test_croston_sba_on_intermittent_series_matches_formula_and_true_rate(self) -> None:
        # Intermittent series: 10 units demanded every 5th day (true rate = 2.0/day)
        series = [
            0.0, 0.0, 0.0, 0.0, 10.0,
            0.0, 0.0, 0.0, 0.0, 10.0,
            0.0, 0.0, 0.0, 0.0, 10.0,
            0.0, 0.0, 0.0, 0.0, 10.0,
        ]
        alpha = 0.2
        res = run_croston_recursion(series, alpha=alpha, method="sba")

        # Every positive event has y_t = 10.0 and interval q = 5
        self.assertAlmostEqual(res["z_t"], 10.0, places=6)
        self.assertAlmostEqual(res["p_t"], 5.0, places=6)
        self.assertAlmostEqual(res["croston_rate"], 2.0, places=6)
        # SBA formula: (1 - alpha / 2) * (z_t / p_t) = (1 - 0.1) * 2.0 = 1.8
        expected_sba = (1.0 - alpha / 2.0) * (10.0 / 5.0)
        self.assertAlmostEqual(res["sba_rate"], expected_sba, places=6)

        preds = forecast_croston_sba(series, horizon=5, alpha=alpha, round_digits=None)
        for p in preds:
            self.assertAlmostEqual(p, expected_sba, places=6)

        # Verify step-by-step updates on varying non-zero sizes and intervals
        custom_series = [6.0, 0.0, 0.0, 9.0, 0.0, 12.0]
        # Event 1 at t=0: z=6.0, p=1.0
        # Event 2 at t=3 (q=3): z = 0.2*9 + 0.8*6 = 6.6, p = 0.2*3 + 0.8*1 = 1.4
        # Event 3 at t=5 (q=2): z = 0.2*12 + 0.8*6.6 = 7.68, p = 0.2*2 + 0.8*1.4 = 1.52
        custom_res = fit_croston_sba(custom_series, alpha=0.2, method="sba")
        self.assertAlmostEqual(custom_res["z_t"], 7.68, places=6)
        self.assertAlmostEqual(custom_res["p_t"], 1.52, places=6)
        expected_rate = (1.0 - 0.1) * (7.68 / 1.52)
        self.assertAlmostEqual(custom_res["forecast_rate"], expected_rate, places=6)

    def test_linear_trend_closed_form_ols_matches_hand_computed_values(self) -> None:
        # Exact linear series y_t = 10 + 2.5 * t for t = 1..8
        series = [10.0 + 2.5 * t for t in range(1, 9)]
        fitted = fit_linear_trend(series)

        self.assertAlmostEqual(fitted["a"], 10.0, places=6)
        self.assertAlmostEqual(fitted["b"], 2.5, places=6)
        self.assertAlmostEqual(fitted["t_bar"], 4.5, places=6)
        self.assertAlmostEqual(fitted["y_bar"], 21.25, places=6)

        preds = forecast_linear_trend(series, horizon=4, round_digits=None)
        for h in range(1, 5):
            expected = 10.0 + 2.5 * (8 + h)
            self.assertAlmostEqual(preds[h - 1], expected, places=6)

        # Declining series clamps at >= 0.0 by default
        declining = [10.0, 7.0, 4.0, 1.0]
        clamped_preds = forecast_linear_trend(declining, horizon=3)
        self.assertEqual(clamped_preds, [0.0, 0.0, 0.0])


if __name__ == "__main__":
    unittest.main()
