(() => {
  const safeStorage = {
    get(key, fallback) {
      try {
        return localStorage.getItem(key) ?? fallback;
      } catch {
        return fallback;
      }
    },
    set(key, value) {
      try {
        localStorage.setItem(key, value);
      } catch {
        // Preferences remain usable for the current page when storage is unavailable.
      }
    }
  };

  const sidebar = document.querySelector('#sidebar');
  const toggle = document.querySelector('[data-sidebar-toggle]');
  const scrim = document.querySelector('.sidebar-scrim');
  const desktop = window.matchMedia('(min-width: 961px)');
  const collapseSidebar = collapsed => {
    document.body.classList.toggle('sidebar-collapsed', collapsed);
    safeStorage.set('tms-supervisor-sidebar-collapsed', collapsed ? '1' : '0');
    toggle?.setAttribute('aria-expanded', String(!collapsed));
  };
  collapseSidebar(safeStorage.get('tms-supervisor-sidebar-collapsed', '0') === '1');
  if (!desktop.matches) toggle?.setAttribute('aria-expanded', 'false');

  const closeMobileSidebar = () => {
    if (!sidebar || !toggle || !scrim) return;
    sidebar.classList.remove('is-open');
    scrim.classList.remove('is-visible');
    toggle.setAttribute('aria-expanded', 'false');
  };

  if (sidebar && toggle && scrim) {
    toggle.addEventListener('click', () => {
      if (desktop.matches) {
        collapseSidebar(!document.body.classList.contains('sidebar-collapsed'));
        return;
      }
      const isOpen = sidebar.classList.toggle('is-open');
      scrim.classList.toggle('is-visible', isOpen);
      toggle.setAttribute('aria-expanded', String(isOpen));
    });
    scrim.addEventListener('click', closeMobileSidebar);
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') closeMobileSidebar();
    });
  }
  document.querySelector('[data-sidebar-preference]')?.addEventListener('click', () => {
    collapseSidebar(!document.body.classList.contains('sidebar-collapsed'));
  });

  const createAccountDrawer = document.querySelector('#account-create-panel');
  document.querySelectorAll('[data-account-create-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      if (!(createAccountDrawer instanceof HTMLElement)) return;
      createAccountDrawer.hidden = !createAccountDrawer.hidden;
      document.querySelectorAll('[data-account-create-toggle]').forEach(toggleButton => {
        toggleButton.setAttribute('aria-expanded', String(!createAccountDrawer.hidden));
      });
      if (!createAccountDrawer.hidden) {
        createAccountDrawer.querySelector('input[name="username"]')?.focus();
      }
    });
  });
  document.querySelectorAll('[data-account-edit-toggle]').forEach(button => {
    button.addEventListener('click', () => {
      const card = button.closest('.account-card');
      const drawer = card?.querySelector('.account-edit-drawer');
      if (!(drawer instanceof HTMLElement)) return;
      document.querySelectorAll('.account-edit-drawer:not([hidden])').forEach(openDrawer => {
        if (openDrawer === drawer) return;
        openDrawer.hidden = true;
        const openButton = openDrawer.closest('.account-card')?.querySelector('[data-account-edit-toggle]');
        openButton?.setAttribute('aria-expanded', 'false');
        if (openButton) openButton.textContent = 'Manage';
      });
      drawer.hidden = !drawer.hidden;
      button.setAttribute('aria-expanded', String(!drawer.hidden));
      button.textContent = drawer.hidden ? 'Manage' : 'Close';
      if (!drawer.hidden) {
        drawer.querySelector('.account-edit-form input[name="full_name"]')?.focus();
      }
    });
  });

  const calendarSetting = document.querySelector('[data-calendar-setting]');
  const calendarMode = safeStorage.get('tms-supervisor-calendar', 'gregorian') === 'ethiopian'
    ? 'ethiopian'
    : 'gregorian';
  if (calendarSetting instanceof HTMLSelectElement) calendarSetting.value = calendarMode;

  const formatters = {
    gregorian: new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric' }),
    ethiopian: new Intl.DateTimeFormat('am-ET-u-ca-ethiopic', {
      calendar: 'ethiopic',
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    })
  };
  const formatDates = mode => {
    const formatter = formatters[mode] || formatters.gregorian;
    document.querySelectorAll('[data-calendar-date]').forEach(element => {
      const raw = element.getAttribute('datetime');
      if (!raw) return;
      const date = new Date(`${raw}T12:00:00`);
      if (!Number.isNaN(date.getTime())) element.textContent = formatter.format(date);
    });
    document.querySelectorAll('[data-calendar-date-top]').forEach(element => {
      element.textContent = formatter.format(new Date());
    });
  };
  formatDates(calendarMode);
  calendarSetting?.addEventListener('change', () => {
    const mode = calendarSetting.value === 'ethiopian' ? 'ethiopian' : 'gregorian';
    safeStorage.set('tms-supervisor-calendar', mode);
    formatDates(mode);
  });

  document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', event => {
      const submitter = event.submitter;
      const message = submitter?.dataset.confirm || form.dataset.confirm;
      if (message && !window.confirm(message)) event.preventDefault();
    });
  });
  document.querySelector('[data-print]')?.addEventListener('click', () => window.print());

  const audio = document.querySelector('#mini-audio');
  const dock = document.querySelector('#audio-dock');
  const dockTitle = document.querySelector('#audio-dock-title');
  const dockDuration = document.querySelector('#audio-dock-duration');
  const durationText = duration => {
    if (!Number.isFinite(duration) || duration < 0) return 'Duration unavailable';
    const seconds = Math.floor(duration);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainder = seconds % 60;
    return hours
      ? `${hours}:${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`
      : `${minutes}:${String(remainder).padStart(2, '0')}`;
  };
  const normalizeAudioSource = source => {
    try {
      return new URL(source, document.baseURI).href;
    } catch {
      return source;
    }
  };
  const durationCacheKey = source => `tms-audio-duration:${normalizeAudioSource(source)}`;
  document.querySelectorAll('[data-audio-duration]').forEach(element => {
    const source = element.dataset.audioSrc;
    if (!source) return;
    const cached = safeStorage.get(durationCacheKey(source), '');
    if (cached && Number.isFinite(Number(cached))) {
      element.textContent = durationText(Number(cached));
    }
  });

  const loadAudioDuration = element => {
    const source = element.dataset.audioSrc;
    if (!source) return;
    const cachedText = safeStorage.get(durationCacheKey(source), '');
    const cached = Number(cachedText);
    if (cachedText !== '' && Number.isFinite(cached) && cached >= 0) {
      element.textContent = durationText(cached);
      return;
    }
    const metadataProbe = new Audio();
    metadataProbe.preload = 'metadata';
    metadataProbe.addEventListener('loadedmetadata', () => {
      element.textContent = durationText(metadataProbe.duration);
      safeStorage.set(durationCacheKey(source), String(metadataProbe.duration));
      metadataProbe.removeAttribute('src');
      metadataProbe.load();
    }, { once: true });
    metadataProbe.addEventListener('error', () => {
      element.textContent = 'Duration unavailable';
    }, { once: true });
    metadataProbe.src = source;
  };
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        loadAudioDuration(entry.target);
      });
    }, { rootMargin: '180px 0px', threshold: 0.01 });
    document.querySelectorAll('[data-audio-duration]').forEach(element => observer.observe(element));
  } else {
    document.querySelectorAll('[data-audio-duration]').forEach(loadAudioDuration);
  }

  const weeklyReport = document.querySelector('[data-weekly-report]');
  if (weeklyReport) {
    const weeklyRecords = [...weeklyReport.querySelectorAll('[data-weekly-record]')];
    const setWeeklyDuration = (row, seconds) => {
      row.dataset.weeklyDurationSeconds = String(seconds);
      const cell = row.querySelector('[data-weekly-record-duration]');
      if (cell) cell.textContent = durationText(seconds);
    };
    const markWeeklyDurationUnavailable = row => {
      row.dataset.weeklyDurationUnavailable = '1';
      const cell = row.querySelector('[data-weekly-record-duration]');
      if (cell) cell.textContent = 'Unavailable';
    };
    const loadWeeklyDuration = row => new Promise(resolve => {
      const source = row.dataset.audioSrc;
      if (!source) {
        markWeeklyDurationUnavailable(row);
        resolve();
        return;
      }
      const cacheKey = durationCacheKey(source);
      const cachedText = safeStorage.get(cacheKey, '');
      const cached = Number(cachedText);
      if (cachedText !== '' && Number.isFinite(cached) && cached >= 0) {
        setWeeklyDuration(row, cached);
        resolve();
        return;
      }
      const probe = new Audio();
      probe.preload = 'metadata';
      let settled = false;
      const finish = seconds => {
        if (settled) return;
        settled = true;
        window.clearTimeout(timeout);
        if (Number.isFinite(seconds) && seconds >= 0) {
          safeStorage.set(cacheKey, String(seconds));
          setWeeklyDuration(row, seconds);
        } else {
          markWeeklyDurationUnavailable(row);
        }
        probe.removeAttribute('src');
        probe.load();
        resolve();
      };
      const timeout = window.setTimeout(() => finish(NaN), 20000);
      probe.addEventListener('loadedmetadata', () => finish(probe.duration), { once: true });
      probe.addEventListener('error', () => finish(NaN), { once: true });
      probe.src = source;
    });
    const updateWeeklySummaries = () => {
      const ranked = [...weeklyReport.querySelectorAll('[data-weekly-person]')].map(person => {
        const rows = [...person.querySelectorAll('[data-weekly-record]')];
        const days = new Map([...person.querySelectorAll('[data-weekly-day]')].map(day => [
          day.dataset.date,
          { seconds: 0, count: 0 }
        ]));
        let totalSeconds = 0;
        let knownCount = 0;
        rows.forEach(row => {
          const seconds = Number(row.dataset.weeklyDurationSeconds);
          if (!Number.isFinite(seconds) || seconds < 0) return;
          totalSeconds += seconds;
          knownCount += 1;
          const day = days.get(row.dataset.finishedDate);
          if (day) {
            day.seconds += seconds;
            day.count += 1;
          }
        });
        const unavailableCount = rows.length - knownCount;
        const total = person.querySelector('[data-weekly-total]');
        if (total) {
          total.textContent = knownCount === 0 && unavailableCount > 0
            ? `Unavailable (${unavailableCount})`
            : `${durationText(totalSeconds)}${unavailableCount ? ` · ${unavailableCount} unavailable` : ''}`;
        }
        const expected = Number(person.dataset.expectedSeconds);
        const remaining = person.querySelector('[data-weekly-remaining]');
        if (remaining) {
          remaining.textContent = unavailableCount > 0
            ? 'Incomplete audio data'
            : durationText(Math.max(0, expected - totalSeconds));
        }
        person.querySelectorAll('[data-weekly-day]').forEach(dayRow => {
          const day = days.get(dayRow.dataset.date);
          const cell = dayRow.querySelector('[data-weekly-day-total]');
          if (!cell || !day) return;
          const missing = rows.filter(row =>
            row.dataset.finishedDate === dayRow.dataset.date && row.dataset.weeklyDurationUnavailable === '1'
          ).length;
          cell.textContent = day.count === 0 && missing === 0
            ? '0:00'
            : `${durationText(day.seconds)}${missing ? ` · ${missing} unavailable` : ''}`;
        });
        const average = person.querySelector('[data-weekly-average]');
        if (average) {
          average.textContent = `${durationText(totalSeconds / 5)}${unavailableCount ? ' · partial' : ''}`;
        }
        return { person, totalSeconds, unavailableCount };
      }).sort((left, right) => right.totalSeconds - left.totalSeconds);
      ranked.forEach((result, index) => {
        const rank = result.person.querySelector('[data-weekly-rank]');
        if (rank) {
          rank.textContent = `Rank ${index + 1} of ${ranked.length}${result.unavailableCount ? ' · partial' : ''}`;
        }
      });
    };
    const runWeeklyReport = async () => {
      let next = 0;
      const worker = async () => {
        while (next < weeklyRecords.length) {
          const row = weeklyRecords[next++];
          await loadWeeklyDuration(row);
          updateWeeklySummaries();
        }
      };
      await Promise.all(Array.from({ length: Math.min(4, weeklyRecords.length) }, worker));
      updateWeeklySummaries();
      const printButton = document.querySelector('[data-weekly-print]');
      if (printButton instanceof HTMLButtonElement) {
        printButton.disabled = false;
        printButton.textContent = '↓ Save as PDF / Print';
      }
    };
    if (weeklyRecords.length === 0) {
      updateWeeklySummaries();
      const printButton = document.querySelector('[data-weekly-print]');
      if (printButton instanceof HTMLButtonElement) {
        printButton.disabled = false;
        printButton.textContent = '↓ Save as PDF / Print';
      }
    }
    else runWeeklyReport();
  }

  if (audio instanceof HTMLAudioElement && dock instanceof HTMLElement) {
    document.querySelectorAll('.audio-launch').forEach(button => {
      button.addEventListener('click', () => {
        if (!button.dataset.audioSrc) return;
        audio.src = button.dataset.audioSrc;
        dockTitle.textContent = button.dataset.audioTitle || 'Recording';
        dockDuration.textContent = 'Audio duration: loading';
        dock.hidden = false;
        dock.classList.add('is-visible');
        audio.play().catch(() => {
          dockDuration.textContent = 'Press play to start audio';
        });
      });
    });
    audio.addEventListener('loadedmetadata', () => {
      const duration = durationText(audio.duration);
      dockDuration.textContent = `Audio duration: ${duration}`;
      safeStorage.set(durationCacheKey(audio.currentSrc || audio.src), String(audio.duration));
      document.querySelectorAll('[data-audio-duration]').forEach(element => {
        if (normalizeAudioSource(element.dataset.audioSrc || '') === normalizeAudioSource(audio.currentSrc || audio.src)) {
          element.textContent = duration;
        }
      });
    });
    document.querySelector('[data-audio-close]')?.addEventListener('click', () => {
      audio.pause();
      audio.removeAttribute('src');
      audio.load();
      dock.hidden = true;
      dock.classList.remove('is-visible');
    });
  }

  const chartPalette = ['#173b63', '#167d8d', '#287a58', '#a36c16', '#557da3', '#55a2a9', '#91bda6', '#ca9a4e'];
  const getChartContext = canvas => {
    if (!(canvas instanceof HTMLCanvasElement)) return null;
    const rect = canvas.getBoundingClientRect();
    if (rect.width < 10 || rect.height < 10) return null;
    const scale = window.devicePixelRatio || 1;
    canvas.width = Math.floor(rect.width * scale);
    canvas.height = Math.floor(rect.height * scale);
    const context = canvas.getContext('2d');
    context?.scale(scale, scale);
    return context ? { context, width: rect.width, height: rect.height } : null;
  };
  const drawDonut = (canvas, labels, values, legend) => {
    const surface = getChartContext(canvas);
    if (!surface) return;
    const { context, width, height } = surface;
    const total = values.reduce((sum, item) => sum + item, 0);
    const radius = Math.min(height * 0.38, width * 0.24, 105);
    const centerX = width * 0.36;
    const centerY = height / 2;
    context.lineWidth = Math.max(18, radius * 0.34);
    let angle = -Math.PI / 2;
    values.forEach((value, index) => {
      const arc = total ? (value / total) * Math.PI * 2 : 0;
      context.beginPath();
      context.strokeStyle = chartPalette[index % chartPalette.length];
      context.arc(centerX, centerY, radius, angle, angle + arc);
      context.stroke();
      angle += arc;
    });
    context.fillStyle = '#173b63';
    context.font = '700 24px Segoe UI, sans-serif';
    context.textAlign = 'center';
    context.fillText(String(total), centerX, centerY + 4);
    context.fillStyle = '#66758a';
    context.font = '11px Segoe UI, sans-serif';
    context.fillText('recordings', centerX, centerY + 23);
    if (legend) {
      legend.replaceChildren();
      labels.forEach((label, index) => {
        const item = document.createElement('div');
        item.className = 'legend-row';
        const swatch = document.createElement('span');
        swatch.className = 'legend-swatch';
        swatch.style.backgroundColor = chartPalette[index % chartPalette.length];
        const text = document.createElement('span');
        text.textContent = label;
        const count = document.createElement('strong');
        count.textContent = String(values[index] || 0);
        item.append(swatch, text, count);
        legend.append(item);
      });
    }
  };
  const drawBars = (canvas, labels, values, secondary = []) => {
    const surface = getChartContext(canvas);
    if (!surface) return;
    const { context, width, height } = surface;
    const padding = { top: 16, right: 16, bottom: 43, left: 34 };
    const chartWidth = width - padding.left - padding.right;
    const chartHeight = height - padding.top - padding.bottom;
    const max = Math.max(1, ...values, ...secondary);
    context.font = '10px Segoe UI, sans-serif';
    context.textAlign = 'right';
    for (let tick = 0; tick <= 4; tick += 1) {
      const y = padding.top + chartHeight * tick / 4;
      context.strokeStyle = '#e8edf3';
      context.beginPath();
      context.moveTo(padding.left, y);
      context.lineTo(width - padding.right, y);
      context.stroke();
      context.fillStyle = '#8291a1';
      context.fillText(String(Math.round(max * (4 - tick) / 4)), padding.left - 7, y + 3);
    }
    const groupWidth = chartWidth / Math.max(labels.length, 1);
    const barWidth = Math.max(6, Math.min(25, groupWidth * (secondary.length ? 0.28 : 0.52)));
    labels.forEach((label, index) => {
      const center = padding.left + groupWidth * (index + 0.5);
      const entries = secondary.length
        ? [[values[index] || 0, '#167d8d', -barWidth * 0.55], [secondary[index] || 0, '#173b63', barWidth * 0.55]]
        : [[values[index] || 0, '#167d8d', 0]];
      entries.forEach(([value, color, offset]) => {
        const barHeight = (value / max) * chartHeight;
        context.fillStyle = color;
        context.beginPath();
        context.roundRect(center + offset - barWidth / 2, padding.top + chartHeight - barHeight, barWidth, barHeight, 3);
        context.fill();
      });
      context.save();
      context.translate(center, height - 8);
      context.rotate(labels.length > 6 ? -0.45 : 0);
      context.textAlign = labels.length > 6 ? 'right' : 'center';
      context.fillStyle = '#66758a';
      context.fillText(label.length > 17 ? `${label.slice(0, 16)}…` : label, 0, 0);
      context.restore();
    });
  };
  const loadJson = id => {
    const node = document.getElementById(id);
    if (!node) return null;
    try {
      return JSON.parse(node.textContent || '{}');
    } catch {
      return null;
    }
  };
  const dashboardData = loadJson('dashboard-chart-data');
  if (dashboardData) {
    drawDonut(
      document.getElementById('status-chart'),
      dashboardData.status.labels,
      dashboardData.status.values,
      document.getElementById('status-chart-legend')
    );
    const workCanvas = document.getElementById('workload-chart');
    drawBars(workCanvas, dashboardData.workload.labels, dashboardData.workload.values, dashboardData.workload.finished);
    const renderCharts = () => {
      drawDonut(document.getElementById('status-chart'), dashboardData.status.labels, dashboardData.status.values, document.getElementById('status-chart-legend'));
      drawBars(workCanvas, dashboardData.workload.labels, dashboardData.workload.values, dashboardData.workload.finished);
    };
    window.addEventListener('resize', renderCharts, { passive: true });
  }
  const reportData = loadJson('report-chart-data');
  if (reportData) {
    const renderReport = () => {
      const canvas = document.getElementById('report-chart');
      if (reportData.type === 'statuses') {
        drawDonut(canvas, reportData.data.labels, reportData.data.values);
      } else {
        drawBars(canvas, reportData.data.labels, reportData.data.values);
      }
    };
    renderReport();
    window.addEventListener('resize', renderReport, { passive: true });
  }
})();
