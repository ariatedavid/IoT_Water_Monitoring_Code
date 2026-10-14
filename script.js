// ==========================
// Live Clock
// ==========================

function updateClock() {
    const clock = document.getElementById("clock");
    if (!clock) return;

    const now = new Date();
    const options = {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    };

    const date = now.toLocaleDateString('en-US', options);
    const time = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    clock.innerHTML = date + '<br>' + time;
}

setInterval(updateClock, 1000);
updateClock();


// ==========================
// Flow Rate Chart
// ==========================

let flowChart = null;

function initFlowChart() {
    const chartCanvas = document.getElementById('flowChart');
    if (!chartCanvas || !window.flowChartData) return;

    const ctx = chartCanvas.getContext('2d');
    const chartData = window.flowChartData;

    flowChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Flow Rate (L/min)',
                data: chartData.values,
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                backgroundColor: 'rgba(59, 130, 246, 0.12)',
                borderColor: '#3b82f6',
                pointBackgroundColor: '#3b82f6',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            animation: { duration: 400 },
            plugins: {
                legend: { display: true }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: '#f3f4f6' },
                    title: {
                        display: true,
                        text: 'Liters per Minute'
                    }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
}

initFlowChart();


// ==========================
// Live Dashboard Updates
// ==========================

function updateLeakCard(color, status) {
    const leakStatusValue = document.getElementById('leakStatusValue');
    if (!leakStatusValue) return;

    leakStatusValue.className = 'text-' + color;
    leakStatusValue.textContent = status;
}

function updateFlowRateDisplay(data) {
    const flowRateDisplay = document.getElementById('flowRateDisplay');
    if (!flowRateDisplay) return;

    if (data.has_flow) {
        flowRateDisplay.innerHTML =
            '<h2 id="currentFlowValue">' + data.current_flow.toFixed(2) + '</h2>' +
            '<p id="currentFlowUnit">L/min</p>';
    } else {
        flowRateDisplay.innerHTML =
            '<h2 class="stat-muted" id="currentFlowValue">No flow</h2>' +
            '<p id="currentFlowUnit">Waiting for water flow</p>';
    }
}

function getPersistedCalendarSelection() {
    try {
        return localStorage.getItem('waterCalendarSelectedDate');
    } catch (error) {
        return null;
    }
}

function setPersistedCalendarSelection(date) {
    try {
        if (!date) {
            localStorage.removeItem('waterCalendarSelectedDate');
            return;
        }
        localStorage.setItem('waterCalendarSelectedDate', date);
    } catch (error) {}
}

function restorePersistedCalendarSelection() {
    const selectedDate = getPersistedCalendarSelection();
    if (!selectedDate) return;

    const panel = document.getElementById('calendarActivities');
    if (!panel || !window.calendarActivities) return;

    const monthKey = selectedDate.substring(0, 7);
    if (monthKey !== window.calendarMonthKey) {
        setPersistedCalendarSelection(null);
        return;
    }

    const day = document.querySelector('[data-calendar-date="' + selectedDate + '"]');
    if (day) {
        renderCalendarActivities(selectedDate, window.calendarActivities[selectedDate] || []);
        day.classList.add('calendar-day-selected');
    }
}

function renderCalendarActivities(date, activities) {
    const panel = document.getElementById('calendarActivities');
    const title = document.getElementById('selectedCalendarDate');
    const list = document.getElementById('calendarActivityList');
    if (!panel || !title || !list) return;

    const usage = Number(window.calendarDays[date] || 0);
    const estimatedCost = Number(window.calendarCosts && window.calendarCosts[date] !== undefined ? window.calendarCosts[date] : (usage * Number(window.waterCostPerLiter || 0)));
    const selectedDate = new Date(date + 'T00:00:00');

    title.textContent = selectedDate.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    document.getElementById('selectedCalendarUsage').textContent = usage.toFixed(2) + ' L';
    document.getElementById('selectedCalendarCost').textContent = '₱' + estimatedCost.toFixed(2);
    document.getElementById('selectedCalendarReadings').textContent = String(activities.length);
    list.replaceChildren();

    if (!activities.length) {
        const empty = document.createElement('p');
        empty.className = 'calendar-activity-empty';
        empty.textContent = 'No water readings recorded on this date.';
        list.appendChild(empty);
    } else {
        activities.forEach(function (activity) {
            const item = document.createElement('div');
            item.className = 'calendar-activity-item';
            item.innerHTML = '<strong>' + activity.created_at.substring(11, 19) + '</strong><span>' + Number(activity.flow_rate).toFixed(2) + ' L/min</span>';
            list.appendChild(item);
        });
    }

    setPersistedCalendarSelection(date);
    panel.hidden = false;
    panel.setAttribute('aria-hidden', 'false');
}

