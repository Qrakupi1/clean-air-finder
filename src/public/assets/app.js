const API = {
    cleanest: '/api/v1/air-quality/cleanest',
    daily: '/api/v1/air-quality/daily'
};

const elements = {
    from: document.querySelector('#date-from'),
    to: document.querySelector('#date-to'),
    apply: document.querySelector('#apply-dates'),
    ranking: document.querySelector('#ranking'),
    loading: document.querySelector('#loading'),
    error: document.querySelector('#error'),
    dateError: document.querySelector('#date-error'),
    period: document.querySelector('#period-label'),
    citySelect: document.querySelector('#city-select'),
    chart: document.querySelector('#chart'),
    chartLoading: document.querySelector('#chart-loading'),
    chartEmpty: document.querySelector('#chart-empty'),
    chartSummary: document.querySelector('#chart-summary')
};

let currentCities = [];
let requestVersion = 0;

function localDateString(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function setDefaultDates(days) {
    const end = new Date();
    end.setDate(end.getDate() - 1);

    const start = new Date(end);
    start.setDate(start.getDate() - days + 1);

    elements.from.value = localDateString(start);
    elements.to.value = localDateString(end);
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    })[char]);
}

async function fetchJson(url) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' }
    });

    const body = await response.json();

    if (!response.ok) {
        throw new Error(body.error || `Request failed (${response.status})`);
    }

    return body;
}

