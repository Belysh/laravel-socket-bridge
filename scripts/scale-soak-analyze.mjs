/** Enrich a finished raw report without replacing its pass/fail result. */
import { readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
const directory = resolve(process.argv[2] ?? ".test-results/missing-run");
const report = JSON.parse(
  await readFile(resolve(directory, "report.json"), "utf8"),
);
const samples = (
  await readFile(resolve(directory, "gateway-samples.jsonl"), "utf8")
)
  .trim()
  .split("\n")
  .filter(Boolean)
  .map((line) => JSON.parse(line));
const groups = new Map();
for (const sample of samples) {
  const key = `${sample.gateway}:${sample.pid}`;
  if (!groups.has(key)) groups.set(key, []);
  groups.get(key).push(sample);
}
function assessRetainedHeap(values) {
  const lifetime = values.at(-1).at - values[0].at;
  if (lifetime < 3600000)
    return { status: "insufficient-duration", minimum_lifetime_seconds: 3600 };
  const average = (items) =>
    items.reduce((sum, sample) => sum + sample.memory.heapUsed, 0) /
    items.length;
  const early = values.filter(
    (sample) =>
      sample.at >= values[0].at + 600000 && sample.at < values[0].at + 1200000,
  );
  const late = values.filter(
    (sample) => sample.at >= values.at(-1).at - 600000,
  );
  const tail = values.filter(
    (sample) => sample.at >= values[0].at + lifetime / 2,
  );
  if (early.length < 30 || late.length < 30 || tail.length < 30)
    return { status: "insufficient-samples" };
  const baseline = average(early),
    ending = average(late);
  const meanX =
    tail.reduce((sum, sample) => sum + (sample.at - tail[0].at) / 3600000, 0) /
    tail.length;
  const meanY = average(tail);
  let numerator = 0,
    denominator = 0;
  for (const sample of tail) {
    const centeredX = (sample.at - tail[0].at) / 3600000 - meanX;
    numerator += centeredX * (sample.memory.heapUsed - meanY);
    denominator += centeredX * centeredX;
  }
  const slope = numerator / denominator;
  const maximumGrowth = Math.max(baseline * 0.25, 8 * 1024 * 1024);
  const maximumSlope = 8 * 1024 * 1024;
  return {
    status:
      ending - baseline <= maximumGrowth && slope <= maximumSlope
        ? "passed"
        : "failed",
    forced_gc: true,
    early_mean_bytes: baseline,
    final_mean_bytes: ending,
    change_bytes: ending - baseline,
    allowed_growth_bytes: maximumGrowth,
    final_half_slope_bytes_per_hour: slope,
    allowed_slope_bytes_per_hour: maximumSlope,
  };
}
const processMemory = [...groups].map(([process, values]) => {
  const warmup =
    values[0].at + Math.min(300000, (values.at(-1).at - values[0].at) * 0.2);
  const measured = values.filter((value) => value.at >= warmup);
  const window = Math.min(6, Math.floor(measured.length / 2));
  const first = window ? measured.slice(0, window) : [],
    last = window ? measured.slice(-window) : [];
  const mean = (items) =>
    items.length
      ? items.reduce((sum, item) => sum + item.memory.heapUsed, 0) /
        items.length
      : null;
  const start = mean(first),
    end = mean(last);
  return {
    process,
    gateway: values[0].gateway,
    pid: values[0].pid,
    samples: values.length,
    lifetime_seconds: (values.at(-1).at - values[0].at) / 1000,
    post_warmup_heap_start_bytes: start,
    post_warmup_heap_end_bytes: end,
    heap_change_bytes: start !== null && end !== null ? end - start : null,
    heap_change_percent: start ? (100 * (end - start)) / start : null,
    peak_rss_bytes: Math.max(...values.map((value) => value.memory.rss)),
    last_counters: values.at(-1).counters,
    retained_heap_assessment: assessRetainedHeap(values),
  };
});
const seconds = report.elapsed_ms / 1000;
const acknowledgedPublications = Object.hasOwn(report, "events_attempted");
const memoryAssessments = processMemory
  .map((process) => process.retained_heap_assessment)
  .filter((item) => ["passed", "failed"].includes(item.status));
const requireMemoryAssessment =
  report.final && report.passed && report.target_duration_ms >= 3600000;
const memoryPassed =
  memoryAssessments.length > 0 &&
  memoryAssessments.every((item) => item.status === "passed");
const analysis = {
  run_id: report.run_id,
  report_final: report.final,
  report_passed:
    report.passed === true && report.elapsed_ms >= report.target_duration_ms,
  publication_measurement: acknowledgedPublications
    ? "Redis-acknowledged writes"
    : "Producer attempts only; preflight counter predates acknowledgement tracking",
  measured_seconds: seconds,
  throughput_per_second: {
    published_events: acknowledgedPublications
      ? report.events_published / seconds
      : null,
    attempted_events:
      (report.events_attempted ?? report.events_published) / seconds,
    client_deliveries: report.event_deliveries / seconds,
    committed_operations: report.operations_completed / seconds,
  },
  event_delivery_gap_including_faults:
    (report.theoretical_delivery_opportunities ??
      report.events_published * report.connections) - report.event_deliveries,
  memory_regression: {
    status: memoryAssessments.length
      ? memoryPassed
        ? "passed"
        : "failed"
      : "insufficient-duration",
    assessed_processes: memoryAssessments.length,
    required_for_completed_hour_plus_run: Boolean(requireMemoryAssessment),
  },
  process_memory: processMemory,
  notes: [
    "Memory grouped by PID; restarted gateway processes are not one continuous lifetime.",
    "Counters reset with process restart.",
    "Long-lived PID memory assessment excludes the first ten minutes; early and final ten-minute forced-GC means may grow by at most max(25%, 8 MiB); final-half least-squares slope must be <=8 MiB/hour. This is a bounded regression check, not proof of no leaks.",
    "Fanout gap includes deliberately disconnected, unauthorized/suspended and slow-client intervals, and for old preflight reports unsuccessful producer attempts.",
    "A successful transactional-command assertion is scoped to the same retained receipt/session/database; it is not exactly-once browser delivery.",
  ],
};
await writeFile(
  resolve(directory, "analysis.json"),
  JSON.stringify(analysis, null, 2) + "\n",
);
console.log(JSON.stringify(analysis, null, 2));

if (requireMemoryAssessment && !memoryPassed) process.exitCode = 1;
