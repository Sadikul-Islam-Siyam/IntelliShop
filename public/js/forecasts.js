/**
 * Demand Forecasting Interactive Frontend Module.
 * Displays required product demand quantities (units) for upcoming days.
 */

document.addEventListener('DOMContentLoaded', () => {
    const branchSelect = document.getElementById('branch-select');
    const productSelect = document.getElementById('product-select');
    const horizonInput = document.getElementById('horizon-input');
    const btnRunForecast = document.getElementById('btn-run-forecast');
    const btnRunAll = document.getElementById('btn-run-all');
    const toastBanner = document.getElementById('forecast-toast');

    const kpiTotalUnits = document.getElementById('kpi-total-units');
    const kpiHorizonLabel = document.getElementById('kpi-horizon-label');
    const kpiAvgDaily = document.getElementById('kpi-avg-daily');
    const kpiNext7Days = document.getElementById('kpi-next-7days');
    const kpiPeakUnits = document.getElementById('kpi-peak-units');
    const kpiPeakDate = document.getElementById('kpi-peak-date');
    const selectedProductPill = document.getElementById('selected-product-pill');

    const forecastHorizonTbody = document.getElementById('forecast-horizon-tbody');
    const batchSummarySection = document.getElementById('batch-summary-section');
    const batchSummaryTbody = document.getElementById('batch-summary-tbody');

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const api = axios.create({
        baseURL: '/api/v1',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        },
        withCredentials: true,
    });

    let forecastChart = null;

    function showToast(message, type = 'success') {
        if (!toastBanner) return;
        toastBanner.textContent = message;
        toastBanner.className = `toast-banner show ${type === 'error' ? 'toast-error' : 'toast-success'}`;
        setTimeout(() => {
            toastBanner.classList.remove('show');
        }, 4000);
    }

    function renderChart(history, forecasts) {
        const ctx = document.getElementById('demand-forecast-chart');
        if (!ctx || typeof Chart === 'undefined') return;

        const histLabels = history.map((h) => h.date);
        const fcastLabels = forecasts.map((f) => f.date);
        const allLabels = [...histLabels, ...fcastLabels];

        const histData = history.map((h) => Math.round(Number(h.quantity)));
        const paddedHist = [...histData, ...new Array(fcastLabels.length).fill(null)];

        const lastHistVal = histData.length > 0 ? histData[histData.length - 1] : null;
        const predPrefix = histData.length > 0
            ? [...new Array(histData.length - 1).fill(null), lastHistVal]
            : [];

        const fcastData = [...predPrefix, ...forecasts.map((f) => Math.ceil(Number(f.predicted_demand)))];

        if (forecastChart) {
            forecastChart.destroy();
        }

        forecastChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: allLabels,
                datasets: [
                    {
                        label: 'Past Daily Sales (Units)',
                        data: paddedHist,
                        borderColor: '#1e40af',
                        backgroundColor: 'rgba(30, 64, 175, 0.08)',
                        borderWidth: 2.2,
                        pointRadius: 2.5,
                        tension: 0.2,
                    },
                    {
                        label: 'Upcoming Required Demand (Units)',
                        data: fcastData,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.14)',
                        borderWidth: 2.8,
                        pointRadius: 4,
                        fill: true,
                        tension: 0.2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Quantity (Units)',
                        },
                    },
                    x: {
                        ticks: {
                            maxTicksLimit: 12,
                        },
                    },
                },
            },
        });
    }

    function renderPayload(payload) {
        if (!payload) return;

        const forecasts = payload.forecasts || [];
        const history = payload.history || [];

        if (selectedProductPill && payload.product) {
            selectedProductPill.textContent = `${payload.product.name} (${payload.product.sku})`;
        }

        const totalRequired = forecasts.reduce((acc, row) => acc + Math.ceil(Number(row.predicted_demand || 0)), 0);
        const avgDaily = forecasts.length > 0 ? totalRequired / forecasts.length : 0;
        const next7Required = forecasts
            .slice(0, 7)
            .reduce((acc, row) => acc + Math.ceil(Number(row.predicted_demand || 0)), 0);

        let peakUnits = 0;
        let peakDateStr = '—';
        forecasts.forEach((row) => {
            const units = Math.ceil(Number(row.predicted_demand || 0));
            if (units > peakUnits) {
                peakUnits = units;
                peakDateStr = `${row.date} (${row.weekday || ''})`;
            }
        });

        if (kpiTotalUnits) {
            kpiTotalUnits.textContent = `${totalRequired} units`;
        }
        if (kpiHorizonLabel) {
            kpiHorizonLabel.textContent = `For upcoming ${forecasts.length} days`;
        }
        if (kpiAvgDaily) {
            kpiAvgDaily.textContent = `${Math.ceil(avgDaily)} units / day`;
        }
        if (kpiNext7Days) {
            kpiNext7Days.textContent = `${next7Required} units`;
        }
        if (kpiPeakUnits) {
            kpiPeakUnits.textContent = `${peakUnits} units`;
        }
        if (kpiPeakDate) {
            kpiPeakDate.textContent = peakDateStr;
        }

        if (forecastHorizonTbody) {
            if (forecasts.length === 0) {
                forecastHorizonTbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:#64748b;">No upcoming demand data available.</td></tr>`;
            } else {
                forecastHorizonTbody.innerHTML = forecasts
                    .map((row) => {
                        const requiredQty = Math.ceil(Number(row.predicted_demand || 0));
                        const minQty = Math.max(0, Math.floor(Number(row.lower_bound || 0)));
                        const maxQty = Math.ceil(Number(row.upper_bound || requiredQty));
                        return `<tr>
                            <td><strong>${row.date}</strong></td>
                            <td>${row.weekday || ''} (Day +${row.step})</td>
                            <td style="font-weight:700;color:#059669;font-size:0.95rem;">${requiredQty} units</td>
                            <td>${minQty} – ${maxQty} units</td>
                        </tr>`;
                    })
                    .join('');
            }
        }

        renderChart(history, forecasts);
    }

    async function loadCurrentForecast() {
        if (!branchSelect || !productSelect) return;
        try {
            const response = await api.get('/forecasts', {
                params: {
                    branch_id: branchSelect.value,
                    product_id: productSelect.value,
                },
            });
            renderPayload(response.data.data);
        } catch (err) {
            showToast(err.response?.data?.error?.message || 'Failed to load upcoming demand.', 'error');
        }
    }

    if (branchSelect) {
        branchSelect.addEventListener('change', loadCurrentForecast);
    }
    if (productSelect) {
        productSelect.addEventListener('change', loadCurrentForecast);
    }

    if (btnRunForecast) {
        btnRunForecast.addEventListener('click', async () => {
            btnRunForecast.disabled = true;
            const origText = btnRunForecast.textContent;
            btnRunForecast.textContent = 'Updating...';

            try {
                const response = await api.post('/forecasts/run', {
                    branch_id: Number(branchSelect.value),
                    product_id: Number(productSelect.value),
                    horizon: Number(horizonInput.value || 14),
                });
                renderPayload(response.data.data);
                showToast('Upcoming demand quantities updated.');
            } catch (err) {
                showToast(err.response?.data?.error?.message || 'Error updating demand forecast.', 'error');
            } finally {
                btnRunForecast.disabled = false;
                btnRunForecast.textContent = origText;
            }
        });
    }

    if (btnRunAll) {
        btnRunAll.addEventListener('click', async () => {
            btnRunAll.disabled = true;
            const origText = btnRunAll.textContent;
            btnRunAll.textContent = 'Calculating All...';
            const horizonDays = Number(horizonInput.value || 14);

            try {
                const response = await api.post('/forecasts/run-all', {
                    branch_id: Number(branchSelect.value),
                    horizon: horizonDays,
                });
                const rows = response.data.data || [];
                if (batchSummarySection && batchSummaryTbody) {
                    batchSummarySection.style.display = 'block';
                    batchSummaryTbody.innerHTML = rows
                        .map((r) => {
                            const totalUnits = Math.ceil(Number(r.total_horizon_demand || 0));
                            const dailyAvg = Math.ceil(totalUnits / Math.max(1, horizonDays));
                            return `<tr>
                                <td><strong>${r.product_name}</strong></td>
                                <td><span class="pill pill-baseline">${r.sku}</span></td>
                                <td style="font-weight:700;color:#059669;">${totalUnits} units</td>
                                <td>${dailyAvg} units / day</td>
                            </tr>`;
                        })
                        .join('');
                }
                await loadCurrentForecast();
                showToast(`Updated upcoming demand quantities for ${rows.length} products.`);
            } catch (err) {
                showToast(err.response?.data?.error?.message || 'Error calculating product demand.', 'error');
            } finally {
                btnRunAll.disabled = false;
                btnRunAll.textContent = origText;
            }
        });
    }

    loadCurrentForecast();
});
