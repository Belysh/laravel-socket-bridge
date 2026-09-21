import test from "node:test";
import assert from "node:assert/strict";
import { mkdtemp, readFile, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";
import { analyzeReport } from "../../scripts/scale-soak-analyze.mjs";

const MiB = 1024 * 1024;
const report = (minutes = 40) => ({
  run_id: "synthetic-analyzer-regression",
  final: true,
  passed: true,
  elapsed_ms: minutes * 60000,
  target_duration_ms: minutes * 60000,
  events_attempted: 12000,
  events_published: 12000,
  event_deliveries: 6000000,
  connections: 500,
  operations_completed: 4800,
});
function samples(minutes = 40, heap = () => 32 * MiB, pid = 1234) {
  return Array.from({ length: minutes * 6 + 1 }, (_, index) => ({
    gateway: 1, pid, at: 1700000000000 + index * 10000,
    memory: { heapUsed: heap(index / 6), rss: 80 * MiB }, counters: {},
  }));
}
const limited = result => result.analysis.memory_regression.duration_limited_assessment;

test("40-minute observation passes only the explicitly duration-limited gate", () => {
  const result = analyzeReport(report(), samples());
  assert.equal(result.exitCode, 0);
  assert.equal(result.analysis.memory_regression.status, "insufficient-duration");
  assert.equal(result.analysis.memory_regression.required_for_completed_hour_plus_run, false);
  assert.equal(limited(result).status, "passed-duration-limited");
  assert.equal(limited(result).required_for_completed_40_to_59_minute_run, true);
  assert.match(limited(result).conclusion, /does not establish absence of memory leaks/);
  const assessment = result.analysis.process_memory[0].duration_limited_retained_heap_assessment;
  assert.equal(assessment.warmup_seconds, 600);
  assert.equal(assessment.comparison_window_seconds, 300);
  assert.equal(assessment.allowed_growth_bytes, 8 * MiB);
  assert.equal(assessment.allowed_slope_bytes_per_hour, 8 * MiB);
});

test("hour-plus methodology keeps ten-minute comparison windows and its original gate", () => {
  const result = analyzeReport(report(75), samples(75));
  assert.equal(result.exitCode, 0);
  assert.equal(result.analysis.memory_regression.status, "passed");
  assert.equal(result.analysis.memory_regression.required_for_completed_hour_plus_run, true);
  assert.equal(limited(result).status, "not-applicable");
  const assessment = result.analysis.process_memory[0].retained_heap_assessment;
  assert.equal(assessment.comparison_window_seconds, 600);
  assert.equal(assessment.warmup_seconds, 600);
});

test("short-run final-half slope threshold is not relaxed", () => {
  const result = analyzeReport(report(), samples(40, minute => (32 + minute / 60 * 10) * MiB));
  const assessment = result.analysis.process_memory[0].duration_limited_retained_heap_assessment;
  assert.ok(assessment.change_bytes < assessment.allowed_growth_bytes);
  assert.ok(assessment.final_half_slope_bytes_per_hour > assessment.allowed_slope_bytes_per_hour);
  assert.equal(limited(result).status, "failed-duration-limited");
  assert.equal(result.exitCode, 1);
});

test("short-run retained growth fails even when the final-half slope is flat", () => {
  const result = analyzeReport(report(), samples(40, minute => (minute < 16 ? 32 : 44) * MiB));
  const assessment = result.analysis.process_memory[0].duration_limited_retained_heap_assessment;
  assert.equal(assessment.final_half_slope_bytes_per_hour, 0);
  assert.ok(assessment.change_bytes > assessment.allowed_growth_bytes);
  assert.equal(limited(result).status, "failed-duration-limited");
  assert.equal(result.exitCode, 1);
});

test("restarted PIDs are not combined to manufacture sufficient continuous lifetime", () => {
  const result = analyzeReport(report(), [...samples(20, undefined, 1234), ...samples(20, undefined, 5678)]);
  assert.equal(limited(result).status, "insufficient-duration");
  assert.equal(limited(result).assessed_processes, 0);
  assert.equal(result.exitCode, 1);
  assert.equal(analyzeReport(report(), samples(34)).exitCode, 1);
  assert.equal(analyzeReport(report(), samples(35)).exitCode, 0);
});

test("missing observations and invalid samples cannot pass the short-run gate", () => {
  const complete = samples();
  const gapped = complete.filter(sample => !(sample.at > complete[0].at + 1200000 && sample.at < complete[0].at + 1500000));
  for (const values of [gapped, complete.filter((_, index) => index % 2 === 0), [...complete, { ...complete[0], memory: { heapUsed: NaN, rss: 0 } }]]) {
    const result = analyzeReport(report(), values);
    assert.equal(limited(result).status, "insufficient-samples");
    assert.equal(result.exitCode, 1);
  }
});

test("a short or interrupted raw report is never upgraded to a completed long run", () => {
  const smoke = analyzeReport(report(5), samples(5));
  assert.equal(limited(smoke).status, "not-applicable");
  const stopped = analyzeReport({ ...report(120), elapsed_ms: 43 * 60000, passed: false }, samples(43));
  assert.equal(stopped.analysis.report_passed, false);
  assert.equal(stopped.analysis.memory_regression.status, "insufficient-duration");
  assert.equal(limited(stopped).status, "not-applicable");
});

test("CLI writes a separate analysis, preserves the raw report and fails on insufficient observations", async () => {
  const directory = await mkdtemp(join(tmpdir(), "socket-bridge-analysis-"));
  try {
    const raw = JSON.stringify(report(), null, 2) + "\n";
    await writeFile(join(directory, "report.json"), raw);
    await writeFile(join(directory, "gateway-samples.jsonl"), samples(34).map(sample => JSON.stringify(sample)).join("\n") + "\n");
    const execution = spawnSync(process.execPath, [new URL("../../scripts/scale-soak-analyze.mjs", import.meta.url).pathname, directory], { encoding: "utf8" });
    assert.equal(execution.status, 1, execution.stderr);
    assert.equal(await readFile(join(directory, "report.json"), "utf8"), raw);
    const analysis = JSON.parse(await readFile(join(directory, "analysis.json"), "utf8"));
    assert.equal(analysis.memory_regression.duration_limited_assessment.status, "insufficient-duration");
  } finally { await rm(directory, { recursive: true, force: true }); }
});
