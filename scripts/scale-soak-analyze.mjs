/** Enrich a finished raw report without replacing its pass/fail result. */
import { readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

function assessRetainedHeap(values, durationLimited = false) {
  const minimumLifetime = durationLimited ? 2100000 : 3600000;
  const windowMs = durationLimited ? 300000 : 600000;
  const scope = durationLimited ? "duration-limited" : "hour-plus";
  if (!values.length || values.some(sample => !Number.isFinite(sample.at)
    || !Number.isFinite(sample.memory?.heapUsed) || sample.memory.heapUsed < 0))
    return { status: "insufficient-samples", scope, reason: "Invalid memory samples" };
  const lifetime = values.at(-1).at - values[0].at;
  if (lifetime < minimumLifetime)
    return { status: "insufficient-duration", scope, minimum_lifetime_seconds: minimumLifetime / 1000 };
  const average = (items) =>
    items.reduce((sum, sample) => sum + sample.memory.heapUsed, 0) /
    items.length;
  const early = values.filter(
    (sample) =>
      sample.at >= values[0].at + 600000 && sample.at < values[0].at + 1200000,
  );
  const late = values.filter(
    (sample) => sample.at >= values.at(-1).at - windowMs,
  );
  const tail = values.filter(
    (sample) => sample.at >= values[0].at + lifetime / 2,
  );
  const first = durationLimited
    ? early.filter(sample => sample.at < values[0].at + 600000 + windowMs)
    : early;
  if (first.length < 30 || late.length < 30 || tail.length < 30)
    return { status: "insufficient-samples", scope };
  // A short observation must not pass from a handful of samples at each end
  // of a large gap. The harness samples every ten seconds; allow up to 30 s.
  const maxGap = Math.max(...values.slice(1).map((sample, index) => sample.at - values[index].at));
  if (durationLimited && (maxGap > 30000 || values.some((sample, index) => index && sample.at <= values[index - 1].at)
    || first.at(-1).at - first[0].at < windowMs - 20000
    || late.at(-1).at - late[0].at < windowMs - 20000))
    return { status: "insufficient-samples", scope, reason: "Incomplete continuous sampling or five-minute windows", maximum_sample_gap_seconds: maxGap / 1000 };
  const baseline = average(first),
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
        ? durationLimited ? "passed-duration-limited" : "passed"
        : durationLimited ? "failed-duration-limited" : "failed",
    scope,
    forced_gc: true,
    warmup_seconds: 600,
    comparison_window_seconds: windowMs / 1000,
    early_samples: first.length,
    final_samples: late.length,
    early_sample_span_seconds: (first.at(-1).at - first[0].at) / 1000,
    final_sample_span_seconds: (late.at(-1).at - late[0].at) / 1000,
    early_mean_bytes: baseline,
    final_mean_bytes: ending,
    change_bytes: ending - baseline,
    allowed_growth_bytes: maximumGrowth,
    final_half_slope_bytes_per_hour: slope,
    allowed_slope_bytes_per_hour: maximumSlope,
  };
}

export function analyzeReport(report, samples) {
  const durationLimited = report.target_duration_ms >= 2400000 && report.target_duration_ms < 3600000;
  const groups = new Map();
  for (const sample of samples) {
    const key = `${sample.gateway}:${sample.pid}`;
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(sample);
  }
  for (const values of groups.values()) values.sort((a, b) => a.at - b.at);
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
      ...(durationLimited ? { duration_limited_retained_heap_assessment: assessRetainedHeap(values, true) } : {}),
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
  const limitedAssessments = processMemory.map(process => process.duration_limited_retained_heap_assessment)
    .filter(item => item && ["passed-duration-limited", "failed-duration-limited"].includes(item.status));
  const limitedPassed = limitedAssessments.length > 0 && limitedAssessments.every(item => item.status === "passed-duration-limited");
  const requireLimitedAssessment = Boolean(durationLimited && report.final && report.passed);
  const limitedInsufficient = processMemory.some(process => process.duration_limited_retained_heap_assessment?.status === "insufficient-samples")
    ? "insufficient-samples" : "insufficient-duration";
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
      duration_limited_assessment: {
        status: !durationLimited ? "not-applicable" : limitedAssessments.length
          ? limitedPassed ? "passed-duration-limited" : "failed-duration-limited"
          : limitedInsufficient,
        assessed_processes: limitedAssessments.length,
        required_for_completed_40_to_59_minute_run: requireLimitedAssessment,
        minimum_continuous_lifetime_seconds: 2100,
        conclusion: "Duration-limited regression assessment only; does not establish absence of memory leaks or satisfy the hour-plus gate.",
      },
    },
    process_memory: processMemory,
    notes: [
      "Memory grouped by PID; restarted gateway processes are not one continuous lifetime.",
      "Counters reset with process restart.",
      "Long-lived PID memory assessment excludes the first ten minutes; early and final ten-minute forced-GC means may grow by at most max(25%, 8 MiB); final-half least-squares slope must be <=8 MiB/hour. This is a bounded regression check, not proof of no leaks.",
      "Runs targeting 40 to less than 60 minutes also get a separate duration-limited assessment: a continuous PID span of at least 35 minutes, ten-minute warmup, first/final five-minute windows, at least 30 samples per window, and no sampling gap above 30 seconds. Growth and slope thresholds are unchanged. Insufficient observations never pass; the hour-plus assessment remains separate.",
      "Fanout gap includes deliberately disconnected, unauthorized/suspended and slow-client intervals, and for old preflight reports unsuccessful producer attempts.",
      "A successful transactional-command assertion is scoped to the same retained receipt/session/database; it is not exactly-once browser delivery.",
    ],
  };
  return { analysis, exitCode: requireMemoryAssessment && !memoryPassed || requireLimitedAssessment && !limitedPassed ? 1 : 0 };
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const directory = resolve(process.argv[2] ?? ".test-results/missing-run");
  const report = JSON.parse(await readFile(resolve(directory, "report.json"), "utf8"));
  const samples = (await readFile(resolve(directory, "gateway-samples.jsonl"), "utf8"))
    .trim().split("\n").filter(Boolean).map(line => JSON.parse(line));
  const { analysis, exitCode } = analyzeReport(report, samples);
  await writeFile(
    resolve(directory, "analysis.json"),
    JSON.stringify(analysis, null, 2) + "\n",
  );
  console.log(JSON.stringify(analysis, null, 2));
  process.exitCode = exitCode;
}