function renderRanking(cities) {
    elements.ranking.innerHTML = cities.map(city => {
        const ranked = city.status === 'ranked';
        const value = city.average_pm25 === null
            ? '—'
            : Number(city.average_pm25).toFixed(3);

        const coverage = Number(city.period_coverage_percent) || 0;

        return `
            <article class="city-card ${ranked ? 'ranked' : 'insufficient'}">
                <div class="card-top">
                    <span class="city-name">${escapeHtml(city.city)}</span>
                    ${
                        ranked
                            ? `<span class="rank-label">#${city.rank} RANKED</span>`
                            : `<span class="status-label">INSUFFICIENT DATA</span>`
                    }
                </div>

                <div class="city-value">${value}</div>
                <div class="city-unit">µg/m³ average PM2.5</div>

                <div class="coverage-track">
                    <div class="coverage-fill" style="width:${Math.min(coverage, 100)}%"></div>
                </div>

                <div class="card-meta">
                    <span>${city.eligible_days}/${city.calendar_days} eligible days</span>
                    <span>${coverage.toFixed(2)}%</span>
                </div>
            </article>
        `;
    }).join('');
}

function populateCitySelect(cities) {
    const previous = elements.citySelect.value;

    elements.citySelect.innerHTML = cities.map(city => `
        <option value="${city.city_id}">
            ${escapeHtml(city.city)}
        </option>
    `).join('');

    const preferred = cities.find(city => city.rank === 1)
        || cities.find(city => city.eligible_days > 0)
        || cities[0];

    if (previous && cities.some(city => String(city.city_id) === previous)) {
        elements.citySelect.value = previous;
    } else if (preferred) {
        elements.citySelect.value = String(preferred.city_id);
    }

    elements.citySelect.disabled = cities.length === 0;
}

async function loadRanking() {
    const from = elements.from.value;
    const to = elements.to.value;

    elements.dateError.textContent = '';

    if (!from || !to || from > to) {
        elements.dateError.textContent = 'Please choose a valid date range.';
        return;
    }

    const start = new Date(`${from}T00:00:00`);
    const end = new Date(`${to}T00:00:00`);
    const days = Math.round((end - start) / 86400000) + 1;

    if (days > 366) {
        elements.dateError.textContent = 'Please choose a period of 366 days or less.';
        return;
    }

    const version = ++requestVersion;

    elements.loading.classList.remove('hidden');
    elements.error.classList.add('hidden');
    elements.ranking.innerHTML = '';
    elements.citySelect.disabled = true;
    elements.period.textContent = `${from} – ${to}`;

    try {
        const query = new URLSearchParams({ from, to });
        const result = await fetchJson(`${API.cleanest}?${query}`);

        if (version !== requestVersion) return;

        currentCities = result.data.cities;
        renderRanking(currentCities);
        populateCitySelect(currentCities);

        elements.loading.classList.add('hidden');

        if (elements.citySelect.value) {
            await loadDailyHistory();
        } else {
            showEmptyChart('No cities are available for this period.');
        }

    } catch (error) {
        if (version !== requestVersion) return;

        elements.loading.classList.add('hidden');
        elements.error.textContent = error.message;
        elements.error.classList.remove('hidden');
    }
}

function showEmptyChart(message) {
    elements.chart.classList.add('hidden');
    elements.chartLoading.classList.add('hidden');
    elements.chartEmpty.textContent = message;
    elements.chartEmpty.classList.remove('hidden');
    elements.chartSummary.textContent = '';
}

function renderChart(points) {
    if (!points.length) {
        showEmptyChart('No daily city measurements are available for this period.');
        return;
    }

    const width = 850;
    const height = 300;
    const left = 58;
    const right = 20;
    const top = 20;
    const bottom = 42;
    const chartWidth = width - left - right;
    const chartHeight = height - top - bottom;

    const values = points.map(point => Number(point.average_pm25));
    const maxValue = Math.max(5, Math.ceil(Math.max(...values) / 5) * 5);
    const minValue = 0;

    const x = index => left + (
        points.length === 1
            ? chartWidth / 2
            : index / (points.length - 1) * chartWidth
    );

    const y = value => top + (
        (maxValue - value) / (maxValue - minValue) * chartHeight
    );

    const gridLines = [];
    const steps = 4;

    for (let i = 0; i <= steps; i++) {
        const value = maxValue * i / steps;
        const position = y(value);

        gridLines.push(`
            <line x1="${left}" y1="${position}" x2="${width - right}"
                  y2="${position}" stroke="#e8eee9" stroke-width="1"/>
            <text x="${left - 10}" y="${position + 4}"
                  text-anchor="end" fill="#83918a" font-size="11">
                ${value.toFixed(0)}
            </text>
        `);
    }

    const coordinates = points.map((point, index) =>
        `${x(index)},${y(Number(point.average_pm25))}`
    ).join(' ');

    const circles = points.map((point, index) => `
        <circle cx="${x(index)}"
                cy="${y(Number(point.average_pm25))}"
                r="3.5"
                fill="#267b62">
            <title>${point.date}: ${Number(point.average_pm25).toFixed(3)} µg/m³</title>
        </circle>
    `).join('');

    const labelIndexes = [...new Set([
        0,
        Math.floor((points.length - 1) / 2),
        points.length - 1
    ])];

    const labels = labelIndexes.map(index => `
        <text x="${x(index)}" y="${height - 12}"
              text-anchor="middle" fill="#83918a" font-size="11">
            ${points[index].date.slice(5)}
        </text>
    `).join('');

    elements.chart.innerHTML = `
        <svg viewBox="0 0 ${width} ${height}"
             role="img"
             aria-label="Daily PM2.5 average chart">
            ${gridLines.join('')}
            <polyline points="${coordinates}"
                      fill="none"
                      stroke="#267b62"
                      stroke-width="2.5"
                      stroke-linecap="round"
                      stroke-linejoin="round"/>
            ${circles}
            ${labels}
        </svg>
    `;

    elements.chart.classList.remove('hidden');
    elements.chartEmpty.classList.add('hidden');

    const average = values.reduce((sum, value) => sum + value, 0) / values.length;

    elements.chartSummary.textContent =
        `${points.length} eligible days · Average ${average.toFixed(3)} µg/m³`;
}

async function loadDailyHistory() {
    const cityId = elements.citySelect.value;

    if (!cityId) {
        showEmptyChart('Select a city to view its daily measurements.');
        return;
    }

    const query = new URLSearchParams({
        city_id: cityId,
        from: elements.from.value,
        to: elements.to.value
    });

    elements.chart.classList.add('hidden');
    elements.chartEmpty.classList.add('hidden');
    elements.chartLoading.classList.remove('hidden');

    try {
        const result = await fetchJson(`${API.daily}?${query}`);

        elements.chartLoading.classList.add('hidden');
        renderChart(result.data.points);

    } catch (error) {
        showEmptyChart(error.message);
    }
}

elements.apply.addEventListener('click', loadRanking);

elements.citySelect.addEventListener('change', loadDailyHistory);

document.querySelectorAll('.range-button').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('.range-button').forEach(item =>
            item.classList.remove('active')
        );

        button.classList.add('active');

        setDefaultDates(Number(button.dataset.days));
        loadRanking();
    });
});

elements.from.addEventListener('change', () => {
    document.querySelectorAll('.range-button').forEach(button =>
        button.classList.remove('active')
    );
});

elements.to.addEventListener('change', () => {
    document.querySelectorAll('.range-button').forEach(button =>
        button.classList.remove('active')
    );
});

// Initial view: last 46 calendar days, ending yesterday.
setDefaultDates(46);
loadRanking();