function renderCalendarData(data) {
    const grid = document.querySelector('#usageCalendar .calendar-days');
    if (!grid) return;
    const parts = data.calendar_month_key.split('-').map(Number);
    const year = parts[0];
    const month = parts[1];
    const days = new Date(year, month, 0).getDate();
    const firstDay = new Date(year, month - 1, 1).getDay();
    const now = new Date();
    const today = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    document.getElementById('calendarMonthLabel').textContent = data.calendar_month;
    grid.replaceChildren();
    window.calendarActivities = data.calendar_activities || {};
    window.calendarDays = data.calendar_days || {};
    window.calendarCosts = data.calendar_costs || {};
    window.waterCostPerLiter = data.water_cost_per_liter || 0;
    window.calendarMonthKey = data.calendar_month_key;
    for (let i = 0; i < firstDay; i++) {
        const blank = document.createElement('span');
        blank.className = 'calendar-day calendar-day-empty';
        grid.appendChild(blank);
    }
    for (let dayNumber = 1; dayNumber <= days; dayNumber++) {
        const date = data.calendar_month_key + '-' + String(dayNumber).padStart(2, '0');
        const day = document.createElement('span');
        day.className = 'calendar-day' + (date === today ? ' calendar-day-today' : '');
        day.dataset.calendarDate = date;
        day.innerHTML = '<strong>' + dayNumber + '</strong><small>' + Number(data.calendar_days[date] || 0).toFixed(2) + ' L</small>';
        grid.appendChild(day);
    }
}

function loadCalendarMonth(year, month) {
    window.location.href = 'index.php?calendar_year=' + encodeURIComponent(year) + '&calendar_month=' + encodeURIComponent(month) + '#waterUsageCalendarPanel';
}

function initUsageCalendar() {
    const calendar = document.getElementById('usageCalendar');
    if (!calendar) return;
    calendar.addEventListener('click', function (event) {
        const day = event.target.closest('[data-calendar-date]');
        if (!day) return;
        renderCalendarActivities(day.dataset.calendarDate, window.calendarActivities[day.dataset.calendarDate] || []);
        calendar.querySelectorAll('.calendar-day-selected').forEach(function (item) { item.classList.remove('calendar-day-selected'); });
        day.classList.add('calendar-day-selected');
    });
    document.getElementById('calendarPreviousMonth').addEventListener('click', function () {
        setPersistedCalendarSelection(null);
        const current = window.calendarMonthKey.split('-').map(Number);
        const date = new Date(current[0], current[1] - 2, 1);
        loadCalendarMonth(date.getFullYear(), date.getMonth() + 1);
    });
    document.getElementById('calendarNextMonth').addEventListener('click', function () {
        setPersistedCalendarSelection(null);
        const current = window.calendarMonthKey.split('-').map(Number);
        const date = new Date(current[0], current[1], 1);
        loadCalendarMonth(date.getFullYear(), date.getMonth() + 1);
    });
    function closeCalendar() {
        const modal = document.getElementById('calendarActivities');
        if (modal) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); }
        setPersistedCalendarSelection(null);
    }
    document.getElementById('closeCalendarActivities').addEventListener('click', closeCalendar);
    document.querySelector('[data-calendar-close]').addEventListener('click', closeCalendar);
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeCalendar(); });
    restorePersistedCalendarSelection();
}

function updateHealthGauge(data) {
    const gauge = document.getElementById('healthGauge');
    const gaugeValue = document.getElementById('healthGaugeValue');
    const gaugeLabel = document.getElementById('healthGaugeLabel');
    if (!gauge || !gaugeValue || !gaugeLabel) return;

    let percent = 0;
    let label = 'Waiting for sensor';

    if (data.has_flow) {
        if (data.leak_status === 'LEAK DETECTED') {
            percent = 42;
            label = 'Needs attention';
        } else {
            percent = 92;
            label = 'On track';
        }
    }

    gauge.style.setProperty('--gauge', percent);
    gaugeValue.textContent = percent + '%';
    gaugeLabel.textContent = label;
}

