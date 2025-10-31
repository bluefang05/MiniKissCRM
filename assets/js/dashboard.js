document.addEventListener('DOMContentLoaded', function () {
    const autoRefresh = true; // Set to false to disable
    const fmt = x => x.toLocaleString();
    const dur = s => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

    const rangeButtons = document.querySelectorAll('.time-filter button');
    let range = 30;

    // Initialize Chart.js instances
    const ctxDay = document.getElementById('cDay').getContext('2d');
    const ctxHour = document.getElementById('cHour').getContext('2d');
    const ctxDisp = document.getElementById('cDisp').getContext('2d');
    const ctxUser = document.getElementById('cUser').getContext('2d');
    const ctxFunnel = document.getElementById('cFunnel').getContext('2d');

    const chDay = new Chart(ctxDay, {
        type: 'line',
        data: {
            labels: [],
            datasets: [{
                label: 'Daily Calls',
                data: [],
                borderColor: '#2c5d4a',
                backgroundColor: 'rgba(44, 93, 74, 0.1)',
                fill: true,
                tension: 0.3,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: false },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    const chHour = new Chart(ctxHour, {
        type: 'bar',
        data: {
            labels: [...Array(24).keys()].map(h => String(h).padStart(2, '0')),
            datasets: [{
                label: 'Calls per Hour',
                data: [],
                backgroundColor: '#2c5d4a'
            }]
        },
        options: {
            indexAxis: 'x',
            responsive: true,
            plugins: { legend: false },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    const chDisp = new Chart(ctxDisp, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Dispositions',
                data: [],
                backgroundColor: '#2c5d4a'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: false },
            scales: {
                x: { beginAtZero: true }
            }
        }
    });

    const chUser = new Chart(ctxUser, {
        type: 'bar',
        data: {
            labels: [],
            datasets: [{
                label: 'Calls per User',
                data: [],
                backgroundColor: '#2c5d4a'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: false },
            scales: {
                x: { beginAtZero: true }
            }
        }
    });

    const chFun = new Chart(ctxFunnel, {
        type: 'bar',
        data: {
            labels: ['Interested', 'Qualified', 'Closed'],
            datasets: [{
                label: 'Lead Funnel',
                data: [0, 0, 0],
                backgroundColor: ['#ffc107', '#17a2b8', '#28a745']
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: { legend: false },
            scales: {
                x: { beginAtZero: true }
            }
        }
    });

    function updateUI(data) {
        // Update KPIs
        document.getElementById('total-leads').textContent = fmt(data.kpi.leads);
        document.getElementById('calls-today').textContent = fmt(data.kpi.today);
        document.getElementById('conversion-rate').textContent = data.kpi.rate + '%';
        document.getElementById('active-users').textContent = fmt(data.kpi.users);
        document.getElementById('avg-duration').textContent = dur(data.kpi.avg);
        document.getElementById('max-duration').textContent = dur(data.kpi.max);
        document.getElementById('min-duration').textContent = dur(data.kpi.min);

        // Update Charts
        chDay.data.labels = data.day.labels || [];
        chDay.data.datasets[0].data = data.day.values || [];
        chDay.update();

        chHour.data.labels = data.hour.labels || [];
        chHour.data.datasets[0].data = data.hour.values || [];
        chHour.update();

        chDisp.data.labels = data.dispositions.labels || [];
        chDisp.data.datasets[0].data = data.dispositions.values || [];
        chDisp.update();

        chUser.data.labels = data.users.labels || [];
        chUser.data.datasets[0].data = data.users.values || [];
        chUser.update();

        chFun.data.datasets[0].data = data.funel || [0, 0, 0];
        chFun.update();

        // Update Latest Calls Table
        const tbody = document.querySelector('.latest-calls tbody');
        tbody.innerHTML = '';
        if (data.latest.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center">No calls found.</td></tr>';
        } else {
            data.latest.forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${row.tm}</td>
                    <td>${row.agent}</td>
                    <td>${row.lead}</td>
                    <td>${row.disp}</td>
                    <td>${row.dur}</td>
                `;
                tbody.appendChild(tr);
            });
        }
    }

    async function fetchData() {
        const url = new URL(window.location.href);
        url.searchParams.set('api', '1');

        const from = document.getElementById('pick-from').value;
        const to = document.getElementById('pick-to').value;

        if (from && to) {
            url.searchParams.set('from', from);
            url.searchParams.set('to', to);
        } else {
            url.searchParams.set('range', range);
        }

        try {
            const res = await fetch(url);
            const json = await res.json();

            if (json.error) {
                throw new Error('API returned an error: ' + json.error);
            }

            updateUI(json.data);
        } catch (err) {
            console.error('Fetch error:', err);
            document.getElementById('total-leads').textContent = 'Error!';
            document.getElementById('online').textContent = '● Error!';
        }
    }

    // Attach event listeners
    rangeButtons.forEach(btn => {
        btn.addEventListener('click', e => {
            rangeButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            range = parseInt(btn.dataset.range, 10);
            fetchData();
        });
    });

    document.getElementById('applyCustom').addEventListener('click', () => {
        document.querySelectorAll('.time-filter button').forEach(b => b.classList.remove('active'));
        fetchData();
    });

    document.getElementById('btn-refresh').addEventListener('click', fetchData);
    document.getElementById('btn-toggle').addEventListener('click', () => {
        paused = !paused;
        document.getElementById('btn-toggle').textContent = paused ? '▶️ Resume' : '⏸ Pause';
    });

    // Initial load
    fetchData();

    // Auto-refresh
    if (autoRefresh) {
        setInterval(fetchData, 15000);
    }
});