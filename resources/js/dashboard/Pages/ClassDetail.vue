<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import DataTable from '../components/DataTable.vue';
import StatusPill from '../components/StatusPill.vue';
import Histogram from '../components/Histogram.vue';
import ChartCard from '../components/ChartCard.vue';
import BackLink from '../components/BackLink.vue';
import Kicker from '../components/Kicker.vue';
import Empty from '../components/Empty.vue';

const page = usePage();
const className = computed(() => page.props.class_name);
const stats = computed(() => page.props.stats ?? {});
const throughputSeries = computed(() => page.props.throughput_series ?? []);
const histogram = computed(() => page.props.runtime_histogram ?? []);
const recentRuns = computed(() => page.props.recent_runs ?? []);
const recentFailures = computed(() => page.props.recent_failures ?? []);

// Build SVG path from throughput_series: time-X, normalized value-Y.
const chartPath = computed(() => {
  const points = throughputSeries.value;
  if (points.length === 0) return { line: '', area: '' };
  const W = 800, H = 160, PAD_T = 8, PAD_B = 12;
  const max = Math.max(1, ...points.map((p) => Number(p.value) || 0));
  const xs = points.map((_, i) => (i / Math.max(1, points.length - 1)) * W);
  const ys = points.map((p) => H - PAD_B - ((Number(p.value) || 0) / max) * (H - PAD_T - PAD_B));
  const linePts = xs.map((x, i) => `${x},${ys[i]}`).join(' L ');
  const areaPts = `${linePts} L ${xs[xs.length - 1]},${H} L ${xs[0]},${H} Z`;
  return { line: `M ${linePts}`, area: `M ${areaPts}` };
});

const chartAxis = computed(() => {
  const pts = throughputSeries.value;
  if (pts.length < 2) return ['—', '—'];
  const fmt = (ts) => new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  return [fmt(pts[0].ts), fmt(pts[Math.floor(pts.length / 2)].ts), fmt(pts[pts.length - 1].ts) + ' now'];
});

function statusKind(s) {
  if (s === 'completed') return 'ok';
  if (s === 'failed') return 'err';
  if (s === 'released') return 'info';
  if (s === 'reserved' || s === 'pending') return 'warn';
  return 'neutral';
}
</script>