function updateAutomationAlerts(data) {
    const container = document.getElementById('automationAlerts');
    if (!container) return;

    if (data.has_flow && data.leak_status === 'LEAK DETECTED') {
        const shutoffMsg = data.automatic_shutoff
            ? ' Solenoid valve has been automatically closed.'
            : ' Automatic shut off is disabled — alert only.';
        container.innerHTML =
            '<div class="alert-item">' +
            '<div class="alert-item-icon critical"><i class="bi bi-exclamation-lg"></i></div>' +
            '<div class="alert-item-content">' +
            '<strong>High Flow Spike Detected</strong>' +
            '<p>Flow rate exceeded ' + data.leak_threshold.toFixed(1) + ' L/min threshold.' + shutoffMsg + '</p>' +
            '<span class="alert-badge critical">Critical</span>' +
            '</div></div>';
    } else if (data.has_flow) {
        container.innerHTML =
            '<div class="alert-item">' +
            '<div class="alert-item-icon success"><i class="bi bi-check-lg"></i></div>' +
            '<div class="alert-item-content">' +
            '<strong>Normal Flow Detected</strong>' +
            '<p>Current reading is within the safe operating range.</p>' +
            '<span class="alert-badge success">Normal</span>' +
            '</div></div>';
    } else {
        container.innerHTML =
            '<div class="alert-item">' +
            '<div class="alert-item-icon neutral"><i class="bi bi-hourglass"></i></div>' +
            '<div class="alert-item-content">' +
            '<strong>No Flow Detected</strong>' +
            '<p>Waiting for the sensor to detect water flow.</p>' +
            '<span class="alert-badge neutral">Standby</span>' +
            '</div></div>';
    }
}

function updateLogsTable(logs) {
    const tbody = document.getElementById('logsTableBody');
    if (!tbody) return;

    if (!logs.length) {
        tbody.innerHTML =
            '<tr id="logsEmptyRow"><td colspan="4" class="text-center text-muted py-4">No flow readings recorded yet.</td></tr>';
        return;
    }

    tbody.innerHTML = logs.map(function (row) {
        return '<tr>' +
            '<td>' + row.id + '</td>' +
            '<td>' + row.flow_rate.toFixed(2) + ' L/min</td>' +
            '<td><span class="badge ' + row.status_class + '">' + row.status + '</span></td>' +
            '<td>' + formatDashboardTimestamp(row.created_at) + '</td>' +
            '</tr>';
    }).join('');
}

