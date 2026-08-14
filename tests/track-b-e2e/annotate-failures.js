// Parses tests/track-b-e2e/test-results/results.json (Playwright's JSON
// reporter output) and emits one GitHub Actions `::error::` workflow
// command per failed test/assertion, including the actual error message
// and a stack snippet. These become check-run annotations retrievable via
// the GitHub API (Checks → annotations), independent of raw log storage.
const fs = require('fs');
const path = require('path');

const resultsPath = path.join(__dirname, 'test-results', 'results.json');
if (!fs.existsSync(resultsPath)) {
  console.log('No results.json found — nothing to annotate.');
  process.exit(0);
}

const data = JSON.parse(fs.readFileSync(resultsPath, 'utf8'));

function walkSuites(suites, cb) {
  for (const suite of suites || []) {
    for (const spec of suite.specs || []) {
      cb(spec, suite.title);
    }
    walkSuites(suite.suites, cb);
  }
}

let failCount = 0;
walkSuites(data.suites, (spec, suiteTitle) => {
  for (const test of spec.tests || []) {
    for (const result of test.results || []) {
      if (result.status !== 'passed') {
        failCount++;
        const err = (result.errors && result.errors[0]) || result.error || {};
        const msg = (err.message || 'Unknown failure').split('\n').slice(0, 5).join(' | ').replace(/\n/g, ' ');
        const file = spec.file || 'unknown file';
        const line = spec.line || 0;
        console.log(`::error file=${file},line=${line},title=${suiteTitle} — ${spec.title}::${msg}`);
      }
    }
  }
});

console.log(`\nAnnotated ${failCount} failing test result(s).`);