<template>
  <div>
    <BackLink to="/sunset/metrics">Back to Metrics</BackLink>

    <div class="page-head">
      <div>
        <Kicker icon="i-zap">Job class</Kicker>
        <h1 class="class-name">{{ className }}</h1>
      </div>
      <span class="page-sub" style="margin-left: auto;">last 60 minutes</span>
      <div class="page-actions">
        <button class="btn" v-if="stats.failures_1h > 0">
          <svg><use href="#i-alert"/></svg>View failures ({{ stats.failures_1h }})
        </button>
        <button class="btn"><svg><use href="#i-refresh"/></svg></button>
      </div>
    </div>

    <div class="stats cols-6">
      <div class="stat">
        <div class="stat-label">Runs / 1h</div>
        <div class="stat-value">{{ stats.runs_1h ?? 0 }}</div>
      </div>
      <div class="stat">
        <div class="stat-label">Avg</div>
        <div class="stat-value">{{ stats.avg_ms ?? 0 }}<span class="unit">ms</span></div>
      </div>
      <div class="stat">
        <div class="stat-label">p50</div>
        <div class="stat-value">{{ stats.p50_ms ?? 0 }}<span class="unit">ms</span></div>
      </div>
      <div class="stat">
        <div class="stat-label">p95</div>
        <div class="stat-value">{{ stats.p95_ms ?? 0 }}<span class="unit">ms</span></div>
      </div>
      <div class="stat">
        <div class="stat-label">p99</div>
        <div class="stat-value amber">{{ stats.p99_ms ?? 0 }}<span class="unit">ms</span></div>
      </div>
      <div class="stat">
        <div class="stat-label">Failure rate</div>
        <div class="stat-value red">{{ stats.failure_rate_pct ?? 0 }}<span class="unit" style="color: rgb(var(--red)); opacity: 0.7;">%</span></div>
        <div class="stat-delta">{{ stats.failures_1h ?? 0 }} in last hour</div>
      </div>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Throughput</h2>
        <span class="meta">jobs/min · last 60 min</span>
      </div>
      <ChartCard :axis="chartAxis">
        <svg class="full-spark" viewBox="0 0 800 160" preserveAspectRatio="none" aria-label="Throughput chart">
          <defs>
            <linearGradient id="cd-fade" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0" stop-color="#a78bfa" stop-opacity="0.4"/>
              <stop offset="1" stop-color="#a78bfa" stop-opacity="0"/>
            </linearGradient>
          </defs>
          <g stroke="currentColor" stroke-width="0.5" opacity="0.08">
            <line x1="0" x2="800" y1="40" y2="40"/>
            <line x1="0" x2="800" y1="80" y2="80"/>
            <line x1="0" x2="800" y1="120" y2="120"/>
          </g>
          <path v-if="chartPath.area" :d="chartPath.area" fill="url(#cd-fade)"/>
          <path v-if="chartPath.line" :d="chartPath.line" fill="none" stroke="#a78bfa" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </ChartCard>
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Runtime distribution</h2>
        <span class="meta">
          {{ stats.runs_1h ?? 0 }} runs · last 60 min
        </span>
      </div>
      <Empty v-if="histogram.length === 0" message="No histogram data yet." />
      <Histogram v-else :buckets="histogram" :total="stats.runs_1h" />
    </div>

    <div class="section">
      <div class="section-head">
        <h2>Recent runs</h2>
        <span class="meta">last {{ recentRuns.length }} invocations</span>
      </div>
      <Empty v-if="recentRuns.length === 0" message="No recent runs for this class." />
      <DataTable
        v-else
        :columns="[
          { key: 'at', label: 'At', width: '120px', align: 'right' },
          { key: 'queue', label: 'Queue', width: '160px' },
          { key: 'runtime_ms', label: 'Runtime', width: '110px', align: 'right' },
          { key: 'status', label: 'Status', width: '120px' },
          { key: 'attempt', label: 'Attempt', width: '110px', align: 'right' },
          { key: 'pid', label: 'Worker pid', width: '120px', align: 'right' },
          { key: 'tags', label: 'Tags', width: '1fr' },
        ]"
        :rows="recentRuns"
        :selectable="false"
      >
        <template #queue="{ row }">
          <span class="pill neutral">{{ row.queue ?? '—' }}</span>
        </template>
        <template #runtime_ms="{ row }">
          <span v-if="row.runtime_ms != null">{{ row.runtime_ms }}ms</span>
          <span v-else style="color: rgb(var(--muted))">—</span>
        </template>
        <template #status="{ row }">
          <StatusPill :status="statusKind(row.status)">{{ row.status || '—' }}</StatusPill>
        </template>
      </DataTable>
    </div>

    <div v-if="recentFailures.length" class="section">
      <div class="section-head">
        <h2>Recent failures</h2>
        <span class="meta">{{ recentFailures.length }} in last 60 min</span>
      </div>
      <DataTable
        :columns="[
          { key: 'failed_at', label: 'Failed at', width: '140px', align: 'right' },
          { key: 'exception_class', label: 'Exception', width: '1.5fr' },
          { key: 'message', label: 'Message', width: '1.5fr' },
          { key: 'attempts', label: 'Attempts', width: '110px', align: 'right' },
        ]"
        :rows="recentFailures"
        :selectable="false"
      >
        <template #exception_class="{ row }">
          <span style="color: rgb(var(--red)); font-weight: 500;">{{ row.exception_class }}</span>
        </template>
      </DataTable>
    </div>

    <div v-if="(stats.failures_1h ?? 0) > 0" class="callout">
      Recent failures detected. Consider declaring
      <code>Sunset::limit({{ className }}::class)-&gt;throttle(perMinute: 200)</code>
      to back off pre-emptively if these are upstream rate-limit errors.
    </div>
  </div>
</template>