function formatDashboardTimestamp(value) {
    if (!value || value === 'No Data') return value || 'No Data';

    const match = String(value).match(/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
    if (!match) return value;

    const hour = Number(match[2]);
    const period = hour >= 12 ? 'PM' : 'AM';
    const displayHour = (hour % 12) || 12;
    return match[1] + ' ' + String(displayHour).padStart(2, '0') + ':' + match[3] + ':' + match[4] + ' ' + period;
}

function ensureChart(data) {
    const chartContainer = document.getElementById('chartContainer');
    if (!chartContainer) return;

    if (!data.has_flow || !data.chart_values.length) {
        if (flowChart) {
            flowChart.destroy();
            flowChart = null;
        }
        chartContainer.innerHTML =
            '<div class="empty-state" id="chartPlaceholder">' +
            'No flow data yet. Readings will appear when water flow is detected.' +
            '</div>';
        return;
    }

    if (!flowChart) {
        chartContainer.innerHTML = '<canvas id="flowChart" height="90"></canvas>';
        window.flowChartData = {
            labels: data.chart_labels,
            values: data.chart_values
        };
        initFlowChart();
        return;
    }

    flowChart.data.labels = data.chart_labels;
    flowChart.data.datasets[0].data = data.chart_values;
    flowChart.update();
}

function updateValveDisplay(data) {
    const valveStatusDisplay = document.getElementById('valveStatusDisplay');
    if (valveStatusDisplay) {
        valveStatusDisplay.className = 'text-' + data.valve_status_class;
        valveStatusDisplay.textContent = data.valve_status;
    }

    const valveInfoStatus = document.getElementById('valveInfoStatus');
    if (valveInfoStatus) valveInfoStatus.textContent = data.valve_status;

    const autoShutoffInfo = document.getElementById('autoShutoffInfo');
    if (autoShutoffInfo) autoShutoffInfo.textContent = data.automatic_shutoff_label;
}

function applyDashboardData(data) {
    if (data.dashboard_version) window.dashboardVersion = data.dashboard_version;
    if (data.usage_summary_version && data.total_usage !== undefined) {
        window.usageSummaryVersion = data.usage_summary_version;
    }

    updateFlowRateDisplay(data);

    const totalUsage = document.getElementById('totalUsageValue');
    if (totalUsage && data.total_usage !== undefined) totalUsage.textContent = data.total_usage.toFixed(2);

    const estimatedCost = document.getElementById('estimatedCostValue');
    if (estimatedCost && data.estimated_cost !== undefined) estimatedCost.textContent = '₱' + data.estimated_cost.toFixed(2);

    if (data.calendar_month_key === window.calendarMonthKey) {
        window.calendarActivities = data.calendar_activities || {};
        window.calendarDays = data.calendar_days || {};
        Object.keys(window.calendarDays).forEach(function (date) {
            const day = document.querySelector('[data-calendar-date="' + date + '"] small');
            if (day) day.textContent = Number(window.calendarDays[date]).toFixed(2) + ' L';
        });
    }

    updateLeakCard(data.status_color, data.leak_status);
    updateValveDisplay(data);

    const deviceStatus = document.getElementById('deviceStatusValue');
    if (deviceStatus) {
        deviceStatus.className = 'text-' + data.device_status_class;
        deviceStatus.textContent = data.device_status;
    }

    const esp32Info = document.getElementById('esp32Info');
    if (esp32Info) {
        esp32Info.textContent = data.sensor_online ? 'Online' : 'Offline';
    }

    const lastUpdate = document.getElementById('lastUpdateValue');
    if (lastUpdate) lastUpdate.textContent = formatDashboardTimestamp(data.last_update);

    const monitoringStatus = document.getElementById('monitoringStatus');
    if (monitoringStatus) monitoringStatus.textContent = data.monitoring_status;

    updateHealthGauge(data);
    updateAutomationAlerts(data);
    updateLogsTable(data.logs);
    ensureChart(data);
}

function fetchLiveDashboardData() {
    if (liveDataRequestPending) return;
    liveDataRequestPending = true;

    fetch('api/live_data.php?live=1')
        .then(function (response) { return response.json(); })
        .then(function (data) {
            applyDashboardData(data);
            window.lastReadingId = data.last_reading_id;
        })
        .catch(function () {})
        .finally(function () { liveDataRequestPending = false; });
}

let dashboardVersionCheckPending = false;
let liveDataRequestPending = false;
let usageSummaryRequestPending = false;
let usageSummaryDirty = false;

function checkDashboardVersion() {
    if (dashboardVersionCheckPending) return;
    dashboardVersionCheckPending = true;

    fetch('api/live_data.php?check=1')
        .then(function (response) { return response.json(); })
        .then(function (versions) {
            if (versions.dashboard_version !== window.dashboardVersion) {
                fetchLiveDashboardData();
            }
            if (versions.usage_summary_version !== window.usageSummaryVersion) {
                usageSummaryDirty = true;
            }
        })
        .catch(function () {})
        .finally(function () { dashboardVersionCheckPending = false; });
}

function fetchUsageSummary() {
    if (!usageSummaryDirty || usageSummaryRequestPending) return;
    usageSummaryRequestPending = true;
    usageSummaryDirty = false;

    const calendarMonth = String(window.calendarMonthKey || '').split('-');
    const query = calendarMonth.length === 2
        ? '?calendar_year=' + encodeURIComponent(calendarMonth[0]) + '&calendar_month=' + encodeURIComponent(calendarMonth[1])
        : '';

    fetch('api/live_data.php' + query)
        .then(function (response) { return response.json(); })
        .then(applyDashboardData)
        .catch(function () { usageSummaryDirty = true; })
        .finally(function () { usageSummaryRequestPending = false; });
}

renderCalendarData(window.calendarData);
initUsageCalendar();

const dashboard = document.getElementById('dashboard');
if (dashboard) {
    checkDashboardVersion();
    setInterval(checkDashboardVersion, 5000);
    setInterval(fetchUsageSummary, 60000);
}
